<?php
// ============================================================
//  FUNCTIONS.PHP  (shared/)
//  Global helper functions used across the application.
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/section_code.php';

// ─── Output escaping (D2) ────────────────────────────────────────
//
// HTML-escape a value for output in a text node or a quoted attribute.
//
// WHY THIS EXISTS: escaping was opt-in and manual — every page called
// htmlspecialchars() by hand, and nurse/dashboard.php even defined its own
// local h(). That makes escaping easy to forget, and one forgotten call is
// a stored XSS: a student record with a name like `<img src=x
// onerror=alert(1)>` renders as live script for every staff member who
// opens the page. The 2026 audit found exactly one such miss
// (student/ids.php, a raw DB value written into a src attribute).
//
// e() is the short name the codebase already uses in mail bodies
// (e_(), dt_esc() in document_templates.php). It lives here, in the file
// every page already includes, so escaping becomes the path of least
// resistance rather than something to remember.
//
// Note this does NOT make escaping automatic. PHP has no auto-escaping
// template layer here, so e() still has to be called — the difference is
// that there is now one obvious, greppable helper to call instead of a
// hand-written htmlspecialchars() call with the wrong flags at each site.
//
// ENT_QUOTES matters: it escapes single quotes too, which is what protects
// an attribute written with single quotes. The default flags do not.
if (!function_exists('e')) {
    /**
     * @param mixed $value Any scalar, array-key, or null; null becomes ''.
     */
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

// Alias used throughout the mail senders. Delegates to e() so there is a
// single implementation, not two that can drift.
if (!function_exists('e_')) {
    function e_(?string $value): string
    {
        return e($value);
    }
}

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

/**
 * F2 — validate an upload by its ACTUAL CONTENT, not its name.
 *
 * The extension allowlist above is necessary but not sufficient: a file
 * called report.pdf can contain anything, and a polyglot can be both a
 * valid image and valid script. OWASP's File Upload guidance is explicit
 * that the extension and the client-supplied Content-Type are attacker
 * controlled and must not be trusted; the file signature is what counts.
 *
 * This checks the real magic bytes with finfo and refuses anything that
 * does not match what its extension claims. Extensions with no reliable
 * signature (doc/xls/zip-family containers) are allowed through, because
 * finfo reports the container generically and a strict check would reject
 * legitimate registrar documents — the script-execution block in
 * uploads/.htaccess remains the control that matters for those.
 *
 * @return array{ok:bool, reason:string, detected:string}
 */
function validateUploadSignature(string $tmpPath, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    // Extensions whose format has no dependable magic number. These stay
    // extension-validated only; see the note above.
    $containerExts = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods',
                      'zip', 'rar', '7z', 'txt', 'csv'];

    if (!is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'reason' => 'not an uploaded file', 'detected' => ''];
    }
    if (in_array($ext, $containerExts, true)) {
        return ['ok' => true, 'reason' => 'extension-only (container format)', 'detected' => ''];
    }

    if (!function_exists('finfo_open')) {
        // fileinfo extension unavailable: fall back to extension-only
        // rather than rejecting every legitimate upload.
        error_log('[upload] finfo unavailable; skipping signature check for ' . $originalName);
        return ['ok' => true, 'reason' => 'finfo unavailable', 'detected' => ''];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        error_log('[upload] finfo_open failed; skipping signature check');
        return ['ok' => true, 'reason' => 'finfo_open failed', 'detected' => ''];
    }
    $detected = (string) finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    if ($detected === '') {
        return ['ok' => false, 'reason' => 'could not identify file content', 'detected' => ''];
    }

    // What a file claiming to be <ext> is actually allowed to be.
    $expected = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp'  => ['image/bmp', 'image/x-ms-bmp'],
        'pdf'  => ['application/pdf'],
    ];

    if (!isset($expected[$ext])) {
        return ['ok' => true, 'reason' => 'no signature rule for this extension', 'detected' => $detected];
    }

    if (!in_array(strtolower($detected), $expected[$ext], true)) {
        // Explicitly refuse anything that looks executable. This is the
        // case that matters: a .png that is really PHP.
        if (preg_match('#(php|script|html|xml|executable)#i', $detected)) {
            return [
                'ok'       => false,
                'reason'   => 'the file content is executable code, not a ' . strtoupper($ext),
                'detected' => $detected,
            ];
        }
        return [
            'ok'       => false,
            'reason'   => 'file content does not match its .' . $ext . ' extension',
            'detected' => $detected,
        ];
    }

    return ['ok' => true, 'reason' => 'signature verified', 'detected' => $detected];
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
 * The one definition of what a student's status can be.
 *
 * There used to be four independent answers to that question: the DB enum
 * (8 values), $ALL_STATUSES/$DB_STATUSES in registrar/status-tracker.php,
 * the badge/label maps in this file, and a sixth list in
 * shared/analytics.php. They had already drifted - `alumni` was drawn as a
 * slice of the AI insights pie while not being a value the column would
 * accept, and `archived` was written by the delete path while not being in the
 * enum at all. MySQL does not reject an out-of-enum value in strict mode here,
 * it silently coerces it to '', so deleting a student produced a row with an
 * empty status: the record stayed in every list and vanished from every
 * status filter, and the tracker logged a change to a status that never
 * existed.
 *
 * So the set is defined once, here, and everything else - the pages, the API,
 * the CSS, the analytics pie, the schema dump and the migration - reads it.
 * Adding a status is one edit here plus the migration, not six.
 *
 * The six values, and what each one means:
 *
 *   enrolled   taken on, not yet confirmed as attending        (default)
 *   active     attending
 *   graduate   completed the programme, diploma awarded
 *   alumni     graduate who has left the school / former student
 *   dropped    withdrawn before completing
 *
 * graduate and alumni are deliberately SEPARATE rather than one "finished"
 * value: the insights pie needs to distinguish "just finished" from "gone
 * years ago", and collapsing them loses that.
 *
 * There is no `archived` status. Archiving is a soft-delete concern and is
 * modelled by a separate column, not by inventing a status value.
 */
