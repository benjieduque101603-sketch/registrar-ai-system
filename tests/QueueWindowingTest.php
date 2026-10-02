<?php

use PHPUnit\Framework\TestCase;

/**
 * Windowed queue: the duplicate-number guard and per-window serving slots.
 *
 * Two bugs motivated this file, both observed live:
 *
 *  1. A student could hold two simultaneous numbers. The join guard was a
 *     5-minute TIME check, so once the cooldown lapsed a student still
 *     standing in line was issued a second ticket (Roldan Tiu ended up
 *     holding #3 and #6 at once). The invariant that actually matters is
 *     the ticket's STATUS, not its age.
 *
 *  2. Calling from a second window silently closed out the student the
 *     first window was still serving, because call_next auto-completed the
 *     globally-newest serving ticket instead of the one at the requested
 *     window. The `counter` column was written but never read back.
 */
final class QueueWindowingTest extends TestCase
{
    private const PUBLIC_API = __DIR__ . '/../api/queue-public.php';
    private const AUTH_API   = __DIR__ . '/../api/queue.php';
    private const HELPERS    = __DIR__ . '/../shared/queue_helpers.php';
    private const MONITOR    = __DIR__ . '/../queue/monitor.php';
    private const CONSOLE_JS = __DIR__ . '/../js/queue.js';

    private static function src(string $path): string
    {
        return file_get_contents($path);
    }

    // ── Duplicate-number guard ────────────────────────────────

    public function testJoinBlocksOnLiveTicketStatusNotElapsedTime(): void
    {
        $src = self::src(self::PUBLIC_API);

        // The live-ticket lookup must filter on status, not on joined_at age.
        self::assertMatchesRegularExpression(
            "/status IN \('waiting','serving'\)/",
            $src,
            'Join must look for an existing waiting/serving ticket.'
        );

        // The old 300-second guard is the bug. It must be gone.
        self::assertDoesNotMatchRegularExpression(
            '/strtotime\(\$existing\[.joined_at.\]\)\s*<\s*300/',
            $src,
            'The 5-minute TIME cooldown must not gate re-queueing any more.'
        );
    }

    public function testJoinRejectsASecondNumberAndReturnsTheExistingOne(): void
    {
        $src = self::src(self::PUBLIC_API);

        self::assertStringContainsString('if ($live) {', $src,
            'Join must bail out when a live ticket already exists.');
        self::assertStringContainsString("'already_queued'", $src);
        self::assertStringContainsString("'now_serving'", $src);
    }

    public function testNewTicketIsNotPinnedToWindowOne(): void
    {
        // A waiting ticket has not been called to any desk yet. Hard-coding
        // counter=1 made every waiting row look like it belonged to Window 1.
        self::assertMatchesRegularExpression(
            "/'status'\s*=>\s*'waiting',\s*\r?\n\s*'counter'\s*=>\s*0,/",
            self::src(self::PUBLIC_API),
            'A newly joined waiting ticket must have counter 0, not 1.'
        );
    }

    // ── Per-window serving ────────────────────────────────────

    public function testCallNextIsScopedToTheRequestedWindow(): void
    {
        // Without the counter filter, calling at Window 2 completes whoever
        // Window 1 was serving.
        self::assertMatchesRegularExpression(
            "/status = 'serving' AND counter = \?/",
            self::src(self::AUTH_API),
            'call_next must only consider the ticket at the requested window.'
        );
    }

    public function testCallNextRefusesInsteadOfAutoCompletingAnotherWindow(): void
    {
        $src = self::src(self::AUTH_API);

        self::assertStringContainsString("'window_busy'", $src);
        // The old destructive behaviour must be gone entirely.
        self::assertDoesNotMatchRegularExpression(
            '/queue_auto_complete/',
            $src,
            'No window may auto-complete a ticket belonging to another window.'
        );
    }

    public function testStateIsScopedToTheRegistrarsOwnWindow(): void
    {
        $src = self::src(self::AUTH_API);
        self::assertStringContainsString('$windowMap[$myWindow]', $src);
        self::assertStringContainsString("'my_window'", $src);
    }

