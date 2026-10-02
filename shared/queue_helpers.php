<?php
// ============================================================
//  SHARED/QUEUE_HELPERS.PHP
//  Windowed-queue helpers shared by the public kiosk/monitor
//  feeds (api/queue-public.php) and the staff console
//  (api/queue.php), so both agree on what a "window" is and
//  how the live serving map is read.
//
//  Model: FOUR windowed lanes. Each window owns one (txn_type,
//  priority_group) pair and serves at most one student at a time.
//  The counter column on queue_tickets is the authoritative window
//  assignment for any 'serving' ticket.
//
//  A single shared line was what this replaced. That made "now
//  serving" ambiguous with more than one desk open, and it meant a
//  priority student queued behind a regular one with nothing to
//  prioritise them — the whole point of the split.
// ============================================================

// Number of service windows. Every write clamps to 1..QUEUE_MAX_WINDOWS.
if (!defined('QUEUE_MAX_WINDOWS')) {
    define('QUEUE_MAX_WINDOWS', 4);
}

// Ticket statuses that count as "in the queue right now" — a student
// holding any of these must not be issued a second number. Used for the
// duplicate guard and for the "who is where" live map.
if (!defined('QUEUE_LIVE_STATUSES_SQL')) {
    define('QUEUE_LIVE_STATUSES_SQL', "'waiting','serving'");
}

// ── Lanes ────────────────────────────────────────────────────
// The window map is the contract between the kiosk (which asks the
// student to pick a lane) and the console (which window serves it).
// Both sides read this array, so the two can never disagree about
// which desk is which. Index is the window number; 1-based to match
// `counter`.
function queueWindows(): array {
    return [
        1 => ['txn_type' => 'service', 'priority_group' => 'priority', 'label' => 'Service · Priority'],
        2 => ['txn_type' => 'service', 'priority_group' => 'student',  'label' => 'Service · Student'],
        3 => ['txn_type' => 'claim',   'priority_group' => 'priority', 'label' => 'Claim · Priority'],
        4 => ['txn_type' => 'claim',   'priority_group' => 'student',  'label' => 'Claim · Student'],
    ];
}

// The lane a window serves. Unknown windows clamp to the last one, so
// a stale localStorage value or a hand-edited query string still lands
// on a real desk instead of nulling every comparison.
function queueWindowLane(int $window): array {
    $windows = queueWindows();
    $window = max(1, min(QUEUE_MAX_WINDOWS, $window));
    return $windows[$window];
}

// The window that serves a given lane, or null if the pair is not a
// desk. The kiosk uses this to tell the student where to go.
function queueWindowForLane(string $txnType, string $priorityGroup): ?int {
    foreach (queueWindows() as $n => $w) {
        if ($w['txn_type'] === $txnType && $w['priority_group'] === $priorityGroup) {
            return $n;
        }
    }
    return null;
}

// Is this a lane the system actually serves? Guards the public join
// endpoint against a hand-rolled POST claiming a desk that is not there.
function queueIsValidLane(string $txnType, string $priorityGroup): bool {
    return queueWindowForLane($txnType, $priorityGroup) !== null;
}

// Defaults for a day with no saved settings. Also the fallback when
// queue_day_settings does not exist yet on an un-migrated database, so
// a half-applied migration degrades to today's behaviour instead of 500ing
// every tap at the kiosk.
function queueDefaultDaySettings(): array {
    return [
        'opens_time'        => '08:00:00',
        'closes_time'       => '17:00:00',
        'cutoff_enabled'    => 1,
        'cutoff_forced_at'  => null,
        'cutoff_forced_by'  => null,
        'max_taps_student'  => 0,
        'max_taps_priority' => 0,
    ];
}

// The saved settings for one day, merged over the defaults.
//
// Deliberately READ-ONLY: it never inserts the missing row. A kiosk
// poll runs every few seconds, and a public endpoint that created a row
// on every read would make the table grow for no reason. Only an
// explicit save from the registrar console writes.
function queueDaySettings($db, string $date): array {
    $defaults = queueDefaultDaySettings();
    try {
        $row = $db->fetchOne("SELECT * FROM queue_day_settings WHERE queue_date = ?", [$date]);
    } catch (Throwable $e) {
        // Table missing (migration not applied yet). The queue still
        // works; it just runs on defaults until the migration lands.
        return $defaults;
    }
    if (!$row) {
        return $defaults;
    }
    return array_merge($defaults, $row);
}

