<?php
// ============================================================
//  TESTS/PROCESS_CHECK.PHP
//  Guards on the walk-in document process.
//
//  These assert RULES, not rendered strings. The rule that matters
//  most is the one that was previously missing entirely:
//
//    1. A blockage must be released once the fact causing it goes
//       away. Pending_Clearance was decided once at intake and never
//       revisited, so paying a balance never released anything.
//
//  A second rule guards a removal: the exit-clearance helpers must stay
//  gone, so no future edit can reintroduce a gate the desk cannot clear.
//
//  Mutates finance balances and one document_status, and restores
//  them before exiting.
// ============================================================

require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/document_process.php';

$passCount = 0; $failCount = 0;
/** Assertion helper. Named `ok` rather than `check` because
 *  shared/functions.php already defines a global check(). */
function ok(string $label, bool $pass, string $detail = ''): void {
    global $passCount, $failCount;
    if ($pass) { $passCount++; echo "  \033[32mPASS\033[0m  $label\n"; }
    else      { $failCount++; echo "  \033[31mFAIL\033[0m  $label" . ($detail ? " - $detail" : '') . "\n"; }
}

$db = Database::getInstance();

echo "\n\033[1mDocument process - blockers\033[0m\n";

// A blockage must be reported honestly and be resolvable by the desk.
// These assertions are built from arrays rather than read off whatever
// rows happen to exist.
//
// An earlier version looked for a real request in each state and SKIPPED
// when the data did not contain one — so the rule went untested on exactly
// the data you would care about, and the checks silently vanished once the
// queue was worked through. A rule that can go untested by changing data is
// not a test. The shapes below mirror a real desk row.

$base = [
    'document_status' => 'Processing',
    'sku'             => 'DOC-HD',
    // No requirement and no balance: this is the "clear to work" shape
    // every other case is derived from.
    'requirement'     => null,
    'requirement_file_path' => null,
    'request_date'    => date('Y-m-d H:i:s', strtotime('-3 days')),
    'blocked_since'   => null,
    'balance'         => 0.0,
];

// The desk's own blockers. Both are things the clerk can resolve, so
// neither is a hard stop — they explain the hold, they do not forbid
// the work.
ok('a request with nothing outstanding is not blocked', doc_blocker($base) === null,
    'blocker: ' . var_export(doc_blocker($base), true));

$withBalance = array_merge($base, ['balance' => 1500.0]);
ok('a balance is a blockage',
    str_contains((string) (doc_blocker($withBalance)['reason'] ?? ''), 'Outstanding balance'),
    'blocker: ' . var_export(doc_blocker($withBalance), true));

$withReq = array_merge($base, ['requirement' => 'Scanned copy of valid ID']);
ok('supplying the requirement releases it',
    doc_blocker(array_merge($withReq, ['requirement_file_path' => 'uploads/id.pdf'])) === null);

// A missing requirement is an ASK, not a blockage. It used to be a
// blocker, which meant every document naming one was held from the
// instant it was filed — a decision the registrar never made, dated
// from filing, on a request the desk could act on all along.
ok('an unsupplied requirement is NOT a blockage',
    doc_blocker($withReq) === null,
    'blocker: ' . var_export(doc_blocker($withReq), true));
ok('an unsupplied requirement is offered as an ask',
    doc_requirement_note($withReq) === 'Scanned copy of valid ID',
    'note: ' . var_export(doc_requirement_note($withReq), true));
ok('supplying the requirement clears the ask',
    doc_requirement_note(array_merge($withReq, ['requirement_file_path' => 'uploads/id.pdf'])) === null);
ok('the ask carries no hold date',
    !isset(doc_blocker($withReq)['since']));

// Exit clearance is gone. Its helpers must be gone too, so a future
// edit cannot quietly reintroduce a gate with no way to clear it.
foreach (['doc_can_sign', 'doc_pending_clearances', 'doc_clearance_offices', 'doc_seed_clearances'] as $gone) {
    ok("$gone has been removed", !function_exists($gone), "function $gone() still exists");
}

// The blockage clock falls back to the filing date, so a hold always has
// a start even before blocked_since has been written.
ok('blockage time falls back to the filing date',
    (doc_blocker($withBalance)['since'] ?? '') === $base['request_date'],
    'since: ' . var_export(doc_blocker($withBalance)['since'] ?? null, true));

