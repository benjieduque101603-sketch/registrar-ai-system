<?php
// ============================================================
//  API/NOTIFICATIONS.PHP
//  Staff notification bell, fed from the audit_logs trail.
//
//  Read state is a per-user cursor (staff_notification_reads)
//  holding the highest audit_logs.id that user has already seen.
//  audit_logs is shared and append-only, so "read" cannot be a
//  column on the row itself — it is a fact about one reader.
//
//    GET                     → feed + unread count
//    GET ?unread=1           → unread count only
//    POST action=read_all    → advance the cursor to the newest row
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

// The audit trail is staff-only. Students have their own scoped
// endpoint (student-notifications.php); letting them reach this one
// would expose every user's activity to the whole school portal.
if (getCurrentUserRole() === 'student') {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

$db     = Database::getInstance();
$userId = (int) getCurrentUserId();

/** Highest audit_logs.id this user has already acknowledged. */
function readCursor($db, int $userId): int {
    try {
        $row = $db->fetchOne(
            "SELECT last_read_id FROM staff_notification_reads WHERE user_id = ?",
            [$userId]
        );
        return $row ? (int) $row['last_read_id'] : 0;
    } catch (Throwable $e) {
        // Migration not applied yet — treat everything as unread
        // rather than silently reporting an empty inbox.
        error_log('[notifications] read cursor unavailable: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Make sure the cursor table exists, creating it if the migration has not
 * been run against this database.
 *
 * The read side already degrades when the table is missing - it falls back
 * to "everything is unread" - but the write side used to hard-fail with
 * "Could not save your read state" on every single attempt, forever, on a
 * database where the migration had not been applied. The bell then looked
 * healthy while Mark All as Read was permanently broken, with no way for
 * the user to tell why. Creating the table on first use is idempotent and
 * costs one query on the rare path, so the two halves of the feature
 * finally fail the same way.
 *
 * @return bool True when the table is present and writable.
 */
function ensureReadTable($db): bool {
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $db->query(
            "CREATE TABLE IF NOT EXISTS `staff_notification_reads` (
                `user_id`      int(11) NOT NULL,
                `last_read_id` int(11) NOT NULL DEFAULT 0,
                `updated_at`   timestamp NOT NULL DEFAULT current_timestamp()
                                ON UPDATE current_timestamp(),
                PRIMARY KEY (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        $ok = true;
    } catch (Throwable $e) {
        error_log('[notifications] could not create read cursor table: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    // ── MARK ALL READ ──
    if ($method === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action !== 'read_all') {
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
            exit;
        }
        // The cursor table is keyed by the session's user id but is not
        // foreign-keyed to users: a session can outlive its user row after
        // a re-seed or a restored backup, and that must not turn this into
        // a 500. An older copy of this table may still carry that FK, and
        // it is the single most common cause of this write failing, so it
        // is dropped here before the insert.
        try {
            ensureReadTable($db);

            // Drop the FK only if it is actually there. An unconditional
            // ALTER raises "Can't drop ... check that it exists" on a table
            // that is already correct, and that error would be caught below
            // and reported as a failed save - turning the fix into the very
            // bug it is meant to remove.
            $hasFk = (int) $db->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'staff_notification_reads'
                   AND CONSTRAINT_NAME = 'fk_snr_user'"
            );
            if ($hasFk > 0) {
                $db->query("ALTER TABLE `staff_notification_reads` DROP FOREIGN KEY `fk_snr_user`");
            }

            $maxId = (int) $db->fetchColumn("SELECT COALESCE(MAX(id), 0) FROM audit_logs");
            $db->query(
                "INSERT INTO staff_notification_reads (user_id, last_read_id)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))",
                [$userId, $maxId]
            );
            echo json_encode(['success' => true, 'message' => 'All notifications marked as read.', 'unread' => 0]);
        } catch (Throwable $e) {
            error_log('[notifications] could not store read cursor for user ' . $userId . ': ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Could not save your read state. Please reload and try again.',
            ]);
        }
        exit;
    }

    $cursor = readCursor($db, $userId);

    // ── UNREAD COUNT ONLY ──
    if (!empty($_GET['unread'])) {
        $cnt = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM audit_logs WHERE id > ?",
            [$cursor]
        );
        echo json_encode(['success' => true, 'unread' => $cnt]);
        exit;
    }

    // ── FEED ──
    $logs = $db->fetchAll(
        "SELECT id, action, table_name, created_at FROM audit_logs ORDER BY id DESC LIMIT 20"
    );

    // Unread is counted over the whole table, not inside the 20-row window
    // above. Counting the window capped the badge at 20, while ?unread=1 -
    // which the bell polls - counted every row past the cursor, so the two
    // disagreed as soon as a user had more than 20 unseen entries. The
    // student feed already counts this way; this one had not caught up.
    $unread = (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM audit_logs WHERE id > ?",
        [$cursor]
    );

    $notifications = [];

    foreach ($logs as $log) {
        $icon = 'fa-circle-info';
        $action = strtolower($log['action']);

        if (strpos($action, 'insert') !== false || strpos($action, 'create') !== false || strpos($action, 'add') !== false) {
            $icon = 'fa-plus-circle';
        } elseif (strpos($action, 'update') !== false || strpos($action, 'edit') !== false) {
            $icon = 'fa-pen';
        } elseif (strpos($action, 'delete') !== false || strpos($action, 'archive') !== false) {
            $icon = 'fa-trash-alt';
        } elseif (strpos($action, 'login') !== false) {
            $icon = 'fa-right-to-bracket';
        } elseif (strpos($action, 'assign') !== false) {
            $icon = 'fa-credit-card';
        }

        $table = $log['table_name'] ?? '';
        $tableLabel = '';
        if ($table === 'students') $tableLabel = 'Student';
        elseif ($table === 'rfid_cards') $tableLabel = 'RFID Card';
        elseif ($table === 'users') $tableLabel = 'User';
        elseif ($table === 'guardians') $tableLabel = 'Guardian';
        elseif ($table === 'documents') $tableLabel = 'Document';
        else $tableLabel = ucfirst(str_replace('_', ' ', $table));

        $time = $log['created_at'];
        $timeAgo = '';
        if ($time) {
            $diff = time() - strtotime($time);
            if ($diff < 60) $timeAgo = 'Just now';
            elseif ($diff < 3600) $timeAgo = floor($diff / 60) . 'm ago';
            elseif ($diff < 86400) $timeAgo = floor($diff / 3600) . 'h ago';
            else $timeAgo = date('M d', strtotime($time));
        }

        $isUnread = (int) $log['id'] > $cursor;

        $notifications[] = [
            'id'      => (int) $log['id'],
            'title'   => $tableLabel ? $tableLabel . ' ' . $log['action'] : $log['action'],
            'message' => $tableLabel ? $tableLabel . ' record ' . $log['action'] : $log['action'],
            'time'    => $timeAgo,
            'unread'  => $isUnread,
            'icon'    => $icon
        ];
    }

    echo json_encode(['success' => true, 'data' => $notifications, 'unread' => $unread]);

} catch (Throwable $e) {
    error_log('[notifications] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error.']);
}
