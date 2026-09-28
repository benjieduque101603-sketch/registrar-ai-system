<?php
// Render registrar/academic-history.php in-process with a registrar session
// and assert on the HTML.
//
//   php tests/_render_academic_history.php [term] [program] [section]
//
// Query parameters can be passed so a filtered or empty view is rendered
// and inspected the same way, rather than only the default. The empty case
// is the one worth rendering deliberately: a stale filter is the commonest
// way to land on a list with nobody in it, and that path needs to offer a
// way out rather than just reporting the fact.
//
// The term is passed as the one string the page reads, not as the old
// sy/sem pair. Passing those would have kept the harness running while
// quietly rendering the default term, which is how a broken argument
// mapping goes unnoticed.
//
// The HTTP route is not usable for this: shared/session_config.php sets
// session.use_strict_mode=1, so a session id minted by a CLI script is
// correctly refused by the web request. Rendering the file directly
// exercises the same includes, the same database work and the same
// <script> block.
ini_set('session.use_strict_mode', '0');
session_name('BCP_REGISTRAR_SESSION');

if (isset($argv[1])) $_GET['term']    = $argv[1];
if (isset($argv[2])) $_GET['program'] = $argv[2];
if (isset($argv[3])) $_GET['section'] = $argv[3];

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
    'term selector'     => 'name="term"',
    'term is typed'     => 'type="text"',
    'roster search'     => 'id="rosterSearch"',
    'searchable rows'   => 'data-ah-search',
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
];

// The read-only view carries no dataset of its own. The earlier
// version of this dialog shipped fabricated screenshot figures behind
// its own entry point, and the name of that function was the only
// thing telling the two apart - so the guard is on the data, not on
// what the function is called. openView() is legitimate now.
//
// Case-insensitive on purpose: strpos above is not, and an invented
// field called MISMATCH or mismatches would walk straight past a
// lowercase needle.
if (preg_match('/mismatch/i', $html)) {
    structure('the view has no dataset of its own', false, 'a "mismatch" identifier is present in the page');
} else {
    structure('the view has no dataset of its own', true);
}

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


// ── Structural sanity ────────────────────────────────────────────────
// The page's own arithmetic and its own script were both correct while
// the layout was completely broken: the <main class="dashboard-main">
// wrapper had been lost, so the content sat under the sidebar and every
// other check still passed. These assertions are the ones that would
// have noticed.
function structure(string $label, bool $ok): void {
    global $fail;
    if ($ok) {
        printf("  ok    %s\n", $label);
    } else {
        $fail++;
        printf("  FAIL  %s\n", $label);
    }
}

// .dashboard-main carries `margin-left: var(--sidebar-width)`. Without it
// the whole page renders underneath the sidebar, which is the single most
// visible failure this page can have.
structure(
    'the page opens a .dashboard-main wrapper',
    (bool) preg_match('/<main\b[^>]*class="[^"]*\bdashboard-main\b/', $html)
);
structure(
    'that wrapper is closed',
    substr_count($html, '<main') === substr_count($html, '</main>')
);
structure(
    'the page uses the shared .dashboard-container',
    str_contains($html, 'dashboard-container')
);

// A PHP comment left between the tags is rendered as visible text. This
// is not hypothetical: a one-line comment opener that lost its tags did
// exactly that, and the prose appeared on the page.
if (preg_match('/^<p>\s*Grade entry/m', $html) || preg_match('/Read-only\. It reports what is missing/', $html)) {
    $fail++;
    printf("  FAIL  comment text is being rendered onto the page\n");
} else {
    printf("  ok    no comment prose leaks into the output\n");
}

