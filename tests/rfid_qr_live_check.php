<?php
// End-to-end proof for the production RFID report.
//
//   php tests/rfid_qr_live_check.php
//
// "uploads/ids/id_1_1790314776.svg  404" on the live site, and the QR that
// should have been there was a broken image. This reproduces that condition
// exactly - a student_ids row whose qr_code_path names a file this host does
// not have, which is what a database carried over from another server looks
// like - and proves the page no longer asks the browser for it.
//
// The restorer is registered before anything is touched, so the row is put
// back even if this script is killed halfway.

require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/qr_generator.php';

$db = Database::getInstance();

// A filename in the shape generateStudentQrFile() produces, with a timestamp
// no local file carries. Same shape as the production 404.
$ghost     = '../uploads/ids/id_1_1790314776.svg';
$target    = $db->fetchOne(
    "SELECT id, student_id, qr_code_path FROM student_ids
      WHERE student_id IS NOT NULL AND qr_code_path IS NOT NULL AND qr_code_path <> ''
      ORDER BY id LIMIT 1"
);
if (!$target) {
    fwrite(STDERR, "  SKIP: no student_ids row with a QR to redirect.\n");
    exit(0);
}
$original = $target['qr_code_path'];

register_shutdown_function(function () use ($db, $target, $original) {
    try {
        $db->update('student_ids', ['qr_code_path' => $original], 'id = ?', [$target['id']]);
    } catch (Throwable $e) {
        fwrite(STDERR, "  RESTORE FAILED: " . $e->getMessage() . "\n");
    }
});

$fail = 0;
function check(string $label, bool $ok, string $detail = '') {
    global $fail;
    if (!$ok) $fail++;
    // The detail explains a FAILURE. Printing it next to a pass teaches the
    // reader to skim past the one line that matters.
    printf("  %-50s %s%s\n", $label, $ok ? 'OK' : 'FAIL', (!$ok && $detail !== '') ? "  ($detail)" : '');
}

printf("RFID QR live check: card %d, student %d\n", (int) $target['id'], (int) $target['student_id']);

// -- 1. The resolver refuses to hand back a URL for a file that is not there.
check('a missing QR file resolves to nothing',
    resolveStudentQrUrl($ghost, '../') === '',
    'it returned a URL the browser would 404 on');

// -- 2. A file that IS there still resolves, unchanged. A fix that breaks the
// working case is not a fix.
$real = resolveStudentQrUrl($original, '../');
check('a QR file that exists still resolves',
    $real !== '' && strpos($real, 'uploads/ids/') !== false, $real);

// -- 3. Point the row at the ghost file and render the real page.
$db->update('student_ids', ['qr_code_path' => $ghost], 'id = ?', [$target['id']]);

$u = $db->fetchOne("SELECT id, full_name, role FROM users WHERE role = 'registrar' LIMIT 1")
  ?: $db->fetchOne("SELECT id, full_name, role FROM users ORDER BY id LIMIT 1");
$_SESSION['user_id']       = (int) $u['id'];
$_SESSION['role']          = (string) $u['role'];
$_SESSION['full_name']     = (string) $u['full_name'];
$_SESSION['email']         = '';
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token']    = bin2hex(random_bytes(32));
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];

$cwd = getcwd();
chdir(__DIR__ . '/../registrar');
ob_start();
try {
    include __DIR__ . '/../registrar/rfid-cards.php';
} catch (Throwable $e) {
    ob_end_clean();
    chdir($cwd);
    fwrite(STDERR, '  FATAL: ' . $e->getMessage() . "\n");
    exit(1);
}
$html = (string) ob_get_clean();
chdir($cwd);

// -- 4. The assertions. The filename must appear nowhere as something the
// browser is asked to fetch; the in-browser generator must have taken over.
check('the page renders', $html !== '' && strpos($html, 'card-uid-display') !== false);
check('no request is made for the missing file',
    strpos($html, 'id_1_1790314776.svg') === false,
    'the ghost filename is still being served as a src');
check('no empty src is emitted for the card photo',
    !preg_match('/<img[^>]*id="cardPhoto"[^>]*\ssrc=/i', $html));
check('no inline onerror calls a function that is not defined yet',
    strpos($html, 'onerror="showIdInitialsFallback()"') === false);
check('the QR falls back to the in-browser generator',
    substr_count($html, 'data-qr-student-id=') > 0,
    'no qr-auto thumbnail was rendered');

echo "\n" . ($fail === 0 ? "OK - a missing QR file no longer breaks the page.\n" : "FAILED - $fail check(s)\n");
exit($fail === 0 ? 0 : 1);
