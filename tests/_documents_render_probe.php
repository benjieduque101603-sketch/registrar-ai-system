<?php
// Renders registrar/documents.php with a stubbed login so the browser probe can
// drive the real markup. NOT a test.
//
//   php tests/_documents_render_probe.php
//
// Modelled on tests/_students_render_probe.php, and for the same two reasons:
//
//   1. cwd. documents.php includes '../includes/header.php' by RELATIVE path, so
//      it must run from inside registrar/. From the repo root it drops the
//      header and half the markup and still exits 0 - a page that passes a
//      length check with no table in it.
//
//   2. The session. documents.php opens with
//      `if (empty($_SESSION['user_id'])) redirect+exit`. Assigning $_SESSION
//      above does not get past it: session_config.php runs session_start() on
//      the way in, and a started session replaces $_SESSION wholesale, so the
//      stub is gone before the guard is tested. The session file is written to
//      disk first, in the exact `php` serializer format, under the ID the page
//      will ask for. requireRole('registrar') then passes on the role in the
//      same record.
$sessionId = 'probdocspage000001';
$savePath  = rtrim(ini_get('session.save_path'), '/\\');
$data      = 'user_id|i:1;role|s:9:"registrar";user_role|s:9:"registrar";';
if (!is_dir($savePath)) { mkdir($savePath, 0777, true); }
file_put_contents($savePath . DIRECTORY_SEPARATOR . 'sess_' . $sessionId, $data);
ini_set('session.use_cookies', '0');
session_id($sessionId);
session_name('BCP_REGISTRAR_SESSION');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/registrar-ai-system/registrar/documents.php';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SCRIPT_NAME']    = '/registrar-ai-system/registrar/documents.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../registrar/documents.php';
$_SERVER['DOCUMENT_ROOT']  = 'C:/xampp/htdocs';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'probe';

// A fatal inside the include chain is otherwise invisible: ob_start() has
// swallowed the buffer and the process just exits. The "wrote N bytes" line at
// the end is the proof the render completed.
ini_set('display_errors', '1');
error_reporting(E_ALL);
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e) { fwrite(STDERR, 'SHUTDOWN: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line'] . "\n"); }
});

ob_start();
chdir(__DIR__ . '/../registrar');
require __DIR__ . '/../registrar/documents.php';
$html = ob_get_clean();
// Into registrar/, not the repo root: the page loads ../js/*.js and ../css/*.css
// and those paths only resolve from registrar/.
file_put_contents(__DIR__ . '/../registrar/_probe_documents.html', $html);
fwrite(STDERR, 'wrote ' . strlen($html) . " bytes to registrar/_probe_documents.html\n");
