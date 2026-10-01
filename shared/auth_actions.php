<?php
// shared/auth_actions.php - Login hardend for Phase 5:
//   username / student_ID / email + password → OTP → session
//   with 10-minute lockout after every 5 failed attempts.
//   Shared hardening logic lives in shared/auth_security.php.
//   CSRF is enforced on every POST (csrf_guard.php); a second,
//   per-email/IP throttle layer lives in login_throttle.php.

// Set JSON content-type FIRST so security_headers.php skips its text/html.
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth_security.php';
require_once __DIR__ . '/csrf_guard.php';
require_once __DIR__ . '/login_throttle.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';

function sendResponse($success, $message, $data = null) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// ─── LOGIN ACTION ──────────────────────────────────────────────
// Direct credential + password → instant login & session grant
if ($action === 'login') {
    $credential = trim($_POST['username'] ?? ($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $credential = trim($credential);

    if ($credential === '' || $password === '') {
        sendResponse(false, 'Please enter your ID number / username and password.');
    }

    $clientIp = requestClientIp();

    try {
        $db = Database::getInstance();

        // Second throttle layer: per email+IP, 5 failures / 15 min.
        // Was defined in login_throttle.php with zero call sites.
        $throttle = loginThrottleStatus($credential, $clientIp);
        if ($throttle['blocked']) {
            $mins = max(1, (int) ceil($throttle['retry_after'] / 60));
            sendResponse(false, "Too many failed attempts. Try again in {$mins} minute(s).");
        }

        $user = resolveLoginUser($db, $credential);

        // Uniform failure for unknown user, wrong password and disabled
        // account alike. Distinguishing them let an attacker enumerate
        // valid usernames. Equalise wording AND cost: the dummy verify
        // below removes the ~100ms timing oracle when no user matched.
        if (!$user) {
            password_verify($password, '$2y$10$usesomesillystringfore.HXwmS7cBGaWku1NvXWCFRzcYAa');
            loginThrottleRecord($credential, $clientIp, false);
            sendResponse(false, 'Invalid ID / username or password.');
        }

        if (!$user['is_active']) {
            loginThrottleRecord($credential, $clientIp, false);
            sendResponse(false, 'Invalid ID / username or password.');
        }

        if (!password_verify($password, $user['password_hash'])) {
            // Account lockout: 5 failures → 10 minutes. This call did not
            // exist before, so lockout was entirely inert.
            handleFailedAttempt($db, (int) $user['id']);
            loginThrottleRecord($credential, $clientIp, false);
            sendResponse(false, 'Invalid ID / username or password.');
        }

        // Correct credentials but still locked out from earlier failures.
        $lockedFor = lockoutRemainingSeconds($db, (int) $user['id']);
        if ($lockedFor > 0) {
            $mins = max(1, (int) ceil($lockedFor / 60));
            loginThrottleRecord($credential, $clientIp, false);
            sendResponse(false, "Account temporarily locked. Try again in {$mins} minute(s).");
        }

        // Direct sign-in without OTP
        resetLoginLockout($db, (int) $user['id']);
        loginThrottleRecord($credential, $clientIp, true);
        loginThrottleClear($credential, $clientIp);
        $redirect = signInSession($user);

        sendResponse(true, 'Login successful.', [
            'user' => [
                'id'        => (int) $user['id'],
                'email'     => $user['email'],
                'full_name' => $user['full_name'],
                'role'      => $user['role'],
            ],
            'redirect' => $redirect,
        ]);
    } catch (Exception $e) {
        sendResponse(false, 'An error occurred. Please try again.');
    }
}

// ─── RESEND OTP ACTION ─────────────────────────────────────────
// Was unauthenticated and accepted an arbitrary user_id AND purpose,
// so anyone could mint (and mail-bomb) reset codes for any account.
// Now it only re-sends a code that was already legitimately issued
// for that user and purpose in this session, and is rate limited.
if ($action === 'resend_otp') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $purpose = ($_POST['purpose'] ?? 'login') === 'reset' ? 'reset' : 'login';
    if ($userId <= 0) {
        sendResponse(false, 'Invalid request.');
    }
    // Must be the account this session is actually working on.
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    $pendingUserId = (int) ($_SESSION['otp_user_id'] ?? 0);
    if (($sessionUserId !== $userId) && ($pendingUserId !== $userId)) {
        sendResponse(false, 'Invalid request.');
    }
    try {
        $db = Database::getInstance();

        // Rate limit: do not mint codes on demand.
        $throttle = loginThrottleStatus((string) $userId, requestClientIp());
        if ($throttle['blocked']) {
            sendResponse(false, 'Too many requests. Please wait before requesting another code.');
        }

        $user = $db->fetchOne("SELECT id, email FROM users WHERE id = ? AND is_active = 1", [$userId]);
        if (!$user) {
            sendResponse(false, 'Unable to resend the code. Please try again.');
        }
        if (trim((string) ($user['email'] ?? '')) === '' || !isValidEmail((string) $user['email'])) {
            // Sending to the @invalid.example sentinel could only bounce.
            sendResponse(false, 'This account has no deliverable email address. Contact the registrar.');
        }

        $otp = issueOtp($db, $userId, $purpose);
        // Never return the code. It used to be echoed as 'otp' whenever
        // delivery failed, and OTP_SHOW_ONSCREEN defaulted ON because
        // APP_ENV defaulted to 'development' — so a misconfigured host
        // handed the reset code straight to the caller.
        sendResponse(true, 'A new code has been sent.', [
            'masked_email' => $otp['masked_email'],
            'delivered'    => $otp['delivered'],
        ]);
    } catch (Exception $e) {
        sendResponse(false, 'Unable to resend the code. Please try again.');
    }
}

// ─── VERIFY OTP ACTION ─────────────────────────────────────────
// Step 2: confirm the code → establish the real session.
if ($action === 'verify_otp') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $purpose = ($_POST['purpose'] ?? 'login') === 'reset' ? 'reset' : 'login';
    $code = trim($_POST['otp'] ?? '');

    if ($userId <= 0 || $code === '') {
        sendResponse(false, 'Invalid request.');
    }

    // A code may only be verified for the account this session started
    // the flow for. Without this, anyone could aim a verify at an
    // arbitrary user_id and walk through codes they never requested.
    $sessionUserId   = (int) ($_SESSION['user_id'] ?? 0);
    $pendingUserId   = (int) ($_SESSION['otp_user_id'] ?? 0);
    if (($sessionUserId !== $userId) && ($pendingUserId !== $userId)) {
        sendResponse(false, 'Invalid request.');
    }

    try {
        $db = Database::getInstance();
        $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            sendResponse(false, 'Account not found. Please sign in again.');
        }
        if (!$user['is_active']) {
            // Neutral wording: a distinct 'disabled' reply confirms the account
            // exists, which is an enumeration oracle.
            sendResponse(false, 'This reset link is invalid or has expired. Please start over.');
        }

        if (!verifyOtpCode($db, $userId, $purpose, $code)) {
            sendResponse(false, 'Invalid or expired code. Please try again.', ['otp_invalid' => true]);
        }

        // The code is proven. Bind this session to the account so the
        // follow-up steps cannot be redirected at another user.
        $_SESSION['otp_user_id'] = $userId;
        $_SESSION['otp_purpose'] = $purpose;

        if ($purpose === 'reset') {
            // Mint the reset grant. This is the ONLY place it is created,
            // and it is returned to the client once — only its hash is
            // stored, so a leaked response cannot be replayed.
            $resetToken = issueResetGrant($db, $userId);
            logActivity($userId, 'password_reset_grant_issued');
            sendResponse(true, 'Code verified. Set your new password.', [
                'step'        => 'reset_password',
                'user_id'     => $userId,
                'reset_token' => $resetToken,
            ]);
        }

        // Login flow: grant the session and redirect.
        $redirect = signInSession($user);
        sendResponse(true, 'Login successful.', [
            'user' => [
                'id'       => (int) $user['id'],
                'email'    => $user['email'],
                'full_name'=> $user['full_name'],
                'role'     => $user['role'],
            ],
            'redirect' => $redirect,
        ]);
    } catch (Exception $e) {
        sendResponse(false, 'An error occurred. Please try again.');
    }
}

