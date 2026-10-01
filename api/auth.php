<?php
// ============================================================
//  API/AUTH.PHP
//  Authentication API endpoints — mirror of shared/auth_actions.php
//  for JSON clients. Same Phase 5 hardening (OTP + lockout),
//  plus the session-level CSRF + per-email/IP throttle layers.
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/auth_security.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/login_throttle.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function failJson(string $msg, bool $locked = false, ?array $extra = null): void {
    $payload = ['success' => false, 'message' => $msg];
    if ($locked) $payload['locked'] = true;
    if ($extra)  $payload = array_merge($payload, $extra);
    echo json_encode($payload);
    exit;
}

// ─── LOGIN ──────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'login') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $credential = trim($input['username'] ?? ($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    if ($credential === '' || $password === '') {
        failJson('ID number / username and password are required.');
    }

    $clientIp = requestClientIp();

    try {
        $db = Database::getInstance();

        // Second throttle layer: per email+IP. These functions existed in
        // shared/login_throttle.php but had zero call sites, so brute force
        // was previously unlimited.
        $throttle = loginThrottleStatus($credential, $clientIp);
        if ($throttle['blocked']) {
            $mins = max(1, (int) ceil($throttle['retry_after'] / 60));
            failJson("Too many failed attempts. Try again in {$mins} minute(s).");
        }

        $user = resolveLoginUser($db, $credential);

        // Uniform response for unknown user / wrong password / disabled,
        // and equal cost via the dummy verify. Different messages or a
        // different response time both leak whether an account exists.
        if (!$user) {
            password_verify($password, '$2y$10$usesomesillystringfore.HXwmS7cBGaWku1NvXWCFRzcYAa');
            loginThrottleRecord($credential, $clientIp, false);
            failJson('Invalid ID / username or password.');
        }
        if (!$user['is_active']) {
            loginThrottleRecord($credential, $clientIp, false);
            failJson('Invalid ID / username or password.');
        }
        if (!password_verify($password, $user['password_hash'])) {
            handleFailedAttempt($db, (int) $user['id']);
            loginThrottleRecord($credential, $clientIp, false);
            failJson('Invalid ID / username or password.');
        }
        $lockedFor = lockoutRemainingSeconds($db, (int) $user['id']);
        if ($lockedFor > 0) {
            $mins = max(1, (int) ceil($lockedFor / 60));
            loginThrottleRecord($credential, $clientIp, false);
            failJson("Account temporarily locked. Try again in {$mins} minute(s).");
        }

        resetLoginLockout($db, (int) $user['id']);
        loginThrottleRecord($credential, $clientIp, true);
        loginThrottleClear($credential, $clientIp);
        $redirect = signInSession($user);

        echo json_encode([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'user' => [
                    'id'        => (int) $user['id'],
                    'email'     => $user['email'],
                    'full_name' => $user['full_name'],
                    'role'      => $user['role'],
                ],
                'redirect' => $redirect,
            ],
        ]);
    } catch (Exception $e) {
        failJson('Login failed. Please try again.');
    }
    exit;
}

// ─── RESEND OTP ─────────────────────────────────────────────────
if ($method === 'POST' && $action === 'resend_otp') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $userId = (int) ($input['user_id'] ?? 0);
    $purpose = ($input['purpose'] ?? 'login') === 'reset' ? 'reset' : 'login';
    if ($userId <= 0) failJson('Invalid request.');

    // Was unauthenticated and accepted an arbitrary user_id + purpose, so
    // anyone could mint or mail-bomb codes for any account.
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    $pendingUserId = (int) ($_SESSION['otp_user_id'] ?? 0);
    if (($sessionUserId !== $userId) && ($pendingUserId !== $userId)) {
        failJson('Invalid request.');
    }

    try {
        $db = Database::getInstance();
        $throttle = loginThrottleStatus((string) $userId, requestClientIp());
        if ($throttle['blocked']) {
            failJson('Too many requests. Please wait before requesting another code.');
        }
        $user = $db->fetchOne("SELECT id, email FROM users WHERE id = ? AND is_active = 1", [$userId]);
        if (!$user) failJson('Unable to resend the code. Please try again.');
        if (!isValidEmail((string) ($user['email'] ?? ''))) {
            failJson('This account has no deliverable email address. Contact the registrar.');
        }
        $otp = issueOtp($db, $userId, $purpose);
        // Never return the code. It used to be echoed here whenever delivery
        // failed, which combined with the OTP_SHOW_ONSCREEN default handed
        // the reset code straight to the caller.
        echo json_encode([
            'success' => true,
            'message' => 'A new code has been sent.',
            'data' => ['masked_email' => $otp['masked_email'], 'delivered' => $otp['delivered']],
        ]);
    } catch (Exception $e) { failJson('Unable to resend the code. Please try again.'); }
    exit;
}

