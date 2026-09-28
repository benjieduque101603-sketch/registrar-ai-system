<?php
// ============================================================
//  API/QUEUE-PUBLIC.PHP
//  Public (NO login) Queue endpoints for the kiosk + monitor.
//    POST ?action=join                    kiosk tap-in
//    GET  ?action=board                   full-lineup feed (monitor/kiosk/portal)
//    GET  ?action=my_ticket&number=N      standing lookup (portal-ready)
//
//  Join is evaluated strictly in this order:
//    1. card validation (exists, linked, active)
//    2. 2 s per-card anti-bounce (same cardUid rapid re-tap)
//    3. 5 min per-student cooldown (already has a ticket that is < 5 min old)
//    4. join from the back (always) — new number appended, prior ticket stays
//       Steps 3 + 4 run inside a transaction with SELECT … FOR UPDATE
//       to prevent duplicate tickets from race conditions.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/rfid_helpers.php';
require_once __DIR__ . '/../shared/queue_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$db   = Database::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function padNumber(int $n): string {
    return str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

// queue_date / joined_at are written in PHP's Asia/Manila wall clock,
// but the MySQL session may run at +00:00, so CURDATE()/NOW() can be
// 8 h behind. Always bind the PHP-computed date for "today" comparisons.
$today = date('Y-m-d');