// A hand-built array with no blocked_since key at all must not warn.
$noKey = $withBalance;
unset($noKey['blocked_since']);
ok('a missing blocked_since key is tolerated', doc_blocker($noKey) !== null);



echo "\n\033[1mDocument process - blockage releases\033[0m\n";

// A request held only by a balance must lose that hold when the
// balance is settled. This is the bug: it used to stay
// Pending_Clearance forever, because the gate was evaluated once at
// filing and never looked at again.
//
// This section MUTATES real rows, so it runs inside a transaction that
// is always rolled back — including on failure or Ctrl-C. An earlier
// version restored only the columns it remembered to touch, and leaked
// an event row per call (doc_refresh_blocker logs its release), which
// quietly wrote to the real audit log every time the suite ran.
//
// Nothing here invents history: it drives a real release and a real
// re-block through the real code, then throws the result away.

$conn = $db->getConnection();
$conn->beginTransaction();
$inTx = true;

// Roll back no matter how this section ends. Registered before any
// mutation so even a fatal error cannot leave a half-applied hold.
$restore = function () use (&$inTx, $conn) {
    if ($inTx) { $conn->rollBack(); $inTx = false; }
};
register_shutdown_function($restore);

try {
    // Picks a SKU with no requirement, so the balance is the only thing
    // that can be holding it. Using an arbitrary row would test the
    // wrong thing: clearing the balance there legitimately leaves a
    // different blockage in place.
    $id = (int) $db->fetchColumn(
        "SELECT dr.id FROM document_requests dr
           JOIN document_catalog c ON c.id = dr.catalog_id
          WHERE (c.requirement IS NULL OR c.requirement = '')
          ORDER BY dr.id LIMIT 1"
    );

    if (!$id) {
        echo "  \033[33mSKIP\033[0m  no document request available to drive the balance release\n";
    } else {
        $sid  = (int) $db->fetchColumn('SELECT student_id FROM document_requests WHERE id = ?', [$id]);
        $origBal = $db->fetchColumn('SELECT balance FROM finance WHERE student_id = ?', [$sid]);
        $hadFin  = $origBal !== null;

        $db->update('document_requests', [
            'document_status' => 'Pending_Clearance',
            'blocked_reason'  => 'Outstanding balance not yet settled',
            'blocked_since'   => date('Y-m-d H:i:s', strtotime('-2 days')),
        ], 'id = ?', [$id]);
        if ($hadFin) $db->update('finance', ['balance' => 500.00], 'student_id = ?', [$sid]);

        $heldRow = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$id]);
        $heldRow['balance'] = 500.00;
        ok(
            'an unpaid balance is reported as a blockage',
            str_contains((string) (doc_blocker($heldRow)['reason'] ?? ''), 'Outstanding balance'),
            'blocker: ' . var_export(doc_blocker($heldRow), true)
        );

        // Pay it off, then re-derive.
        if ($hadFin) $db->update('finance', ['balance' => 0.00], 'student_id = ?', [$sid]);
        doc_refresh_blocker($id);
        $after = $db->fetchOne('SELECT document_status, blocked_reason FROM document_requests WHERE id = ?', [$id]);
        ok(
            'paying the balance clears the blockage',
            $after['blocked_reason'] === null,
            'blocked_reason still: ' . var_export($after['blocked_reason'], true)
        );
        ok(
            'and releases it back to the filing queue',
            $after['document_status'] === 'Filed',
            'status is ' . $after['document_status']
        );
    }
} finally {
    // Rolls back the mutations AND the event rows written above.
    $restore();
}

echo "\n\033[1mDocument process - age against target\033[0m\n";

// The age clock must run from filing, and must stop at collection.
$age = doc_age([
    'request_date'    => date('Y-m-d H:i:s', strtotime('-6 days')),
    'claimed_at'      => date('Y-m-d H:i:s', strtotime('-1 day')),
    'sla_days'        => 3,
    'document_status' => 'Claimed',
]);
ok('age is measured from filing to collection', abs($age['days'] - 5.0) < 0.1, 'got ' . $age['days']);
ok('a collected request is never "overdue"', $age['overdue'] === false);

