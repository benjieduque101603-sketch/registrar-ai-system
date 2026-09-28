<?php
// ============================================================
//  API/MASTERLIST.PHP
//  Read-only masterlist roster. The registrar prepares the list of
//  students; the receiving department assigns section codes, so this
//  endpoint never writes a section.
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';

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

try {
    $db = Database::getInstance();

    if ($method === 'GET') {
        $courseFilter = isset($_GET['course']) ? trim((string) $_GET['course']) : '';
        $yearFilter = isset($_GET['year_level']) ? trim((string) $_GET['year_level']) : '';

        $sql = "SELECT * FROM students WHERE 1=1";
        $params = [];

        if ($courseFilter !== '') {
            $sql .= " AND TRIM(course) = ?";
            $params[] = $courseFilter;
        }
        if ($yearFilter !== '' && is_numeric($yearFilter)) {
            $sql .= " AND year_level = ?";
            $params[] = (int) $yearFilter;
        }

        $sql .= " ORDER BY TRIM(course) ASC, COALESCE(year_level, 0) ASC, last_name ASC, first_name ASC";

        $students = $db->fetchAll($sql, $params);

        // Flat roster. The registrar does not assign section codes, so there
        // is no grouping and no capacity to report — the receiving department
        // assigns sections after the list is handed off.
        echo json_encode([
            'success' => true,
            'total' => count($students),
            'students' => $students,
        ]);
        exit;
    }

    // This endpoint is read-only. The registrar prepares the list; the
    // receiving department assigns section codes, so nothing here writes one.
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'This endpoint is read-only. Section codes are assigned by the receiving department.',
    ]);
} catch (Exception $e) {
    json_error($e);
}