// ─── VERIFY OTP ─────────────────────────────────────────────────
if ($method === 'POST' && $action === 'verify_otp') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $userId = (int) ($input['user_id'] ?? 0);
    $purpose = ($input['purpose'] ?? 'login') === 'reset' ? 'reset' : 'login';
    $code = trim($input['otp'] ?? '');
    if ($userId <= 0 || $code === '') failJson('Invalid request.');

    // Only the account this session opened the flow for may be verified.
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    $pendingUserId = (int) ($_SESSION['otp_user_id'] ?? 0);
    if (($sessionUserId !== $userId) && ($pendingUserId !== $userId)) {
        failJson('Invalid request.');
    }

    try {
        $db = Database::getInstance();
        $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user) failJson('Account not found. Please sign in again.');
        // Neutral wording: a distinct 'disabled' reply confirms the account// exists, which is an enumeration oracle. Same message as an invalid grant.
        if (!$user['is_active']) failJson('This reset link is invalid or has expired. Please start over.');
        if (!verifyOtpCode($db, $userId, $purpose, $code)) {
            failJson('Invalid or expired code. Please try again.', false, ['otp_invalid' => true]);
        }

        // Bind the session to this account now that the code is proven.
        $_SESSION['otp_user_id'] = $userId;
        $_SESSION['otp_purpose'] = $purpose;

        if ($purpose === 'reset') {
            // Mint the single-use reset grant — the ONLY thing that lets
            // reset_password proceed.
            $resetToken = issueResetGrant($db, $userId);
            logActivity($userId, 'password_reset_grant_issued');
            echo json_encode([
                'success' => true,
                'message' => 'Code verified. Set your new password.',
                'data' => ['step' => 'reset_password', 'user_id' => $userId, 'reset_token' => $resetToken],
            ]);
        } else {
            $redirect = signInSession($user);
            echo json_encode([
                'success' => true,
                'message' => 'Login successful.',
                'data' => [
                    'user' => ['id' => (int) $user['id'], 'email' => $user['email'], 'full_name' => $user['full_name'], 'role' => $user['role']],
                    'redirect' => $redirect,
                ],
            ]);
        }
    } catch (Exception $e) { failJson('An error occurred. Please try again.'); }
    exit;
}

