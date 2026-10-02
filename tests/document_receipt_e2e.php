<?php
// ============================================================
//  TESTS/DOCUMENT_RECEIPT_E2E.PHP
//  End-to-end test of the GCash receipt upload, over real HTTP.
//
//    php tests/document_receipt_e2e.php
//
//  WHY THIS FILE EXISTS
//  --------------------
//  doc_store_receipt() calls is_uploaded_file() and
//  move_uploaded_file(). Both return false for a path that merely
//  EXISTS on disk — only a genuine multipart upload through PHP
//  satisfies them. So the helper cannot be exercised from the CLI at
//  all, and every earlier check of it was a check of the code AROUND
//  it (extension list, size comparison, message strings).
//
//  The failure this is here to catch is specific: if the endpoint's
//  multipart parsing is wired up wrong, doc_store_receipt() returns
//  ok=false and the student is told "No receipt file was received" —
//  a message that is indistinguishable from the student having
//  forgotten to attach anything. It looks like a UX copy bug and is
//  actually a total feature failure.
//
//  HOW IT AUTHENTICATES
//  --------------------
//  A real login needs a password nobody here knows, so the session
//  file is written directly, in the exact `php` serializer format,
//  under the id the request will carry. session_start() then finds a
//  real session and adopts it. Same approach as
//  tests/_students_render_probe.php.
// ============================================================

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

// A fatal here exits 255 with NOTHING on stdout, which is indistinguishable
// from a test that silently skipped everything. This one line turns that
// into a readable message. Left in permanently: it costs nothing and every
// future debugging session of this file will need it.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && ($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        fwrite(STDERR, "\n  FATAL  " . $e['message']
            . "\n         at " . $e['file'] . ':' . $e['line'] . "\n");
    }
});

$db = Database::getInstance();

$BASE  = 'http://localhost/registrar-ai-system';
$ENDPT = $BASE . '/api/student-documents.php';
$TMP   = sys_get_temp_dir() . '/rcpt_' . getmypid();

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $label\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail ? "  --  $detail" : '') . "\n"; }
}

function section(string $t): void { echo "\n=== $t ===\n"; }

if (!is_dir($TMP)) { mkdir($TMP, 0777, true); }

/**
 * POST multipart to the endpoint as the given user.
 *
 * $fields become regular POST parts; $fileParts maps a field name to
 * ['path'=>..., 'type'=>...] and is attached with CURLFile, which is
 * what makes $_FILES actually populate on the server.
 */
