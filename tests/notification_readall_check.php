<?php
// Reproduces the "mark as read, navigate away, it comes back" report by
// exercising the failure paths the happy-path suite never hits.
// Run with the dev server up: php tests/notification_readall_check.php

$BASE = 'http://localhost/registrar-ai-system';
$pass = 0; $fail = 0;
function check($name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; }
    else { $fail++; echo "  FAIL $name\n       got:  " . var_export($got, true)
                    . "\n       want: " . var_export($want, true) . "\n"; }
}

function req(string $url, string $sid, string $method = 'GET', ?array $fields = null, string $csrf = ''): array {
    $headers = "Cookie: BCP_REGISTRAR_SESSION=$sid\r\n";
    $opts = ['method' => $method, 'ignore_errors' => true, 'timeout' => 15, 'header' => $headers];
    if ($method === 'POST') {
        $headers .= "X-CSRF-Token: $csrf\r\nContent-Type: application/x-www-form-urlencoded\r\n";
        $opts['header'] = $headers;
        $opts['content'] = http_build_query($fields ?? []);
    }
    $body = @file_get_contents($url, false, stream_context_create(['http' => $opts]));
    return [
        'status' => (int) (($http_response_header[0] ?? '') ? preg_replace('/\D/', '', $http_response_header[0]) : 0),
        'body' => $body === false ? '' : $body,
    ];
}

require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

$email = 'notif_readall_probe@bestlink.edu.ph';
$purge = function () use ($db, $email) {
    $db->query("DELETE FROM audit_logs WHERE user_id IN (SELECT id FROM users WHERE email = ?)", [$email]);
    // The cases below drop and rebuild this table, so it may legitimately
    // be absent when cleanup runs. Deleting from a table that is not there
    // is not a failure worth aborting on.
    try {
        $db->query("DELETE FROM staff_notification_reads WHERE user_id IN (SELECT id FROM users WHERE email = ?)", [$email]);
    } catch (Throwable $e) {
        // no cursor table yet
    }
    $db->query("DELETE FROM users WHERE email = ?", [$email]);
};
$purge();
$db->insert('users', [
    'email' => $email,
    'password_hash' => password_hash('probe', PASSWORD_DEFAULT),
    'full_name' => 'ReadAll Probe',
    'role' => 'registrar',
    'is_active' => 1,
]);
$uid = (int) $db->lastInsertId();
$db->insert('audit_logs', ['user_id' => $uid, 'action' => 'readall_probe', 'table_name' => 'students', 'ip_address' => '127.0.0.1']);

$sid = bin2hex(random_bytes(16));
req("$BASE/tests/_session_probe.php?sid=" . urlencode($sid) . "&uid=$uid&role=registrar", $sid);

$before = json_decode(req("$BASE/api/notifications.php", $sid)['body'], true);
echo "unread before: " . ($before['unread'] ?? '?') . "\n\n";

// A real token for this session. The cases below exercise the success
// path, and the token is session-bound, so it has to be read from a page
// this session actually rendered rather than reused across sessions.
$page = req("$BASE/dashboard.php", $sid);
preg_match("/name=[\"']csrf-token[\"']\s+content=[\"']([^\"']+)/", $page['body'], $tok);
$csrf = $tok[1] ?? '';
check('session renders a CSRF token', $csrf !== '', true);

// ── 1. CSRF-less POST: does it report failure, or silently "succeed"? ──
echo "csrf-less POST:\n";
$r = req("$BASE/api/notifications.php", $sid, 'POST', ['action' => 'read_all'], '');
$d = json_decode($r['body'], true);
echo "  status={$r['status']} success=" . var_export($d['success'] ?? null, true) . "\n";
check('server reports failure', $d['success'] ?? null, false);
// The critical property: a 419/500 is a RESOLVED promise in fetch().
// If the UI keyed off resolution alone it would clear the badge here
// while the server changed nothing, and the count would return on the
// next page. The payload must therefore say success:false.
check('failure is visible in the body', isset($d['success']) && $d['success'] === false, true);
$after = json_decode(req("$BASE/api/notifications.php", $sid)['body'], true);
check('nothing was actually marked read', $after['unread'] ?? null, $before['unread'] ?? null);