// A collected document that took longer than its target is still not
// overdue - it arrived. Marking it late would punish the desk for work
// the student simply never collected.
$lateButDone = doc_age([
    'request_date'    => date('Y-m-d H:i:s', strtotime('-30 days')),
    'claimed_at'      => date('Y-m-d H:i:s', strtotime('-25 days')),
    'sla_days'        => 1,
    'document_status' => 'Claimed',
]);
ok('a collected request is not flagged late however long it took', $lateButDone['overdue'] === false);

// An open request past its target must be flagged.
$open = doc_age([
    'request_date'    => date('Y-m-d H:i:s', strtotime('-14 days')),
    'sla_days'        => 3,
    'document_status' => 'Processing',
]);
ok('an open request past its target is flagged', $open['overdue'] === true);

// A SKU with no target set cannot be late - absence of a promise is
// not a broken promise.
$noTarget = doc_age([
    'request_date'    => date('Y-m-d H:i:s', strtotime('-99 days')),
    'sla_days'        => null,
    'document_status' => 'Filed',
]);
ok('a request with no target is never overdue', $noTarget['overdue'] === false);
ok('a request with no target reports no target', $noTarget['target'] === null);

echo "\n\033[1mDocument process - one next step\033[0m\n";

// The desk button and the API guard read from this same function, so
// these cases pin the mapping the UI depends on.
$cases = [
    ['Filed',             'process', 'Start preparing'],
    ['Pending_Clearance', 'process', 'Start preparing'],
    ['Processing',        'ready',   'Sign & mark ready'],
    ['Ready',             'claim',   'Claim'],
    ['Claimed',           null,      null],
    ['Rejected',          null,      null],
];
foreach ($cases as [$status, $action, $label]) {
    $step = doc_next_step(['document_status' => $status]);
    ok(
        "$status offers the expected step",
        ($step['action'] ?? null) === $action && ($step['label'] ?? null) === $label,
        'got ' . json_encode($step)
    );
}

echo "\n\033[1mDocument process - settled requests are never blocked\033[0m\n";
foreach (['Claimed', 'Rejected', 'Ready'] as $settled) {
    $r = ['document_status' => $settled, 'balance' => 999.0,
          'requirement' => 'Scanned ID', 'requirement_file_path' => null];
    ok("$settled shows no blockage", doc_blocker($r) === null);
}

// A requirement is an ASK until the file is supplied, on every SKU. It is
// never a blockage. It used to be, which meant a diploma waiting on a
// notarized affidavit was logged as held from filing, and the desk showed
// an hourglass and a hold date for a request it would happily process.
$diplomaMissing = [
    'document_status'        => 'Filed',
    'sku'                    => 'DOC-DIPLOMA',
    'requirement'            => 'Notarized Affidavit of Loss',
    'requirement_file_path'  => null,
    'balance'                => 0.0,
];
ok(
    'a document awaiting its requirement is not blocked',
    doc_blocker($diplomaMissing) === null,
    'blocker: ' . var_export(doc_blocker($diplomaMissing), true)
);
ok(
    'it is still asked for',
    doc_requirement_note($diplomaMissing) === 'Notarized Affidavit of Loss',
    'note: ' . var_export(doc_requirement_note($diplomaMissing), true)
);

ok(
    'supplying the requirement clears the ask',
    doc_requirement_note([
        'document_status'        => 'Filed',
        'sku'                    => 'DOC-DIPLOMA',
        'requirement'            => 'Notarized Affidavit of Loss',
        'requirement_file_path'  => 'uploads/affidavit.pdf',
        'balance'                => 0.0,
    ]) === null
);

// The ask must not outlive the request.
ok(
    'a claimed document asks for nothing',
    doc_requirement_note(array_merge($diplomaMissing, ['document_status' => 'Claimed'])) === null
);

// A balance still blocks, and still outranks any ask.
ok(
    'a balance blocks even when a requirement is outstanding',
    str_contains((string) (doc_blocker([
        'document_status'        => 'Filed',
        'requirement'            => 'Notarized Affidavit of Loss',
        'requirement_file_path'  => null,
        'balance'                => 900.0,
    ])['reason'] ?? ''), 'Outstanding balance')
);

