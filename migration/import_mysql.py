#!/usr/bin/env python3
"""Idempotent Firestore JSONL snapshot importer for the new UCC MySQL database."""
from __future__ import annotations
import argparse, json, os, sys, uuid
from datetime import datetime, date
from pathlib import Path
from typing import Any
import pymysql
from pymysql.cursors import DictCursor

ROOT = Path(__file__).resolve().parents[1]
def load_env():
    env = {}
    p = ROOT / ".env"
    if p.exists():
        for line in p.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line: continue
            k, v = line.split("=", 1); env[k.strip()] = v.strip().strip('"').strip("'")
    return env

def decode(value: Any) -> Any:
    if isinstance(value, list): return [decode(v) for v in value]
    if isinstance(value, dict):
        marker = value.get("__ucc_type__")
        if marker == "timestamp":
            raw = value.get("value", "")
            return datetime.fromisoformat(raw.replace("Z", "+00:00")).replace(tzinfo=None)
        if marker == "document_reference": return value.get("path")
        if marker == "bytes": return value.get("value")
        return {k: decode(v) for k, v in value.items()}
    return value

def as_date(value, default=None):
    value = decode(value)
    if value is None or value == "": return default
    if isinstance(value, datetime): return value.date()
    if isinstance(value, date): return value
    try: return date.fromisoformat(str(value)[:10])
    except ValueError: return default

def as_datetime(value, default=None):
    value = decode(value)
    if value is None or value == "": return default
    if isinstance(value, datetime): return value
    if isinstance(value, date): return datetime.combine(value, datetime.min.time())
    try: return datetime.fromisoformat(str(value).replace("Z", "+00:00")).replace(tzinfo=None)
    except ValueError: return default

def numeric_id(document_id, data):
    try: return int(document_id)
    except (TypeError, ValueError): pass
    raw = data.get("id")
    if raw is None or raw == "": return None
    try: return int(raw)
    except (TypeError, ValueError): return None

def as_bool(value, default=True):
    if isinstance(value, bool): return value
    if isinstance(value, (int, float)): return value != 0
    if isinstance(value, str):
        text = value.strip().lower()
        if text in ("true", "1", "yes"): return True
        if text in ("false", "0", "no"): return False
    return default

def positive_fk(cur, table, value):
    try: candidate = int(value)
    except (TypeError, ValueError): return None
    if candidate <= 0: return None
    cur.execute(f"SELECT id FROM {table} WHERE id=%s LIMIT 1", (candidate,))
    return candidate if cur.fetchone() else None

def execute_upsert(cur, table, data):
    cols = list(data.keys())
    col_sql = ",".join(cols)
    marks = ",".join(["%s"] * len(cols))
    update_cols = [c for c in cols if c != "id"]
    update_sql = ",".join(f"{c}=VALUES({c})" for c in update_cols) or "id=VALUES(id)"
    cur.execute(f"INSERT INTO {table} ({col_sql}) VALUES ({marks}) ON DUPLICATE KEY UPDATE {update_sql}", list(data.values()))

