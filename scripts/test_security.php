<?php
// ============================================================
//  scripts/test_security.php
//  One command to verify the Phase 0 + Phase 1 security work.
//
//  Runs all three layers and prints a single verdict:
//
//    LAYER 1  schema + helpers   scripts/verify_phase01.php
//    LAYER 2  unit tests         tests/AuthHardeningTest.php
//    LAYER 3  live attack sim    scripts/security_attack_sim.php
//
//  Layer 3 is the one that matters most: it attacks the RUNNING server
//  over real HTTP, so it proves the deployed behaviour actually refuses
//  the attacks. It needs Apache and MySQL up.
//
//  Usage:
//      php scripts/test_security.php
//
//  Exits 0 when everything passes, 1 otherwise, so it can be wired
//  into CI or a pre-deploy check.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

$root = dirname(__DIR__);
$php  = PHP_BINARY;
$mysql = 'C:\\xampp\\mysql\\bin\\mysql.exe';
$db   = 'registrar_ai';

function run(string $cmd): array
{
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

function step(string $title): void
{
    echo "\n" . str_repeat('=', 68) . "\n{$title}\n" . str_repeat('=', 68) . "\n";
}

// ── Preflight ──────────────────────────────────────────────────
step('PREFLIGHT');

echo "Checking the local server is reachable...\n";
$ch = curl_init('http://localhost/registrar-ai-system/login.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
curl_exec($ch);
$http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http === 200) {
    echo "  login.php -> HTTP 200  OK\n";
} else {
    echo "  login.php -> HTTP {$http}  NOT REACHABLE\n";
    echo "\n  Start Apache and MySQL from the XAMPP Control Panel, then re-run.\n";
    echo "  (Layer 3 needs a live server; Layers 1-2 do not.)\n";
    exit(1);
}

// ── Reset the login throttle ───────────────────────────────────
// Layer 3 deliberately trips the lockout. Clearing it first means the
// brute-force check starts from a known state instead of reporting a
// stale "too many attempts" on the very first try.
if (is_file($mysql)) {
    run('"' . $mysql . '" -u root ' . $db . ' -e "UPDATE users SET login_attempts=0, locked_until=NULL; DELETE FROM login_attempts;"');
    echo "  Login counters reset so the brute-force test starts clean.\n";
}

$failed = [];

// ── Layer 1 ────────────────────────────────────────────────────
step('LAYER 1 of 3 — schema and helpers');
echo "\$ php scripts/verify_phase01.php\n\n";
[$c, $out] = run('"' . $php . '" "' . $root . '/scripts/verify_phase01.php"');
echo $out . "\n";
if (strpos($out, 'FAIL: 0') === false) {
    $failed[] = 'Layer 1: verify_phase01.php reported failures';
}

// ── Layer 2 ────────────────────────────────────────────────────
step('LAYER 2 of 3 — unit tests');
echo '$ php vendor/phpunit/phpunit/phpunit --no-coverage tests/AuthHardeningTest.php' . "\n\n";
[$c, $out] = run('"' . $php . '" "' . $root . '/vendor/phpunit/phpunit/phpunit" --no-coverage "' . $root . '/tests/AuthHardeningTest.php"');
// Only show the tail; the dots are noise.
$tail = array_slice(explode("\n", trim($out)), -4);
echo implode("\n", $tail) . "\n";
if (strpos($out, 'OK (') === false) {
    $failed[] = 'Layer 2: AuthHardeningTest did not report OK';
}

// ── Layer 3 ────────────────────────────────────────────────────
step('LAYER 3 of 3 — live attack simulation');
echo '$ php scripts/security_attack_sim.php' . "\n\n";
[$c, $out] = run('"' . $php . '" "' . $root . '/scripts/security_attack_sim.php"');
// Show only the verdict lines so the important part is not buried.
foreach (explode("\n", $out) as $line) {
    if (preg_match('/^\s+\[(BLOCKED|VULNERABLE)\]/', $line)
        || preg_match('/^\s+(BLOCKED|VULNERABLE):/', $line)
        || strpos($line, 'RESULT:') === 0
    ) {
        echo rtrim($line) . "\n";
    }
}
if (strpos($out, 'VULNERABLE: 0') === false) {
    $failed[] = 'Layer 3: an attack was not blocked';
}

// ── Verdict ────────────────────────────────────────────────────
step('VERDICT');
if ($failed) {
    echo "  FAILED\n\n";
    foreach ($failed as $f) {
        echo "   - {$f}\n";
    }
    echo "\n  See SECURITY-ROADMAP.md for the full verification steps.\n";
    exit(1);
}

echo "  All three layers passed.\n\n";
echo "  Phase 0 (mail), Phase 1 (authentication), Phase 2 (authorization)\n";
echo "  and Phase 3 (files and exports) all hold.\n\n";
echo "  Not covered here: any defect that needs TWO authenticated users of\n";
echo "  different roles at once. The deepest IDOR case — a logged-in student\n";
echo "  reading another student's record — is asserted at the source level in\n";
echo "  AuthHardeningTest, and its role allow-list is checked by the attack\n";
echo "  simulator, but an end-to-end two-student test would need real student\n";
echo "  passwords. Do that manually before deploying.\n";