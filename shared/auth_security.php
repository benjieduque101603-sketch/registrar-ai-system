<?php
// ============================================================
//  SHARED/AUTH_SECURITY.PHP
//  Shared login hardening used by BOTH login endpoints
//  (shared/auth_actions.php and api/auth.php) so the rules stay
//  identical:
//    1. resolveLoginUser()   — username / student_id / email lookup
//    2. handleFailedAttempt()— increment + 10-min lockout every 5
//    3. issueOtp()           — hashed 6-digit one-time code (mail
//                              with on-screen fallback)
//    4. verifyOtpCode()      — check + consume an OTP
//    5. signInSession()      — establish the logged-in session
//  Idempotent includes (guarded) so it is safe to require() from
//  any entry point.
// ============================================================

if (defined('AUTH_SECURITY_LOADED')) {
    return;
}
define('AUTH_SECURITY_LOADED', true);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';   // logActivity()

// 5 failed attempts → lock out for 10 minutes.
if (!defined('LOCKOUT_THRESHOLD'))   define('LOCKOUT_THRESHOLD', 5);
if (!defined('LOCKOUT_DURATION_SEC')) define('LOCKOUT_DURATION_SEC', 600);
if (!defined('OTP_TTL_SEC'))         define('OTP_TTL_SEC', 300);      // 5 min
if (!defined('OTP_MAX_VERIFY_ATTEMPTS')) define('OTP_MAX_VERIFY_ATTEMPTS', 3);
// Show the OTP on screen as a dev/test fallback when mail() is
// unavailable (bare XAMPP). Turn OFF in production.
// Override with OTP_SHOW_ONSCREEN env var or APP_ENV setting
if (!defined('OTP_SHOW_ONSCREEN')) {
    $showOtpOnScreen = getenv('OTP_SHOW_ONSCREEN');
    if ($showOtpOnScreen === false) {
        // Default: disable in production, enable in development
        $showOtpOnScreen = (defined('APP_ENV') && APP_ENV === 'production') ? 'false' : 'true';
    }
    define('OTP_SHOW_ONSCREEN', $showOtpOnScreen === 'true' || $showOtpOnScreen === '1');
}

/**
 * Resolve a login account by an arbitrary credential string.
 * Accepts, in order:
 *   - users.username      (staff login ID)
 *   - students.student_number → the linked users.student_id
 *   - users.email         (backward compat / transitional)
 * Returns the full users row, or null when no match.
 */
function resolveLoginUser($db, string $credential): ?array {
    $credential = trim($credential);
    if ($credential === '') {
        return null;
    }
    return ($db->fetchOne(
        "SELECT u.* FROM users u
         WHERE u.username = ?
            OR u.email = ?
            OR u.id = (SELECT u2.id
                       FROM students s
                       JOIN users u2 ON u2.student_id = s.id
                       WHERE s.student_number = ? LIMIT 1)
         LIMIT 1",
        [$credential, $credential, $credential]
    ) ?: null);
}

/**
 * Register one failed login attempt. When the count reaches
 * LOCKOUT_THRESHOLD, set locked_until = NOW()+10 min and reset the
 * counter. Returns the user's current lockout state.
 */
function handleFailedAttempt($db, int $userId): void {
    $attempts = (int) $db->fetchColumn(
        "SELECT login_attempts FROM users WHERE id = ?", [$userId]
    );
    $attempts++;
    if ($attempts >= LOCKOUT_THRESHOLD) {
        // Store the deadline as a PHP-computed wall-clock string. Using
        // MySQL DATE_ADD(NOW(), ...) here would write in the MySQL session
        // timezone (UTC) while lockoutRemainingSeconds() interprets the
        // string in PHP's timezone (Asia/Manila) — an 8-hour skew that
        // would make the lockout look already expired.
        $db->query(
            "UPDATE users SET login_attempts = 0,
                 locked_until = ?
              WHERE id = ?",
            [date('Y-m-d H:i:s', time() + LOCKOUT_DURATION_SEC), $userId]
        );
    } else {
        $db->update('users', ['login_attempts' => $attempts], 'id = ?', [$userId]);
    }
}

