<?php
// ============================================================
//  API/STUDENTS.PHP
//  Student CRUD operations
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/normalize.php';

// Require login
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
// Admin + registrar only
if (!in_array(getCurrentUserRole(), ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

try {
    $db = Database::getInstance();

    // NOTE: syncFatherMotherGuardians() is defined in shared/functions.php
    // (moved here from this file so the shared createStudentFromInput()
    // helper and the enrollments API can reuse it without redefinition).

    // ─── GET GUARDIAN ──────────────────────────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'guardian' && isset($_GET['student_id'])) {
        $guardian = $db->fetchOne("SELECT * FROM guardians WHERE student_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1", [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $guardian]);
        exit;
    }

    // ─── GET ALL GUARDIANS (Subsystem 2 — multi) ────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'guardians' && isset($_GET['student_id'])) {
        $guardians = $db->fetchAll(
            "SELECT * FROM guardians WHERE student_id = ? ORDER BY is_primary DESC, is_emergency DESC, id ASC",
            [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $guardians]);
        exit;
    }

    // ─── SAVE GUARDIAN (add or update) ──────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save-guardian') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        $gId = intval($input['id'] ?? 0);
        if (!$studentId) { echo json_encode(['success' => false, 'message' => 'Student required.']); exit; }
        if (!in_array($input['relationship'] ?? '', ['father','mother','guardian','spouse','sibling'], true)) {
            $input['relationship'] = 'guardian';
        }
        $gData = [
            'full_name'      => trim($input['full_name'] ?? ''),
            'relationship'   => $input['relationship'],
            'contact_number' => normalizePhone(trim((string) ($input['contact_number'] ?? ''))),
            'email'          => ($input['email'] ?? '') !== '' ? trim($input['email']) : null,
            'address'        => ($input['address'] ?? '') !== '' ? trim($input['address']) : null,
            'is_primary'     => !empty($input['is_primary']) ? 1 : 0,
            'is_emergency'   => !empty($input['is_emergency']) ? 1 : 0,
        ];
        if ($gData['full_name'] === '') { echo json_encode(['success' => false, 'message' => 'Guardian name required.']); exit; }
        if ($gData['contact_number'] === '' || !isValidPhone($gData['contact_number'])) { echo json_encode(['success' => false, 'message' => 'Guardian contact number is required and must be an 11-digit mobile number (e.g. 09171234567).']); exit; }
        if ($gId > 0) {
            $db->update('guardians', $gData, 'id = ? AND student_id = ?', [$gId, $studentId]);
        } else {
            // DEFENSIVE DE-DUP: a re-save of the same guardian (same
            // relationship + full name) must not create a clone. If an
            // identical row already exists for this student, update it
            // instead of inserting a duplicate.
            $dupe = $db->fetchOne(
                "SELECT id FROM guardians
                  WHERE student_id = ? AND relationship = ? AND full_name = ?
                  ORDER BY is_primary DESC, id ASC LIMIT 1",
                [$studentId, $gData['relationship'], $gData['full_name']]
            );
            if ($dupe) {
                $gId = (int) $dupe['id'];
                $db->update('guardians', $gData, 'id = ? AND student_id = ?', [$gId, $studentId]);
            } else {
                $gData['student_id'] = $studentId;
                $gId = $db->insert('guardians', $gData);
            }
        }
        echo json_encode(['success' => true, 'message' => 'Guardian saved.', 'data' => ['id' => (int) $gId]]);
        exit;
    }

    // ─── DELETE GUARDIAN ────────────────────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete-guardian') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Guardian ID required.']); exit; }
        $db->delete('guardians', 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Guardian deleted.']);
        exit;
    }

    // ─── EMERGENCY CONTACTS (Subsystem 2) ───────────────────────
    // GET ?action=emergency&student_id=N   → list
    // POST ?action=save-emergency         → add/update
    // POST ?action=delete-emergency       → remove
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'emergency' && isset($_GET['student_id'])) {
        $rows = $db->fetchAll(
            "SELECT * FROM emergency_contacts WHERE student_id = ? ORDER BY is_primary DESC, id ASC",
            [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save-emergency') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        $id = intval($input['id'] ?? 0);
        if (!$studentId) { echo json_encode(['success' => false, 'message' => 'Student required.']); exit; }
        $eData = [
            'full_name'      => trim($input['full_name'] ?? ''),
            'relationship'   => trim($input['relationship'] ?? ''),
            'contact_number' => normalizePhone(trim((string) ($input['contact_number'] ?? ''))),
            'address'        => ($input['address'] ?? '') !== '' ? trim($input['address']) : null,
            'is_primary'     => !empty($input['is_primary']) ? 1 : 0,
        ];
        if ($eData['full_name'] === '') { echo json_encode(['success' => false, 'message' => 'Contact name required.']); exit; }
        if ($eData['contact_number'] === '' || !isValidPhone($eData['contact_number'])) { echo json_encode(['success' => false, 'message' => 'Emergency contact number is required and must be an 11-digit mobile number (e.g. 09171234567).']); exit; }
        if ($id > 0) {
            $db->update('emergency_contacts', $eData, 'id = ? AND student_id = ?', [$id, $studentId]);
        } else {
            $eData['student_id'] = $studentId;
            $id = $db->insert('emergency_contacts', $eData);
        }
        echo json_encode(['success' => true, 'message' => 'Emergency contact saved.', 'data' => ['id' => $id]]);
        exit;
    }
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete-emergency') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Contact ID required.']); exit; }
        $db->delete('emergency_contacts', 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Emergency contact deleted.']);
        exit;
    }

    // ─── GET ACADEMIC HISTORY ──────────────────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'academic' && isset($_GET['student_id'])) {
        $data = $db->fetchAll("SELECT * FROM academic_history WHERE student_id = ? ORDER BY created_at DESC", [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    // ─── GET HEALTH ────────────────────────────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'health' && isset($_GET['student_id'])) {
        $data = $db->fetchOne("SELECT * FROM health_records WHERE student_id = ?", [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    // ─── GET DOCUMENT REQUESTS ─────────────────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'documents' && isset($_GET['student_id'])) {
        $data = $db->fetchAll("SELECT * FROM document_requests WHERE student_id = ? ORDER BY request_date DESC", [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    // ─── GET LAST SCAN ────────────────────────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'lastscan' && isset($_GET['student_id'])) {
        $student = $db->fetchOne("SELECT card_uid FROM rfid_cards WHERE student_id = ?", [intval($_GET['student_id'])]);
        if ($student) {
            $scan = $db->fetchOne("SELECT scanned_at, location, event_type, status FROM rfid_scan_logs WHERE card_uid = ? ORDER BY scanned_at DESC LIMIT 1", [$student['card_uid']]);
            echo json_encode(['success' => true, 'data' => $scan]);
        } else {
            echo json_encode(['success' => true, 'data' => null]);
        }
        exit;
    }

    // ─── GET ALL STUDENTS ──────────────────────────────────────
    if ($method === 'GET' && !$id) {
        $students = $db->fetchAll(
            "SELECT * FROM students ORDER BY created_at DESC"
        );
        echo json_encode(['success' => true, 'data' => $students]);
        exit;
    }

    // ─── GET SINGLE STUDENT ────────────────────────────────────
    if ($method === 'GET' && $id) {
        $student = $db->fetchOne(
            "SELECT * FROM students WHERE id = ?",
            [$id]
        );
        if ($student) {
            echo json_encode(['success' => true, 'data' => $student]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
        }
        exit;
    }

    // ─── PHOTO UPLOAD ─────────────────────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'upload-photo') {
        $studentId = intval($_POST['student_id'] ?? 0);
        if (!$studentId || !isset($_FILES['photo'])) {
            echo json_encode(['success' => false, 'message' => 'No file or student ID.']);
            exit;
        }
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type.']);
            exit;
        }
        $filename = 'student_' . $studentId . '_' . time() . '.' . $ext;
        $dest = __DIR__ . '/../uploads/students/' . $filename;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
            $photoUrl = '../uploads/students/' . $filename;
            $db->update('students', ['photo' => $photoUrl], 'id = ?', [$studentId]);
            echo json_encode(['success' => true, 'photo_url' => $photoUrl]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Upload failed.']);
        }
        exit;
    }

    // ─── SAVE ACADEMIC HISTORY ─────────────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save-academic') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        $schoolName = trim($input['school_name'] ?? '');
        if (!$studentId || $schoolName === '') {
            echo json_encode(['success' => false, 'message' => 'Student and school name are required.']);
            exit;
        }
        $data = [
            'student_id'        => $studentId,
            'school_name'       => $schoolName,
            'school_year'       => $input['school_year'] ?? null,
            'grade_level'       => $input['grade_level'] ?? null,
            'gwa'               => ($input['gwa'] ?? '') !== '' ? (float) $input['gwa'] : null,
            'subjects_completed'=> ($input['subjects_completed'] ?? '') !== '' ? (int) $input['subjects_completed'] : null,
            'semester'          => $input['semester'] ?? null,
            'remarks'           => $input['remarks'] ?? null
        ];
        // Guard optional columns (pre-migration safety)
        $cols = $db->fetchAll("SHOW COLUMNS FROM academic_history");
        $colNames = array_column($cols, 'Field');
        if (!in_array('semester', $colNames, true)) unset($data['semester']);

        $recordId = intval($input['id'] ?? 0);
        if ($recordId) {
            $db->update('academic_history', $data, 'id = ?', [$recordId]);
        } else {
            $recordId = $db->insert('academic_history', $data);
        }

        // Store per-subject grades (Subsystem 3) if provided
        if (isset($input['grades']) && is_array($input['grades'])) {
            $tblExists = (int) $db->fetchColumn("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades'");
            if ($tblExists) {
                // Remove existing grades for this record, then re-insert
                $db->delete('academic_grades', 'academic_history_id = ?', [$recordId]);
                foreach ($input['grades'] as $g) {
                    $subject = trim($g['subject'] ?? '');
                    if ($subject === '') continue;
                    $db->insert('academic_grades', [
                        'academic_history_id' => $recordId,
                        'subject'  => $subject,
                        'units'    => ($g['units'] ?? '') !== '' ? (float) $g['units'] : null,
                        'grade'    => ($g['grade'] ?? '') !== '' ? (float) $g['grade'] : null,
                        'remarks'  => ($g['remarks'] ?? '') !== '' ? trim($g['remarks']) : null,
                    ]);
                }
            }
            // Also recompute subjects_completed from actual grade count
            if (!empty($input['grades'])) {
                $db->update('academic_history', ['subjects_completed' => count($input['grades'])], 'id = ?', [$recordId]);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Academic record saved.', 'data' => ['id' => $recordId]]);
        exit;
    }

    // ─── GET ACADEMIC GRADES (Subsystem 3) ─────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'grades' && isset($_GET['record_id'])) {
        $grades = $db->fetchAll(
            "SELECT * FROM academic_grades WHERE academic_history_id = ? ORDER BY id ASC",
            [intval($_GET['record_id'])]);
        echo json_encode(['success' => true, 'data' => $grades]);
        exit;
    }

    // ─── DELETE ACADEMIC HISTORY ───────────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete-academic') {
        $input = json_decode(file_get_contents('php://input'), true);
        $recordId = intval($input['id'] ?? 0);
        if (!$recordId) {
            echo json_encode(['success' => false, 'message' => 'Record ID required.']);
            exit;
        }
        $db->delete('academic_history', 'id = ?', [$recordId]);
        echo json_encode(['success' => true, 'message' => 'Academic record deleted.']);
        exit;
    }

    // Import previous-school records from the enrollment intake into
    // academic_history (idempotent: existing schools are skipped).
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'import-academic') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        $student = $db->fetchOne("SELECT id, student_number FROM students WHERE id = ?", [$studentId]);
        if (!$student) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }
        $imported = 0;
        $encRows = $db->fetchAll(
            "SELECT prev_school_name, prev_school_last_year, prev_school_graduated_sy
             FROM enrollments
             WHERE student_number = ? AND status IN ('received','re-enrolled')
               AND prev_school_name IS NOT NULL AND TRIM(prev_school_name) != ''",
            [$student['student_number']]
        );
        foreach ($encRows as $enc) {
            $school = trim((string) $enc['prev_school_name']);
            if ($school === '') continue;
            $exists = $db->fetchOne(
                "SELECT id FROM academic_history WHERE student_id = ? AND TRIM(school_name) = ?",
                [$studentId, $school]
            );
            if ($exists) continue;
            $db->insert('academic_history', [
                'student_id'  => $studentId,
                'school_name' => $school,
                'school_year' => ($enc['prev_school_graduated_sy'] ?? '') !== '' ? $enc['prev_school_graduated_sy'] : null,
                'grade_level' => ($enc['prev_school_last_year'] ?? '') !== '' ? $enc['prev_school_last_year'] : null,
            ]);
            $imported++;
        }
        echo json_encode([
            'success' => true,
            'message' => $imported > 0 ? 'Imported ' . $imported . ' previous-school record(s).' : 'No new records to import.',
            'data'    => ['imported' => $imported],
        ]);
        exit;
    }

    // ─── SAVE HEALTH RECORD ────────────────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save-health') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        if (!$studentId) {
            echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
            exit;
        }
        $data = [
            'blood_type'              => $input['blood_type'] ?? null,
            'allergies'               => $input['allergies'] ?? null,
            'pre_existing_conditions' => $input['pre_existing_conditions'] ?? null,
            'immunization_records'    => $input['immunization_records'] ?? null,
            'height'                  => ($input['height'] ?? '') !== '' ? (float) $input['height'] : null,
            'weight'                  => ($input['weight'] ?? '') !== '' ? (float) $input['weight'] : null,
            'notes'                   => $input['notes'] ?? null
        ];
        $existing = $db->fetchOne("SELECT id FROM health_records WHERE student_id = ?", [$studentId]);
        if ($existing) {
            $db->update('health_records', $data, 'student_id = ?', [$studentId]);
            $recordId = $existing['id'];
        } else {
            $data['student_id'] = $studentId;
            $recordId = $db->insert('health_records', $data);
        }
        echo json_encode(['success' => true, 'message' => 'Health record saved.', 'data' => ['id' => $recordId]]);
        exit;
    }

    // ─── HEALTH RECORD ── include blood_pressure / dietary_restrictions
    //     (columns added by registrar_upgrade.sql — guarded with branch)
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save-health-full') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        if (!$studentId) { echo json_encode(['success' => false, 'message' => 'Student ID is required.']); exit; }
        $data = [
            'blood_type'              => $input['blood_type'] ?? null,
            'allergies'               => $input['allergies'] ?? null,
            'pre_existing_conditions' => $input['pre_existing_conditions'] ?? null,
            'immunization_records'    => $input['immunization_records'] ?? null,
            'height'                  => ($input['height'] ?? '') !== '' ? (float) $input['height'] : null,
            'weight'                  => ($input['weight'] ?? '') !== '' ? (float) $input['weight'] : null,
            'blood_pressure'          => $input['blood_pressure'] ?? null,
            'dietary_restrictions'    => $input['dietary_restrictions'] ?? null,
            'notes'                   => $input['notes'] ?? null
        ];
        $existing = $db->fetchOne("SELECT id FROM health_records WHERE student_id = ?", [$studentId]);
        if ($existing) {
            $db->update('health_records', $data, 'student_id = ?', [$studentId]);
            $recordId = $existing['id'];
        } else {
            $data['student_id'] = $studentId;
            $recordId = $db->insert('health_records', $data);
        }
        echo json_encode(['success' => true, 'message' => 'Health record saved.', 'data' => ['id' => $recordId]]);
        exit;
    }

    // ─── HEALTH VISITS (Subsystem 4 — timeline) ────────────────
    // GET ?action=visits&student_id=N   → list visits
    // POST ?action=add-visit            → create a visit
    // POST ?action=delete-visit         → remove a visit
    $hasVisits = (int) $db->fetchColumn("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits'") === 1;
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'visits' && isset($_GET['student_id'])) {
        if (!$hasVisits) { echo json_encode(['success' => true, 'data' => []]); exit; }
        $visits = $db->fetchAll(
            "SELECT * FROM health_visits WHERE student_id = ? ORDER BY visit_date DESC, id DESC",
            [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $visits]);
        exit;
    }

    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'add-visit') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        if (!$studentId) { echo json_encode(['success' => false, 'message' => 'Student ID is required.']); exit; }
        if (!$hasVisits) { echo json_encode(['success' => false, 'message' => 'Run registrar_upgrade.sql first (health_visits table missing).']); exit; }
        $id = $db->insert('health_visits', [
            'student_id'     => $studentId,
            'visit_date'     => ($input['visit_date'] ?? '') !== '' ? $input['visit_date'] : date('Y-m-d'),
            'complaint'      => $input['complaint'] ?? null,
            'diagnosis'      => $input['diagnosis'] ?? null,
            'temperature'    => ($input['temperature'] ?? '') !== '' ? (float) $input['temperature'] : null,
            'blood_pressure' => $input['blood_pressure'] ?? null,
            'treatment'      => $input['treatment'] ?? null,
            'medication'     => $input['medication'] ?? null,
            'physician'      => $input['physician'] ?? null,
            'notes'          => $input['notes'] ?? null,
            'created_at'     => date('Y-m-d H:i:s')
        ]);
        // bump clinic_visits counter
        $db->getConnection()->exec("UPDATE health_records hr SET hr.clinic_visits = COALESCE(hr.clinic_visits,0) + 1 WHERE hr.student_id = " . intval($studentId));
        echo json_encode(['success' => true, 'message' => 'Visit logged.', 'data' => ['id' => $id]]);
        exit;
    }

    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete-visit') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Visit ID required.']); exit; }
        $db->delete('health_visits', 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Visit deleted.']);
        exit;
    }

    // ─── BULK STATUS UPDATE ────────────────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'bulk-status') {
        $input = json_decode(file_get_contents('php://input'), true);
        $ids = $input['ids'] ?? [];
        $status = $input['status'] ?? '';
        if (empty($ids) || !$status) {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }
        foreach ($ids as $sid) {
            $db->update('students', ['status' => $status], 'id = ?', [intval($sid)]);
            trackStatusChange(intval($sid), $status, 'Bulk status update');
        }
        echo json_encode(['success' => true, 'message' => count($ids) . ' student(s) updated.']);
        exit;
    }

    // ─── CREATE STUDENT ────────────────────────────────────────
    // Delegates to the shared createStudentFromInput() helper (shared/
    // functions.php) so the manual Add form and the Receive-Student
    // Accept flow share one code path.
    // Resend the portal welcome email (also resets the temporary password).
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'resend_welcome_email') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        if (!$studentId) { echo json_encode(['success' => false, 'message' => 'Student required.']); exit; }
        $student = $db->fetchOne("SELECT * FROM students WHERE id = ?", [$studentId]);
        if (!$student) { echo json_encode(['success' => false, 'message' => 'Student not found.']); exit; }
        if (trim((string) ($student['email'] ?? '')) === '' || !isValidEmail((string) $student['email'])) {
            echo json_encode(['success' => false, 'message' => 'This student has no valid email address - add one first.']); exit;
        }
        $portalUser = $db->fetchOne("SELECT * FROM users WHERE student_id = ? AND role = 'student' ORDER BY id ASC LIMIT 1", [$studentId]);
        $firstName = trim((string) $student['first_name']);
        $birthYear = (int) substr(trim((string) $student['birth_date']), 0, 4);
        $firstTwo = mb_strtolower(mb_substr($firstName, 0, 2));
        $password = '#' . $firstTwo . $birthYear;
        if ($portalUser) {
            $username = (string) ($portalUser['username'] ?? '');
            $db->update('users', [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'updated_at'    => date('Y-m-d H:i:s'),
            ], 'id = ?', [$portalUser['id']]);
        } else {
            $idDigits = preg_replace('/[^0-9]/', '', (string) $student['student_number']);
            $id9 = substr($idDigits, -9);
            $username = mb_strtolower(mb_substr($firstName, 0, 1)) . $id9;
            $dup = $db->fetchOne("SELECT id FROM users WHERE username = ?", [$username]);
            if ($dup) { $username .= '_' . date('ymd'); }
            $db->insert('users', [
                'email'         => strtolower(trim((string) $student['email'])),
                'username'      => strtolower($username),
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'full_name'     => trim((string) $student['first_name'] . ' ' . $student['last_name']),
                'role'          => 'student',
                'student_id'    => (int) $student['id'],
                'is_active'     => 1,
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
        }
        $account = [
            'username' => $username,
            'email'    => strtolower(trim((string) $student['email'])),
            'password' => $password,
            'full_name'=> trim((string) $student['first_name'] . ' ' . $student['last_name']),
        ];
        if (!function_exists('sendStudentWelcomeEmail')) {
            $ml = __DIR__ . '/../shared/mail_client.php';
            if (is_file($ml)) require_once $ml;
        }
        if (!function_exists('sendStudentWelcomeEmail') || !emailConfigured()) {
            echo json_encode(['success' => false, 'message' => 'Email sending is not configured (SMTP).']); exit;
        }
        try {
            $sent = sendStudentWelcomeEmail(
                ['id' => (int) $student['id'], 'student_number' => (string) $student['student_number']],
                $account,
                (int) ($_SESSION['user_id'] ?? 0)
            );
        } catch (Throwable $e) {
            error_log('[students.php] resend welcome failed: ' . $e->getMessage());
            $sent = ['sent' => false];
        }
        logActivity($_SESSION['user_id'] ?? 0, 'student_welcome_resend', json_encode(['student_id' => $studentId, 'sent' => !empty($sent['sent'])]), 'users', (int) ($portalUser['id'] ?? $studentId));
        echo json_encode([
            'success' => !empty($sent['sent']),
            'message' => !empty($sent['sent']) ? 'Welcome email sent to ' . trim((string) $student['email']) . '. Temporary password was reset.' : 'Email could not be sent. Check the PHP error log.'
        ]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request payload.']);
            exit;
        }

        try {
            $created = createStudentFromInput($input, $db);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        } catch (RuntimeException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
            error_log('[students.php] createStudentFromInput failed: ' . $e->getMessage());
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Student added successfully.',
            'data' => [
                'id'             => $created['id'],
                'student_number' => $created['student_number'],
                'portal_account' => $created['portal_account'],
            ],
        ]);
        exit;
    }

    // ─── UPDATE STUDENT ────────────────────────────────────────
    if ($method === 'PUT' && $id) {
        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!is_array($input)) {
                echo json_encode(['success' => false, 'message' => 'Invalid request payload.']);
                exit;
            }

            $existing = $db->fetchOne("SELECT id, year_level FROM students WHERE id = ?", [$id]);
            if (!$existing) {
                echo json_encode(['success' => false, 'message' => 'Student not found.']);
                exit;
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading student: ' . $e->getMessage()]);
            exit;
        }

        try {
            $data = [];
            $allowedFields = ['first_name', 'middle_name', 'last_name', 'gender', 'civil_status', 'birth_date', 'place_of_birth',
                              'birth_country', 'lrn', 'name_suffix', 'mother_name', 'father_name',
                              'nationality', 'religion', 'address', 'contact_number', 'email',
                              'course', 'major', 'year_level', 'school_year', 'semester', 'section', 'adviser_id', 'status',
                              'student_number'];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $input)) {
                    $value = $input[$field];
                    if ($value === '' && in_array($field, ['birth_date', 'middle_name', 'place_of_birth', 'birth_country', 'nationality', 'religion', 'contact_number', 'email', 'course', 'major', 'year_level', 'school_year', 'semester', 'section', 'adviser_id', 'lrn', 'name_suffix', 'mother_name', 'father_name'], true)) {
                        $value = null;
                    }
                    if ($field === 'birth_date' && $value === '0000-00-00') {
                        $value = null;
                    }
                    if ($field === 'year_level' && $value !== null && $value !== '') {
                        $value = (int)$value;
                    } elseif ($field === 'year_level' && $value === '') {
                        $value = null;
                    }
                    if ($field === 'adviser_id' && $value !== null) {
                        $value = (int)$value;
                    }
                    // A section code encodes the year level ([year][sem][###]),
                    // so a section is meaningless without a year level. Reject
                    // the combination rather than storing a misleading record.
                    if ($field === 'section' && $value !== null && $value !== '') {
                        // Fall back to the stored year level when a partial
                        // update doesn't resend it.
                        $effectiveYear = array_key_exists('year_level', $input)
                            ? trim((string) ($input['year_level'] ?? ''))
                            : trim((string) ($existing['year_level'] ?? ''));
                        if ($effectiveYear === '' || (int) $effectiveYear < 1) {
                            echo json_encode(['success' => false, 'message' => 'A section cannot be set without a year level. Set the year level first.']);
                            exit;
                        }
                    }
                    if ($field === 'lrn' && $value !== null && $value !== '') {
                        $value = strtoupper(preg_replace('/[^0-9]/', '', (string)$value));
                    }
                    // B3: normalize text fields before saving.
                    if ($value !== null && $value !== '') {
                        if (in_array($field, ['first_name', 'middle_name', 'last_name', 'place_of_birth', 'religion', 'mother_name', 'father_name'], true)) {
                            $value = normalizeNameCase((string) $value);
                        } elseif ($field === 'contact_number') {
                            $value = normalizePhone((string) $value);
                            if ($value === '' || !isValidPhone($value)) {
                                echo json_encode(['success' => false, 'message' => 'Contact number is required and must be an 11-digit mobile number (e.g. 09171234567).']);
                                exit;
                            }
                        } elseif ($field === 'email') {
                            $value = strtolower(trim((string) $value));
                        } elseif ($field === 'course') {
                            $value = courseStandardize((string) $value);
                        } elseif ($field === 'address' || $field === 'nationality' || $field === 'major' || $field === 'school_year' || $field === 'birth_country' || $field === 'name_suffix') {
                            $value = trim((string) $value);
                        }
                    }
                    $data[$field] = $value;
                }
            }

            if (empty($data)) {
                echo json_encode(['success' => false, 'message' => 'No data to update.']);
                exit;
            }

            // Email is required on every update (welcome email needs it).
            if (array_key_exists('email', $data)) {
                if ($data['email'] === null || trim((string) $data['email']) === '') {
                    echo json_encode(['success' => false, 'message' => 'Email is required.']);
                    exit;
                }
                if (!isValidEmail((string) $data['email'])) {
                    echo json_encode(['success' => false, 'message' => 'A valid email address is required.']);
                    exit;
                }
            }
            $db->update('students', $data, 'id = ?', [$id]);
            // Track status change in status_tracker
            if (array_key_exists('status', $data)) {
                trackStatusChange($id, $data['status'], $input['status_reason'] ?? null);
            }
            // Update guardian if provided
            $guardianName = trim($input['guardian_name'] ?? '');
            $gContact = trim((string) ($input['guardian_contact'] ?? ''));
            if ($gContact !== '') {
                $gContact = normalizePhone($gContact);
                if (!isValidPhone($gContact)) {
                    echo json_encode(['success' => false, 'message' => 'Guardian contact number must be an 11-digit mobile number (e.g. 09171234567).']);
                    exit;
                }
            }
            if ($guardianName !== '') {
                if ($gContact === '') {
                    echo json_encode(['success' => false, 'message' => 'Guardian contact number is required and must be an 11-digit mobile number (e.g. 09171234567).']);
                    exit;
                }
                $existingGuardian = $db->fetchOne("SELECT id FROM guardians WHERE student_id = ?", [$id]);
                $gData = [
                    'full_name' => $guardianName,
                    'relationship' => $input['guardian_relationship'] ?? 'guardian',
                    'contact_number' => $gContact,
                    'email' => $input['guardian_email'] ?? null
                ];
                if ($existingGuardian) {
                    $db->update('guardians', $gData, 'student_id = ?', [$id]);
                } else {
                    $gData['student_id'] = $id;
                    $db->insert('guardians', $gData);
                }
            }
            // NOTE: Father/Mother → guardian auto-sync runs at ENROLLMENT
            // only (in the create path). It deliberately does NOT run on
            // updates here: re-running it would resurrect a parent guardian
            // the registrar deleted on the Contacts page, whenever the
            // student record is saved again. After enrollment the guardian
            // list is owned by the registrar (Contacts page + the
            // "Auto-fill from Enrollment" action).
            echo json_encode(['success' => true, 'message' => 'Student updated successfully.']);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error updating student: ' . $e->getMessage()]);
            exit;
        }
    }

    // ─── SOFT DELETE STUDENT ────────────────────────────────────
    if ($method === 'DELETE' && $id) {
        $existing = $db->fetchOne("SELECT id FROM students WHERE id = ?", [$id]);
        if (!$existing) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }

        $db->update('students', ['status' => 'archived'], 'id = ?', [$id]);
        trackStatusChange($id, 'archived', 'Student deactivated');
        echo json_encode(['success' => true, 'message' => 'Student deactivated.']);
        exit;
    }

    // ─── INVALID REQUEST ───────────────────────────────────────
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);

} catch (Exception $e) {
    json_error($e);
}
?>
