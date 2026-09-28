<?php
// ============================================================
//  API/STUDENT-NOTIFICATIONS.PHP
//  CRUD for the student notification bell.
//  GET    ?student_id=N          → list notifications for student
//  POST   (action=read)         → mark notification(s) read
//  POST   (action=read_all)     → mark all as read for student
//  GET    ?student_id=N&unread=1 → count unread only
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

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$role = getCurrentUserRole();

// ── Student can only see their own; admin/registrar can specify any
$studentId = 0;
if ($role === 'student') {
    $studentId = intval($_SESSION['student_id'] ?? 0);
    if (!$studentId) {
        // Try lookup by user id
        $row = $db->fetchOne("SELECT student_id FROM users WHERE id = ?", [$_SESSION['user_id']]);
        $studentId = $row ? intval($row['student_id']) : 0;
    }
} elseif (in_array($role, ['admin', 'registrar'], true)) {
    $studentId = intval($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
}

// ── LIST / COUNT ──
if ($method === 'GET') {
    if (!$studentId) {
        echo json_encode(['success' => true, 'data' => [], 'unread' => 0]);
        exit;
    }

    $unreadOnly = !empty($_GET['unread']);

    if ($unreadOnly) {
        $cnt = $db->fetchColumn(
            "SELECT COUNT(*) FROM student_notifications WHERE student_id = ? AND is_read = 0",
            [$studentId]
        );
        echo json_encode(['success' => true, 'unread' => (int)$cnt]);
        exit;
    }

    $notifs = $db->fetchAll(
        "SELECT id, title, message, type, is_read, related_doc_id, created_at, read_at
         FROM student_notifications
         WHERE student_id = ?
         ORDER BY created_at DESC LIMIT 50",
        [$studentId]
    );

    // Counted over the whole table, not the 50-row window above.
    // Counting the window instead made the badge disagree with the
    // ?unread=1 mode as soon as a student passed 50 notifications.
    $unread = (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM student_notifications WHERE student_id = ? AND is_read = 0",
        [$studentId]
    );

    $data = [];
    foreach ($notifs as $n) {
        $diff = time() - strtotime($n['created_at']);
        if ($diff < 60) $timeAgo = 'Just now';
        elseif ($diff < 3600) $timeAgo = floor($diff / 60) . 'm ago';
        elseif ($diff < 86400) $timeAgo = floor($diff / 3600) . 'h ago';
        else $timeAgo = date('M d', strtotime($n['created_at']));

        $iconMap = ['info' => 'fa-circle-info', 'warning' => 'fa-triangle-exclamation', 'success' => 'fa-circle-check', 'error' => 'fa-circle-xmark'];
        $data[] = [
            'id'       => (int)$n['id'],
            'title'    => $n['title'],
            'message'  => $n['message'],
            'type'     => $n['type'],
            'unread'   => !$n['is_read'],
            'icon'     => $iconMap[$n['type']] ?? 'fa-circle-info',
            'time'     => $timeAgo,
            'doc_id'   => $n['related_doc_id'] ? (int)$n['related_doc_id'] : null,
        ];
    }

    echo json_encode(['success' => true, 'data' => $data, 'unread' => $unread]);
    exit;
}

// ── MARK READ / READ ALL ──
if ($method === 'POST') {
    if (!$studentId) {
        echo json_encode(['success' => false, 'message' => 'Student context required.']);
        exit;
    }

    if ($action === 'read') {
        $notifId = intval($_POST['notification_id'] ?? 0);
        if (!$notifId) {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $notifId = intval($input['notification_id'] ?? 0);
        }
        if (!$notifId) {
            echo json_encode(['success' => false, 'message' => 'notification_id required.']);
            exit;
        }
        $db->update('student_notifications', [
            'is_read' => 1,
            'read_at' => date('Y-m-d H:i:s'),
        ], 'id = ? AND student_id = ?', [$notifId, $studentId]);
        echo json_encode(['success' => true, 'message' => 'Marked as read.']);
        exit;
    }

    if ($action === 'read_all') {
        $db->update('student_notifications', [
            'is_read' => 1,
            'read_at' => date('Y-m-d H:i:s'),
        ], 'student_id = ? AND is_read = 0', [$studentId]);
        echo json_encode(['success' => true, 'message' => 'All notifications marked as read.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);
