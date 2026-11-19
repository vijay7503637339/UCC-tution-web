#!/usr/bin/env python3
"""Compare JSONL snapshot root collection counts with MySQL typed tables and archive count."""
import argparse, json
from pathlib import Path
import pymysql
from pymysql.cursors import DictCursor

ROOT=Path(__file__).resolve().parents[1]
def env():
    values={}
    for line in (ROOT/'.env').read_text().splitlines():
        line=line.strip()
        if line and not line.startswith('#') and '=' in line:
            k,v=line.split('=',1);values[k.strip()]=v.strip().strip('"').strip("'")
    return values

def main():
    ap=argparse.ArgumentParser();ap.add_argument('export_file');args=ap.parse_args()
    counts={}
    with Path(args.export_file).open(encoding='utf-8') as f:
        for line in f:
            if line.strip():
                row=json.loads(line);counts[row['collection_path']]=counts.get(row['collection_path'],0)+1
    e=env();db=pymysql.connect(host=e.get('DB_HOST','127.0.0.1'),port=int(e.get('DB_PORT','3306')),user=e['DB_USER'],password=e['DB_PASS'],database=e['DB_NAME'],charset='utf8mb4',cursorclass=DictCursor)
    mapping={'students':'students','batches':'batches','teachers':'teachers','fees':'fee_payments','enquiries':'enquiries','attendance':'attendance_sessions','expenses':'expenses','actionLogs':'action_logs'}
    report={'source_counts':counts,'mysql_counts':{},'archive_count':0,'comparison':{}}
    with db.cursor() as c:
        c.execute('SELECT COUNT(*) AS n FROM firestore_document_archive');report['archive_count']=c.fetchone()['n']
        for collection,table in mapping.items():
            if collection in counts:
                c.execute('SELECT COUNT(*) AS n FROM '+table);n=c.fetchone()['n'];report['mysql_counts'][collection]=n
                report['comparison'][collection]={'source':counts[collection],'mysql':n,'matches':n==counts[collection]}
    db.close();print(json.dumps(report,indent=2,ensure_ascii=False))
    if not all(v['matches'] for v in report['comparison'].values()):raise SystemExit(1)
if __name__=='__main__':main()
