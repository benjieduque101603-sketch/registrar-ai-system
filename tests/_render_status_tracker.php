<?php
// Render registrar/status-tracker.php in-process with a registrar session
// and write the HTML to st_render.html for inspection.
//
//   php tests/_render_status_tracker.php
//
// The HTTP route is not usable for this: shared/session_config.php sets
// session.use_strict_mode=1, so a session id minted by a CLI script is
// correctly refused by the web request, and every fetch lands on the
// login page. That is the app hardening working, not a defect. Rendering
// the file directly exercises the same includes, the same database work
// and the same <script> block.
ini_set('session.use_strict_mode', '0');
session_name('BCP_REGISTRAR_SESSION');

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

ob_start();
include __DIR__ . '/../registrar/status-tracker.php';
$html = ob_get_clean();

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