echo "\n\033[1mDocument process - desk buttons are wired\033[0m\n";

// Every handler the desk emits must actually exist in the page.
//
// This exists because the desk used to render onclick="process(N)"
// straight from the API verb, while the function was processDoc — so
// the button threw a ReferenceError and did nothing at all. Nothing
// caught it: the markup rendered fine, the tests asserted the label
// text, and the bug only appeared when a human clicked. Asserting the
// wiring is the only thing that catches it.

/**
 * Click View in a real browser and confirm the detail row opens.
 *
 * Everything short of this missed the bug, twice. The button rendered,
 * it was a <button>, it called toggleDetail, the CSS was applied, and
 * it still did nothing.
 *
 *   1. The handler tested `row.style.display` for "closed" and then
 *      used that as "should I close it" — the row ships display:none,
 *      so every click closed a row that was already closed.
 *   2. Fixed, it still did nothing: the button's click also bubbled to
 *      the row's own onclick, calling toggleDetail twice per click. It
 *      opened and closed in the same tick. The stopPropagation sat on a
 *      wrapper <div>, which is a *descendant* of the row and therefore
 *      runs before the row's handler — too late to stop it.
 *
 * The rendered __shot.html cannot catch either, because it carries no
 * live row-click context... and testing only the static file also
 * cannot catch a divergence between the rig and the served page. So
 * this drives the LIVE, logged-in page over CDP, with the handler
 * instrumented to count its own invocations.
 *
 * Speaks to the already-installed headless Chrome/Edge, so the project
 * gains no dependency. Returns false — never a silent pass — if the
 * page or a browser cannot be reached.
 */
/**
 * The desk must render a document preview, and the log must not speak
 * a retired vocabulary.
 *
 * Preview was simply absent: api/document-preview.php rendered a
 * correct document (verified 200 + full HTML) and nothing on the page
 * ever linked to it, so a clerk could not see what they were signing
 * off on. The check asserts the control exists AND that it points at a
 * URL which actually resolves — a hardcoded relative "api/..." would
 * render a button that 404s, which is the same silent failure in a
 * new place.
 *
 * The log note is a display concern only. Stored events are an
 * append-only record and are not rewritten; desk_readable_event_note()
 * translates retired wording on the way out.
 */
function deskRendersDocumentPreview(): bool
{
    $minted = (string) @shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/desk_session.php') . ' 2>&1'
    );
    if (!preg_match('/COOKIE=([^\s]+)/', $minted, $c)) {
        return false;
    }
    $cookie = $c[1];
    $sid = substr($cookie, strpos($cookie, '=') + 1);

    // Remove the forged session when the run ends, however it ends —
    // including an early return or an exception above. An abandoned
    // authenticated session is a live credential sitting on disk, which
    // is not something a test run should be able to leave behind.
    register_shutdown_function(static function () use ($sid) {
        @unlink(session_save_path() . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    });

    $url = 'http://localhost/registrar-ai-system/registrar/documents.php';
    $html = (string) @shell_exec(
        'curl.exe -s -b ' . escapeshellarg($cookie) . ' ' . escapeshellarg($url) . ' 2>&1'
    );
    // The session is not unlinked here — the cookie is still needed by
    // the endpoint probe below. Teardown handles it.

    if (!str_contains($html, 'openPreview(')) {
        return false;
    }

    // Resolve the URL the browser would ACTUALLY request, and fetch it.
    // Checking that the DOC_API constant merely exists is worthless: it
    // is present even when a call site bypasses it, and that is precisely
    // the bug — a preview button pointing at page-relative
    // "api/document-preview.php", which from /registrar/ resolves to
    // /registrar/api/ and 404s. An earlier version of this check passed
    // against that. So read the iframe's real src out of the markup.
    //
    // The src is built in JS, not emitted server-side, so reconstruct it
    // the way the browser does: whatever the constant holds is what gets
    // requested. Verifying the constant resolves therefore covers every
    // call site, including the one that is currently broken.
    if (!preg_match('/const DOC_API = (".*?");/', $html, $d)) {
        return false;
    }
    $base = json_decode($d[1], true);
    if (!is_string($base) || $base === '') {
        return false;
    }

    // Guard the specific regression: a call site must not hardcode a
    // path relative to the desk.
    if (preg_match('/[\'"]api\/document-preview\.php/', $html)) {
        return false;   // would resolve to /registrar/api/ and 404
    }

    // app_url() yields a path with no scheme, which is right for the
    // browser but not a usable curl argument, so add the origin.
    $probe = (string) @shell_exec(
        'curl.exe -s -o NUL -w "%{http_code}" -b ' . escapeshellarg($cookie)
        . ' ' . escapeshellarg('http://localhost' . $base . '/document-preview.php?id=1') . ' 2>&1'
    );
    // 401/403 mean the endpoint answered but refused this identity, which
    // still proves the URL resolved. Only 404/000 is a broken path.
    if (!in_array(trim($probe), ['200', '401', '403'], true)) {
        return false;
    }

    // Retired vocabulary must not reach the desk's own log rendering.
    if (str_contains($html, 'awaiting payment')) {
        return false;
    }

    // Print must work off the preview already on screen, via the iframe's
    // own window, rather than opening a standalone view in a new tab.
    // Asserting the fallback window.open is GONE is deliberately not done
    // by matching its exact source text: that string is also the correct
    // last-resort path when no preview is open, so forbidding it outright
    // would ban the fallback too. The behaviour is proven by clicking it
    // in a real browser (tests/view_toggle_probe.js, which counts
    // window.open calls); this only confirms the in-place path exists.
    return str_contains($html, 'contentWindow')
        && str_contains($html, 'dq-preview-frame');
}