/**
 * Seconds remaining in an active lockout, or 0 when not locked.
 */
function lockoutRemainingSeconds($db, int $userId): int {
    $locked = $db->fetchColumn(
        "SELECT locked_until FROM users WHERE id = ?", [$userId]
    );
    if (!$locked) {
        return 0;
    }
    $t = strtotime($locked);
    return $t > time() ? ($t - time()) : 0;
}

/** Reset the failed-attempt / lockout fields on a successful login. */
function resetLoginLockout($db, int $userId): void {
    $db->query(
        "UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?",
        [$userId]
    );
}

/** "somebody@bestlink.edu.ph" → "s******@bestlink.edu.ph". */
function maskEmail(?string $email): string {
    $email = trim((string) $email);
    if ($email === '' || strpos($email, '@') === false) {
        return '';
    }
    [$local, $domain] = explode('@', $email, 2);
    if (strlen($local) <= 1) {
        return '*@' . $domain;
    }
    return $local[0] . str_repeat('*', max(1, strlen($local) - 2)) . $local[strlen($local) - 1] . '@' . $domain;
}

/**
 * Generate + persist a one-time code and attempt delivery.
 * Returns:
 *   [
 *     'masked_email' => string,
 *     'delivered'    => bool (true when mail() succeeded),
 *     'otp'          => string|null plaintext, present ONLY on the
 *                       on-screen fallback path
 *   ]
 */
function issueOtp($db, int $userId, string $purpose = 'login', ?string $email = null): array {
    $user = $db->fetchOne(
        "SELECT id, email FROM users WHERE id = ?", [$userId]
    );
    $email = $email ?? ($user['email'] ?? '');
    $purpose = in_array($purpose, ['login', 'reset'], true) ? $purpose : 'login';

    // Clean up any expired OR already-used codes for this user+purpose
    // so an old code can never be replayed. expires_at is stored as a
    // PHP-computed wall-clock string (Asia/Manila), so compare against
    // PHP's wall clock, not MySQL NOW() (which runs in UTC here).
    $nowStr = date('Y-m-d H:i:s');
    $db->query(
        "DELETE FROM otp_codes
         WHERE user_id = ? AND purpose = ?
           AND (expires_at < ? OR used_at IS NOT NULL)",
        [$userId, $purpose, $nowStr]
    );

    $otp = (string) random_int(100000, 999999);
    $db->insert('otp_codes', [
        'user_id'    => $userId,
        'otp_hash'   => password_hash($otp, PASSWORD_DEFAULT),
        'purpose'    => $purpose,
        'expires_at' => date('Y-m-d H:i:s', time() + OTP_TTL_SEC),
    ]);

    // Prefer SMTP via PHPMailer when email is configured (reliable on
    // XAMPP where PHP mail() has no transport); fall back to mail().
    if (!function_exists('sendEmail')) {
        $mailClient = __DIR__ . '/mail_client.php';
        if (is_file($mailClient) && is_file(__DIR__ . '/../vendor/autoload.php')) {
            require_once $mailClient;
        }
    }

    $delivered = false;
    $to = trim($email);
    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $subject = 'Your ' . ($purpose === 'reset' ? 'Password Reset' : 'One-Time') . ' Code – BCP Registrar';
        $escOtp = function_exists('e_') ? e_($otp) : htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
        $htmlBody = '<p>Hello,</p>'
            . '<p>Your BCP Registrar ' . ($purpose === 'reset' ? 'password reset' : 'verification') . ' code is:</p>'
            . '<p style="font-size:26px;font-weight:700;letter-spacing:4px;color:#2563eb;">' . $escOtp . '</p>'
            . '<p>This code expires in ' . (int) (OTP_TTL_SEC / 60) . ' minutes.</p>'
            . '<p style="color:#64748b;font-size:12px;">If you did not request this, please ignore this message.</p>';

        if (function_exists('sendEmail') && emailConfigured()) {
            $delivered = sendEmail(['email' => $to], $subject, $htmlBody);
        } else {
            $plainBody = trim(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $htmlBody)));
            $headers = "From: no-reply@bestlink.edu.ph\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            $delivered = @mail($to, $subject, $plainBody, $headers);
        }
    }

    $plaintext = null;
    if (!$delivered && OTP_SHOW_ONSCREEN) {
        $plaintext = $otp;
    }

    logActivity($userId, 'otp_issued', json_encode(['purpose' => $purpose, 'delivered' => $delivered]));

    return [
        'masked_email' => maskEmail($email),
        'delivered'    => $delivered,
        'otp'          => $plaintext,
    ];
}

