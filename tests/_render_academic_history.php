<?php
// Render registrar/academic-history.php in-process with a registrar session
// and assert on the HTML.
//
//   php tests/_render_academic_history.php [sy] [sem] [program] [section]
//
// Query parameters can be passed so a filtered or empty view is rendered
// and inspected the same way, rather than only the default. The empty case
// is the one worth rendering deliberately: a stale filter is the commonest
// way to land on a list with nobody in it, and that path needs to offer a
// way out rather than just reporting the fact.
//
// The HTTP route is not usable for this: shared/session_config.php sets
// session.use_strict_mode=1, so a session id minted by a CLI script is
// correctly refused by the web request. Rendering the file directly
// exercises the same includes, the same database work and the same
// <script> block.
ini_set('session.use_strict_mode', '0');
session_name('BCP_REGISTRAR_SESSION');

if (isset($argv[1])) $_GET['sy']      = $argv[1];
if (isset($argv[2])) $_GET['sem']     = $argv[2];
if (isset($argv[3])) $_GET['program'] = $argv[3];
if (isset($argv[4])) $_GET['section'] = $argv[4];

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
include __DIR__ . '/../registrar/academic-history.php';
$html = ob_get_clean();

$out = __DIR__ . '/../ah_render.html';
file_put_contents($out, $html);
printf("rendered %d bytes to %s\n", strlen($html), basename($out));

// ── Assertions ───────────────────────────────────────────────
// The page was rebuilt from a previous-school importer into a term
// grading workspace. Both directions matter: the grading affordances
// have to be present, and the importer's vocabulary has to be gone
// from the markup rather than merely de-emphasised.
$expect = [
    'term selector'    => 'name="sy"',
    'semester select'  => 'name="sem"',
    'program filter'   => 'name="program"',
    'section filter'   => 'name="section"',
    'grades button'    => 'openGrades(',
    'grade grid'       => 'id="gradeRows"',
    'rating input'     => 'data-f="final_rating"',
    'units input'      => 'data-f="units"',
    'result select'    => 'data-f="grade_status"',
    'save action'      => 'saveGrades',
    'preview weighting' => 'function previewGwa',
    'audit button'     => 'openAudit()',
    'audit drawer'     => 'id="auditModal"',
    'audit findings'   => 'AH_AUDIT',
    'scale explained'  => 'lower is better',
];
// Checked as markup, not bare text. The comments in this rework quote the
// old labels on purpose, so a plain substring search would report them as
// still present when the only match is a comment.
$reject = [
    'previous-school header' => 'Previous schools and academic records',
    'receive button'         => 'openReceive()',
    'receive modal'          => 'id="receiveModal"',
    'school-name input'      => 'name="school_name"',
    'typed GWA input'        => 'name="gwa"',
    'old student-list view'  => 'openView(studentId)',
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

// A view with nobody in it must offer a way out, not just report the
// fact. A stale filter is the commonest way to land here, so the escape
// has to be on the page.
$filteredOut = strpos($html, 'No students match this view') !== false;
if ($filteredOut) {
    printf("  ok    empty view explains itself\n");
    if (strpos($html, 'Clear filters') !== false) {
        printf("  ok    empty view offers a way out\n");
    } else {
        $fail++;
        printf("  FAIL  empty view offers no way out\n");
    }
} elseif (strpos($html, 'class="ah-table"') !== false) {
    printf("  ok    roster table present\n");
} else {
    $fail++;
    printf("  FAIL  roster table missing\n");
}

// The GWA must not be editable anywhere. It is computed; a field for it
// would let a person re-introduce the disagreement this page removes.
if (preg_match('/<input[^>]*\bname=["\']gwa["\']/i', $html)) {
    $fail++;
    printf("  FAIL  a GWA input is still rendered\n");
} else {
    printf("  ok    no GWA input anywhere\n");
}

// The audit must be read-only: no call in the script block may reach a
// write endpoint.
if (preg_match('/fetch\([^)]*action=(?!save-academic)/', $html)) {
    $fail++;
    printf("  FAIL  the page calls an endpoint other than save-academic\n");
} else {
    printf("  ok    the only write call is save-academic\n");
}

printf("\n  %d failed\n", $fail);
exit($fail === 0 ? 0 : 1);
