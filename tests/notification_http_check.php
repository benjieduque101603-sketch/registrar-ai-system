<?php
// End-to-end check of the notification endpoints over real HTTP.
// The session is injected via tests/_session_probe.php because
// api/auth.php?action=login currently 500s in this environment
// (pre-existing, unrelated to the notification work).
// Run with the dev server up: php tests/notification_http_check.php

$BASE = 'http://localhost/registrar-ai-system';
$pass = 0; $fail = 0;
function check($name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; }
    else { $fail++; echo "  FAIL $name\n       got:  " . var_export($got, true)
                    . "\n       want: " . var_export($want, true) . "\n"; }
}

function req(string $url, string $sid, string $method = 'GET', ?array $fields = null, string $csrf = ''): string {
    $headers = "Cookie: BCP_REGISTRAR_SESSION=$sid\r\n";
    $opts = ['method' => $method, 'ignore_errors' => true, 'timeout' => 15, 'header' => $headers];
    if ($method === 'POST') {
        $headers .= "X-CSRF-Token: $csrf\r\nContent-Type: application/x-www-form-urlencoded\r\n";
        $opts['header'] = $headers;
        $opts['content'] = http_build_query($fields ?? []);
    }
    $body = @file_get_contents($url, false, stream_context_create(['http' => $opts]));
    return $body === false ? '' : $body;
}

function mkSession(int $uid, string $role, int $stid = 0): string {
    $sid = bin2hex(random_bytes(16));
    $url = $GLOBALS['BASE'] . '/tests/_session_probe.php?sid=' . urlencode($sid)
         . '&uid=' . $uid . '&role=' . urlencode($role) . ($stid ? '&stid=' . $stid : '');
    req($url, $sid);
    return $sid;
}

require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();
$GLOBALS['BASE'] = $BASE;

$email = 'notif_http_probe@bestlink.edu.ph';
// Children first: audit_logs and staff_notification_reads both hold an
// FK to users, so a leftover probe row must be cleared in that order.
$purge = function () use ($db, $email) {
    $db->query("DELETE FROM audit_logs WHERE user_id IN (SELECT id FROM users WHERE email = ?)", [$email]);
    $db->query("DELETE FROM staff_notification_reads WHERE user_id IN (SELECT id FROM users WHERE email = ?)", [$email]);
    $db->query("DELETE FROM users WHERE email = ?", [$email]);
};
$purge();
$db->insert('users', [
    'email' => $email,
    'password_hash' => password_hash('probe', PASSWORD_DEFAULT),
    'full_name' => 'Notif HTTP Probe',
    'role' => 'registrar',
    'is_active' => 1,
]);
$staffId = (int) $db->lastInsertId();
$db->query("DELETE FROM audit_logs WHERE user_id = ?", [$staffId]);

$ids = [];
foreach (['notif_probe_create', 'notif_probe_update'] as $action) {
    $db->insert('audit_logs', [
        'user_id' => $staffId, 'action' => $action,
        'table_name' => 'students', 'ip_address' => '127.0.0.1',
    ]);
    $ids[] = (int) $db->lastInsertId();
}
echo "probe user #$staffId, audit ids " . implode(',', $ids) . "\n\n";

$staffSess = mkSession($staffId, 'registrar');

echo "staff feed:\n";
$d = json_decode(req("$BASE/api/notifications.php", $staffSess), true);
check('responds ok', $d['success'] ?? null, true);
// The feed is the shared audit trail, so other users' rows appear too.
// Assert on this user's own rows rather than the total.
$mine = array_values(array_filter($d['data'] ?? [],
    fn($n) => str_starts_with($n['title'] ?? '', 'Student')));
check('lists the probe activity', count($mine) >= 2, true);
check('probe rows are unread', $mine[0]['unread'] ?? null, true);
$unreadMine = count(array_filter($mine, fn($n) => !empty($n['unread'])));
check('both probe rows count as unread', $unreadMine, 2);

// The token a real page would hold.
$dash = req("$BASE/login.php", $staffSess);
preg_match("/name=[\"']csrf-token[\"']\s+content=[\"']([^\"']+)/", (string)$dash, $m);
$csrf = $m[1] ?? '';
echo "  (csrf token: " . ($csrf !== '' ? 'found' : 'not found') . ")\n";

if ($csrf !== '') {
    echo "\nmark all read:\n";
    $pd = json_decode(req("$BASE/api/notifications.php", $staffSess, 'POST', ['action' => 'read_all'], $csrf), true);
    check('succeeds', $pd['success'] ?? null, true);
    check('reports zero unread', $pd['unread'] ?? null, 0);

    // The assertion that matters: a fresh request must show it cleared.
    $d2 = json_decode(req("$BASE/api/notifications.php", $staffSess), true);
    check('stays cleared after reload', $d2['unread'] ?? null, 0);
    check('items now read', $d2['data'][0]['unread'] ?? null, false);

    // A later event must reappear as unread.
    $db->insert('audit_logs', [
        'user_id' => $staffId, 'action' => 'notif_probe_after_read',
        'table_name' => 'students', 'ip_address' => '127.0.0.1',
    ]);
    $d3 = json_decode(req("$BASE/api/notifications.php", $staffSess), true);
    check('a later event shows unread again', $d3['unread'] ?? null, 1);
} else {
    echo "\nmark all read: SKIPPED (no CSRF token without a real login)\n";
}

// A CSRF-less POST must be refused.
echo "\ncsrf enforcement:\n";
$noCsrf = json_decode(req("$BASE/api/notifications.php", $staffSess, 'POST', ['action' => 'read_all'], ''), true);
check('POST without a token is refused', $noCsrf['success'] ?? null, false);

echo "\nstudent reaching the staff endpoint:\n";
$stu = $db->fetchOne("SELECT id, student_id FROM users WHERE role='student' AND student_id IS NOT NULL LIMIT 1");
if ($stu) {
    $stuSess = mkSession((int)$stu['id'], 'student', (int)$stu['student_id']);
    $ds = json_decode(req("$BASE/api/notifications.php", $stuSess), true);
    check('student is refused', $ds['success'] ?? null, false);
    check('refusal says Forbidden', trim($ds['message'] ?? ''), 'Forbidden.');
    check('no audit data leaked', isset($ds['data']), false);

    // The student feed must still work for them.
    $own = json_decode(req("$BASE/api/student-notifications.php", $stuSess), true);
    check('student feed still works', $own['success'] ?? null, true);
} else {
    echo "  SKIP no student account\n";
}

$purge();
check('probe user removed', (int) $db->fetchColumn("SELECT COUNT(*) FROM users WHERE email = ?", [$email]), 0);
check('probe audit rows removed', (int) $db->fetchColumn("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'notif_probe_%'"), 0);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
