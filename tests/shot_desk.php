<?php
// Screenshot rig for the counter desk.
//   php tests/shot_desk.php
// Renders registrar/documents.php under a real registrar session and writes
// the result to registrar/__shot.html. That file sits at the same directory
// depth as the real page, so its relative asset paths (../css, ../shared)
// resolve identically, and it needs no login round-trip — the session is
// baked into the markup. Serve it over http, screenshot with headless Edge,
// then delete it: it must never be left where a browser could serve it.
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
// Boot the session first: session_start() replaces $_SESSION wholesale.
require_once __DIR__ . '/../shared/session_config.php';

$db = Database::getInstance();
$u  = $db->fetchOne(
    "SELECT id, full_name, role FROM users WHERE role IN ('admin','registrar') AND is_active = 1 ORDER BY id LIMIT 1"
);
if (!$u) {
    fwrite(STDERR, "No active registrar/admin user to simulate.\n");
    exit(1);
}
$_SESSION['user_id']       = (int) $u['id'];
$_SESSION['role']          = (string) $u['role'];
$_SESSION['full_name']     = (string) $u['full_name'];
$_SESSION['email']         = '';
$_SESSION['last_activity'] = time();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];

$cwd = getcwd();
chdir(__DIR__ . '/../registrar');

ob_start();
set_error_handler(fn() => true);
try {
    include __DIR__ . '/../registrar/documents.php';
} catch (Throwable $e) {
    ob_end_clean();
    chdir($cwd);
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
restore_error_handler();
$html = ob_get_clean();
chdir($cwd);

$dest = __DIR__ . '/../registrar/__shot.html';
file_put_contents($dest, $html);

// Optional: force the first detail row open so a screenshot shows the
// expanded state. Cosmetic only — the behaviour of the View button is
// verified by tests/view_toggle_check.js, which clicks it for real.
// Pass "expand" as the first argument to enable it.
if (($argv[1] ?? '') === 'expand') {
    $opened = preg_replace(
        '/(<tr class="doc-detail-row" id="detail-\d+") style="display:none;"/',
        '$1 style="display:;"',
        $html,
        1
    );
    file_put_contents($dest, $opened);
}
printf("wrote %s (%d bytes)\n", $dest, strlen($html));
echo "screenshot: http://localhost/registrar-ai-system/registrar/__shot.html\n";
echo "delete it when finished — it bypasses login.\n";