function post(array $fields, array $fileParts, string $sid, string $token): array
{
    $payload = $fields;
    foreach ($fileParts as $name => $f) {
        $payload[$name] = new CURLFile($f['path'], $f['type'], $f['name'] ?? basename($f['path']));
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $GLOBALS['ENDPT'],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'Cookie: BCP_REGISTRAR_SESSION=' . $sid,
            'X-CSRF-Token: ' . $token,
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsz  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err  = curl_error($ch);
    curl_close($ch);

    $body = $raw === false ? '' : substr((string) $raw, $hsz);
    $json = json_decode($body, true);
    return [
        'http'  => $code,
        'body'  => $body,
        'json'  => is_array($json) ? $json : null,
        'error' => $err,
        // What counts as "the request was refused" has to be judged from
        // the BODY, not the status number.
        //
        // This host's Apache only emits status codes that exist in its
        // internal table, so rejectCsrf()'s http_response_code(419) goes
        // out on the wire as 500 — 419 is an unassigned IANA code. That
        // was verified directly: a three-line file that does nothing but
        // http_response_code(419) answers 500, and the same file asking
        // for 418 also answers 500, while 400/401/403/409/422/429/451 all
        // come through intact. So ">= 500 means PHP died" is wrong here,
        // and would turn every legitimate CSRF rejection into a bogus
        // fatal. assertRefused() below matches on the refusal itself.
        'fatal' => (trim($body) === '' && $code >= 200)
            ? '(empty body)'
            : (($code >= 500 && !looksRefused($body, $json)) ? substr(trim($body), 0, 400) : null),
    ];
}

// True when the response is a deliberate refusal rather than a crash.
// Every guard in this app rejects with success:false plus a message; a
// real fatal produces an HTML error page or an empty body instead.
// Matched on that shape so the check survives the 419→500 mangling.
function looksRefused(string $body, ?array $json): bool {
    if (is_array($json) && array_key_exists('success', $json) && $json['success'] === false) {
        return true;
    }
    $b = strtolower($body);
    return str_contains($b, 'csrf')
        || str_contains($b, 'session')
        || str_contains($b, 'sign in')
        || str_contains($b, 'login');
}

// ─── Fixtures ──────────────────────────────────────────────
$tag = 'rcpte2e' . substr(bin2hex(random_bytes(4)), 0, 8);

$studentId = (int) $db->insert('students', [
    'first_name'     => 'Receipt',
    'last_name'      => $tag,
    'birth_date'     => '2007-05-14',
    'gender'         => 'Female',
    'address'        => 'E2E test address',
    'contact_number' => '09171234567',
    'email'          => $tag . '@example.test',
    'year_level'     => 2,
    'status'         => 'active',
]);

// Column names match the OTHER probes in this directory
// (notification_http_check.php et al), not what they might be guessed to be:
// the table has password_hash / full_name / is_active and has no `password`
// or `status` at all. Guessing here fails as a PDOException at INSERT, which
// exits before any assertion runs.
$userId = (int) $db->insert('users', [
    'email'         => $tag . '@example.test',
    'password_hash' => password_hash('x', PASSWORD_BCRYPT),
    'full_name'     => 'Receipt Probe',
    'role'          => 'student',
    'student_id'    => $studentId,
    'is_active'     => 1,
]);
// Column names come from `SHOW COLUMNS ON document_requests` (see
// tests/_cols.php), NOT from registrar_ai.sql. Two reasons that matters:
//   - `total_amount` / `request_number` / `requested_at` do not exist; the
//     money is `fee_amount` + `delivery_fee` and the timestamp is the
//     defaulted `request_date`.
//   - `document_type` is an ENUM of codes (form137, good_moral,
//     transcript, certificate, clearance), not free text, so the readable
//     label cannot be stored here at all.
//
// A paid, online request the student owns — the only shape the
// endpoint is meant to accept.
$reqId = (int) $db->insert('document_requests', [
    'student_id'       => $studentId,
    'catalog_id'       => null,
    'document_type'    => 'good_moral',
    'quantity'         => 1,
    'purpose'          => 'Scholarship application',
    'request_type'     => 'Regular',
    'fulfillment_type' => 'Pickup',
    'payment_method'   => 'Online',
    'fee_amount'       => 150.00,
    'delivery_fee'     => 0.00,
    'document_status'  => 'Awaiting_Payment',
    'paid_at'          => date('Y-m-d H:i:s'),
]);

// Someone else's request — used to prove the endpoint cannot be used
// to map the table or to write to a row you do not own.
$otherStudentId = (int) $db->insert('students', [
    'first_name' => 'Other', 'last_name' => $tag . 'x',
    'birth_date' => '2008-01-01', 'gender' => 'Male',
    'address' => 'x', 'contact_number' => '09171234568',
    'email' => $tag . 'x@example.test', 'year_level' => 1, 'status' => 'active',
]);
$otherReqId = (int) $db->insert('document_requests', [
    'student_id' => $otherStudentId, 'catalog_id' => null,
    'document_type' => 'transcript', 'quantity' => 1, 'purpose' => 'other',
    'request_type' => 'Regular', 'fulfillment_type' => 'Pickup',
    'payment_method' => 'Online', 'fee_amount' => 100.00, 'delivery_fee' => 0.00,
    'document_status' => 'Awaiting_Payment',
    'paid_at' => date('Y-m-d H:i:s'),
]);

// A cash request owned by this student — must be refused even though
// the student owns it and the file is valid.
$cashReqId = (int) $db->insert('document_requests', [
    'student_id' => $studentId, 'catalog_id' => null,
    'document_type' => 'certificate', 'quantity' => 1, 'purpose' => 'cash',
    'request_type' => 'Regular', 'fulfillment_type' => 'Pickup',
    'payment_method' => 'Counter', 'fee_amount' => 1000.00, 'delivery_fee' => 0.00,
    'document_status' => 'Pending_Clearance',
]);

// ─── A valid PNG to upload ──────────────────────────────────
$png = $TMP . '/receipt.png';
// 1x1 transparent PNG — the smallest thing that is genuinely a PNG,
// so the extension and magic-byte checks both have something real
// to agree about.
file_put_contents($png, base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
));

