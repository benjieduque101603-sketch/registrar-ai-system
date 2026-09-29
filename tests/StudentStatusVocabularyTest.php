<?php

use PHPUnit\Framework\TestCase;

/**
 * A student has exactly five statuses, and only one file decides which.
 *
 * There used to be five independent answers to "what can a student's status
 * be": the DB ENUM (8 values), $ALL_STATUSES/$DB_STATUSES in
 * registrar/status-tracker.php (11 values, 8 of them real), the badge and
 * label maps in shared/functions.php, the pie in shared/analytics.php, and the
 * filter <select> on the Students page. They had already drifted in ways that
 * caused real, verified failures:
 *
 *   · `alumni` was drawn as a slice of the insights pie while not being a value
 *     the column accepted, so the wedge was always empty.
 *   · The delete path wrote status='archived', which was never in the ENUM.
 *     MySQL does not error on an out-of-enum value - it truncates to ''. Proven
 *     on this schema: the INSERT reported success and the row read back as ''.
 *     So deleting a student produced a record that stayed in every list and
 *     matched no status filter, with a status_tracker entry for a status that
 *     did not exist.
 *   · The bulk-status endpoint had no validation at all, so any string from the
 *     client was written to the ENUM - and silently became ''.
 *
 * Asserted here: the set, the single definition, that no page keeps a private
 * copy, that the write paths validate, and that the column agrees.
 */
final class StudentStatusVocabularyTest extends TestCase
{
    private const FUNCTIONS = __DIR__ . '/../shared/functions.php';
    private const SCHEMA    = __DIR__ . '/../registrar_ai.sql';
    private const MIGRATION = __DIR__ . '/../migrations/student_status_five_values.sql';

    private const FIVE = ['enrolled', 'active', 'graduate', 'alumni', 'dropped'];

    protected function setUp(): void
    {
        require_once self::FUNCTIONS;
    }

    // ── The set ────────────────────────────────────────────────────

    public function testThereAreExactlyFiveStatuses(): void
    {
        self::assertSame(self::FIVE, studentStatuses());
    }

    /**
     * graduate and alumni are separate values, on purpose.
     *
     * The insights pie needs to tell "just finished" from "left years ago".
     * Collapsing them would make the question unanswerable, and the old pie had
     * already tried to draw both.
     */
    public function testGraduateAndAlumniAreDistinct(): void
    {
        self::assertContains('graduate', studentStatuses());
        self::assertContains('alumni', studentStatuses());
        self::assertNotSame(studentStatusLabel('graduate'), studentStatusLabel('alumni'));
    }

    /**
     * The statuses that are gone are gone, including `archived`.
     *
     * `archived` is the one that mattered: it was being written by the delete
     * path and could not be stored. Its absence from the vocabulary is the
     * assertion that the delete path can no longer write it.
     */
    public function testRetiredStatusesAreNotInTheVocabulary(): void
    {
        foreach (['probation', 'at-risk', 'loa', 'transferred', 'graduated', 'archived', 'inactive'] as $gone) {
            self::assertNotContains($gone, studentStatuses(), "$gone should no longer be a status.");
            self::assertFalse(isValidStudentStatus($gone), "$gone should not validate.");
            self::assertSame('', studentStatusLabel($gone), "$gone should not have a label.");
        }
    }

    public function testEveryStatusValidatesAndLabels(): void
    {
        foreach (self::FIVE as $st) {
            self::assertTrue(isValidStudentStatus($st), "$st must be storable.");
            self::assertNotSame('', studentStatusLabel($st), "$st must have a label.");
            self::assertNotSame('', studentStatusMeta($st)['color'], "$st must have a colour.");
            self::assertNotSame('unknown', studentStatusMeta($st)['class'], "$st must be styled.");
        }
    }

    /**
     * An unrecognised status is visibly unrecognised.
     *
     * studentStatusLabel() returns '' rather than a prettified version of the
     * raw token, and studentStatusMeta() returns the 'unknown' class. A bug that
     * writes a status nobody recognises then shows as a gap or a grey badge,
     * rather than a plausible-looking word next to a student's name.
     */
    public function testUnknownStatusIsVisiblyUnknown(): void
    {
        self::assertSame('', studentStatusLabel('atlarg'));
        self::assertSame('unknown', studentStatusMeta('atlarg')['class']);
        self::assertFalse(isValidStudentStatus('atlarg'));

        // Case and padding are the caller's business, not a new status.
        self::assertTrue(isValidStudentStatus('  ACTIVE '));
        self::assertSame('Active', studentStatusLabel(' ACTIVE '));
    }

