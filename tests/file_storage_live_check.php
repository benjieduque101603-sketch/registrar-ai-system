<?php
// End-to-end check for the Digital File Storage reports.
//
//   php tests/file_storage_live_check.php
//
// Two separate faults, one report:
//
//   "the images failed to load even in the preview and view modal"
//       uploads/ is gitignored, so a database carried over from another host
//       names files that were never deployed here. The page rendered an <img>
//       and a Download href for a file that cannot exist: 404s, a broken-image
//       glyph, and an empty preview that looked like a bug in the preview.
//
//   "uploading file not working - File chooser dialog can only be shown with
//    a user activation"
//       The dropzone was wired TWICE, so one click called input.click() twice.
//       Chrome rejects the second. The duplicate copy also declared a second
//       openUpload() that does not reset the form, and function declarations
//       are hoisted - so the reset-less stub silently replaced the real one.
//
// This renders the real page against a row pointing at a file that does not
// exist, exactly as production does, and restores the row on exit.

$root = dirname(__DIR__);
require_once $root . '/shared/database.php';
require_once $root . '/shared/stored_file.php';

$db = Database::getInstance();

$fail = 0;
function check(string $label, bool $ok, string $detail = '') {
    global $fail;
    if (!$ok) $fail++;
    printf("  %-52s %s%s\n", $label, $ok ? 'OK' : 'FAIL', (!$ok && $detail !== '') ? "  ($detail)" : '');
}

echo "File storage live check\n";

// -- 1. The resolver ---------------------------------------------------------
// A filename in the shape the production 404 had.
$ghost = '../uploads/student_files/1/1_1790314810_download.jpg';
check('a file that is not on disk resolves to nothing',
    storedFileUrl($ghost, '../') === '');
check('storedFileDiskPath agrees and returns null',
    storedFileDiskPath($ghost) === null);

$real = $db->fetchOne(
    "SELECT id, student_id, file_path FROM documents
      WHERE file_path LIKE '%.jpg' ORDER BY id LIMIT 1"
);
if (!$real) {
    fwrite(STDERR, "  SKIP: no image row to redirect.\n");
    exit(0);
}
check('a file that IS on disk still resolves',
    storedFileUrl($real['file_path'], '../') !== '',
    'the fix must not break the working case');
check('traversal in a stored path is refused',
    storedFileUrl('../../etc/passwd', '../') === ''
    && storedFileUrl('../shared/config.php', '../') === ''
    && storedFileUrl('../../registrar_ai.sql', '../') === ''
    && storedFileUrl('uploads/../../shared/config.php', '../') === '');

// Stripping '../' is not containment on its own: '../shared/config.php'
// reduces to a file that really exists, so without an allow-list the Download
// link becomes a way to read application source.
check('only the upload roots are resolvable',
    storedFileRel('../uploads/student_files/1/x.jpg') === 'uploads/student_files/1/x.jpg'
    && storedFileRel('./assets/uploads/students/x.jpg') === 'assets/uploads/students/x.jpg'
    && storedFileRel('../shared/config.php') === ''
    && storedFileRel('../registrar_ai.sql') === ''
    && storedFileRel('composer.json') === '');

// -- 2. Redirect the row at a file that was never deployed ------------------
$original = $real['file_path'];
register_shutdown_function(function () use ($db, $real, $original) {
    try {
        $db->update('documents', ['file_path' => $original], 'id = ?', [$real['id']]);
    } catch (Throwable $e) {
        fwrite(STDERR, "  RESTORE FAILED: " . $e->getMessage() . "\n");
    }
});
$db->update('documents', ['file_path' => $ghost], 'id = ?', [$real['id']]);

$u = $db->fetchOne("SELECT id, full_name, role FROM users WHERE role IN ('registrar','admin') LIMIT 1")
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
    include __DIR__ . '/../registrar/file-storage.php';
} catch (Throwable $e) {
    ob_end_clean();
    chdir($cwd);
    fwrite(STDERR, '  FATAL: ' . $e->getMessage() . "\n");
    exit(1);
}
$html = (string) ob_get_clean();
chdir($cwd);

// -- 3. The page must not ask for the file ----------------------------------
check('the page renders', $html !== '' && strpos($html, 'Digital File Storage') !== false);
check('no request is made for the missing file',
    strpos($html, '1_1790314810_download.jpg') === false,
    'the ghost filename is still in a src or href');
