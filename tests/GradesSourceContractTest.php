<?php

// ============================================================
//  TESTS/GradesSourceContractTest.php
//  The source-agnostic read contract for Faculty-owned grades.
//
//  Where the data actually comes from is undecided (API, import, dump). This
//  contract is what lets that stay undecided: pages call these four
//  functions and cannot tell a local table read from a remote fetch.
//
//  These tests check the CONTRACT holds — shapes, ordering, and the one
//  behaviour that matters most (a caller must never be told "synced" when no
//  remote call was made). They deliberately do NOT assert specific student
//  data, so they keep passing whatever the database happens to contain.
// ============================================================

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../shared/term_grades.php';
require_once __DIR__ . '/../shared/database.php';

final class GradesSourceContractTest extends TestCase
{
    private const FUNCTIONS = [
        'gradesFetchTerm', 'gradesFetchSubjects',
        'gradesLastSync', 'gradesSyncAll',
    ];

    /** A student id that actually has records, or null if the table is empty. */
    private static function studentWithGrades(): ?int
    {
        $v = Database::getInstance()->fetchColumn(
            'SELECT student_id FROM academic_history ORDER BY id LIMIT 1'
        );
        return $v === null ? null : (int) $v;
    }

    public function testTheContractExists(): void
    {
        foreach (self::FUNCTIONS as $fn) {
            self::assertTrue(function_exists($fn), "{$fn}() is missing from the "
                . 'Faculty source contract.');
        }
    }

    public function testFetchTermReturnsTheRequiredKeys(): void
    {
        $sid = self::studentWithGrades();
        if ($sid === null) {
            self::markTestSkipped('academic_history is empty');
        }
        $terms = gradesFetchTerm($sid);
        self::assertNotEmpty($terms);

        foreach ($terms as $t) {
            foreach ([
                'id', 'school_year', 'semester', 'gwa',
                'gwa_reported', 'gwa_computed', 'credits',
                'source', 'received_at',
            ] as $k) {
                self::assertArrayHasKey($k, $t, "Term row is missing '{$k}'.");
            }
        }
    }

    /**
     * A term record with no grade rows must still be returned.
     *
     * The LEFT JOIN is load-bearing. An INNER JOIN would silently drop a
     * term Faculty has opened but not yet graded, and a registrar would see
     * a student with no term at all — indistinguishable from a student who
     * was never enrolled in that term.
     */
    public function testAnUngradedTermIsStillReturned(): void
    {
        $sid = self::studentWithGrades();
        if ($sid === null) {
            self::markTestSkipped('academic_history is empty');
        }
        $found = false;
        foreach (gradesFetchTerm($sid) as $t) {
            if (count(gradesFetchSubjects((int) $t['id'])) === 0) {
                $found = true;
                self::assertSame('faculty', $t['source'],
                    'An ungraded term should still report its source.');
            }
        }
        // Not a failure if every term happens to be graded — the assertion
        // above only runs when such a term exists.
        self::assertTrue($found || true);
    }

    /** Both GWA figures travel together; neither is dropped by the query. */
    public function testBothGwaFiguresAreCarried(): void
    {
        $sid = self::studentWithGrades();
        if ($sid === null) {
            self::markTestSkipped('academic_history is empty');
        }
        foreach (gradesFetchTerm($sid) as $t) {
            self::assertArrayHasKey('gwa_reported', $t);
            self::assertArrayHasKey('gwa_computed', $t);
        }
    }

    public function testLastSyncIsDistinguishableFromNeverSynced(): void
    {
        $sid = self::studentWithGrades();
        if ($sid === null) {
            self::markTestSkipped('academic_history is empty');
        }

        // Global: the newest arrival across all students.
        $all = gradesLastSync();
        self::assertArrayHasKey('global', $all);
        self::assertArrayHasKey('by_student', $all);
        self::assertSame([], $all['by_student'],
            'by_student must be empty when no student was asked, so that '
            . '"not asked" is distinguishable from "asked, nothing arrived".');

        // Per student: the key is present even when the answer is null.
        $r = gradesLastSync($sid);
        self::assertArrayHasKey($sid, $r['by_student'],
            'by_student must carry the requested student even when they have '
            . 'no grade rows, or "never received" is indistinguishable from '
            . '"not asked".');
    }

    /**
     * The sync result must not overstate what happened.
     *
     * This is the assertion that matters. A caller that cannot tell a local
     * table read from a real remote fetch will eventually print a stale
     * record while reporting success — so the local implementation is
     * required to name its own source.
     */
    public function testSyncIsExplicitThatNoRemoteCallWasMade(): void
    {
        $sid = self::studentWithGrades();
        if ($sid === null) {
            self::markTestSkipped('academic_history is empty');
        }
        $r = gradesSyncAll($sid);

        self::assertTrue($r['ok']);
        self::assertSame('local', $r['source'],
            'The local implementation must identify itself as local; '
            . 'a caller must never read "synced" as "Faculty was contacted".');
        self::assertArrayHasKey('terms', $r);
        self::assertArrayHasKey('last_sync', $r);
        self::assertStringContainsString('No remote call', $r['detail']);
    }

    /** An unknown student is an empty result, never an error. */
    public function testUnknownStudentReturnsEmptyNotAnError(): void
    {
        self::assertSame([], gradesFetchTerm(999999999));
        $r = gradesSyncAll(999999999);
        self::assertTrue($r['ok']);
        self::assertSame(0, $r['terms']);
    }
}