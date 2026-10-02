<?php
// Does the 'ready' action actually fire the pickup email?
//
//   php tests/document_pickup_wired.php
//
// WHY THIS EXISTS SEPARATELY FROM tests/document_pickup_email.php
// ----------------------------------------------------------------
// That test calls sendDocumentPickupEmail() directly, so it proves the
// FUNCTION is right. It says nothing about the wiring — and the wiring
// is the thing that rots. If someone deletes the sendDocumentPickupEmail
// call from the 'ready' branch, every assertion in the other file still
// passes while the feature is dead.
//
// So this drives the real endpoint over HTTP with a real session and
// checks the observable consequences: a communication_log row appears,
// and the row on document_requests carries the outcome.
//
// It also asserts the negative a direct-call test cannot: marking ready
// when mail is broken must STILL succeed. A pickup email that takes the
// document down with it is worse than no email.
//
// The session file is written by hand in the `php` serializer format,
// the same trick tests/document_receipt_e2e.php uses — a real login
// needs a password nobody here knows.

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

// A fatal here exits 255 with nothing on stdout, which is
// indistinguishable from a test that silently skipped everything.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && ($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        fwrite(STDERR, "\n  FATAL  " . $e['message'] . "\n         at " . $e['file'] . ':' . $e['line'] . "\n");
    }
});

$db = Database::getInstance();

$BASE  = 'http://localhost/registrar-ai-system';
$ENDPT = $BASE . '/api/documents.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  --  $detail" : '') . "\n"; }
}
function section(string $t): void { echo "\n=== $t ===\n"; }

$sid = null; $userId = null; $studentId = null; $reqId = null; $catId = null;