// ── 2. Does the sidebar JS inspect the body, or just resolve? ──
echo "\nsidebar JS handling:\n";
$sidebar = file_get_contents(__DIR__ . '/../includes/sidebar.php');
$start = strpos($sidebar, 'function markAllRead');
$end   = strpos($sidebar, "if (bellBtn) bellBtn.addEventListener", $start);
$fn = ($start !== false && $end !== false) ? substr($sidebar, $start, $end - $start) : '';
check('reads the response body', str_contains($fn, '.json()'), true);
check('checks success before clearing', (bool) preg_match('/\.success/', $fn), true);
check('shows an error when rejected', (bool) preg_match('/else|catch/', $fn), true);
check('does not close the modal on failure',
    !preg_match('/\}\)\.catch[^;]*closeNotifModal/s', $fn) || str_contains($fn, 'd.success'), true);
// The POST must carry the token itself rather than trusting csrf.js to
// have patched window.fetch first.
check('sends X-CSRF-Token explicitly', str_contains($fn, "'X-CSRF-Token': CSRF_TOKEN"), true);
check('reads the token from the page', str_contains($sidebar, "meta[name=csrf-token]"), true);

// ── 3. A database missing the cursor table, or carrying the old FK ──
// The read side always degraded to "everything unread" when the cursor
// table was absent, but the write side hard-failed with "Could not save
// your read state" every single time, forever. The bell looked healthy
// while Mark All as Read was permanently broken. Both halves must now
// recover on their own.
echo "\nrecovers from a broken cursor table:\n";
$tableExists = fn() => (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_notification_reads'"
);
// Case A: the table is gone entirely.
$db->query("DROP TABLE IF EXISTS `staff_notification_reads`");
check('cursor table removed for the test', $tableExists(), 0);

$feed = json_decode(req("$BASE/api/notifications.php", $sid)['body'], true);
check('feed still answers with no table', $feed['success'] ?? null, true);

$mark = json_decode(req("$BASE/api/notifications.php", $sid, 'POST', ['action' => 'read_all'], $csrf)['body'], true);
check('mark all read succeeds with no table', $mark['success'] ?? null, true);
check('table was recreated', $tableExists(), 1);

$afterFeed = json_decode(req("$BASE/api/notifications.php", $sid)['body'], true);
check('read state actually stuck', $afterFeed['unread'] ?? null, 0);

// Case B: the table exists but still carries the old users FK, which
// rejects any user_id not present in users.
$db->query("DROP TABLE IF EXISTS `staff_notification_reads`");
$db->query("CREATE TABLE `staff_notification_reads` (
    user_id INT(11) NOT NULL,
    last_read_id INT(11) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_snr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB");
$fk = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_notification_reads'
       AND CONSTRAINT_NAME = 'fk_snr_user'"
);
check('legacy FK is in place for the test', $fk, 1);

$mark2 = json_decode(req("$BASE/api/notifications.php", $sid, 'POST', ['action' => 'read_all'], $csrf)['body'], true);
check('mark all read succeeds despite the FK', $mark2['success'] ?? null, true);
check('FK was dropped',
    (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_notification_reads'
           AND CONSTRAINT_NAME = 'fk_snr_user'"
    ), 0);

// ── cleanup ──
$purge();
check('probe user removed', (int) $db->fetchColumn("SELECT COUNT(*) FROM users WHERE email = ?", [$email]), 0);
// The cases above drop and rebuild this table. Leave it in the shape the
// app expects: the migration's table, without the legacy FK. Dropping it
// here broke the other notification suites, which assume it is present
// even though the endpoint now creates it on demand.
$db->query("CREATE TABLE IF NOT EXISTS `staff_notification_reads` (
    `user_id`      int(11) NOT NULL,
    `last_read_id` int(11) NOT NULL DEFAULT 0,
    `updated_at`   timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
$db->query("DELETE FROM `staff_notification_reads`");
check('cursor table left present for other suites', $tableExists(), 1);
check('cursor table left empty', (int) $db->fetchColumn("SELECT COUNT(*) FROM `staff_notification_reads`"), 0);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