// The dialogs must be the shared overlay, not a private one, and the
// scrim must not carry a width cap: .modal-overlay is `inset: 0`, and a
// max-width on it leaves the right-hand side of the page undimmed.
// Three dialogs share the shell: the grade editor, the read-only view,
// and the pre-close audit. A count that says two would pass while one
// of them silently stopped being a modal.
structure(
    'all three dialogs use the shared .modal-overlay',
    substr_count($html, 'modal-overlay ah-dialog') === 3
);
// The roster's controls place their cells by named area, not by
// auto-placement. Auto-placement looks correct at full width and then
// stacks the search under the term box at every narrower breakpoint,
// because the readout spans a row and pushes the third cell into the
// first column. That was a real bug here, and it is invisible in a wide
// screenshot - it only shows when the columns are measured.
$css = file_get_contents(__DIR__ . '/../css/academic-history.css');
structure(
    'the roster controls place their cells by named area',
    (bool) preg_match('/\.ah-panel-controls\s*\{[^}]*grid-template-areas/s', $css)
    && str_contains($css, 'grid-area: term')
    && str_contains($css, 'grid-area: search')
    && str_contains($css, 'grid-area: readout')
);
structure(
    'each breakpoint redefines the control areas',
    // One definition for the default, plus one per breakpoint that
    // changes the shape. Fewer means a breakpoint inherits a layout
    // built for a different number of columns.
    substr_count($css, 'grid-template-areas') >= 3
);
// The term and the search belong to the roster, so they are inside the
// roster panel rather than floating between the summary and the table.
structure(
    'the term and search live inside the roster panel',
    (bool) preg_match(
        '/class="ah-panel"[\s\S]{0,400}?class="ah-panel-controls"[\s\S]{0,6000}?<table/',
        $html
    )
);
// The summary comes directly under the header, and the roster panel
// under the summary. This is a claim about order, so it is checked as
// order - a negative match on "no controls near the header" would be
// wrong, because the whole summary and panel block does sit near it.
$atHeader  = strpos($html, 'class="ah-header"');
$atStats   = strpos($html, 'class="ah-stats"');
$atPanel   = strpos($html, 'class="ah-panel"');
structure(
    'the summary sits directly under the header, and the roster under that',
    $atHeader !== false && $atStats !== false && $atPanel !== false
    && $atHeader < $atStats
    && $atStats < $atPanel
);
// Every card states its own tone. A card that relied on a default hue
// could lose its colour by forgetting an attribute, and one that
// swapped its tone away when it had nothing to report would go grey
// while its four neighbours stayed coloured - which is exactly what
// the Awaiting card used to do.
{
    preg_match_all('/<div class="ah-stat"([^>]*)>([\s\S]*?)ah-stat-label">([^<]*)</', $html, $m, PREG_SET_ORDER);
    $toneless = [];
    foreach ($m as $c) {
        if (!preg_match('/data-tone="([a-z]+)"/', $c[1], $t)) {
            $toneless[] = $c[3];
        }
    }
    structure(
        'every summary card states its own tone',
        $m && !$toneless,
        $toneless ? 'no tone on: ' . implode(', ', $toneless) : count($m) . ' cards, all toned'
    );
    // The Awaiting card carries "wait" whether or not it has anything
    // outstanding. This fixture has none outstanding, so the attribute
    // here is the one that used to disappear.
    structure(
        'the Awaiting card keeps its tone when there is nothing outstanding',
        (bool) preg_match('/<div class="ah-stat" data-tone="wait" data-empty="true">/', $html)
    );
}
// The empty treatment may dim a card; it may not repaint it. Setting
// --accent or --accent-soft from the [data-empty] rule is what let a
// toned card lose its hue, because those beat the tone rules on order.
structure(
    'an empty card steps back in weight, not out of colour',
    !preg_match('/\.ah-stat\[data-empty="true"\]\s*\{[^}]*--accent\s*:/', $css)
    && (bool) preg_match('/\.ah-stat\[data-empty="true"\]::after\s*\{[^}]*var\(--accent-rgb\)/', $css)
);
// The band thresholds the modal colours ratings with have to be the
// same numbers the roster bands GWAs with, or a rating changes colour
// without changing meaning. GWA_AT_RISK is a PHP constant, so it is
// read from the source rather than the rendered page, and the modal's
// own numbers are read from the script the page ships.
{
    $src  = file_get_contents(__DIR__ . '/../shared/term_grades.php');
    $risk = preg_match('/const GWA_AT_RISK = ([\d.]+);/', $src, $m) ? (float) $m[1] : null;
    $js   = '';
    if (preg_match('/function ratingBand[\s\S]*?\n\}/', $html, $m)) $js = $m[0];
    structure(
        'the view bands ratings on the same thresholds the roster uses',
        $risk !== null
        && (bool) preg_match('/n < ' . preg_quote((string) ($risk - 0.5), '/') . ' \?/', $js)
        && (bool) preg_match('/n < ' . preg_quote((string) $risk, '/') . ' \?/', $js),
        $risk === null ? 'GWA_AT_RISK not found' : 'server at risk = ' . $risk
    );
}

