<?php
// Render check for registrar/documents.php.
//   php tests/desk_check.php
// Boots a real registrar session and includes the page, then asserts two
// things: that it uses the app's shared design system (so it matches
// every other registrar module), and that the walk-in lifecycle is what
// the table offers. The lifecycle itself is exercised in detail by
// status_realign_check.php against the database.
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
// Boot the session FIRST: session_config.php calls session_start(), which
// replaces $_SESSION wholesale. Anything assigned before that is discarded,
// and the page's requireRole() guard would bounce us to the login page.
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

// registrar/documents.php includes ../includes/header.php. PHP resolves a
// relative include against the *calling* script's directory, and the calling
// script here is this file in tests/ — so the page's own relative includes
// would miss. Stand in the registrar directory for the duration.
$cwd = getcwd();
chdir(__DIR__ . '/../registrar');

ob_start();
$warn = [];
set_error_handler(function ($no, $str, $file, $line) use (&$warn) {
    $warn[] = "$str in $file:$line";
    return true;
});
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

$fail = 0;
function check(string $label, bool $ok, string $detail = '') {
    global $fail;
    if (!$ok) $fail++;
    printf("  %-46s %s%s\n", $label, $ok ? 'OK' : 'FAIL', $detail !== '' ? "  ($detail)" : '');
}

echo "Counter desk render check (user_id " . (int) $u . ")\n";
printf("  output: %d bytes\n\n", strlen($html));

echo "House style\n";
// The page must ride the shared design system, not carry a private skin.
// These are the primitives every other registrar module uses.
foreach ([
    'metric strip' => 'class="dq-strip"',
    'chart panel'  => 'class="panel"',
    'data table'   => 'class="table"',
    'filter bar'   => 'class="dq-filters"',
] as $label => $needle) {
    check('uses ' . $label, strpos($html, $needle) !== false);
}
check('page links documents.css',  strpos($html, 'documents.css') !== false);
check('no bespoke root tokens',    !preg_match('/--desk\s*:|--sheet\s*:|--ink\s*:/', $html));

echo "\nForm structure\n";
// Every <form> must be closed. An unclosed form does not fail loudly: the
// HTML parser keeps it open to the end of the body, so every LATER modal's
// controls become its children. The new-request form then inherits a
// required date input from the sign modal, checkValidity() returns false on
// a form the clerk filled in correctly, and Add Request silently does
// nothing - no error, no request, no clue. It happened once already, from
// a patch that dropped the closing tag, and nothing caught it because PHP
// never sees a missing close on a void-ish tag. Counting the tags is the
// only cheap way to catch it.
$openForms  = preg_match_all('/<form\b/i', $html, $m1);
$closeForms = preg_match_all('/<\/form\s*>/i', $html, $m2);
check(
    'every <form> is closed',
    $openForms > 0 && $openForms === $closeForms,
    sprintf('%d open / %d close', $openForms, $closeForms)
);

// The new-request form must end before the next modal opens, or it will
// swallow that modal's fields.
$formEnd  = strpos($html, '</form>');
$nextModal = strpos($html, 'id="approveReleaseModal"');
check(
    'new request form closes before the next modal',
    $formEnd !== false && $nextModal !== false && $formEnd < $nextModal,
    $formEnd === false ? 'no </form> found' : 'closes at ' . $formEnd
);

check('no custom card classes',    strpos($html, 'dq-card') === false);
check('no lane grid',              strpos($html, 'dq-lane') === false);

echo "\nRetired vocabulary must not leak\n";
foreach (['Awaiting Payment', 'Awaiting_Payment', 'awaiting-payment', '>Shipped<', 'Ready for Release', 'Request Queue'] as $bad) {
    check('absent: ' . $bad, strpos($html, $bad) === false);
}