// ─── FORGOT PASSWORD ────────────────────────────────────────────
if ($method === 'POST' && $action === 'forgot') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $email = strtolower(trim($input['email'] ?? ''));
    if ($email === '') failJson('Please enter your email address.');
    try {
        $db = Database::getInstance();

        // Rate limit first: stops this endpoint being used to mail-bomb.
        $throttle = loginThrottleStatus($email, requestClientIp());
        if ($throttle['blocked']) {
            failJson('Too many requests. Please wait before trying again.');
        }
        loginThrottleRecord($email, requestClientIp(), false);

        // users.email is no longer UNIQUE (a shared address is legitimate), so
        // this could match several accounts. Pick deterministically by oldest id
        // so the outcome cannot vary between requests.
        $user = $db->fetchOne("SELECT id, email, is_active FROM users WHERE email = ? ORDER BY id ASC LIMIT 1", [$email]);
        // One neutral message for every outcome — unknown, disabled, or
        // account with no deliverable address. Differing replies would let
        // an attacker enumerate which addresses are registered.
        if (!$user) {
            failJson('If that email is registered, a reset code has been sent.');
        }
        if (!$user['is_active'] || !isValidEmail((string) ($user['email'] ?? ''))) {
            failJson('If that email is registered, a reset code has been sent.');
        }

        // Bind this session to the account being recovered.
        $_SESSION['otp_user_id'] = (int) $user['id'];
        $_SESSION['otp_purpose'] = 'reset';

        $otp = issueOtp($db, (int) $user['id'], 'reset', $user['email']);
        echo json_encode([
            'success' => true,
            'message' => 'A reset code has been sent to your email.',
            'data' => [
                'step'         => 'otp',
                'user_id'      => (int) $user['id'],
                'purpose'      => 'reset',
                'masked_email' => $otp['masked_email'],
                'delivered'    => $otp['delivered'],
            ],
        ]);
    } catch (Exception $e) { failJson('An error occurred. Please try again.'); }
    exit;
}

// ─── RESET PASSWORD ─────────────────────────────────────────────
// THE CRITICAL FIX.
//
// This accepted a bare user_id + new_password with no proof of anything,
// so anyone could take over any account:
//
//     POST ?action=reset_password {"user_id":1,"new_password":"x"}
//
// It now requires a reset grant, minted only after the reset OTP was
// verified, stored hashed, expiring in 15 minutes and single-use.
if ($method === 'POST' && $action === 'reset_password') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $grantToken = trim((string) ($input['reset_token'] ?? ''));
    $newPassword = (string) ($input['new_password'] ?? '');
    $confirm = (string) ($input['confirm_password'] ?? '');

    if ($grantToken === '' || $newPassword === '' || $confirm === '') failJson('Please fill in all fields.');
    if ($newPassword !== $confirm) failJson('Passwords do not match.');

    // Same policy as every other password-set path.
    require_once __DIR__ . '/../shared/password_policy.php';
    $policy = checkPasswordPolicy($newPassword);
    if (!$policy['valid']) failJson($policy['message']);

    try {
        $db = Database::getInstance();

        // Take the user id from the verified grant, never from the request.
        // This is what closes the account-takeover hole.
        $userId = consumeResetGrant($db, $grantToken);
        if ($userId === null) {
            failJson('This reset link is invalid or has expired. Please start over.');
        }

        $user = $db->fetchOne("SELECT id, is_active FROM users WHERE id = ?", [$userId]);
        if (!$user) failJson('Account not found. Please start over.');
        // Neutral wording: a distinct 'disabled' reply confirms the account// exists, which is an enumeration oracle. Same message as an invalid grant.
        if (!$user['is_active']) failJson('This reset link is invalid or has expired. Please start over.');

        // Writes the hash + password_changed_at together, so every session
        // issued before now is invalidated by shared/session_config.php.
        finalizePasswordChange($db, (int) $user['id'], password_hash($newPassword, PASSWORD_DEFAULT));

        unset($_SESSION['otp_user_id'], $_SESSION['otp_purpose']);

        logActivity((int) $user['id'], 'password_reset', json_encode(['via' => 'reset_grant']));
        echo json_encode(['success' => true, 'message' => 'Your password has been reset. You can now sign in.', 'data' => ['step' => 'done']]);
    } catch (Exception $e) { failJson('An error occurred. Please try again.'); }
    exit;
}

// ─── LOGOUT ─────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'logout') {
    session_unset();
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
    exit;
}

// ─── CHECK SESSION ─────────────────────────────────────────────
if ($method === 'GET' && $action === 'session') {
    if (isLoggedIn()) {
        echo json_encode([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $_SESSION['user_id'],
                    'email' => $_SESSION['email'] ?? '',
                    'full_name' => $_SESSION['full_name'] ?? 'User',
                    'role' => $_SESSION['role'] ?? '',
                ]
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Session expired.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);