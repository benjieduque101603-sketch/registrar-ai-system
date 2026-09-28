<?php
// Render registrar/status-tracker.php in-process with a registrar session
// and write the HTML to st_render.html for inspection.
//
//   php tests/_render_status_tracker.php [status] [q] [page]
//
// Query parameters can be passed so a filtered, searched or paged view can
// be rendered and inspected the same way, rather than only the default.
//
// The HTTP route is not usable for this: shared/session_config.php sets
// session.use_strict_mode=1, so a session id minted by a CLI script is
// correctly refused by the web request, and every fetch lands on the
// login page. That is the app hardening working, not a defect. Rendering
// the file directly exercises the same includes, the same database work
// and the same <script> block.
ini_set('session.use_strict_mode', '0');
session_name('BCP_REGISTRAR_SESSION');

if (isset($argv[1])) $_GET['status'] = $argv[1];
if (isset($argv[2])) $_GET['q']      = $argv[2];
if (isset($argv[3])) $_GET['page']   = $argv[3];

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();
$u  = $db->fetchOne(
    "SELECT id, full_name, role FROM users
     WHERE role IN ('admin','registrar') AND is_active = 1 ORDER BY id LIMIT 1"
);
if (!$u) {
    fwrite(STDERR, "No active registrar/admin user.\n");
    exit(1);
}

@session_start();
$_SESSION['user_id']       = (int) $u['id'];
$_SESSION['role']          = (string) $u['role'];
$_SESSION['full_name']     = (string) $u['full_name'];
$_SESSION['email']         = '';
$_SESSION['last_activity'] = time();

// A web request runs with the CWD set to the directory holding the entry
// script, so '../includes/sidebar.php' resolves. The CLI does not, so
// without this the relative includes fail, the page renders with no
// header and no sidebar, and every assertion about chrome passes
// vacuously. The includes are warnings, not fatal errors, which is why
// this went unnoticed.
$cwd = getcwd();
chdir(__DIR__ . '/../registrar');

ob_start();
include __DIR__ . '/../registrar/status-tracker.php';
$html = ob_get_clean();

chdir($cwd);

$out = __DIR__ . '/../st_render.html';
file_put_contents($out, $html);
printf("rendered %d bytes to %s\n", strlen($html), basename($out));

// ── Assertions on the rendered page ────────────────────────────
// A page that renders is not the same as a page that renders the right
// thing, and these are the elements this rework exists to provide.
$expect = [
    'work queue'      => 'st-desk',
    'queue heading'   => 'Needs a decision',
    'missed button'   => 'Check what I missed',
    'case panel'      => 'st-case',
    'assemble action' => 'assembleCase',
    'change form'     => 'Record a status change',
    'reason field'    => 'chReason',
    'attention label' => 'Activity check',
    // The footer is what loads the sidebar's behaviour. Its collapse
    // button and mobile drawer are rendered by includes/sidebar.php,
    // so a page that renders the sidebar but not the footer looks
    // complete and does nothing when the button is pressed - which is
    // exactly the bug this page shipped for a long time.
    //
    // Matched on the src attribute, not the bare filename. The comment
    // above the include in this page names each script it was missing,
    // and a bare-name search finds its own explanation and passes.
    'collapse button' => 'id="sidebarCollapse"',
    'sidebar script'  => 'src="../js/sidebar.js"',
    'logout confirm'  => 'src="../js/logout.js"',
    'idle timeout'    => 'src="../js/session-warning.js"',
    'document closed' => '</html>',
];
// Checked as markup, not as bare text. The explanatory comments in this
// file quote the old button labels on purpose, so a substring search
// reports them as still present when the only match is a comment.
$reject = [
    'old AI workspace bar' => 'AI workspace</strong>',
    'old AI report button' => 'id="btnAIReport"',
    'old anomaly label'    => 'Scan Anomalies',
    'old profile button'   => 'id="btnModalAIProfile"',
    'old brief element'    => 'id="modalAI"',
];

$fail = 0;
foreach ($expect as $label => $needle) {
    if (strpos($html, $needle) !== false) {
        printf("  ok    %s present\n", $label);
    } else {
        $fail++;
        printf("  FAIL  %s missing (%s)\n", $label, $needle);
    }
}
foreach ($reject as $label => $needle) {
    if (strpos($html, $needle) !== false) {
        $fail++;
        printf("  FAIL  %s still present\n", $label);
    } else {
        printf("  ok    %s gone\n", $label);
    }
}

printf("\n  %d failed\n", $fail);
exit($fail === 0 ? 0 : 1);
