<?php
// Reproduces the reported flow end to end: mark as read, then load a
// DIFFERENT module page, and check the badge the sidebar would compute.
// This is the regression that survived the first round of testing.
// Run with the dev server up: php tests/notification_nav_check.php

$BASE = 'http://localhost/registrar-ai-system';
$pass = 0; $fail = 0;
function check($name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; }
    else { $fail++; echo "  FAIL $name\n       got:  " . var_export($got, true)
                    . "\n       want: " . var_export($want, true) . "\n"; }
}
function req(string $url, string $sid, string $method = 'GET', ?array $fields = null, string $csrf = ''): string {
    $h = "Cookie: BCP_REGISTRAR_SESSION=$sid\r\n";
    $o = ['method' => $method, 'ignore_errors' => true, 'timeout' => 15, 'header' => $h];
    if ($method === 'POST') {
        $o['header'] = $h . "X-CSRF-Token: $csrf\r\nContent-Type: application/x-www-form-urlencoded\r\n";
        $o['content'] = http_build_query($fields ?? []);
    }
    $b = @file_get_contents($url, false, stream_context_create(['http' => $o]));
    return $b === false ? '' : $b;
}

require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

$email = 'notif_nav_probe@bestlink.edu.ph';
$purge = function () use ($db, $email) {
    $db->query("DELETE FROM audit_logs WHERE user_id IN (SELECT id FROM users WHERE email = ?)", [$email]);
    $db->query("DELETE FROM staff_notification_reads WHERE user_id IN (SELECT id FROM users WHERE email = ?)", [$email]);
    $db->query("DELETE FROM users WHERE email = ?", [$email]);
};
$purge();
$db->insert('users', [
    'email' => $email, 'password_hash' => password_hash('p', PASSWORD_DEFAULT),
    'full_name' => 'Nav Probe', 'role' => 'registrar', 'is_active' => 1,
]);
$uid = (int) $db->lastInsertId();
for ($i = 0; $i < 3; $i++) {
    $db->insert('audit_logs', ['user_id' => $uid, 'action' => 'nav_probe_' . $i,
        'table_name' => 'students', 'ip_address' => '127.0.0.1']);
}

$sid = bin2hex(random_bytes(16));
req("$BASE/tests/_session_probe.php?sid=" . urlencode($sid) . "&uid=$uid&role=registrar", $sid);

// Grab a CSRF token the way a real page exposes it.
$page = req("$BASE/login.php", $sid);
preg_match("/name=[\"']csrf-token[\"']\s+content=[\"']([^\"']+)/", $page, $m);
$csrf = $m[1] ?? '';

$before = json_decode(req("$BASE/api/notifications.php", $sid), true);
$mine = fn($d) => count(array_filter($d['data'] ?? [],
    fn($n) => str_starts_with($n['title'] ?? '', 'Student') && !empty($n['unread'])));
echo "unread (probe rows) before: " . $mine($before) . "\n";

echo "\nmark as read on module A:\n";
$p = json_decode(req("$BASE/api/notifications.php", $sid, 'POST', ['action' => 'read_all'], $csrf), true);
check('server confirms success', $p['success'] ?? null, true);
check('server reports zero unread', $p['unread'] ?? null, 0);

// The reported symptom: navigate to a different module and re-read the
// feed exactly as that page's sidebar would on load.
echo "\nafter navigating to another module:\n";
$after = json_decode(req("$BASE/api/notifications.php", $sid), true);
check('feed reports zero unread', $mine($after), 0);
check('total unread is zero', $after['unread'] ?? null, 0);
check('no probe row is flagged unread', $mine($after), 0);

// And the sidebar's own badge computation over that payload must be 0.
$badge = count(array_filter($after['data'] ?? [], fn($n) => !empty($n['unread'])));
check('sidebar badge computes 0', $badge, 0);

// A new event after navigating must still surface.
$db->insert('audit_logs', ['user_id' => $uid, 'action' => 'nav_probe_after',
    'table_name' => 'students', 'ip_address' => '127.0.0.1']);
$later = json_decode(req("$BASE/api/notifications.php", $sid), true);
check('a later event still shows up unread', $mine($later), 1);

$purge();
check('probe user removed', (int) $db->fetchColumn("SELECT COUNT(*) FROM users WHERE email = ?", [$email]), 0);
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
