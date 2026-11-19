#!/usr/bin/env python3
"""Read-only Firestore snapshot exporter. Credentials stay on the operator's machine."""
from __future__ import annotations
import argparse, json, os, sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import firebase_admin
from firebase_admin import credentials, firestore

def encode(value: Any) -> Any:
    if isinstance(value, datetime):
        return {"__ucc_type__": "timestamp", "value": value.astimezone(timezone.utc).isoformat()}
    # Avoid relying on SDK re-export locations for Firestore value classes.
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
    """Build a collection path using the public parent and id properties."""
    parent = collection_ref.parent
    return f"{parent.path}/{collection_ref.id}" if parent is not None else collection_ref.id

def export_collection(db, collection_ref, writer, summary):
    path = collection_path(collection_ref)
    for snapshot in collection_ref.stream():
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
            export_collection(db, child_collection, writer, summary)

def main():
    parser = argparse.ArgumentParser(description="Export all Firestore root collections and nested subcollections to JSONL.")
    parser.add_argument("--output", help="Output JSONL path. Defaults to a timestamped file under migration/exports.")
    parser.add_argument("--project-id", help="Optional Firebase project ID for the summary manifest.")
    args = parser.parse_args()
    credential_path = os.environ.get("GOOGLE_APPLICATION_CREDENTIALS")
    if credential_path and Path(credential_path).is_file():
        cred = credentials.Certificate(credential_path)
    else:
        cred = credentials.ApplicationDefault()
    try:
        app = firebase_admin.get_app()
    except ValueError:
        firebase_admin.initialize_app(cred, {"projectId": args.project_id} if args.project_id else None)
        app = firebase_admin.get_app()
    db = firestore.client(app=app)
    output = Path(args.output) if args.output else Path(__file__).resolve().parent / "exports" / ("firestore-" + datetime.now().strftime("%Y%m%d-%H%M%S") + ".jsonl")
    output.parent.mkdir(parents=True, exist_ok=True)
    summary = {}
    try:
        with output.open("w", encoding="utf-8") as writer:
            for collection in db.collections():
                export_collection(db, collection, writer, summary)
    except Exception as exc:
        print(f"Export failed: {exc}", file=sys.stderr)
        raise
    manifest = {
        "created_at": datetime.now(timezone.utc).isoformat(),
        "project_id": args.project_id or os.environ.get("FIREBASE_PROJECT_ID"),
        "export_file": str(output),
        "collections": summary,
        "total_documents": sum(summary.values()),
        "note": "Read-only snapshot. This is not a live sync; Firestore can continue to change after export.",
    }
    manifest_path = output.with_suffix(".manifest.json")
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(manifest, ensure_ascii=False, indent=2))

if __name__ == "__main__":
    main()
