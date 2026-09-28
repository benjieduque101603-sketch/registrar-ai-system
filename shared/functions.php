<?php
// ============================================================
//  FUNCTIONS.PHP  (shared/)
//  Global helper functions used across the application.
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/section_code.php';

// ── Prevent direct access ──
if (defined('FUNCTIONS_LOADED')) {
    return;
}
define('FUNCTIONS_LOADED', true);

// ─── STRING HELPERS ────────────────────────────────────────────

/**
 * Truncate a string to a specified length
 */
function truncate($string, $length = 50, $suffix = '...') {
    if (strlen($string) <= $length) {
        return $string;
    }
    return substr($string, 0, $length) . $suffix;
}

/**
 * Generate a random string
 */
function randomString($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Generate a strong human-readable password (letters + digits).
 */
function generateStrongPassword($length = 10) {
    $lower = 'abcdefghjkmnpqrstuvwxyz';
    $upper = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    $digits = '23456789';
    $all = $lower . $upper . $digits;
    $chars = [];
    // Ensure at least one of each class, then fill randomly.
    $chars[] = $lower[random_int(0, strlen($lower) - 1)];
    $chars[] = $upper[random_int(0, strlen($upper) - 1)];
    $chars[] = $digits[random_int(0, strlen($digits) - 1)];
    for ($i = 3; $i < $length; $i++) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    shuffle($chars);
    return implode('', $chars);
}

/**
 * Generate a slug from a string
 */
function slugify($string) {
    $string = strtolower(trim($string));
    $string = preg_replace('/[^a-z0-9-]/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    return trim($string, '-');
}

/**
 * Format a phone number
 */
function formatPhoneNumber($number) {
    $number = preg_replace('/[^0-9]/', '', $number);
    if (strlen($number) === 11) {
        return substr($number, 0, 4) . '-' . substr($number, 4, 3) . '-' . substr($number, 7, 4);
    }
    return $number;
}

/**
 * Format a date
 */
function formatDate($date, $format = 'M d, Y') {
    if (!$date || $date === '0000-00-00') {
        return '—';
    }
    return date($format, strtotime($date));
}

/**
 * Format a time
 */
function formatTime($time, $format = 'h:i A') {
    if (!$time) {
        return '—';
    }
    return date($format, strtotime($time));
}

/**
 * Format a datetime
 */
function formatDateTime($datetime, $format = 'M d, Y h:i A') {
    if (!$datetime) {
        return '—';
    }
    return date($format, strtotime($datetime));
}

/**
 * Calculate days between two dates
 */
function daysBetween($date1, $date2 = null) {
    $date2 = $date2 ?? date('Y-m-d');
    $diff = strtotime($date2) - strtotime($date1);
    return floor($diff / (60 * 60 * 24));
}

// ─── VALIDATION HELPERS ────────────────────────────────────────

/**
 * Validate email
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (Philippines format)
 */
function isValidPhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', (string) $phone);
    // +63/63 prefix → drop the country code so we get a leading 09.
    if (strlen($phone) === 12 && strpos($phone, '63') === 0) {
        $phone = '0' . substr($phone, 2);
    }
    if (strlen($phone) === 13 && strpos($phone, '63') === 0) {
        $phone = '0' . substr($phone, 2);
    }
    // Exactly 11 digits: 09XXXXXXXXX.
    return preg_match('/^09\d{9}$/', $phone) === 1;
}

/**
 * Minimum password length (enforce strong passwords in production)
 */
if (!defined('PASSWORD_MIN_LENGTH')) {
    define('PASSWORD_MIN_LENGTH', (defined('APP_ENV') && APP_ENV === 'production') ? 12 : 8);
}

/**
 * Validate password strength. Returns array with 'valid' (bool) and 'message' (string).
 * Requirements:
 *   - Minimum length (8 chars in dev, 12 in production)
 *   - At least one uppercase letter
 *   - At least one lowercase letter
 *   - At least one digit
 */
function validatePassword($password) {
    $minLength = defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 8;
    $password = (string) $password;

    if (strlen($password) < $minLength) {
        return [
            'valid' => false,
            'message' => "Password must be at least {$minLength} characters."
        ];
    }

    if (!preg_match('/[A-Z]/', $password)) {
        return [
            'valid' => false,
            'message' => 'Password must contain at least one uppercase letter.'
        ];
    }

    if (!preg_match('/[a-z]/', $password)) {
        return [
            'valid' => false,
            'message' => 'Password must contain at least one lowercase letter.'
        ];
    }

    if (!preg_match('/[0-9]/', $password)) {
        return [
            'valid' => false,
            'message' => 'Password must contain at least one digit.'
        ];
    }

    return ['valid' => true, 'message' => 'Password is strong.'];
}

/**
 * Legacy compatibility function
 */
function isValidPassword($password) {
    $result = validatePassword($password);
    return $result['valid'];
}

// ─── FILE HELPERS ──────────────────────────────────────────────

/**
 * Get file extension
 */
function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Get file size formatted
 */
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Generate a unique filename
 */
function generateFilename($originalName) {
    $ext = getFileExtension($originalName);
    return date('Ymd_His') . '_' . randomString(8) . '.' . $ext;
}

/**
 * Check if file is allowed
 */
function isAllowedFile($filename) {
    $ext = getFileExtension($filename);
    return in_array($ext, ALLOWED_FILE_EXTENSIONS);
}

/**
 * Get file mime type
 */
function getFileMime($path) {
    return mime_content_type($path);
}

// ─── LOGGING HELPERS ───────────────────────────────────────────

/**
 * Log user activity
 */
/**
 * Log an activity to the audit_logs table.
 *
 * Schema (registrar_ai.sql): id, user_id, action, table_name, record_id,
 * old_values, new_values, ip_address, user_agent, created_at.
 *
 * @param int|null    $userId     Actor id (null → system)
 * @param string      $action     Action label, e.g. 'user_create', 'student_status'
 * @param string|null $details    Human-readable detail text (fallback)
 * @param string|null $tableName  Affected table, e.g. 'users', 'students'
 * @param int|null    $recordId   Affected record id
 * @param mixed       $oldValues  Prior row/values (stored as JSON in old_values)
 * @param mixed       $newValues  New row/values (stored as JSON in new_values)
 */
function logActivity($userId, $action, $details = null, $tableName = null, $recordId = null, $oldValues = null, $newValues = null) {
    try {
        $db = Database::getInstance();
        $data = [
            'user_id'    => $userId ? intval($userId) : 0,
            'action'     => $action,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        if ($tableName !== null) $data['table_name'] = $tableName;
        if ($recordId !== null)  $data['record_id']  = (int) $recordId;
        if ($oldValues !== null) $data['old_values'] = json_encode($oldValues);
        if ($newValues !== null) $data['new_values'] = json_encode($newValues);
        // If no structured values were passed, keep backward compatibility by
        // storing the human-readable detail into old_values (as text) so it is never lost.
        if ($oldValues === null && $newValues === null && $details !== null) {
            $data['old_values'] = json_encode(['details' => $details]);
        }
        $db->insert('audit_logs', $data);
    } catch (Exception $e) {
        // Silent fail - log to error log
        error_log('Failed to log activity: ' . $e->getMessage());
    }
}

/**
 * Human-readable label for a document_requests.document_type enum value.
 * Lives here because the same wording is needed by the registrar desk,
 * the student portal, and the notification text.
 *
 * @param string $type Raw enum value (form137, good_moral, ...)
 * @return string       Display label, or '' for an unknown value
 */
function documentTypeLabel(string $type): string {
    $map = [
        'form137'     => 'Form 137',
        'good_moral'  => 'Good Moral Certificate',
        'transcript'  => 'Transcript of Records',
        'certificate' => 'Certificate',
        'clearance'   => 'Clearance',
    ];
    return $map[$type] ?? '';
}

/**
 * Send a notification to a student's bell (student_notifications table).
 *
 * @param int    $studentId   Target student
 * @param string $title       Short title
 * @param string $message     Body text
 * @param string $type        'info', 'warning', 'success', 'error'
 * @param int|null $docId     Related document id
 * @param int|null $createdBy Actor (registrar/admin user id)
 * @return int|null           Inserted notification id
 */
function notifyStudent(int $studentId, string $title, string $message, string $type = 'info', ?int $docId = null, ?int $createdBy = null): ?int {
    try {
        $db = Database::getInstance();
        $id = $db->insert('student_notifications', [
            'student_id'     => $studentId,
            'title'          => $title,
            'message'        => $message,
            'type'           => $type,
            'is_read'        => 0,
            'related_doc_id' => $docId,
            'created_by'     => $createdBy,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        return (int) $id;
    } catch (Exception $e) {
        error_log('[notifyStudent] failed for student #' . $studentId . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * Log an error
 */
function logError($message) {
    $logFile = LOGS_PATH . 'error.log';
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND);
}

/**
 * Log an error
 */
/**
 * Log API request
 */
function logApiRequest($endpoint, $request, $response) {
    $logFile = LOGS_PATH . 'api.log';
    $entry = '[' . date('Y-m-d H:i:s') . '] ' .
             'Endpoint: ' . $endpoint . PHP_EOL .
             'Request: ' . json_encode($request) . PHP_EOL .
             'Response: ' . json_encode($response) . PHP_EOL .
             '---' . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND);
}

// ─── RESPONSE HELPERS ──────────────────────────────────────────

/**
 * Send JSON response
 */
function jsonResponse($success, $message = '', $data = null, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

/**
 * Send success response
 */
function successResponse($data = null, $message = 'Success') {
    jsonResponse(true, $message, $data);
}

/**
 * Send error response
 */
function errorResponse($message = 'Error', $code = 400) {
    jsonResponse(false, $message, null, $code);
}

// ─── SECURITY HELPERS ──────────────────────────────────────────

/**
 * Generate CSRF token
 */
if (!defined('CSRF_TOKEN_LENGTH')) {
    define('CSRF_TOKEN_LENGTH', 64);
}
function generateCsrfToken() {
    // Prefer the guard token so pages and APIs always share one token.
    if (function_exists('csrfToken')) {
        return csrfToken();
    }
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = randomString(CSRF_TOKEN_LENGTH);
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCsrfToken($token) {
    if (function_exists('csrfTokenValid')) {
        return csrfTokenValid($token);
    }
    $expected = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && $token !== '' && is_string($expected)
        && hash_equals($expected, $token);
}

/**
 * Sanitize input
 */
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Encrypt data
 */
function encryptData($data, $key = null) {
    $key = $key ?? JWT_SECRET;
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt data
 */
function decryptData($encrypted, $key = null) {
    $key = $key ?? JWT_SECRET;
    $data = base64_decode($encrypted);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
}

// ─── STUDENT HELPERS ────────────────────────────────────────────

/**
 * Generate the next student number, starting at 1 and counting up
 * sequentially: 1, 2, 3, ... Legacy 9-digit numbers (100000001, ...)
 * are skipped so a fresh series begins at 1 even if old records exist.
 */
function generateStudentNumber() {
    $db = Database::getInstance();
    $last = $db->fetchColumn(
        "SELECT student_number FROM students
         WHERE student_number REGEXP '^[0-9]+$'
           AND NOT (student_number REGEXP '^10000000[0-9]+$')
         ORDER BY CAST(student_number AS UNSIGNED) DESC LIMIT 1"
    );
    if ($last) {
        return (string) ((int) $last + 1);
    }
    return '1';
}

/**
 * Get student full name
 */
function getStudentFullName($student) {
    $parts = [];
    if (!empty($student['first_name'])) {
        $parts[] = $student['first_name'];
    }
    if (!empty($student['middle_name'])) {
        $parts[] = $student['middle_name'];
    }
    if (!empty($student['last_name'])) {
        $parts[] = $student['last_name'];
    }
    return implode(' ', $parts);
}

/**
 * Get student initials
 */
function getStudentInitials($student) {
    $name = getStudentFullName($student);
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper($word[0]);
        }
    }
    return substr($initials, 0, 2);
}

/**
 * Next section number for a course+year+semester, mirroring autoAssignStudentSections.
 * Scoped by course + year + semester only (the code has no SY digit), consistent with
 * auto-assign's bucket key. Manual codes that aren't 5-digit numeric are ignored.
 */
function nextSectionNumber(string $course, int $year, ?string $semester): int {
    $db = Database::getInstance();
    $prefix = substr(sectionCodeFromParts($year, $semester, 1), 0, 2); // e.g. "11"
    $rows = $db->fetchAll(
        "SELECT DISTINCT section FROM students
         WHERE TRIM(course) = ? AND year_level = ?
           AND TRIM(IFNULL(semester, '')) = ?
           AND section LIKE ? AND section REGEXP '^[0-9]{5}$'",
        [$course, $year, (string) $semester, $prefix . '%']
    );
    $max = 0;
    foreach ($rows as $r) {
        $num = (int) substr(trim((string) $r['section']), 2);
        if ($num > $max) $max = $num;
    }
    return $max + 1;
}

/**
 * True if a student already exists in this course+year+semester+section.
 * Same code across different courses is allowed (matches auto-assign).
 */
function sectionExists(string $course, int $year, ?string $semester, string $section): bool {
    $db = Database::getInstance();
    $found = $db->fetchOne(
        "SELECT id FROM students
         WHERE TRIM(course) = ? AND year_level = ?
           AND TRIM(IFNULL(semester, '')) = ? AND TRIM(section) = ?
         LIMIT 1",
        [$course, $year, (string) $semester, $section]
    );
    return (bool) $found;
}

/**
 * Offered BCP courses with their majors. Single source of truth shared by
 * the Students page and the Masterlist section-creation flow.
 */
function getOfferedCourses(): array {
    return [
        'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)' => [],
        'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)' => [],
        'BACHELOR OF SCIENCE IN ACCOUNTING INFORMATION SYSTEM (BSAIS)' => [],
        'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)' => [],
        'BACHELOR OF SCIENCE IN OFFICE ADMINISTRATION (BSOA)' => [],
        'BACHELOR OF SCIENCE IN ENTREPRENEURSHIP (BSENTREP)' => [],
        'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)' => [
            'Human Resource Management',
            'Marketing Management',
        ],
        'BACHELOR OF LIBRARY INFORMATION SCIENCE (BLIS)' => [],
        'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)' => [],
        'BACHELOR OF SCIENCE IN PSYCHOLOGY (BSP)' => [],
        'BACHELOR OF SCIENCE IN CRIMINOLOGY (BSCRIM)' => [],
        'BACHELOR OF SCIENCE IN PHYSICAL EDUCATION (BPED)' => [],
        'BACHELOR OF SCIENCE IN TECHNOLOGICAL & LIVELIHOOD EDUCATION (BTLED)' => [],
        'BACHELOR OF SCIENCE IN ELEMENTARY EDUCATION (BEED)' => [],
        'BACHELOR OF SCIENCE IN SECONDARY EDUCATION (BSED)' => [
            'English',
            'Social Studies',
            'Filipino',
            'Values Education',
            'Mathematics',
            'Science',
        ],
    ];
}

/**
 * Assign sections automatically: group by course + year + semester,
 * max N students per section. Section codes follow [year][sem][###],
 * e.g. 11001 (yr 1, sem 1, section 1), 12001 (yr 1, sem 2), 21001 (yr 2, sem 1).
 *
 * @return array{updated: int, skipped: int, sections: list<array{course: ?string, year_level: int, semester: ?string, section: string, count: int, max: int}>}
 */
function autoAssignStudentSections(?int $maxPerSection = null): array {
    $maxPerSection = $maxPerSection ?? (defined('MAX_STUDENTS_PER_SECTION') ? (int) MAX_STUDENTS_PER_SECTION : 50);
    if ($maxPerSection < 1) {
        $maxPerSection = 50;
    }

    $db = Database::getInstance();

    // A section code is derived from the year level, so students without one
    // cannot be placed. They are excluded here (rather than defaulted to
    // year 0, which would render a misleading Year-1 code) and reported back
    // as skipped so the registrar can see why they were left out.
    $skippedRow = $db->fetchOne(
        "SELECT COUNT(*) AS cnt FROM students
         WHERE course IS NOT NULL AND TRIM(course) != ''
           AND (year_level IS NULL OR TRIM(IFNULL(year_level, '')) = '')"
    );
    $skipped = (int) ($skippedRow['cnt'] ?? 0);

    $students = $db->fetchAll(
        "SELECT id, course, year_level, semester, section, last_name, first_name
         FROM students
         WHERE course IS NOT NULL AND TRIM(course) != ''
           AND year_level IS NOT NULL AND TRIM(IFNULL(year_level, '')) != ''
         ORDER BY TRIM(course) ASC, year_level ASC, last_name ASC, first_name ASC, id ASC"
    );

    $buckets = [];
    foreach ($students as $row) {
        $course = trim((string) $row['course']);
        $year = (int) $row['year_level'];
        $semester = ($row['semester'] ?? '') !== '' ? (string) $row['semester'] : null;
        $key = $course . "\0" . $year . "\0" . ($semester ?? '');
        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'course' => $course,
                'year_level' => $year,
                'semester' => $semester,
                'ids' => [],
            ];
        }
        $buckets[$key]['ids'][] = [
            'id' => (int) $row['id'],
            'section' => trim((string) ($row['section'] ?? '')),
        ];
    }

    $updated = 0;
    $sections = [];
    $conn = $db->getConnection();
    $conn->beginTransaction();

    try {
        foreach ($buckets as $bucket) {
            $course = $bucket['course'];
            $year = $bucket['year_level'];
            $semester = $bucket['semester'];

            // Split this bucket's students into those already in a section vs unassigned
            $unassigned = [];
            foreach ($bucket['ids'] as $s) {
                if ($s['section'] === '') $unassigned[] = $s['id'];
            }

            // Existing section codes for this course+year+semester (5-digit only)
            $existingRows = $db->fetchAll(
                "SELECT TRIM(section) AS section, COUNT(*) AS cnt
                 FROM students
                 WHERE TRIM(course) = ? AND year_level = ?
                   AND TRIM(IFNULL(semester, '')) = ?
                   AND section REGEXP '^[0-9]{5}$'
                 GROUP BY TRIM(section)
                 ORDER BY section",
                [$course, $year, (string) $semester]
            );

            $existingCodes = [];
            foreach ($existingRows as $r) {
                $existingCodes[trim($r['section'])] = (int) $r['cnt'];
            }

            // 1) Fill existing sections first (only unassigned students)
            foreach ($existingCodes as $code => $cnt) {
                if (empty($unassigned)) break;
                $slots = $maxPerSection - $cnt;
                if ($slots <= 0) continue;
                $toPlace = array_splice($unassigned, 0, $slots);
                foreach ($toPlace as $sid) {
                    $db->update('students', ['section' => $code], 'id = ?', [$sid]);
                    $updated++;
                }
                $existingCodes[$code] += count($toPlace);
            }

            // 2) Create new sections only for students still unassigned
            $nextNumber = 1;
            while (!empty($unassigned)) {
                // Pick the next free code (skip codes already used)
                while (isset($existingCodes[sectionCodeFromParts($year, $semester, $nextNumber)])) {
                    $nextNumber++;
                }
                $code = sectionCodeFromParts($year, $semester, $nextNumber);
                $toPlace = array_splice($unassigned, 0, $maxPerSection);
                foreach ($toPlace as $sid) {
                    $db->update('students', ['section' => $code], 'id = ?', [$sid]);
                    $updated++;
                }
                $existingCodes[$code] = count($toPlace);
                $sections[] = [
                    'course' => $course,
                    'year_level' => $year,
                    'semester' => $semester,
                    'section' => $code,
                    'count' => count($toPlace),
                    'max' => $maxPerSection,
                ];
            }

            // Record the filled existing sections in the result
            foreach ($existingCodes as $code => $cnt) {
                // Skip codes we already added as newly-created (avoid duplicates
                // in the report). Cast to string: PHP turns numeric array keys
                // like "11001" into ints, which would never match strictly.
                $codeStr = (string) $code;
                $isNew = false;
                foreach ($sections as $s) {
                    if ($s['section'] === $codeStr) { $isNew = true; break; }
                }
                if (!$isNew) {
                    $sections[] = [
                        'course' => $course,
                        'year_level' => $year,
                        'semester' => $semester,
                        'section' => $codeStr,
                        'count' => $cnt,
                        'max' => $maxPerSection,
                    ];
                }
            }
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

    return ['updated' => $updated, 'skipped' => $skipped, 'sections' => $sections];
}

/**
 * Group student rows for masterlist display (course → year → section).
 *
 * @param array<int, array<string, mixed>> $students
 * @return list<array{course: string, year_level: string, section: string, students: array<int, array<string, mixed>>}>
 */
function groupStudentsForMasterlist(array $students): array {
    $groups = [];

    foreach ($students as $student) {
        $courseRaw = trim((string) ($student['course'] ?? ''));
        $course = $courseRaw !== '' ? $courseRaw : 'No Course';
        $year = $student['year_level'];
        $yearLabel = ($year !== null && $year !== '') ? (string) (int) $year : 'N/A';
        $sectionRaw = trim((string) ($student['section'] ?? ''));
        $section = $sectionRaw !== '' ? $sectionRaw : '—';
        $key = $course . "\0" . $yearLabel . "\0" . $section;

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'course' => $course,
                'year_level' => $yearLabel,
                'section' => $section,
                'students' => [],
            ];
        }
        $groups[$key]['students'][] = $student;
    }

    $list = array_values($groups);
    usort($list, static function (array $a, array $b): int {
        return [$a['course'], $a['year_level'], $a['section']]
            <=> [$b['course'], $b['year_level'], $b['section']];
    });

    return $list;
}

/**
 * Whether a masterlist group has a real assigned section (not placeholder).
 */
function masterlistGroupHasSection(array $group): bool {
    $section = trim((string) ($group['section'] ?? ''));
    return $section !== '' && $section !== '—';
}

/**
 * Keep only groups that belong to an assigned section.
 *
 * @param list<array<string, mixed>> $groups
 * @return list<array<string, mixed>>
 */
function filterAssignedMasterlistGroups(array $groups): array {
    return array_values(array_filter($groups, 'masterlistGroupHasSection'));
}

// ─── STATUS HELPERS ────────────────────────────────────────────

/**
 * Get status badge class
 */
function getStatusBadgeClass($status) {
    $classes = [
        'active' => 'active',
        'inactive' => 'inactive',
        'pending' => 'pending',
        'approved' => 'approved',
        'denied' => 'denied',
        'completed' => 'completed',
        'at-risk' => 'at-risk',
        'probation' => 'probation',
        'graduated' => 'graduated',
        'dropped' => 'dropped',
        'transferred' => 'transferred',
        'loa' => 'loa',
        'enrolled' => 'active',
        'cancelled' => 'denied'
    ];
    return $classes[$status] ?? 'default';
}

/**
 * Get status label
 */
function getStatusLabel($status) {
    $labels = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'pending' => 'Pending',
        'approved' => 'Approved',
        'denied' => 'Denied',
        'completed' => 'Completed',
        'at-risk' => 'At Risk',
        'probation' => 'Probation',
        'graduated' => 'Graduated',
        'dropped' => 'Dropped',
        'transferred' => 'Transferred',
        'loa' => 'LOA',
        'enrolled' => 'Enrolled',
        'cancelled' => 'Cancelled'
    ];
    return $labels[$status] ?? ucfirst($status);
}

/**
 * Canonical student-status label for the student portal.
 * Legacy values (probation / at-risk / loa) are folded into the
 * 5-value model: Enrolled, Active, Graduated, Transferred, Dropped.
 */
function getStudentStatusLabel($status) {
    $map = [
        'enrolled'    => 'Enrolled',
        'active'      => 'Active',
        'probation'   => 'Active',
        'at-risk'     => 'Active',
        'loa'         => 'Active',
        'graduated'   => 'Graduated',
        'transferred' => 'Transferred',
        'dropped'     => 'Dropped',
    ];
    return $map[strtolower((string) $status)] ?? 'Enrolled';
}

/**
 * Record a student status change into the status_tracker table.
 * Used whenever a student's status changes (quick dropdown, bulk update,
 * restore). Keeps the dashboard "Recent Activity" and AI insights fed.
 */
function trackStatusChange($studentId, $newStatus, $reason = null) {
    try {
        $db = Database::getInstance();
        $old = $db->fetchOne("SELECT status FROM students WHERE id = ?", [intval($studentId)]);
        $oldStatus = $old['status'] ?? null;
        if ($oldStatus === $newStatus) {
            return false; // nothing changed
        }
        $db->insert('status_tracker', [
            'student_id'      => intval($studentId),
            'previous_status' => $oldStatus,
            'current_status'  => $newStatus,
            'reason'          => $reason,
            'changed_by'      => $_SESSION['user_id'] ?? null,
            'created_at'      => date('Y-m-d H:i:s')
        ]);
        return true;
    } catch (Exception $e) {
        // Silent fail — never block the status update because logging broke.
        error_log('trackStatusChange failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Deterministic mock courier quotation (Lalamove-style) for the
 * Document Request demo.
 *
 * Distance is seeded from the dropoff address so the quote a student
 * sees at submission always equals the one the registrar sees when
 * shipping. The delivery fee is borne by the STUDENT (added to the
 * request total / collected on delivery) — never by the school.
 *
 * @param  string $address courier dropoff address (used as the seed)
 * @return array{total_fee:float, distance_km:float, currency:string}
 */
function mockDeliveryQuote(string $address): array
{
    // 1.5 – 5.5 km, stable per address.
    $distanceKm = round(1.5 + ((crc32(trim($address)) % 41)) / 10, 1);
    return [
        'total_fee'    => round(50.00 + $distanceKm * 20.00, 2),
        'distance_km'  => $distanceKm,
        'currency'     => 'PHP',
    ];
}

/**
 * Create a student record from a normalized input array — the single
 * code path shared by the manual "Add Student" form and the "Receive
 * Student" (enrollment intake) Accept flow.
 *
 * Accepts the same input keys as the old api/students.php POST payload:
 *   first_name*, last_name*, address*  (*required)
 *   birth_date*  (*required — drives the auto-generated portal password)
 *   middle_name, name_suffix, gender, civil_status, place_of_birth,
 *   birth_country, nationality, religion, email, contact_number,
 *   course, major, year_level, school_year, semester, section, adviser_id,
 *   status, lrn, father_name, mother_name, guardian_name,
 *   guardian_relationship, guardian_contact, guardian_email,
 *   prev_school_name, prev_school_last_year, prev_school_graduated_sy,
 *   emergency_name, emergency_relationship, emergency_contact
 *
 * On success it:
 *   - auto-generates + inserts the student (portal `users` account
 *     auto-created with the standard credential scheme),
 *   - syncs Father/Mother into the guardians table,
 *   - appends previous-school to academic_history and the emergency
 *     contact to emergency_contacts when supplied,
 *   - logs every action.
 *
 * @param  array  $input    parsed JSON payload
 * @param  object $db       Database instance
 * @return array{id:int, student_number:string, portal_account:?array}
 */

/**
 * Keep the guardians table in sync with the student's Parent Info.
 *
 * When a student is enrolled/updated with a Father's and/or Mother's
 * Name, those parents should appear as proper `guardians` rows so the
 * Contacts page lists them — not just sit on the students record.
 *
 * For each parent that isn't already on file (matched by relationship
 * OR full name), a guardian row is inserted. If the optional generic
 * "guardian" section matches that parent's relationship, its contact
 * details are reused; otherwise the row is created with blank contact
 * info so it can be filled in later. Never touches existing rows, so
 * it is safe to run on every save (no duplicates).
 */
function syncFatherMotherGuardians(int $studentId, ?string $fatherName, ?string $motherName, array $generic = []): int {
    $db = Database::getInstance();
    $created = 0;
    foreach (['father' => $fatherName, 'mother' => $motherName] as $rel => $name) {
        $name = trim((string) $name);
        if ($name === '') { continue; }
        $exists = $db->fetchOne(
            'SELECT id FROM guardians WHERE student_id = ? AND (relationship = ? OR full_name = ?)',
            [$studentId, $rel, $name]
        );
        if ($exists) { continue; }
        // Reuse the generic guardian's contact info when it targets this parent.
        $useGeneric = isset($generic['relationship']) && $generic['relationship'] === $rel;
        $contactNumber = $useGeneric ? trim((string) ($generic['contact_number'] ?? '')) : '';
        if ($contactNumber !== '') {
            $contactNumber = normalizePhone($contactNumber);
        }
        // Never auto-create a guardian without a valid 11-digit contact number.
        if ($contactNumber === '' || !isValidPhone($contactNumber)) { continue; }
        $db->insert('guardians', [
            'student_id'     => $studentId,
            'full_name'      => $name,
            'relationship'   => $rel,
            'contact_number' => $useGeneric ? trim((string) ($generic['contact_number'] ?? '')) : '',
            'email'          => $useGeneric && trim((string) ($generic['email'] ?? '')) !== ''
                ? trim((string) $generic['email']) : null,
            'address'        => $useGeneric && trim((string) ($generic['address'] ?? '')) !== ''
                ? trim((string) $generic['address']) : null,
            'is_primary'     => 0,
            'is_emergency'   => 0,
        ]);
        $created++;
    }
    return $created;
}

/**
 * Link documents that were staged under an enrollment number to a
 * student record. Used by the enrollment intake once a student is
 * accepted or re-enrolled. Returns how many documents were linked.
 */
function syncDocumentsByEnrollNo(string $enrollNo, int $studentId): int {
    if (trim($enrollNo) === '') return 0;
    $db = Database::getInstance();
    $cols = $db->fetchAll("SHOW COLUMNS FROM documents");
    $colNames = array_column($cols, 'Field');
    if (!in_array('enroll_no', $colNames, true)) return 0;
    return (int) $db->update('documents', [
        'student_id'    => $studentId,
        'enroll_status' => 'linked',
    ], 'enroll_no = ? AND student_id IS NULL', [trim($enrollNo)]);
}
function createStudentFromInput(array $input, $db): array
{
    $firstName = trim($input['first_name'] ?? '');
    $lastName  = trim($input['last_name'] ?? '');
    $address   = trim($input['address'] ?? '');

    if ($firstName === '' || $lastName === '' || $address === '') {
        throw new InvalidArgumentException('First name, last name, and address are required.');
    }

    // Birth date is required: the auto-created portal password is derived
    // from the born year (# + first two letters of first name + YYYY).
    $birthDateRaw = trim((string) ($input['birth_date'] ?? ''));
    if ($birthDateRaw === '' || $birthDateRaw === '0000-00-00' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDateRaw)) {
        throw new InvalidArgumentException('Birth date is required.');
    }
    $birthYear = (int) substr($birthDateRaw, 0, 4);

    // Student number is assigned later by the enrollment department — leave empty on creation.
    $studentNumber = isset($input['student_number']) && trim($input['student_number']) !== ''
        ? trim($input['student_number'])
        : '';

    // B3: normalize before save so bad data never lands.
    $firstName = normalizeNameCase($firstName);
    $lastName  = normalizeNameCase($lastName);
    $address   = trim($address);
    // Contact number: required, must be an 11-digit PH mobile (09XXXXXXXXX).
    $contactNumber = normalizePhone(trim((string) ($input['contact_number'] ?? '')));
    if ($contactNumber === '' || !isValidPhone($contactNumber)) {
        throw new InvalidArgumentException('Contact number is required and must be an 11-digit mobile number (e.g. 09171234567).');
    }

    // Email: required, must be a valid address (the welcome email needs it).
    $studentEmail = strtolower(trim((string) ($input['email'] ?? '')));
    if ($studentEmail === '' || !isValidEmail($studentEmail)) {
        throw new InvalidArgumentException('Email is required and must be a valid address.');
    }

    // Year level: required, and must be a real year. A section code is derived
    // from it ([year][sem][###]), so a blank year would leave the student
    // unplaceable in any section.
    $yearLevelRaw = trim((string) ($input['year_level'] ?? ''));
    if ($yearLevelRaw === '') {
        throw new InvalidArgumentException('Year level is required.');
    }
    $yearLevel = (int) $yearLevelRaw;
    if ($yearLevel < 1 || $yearLevel > 4) {
        throw new InvalidArgumentException('Year level must be 1st, 2nd, 3rd, or 4th Year.');
    }

    // Semester: required, from the fixed set the section codes encode.
    $semesterRaw = trim((string) ($input['semester'] ?? ''));
    if ($semesterRaw === '') {
        throw new InvalidArgumentException('Semester is required.');
    }
    if (!in_array($semesterRaw, ['1st', '2nd', 'summer'], true)) {
        throw new InvalidArgumentException('Semester must be 1st, 2nd, or summer.');
    }

    $data = [
        'student_number' => $studentNumber,
        'first_name' => $firstName,
        'middle_name' => isset($input['middle_name']) && $input['middle_name'] !== '' ? normalizeNameCase(trim($input['middle_name'])) : null,
        'last_name' => $lastName,
        'gender' => $input['gender'] ?? null,
        'civil_status' => $input['civil_status'] ?? null,
        'birth_date' => $birthDateRaw,
        'place_of_birth' => isset($input['place_of_birth']) && $input['place_of_birth'] !== '' ? normalizeNameCase(trim($input['place_of_birth'])) : null,
        'birth_country' => isset($input['birth_country']) && trim($input['birth_country']) !== '' ? trim($input['birth_country']) : null,
        'nationality' => isset($input['nationality']) && trim($input['nationality']) !== '' ? trim($input['nationality']) : null,
        'religion' => isset($input['religion']) && trim($input['religion']) !== '' ? normalizeNameCase(trim($input['religion'])) : null,
        'address' => $address,
        'contact_number' => $contactNumber,
        'email' => $studentEmail,
        'course' => isset($input['course']) && trim($input['course']) !== '' ? courseStandardize(trim($input['course'])) : null,
        'major' => isset($input['major']) && trim($input['major']) !== '' ? trim($input['major']) : null,
        'year_level' => $yearLevel,
        'school_year' => isset($input['school_year']) && trim($input['school_year']) !== '' ? trim($input['school_year']) : null,
        'semester' => $semesterRaw,
        'section' => $input['section'] ?? null,
        'adviser_id' => isset($input['adviser_id']) && $input['adviser_id'] !== '' ? (int) $input['adviser_id'] : null,
        'status' => $input['status'] ?? 'active',
    ];

    // Subsystem 1 additions — LRN, suffix, parents (guarded against pre-migration schema).
    $studentCols = $db->fetchAll("SHOW COLUMNS FROM students");
    $studentColNames = array_column($studentCols, 'Field');
    if (isset($input['lrn']) && trim($input['lrn']) !== '' && in_array('lrn', $studentColNames, true)) {
        $data['lrn'] = strtoupper(preg_replace('/[^0-9]/', '', trim($input['lrn'])));
    }
    if (isset($input['name_suffix']) && trim($input['name_suffix']) !== '' && in_array('name_suffix', $studentColNames, true)) {
        $data['name_suffix'] = trim($input['name_suffix']);
    }
    if (isset($input['mother_name']) && trim($input['mother_name']) !== '' && in_array('mother_name', $studentColNames, true)) {
        $data['mother_name'] = normalizeNameCase(trim($input['mother_name']));
    }
    if (isset($input['father_name']) && trim($input['father_name']) !== '' && in_array('father_name', $studentColNames, true)) {
        $data['father_name'] = normalizeNameCase(trim($input['father_name']));
    }
    if (isset($input['previous_school']) && trim($input['previous_school']) !== '' && in_array('previous_school', $studentColNames, true)) {
        $data['previous_school'] = normalizeNameCase(trim($input['previous_school']));
    }
    if (isset($input['school_year_graduated']) && trim($input['school_year_graduated']) !== '' && in_array('school_year_graduated', $studentColNames, true)) {
        $data['school_year_graduated'] = trim($input['school_year_graduated']);
    }
    if (isset($input['last_year_level_completed']) && trim($input['last_year_level_completed']) !== '' && in_array('last_year_level_completed', $studentColNames, true)) {
        $data['last_year_level_completed'] = trim($input['last_year_level_completed']);
    }

    $newId = $db->insert('students', $data);

    // Insert guardian (optional explicit guardian section).
    $guardianName = trim($input['guardian_name'] ?? '');
    $guardianContact = trim((string) ($input['guardian_contact'] ?? ''));
    if ($guardianContact !== '') {
        $guardianContact = normalizePhone($guardianContact);
        if (!isValidPhone($guardianContact)) {
            throw new InvalidArgumentException('Guardian contact number must be an 11-digit mobile number (e.g. 09171234567).');
        }
    }
    if ($guardianName !== '' && $guardianContact === '') {
            throw new InvalidArgumentException('Guardian contact number is required and must be an 11-digit mobile number (e.g. 09171234567).');
    }
    if ($guardianName !== '') {
        try {
            $db->insert('guardians', [
                'student_id' => $newId,
                'full_name' => $guardianName,
                'relationship' => $input['guardian_relationship'] ?? 'guardian',
                'contact_number' => $guardianContact,
                'email' => $input['guardian_email'] ?? null,
            ]);
        } catch (Exception $e) {
        }
    }

    // Auto-sync Father/Mother Name → guardian rows so the parents
    // entered in Personal Info always appear on the Contacts page.
    if (function_exists('syncFatherMotherGuardians')) {
        syncFatherMotherGuardians($newId, $input['father_name'] ?? null, $input['mother_name'] ?? null, [
            'relationship'   => $input['guardian_relationship'] ?? 'guardian',
            'contact_number' => $guardianContact,
            'email'          => $input['guardian_email'] ?? '',
        ]);
    }

    // ── Previous school → academic_history ─────────────────────
    $prevSchool = trim($input['prev_school_name'] ?? '');
    if ($prevSchool !== '') {
        try {
            $db->insert('academic_history', [
                'student_id'  => $newId,
                'school_name' => $prevSchool,
                'school_year' => $input['prev_school_graduated_sy'] ?? null,
                'grade_level' => $input['prev_school_last_year'] ?? null,
            ]);
        } catch (Exception $e) {
        }
    }

    // ── Emergency contact → emergency_contacts ─────────────────
    $emergName = trim($input['emergency_name'] ?? '');
    $emergencyContact = trim((string) ($input['emergency_contact'] ?? ''));
    if ($emergencyContact !== '') {
        $emergencyContact = normalizePhone($emergencyContact);
        if (!isValidPhone($emergencyContact)) {
            throw new InvalidArgumentException('Emergency contact number must be an 11-digit mobile number (e.g. 09171234567).');
        }
    }
    if ($emergName !== '' && $emergencyContact === '') {
            throw new InvalidArgumentException('Emergency contact number is required and must be an 11-digit mobile number (e.g. 09171234567).');
    }
    if ($emergName !== '') {
        try {
            $db->insert('emergency_contacts', [
                'student_id'     => $newId,
                'full_name'      => $emergName,
                'relationship'   => $input['emergency_relationship'] ?? null,
                'contact_number' => $emergencyContact,
                'is_primary'     => 1,
            ]);
        } catch (Exception $e) {
        }
    }

    logActivity($_SESSION['user_id'] ?? 0, 'student_created', json_encode(['student_number' => $studentNumber]), 'students', $newId);

    // ─── AUTO-CREATE STUDENT PORTAL ACCOUNT ────────────────────
    // Automatically create a `users` entry so the student can
    // log in to the student portal immediately. The registrar
    // receives the credentials to share with the student.
    //
    // Credential scheme (as specified):
    //   username = first letter of first name + last digits of student number
    //             e.g. Juan / 100000001  ->  j100000001
    //             e.g. Juan / 1          ->  j1
    //   password = '#' + first 2 letters of first name + 4-digit birth year
    //             e.g. Juan / 2005        ->  #ju2005
    $portalAccount = null;
    try {
        $fullName = trim($firstName . ' ' . $lastName);

        // Username: use the DB auto-increment id when student_number not yet assigned.
        $idDigits = preg_replace('/[^0-9]/', '', $studentNumber ?: (string) $newId);
        $id9 = str_pad(substr($idDigits, -9), 9, '0', STR_PAD_LEFT);
        $firstLetter = mb_strtolower(mb_substr($firstName, 0, 1));
        $username = $firstLetter . $id9;

        // Password: '#' + first 2 letters (lowercase) + birth year.
        $firstTwo = mb_strtolower(mb_substr($firstName, 0, 2));
        $password = '#' . $firstTwo . $birthYear;

        // Fall back to the student's email (or generate one) as the
        // email field — kept separate from the username login.
        // Derive domain from MAIL_FROM so auto-generated addresses
        // land on a domain that actually accepts mail.
        $studentEmail = $data['email'] ?? null;
        if (!$studentEmail || !isValidEmail($studentEmail)) {
            $fallbackDomain = (defined('MAIL_FROM') && MAIL_FROM !== '')
                ? substr(MAIL_FROM, strrpos(MAIL_FROM, '@') + 1)
                : 'bestlink.edu.ph';
            $studentEmail = 'student_' . $newId . '@' . $fallbackDomain;
        }
        // Ensure email uniqueness — append a suffix if it already exists.
        $emailCheck = $db->fetchOne("SELECT id FROM users WHERE email = ?", [$studentEmail]);
        if ($emailCheck) {
            $studentEmail = 'student_' . $newId . '_' . date('ymd') . '@' . substr($studentEmail, strrpos($studentEmail, '@') + 1);
        }
        // Ensure username uniqueness — append a suffix if it already exists.
        $userCheck = $db->fetchOne("SELECT id FROM users WHERE username = ?", [$username]);
        if ($userCheck) {
            $username = $firstLetter . $id9 . '_' . date('ymd');
        }

        $db->insert('users', [
            'username'      => strtolower($username),
            'email'         => strtolower($studentEmail),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'full_name'     => $fullName,
            'role'          => 'student',
            'student_id'    => $newId,
            'is_active'     => 1,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        logActivity($_SESSION['user_id'] ?? 0, 'student_portal_auto_create', null, 'users', $newId);
        $portalAccount = [
            'username' => $username,
            'email'    => $studentEmail,
            'password' => $password,
            'full_name'=> $fullName,
        ];

        // ── Welcome email (opportunistic — never breaks enrollment) ──
        // Send only when the mail library (PHPMailer via vendor/autoload)
        // AND SMTP are actually configured. Otherwise the registrar just
        // sees the credentials in the modal and shares them manually.
        $mailResult = null;
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            try {
                require_once dirname(__DIR__) . '/shared/mail_client.php';
                if (function_exists('sendStudentWelcomeEmail') && emailConfigured()) {
                    $mailResult = sendStudentWelcomeEmail(
                        ['id' => $newId, 'student_number' => $studentNumber],
                        $portalAccount,
                        $_SESSION['user_id'] ?? null
                    );
                }
            } catch (Throwable $e) {
                // Mail layer must never crash enrollment.
                error_log('[students.php] Welcome email skipped: ' . $e->getMessage());
            }
        }
        $portalAccount['email_sent'] = $mailResult !== null && !empty($mailResult['sent']);
    } catch (Exception $e) {
        // Student was created but portal account failed — log but
        // don't block the response. The admin can create the account
        // manually via User Management.
        error_log('[students.php] Auto portal account failed for student #' . $newId . ': ' . $e->getMessage());
    }

    return [
        'id'             => $newId,
        'student_number' => $studentNumber,
        'portal_account' => $portalAccount,
    ];
}
?>