// ─── JOIN (kiosk tap) ─────────────────────────────────────────
if ($action === 'join') {
    // must be a POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $cardUid = trim($input['card_uid'] ?? $input['uid'] ?? '');

    if ($cardUid === '') {
        echo json_encode(['success' => false, 'message' => 'Card UID is required.']);
        exit;
    }

    try {
        // ── Lightweight throttle (~15 joins/min/IP) ─────────────
        $joinCount = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM rfid_scan_logs
             WHERE event_type = 'queue_join' AND ip_address = ?
               AND scanned_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)",
            [$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']
        );
        if ($joinCount >= 15) {
            echo json_encode(['success' => false, 'message' => 'Too many join attempts. Please try again shortly.']);
            exit;
        }

        // ── 1. Card validation ────────────────────────────────
        // All validation failures (not found, unlinked, lost, expired,
        // inactive) return the same generic message to prevent card-UID
        // enumeration. The specific reason is logged server-side only.
        $card = lookupCardByUid($db, $cardUid);
        $deniedLogReason = null;
        if (!$card) {
            $deniedLogReason = 'not_found';
        } elseif ($card['student_id'] === null) {
            $deniedLogReason = 'unlinked';
        } elseif (($card['status'] ?? '') === 'lost') {
            $deniedLogReason = 'lost';
        } elseif (($card['status'] ?? '') === 'inactive') {
            $deniedLogReason = 'inactive';
        } elseif (!empty($card['expiry_date']) && $card['expiry_date'] < date('Y-m-d')) {
            $deniedLogReason = 'expired';
        } elseif (($card['status'] ?? '') === 'expired') {
            $deniedLogReason = 'expired';
        }
        if ($deniedLogReason) {
            $db->insert('rfid_scan_logs', [
                'card_uid'   => $cardUid,
                'student_id' => $card ? ($card['student_id'] ?? null) : null,
                'location'   => 'Registrar Kiosk',
                'event_type' => 'queue_join',
                'status'     => 'denied',
                'scanner_id' => 'kiosk',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ]);
            echo json_encode(['success' => false, 'code' => 'denied', 'message' => 'Unable to process this card. Please see the registrar.']);
            exit;
        }

        $studentId = (int) $card['student_id'];
        $studentName = trim(($card['first_name'] ?? '') . ' ' . ($card['last_name'] ?? '')) ?: 'Student';
        $studentNumber = $card['student_number'] ?? null;
        $course = $card['course'] ?? null;

        // ── 2. 2 s per-card anti-bounce ────────────────────────
        $recentByCard = $db->fetchOne(
            "SELECT joined_at FROM queue_tickets
             WHERE queue_date = ? AND card_uid = ?
             ORDER BY joined_at DESC LIMIT 1",
            [$today, $cardUid]
        );
        if ($recentByCard && (time() - strtotime($recentByCard['joined_at']) < 2)) {
            echo json_encode(['success' => false, 'code' => 'throttle', 'message' => 'Please wait a moment before tapping again.']);
            exit;
        }

        // ── 3. One live ticket per student per day ───────────────
        // ── 4. Join from the back (always) ──────────────────────
        // The invariant that matters is the ticket's STATUS, not its age.
        // This used to be a 5-minute TIME check, which meant a student still
        // standing in line could tap again the moment the cooldown expired and
        // be issued a second number (observed: Roldan Tiu holding #3 and #6,
        // Cathy Tenco holding #4 and #5, simultaneously). A 'waiting' or
        // 'serving' ticket blocks a new one no matter how old it is; only a
        // finished ticket (completed / no-show / cancelled / removed) allows a
        // fresh number, and then a short cooldown still guards an instant
        // re-tap the moment someone is marked served.
        //
        // Steps 3 + 4 run in a transaction with SELECT … FOR UPDATE so two
        // concurrent taps cannot both pass the live-ticket check.
        $db->beginTransaction();
        try {
            $live = $db->fetchOne(
                "SELECT * FROM queue_tickets
                 WHERE queue_date = ? AND student_id = ? AND status IN ('waiting','serving')
                 ORDER BY joined_at DESC LIMIT 1 FOR UPDATE",
                [$today, $studentId]
            );
            if ($live) {
                $db->rollBack();
                $isServing = $live['status'] === 'serving';
                $position = 0;
                if (!$isServing) {
                    $position = (int) $db->fetchColumn(
                        "SELECT COUNT(*) FROM queue_tickets
                         WHERE queue_date = ? AND status = 'waiting' AND ticket_number <= ?",
                        [$today, (int) $live['ticket_number']]
                    );
                }
                echo json_encode([
                    'success' => false,
                    'code'    => $isServing ? 'now_serving' : 'already_queued',
                    'message' => $isServing
                        ? 'You are being served now — please proceed to the window.'
                        : 'You already have number ' . padNumber((int) $live['ticket_number'])
                          . ' — you are #' . $position . ' in line.',
                    'data'    => [
                        'ticket_id'      => (int) $live['id'],
                        'ticket_number'  => (int) $live['ticket_number'],
                        'display_number' => padNumber((int) $live['ticket_number']),
                        'student_name'   => $live['student_name'],
                        'status'         => $live['status'],
                        'counter'        => (int) $live['counter'],
                        'position'       => $position,
                        'waiting_ahead'  => max(0, $position - 1),
                    ],
                ]);
                exit;
            }

            // Short cooldown — only meaningful once a previous ticket has
            // actually finished, so this cannot block a legitimate re-queue.
            $finished = $db->fetchOne(
                "SELECT * FROM queue_tickets
                 WHERE queue_date = ? AND student_id = ?
                 ORDER BY joined_at DESC LIMIT 1 FOR UPDATE",
                [$today, $studentId]
            );
            if ($finished && (time() - strtotime($finished['joined_at']) < 30)) {
                $db->rollBack();
                echo json_encode([
                    'success' => false,
                    'code'    => 'cooldown',
                    'message' => 'You were just served — please wait a moment before taking a new number.',
                    'data'    => [
                        'ticket_id'      => (int) $finished['id'],
                        'display_number' => padNumber((int) $finished['ticket_number']),
                        'student_name'   => $studentName,
                    ],
                ]);
                exit;
            }

            [$reader, $location] = resolveReaderLocation($db, null);

            $nextNumber = (int) $db->fetchColumn(
                "SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM queue_tickets WHERE queue_date = ? FOR UPDATE",
                [$today]
            );
            $now = date('Y-m-d H:i:s');
            $ticketId = $db->insert('queue_tickets', [
                'queue_date'     => $today,
                'ticket_number'  => $nextNumber,
                'student_id'     => $studentId,
                'student_name'   => $studentName,
                'student_number' => $studentNumber,
                'course'         => $course,
                'status'         => 'waiting',
                'counter'        => 0,
                'card_uid'       => $cardUid,
                'joined_at'      => $now,
            ]);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $db->insert('rfid_scan_logs', [
            'card_uid'   => $cardUid,
            'student_id' => $studentId,
            'location'   => $location,
            'event_type' => 'queue_join',
            'status'     => 'success',
            'scanner_id' => $reader ? (string) $reader['id'] : 'kiosk',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);

        $position = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM queue_tickets
             WHERE queue_date = ? AND status = 'waiting' AND ticket_number <= ?",
            [$today, $nextNumber]
        );

        $reQueued = !empty($finished); // had a finished ticket earlier today

        echo json_encode([
            'success' => true,
            'message' => 'Ticket ready — please wait for your number to be called.',
            'data'    => [
                'ticket_id'      => (int) $ticketId,
                'ticket_number'  => $nextNumber,
                'display_number' => padNumber($nextNumber),
                'student_name'   => $studentName,
                'position'       => $position,
                'waiting_ahead'  => max(0, $position - 1),
                're_queued'      => $reQueued,
            ],
        ]);
    } catch (Throwable $e) {
        json_error($e, 'Unable to join the queue.');
    }
    exit;
}