echo "\nWalk-in lifecycle is offered\n";
foreach (['Filed', 'Being prepared', 'Ready for collection', 'Claimed', 'Rejected'] as $term) {
    check('presents: ' . $term, strpos($html, $term) !== false);
}
// The action buttons are asserted against the SOURCE, not the render: which
// button appears depends on which statuses the data happens to contain, and
// every row may legitimately be Filed on a fresh database. The page must
// still offer the whole path, so read the template.
//
// The labels themselves now live in doc_next_step() in
// shared/document_process.php rather than inline in the markup — one
// definition feeding both this page and the API guard, so the button
// and the guard cannot disagree. tests/process_check.php asserts the
// per-status mapping; here we only assert the path is reachable.
$src   = file_get_contents(__DIR__ . '/../registrar/documents.php');
$proc  = file_get_contents(__DIR__ . '/../shared/document_process.php');

// A Filed request must be startable without payment being taken first.
// The old build hid "Process" behind a paid_at check, which is exactly
// the gate the counter lifecycle removed.
check('start action defined',   strpos($proc, 'Start preparing') !== false);
check('sign action defined',    strpos($proc, 'Sign & mark ready') !== false);
check('claim action defined',  strpos($proc, "'Claim'") !== false);
check('no client payment gate', strpos($src, 'Payment not confirmed') === false);
// Source-level, not rendered: the wording lives in the per-request detail
// row, so a database with no requests yet - which is exactly what a fresh
// install is - renders no row and the check could not pass. Asserting it
// in the output only tested whether this machine happened to have data.
check('payment taken at the counter', strpos($src, 'Paid at the counter') !== false);
// The desk renders its buttons from doc_next_step(), so the lifecycle
// path cannot be quietly dropped from the template.
check('desk drives actions from the shared step', strpos($src, 'doc_next_step(') !== false);
// Every retired status must be gone from the guard too, not just the label.
check('guard uses Filed', strpos($src, "'Pending_Clearance'") !== false
                       || strpos($proc, "'Pending_Clearance'") !== false);
check('no Shipped branch',  !preg_match("/\\\$st\s*===?\s*'Shipped'/", $src));
check('no Awaiting_Payment branch', !preg_match("/in_array\(\\\$st,[^)]*Awaiting_Payment/", $src));

echo "\nNo queue coupling\n";
// The sidebar legitimately links to the Queue module, so that word can appear
// in the chrome. What must not appear is the documents page tying itself to
// tickets, or calling its own table a queue.
check('page never queries queue_tickets', strpos($html, 'queue_tickets') === false);
check('no queue_tickets in source',      strpos($src, 'queue_tickets') === false);
check('no "Queue Table" caption',        strpos($src, 'Queue Table') === false);

echo "\nData binding\n";
$unresolved = [];
foreach (['$openCount', '$nowServing', '$lanes', '$headline', '$isPaid'] as $v) {
    if (strpos($html, $v) !== false) $unresolved[] = $v;
}
check('no unresolved PHP variables', count($unresolved) === 0, implode(', ', $unresolved));
// The harness must start a session before the page does, so the page's own
// ini_set() calls for session hardening are expected to be inert here.
// Filter that one out; anything else is a real defect.
$realWarn = array_values(array_filter($warn, fn($w) => stripos($w, 'Session ini settings cannot be changed') === false));
check('no PHP warnings raised',  count($realWarn) === 0, implode(' | ', array_slice($realWarn, 0, 3)));
    // Detects double-encoded UTF-8, e.g. an em-dash that went through a
    // latin1 round trip and now reads as a-tilde + euro + quote.
    //
    // Matched on raw bytes, not on a literal pattern. A literal pattern
    // written with the mojibake characters in it is itself fragile: save
    // this file from an editor that guesses the wrong encoding and the
    // needle is corrupted, after which the check silently stops matching
    // and reports "no encoding damage" no matter what is on the page. The
    // bytes are written as escapes so no editor can mangle them.
    $mojibake = "/\xC3\xA2\xE2\x82\xAC|\xC3\x83/";
    $hits = [];
    if (preg_match_all($mojibake, $html, $m2, PREG_OFFSET_CAPTURE)) {
        foreach (array_slice($m2[0], 0, 3) as $hit) {
            $hits[] = 'at byte ' . $hit[1] . ': ... '
                . trim(preg_replace('/\s+/', ' ', substr($html, max(0, $hit[1] - 30), 70)));
        }
    }
    check('no encoding damage', count($hits) === 0, implode(' | ', $hits));
