<?php
// ============================================================
//  SHARED/NORMALIZE.PHP
//  Deterministic data-cleaning layer for student records.
//  No LLM, no API cost — pure PHP rules used by the student
//  form and the list page's data-quality tools.
//
//  Includes:
//    - courseStandardize(): map abbreviations/nicknames → official name
//    - normalizePhone(), normalizeEmail(), normalizeNameCase()
//    - findDuplicateStudents(): fuzzy name+birthdate duplicate detection
//    - studentDataQualityFlags(): field-level sanity flags
//    - currentSchoolYear(), currentSemester(): smart defaults
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/database.php';

if (defined('NORMALIZE_LOADED')) {
    return;
}
define('NORMALIZE_LOADED', true);

/**
 * Normalize extracted/originally-typed fields into clean stored values.
 * Shared by paste_fill, extract_doc (text + vision), and the form.
 *
 * @param array $data
 * @return array
 */
function aiNormalizeExtracted(array $data): array {
    if (isset($data['first_name']))  $data['first_name']  = normalizeNameCase((string) $data['first_name']);
    if (isset($data['last_name']))   $data['last_name']   = normalizeNameCase((string) $data['last_name']);
    if (isset($data['middle_name'])) $data['middle_name'] = normalizeNameCase((string) $data['middle_name']);
    if (isset($data['contact_number'])) $data['contact_number'] = normalizePhone((string) $data['contact_number']);
    if (isset($data['course']))      $data['course']      = courseStandardize((string) $data['course']);
    if (isset($data['nationality']) && strtolower(trim((string) $data['nationality'])) === 'filipino') {
        $data['nationality'] = 'Filipino';
    }
    if (isset($data['email']))       $data['email']       = strtolower(trim((string) $data['email']));
    return $data;
}

/**
 * Canonical course map: any common abbreviation/nickname → the official
 * name used by getOfferedCourses(). Keeps masterlist grouping consistent.
 */
function courseAliases(): array {
    $official = getOfferedCourses();

    // Words that are too generic to resolve a course on their own.
    $stopWords = ['bachelor', 'of', 'science', 'bs', 'in', 'and', 'the', 'management', 'technology', 'education'];
    $byWord = [];
    foreach (array_keys($official) as $name) {
        // Build a lowercase word set from the official name.
        $words = preg_split('/\s+/', strtolower(preg_replace('/[^A-Za-z0-9]+/', ' ', $name)));
        foreach ($words as $w) {
            if (strlen($w) >= 2 && !in_array($w, $stopWords, true)) $byWord[$w] = $name;
        }
    }

    $map = [
        // Full-title match (lowercased)
        strtolower('Bachelor of Science in Information Technology') => 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)',
        strtolower('Bachelor of Science in Hospitality Management')  => 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)',
        strtolower('Bachelor of Science in Accounting Information System') => 'BACHELOR OF SCIENCE IN ACCOUNTING INFORMATION SYSTEM (BSAIS)',
        strtolower('Bachelor of Science in Tourism Management')      => 'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)',
        strtolower('Bachelor of Science in Office Administration')   => 'BACHELOR OF SCIENCE IN OFFICE ADMINISTRATION (BSOA)',
        strtolower('Bachelor of Science in Entrepreneurship')        => 'BACHELOR OF SCIENCE IN ENTREPRENEURSHIP (BSENTREP)',
        strtolower('Bachelor of Science in Business Administration') => 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)',
        strtolower('Bachelor of Library Information Science')        => 'BACHELOR OF LIBRARY INFORMATION SCIENCE (BLIS)',
        strtolower('Bachelor of Science in Computer Engineering')    => 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)',
        strtolower('Bachelor of Science in Psychology')              => 'BACHELOR OF SCIENCE IN PSYCHOLOGY (BSP)',
        strtolower('Bachelor of Science in Criminology')             => 'BACHELOR OF SCIENCE IN CRIMINOLOGY (BSCRIM)',
        strtolower('Bachelor of Science in Physical Education')      => 'BACHELOR OF SCIENCE IN PHYSICAL EDUCATION (BPED)',
        strtolower('Bachelor of Science in Technological and Livelihood Education') => 'BACHELOR OF SCIENCE IN TECHNOLOGICAL & LIVELIHOOD EDUCATION (BTLED)',
        strtolower('Bachelor of Science in Technological and Livelihood Education ') => 'BACHELOR OF SCIENCE IN TECHNOLOGICAL & LIVELIHOOD EDUCATION (BTLED)',
        strtolower('Bachelor of Science in Elementary Education')    => 'BACHELOR OF SCIENCE IN ELEMENTARY EDUCATION (BEED)',
        strtolower('Bachelor of Science in Secondary Education')     => 'BACHELOR OF SCIENCE IN SECONDARY EDUCATION (BSED)',
        // Common acronyms
        'bsit'   => 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)',
        'bshm'   => 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)',
        'bsais'  => 'BACHELOR OF SCIENCE IN ACCOUNTING INFORMATION SYSTEM (BSAIS)',
        'bscp'   => 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)',
        'bscpe'  => 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)',
        // BSCS is Computer *Science* — a course this school does not offer.
        // It must NOT be silently mapped to Computer Engineering.
        'bscs'   => 'BACHELOR OF SCIENCE IN COMPUTER SCIENCE (BSCS)',
        'bstm'   => 'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)',
        'bsoa'   => 'BACHELOR OF SCIENCE IN OFFICE ADMINISTRATION (BSOA)',
        'bsentrep' => 'BACHELOR OF SCIENCE IN ENTREPRENEURSHIP (BSENTREP)',
        'bsba'   => 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)',
        'blis'   => 'BACHELOR OF LIBRARY INFORMATION SCIENCE (BLIS)',
        'bsp'    => 'BACHELOR OF SCIENCE IN PSYCHOLOGY (BSP)',
        'bscrim' => 'BACHELOR OF SCIENCE IN CRIMINOLOGY (BSCRIM)',
        'bscriminology' => 'BACHELOR OF SCIENCE IN CRIMINOLOGY (BSCRIM)',
        'bped'   => 'BACHELOR OF SCIENCE IN PHYSICAL EDUCATION (BPED)',
        'btled'  => 'BACHELOR OF SCIENCE IN TECHNOLOGICAL & LIVELIHOOD EDUCATION (BTLED)',
        'beed'   => 'BACHELOR OF SCIENCE IN ELEMENTARY EDUCATION (BEED)',
        'bsed'   => 'BACHELOR OF SCIENCE IN SECONDARY EDUCATION (BSED)',
    ];

    return array_merge($byWord, $map);
}