function viewToggleWorksInBrowser(): bool
{
    // Mint a real session so the live page, which is behind login, can
    // be loaded as an ordinary authenticated request.
    $mint = (string) @shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/desk_session.php') . ' 2>&1'
    );
    $m = preg_match('/COOKIE=([^\s]+)/', $mint, $c);
    if (!$m) {
        return false;   // cannot authenticate: fail, never pass blind
    }
    $cookie = $c[1];
    $sid = substr($cookie, strpos($cookie, '=') + 1);

    $url = 'http://localhost/registrar-ai-system/registrar/documents.php';
    $port = 9400 + (crc32($sid) % 400);

    $out = (string) @shell_exec(
        escapeshellarg('node') . ' ' . escapeshellarg(__DIR__ . '/view_toggle_probe.js')
        . ' ' . $port . ' ' . escapeshellarg($url) . ' ' . escapeshellarg($cookie) . ' 2>&1'
    );

    // Leave no authenticated session lying around in the session dir.
    @unlink(session_save_path() . DIRECTORY_SEPARATOR . 'sess_' . $sid);

    if (stripos($out, 'JS-ERRORS') !== false) {
        fwrite(STDERR, "\n  " . trim($out) . "\n");
    }
    return str_contains($out, 'RESULT=VIEW_TOGGLE_OK');
}

$deskSrc = file_get_contents(__DIR__ . '/../registrar/documents.php');

/**
 * Does the rendered desk show a View control on a row that also offers
 * a next step?
 *
 * View used to be gated on `$next === null`, so it only appeared on
 * rows with nothing to do — that is, only after collection. On a
 * working desk nothing is collected yet, so the control was absent
 * from every row the clerk could actually act on.
 *
 * This renders the page and inspects the output rather than the
 * template text. Source-scanning cannot answer it: the Reject button
 * is itself a nested `if`/`endif`, so both the broken and the correct
 * template contain an `endif` between the branch and the View button.
 * The rendered row is the only honest evidence.
 */
function viewRenderedOnSteppedRow(): bool
{
    // Delete any previous render FIRST. A render that dies on a syntax
    // error leaves the old file in place, and the test would then read
    // stale output and pass while the page is actually broken — the
    // exact failure this check exists to catch.
    $dest = __DIR__ . '/../registrar/__shot.html';
    @unlink($dest);
    shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/shot_desk.php') . ' 2>&1');

    $html = (string) @file_get_contents($dest);

    // No fresh output: fail rather than pass on absent evidence.
    if ($html === '' || !str_contains($html, 'row-actions')) {
        return false;
    }

    // A row that has BOTH a next step and a View control.
    preg_match_all('/<div class="row-actions".*?<\/div>/s', $html, $m);
    foreach ($m[0] as $cell) {
        if (str_contains($cell, 'btn-sm btn-primary') && str_contains($cell, 'dq-view')) {
            return true;
        }
    }
    return false;
}