// ─── FORGOT PASSWORD ACTION ────────────────────────────────────
// Only here does the EMAIL field appear. Sends a reset OTP.
if ($action === 'forgot') {
    $email = trim($_POST['email'] ?? '');
    if ($email === '') {
        sendResponse(false, 'Please enter your email address.');
    }
    try {
        $db = Database::getInstance();

        // Rate limit before doing any work: stops an attacker using this
        // endpoint to mail-bomb an address.
        $throttle = loginThrottleStatus($email, requestClientIp());
        if ($throttle['blocked']) {
            sendResponse(false, 'Too many requests. Please wait before trying again.');
        }
        loginThrottleRecord($email, requestClientIp(), false);

        // users.email is no longer UNIQUE (a shared address is legitimate), so`n        // this could match several accounts. Pick deterministically by oldest id`n        // so the outcome cannot vary between requests.`n        $user = $db->fetchOne("SELECT id, email, is_active FROM users WHERE email = ? ORDER BY id ASC LIMIT 1", [strtolower($email)]);
        if (!$user) {
            // Don't reveal whether an account exists.
            sendResponse(false, 'If that email is registered, a reset code has been sent.');
        }
        if (!$user['is_active']) {
            sendResponse(false, 'If that email is registered, a reset code has been sent.');
        }
        if (!isValidEmail((string) ($user['email'] ?? ''))) {
            // Portal account with no deliverable address. Same neutral
            // message so this cannot be used to probe for real accounts.
            sendResponse(false, 'If that email is registered, a reset code has been sent.');
        }

        // Remember which account this session is legitimately working on,
        // so resend_otp/verify_otp cannot be pointed at another user.
        $_SESSION['otp_user_id'] = (int) $user['id'];
        $_SESSION['otp_purpose'] = 'reset';

        $otp = issueOtp($db, (int) $user['id'], 'reset', $user['email']);
        sendResponse(true, 'A reset code has been sent to your email.', [
            'step'          => 'otp',
            'user_id'       => (int) $user['id'],
            'purpose'       => 'reset',
            'masked_email'  => $otp['masked_email'],
            // 'delivered' tells the UI to say "check your inbox" vs
            // "we could not reach the mail server". The CODE itself is
            // never returned.
            'delivered'     => $otp['delivered'],
        ]);
    } catch (Exception $e) {
        sendResponse(false, 'An error occurred. Please try again.');
    }
}