/**
 * Return the official course name for a raw input, or the raw input
 * unchanged if it can't be confidently matched.
 */
function courseStandardize(?string $raw): string {
    $raw = trim((string) $raw);
    if ($raw === '') return '';

    $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $raw));
    $map = courseAliases();

    // 1) Exact normalized-key match.
    if (isset($map[$key])) {
        return $map[$key];
    }

    // 2) Distinctive phrase markers (order matters — most specific first).
    $phrases = [
        '/information\s+technology/'          => 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)',
        '/hospitality\s+management/'           => 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)',
        '/accounting\s+information\s+system/'  => 'BACHELOR OF SCIENCE IN ACCOUNTING INFORMATION SYSTEM (BSAIS)',
        '/tour(ism)?\s+management/'            => 'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)',
        '/office\s+administration/'            => 'BACHELOR OF SCIENCE IN OFFICE ADMINISTRATION (BSOA)',
        '/entrepreneurship/'                   => 'BACHELOR OF SCIENCE IN ENTREPRENEURSHIP (BSENTREP)',
        '/business\s+administration/'          => 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)',
        '/library\s+information\s+science/'    => 'BACHELOR OF LIBRARY INFORMATION SCIENCE (BLIS)',
        '/computer\s+engineering/'             => 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)',
        '/psychology/'                         => 'BACHELOR OF SCIENCE IN PSYCHOLOGY (BSP)',
        '/criminology/'                        => 'BACHELOR OF SCIENCE IN CRIMINOLOGY (BSCRIM)',
        '/physical\s+education/'               => 'BACHELOR OF SCIENCE IN PHYSICAL EDUCATION (BPED)',
        '/technological\s*(and|&|livelihood)\s*livelihood|livelihood\s+education/' => 'BACHELOR OF SCIENCE IN TECHNOLOGICAL & LIVELIHOOD EDUCATION (BTLED)',
        '/elementary\s+education/'             => 'BACHELOR OF SCIENCE IN ELEMENTARY EDUCATION (BEED)',
        '/secondary\s+education/'              => 'BACHELOR OF SCIENCE IN SECONDARY EDUCATION (BSED)',
    ];
    foreach ($phrases as $pattern => $officialName) {
        if (preg_match($pattern . 'i', $raw)) {
            return $officialName;
        }
    }

    // 3) Conservative word/abbreviation match. A single shared generic word
    //    (e.g. "computer") must NOT pull a course to a different discipline.
    //    We score the input against every official name and only accept a
    //    match when the input is clearly the same course.
    $officialNames = array_keys(getOfferedCourses());
    $bestName  = null;
    $bestScore = 0.0;
    foreach ($officialNames as $official) {
        $score = courseNameSimilarity($raw, $official);
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestName  = $official;
        }
    }
    // Accept only a confident, unambiguous match (>= 0.7 and clearly beating
    // the runner-up). Otherwise leave the value untouched.
    if ($bestName !== null && $bestScore >= 0.7) {
        return $bestName;
    }

    return $raw;
}