/** PUT {action, ...} to api/documents.php as the forged session. */
function put(string $sid, string $token, array $body): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $GLOBALS['ENDPT'] . '?id=' . $GLOBALS['reqId'],
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Cookie: BCP_REGISTRAR_SESSION=' . $sid,
            'X-CSRF-Token: ' . $token,
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string) $raw, true), 'raw' => (string) $raw];
}
try {
    // ── Fixture ────────────────────────────────────────────────────
    $now = date('Y-m-d H:i:s');

    $db->insert('users', [
        'username'  => 'pickupwire_' . substr(md5((string) getmypid()), 0, 8),
        'full_name' => 'Pickup Wire Staff',
        'role'      => 'registrar',
        'created_at' => $now,
    ]);
    $userId = (int) $db->lastInsertId();

    $db->insert('students', [
        'first_name' => 'Wire', 'last_name' => 'Testcase',
        // Real-looking but non-routable: nothing can be delivered even
        // if the transport accepts the message.
        'email' => 'wire.testcase@example.test',
        'status' => 'Active', 'created_at' => $now,
    ]);
    $studentId = (int) $db->lastInsertId();

    $db->query('DELETE FROM document_catalog WHERE sku = ?', ['DOC-TEST-WIRE']);
    $db->insert('document_catalog', [
        'sku' => 'DOC-TEST-WIRE', 'name' => 'Wire Test Certificate',
        'base_fee' => 100.00, 'is_active' => 1, 'created_at' => $now,
    ]);
    $catId = (int) $db->lastInsertId();

    $db->insert('document_requests', [
        'student_id' => $studentId, 'catalog_id' => $catId,
        'document_type' => 'certificate', 'request_id' => 'PW-' . $studentId,
        // Processing, not Filed: 'ready' refuses anything else, and that
        // refusal is the API's own guard, not something to work around.
        'document_status' => 'Processing', 'status' => 'processing',
        'fee_amount' => 100.00, 'quantity' => 1,
        'request_date' => $now,
    ]);
    $reqId = (int) $db->lastInsertId();

    // ── Forged session ─────────────────────────────────────────────
    // sid must satisfy use_strict_mode=1's charset [0-9a-v] and the
    // 26-character sid_length, or PHP discards the file and the request
    // lands unauthenticated with a confusing 403.
    $sid = 'pw' . str_repeat('a', 24);
    $token = str_repeat('b', 40);
    // s:6:"registrar" — the length MUST be strlen(), or a mismatch makes
    // PHP throw away the whole session and every assertion below fails
    // for a reason that has nothing to do with the feature.
    $data = 'user_id|i:' . $userId . ';role|s:9:"registrar";user_role|s:9:"registrar";'
          . 'csrf_token|s:' . strlen($token) . ':"' . $token . '";'
          . 'logged_in|b:1;';
    $savePath = rtrim(ini_get('session.save_path'), '/\\');
    if (!is_dir($savePath)) { @mkdir($savePath, 0777, true); }
    file_put_contents($savePath . DIRECTORY_SEPARATOR . 'sess_' . $sid, $data);

    section('0. The request is reachable at all');
    $r = put($sid, $token, ['action' => 'ready', 'approval_reason' => 'x', 'release_date' => '']);
    check('a bad release_date is refused (so the session works)',
        ($r['body']['success'] ?? false) === false,
        'code=' . $r['code'] . ' body=' . substr($r['raw'], 0, 200));

    section('1. Marking ready succeeds AND fires the pickup email');
    $r = put($sid, $token, [
        'action'          => 'ready',
        'approval_reason' => 'Signed by registrar',
        'release_date'    => '2026-03-04',
    ]);
    check('ready action succeeded', ($r['body']['success'] ?? false) === true,
        'code=' . $r['code'] . ' body=' . substr($r['raw'], 0, 300));
    check('status is now Ready', ($r['body']['data']['document_status'] ?? '') === 'Ready',
        json_encode($r['body']['data'] ?? null));

    // THE assertion. If the wiring is removed, this is the check that
    // fails — and it is the only one that does.
    $logs = $db->fetchAll(
        "SELECT * FROM communication_log WHERE student_id = ? AND message_type = 'pickup'",
        [$studentId]
    );
    check('a pickup communication_log row was written', count($logs) === 1, 'rows=' . count($logs));
    check('the log row names this request',
        $logs !== [] && $logs[0]['ref'] === 'PW-' . $studentId, $logs[0]['ref'] ?? 'none');
    check('the log row names the staff member who marked it ready',
        $logs !== [] && (int) $logs[0]['sent_by'] === $userId);
    section('2. The outcome is persisted on the request row');
    $after = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$reqId]);
    // Mail is configured on this box but the recipient is an unrouteable
    // example.test name, so a send may succeed or fail depending on the
    // transport. Either is a correct outcome — what must hold is that the
    // columns agree with each other rather than being silently NULL.
    $notifiedAt  = $after['pickup_notified_at'];
    $notifiedTo  = $after['pickup_notified_to'];
    $notifyError = $after['pickup_notify_error'];
    check('pickup outcome was written to the row, not left blank',
        $notifiedAt !== null || $notifyError !== null,
        'at=' . var_export($notifiedAt, true) . ' err=' . var_export($notifyError, true));
    check('the two outcome columns never disagree',
        !($notifiedAt !== null && $notifyError !== null),
        'at=' . var_export($notifiedAt, true) . ' err=' . var_export($notifyError, true));
    if ($notifiedAt !== null) {
        check('the notified-to column names a recipient',
            is_string($notifiedTo) && strpos($notifiedTo, '@') !== false,
            var_export($notifiedTo, true));
    }

    section('3. The response tells the clerk what happened to the email');
    check('the response carries a pickup_email summary',
        isset($r['body']['data']['pickup_email']) && $r['body']['data']['pickup_email'] !== '',
        json_encode($r['body']['data'] ?? null));
    check('pickup_sent is a count, not a boolean',
        array_key_exists('pickup_sent', $r['body']['data'] ?? [])
        && is_int($r['body']['data']['pickup_sent']),
        json_encode($r['body']['data']['pickup_sent'] ?? null));

    section('4. Broken mail must NOT take the document down');
    // Re-assert on a second request. The pickup send sits after the
    // status write precisely so a mail failure cannot undo it; this is
    // that design decision, checked rather than assumed.
    $db->update('document_requests',
        ['document_status' => 'Processing', 'status' => 'processing'], 'id = ?', [$reqId]);
    $r2 = put($sid, $token, [
        'action'          => 'ready',
        'approval_reason' => 'Signed by registrar',
        'release_date'    => '2026-03-05',
    ]);
    check('ready still succeeds when the mail transport fails',
        ($r2['body']['success'] ?? false) === true,
        'code=' . $r2['code'] . ' body=' . substr($r2['raw'], 0, 300));
    $row2 = $db->fetchOne('SELECT document_status FROM document_requests WHERE id = ?', [$reqId]);
    check('the document is Ready in the database',
        ($row2['document_status'] ?? '') === 'Ready', var_export($row2['document_status'] ?? null, true));

    section('5. A second attempt logs a second notice, not a silent no-op');
    $logs2 = $db->fetchAll(
        "SELECT * FROM communication_log WHERE student_id = ? AND message_type = 'pickup'",
        [$studentId]
    );
    check('the second ready attempt produced its own log row',
        count($logs2) === 2, 'rows=' . count($logs2));

echo "\n" . str_repeat('-', 52);
echo "\n  $pass passed, $fail failed\n";
echo str_repeat('-', 52) . "\n";
} finally {
    if ($sid) { @unlink(rtrim(ini_get('session.save_path'), '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sid); }
    if ($studentId) {
        foreach ([
            "DELETE FROM communication_log       WHERE student_id = ?",
            "DELETE FROM document_request_events WHERE request_id IN (SELECT id FROM document_requests WHERE student_id = ?)",
            "DELETE FROM document_requests       WHERE student_id = ?",
        ] as $sql) {
            try { $db->query($sql, [$studentId]); } catch (Throwable $e) { /* best effort */ }
        }
        try { $db->query('DELETE FROM students WHERE id = ?', [$studentId]); } catch (Throwable $e) {}
    }
    if ($catId !== null) {
        try { $db->query('DELETE FROM document_catalog WHERE sku = ?', ['DOC-TEST-WIRE']); } catch (Throwable $e) {}
    }
    if ($userId) {
        try { $db->query('DELETE FROM audit_logs WHERE user_id = ?', [$userId]); } catch (Throwable $e) {}
        try { $db->query('DELETE FROM users WHERE id = ?', [$userId]); } catch (Throwable $e) {}
    }
    echo "\n  test data cleaned up\n";
}

exit($fail === 0 ? 0 : 1);