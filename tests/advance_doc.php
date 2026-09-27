<?php
// Advance a request to a given status through the REAL api/documents.php
// transitions, so the mid-track rail state can be seen in a screenshot.
// Restores the original status on the way out (register_shutdown_function),
// because this is a fixture, not a data change.
require_once __DIR__ . '/../shared/config.php';

$id     = (int) ($argv[1] ?? 0);
$target = (string) ($argv[2] ?? 'Processing');
if ($id < 1 || $target === '') { fwrite(STDERR, "usage: advance_doc.php <id> <status>\n"); exit(1); }

// The transitions the desk allows, in order.
$order = ['Filed', 'Processing', 'Ready', 'Claimed'];

$jar = tempnam(sys_get_temp_dir(), 'advcookie');
$orig = null;
register_shutdown_function(static function () use ($id, &$orig, $jar) {
    if ($orig !== null && $orig !== '') {
        // Put back BOTH the status and the lifecycle columns. Restoring
        // the status alone was not enough: the first version of this
        // left approval_reason / ready_at / processed_by / processed_date
        // / release_date stamped on the row, so a fixture that was
        // "filed at the counter" came back looking signed off but
        // filed - a state the desk can never actually reach, and
        // therefore a state the rail had never been seen in.
        // The activity log cannot be un-written, so it is removed
        // here rather than leaving probe entries in the fixture.
        try {
            require_once __DIR__ . '/../shared/database.php';
            $db = Database::getInstance();
            $db->query(
                'UPDATE document_requests
                    SET document_status = ?, approval_reason = NULL, ready_at = NULL,
                        processed_by = NULL, processed_date = NULL, release_date = NULL,
                        claimed_at = NULL
                  WHERE id = ?',
                [$orig, $id]
            );
            // Everything this script logged came from an admin whose
            // events are all at-or-after the run. The pre-existing
            // "Request filed" row has the original created_at, so
            // deleting by id range is safer than matching note text.
            $db->query(
                'DELETE FROM document_request_events
                  WHERE request_id = ? AND created_at >= ?',
                [$id, date('Y-m-d H:i:s', $GLOBALS['adv_started'] ?? time())]
            );
            echo "\nrestored $id to $orig (status, lifecycle columns, and log)\n";
        } catch (Throwable $e) { fwrite(STDERR, "restore failed: " . $e->getMessage() . "\n"); }
    }
    @unlink($jar);
});

$GLOBALS['adv_started'] = time();
function http_call(string $method, string $url, string $cookie, string $jar, ?array $payload = null): array {
    $ch = curl_init($url);
    $headers = [];
    if (!empty($GLOBALS['csrf'])) $headers[] = 'X-CSRF-Token: ' . $GLOBALS['csrf'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string) $body];
}

// A registrar session, the same way the seed script gets one.
$cm = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/desk_session.php') . ' 2>&1', $cm, $rc);
$sid = null;
foreach ($cm as $line) {
    if (preg_match('/SESSION=(\S+)/', $line, $m)) { $sid = $m[1]; break; }
}
if ($sid === null) { fwrite(STDERR, "no session: " . implode(' | ', $cm) . "\n"); exit(1); }
file_put_contents($jar, "# Netscape HTTP Cookie File\nlocalhost\tFALSE\t/\tFALSE\t0\tBCP_REGISTRAR_SESSION\t$sid\n");

require_once __DIR__ . '/../shared/database.php';
// The CSRF token, read off a real page render exactly as the seed
// script does. The API rejects writes without it, so this is not
// optional.
$page = http_call('GET', 'http://localhost/registrar-ai-system/registrar/documents.php', $sid, $jar);
if (!preg_match('/<meta\s+name=[\'"]csrf-token[\'"]\s+content=[\'"]([^\'"]+)[\'"]/', $page['body'], $m)) {
    fwrite(STDERR, "no csrf token in page render\n"); exit(1);
}
if ($m[1] === '') { fwrite(STDERR, "empty csrf
"); exit(1); }
$GLOBALS['csrf'] = $m[1];
echo "csrf ok\n";

$db = Database::getInstance();
$orig = (string) $db->fetchColumn('SELECT document_status FROM document_requests WHERE id = ?', [$id]);
echo "id=$id from=$orig target=$target\n";

if (!in_array($orig, $order, true) || !in_array($target, $order, true)) {
    fwrite(STDERR, "unsupported move\n"); exit(1);
}
$from = array_search($orig, $order, true);
$to   = array_search($target, $order, true);

$base = 'http://localhost/registrar-ai-system/api/documents.php';
for ($i = $from; $i < $to; $i++) {
    $step = ['Processing' => 'process', 'Ready' => 'ready', 'Claimed' => 'claim'][$order[$i + 1]] ?? null;
    if ($step === null) continue;
    $extra = $step === 'ready' ? ['approval_reason' => 'Signed by registrar', 'release_date' => date('Y-m-d', strtotime('+1 day'))] : [];
    $r = http_call('PUT', $base . '?id=' . $id, $sid, $jar, array_merge(['action' => $step], $extra));
    echo "  {$order[$i]} -> {$order[$i+1]}: HTTP {$r['code']} {$r['body']}\n";
}
echo "OK\n";

// Optional: hold the state open so a screenshot can be taken of a
// mid-track rail, which is otherwise only reachable by racing the
// restore. The browser probe runs in the window before the restore.
$hold = (int) ($argv[3] ?? 0);
if ($hold > 0) {
    fwrite(STDERR, "holding for {$hold}s\n");
    for ($s = 0; $s < $hold; $s++) { sleep(1); }
}