check('no raw <?php leakage',    strpos($html, '<?php') === false);

// The page's own rules must live inside a <style> element. A missing or
// misplaced opener leaves them as visible body text: the structural checks
// above still pass, and the page is unusable. Only rendering catches it.
echo "\nCSS containment\n";
$styleBlocks = [];
preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $html, $m);
foreach ($m[1] as $block) $styleBlocks[] = $block;
$carrying = array_values(array_filter($styleBlocks, fn($b) => strpos($b, '.dq-strip') !== false));
check('page CSS is inside a <style> block', count($carrying) === 1, count($carrying) . ' block(s) carry it');
check('<style> openers match closers',
    preg_match_all('#<style\b#i', $html) === preg_match_all('#</style>#i', $html),
    preg_match_all('#<style\b#i', $html) . ' open / ' . preg_match_all('#</style>#i', $html) . ' close');
// The definitive test: remove every <style> element, then confirm none of the
// page's own rules survive in the remaining markup. That is exactly the
// failure mode — orphaned rules rendering as body text.
$withoutStyle = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);
$orphans = [];
foreach (['.dq-strip{', '.dq-metric{', '.dq-fields{', '.dq-search{'] as $sel) {
    if (strpos($withoutStyle, $sel) !== false) $orphans[] = $sel;
}
check('no CSS leaked into the body', count($orphans) === 0, implode(', ', $orphans));

// Charts are wired, not merely present.
//
// Both charts on this page were blank for a long time and nothing said so.
// The page set $use_chart = true, so Chart.js loaded from the CDN, and the
// two <canvas> elements carried correct data-attributes inside correctly
// sized .metric-canvas wrappers. But $page_scripts was never assigned, so
// js/documents.js - the only code that calls `new Chart(...)` - was never
// included. The library was on the page and nobody was asking it to draw.
//
// Every other failure mode here is loud: a 404, a PHP warning, a broken
// <form>. This one renders two empty boxes over correct axes and reads as
// "no revenue yet", which is a plausible lie. So assert the wiring.
echo "\nChart wiring\n";
check('js/documents.js is requested by the page',
    (bool) preg_match('#\$page_scripts\s*=\s*\[[^\]]*[\'"]documents\.js[\'"]#', $src),
    'no $page_scripts entry for documents.js');
check('Chart.js is enabled for the page',
    strpos($src, '$use_chart = true') !== false);
foreach (['revenueChart' => 'Revenue by Document Type', 'volumeChart' => 'Daily Intake'] as $id => $label) {
    check("$label canvas is present", strpos($html, 'id="' . $id . '"') !== false);
    check("$label canvas has a sized wrapper",
        (bool) preg_match('#class="metric-canvas"[^>]*>\s*<canvas id="' . $id . '"#', $html),
        'canvas is not the first child of a .metric-canvas wrapper');
}
// A canvas with no data-attribute payload renders an empty chart even once
// the script loads, and does so just as quietly.
foreach (['labels' => 'revenueChart', 'data' => 'revenueChart',
          'labels' => 'volumeChart', 'settled' => 'volumeChart', 'outstanding' => 'volumeChart'] as $attr => $id) {
    check("  $id carries data-$attr",
        (bool) preg_match('#<canvas id="' . $id . '"[^>]*\bdata-' . $attr . '=#s', $html));
}
$js = file_get_contents(__DIR__ . '/../js/documents.js');
check('the page builds its own charts in js/documents.js',
    strpos($js, 'new Chart(') !== false);

// Express is out of the product: priority survives only as a hidden
// Regular value the API requires, so every row is Regular. Any chart
// series or filter option still offering Express is a dead control that
// can only ever draw an empty bar or match nothing.
check('no Express series on the volume chart', !preg_match('/data-express\s*=/', $html));
check('no Express option in the type filter',
    !preg_match('#<option value="Express">#', $html));
check('no Express in chart labels', !preg_match('#label:\s*[\'"]Express[\'"]#', $js));
// The split it replaced must be the one that is actually drawn.
check('volume chart stacks settled against outstanding',
    strpos($js, "stack: 'intake'") !== false);