/** Receipt files written by a request, so the cleanup assertions can count them. */
function storedReceipts(): array {
    $dir = __DIR__ . '/../uploads/payment_receipts';
    return is_dir($dir) ? array_values(array_filter(scandir($dir), fn($f) => $f[0] !== '.')) : [];
}

// ─── Session for the student ────────────────────────────────
// Written to disk in the `php` serializer format before the first
// request. It has to come after the fixtures because the payload
// embeds $userId and $studentId.
//
// The id is 'rcp' + 23 hex chars = EXACTLY 26, which is not
// decoration. shared/session_config.php sets session.use_strict_mode=1,
// and strict mode rejects any id whose length differs from
// session.sid_length (26 in php.ini) or that uses characters outside
// the [0-9a-v] set -- issuing a fresh EMPTY session instead. An empty
// session fails the CSRF check before the endpoint is ever reached, so
// a short id does not look like "the upload is broken", it looks like
// "every single POST is refused" and every negative assertion in the
// test passes for entirely the wrong reason. Same construction as
// tests/term_http_check.php.
if (!function_exists('curl_init')) {
    echo "\n  SKIP  curl is not available; this test needs real HTTP.\n";
    echo "  (doc_store_receipt() calls is_uploaded_file(), which only\n";
    echo "   returns true for a genuine multipart upload.)\n";
    exit(0);
}

$sid  = 'rcp' . substr(bin2hex(random_bytes(23)), 0, 23);
$savePath = rtrim(ini_get('session.save_path'), '/\\');
$token = str_repeat('a', 32);

// s:<len>:"..." is PHP's session serializer, and the length must match the
// string EXACTLY. "student" is SEVEN characters; writing s:6:"student"
// makes session_start() fail with "Failed to decode session object" and
// silently discard the whole session - so the request arrives with an
// empty $_SESSION, every CSRF check fails, and every POSITIVE assertion
// in this test fails while every NEGATIVE one passes for entirely the
// wrong reason. strlen() rather than a literal so it cannot drift again.
function sessPair(string $k, string $v): string { return $k . '|s:' . strlen($v) . ':"' . $v . '";'; }
function sessStr(string $data): string { return sessPair('user_role', 'student') . sessPair('role', 'student') . $data; }

$payload = 'user_id|i:' . $userId
         . ';' . sessPair('role', 'student')
         . ';' . sessPair('user_role', 'student')
         . ';student_id|i:' . $studentId
         . ';' . sessPair('csrf_token', $token);
file_put_contents($savePath . DIRECTORY_SEPARATOR . 'sess_' . $sid, $payload);

// A second student's session, so the ownership test can also prove the
// refusal is not merely "you are not this row's owner" but "this row is
// not yours to touch, whoever you are".
//
// It carries its OWN valid csrf_token, which matters: without one this
// session would be refused by the CSRF guard, and the ownership test
// would pass while proving nothing about ownership at all.
$sid2 = 'rct' . substr(bin2hex(random_bytes(23)), 0, 23);
$token2 = str_repeat('b', 32);
file_put_contents($savePath . DIRECTORY_SEPARATOR . 'sess_' . $sid2,
    sessStr('user_id|i:' . ($userId + 1000) . ';student_id|i:' . $otherStudentId
    . ';' . sessPair('csrf_token', $token2)));

