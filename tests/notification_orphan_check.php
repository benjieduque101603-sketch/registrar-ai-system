<?php
// The failure that produced "mark all as read" errors in the browser:
// the session's user_id had no matching row in users, and the cursor
// table's foreign key turned that into a 500. Reproduces it with a
// session id that is deliberately not a real user.
// Run with the dev server up: php tests/notification_orphan_check.php

$BASE = 'http://localhost/registrar-ai-system';
$pass = 0; $fail = 0;
function check($name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; }
    else { $fail++; echo "  FAIL $name\n       got:  " . var_export($got, true)
                    . "\n       want: " . var_export($want, true) . "\n"; }
}
function req(string $url, string $sid, string $method = 'GET', ?array $fields = null, string $csrf = ''): array {
    $h = "Cookie: BCP_REGISTRAR_SESSION=$sid\r\n";
    $o = ['method' => $method, 'ignore_errors' => true, 'timeout' => 15, 'header' => $h];
    if ($method === 'POST') {
        $o['header'] = $h . "X-CSRF-Token: $csrf\r\nContent-Type: application/x-www-form-urlencoded\r\n";
        $o['content'] = http_build_query($fields ?? []);
    }
    $b = @file_get_contents($url, false, stream_context_create(['http' => $o]));
    return [
        'status' => (int) (preg_match('#\s(\d{3})\s#', $http_response_header[0] ?? '', $m) ? $m[1] : 0),
        'body' => $b === false ? '' : $b,
    ];
}

require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

// A user id guaranteed not to exist in users.
$ghostId = (int) $db->fetchColumn("SELECT COALESCE(MAX(id), 0) + 5000 FROM users");
$exists = (int) $db->fetchColumn("SELECT COUNT(*) FROM users WHERE id = ?", [$ghostId]);
check('test user id really is absent', $exists, 0);

// Seed activity so there is something to mark read.
$real = $db->fetchOne("SELECT id FROM users WHERE role IN ('admin','registrar') ORDER BY id LIMIT 1");
$db->insert('audit_logs', [
    'user_id' => (int) $real['id'], 'action' => 'orphan_probe',
    'table_name' => 'students', 'ip_address' => '127.0.0.1',
]);
$maxId = (int) $db->fetchColumn("SELECT COALESCE(MAX(id),0) FROM audit_logs");
$db->query("DELETE FROM staff_notification_reads WHERE user_id = ?", [$ghostId]);

// Session for a user who does not exist, exactly like a stale login.
$sid = bin2hex(random_bytes(16));
req("$BASE/tests/_session_probe.php?sid=" . urlencode($sid) . "&uid=$ghostId&role=registrar", $sid);

$page = req("$BASE/dashboard.php", $sid);
preg_match("/name=[\"']csrf-token[\"']\s+content=[\"']([^\"']+)/", $page['body'], $m);
$csrf = $m[1] ?? '';

echo "\nmark all read with a session whose user row is gone:\n";
$r = req("$BASE/api/notifications.php", $sid, 'POST', ['action' => 'read_all'], $csrf);
$d = json_decode($r['body'], true);
echo "  http={$r['status']} success=" . var_export($d['success'] ?? null, true) . "\n";
check('does not 500', $r['status'] < 500, true);
check('succeeds', $d['success'] ?? null, true);

// The feed must still work for that session.
$f = json_decode(req("$BASE/api/notifications.php", $sid)['body'], true);
check('feed still responds', $f['success'] ?? null, true);
check('cursor was stored for the orphan id',
    (int) $db->fetchColumn("SELECT COUNT(*) FROM staff_notification_reads WHERE user_id = ?", [$ghostId]), 1);

// A real user must be unaffected. The token is session-bound, so it has
// to be read from that session's own page rather than reused.
$realSid = bin2hex(random_bytes(16));
req("$BASE/tests/_session_probe.php?sid=" . urlencode($realSid) . "&uid=" . (int)$real['id'] . "&role=registrar", $realSid);
$realPage = req("$BASE/dashboard.php", $realSid);
preg_match("/name=[\"']csrf-token[\"']\s+content=[\"']([^\"']+)/", $realPage['body'], $rm);
$realCsrf = $rm[1] ?? '';
check('real session renders a token', $realCsrf !== '', true);
$rp = json_decode(req("$BASE/api/notifications.php", $realSid, 'POST', ['action' => 'read_all'], $realCsrf)['body'], true);
check('real user still marks read', $rp['success'] ?? null, true);

// Cleanup. Clear every cursor this test could have written, not just the
// two ids, so a leftover from an interrupted earlier run cannot make an
// unrelated "table is empty" assertion fail.
$db->query("DELETE FROM staff_notification_reads");
$db->query("DELETE FROM audit_logs WHERE action = 'orphan_probe'");
check('cursor rows cleaned up',
    (int) $db->fetchColumn("SELECT COUNT(*) FROM staff_notification_reads"), 0);
check('audit rows cleaned up',
    (int) $db->fetchColumn("SELECT COUNT(*) FROM audit_logs WHERE action = 'orphan_probe'"), 0);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