    public function testNoEndpointStillReadsTheGlobalNewestServingTicket(): void
    {
        // This exact query is what made every caller see one shared slot.
        foreach ([self::PUBLIC_API, self::AUTH_API] as $file) {
            self::assertDoesNotMatchRegularExpression(
                "/status = 'serving'\s*\r?\n\s*ORDER BY id DESC LIMIT 1/",
                self::src($file),
                basename($file) . ' still resolves "now serving" to one global ticket.'
            );
        }
    }

    // ── Open hours, cut-off, and tap caps ─────────────────────

    public function testDefaultsMatchTheAgreedOfficeHours(): void
    {
        $src = self::src(self::HELPERS);
        self::assertStringContainsString("'opens_time'        => '08:00:00'", $src);
        self::assertStringContainsString("'closes_time'       => '17:00:00'", $src);
        // 0 = unlimited, so a fresh install behaves as it did before.
        self::assertStringContainsString("'max_taps_student'  => 0", $src);
        self::assertStringContainsString("'max_taps_priority' => 0", $src);
    }

    public function testReadingDaySettingsNeverWritesARow(): void
    {
        // The kiosk polls this every few seconds. A read that inserted the
        // missing row would grow the table for no reason, so only an
        // explicit save writes.
        $src = self::src(self::HELPERS);
        $body = self::actionBlock($src, 'function queueDaySettings');
        self::assertDoesNotMatchRegularExpression(
            '/INSERT/i',
            $body,
            'queueDaySettings must stay read-only.'
        );
        self::assertStringContainsString('catch (Throwable', $body,
            'A missing table must degrade to defaults, not 500.');
    }

    public function testClosedStateDistinguishesBeforeOpenAfterCloseAndForced(): void
    {
        $src = self::src(self::HELPERS);
        // Three closed states, three different sentences to a student at
        // a kiosk. Collapsing them into one "closed" makes people come
        // back at the wrong time.
        foreach (['before_open', 'after_close', 'forced'] as $reason) {
            self::assertStringContainsString("'" . $reason . "'", $src);
        }
    }

    public function testJoinRefusesWhenTheQueueIsClosed(): void
    {
        $src = self::src(self::PUBLIC_API);
        self::assertStringContainsString("'queue_closed'", $src);
        self::assertStringContainsString('queueIsClosed(', $src);
        self::assertLessThan(
            strpos($src, 'lookupCardByUid'),
            strpos($src, 'queueIsClosed('),
            'The closed check must run before the card lookup.'
        );
    }

    /**
     * The Tap Card tab used to walk the kiosk straight out of the closed
     * sign and back onto the tap prompt. Observed live: the poll had put the
     * sign up, one press of "Tap Card" dismissed it, and the student was then
     * invited to tap a card that beginTap() refuses — so the tap was made and
     * then bounced in front of whoever was watching.
     *
     * Source-level, because the bug is a missing guard rather than a wrong
     * value: the tab handler has to consult queueClosed before showing the
     * tap screen, and it has to re-raise the sign rather than just return.
     */
    public function testTapTabCannotDismissTheClosedSign(): void
    {
        $src = self::src(self::CONSOLE_JS);

        // The tab handler must check the closed state...
        self::assertMatchesRegularExpression(
            "/dataset\.tab === 'tap'\)\s*\{\s*if \(queueClosed\)/",
            $src,
            'The Tap Card tab must check queueClosed before showing the tap screen.'
        );

        // ...and must re-raise the sign, not silently decline, so the student
        // gets an answer instead of a dead press.
        self::assertMatchesRegularExpression(
            "/if \(queueClosed\)\s*\{\s*showClosed\(/",
            $src,
            'Pressing Tap Card while closed must re-raise the closed sign.'
        );

        // The other two tabs must stay reachable: reading the board and
        // checking a held number both still work with no numbers being issued.
        self::assertStringContainsString("b.dataset.tab === 'board') show('board')", $src,
            'The board must stay reachable while the queue is closed.');
        self::assertStringContainsString("b.dataset.tab === 'standing') show('standing')", $src,
            'Check-my-number must stay reachable while the queue is closed.');

        // A sign raised by the poll carries no server-side sentence, so the
        // kiosk must build one from the reason. Without this it showed the
        // generic "closed right now" for "has not opened yet", which is the
        // one case that most needs to say when to come back.
        self::assertStringContainsString('closedMessageFor(', $src,
            'The kiosk must derive a closed message from the reason and time.');
        foreach (['before_open', 'after_close', 'forced'] as $reason) {
            self::assertMatchesRegularExpression(
                '/' . $reason . '/',
                $src,
                'A specific sentence is required for the "' . $reason . '" state.'
            );
        }
    }

