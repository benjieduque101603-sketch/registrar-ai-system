<?php
// Screenshot a sibling registrar page through the same static harness, as
// a control.
//
//   php tests/ah_shot_control.php registrar/health-records.php
//
// If the control renders correctly and academic-history does not, the fault
// is in this page's CSS. If both overlap, the fault is in the harness and
// the page was never the problem - which is the difference between fixing
// the stylesheet and chasing a ghost.

$page = $argv[1] ?? 'registrar/health-records.php';
ini_set('session.use_strict_mode', '0');
session_name('BCP_REGISTRAR_SESSION');
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

$u = Database::getInstance()->fetchOne(
    "SELECT id, full_name, role FROM users
     WHERE role IN ('admin','registrar') AND is_active = 1 ORDER BY id LIMIT 1"
);
@session_start();
$_SESSION['user_id']       = $u ? (int) $u['id'] : 1;
$_SESSION['role']          = $u ? (string) $u['role'] : 'registrar';
$_SESSION['full_name']     = $u ? (string) $u['full_name'] : 'Test';
$_SESSION['last_activity'] = time();

$cwd = getcwd();
chdir(dirname(__DIR__) . '/registrar');
ob_start();
include dirname(__DIR__) . '/' . $page;
$html = ob_get_clean();
chdir($cwd);

$root = dirname(__DIR__);
$html = str_replace('../css/', $root . '/css/', $html);
$html = str_replace('../js/', $root . '/js/', $html);
$html = preg_replace('#<script[^>]*page-loader[^>]*>.*?</script>#s', '', $html);
$html = str_replace('<div id="page-loader">', '<div id="page-loader" style="display:none">', $html);

$out = __DIR__ . '/../ah_control.html';
file_put_contents($out, $html);
printf("wrote %d bytes to %s from %s\n", strlen($html), basename($out), $page);