function studentStatuses(): array
{
    return ['enrolled', 'active', 'graduate', 'alumni', 'dropped'];
}

/** True when $status is one this system will actually store. */
function isValidStudentStatus($status): bool
{
    return in_array(strtolower(trim((string) $status)), studentStatuses(), true);
}

/**
 * The display label for a status, or '' for an unknown one.
 *
 * Returns '' rather than ucfirst() of the raw value: an unrecognised status is
 * a bug, and printing the raw token next to a student's name hides it. The
 * callers that must never show a blank fall back explicitly.
 */
function studentStatusLabel($status): string
{
    $labels = [
        'enrolled' => 'Enrolled',
        'active'   => 'Active',
        'graduate' => 'Graduate',
        'alumni'   => 'Alumni',
        'dropped'  => 'Dropped',
    ];
    return $labels[strtolower(trim((string) $status))] ?? '';
}

/**
 * Presentation for a status: colour, tint, icon, CSS class.
 *
 * Single definition, because the badge, the dot, the filter chip and the
 * tracker timeline each need the same three values and had been keeping
 * separate copies. The CSS in css/registrar.css is keyed to `class`, so a new
 * status needs a rule there too - but the colour itself is stated once.
 */
function studentStatusMeta($status): array
{
    $meta = [
        'enrolled' => ['label' => 'Enrolled', 'class' => 'enrolled', 'color' => '#2563eb', 'bg' => '#eff6ff', 'icon' => 'fas fa-user-plus'],
        'active'   => ['label' => 'Active',   'class' => 'active',   'color' => '#16a34a', 'bg' => '#f0fdf4', 'icon' => 'fas fa-user-check'],
        'graduate' => ['label' => 'Graduate', 'class' => 'graduate', 'color' => '#7c3aed', 'bg' => '#f5f3ff', 'icon' => 'fas fa-graduation-cap'],
        'alumni'   => ['label' => 'Alumni',   'class' => 'alumni',   'color' => '#0891b2', 'bg' => '#ecfeff', 'icon' => 'fas fa-users'],
        'dropped'  => ['label' => 'Dropped',  'class' => 'dropped',  'color' => '#dc2626', 'bg' => '#fef2f2', 'icon' => 'fas fa-user-xmark'],
    ];
    $key = strtolower(trim((string) $status));
    return $meta[$key] ?? ['label' => '', 'class' => 'unknown', 'color' => '#94a3b8', 'bg' => '#f1f5f9', 'icon' => 'fas fa-circle-question'];
}

