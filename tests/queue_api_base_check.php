<?php
// The queue pages must work at ANY mount depth.
//
//   php tests/queue_api_base_check.php
//
// The production report was a wall of identical 404s:
//
//     api/queue.php?action=state       404
//     api/queue.php?action=call_next  404
//     ... and the monitor never painted, the kiosk tap did nothing
//
// and the cause was one line of JavaScript working out where the API is by
// counting slashes in window.location.pathname. That is only correct for one
// deployment shape:
//
//     localhost/registrar-ai-system/queue/monitor.php   3 slashes -> depth 2 -> '../api/'
//     registrar.bcpsms2.com/queue/monitor.php          2 slashes -> depth 1 -> 'api/'
//
// On the live host that second case resolves against /queue/, producing
// /queue/api/queue.php. The file is at /api/queue.php. Every request 404s.
//
// So the fix is that the server - which knows the mount point - passes the API
// base down in data-api-base, and the client stops guessing. This asserts both
// halves: the pages emit a correct base for each simulated deployment, and
// queue.js reads it rather than deriving one.

$root = dirname(__DIR__);
$read = function (string $rel) use ($root): string {
    $p = $root . '/' . $rel;
    if (!is_file($p)) { fwrite(STDERR, "  missing: $rel\n"); exit(1); }
    return (string) file_get_contents($p);
};

$fail = 0;
function check(string $label, bool $ok, string $detail = '') {
    global $fail;
    if (!$ok) $fail++;
    printf("  %-56s %s%s\n", $label, $ok ? 'OK' : 'FAIL', (!$ok && $detail !== '') ? "  ($detail)" : '');
}

echo "Queue API base\n";

// -- 1. The pages emit it -----------------------------------------------------
foreach (['queue/monitor.php', 'queue/kiosk.php'] as $rel) {
    $src = $read($rel);
    check("$rel emits data-api-base", strpos($src, 'data-api-base=') !== false);
    check("$rel computes it with app_url()", strpos($src, "app_url('/api')") !== false);
    // It must not pull in config.php just for this - that opens a mysqli
    // connection on a page whose whole job is to render when the DB is down.
    check(
        $rel . ' does not open a DB connection for it',
        !preg_match('/require_once[^;]*shared\/config\.php/', $src),
        'includes config.php; the display then depends on the database'
    );
    check($rel . ' includes app_path.php', strpos($src, 'shared/app_path.php') !== false);
}

// -- 2. The client reads it ----------------------------------------------------
$js = $read('js/queue.js');
check('queue.js reads data-api-base from <body>',
    strpos($js, "getAttribute('data-api-base')") !== false);
check('queue.js derives both endpoints from that base',
    strpos($js, "API_BASE + 'queue-public.php'") !== false
    && strpos($js, "API_BASE + 'queue.php'") !== false);
check('the URL-depth guess is a fallback, not the source',
    strpos($js, 'if (!API_BASE)') !== false);
check('the guess warns when it is used',
    preg_match('/if \(!API_BASE\)\s*\{[^}]*console\.warn/', $js) === 1,
    'a silent fallback reintroduces the same invisible bug');

// -- 3. Both endpoints still exist -------------------------------------------
foreach (['api/queue.php', 'api/queue-public.php'] as $rel) {
    check("$rel exists", is_file($root . '/' . $rel));
}
$api = $read('api/queue.php');
foreach (['state', 'call_next'] as $action) {
    check("api/queue.php serves action=$action",
        strpos($api, "'$action'") !== false);
}

// -- 4. The base resolves correctly at every depth --------------------------
// app_base_path() memoises in a static, so each mount point needs its own
// process. tests/_queue_base_probe.php does exactly that and prints the answer.
//
// Both arguments matter: SCRIPT_NAME is the URL the browser asked for, and the
// page's path on disk is where it actually sits. They are different strings and
// the fallback needs both - which is precisely why the old code got it wrong.
$probe = __DIR__ . '/_queue_base_probe.php';

