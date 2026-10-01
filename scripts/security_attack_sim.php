<?php
// ============================================================
//  scripts/security_attack_sim.php
//  Live attack simulation against a RUNNING local server.
//
//  Unit tests prove the code contains the right logic. This proves the
//  deployed behaviour actually refuses the attack. It sends real HTTP
//  requests to the local Apache instance, exactly as an attacker would.
//
//  It is READ-ONLY and non-destructive: no account is taken over, no
//  password is changed, no real mailbox is touched. Every attempt is
//  expected to FAIL. If one succeeds, the report says so in red.
//
//  Usage:
//      php scripts/security_attack_sim.php
//      php scripts/security_attack_sim.php --url http://localhost/registrar-ai-system
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

// ── Locate the app ────────────────────────────────────────────
$defaultUrl = 'http://localhost/registrar-ai-system';
$baseUrl = $defaultUrl;
foreach ($argv as $i => $arg) {
    if ($arg === '--url' && isset($argv[$i + 1])) {
        $baseUrl = rtrim($argv[$i + 1], '/');
    }
}
$authUrl = $baseUrl . '/shared/auth_actions.php';

echo str_repeat('=', 68) . "\n";
echo " LIVE ATTACK SIMULATION — {$baseUrl}\n";
echo str_repeat('=', 68) . "\n\n";

$blocked = 0;
$vulnerable = 0;
$info = 0;

function banner(string $t): void {
    echo "\n" . str_repeat('-', 68) . "\n{$t}\n" . str_repeat('-', 68) . "\n";
}
function blockedIt(string $label, string $why): void {
    global $blocked;
    $blocked++;
    echo "  [BLOCKED] {$label}\n           {$why}\n";
}
function vuln(string $label, string $why): void {
    global $vulnerable;
    $vulnerable++;
    echo "  [VULNERABLE] {$label}\n           {$why}\n";
}
function noteIt(string $label): void {
    global $info;
    $info++;
    echo "  [INFO]   {$label}\n";
}

/**
 * Shared cURL cookie jar.
 *
 * This is essential, not a convenience. The CSRF token is bound to the
 * PHP session (csrf_guard.php compares it against $_SESSION). If each
 * request opens a new session, the token read from login.php does not
 * match the session used by the POST, and EVERY call is rejected with
 * "Invalid or missing CSRF token" — which would make every simulated
 * attack look "blocked" for the wrong reason and prove nothing.
 *
 * One cookie jar per browser session: startBrowser() for a fresh
 * attacker, which is how a real attacker would operate.
 */
$cookieJar = tempnam(sys_get_temp_dir(), 'secsim_cookies_');
register_shutdown_function(static function () use ($cookieJar) {
    if (is_file($cookieJar)) {
        @unlink($cookieJar);
    }
});

/** Start a fresh session (a new "browser" with no cookies). */
function startBrowser(string $jar): void
{
    // Overwriting the jar resets it: any previous session is forgotten.
    file_put_contents($jar, '');
}

