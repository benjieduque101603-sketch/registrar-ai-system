â<?php
// shared/session_config.php

if (defined('SESSION_CONFIG_LOADED')) {
    return;
}
define('SESSION_CONFIG_LOADED', true);

// config.php provides SESSION_IDLE_TIMEOUT (and DB constants used by pages).
// Load it here so this file works standalone regardless of include order.
require_once __DIR__ . '/config.php';

// Idle session timeout (seconds). A value of 0 disables idle logout
// (and the client-side warning). Touching last_activity on every request
// keeps the window sliding.
$idleTimeout = defined('SESSION_IDLE_TIMEOUT') ? (int) SESSION_IDLE_TIMEOUT : 1200;
$idleLogout  = $idleTimeout > 0;

// -- Start the session -------------------------------------------------
//
// The cookie flags MUST be set before session_start(), or PHP falls back
// to php.ini defaults and the session cookie silently loses protection.
//
// They used to live only in shared/security_headers.php, which required
// every entry point to include that file BEFORE this one. 32 of the
// api/*.php endpoints did not, so on a direct request their cookie was
// issued with whatever php.ini happened to say - commonly without HttpOnly
// and without SameSite.
//
// Setting them here fixes all of them at once and makes the ordering
// impossible to get wrong. security_headers.php still sends the response
// headers (CSP, X-Frame-Options, HSTS); this only owns the cookie, so
// there is no conflict when a page includes both.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    // Keep PHP's session GC from collecting the file before the idle
    // timeout fires; long lifetime when idle logout is disabled.
    ini_set('session.gc_maxlifetime', (string) ($idleLogout ? $idleTimeout : 30 * 86400));

    // HttpOnly: JavaScript cannot read the cookie, so an XSS bug cannot
    // exfiltrate the session.
    ini_set('session.cookie_httponly', '1');
    // Lax: allows normal external navigation into the app (a student
    // clicking a link from email or the queue portal) while still
    // blocking cross-site POSTs. Strict would break the QR-verification
    // and email-link flows.
    ini_set('session.cookie_samesite', 'Lax');
    // Secure: only over HTTPS. Gated so plain-HTTP local XAMPP still
    // works; a live host terminates HTTPS at the vhost or proxy.
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }

    session_name('BCP_REGISTRAR_SESSION');
    session_start();
}

// --- Idle session timeout ---------------------------------------
// Log the user out if they've been inactive past SESSION_IDLE_TIMEOUT.
// Touching last_activity on every request keeps the window sliding.
if (!empty($_SESSION['user_id'])) {
    if ($idleLogout && isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idleTimeout) {
        $_SESSION = array();
        session_destroy();

        // API calls must receive JSON 401, not an HTML redirect.
        if (basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) === 'api') {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.', 'timeout' => true]);
            exit;
        }

        // HTML pages: root-relative redirect so logout lands on Sign In from any depth.
        header('Location: ' . app_url('login.php?timeout=1'));
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// â”€â”€â”€ Password-change invalidation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// A password change must end every session that predates it, so a
// stolen cookie stops working the moment the victim resets.
//
// PHP sessions are files keyed by an opaque id with no portable way to
// delete another device's session, so the check is a timestamp
// comparison: if users.password_changed_at is newer than this
// session's login_time, the session is dead.
//
// Both sides must be in the same clock. login_time is PHP's time()
// (Asia/Manila, set in shared/config.php); password_changed_at is written
// by finalizePasswordChange() using PHP's date() for the same reason
// (MySQL NOW() would be UTC on this host and skew the comparison).
if (!empty($_SESSION['user_id']) && !empty($_SESSION['login_time'])) {
    $passwordChangedAt = null;
    try {
        $db = Database::getInstance();
        $passwordChangedAt = $db->fetchColumn(
            "SELECT password_changed_at FROM users WHERE id = ?",
            [(int) $_SESSION['user_id']]
        );
    } catch (Throwable $e) {
        // Migration not applied yet, or DB briefly unavailable. Fail OPEN
        // rather than logging everyone out during an outage. The column is
        // added by migrations/security_hardening_phase1.sql.
        $passwordChangedAt = null;
    }

    if (!empty($passwordChangedAt) && strtotime((string) $passwordChangedAt) > (int) $_SESSION['login_time']) {
        $_SESSION = array();
        session_destroy();

        if (basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) === 'api') {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Your password has changed. Please sign in again.',
                'password_changed' => true,
            ]);
            exit;
        }

        header('Location: ' . app_url('login.php?timeout=password_changed'));
        exit;
    }
}

// Helper functions
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getCurrentUserRole() {
    return $_SESSION['role'] ?? null;
}

function getCurrentUserName() {
    return $_SESSION['full_name'] ?? 'User';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . app_url('login.php?timeout=1'));
        exit;
    }
}

function requireRole($requiredRole) {
    requireLogin();
    $role = getCurrentUserRole();
    if ($role === 'admin' || $role === $requiredRole) {
        return;
    }
    header('Location: dashboard.php?error=access_denied');
    exit;
}
/**
 * Guard for student-portal pages. Only a 'student' role (or admin,
 * which bypasses everything) may proceed. Anything else is bounced.
 */
function requireStudent() {
    requireLogin();
    $role = getCurrentUserRole();
    if ($role === 'admin' || $role === 'student') {
        return;
    }
    header('Location: dashboard.php?error=access_denied');
    exit;
}

/**
 * Resolve the linked students.id for the currently logged-in user.
 * Returns int|null â€” null when the account has no linked student record.
 * Requires the database to be available (pages call this after Database::getInstance()).
 */
function getCurrentStudentId() {
    if (!isLoggedIn()) {
        return null;
    }
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $db = Database::getInstance();
        $id = $db->fetchColumn(
            "SELECT student_id FROM users WHERE id = ?",
            [getCurrentUserId()]
        );
        $cached = $id !== null ? (int) $id : null;
    } catch (Throwable $e) {
        $cached = null;
    }
    return $cached;
}
?>