    public function testTapCapIsCheckedInsideTheTransaction(): void
    {
        $src = self::src(self::PUBLIC_API);
        self::assertStringContainsString('queueTapLimitReached(', $src);
        // "Inside the transaction" means it runs AFTER beginTransaction
        // (so two rapid taps cannot both read a stale count) and BEFORE
        // the row is written (so an over-limit tap gets no number).
        self::assertGreaterThan(
            strpos($src, '$db->beginTransaction()'),
            strpos($src, 'queueTapLimitReached('),
            'The tap cap must be enforced inside the join transaction.'
        );
        self::assertLessThan(
            strpos($src, "\$db->insert('queue_tickets'"),
            strpos($src, 'queueTapLimitReached('),
            'The tap cap must be checked before a number is issued.'
        );
    }

    public function testTapsTodayCountsEveryStatusNotJustLiveOnes(): void
    {
        // The cap is taps per DAY. Counting only waiting/serving would let
        // a served student tap again immediately.
        self::assertMatchesRegularExpression(
            '/SELECT COUNT\(\*\) FROM queue_tickets WHERE queue_date = \? AND student_id = \?/',
            self::src(self::HELPERS)
        );
    }

    // ── Cut-off controls ─────────────────────────────────────

    public function testConsoleExposesCutOffAndReopen(): void
    {
        $src = self::src(self::AUTH_API);
        self::assertStringContainsString("'set_cutoff'", $src);
        self::assertStringContainsString("'clear_cutoff'", $src);
        // An upsert: pressing CUT OFF on a day nobody configured must work.
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $src);
    }

    public function testSavingTimesCannotSilentlyReopenAClosedQueue(): void
    {
        // The manual cut-off is its own action. Saving hours must not clear
        // it, or a registrar re-saving the times reopens the queue.
        $src = self::src(self::AUTH_API);
        $block = self::actionBlock($src, "if (\$action === 'save_day_settings')");
        self::assertStringContainsString('cutoff_forced_at', $block);
        self::assertStringContainsString('NULL,NULL', $block,
            'save_day_settings must not carry the existing forced cut-off.');
        self::assertStringNotContainsString('cutoff_forced_at = NULL', $block,
            'Saving hours must never clear the manual cut-off.');
    }

    public function testHelperDefinesFourWindows(): void
    {
        // Was 3. The single shared line became four desks:
        // service/claim x student/priority. Bumping the constant without
        // updating this assertion would leave the test green on a system
        // that still rendered three slots, so it is pinned deliberately.
        self::assertStringContainsString("define('QUEUE_MAX_WINDOWS', 4)", self::src(self::HELPERS));
        self::assertStringNotContainsString("define('QUEUE_MAX_WINDOWS', 3)", self::src(self::HELPERS));
    }

    // ── Lane split ─────────────────────────────────────────────

    public function testEveryWindowServesExactlyOneLane(): void
    {
        $src = self::src(self::HELPERS);
        // The four desks, in order. A duplicate or missing pair would
        // silently merge two queues into one, which is the whole bug
        // this split exists to fix.
        foreach ([
            "1 => ['txn_type' => 'service', 'priority_group' => 'priority'",
            "2 => ['txn_type' => 'service', 'priority_group' => 'student'",
            "3 => ['txn_type' => 'claim',   'priority_group' => 'priority'",
            "4 => ['txn_type' => 'claim',   'priority_group' => 'student'",
        ] as $row) {
            self::assertStringContainsString($row, $src, 'Missing or reordered window lane: ' . $row);
        }
    }

    public function testCallNextOnlyDrawsFromItsOwnLane(): void
    {
        $src = self::src(self::AUTH_API);
        // Without these two filters, Window 2 (Service - Student) pulls
        // the oldest ticket off the whole day and can call a priority
        // student who belongs at Window 1.
        self::assertMatchesRegularExpression(
            "/status = 'waiting'\s*\r?\n\s*AND txn_type = \? AND priority_group = \?/",
            $src,
            'call_next must filter waiting tickets to the window\'s own lane.'
        );
    }

    public function testSkipAutoAdvanceStaysInTheSkippedTicketsLane(): void
    {
        $src = self::src(self::AUTH_API);
        // The skip path auto-calls the next ticket. It has to follow the
        // skipped ticket's lane, not the globally-oldest waiting number.
        self::assertStringContainsString('$skippedLane', $src);
        self::assertStringContainsString(
            "AND txn_type = ? AND priority_group = ?",
            $src,
            'The skip auto-advance must also be lane-scoped.'
        );
    }

    public function testJoinRejectsALaneThatIsNotADesk(): void
    {
        $src = self::src(self::PUBLIC_API);
        self::assertStringContainsString("'bad_lane'", $src);
        self::assertStringContainsString('queueIsValidLane(', $src);
        // Validated before the card, so a bad lane is never reported as a
        // card problem and nothing is written.
        self::assertLessThan(
            strpos($src, 'lookupCardByUid'),
            strpos($src, 'queueIsValidLane('),
            'The lane check must run before the card lookup.'
        );
    }

    public function testJoinWritesTheLaneOntoTheTicket(): void
    {
        $src = self::src(self::PUBLIC_API);
        self::assertStringContainsString("'txn_type'       => \$txnType", $src);
        self::assertStringContainsString("'priority_group' => \$priorityGroup", $src);
    }

    public function testPositionIsRankedWithinTheLane(): void
    {
        $src = self::src(self::PUBLIC_API);
        // "You are #3" must mean #3 among people waiting for the same
        // desk. Ranking across all four lines told a priority student
        // they were behind someone they would never be called behind.
        self::assertMatchesRegularExpression(
            "/status = 'waiting'\s*\r?\n\s*AND txn_type = \? AND priority_group = \?/",
            $src
        );
    }

    public function testReopenDoesNotExtendPastTheClosingTime(): void
    {
        // Reopening at 6 PM would invent an office-hours window nobody
        // agreed to. clear_cutoff returns the day to the CLOCK rule only.
        $src = self::src(self::AUTH_API);
        $block = self::actionBlock($src, "if (\$action === 'clear_cutoff')");
        self::assertStringContainsString('cutoff_forced_at = NULL', $block);
        self::assertStringContainsString('closes_time', $block,
            'Reopen must still respect the closing time.');
        self::assertStringNotContainsString('closes_time = VALUES', $block,
            'Reopen must not extend the closing time.');
    }

    public function testAnInvertedWindowIsRefusedRatherThanSaved(): void
    {
        // A closing time at or before the opening time would close the
        // queue all day while looking correctly configured.
        self::assertMatchesRegularExpression(
            '/strtotime\(\$closes\) <= strtotime\(\$opens\)/',
            self::src(self::AUTH_API)
        );
    }

    // ── History date filter ───────────────────────────────────

    public function testHistoryIsDateSelectable(): void
    {
        $src = self::src(self::AUTH_API);
        self::assertStringContainsString('function queueHistoryDate(', $src);
        self::assertStringContainsString("queueHistoryDate(\$_GET['date'] ?? \$today)", $src);
        self::assertStringContainsString('[$historyDate]', $src,
            'The finished-ticket query must be date-scoped.');
    }

    public function testHistoryDateRejectsRubbishAndTheFuture(): void
    {
        $fn = self::actionBlock(self::src(self::AUTH_API), 'function queueHistoryDate(');
        self::assertStringContainsString('checkdate(', $fn);
        self::assertStringContainsString('$raw > $today', $fn,
            'A future date renders an empty table that reads as data loss.');
    }

    /**
     * The body of one action branch or function.
     *
     * Cut at the next top-level `if ($action ===` / `function `, NOT at
     * "\nexit;" — `exit;` is indented inside these blocks, so an anchor on
     * it silently yields an empty string and every assertion below it then
     * fails for the wrong reason.
     */
    private static function actionBlock(string $src, string $needle): string
    {
        $i = strpos($src, $needle);
        self::assertNotFalse($i, 'Anchor not found: ' . $needle);
        $rest = substr($src, $i);
        $cut = strlen($rest);
        if (preg_match('/\n(?:if \(\$action|function )\s/', $rest, $m, PREG_OFFSET_CAPTURE)) {
            $cut = $m[0][1];
        }
        return substr($rest, 0, $cut);
    }

    public function testWindowMapAlwaysRendersAFixedNumberOfSlots(): void
    {
        // The monitor needs a stable row of slots; a variable list would make
        // the grid jump as desks open and close.
        self::assertMatchesRegularExpression(
            '/for \(\$w = 1; \$w <= QUEUE_MAX_WINDOWS; \$\w\+\+\) \{\s*\r?\n\s*\$map\[\$w\] = null;/',
            self::src(self::HELPERS),
            'buildWindowMap must pre-fill every window slot with null.'
        );
    }

    public function testPadNumberIsNotRedeclared(): void
    {
        // api/queue.php and api/queue-public.php each define padNumber
        // locally; the helper is included by both, so it must guard.
        self::assertStringContainsString("if (!function_exists('padNumber'))", self::src(self::HELPERS));
    }

    public function testBothApisIncludeTheWindowHelper(): void
    {
        foreach ([self::PUBLIC_API, self::AUTH_API] as $file) {
            self::assertStringContainsString(
                "require_once __DIR__ . '/../shared/queue_helpers.php';",
                self::src($file),
                basename($file) . ' does not load shared/queue_helpers.php.'
            );
        }
    }

    // ── Monitor + console surfaces ────────────────────────────

    public function testMonitorRendersAWindowStripNotASingleHero(): void
    {
        $monitor = self::src(self::MONITOR);
        self::assertStringContainsString('id="windowStrip"', $monitor);
        // The single-hero markup is what hid the other desks.
        self::assertStringNotContainsString('id="heroNumber"', $monitor);
    }

    public function testMonitorAnimatesEachWindowIndependently(): void
    {
        // A single shared key meant one desk calling flashed the whole board.
        self::assertStringContainsString('lastServing[slot.window]', self::src(self::CONSOLE_JS));
    }

    public function testConsolePollIsScopedToTheSelectedWindow(): void
    {
        self::assertStringContainsString("'?action=state&window=' + w", self::src(self::CONSOLE_JS));
    }

    /**
     * buildWindowMap emits display_number / student_name, but the board
     * template below hands windowMapToPayload a per-window entry verbatim.
     * The monitor and kiosk originally read `s.number` / `s.name`, which are
     * undefined there — the data was present and the board rendered three
     * blank windows. Pin the canonical keys both clients must read.
     */
    public function testWindowSlotsAreRenderedWithTheKeysTheMapActuallyEmits(): void
    {
        $js = self::src(self::CONSOLE_JS);

        self::assertStringContainsString('esc(s.display_number)', $js,
            'Window slots must read display_number from the map.');
        self::assertStringContainsString('esc(s.student_name)', $js,
            'Window slots must read student_name from the map.');

        self::assertDoesNotMatchRegularExpression(
            '/esc\(s\.number\)|esc\(s\.name\)/',
            $js,
            'Window slots still read the short keys, which the per-window map never sets.'
        );
    }
}