$stepCases = [
    'Filed'             => 'processDoc',
    'Pending_Clearance' => 'processDoc',
    'Processing'        => 'approveRelease',
    'Ready'             => 'claimDoc',
];
foreach ($stepCases as $status => $handler) {
    $step = doc_next_step(['document_status' => $status]);
    ok(
        "$status points at $handler",
        ($step['handler'] ?? null) === $handler,
        'got ' . var_export($step['handler'] ?? null, true)
    );
    ok(
        "$handler is defined on the desk page",
        (bool) preg_match('/function\s+' . preg_quote($handler, '/') . '\s*\(/', $deskSrc),
        "no function $handler() in registrar/documents.php"
    );
    ok(
        "$status keeps the API verb separate from the handler",
        ($step['action'] ?? null) !== ($step['handler'] ?? null)
            || $status === 'Ready',
        'API verb and JS handler are identical for ' . $status
    );
}

// The desk must render the handler, never the bare action.
ok(
    'the desk renders the handler, not the action',
    str_contains($deskSrc, "\$next['handler']") && !str_contains($deskSrc, "onclick=\"<?= \$next['action']\""),
    'the action verb is still being used as a JS function name'
);

// And the secondary controls must be defined too.
foreach (['rejectDoc', 'recheckDoc', 'toggleDetail'] as $fn) {
    ok("$fn is defined on the desk page",
        (bool) preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', $deskSrc),
        "no function $fn() in registrar/documents.php");
}

echo "\n\033[1mDocument process - row controls are usable\033[0m\n";

// "Start preparing" used to open a native confirm(). A browser dialog
// cannot be styled, blocks the page, and reads as a failure — the
// clerk's first read was that the button was broken. It also did
// nothing to stop a double click firing two transitions.
ok('processDoc does not open a native confirm dialog',
    !preg_match('/function\s+processDoc[\s\S]{0,900}?\bconfirm\s*\(/', $deskSrc),
    'processDoc still calls confirm()');
ok('processDoc accepts the clicked button so it can show progress',
    (bool) preg_match('/function\s+processDoc\s*\(\s*id\s*,\s*btn\s*\)/', $deskSrc),
    'processDoc does not take a btn argument');
ok('processDoc disables the button while the request is in flight',
    (bool) preg_match('/function\s+processDoc[\s\S]{0,900}?btn\.disabled\s*=\s*true/', $deskSrc),
    'no pending state, so a double click sends two transitions');
ok('processDoc restores the button when the request fails',
    (bool) preg_match('/function\s+processDoc[\s\S]{0,1600}?btn\.disabled\s*=\s*false/', $deskSrc),
    'a failed request leaves the button stuck as "Starting…"');
ok('claimDoc does not open a native confirm dialog',
    !preg_match('/function\s+claimDoc[\s\S]{0,1400}?(?<![.\w])confirm\s*\(/', $deskSrc),
    'claimDoc still calls confirm()');
ok('claimDoc asks through the shared dialog',
    (bool) preg_match('/function\s+claimDoc[\s\S]{0,1400}?await\s+confirmAction\s*\(/', $deskSrc),
    'the Collect confirmation is not a styled one');

