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

require __DIR__ . '/../shared/security_headers.php';
include $argv[2];