$before = count(storedReceipts());
echo "  endpoint: $ENDPT\n";
echo "  student:  #$studentId   request: #$reqId\n";

// ============================================================
//  1. The headline case: a valid upload actually lands
// ============================================================
section('1. A real multipart upload is stored');

$r = post(['action' => 'upload_receipt', 'request_id' => $reqId, 'gcash_ref' => 'GCASH-99887766'],
    ['payment_receipt' => ['path' => $png, 'type' => 'image/png']],
    $sid, $token);

// Built by concatenation, not "$r['fatal']": PHP's simple string
// interpolation mangles the quotes around the array key and the line
// becomes a parse error rather than the diagnostic it was meant to be.
if ($r['fatal']) { echo '  FATAL  ' . $r['fatal'] . "\n"; }
check('returns 200', $r['http'] === 200, 'http=' . $r['http'] . ' body=' . substr($r['body'], 0, 200));
check('returns JSON success', ($r['json']['success'] ?? false) === true, substr($r['body'], 0, 250));

$row = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$reqId]);
check('receipt path recorded on the request', !empty($row['payment_receipt_path']),
    'path=' . var_export($row['payment_receipt_path'] ?? null, true));
check('the file is actually on disk', !empty($row['payment_receipt_path'])
    && is_file(__DIR__ . '/../' . $row['payment_receipt_path']));
check('sha256 recorded for tamper checks', !empty($row['payment_receipt_sha256']));
check('GCash reference recorded', ($row['payment_receipt_ref'] ?? '') === 'GCASH-99887766',
    'ref=' . var_export($row['payment_receipt_ref'] ?? null, true));
check('upload timestamp set', !empty($row['payment_receipt_uploaded_at']));
// The path must sit INSIDE uploads/payment_receipts/ (that directory has
// its own .htaccess denying direct web access), and the generated name
// must not be the student-supplied one — otherwise a student could name
// a file to collide with something else on disk.
check('stored inside the protected payment_receipts dir',
    strpos((string) $row['payment_receipt_path'], 'uploads/payment_receipts/') === 0,
    'path=' . $row['payment_receipt_path']);
check('the stored name is not the student-supplied filename',
    basename((string) $row['payment_receipt_path']) !== 'receipt.png',
    'name=' . basename((string) $row['payment_receipt_path']));
check('the original filename is kept separately for display',
    $row['payment_receipt_filename'] === 'receipt.png',
    'display=' . var_export($row['payment_receipt_filename'] ?? null, true));
check('that directory denies direct web access',
    is_file(__DIR__ . '/../uploads/payment_receipts/.htaccess'));

section('2. The student CANNOT verify their own receipt');
// The whole point of the registrar gate. If this column is set by
// the upload path, the verification step is decorative.
check('verified_at is NULL after upload', $row['payment_receipt_verified_at'] === null);
check('verified_by is NULL after upload', $row['payment_receipt_verified_by'] === null);
check('response tells the student it is awaiting verification',
    stripos((string) ($r['json']['message'] ?? ''), 'registrar') !== false,
    'msg=' . ($r['json']['message'] ?? ''));

$ev = $db->fetchAll('SELECT * FROM document_request_events WHERE request_id = ? ORDER BY id', [$reqId]);
check('upload is recorded as an event', count($ev) > 0 && stripos((string) $ev[0]['note'], 'receipt') !== false,
    'events=' . count($ev));

// ============================================================
//  3. Ownership — the check that must not leak
// ============================================================
section('3. A student cannot touch another student\'s request');