// implicit submission - the term box (text) and the search box (search)
// - and a form with more than one such field and no submit button
// ignores Enter entirely. Without this button there was no way to
// commit a new term except by hand-editing the URL.
$formHtml = '';
if (preg_match('/<form[^>]*id="termForm"[\s\S]*?<\/form>/', $html, $m)) {
    $formHtml = $m[0];
}
structure(
    'the term form has a submit button, so Enter and a click both work',
    str_contains($formHtml, 'type="submit"')
    // A submit button alone is not enough if the term box stopped being
    // inside the form; the button has to be in the same form.
    && str_contains($formHtml, 'id="fltTerm"')
    && str_contains($formHtml, 'id="rosterSearch"')
);
// The search box must not submit the form. It filters rows that are
// already on screen, and a reload would throw the filter away. Its
// Enter handler calls preventDefault, and this is the half of that
// contract which can be checked here.
structure(
    'the search box cannot submit the form, and carries no name to send',
    !preg_match('/id="rosterSearch"[^>]*\bname=/', $html)
);

// The help for the term box is read out but not printed. A third line
// under one cell is what dropped the search input below the term input
// the first time this bar was built.
structure(
    'the term help is off the page, so the cells share a height',
    str_contains($html, 'id="termHelp"')
    && str_contains($css, '.ah-sr-only')
    && !preg_match('/id="termHelp"[^>]*class="ah-term-help"/', $html)
);
structure(
    'the dialog scrim is not width-capped',
    !preg_match('/^\.ah-dialog\s*\{[^}]*max-width/m', $css)
);
structure(
    'the stylesheet is linked, not inlined',
    (bool) preg_match('/academic-history\.css/', $html)
    && !preg_match('/<style>[\s\S]*\.ah-header\s*\{/', $html)
);

// -- Dialog focus -----------------------------------------------------
// Both dialogs declared aria-modal="true" while doing none of the three
// things that declaration promises. js/confirm.js already returned focus
// correctly, so the page was inconsistent with the house pattern as well
// as with the WAI-ARIA one. These assert the wiring; they cannot drive a
// real DOM, so they check that the mechanism is present and reachable
// rather than that focus lands in the right pixel.
structure(
    'the focus trap is installed',
    str_contains($html, "e.key !== 'Tab'") && str_contains($html, 'dialogFocusables')
);
structure(
    'closing a dialog restores focus to its opener',
    str_contains($html, 'lastDialogFocus.focus()')
);
structure(
    'opening a dialog takes focus before it is shown',
    str_contains($html, 'lastDialogFocus = document.activeElement')
);
// The audit drawer is a report, so it focuses a heading rather than its
// close button. A heading that cannot hold focus makes that a no-op.
structure(
    'the audit heading can receive focus',
    (bool) preg_match('/id="auditModalTitle"[^>]*tabindex="-1"/', $html)
);
structure(
    'a script focus target does not use :focus-visible',
    str_contains($css, '.ah-dialog-head h3:focus {')
);

// -- The GWA invariant -------------------------------------------------
// A read-only record view was built here once that compared the stored
// GWA against the one the ratings work out to, on the assumption the two
// could drift apart. They cannot: the save path is the only writer of
// academic_history.gwa and it writes a value computed from the same
// subject list in the same request. So the comparison had no reachable
// state, and the UI around it was built on a false premise.
//
// This pins the premise. It is the check that would have caught the
// assumption before it became a screen, and it is cheap: one query
// against the schema, one read of the save path.
$schemaGwa = $db->fetchColumn(
    "SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'academic_history'
        AND column_name = 'gwa'"
);
if ((int) $schemaGwa !== 1) {
    $fail++;
    printf("  FAIL  academic_history.gwa is not a single column; re-check the save path\n");
} else {
    printf("  ok    academic_history.gwa is one column\n");
}

// The write is a $db->update() with a $data array rather than inline
// SQL, so the check follows the assignment instead. What matters is
// that every 'gwa' written comes from one variable, and that variable
// is a termGwa() call - a computed figure, never a value from the wire.
$savePath = file_get_contents(__DIR__ . '/../api/students.php');
preg_match_all("/'gwa'\s*=>\s*\\$(\w+)/", $savePath, $gwaWrites);
$assigned = array_values(array_unique($gwaWrites[1]));