// Is the queue taking numbers right now?
//
// Returns the boolean AND a reason, because "closed" is three
// different sentences to a student standing at a kiosk:
//   before_open  -> "the queue opens at 8:00 AM"
//   after_close  -> "the queue closes at 5:00 PM"
//   forced       -> "the queue is closed for today" (+ the cut-off time)
//
// Every comparison is PHP wall clock on PHP-written strings. CURDATE()
// and NOW() are never used: the MySQL session can sit at +00:00 while
// PHP is Asia/Manila, which put CURDATE() 8 hours behind the
// queue_date column (see brain/mysql-timezone-skew.md).
function queueIsClosed(array $settings, ?string $now = null): array {
    $now = $now ?? date('H:i:s');

    // A registrar pressing CUT OFF overrides the clock immediately.
    if (!empty($settings['cutoff_forced_at'])) {
        return [
            'closed' => true,
            'reason' => 'forced',
            'at'     => (string) $settings['cutoff_forced_at'],
        ];
    }

    // cutoff_enabled = 0 means the office hours themselves are switched
    // off for the day — an open-ended queue with no window.
    if (empty($settings['cutoff_enabled'])) {
        return ['closed' => false, 'reason' => '', 'at' => null];
    }

    $opens  = (string) ($settings['opens_time']  ?: '00:00:00');
    $closes = (string) ($settings['closes_time'] ?: '23:59:59');

    if (strtotime($now) < strtotime($opens)) {
        return ['closed' => true, 'reason' => 'before_open', 'at' => $opens];
    }
    if (strtotime($now) > strtotime($closes)) {
        return ['closed' => true, 'reason' => 'after_close', 'at' => $closes];
    }
    return ['closed' => false, 'reason' => '', 'at' => null];
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

// How many times this student has taken a number today. Counts every
// status, not just the live ones: the cap is about taps per DAY, so a
// student who was served and comes back has still used one of their taps.
function queueTapsToday($db, string $date, int $studentId): int {
    if ($studentId <= 0) {
        return 0;
    }
    return (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM queue_tickets WHERE queue_date = ? AND student_id = ?",
        [$date, $studentId]
    );
}

// The daily cap for a lane. 0 means unlimited.
function queueTapLimit(array $settings, string $priorityGroup): int {
    return $priorityGroup === 'priority'
        ? (int) ($settings['max_taps_priority'] ?? 0)
        : (int) ($settings['max_taps_student'] ?? 0);
}

// Convenience: has this student used up their allowance for this lane?
function queueTapLimitReached(array $settings, int $tapsUsed, string $priorityGroup): bool {
    $limit = queueTapLimit($settings, $priorityGroup);
    return $limit > 0 && $tapsUsed >= $limit;
}

// Build the per-window serving map for a date. Returns
// [1 => ['ticket'=>[...], 'called_at'=>...], 2 => null, ...] —
// a fixed-size array indexed by window so the monitor can render a
// stable row of slots (empty ones included) instead of a variable list.
function buildWindowMap($db, string $today): array {
    $rows = $db->fetchAll(
        "SELECT id, ticket_number, student_name, student_number, course,
                counter, called_at, txn_type, priority_group
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
            'txn_type'       => $r['txn_type'] ?? 'service',
            'priority_group' => $r['priority_group'] ?? 'student',
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
        $lane = queueWindowLane($w);
        $slots[] = [
            'window'         => $w,
            'label'          => $lane['label'],
            'txn_type'       => $lane['txn_type'],
            'priority_group' => $lane['priority_group'],
            'serving'        => $s,
        ];
        if ($s !== null && $primary === null) {
            $primary = ['number' => $s['display_number'], 'name' => $s['student_name'], 'counter' => $w];
        }
    }
    return ['windows' => $slots, 'serving' => $primary];
}