/**
 * Get status badge class
 */
function getStatusBadgeClass($status) {
    return studentStatusMeta($status)['class'];
}

/**
 * Get status label
 *
 * Falls back to ucfirst() for anything that is not a student status, because
 * this helper is also used for other status columns (rfid_cards, document
 * requests) whose vocabularies this system does not own.
 */
function getStatusLabel($status) {
    return studentStatusLabel($status) ?: ucfirst((string) $status);
}

/**
 * A short, human-readable acronym for a program.
 *
 * The `course` column is free text and the office writes it three
 * different ways: "BS Computer Science", "BSED", and "BACHELOR OF
 * SCIENCE IN INFORMATION TECHNOLOGY (BSIT)" are all rows in the same
 * table. Printed in full, the long form takes most of a table cell and
 * pushes the columns that matter - the status, the attention level -
 * off to the right.
 *
 * Three cases, in order of confidence:
 *
 *   1. An acronym is already written out in brackets, as the long form
 *      usually is. That is authoritative and is used verbatim.
 *   2. The value is already short and looks like an acronym. Used as is.
 *   3. Otherwise the initials of the significant words, with the
 *      connective words dropped, because "Bachelor of Science in
 *      Information Technology" should not read "BOSIIT".
 *
 * Never returns an empty string, and never silently loses the program:
 * the caller is expected to keep the full name in a title attribute, so
 * the abbreviation is a compression of the record rather than a
 * replacement for it.
 *
 * @param string $course
 * @return string 1-6 characters, upper case.
 */
function courseAcronym($course) {
    $course = trim((string) $course);
    if ($course === '') {
        return '';
    }

    // 1. An acronym in brackets: "BACHELOR OF SCIENCE IN IT (BSIT)".
    if (preg_match('/\(([A-Za-z0-9&.\- ]{2,10})\)\s*$/', $course, $m)) {
        $inner = strtoupper(trim($m[1]));
        if (strlen($inner) <= 6) {
            return $inner;
        }
        // Too long to be an acronym - it is a note, not a short form. Drop
        // it before taking initials, or its first character lands in the
        // result and the column reads "BSS(".
        $course = rtrim(trim(substr($course, 0, strrpos($course, '('))));
    }

    // 2. Already an acronym: short, and not a sentence.
    if (strlen($course) <= 8 && !preg_match('/\s{2,}/', $course)) {
        return strtoupper($course);
    }

    // 3. Initials of the significant words.
    // 3. Initials of the significant words. The delimiter class escapes the
    // slash as well as the hyphen: an unescaped "/" ends the pattern, and
    // the result is a preg_split() that returns false and then counts as
    // an array a few lines later.
    $filler = ['of', 'in', 'the', 'and', 'for', 'a', 'an', 'at', 'on', 'to'];
    $words  = preg_split('/[\s\-\/]+/', $course, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($words)) {
        $words = [$course];
    }
    $keep   = [];
    foreach ($words as $w) {
        $lower = strtolower($w);
        if (in_array($lower, $filler, true)) {
            continue;
        }
        $keep[] = $w;
    }
    // A name made only of filler words still has to yield something.
    if (!$keep) {
        $keep = $words;
    }
    if (count($keep) === 1 && strlen($keep[0]) > 4) {
        // "Engineering" alone, or a single long word: take its start.
        return strtoupper(substr($keep[0], 0, 4));
    }

    $out = '';
    foreach ($keep as $w) {
        $out .= strtoupper(mb_substr($w, 0, 1));
        if (mb_strlen($out) >= 6) {
            break;
        }
    }
    return $out !== '' ? $out : strtoupper(substr($course, 0, 3));
}


