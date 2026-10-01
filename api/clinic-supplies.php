<?php
// ============================================================
//  API/CLINIC-SUPPLIES.PHP
//  Medicine/Supply inventory CRUD for the clinic portal.
//    GET    → list supplies (with optional ?q= search)
//    POST   → add / update supply
//    DELETE → remove supply
//    POST   action=log-usage → log dispensing per visit
//    GET    action=usage     → usage history for a supply
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

// ─── LIST SUPPLIES ───
if ($method === 'GET' && $action !== 'usage') {
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT * FROM clinic_supplies";
    $params = [];
    if ($q !== '') {
        $sql .= " WHERE name LIKE ? OR category LIKE ? OR description LIKE ?";
        $like = "%$q%";
        $params = [$like, $like, $like];
    }
    $sql .= " ORDER BY name ASC";
    echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $params)]);
    exit;
}

// ─── USAGE HISTORY ───
if ($method === 'GET' && $action === 'usage') {
    $supplyId = intval($_GET['supply_id'] ?? 0);
    $sql = "SELECT su.*, cs.name AS supply_name
            FROM clinic_supply_usage su
            INNER JOIN clinic_supplies cs ON su.supply_id = cs.id";
    $params = [];
    if ($supplyId) { $sql .= " WHERE su.supply_id = ?"; $params[] = $supplyId; }
    $sql .= " ORDER BY su.used_at DESC LIMIT 200";
    echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $params)]);
    exit;
}

// ─── ADD / UPDATE SUPPLY ───
if ($method === 'POST' && $action !== 'log-usage') {
    $id       = intval($input['id'] ?? 0);
    $name     = trim($input['name'] ?? '');
    $category = trim($input['category'] ?? '');
    $qty      = intval($input['quantity'] ?? 0);
    $unit     = trim($input['unit'] ?? 'pcs');
    $desc     = trim($input['description'] ?? '');
    $minQty   = intval($input['min_quantity'] ?? 5);

    if ($name === '') { echo json_encode(['success'=>false,'message'=>'Name required.']); exit; }

    $data = [
        'name'         => $name,
        'category'     => $category,
        'quantity'     => $qty,
        'unit'         => $unit,
        'description'  => $desc,
        'min_quantity' => $minQty,
        'updated_at'   => date('Y-m-d H:i:s'),
    ];

    if ($id > 0) {
        $db->update('clinic_supplies', $data, 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Supply updated.', 'id' => $id]);
    } else {
        $data['created_at'] = date('Y-m-d H:i:s');
        $db->insert('clinic_supplies', $data);
        echo json_encode(['success' => true, 'message' => 'Supply added.', 'id' => $db->lastInsertId()]);
    }
    exit;
}

// ─── LOG USAGE (dispense) ───
if ($method === 'POST' && $action === 'log-usage') {
    $supplyId = intval($input['supply_id'] ?? 0);
    $qty      = intval($input['quantity_used'] ?? 0);
    $visitId  = intval($input['health_visit_id'] ?? 0);
    $notes    = trim($input['notes'] ?? '');

    if (!$supplyId || $qty <= 0) { echo json_encode(['success'=>false,'message'=>'Supply and positive quantity required.']); exit; }

    $supply = $db->fetchOne("SELECT * FROM clinic_supplies WHERE id = ?", [$supplyId]);
    if (!$supply) { echo json_encode(['success'=>false,'message'=>'Supply not found.']); exit; }

    $db->insert('clinic_supply_usage', [
        'supply_id'       => $supplyId,
        'quantity_used'   => $qty,
        'health_visit_id' => $visitId ?: null,
        'used_by'         => (int)$_SESSION['user_id'],
        'notes'           => $notes,
        'used_at'         => date('Y-m-d H:i:s'),
    ]);

    $newQty = max(0, (int)$supply['quantity'] - $qty);
    $db->update('clinic_supplies', ['quantity' => $newQty, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$supplyId]);

    echo json_encode(['success' => true, 'message' => 'Dispensed.', 'remaining' => $newQty]);
    exit;
}

// ─── DELETE SUPPLY ───
if ($method === 'DELETE') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'ID required.']); exit; }
    $db->delete('clinic_supplies', 'id = ?', [$id]);
    echo json_encode(['success' => true, 'message' => 'Deleted.']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
