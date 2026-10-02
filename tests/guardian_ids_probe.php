<?php
// Every getElementById(...) in the guardians page, cross-checked against the
// ids the rendered page actually defines.
//
// WHY: the "Sync from student information" button and the #mgPickerWrap /
// #mgPicker markup it fed were deleted together. The picker ids appeared in
// openManage()'s old no-argument branch and in a change listener, both of
// which went with it — but that pairing is exactly what is easy to get wrong.
// A leftover getElementById('mgPicker') returns null and throws on .style /
// .addEventListener, and the symptom is "the Manage Contacts modal does
// nothing", which is indistinguishable from a dead handler without this.
//
//   php tests/guardian_ids_probe.php
//
// ORDER MATTERS HERE. Nothing may be echoed before the session setup below.
// Output makes PHP treat the headers as sent, session_id() is then REFUSED
// with a warning, the page sees an empty $_SESSION and exits at its login
// guard — silently, because ob_start() is still holding the buffer. That is
// exactly how this probe's first version came to report a page full of
// "missing ids" for a page that renders perfectly well: it had never
// rendered. Hence the opening tag on line 1 and no echo above the setup.
require_once __DIR__ . '/../shared/config.php';

// Same trick as _students_render_probe.php: the page opens with a session
// guard, and a started session replaces $_SESSION wholesale, so the stub has
// to exist as a real session FILE under the id the page will ask for.
$sessionId = 'probeguardianpage01';
$savePath  = rtrim(ini_get('session.save_path'), '/\\');
if (!is_dir($savePath)) { mkdir($savePath, 0777, true); }
file_put_contents($savePath . DIRECTORY_SEPARATOR . 'sess_' . $sessionId,
    'user_id|i:1;role|s:9:"registrar";user_role|s:9:"registrar";');
ini_set('session.use_cookies', '0');
session_id($sessionId);
session_name('BCP_REGISTRAR_SESSION');

// session_config.php and security_headers.php read the request environment.
// Without these the page can bail partway, and the render stops silently
// (ob_start() is still holding the buffer).
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = '/registrar-ai-system/registrar/guardians.php';
$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['SCRIPT_NAME']     = '/registrar-ai-system/registrar/guardians.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/registrar/guardians.php';
$_SERVER['DOCUMENT_ROOT']   = 'C:/xampp/htdocs';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'probe';

// A fatal inside the include chain is invisible while ob_start() holds the
// buffer, so surface it on stderr instead.
ini_set('display_errors', '1');
error_reporting(E_ALL);
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e) { fwrite(STDERR, 'SHUTDOWN: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line'] . "\n"); }
});

chdir(__DIR__ . '/../registrar');
ob_start();
include 'guardians.php';
$html = ob_get_clean();

$defined = [];
preg_match_all('/\bid="([^"]+)"/', $html, $m);
foreach ($m[1] as $id) {
    $defined[$id] = true;
}

preg_match_all("/getElementById\('([^']+)'\)/", $html, $used);
$used = array_values(array_unique($used[1]));

$missing = array_values(array_filter($used, fn($id) => !isset($defined[$id])));
$suspect = array_values(array_filter($used, fn($id) => preg_match('/^mgPicker/', $id)));

$pass = 0; $fail = 0;
$check = function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "  PASS  $label\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail ? "  --  $detail" : '') . "\n"; }
};

echo "\n=== A. The page rendered ===\n\n";
$check('output is substantial (not a blank/error page)', strlen($html) > 20000, strlen($html) . ' bytes');
$check('no PHP error leaked into the markup',
    !preg_match('/(Fatal error|Warning:|Notice:|Uncaught)/i', $html),
    trim(strip_tags(substr($html, strpos($html, 'Fatal error') ?: 0, 200))));

echo "\n=== B. The Sync button and its picker are gone ===\n\n";
$check('no "Sync from student information" control',
    stripos($html, 'Sync from student information') === false
    || preg_match('/^\s*<!--/m', $html),   // only inside a comment
    'found: ' . substr($html, stripos($html, 'Sync from student information'), 40));
$check('#mgPicker is not in the markup', !isset($defined['mgPicker']));
$check('#mgPickerWrap is not in the markup', !isset($defined['mgPickerWrap']));
$check('#mgStudentId (the hidden input) survives', isset($defined['mgStudentId']));

echo "\n=== C. No script still reaches for the removed ids ===\n\n";
$check('no getElementById("mgPicker...") anywhere', $suspect === [], implode(', ', $suspect));
$check('every getElementById() id exists in the page',
    $missing === [],
    count($missing) . ' missing: ' . implode(', ', array_slice($missing, 0, 8)));

echo "\n=== D. The row button is the only way in ===\n\n";
$check('openManage() is declared', (bool) preg_match('/function openManage\s*\(/', $html));
$check('openManage() rejects a missing student id',
    (bool) preg_match('/function openManage\s*\([^)]*\)\s*\{\s*if\s*\(\s*!id\s*\)/', $html));
$check('every openManage() caller passes a student id',
    preg_match_all('/openManage\(\s*\)\s*"/', $html) === 0,
    'a bare openManage() call would now be a no-op toast');
// Matched against the RENDERED markup, so the pattern is the digit the PHP
// template emitted, not the source expression that produced it. A regex
// written against the source would test the template's punctuation rather
// than whether a student id actually reaches the handler.
// (Written as prose on purpose: a literal PHP tag inside a comment here is
// parsed as code, not as text, and this file is exactly where that mistake
// would hide.)
$check('the list row pencil calls openManage() with a student id',
    (bool) preg_match('/onclick="openManage\(\s*\d+\s*,/', $html),
    'no rendered onclick passes a numeric id to openManage');
$check('"Auto-fill from Enrollment" still present (different feature)',
    strpos($html, 'Auto-fill from Enrollment') !== false);

echo "\n" . str_repeat('-', 52) . "\n";
echo "  $pass passed, $fail failed\n";
echo str_repeat('-', 52) . "\n";
exit($fail === 0 ? 0 : 1);