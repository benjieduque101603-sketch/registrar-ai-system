<?php

if (defined('STUDENT_QUALITY_LOADED')) {
    return;
}
define('STUDENT_QUALITY_LOADED', true);

require_once __DIR__ . '/config.php';
function studentQualityValidEmail(string $email): bool
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function studentQualityNormalizePhone(string $phone): string
{
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (strpos($digits, '63') === 0 && strlen($digits) === 12) {
        $digits = '0' . substr($digits, 2);
    }
    if (preg_match('/^9\d{9}$/', $digits)) {
        $digits = '0' . $digits;
    }
    if (!preg_match('/^09\d{9}$/', $digits)) {
        return trim($phone);
    }
    return substr($digits, 0, 4) . '-' . substr($digits, 4, 3) . '-' . substr($digits, 7, 4);
}

function studentQualityScoreValue(array $student): int
{
    $weights = [
        'student_number' => 10, 'first_name' => 10, 'last_name' => 10,
        'address' => 8, 'gender' => 8, 'birth_date' => 10, 'course' => 12,
        'contact_number' => 8, 'email' => 6, 'nationality' => 4,
        'section' => 5, 'school_year' => 5,
        'year_level' => 3, 'semester' => 3,
    ];
    $score = 100;
    foreach ($weights as $field => $points) {
        if (trim((string)($student[$field] ?? '')) === '') {
            $score -= $points;
        }
    }
    $email = trim((string)($student['email'] ?? ''));
    if ($email !== '' && !studentQualityValidEmail($email)) $score -= 3;
    $phone = preg_replace('/[^0-9]/', '', (string)($student['contact_number'] ?? ''));
    if ($phone !== '' && !preg_match('/^(?:09\d{9}|9\d{9})$/', $phone)) $score -= 3;
    $birthDate = trim((string)($student['birth_date'] ?? ''));
    if ($birthDate !== '' && strtotime($birthDate) > time()) $score -= 5;
    return max(0, min(100, $score));
}

require_once __DIR__ . '/database.php';

/**
 * Build the structured, deterministic data-quality contract used by the
 * Student page modal. AI may explain these findings, but it does not create them.
 */
require_once __DIR__ . '/normalize.php';

function studentQualityStandardizeCourse(string $course): string
{
    return function_exists('courseStandardize') ? courseStandardize($course) : $course;
}

function buildStudentQualityReport(array $student, ?callable $courseNormalizer = null): array
{
    $courseNormalizer = $courseNormalizer ?: 'studentQualityStandardizeCourse';
    $issues = [];
    $repairs = [];

    $addIssue = static function (
        string $field,
        string $category,
        string $severity,
        string $label,
        string $message,
        ?string $suggested = null,
        string $reason = ''
    ) use (&$issues, &$repairs, $student): void {
        $current = (string)($student[$field] ?? '');
        $issues[] = [
            'field' => $field,
            'category' => $category,
            'severity' => $severity,
            'label' => $label,
            'message' => $message,
            'current_value' => $current,
            'suggested_value' => $suggested,
        ];
        if ($suggested !== null && $suggested !== $current) {
            $repairs[] = [
                'field' => $field,
                'current_value' => $current,
                'suggested_value' => $suggested,
                'reason' => $reason,
            ];
        }
    };

    $required = [
        'student_number' => ['identity', 'Student number'],
        'first_name' => ['identity', 'First name'],
        'last_name' => ['identity', 'Last name'],
        'gender' => ['identity', 'Gender'],
        'birth_date' => ['identity', 'Birth date'],
        'nationality' => ['identity', 'Nationality'],
        'address' => ['contact', 'Address'],
        'contact_number' => ['contact', 'Contact number'],
        'email' => ['contact', 'Email'],
        'course' => ['academic', 'Course'],
        'year_level' => ['academic', 'Year level'],
        'section' => ['academic', 'Section'],
        'school_year' => ['academic', 'School year'],
        'semester' => ['academic', 'Semester'],
    ];
    foreach ($required as $field => [$category, $label]) {
        if (trim((string)($student[$field] ?? '')) === '') {
            $addIssue($field, $category, $field === 'student_number' ? 'high' : 'medium', $label, $label . ' is missing.');
        }
    }

    $email = trim((string)($student['email'] ?? ''));
    if ($email !== '' && !studentQualityValidEmail($email)) {
        $addIssue('email', 'contact', 'high', 'Email', 'Email address has an invalid format.');
    } else {
        $lowerEmail = strtolower($email);
        if ($email !== $lowerEmail) {
            $addIssue('email', 'contact', 'low', 'Email', 'Email uses inconsistent capitalization.', $lowerEmail, 'Stored in lowercase for consistency.');
        }
    }

    $phone = trim((string)($student['contact_number'] ?? ''));
    if ($phone !== '') {
        $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
        $isLocalMobile = preg_match('/^9\d{9}$/', $phoneDigits);
        $isStandardMobile = preg_match('/^09\d{9}$/', $phoneDigits);
        $normalizedPhone = studentQualityNormalizePhone($phone);
        if (!$isLocalMobile && !$isStandardMobile) {
            $addIssue('contact_number', 'contact', 'high', 'Contact number', 'Contact number is not a valid Philippine mobile number.');
        } elseif ($normalizedPhone !== $phone) {
            $addIssue('contact_number', 'contact', 'low', 'Contact number', 'Contact number can use the standard Philippine format.', $normalizedPhone, 'Normalized to 09XX-XXX-XXXX.');
        }
    }

    $birthDate = trim((string)($student['birth_date'] ?? ''));
    if ($birthDate !== '' && strtotime($birthDate) > time()) {
        $addIssue('birth_date', 'identity', 'high', 'Birth date', 'Birth date is in the future.');
    }

    $course = trim((string)($student['course'] ?? ''));
    if ($course !== '') {
        $standardCourse = trim((string)$courseNormalizer($course));
        if ($standardCourse !== '' && $standardCourse !== $course) {
            $addIssue('course', 'academic', 'medium', 'Course', 'Course does not match the official course name.', $standardCourse, 'Matched to the official course catalog.');
        }
    }

    $critical = count(array_filter($issues, static fn(array $issue): bool => $issue['severity'] === 'high'));
    $summary = empty($issues)
        ? 'No data-quality issues were found.'
        : sprintf('%d issue%s found, including %d high-priority item%s.', count($issues), count($issues) === 1 ? '' : 's', $critical, $critical === 1 ? '' : 's');

    return [
        'student_id' => (int)($student['id'] ?? 0),
        'score' => studentQualityScoreValue($student),
        'issues' => $issues,
        'safe_repairs' => $repairs,
        'summary' => $summary,
    ];
}

