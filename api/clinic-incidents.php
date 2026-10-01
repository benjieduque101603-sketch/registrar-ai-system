<?php
// ============================================================
//  API/CLINIC-INCIDENTS.PHP
//  Incident Reports — non-visit clinic events.
//    GET    → list incidents (with ?q=, ?status=, ?from=, ?to=)
//    POST   → create / update incident
//    DELETE → remove incident
// ============================================================

header('Content-Type: application/json');
require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/database.php';
if (!isLoggedIn() || !in_array(getCurrentUserRole(), ['nurse','admin'])) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Forbidden.']); exit;
}
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

// ─── LIST INCIDENTS ───
if ($method === 'GET' && !$action) {
    $q      = trim($_GET['q'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $from   = trim($_GET['from'] ?? '');
    $to     = trim($_GET['to'] ?? '');

    $sql = "SELECT ir.*, s.student_number, CONCAT(s.first_name,' ',s.last_name) AS student_name
            FROM clinic_incidents ir
            LEFT JOIN students s ON ir.student_id = s.id
            WHERE 1=1";
    $params = [];
    if ($q !== '') {
        $sql .= " AND (ir.incident_type LIKE ? OR ir.description LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_number LIKE ?)";
        $like = "%$q%";
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
    }
    if ($status !== '') { $sql .= " AND ir.status = ?"; $params[] = $status; }
    if ($from !== '') { $sql .= " AND ir.incident_date >= ?"; $params[] = $from; }
    if ($to !== '') { $sql .= " AND ir.incident_date <= ?"; $params[] = $to; }
    $sql .= " ORDER BY ir.incident_date DESC, ir.id DESC LIMIT 200";
    echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $params)]);
    exit;
}

// ─── CREATE / UPDATE ───
if ($method === 'POST') {
    $id           = intval($input['id'] ?? 0);
    $studentId    = intval($input['student_id'] ?? 0);
    $incidentType = trim($input['incident_type'] ?? '');
    $description  = trim($input['description'] ?? '');
    $location     = trim($input['location'] ?? '');
    $date         = trim($input['incident_date'] ?? date('Y-m-d'));
    $time         = trim($input['incident_time'] ?? date('H:i:s'));
    $severity     = trim($input['severity'] ?? 'low');
    $actionTaken  = trim($input['action_taken'] ?? '');
    $followUp     = trim($input['follow_up'] ?? '');
    $status       = trim($input['status'] ?? 'open');

    if ($incidentType === '') { echo json_encode(['success'=>false,'message'=>'Incident type required.']); exit; }

    $data = [
        'student_id'    => $studentId ?: null,
        'incident_type' => $incidentType,
        'description'   => $description,
        'location'      => $location,
        'incident_date' => $date,
        'incident_time' => $time,
        'severity'      => $severity,
        'action_taken'  => $actionTaken,
        'follow_up'     => $followUp,
        'status'        => $status,
        'reported_by'   => (int)$_SESSION['user_id'],
    ];

    if ($id > 0) {
        $db->update('clinic_incidents', $data, 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Incident updated.', 'id' => $id]);
    } else {
        $data['created_at'] = date('Y-m-d H:i:s');
        $db->insert('clinic_incidents', $data);
        echo json_encode(['success' => true, 'message' => 'Incident reported.', 'id' => $db->lastInsertId()]);
    }
    exit;
}

// ─── DELETE ───
if ($method === 'DELETE') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'ID required.']); exit; }
    $db->delete('clinic_incidents', 'id = ?', [$id]);
    echo json_encode(['success' => true, 'message' => 'Deleted.']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