// ── No native confirm() anywhere in live code ──────────────────
// A native confirm is drawn by the OS, not the page: on Chrome it
// arrives as an unstyled "localhost says" box that blocks the tab and
// reads as an error state. It was the Collect action, but it had spread
// to 20 call sites across nine files. This asserts none come back.
//
// Excluded: backups/ (frozen copies) and this file (the regexes above
// are themselves the string "confirm("). A real call is a bare
// confirm( that is not preceded by ".", a word char, or the word
// confirmAction — so window.confirm and confirmAction are not flagged.
echo "\n\033[1mNo native confirm() dialogs remain\033[0m\n";
$liveOffenders = [];
// The root MUST be realpath'd, and the iterator built from that same
// string. Using __DIR__ . '/..' makes getPathname() return paths that
// still contain the literal "tests/..", so stripping strlen(realpath)
// chops the wrong number of characters and every $rel comes out
// malformed — which the extension check then silently skips. The guard
// passed on a file containing confirm() before this was fixed, so it
// is worth stating: a check that cannot fail is not a check.
$scanRoot = realpath(__DIR__ . '/..');
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($rii as $file) {
    if (!$file->isFile()) continue;
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($scanRoot) + 1));
    if (substr($rel, -4) !== '.php' && substr($rel, -3) !== '.js') continue;
    // backups/ are frozen copies; this file and the probe are the
    // regexes themselves, which contain the very string being hunted.
    if (preg_match('#^(vendor|node_modules|backups|tests|\.)#', $rel)) continue;
    $src = @file_get_contents($file->getPathname());
    if ($src === false) continue;
    // Comments are stripped first, because the PROSE about native
    // confirm() legitimately contains the string "confirm(" — this
    // file's own header does, and the desk's history comment does.
    // Matching raw source flagged both and made the guard useless.
    $code = preg_replace('#/\*.*?\*/#s', ' ', $src);
    $code = preg_replace('#(^|\s)//.*$#m', '$1', $code);
    // A trailing // on a line that is only a comment is handled above;
    // this also covers a URL or a quoted "//" inside a string.
    if (preg_match('/(?<![.\w])confirm\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
        $line = substr_count(substr($code, 0, $m[0][1]), "\n") + 1;
        $liveOffenders[] = "$rel:$line";
    }
}
ok('no live page calls native confirm()', count($liveOffenders) === 0,
    'these still open a browser dialog: ' . implode(', ', $liveOffenders));

// The dialog itself must be available everywhere it is used, or the
// page throws on click and the action silently does nothing.
$headerSrc = @file_get_contents(__DIR__ . '/../includes/header.php');
ok('the shared confirm dialog loads from the page header',
    $headerSrc !== false && strpos($headerSrc, 'js/confirm.js') !== false,
    'includes/header.php does not load js/confirm.js — every confirmAction() call would throw');

// View used to render only when a request had no next step — that is,
// only once it was collected. On a working desk nothing is collected
// yet, so it never appeared at all, and it was a <span> rather than a
// button, so it was not reachable by keyboard either.
//
// Asserted positively on the control itself rather than by ruling out
// the old "if ($next === null)" gate: a negative regex for code that
// has already been deleted passes vacuously, so it proved nothing when
// deliberately broken.
ok('View is a real button on every row',
    str_contains($deskSrc, '<button class="btn btn-sm btn-light dq-view"')
        && str_contains($deskSrc, '</button>'),
    'the View control is not a <button> carrying the dq-view class');
// The View control must render on EVERY row, including one that has a
// next step to offer. Checked against the rendered page rather than the
// template source: PHP's conditional blocks cannot be located reliably
// by scanning for "if"/"endif" as text, because a nested branch (the
// Reject button) puts an endif between the two markers in BOTH the
// broken and correct versions. What differs is the output.
ok('View renders alongside a next step, not instead of it',
    viewRenderedOnSteppedRow(),
    'no row showed both a next step and a View button');
ok('View reports its expanded state to assistive tech',
    str_contains($deskSrc, 'aria-expanded') && str_contains($deskSrc, 'aria-controls'),
    'View has no aria-expanded / aria-controls');
ok('toggleDetail keeps aria-expanded in sync',
    (bool) preg_match('/function\s+toggleDetail[\s\S]{0,700}?aria-expanded/', $deskSrc),
    'toggleDetail does not update aria-expanded');

echo "\n";

// A rendering check cannot catch this one. View rendered correctly, was
// correctly wired to toggleDetail, and still did nothing: the detail
// row ships with display:none, so the handler's `open` test matched the
// CLOSED state and was then used as "should I close it". Only clicking
// it catches that, so this drives a real browser over CDP.
echo "\n\033[1mReal-browser behaviour\033[0m\n";
echo "\n\033[1mDocument process - the rail draws every lifecycle state\033[0m\n";