$beforeForeign = count(storedReceipts());
$r = post(['action' => 'upload_receipt', 'request_id' => $otherReqId, 'gcash_ref' => ''],
    ['payment_receipt' => ['path' => $png, 'type' => 'image/png']],
    $sid, $token);

check('refused', ($r['json']['success'] ?? true) === false, substr($r['body'], 0, 200));
check('the other request is untouched',
    $db->fetchOne('SELECT payment_receipt_path FROM document_requests WHERE id = ?', [$otherReqId])['payment_receipt_path'] === null);
check('no orphan file left behind', count(storedReceipts()) === $beforeForeign,
    'before=' . $beforeForeign . ' after=' . count(storedReceipts()));

// The other direction, as the OTHER student, with their own valid token.
// Without this the section only proves one owner cannot write outside
// their own rows; it does not prove that student B cannot write into
// student A's request even when B is authenticated just as legitimately.
// The token matters: reusing student A's would be refused by the CSRF
// guard, and that refusal would be indistinguishable from ownership.
$rO = post(['action' => 'upload_receipt', 'request_id' => $reqId, 'gcash_ref' => ''],
    ['payment_receipt' => ['path' => $png, 'type' => 'image/png']], $sid2, $token2);
check('the other student is refused too', ($rO['json']['success'] ?? true) === false, substr($rO['body'], 0, 200));
check('their attempt left the owner\'s receipt intact',
    $db->fetchOne('SELECT payment_receipt_path FROM document_requests WHERE id = ?', [$reqId])['payment_receipt_path'] === $row['payment_receipt_path']);
check('no orphan from the other student either', count(storedReceipts()) === $beforeForeign,
    'files=' . count(storedReceipts()));

// The error must be the same for "does not exist" and "not yours".
// Different messages would let a student probe which ids are real.
$rA = post(['action' => 'upload_receipt', 'request_id' => 999999999, 'gcash_ref' => ''],
    ['payment_receipt' => ['path' => $png, 'type' => 'image/png']], $sid, $token);
$rB = post(['action' => 'upload_receipt', 'request_id' => $otherReqId, 'gcash_ref' => ''],
    ['payment_receipt' => ['path' => $png, 'type' => 'image/png']], $sid, $token);
check('missing vs not-yours give the same message',
    ($rA['json']['message'] ?? 'x') === ($rB['json']['message'] ?? 'y'),
    '"' . ($rA['json']['message'] ?? '') . '" vs "' . ($rB['json']['message'] ?? '') . '"');
check('still no orphan from either attempt', count(storedReceipts()) === $beforeForeign,
    'files=' . count(storedReceipts()));

// ============================================================
//  4. Cash requests do not take receipts
// ============================================================
section('4. A cash request refuses a receipt');
$r = post(['action' => 'upload_receipt', 'request_id' => $cashReqId, 'gcash_ref' => ''],
    ['payment_receipt' => ['path' => $png, 'type' => 'image/png']], $sid, $token);
check('refused', ($r['json']['success'] ?? true) === false, substr($r['body'], 0, 200));
check('the cash request has no receipt',
    $db->fetchOne('SELECT payment_receipt_path FROM document_requests WHERE id = ?', [$cashReqId])['payment_receipt_path'] === null);

// ============================================================
//  5. Bad files
// ============================================================
section('5. Malformed uploads are rejected, and leave nothing behind');

$txt = $TMP . '/evil.php';
file_put_contents($txt, "<?php echo 'pwned';");
$big = $TMP . '/big.png';
// A real PNG header so only the SIZE is wrong — a file that is both
// the wrong type and the wrong size would pass for the wrong reason.
file_put_contents($big, "\x89PNG\r\n\x1a\n" . str_repeat('0', 6 * 1024 * 1024));