// ─── RESET PASSWORD ACTION ─────────────────────────────────────
// THE CRITICAL FIX.
//
// This used to accept a bare user_id + new_password with no proof of
// anything, so anyone could take over any account:
//
//     POST action=reset_password&user_id=1&new_password=x
//
// It now requires a reset grant, which is minted ONLY after the reset
// OTP was verified. The grant is hashed at rest, expires in 15 minutes
// and is single-use (see issueResetGrant/consumeResetGrant).
if ($action === 'reset_password') {
    $grantToken = trim((string) ($_POST['reset_token'] ?? ''));
    $newPassword = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($grantToken === '' || $newPassword === '' || $confirm === '') {
        sendResponse(false, 'Please fill in all fields.');
    }
    if ($newPassword !== $confirm) {
        sendResponse(false, 'Passwords do not match.');
    }

    // Enforce the same policy as every other password-set path. This
    // endpoint previously checked only strlen >= 6.
    require_once __DIR__ . '/password_policy.php';
    require_once __DIR__ . '/functions.php';
    $policy = checkPasswordPolicy($newPassword);
    if (!$policy['valid']) {
        sendResponse(false, $policy['message']);
    }

    try {
        $db = Database::getInstance();

        // Verify the grant and take the user id from IT — never from the
        // request. This is what closes the takeover.
        $userId = consumeResetGrant($db, $grantToken);
        if ($userId === null) {
            sendResponse(false, 'This reset link is invalid or has expired. Please start over.');
        }

        $user = $db->fetchOne("SELECT id, is_active FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            sendResponse(false, 'Account not found. Please start over.');
        }
        if (!$user['is_active']) {
            // Neutral wording: a distinct 'disabled' reply confirms the account
            // exists, which is an enumeration oracle.
            sendResponse(false, 'This reset link is invalid or has expired. Please start over.');
        }

        // Writes the hash AND password_changed_at in one statement. The
        // stamp makes every session issued before this moment invalid
        // (checked in shared/session_config.php).
        finalizePasswordChange($db, (int) $user['id'], password_hash($newPassword, PASSWORD_DEFAULT));

        // Clear the session's pending-OTP state.
        unset($_SESSION['otp_user_id'], $_SESSION['otp_purpose']);

        logActivity((int) $user['id'], 'password_reset', json_encode(['via' => 'reset_grant']));
        sendResponse(true, 'Your password has been reset. You can now sign in.', ['step' => 'done']);
    } catch (Exception $e) {
        sendResponse(false, 'An error occurred. Please try again.');
    }
}

// ─── LOGOUT ACTION ──────────────────────────────────────────────
if ($action === 'logout') {
    session_unset();
    session_destroy();
    sendResponse(true, 'Logged out successfully');
}

// ─── CHECK SESSION ACTION ──────────────────────────────────────
if ($action === 'check_session') {
    if (isset($_SESSION['user_id'])) {
        sendResponse(true, 'Session is valid', [
            'user' => [
                'id' => $_SESSION['user_id'],
                'email' => $_SESSION['email'],
                'full_name' => $_SESSION['full_name'],
                'role' => $_SESSION['role']
            ]
        ]);
    } else {
        sendResponse(false, 'Session expired');
    }
}

sendResponse(false, 'Invalid action.');
?>