check('the missing file is flagged', strpos($html, 'data-missing="1"') !== false);
check('the page says so in words',
    strpos($html, 'not on this server') !== false);
check('the count is summarised at the top', strpos($html, 'class="fs-alert"') !== false);
check('a re-upload is offered', strpos($html, 'reuploadMissing(') !== false);
check('Preview is withheld for it', strpos($html, 'fa-eye-slash') !== false);

// -- 4. The upload wiring ----------------------------------------------------
$page = (string) file_get_contents(__DIR__ . '/../registrar/file-storage.php');
$body = preg_replace('#^\s*(//|\*|/\*).*$#m', '', $page);   // ignore the prose about the fix

preg_match_all("/\.addEventListener\(\s*'click'\s*,\s*function\s*\(\s*\)\s*\{\s*([A-Za-z_$][\w$]*)\.click\(\)/", $body, $m);
$fileChooserClicks = count($m[0]);
check('the file chooser is triggered exactly once', $fileChooserClicks === 1,
    "found $fileChooserClicks");

preg_match_all('/function\s+openUpload\s*\(/', $body, $o);
check('openUpload is defined exactly once', count($o[0]) === 1,
    'found ' . count($o[0]));
check('openUpload resets the form', strpos($body, "replaceDocId = null;") !== false);
check('a replace is sent when one is pending', strpos($body, "fd.append('replace_id'") !== false);

// -- 5. The RFID table shows the photo held in Digital File Storage ---------
// Reported as: "in the RFID table, the student has this RT icon".
//
// students.photo is NULL for every student here - the photographs were uploaded
// through Digital File Storage, which writes to the documents table. The RFID
// list read students.photo and nothing else, so it drew initials for a student
// whose photograph was in the database all along. The documents row was already
// selected as id_photo and used by the card-view modal; the list never consulted it.
//
// Checked by RENDERING the page, not by reading its source. The whole point is
// which of two branches the template takes, and a source assertion cannot see
// that - which is how the original bug survived: the SQL really did select a
// photo, so the code read as correct while the markup never asked for it.
//
// The row is put back first: this is the one case where the file SHOULD resolve.
$db->update('documents', ['file_path' => $original], 'id = ?', [$real['id']]);

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
$rfid = (string) ob_get_clean();
chdir($cwd);

$photoName = basename(str_replace('\\', '/', (string) $original));

check('the RFID page renders', $rfid !== '' && strpos($rfid, 'card-uid-display') !== false);
check('the RFID table uses the Digital File Storage photograph',
    strpos($rfid, $photoName) !== false,
    'the photo is not in the rendered table at all');
check('the avatar is served from uploads/student_files',
    (bool) preg_match('#<img class="student-avatar" src="\.\./uploads/student_files/#', $rfid),
    'no resolved <img class="student-avatar"> was emitted');
check('initials remain as a fallback alongside the photo',
    (bool) preg_match('#<img class="student-avatar"[^>]*>\s*<div class="student-avatar [^"]*" style="display:none;">#', $rfid),
    'the initials div is not present-and-hidden next to the photo');
check('a photo that fails at paint uncovers the initials',
    strpos($rfid, "this.nextElementSibling.style.display='flex'") !== false);

// And with the file gone again it must fall back to initials rather than
// emitting a src that 404s - the same property the file-storage row needs.
$db->update('documents', ['file_path' => $ghost], 'id = ?', [$real['id']]);
$cwd = getcwd();
chdir(__DIR__ . '/../registrar');
ob_start();
include __DIR__ . '/../registrar/rfid-cards.php';
$rfidGone = (string) ob_get_clean();
chdir($cwd);
$db->update('documents', ['file_path' => $original], 'id = ?', [$real['id']]);

check('with the photo missing, no request is made for it',
    strpos($rfidGone, $photoName) === false,
    'the filename is still emitted as a src');
check('with the photo missing, the initials stand in',
    (bool) preg_match('#<div class="student-avatar [^"]*" style="">#', $rfidGone),
    'no visible initials avatar was rendered');

echo "\n" . ($fail === 0 ? "OK - a missing file is reported, not rendered.\n" : "FAILED - $fail check(s)\n");
exit($fail === 0 ? 0 : 1);