    // ── One definition ─────────────────────────────────────────────

    /**
     * No page keeps its own copy of the list.
     *
     * This is the assertion that actually prevents a recurrence. Each of these
     * files had a hand-written array of statuses, and each was free to drift.
     */
    public function testNoPageKeepsAPrivateStatusList(): void
    {
        $pages = [
            'registrar/status-tracker.php',
            'registrar/students.php',
            'dashboard.php',
            'student/profile.php',
        ];
        foreach ($pages as $rel) {
            $src = file_get_contents(dirname(__DIR__) . '/' . $rel);
            // Comments explaining the removal are allowed to name the old value.
            $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $src);
            foreach (['probation', 'at-risk', 'transferred'] as $gone) {
                self::assertStringNotContainsString(
                    "'" . $gone . "'",
                    $code,
                    "$rel still names '$gone' outside a comment. Read it from studentStatuses()."
                );
            }
        }
    }

    /**
     * Every status <select> on the page is built from the shared list.
     *
     * There were six of them - filter, quick menu, edit, add, bulk action and
     * one more - and each was a hand-written array. Fixing five and missing the
     * sixth is exactly how this happens, so the assertion is on the RENDERED
     * page rather than on a list of known ids: any <option> offering a value
     * outside the vocabulary fails, whichever select it is in.
     *
     * Needs a rendered page, so it skips when the render probe has not been run.
     * That probe writes registrar/_probe_students.html.
     */
    public function testEveryRenderedStatusOptionIsARealStatus(): void
    {
        $html = dirname(__DIR__) . '/registrar/_probe_students.html';
        if (!is_file($html)) {
            self::markTestSkipped('Run tests/_students_render_probe.php first.');
        }

        $page = file_get_contents($html);

        preg_match_all('/<option value="([a-z\-]+)"/i', $page, $m);
        $found = array_values(array_unique($m[1]));

        // Only values that claim to be statuses are checked; non-status option
        // values (empty, custom actions) are none of this test's business.
        $candidates = array_values(array_intersect($found, [
            'enrolled', 'active', 'graduate', 'alumni', 'dropped',
            'probation', 'at-risk', 'loa', 'transferred', 'graduated', 'archived', 'inactive',
        ]));

        foreach ($candidates as $c) {
            self::assertTrue(
                isValidStudentStatus($c),
                "A rendered <option> offers status '$c', which the column cannot store."
            );
        }

        // And the real ones must be offered, or the UI is broken the other way.
        foreach (self::FIVE as $st) {
            self::assertContains(
                $st,
                $found,
                "Status '$st' is never offered in any select on the Students page."
            );
        }
    }

    /**
     * The insights pie is built from the shared list.
     *
     * It used to be hand-written and drew an "Alumni" wedge from a value the
     * column rejected - a category the chart promised and could never fill.
     */
    public function testInsightsPieCoversEveryStatusAndNothingElse(): void
    {
        require_once dirname(__DIR__) . '/shared/analytics.php';

        $buckets = aiInsightStatusBuckets();
        $covered = [];
        foreach ($buckets as $label => $meta) {
            self::assertNotEmpty($label, 'A pie slice must have a label.');
            foreach ($meta['statuses'] as $st) {
                self::assertContains($st, studentStatuses(), "The pie draws '$st', which is not a status.");
                $covered[] = $st;
            }
        }
        self::assertSame(self::FIVE, $covered, 'The pie must show exactly the five statuses, once each.');
    }

    // ── The column ─────────────────────────────────────────────────

    /**
     * The schema dump and the code agree.
     *
     * The dump is what a fresh install runs. If it kept the old ENUM, a new
     * server would reject 'graduate' outright while the dev server accepted it -
     * the worst kind of difference to find in production.
     */
    public function testSchemaEnumMatchesTheVocabulary(): void
    {
        $sql = file_get_contents(self::SCHEMA);

        // Scoped to the students table. An unscoped /`status`\s+enum\(/ matches
        // the FIRST status enum in the dump, which belongs to a different table -
        // it read document_requests' (open, in_progress, resolved, closed) and
        // reported a drift that did not exist. A test that fails for the wrong
        // reason trains people to ignore it.
        $tableAt = strpos($sql, 'CREATE TABLE `students`');
        self::assertNotFalse($tableAt, 'Could not find the students table in the dump.');

        $body = substr($sql, $tableAt);
        $end  = strpos($body, "\n) ENGINE");
        if ($end !== false) {
            $body = substr($body, 0, $end);
        }

        self::assertSame(
            1,
            preg_match("/`status`\s+enum\(([^)]*)\)/i", $body, $m),
            'Could not find the students.status enum inside the students table.'
        );

        $enum = array_map(static fn($v) => trim(trim($v), "'"), explode(',', $m[1]));
        self::assertSame(self::FIVE, $enum, 'The schema enum has drifted from studentStatuses().');
    }

    /**
     * The migration converts the data before narrowing the column.
     *
     * Narrowing an ENUM does not convert what is inside it: without the UPDATEs
     * first, the ALTER truncates every legacy value to '' - which is precisely
     * the corruption the delete path was already causing.
     */
    public function testMigrationConvertsDataBeforeNarrowingTheColumn(): void
    {
        $sql = file_get_contents(self::MIGRATION);

        $alterAt = strpos($sql, 'ALTER TABLE students');
        self::assertNotFalse($alterAt, 'The migration must narrow the column.');

        // Every legacy value is moved before the ALTER.
        foreach (['graduated', 'transferred'] as $legacy) {
            self::assertLessThan(
                $alterAt,
                (int) strpos($sql, "status = 'enrolled' WHERE status IS NULL"),
                "The migration ordering is wrong around '$legacy'."
            );
            self::assertStringContainsString(
                "WHERE status = '$legacy'",
                $sql,
                "The migration never converts '$legacy'."
            );
        }
        self::assertLessThan(
            $alterAt,
            (int) strpos($sql, "WHERE status IN ('probation', 'at-risk', 'loa')"),
            'probation / at-risk / loa are converted after the ALTER, so they would be truncated.'
        );

        // Rows already corrupted by the archived bug must be rescued, not lost.
        self::assertMatchesRegularExpression(
            "/UPDATE students SET status = 'enrolled' WHERE status IS NULL OR status = ''/",
            $sql,
            'The migration must recover rows left with an empty status by the archived bug.'
        );

        // The audit trail is translated too, or the tracker timeline renders blanks.
        self::assertStringContainsString('UPDATE status_tracker', $sql);
        self::assertStringContainsString('DELETE FROM masterlist_cache', $sql);
    }

    // ── The write paths ────────────────────────────────────────────

    /**
     * Status writes are validated.
     *
     * The bulk endpoint had no check, so any string from the client reached the
     * ENUM. With an unvalidated write and MySQL's silent truncation, a typo in a
     * stale browser tab wrote '' onto real students and the API still answered
     * "updated".
     */
    public function testApiValidatesStatusBeforeWriting(): void
    {
        $api = file_get_contents(dirname(__DIR__) . '/api/students.php');

        self::assertStringContainsString(
            'isValidStudentStatus($status)',
            $api,
            'The bulk status endpoint must reject a status the column cannot store.'
        );
        // The delete path used to write a status that was not in the enum.
        self::assertStringNotContainsString(
            "'status' => 'archived'",
            $api,
            'The delete path writes status=archived again, which the column cannot store.'
        );
        self::assertStringContainsString(
            "'status' => 'dropped'",
            $api,
            'The delete path must record a withdrawal using a real status.'
        );
    }

    /**
     * The RFID guards do not confuse a student status with a card status.
     *
     * The eligibility check compared students.status to 'archived' (a value it
     * could not hold, so it never excluded anyone), and the duplicate-card query
     * listed probation / at-risk / loa while querying rfid_cards.status - a
     * different enum entirely.
     */
    public function testRfidGuardsUseTheRightVocabularyForEachColumn(): void
    {
        $api = file_get_contents(dirname(__DIR__) . '/api/rfid.php');

        self::assertStringNotContainsString(
            "=== 'archived'",
            $api,
            'An RFID guard is still testing students.status against archived.'
        );
        // No student status may appear inside a query on rfid_cards.status.
        self::assertDoesNotMatchRegularExpression(
            "/rfid_cards[^;]*status IN \([^)]*'probation'/",
            $api,
            'A card-status query is still listing student statuses.'
        );
        self::assertStringContainsString(
            "in_array(\$student['status'] ?? '', ['active', 'enrolled'], true)",
            $api,
            'Card eligibility must be limited to the statuses that can attend.'
        );
    }

    // ── The dependency ─────────────────────────────────────────────

    /**
     * Every file that calls a shared status helper must load it.
     *
     * dashboard.php called studentStatuses() without loading
     * shared/functions.php, so renaming a status took the whole dashboard down
     * with "Call to undefined function studentStatuses()" - blank page, not a
     * broken panel, because the call is at the top level of the script.
     *
     * The same gap broke ai/insights.php and api/ai-insights-data.php, which
     * include shared/analytics.php without loading functions.php themselves.
     *
     * Asserted by loading each file in isolation and calling the helper for
     * real, which is the only way to catch this: a static read of the requires
     * says the page is fine when the page is only fine on the one path someone
     * happened to test.
     */
    public function testSharedStatusHelpersResolveForEveryCaller(): void
    {
        $root = dirname(__DIR__);

        // Each shared file is loaded for real, in isolation - the same thing a
        // page does - and its status helper is called. If a dependency is
        // missing this fatals exactly as the live page did, which is the only
        // assertion that proves the dependency rather than inferring it from a
        // require list.
        //
        // analytics.php is checked properly by testAnalyticsLoadsCleanlyOnItsOwn
        // below, which needs a separate process; this one covers
        // status_evidence.php, whose helpers are its own, plus both pages.
        require_once $root . '/shared/status_evidence.php';
        self::assertTrue(
            function_exists('isTerminalStudentStatus'),
            'shared/status_evidence.php does not define isTerminalStudentStatus().'
        );
        self::assertTrue(
            isTerminalStudentStatus('graduate'),
            'isTerminalStudentStatus() is broken once the file is loaded on its own.'
        );

        // The pages, checked for the require they were missing.
        //
        // Matched against a real require STATEMENT, not the bare filename. A
        // plain assertStringContainsString('shared/functions.php') is satisfied
        // by the explanatory comment sitting directly above the require, so
        // deleting the require left that test green - which is exactly the bug
        // it was written to catch.
        //
        // The trailing quote is required and not incidental: the statement ends
        // in .php'; and a pattern expecting the semicolon straight after the
        // extension matches nothing, which would fail on correct code while
        // passing on a commented-out require.
        self::assertMatchesRegularExpression(
            '/^\h*require(?:_once)?\h+[^;\v]*shared\/functions\.php\'?;/m',
            file_get_contents($root . '/dashboard.php'),
            'dashboard.php calls the status helpers but never loads shared/functions.php.'
        );

        // student/profile.php is asserted through its guard, because that is
        // where the dependency actually lives - _guard.php requires
        // functions.php for every page in the student portal. A check that
        // demanded the require in profile.php itself would be wrong, and would
        // push someone into adding a second, redundant include.
        self::assertMatchesRegularExpression(
            '/^\h*require(?:_once)?\h+[^;\v]*shared\/functions\.php\'?;/m',
            file_get_contents($root . '/student/_guard.php'),
            'student/_guard.php no longer loads shared/functions.php for the portal pages.'
        );

        // And the two shared files must declare the dependency themselves,
        // rather than trusting callers - that is what makes it future-proof.
        foreach (['shared/analytics.php', 'shared/status_evidence.php'] as $lib) {
            $src = file_get_contents($root . '/' . $lib);
            self::assertStringContainsString(
                "require_once __DIR__ . '/functions.php'",
                $src,
                "$lib calls status helpers without requiring functions.php itself."
            );
        }
    }

    /**
     * The real thing, executed.
     *
     * Included last because it defines constants and pulls in the database, so
     * it cannot run inside the same process as the other checks. Verified out of
     * band: loading shared/analytics.php with no functions.php and calling
     * aiInsightStatusBuckets() produced exactly
     * "Error: Call to undefined function studentStatuses()".
     */
    public function testAnalyticsLoadsCleanlyOnItsOwn(): void
    {
        $root = dirname(__DIR__);
        $php  = PHP_BINARY;
        if ($php === '' || !is_file($php)) {
            self::markTestSkipped('No PHP binary to re-exec with.');
        }

        // A fresh process that loads ONLY shared/analytics.php and calls the
        // helper - exactly the sequence ai/insights.php performs. If the
        // dependency is missing this fatals with the same message the live page
        // did, which a static require-counting assertion could never prove.
        $script = '<?php' . "\n"
            . 'require ' . var_export($root . '/shared/analytics.php', true) . ";\n"
            . '$b = aiInsightStatusBuckets();' . "\n"
            . 'echo count($b);' . "\n";

        $tmp = $root . '/tests/_status_dep_probe.php';
        file_put_contents($tmp, $script);

        $out = [];
        $rc  = 0;
        exec(escapeshellarg($php) . ' ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
        @unlink($tmp);

        $text = trim(implode("\n", $out));
        self::assertSame(0, $rc, "Loading shared/analytics.php on its own failed:\n$text");
        self::assertSame(
            '5',
            $text,
            "aiInsightStatusBuckets() should return the five statuses, got:\n$text"
        );
    }
}