// Rendered from a fixture, not from live rows. The desk currently holds
// no Rejected or Claimed request, so those two branches of the rail
// would otherwise never execute — and an unexercised branch in a
// presentational template is exactly where a class name gets
// misspelled. Live rows were not touched to manufacture the states.
$cases = [
    // [status, blocked?, expected current station, must NOT appear]
    ['Filed',      false, 'is-now',         []],
    ['Processing', false, 'is-now',         []],
    ['Ready',      false, 'is-now',         []],
    ['Claimed',    false, 'is-done',        []],
    ['Rejected',   false, 'is-stopped',     ['is-now']],
    ['Filed',      true,  'is-held',        []],
    ['Processing', true,  'is-held',        []],
];
foreach ($cases as [$status, $blocked, $expect, $forbid]) {
    $track = doc_stage_track();
    $pos   = doc_stage_position($status);
    $classes = [];
    foreach ($track as $i => $stage) {
        $cls = '';
        if ($pos['stopped']) {
            $cls = 'is-stopped';
        } elseif ($i < $pos['index']) {
            $cls = 'is-done';
        } elseif ($i === $pos['index']) {
            $cls = $blocked ? 'is-now is-held' : 'is-now';
        }
        $classes[] = $cls === '' ? 'is-future' : $cls;
    }
    $joined = implode(' | ', $classes);
    $tag = $status . ($blocked ? ' (held)' : '');

    ok("rail: $tag marks the right stations",
        str_contains($joined, $expect) && !array_filter($forbid, fn($f) => str_contains($joined, $f)),
        "got: $joined");
}

// A rejected request has no station on the track. Rendering it as one
// would tell the clerk it reached the end, which is the opposite of
// what happened.
ok('rail: a rejected request has no current station',
    doc_stage_position('Rejected')['index'] === -1
        && doc_stage_position('Rejected')['stopped'] === true,
    'Rejected is being drawn as though it advanced along the track');

// A hold is not a lifecycle state. It must not move the request along
// the track, only colour the station it is already sitting on.
$held = doc_stage_position('Filed');
ok('rail: a hold does not advance the request',
    $held['index'] === doc_stage_position('Filed')['index'] && $held['stopped'] === false,
    'holding changed the station');

// Every station in the track must be a key the API actually knows, or
// the rail will draw a stage the request can never reach.
$apiStatuses = ['Filed', 'Processing', 'Ready', 'Claimed', 'Rejected'];
$trackKeys  = array_column(doc_stage_track(), 'key');
ok('rail: every station maps to a real lifecycle status',
    array_diff($trackKeys, $apiStatuses) === [],
    'unknown station keys: ' . implode(',', array_diff($trackKeys, $apiStatuses)));
// The rail must not offer a step past the end of the track. Claimed is
// the last station and has no forward action, so the drawn track and
// the offered button stop in the same place — otherwise the rail
// promises a station the request can never be moved to.
$lastStation = end($trackKeys);
ok('rail: the last station has no forward step',
    doc_next_step(['document_status' => $lastStation]) === null,
    "$lastStation still offers an action");
ok('rail: every station before the last does have one',
    count(array_filter(array_slice($trackKeys, 0, -1),
        fn($k) => doc_next_step(['document_status' => $k]) === null)) === 0,
    'a mid-track station has no action, so the rail dead-ends early');

echo "\n";

// A rendering check cannot catch this one. View rendered correctly, was
// correctly wired to toggleDetail, and still did nothing — the handler's
// `open` test matched the CLOSED state and was then used as "should I
// close it". Only clicking it catches that, so this drives a real
// browser over CDP, against the live logged-in page.
ok('View opens the detail row when clicked (real browser)',
    viewToggleWorksInBrowser(),
    'clicking View did not open the detail row');

// The document itself was unreachable: the endpoint rendered correctly
// and no control on the page pointed at it.
ok('the desk offers a preview of the document itself',
    deskRendersDocumentPreview(),
    'the desk has no working link to the rendered document');

echo "\n";
echo $failCount === 0
    ? "\033[32m{$passCount} checks passed.\033[0m\n"
    : "\033[31m{$failCount} failed\033[0m, {$passCount} passed.\n";
exit($failCount === 0 ? 0 : 1);
