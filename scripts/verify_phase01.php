<?php
// ============================================================
//  scripts/verify_phase01.php
//  Local acceptance check for the Phase 0 + Phase 1 hardening.
//
//  Run it AFTER applying migrations/security_hardening_phase1.sql.
//
//  Read-only. Phase 1 checks exercise the REAL grant + lockout helpers
//  against the live database inside a transaction that is always rolled
//  back, so nothing is left behind.
//
//  Usage:
//      php scripts/verify_phase01.php
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/auth_security.php';
require_once __DIR__ . '/../shared/functions.php';

$pass = 0;
$fail = 0;
$warn = 0;

function ok(string $label, string $detail = ''): void {
    global $pass;
    $pass++;
    echo "  [PASS] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}
function bad(string $label, string $detail = ''): void {
    global $fail;
    $fail++;
    echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}
function note(string $label, string $detail = ''): void {
    global $warn;
    $warn++;
    echo "  [WARN] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}
function head(string $title): void {
    echo "\n" . str_repeat('=', 66) . "\n{$title}\n" . str_repeat('=', 66) . "\n";
}

try {
    $db  = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Throwable $e) {
    echo "FATAL: cannot connect to the database.\n{$e->getMessage()}\n";
    exit(1);
}

// ── 1. Schema ──────────────────────────────────────────────────
head('1. SCHEMA (migrations/security_hardening_phase1.sql)');

function tableExists($pdo, string $table): bool {
    try {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
function columnExists($pdo, string $table, string $column): bool {
    try {
        return $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")->fetch() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

if (tableExists($pdo, 'password_reset_grants')) {
    ok('password_reset_grants table exists');
} else {
    bad('password_reset_grants table MISSING', 'the reset flow cannot work without it');
}
if (columnExists($pdo, 'users', 'password_changed_at')) {
    ok('users.password_changed_at exists', 'enables session invalidation');
} else {
    bad('users.password_changed_at MISSING');
}
if (columnExists($pdo, 'otp_codes', 'verify_attempts')) {
    ok('otp_codes.verify_attempts exists', 'enables the OTP brute-force cap');
} else {
    bad('otp_codes.verify_attempts MISSING');
}
if (columnExists($pdo, 'students', 'email_bounced_at') && columnExists($pdo, 'users', 'email_bounced_at')) {
    ok('email_bounced_at on students + users', 'bounce webhook can record failures');
} else {
    note('email_bounced_at missing', 'bounce feedback loop will not record anything');
}

// ── 2. Phase 0: fabricated mailboxes ───────────────────────────
head('2. PHASE 0 — fabricated mailboxes');

$fabricated = $db->fetchAll(
    "SELECT id, username, email FROM users
     WHERE email REGEXP '^student_[0-9]{2,}(_[0-9]{6}(_[0-9]+)?)?@'"
);
$sentinel = $db->fetchAll(
    "SELECT id, username, email FROM users WHERE email LIKE '%@invalid.example'"
);

if (!$fabricated) {
    ok('no fabricated student_<id>@ addresses remain');
} else {
    bad(count($fabricated) . ' fabricated address(es) still in users',
        'run: php scripts/purge_fake_emails.php --run');
    foreach (array_slice($fabricated, 0, 8) as $f) {
        echo "         #{$f['id']} {$f['username']}  {$f['email']}\n";
    }
}
if ($sentinel) {
    note(count($sentinel) . ' sentinel (@invalid.example) account(s)',
        'expected for students with no real email');
}

$functionsSrc = (string) file_get_contents(__DIR__ . '/../shared/functions.php');
$backfillSrc  = (string) file_get_contents(__DIR__ . '/../backfill_student_accounts.php');
if (strpos($functionsSrc, "'student_' .") === false) {
    ok('shared/functions.php no longer generates a mailbox name');
} else {
    bad('shared/functions.php still fabricates a mailbox name');
}
if (strpos($backfillSrc, "'student_' .") === false) {
    ok('backfill_student_accounts.php no longer generates a mailbox name');
} else {
    bad('backfill_student_accounts.php still fabricates a mailbox name');
}

// ── 3. Phase 1: reset grant ────────────────────────────────────
head('3. PHASE 1 — password reset grant');

$testUser = $db->fetchOne("SELECT id, email, is_active FROM users ORDER BY id ASC LIMIT 1");
if (!$testUser) {
    echo "  No users in the database — create an admin account first.\n";
} else {
    $uid = (int) $testUser['id'];
    $pdo->beginTransaction();
    try {
        $token = issueResetGrant($db, $uid);
        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            ok('issueResetGrant returns a 64-hex-char token', '256 bits of entropy');
        } else {
            bad('issueResetGrant returned an unexpected token format');
        }

        $first  = consumeResetGrant($db, $token);
        $second = consumeResetGrant($db, $token);

        if ($first === $uid) {
            ok('grant resolves to the correct user', "user_id={$first}");
        } else {
            bad('grant did not resolve to the issuing user', 'got ' . var_export($first, true));
        }
        if ($second === null) {
            ok('grant is single-use (replay rejected)');
        } else {
            bad('grant was accepted twice', 'a leaked token could be replayed');
        }
        if (consumeResetGrant($db, str_repeat('a', 64)) === null) {
            ok('an invalid token is rejected');
        } else {
            bad('an invalid token was accepted');
        }

        $stored = $pdo->prepare("SELECT token_hash FROM password_reset_grants WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stored->execute([$uid]);
        $row = $stored->fetch();
        if ($row && strpos((string) $row['token_hash'], '$2y$') === 0) {
            ok('grant is stored hashed (bcrypt), not in plaintext');
        } else {
            bad('grant is not stored as a bcrypt hash');
        }
    } catch (Throwable $e) {
        bad('reset grant checks threw', $e->getMessage());
    } finally {
        $pdo->rollBack();
    }
}

// ── 4. Phase 1: lockout + throttle wiring ──────────────────────
head('4. PHASE 1 — brute-force controls');

if ($testUser) {
    $uid = (int) $testUser['id'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$uid]);

        for ($i = 1; $i <= LOCKOUT_THRESHOLD; $i++) {
            handleFailedAttempt($db, $uid);
        }

        $remaining = lockoutRemainingSeconds($db, $uid);
        if ($remaining > 0) {
            ok('lockout engages after ' . LOCKOUT_THRESHOLD . ' failures', "{$remaining}s remaining");
        } else {
            bad('lockout did NOT engage after ' . LOCKOUT_THRESHOLD . ' failures',
                'brute force is still unlimited');
        }

        resetLoginLockout($db, $uid);
        if (lockoutRemainingSeconds($db, $uid) === 0) {
            ok('resetLoginLockout clears the lock');
        } else {
            bad('resetLoginLockout did not clear the lock');
        }
    } catch (Throwable $e) {
        bad('lockout checks threw', $e->getMessage());
    } finally {
        $pdo->rollBack();
    }
}

$authSrc = (string) file_get_contents(__DIR__ . '/../shared/auth_security.php');
if (strpos($authSrc, 'verify_attempts = verify_attempts + 1') !== false) {
    ok('OTP verify attempts are incremented (cap can fire)');
} else {
    bad('OTP attempts are never incremented', 'the 3-attempt cap cannot work');
}

foreach (['api/auth.php', 'shared/auth_actions.php'] as $endpoint) {
    $src = (string) file_get_contents(__DIR__ . '/../' . $endpoint);
    $missing = [];
    foreach (['handleFailedAttempt(', 'lockoutRemainingSeconds(', 'loginThrottleStatus(', 'loginThrottleRecord('] as $fn) {
        if (strpos($src, $fn) === false) {
            $missing[] = rtrim($fn, '(');
        }
    }
    if (!$missing) {
        ok("{$endpoint} calls lockout + throttle");
    } else {
        bad("{$endpoint} never calls: " . implode(', ', $missing));
    }
}

// ── 5. No OTP in responses ─────────────────────────────────────
head('5. PHASE 1 — secrets must not leak in responses');

foreach (['api/auth.php', 'shared/auth_actions.php'] as $endpoint) {
    $src = (string) file_get_contents(__DIR__ . '/../' . $endpoint);
    if (strpos($src, "\$otp['otp']") === false) {
        ok("{$endpoint} never returns the plaintext OTP");
    } else {
        bad("{$endpoint} still returns the plaintext OTP in its JSON");
    }
}
echo "  Actual APP_ENV right now: " . APP_ENV . "\n";
echo "  (production = fail-closed, which is what you want)\n";

// ── 6. Mail transport reality check ────────────────────────────
head('6. MAIL TRANSPORT (why your mail went out via smtp.gmail.com)');

echo "  Brevo configured : " . (defined('BREVO_CONFIGURED') && BREVO_CONFIGURED ? 'YES' : 'no') . "\n";
echo "  Gmail API        : " . (defined('GMAIL_API_CONFIGURED') && GMAIL_API_CONFIGURED ? 'YES' : 'no') . "\n";
echo "  SMTP fallback    : " . (defined('SMTP_HOST') && SMTP_HOST !== '' ? 'YES (' . SMTP_HOST . ')' : 'no') . "\n";
echo "  Mail configured  : " . (defined('EMAIL_CONFIGURED') && EMAIL_CONFIGURED ? 'YES' : 'no') . "\n\n";
if (defined('BREVO_CONFIGURED') && !BREVO_CONFIGURED) {
    note('Brevo is NOT configured', 'add BREVO_API_KEY= to shared/email_secret.local');
    echo "         Until then sendEmail() falls back to SMTP — which is why your\n";
    echo "         bounces came from 'gsmtp' (smtp.gmail.com).\n";
}

// ── Summary ────────────────────────────────────────────────────
head('SUMMARY');
echo "  PASS: {$pass}   FAIL: {$fail}   WARN: {$warn}\n\n";
if ($fail > 0) {
    echo "  {$fail} check(s) failed. Apply the migration, then re-run:\n";
    echo "      C:\\xampp\\mysql\\bin\\mysql.exe -u root registrar_ai < migrations\\security_hardening_phase1.sql\n";
    exit(1);
}
echo "  All blocking checks passed.\n";
echo "  Next: run the manual browser tests in SECURITY-ROADMAP.md.\n";