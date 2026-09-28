<?php
// ============================================================
//  API/CLINIC.PHP
//  Health Record Log — Clinic Portal API.
//    POST  identify     → resolve student from RFID card UID
//                         (nurse kiosk tap-in)
//    GET   visits       → list a student's clinic visits
//    POST  save-visit   → log a clinic visit (nurse)
//    GET   log          → filterable record feed (registrar
//                         view-only "Health Record Log")
//
//  Roles: identify & visits → nurse / registrar / admin
//         save-visit        → nurse / admin
//         log               → registrar / admin / nurse
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$role = getCurrentUserRole();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

function requireClinicRoles(array $allowed): void {
    global $role;
    if ($role === 'admin' || in_array($role, $allowed, true)) {
        return;
    }
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];

// ─── IDENTIFY (RFID tap-in, or manual student_id lookup) ────
if ($method === 'POST' && $action === 'identify') {
    requireClinicRoles(['nurse', 'registrar']);
    $cardUid = trim($input['card_uid'] ?? $input['uid'] ?? '');
    $studentById = isset($input['student_id']) ? intval($input['student_id']) : 0;

    if ($cardUid === '' && !$studentById) {
        echo json_encode(['success' => false, 'message' => 'Card UID or student ID is required.']);
        exit;
    }
    try {
        if ($cardUid !== '') {
            $student = $db->fetchOne("
                SELECT
                    s.id, s.student_number, s.first_name, s.middle_name, s.last_name,
                    s.course, s.year_level, s.section, s.photo,
                    rf.card_uid, rf.status AS card_status,
                    rf.expiry_date
                FROM rfid_cards rf
                INNER JOIN students s ON rf.student_id = s.id
                WHERE rf.card_uid = ?
                LIMIT 1
            ", [$cardUid]);

            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Card not recognized.']);
                exit;
            }
            if ($student['card_status'] !== 'active') {
                echo json_encode(['success' => false, 'message' => 'Card is not active (' . $student['card_status'] . ').']);
                exit;
            }
            if ($student['expiry_date'] && $student['expiry_date'] < date('Y-m-d')) {
                echo json_encode(['success' => false, 'message' => 'Card has expired.']);
                exit;
            }
        } else {
            $student = $db->fetchOne("
                SELECT
                    s.id, s.student_number, s.first_name, s.middle_name, s.last_name,
                    s.course, s.year_level, s.section, s.photo,
                    NULL AS card_uid, 'active' AS card_status,
                    NULL AS expiry_date
                FROM students s
                WHERE s.id = ?
                LIMIT 1
            ", [$studentById]);

            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Student not found.']);
                exit;
            }
        }

        // Latest visit = current medical profile / vitals on file (denormalized)
        $latest = $db->fetchOne(
            "SELECT blood_type, allergies, height, weight, pre_existing_conditions,
                    immunization_records, temperature, blood_pressure, reason_for_visit, assessment, action_taken
             FROM health_visits
             WHERE student_id = ?
             ORDER BY COALESCE(date_time, created_at) DESC, id DESC
             LIMIT 1",
            [(int) $student['id']]
        );

        // ── Log to rfid_scan_logs (clinic tap) ────────────────
        if (!empty($cardUid)) {
            try {
                $db->insert('rfid_scan_logs', [
                    'card_uid'   => $cardUid,
                    'student_id' => (int) $student['id'],
                    'location'   => 'Clinic',
                    'event_type' => 'clinic',
                    'status'     => 'success',
                    'scanner_id' => 'nurse-kiosk',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                ]);
            } catch (Exception $logErr) {
                error_log('[clinic identify] scan log failed: ' . $logErr->getMessage());
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Student identified.',
            'data' => [
                'id'            => (int) $student['id'],
                'student_number'=> $student['student_number'],
                'name'          => trim(($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '') . ' ' . ($student['last_name'] ?? '')),
                'program'       => $student['course'],
                'year_level'    => $student['year_level'],
                'section'       => $student['section'],
                'photo'         => $student['photo'] ?? '',
                'card_uid'      => $student['card_uid'],
                'profile'       => $latest ?: null,
            ],
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'An error occurred.']);
    }
    exit;
}

