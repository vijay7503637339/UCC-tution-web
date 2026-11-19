# UCC Tuition Manager Web

Standalone PHP + MySQL web panel and REST API. The existing Flutter app and Firebase backend remain unchanged. This repository uses a separate MySQL database; Firestore import will be a manually triggered snapshot only, with no automatic or two-way synchronization. Flutter API integration is a later phase after the web panel has been validated.

## Modules
Dashboard, students and child profiles, batches, teachers, fees, attendance, enquiries/admissions, expenses, action logs, fee analysis, reminders, reports and assistant data endpoints.

## Requirements
PHP 8.2+, PDO MySQL, MySQL 8.0+ or compatible MariaDB, HTTPS in production.

## Setup
1. Copy `.env.example` to `.env` and configure a strong APP_KEY and MySQL credentials.
2. Create a NEW database and import `database/schema.sql`.
3. Set document root to `public/`. Keep `src/`, `database/`, `storage/` and `.env` outside public access.
4. Create the initial admin securely; public registration must remain disabled.
5. Enable HTTPS before using real student data.

All API endpoints are under `/api/v1`; protected endpoints require `Authorization: Bearer <token>`.

Never commit `.env`, service-account JSON, raw Firestore exports, student records, database dumps or passwords.
