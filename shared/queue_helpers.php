<?php
// ============================================================
//  SHARED/QUEUE_HELPERS.PHP
//  Windowed-queue helpers shared by the public kiosk/monitor
//  feeds (api/queue-public.php) and the staff console
//  (api/queue.php), so both agree on what a "window" is and
//  how the live serving map is read.
//
//  Model: ONE shared waiting line; each window serves at most
//  one student at a time. The counter column on queue_tickets is
//  the authoritative window assignment for any 'serving' ticket.
// ============================================================

// Number of service windows. Every write clamps to 1..QUEUE_MAX_WINDOWS.
if (!defined('QUEUE_MAX_WINDOWS')) {
    define('QUEUE_MAX_WINDOWS', 3);
}

// Ticket statuses that count as "in the queue right now" — a student
// holding any of these must not be issued a second number. Used for the
// duplicate guard and for the "who is where" live map.
if (!defined('QUEUE_LIVE_STATUSES_SQL')) {
    define('QUEUE_LIVE_STATUSES_SQL', "'waiting','serving'");
}

// padNumber is also defined locally in api/queue.php and api/queue-public.php
// (both are standalone entry points that may be required without the other).
// Guarded so including this file from either one is safe.
if (!function_exists('padNumber')) {
    function padNumber(int $n): string {
        return str_pad((string)$n, 3, '0', STR_PAD_LEFT);
    }
}

// Clamp any incoming window number into a valid 1..N range.
function normalizeWindow($raw): int {
    return max(1, min(QUEUE_MAX_WINDOWS, (int) $raw));
}

// Build the per-window serving map for a date. Returns
// [1 => ['ticket'=>[...], 'called_at'=>...], 2 => null, 3 => null] —
// a fixed-size array indexed by window so the monitor can render a
// stable row of slots (empty ones included) instead of a variable list.
function buildWindowMap($db, string $today): array {
    $rows = $db->fetchAll(
        "SELECT id, ticket_number, student_name, student_number, course,
                counter, called_at
         FROM queue_tickets
         WHERE queue_date = ? AND status = 'serving'",
        [$today]
    );

    $map = [];
    for ($w = 1; $w <= QUEUE_MAX_WINDOWS; $w++) {
        $map[$w] = null;
    }
    foreach ($rows as $r) {
        $w = normalizeWindow($r['counter']);
        $map[$w] = [
            'ticket_id'      => (int) $r['id'],
            'ticket_number'  => (int) $r['ticket_number'],
            'display_number' => padNumber((int) $r['ticket_number']),
            'student_name'   => $r['student_name'],
            'student_number' => $r['student_number'],
            'course'         => $r['course'],
            'counter'        => $w,
            'called_at'      => $r['called_at'],
        ];
    }
    return $map;
}

// Shape a window map for JSON: an ordered list of window slots, each with
// its number, name, and whether it is occupied. `serving` keeps a single
// top-level summary (lowest occupied window) for legacy single-slot
// consumers like the kiosk board tile.
function windowMapToPayload(array $map): array {
    $slots = [];
    $primary = null;
    for ($w = 1; $w <= QUEUE_MAX_WINDOWS; $w++) {
        $s = $map[$w] ?? null;
        $slots[] = [
            'window'  => $w,
            'serving' => $s,
        ];
        if ($s !== null && $primary === null) {
            $primary = ['number' => $s['display_number'], 'name' => $s['student_name'], 'counter' => $w];
        }
    }
    return ['windows' => $slots, 'serving' => $primary];
}