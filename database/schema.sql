SET NAMES utf8mb4;
SET time_zone = '+00:00';
CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
 role ENUM('admin','staff') NOT NULL DEFAULT 'admin', is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS api_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_used_at DATETIME NULL,
 CONSTRAINT fk_tokens_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX idx_tokens_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS teachers (
 id BIGINT NOT NULL PRIMARY KEY, name VARCHAR(160) NOT NULL, subject VARCHAR(160) NOT NULL DEFAULT '',
 phone VARCHAR(30) NOT NULL DEFAULT '', raw_data JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_teachers_name(name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS batches (
 id BIGINT NOT NULL PRIMARY KEY, name VARCHAR(160) NOT NULL, subject VARCHAR(160) NOT NULL DEFAULT '',
 schedule VARCHAR(255) NOT NULL DEFAULT '', teacher_id BIGINT NULL, raw_data JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_batches_name(name), INDEX idx_batches_teacher(teacher_id),
 CONSTRAINT fk_batches_teacher FOREIGN KEY(teacher_id) REFERENCES teachers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS students (
 id BIGINT NOT NULL PRIMARY KEY, name VARCHAR(160) NOT NULL, class_name VARCHAR(80) NOT NULL DEFAULT '',
 guardian_phone VARCHAR(30) NOT NULL DEFAULT '', batch_id BIGINT NULL, teacher_id BIGINT NULL,
 monthly_fee DECIMAL(12,2) NOT NULL DEFAULT 0, due_day TINYINT UNSIGNED NOT NULL DEFAULT 10,
 joined_on DATE NULL, is_fees_advance TINYINT(1) NOT NULL DEFAULT 1,
 status ENUM('active','inactive') NOT NULL DEFAULT 'active', last_reminder DATETIME NULL,
 last_payment_date DATETIME NULL, raw_data JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_students_name(name), INDEX idx_students_class(class_name),
 INDEX idx_students_batch(batch_id), INDEX idx_students_teacher(teacher_id),
 INDEX idx_students_status_joined(status,joined_on),
 CONSTRAINT fk_students_batch FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE SET NULL,
 CONSTRAINT fk_students_teacher FOREIGN KEY(teacher_id) REFERENCES teachers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS student_children (
 id BIGINT NOT NULL PRIMARY KEY, family_id BIGINT NOT NULL, name VARCHAR(160) NOT NULL,
 class_name VARCHAR(80) NOT NULL DEFAULT '', batch_id BIGINT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
 raw_data JSON NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_children_family(family_id), INDEX idx_children_batch(batch_id),
 CONSTRAINT fk_children_family FOREIGN KEY(family_id) REFERENCES students(id) ON DELETE CASCADE,
 CONSTRAINT fk_children_batch FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS fee_payments (
 id BIGINT NOT NULL PRIMARY KEY, student_id BIGINT NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 fee_month DATE NOT NULL, paid_on DATETIME NOT NULL, method VARCHAR(80) NOT NULL DEFAULT '',
 note TEXT NULL, raw_data JSON NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_fees_student_month(student_id,fee_month), INDEX idx_fees_paid_on(paid_on),
 CONSTRAINT fk_fees_student FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS enquiries (
 id BIGINT NOT NULL PRIMARY KEY, student_name VARCHAR(160) NOT NULL, class_name VARCHAR(80) NOT NULL DEFAULT '',
 parent_name VARCHAR(160) NOT NULL DEFAULT '', phone VARCHAR(30) NOT NULL DEFAULT '', address TEXT NULL,
 enquiry_date DATE NULL, expected_join_date DATE NULL, notes TEXT NULL, status VARCHAR(40) NOT NULL DEFAULT 'New',
 source VARCHAR(120) NOT NULL DEFAULT '', converted_to_student_id BIGINT NULL, admission_date DATE NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 raw_data JSON NULL, INDEX idx_enquiries_status_created(status,created_at), INDEX idx_enquiries_phone(phone),
 CONSTRAINT fk_enquiries_student FOREIGN KEY(converted_to_student_id) REFERENCES students(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS attendance_sessions (
 id VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY, batch_id BIGINT NULL, batch_name VARCHAR(160) NOT NULL DEFAULT '',
 session_date DATE NOT NULL, present_count INT UNSIGNED NOT NULL DEFAULT 0,
 absent_count INT UNSIGNED NOT NULL DEFAULT 0, total_students INT UNSIGNED NOT NULL DEFAULT 0, raw_data JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_attendance_date_batch(session_date,batch_id),
 CONSTRAINT fk_attendance_batch FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS attendance_records (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, session_id VARCHAR(100) NOT NULL,
 student_id BIGINT NULL, attendance_status VARCHAR(30) NOT NULL,
 UNIQUE KEY uq_attendance_session_student(session_id,student_id), INDEX idx_attendance_student(student_id),
 CONSTRAINT fk_attendance_record_session FOREIGN KEY(session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE,
 CONSTRAINT fk_attendance_record_child FOREIGN KEY(student_id) REFERENCES student_children(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS expenses (
 id BIGINT NOT NULL PRIMARY KEY, amount DECIMAL(12,2) NOT NULL DEFAULT 0, description TEXT NOT NULL,
 category VARCHAR(120) NOT NULL DEFAULT 'Other', expense_date DATE NOT NULL, raw_data JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_expenses_date(expense_date), INDEX idx_expenses_category(category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS action_logs (
 id BIGINT NOT NULL PRIMARY KEY, message TEXT NOT NULL, log_date DATETIME NOT NULL, type VARCHAR(80) NOT NULL DEFAULT '',
 raw_data JSON NULL, INDEX idx_action_logs_date(log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS firestore_document_archive (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, collection_path VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 document_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, parent_document_path VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
 document_json JSON NOT NULL, source_updated_at DATETIME NULL, imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_archive_doc(collection_path(220),document_id,parent_document_path(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS migration_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, run_key CHAR(36) NOT NULL UNIQUE,
 status ENUM('started','completed','failed') NOT NULL DEFAULT 'started',
 source_project_id VARCHAR(190) NULL, started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL, summary_json JSON NULL, error_text TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
