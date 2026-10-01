<?php
// ============================================================
//  API/MAIL-BOUNCE.PHP
//  Receives hard-bounce notifications from the mail provider.
//
//  Why this exists
//  ---------------
//  The registrar kept seeing messages like:
//
//    "Your message wasn't delivered to student_1660_260929@gmail.com
//     because the address couldn't be found...
//     550 5.1.1 The email account that you tried to reach does not exist"
//
//  That is the provider reporting that a recipient does not exist.
//  Without an endpoint to receive those reports the application can
//  never learn an address is dead, so it keeps trying forever.
//
//  Configure in the provider (Brevo: Settings > Webhooks, add a
//  transactional webhook pointing here). The app then records the
//  failure against the student/user so staff see WHY a message did
//  not arrive, instead of silently retrying.
//
//  Endpoint: POST /api/mail-bounce.php
//
//  Authentication
//  --------------
//  Called by the provider, not a browser, so it cannot use a session.
//  It is protected by a shared secret in a request header:
//
//      X-Bounce-Token: <MAIL_BOUNCE_TOKEN>
//
//  When MAIL_BOUNCE_TOKEN is unset the endpoint refuses every request
//  (fail closed) rather than accepting unauthenticated writes to
//  student records.
// ============================================================

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

// ── 1. Authenticate the caller ──────────────────────────────────
// Fail closed: with no token configured there is no way to tell a real
// provider callback from an attacker.
$expected = env('MAIL_BOUNCE_TOKEN');
if ($expected === '' || $expected === false) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Bounce endpoint disabled: set MAIL_BOUNCE_TOKEN to enable it.',
    ]);
    exit;
}

$provided = $_SERVER['HTTP_X_BOUNCE_TOKEN'] ?? ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '');
if (!is_string($provided) || $provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(401);
    error_log('[mail-bounce] rejected: bad or missing token');
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ── 2. Normalise the payload into a list of events ──────────────
$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST ?: [];
}

// Providers differ: some send {"email":"a@b.c","event":"hard_bounce"},
// others wrap a batch in {"events":[...]}.
foreach (['events', 'data'] as $wrapper) {
    if (isset($input[$wrapper]) && is_array($input[$wrapper])) {
        $input = $input[$wrapper];
        break;
    }
}

$events = [];
foreach ($input as $key => $value) {
    if (is_array($value) && isset($value['email'])) {
        $events[] = $value;
    } elseif (is_string($key) && filter_var($key, FILTER_VALIDATE_EMAIL)) {
        $events[] = ['email' => $key, 'reason' => is_scalar($value) ? (string) $value : ''];
    }
}

if (!$events) {
    echo json_encode(['success' => true, 'message' => 'No bounce events in payload.', 'marked' => 0]);
    exit;
}
/**
 * Only a HARD bounce means "this mailbox does not exist". A soft bounce
 * is transient (mailbox full, greylisted, 4xx) and must NOT disable an
 * otherwise good address.
 */
function mailBounceIsHard(array $event): bool
{
    $type = strtolower((string) ($event['event'] ?? $event['type'] ?? $event['status'] ?? ''));
    if ($type === '') {
        return true; // assume the worst when the provider is unlabelled
    }
    $soft = ['soft_bounce', 'softbounce', 'deferred', 'delayed', 'temp', 'try_again'];
    $hard = ['hard_bounce', 'hardbounce', 'bounce', 'invalid', 'no_such_user', 'nosuchuser', 'rejected'];
    if (in_array($type, $soft, true)) {
        return false;
    }
    if (in_array($type, $hard, true)) {
        return true;
    }
    // Unfamiliar label: fall back to the reason text.
    $reason = strtolower((string) ($event['reason'] ?? $event['message'] ?? ''));
    return strpos($reason, 'does not exist') !== false
        || strpos($reason, 'nosuchuser') !== false
        || strpos($reason, 'no such user') !== false
        || strpos($reason, 'not found') !== false;
}

$db = Database::getInstance();
$now = date('Y-m-d H:i:s');
$marked = 0;

foreach ($events as $event) {
    $email = strtolower(trim((string) ($event['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        continue;
    }
    if (!mailBounceIsHard($event)) {
        continue;
    }

    $reason = trim((string) ($event['reason'] ?? $event['message'] ?? $event['event'] ?? 'hard bounce'));
    $reason = mb_substr($reason, 0, 255);

    try {
        // Columns come from migrations/security_hardening_phase1.sql. If
        // that migration has not been applied, log and continue rather
        // than failing the whole webhook (the provider would retry it).
        // students and users are separate rows that usually share a value.
        $db->update(
            'students',
            ['email_bounced_at' => $now, 'email_bounce_reason' => $reason],
            'email = ?',
            [$email]
        );
        $db->update(
            'users',
            ['email_bounced_at' => $now, 'email_bounce_reason' => $reason],
            'email = ?',
            [$email]
        );

        $marked++;
        error_log('[mail-bounce] hard bounce recorded for ' . $email . ' :: ' . $reason);
    } catch (Throwable $e) {
        error_log('[mail-bounce] failed for ' . $email . ': ' . $e->getMessage());
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Bounce events processed.',
    'marked' => $marked,
]);