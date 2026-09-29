<?php
// Probe helper for tests/queue_api_base_check.php. NOT a test.
//
//   php tests/_queue_base_probe.php "/queue/monitor.php"
//
// Prints what app_url('/api') resolves to for a given SCRIPT_NAME, with
// DOCUMENT_ROOT deliberately empty so the SCRIPT_NAME fallback is what runs.
//
// A separate PROCESS is required: app_base_path() memoises in a static, so one
// process can only ever answer for the first mount point it is asked about -
// which is exactly the assumption this test exists to challenge. It also keeps
// the shell out of the quoting business; escapeshellarg() and embedded double
// quotes do not mix on Windows.

$_SERVER['DOCUMENT_ROOT'] = '';
$_SERVER['SCRIPT_NAME']    = $argv[1] ?? '';
// SCRIPT_FILENAME has to be realistic too: app_base_path() counts how deep the
// page sits by comparing this path against APP_ROOT. Under the web server it is
// the page's real location on disk.
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/' . ltrim($argv[2] ?? '', '/');

require_once __DIR__ . '/../shared/app_path.php';

echo app_url('/api');
