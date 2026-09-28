<?php
// ============================================================
//  API/MASTERLIST-AI-SEARCH.PHP
//  AI smart search for the masterlist.
//  Turns a natural-language query into the same GET filters the
//  masterlist already supports (course, year_level, school_year,
//  semester, section, status) plus free-text keywords. LLM
//  responses are cached in ai_cache via aiGenerateJson.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
$role = getCurrentUserRole();
if ($role !== 'admin' && $role !== 'registrar') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/ai_client.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$query = trim((string) ($input['query'] ?? ''));

if ($query === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Search query is required.']);
    exit;
}

try {
    $db = Database::getInstance();

    // Exact values the masterlist filters can match.
    $courses = array_map(fn($r) => (string) trim((string) $r['course']), $db->fetchAll(
        "SELECT DISTINCT TRIM(course) AS course FROM students
         WHERE course IS NOT NULL AND TRIM(course) != '' ORDER BY course"
    ));

    $years = array_map(fn($r) => (string) (int) $r['year_level'], $db->fetchAll(
        "SELECT DISTINCT year_level FROM students WHERE year_level IS NOT NULL ORDER BY year_level"
    ));

    $schoolYears = array_map(fn($r) => (string) $r['school_year'], $db->fetchAll(
        "SELECT DISTINCT school_year FROM students
         WHERE school_year IS NOT NULL AND school_year != '' ORDER BY school_year DESC"
    ));

    $statuses = ['active', 'probation', 'at-risk', 'loa', 'graduated', 'transferred', 'dropped'];
    $semesters = ['1st', '2nd', 'summer'];

    $system = 'You are a search assistant for a Philippine university registrar masterlist. '
        . 'Translate a natural-language query into an EXACT JSON filter object. '
        . 'Allowed course values (use the exact string): ' . json_encode($courses) . '. '
        . 'Allowed year levels (as integers): ' . json_encode($years) . '. '
        . 'Allowed school years (use the exact string): ' . json_encode($schoolYears) . '. '
        . 'Allowed semesters: ' . implode(', ', $semesters) . '. '
        . 'Allowed statuses: ' . implode(', ', $statuses) . '. '
        . 'Allowed JSON keys: course, year_level, school_year, semester, section, status, '
        . 'keywords (array of free-text terms such as a student name or student number), '
        . 'explanation (one short, human-friendly sentence describing what was applied). '
        . 'Only include a key when the query clearly implies it; otherwise omit it. '
        . 'Never invent values outside the allowed lists. Be literal and conservative.';

    $filter = aiGenerateJson($system, 'Query: "' . mb_substr($query, 0, 300) . '"', [], ['max_tokens' => 260]);

    if (!is_array($filter)) {
        $filter = [];
    }

    $clean = [
        'course'      => null,
        'year_level'  => null,
        'school_year' => null,
        'semester'    => null,
        'section'     => null,
        'status'      => null,
        'keywords'    => [],
        'explanation' => null,
    ];

    if (!empty($filter['course']) && in_array(trim((string) $filter['course']), $courses, true)) {
        $clean['course'] = trim((string) $filter['course']);
    }

    $yearRaw = trim((string) ($filter['year_level'] ?? ''));
    if ($yearRaw !== '' && preg_match('/\d+/', $yearRaw, $m) && in_array($m[0], $years, true)) {
        $clean['year_level'] = (int) $m[0];
    }

    if (!empty($filter['school_year']) && in_array(trim((string) $filter['school_year']), $schoolYears, true)) {
        $clean['school_year'] = trim((string) $filter['school_year']);
    }

    $semRaw = strtolower(trim((string) ($filter['semester'] ?? '')));
    $semMap = ['first' => '1st', 'first sem' => '1st', 'first semester' => '1st', '1st' => '1st', '1st sem' => '1st', '1st semester' => '1st', 'second' => '2nd', 'second sem' => '2nd', 'second semester' => '2nd', '2nd' => '2nd', '2nd sem' => '2nd', '2nd semester' => '2nd', 'summer' => 'summer', 'summer class' => 'summer', 'summer semester' => 'summer'];
    if ($semRaw !== '' && isset($semMap[$semRaw])) {
        $clean['semester'] = $semMap[$semRaw];
    }

    $sec = trim((string) ($filter['section'] ?? ''));
    if ($sec !== '' && strlen($sec) <= 30) {
        $clean['section'] = $sec;
    }

    $stRaw = strtolower(trim((string) ($filter['status'] ?? '')));
    $stMap = ['at risk' => 'at-risk', 'atrisk' => 'at-risk', 'probationary' => 'probation'];
    if (isset($stMap[$stRaw])) {
        $stRaw = $stMap[$stRaw];
    }
    if (in_array($stRaw, $statuses, true)) {
        $clean['status'] = $stRaw;
    }

    if (!empty($filter['keywords']) && is_array($filter['keywords'])) {
        $kw = [];
        foreach ($filter['keywords'] as $k) {
            $k = trim((string) $k);
            if ($k !== '') {
                $kw[] = $k;
            }
        }
        $clean['keywords'] = array_slice(array_values($kw), 0, 10);
    }

    $clean['explanation'] = trim((string) ($filter['explanation'] ?? ''));
    if ($clean['explanation'] === '') {
        $applied = [];
        if ($clean['course'])            $applied[] = $clean['course'];
        if ($clean['year_level'])        $applied[] = 'Year ' . $clean['year_level'];
        if ($clean['school_year'])       $applied[] = $clean['school_year'];
        if ($clean['semester'])          $applied[] = $clean['semester'] . ' sem';
        if ($clean['section'])           $applied[] = 'Section ' . $clean['section'];
        if ($clean['status'])            $applied[] = $clean['status'];
        if (count($clean['keywords']))   $applied[] = 'keywords: ' . implode(' / ', $clean['keywords']);
        $clean['explanation'] = $applied ? ('Showing ' . implode(', ', $applied)) : 'Showing all students';
    }

    // Nothing usable -> fall back to a free-text keyword search.
    if (!$clean['course'] && !$clean['year_level'] && !$clean['school_year'] && !$clean['semester'] && !$clean['section'] && !$clean['status'] && !count($clean['keywords'])) {
        $clean['keywords'] = [$query];
        $clean['explanation'] = 'AI could not map this to filters, so it will search the whole list for: ' . $query;
    }

    echo json_encode(['success' => true, 'data' => ['filter' => $clean]]);
    exit;

} catch (Throwable $t) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'AI search failed: ' . $t->getMessage()]);
    exit;
}
