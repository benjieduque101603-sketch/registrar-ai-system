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
// The page includes '../includes/header.php' and '../includes/sidebar.php'
// with paths relative to registrar/, not to this file. PHP resolves a
// relative include against the current working directory, so without this
// the header silently fails to load, the scoped stylesheet link is never
// emitted, and every assertion about the page shell passes vacuously.
// That is not hypothetical: it is exactly what happened before this line
// existed, and the status-tracker render test has the same gap.
$cwd = getcwd();
chdir(__DIR__ . '/../registrar');
try {
    include __DIR__ . '/../registrar/academic-history.php';
} finally {
    chdir($cwd);
}
$html = ob_get_clean();

$out = __DIR__ . '/../ah_render.html';
file_put_contents($out, $html);
printf("rendered %d bytes to %s\n", strlen($html), basename($out));

// ── Assertions ───────────────────────────────────────────────
// The page was rebuilt from a previous-school importer into a term
// grading workspace, and then onto the portal's shared design system.
// Both directions matter: the grading affordances have to be present, the
// importer's vocabulary has to be gone, and the page has to be built from
// the same components as its siblings rather than a private set of them.
$expect = [
    'term selector'     => 'name="sy"',
    'semester select'   => 'name="sem"',
    'program filter'    => 'name="program"',
    'section filter'    => 'name="section"',
    'grades button'     => 'openGrades(',
    'grade grid'        => 'id="gradeRows"',
    'rating input'      => 'data-f="final_rating"',
    'units input'       => 'data-f="units"',
    'result select'     => 'data-f="grade_status"',
    'save action'       => 'saveGrades',
    'preview weighting' => 'function previewGwa',
    'audit button'      => 'openAudit()',
    'audit drawer'      => 'id="auditModal"',
    'audit findings'    => 'AH_AUDIT',
    'scale explained'   => 'lower is better',

    // Shared components, not private ones. Each of these is a class the
    // rest of the portal already ships; a page that invents its own
    // version of one is the reason it looks like a different app.
    'shared button'     => 'class="btn btn-light"',
    'shared primary'    => 'btn btn-primary',
    'shared field'      => 'class="form-control"',
    'shared table'      => '<table class="table">',
    'shared badge'      => 'class="badge ah-state"',
    'shared overlay'    => 'class="modal-overlay ah-dialog"',
    'shared modal head' => 'class="modal-close"',

    // The scoped stylesheet, linked by the shared header the way every
    // other page gets it. The $extra_css assignment itself is not in the
    // output: the header consumes it and emits the <link>.
    'scoped stylesheet' => 'css/academic-history.css',
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
} elseif (strpos($html, '<table class="table">') !== false) {
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

// The page must not carry its own inline CSS. That was the original
// defect: the styles lived in a <style> block in the page, so they could
// not see the shared components and the result did not match its
// siblings. The shared header emits <style> blocks of its own, so this
// checks for one that belongs to the page - by looking for a selector
// only this page would define, and by confirming the block is gone from
// the source file.
$pageSource = file_get_contents(__DIR__ . '/../registrar/academic-history.php');
if (preg_match('/<style\b/i', $pageSource)) {
    $fail++;
    printf("  FAIL  the page source still contains a <style> block\n");
} else {
    printf("  ok    no inline <style> block in the page source\n");
}
// The rendered document must not carry this page's own rules either.
if (preg_match('/\.ah-header\s*\{/i', $html)) {
    $fail++;
    printf("  FAIL  the rendered page inlines .ah-header rules\n");
} else {
    printf("  ok    the rendered page does not inline its own rules\n");
}

// And the scoped stylesheet it points at has to exist, or the page renders
// unstyled while the test still passes.
$cssPath = __DIR__ . '/../css/academic-history.css';
if (is_file($cssPath)) {
    printf("  ok    css/academic-history.css exists\n");
} else {
    $fail++;
    printf("  FAIL  css/academic-history.css is missing\n");
}

// The audit must be read-only: no call in this page's own script block may
// reach a write endpoint other than the one save. Scoped to the last
// inline <script> block, which is this page's: includes/header.php ships
// its own scripts (the notification poller, for one) and those are not
// this page's business. Matching against the whole document would either
// blame the header or hide a real call here.
// Located by its own marker rather than by position: the header and the
// footer both ship inline scripts, and assuming the page's is the last one
// in the document would silently start testing the footer.
$pageScript = '';
if (preg_match('/<script[^>]*>((?:(?!<\/script>).)*?const AH =.*?)<\/script>/s', $html, $m)) {
    $pageScript = $m[1];
}
if ($pageScript === '') {
    $fail++;
    printf("  FAIL  this page's script block was not found; the checks below are not running\n");
}

$fetchCalls = [];
if (preg_match_all('/fetch\(\s*[\'"]([^\'"]+)/', $pageScript, $m)) {
    $fetchCalls = $m[1];
}
$offenders = array_values(array_filter(
    $fetchCalls,
    static fn($u) => !str_contains($u, 'action=save-academic')
));
if ($offenders) {
    $fail++;
    printf("  FAIL  the page calls another endpoint: %s\n", implode(', ', $offenders));
} elseif (count($fetchCalls) === 0) {
    $fail++;
    printf("  FAIL  no fetch call found; the assertion is not testing anything\n");
} else {
    printf("  ok    the only write call is save-academic\n");
}

// The audit drawer in particular must not reach the network at all: it
// reports on records, and a finding must not be able to change one.
$auditStart = strpos($html, 'function openAudit');
$auditEnd   = $auditStart === false ? false : strpos($html, 'function closeAudit');
if ($auditStart !== false && $auditEnd !== false) {
    $auditBody = substr($html, $auditStart, $auditEnd - $auditStart);
    if (str_contains($auditBody, 'fetch(')) {
        $fail++;
        printf("  FAIL  the audit drawer makes a request\n");
    } else {
        printf("  ok    the audit drawer makes no request\n");
    }
}

printf("\n  %d failed\n", $fail);
exit($fail === 0 ? 0 : 1);