$cases = [
    ['no file part at all', [], ['payment_receipt' => null]],
    ['a .php script',       ['payment_receipt' => ['path' => $txt, 'type' => 'image/png']], null],
    ['an oversize PNG',     ['payment_receipt' => ['path' => $big, 'type' => 'image/png']], null],
];
$expectRe = ['no file part at all' => '/no receipt file/i',
             'a .php script'       => '/image|photo|png/i',
             'an oversize PNG'     => '/mb|megabyte|large|size/i'];

foreach ($cases as [$label, $files]) {
    $n = count(storedReceipts());
    $clean = [];
    foreach ($files as $k => $v) { if ($v) { $clean[$k] = $v; } }
    $r = post(['action' => 'upload_receipt', 'request_id' => $reqId, 'gcash_ref' => ''], $clean, $sid, $token);
    $ok = ($r['json']['success'] ?? true) === false
        && preg_match($expectRe[$label], (string) ($r['json']['message'] ?? ''));
    check($label . ' is refused with a clear message', $ok,
        'msg="' . ($r['json']['message'] ?? substr($r['body'], 0, 120)) . '"');
    check($label . ' leaves no file on disk', count(storedReceipts()) === $n);
}

// The original receipt must have survived all of the above.
$row2 = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$reqId]);
check('the good receipt is still attached after the bad attempts',
    $row2['payment_receipt_path'] === $row['payment_receipt_path']
    && is_file(__DIR__ . '/../' . $row2['payment_receipt_path']));

// ============================================================
//  6. Re-upload replaces AND resets verification
// ============================================================
section('6. Replacing a receipt resets its verification');

// Pretend the registrar already approved the first upload.
$db->update('document_requests', [
    'payment_receipt_verified_at' => date('Y-m-d H:i:s'),
    'payment_receipt_verified_by' => $userId + 999,
], 'id = ?', [$reqId]);
$oldPath = $row['payment_receipt_path'];

$r = post(['action' => 'upload_receipt', 'request_id' => $reqId, 'gcash_ref' => 'GCASH-11112222'],
    ['payment_receipt' => ['path' => $png, 'type' => 'image/png']], $sid, $token);
check('second upload succeeds', ($r['json']['success'] ?? false) === true, substr($r['body'], 0, 200));

$row3 = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$reqId]);
check('verified_at is cleared again', $row3['payment_receipt_verified_at'] === null);
check('verified_by is cleared again', $row3['payment_receipt_verified_by'] === null);
check('waiver is cleared too', $row3['payment_receipt_waived_at'] === null && $row3['payment_receipt_waived_by'] === null);
check('the new ref replaced the old', $row3['payment_receipt_ref'] === 'GCASH-11112222');
// clearstatcache() is REQUIRED here, and the reason is easy to miss. Line
// 430 above called is_file() on this exact path while it still existed,
// and PHP caches stat results per-process. The delete happened in a
// DIFFERENT process (the HTTP request that handled upload #2), so this
// process would carry on reporting the file as present forever. Without
// the clear, this check fails on correct code.
$oldAbs = __DIR__ . '/../' . $oldPath;
clearstatcache(true, $oldAbs);
check('the SUPERSEDED file was deleted', !is_file($oldAbs), 'old=' . $oldPath);
// The surviving file must be the NEW one, not merely "some" file.
clearstatcache(true, __DIR__ . '/../' . $row3['payment_receipt_path']);
check('the file that remains is the new receipt',
    is_file(__DIR__ . '/../' . $row3['payment_receipt_path']));
check('exactly one receipt file is on disk for this flow', count(storedReceipts()) === $before + 1,
    'files=' . count(storedReceipts()) . ' expected=' . ($before + 1));

// ============================================================
//  7. Auth
// ============================================================
section('7. The endpoint still requires a session and a token');
// Asserted on the body, not the status code: rejectCsrf() asks for 419
// and this host's Apache cannot emit it (see looksRefused()). What
// actually matters is that the upload was refused AND that nothing was
// written — a guard that returned 419 but still stored the file would
// pass a status-code-only check.
$beforeFiles = count(storedReceipts());
$r = post(['action' => 'upload_receipt', 'request_id' => $reqId, 'gcash_ref' => 'GCASH-HACK'],
    ['payment_receipt' => ['path' => $png, 'type' => 'image/png']], 'nosuchsession', $token);