/**
 * Canonical student-status label for the student portal.
 *
 * Now a thin alias of studentStatusLabel(). It used to carry its own 5-value
 * model ("Enrolled / Active / Graduated / Transferred / Dropped") which folded
 * probation, at-risk and loa into Active - a fourth independent opinion about
 * the same column, and one whose vocabulary no longer matched the enum.
 */
function getStudentStatusLabel($status) {
    return studentStatusLabel($status);
}

/**
 * Record a student status change into the status_tracker table.
 * Used whenever a student's status changes (quick dropdown, bulk update,
 * restore). Keeps the dashboard "Recent Activity" and AI insights fed.
 */
function trackStatusChange($studentId, $newStatus, $reason = null, array $meta = []) {
    try {
        $db = Database::getInstance();
        $old = $db->fetchOne("SELECT status FROM students WHERE id = ?", [intval($studentId)]);
        $oldStatus = $old['status'] ?? null;
        if ($oldStatus === $newStatus) {
            return false; // nothing changed
        }
        // A leave of absence has to end. The window columns make that
        // possible; they are not part of the base schema on every server,
        // so they are written only when present rather than assumed. A
        // missing end_date costs a later review its expiry date, which is
        // recoverable; a failed insert would lose the whole status change.
        $row = [
            'student_id'      => intval($studentId),
            'previous_status' => $oldStatus,
            'current_status'  => $newStatus,
            'reason'          => $reason,
            'changed_by'      => $_SESSION['user_id'] ?? null,
            'created_at'      => date('Y-m-d H:i:s'),
        ];
        static $cols = null;
        if ($cols === null) {
            $cols = [];
            try {
                $cols = array_column(
                    $db->fetchAll('SHOW COLUMNS FROM status_tracker'),
                    'Field'
                );
            } catch (Exception $e) {
                $cols = [];
            }
        }
        if (!empty($meta['effective_date']) && in_array('effective_date', $cols, true)) {
            $row['effective_date'] = $meta['effective_date'];
        }
        if (array_key_exists('end_date', $meta) && in_array('end_date', $cols, true)) {
            // An explicit null clears the window; an absent key leaves any
            // existing value alone. Conflating the two would silently drop a
            // registrar's expiry date.
            $row['end_date'] = $meta['end_date'] ?: null;
        }
        $db->insert('status_tracker', $row);
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
        // Section is hard-coded to NULL, and any `section` in the payload is
        // ignored. Section is Class Scheduling's field (#297) — see
        // DEPARTMENTS.md — and a newly enrolled student has not been placed
        // in a block yet. NULL, not '': the column is VARCHAR, and an empty
        // string would travel into the Masterlist as if it were a real
        // (blank) section code rather than as "not yet assigned".
        'section' => null,
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

    // ── Guardians → guardians ──────────────────────────────────
    // Two input shapes reach this function and both must keep working.
    //
    //  a) `guardians` — a LIST, posted by the Enrol modal's repeater. One
    //     entry per person on the form. This is the shape that lets a
    //     student have a father AND a mother AND a guardian; the old flat
    //     block could only ever record one of them.
    //  b) guardian_name / guardian_contact / ... — the flat shape, still
    //     sent by the enrollment intake (api/enrollments.php) and by the
    //     row-1 half of the Enrol modal. Unchanged, because those callers
    //     were not touched and silently dropping their guardian on a
    //     refactor would lose data nobody would notice until a parent
    //     phoned the school.
    $guardianRows = [];
    if (isset($input['guardians']) && is_array($input['guardians']) && count($input['guardians']) > 0) {
        $guardianRows = $input['guardians'];
    } else {
        $guardianRows = [[
            'full_name'      => $input['guardian_name'] ?? '',
            'relationship'   => $input['guardian_relationship'] ?? 'guardian',
            'contact_number' => $input['guardian_contact'] ?? '',
            'email'          => $input['guardian_email'] ?? '',
            'address'        => $input['guardian_address'] ?? '',
        ]];
    }

    // Only the five values the ENUM allows. A free-text relationship from
    // an emergency-contact-style field would be rejected by MySQL and abort
    // the whole enrolment, so anything unrecognised becomes 'guardian'
    // rather than throwing.
    $allowedRelationships = ['father', 'mother', 'guardian', 'spouse', 'sibling'];

    // De-duplicate across the list AND against the flat shape: the Enrol
    // modal sends row 1 twice (once inside the array, once flat), so
    // without this the primary guardian gets two identical rows and the
    // Contacts page shows them side by side. Same relationship OR same
    // name = same person — the rule syncFatherMotherGuardians() uses, so
    // a father named in the form and the same father from father_name
    // produce one row, not two.
    $seenKeys = [];
    $primaryInserted = false;
    $firstGuardianForSync = null;

    foreach ($guardianRows as $g) {
        if (!is_array($g)) { continue; }

        $gName = trim((string) ($g['full_name'] ?? ''));
        $gContactRaw = trim((string) ($g['contact_number'] ?? ''));
        $gContact = $gContactRaw !== '' ? normalizePhone($gContactRaw) : '';
        $gEmail = trim((string) ($g['email'] ?? ''));
        $gAddress = trim((string) ($g['address'] ?? ''));

        // An entirely empty row is not a person. The repeater's "Add
        // another" button leaves blanks behind often enough that this is
        // the common case, not the exception.
        if ($gName === '' && $gContact === '' && $gEmail === '' && $gAddress === '') { continue; }

        if ($gContactRaw !== '' && !isValidPhone($gContact)) {
            throw new InvalidArgumentException(
                ($gName !== '' ? '"' . $gName . '"' : 'A guardian')
                . "'s contact number must be an 11-digit mobile number (e.g. 09171234567)."
            );
        }
        // guardians.contact_number is NOT NULL. A named guardian with no
        // number cannot be stored at all, so this is rejected rather than
        // silently dropped — the clerk needs to know the row was not saved.
        if ($gName !== '' && $gContact === '') {
            throw new InvalidArgumentException(
                '"' . $gName . '" needs a mobile number (11 digits starting 09, e.g. 09171234567).'
            );
        }

        $gRel = (string) ($g['relationship'] ?? 'guardian');
        if (!in_array($gRel, $allowedRelationships, true)) { $gRel = 'guardian'; }

        $key = mb_strtolower($gRel . '|' . $gName);
        if (isset($seenKeys[$key])) { continue; }
        $seenKeys[$key] = true;

        $isPrimary = !$primaryInserted ? 1 : 0;
        if ($isPrimary) { $primaryInserted = true; }

        try {
            $db->insert('guardians', [
                'student_id'     => $newId,
                'full_name'      => $gName,
                'relationship'   => $gRel,
                'contact_number' => $gContact,
                'email'          => $gEmail !== '' ? $gEmail : null,
                'address'        => $gAddress !== '' ? $gAddress : null,
                'is_primary'     => $isPrimary,
                'is_emergency'   => 0,
            ]);
            if ($firstGuardianForSync === null) {
                $firstGuardianForSync = [
                    'relationship'   => $gRel,
                    'contact_number' => $gContact,
                    'email'          => $gEmail,
                    'address'        => $gAddress,
                ];
            }
        } catch (Exception $e) {
            // One bad guardian row must not abort the enrolment: the
            // student row itself is already written at this point, and
            // losing it would be far worse than losing one contact.
            error_log('[createStudentFromInput] guardian row skipped: ' . $e->getMessage());
        }
    }

    // Auto-sync Father/Mother Name → guardian rows so the parents entered
    // in Personal Info always appear on the Contacts page. It skips any
    // parent already inserted above, so running it after the repeater
    // cannot duplicate the rows the clerk just filled in.
    if (function_exists('syncFatherMotherGuardians')) {
        syncFatherMotherGuardians(
            $newId,
            $input['father_name'] ?? null,
            $input['mother_name'] ?? null,
            $firstGuardianForSync ?? []
        );
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
    // The whole record is optional, but the name and the number travel
    // together: a row with a name and no number is worse than no row at
    // all, because on the emergency list it looks like somebody to ring
    // and the office has nothing to dial. Rejecting both halves matches
    // the rule the Enrol modal enforces before it posts.
    $emergName = trim((string) ($input['emergency_name'] ?? ''));
    $emergRel  = trim((string) ($input['emergency_relationship'] ?? ''));
    $emergAddr = trim((string) ($input['emergency_address'] ?? ''));
    $emergContact = trim((string) ($input['emergency_contact'] ?? ''));
    if ($emergContact !== '') {
        $emergContact = normalizePhone($emergContact);
        if (!isValidPhone($emergContact)) {
            throw new InvalidArgumentException('Emergency contact number must be an 11-digit mobile number (e.g. 09171234567).');
        }
    }
    if ($emergName !== '' && $emergContact === '') {
        throw new InvalidArgumentException('The emergency contact needs a mobile number, or clear the name.');
    }
    if ($emergName === '' && $emergContact !== '') {
        throw new InvalidArgumentException('The emergency contact needs a name, or clear the mobile number.');
    }
    if ($emergName !== '') {
        try {
            $db->insert('emergency_contacts', [
                'student_id'     => $newId,
                'full_name'      => $emergName,
                'relationship'   => $emergRel !== '' ? $emergRel : null,
                'contact_number' => $emergContact,
                'address'        => $emergAddr !== '' ? $emergAddr : null,
                'is_primary'     => 1,
            ]);
        } catch (Exception $e) {
            // Same reasoning as the guardian rows: the student is already
            // written, so a failure here must not unwind the enrolment.
            error_log('[createStudentFromInput] emergency contact skipped: ' . $e->getMessage());
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

        // Email: use the real address the registrar entered.
        //
        // HISTORY (the actual cause of "550 5.1.1 NoSuchUser"):
        //   This used to fall back to fabricating 'student_<id>_<ymd>@gmail.com'
        //   when the address was missing OR already taken by another user,
        //   because users.email carried a UNIQUE index. In the observed case
        //   the registrar entered roldantiu89@gmail.com, which their own admin
        //   account already used, so the real address was silently discarded
        //   and replaced with a mailbox that never existed. The bounce then
        //   came straight back because MAIL_FROM is that same address.
        //
        // NOW: an address that is merely SHARED is kept as-is — email is not a
        // unique identity in a school (families share addresses; a student may
        // later become staff). users.email is no longer UNIQUE (see
        // migrations/security_hardening_phase1.sql step 6).
        //
        // NULL is now representable and means "no usable address on file".
        $studentEmail = isset($data['email']) ? trim((string) $data['email']) : '';
        $emailIsPlaceholder = false;
        if ($studentEmail === '' || !isValidEmail($studentEmail)) {
            $studentEmail = null;   // no address — do NOT invent one
            $emailIsPlaceholder = true;
        }

        // Ensure username uniqueness — append a suffix if it already exists.
        $userCheck = $db->fetchOne("SELECT id FROM users WHERE username = ?", [$username]);
        if ($userCheck) {
            $username = $firstLetter . $id9 . '_' . date('ymd');
        }

        $db->insert('users', [
            'username'      => strtolower($username),
            // NULL is meaningful now: "no usable address on file". Do not
            // substitute a sentinel — a fake domain can never receive mail,
            // and a shared real address is perfectly legitimate.
            'email'         => $studentEmail !== null ? strtolower($studentEmail) : null,
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
            'email'    => $studentEmail ?? '',
            'password' => $password,
            'full_name'=> $fullName,
            // The registrar UI must not offer to "resend" when there is
            // nothing deliverable to send to.
            'email_deliverable' => !$emailIsPlaceholder,
        ];

        // ── Welcome email (opportunistic — never breaks enrollment) ──
        // Only attempted when there is a REAL address on file. There is no
        // sentinel to worry about any more: a NULL email simply means
        // nothing is sent, and the registrar sees the credentials in the
        // modal and shares them manually.
        $mailResult = null;
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload) && $emailIsPlaceholder === false) {
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
        } elseif ($emailIsPlaceholder === true) {
            $portalAccount['skip_reason'] = 'no_email_on_file';
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
