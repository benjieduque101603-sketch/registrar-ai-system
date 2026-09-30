<?php
// Render probe for tests/queue_api_base_check.php. NOT a test.
//
//   php tests/_queue_render_probe.php "/queue/monitor.php" "<abs path>"
//
// Renders a page with a chosen SCRIPT_NAME and prints the HTML, so the test can
// read what a browser would actually parse out of the data-api-base attribute.
//
// A separate process for the same reason as _queue_base_probe.php: app_base_path()
// memoises, so one process cannot answer for two mount points. Environment
// variables would have been tidier, but $_SERVER is only populated from the
// environment in CLI when variables_order includes 'E', and relying on a php.ini
// flag from a test is a worse dependency than one more file.

$_SERVER['DOCUMENT_ROOT']  = '';
$_SERVER['SCRIPT_NAME']     = $argv[1] ?? '';
$_SERVER['SCRIPT_FILENAME'] = $argv[2] ?? '';
$_SERVER['REQUEST_METHOD']  = 'GET';

// header.php is the shared chrome for every logged-in page. It now emits
// data-api-base so js/queue.js stops guessing on pages it does not own
// (the serving console being the important one). Simulate the session
// pieces header.php and footer.php touch so the page renders fully.
//
// The session is started AFTER security_headers.php below, because that file
// sets session ini directives and PHP refuses ini_set() once a session is
// active (it warns and the render aborts under the test's error handler).
require __DIR__ . '/../shared/security_headers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['role']    = 'registrar';
$_SESSION['email']   = 'probe@example.test';
$_SESSION['name']    = 'Probe';

// PHP resolves a relative include against the CALLING script's directory
// first, which here is tests/ - not the page's own directory. Pages under
// registrar/ and student/ include shared chrome by relative path
// ('../includes/header.php'), so the probe has to stand where the page
// stands. chdir() is safe: app_base_path() reads DOCUMENT_ROOT (empty here)
// and then works from SCRIPT_NAME and an absolute SCRIPT_FILENAME, neither of
// which is relative to the CWD.
$page = $argv[2] ?? '';
if ($page !== '' && is_file($page)) {
    chdir(dirname($page));
}
include $page;
