<?php
// ============================================================
//  API/MASTERLIST.PHP
//  Auto-assign sections & masterlist helpers
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
        $courseFilter = isset($_GET['course']) ? trim((string) $_GET['course']) : '';
        $yearFilter = isset($_GET['year_level']) ? trim((string) $_GET['year_level']) : '';

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

        $sql .= " ORDER BY TRIM(course) ASC, COALESCE(year_level, 0) ASC, section ASC, last_name ASC, first_name ASC";

        $students = $db->fetchAll($sql, $params);
        $groups = groupStudentsForMasterlist($students);

        echo json_encode([
            'success' => true,
            'max_per_section' => $maxPerSection,
            'total' => count($students),
            'groups' => $groups,
        ]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }

        $action = $input['action'] ?? '';

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
        if ($action === 'next_section') {
            $course = trim((string) ($input['course'] ?? ''));
            $year = isset($input['year_level']) ? (int) $input['year_level'] : 0;
            $semesterRaw = trim((string) ($input['semester'] ?? ''));
            $semester = ($semesterRaw !== '' && in_array($semesterRaw, ['1st', '2nd', 'summer'], true)) ? $semesterRaw : null;

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
            $ids = array_map('intval', (array) ($input['ids'] ?? []));
            $section = trim((string) ($input['section'] ?? ''));
            if (empty($ids) || $section === '') {
                echo json_encode(['success' => false, 'message' => 'Select students and a section.']);
                exit;
            }

            // Context fields are stamped onto each assigned student so the
            // masterlist grouping stays coherent.
            $fields = ['section' => $section];
            foreach (['course', 'school_year'] as $col) {
                $v = trim((string) ($input[$col] ?? ''));
                if ($v !== '') $fields[$col] = $v;
            }
            if (isset($input['year_level']) && $input['year_level'] !== '') {
                $fields['year_level'] = (int) $input['year_level'];
            }
            if (isset($input['semester']) && $input['semester'] !== '') {
                $fields['semester'] = $input['semester'];
            }
            if (isset($input['adviser_id']) && $input['adviser_id'] !== '') {
                $fields['adviser_id'] = (int) $input['adviser_id'];
            }

            // A section code is derived from the year level, so a student with
            // no year level cannot hold a section. When this request does not
            // stamp a year level, reject any selected student that lacks one
            // rather than writing a section that contradicts their record.
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
                        fn($r) => trim($r['first_name'] . ' ' . $r['last_name']),
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

            echo json_encode(['success' => true, 'message' => count($ids) . ' student(s) assigned to section ' . $section . '.', 'updated' => count($ids)]);
            exit;
        }

        // ─── EDIT SECTION (rename/move ALL students in it) ──
        if ($action === 'edit_section') {
            // Identify the section to edit via its old context + code
            $oldCourse  = trim((string) ($input['old_course'] ?? ''));
            $oldYear    = isset($input['old_year_level']) ? (int) $input['old_year_level'] : 0;
            $oldSemRaw  = trim((string) ($input['old_semester'] ?? ''));
            $oldSem     = ($oldSemRaw !== '' && in_array($oldSemRaw, ['1st', '2nd', 'summer'], true)) ? $oldSemRaw : null;
            $oldSection = trim((string) ($input['old_section'] ?? ''));

            if ($oldCourse === '' || $oldSection === '') {
                echo json_encode(['success' => false, 'message' => 'Missing section to edit.']);
                exit;
            }

            // Find students currently in the old section
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

            // New values
            $newCourse  = trim((string) ($input['course'] ?? $oldCourse));
            $newYear    = isset($input['year_level']) && $input['year_level'] !== '' ? (int) $input['year_level'] : $oldYear;
            $newSemRaw  = trim((string) ($input['semester'] ?? ''));
            $newSem     = ($newSemRaw !== '' && in_array($newSemRaw, ['1st', '2nd', 'summer'], true)) ? $newSemRaw : $oldSem;
            $newSection = trim((string) ($input['section'] ?? $oldSection));
            $newSchoolYear = trim((string) ($input['school_year'] ?? ''));
            $newAdviser = isset($input['adviser_id']) && $input['adviser_id'] !== '' ? (int) $input['adviser_id'] : null;

            if ($newCourse === '' || $newSection === '') {
                echo json_encode(['success' => false, 'message' => 'Course and section code are required.']);
                exit;
            }

            // Uniqueness: if the new code differs from the old, ensure it's free
            // in the target course/year/semester (excluding the members being moved).
            if ($newSection !== $oldSection) {
                $collision = $db->fetchOne(
                    "SELECT id FROM students
                     WHERE TRIM(course) = ? AND year_level = ?
                       AND TRIM(IFNULL(semester, '')) = ? AND TRIM(section) = ?
                       AND id NOT IN (" . implode(',', array_map('intval', array_column($members, 'id'))) . ")
                     LIMIT 1",
                    [$newCourse, $newYear, (string) $newSem, $newSection]
                );
                if ($collision) {
                    echo json_encode(['success' => false, 'message' => 'Section code ' . $newSection . ' is already in use for ' . $newCourse . ' / Year ' . $newYear . '.']);
                    exit;
                }
            }

            // Stamp new values onto every member of the old section
            $fields = ['section' => $newSection, 'course' => $newCourse, 'year_level' => $newYear, 'semester' => $newSem];
            if ($newSchoolYear !== '') $fields['school_year'] = $newSchoolYear;
            if ($newAdviser !== null) $fields['adviser_id'] = $newAdviser;

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

            echo json_encode(['success' => true, 'message' => 'Section updated. ' . count($members) . ' student(s) moved to ' . $newSection . '.', 'updated' => count($members)]);
            exit;
        }

        // ─── AUTO-ASSIGN SECTIONS (unchanged) ────────────────
        if ($action === 'assign_sections') {
            $requestedMax = isset($input['max_per_section']) ? (int) $input['max_per_section'] : $maxPerSection;
            if ($requestedMax < 1 || $requestedMax > 500) {
                $requestedMax = $maxPerSection;
            }

            $result = autoAssignStudentSections($requestedMax);

            $message = 'Sections assigned successfully.';
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

    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
} catch (Exception $e) {
    json_error($e);
}
