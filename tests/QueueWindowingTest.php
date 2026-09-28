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

    // ── Window map helper ─────────────────────────────────────

    public function testHelperDefinesThreeWindows(): void
    {
        self::assertStringContainsString("define('QUEUE_MAX_WINDOWS', 3)", self::src(self::HELPERS));
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