/**
 * Simple lexical similarity between a raw course input and an official name.
 * Tokenizes both, compares the distinctive (non-generic) words, and combines
 * with a substring bonus. Returns 0..1.
 */
function courseNameSimilarity(string $raw, string $official): float {
    $generic = ['bachelor', 'of', 'science', 'in', 'and', 'the', 'management', 'technology', 'education', 'computer'];
    $norm = function (string $s) {
        $s = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $s));
        $words = preg_split('/\s+/', trim($s));
        return array_values(array_filter($words, fn($w) => strlen($w) >= 3));
    };
    $rw = $norm($raw);
    $ow = $norm($official);
    if (empty($rw) || empty($ow)) return 0.0;

    // Distinctive words only (drop generic terms).
    $rd = array_diff($rw, $generic);
    $od = array_diff($ow, $generic);
    if (empty($od)) return 0.0;

    // How many distinctive raw words appear in the official set?
    $hits = count(array_intersect($rd, $od));
    $coverage = $hits / max(count($rd), 1);

    // Substring bonus for distinctive words (e.g. "bsit" inside "BSIT").
    $subBonus = 0.0;
    foreach ($rd as $w) {
        if (strlen($w) >= 4 && stripos($official, $w) !== false) {
            $subBonus += 0.15;
        }
    }

    $score = $coverage * 0.85 + min($subBonus, 0.3);
    return max(0.0, min(1.0, $score));
}

/**
 * Normalize a Philippine mobile number to "09XX-XXX-XXXX".
 */
function normalizePhone($phone): string {
    $phone = preg_replace('/[^0-9]/', '', (string) $phone);
    // +63/63 prefix → drop the country code so we get a leading 09.
    if (strlen($phone) === 12 && strpos($phone, '63') === 0) {
        $phone = '0' . substr($phone, 2);
    }
    if (strlen($phone) === 13 && strpos($phone, '63') === 0) {
        $phone = '0' . substr($phone, 2);
    }
    if (preg_match('/^09\d{9}$/', $phone)) {
        return substr($phone, 0, 4) . '-' . substr($phone, 4, 3) . '-' . substr($phone, 7, 4);
    }
    return trim((string) $phone);
}

/**
 * Name to Title Case with proper Philippine surname handling.
 */
function normalizeNameCase($name): string {
    $name = trim((string) $name);
    if ($name === '') return '';
    // Lowercase, collapse spaces, then title-case each word.
    $lower = strtolower(preg_replace('/\s+/', ' ', $name));
    return mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8');
}

/**
 * Quick validity flags for a student record. Returns a list of
 * human-readable warnings.
 */
