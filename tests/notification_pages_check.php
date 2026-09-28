<?php
// Reproduces the exact user flow across real module pages: load a page,
// read the CSRF token it renders, click "Mark All as Read", and check
// what the server answers. A page that renders the sidebar WITHOUT the
// csrf-token meta, or whose token the server rejects, is the bug.
// Run with the dev server up: php tests/notification_pages_check.php

$BASE = 'http://localhost/registrar-ai-system';
$pass = 0; $fail = 0;
function check($name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; }
    else { $fail++; echo "  FAIL $name\n       got:  " . var_export($got, true)
                    . "\n       want: " . var_export($want, true) . "\n"; }
}
// "HTTP/1.1 200 OK" → 200. Stripping every non-digit would also eat
// the leading "1" of the protocol version and yield 1200.
function statusOf(?string $line = null): int {
    return $line && preg_match('#\s(\d{3})\s#', $line, $m) ? (int) $m[1] : 0;
}

function get(string $url, string $sid): array {
    $b = @file_get_contents($url, false, stream_context_create(['http' => [
        'ignore_errors' => true, 'timeout' => 15,
        'header' => "Cookie: BCP_REGISTRAR_SESSION=$sid\r\n",
    ]]));
    return [
        'status' => statusOf($http_response_header[0] ?? ''),
        'body' => $b === false ? '' : $b,
    ];
}
function post(string $url, string $sid, string $csrf): array {
    $b = @file_get_contents($url, false, stream_context_create(['http' => [
        'method' => 'POST', 'ignore_errors' => true, 'timeout' => 15,
        'header' => "Cookie: BCP_REGISTRAR_SESSION=$sid\r\n"
                  . "X-CSRF-Token: $csrf\r\n"
                  . "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => 'action=read_all',
    ]]));
    return [
        'status' => statusOf($http_response_header[0] ?? ''),
        'body' => $b === false ? '' : $b,
    ];
}

require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

$email = 'notif_pages_probe@bestlink.edu.ph';
$purge = function () use ($db, $email) {
    $db->query("DELETE FROM audit_logs WHERE user_id IN (SELECT id FROM users WHERE email = ?)", [$email]);
    $db->query("DELETE FROM staff_notification_reads WHERE user_id IN (SELECT id FROM users WHERE email = ?)", [$email]);
    $db->query("DELETE FROM users WHERE email = ?", [$email]);
};
$purge();
$db->insert('users', [
    'email' => $email, 'password_hash' => password_hash('p', PASSWORD_DEFAULT),
    'full_name' => 'Pages Probe', 'role' => 'registrar', 'is_active' => 1,
]);
$uid = (int) $db->lastInsertId();
$db->insert('audit_logs', ['user_id' => $uid, 'action' => 'pages_probe',
    'table_name' => 'students', 'ip_address' => '127.0.0.1']);

$sid = bin2hex(random_bytes(16));
get("$BASE/tests/_session_probe.php?sid=" . urlencode($sid) . "&uid=$uid&role=registrar", $sid);

// Real module pages that render the sidebar.
$pages = [
    'dashboard.php', 'settings.php',
    'registrar/students.php', 'registrar/documents.php', 'registrar/queue.php',
    'registrar/masterlist.php', 'registrar/audit-logs.php', 'registrar/users.php',
];

foreach ($pages as $p) {
    $r = get("$BASE/$p", $sid);
    $hasBell    = str_contains($r['body'], 'id="bellBtn"');
    preg_match("/name=[\"']csrf-token[\"']\s+content=[\"']([^\"']+)/", $r['body'], $m);
    $tok = $m[1] ?? '';

    if ($r['status'] !== 200 || !$hasBell) {
        // Not a page this probe can render (auth/redirect); report and move on.
        printf("  --   %-28s status=%d bell=%s\n", $p, $r['status'], $hasBell ? 'yes' : 'no');
        continue;
    }
    $pr = post("$BASE/api/notifications.php", $sid, $tok);
    $pd = json_decode($pr['body'], true);
    $ok = ($pd['success'] ?? false) === true;
    printf("  %-4s %-28s token=%s post=%d success=%s\n",
        $ok ? 'ok' : 'FAIL', $p,
        $tok !== '' ? 'yes' : 'MISSING', $pr['status'],
        var_export($pd['success'] ?? null, true));
    if ($ok) { $pass++; } else { $fail++; }
    if ($tok === '') {
        echo "       ^ page renders the sidebar but no csrf-token meta\n";
    }
}

$purge();
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