def main():
    parser = argparse.ArgumentParser(description="Import a previously exported Firestore JSONL snapshot into the NEW MySQL database.")
    parser.add_argument("export_file", help="Path to JSONL produced by export_firestore.py")
    parser.add_argument("--dry-run", action="store_true", help="Parse and count documents without writing to MySQL.")
    parser.add_argument("--batch-size", type=int, default=250)
    args = parser.parse_args()
    path = Path(args.export_file)
    if not path.is_file(): raise SystemExit(f"Export file not found: {path}")
    env = load_env()
    connection = None
    if not args.dry_run:
        connection = pymysql.connect(host=env.get("DB_HOST", "127.0.0.1"), port=int(env.get("DB_PORT", "3306")),
            user=env.get("DB_USER", ""), password=env.get("DB_PASS", ""), database=env.get("DB_NAME", ""),
            charset="utf8mb4", cursorclass=DictCursor, autocommit=False)
    run_id = str(uuid.uuid4()); counts = {}; imported = 0; warnings = []
    if connection:
        with connection.cursor() as cur:
            cur.execute("INSERT INTO migration_runs(run_key,status,source_project_id) VALUES(%s,'started',%s)", (run_id, env.get("FIREBASE_PROJECT_ID")))
        connection.commit()
    try:
        with path.open("r", encoding="utf-8") as stream:
            records = [json.loads(line) for line in stream if line.strip()]
            priority = {"teachers": 0, "batches": 1, "students": 2, "fees": 3, "enquiries": 4, "expenses": 5, "attendance": 6, "actionLogs": 7}
            records.sort(key=lambda rec: priority.get(rec.get("collection_path", "").split("/")[0], 50))
            for line_no, rec in enumerate(records, 1):
                collection_path = rec["collection_path"]; doc_id = rec["document_id"]
                raw = rec.get("data") or {}; data = decode(raw)
                counts[collection_path] = counts.get(collection_path, 0) + 1
                if args.dry_run:
                    imported += 1; continue
                try:
                    with connection.cursor() as cur:
                        cur.execute("INSERT INTO firestore_document_archive(collection_path,document_id,parent_document_path,document_json) VALUES(%s,%s,%s,%s) ON DUPLICATE KEY UPDATE document_json=VALUES(document_json),imported_at=CURRENT_TIMESTAMP",
                            (collection_path, doc_id, rec.get("parent_document_path") or "", json.dumps(raw, ensure_ascii=False, separators=(",", ":"))))
                        if collection_path in ("teachers", "batches", "students", "fees", "enquiries", "attendance", "expenses", "actionLogs") and "/" not in collection_path:
                            key = collection_path
                            if key == "teachers":
                                rid = numeric_id(doc_id, data)
                                if rid is not None: execute_upsert(cur, "teachers", {"id":rid,"name":str(data.get("name","")),"subject":str(data.get("subject","")),"phone":str(data.get("phone","")),"raw_data":json.dumps(raw,ensure_ascii=False)})
                                else: warnings.append(f"{rec['document_path']}: non-numeric teacher id, archived only")
                            elif key == "batches":
                                rid=numeric_id(doc_id,data)
                                if rid is not None: execute_upsert(cur,"batches",{"id":rid,"name":str(data.get("name","")),"subject":str(data.get("subject","")),"schedule":str(data.get("schedule","")),"teacher_id":positive_fk(cur,"teachers",data.get("teacherId")),"raw_data":json.dumps(raw,ensure_ascii=False)})
                                else: warnings.append(f"{rec['document_path']}: non-numeric batch id, archived only")
                            elif key == "students":
                                rid=numeric_id(doc_id,data)
                                if rid is not None:
                                    joined=as_date(data.get("joinedOn"),date.today())
                                    children=[item for item in data.get("children",[]) if isinstance(item,dict)] if isinstance(data.get("children"),list) else []
                                    execute_upsert(cur,"students",{"id":rid,"name":str(data.get("name","")),"class_name":str(data.get("className",data.get("class",""))),"guardian_phone":str(data.get("guardianPhone",data.get("phone",""))),"batch_id":positive_fk(cur,"batches",data.get("batchId")),"teacher_id":positive_fk(cur,"teachers",data.get("teacherId")),"monthly_fee":float(data.get("monthlyFee",0) or 0),"due_day":max(1,min(31,int(data.get("dueDay",10) or 10))),"joined_on":joined,"is_fees_advance":int(as_bool(data.get("isFeeAdvance",data.get("isFeesAdvance",True)), True)),"status":"inactive" if data.get("status")=="inactive" else "active","last_reminder":as_datetime(data.get("lastReminder")),"last_payment_date":as_datetime(data.get("lastPaymentDate")),"raw_data":json.dumps(raw,ensure_ascii=False)})
                                    if not children: children=[{"id":rid,"name":data.get("name","Student"),"className":data.get("className",data.get("class","")),"batchId":data.get("batchId"),"isActive":True}]
                                    for child in children:
                                        if not isinstance(child,dict): continue
                                        cid=numeric_id(child.get("id",rid),child) or rid
                                        cur.execute("SELECT family_id FROM student_children WHERE id=%s", (cid,))
                                        existing_child = cur.fetchone()
                                        if existing_child and int(existing_child["family_id"]) != rid:
                                            warnings.append(f"{rec['document_path']}: child ID {cid} already belongs to another family; archived only")
                                            continue
                                        execute_upsert(cur,"student_children",{"id":cid,"family_id":rid,"name":str(child.get("name","")),"class_name":str(child.get("className",child.get("class_name",""))),"batch_id":positive_fk(cur,"batches",child.get("batchId",child.get("batch_id",data.get("batchId")))),"is_active":int(as_bool(child.get("isActive",child.get("is_active",True)), True)),"raw_data":json.dumps(child,ensure_ascii=False)})
                                else: warnings.append(f"{rec['document_path']}: non-numeric student id, archived only")
                            elif key == "fees":
                                rid=numeric_id(doc_id,data); student=int(data.get("studentId",0) or 0)
                                if rid is not None and student:
                                    month=as_date(data.get("feeForMonth",data.get("month")),date.today().replace(day=1));month=month.replace(day=1)
                                    execute_upsert(cur,"fee_payments",{"id":rid,"student_id":student,"amount":float(data.get("amount",0) or 0),"fee_month":month,"paid_on":as_datetime(data.get("date"),datetime.now()),"method":str(data.get("method","")),"note":str(data.get("note","")),"raw_data":json.dumps(raw,ensure_ascii=False)})
                            elif key == "enquiries":
                                rid=numeric_id(doc_id,data)
                                if rid is not None:
                                    execute_upsert(cur,"enquiries",{"id":rid,"student_name":str(data.get("studentName","")),"class_name":str(data.get("className",data.get("class",""))),"parent_name":str(data.get("parentName","")),"phone":str(data.get("phone",data.get("parentMobile",""))),"address":str(data.get("address","")),"enquiry_date":as_date(data.get("enquiryDate")),"expected_join_date":as_date(data.get("expectedJoinDate")),"notes":str(data.get("notes","")),"status":str(data.get("status","New")),"source":str(data.get("source","")),"converted_to_student_id":positive_fk(cur,"students",data.get("convertedToStudentId")),"admission_date":as_date(data.get("admissionDate")),"raw_data":json.dumps(raw,ensure_ascii=False)})
                            elif key == "attendance":
                                bid=int(data.get("batchId",0) or 0); raw_att=data.get("attendance",[]); recs={}
                                if isinstance(raw_att,dict): recs={str(k):v for k,v in raw_att.items()}
                                elif isinstance(raw_att,list):
                                    for item in raw_att:
                                        if isinstance(item,dict) and item.get("studentId") is not None: recs[str(item["studentId"])]=item.get("status","")
                                session_date=as_date(data.get("date"),date.today())
                                present=int(data.get("presentCount",sum(1 for v in recs.values() if v=="present")) or 0)
                                absent=int(data.get("absentCount",sum(1 for v in recs.values() if v=="absent")) or 0)
                                execute_upsert(cur,"attendance_sessions",{"id":doc_id,"batch_id":positive_fk(cur,"batches",bid),"batch_name":str(data.get("batchName","")),"session_date":session_date,"present_count":present,"absent_count":absent,"total_students":int(data.get("totalStudents",len(recs)) or 0),"raw_data":json.dumps(raw,ensure_ascii=False)})
                                cur.execute("DELETE FROM attendance_records WHERE session_id=%s",(doc_id,))
                                for student_id,status in recs.items():
                                    if status in ("present","absent","leave"):
                                        try: cur.execute("INSERT INTO attendance_records(session_id,student_id,attendance_status) VALUES(%s,%s,%s)",(doc_id,int(student_id),status))
                                        except (ValueError, pymysql.MySQLError) as exc: warnings.append(f"{rec['document_path']}: attendance child {student_id} skipped ({exc})")
                            elif key == "expenses":
                                rid=numeric_id(doc_id,data)
                                if rid is not None: execute_upsert(cur,"expenses",{"id":rid,"amount":float(data.get("amount",0) or 0),"description":str(data.get("description","")),"category":str(data.get("category","Other")),"expense_date":as_date(data.get("date"),date.today()),"raw_data":json.dumps(raw,ensure_ascii=False)})
                            elif key == "actionLogs":
                                rid=numeric_id(doc_id,data)
                                if rid is not None: execute_upsert(cur,"action_logs",{"id":rid,"message":str(data.get("message","")),"log_date":as_datetime(data.get("date"),datetime.now()),"type":str(data.get("type","")),"raw_data":json.dumps(raw,ensure_ascii=False)})
                    imported += 1
                    if imported % max(1,args.batch_size)==0: connection.commit()
                except Exception as exc:
                    warnings.append(f"line {line_no} ({rec.get('document_path')}): {exc}")
                    connection.rollback()
        if connection:
            summary={"total_documents":sum(counts.values()),"collections":counts,"warnings":warnings[:200]}
            with connection.cursor() as cur:
                cur.execute("UPDATE migration_runs SET status='completed',completed_at=UTC_TIMESTAMP(),summary_json=%s,error_text=%s WHERE run_key=%s",
                    (json.dumps(summary,ensure_ascii=False),("\n".join(warnings[:100]) or None),run_id))
            connection.commit()
        print(json.dumps({"run_id":run_id,"documents_read":imported,"collections":counts,"warnings":warnings[:200],"dry_run":args.dry_run},ensure_ascii=False,indent=2))
        if warnings: sys.exit(1)
    except Exception as exc:
        if connection:
            connection.rollback()
            with connection.cursor() as cur:
                cur.execute("UPDATE migration_runs SET status='failed',completed_at=UTC_TIMESTAMP(),error_text=%s WHERE run_key=%s",(str(exc),run_id))
            connection.commit()
        raise
    finally:
        if connection: connection.close()

if __name__ == "__main__":
    main()