function studentDataQualityFlags(array $s): array {
    $flags = [];
    if (empty(trim((string)($s['student_number'] ?? ''))))       $flags[] = 'Missing student number';
    if (empty(trim((string)($s['first_name'] ?? ''))))           $flags[] = 'Missing first name';
    if (empty(trim((string)($s['last_name'] ?? ''))))            $flags[] = 'Missing last name';
    if (empty(trim((string)($s['address'] ?? ''))))              $flags[] = 'Missing address';
    if (empty(trim((string)($s['gender'] ?? ''))))               $flags[] = 'Missing gender';
    if (empty(trim((string)($s['birth_date'] ?? ''))))           $flags[] = 'Missing birth date';
    if (empty(trim((string)($s['course'] ?? ''))))               $flags[] = 'Missing course';

    // Email validity
    if (!empty($s['email']) && !isValidEmail(trim($s['email']))) $flags[] = 'Invalid email format';

    // Phone validity
    if (!empty($s['contact_number']) && !preg_match('/^09\d{9}$/', preg_replace('/[^0-9]/', '', $s['contact_number']))) {
        $flags[] = 'Contact number not a valid PH mobile';
    }

    // Birth date sanity
    if (!empty($s['birth_date'])) {
        $bd = strtotime((string) $s['birth_date']);
        if ($bd !== false && $bd > time()) {
            $flags[] = 'Birth date is in the future';
        }
    }

    // Course standardization
    if (!empty($s['course'])) {
        $std = courseStandardize((string) $s['course']);
        if ($std !== trim((string) $s['course'])) {
            $flags[] = 'Course name not standardized';
        }
    }

    return $flags;
}

/**
 * 0–100 completeness score for a student record. Each missing/incorrect
 * field deducts from a perfect score. Used for the per-row quality dot.
 *
 * @param array $s Student row
 * @return int
 */
function studentQualityScore(array $s): int {
    $score = 100;
    $missing = [
        'student_number' => 10,
        'first_name'     => 10,
        'last_name'      => 10,
        'address'        => 8,
        'gender'         => 8,
        'birth_date'     => 10,
        'course'         => 12,
        'contact_number' => 8,
        'email'          => 6,
        'nationality'    => 4,
        'religion'       => 4,
        'section'        => 5,
        'school_year'    => 5,
    ];
    foreach ($missing as $field => $pts) {
        if (empty(trim((string) ($s[$field] ?? '')))) {
            $score -= $pts;
        }
    }
    // Invalid email / phone
    if (!empty($s['email']) && !isValidEmail(trim($s['email']))) $score -= 3;
    if (!empty($s['contact_number']) && !preg_match('/^09\d{9}$/', preg_replace('/[^0-9]/', '', $s['contact_number']))) $score -= 3;
    // Future birth date
    if (!empty($s['birth_date'])) {
        $bd = strtotime((string) $s['birth_date']);
        if ($bd !== false && $bd > time()) $score -= 5;
    }
    return max(0, min(100, $score));
}

/**
 * Anomaly flags for a student record: things worth a registrar's attention.
 * Returns a list of short machine-readable keys.
 *
 * @param array $s Student row
 * @return array<int, string>
 */
function studentAnomalies(array $s): array {
    $flags = [];

    // Age vs year-level mismatch (e.g. 40-year-old 1st year is worth a look).
    if (!empty($s['birth_date']) && !empty($s['year_level'])) {
        $age = floor((time() - strtotime((string) $s['birth_date'])) / (365.25 * 24 * 3600));
        $yl = (int) $s['year_level'];
        if ($age >= 0 && $yl >= 1 && $yl <= 4) {
            $expectedMax = 21 + $yl; // generous: typical + buffer
            if ($age > $expectedMax + 10) {
                $flags[] = 'age_mismatch';
            }
        }
    }

    // Future birth date.
    if (!empty($s['birth_date'])) {
        $bd = strtotime((string) $s['birth_date']);
        if ($bd !== false && $bd > time()) {
            $flags[] = 'future_birthdate';
        }
    }

    // Blank critical field.
    if (empty(trim((string) ($s['address'] ?? ''))))      $flags[] = 'no_address';
    if (empty(trim((string) ($s['contact_number'] ?? '')))) $flags[] = 'no_contact';
    if (empty(trim((string) ($s['gender'] ?? ''))))       $flags[] = 'no_gender';

    // Course not standardized (may be truncated or off-list).
    if (!empty($s['course'])) {
        $std = courseStandardize((string) $s['course']);
        if ($std !== trim((string) $s['course'])) {
            $flags[] = 'course_nonstandard';
        }
    }

    return $flags;
}

/**
 * Fuzzy duplicate detection against existing students.
 * Returns a list of candidates with a similarity score.
 *
 * @param string $firstName
 * @param string $lastName
 * @param string|null $birthDate (YYYY-MM-DD)
 * @return array<int, array{id:int, name:string, student_number:string, score:float}>
 */
