<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../shared/normalize.php';
require_once __DIR__ . '/../shared/student_quality.php';

final class StudentDataQualityTest extends TestCase
{
    /**
     * The weights must total 100, so a complete record can actually score 100.
     *
     * `section` was removed as a weight - Class Scheduling owns it, so a
     * Registrar could never fill it and every student was silently docked
     * points for a field outside this office. Its 5 points moved to `course`.
     *
     * A concurrent branch made the same removal but set course to 12, which
     * leaves the weights summing to 97: no student could ever reach 100, so
     * every quality dot on the Students page was permanently amber and nothing
     * in this suite noticed, because every existing assertion checks a record
     * that is deliberately incomplete. This pins the total instead.
     */
    public function testWeightsTotalOneHundred(): void
    {
        $src = file_get_contents(__DIR__ . '/../shared/student_quality.php');

        self::assertSame(
            1,
            preg_match('/\$weights\s*=\s*\[(.*?)\];/s', $src, $m),
            'Could not read the $weights table out of shared/student_quality.php.'
        );

        preg_match_all("/'(\w+)'\s*=>\s*(\d+)/", $m[1], $pairs, PREG_SET_ORDER);
        self::assertNotEmpty($pairs, 'The weights table parsed to nothing.');

        $total = array_sum(array_map(static fn($p) => (int) $p[2], $pairs));
        self::assertSame(
            100,
            $total,
            'The data-quality weights no longer total 100, so a complete record cannot score 100. '
            . 'Fields: ' . implode(', ', array_map(static fn($p) => $p[1] . '=' . $p[2], $pairs))
        );
    }

    public function testBuildsFieldSpecificIssuesAndSafeRepairs(): void
    {
        $student = [
            'id' => 42,
            'student_number' => '2026-01482',
            'first_name' => 'maria',
            'middle_name' => '',
            'last_name' => 'santos',
            'gender' => '',
            'birth_date' => '2090-01-01',
            'address' => 'Quezon City',
            'contact_number' => '9171234567',
            'email' => 'Maria.Santos@Example.COM',
            'course' => '',
            'year_level' => 2,
            'section' => '',
            'school_year' => '',
            'semester' => '',
            'status' => 'active',
        ];

        $report = buildStudentQualityReport(
            $student,
            fn(string $course): string => $course === 'BS Information Technology'
                ? 'Bachelor of Science in Information Technology (BSIT)'
                : $course
        );

        self::assertSame(42, $report['student_id']);
        self::assertLessThan(100, $report['score']);
        self::assertNotEmpty($report['issues']);
        self::assertContains('identity', array_column($report['issues'], 'category'));

        $repairFields = array_column($report['safe_repairs'], 'field');
        self::assertContains('contact_number', $repairFields);
        self::assertContains('email', $repairFields);
        self::assertNotContains('birth_date', $repairFields);
        self::assertNotContains('first_name', $repairFields);

        $phone = $report['safe_repairs'][array_search('contact_number', $repairFields, true)];
        self::assertSame('0917-123-4567', $phone['suggested_value']);
    }

    public function testUnknownCourseIsReviewOnly(): void
    {
        $student = [
            'id' => 44, 'student_number' => '2026-01484', 'first_name' => 'Leo',
            'last_name' => 'Cruz', 'gender' => 'Male', 'birth_date' => '2004-01-01',
            'nationality' => 'Filipino', 'address' => 'Quezon City', 'contact_number' => '09171234567',
            'email' => 'leo@example.com', 'course' => 'Unlisted Course', 'year_level' => 2,
            'section' => '21001', 'school_year' => '2026-2027', 'semester' => '2nd',
        ];

        $report = buildStudentQualityReport($student, fn(string $course): string => $course);

        self::assertNotContains('course', array_column($report['safe_repairs'], 'field'));
    }

    public function testMissingAcademicTermReducesScore(): void
    {
        $student = [
            'id' => 46, 'student_number' => '2026-01486', 'first_name' => 'Mia',
            'last_name' => 'Reyes', 'gender' => 'Female', 'birth_date' => '2005-01-01',
            'nationality' => 'Filipino', 'address' => 'Manila', 'contact_number' => '09171234567',
            'email' => 'mia@example.com', 'course' => 'BSIT', 'year_level' => 1,
            'section' => '11001', 'school_year' => '2026-2027', 'semester' => '',
        ];

        $report = buildStudentQualityReport($student, fn(string $course): string => $course);

        self::assertLessThan(100, $report['score']);
    }

    public function testDuplicateDetectionUsesLoadedStudentsAndFindsSameNumber(): void
    {
        $student = [
            'id' => 44, 'student_number' => '2026-01484', 'first_name' => 'Leo',
            'last_name' => 'Cruz', 'birth_date' => '2004-01-01',
        ];
        $duplicates = studentQualityDuplicateCandidates($student, [
            $student,
            ['id' => 45, 'student_number' => '2026-01484', 'first_name' => 'Other', 'last_name' => 'Name', 'birth_date' => '2006-01-01'],
        ]);

        self::assertCount(1, $duplicates);
        self::assertSame('Same student number', $duplicates[0]['reason']);
    }

    public function testCompleteCanonicalRecordScoresOneHundred(): void
    {
        $student = [
            'id' => 47, 'student_number' => '2026-01487', 'first_name' => 'Ana',
            'last_name' => 'Reyes', 'gender' => 'Female', 'birth_date' => '2005-01-01',
            'nationality' => 'Filipino', 'address' => 'Manila', 'contact_number' => '09171234567',
            'email' => 'ana@example.com', 'course' => 'BSIT', 'year_level' => 1,
            'section' => '11001', 'school_year' => '2026-2027', 'semester' => '1st',
        ];

        $report = buildStudentQualityReport($student, fn(string $course): string => $course);

        self::assertSame(100, $report['score']);
        self::assertCount(1, $report['issues']);
        self::assertSame('contact_number', $report['issues'][0]['field']);
        self::assertSame('low', $report['issues'][0]['severity']);
    }

    public function testInvalidEmailIsReviewOnly(): void
    {
        $student = [
            'id' => 43, 'student_number' => '2026-01483', 'first_name' => 'Ana',
            'last_name' => 'Reyes', 'gender' => 'Female', 'birth_date' => '2005-01-01',
            'nationality' => 'Filipino', 'address' => 'Manila', 'contact_number' => '09171234567',
            'email' => 'invalid-address', 'course' => 'BSIT', 'year_level' => 1,
            'section' => '11001', 'school_year' => '2026-2027', 'semester' => '1st',
        ];

        $report = buildStudentQualityReport($student, fn(string $course): string => $course);

        self::assertNotEmpty(array_filter($report['issues'], static fn(array $issue): bool => $issue['field'] === 'email'));
        self::assertNotContains('email', array_column($report['safe_repairs'], 'field'));
    }
}
