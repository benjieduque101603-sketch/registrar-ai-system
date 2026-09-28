<?php
// ============================================================
//  API/QUEUE.PHP
//  Authenticated Queue endpoints (registrar/admin).
//    GET  ?action=state       console state for today
//    POST ?action=call_next   serve oldest waiting (auto-complete current)
//    POST ?action=skip        advance past absent / non-compliant student
//    POST ?action=complete    finish the serving ticket
//    POST ?action=no_show     mark serving ticket as no-show
//    POST ?action=remove      remove a stuck/duplicate ticket
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/queue_helpers.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
if (!in_array(getCurrentUserRole(), ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$db = Database::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

function padNumber(int $n): string {
    return str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

/**
 * Best-effort log of a queue state transition to rfid_scan_logs.
 * Wrapped in its own try/catch so a logging failure (e.g. the event_type
 * ENUM not yet migrated) never breaks the actual queue operation.
 */
function logQueueEvent($db, array $ticket, string $eventType, string $status, string $location = 'Queue Manager', string $scannerId = 'queue-manager'): void {
    try {
        $db->insert('rfid_scan_logs', [
            'card_uid'   => $ticket['card_uid'] ?? '',
            'student_id' => $ticket['student_id'] ?? null,
            'location'   => $location,
            'event_type' => $eventType,
            'status'     => $status,
            'scanner_id' => $scannerId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);
    } catch (Throwable $e) {
        // Non-fatal — surface nothing to the caller.
        error_log('logQueueEvent failed: ' . $e->getMessage());
    }
}

$now = date('Y-m-d H:i:s');

// ── Timezone-consistent "today" ─────────────────────────────────
// queue_date / joined_at are written in PHP's Asia/Manila wall clock
// (shared/config.php), but the MySQL session may run at +00:00
// (registrar_ai.sql line 12 sets time_zone = '+00:00'), so CURDATE()/
// NOW() evaluate 8 h behind the PHP-supplied dates. Always bind the
// PHP-computed date instead of relying on CURDATE().
$today = date('Y-m-d');

// ─── STATE ────────────────────────────────────────────────────
if ($action === 'state') {
    try {
        $windowMap = buildWindowMap($db, $today);
        $myWindow  = normalizeWindow($_GET['window'] ?? ($_COOKIE['queue_window'] ?? 1));
        // `serving` below is scoped to the registrar's own window, so the
        // console shows the person at THIS desk, not whoever was called last
        // anywhere. `windows` carries the full board for the summary strip.
        $serving = $windowMap[$myWindow] ?? null;

        $waitingRows = $db->fetchAll(
            "SELECT * FROM queue_tickets
             WHERE queue_date = ? AND status = 'waiting'
             ORDER BY ticket_number ASC",
            [$today]
        );
        $waiting = [];
        foreach ($waitingRows as $i => $w) {
            $waiting[] = [
                'ticket_id'     => (int) $w['id'],
                'ticket_number' => (int) $w['ticket_number'],
                'display_number'=> padNumber((int) $w['ticket_number']),
                'position'      => $i + 1,
                'student_name'  => $w['student_name'],
                'student_number'=> $w['student_number'],
                'course'        => $w['course'],
                'joined_at'     => $w['joined_at'],
            ];
        }

        $completedRows = $db->fetchAll(
            "SELECT * FROM queue_tickets
             WHERE queue_date = ? AND status IN ('completed','no-show','removed','cancelled')
             ORDER BY COALESCE(served_at, joined_at) DESC, id DESC
             LIMIT 10",
            [$today]
        );
        $completed = array_map(static function ($c) {
            return [
                'ticket_id'     => (int) $c['id'],
                'ticket_number' => (int) $c['ticket_number'],
                'display_number'=> padNumber((int) $c['ticket_number']),
                'student_name'  => $c['student_name'],
                'status'        => $c['status'],
                'served_at'     => $c['served_at'],
            ];
        }, $completedRows);

        $stats = [
            'waiting'   => (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE queue_date = ? AND status = 'waiting'", [$today]),
            'serving'   => (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE queue_date = ? AND status = 'serving'", [$today]),
            'completed' => (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE queue_date = ? AND status = 'completed'", [$today]),
            'no_show'   => (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE queue_date = ? AND status = 'no-show'", [$today]),
            'cancelled' => (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE queue_date = ? AND status = 'cancelled'", [$today]),
        ];

        echo json_encode([
            'success' => true,
            'data'    => [
                'serving'   => $serving,
                'windows'   => windowMapToPayload($windowMap)['windows'],
                'my_window' => $myWindow,
                'waiting'   => $waiting,
                'completed' => $completed,
                'stats'     => $stats,
            ],
        ]);
    } catch (Throwable $e) {
        json_error($e, 'Unable to load queue state.');
    }
    exit;
}

// All mutating actions are POSTs
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// ─── CALL NEXT ────────────────────────────────────────────────
if ($action === 'call_next') {
    try {
        $window = normalizeWindow($input['window'] ?? 1);

        // A window serves ONE student at a time. This used to auto-complete
        // the globally-newest serving ticket regardless of which window owned
        // it, so calling from Window 2 silently closed out the student Window
        // 1 was still seeing — and two calls could stack on one desk. Scope
        // the check to the requested window and refuse rather than guess.
        $serving = $db->fetchOne(
            "SELECT * FROM queue_tickets
             WHERE queue_date = ? AND status = 'serving' AND counter = ?
             ORDER BY id DESC LIMIT 1",
            [$today, $window]
        );
        if ($serving) {
            echo json_encode([
                'success' => false,
                'code'    => 'window_busy',
                'message' => 'Window ' . $window . ' is still serving number '
                             . padNumber((int) $serving['ticket_number'])
                             . ' — ' . $serving['student_name']
                             . '. Complete or skip them first.',
                'data'    => [
                    'ticket_id'      => (int) $serving['id'],
                    'display_number' => padNumber((int) $serving['ticket_number']),
                    'student_name'   => $serving['student_name'],
                    'counter'        => $window,
                    'called_at'      => $serving['called_at'],
                ],
            ]);
            exit;
        }

        $next = $db->fetchOne(
            "SELECT * FROM queue_tickets
             WHERE queue_date = ? AND status = 'waiting'
             ORDER BY ticket_number ASC LIMIT 1",
            [$today]
        );
        if (!$next) {
            echo json_encode(['success' => true, 'message' => 'Queue is empty.', 'data' => ['called' => null]]);
            exit;
        }

        $db->update('queue_tickets',
            ['status' => 'serving', 'called_at' => $now, 'counter' => $window],
            'id = ?', [$next['id']]);
        logActivity($_SESSION['user_id'], 'queue_call_next', null, 'queue_tickets', $next['id'],
            ['status' => 'waiting'], ['status' => 'serving']);

        // Log queue call event to rfid_scan_logs
        logQueueEvent($db, $next, 'queue_call', 'success');

        echo json_encode([
            'success' => true,
            'message' => 'Called number ' . padNumber((int) $next['ticket_number']) . ' — ' . $next['student_name'] . ' — Proceed to Window ' . $window . '.',
            'data'    => [
                'called' => [
                    'ticket_id'      => (int) $next['id'],
                    'ticket_number'  => (int) $next['ticket_number'],
                    'display_number' => padNumber((int) $next['ticket_number']),
                    'student_name'   => $next['student_name'],
                    'student_number' => $next['student_number'],
                    'course'         => $next['course'],
                    'called_at'      => $now,
                    'counter'        => $window,
                ],
            ],
        ]);
    } catch (Throwable $e) {
        json_error($e, 'Unable to call next.');
    }
    exit;
}

// ─── SKIP (absent / failed to comply within 5 minutes) ────────
if ($action === 'skip') {
    $ticketId = (int) ($input['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        echo json_encode(['success' => false, 'message' => 'A ticket is required.']);
        exit;
    }
    try {
        $ticket = $db->fetchOne(
            "SELECT * FROM queue_tickets WHERE id = ? AND queue_date = ? AND status IN ('waiting','serving')",
            [$ticketId, $today]
        );
        if (!$ticket) {
            echo json_encode(['success' => false, 'message' => 'Ticket not found or already finished.']);
            exit;
        }

        $wasServing = $ticket['status'] === 'serving';
        $db->update('queue_tickets',
            ['status' => 'no-show', 'served_at' => $now],
            'id = ?', [$ticketId]);
        logActivity($_SESSION['user_id'], 'queue_skip', null, 'queue_tickets', $ticketId,
            ['status' => $ticket['status']], ['status' => 'no-show']);

        // Log queue no-show event to rfid_scan_logs for the skipped ticket
        logQueueEvent($db, $ticket, 'queue_no_show', 'denied');

        $calledNext = null;
        if ($wasServing) {
            $next = $db->fetchOne(
                "SELECT * FROM queue_tickets
                 WHERE queue_date = ? AND status = 'waiting'
                 ORDER BY ticket_number ASC LIMIT 1",
                [$today]
            );
            if ($next) {
                $db->update('queue_tickets',
                    ['status' => 'serving', 'called_at' => $now],
                    'id = ?', [$next['id']]);
                logActivity($_SESSION['user_id'], 'queue_call_next', null, 'queue_tickets', $next['id'],
                    ['status' => 'waiting'], ['status' => 'serving']);

                // Log queue call event to rfid_scan_logs for the newly called ticket
                logQueueEvent($db, $next, 'queue_call', 'success');

                $calledNext = [
                    'ticket_id'      => (int) $next['id'],
                    'ticket_number'  => (int) $next['ticket_number'],
                    'display_number' => padNumber((int) $next['ticket_number']),
                    'student_name'   => $next['student_name'],
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Marked ' . $ticket['student_name'] . ' as no-show'
                         . ($calledNext ? ' and called ' . $calledNext['display_number'] . '.' : '.'),
            'data'    => ['skipped' => (int) $ticketId, 'called_next' => $calledNext],
        ]);
    } catch (Throwable $e) {
        json_error($e, 'Unable to skip the ticket.');
    }
    exit;
}

// ─── COMPLETE ─────────────────────────────────────────────────
if ($action === 'complete') {
    $ticketId = (int) ($input['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        echo json_encode(['success' => false, 'message' => 'A ticket is required.']);
        exit;
    }
    try {
        $ticket = $db->fetchOne(
            "SELECT * FROM queue_tickets WHERE id = ? AND queue_date = ? AND status = 'serving'",
            [$ticketId, $today]
        );
        if (!$ticket) {
            echo json_encode(['success' => false, 'message' => 'Ticket is not being served.']);
            exit;
        }
        $db->update('queue_tickets',
            ['status' => 'completed', 'served_at' => $now],
            'id = ?', [$ticketId]);
        logActivity($_SESSION['user_id'], 'queue_complete', null, 'queue_tickets', $ticketId,
            ['status' => 'serving'], ['status' => 'completed']);

        // Log queue completed event to rfid_scan_logs
        logQueueEvent($db, $ticket, 'queue_completed', 'success');

        echo json_encode(['success' => true, 'message' => $ticket['student_name'] . ' completed.']);
    } catch (Throwable $e) {
        json_error($e, 'Unable to complete the ticket.');
    }
    exit;
}

// ─── NO-SHOW ──────────────────────────────────────────────────
if ($action === 'no_show') {
    $ticketId = (int) ($input['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        echo json_encode(['success' => false, 'message' => 'A ticket is required.']);
        exit;
    }
    try {
        $ticket = $db->fetchOne(
            "SELECT * FROM queue_tickets WHERE id = ? AND queue_date = ? AND status = 'serving'",
            [$ticketId, $today]
        );
        if (!$ticket) {
            echo json_encode(['success' => false, 'message' => 'Ticket is not being served.']);
            exit;
        }
        $db->update('queue_tickets',
            ['status' => 'no-show', 'served_at' => $now],
            'id = ?', [$ticketId]);
        logActivity($_SESSION['user_id'], 'queue_no_show', null, 'queue_tickets', $ticketId,
            ['status' => 'serving'], ['status' => 'no-show']);

        // Log queue no-show event to rfid_scan_logs
        logQueueEvent($db, $ticket, 'queue_no_show', 'denied');

        echo json_encode(['success' => true, 'message' => $ticket['student_name'] . ' marked as no-show.']);
    } catch (Throwable $e) {
        json_error($e, 'Unable to mark no-show.');
    }
    exit;
}

// ─── REMOVE ───────────────────────────────────────────────────
if ($action === 'remove') {
    $ticketId = (int) ($input['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        echo json_encode(['success' => false, 'message' => 'A ticket is required.']);
        exit;
    }
    try {
        $ticket = $db->fetchOne("SELECT * FROM queue_tickets WHERE id = ? AND queue_date = ?", [$ticketId, $today]);
        if (!$ticket) {
            echo json_encode(['success' => false, 'message' => 'Ticket not found.']);
            exit;
        }
        $db->update('queue_tickets',
            ['status' => 'removed', 'served_at' => $now],
            'id = ?', [$ticketId]);
        logActivity($_SESSION['user_id'], 'queue_remove', null, 'queue_tickets', $ticketId,
            ['status' => $ticket['status']], ['status' => 'removed']);

        // Log queue cancelled event to rfid_scan_logs
        logQueueEvent($db, $ticket, 'queue_cancelled', 'denied');

        echo json_encode(['success' => true, 'message' => 'Ticket removed from the queue.']);
    } catch (Throwable $e) {
        json_error($e, 'Unable to remove the ticket.');
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);
