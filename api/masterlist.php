<?php
// ============================================================
//  API/MASTERLIST.PHP
//  Read-only student list for the registrar masterlist.
//
//  This used to also create, rename and auto-assign sections.
//  Sectioning is handled by another department, so all of that
//  is gone; what remains is a filterable list of enrolled
//  students grouped by course and year level.
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'The masterlist is read-only.']);
    exit;
}

try {
    $db = Database::getInstance();

    $courseFilter = isset($_GET['course']) ? trim((string) $_GET['course']) : '';
    $yearFilter   = isset($_GET['year_level']) ? trim((string) $_GET['year_level']) : '';

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
    $groups = groupStudentsForMasterlist($students);

    echo json_encode([
        'success' => true,
        'total' => count($students),
        'groups' => $groups,
    ]);
} catch (Throwable $e) {
    error_log('[api/masterlist] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load the masterlist.']);
}
