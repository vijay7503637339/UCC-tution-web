#!/usr/bin/env python3
"""Read-only Firestore snapshot exporter with paginated, retryable collection reads."""
from __future__ import annotations
import argparse, json, os, sys, time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import firebase_admin
from firebase_admin import credentials, firestore
from google.api_core.retry import Retry
from google.cloud.firestore_v1.field_path import FieldPath

PAGE_SIZE = 200
STREAM_TIMEOUT_SECONDS = 120

def encode(value: Any) -> Any:
    if isinstance(value, datetime):
        return {"__ucc_type__": "timestamp", "value": value.astimezone(timezone.utc).isoformat()}
    if value.__class__.__name__ == "DocumentReference" and hasattr(value, "path"):
        return {"__ucc_type__": "document_reference", "path": value.path}
    if value.__class__.__name__ == "GeoPoint" and hasattr(value, "latitude") and hasattr(value, "longitude"):
        return {"__ucc_type__": "geo_point", "latitude": value.latitude, "longitude": value.longitude}
    if isinstance(value, bytes):
        import base64
        return {"__ucc_type__": "bytes", "value": base64.b64encode(value).decode("ascii")}
    if isinstance(value, dict):
        return {str(k): encode(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [encode(v) for v in value]
    if value is None or isinstance(value, (str, int, float, bool)):
        return value
    return str(value)

def collection_path(collection_ref) -> str:
    parent = collection_ref.parent
    return f"{parent.path}/{collection_ref.id}" if parent is not None else collection_ref.id

def iter_collection_documents(collection_ref, page_size: int = PAGE_SIZE):
    """Read a collection in short document-ID-ordered pages to avoid long streams."""
    cursor = None
    while True:
        query = collection_ref.order_by(FieldPath.document_id()).limit(page_size)
        if cursor is not None:
            query = query.start_after(cursor)
        # Supplying Retry explicitly avoids the SDK fallback path that can raise
        # AttributeError on some gRPC transport versions after a transient 504.
        docs = list(query.stream(retry=Retry(), timeout=STREAM_TIMEOUT_SECONDS))
        if not docs:
            break
        for snapshot in docs:
            yield snapshot
        cursor = docs[-1]
        if len(docs) < page_size:
            break

def export_collection(db, collection_ref, writer, summary, page_size: int):
    path = collection_path(collection_ref)
    print(f"Exporting collection: {path}", file=sys.stderr, flush=True)
    for snapshot in iter_collection_documents(collection_ref, page_size=page_size):
        parent_document = snapshot.reference.parent.parent
        record = {
            "collection_path": path,
            "document_id": snapshot.id,
            "document_path": snapshot.reference.path,
            "parent_document_path": parent_document.path if parent_document is not None else None,
            "data": encode(snapshot.to_dict() or {}),
        }
        writer.write(json.dumps(record, ensure_ascii=False, separators=(",", ":")) + "\n")
        summary[path] = summary.get(path, 0) + 1
        for child_collection in snapshot.reference.collections():
            export_collection(db, child_collection, writer, summary, page_size)

def main():
    parser = argparse.ArgumentParser(description="Export all Firestore root collections and nested subcollections to JSONL.")
    parser.add_argument("--output", help="Output JSONL path. Defaults to a timestamped file under migration/exports.")
    parser.add_argument("--project-id", help="Optional Firebase project ID for the manifest.")
    parser.add_argument("--page-size", type=int, default=PAGE_SIZE, help="Documents per Firestore read page (default: 200).")
    args = parser.parse_args()
    if args.page_size < 1 or args.page_size > 500:
        raise SystemExit("--page-size must be between 1 and 500")
    credential_path = os.environ.get("GOOGLE_APPLICATION_CREDENTIALS")
    if credential_path and Path(credential_path).is_file():
        cred = credentials.Certificate(credential_path)
    else:
        cred = credentials.ApplicationDefault()
    try:
        app = firebase_admin.get_app()
    except ValueError:
        options = {"projectId": args.project_id or os.environ.get("FIREBASE_PROJECT_ID")}
        options = {k: v for k, v in options.items() if v}
        app = firebase_admin.initialize_app(cred, options or None)
    db = firestore.client(app=app)
    output = Path(args.output) if args.output else Path(__file__).resolve().parent / "exports" / ("firestore-" + datetime.now().strftime("%Y%m%d-%H%M%S") + ".jsonl")
    output.parent.mkdir(parents=True, exist_ok=True)
    summary = {}
    started = time.time()
    try:
        with output.open("w", encoding="utf-8") as writer:
            for collection in db.collections():
                export_collection(db, collection, writer, summary, args.page_size)
                writer.flush()
    except Exception as exc:
        print(f"Export failed; partial file retained at {output}: {exc}", file=sys.stderr)
        raise
    manifest = {
        "created_at": datetime.now(timezone.utc).isoformat(),
        "project_id": args.project_id or os.environ.get("FIREBASE_PROJECT_ID"),
        "export_file": str(output),
        "collections": summary,
        "total_documents": sum(summary.values()),
        "duration_seconds": round(time.time() - started, 2),
        "page_size": args.page_size,
        "note": "Read-only point-in-time-ish snapshot assembled during export; Firestore can continue to change while it runs. No live sync.",
    }
    manifest_path = output.with_suffix(".manifest.json")
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(manifest, ensure_ascii=False, indent=2))

if __name__ == "__main__":
    main()
