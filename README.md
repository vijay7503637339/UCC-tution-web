# UCC Tuition Manager Web

Independent PHP + MySQL web administration panel and REST API for Unique Coaching Classes.

## Important separation

- The existing Flutter app in repository **vijay7503637339/TutionApp** is not modified by this repository.
- Its Firebase / Firestore connection remains active.
- This web project uses its own MySQL database and its own admin accounts.
- The Firestore importer is an operator-triggered, one-time snapshot tool. There is no live sync, dual write, or automatic update from the Flutter app.
- Only after the web panel and API are tested should a separate Flutter integration project use these APIs.

## Included modules

- Dashboard with month selector, admissions, fee collection, pending and overdue summaries, expenses and net receipts.
- Student / family profiles with child profiles, class, guardian, batch, teacher, fee settings and transaction history.
- Batch and teacher management.
- Fee transactions, partial payments and fee-month allocation.
- Attendance registers per batch and date, with present / absent / leave statuses and history.
- Enquiries and conversion to student admissions.
- Expense tracking and activity logs.
- Fee reminders with pre-filled WhatsApp drafts (manual review and Send required).
- Fee analysis assistant with deterministic MySQL calculations (not an external LLM integration).
- CSV fee transaction export.
- Versioned REST API (/api/v1) with token authentication.
- Firestore recursive JSONL snapshot export, MySQL import and count verification utilities.

## Requirements

- PHP 8.2 or newer.
- PDO and PDO MySQL extensions.
- MySQL 8.0+ or compatible MariaDB with InnoDB and JSON column support.
- HTTPS in production.
- Python 3.10+ and access to the new MySQL database only when running the optional migration importer on your Mac.

## Local setup

1. Create a fresh empty MySQL database for this web project.
2. Copy .env.example to .env and fill in the MySQL connection values. Never commit .env.
3. Import database/schema.sql into that new database.
4. Create the first admin from the project root:

   ~~~sh
   php bin/create-admin.php --name="UCC Admin" --email="admin@example.com"
   ~~~

   The script prompts for a password (minimum 12 characters). There is no public registration route.
5. Set the web document root to the project's public/ directory.
6. Enable HTTPS, then open /login.

For local development:

~~~sh
cp .env.example .env
# edit .env
mysql -u YOUR_USER -p YOUR_DATABASE < database/schema.sql
php bin/create-admin.php --name="UCC Admin" --email="admin@example.com"
php -S 127.0.0.1:8000 -t public public/index.php
~~~

## cPanel / MilesWeb deployment

1. Create a separate database and database user in cPanel. Grant only that database the required privileges.
2. Upload the repository outside the public web directory where possible.
3. Configure the domain/subdomain document root to project/public/, not the project root.
4. Copy .env.example to .env in the project root and set the actual cPanel database name, username, password and host.
5. Import database/schema.sql using phpMyAdmin.
6. Use cPanel Terminal / SSH (if enabled) to run the CLI admin creation command. Do not expose the bin/ directory over HTTP.
7. Confirm /api/v1/health returns JSON and /login displays the sign-in page.
8. Keep PHP error display disabled in production and use HTTPS.

If running the importer from your Mac, the hosting account must allow MySQL connections from your current public IP (or use an approved SSH tunnel). Do not open MySQL to all IPs. If remote MySQL is unavailable, deploy a controlled importer on the server instead; do not put database credentials into browser JavaScript.

## Firestore snapshot migration

This step is optional and separate from normal web operation.

1. On your Mac, create a Python virtual environment in the project directory.
2. Install migration packages:

   ~~~sh
   python3 -m venv .venv
   source .venv/bin/activate
   pip install -r migration/requirements.txt
   ~~~

3. Download a Firebase service account key with read-only Firestore access. Keep it outside the repository and set:

   ~~~sh
   export GOOGLE_APPLICATION_CREDENTIALS="$HOME/.config/ucc-migration/firebase-admin.json"
   export FIREBASE_PROJECT_ID="your-firebase-project-id"
   ~~~

4. Export every root collection and nested subcollection to a local JSONL snapshot:

   ~~~sh
   python migration/export_firestore.py
   ~~~

5. Preview document counts without database writes:

   ~~~sh
   python migration/import_mysql.py migration/exports/FIRESTORE_EXPORT.jsonl --dry-run
   ~~~

6. After verifying .env points only to the new web MySQL database, run the import:

   ~~~sh
   python migration/import_mysql.py migration/exports/FIRESTORE_EXPORT.jsonl
   ~~~

7. Compare the source snapshot counts with MySQL:

   ~~~sh
   python migration/verify_migration.py migration/exports/FIRESTORE_EXPORT.jsonl
   ~~~

The exporter is read-only. The importer preserves original Firestore document IDs, imports the app's known collections into typed tables, and archives all root and nested documents in firestore_document_archive. Run the importer only after the SQL schema is installed. It is repeatable for the same snapshot; take a fresh backup before re-importing into a database that already has test edits.

**Important:** a JSONL export is a point-in-time snapshot, not a live mirror. New Firebase changes made after export are not copied to MySQL. Do not delete or disable Firebase.

## REST API

Base path: /api/v1

- GET /health – service and DB status.
- POST /auth/login – email/password; returns a bearer token.
- GET /auth/me and POST /auth/logout.
- GET /dashboard?month=YYYY-MM.
- GET /analytics?month=YYYY-MM.
- GET /reminders?month=YYYY-MM.
- GET /reports/fees?month=YYYY-MM.
- CRUD endpoints for students, batches, teachers, fees, enquiries, attendance, expenses, and action-logs.
- Attendance support: GET /attendance/students?batch_id=ID, POST /attendance, GET /attendance/{session_id}.

Protected routes require Authorization: Bearer <token>. JSON keys use snake_case. Example:

~~~sh
curl -X POST https://YOUR-DOMAIN/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"YOUR_PASSWORD"}'
~~~

Then pass the returned token in the Authorization header. Never store an API token in a public website script.

## Validation

GitHub Actions runs PHP syntax checks, Composer metadata validation and Python bytecode compilation on pushes to main.

## Security checklist

- Keep .env, Firebase service account keys, Firestore JSONL exports and SQL backups out of Git.
- Do not put backups or exports under public/.
- Use strong admin passwords and HTTPS.
- Restrict MySQL remote access to a single trusted IP or use an SSH tunnel.
- Keep APP_DEBUG=false in production.
- Keep Firebase and the original Flutter application untouched until a later, separately tested cutover.