$cases = [
    // [SCRIPT_NAME, page on disk, expected app_url('/api')]
    ['/registrar-ai-system/queue/monitor.php', 'queue/monitor.php',        '/registrar-ai-system/api'], // local dev, subdirectory
    ['/queue/monitor.php',                    'queue/monitor.php',        '/api'],                     // live host, app at root
    ['/registrar-ai-system/login.php',         'login.php',                '/registrar-ai-system/api'], // root page, subdirectory
    ['/login.php',                            'login.php',                '/api'],                     // root page, at root
    ['/registrar-ai-system/registrar/rfid-cards.php', 'registrar/rfid-cards.php', '/registrar-ai-system/api'],
    ['/registrar-ai-system/api/documents.php', 'api/documents.php',        '/registrar-ai-system/api'],
];
foreach ($cases as list($sname, $rel, $want)) {
    $got = trim((string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' '
        . escapeshellarg($sname) . ' ' . escapeshellarg($rel) . ' 2>&1'
    ));
    check(
        'app_url("/api") for ' . $rel . ' at ' . $sname,
        $got === $want,
        "got '$got', want '$want'"
    );
}

// -- 5. The rendered pages carry the right URL at each mount ----------------
// Renders each page under both deployments and reads the attribute out of the
// HTML, so this asserts what a browser would actually parse - not what the
// source appears to say.
$renderProbe = __DIR__ . '/_queue_render_probe.php';

function renderAs(string $renderProbe, string $rel, string $scriptName, string $onDisk): string
{
    return (string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($renderProbe) . ' '
        . escapeshellarg($scriptName) . ' ' . escapeshellarg($onDisk) . ' 2>&1'
    );
}

foreach (['queue/monitor.php', 'queue/kiosk.php'] as $rel) {
    $abs   = $root . '/' . $rel;
    $local = renderAs($renderProbe, $rel, '/registrar-ai-system/' . $rel, $abs);
    $prod  = renderAs($renderProbe, $rel, '/' . $rel, $abs);

    check(
        "$rel emits the subdirectory API base",
        strpos($local, 'data-api-base="/registrar-ai-system/api/"') !== false,
        'attribute missing or wrong in the rendered page'
    );
    check(
        "$rel emits the root API base",
        strpos($prod, 'data-api-base="/api/"') !== false,
        'attribute missing or wrong in the rendered page'
    );
    check("$rel renders without a fatal", stripos($local, 'Fatal error') === false);
}

// -- 5. Failures are reportable without server access ----------------------
// The person reporting this bug could not reach the server. Five handlers
// swallowed the error entirely and the rest said "Network error.", so a wrong
// URL - a fault entirely visible from the client - looked identical to a dead
// database, and neither could be told apart from the outside.
$js = $read('js/queue.js');

check('fetchJson reports the status and the URL',
    strpos($js, "'HTTP ' + r.status + ' from ' + url") !== false);
check('fetchJson distinguishes a network failure',
    strpos($js, "'Cannot reach ' + url") !== false);
check('fetchJson reports unparseable JSON rather than swallowing it',
    strpos($js, "'Bad JSON from ' + url") !== false);
check('there is a shared reporter', strpos($js, 'function reportQueueError(') !== false);
check('the reporter is deduplicated for polled requests',
    strpos($js, 'var lastReported') !== false && strpos($js, 'lastReported[key]') !== false);

// No handler may discard the error any more. Every .catch must take the error.
//
// Scanned line by line, skipping comment lines: this file documents the
// handlers it replaced and quotes them verbatim, so a whole-file regex
// "finds" `catch(function () {})` in the prose and fails against correct code.
// Naive comment-stripping would be worse - it would eat the // in https://.
$silent  = [];
$caught  = 0;
foreach (explode("\n", $js) as $line) {
    $trimmed = ltrim($line);
    if ($trimmed === '' || $trimmed[0] === '/' || $trimmed[0] === '*') {
        continue;
    }
    if (strpos($line, '.catch(function') === false) {
        continue;
    }
    $caught++;
    if (preg_match('/\.catch\(function\s*\(\s*([^)]*)\)/', $line, $a) && trim($a[1]) === '') {
        $silent[] = 'line ' . (count($silent) + 1);
    }
}
check('every .catch receives the error', count($silent) === 0, implode(', ', $silent));
check('the queue still has its handlers', $caught >= 8, "found $caught");

check('no handler shows the bare string "Network error."',
    strpos($js, "'Network error.'") === false && strpos($js, "'Network error. Please try again.'") === false);

// The user-facing line keeps the status code, because "error 404" is something
// a person standing at a kiosk can read out over the phone.
check('the user-facing reason names the status code',
    strpos($js, "(error \" + code[1]") !== false);
check('the user-facing reason is not a URL dump',
    (bool) preg_match('/function shortReason\(err\)\s*\{[\s\S]{0,600}?\n    \}/', $js));

echo "\n" . ($fail === 0 ? "OK - the queue finds its API at any mount depth.\n" : "FAILED - $fail check(s)\n");
exit($fail === 0 ? 0 : 1);