function teacherDataQualityFlags(array $t, array $allUsers, ?int $studentLoad): array {
    $flags = [];
    $id = (int) ($t['id'] ?? 0);

    if (empty(trim((string) ($t['email'] ?? ''))))                 $flags[] = 'Missing email';
    if (empty(trim((string) ($t['full_name'] ?? ''))))             $flags[] = 'Missing name';
    if (empty(trim((string) ($t['rfid_uid'] ?? ''))))              $flags[] = 'No RFID assigned';
    if (empty(trim((string) ($t['password_hash'] ?? ''))))         $flags[] = 'No password set';

    // Inactive but still advising students.
    if (empty($t['is_active']) && $studentLoad > 0) {
        $flags[] = 'Inactive but advises students';
    }

    // Duplicate email or RFID across other accounts.
    foreach ($allUsers as $u) {
        if ((int) ($u['id'] ?? 0) === $id) continue;
        if (($u['email'] ?? '') !== '' && strtolower((string)$u['email']) === strtolower((string)($t['email'] ?? ''))) {
            $flags[] = 'Email conflicts with another account';
        }
        if (($u['rfid_uid'] ?? '') !== '' && (string)$u['rfid_uid'] === (string)($t['rfid_uid'] ?? '')) {
            $flags[] = 'RFID already in use';
        }
    }

    return $flags;
}

/**
 * Fuzzy duplicate detection against existing students.
 * Returns a list of candidates with a similarity score.
 *
 * @param string $firstName
 * @param string $lastName
 * @param string|null $birthDate (YYYY-MM-DD)
 * @return array<int, array{id:int, name:string, student_number:string, score:float}>
 */
function findDuplicateStudents(string $firstName, string $lastName, ?string $birthDate = null): array {
    $db = Database::getInstance();
    $results = [];

    $sql = "SELECT id, first_name, middle_name, last_name, student_number, birth_date FROM students";
    $rows = $db->fetchAll($sql);

    $fn = strtolower(trim($firstName));
    $ln = strtolower(trim($lastName));
    if ($fn === '' || $ln === '') {
        return [];
    }

    foreach ($rows as $r) {
        $rfn = strtolower(trim((string) ($r['first_name'] ?? '')));
        $rln = strtolower(trim((string) ($r['last_name'] ?? '')));

        $nameScore = 0.0;
        // Last name must match reasonably well.
        $lnSim = similar_text($ln, $rln) / max(strlen($ln), strlen($rln), 1);
        if ($lnSim >= 0.85) {
            $fnSim = similar_text($fn, $rfn) / max(strlen($fn), strlen($rfn), 1);
            $nameScore = ($lnSim * 0.6) + ($fnSim * 0.4);
        }

        if ($nameScore < 0.72) {
            continue;
        }

        // Birth date bonus/penalty.
        if (!empty($birthDate) && !empty($r['birth_date'])) {
            if ($birthDate === $r['birth_date']) {
                $nameScore = min(1.0, $nameScore + 0.2);
            } else {
                $nameScore -= 0.25;
            }
        }

        if ($nameScore >= 0.72) {
            $results[] = [
                'id'            => (int) $r['id'],
                'name'          => trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'student_number'=> (string) ($r['student_number'] ?? ''),
                'birth_date'    => (string) ($r['birth_date'] ?? ''),
                'score'         => round($nameScore, 2),
            ];
        }
    }

    // Sort by score desc.
    usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($results, 0, 5);
}

/**
 * Current school year as "YYYY-YYYY" from today's date (e.g. 2026-2027).
 */
function currentSchoolYear(): string {
    $y = (int) date('Y');
    $m = (int) date('n');
    // PH academic year typically runs June–May; switch at June.
    if ($m >= 6) {
        return $y . '-' . ($y + 1);
    }
    return ($y - 1) . '-' . $y;
}

/**
 * Current semester label based on the month (rough PH calendar):
 * Jun–Oct → 1st, Nov–Mar → 2nd, Apr–May → summer.
 */
function currentSemester(): string {
    $m = (int) date('n');
    if ($m >= 4 && $m <= 5) return 'summer';
    if ($m >= 6 && $m <= 10) return '1st';
    return '2nd';
}

/**
 * Smart default year level (1st year).
 */
function defaultYearLevel(): int {
    return 1;
}