/**
 * Verify + consume a one-time code. True ONLY for the newest,
 * unused, unexpired code matching user+purpose. Marks it used.
 *
 * Brute force: OTP_MAX_VERIFY_ATTEMPTS was defined here but never
 * consulted, so a 6-digit code (900,000 combinations) could be guessed
 * without limit — especially easy because resend_otp would mint a fresh
 * code on demand. The attempt counter is now enforced and, once spent,
 * the code is consumed so the attacker must wait for a new one.
 */
function verifyOtpCode($db, int $userId, string $purpose, string $code): bool {
    $purpose = in_array($purpose, ['login', 'reset'], true) ? $purpose : 'login';
    $nowStr = date('Y-m-d H:i:s');
    $row = $db->fetchOne(
        "SELECT id, otp_hash, verify_attempts FROM otp_codes
         WHERE user_id = ? AND purpose = ?
           AND used_at IS NULL AND expires_at >= ?
         ORDER BY id DESC LIMIT 1",
        [$userId, $purpose, $nowStr]
    );
    if (!$row) {
        return false;
    }

    // Already exhausted: burn the code so it cannot be retried.
    if ((int) $row['verify_attempts'] >= OTP_MAX_VERIFY_ATTEMPTS) {
        $db->query(
            "UPDATE otp_codes SET used_at = NOW() WHERE id = ?", [(int) $row['id']]
        );
        error_log('[otp] verify attempt cap reached for user ' . $userId . ' (' . $purpose . ')');
        return false;
    }

    if (!password_verify(trim($code), $row['otp_hash'])) {
        $db->query(
            "UPDATE otp_codes SET verify_attempts = verify_attempts + 1 WHERE id = ?",
            [(int) $row['id']]
        );
        return false;
    }

    $db->query(
        "UPDATE otp_codes SET used_at = NOW() WHERE id = ?", [(int) $row['id']]
    );
    return true;
}

// ============================================================
//  PASSWORD RESET GRANTS
//  ============================================================
//
//  Why this exists
//  ---------------
//  reset_password used to accept a bare `user_id` + `new_password` with
//  no proof of anything:
//
//      POST action=reset_password&user_id=1&new_password=x
//
//  No OTP check, no token, no session. That was unauthenticated
//  takeover of any account, including admin (CWE-620, Weak Password
//  Recovery Mechanism).
//
//  Now a grant is minted ONLY after an OTP has been verified. The grant
//  is stored hashed, expires, and is single-use — matching the OWASP
//  Forgot Password guidance to create a limited session from the
//  verified code that permits only the reset.
//
//  Table: migrations/security_hardening_phase1.sql
// ---------------------------------------------------------------

if (!defined('RESET_GRANT_TTL_SEC')) define('RESET_GRANT_TTL_SEC', 900); // 15 min

/**
 * Mint a reset grant after a reset OTP was verified.
 * Returns the raw token ONCE — only its hash is stored.
 */