/**
 * Find possible duplicate students from an already-loaded collection.
 * Avoids N+1 reads while retaining the deterministic name/birth-date score.
 */
function studentQualityDuplicateCandidates(array $student, array $allStudents): array
{
    $currentId = (int)($student['id'] ?? 0);
    $first = strtolower(trim((string)($student['first_name'] ?? '')));
    $last = strtolower(trim((string)($student['last_name'] ?? '')));
    $birthDate = trim((string)($student['birth_date'] ?? ''));
    $studentNumber = trim((string)($student['student_number'] ?? ''));
    if (($first === '' || $last === '') && $studentNumber === '') return [];

    $matches = [];
    foreach ($allStudents as $candidate) {
        $candidateId = (int)($candidate['id'] ?? 0);
        if ($candidateId === $currentId) continue;
        $candidateFirst = strtolower(trim((string)($candidate['first_name'] ?? '')));
        $candidateLast = strtolower(trim((string)($candidate['last_name'] ?? '')));
        $candidateNumber = trim((string)($candidate['student_number'] ?? ''));
        $sameNumber = $studentNumber !== '' && hash_equals($studentNumber, $candidateNumber);
        $nameScore = 0.0;
        if ($first !== '' && $last !== '' && $candidateFirst !== '' && $candidateLast !== '') {
            $lastSimilarity = similar_text($last, $candidateLast) / max(strlen($last), strlen($candidateLast), 1);
            if ($lastSimilarity >= 0.85) {
                $firstSimilarity = similar_text($first, $candidateFirst) / max(strlen($first), strlen($candidateFirst), 1);
                $nameScore = ($lastSimilarity * 0.6) + ($firstSimilarity * 0.4);
            }
        }
        $candidateBirth = trim((string)($candidate['birth_date'] ?? ''));
        if ($birthDate !== '' && $candidateBirth !== '') {
            $nameScore = $birthDate === $candidateBirth ? min(1.0, $nameScore + 0.2) : $nameScore - 0.25;
        }
        $score = $sameNumber ? 1.0 : $nameScore;
        if ($score < 0.72) continue;
        $matches[] = [
            'id' => $candidateId,
            'name' => trim(($candidate['first_name'] ?? '') . ' ' . ($candidate['last_name'] ?? '')),
            'student_number' => $candidateNumber,
            'birth_date' => $candidateBirth,
            'score' => round($score, 2),
            'reason' => $sameNumber ? 'Same student number' : ($birthDate !== '' && $birthDate === $candidateBirth ? 'Similar name and matching birth date' : 'Similar name'),
        ];
    }
    usort($matches, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);
    return array_slice($matches, 0, 5);
}