// ─── LIST A STUDENT'S VISITS ────────────────────────────────
if ($method === 'GET' && $action === 'visits' && isset($_GET['student_id'])) {
    requireClinicRoles(['nurse', 'registrar']);
    $studentId = intval($_GET['student_id']);
    if (!$studentId) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }
    try {
        $visits = $db->fetchAll(
            "SELECT id, visit_date, date_time, reason_for_visit, assessment,
                    action_taken, nurse_notes, record_status, recorded_by,
                    temperature, blood_pressure, blood_type, allergies, height,
                    weight, pre_existing_conditions, immunization_records,
                    created_at
             FROM health_visits
             WHERE student_id = ?
             ORDER BY COALESCE(date_time, created_at) DESC, id DESC",
            [$studentId]
        );
        echo json_encode(['success' => true, 'data' => $visits]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'An error occurred.']);
    }
    exit;
}

// ─── SAVE A CLINIC VISIT (nurse) ────────────────────────────
if ($method === 'POST' && $action === 'save-visit') {
    requireClinicRoles(['nurse']);
    $studentId = intval($input['student_id'] ?? 0);
    if (!$studentId) {
        echo json_encode(['success' => false, 'message' => 'Student is required.']);
        exit;
    }
    $reason   = trim((string)($input['reason_for_visit'] ?? ''));
    $assessment = trim((string)($input['assessment'] ?? ''));
    $actionTaken = trim((string)($input['action_taken'] ?? ''));
    $notes    = trim((string)($input['nurse_notes'] ?? ''));

    // vitals + medical profile (denormalized into this visit)
    $temperature = ($input['temperature'] ?? '') !== '' ? (float) $input['temperature'] : null;
    $bloodPressure = trim((string)($input['blood_pressure'] ?? ''));
    $bloodType = trim((string)($input['blood_type'] ?? ''));
    $height = ($input['height'] ?? '') !== '' ? (float) $input['height'] : null;
    $weight = ($input['weight'] ?? '') !== '' ? (float) $input['weight'] : null;
    $allergies = trim((string)($input['allergies'] ?? ''));
    $conditions = trim((string)($input['pre_existing_conditions'] ?? ''));
    $immunizations = trim((string)($input['immunization_records'] ?? ''));
    $expanded = [
        'visit_type' => trim((string)($input['visit_type'] ?? '')),
        'onset_at' => trim((string)($input['onset_at'] ?? '')),
        'incident_details' => trim((string)($input['incident_details'] ?? '')),
        'symptoms' => trim((string)($input['symptoms'] ?? '')),
        'pain_score' => ($input['pain_score'] ?? '') !== '' ? (float)$input['pain_score'] : null,
        'red_flags' => is_array($input['red_flags'] ?? null) ? implode(',', array_slice(array_map('strval', $input['red_flags']), 0, 20)) : '',
        'pulse' => ($input['pulse'] ?? '') !== '' ? (float)$input['pulse'] : null,
        'respiratory_rate' => ($input['respiratory_rate'] ?? '') !== '' ? (float)$input['respiratory_rate'] : null,
        'oxygen_saturation' => ($input['oxygen_saturation'] ?? '') !== '' ? (float)$input['oxygen_saturation'] : null,
        'current_medications' => trim((string)($input['current_medications'] ?? '')),
        'disposition' => trim((string)($input['disposition'] ?? '')),
        'return_precautions' => trim((string)($input['return_precautions'] ?? '')),
        'follow_up_plan' => trim((string)($input['follow_up_plan'] ?? '')),
    ];
    $saveMode = ($input['save_mode'] ?? 'complete') === 'draft' ? 'Draft' : 'Recorded';
    if ($saveMode === 'Recorded' && empty($expanded['disposition'])) {
        echo json_encode(['success' => false, 'message' => 'Disposition is required for a complete visit.']); exit;
    }
    if ($reason === '') {
        echo json_encode(['success' => false, 'message' => 'Reason for visit is required.']);
        exit;
    }
    if ($actionTaken === '') {
        echo json_encode(['success' => false, 'message' => 'Action taken is required.']);
        exit;
    }

    try {
        $student = $db->fetchOne("SELECT id, student_number FROM students WHERE id = ?", [$studentId]);
        if (!$student) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }

        $dateTime = date('Y-m-d H:i:s');
        $id = $db->insert('health_visits', array_merge([

            'student_id'             => $studentId,
            'date_time'              => $dateTime,
            'visit_date'             => date('Y-m-d'),
            'reason_for_visit'       => $reason,
            'complaint'              => $reason,
            'assessment'             => $assessment !== '' ? $assessment : null,
            'diagnosis'              => $assessment !== '' ? $assessment : null,
            'action_taken'           => $actionTaken,
            'treatment'              => $actionTaken,
            'nurse_notes'            => $notes !== '' ? $notes : null,
            'notes'                  => $notes !== '' ? $notes : null,
            'temperature'            => $temperature,
            'blood_pressure'         => $bloodPressure !== '' ? $bloodPressure : null,
            'blood_type'             => $bloodType !== '' ? $bloodType : null,
            'allergies'              => $allergies !== '' ? $allergies : null,
            'height'                 => $height,
            'weight'                 => $weight,
            'pre_existing_conditions'=> $conditions !== '' ? $conditions : null,
            'immunization_records'   => $immunizations !== '' ? $immunizations : null,
            'record_status'          => $saveMode,
            'recorded_by'            => getCurrentUserId(),
            'created_at'             => $dateTime,
        ], $expanded));

        logActivity(getCurrentUserId(), 'clinic_visit_logged',
            json_encode(['student_id' => $studentId, 'reason' => $reason]));

        echo json_encode([
            'success' => true,
            'message' => 'Health record saved and synced to the registrar portal.',
            'data'    => ['id' => (int) $id, 'date_time' => $dateTime],
        ]);
    } catch (Exception $e) {
        error_log('[clinic save-visit] ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred while saving the record.']);
    }
    exit;
}