function issueResetGrant($db, int $userId): string {
    $token = bin2hex(random_bytes(32));           // 256 bits of entropy
    $nowStr = date('Y-m-d H:i:s');

    // Only one live grant per user: invalidate any previous one.
    $db->query(
        "UPDATE password_reset_grants SET used_at = ?
         WHERE user_id = ? AND used_at IS NULL",
        [$nowStr, $userId]
    );

    $db->insert('password_reset_grants', [
        'user_id'    => $userId,
        'token_hash' => password_hash($token, PASSWORD_DEFAULT),
        'expires_at' => date('Y-m-d H:i:s', time() + RESET_GRANT_TTL_SEC),
    ]);

    return $token;
}

/**
 * Consume a reset grant. Returns the user id on success, or null when
 * the token is unknown, already used, or expired.
 *
 * password_verify() is used rather than hash_equals() because the token
 * is stored as a bcrypt hash: there is nothing to compare byte-wise.
 */
function consumeResetGrant($db, string $token): ?int {
    $token = trim($token);
    if ($token === '' || strlen($token) !== 64) {
        return null;
    }
    $nowStr = date('Y-m-d H:i:s');

    $rows = $db->fetchAll(
        "SELECT id, user_id, token_hash FROM password_reset_grants
         WHERE used_at IS NULL AND expires_at >= ?
         ORDER BY id DESC LIMIT 10",
        [$nowStr]
    );

    foreach ($rows as $row) {
        if (password_verify($token, (string) $row['token_hash'])) {
            // Single-use: mark consumed before the password is written so
            // a concurrent replay cannot win a race.
            $db->update('password_reset_grants', ['used_at' => $nowStr], 'id = ?', [(int) $row['id']]);
            return (int) $row['user_id'];
        }
    }
    return null;
}

/**
 * Finalise a password change: stamp users.password_changed_at, drop any
 * other live reset grants, and clear lockout state.
 *
 * Session invalidation is NOT done here. PHP stores sessions in files
 * keyed by an opaque id, so there is no portable way to enumerate and
 * delete another device's session. The mechanism used instead is the
 * password_changed_at stamp: session_config.php compares it against
 * $_SESSION['login_time'] on every request and destroys any session that
 * predates the change. That achieves the same outcome — a stolen cookie
 * stops working the moment the victim resets — without pretending we can
 * reach into another browser's session store.
 *
 * Timestamp comparison is the only safe check, so it must be written in
 * PHP's wall clock (Asia/Manila), not MySQL NOW() (UTC on this host) —
 * see the same reasoning in handleFailedAttempt().
 */
function finalizePasswordChange($db, int $userId, string $newPasswordHash): void {
    $nowStr = date('Y-m-d H:i:s');

    // users.password_hash is NOT NULL, so the real hash is written here in
    // the same statement rather than nulled as a placeholder.
    $db->update('users', [
        'password_hash'       => $newPasswordHash,
        'password_changed_at' => $nowStr,
        'login_attempts'      => 0,
        'locked_until'        => null,
        'updated_at'          => $nowStr,
    ], 'id = ?', [$userId]);

    // Any other outstanding reset grant is now void.
    $db->query(
        "UPDATE password_reset_grants SET used_at = ? WHERE user_id = ? AND used_at IS NULL",
        [$nowStr, $userId]
    );
}

/**
 * Establish the authenticated session after OTP verification, and
 * return the role-based redirect target.
 */
function signInSession(array $user): string {
    session_regenerate_id(true);
    $_SESSION['user_id']     = (int) $user['id'];
    $_SESSION['email']       = $user['email'] ?? '';
    $_SESSION['full_name']   = $user['full_name'] ?? 'User';
    $_SESSION['role']        = $user['role'] ?? 'staff';
    $_SESSION['login_time']  = time();
    $_SESSION['last_activity'] = time();
    logActivity((int) $user['id'], 'login_success');
    $role = $user['role'] ?? 'staff';
    if ($role === 'student') {
        return 'student/dashboard.php';
    }
    if ($role === 'nurse') {
        return 'nurse/dashboard.php';
    }
    return 'dashboard.php';
}
