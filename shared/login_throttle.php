<?php
// ============================================================
//  SHARED/LOGIN_THROTTLE.PHP
//  DB-backed login throttling (fail-open if the table is
//  missing). Apply the database/login_security.sql migration
//  to enable enforcement. Enforced at 5 failures / 15 min per
//  email+IP, with a 15-minute lockout after the cap is hit.
// ============================================================

if (defined('LOGIN_THROTTLE_LOADED')) {
    return;
}
define('LOGIN_THROTTLE_LOADED', true);

require_once __DIR__ . '/database.php';

define('LOGIN_MAX_FAILURES', 5);
define('LOGIN_WINDOW_SECONDS', 900); // 15 minutes
define('LOGIN_BLOCK_SECONDS', 900);  // lockout duration after hitting the cap

// Check whether logins for this email+IP are currently blocked.
// Returns ['blocked' => bool, 'retry_after' => seconds].
function loginThrottleStatus(string $email, string $ip): array {
    try {
        $db = Database::getInstance();
        $cutoff = date('Y-m-d H:i:s', time() - LOGIN_WINDOW_SECONDS);
        $sql = 'SELECT COUNT(*) AS cnt, MAX(attempted_at) AS last_fail FROM login_attempts WHERE email = ? AND ip_address = ? AND success = 0 AND attempted_at >= ?';
        $row = $db->fetchOne($sql, [$email, $ip, $cutoff]);

        $failures = (int) ($row['cnt'] ?? 0);
        if ($failures < LOGIN_MAX_FAILURES) {
            return ['blocked' => false, 'retry_after' => 0];
        }

        $lastFail = strtotime((string) ($row['last_fail'] ?? ''));
        $retryAfter = max(0, ($lastFail + LOGIN_BLOCK_SECONDS) - time());
        return ['blocked' => true, 'retry_after' => $retryAfter];
    } catch (Exception $e) {
        // Fail open: never lock users out because of a throttle-layer error.
        error_log('[login_throttle] status check failed: ' . $e->getMessage());
        return ['blocked' => false, 'retry_after' => 0];
    }
}

// Record a login attempt (successful or not). Fails open silently.
function loginThrottleRecord(string $email, string $ip, bool $success): void {
    try {
        $db = Database::getInstance();
        $db->insert('login_attempts', [
            'email'        => mb_substr($email, 0, 191),
            'ip_address'   => mb_substr($ip, 0, 45),
            'success'      => $success ? 1 : 0,
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $e) {
        error_log('[login_throttle] record failed: ' . $e->getMessage());
    }
}

// Clear the failure history after a successful login.
function loginThrottleClear(string $email, string $ip): void {
    try {
        $db = Database::getInstance();
        $sql = 'DELETE FROM login_attempts WHERE email = ? AND ip_address = ?';
        $db->query($sql, [$email, $ip]);
    } catch (Exception $e) {
        error_log('[login_throttle] clear failed: ' . $e->getMessage());
    }
}
/**
 * Best-effort client IP for throttling and audit logging.
 *
 * X-Forwarded-For is client-controlled and therefore spoofable, so it is
 * used ONLY as a throttling signal, never for authorisation or access
 * decisions. A spoofed header can at worst throttle the attacker's own
 * requests, which costs them nothing they were not already paying.
 *
 * Behind a proxy (Caddy, in docker-compose.prod.yml) the left-most entry
 * is the original client.
 */
function requestClientIp(): string {
    $candidates = [];

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // May be "client, proxy1, proxy2".
        foreach (explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']) as $part) {
            $candidate = trim($part);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                $candidates[] = $candidate;
            }
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $candidate = trim((string) $_SERVER['HTTP_X_REAL_IP']);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $candidates[] = $candidate;
        }
    }
    if (!empty($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
        $candidates[] = (string) $_SERVER['REMOTE_ADDR'];
    }

    return $candidates[0] ?? '0.0.0.0';
}
