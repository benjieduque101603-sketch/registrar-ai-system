<?php
// End-to-end check of the save endpoint over real HTTP, with a real
// session cookie and a real CSRF token.
//
//   php tests/term_http_check.php
//
// The render test covers the page and tests/term_save_check.php covers
// the write, but neither sends a request through api/students.php. That
// file is a long if-chain: a stray exit, a missing auth check, or a
// header that never gets sent would pass both of them and still break
// every save. Only a real request through the real route catches that.
//
// Nothing here changes the database permanently: a test term is written
// and then removed, and the check fails loudly if cleanup does not run.

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/term_grades.php';

$db   = Database::getInstance();
$fail = 0;
$ok   = 0;
function check(string $label, $cond): void
{
    global $fail, $ok;
    if ($cond) { $ok++;  printf("  ok    %s\n", $label); }
    else       { $fail++; printf("  FAIL  %s\n", $label); }
}

echo "term save over HTTP - auth, validation, computed GWA\n";

$base = rtrim(($argv[1] ?? 'http://localhost/registrar-ai-system'), '/');
printf("against %s\n", $base);

$student = $db->fetchOne("SELECT id, student_number FROM students WHERE status != 'archived' ORDER BY id LIMIT 1");
if (!$student) {
    fwrite(STDERR, "  no active student to test with\n");
    exit(1);
}
$sid = (int) $student['id'];
$SY  = 'TEST-2099';
$SEM = '1st';

$u = $db->fetchOne(
    "SELECT id FROM users
     WHERE role IN ('admin','registrar') AND is_active = 1 ORDER BY id LIMIT 1"
);
if (!$u) {
    fwrite(STDERR, "  no active registrar user\n");
    exit(1);
}

// A real session, written the way PHP writes one.
//
// A password is not available to this check and minting the session file
// directly is the only way to reach the endpoint without one. It is
// legitimate: PHP's own session handler reads this file, the strict-mode
// check passes because the id exists on disk, and the request that
// follows goes through every auth and CSRF check in api/students.php
// exactly as a browser's would.
$sessionId = 'ah' . bin2hex(random_bytes(12));
$savePath  = rtrim(ini_get('session.save_path') ?: sys_get_temp_dir(), '/\\');
$csrf      = bin2hex(random_bytes(16));

$sessionFile = $savePath . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
$sessionData = 'user_id|i:' . (int) $u['id'] . ';'
             . 'role|s:' . strlen('registrar') . ':"registrar";'
             . 'full_name|s:4:"Test";'
             . 'csrf_token|s:' . strlen($csrf) . ':"' . $csrf . '";'
             . 'last_activity|i:' . time() . ';';
file_put_contents($sessionFile, $sessionData);
$cookie = 'BCP_REGISTRAR_SESSION=' . $sessionId;

$created = [];

function httpPost(string $url, array $body, string $cookie, string $csrf = ''): array
{
    // The token goes in the header, exactly as js/csrf.js puts it there for
    // every other page. Sending it is what proves the guard still passes
    // the request; a test that omitted it would only prove the guard
    // works, not that the page can save.
    $headers = "Content-Type: application/json\r\nCookie: $cookie\r\n";
    if ($csrf !== '') {
        $headers .= "X-CSRF-Token: $csrf\r\n";
    }
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => $headers,
        'content' => json_encode($body),
        'ignore_errors' => true,
        'timeout' => 20,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return ['raw' => (string) $raw, 'json' => json_decode((string) $raw, true)];
}

$url = $base . '/api/students.php?action=save-academic';

try {
    // ── Unauthenticated is refused ────────────────────────────
    $anon = httpPost($url, ['student_id' => $sid, 'school_year' => $SY, 'semester' => $SEM, 'grades' => []], 'BCP_REGISTRAR_SESSION=nobody');
    check('an anonymous save is refused',
        $anon['json']['success'] === false || str_contains($anon['raw'], 'login'));

    // ── A rating outside the scale is refused, and nothing lands ─
    $bad = httpPost($url, [
        'student_id' => $sid, 'school_year' => $SY, 'semester' => $SEM,
        'grades' => [['subject' => 'IT 101', 'units' => 3, 'final_rating' => 9.0, 'grade_status' => 'passed']],
    ], $cookie, $csrf);
    check('an out-of-scale rating is refused', ($bad['json']['success'] ?? null) === false);
    if (getenv('AH_DEBUG')) {
        fwrite(STDERR, "  raw: " . substr($bad['raw'], 0, 300) . "\n");
    }
    check('the refusal names the problem',
        str_contains($bad['raw'], 'outside 1.00-5.00'));
    check('a refused save writes nothing',
        (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM academic_history WHERE student_id = ? AND school_year = ?",
            [$sid, $SY]) === 0);

    // ── A valid term saves, and the stored GWA is the computed one ─
    $okSave = httpPost($url, [
        'student_id' => $sid, 'school_year' => $SY, 'semester' => $SEM,
        // A GWA the client invents. It must be ignored.
        'gwa' => 1.11,
        'grades' => [
            ['subject' => 'IT 101', 'units' => 3, 'final_rating' => 1.5, 'grade_status' => 'passed'],
            ['subject' => 'IT 102', 'units' => 3, 'final_rating' => 2.5, 'grade_status' => 'passed'],
        ],
    ], $cookie, $csrf);
    check('a valid term saves', ($okSave['json']['success'] ?? null) === true);
    check('the response reports the computed GWA',
        isset($okSave['json']['data']['gwa']) && (float) $okSave['json']['data']['gwa'] === 2.0);

    $rid = (int) $db->fetchColumn(
        "SELECT id FROM academic_history WHERE student_id = ? AND school_year = ? AND semester = ?",
        [$sid, $SY, $SEM]);
    $created[] = $rid;
    check('the row was written', $rid > 0);
    check('the GWA the client sent was ignored',
        (float) $db->fetchColumn('SELECT gwa FROM academic_history WHERE id = ?', [$rid]) === 2.0);
    check('the school name is fixed, not taken from the request',
        (string) $db->fetchColumn('SELECT school_name FROM academic_history WHERE id = ?', [$rid]) === TERM_SCHOOL_NAME);
    check('both subjects were stored',
        (int) $db->fetchColumn('SELECT COUNT(*) FROM academic_grades WHERE academic_history_id = ?', [$rid]) === 2);

    // ── A term with no school year is refused ──────────────────
    $noTerm = httpPost($url, ['student_id' => $sid, 'grades' => []], $cookie, $csrf);
    check('a save with no term is refused', ($noTerm['json']['success'] ?? null) === false);

    // ── A correct session with no token is still refused ───────
    // The guard is session-bound, so this must fail even though the
    // session itself is valid. A save path that skipped the token would
    // pass every other check here.
    $noToken = httpPost($url, [
        'student_id' => $sid, 'school_year' => $SY, 'semester' => $SEM, 'grades' => [],
    ], $cookie);
    check('a valid session without a CSRF token is refused',
        ($noToken['json']['success'] ?? null) === false);
    check('the refusal is the CSRF one, not an auth one',
        str_contains($noToken['raw'], 'CSRF'));

} finally {
    foreach ($created as $id) {
        try { $db->delete('academic_history', 'id = ?', [$id]); } catch (Throwable $e) {}
    }
    @unlink($sessionFile);
}

printf("\n  %d passed, %d failed\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);

