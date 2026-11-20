<?php
declare(strict_types=1);

/*
 * Temporary one-time Firestore JSONL importer.
 *
 * Upload the JSONL + manifest into migration/exports/ (outside public/).
 * Configure UCC_IMPORT_KEY in the project-root .env with a long random value.
 * Open /ucc/import_firestore_once.php, run once, then DELETE this file and
 * remove UCC_IMPORT_KEY from .env.
 *
 * This importer intentionally skips actionLogs.
 */

define('UCC_IMPORT_ROOT', dirname(__DIR__));

function ucc_import_env(): array {
    $values = [];
    $file = UCC_IMPORT_ROOT . '/.env';
    if (!is_file($file)) {
        throw new RuntimeException('Project .env file was not found.');
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim(trim($value), "'\\" ");
    }
    return $values;
}

function ucc_decode_import_value(mixed $value): mixed {
    if (is_array($value)) {
        if (($value['__ucc_type__'] ?? null) === 'timestamp') {
            $raw = (string)($value['value'] ?? '');
            try { return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
            catch (Throwable) { return null; }
        }
        if (($value['__ucc_type__'] ?? null) === 'document_reference') return $value['path'] ?? null;
        if (($value['__ucc_type__'] ?? null) === 'bytes') return $value['value'] ?? null;
        foreach ($value as $k => $v) $value[$k] = ucc_decode_import_value($v);
    }
    return $value;
}

function ucc_int_id(mixed $documentId, array $data): ?int {
    if (is_numeric($documentId)) return (int)$documentId;
    if (isset($data['id']) && is_numeric($data['id'])) return (int)$data['id'];
    return null;
}

function ucc_date(mixed $value, ?string $fallback = null): ?string {
    $value = ucc_decode_import_value($value);
    if ($value === null || $value === '') return $fallback;
    try { return (new DateTimeImmutable((string)$value))->format('Y-m-d'); }
    catch (Throwable) { return $fallback; }
}

function ucc_datetime(mixed $value, ?string $fallback = null): ?string {
    $value = ucc_decode_import_value($value);
    if ($value === null || $value === '') return $fallback;
    try { return (new DateTimeImmutable((string)$value))->format('Y-m-d H:i:s'); }
    catch (Throwable) { return $fallback; }
}

function ucc_bool(mixed $value, bool $default = true): int {
    if (is_bool($value)) return $value ? 1 : 0;
    if (is_numeric($value)) return (float)$value != 0.0 ? 1 : 0;
    if (is_string($value)) {
        $v = strtolower(trim($value));
        if (in_array($v, ['true','1','yes'], true)) return 1;
        if (in_array($v, ['false','0','no'], true)) return 0;
    }
    return $default ? 1 : 0;
}

function ucc_upsert(PDO $db, string $table, array $row): void {
    $columns = array_keys($row);
    $quoted = array_map(static fn($c) => '`' . str_replace('`', '``', $c) . '`', $columns);
    $updates = [];
    foreach ($columns as $column) {
        if ($column !== 'id') $updates[] = '`' . $column . '`=VALUES(`' . $column . '`)';
    }
    if (!$updates) $updates[] = '`id`=VALUES(`id`)';
    $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $quoted) . ') VALUES (' .
        implode(',', array_fill(0, count($columns), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
    $stmt = $db->prepare($sql);
    $stmt->execute(array_values($row));
}

function ucc_existing_id(PDO $db, string $table, mixed $id): ?int {
    if (!is_numeric($id) || (int)$id <= 0) return null;
    $stmt = $db->prepare("SELECT id FROM `$table` WHERE id=? LIMIT 1");
    $stmt->execute([(int)$id]);
    return $stmt->fetchColumn() !== false ? (int)$id : null;
}

function ucc_import_record(PDO $db, array $record, array &$warnings): void {
    $path = (string)($record['collection_path'] ?? '');
    $docId = (string)($record['document_id'] ?? '');
    if ($path === '' || $docId === '') throw new RuntimeException('Record has no collection_path or document_id.');
    if (explode('/', $path)[0] === 'actionLogs') return;

    $raw = $record['data'] ?? [];
    if (!is_array($raw)) $raw = [];
    $data = ucc_decode_import_value($raw);
    if (!is_array($data)) $data = [];

    $archive = $db->prepare(
        'INSERT INTO firestore_document_archive(collection_path,document_id,parent_document_path,document_json) VALUES(?,?,?,?) ' .
        'ON DUPLICATE KEY UPDATE document_json=VALUES(document_json),imported_at=CURRENT_TIMESTAMP'
    );
    $archive->execute([$path, $docId, (string)($record['parent_document_path'] ?? ''), json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);

    // Root collections are imported into typed application tables.
    if (str_contains($path, '/')) return;
    $id = ucc_int_id($docId, $data);

    switch ($path) {
        case 'teachers':
            if ($id === null) { $warnings[] = "$path/$docId: non-numeric ID; archived only"; break; }
            ucc_upsert($db, 'teachers', [
                'id'=>$id, 'name'=>(string)($data['name'] ?? ''), 'subject'=>(string)($data['subject'] ?? ''),
                'phone'=>(string)($data['phone'] ?? ''), 'raw_data'=>json_encode($raw, JSON_UNESCAPED_UNICODE)
            ]);
            break;

        case 'batches':
            if ($id === null) { $warnings[] = "$path/$docId: non-numeric ID; archived only"; break; }
            ucc_upsert($db, 'batches', [
                'id'=>$id, 'name'=>(string)($data['name'] ?? ''), 'subject'=>(string)($data['subject'] ?? ''),
                'schedule'=>(string)($data['schedule'] ?? ''), 'teacher_id'=>ucc_existing_id($db, 'teachers', $data['teacherId'] ?? null),
                'raw_data'=>json_encode($raw, JSON_UNESCAPED_UNICODE)
            ]);
            break;

        case 'students':
            if ($id === null) { $warnings[] = "$path/$docId: non-numeric ID; archived only"; break; }
            $joined = ucc_date($data['joinedOn'] ?? null, date('Y-m-d'));
            $dueDay = max(1, min(31, (int)($data['dueDay'] ?? 10)));
            ucc_upsert($db, 'students', [
                'id'=>$id, 'name'=>(string)($data['name'] ?? ''), 'class_name'=>(string)($data['className'] ?? $data['class'] ?? ''),
                'guardian_phone'=>(string)($data['guardianPhone'] ?? $data['phone'] ?? ''),
                'batch_id'=>ucc_existing_id($db, 'batches', $data['batchId'] ?? null),
                'teacher_id'=>ucc_existing_id($db, 'teachers', $data['teacherId'] ?? null),
                'monthly_fee'=>(float)($data['monthlyFee'] ?? 0), 'due_day'=>$dueDay, 'joined_on'=>$joined,
                'is_fees_advance'=>ucc_bool($data['isFeeAdvance'] ?? $data['isFeesAdvance'] ?? true),
                'status'=>(($data['status'] ?? '') === 'inactive' ? 'inactive' : 'active'),
                'last_reminder'=>ucc_datetime($data['lastReminder'] ?? null),
                'last_payment_date'=>ucc_datetime($data['lastPaymentDate'] ?? null),
                'raw_data'=>json_encode($raw, JSON_UNESCAPED_UNICODE)
            ]);
            $children = is_array($data['children'] ?? null) ? $data['children'] : [];
            if (!$children) {
                $children = [[
                    'id'=>$id, 'name'=>$data['name'] ?? 'Student', 'className'=>$data['className'] ?? $data['class'] ?? '',
                    'batchId'=>$data['batchId'] ?? null, 'isActive'=>true
                ]];
            }
            foreach ($children as $child) {
                if (!is_array($child)) continue;
                $childId = ucc_int_id($child['id'] ?? $id, $child) ?? $id;
                $check = $db->prepare('SELECT family_id FROM student_children WHERE id=?');
                $check->execute([$childId]);
                $owner = $check->fetchColumn();
                if ($owner !== false && (int)$owner !== $id) {
                    $warnings[] = "$path/$docId: child ID $childId belongs to another family; archived only";
                    continue;
                }
                ucc_upsert($db, 'student_children', [
                    'id'=>$childId, 'family_id'=>$id, 'name'=>(string)($child['name'] ?? ''),
                    'class_name'=>(string)($child['className'] ?? $child['class_name'] ?? ''),
                    'batch_id'=>ucc_existing_id($db, 'batches', $child['batchId'] ?? $child['batch_id'] ?? $data['batchId'] ?? null),
                    'is_active'=>ucc_bool($child['isActive'] ?? $child['is_active'] ?? true),
                    'raw_data'=>json_encode($child, JSON_UNESCAPED_UNICODE)
                ]);
            }
            break;

        case 'fees':
            $studentId = $data['studentId'] ?? null;
            if ($id === null || !is_numeric($studentId) || (int)$studentId <= 0) {
                $warnings[] = "$path/$docId: missing/invalid fee ID or studentId; archived only";
                break;
            }
            $month = ucc_date($data['feeForMonth'] ?? $data['month'] ?? null, date('Y-m-01'));
            $month = substr((string)$month, 0, 7) . '-01';
            ucc_upsert($db, 'fee_payments', [
                'id'=>$id, 'student_id'=>(int)$studentId, 'amount'=>(float)($data['amount'] ?? 0),
                'fee_month'=>$month, 'paid_on'=>ucc_datetime($data['date'] ?? null, date('Y-m-d H:i:s')),
                'method'=>(string)($data['method'] ?? ''), 'note'=>(string)($data['note'] ?? ''),
                'raw_data'=>json_encode($raw, JSON_UNESCAPED_UNICODE)
            ]);
            break;

        case 'enquiries':
            if ($id === null) { $warnings[] = "$path/$docId: non-numeric ID; archived only"; break; }
            ucc_upsert($db, 'enquiries', [
                'id'=>$id, 'student_name'=>(string)($data['studentName'] ?? ''), 'class_name'=>(string)($data['className'] ?? $data['class'] ?? ''),
                'parent_name'=>(string)($data['parentName'] ?? ''), 'phone'=>(string)($data['phone'] ?? $data['parentMobile'] ?? ''),
                'address'=>(string)($data['address'] ?? ''), 'enquiry_date'=>ucc_date($data['enquiryDate'] ?? null),
                'expected_join_date'=>ucc_date($data['expectedJoinDate'] ?? null), 'notes'=>(string)($data['notes'] ?? ''),
                'status'=>(string)($data['status'] ?? 'New'), 'source'=>(string)($data['source'] ?? ''),
                'converted_to_student_id'=>ucc_existing_id($db, 'students', $data['convertedToStudentId'] ?? null),
                'admission_date'=>ucc_date($data['admissionDate'] ?? null), 'raw_data'=>json_encode($raw, JSON_UNESCAPED_UNICODE)
            ]);
            break;

        case 'attendance':
            $batchId = ucc_existing_id($db, 'batches', $data['batchId'] ?? null);
            $rawAttendance = $data['attendance'] ?? [];
            $items = [];
            if (is_array($rawAttendance)) {
                foreach ($rawAttendance as $key => $value) {
                    if (is_array($value) && isset($value['studentId'])) {
                        $items[(string)$value['studentId']] = (string)($value['status'] ?? '');
                    } elseif (!is_array($value) && !is_int($key)) {
                        $items[(string)$key] = (string)$value;
                    }
                }
            }
            $sessionDate = ucc_date($data['date'] ?? null, date('Y-m-d'));
            $present = (int)($data['presentCount'] ?? count(array_filter($items, fn($v)=>$v==='present')));
            $absent = (int)($data['absentCount'] ?? count(array_filter($items, fn($v)=>$v==='absent')));
            $total = (int)($data['totalStudents'] ?? count($items));
            $db->prepare(
                'INSERT INTO attendance_sessions(id,batch_id,batch_name,session_date,present_count,absent_count,total_students,raw_data) VALUES(?,?,?,?,?,?,?,?) ' .
                'ON DUPLICATE KEY UPDATE batch_id=VALUES(batch_id),batch_name=VALUES(batch_name),session_date=VALUES(session_date),present_count=VALUES(present_count),absent_count=VALUES(absent_count),total_students=VALUES(total_students),raw_data=VALUES(raw_data)'
            )->execute([$docId, $batchId, (string)($data['batchName'] ?? ''), $sessionDate, $present, $absent, $total,
                json_encode($raw, JSON_UNESCAPED_UNICODE)]);
            $db->prepare('DELETE FROM attendance_records WHERE session_id=?')->execute([$docId]);
            foreach ($items as $studentId => $status) {
                if (!in_array($status, ['present','absent','leave'], true) || !is_numeric($studentId)) continue;
                $childId = (int)$studentId;
                // Attendance references student_children in this schema; skip orphan IDs safely.
                if (ucc_existing_id($db, 'student_children', $childId) === null) {
                    $warnings[] = "$path/$docId: attendance child $childId not found; detail archived only";
                    continue;
                }
                $db->prepare('INSERT INTO attendance_records(session_id,student_id,attendance_status) VALUES(?,?,?)')
                   ->execute([$docId, $childId, $status]);
            }
            break;

        case 'expenses':
            if ($id === null) { $warnings[] = "$path/$docId: non-numeric ID; archived only"; break; }
            ucc_upsert($db, 'expenses', [
                'id'=>$id, 'amount'=>(float)($data['amount'] ?? 0), 'description'=>(string)($data['description'] ?? ''),
                'category'=>(string)($data['category'] ?? 'Other'), 'expense_date'=>ucc_date($data['date'] ?? null, date('Y-m-d')),
                'raw_data'=>json_encode($raw, JSON_UNESCAPED_UNICODE)
            ]);
            break;
    }
}

function ucc_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    $env = ucc_import_env();
    $expectedKey = (string)($env['UCC_IMPORT_KEY'] ?? '');
    if ($expectedKey === '') throw new RuntimeException('Set UCC_IMPORT_KEY in project-root .env before using this importer.');
    $providedKey = (string)($_POST['import_key'] ?? '');
    $authenticated = $_SERVER['REQUEST_METHOD'] === 'POST' &&
        $providedKey !== '' && hash_equals($expectedKey, $providedKey);

    $status = '';
    $report = null;
    if ($authenticated) {
        if (!isset($_POST['confirm_import']) || $_POST['confirm_import'] !== 'yes') {
            throw new RuntimeException('Please confirm the one-time import checkbox.');
        }
        $lockPath = UCC_IMPORT_ROOT . '/storage/firestore-import.lock';
        $lock = fopen($lockPath, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('An import is already running.');
        $jsonl = UCC_IMPORT_ROOT . '/migration/exports/firestore-final-20261120-144632.jsonl';
        $manifestPath = UCC_IMPORT_ROOT . '/migration/exports/firestore-final-20261120-144632.manifest.json';
        if (!is_file($jsonl) || !is_file($manifestPath)) throw new RuntimeException('Upload the exact JSONL snapshot and matching manifest into migration/exports/.');
        $manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['project_id'] ?? '') !== 'uccapp-5411e' ||
            ($manifest['total_documents'] ?? 0) !== 591 ||
            isset($manifest['collections']['actionLogs'])) {
            throw new RuntimeException('Manifest validation failed: expected UCC snapshot with 591 documents and no actionLogs.');
        }
        $dbName = $env['DB_NAME'] ?? '';
        $dbUser = $env['DB_USER'] ?? '';
        $dbHost = $env['DB_HOST'] ?? 'localhost';
        $dbPort = $env['DB_PORT'] ?? '3306';
        if ($dbName === '' || $dbUser === '') throw new RuntimeException('Database configuration is incomplete.');
        $db = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4", $dbUser, $env['DB_PASS'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $required = ['teachers','batches','students','student_children','fee_payments','enquiries','attendance_sessions','attendance_records','expenses','firestore_document_archive','migration_runs'];
        foreach ($required as $table) {
            $check = $db->query("SHOW TABLES LIKE " . $db->quote($table))->fetchColumn();
            if (!$check) throw new RuntimeException("Required table is missing: $table. Import database/schema.sql first.");
        }
        $runKey = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0,65535),random_int(0,65535),random_int(0,65535),random_int(0,65535),
            random_int(0,65535),random_int(0,65535),random_int(0,65535),random_int(0,65535));
        $db->prepare("INSERT INTO migration_runs(run_key,status,source_project_id) VALUES(?,'started',?)")
           ->execute([$runKey, 'uccapp-5411e']);
        $db->commit();
        $db->beginTransaction();
        $counts = [];
        $warnings = [];
        $started = microtime(true);
        $lineNo = 0;
        $handle = fopen($jsonl, 'rb');
        if (!$handle) throw new RuntimeException('Cannot read JSONL snapshot.');
        while (($line = fgets($handle)) !== false) {
            $lineNo++;
            if (trim($line) === '') continue;
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (explode('/', (string)($record['collection_path'] ?? ''))[0] === 'actionLogs') continue;
            ucc_import_record($db, $record, $warnings);
            $key = (string)$record['collection_path'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        fclose($handle);
        $db->commit();
        $summary = json_encode(['total_documents'=>array_sum($counts),'collections'=>$counts,'warnings'=>$warnings], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $db->prepare("UPDATE migration_runs SET status='completed',completed_at=UTC_TIMESTAMP(),summary_json=? WHERE run_key=?")
           ->execute([$summary, $runKey]);
        $report = ['ok'=>true,'run_id'=>$runKey,'total_documents'=>array_sum($counts),'collections'=>$counts,'warnings'=>$warnings,'duration_seconds'=>round(microtime(true)-$started,2)];
        flock($lock, LOCK_UN);
        fclose($lock);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>UCC Import Complete</title><body style="font:16px system-ui;max-width:800px;margin:40px auto;padding:20px"><h1>Firestore import completed</h1><pre>' . ucc_h(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre><p>Now delete public/import_firestore_once.php and remove UCC_IMPORT_KEY from .env.</p></body></html>';
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>UCC Firestore Import</title><body style="font:16px system-ui;max-width:700px;margin:40px auto;padding:20px"><h1>UCC Firestore Import</h1><p>This one-time tool imports the verified UCC snapshot, skips actionLogs, and writes documents to the archive and typed tables.</p><form method="post"><label>Import key<br><input type="password" name="import_key" required style="width:100%;padding:10px"></label><p><label><input type="checkbox" name="confirm_import" value="yes" required> I have backed up the target MySQL database and want to import the 591-document snapshot.</label></p><button type="submit">Start Import</button></form></body></html>';
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Import Error</title><body style="font:16px system-ui;max-width:800px;margin:40px auto;padding:20px"><h1>Import not completed</h1><pre>' . ucc_h($e->getMessage()) . '</pre><p>No success is claimed. Check the hosting PHP error log for technical details.</p></body></html>';
}
