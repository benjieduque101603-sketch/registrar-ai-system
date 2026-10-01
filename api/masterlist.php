<?php
// ============================================================
//  API/MASTERLIST.PHP
//  Masterlist roster, plus the section-assignment writes.
//
//  The roster (GET) is a read. The writes below stamp
//  students.section, a [year][sem][###] code (11001 = year 1,
//  1st semester, section 1) built by shared/section_code.php.
//
//  Section codes are scoped by course + year level + semester,
//  NOT by school year, because the code has no S.Y. digit: the
//  same program and year across two intakes shares one section
//  space, and numbering it twice would hand the same code to two
//  different sets of students.
//
//  CSRF is enforced on include (shared/csrf_guard.php calls
//  requireCsrf() itself), so every POST below is already guarded.
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
// Admin + registrar only
if (!in_array(getCurrentUserRole(), ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = Database::getInstance();
    $maxPerSection = defined('MAX_STUDENTS_PER_SECTION') ? (int) MAX_STUDENTS_PER_SECTION : 50;

    if ($method === 'GET') {
        $courseFilter  = isset($_GET['course']) ? trim((string) $_GET['course']) : '';
        $yearFilter    = isset($_GET['year_level']) ? trim((string) $_GET['year_level']) : '';
        $sectionFilter = isset($_GET['section']) ? trim((string) $_GET['section']) : '';

        $sql = "SELECT * FROM students WHERE 1=1";
        $params = [];

        if ($courseFilter !== '') {
            $sql .= " AND TRIM(course) = ?";
            $params[] = $courseFilter;
        }
        if ($yearFilter !== '' && is_numeric($yearFilter)) {
            $sql .= " AND year_level = ?";
            $params[] = (int) $yearFilter;
        }
        if ($sectionFilter !== '') {
            $sql .= " AND TRIM(section) = ?";
            $params[] = $sectionFilter;
        }

        // section before last_name: a section is the unit a list is
        // handed off in, so one section's students have to land
        // together. Sorting by name first would interleave them.
        $sql .= " ORDER BY TRIM(course) ASC, COALESCE(year_level, 0) ASC, section ASC, last_name ASC, first_name ASC";

        $students = $db->fetchAll($sql, $params);

        echo json_encode([
            'success' => true,
            'max_per_section' => $maxPerSection,
            'total' => count($students),
            'students' => $students,
        ]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }

        $action = (string) ($input['action'] ?? '');

        // A semester only counts if it is one of the three the code
        // format can express. Anything else becomes null (= 1st sem)
        // rather than being stored verbatim and then failing the
        // sectionCodeFromParts() digit rules.
        $readSemester = static function ($raw): ?string {
            $v = trim((string) $raw);
            return in_array($v, ['1st', '2nd', 'summer'], true) ? $v : null;
        };

        // ─── LIST SECTIONS (grouped summaries) ───────────────
        if ($action === 'list_sections') {
            $sections = $db->fetchAll(
                "SELECT TRIM(course) AS course, year_level, semester, TRIM(section) AS section, COUNT(*) AS count
                 FROM students
                 WHERE section IS NOT NULL AND TRIM(section) != ''
                 GROUP BY TRIM(course), year_level, semester, TRIM(section)
                 ORDER BY TRIM(course), year_level, section"
            );
            echo json_encode(['success' => true, 'sections' => $sections]);
            exit;
        }

        // ─── NEXT SECTION CODE ───────────────────────────────
        // The live preview behind the Create Section modal. Read-only
        // in effect: it looks up the next free number, it does not
        // reserve it, so two people previewing at once both see the
        // same number and the create path re-checks for a collision.
        if ($action === 'next_section') {
            $course = trim((string) ($input['course'] ?? ''));
            $year   = isset($input['year_level']) ? (int) $input['year_level'] : 0;
            $semester = $readSemester($input['semester'] ?? '');

            if ($course === '' || $year < 1 || $year > 9) {
                echo json_encode(['success' => false, 'message' => 'Course and year level are required.']);
                exit;
            }

            $nextNumber = nextSectionNumber($course, $year, $semester);
            $code = sectionCodeFromParts($year, $semester, $nextNumber);

            echo json_encode(['success' => true, 'code' => $code, 'next_number' => $nextNumber]);
            exit;
        }

        // ─── BULK ASSIGN STUDENTS TO A SECTION ───────────────
        if ($action === 'bulk_assign_section') {
            $ids = array_values(array_filter(array_map('intval', (array) ($input['ids'] ?? []))));
            $section = trim((string) ($input['section'] ?? ''));
            if (empty($ids) || $section === '') {
                echo json_encode(['success' => false, 'message' => 'Select students and a section.']);
                exit;
            }

            // Context fields are stamped alongside the code so the
            // masterlist block a student lands in stays coherent with
            // the section they were just put into.
            $fields = ['section' => $section];
            foreach (['course', 'school_year'] as $col) {
                $v = trim((string) ($input[$col] ?? ''));
                if ($v !== '') $fields[$col] = $v;
            }
            if (isset($input['year_level']) && $input['year_level'] !== '') {
                $fields['year_level'] = (int) $input['year_level'];
            }
            if (isset($input['semester']) && $input['semester'] !== '') {
                $fields['semester'] = $readSemester($input['semester']);
            }
            if (isset($input['adviser_id']) && $input['adviser_id'] !== '') {
                $fields['adviser_id'] = (int) $input['adviser_id'];
            }

            // A section code is derived from the year level, so a
            // student with no year level cannot hold one. When this
            // request does not stamp a year level, reject the
            // selected year-less students by name rather than write a
            // code that contradicts their record.
            if (!isset($fields['year_level'])) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $yearless = $db->fetchAll(
                    "SELECT id, first_name, last_name FROM students
                     WHERE id IN ($placeholders)
                       AND (year_level IS NULL OR TRIM(IFNULL(year_level, '')) = '')",
                    $ids
                );
                if (!empty($yearless)) {
                    $names = array_map(
                        static fn($r) => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                        $yearless
                    );
                    echo json_encode([
                        'success' => false,
                        'message' => 'These students have no year level set and cannot be assigned to a section: '
                            . implode(', ', $names) . '. Set their year level first.',
                    ]);
                    exit;
                }
            }

            $conn = $db->getConnection();
            $conn->beginTransaction();
            try {
                foreach ($ids as $sid) {
                    $db->update('students', $fields, 'id = ?', [$sid]);
                }
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }

            echo json_encode([
                'success' => true,
                'message' => count($ids) . ' student(s) assigned to section ' . $section . '.',
                'updated' => count($ids),
            ]);
            exit;
        }

        // ─── EDIT SECTION (rename/move ALL students in it) ──
        if ($action === 'edit_section') {
            // Identify the section to edit via its old context + code.
            $oldCourse  = trim((string) ($input['old_course'] ?? ''));
            $oldYear    = isset($input['old_year_level']) ? (int) $input['old_year_level'] : 0;
            $oldSem     = $readSemester($input['old_semester'] ?? '');
            $oldSection = trim((string) ($input['old_section'] ?? ''));

            if ($oldCourse === '' || $oldSection === '') {
                echo json_encode(['success' => false, 'message' => 'Missing section to edit.']);
                exit;
            }

            $members = $db->fetchAll(
                "SELECT id FROM students
                 WHERE TRIM(course) = ? AND year_level = ?
                   AND TRIM(IFNULL(semester, '')) = ? AND TRIM(section) = ?",
                [$oldCourse, $oldYear, (string) $oldSem, $oldSection]
            );

            if (empty($members)) {
                echo json_encode(['success' => false, 'message' => 'No students found in that section.']);
                exit;
            }

            $newCourse  = trim((string) ($input['course'] ?? $oldCourse)) ?: $oldCourse;
            $newYear    = isset($input['year_level']) && $input['year_level'] !== '' ? (int) $input['year_level'] : $oldYear;
            $newSemRaw  = trim((string) ($input['semester'] ?? ''));
            $newSem     = $newSemRaw !== '' ? $readSemester($newSemRaw) : $oldSem;
            $newSection = trim((string) ($input['section'] ?? $oldSection)) ?: $oldSection;
            $newSchoolYear = trim((string) ($input['school_year'] ?? ''));
            $newAdviser = isset($input['adviser_id']) && $input['adviser_id'] !== '' ? (int) $input['adviser_id'] : null;

            if ($newYear < 1 || $newYear > 9) {
                echo json_encode(['success' => false, 'message' => 'A section needs a year level between 1 and 9.']);
                exit;
            }

            // Uniqueness: if the code OR the block it lives in changes,
            // make sure the target is free. The members being moved are
            // excluded, or a rename to the code a section already holds
            // would collide with the rows doing the moving.
            if ($newSection !== $oldSection
                || $newCourse !== $oldCourse
                || $newYear !== $oldYear
                || (string) $newSem !== (string) $oldSem) {
                $memberIds = array_map('intval', array_column($members, 'id'));
                $collision = $db->fetchOne(
                    "SELECT id FROM students
                     WHERE TRIM(course) = ? AND year_level = ?
                       AND TRIM(IFNULL(semester, '')) = ? AND TRIM(section) = ?
                       AND id NOT IN (" . implode(',', $memberIds) . ")
                     LIMIT 1",
                    [$newCourse, $newYear, (string) $newSem, $newSection]
                );
                if ($collision) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Section code ' . $newSection . ' is already in use for '
                            . $newCourse . ' / Year ' . $newYear . '.',
                    ]);
                    exit;
                }
            }

            $fields = [
                'section'    => $newSection,
                'course'     => $newCourse,
                'year_level' => $newYear,
                'semester'   => $newSem,
            ];
            if ($newSchoolYear !== '') $fields['school_year'] = $newSchoolYear;
            if ($newAdviser !== null)  $fields['adviser_id'] = $newAdviser;

            $conn = $db->getConnection();
            $conn->beginTransaction();
            try {
                foreach ($members as $m) {
                    $db->update('students', $fields, 'id = ?', [(int) $m['id']]);
                }
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Section updated. ' . count($members) . ' student(s) moved to ' . $newSection . '.',
                'updated' => count($members),
            ]);
            exit;
        }

        // ─── AUTO-ASSIGN SECTIONS ────────────────────────────
        // The headline write. Fills the gaps in existing sections
        // first and only opens a new code once every existing one is
        // at the cap, so re-running it is close to a no-op: students
        // already placed keep the code they have.
        if ($action === 'assign_sections') {
            $requestedMax = isset($input['max_per_section']) ? (int) $input['max_per_section'] : $maxPerSection;
            // A cap of 0 or 10000 both produce a list no office can act
            // on, and neither is a number anyone meant to type. Fall
            // back to the configured cap rather than honouring it.
            if ($requestedMax < 1 || $requestedMax > 500) {
                $requestedMax = $maxPerSection;
            }

            $result = autoAssignStudentSections($requestedMax);

            $message = $result['updated'] > 0
                ? 'Assigned ' . $result['updated'] . ' student(s) to a section.'
                : 'Nothing to assign. Every student with a year level already has a section.';
            if ($result['skipped'] > 0) {
                $message .= ' ' . $result['skipped'] . ' student(s) skipped because they have no year level set.';
            }

            echo json_encode([
                'success' => true,
                'message' => $message,
                'max_per_section' => $requestedMax,
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
                'sections' => $result['sections'],
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (Exception $e) {
    json_error($e);
}