check('no session is refused', looksRefused($r['body'], $r['json']), 'http=' . $r['http'] . ' body=' . substr($r['body'], 0, 120));
$r = post(['action' => 'upload_receipt', 'request_id' => $reqId, 'gcash_ref' => 'GCASH-HACK'],
    ['payment_receipt' => ['path' => $png, 'type' => 'image/png']], $sid, 'wrong-token');
check('bad CSRF token is refused', looksRefused($r['body'], $r['json']), 'http=' . $r['http'] . ' body=' . substr($r['body'], 0, 120));
// "Changed nothing" means UNCHANGED, not empty. By this point the request
// legitimately carries the receipt attached in section 6, so asserting
// empty() would only be asserting that section 6 did not happen. The
// baseline is $row3, captured immediately before these attempts.
$rowX = $db->fetchOne('SELECT payment_receipt_path, payment_receipt_ref FROM document_requests WHERE id = ?', [$reqId]);
check('a refused upload changed nothing',
    $rowX['payment_receipt_path'] === $row3['payment_receipt_path']
    && $rowX['payment_receipt_ref'] === $row3['payment_receipt_ref']
    && $rowX['payment_receipt_ref'] !== 'GCASH-HACK',
    json_encode($rowX));
check('a refused upload left no file on disk', count(storedReceipts()) === $beforeFiles,
    'files=' . count(storedReceipts()) . ' expected=' . $beforeFiles);

// ============================================================
//  Cleanup
// ============================================================
section('Cleanup');
foreach ([$reqId, $otherReqId, $cashReqId] as $id) {
    $p = $db->fetchOne('SELECT payment_receipt_path FROM document_requests WHERE id = ?', [$id]);
    if ($p && !empty($p['payment_receipt_path'])) @unlink(__DIR__ . '/../' . $p['payment_receipt_path']);
    $db->query('DELETE FROM document_request_events WHERE request_id = ?', [$id]);
    $db->query('DELETE FROM document_requests WHERE id = ?', [$id]);
}
$db->query('DELETE FROM students WHERE id IN (?, ?)', [$studentId, $otherStudentId]);
// audit_logs.user_id is a FK to users, and the API's own logActivity()
// calls have already written rows pointing at this throwaway user. Without
// this delete first, the users DELETE raises 1451 and the whole test dies
// in cleanup -- AFTER every assertion has passed, which is the worst
// possible place for a fatal: the run looks like a crash, not a pass.
foreach (['audit_logs', 'contact_email_log'] as $child) {
    try {
        $cols = $db->fetchAll("SHOW COLUMNS FROM `$child` LIKE 'user_id'");
        if ($cols) $db->query("DELETE FROM `$child` WHERE user_id = ?", [$userId]);
    } catch (Exception $e) {
        // Table absent in this schema, or no user_id column. Nothing to do.
    }
}
try {
    $db->query('DELETE FROM users WHERE id = ?', [$userId]);
} catch (Exception $e) {
    echo "  WARN  test user {$userId} not removed: " . $e->getMessage() . "\n";
}
@unlink($savePath . DIRECTORY_SEPARATOR . 'sess_' . $sid);
@unlink($savePath . DIRECTORY_SEPARATOR . 'sess_' . $sid2);
foreach (glob($TMP . '/*') ?: [] as $f) @unlink($f);
@rmdir($TMP);
check('test data cleaned up', count(storedReceipts()) === $before,
    'files=' . count(storedReceipts()) . ' before=' . $before);

echo "\n" . str_repeat('-', 52) . "\n";
echo "  $pass passed, $fail failed\n";
echo str_repeat('-', 52) . "\n";
exit($fail === 0 ? 0 : 1);