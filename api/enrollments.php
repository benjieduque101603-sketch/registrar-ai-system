<?php
// ============================================================
//  API/ENROLLMENTS.PHP
//  Registrar "Receive Student" intake pipeline.
//
//  Actions:
//   GET  ?action=list             → pending applicants from the
//                                   Enrollment System (enrollments table)
//   POST ?action=duplicate-check  → does the applicant's identity already
//                                   exist in `students`?
//   POST ?action=accept           → create the student record + related
//                                   data, mark enrollment received
//   POST ?action=re-enroll        → returning student: keep original id,
//                                   update current fields, log a new
//                                   enrollment_history row
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/normalize.php';

// Require login
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
$action = $_GET['action'] ?? '';

try {
    $db = Database::getInstance();

    // ─── LIST APPLICANTS ──────────────────────────────────────
    // Sanity: the enrollments table must exist (migration 002). Checked
    // before ANY query against it so a missing table returns an actionable
    // JSON message instead of an uncaught SQL exception.
    $hasEnrollments = (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollments'"
    ) === 1;
    if (!$hasEnrollments) {
        echo json_encode(['success' => false, 'message' => 'Enrollment system not set up. Import registrar_ai.sql first.']);
        exit;
    }

    if ($method === 'GET' && $action === 'list') {
        $rows = $db->fetchAll(
            "SELECT * FROM enrollments ORDER BY status = 'pending' DESC, id ASC"
        );
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    if ($method !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request payload.']);
        exit;
    }
    $enrollmentId = intval($input['enrollment_id'] ?? 0);

    /**
     * Load an enrollment row by id, or null.
     */
    $loadEnrollment = function(int $id) use ($db) {
        return $db->fetchOne("SELECT * FROM enrollments WHERE id = ?", [$id]);
    };

    /**
     * Append an applicant's previous-school + emergency-contact to an
     * already-existing student (used by re-enroll).
     */
    $appendIntakeExtras = function(array $enc, int $studentId) use ($db) {
        if (trim((string) ($enc['prev_school_name'] ?? '')) !== '') {
            $exists = $db->fetchOne(
                "SELECT id FROM academic_history
                 WHERE student_id = ? AND TRIM(school_name) = ?",
                [$studentId, trim($enc['prev_school_name'])]
            );
            if (!$exists) {
                $db->insert('academic_history', [
                    'student_id'  => $studentId,
                    'school_name' => trim($enc['prev_school_name']),
                    'school_year' => ($enc['prev_school_graduated_sy'] ?? '') !== '' ? $enc['prev_school_graduated_sy'] : null,
                    'grade_level' => ($enc['prev_school_last_year'] ?? '') !== '' ? $enc['prev_school_last_year'] : null,
                ]);
            }
        }
        $emergencyPhone = trim((string) ($enc['emergency_contact'] ?? ''));
        if ($emergencyPhone !== '') {
            $emergencyPhone = normalizePhone($emergencyPhone);
        }
        if (trim((string) ($enc['emergency_name'] ?? '')) !== '' && $emergencyPhone !== '' && isValidPhone($emergencyPhone)) {
            $exists = $db->fetchOne(
                "SELECT id FROM emergency_contacts
                 WHERE student_id = ? AND TRIM(full_name) = ?",
                [$studentId, trim($enc['emergency_name'])]
            );
            if (!$exists) {
                $db->insert('emergency_contacts', [
                    'student_id'     => $studentId,
                    'full_name'      => trim($enc['emergency_name']),
                    'relationship'   => ($enc['emergency_relationship'] ?? '') !== '' ? $enc['emergency_relationship'] : null,
                    'contact_number' => $emergencyPhone,
                    'is_primary'     => 1,
                ]);
            }
        }
    };

    // ─── DUPLICATE CHECK ──────────────────────────────────────
    if ($action === 'duplicate-check') {
        $enc = $loadEnrollment($enrollmentId);
        if (!$enc) {
            echo json_encode(['success' => false, 'message' => 'Enrollment not found.']);
            exit;
        }

        // Match by student_number first (if the applicant already carries one).
        $existing = null;
        $sn = trim((string) ($enc['student_number'] ?? ''));
        if ($sn !== '') {
            $existing = $db->fetchOne("SELECT * FROM students WHERE student_number = ?", [$sn]);
        }
        // Fallback identity match: same first+last name and birth date.
        if (!$existing) {
            $fn = trim((string) ($enc['first_name'] ?? ''));
            $ln = trim((string) ($enc['last_name'] ?? ''));
            $bd = $enc['birth_date'] ?? null;
            if ($fn !== '' && $ln !== '') {
                if ($bd) {
                    $existing = $db->fetchOne(
                        "SELECT * FROM students
                         WHERE LOWER(TRIM(first_name)) = LOWER(?) AND LOWER(TRIM(last_name)) = LOWER(?)
                           AND birth_date = ? LIMIT 1",
                        [$fn, $ln, $bd]
                    );
                } else {
                    // No birth date on file — match name case-insensitively as a softer check.
                    $existing = $db->fetchOne(
                        "SELECT * FROM students
                         WHERE LOWER(TRIM(first_name)) = LOWER(?) AND LOWER(TRIM(last_name)) = LOWER(?)
                         LIMIT 1",
                        [$fn, $ln]
                    );
                }
            }
        }

        if ($existing) {
            echo json_encode([
                'success' => true,
                'exists'  => true,
                'message' => 'Student already exists.',
                'student' => $existing,
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'exists'  => false,
                'message' => 'No existing record.',
                'student' => null,
            ]);
        }
        exit;
    }

    // ─── ACCEPT STUDENT ───────────────────────────────────────
    if ($action === 'accept') {
        $enc = $loadEnrollment($enrollmentId);
        if (!$enc) {
            echo json_encode(['success' => false, 'message' => 'Enrollment not found.']);
            exit;
        }
        if ($enc['status'] !== 'pending') {
            echo json_encode(['success' => false, 'message' => 'This applicant has already been processed.']);
            exit;
        }

        // Re-run the duplicate check defensively before creating.
        $sn = trim((string) ($enc['student_number'] ?? ''));
        $dup = $sn !== '' ? $db->fetchOne("SELECT id FROM students WHERE student_number = ?", [$sn]) : null;
        if (!$dup) {
            $fn = trim((string) ($enc['first_name'] ?? ''));
            $ln = trim((string) ($enc['last_name'] ?? ''));
            $bd = $enc['birth_date'] ?? null;
            if ($fn !== '' && $ln !== '' && $bd) {
                $dup = $db->fetchOne(
                    "SELECT id FROM students
                     WHERE LOWER(TRIM(first_name)) = LOWER(?) AND LOWER(TRIM(last_name)) = LOWER(?) AND birth_date = ? LIMIT 1",
                    [$fn, $ln, $bd]
                );
            }
        }
        if ($dup) {
            echo json_encode([
                'success' => false,
                'message' => 'Student already exists. Use Re-enroll if they are returning.',
                'data'    => ['student_id' => (int) $dup['id']],
            ]);
            exit;
        }

        // Build the create payload from the enrollment row.
        $payload = [
            'first_name'      => $enc['first_name'] ?? '',
            'middle_name'     => $enc['middle_name'] ?? '',
            'last_name'       => $enc['last_name'] ?? '',
            'name_suffix'     => $enc['name_suffix'] ?? '',
            'gender'          => $enc['gender'] ?? '',
            'civil_status'    => $enc['civil_status'] ?? '',
            'birth_date'      => $enc['birth_date'] ?? '',
            'place_of_birth'  => $enc['place_of_birth'] ?? '',
            'nationality'     => $enc['nationality'] ?? '',
            'religion'        => $enc['religion'] ?? '',
            'father_name'     => $enc['father_name'] ?? '',
            'mother_name'     => $enc['mother_name'] ?? '',
            'email'           => $enc['email'] ?? '',
            'address'         => $enc['address'] ?? '',
            'contact_number'  => $enc['contact_number'] ?? '',
            'course'          => $enc['course'] ?? '',
            'major'           => $enc['major'] ?? '',
            'year_level'      => $enc['year_level'] ?? '',
            'school_year'     => $enc['school_year'] ?? '',
            'semester'        => $enc['semester'] ?? '',
            'section'         => $enc['section'] ?? '',
            'prev_school_name'          => $enc['prev_school_name'] ?? '',
            'prev_school_last_year'     => $enc['prev_school_last_year'] ?? '',
            'prev_school_graduated_sy'  => $enc['prev_school_graduated_sy'] ?? '',
            'emergency_name'            => $enc['emergency_name'] ?? '',
            'emergency_relationship'    => $enc['emergency_relationship'] ?? '',
            'emergency_contact'         => $enc['emergency_contact'] ?? '',
            'status'          => 'active',
        ];

        // Data-quality pass: trim text, fix enum-bound fields and reject
        // impossible dates so bad source data can't fail the insert silently.
        foreach (['first_name', 'middle_name', 'last_name', 'name_suffix', 'place_of_birth', 'nationality', 'religion', 'father_name', 'mother_name', 'email', 'address', 'contact_number', 'course', 'major', 'school_year', 'semester', 'section', 'prev_school_name', 'prev_school_last_year', 'prev_school_graduated_sy', 'emergency_name', 'emergency_relationship', 'emergency_contact'] as $f) {
            $payload[$f] = trim((string) $payload[$f]);
        }
        $payload['emergency_contact'] = normalizePhone((string) $payload['emergency_contact']);
        if (trim((string) $payload['emergency_contact']) !== '' && !isValidPhone((string) $payload['emergency_contact'])) {
            echo json_encode(['success' => false, 'message' => 'Emergency contact number must be an 11-digit mobile number (e.g. 09171234567).']);
            exit;
        }
        if (trim((string) $payload['emergency_name']) !== '' && trim((string) $payload['emergency_contact']) === '') {
            echo json_encode(['success' => false, 'message' => 'Emergency contact number is required and must be an 11-digit mobile number (e.g. 09171234567).']);
            exit;
        }
        if (trim((string) $payload['emergency_contact']) !== '' && trim((string) $payload['emergency_name']) === '') {
            echo json_encode(['success' => false, 'message' => 'Emergency contact name is required.']);
            exit;
        }
        $payload['gender'] = in_array(strtolower(trim((string) $payload['gender'])), ['male', 'female'], true)
            ? ucfirst(strtolower(trim((string) $payload['gender']))) : null;
        $payload['civil_status'] = in_array(strtolower(trim((string) $payload['civil_status'])), ['single', 'married', 'widowed', 'separated'], true)
            ? ucfirst(strtolower(trim((string) $payload['civil_status']))) : null;
        $payload['year_level'] = ($payload['year_level'] !== '' && $payload['year_level'] !== null) ? (int) $payload['year_level'] : null;
        $bdRaw = trim((string) $payload['birth_date']);
        if ($bdRaw !== '') {
            $bdParts = explode('-', $bdRaw);
            if (count($bdParts) !== 3 || !checkdate((int) $bdParts[1], (int) $bdParts[2], (int) $bdParts[0])) {
                echo json_encode(['success' => false, 'message' => 'This applicant has an invalid birth date. Ask the Enrollment System to correct it before accepting.']);
                exit;
            }
        }

        try {
            $created = createStudentFromInput($payload, $db);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        } catch (RuntimeException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
            error_log('[enrollments.php] accept failed: ' . $e->getMessage());
            exit;
        }

        // Link any documents staged under the enrollment number.
        syncDocumentsByEnrollNo($created['student_number'], $created['id']);

        // Mark the enrollment received + link the assigned student number.
        $db->update('enrollments', [
            'status'        => 'received',
            'received_at'   => date('Y-m-d H:i:s'),
            'student_number'=> $created['student_number'],
        ], 'id = ?', [$enrollmentId]);

        logActivity($_SESSION['user_id'], 'student_received', json_encode([
            'enrollment_id'  => $enrollmentId,
            'student_id'     => $created['id'],
            'student_number' => $created['student_number'],
            'name'           => trim(($enc['first_name'] ?? '') . ' ' . ($enc['last_name'] ?? '')),
        ]), 'enrollments', $enrollmentId);

        echo json_encode([
            'success' => true,
            'message' => 'Student accepted successfully.',
            'data' => [
                'id'             => $created['id'],
                'student_number' => $created['student_number'],
                'portal_account' => $created['portal_account'],
            ],
        ]);
        exit;
    }

    // ─── RE-ENROLL STUDENT (returning) ────────────────────────
    if ($action === 're-enroll') {
        $enc = $loadEnrollment($enrollmentId);
        if (!$enc) {
            echo json_encode(['success' => false, 'message' => 'Enrollment not found.']);
            exit;
        }
        $studentId = intval($input['student_id'] ?? 0);
        $student = $db->fetchOne("SELECT * FROM students WHERE id = ?", [$studentId]);
        if (!$student) {
            echo json_encode(['success' => false, 'message' => 'Existing student record not found.']);
            exit;
        }
        if ($enc['status'] !== 'pending') {
            echo json_encode(['success' => false, 'message' => 'This applicant has already been processed.']);
            exit;
        }

        // Update the student's current fields from the enrollment.
        // student_number is deliberately NOT touched — it stays the same.
        $newFields = [];
        foreach ([
            'course', 'major', 'year_level', 'school_year', 'semester', 'section'
        ] as $field) {
            $val = $enc[$field] ?? null;
            if ($val !== null && $val !== '') {
                $newFields[$field] = $field === 'year_level' ? (int) $val : $val;
            }
        }
        $newFields['status'] = 'active';

        if (count($newFields) > 0) {
            $db->update('students', $newFields, 'id = ?', [$studentId]);
        }
        trackStatusChange($studentId, 'active', 'Re-enrolled via enrollment intake');

        // Append prev-school + emergency contact if not already present.
        $appendIntakeExtras($enc, $studentId);

        // Log the new enrollment term into enrollment_history.
        $db->insert('enrollment_history', [
            'student_id'     => $studentId,
            'student_number' => $student['student_number'],
            'course'         => $newFields['course'] ?? $student['course'],
            'year_level'     => $newFields['year_level'] ?? $student['year_level'],
            'school_year'    => $newFields['school_year'] ?? $student['school_year'],
            'semester'       => $newFields['semester'] ?? $student['semester'],
            'section'        => $newFields['section'] ?? $student['section'],
            'status'         => 'enrolled',
            'enrolled_at'    => date('Y-m-d H:i:s'),
        ]);

        // Link any documents staged under the enrollment number.
        syncDocumentsByEnrollNo($student['student_number'], $studentId);

        // Mark the enrollment re-enrolled.
        $db->update('enrollments', [
            'status'        => 're-enrolled',
            'received_at'   => date('Y-m-d H:i:s'),
            'student_number'=> $student['student_number'],
        ], 'id = ?', [$enrollmentId]);

        logActivity($_SESSION['user_id'], 'student_reenrolled', json_encode([
            'enrollment_id'  => $enrollmentId,
            'student_id'     => $studentId,
            'student_number' => $student['student_number'],
            'name'           => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')),
        ]), 'enrollments', $enrollmentId);

        echo json_encode([
            'success' => true,
            'message' => 'Student re-enrolled successfully.',
            'data' => [
                'id'             => (int) $studentId,
                'student_number' => $student['student_number'],
            ],
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action.']);

} catch (Exception $e) {
    json_error($e);
}