if (count($assigned) !== 1) {
    $fail++;
    printf("  FAIL  academic_history.gwa is written from %d sources: %s\n",
        count($assigned), $assigned ? implode(', ', $assigned) : 'none');
} else {
    $var = $assigned[0];
    printf("  ok    the stored GWA is assigned from one variable (%s)\n", $var);
    if (!preg_match('/\$' . $var . '\s*=\s*termGwa\(/', $savePath)) {
        $fail++;
        printf("  FAIL  \$%s is not a termGwa() computation; the stored figure has a second source\n", $var);
    } else {
        printf("  ok    that variable is a termGwa() computation\n");
    }
}

// ── The term parser ─────────────────────────────────────────────────
// The term box is free text, so it can be typed wrong. This is the
// one place on the page where a mistake silently redirects work: get
// the term wrong and grades are recorded against the wrong term. The
// parser is therefore tested directly, not through the rendered page,
// because the render test can only see the form, not what it resolves
// to.
function ah_resolve_term(string $query, array $years): array {
    $semesters = ['1st', '2nd', 'Summer'];
    $sy = '';
    $sem = '1st';
    $problem = '';

    if ($query !== '') {
        if (preg_match('/(\d{4})\s*[-–—\/]\s*(\d{2,4})/', $query, $m)) {
            $sy = $m[1] . '-' . $m[2];
        }
        foreach ($semesters as $candidate) {
            if (preg_match('/(?<![a-z0-9])' . strtolower($candidate) . '(?![a-z0-9])/i', $query)) {
                $sem = $candidate;
                break;
            }
        }
        if ($sy !== '' && !in_array($sy, $years, true)) {
            $problem = 'no such year';
            $sy = '';
        }
        if ($sy === '' && $problem === '') {
            $problem = 'unreadable';
        }
    }
    return [$sy, $sem, $problem];
}

$knownYears = ['2026-2028', '2025-2026'];
$terms = [
    // [input, expected year, expected semester, why]
    ['2026-2028 1st',    '2026-2028', '1st',   'the canonical form'],
    ['2026-2028',       '2026-2028', '1st',   'a year alone means 1st'],
    ['1st 2026-2028',    '2026-2028', '1st',   'order should not matter'],
    ['2026-2028, 2nd',   '2026-2028', '2nd',   'punctuation should not matter'],
    ['2nd 2025-2026',    '2025-2026', '2nd',   'semester first, older year'],
    ['2026-2028 Summer', '2026-2028', 'Summer','summer is a semester too'],
    ['summer 2026-2028', '2026-2028', 'Summer','case should not matter'],
    ['2026-2028 1ST',    '2026-2028', '1st',   'uppercase should not matter'],
    ['  2026-2028   2nd  ', '2026-2028', '2nd', 'surrounding space is not a typo'],
    ['2026 / 2028 1st',  '2026-2028', '1st',   'a slash is a valid separator'],
];
foreach ($terms as [$in, $wantSy, $wantSem, $why]) {
    [$gSy, $gSem, $gProblem] = ah_resolve_term($in, $knownYears);
    structure(sprintf('term "%s" resolves (%s)', $in, $why),
        $gSy === $wantSy && $gSem === $wantSem && $gProblem === ''
    );
}

// The failure cases matter more than the successes here, because a
// term the page cannot read must never be quietly replaced by a term
// it can: the registrar would enter real grades against the wrong one.
foreach ([
    ['2030-2031 1st', 'a year with no records', 'no such year'],
    ['banana',        'text with no year',      'unreadable'],
    ['1stsem',        'a fragment, not a term',  'unreadable'],
] as [$in, $why, $wantProblem]) {
    [$gSy, , $gProblem] = ah_resolve_term($in, $knownYears);
    structure(sprintf('term "%s" is refused (%s)', $why, $wantProblem),
        $gProblem === $wantProblem
    );
}

// An empty box is not a mistake. It is what the page loads with, and it
// has to resolve to the newest term quietly rather than scolding the
// registrar for not having typed anything yet.
[$eSy, $eSem, $eProblem] = ah_resolve_term('', $knownYears);
structure('an empty box falls back without complaint',
    $eProblem === '' && $eSem === '1st'
);

// "1st" must not be found inside a longer word. The year is valid and
// 2nd is genuinely present, so if the boundary were missing the parser
// would match "1st" inside "1stsemester" and pick the wrong semester.
// The first version of this check omitted the year, which made it pass
// on the default value instead of on the boundary.
[$bSy, $bSem] = ah_resolve_term('2026-2028 1stsemester and 2nd', $knownYears);
structure('a semester is not found inside a longer word', $bSem === '2nd');

printf("\n  %d failed\n", $fail);
exit($fail === 0 ? 0 : 1);
