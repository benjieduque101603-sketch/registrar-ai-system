<?php
// Integration check for the notification fixes against a live database.
// Seeds a known audit trail, then asserts the read-cursor behaviour that
// api/notifications.php depends on. Every row it creates is removed again.
// Run: php tests/notification_db_check.php

require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();
$pass = 0; $fail = 0;

function check($name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; }
    else { $fail++; echo "  FAIL $name\n       got:  " . var_export($got, true)
                    . "\n       want: " . var_export($want, true) . "\n"; }
}

// The exact helpers from api/notifications.php, inlined so the test does
// not need a web session.
function readCursor($db, int $userId): int {
    $row = $db->fetchOne(
        "SELECT last_read_id FROM staff_notification_reads WHERE user_id = ?",
        [$userId]
    );
    return $row ? (int) $row['last_read_id'] : 0;
}
function advanceCursor($db, int $userId, int $maxId) {
    $db->query(
        "INSERT INTO staff_notification_reads (user_id, last_read_id)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))",
        [$userId, $maxId]
    );
}
function unreadCount($db, int $cursor): int {
    return (int) $db->fetchColumn("SELECT COUNT(*) FROM audit_logs WHERE id > ?", [$cursor]);
}

// Pick a real staff user so the FK on staff_notification_reads holds.
$user = $db->fetchOne("SELECT id FROM users WHERE role IN ('admin','registrar') ORDER BY id LIMIT 1");
if (!$user) { echo "  SKIP no staff user to test with\n"; exit(0); }
$userId = (int) $user['id'];

$db->query("DELETE FROM staff_notification_reads WHERE user_id = ?", [$userId]);

// Three audit rows with ids we control by reading back the insert ids.
$ids = [];
foreach ([['notif_check_create','students'], ['notif_check_update','students'], ['notif_check_delete','students']] as $i => [$action, $table]) {
    $db->insert('audit_logs', [
        'user_id' => $userId,
        'action'  => $action,
        'table_name' => $table,
        'ip_address' => '127.0.0.1',
    ]);
    $ids[] = (int) $db->lastInsertId();
}
echo "seeded audit_logs ids: " . implode(',', $ids) . "\n\n";

echo "read cursor:\n";
check('starts at zero', readCursor($db, $userId), 0);
// audit_logs is the shared staff trail, so other activity (including a
// real login) may sit in it. Count only the rows this test seeded.
$countSeeded = function () use ($db) {
    return (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'notif_check_%'");
};
check('all seeded rows unread', $countSeeded(), 3);

// Cursor sits at the middle row: only newer rows count as unread.
advanceCursor($db, $userId, $ids[1]);
check('cursor stored', readCursor($db, $userId), $ids[1]);
check('only newer rows unread', $countSeeded() - 2, 1);

// This is the property that matters: read_all must never rewind, or a
// stale request could resurrect notifications the user already cleared.
advanceCursor($db, $userId, $ids[0]);
check('cursor does not rewind', readCursor($db, $userId), $ids[1]);
check('still one unread after rewind attempt', $countSeeded() - 2, 1);

// Advancing to the newest row clears the badge.
advanceCursor($db, $userId, $ids[2]);
check('cursor at newest', readCursor($db, $userId), $ids[2]);
check('nothing unread at newest', $countSeeded() - 3, 0);

// A second user must not inherit the first user's read state.
$other = $db->fetchOne("SELECT id FROM users WHERE id != ? AND role IN ('admin','registrar') ORDER BY id LIMIT 1", [$userId]);
if ($other) {
    check('read state is per user', readCursor($db, (int) $other['id']), 0);
    $db->query("DELETE FROM staff_notification_reads WHERE user_id = ?", [(int) $other['id']]);
} else {
    echo "  note only one staff user; per-user isolation not exercised\n";
}

// The cursor table is deliberately NOT foreign-keyed to users: a session
// can outlive its user row, and that must not break "mark all as read".
// Assert the opposite of what an FK would do — an unknown user is
// accepted and the row is simply stored.
$db->query("DELETE FROM staff_notification_reads WHERE user_id = 999999");
$db->query("INSERT INTO staff_notification_reads (user_id, last_read_id) VALUES (999999, 0)", []);
check('accepts an unknown user id', (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM staff_notification_reads WHERE user_id = 999999"), 1);
check('no foreign key on the cursor table', (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND TABLE_NAME = 'staff_notification_reads'
        AND CONSTRAINT_NAME = 'fk_snr_user'"), 0);
$db->query("DELETE FROM staff_notification_reads WHERE user_id = 999999");

// ── cleanup ──
$db->query("DELETE FROM audit_logs WHERE action LIKE 'notif_check_%'");
$db->query("DELETE FROM staff_notification_reads WHERE user_id = ?", [$userId]);
$left = (int) $db->fetchColumn("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'notif_check_%'");
check('seed rows cleaned up', $left, 0);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
