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
//    GET  ?action=state&window=N&date=YYYY-MM-DD
//    POST ?action=save_day_settings  open/close times + daily tap caps
//    POST ?action=set_cutoff        close the queue now
//    POST ?action=clear_cutoff      return to the clock rule
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

/**
 * Normalise a history date from the query string.
 *
 * This value is always passed as a bound parameter and never
 * concatenated into SQL, but it is still checked: a valid Y-m-d can
 * only be a real calendar day, so a typo falls back to today rather
 * than an empty history table, and a future date is refused rather
 * than rendering an empty day that looks like data loss.
 */
function queueHistoryDate($raw, ?string $today = null): string {
    $today = $today ?? date('Y-m-d');
    $raw = trim((string) $raw);
    if ($raw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return $today;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $raw));
    if (!checkdate($m, $d, $y) || $raw > $today) {
        return $today;
    }
    return $raw;
}

/**
 * Normalise an incoming time to H:i:s, or null if it is not a time.
 *
 * Accepts the HH:MM a <input type="time"> submits as well as a full
 * H:i:s, so the console can post either without the server guessing.
 * Returns null rather than silently falling back — a registrar who typed
 * a nonsense time must be told, not given the default back.
 */
function queueTimeValue($raw, ?string $fallback = null): ?string {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return $fallback;
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $m)) {
        return null;
    }
    $h = (int) $m[1];
    $i = (int) $m[2];
    $s = isset($m[3]) ? (int) $m[3] : 0;
    if ($h > 23 || $i > 59 || $s > 59) {
        return null;
    }
    return sprintf('%02d:%02d:%02d', $h, $i, $s);
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
                'txn_type'      => $w['txn_type'] ?? 'service',
                'priority_group'=> $w['priority_group'] ?? 'student',
                'window'        => queueWindowForLane($w['txn_type'] ?? 'service', $w['priority_group'] ?? 'student'),
            ];
        }

        // History is date-selectable; the live line above is not. A
        // registrar reviewing last Tuesday needs that day's finished
        // tickets, but "waiting now" is only ever today — showing a
        // three-day-old waiting list next to a live counter would be
        // nonsense. So only this query is date-scoped.
        $historyDate = queueHistoryDate($_GET['date'] ?? $today);
        $isToday = ($historyDate === $today);

        $completedRows = $db->fetchAll(
            "SELECT * FROM queue_tickets
             WHERE queue_date = ? AND status IN ('completed','no-show','removed','cancelled')
             ORDER BY COALESCE(served_at, joined_at) DESC, id DESC
             LIMIT 200",
            [$historyDate]
        );
        $completed = array_map(static function ($c) {
            return [
                'ticket_id'     => (int) $c['id'],
                'ticket_number' => (int) $c['ticket_number'],
                'display_number'=> padNumber((int) $c['ticket_number']),
                'student_name'  => $c['student_name'],
                'student_number'=> $c['student_number'],
                'status'        => $c['status'],
                'served_at'     => $c['served_at'],
                'txn_type'      => $c['txn_type'] ?? 'service',
                'priority_group'=> $c['priority_group'] ?? 'student',
            ];
        }, $completedRows);

        // Counts follow the date the console is looking at, so the strip
        // and the history table underneath always describe the same day.
        // "Now serving" stays on today regardless — that desk is live now.
        $countDate = $historyDate;
        $stats = [
            'waiting'   => (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE queue_date = ? AND status = 'waiting'", [$countDate]),
            'serving'   => (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE queue_date = ? AND status = 'serving'", [$countDate]),
            'completed' => (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE queue_date = ? AND status = 'completed'", [$countDate]),
            'no_show'   => (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE queue_date = ? AND status = 'no-show'", [$countDate]),
            'cancelled' => (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE queue_date = ? AND status = 'cancelled'", [$countDate]),
        ];

        $daySettings = queueDaySettings($db, $today);
        $closed = queueIsClosed($daySettings);

        echo json_encode([
            'success' => true,
            'data'    => [
                'serving'   => $serving,
                'windows'   => windowMapToPayload($windowMap)['windows'],
                'my_window' => $myWindow,
                'my_lane'   => queueWindowLane($myWindow),
                'waiting'   => $waiting,
                'completed' => $completed,
                'stats'     => $stats,
                'history_date' => $historyDate,
                'is_today'  => $isToday,
                'today'     => $today,
                'queue_closed'    => (bool) $closed['closed'],
                'closed_reason'   => $closed['reason'],
                'closed_at'       => $closed['at'],
                'settings'  => [
                    'opens_time'        => $daySettings['opens_time'],
                    'closes_time'       => $daySettings['closes_time'],
                    'cutoff_enabled'    => (int) $daySettings['cutoff_enabled'],
                    'cutoff_forced_at'  => $daySettings['cutoff_forced_at'],
                    'max_taps_student'  => (int) $daySettings['max_taps_student'],
                    'max_taps_priority' => (int) $daySettings['max_taps_priority'],
                ],
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
        $lane = queueWindowLane($window);

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

        // Only this desk's own lane. Without the filter, Window 2 (Service ·
        // Student) pulled the oldest ticket off the whole day and could
        // call a priority student who belonged at Window 1 — the split
        // existed on paper and did nothing.
        $next = $db->fetchOne(
            "SELECT * FROM queue_tickets
             WHERE queue_date = ? AND status = 'waiting'
               AND txn_type = ? AND priority_group = ?
             ORDER BY ticket_number ASC LIMIT 1",
            [$today, $lane['txn_type'], $lane['priority_group']]
        );
        if (!$next) {
            echo json_encode([
                'success' => true,
                'message' => 'No one is waiting for ' . $lane['label'] . '.',
                'data'    => ['called' => null, 'lane' => $lane],
            ]);
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
            // Auto-advance follows the skipped ticket's OWN lane, not the
            // globally-oldest waiting number. Skipping someone at Window 1
            // used to pull a student belonging to another desk onto this
            // one, leaving the real next-in-line still waiting.
            $skippedLane = [
                'txn_type'       => $ticket['txn_type'] ?? 'service',
                'priority_group' => $ticket['priority_group'] ?? 'student',
            ];
            $next = $db->fetchOne(
                "SELECT * FROM queue_tickets
                 WHERE queue_date = ? AND status = 'waiting'
                   AND txn_type = ? AND priority_group = ?
                 ORDER BY ticket_number ASC LIMIT 1",
                [$today, $skippedLane['txn_type'], $skippedLane['priority_group']]
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

// ─── DAY SETTINGS (cut-off + daily tap caps) ─────────────────
// One row per queue_date, written with an upsert. A registrar pressing
// CUT OFF at 4 PM on a day nobody configured must work, so this cannot
// assume the row already exists.
if ($action === 'save_day_settings') {
    $date = queueHistoryDate($input['date'] ?? $today, $today);
    try {
        $cur = queueDaySettings($db, $date);

        $opens  = queueTimeValue($input['opens_time']  ?? $cur['opens_time'],  '08:00:00');
        $closes = queueTimeValue($input['closes_time'] ?? $cur['closes_time'], '17:00:00');
        if ($opens === null || $closes === null) {
            echo json_encode(['success' => false, 'message' => 'Enter valid opening and closing times.']);
            exit;
        }

        // A window that ends before it starts would close the queue all
        // day while looking configured. Refuse rather than save it.
        if (strtotime($closes) <= strtotime($opens)) {
            echo json_encode(['success' => false, 'message' => 'The closing time must be after the opening time.']);
            exit;
        }

        $maxStudent  = max(0, (int) ($input['max_taps_student']  ?? $cur['max_taps_student']));
        $maxPriority = max(0, (int) ($input['max_taps_priority'] ?? $cur['max_taps_priority']));
        $enabled = isset($input['cutoff_enabled'])
            ? (int) (bool) $input['cutoff_enabled']
            : (int) $cur['cutoff_enabled'];

        // `cutoff_forced_at` is the manual CUT OFF switch and is NOT part of
        // this save: clearing it is a separate, deliberate action
        // (clear_cutoff). Saving times must never silently reopen a queue
        // a registrar just closed.
        $db->query(
            "INSERT INTO queue_day_settings
                (queue_date, opens_time, closes_time, cutoff_enabled,
                 cutoff_forced_at, cutoff_forced_by, max_taps_student, max_taps_priority, updated_by)
             VALUES (?,?,?,?,NULL,NULL,?,?,?)
             ON DUPLICATE KEY UPDATE
                opens_time = VALUES(opens_time),
                closes_time = VALUES(closes_time),
                cutoff_enabled = VALUES(cutoff_enabled),
                max_taps_student = VALUES(max_taps_student),
                max_taps_priority = VALUES(max_taps_priority),
                updated_by = VALUES(updated_by)",
            [$date, $opens, $closes, $enabled, $maxStudent, $maxPriority, $_SESSION['user_id']]
        );
        logActivity($_SESSION['user_id'], 'queue_day_settings', null, 'queue_day_settings', null,
            [], ['queue_date' => $date, 'opens_time' => $opens, 'closes_time' => $closes,
                 'max_taps_student' => $maxStudent, 'max_taps_priority' => $maxPriority]);

        $fresh = queueDaySettings($db, $date);
        $closed = queueIsClosed($fresh);
        echo json_encode([
            'success' => true,
            'message' => 'Day settings saved.',
            'data'    => ['settings' => $fresh, 'queue_closed' => $closed['closed'], 'closed_reason' => $closed['reason']],
        ]);
    } catch (Throwable $e) {
        json_error($e, 'Unable to save day settings.');
    }
    exit;
}

if ($action === 'set_cutoff') {
    try {
        $db->query(
            "INSERT INTO queue_day_settings (queue_date, cutoff_forced_at, cutoff_forced_by, updated_by)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE
                cutoff_forced_at = VALUES(cutoff_forced_at),
                cutoff_forced_by = VALUES(cutoff_forced_by),
                updated_by = VALUES(updated_by)",
            [$today, $now, $_SESSION['user_id'], $_SESSION['user_id']]
        );
        logActivity($_SESSION['user_id'], 'queue_cutoff', null, 'queue_day_settings', null,
            [], ['queue_date' => $today, 'cutoff_forced_at' => $now]);
        echo json_encode([
            'success' => true,
            'message' => 'Queue closed at ' . date('g:i A', strtotime($now))
                       . '. Numbers already issued will still be served.',
            'data'    => ['cutoff_forced_at' => $now],
        ]);
    } catch (Throwable $e) {
        json_error($e, 'Unable to close the queue.');
    }
    exit;
}

// Reopen returns the day to the CLOCK rule. It deliberately does not
// extend past closes_time — reopening the queue at 6 PM would invent an
// office-hours window nobody agreed to, and the whole point of the
// cut-off is that students get a definite answer.
if ($action === 'clear_cutoff') {
    try {
        $db->query(
            "INSERT INTO queue_day_settings (queue_date, cutoff_forced_at, cutoff_forced_by, updated_by)
             VALUES (?,NULL,NULL,?)
             ON DUPLICATE KEY UPDATE
                cutoff_forced_at = NULL,
                cutoff_forced_by = NULL,
                updated_by = VALUES(updated_by)",
            [$today, $_SESSION['user_id']]
        );
        logActivity($_SESSION['user_id'], 'queue_reopen', null, 'queue_day_settings', null,
            ['cutoff_forced_at' => $now], []);
        $fresh = queueDaySettings($db, $today);
        $closed = queueIsClosed($fresh);
        echo json_encode([
            'success' => true,
            'message' => $closed['closed']
                ? 'The manual cut-off is cleared, but the queue is still closed for today (' . $fresh['closes_time'] . ').'
                : 'Queue reopened.',
            'data'    => ['settings' => $fresh, 'queue_closed' => $closed['closed']],
        ]);
    } catch (Throwable $e) {
        json_error($e, 'Unable to reopen the queue.');
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);