// ─── BOARD (full-lineup public feed) ──────────────────────────
if ($action === 'board') {
    try {
        $payload = windowMapToPayload(buildWindowMap($db, $today));

        $waitingRows = $db->fetchAll(
            "SELECT id, ticket_number, student_name FROM queue_tickets
             WHERE queue_date = ? AND status = 'waiting'
             ORDER BY ticket_number ASC",
            [$today]
        );
        $waiting = [];
        foreach ($waitingRows as $i => $w) {
            $waiting[] = [
                'ticket_id'     => (int) $w['id'],
                'position'      => $i + 1,
                'number'        => padNumber((int) $w['ticket_number']),
                'ticket_number' => (int) $w['ticket_number'],
                'name'          => $w['student_name'],
                'next_up'       => $i === 0,
            ];
        }

        $recent = $db->fetchAll(
            "SELECT ticket_number, student_name, status FROM queue_tickets
             WHERE queue_date = ? AND status IN ('completed','no-show','removed','cancelled')
             ORDER BY COALESCE(served_at, joined_at) DESC, id DESC
             LIMIT 5",
            [$today]
        );
        $recentMapped = array_map(static function ($r) {
            return [
                'number' => padNumber((int) $r['ticket_number']),
                'name'   => $r['student_name'],
                'status' => $r['status'],
            ];
        }, $recent);

        $lastNumber = (int) $db->fetchColumn(
            "SELECT COALESCE(MAX(ticket_number), 0) FROM queue_tickets WHERE queue_date = ?",
            [$today]
        );
        $waitingCount = count($waiting);

        echo json_encode([
            'success' => true,
            'data'    => [
                'windows'         => $payload['windows'],
                'serving'         => $payload['serving'],
                'waiting'         => $waiting,
                'recently_served' => $recentMapped,
                'waiting_count' => $waitingCount,
                'last_number'   => $lastNumber,
            ],
        ]);
    } catch (Throwable $e) {
        json_error($e, 'Unable to load the queue.');
    }
    exit;
}

// ─── MY TICKET (standing lookup, portal-ready) ────────────────
if ($action === 'my_ticket') {
    $number = (int) ($_GET['number'] ?? 0);
    if ($number <= 0) {
        echo json_encode(['success' => false, 'message' => 'A valid number is required.']);
        exit;
    }
    try {
        $ticket = $db->fetchOne(
            "SELECT * FROM queue_tickets WHERE queue_date = ? AND ticket_number = ?",
            [$today, $number]
        );
        if (!$ticket) {
            echo json_encode(['success' => false, 'message' => 'Number not found for today.', 'code' => 'not_found']);
            exit;
        }

        $ordering = ['waiting' => 0, 'serving' => 1, 'completed' => 2, 'no-show' => 3, 'cancelled' => 4, 'removed' => 5];
        // "Now serving" for THIS student, not the globally newest serving
        // ticket. With multiple windows open, ordering by id DESC returned
        // whatever was called last at any desk, so a student checked in at
        // Window 1 could be told they were being seen at Window 3. A waiting
        // student has no window yet, so this is null until they are called.
        $serving = $db->fetchOne(
            "SELECT ticket_number, student_name, counter FROM queue_tickets
             WHERE queue_date = ? AND status = 'serving' AND counter = ?",
            [$today, normalizeWindow($ticket['counter'] ?? 0)]
        );
        // The student is only "at a window" when their own ticket is the one
        // being served there.
        $atMyWindow = $serving && (int) $serving['ticket_number'] === (int) $ticket['ticket_number'];

        $position = 0;
        $waitingAhead = 0;
        $nextUp = false;
        if ($ticket['status'] === 'waiting') {
            $position = (int) $db->fetchColumn(
                "SELECT COUNT(*) FROM queue_tickets
                 WHERE queue_date = ? AND status = 'waiting' AND ticket_number <= ?",
                [$today, (int) $ticket['ticket_number']]
            );
            $waitingAhead = max(0, $position - 1);
            $nextUp = $position === 1;
        }

        $lineup = array_map(static function ($w) {
            return ['number' => padNumber((int) $w['ticket_number']), 'name' => $w['student_name']];
        }, $db->fetchAll(
            "SELECT ticket_number, student_name FROM queue_tickets
             WHERE queue_date = ? AND status = 'waiting'
             ORDER BY ticket_number ASC",
            [$today]
        ));

        echo json_encode([
            'success' => true,
            'data'    => [
                'ticket_id'       => (int) $ticket['id'],
                'ticket_number'   => (int) $ticket['ticket_number'],
                'display_number'  => padNumber((int) $ticket['ticket_number']),
                'student_name'    => $ticket['student_name'],
                'status'          => $ticket['status'],
                'status_order'    => $ordering[$ticket['status']] ?? 5,
                'position'        => $position,
                'waiting_ahead'   => $waitingAhead,
                'next_up'         => $nextUp,
                'serving_ticket'  => $atMyWindow
                    ? ['number' => padNumber((int) $serving['ticket_number']), 'name' => $serving['student_name'], 'counter' => (int) $serving['counter']]
                    : null,
                'joined_at'       => $ticket['joined_at'],
                'called_at'       => $ticket['called_at'],
                'served_at'       => $ticket['served_at'],
                'lineup'          => $lineup,
            ],
        ]);
    } catch (Throwable $e) {
        json_error($e, 'Unable to look up the number.');
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);