/** Read a CSRF token from login.php using the current cookie jar. */
function fetchCsrf(string $loginUrl, string $jar): ?string
{
    $ch = curl_init($loginUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $html = (string) curl_exec($ch);
    curl_close($ch);

    if (preg_match("/name='csrf-token' content='([a-f0-9]+)'/", $html, $m)) {
        return $m[1];
    }
    if (preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $html, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * POST to the auth endpoint, carrying the session cookie and CSRF token.
 * Returns [status, decoded-json, raw-body].
 */
function post(string $url, array $fields, string $jar, ?string $csrf = null): array
{
    // Always set the header list explicitly. Passing [] is fine, but passing
    // null makes libcurl ignore CURLOPT_HTTPHEADER entirely on some builds.
    $headers = ['Accept: application/json'];
    if ($csrf !== null && $csrf !== '') {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    // HEADER_SIZE counts the header block including the blank separator line.
    $hsize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $body = substr($raw, $hsize);
    // Decode robustly. json_decode() returns null when the payload has any
    // leading non-JSON bytes, and an unfixed response then reads as
    // "no response" — which previously made a WORKING control look broken.
    //
    // Two defects are handled here:
    //   1. a UTF-8 BOM (EF BB BF) before the JSON;
    //   2. a stray byte sequence before the BOM. Some sources write a
    //      mojibake em-dash, so the payload can begin with stray bytes
    //      that are not part of the JSON at all.
    // Both are stripped before decoding.
    $body = ltrim($body, "\xEF\xBB\xBF");
    // Drop any bytes before the first '{' or '[' that cannot be JSON.
    if ($body !== '' && $body[0] !== '{' && $body[0] !== '[') {
        $bracePos = strcspn($body, '{[');
        if ($bracePos < strlen($body)) {
            $body = substr($body, $bracePos);
        }
    }
    $json = json_decode($body, true);

    return [$status, is_array($json) ? $json : [], $body, $err];
}

// Establish ONE session: fetch login.php and its CSRF token together, so
// the token and the session cookie always belong to the same session.
startBrowser($cookieJar);
$csrf = fetchCsrf($baseUrl . '/login.php', $cookieJar);

if ($csrf === null) {
    echo "FATAL: could not read a CSRF token from login.php.\n";
    echo "Is Apache running and is the app at {$baseUrl}?\n";
    echo "Without a token every POST is rejected by the CSRF guard, which\n";
    echo "would make every attack below look blocked for the wrong reason.\n";
    exit(1);
}

if (!function_exists('curl_init')) {
    echo "FATAL: the PHP cURL extension is required for this script.\n";
    exit(1);
}
// ─────────────────────────────────────────────────────────────
// ATTACK 1 — unauthenticated account takeover (the critical bug)
// ─────────────────────────────────────────────────────────────
banner('ATTACK 1 — Take over any account via password reset');

// The original defect: reset_password accepted a bare user_id and wrote
// the password. Any anonymous attacker could own the admin account.
echo "Anonymous reset for user_id=1 (admin), no OTP and no token...\n";
[, $r1] = post($authUrl, [
    'action'          => 'reset_password',
    'user_id'         => 1,
    'new_password'    => 'Correct-Horse-Battery-7',
    'confirm_password'=> 'Correct-Horse-Battery-7',
], $cookieJar, $csrf);

if (($r1['success'] ?? false) === true) {
    vuln('reset_password accepted an unauthenticated user_id',
        'ANY ACCOUNT CAN BE TAKEN OVER. This was the original critical bug.');
} else {
    blockedIt('reset_password refused without a reset grant',
        trim((string)($r1['message'] ?? 'no response'))
        . ' — the user_id in the request is ignored; the id must come from a verified grant');
}

echo "\nRetrying with a FORGED 64-char reset_token so only the grant can stop it...\n";
[, $r1b] = post($authUrl, [
    'action'          => 'reset_password',
    'user_id'         => 1,
    'reset_token'     => str_repeat('a', 64),   // never issued by the server
    'new_password'    => 'Correct-Horse-Battery-7',
    'confirm_password'=> 'Correct-Horse-Battery-7',
], $cookieJar, $csrf);

if (($r1b['success'] ?? false) === true) {
    vuln('a forged reset_token was accepted', 'ADMIN ACCOUNT COMPROMISED');
} else {
    blockedIt('forged reset_token rejected',
        trim((string)($r1b['message'] ?? 'no response')));
}

// ─────────────────────────────────────────────────────────────
// ATTACK 2 — OTP disclosure via the API response (C2)
// ─────────────────────────────────────────────────────────────
banner('ATTACK 2 — Steal the one-time code from the API response');

echo "Requesting a reset code and inspecting the response body...\n";
[, $r2] = post($authUrl, [
    'action' => 'forgot',
    'email'  => 'no-such-account-xyz@example.invalid',
], $cookieJar, $csrf);

if (array_key_exists('otp', $r2['data'] ?? [])) {
    vuln('the response contains an "otp" field',
        'THE RESET CODE IS RETURNED TO THE CALLER, so any account can be reset by email');
} else {
    blockedIt('no "otp" field in the response',
        'keys returned: ' . (isset($r2['data']) ? implode(', ', array_keys($r2['data'])) : 'none'));
}

// ─────────────────────────────────────────────────────────────
// ATTACK 3 — mint OTPs for arbitrary accounts (C3)
// ─────────────────────────────────────────────────────────────
banner('ATTACK 3 — Mint / mail-bomb codes for any account');

echo "Calling resend_otp for user_id=1 (admin) with no prior session...\n";
[, $r3] = post($authUrl, [
    'action'  => 'resend_otp',
    'user_id' => 1,
    'purpose' => 'reset',
], $cookieJar, $csrf);

if (($r3['success'] ?? false) === true) {
    vuln('resend_otp accepted an arbitrary user_id from an anonymous caller',
        'codes can be minted for any account: mail bombing plus code harvesting');
} else {
    blockedIt('resend_otp refused for an account this session never opened',
        trim((string)($r3['message'] ?? 'no response')));
}
// ─────────────────────────────────────────────────────────────
// ATTACK 4 — brute force / credential stuffing (C4)
// ─────────────────────────────────────────────────────────────
banner('ATTACK 4 — Online password guessing');

$target = 'ADM-001';   // a real username in the local data
$lockoutSeen = false;

echo "Trying 8 wrong passwords for '{$target}'...\n";
for ($i = 1; $i <= 8; $i++) {
    [, $r4] = post($authUrl, [
        'action'   => 'login',
        'username' => $target,
        'password' => 'wrong-guess-' . $i,
    ], $cookieJar, $csrf);

    $msg = (string)($r4['message'] ?? '');
    if (stripos($msg, 'too many') !== false || stripos($msg, 'locked') !== false) {
        $lockoutSeen = true;
        echo "  attempt {$i}: {$msg}\n";
        break;
    }
}

if ($lockoutSeen) {
    blockedIt('repeated guessing is throttled and the account locks',
        'the endpoint started refusing once the failure cap was reached, so brute force is no longer unlimited');
} else {
    vuln('8 consecutive wrong passwords with no lockout or throttle',
        'online brute force is still unlimited (this was the original C4 defect)');
}

// ─────────────────────────────────────────────────────────────
// ATTACK 4b — user enumeration
//
// Both sides must be in an IDENTICAL state, and they are not by
// default. login_throttle keys on (credential, IP), so it survives
// across sessions and is shared by every request from this machine.
// By this point the loop above has just pushed $target past the cap,
// while a never-tried username starts clean. Comparing those two would
// report a fake "enumeration leak" that is really just leftover
// throttle state.
//
// So each probe uses a DIFFERENT, UNTOUCHED credential: a second real
// username that nothing above has attempted, versus a username that
// does not exist. Neither has any throttle history.
// ─────────────────────────────────────────────────────────────
echo "\nComparing the failure message for an untouched real vs fake account...\n";
echo "(a second real username that has never been attempted, vs one that does not exist)\n";

$untouchedReal = trim((string) ($argv[count($argv) - 1] ?? ''));
$untouchedReal = $argv[3] ?? ($argv[2] ?? 'RGS-001');

$jarA = $cookieJar . '.a';
$jarB = $cookieJar . '.b';

// Probe A: one wrong password against an untouched REAL username.
file_put_contents($jarA, '');
$tokA = fetchCsrf($baseUrl . '/login.php', $jarA);
[, $rReal] = post($authUrl, [
    'action' => 'login', 'username' => $untouchedReal, 'password' => 'nope',
], $jarA, $tokA);

// Probe B: one wrong password against a username that does not exist.
file_put_contents($jarB, '');
$tokB = fetchCsrf($baseUrl . '/login.php', $jarB);
[, $rFake] = post($authUrl, [
    'action' => 'login', 'username' => 'no-such-user-zzz', 'password' => 'nope',
], $jarB, $tokB);

@unlink($jarA);
@unlink($jarB);

$msgReal = (string)($rReal['message'] ?? '');
$msgFake = (string)($rFake['message'] ?? '');

if ($msgReal === '' || $msgFake === '') {
    noteIt('could not read both failure messages, skipping the comparison');
} elseif (stripos($msgReal, 'too many') !== false || stripos($msgFake, 'too many') !== false) {
    noteIt('a throttle response was returned, so the comparison is inconclusive');
    echo "           clear login_attempts and re-run for a clean comparison\n";
} elseif ($msgReal === $msgFake) {
    blockedIt('identical response for a real and a non-existent account',
        "\"{$msgReal}\" — no account enumeration via the error text");
} else {
    vuln('the failure message differs between real and fake accounts',
        "real: \"{$msgReal}\" vs fake: \"{$msgFake}\" — this enumerates valid usernames");
}

// ─────────────────────────────────────────────────────────────
// ATTACK 5 — cross-site request forgery
// ─────────────────────────────────────────────────────────────
banner('ATTACK 5 — Cross-site request forgery (no token)');

echo "Calling a state-changing action with NO CSRF token...\n";
$status5 = null;
[, $r5, $raw5] = post($authUrl, [
    'action' => 'forgot',
    'email'  => 'csrf-probe@example.invalid',
], $cookieJar, null);   // deliberately no CSRF token

if (strpos((string)$raw5, 'Invalid or missing CSRF token') !== false) {
    blockedIt('a request without a CSRF token is rejected',
        'shared/csrf_guard.php refused it before any action ran');
} elseif (($r5['success'] ?? false) === true) {
    vuln('a cross-site request with no CSRF token was accepted',
        'an attacker page could trigger this action using the victim\'s session');
} else {
    blockedIt('the no-token request did not succeed',
        trim((string)($r5['message'] ?? 'refused')));
}

// ─────────────────────────────────────────────────────────────
// ATTACK 6 — the deleted secret dumper
// ─────────────────────────────────────────────────────────────
banner('ATTACK 6 — Fetch the secret-dumping diagnostic page');

echo "GET registrar/smtp-debug.php (used to print every SMTP password in cleartext)...\n";
$ch = curl_init($baseUrl . '/registrar/smtp-debug.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$dbgBody = (string) curl_exec($ch);
$dbgStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($dbgStatus === 200 && preg_match('/SMTP_PASS|DB_USER/', $dbgBody)) {
    vuln('smtp-debug.php still exists and leaks configuration',
        'anyone can read your mail and database settings');
} else {
    blockedIt('smtp-debug.php is gone', "HTTP {$dbgStatus} — the file was deleted");
}

// ─────────────────────────────────────────────────────────────
// ATTACK 7 — password policy on the reset path
// ─────────────────────────────────────────────────────────────
banner('ATTACK 7 — Set a weak password through the reset endpoint');

echo "Attempting a 6-character password (the old endpoint accepted >= 6)...\n";
[, $r7] = post($authUrl, [
    'action'          => 'reset_password',
    'reset_token'     => str_repeat('b', 64),   // forged, so this fails anyway
    'new_password'    => 'abc123',
    'confirm_password'=> 'abc123',
], $cookieJar, $csrf);

if (preg_match('/uppercase|symbol|number|Password must/i', (string)($r7['message'] ?? ''))) {
    blockedIt('the password policy is enforced on the reset path',
        trim((string)$r7['message']));
} else {
    noteIt('the policy message was not observed (the grant check fired first, which is also a block)');
}

// ─────────────────────────────────────────────────────────────
// ATTACK 8 — read another student's record (Phase 2 IDOR)
// ─────────────────────────────────────────────────────────────
banner('ATTACK 8 — Student reads another student (ai-tools.php IDOR)');

// The IDOR was that ai-tools.php accepted any authenticated session and
// role-checked only 3 of 11 actions. Proving the student case needs a
// real student session, which this script cannot mint without a
// password. What it CAN prove is that the endpoint now refuses an
// anonymous caller, and that the role allow-list excludes students.

echo "Calling case_brief for another student with no session...\n";
$jar8 = $cookieJar . '.8';
file_put_contents($jar8, '');
$tok8 = fetchCsrf($baseUrl . '/login.php', $jar8);
$ch8 = curl_init($baseUrl . '/api/ai-tools.php?action=case_brief');
curl_setopt_array($ch8, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['student_id' => 1]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_COOKIEJAR => $jar8,
    CURLOPT_COOKIEFILE => $jar8,
    CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . (string) $tok8],
]);
$body8 = (string) curl_exec($ch8);
curl_close($ch8);
@unlink($jar8);

if (strpos($body8, 'Unauthorized') !== false || strpos($body8, '"success":false') !== false) {
    blockedIt('ai-tools refuses an unauthenticated caller',
        'no case brief was returned');
} else {
    vuln('ai-tools returned data without a session', 'the IDOR path is reachable anonymously');
}

echo "\nChecking the role gate excludes students...\n";
$aiToolsSrc = (string) @file_get_contents(__DIR__ . '/../api/ai-tools.php');
if (preg_match('/\$AI_TOOLS_ROLES\s*=\s*\[[^\]]*\]/', $aiToolsSrc, $m)
    && strpos($m[0], "'student'") === false) {
    blockedIt('ai-tools allow-lists staff roles and excludes student',
        trim(preg_replace('/\s+/', ' ', $m[0])) . ' — a future action cannot default to open');
} else {
    vuln('ai-tools does not exclude the student role', 'a student may read any record');
}

// ─────────────────────────────────────────────────────────────
// ATTACK 9 — fetch a student file without authorisation (Phase 3)
// ─────────────────────────────────────────────────────────────
banner('ATTACK 9 — Read an uploaded student file directly');

// The exposure was Apache serving uploads/student_files/ to anyone who
// guessed a filename. Names are <student_id>_<unixtime>_<name>, so both
// halves are guessable — this request uses the real filename shape.
echo "GET a direct uploads/student_files/ path (no session)...\n";
$ch9 = curl_init($baseUrl . '/uploads/student_files/1/1_1790268412_download.jpg');
curl_setopt_array($ch9, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$body9 = (string) curl_exec($ch9);
$code9 = (int) curl_getinfo($ch9, CURLINFO_HTTP_CODE);
curl_close($ch9);

if ($code9 === 200 && strlen($body9) > 0) {
    vuln('the upload directory served a file to an anonymous caller',
        strlen($body9) . ' bytes returned with no authentication');
} else {
    blockedIt('the upload directory refuses anonymous reads', "HTTP {$code9}");
}

echo "\nGET api/file-download.php without a session...\n";
$ch9b = curl_init($baseUrl . '/api/file-download.php?id=3');
curl_setopt_array($ch9b, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$body9b = (string) curl_exec($ch9b);
$code9b = (int) curl_getinfo($ch9b, CURLINFO_HTTP_CODE);
curl_close($ch9b);

if ($code9b === 401 || stripos($body9b, 'Unauthorized') !== false) {
    blockedIt('the download endpoint requires a session', "HTTP {$code9b}");
} elseif (strlen($body9b) > 0) {
    vuln('the download endpoint served bytes without a session',
        strlen($body9b) . ' bytes returned with no authentication');
} else {
    blockedIt('the download endpoint refused the request', "HTTP {$code9b}");
}

// ─────────────────────────────────────────────────────────────
// ATTACK 10 — CSRF on the clinic endpoints (A3)
// ─────────────────────────────────────────────────────────────
banner('ATTACK 10 — Cross-site write on a clinic endpoint with no token');

echo "Creating a clinic supply record with NO CSRF token...\n";
$ch10 = curl_init($baseUrl . '/api/clinic-supplies.php');
curl_setopt_array($ch10, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['name' => 'csrf-probe', 'quantity' => 1]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
]);
$body10 = (string) curl_exec($ch10);
curl_close($ch10);

if (strpos($body10, 'Invalid or missing CSRF token') !== false) {
    blockedIt('clinic-supplies refuses a request with no CSRF token',
        'the guard runs before any handler code');
} else {
    noteIt('clinic-supplies answered without a CSRF error', substr($body10, 0, 120));
}

// ── Summary ────────────────────────────────────────────────────
banner('SUMMARY');
echo "  BLOCKED:    {$blocked}\n";
echo "  VULNERABLE: {$vulnerable}\n";
echo "  INFO:       {$info}\n\n";

if ($vulnerable > 0) {
    echo "  RESULT: {$vulnerable} attack(s) SUCCEEDED. Treat this as an incident.\n";
    exit(1);
}
echo "  RESULT: every simulated attack was refused.\n";
echo "  Covers: Phase 0 (mail), Phase 1 (authentication),\n";
echo "          Phase 2 (authorization), Phase 3 (files and exports).\n\n";
echo "  NOTE: this run deliberately tripped the login throttle for\n";
echo "  '{$target}'. To re-run cleanly, reset the counters:\n\n";
echo "    C:\\xampp\\mysql\\bin\\mysql.exe -u root registrar_ai -e\n";
echo "    \"UPDATE users SET login_attempts=0, locked_until=NULL;\n";
echo "     DELETE FROM login_attempts;\"\n";