// ─── REGISTRAR VIEW-ONLY LOG FEED ───────────────────────────
if ($method === 'GET' && $action === 'log') {
    requireClinicRoles(['registrar']);
    $q        = trim($_GET['q'] ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo   = trim($_GET['date_to'] ?? '');
    $reason   = trim($_GET['reason'] ?? '');
    $status   = trim($_GET['status'] ?? '');

    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = "(hv.reason_for_visit LIKE ? OR CONCAT(s.first_name,' ',s.last_name) LIKE ? OR s.student_number LIKE ?)";
        array_push($params, "%$q%", "%$q%", "%$q%");
    }
    if ($dateFrom !== '') {
        $where[] = "COALESCE(hv.date_time, hv.visit_date) >= ?";
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $where[] = "COALESCE(hv.date_time, hv.visit_date) <= ?";
        $params[] = $dateTo . ' 23:59:59';
    }
    if ($reason !== '') {
        $where[] = "hv.reason_for_visit = ?";
        $params[] = $reason;
    }
    if ($status !== '' && in_array($status, ['Recorded', 'Pending', 'Cancelled'], true)) {
        $where[] = "hv.record_status = ?";
        $params[] = $status;
    }

    $sql = "SELECT
                hv.id, hv.student_id, hv.date_time, hv.visit_date, hv.reason_for_visit,
                hv.assessment, hv.action_taken, hv.nurse_notes,
                hv.record_status, hv.recorded_by,
                hv.temperature, hv.blood_pressure, hv.blood_type,
                hv.allergies, hv.height, hv.weight,
                hv.pre_existing_conditions, hv.immunization_records,
                s.student_number, s.course, s.year_level, s.section,
                CONCAT(s.first_name,' ',s.last_name) AS student_name,
                u.full_name AS recorded_by_name
            FROM health_visits hv
            INNER JOIN students s ON hv.student_id = s.id
            LEFT JOIN users u ON hv.recorded_by = u.id";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY COALESCE(hv.date_time, hv.visit_date) DESC, hv.id DESC";

    try {
        $rows = $db->fetchAll($sql, $params);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'An error occurred.']);
        exit;
    }

    echo json_encode(['success' => true, 'count' => count($rows), 'data' => $rows]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);