// ── Waiting on: the registrar's call, and Recipient is gone ──────────
// A hold is two different facts sharing one column. A balance is derived
// and re-checks itself; a note the clerk typed is a decision and must
// survive a re-check. These guards pin the distinction existing in the UI
// and the page, so a future edit cannot quietly merge them again.
check('the modal offers a Waiting on field', strpos($html, 'id="nrWaitingOn"') !== false);
check('Waiting on is optional, not required',
    (bool) preg_match('#id="nrWaitingOn"[^>]*#s', $html)
    && !preg_match('#<input[^>]*id="nrWaitingOn"[^>]*\brequired#s', $html));
check('the modal submits waiting_on', strpos($html, 'nrWaitingOn') !== false && strpos($html, 'waiting_on:') !== false);

// Recipient asked a question with one possible answer: a walk-in document
// is always collected by the student it was filed for. It must not come
// back to either form, nor be displayed on the desk.
check('no Recipient field in the request modal', strpos($html, 'nrRecipient') === false);
check('no Recipient label in the request modal', !preg_match('#<label[^>]*>\s*Recipient#', $html));
check('no Recipient row in the expanded detail', !preg_match('#<strong>Recipient:</strong>#', $html));
$studentPage = file_get_contents(__DIR__ . '/../student/documents.php');
check('no Recipient field on the student request form',
    strpos($studentPage, 'reqRecipient') === false
    && !preg_match('#<label>Recipient</label>#', $studentPage));
check('the API still accepts a recipient without requiring it',
    strpos(file_get_contents(__DIR__ . '/../api/student-documents.php'), "\$input['recipient']") !== false);

// A registrar hold must be visibly distinguishable from a derived one, or
// the next clerk clicks Re-check on a hold that was never going to move.
// These two are source-level, not rendered: the conditional is PHP, so it
// never appears in the output the page emits.
check('the row offers a way to lift a manual hold', strpos($html, 'dq-lift') !== false);
$self = file_get_contents(__DIR__ . '/../registrar/documents.php');
check('Re-check is not offered on a manual hold',
    strpos($self, "byRegistrar = (\$r['blocked_source'] ?? null) === 'registrar'") !== false
    && strpos($self, 'if ($byRegistrar):') !== false);
check('the detail banner names whose call the hold is',
    strpos($self, "(\$r['blocked_source'] ?? null) === 'registrar'") !== false
    && strpos($self, 're-checking will not clear it') !== false);
check('the banner points at a control that exists',
    strpos($self, 'Lift hold</b>') !== false && strpos($self, "liftHold(") !== false
    && strpos($self, 'function liftHold(') !== false);

// A hold a person set must be reversible by a person, or the decision
// is permanent and therefore not really theirs to make.
$api = file_get_contents(__DIR__ . '/../api/documents.php');
check('the API can lift a registrar hold', strpos($api, "'lift_hold'") !== false);
check('lifting a balance hold is refused', strpos($api, "not by you") !== false);
// Neither endpoint may name blocked_source in a column list: on a server
// where the migration has not been applied that is a fatal SQL error.
check('no column list names blocked_source (un-migrated servers)',
    !preg_match('/SELECT\s+[^`"\']*blocked_source[^`"\']*\s+FROM\s+document_requests/i',
        $api . file_get_contents(__DIR__ . '/../shared/document_process.php')));

$proc = file_get_contents(__DIR__ . '/../shared/document_process.php');
check('a registrar hold outranks the derived balance',
    (bool) preg_match("/blocked_source'\]\s*\?\?\s*null\)\s*===\s*'registrar'.*?return \[/s", $proc));
check('re-checking cannot overwrite a registrar hold',
    substr_count($proc, "'registrar'") >= 2);
check('the schema records who set a hold',
    strpos(file_get_contents(__DIR__ . '/../migrations/document_walkin_only.sql'), 'blocked_source') !== false);

echo "\n" . ($fail === 0 ? "OK — the counter desk renders clean.\n" : "FAILED — $fail check(s)\n");
exit($fail === 0 ? 0 : 1);

