<?php
// Renders registrar/students.php with a stubbed login so the browser probe can
// drive the real markup and the real inline script. NOT a test.
//
//   php tests/_students_render_probe.php
//
// Two things have to be faked, and neither is the session itself.
//
// 1. cwd. students.php includes '../includes/header.php' by RELATIVE path, so
//    it has to run from inside registrar/. From the repo root it emits include
//    warnings, drops the header and half the markup, and still exits 0 - a
//    page that passes a length check but has no modals in it.
//
// ── The login gate ────────────────────────────────────────────
// students.php opens with `if (empty($_SESSION['user_id'])) redirect+exit`.
// Assigning $_SESSION above does NOT get past it, because session_config.php
// runs session_start() on the way in, and a started session replaces $_SESSION
// wholesale - the stub is gone by the time the guard is tested. Assigning
// session_id() first does not help either: use_strict_mode=1 refuses an ID
// with no file behind it and silently issues a fresh empty one.
//
// So the session file is written to disk first, in the exact `php` serializer
// format, under the ID the page will ask for. Then session_start() finds a
// real session, adopts it, and the guard passes. Writing the file by hand is
// the ugly part; the alternative is a throwaway HTTP login, which needs a
// password nobody here knows.
$sessionId = 'probestudentpage001';
$savePath  = rtrim(ini_get('session.save_path'), '/\\');
$data      = 'user_id|i:1;role|s:9:"registrar";user_role|s:9:"registrar";';
if (!is_dir($savePath)) { mkdir($savePath, 0777, true); }
file_put_contents($savePath . DIRECTORY_SEPARATOR . 'sess_' . $sessionId, $data);
ini_set('session.use_cookies', '0');
session_id($sessionId);
session_name('BCP_REGISTRAR_SESSION');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/registrar-ai-system/registrar/students.php';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SCRIPT_NAME']    = '/registrar-ai-system/registrar/students.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../registrar/students.php';
$_SERVER['DOCUMENT_ROOT']  = 'C:/xampp/htdocs';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'probe';

// display_errors is forced on, and a shutdown handler installed, because a
// fatal inside the include chain is otherwise invisible: ob_start() has
// swallowed the buffer and the process just exits. The "wrote N bytes" line at
// the end is the proof the render completed. If that line is missing, the
// include died, and the short file left on disk is the clue - not the answer.
ini_set('display_errors', '1');
error_reporting(E_ALL);
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e) { fwrite(STDERR, 'SHUTDOWN: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line'] . "\n"); }
});

ob_start();
chdir(__DIR__ . '/../registrar');
require __DIR__ . '/../registrar/students.php';
$html = ob_get_clean();
// The copy lands in registrar/, not the repo root. students.php loads
// ../js/*.js and ../css/*.css, and those paths only resolve from registrar/.
// Served from the root they 404, so showToast() and escText() are missing,
// the row handlers throw, and the probe reports three "broken" modals that
// work fine in a browser. Probe artefacts must never be mistaken for the bug
// under investigation.
file_put_contents(__DIR__ . '/../registrar/_probe_students.html', $html);
fwrite(STDERR, 'wrote ' . strlen($html) . " bytes to registrar/_probe_students.html\n");



