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
    $db->query("DELETE FROM staff_notification_reads WHERE user_id IN (SELECT id FROM users WHERE email = ?)", [$email]);
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

// ── cleanup ──
$purge();
check('probe user removed', (int) $db->fetchColumn("SELECT COUNT(*) FROM users WHERE email = ?", [$email]), 0);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
