<?php
// ============================================================
//  API/STUDENTS.PHP
//  Student CRUD operations
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/normalize.php';
// studentPhotoUrl() / studentPhotoSelectSql() - resolving a student's
// photograph from Digital File Storage, and checking it is really on this
// server before handing the client a URL for it.
require_once __DIR__ . '/../shared/stored_file.php';

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
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

try {
    $db = Database::getInstance();

    // NOTE: syncFatherMotherGuardians() is defined in shared/functions.php
    // (moved here from this file so the shared createStudentFromInput()
    // helper and the enrollments API can reuse it without redefinition).

    // ─── GET GUARDIAN ──────────────────────────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'guardian' && isset($_GET['student_id'])) {
        $guardian = $db->fetchOne("SELECT * FROM guardians WHERE student_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1", [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $guardian]);
        exit;
    }

    // ─── GET ALL GUARDIANS (Subsystem 2 — multi) ────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'guardians' && isset($_GET['student_id'])) {
        $guardians = $db->fetchAll(
            "SELECT * FROM guardians WHERE student_id = ? ORDER BY is_primary DESC, is_emergency DESC, id ASC",
            [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $guardians]);
        exit;
    }

    // ─── SAVE GUARDIAN (add or update) ──────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save-guardian') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        $gId = intval($input['id'] ?? 0);
        if (!$studentId) { echo json_encode(['success' => false, 'message' => 'Student required.']); exit; }
        if (!in_array($input['relationship'] ?? '', ['father','mother','guardian','spouse','sibling'], true)) {
            $input['relationship'] = 'guardian';
        }
        $gData = [
            'full_name'      => trim($input['full_name'] ?? ''),
            'relationship'   => $input['relationship'],
            'contact_number' => normalizePhone(trim((string) ($input['contact_number'] ?? ''))),
            'email'          => ($input['email'] ?? '') !== '' ? trim($input['email']) : null,
            'address'        => ($input['address'] ?? '') !== '' ? trim($input['address']) : null,
            'is_primary'     => !empty($input['is_primary']) ? 1 : 0,
            'is_emergency'   => !empty($input['is_emergency']) ? 1 : 0,
        ];
        if ($gData['full_name'] === '') { echo json_encode(['success' => false, 'message' => 'Guardian name required.']); exit; }
        if ($gData['contact_number'] === '' || !isValidPhone($gData['contact_number'])) { echo json_encode(['success' => false, 'message' => 'Guardian contact number is required and must be an 11-digit mobile number (e.g. 09171234567).']); exit; }
        if ($gId > 0) {
            $db->update('guardians', $gData, 'id = ? AND student_id = ?', [$gId, $studentId]);
        } else {
            // DEFENSIVE DE-DUP: a re-save of the same guardian (same
            // relationship + full name) must not create a clone. If an
            // identical row already exists for this student, update it
            // instead of inserting a duplicate.
            $dupe = $db->fetchOne(
                "SELECT id FROM guardians
                  WHERE student_id = ? AND relationship = ? AND full_name = ?
                  ORDER BY is_primary DESC, id ASC LIMIT 1",
                [$studentId, $gData['relationship'], $gData['full_name']]
            );
            if ($dupe) {
                $gId = (int) $dupe['id'];
                $db->update('guardians', $gData, 'id = ? AND student_id = ?', [$gId, $studentId]);
            } else {
                $gData['student_id'] = $studentId;
                $gId = $db->insert('guardians', $gData);
            }
        }
        echo json_encode(['success' => true, 'message' => 'Guardian saved.', 'data' => ['id' => (int) $gId]]);
        exit;
    }

    // ─── DELETE GUARDIAN ────────────────────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete-guardian') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Guardian ID required.']); exit; }
        $db->delete('guardians', 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Guardian deleted.']);
        exit;
    }

    // ─── EMERGENCY CONTACTS (Subsystem 2) ───────────────────────
    // GET ?action=emergency&student_id=N   → list
    // POST ?action=save-emergency         → add/update
    // POST ?action=delete-emergency       → remove
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'emergency' && isset($_GET['student_id'])) {
        $rows = $db->fetchAll(
            "SELECT * FROM emergency_contacts WHERE student_id = ? ORDER BY is_primary DESC, id ASC",
            [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save-emergency') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        $id = intval($input['id'] ?? 0);
        if (!$studentId) { echo json_encode(['success' => false, 'message' => 'Student required.']); exit; }
        $eData = [
            'full_name'      => trim($input['full_name'] ?? ''),
            'relationship'   => trim($input['relationship'] ?? ''),
            'contact_number' => normalizePhone(trim((string) ($input['contact_number'] ?? ''))),
            'address'        => ($input['address'] ?? '') !== '' ? trim($input['address']) : null,
            'is_primary'     => !empty($input['is_primary']) ? 1 : 0,
        ];
        if ($eData['full_name'] === '') { echo json_encode(['success' => false, 'message' => 'Contact name required.']); exit; }
        if ($eData['contact_number'] === '' || !isValidPhone($eData['contact_number'])) { echo json_encode(['success' => false, 'message' => 'Emergency contact number is required and must be an 11-digit mobile number (e.g. 09171234567).']); exit; }
        if ($id > 0) {
            $db->update('emergency_contacts', $eData, 'id = ? AND student_id = ?', [$id, $studentId]);
        } else {
            $eData['student_id'] = $studentId;
            $id = $db->insert('emergency_contacts', $eData);
        }
        echo json_encode(['success' => true, 'message' => 'Emergency contact saved.', 'data' => ['id' => $id]]);
        exit;
    }
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete-emergency') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Contact ID required.']); exit; }
        $db->delete('emergency_contacts', 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Emergency contact deleted.']);
        exit;
    }

    // ─── GET ACADEMIC HISTORY ──────────────────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'academic' && isset($_GET['student_id'])) {
        $data = $db->fetchAll("SELECT * FROM academic_history WHERE student_id = ? ORDER BY created_at DESC", [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    // ─── GET HEALTH ────────────────────────────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'health' && isset($_GET['student_id'])) {
        $data = $db->fetchOne("SELECT * FROM health_records WHERE student_id = ?", [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    // ─── GET DOCUMENT REQUESTS ─────────────────────────────────
    // ─── GET STUDENT DOCUMENTS ───────────────────────────────────
    //
    // Returns THREE things, because a student's document story needs all three
    // and the tab used to show only one of them.
    //
    // It used to read document_requests and nothing else. That table is the
    // counter's walk-in log - a request for a CTC, a good moral, a
    // certificate. It is not a file store, and the two barely overlap:
    // document_requests.document_type is an enum of
    // (form137, good_moral, transcript, certificate, clearance) while the files
    // themselves live in documents.doc_type as
    // (enrollment, transcript, health, photo, clearance, other, form_137, psa).
    // Only "transcript" and "clearance" appear in both. So the tab listed
    // requests a clerk had made while the student's actual uploaded files -
    // sitting in Digital File Storage, the same table the photo comes from -
    // were invisible. "No document requests." was printed for students who had
    // files on file, which reads as "this student has nothing" and is exactly
    // backwards.
    //
    // The three parts:
    //   files    - what is actually stored, each with a URL that is only
    //              non-empty when the file is really on this server
    //   requests - the counter walk-ins, which is real history and still shown
    //   missing  - required types with no file, from the shared rule that
    //              registrar/file-storage.php also uses
    //
    // A document row whose file is not on disk is returned with an empty url
    // and present: false rather than being dropped, so the clerk sees that the
    // record exists and the file needs re-uploading - the distinction the File
    // Storage page makes, and the one that lets someone act.
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'documents' && isset($_GET['student_id'])) {
        $sid = intval($_GET['student_id']);

        $docs = $db->fetchAll(
            "SELECT id, doc_type, filename, file_path, file_size, file_type, description, created_at
             FROM documents
             WHERE student_id = ?
             ORDER BY created_at DESC, id DESC",
            [$sid]
        );

        $files = [];
        $typesPresent = [];
        foreach ($docs as $doc) {
            $url = storedFileUrl($doc['file_path'], '../');
            $files[] = [
                'id'         => (int) $doc['id'],
                'doc_type'   => (string) $doc['doc_type'],
                'label'      => storedDocTypeLabel((string) $doc['doc_type']),
                'filename'   => (string) $doc['filename'],
                'url'        => $url,
                'on_disk'    => $url !== '',
                'file_size'  => $doc['file_size'] !== null ? (int) $doc['file_size'] : null,
                'file_type'  => (string) ($doc['file_type'] ?? ''),
                'is_image'   => in_array(strtolower((string) ($doc['file_type'] ?? '')), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true),
                'created_at' => (string) ($doc['created_at'] ?? ''),
            ];
            // Only a file that is genuinely here counts toward completeness. A
            // database row naming a file this host never received must not make
            // a student look complete.
            if ($url !== '') {
                $typesPresent[] = (string) $doc['doc_type'];
            }
        }

        $requests = $db->fetchAll(
            "SELECT id, request_id, document_type, status, request_date
             FROM document_requests
             WHERE student_id = ?
             ORDER BY request_date DESC, id DESC",
            [$sid]
        );

        $completeness = documentCompleteness($typesPresent);

        echo json_encode([
            'success' => true,
            'data'    => [
                'files'       => $files,
                'requests'    => $requests,
                'missing'     => array_map(
                    static fn($t) => ['doc_type' => $t, 'label' => storedDocTypeLabel($t)],
                    $completeness['missing']
                ),
                'required'    => array_map(
                    static fn($t) => ['doc_type' => $t, 'label' => storedDocTypeLabel($t)],
                    requiredDocumentTypes()
                ),
                'complete'    => $completeness['complete'],
                'file_count'  => count($files),
                'gone_count'  => count(array_filter($files, static fn($f) => !$f['on_disk'])),
            ],
        ]);
        exit;
    }

    // ─── GET LAST SCAN ────────────────────────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'lastscan' && isset($_GET['student_id'])) {
        $student = $db->fetchOne("SELECT card_uid FROM rfid_cards WHERE student_id = ?", [intval($_GET['student_id'])]);
        if ($student) {
            $scan = $db->fetchOne("SELECT scanned_at, location, event_type, status FROM rfid_scan_logs WHERE card_uid = ? ORDER BY scanned_at DESC LIMIT 1", [$student['card_uid']]);
            echo json_encode(['success' => true, 'data' => $scan]);
        } else {
            echo json_encode(['success' => true, 'data' => null]);
        }
        exit;
    }

    // ─── GET ALL STUDENTS ──────────────────────────────────────
    if ($method === 'GET' && !$id) {
        $students = $db->fetchAll(
            "SELECT * FROM students ORDER BY created_at DESC"
        );
        echo json_encode(['success' => true, 'data' => $students]);
        exit;
    }

    // ─── GET SINGLE STUDENT ────────────────────────────────────
    if ($method === 'GET' && $id) {
        // `photo_path` carries the photograph held in Digital File Storage, and
        // `photo_url` is that path already resolved against this server's disk.
        //
        // The modal used to read students.photo and nothing else, and that
        // column is NULL for every student whose picture went through File
        // Storage - so the View modal showed initials for a student who had a
        // photograph on file. The list and this endpoint were wrong in the same
        // way, independently, which is why the resolution now lives in
        // shared/stored_file.php rather than in either page.
        //
        // The URL is computed server-side because deciding between a photo and
        // initials requires checking the disk: uploads/ is gitignored, so a
        // database from a copied dump or a promoted staging box names files
        // this host never received. Resolving here means the client gets '' for
        // a photograph that is not really here, and falls back to initials -
        // rather than requesting a URL that 404s.
        $student = $db->fetchOne(
            "SELECT s.*, " . studentPhotoSelectSql() . " AS photo_path
             FROM students s WHERE s.id = ?",
            [$id]
        );
        if ($student) {
            $student['photo_url'] = studentPhotoUrl($student, '../');
            // The raw column is redundant next to a resolved URL and invites
            // the old mistake of assigning it straight to a background-image.
            unset($student['photo']);
            echo json_encode(['success' => true, 'data' => $student]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
        }
        exit;
    }

    // ─── PHOTO UPLOAD — REMOVED ─────────────────────────────────
    //
    // This endpoint was deleted, not merely unused.
    //
    // It was a second, parallel way to attach a photograph: it wrote the
    // students.photo column, while Digital File Storage writes a documents row.
    // Two paths meant a student's picture could be in either place, and every
    // reader guessed differently - the student list and the View modal both
    // read students.photo alone, so a student whose photo was uploaded through
    // File Storage rendered as "RT" in both, next to a photograph sitting in the
    // database the whole time. It also re-uploaded under a fresh timestamped
    // filename every time, so re-uploading a face orphaned the previous file
    // rather than replacing it.
    //
    // File Storage is the one way in. It records the uploader, the type and the
    // description, and it is where the office already goes to manage student
    // files. See registrar/file-storage.php.
    //
    // The students.photo column is left in place, and still read as a first
    // choice by studentPhotoUrl(), so historical rows that predate File Storage
    // keep showing their picture. Nothing is dropped by removing this.

    // ─── SAVE TERM GRADES ───────────────────────────────────────
    //
    // Rewritten from "save academic history". Two changes matter.
    //
    // The GWA is computed here from the subject ratings rather than
    // accepted from the request. It used to be whatever the client typed,
    // while document_templates.php computed its own figure for the TOR and
    // status_evidence.php read the stored one - three sources, two
    // numbers. Now there is one.
    //
    // The school name is fixed to this office. The page is a term-grades
    // page for this school; the field was only ever required to satisfy a
    // NOT NULL column, and leaving it free let a term row be filed against
    // a previous school, which is what made the "Previous schools"
    // framing wrong in the first place.
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save-academic') {
        require_once __DIR__ . '/../shared/term_grades.php';

        $input    = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        $sy       = trim((string) ($input['school_year'] ?? ''));
        $sem      = trim((string) ($input['semester'] ?? ''));
        if (!$studentId) {
            echo json_encode(['success' => false, 'message' => 'Student is required.']);
            exit;
        }
        if ($sy === '' || $sem === '') {
            echo json_encode(['success' => false, 'message' => 'School year and semester are required.']);
            exit;
        }
        $student = $db->fetchOne("SELECT id, student_number FROM students WHERE id = ?", [$studentId]);
        if (!$student) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }

        $subjects = is_array($input['grades'] ?? null) ? $input['grades'] : [];

        // Validation before anything is written. A rating outside the
        // scale cannot be averaged, so storing it would put a number in
        // the record that the TOR could never reproduce.
        $problems = [];
        foreach ($subjects as $g) {
            $label = trim((string) ($g['subject'] ?? ''));
            if ($label === '') {
                continue;
            }
            $rating = $g['final_rating'] ?? null;
            if ($rating !== null && $rating !== '' && !termRatingValid($rating)) {
                $problems[] = $label . ' has a rating of ' . $rating . ', which is outside 1.00-5.00.';
            }
            $units = (float) ($g['units'] ?? 0);
            if ($units < 0 || $units > 12) {
                $problems[] = $label . ' has ' . $units . ' units. Unit counts run from 0 to 12.';
            }
        }
        if ($problems) {
            echo json_encode([
                'success' => false,
                'message' => 'Nothing was saved. Fix these first:',
                'problems' => $problems,
            ]);
            exit;
        }

        // One row per student per term. The old code matched on
        // school_name, which could not tell two terms of this school
        // apart and so would overwrite the first semester with the
        // second. The term is school_year + semester.
        $existing = $db->fetchOne(
            "SELECT id FROM academic_history
              WHERE student_id = ? AND school_year = ? AND semester = ?",
            [$studentId, $sy, $sem]
        );
        $recordId = $existing ? (int) $existing['id'] : 0;

        // Normalise the subjects once: strip empties, coerce numbers.
        $clean = [];
        $totalUnits = 0.0;
        foreach ($subjects as $g) {
            $label = trim((string) ($g['subject'] ?? ''));
            if ($label === '') {
                continue;
            }
            $units  = ($g['units'] ?? '') !== '' ? (float) $g['units'] : 0.0;
            $rating = ($g['final_rating'] ?? '') !== '' ? (float) $g['final_rating'] : null;
            $totalUnits += max(0.0, $units);
            $clean[] = [
                'subject'        => $label,
                'subject_code'   => trim((string) ($g['subject_code'] ?? '')) ?: null,
                'units'          => $units,
                'final_rating'   => $rating,
                'grade'          => trim((string) ($g['grade'] ?? '')) ?: null,
                'remarks'        => trim((string) ($g['remarks'] ?? '')) ?: null,
                'grade_status'   => trim((string) ($g['grade_status'] ?? '')) ?: null,
            ];
        }

        // The GWA, computed here. Nothing downstream can be asked for a
        // figure it has to take on trust.
        $computedGwa = termGwa($clean);

        $data = [
            'student_id'         => $studentId,
            // Fixed to this office. The column is NOT NULL and the TOR
            // labels a term row with it; leaving it free is what allowed a
            // term to be filed against a previous school.
            'school_name'        => TERM_SCHOOL_NAME,
            'school_year'        => $sy,
            'semester'           => $sem,
            'grade_level'        => trim((string) ($input['grade_level'] ?? '')) ?: null,
            'gwa'                => $computedGwa,
            'credits'            => $totalUnits > 0 ? round($totalUnits, 2) : null,
            'subjects_completed' => count($clean) ?: null,
            'remarks'            => trim((string) ($input['remarks'] ?? '')) ?: null,
        ];

        if ($recordId) {
            $db->update('academic_history', $data, 'id = ?', [$recordId]);
        } else {
            $recordId = (int) $db->insert('academic_history', $data);
        }

        // Rewrite the term's subjects. A delete-then-insert is right
        // here: the term as submitted IS the truth, and a removed subject
        // must not linger as an orphan row.
        $db->delete('academic_grades', 'academic_history_id = ?', [$recordId]);
        foreach ($clean as $g) {
            $db->insert('academic_grades', [
                'academic_history_id' => $recordId,
                'subject'      => $g['subject'],
                'subject_code' => $g['subject_code'],
                'units'        => $g['units'] ?: null,
                'final_rating' => $g['final_rating'],
                'grade'        => $g['grade'],
                'remarks'      => $g['remarks'],
                'grade_status' => $g['grade_status'],
            ]);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Term saved. ' . count($clean) . ' subject(s), GWA '
                       . ($computedGwa === null ? 'not available' : number_format($computedGwa, 2)) . '.',
            'data' => [
                'id'    => $recordId,
                'gwa'   => $computedGwa,
                'units' => $totalUnits,
            ],
        ]);
        exit;
    }

    // ─── GET ACADEMIC GRADES (Subsystem 3) ─────────────────────
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'grades' && isset($_GET['record_id'])) {
        $grades = $db->fetchAll(
            "SELECT * FROM academic_grades WHERE academic_history_id = ? ORDER BY id ASC",
            [intval($_GET['record_id'])]);
        echo json_encode(['success' => true, 'data' => $grades]);
        exit;
    }

    // ─── DELETE ACADEMIC HISTORY ───────────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete-academic') {
        $input = json_decode(file_get_contents('php://input'), true);
        $recordId = intval($input['id'] ?? 0);
        if (!$recordId) {
            echo json_encode(['success' => false, 'message' => 'Record ID required.']);
            exit;
        }
        $db->delete('academic_history', 'id = ?', [$recordId]);
        echo json_encode(['success' => true, 'message' => 'Academic record deleted.']);
        exit;
    }

    // Import previous-school records from the enrollment intake into
    // academic_history (idempotent: existing schools are skipped).
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'import-academic') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        $student = $db->fetchOne("SELECT id, student_number FROM students WHERE id = ?", [$studentId]);
        if (!$student) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }
        $imported = 0;
        $encRows = $db->fetchAll(
            "SELECT prev_school_name, prev_school_last_year, prev_school_graduated_sy
             FROM enrollments
             WHERE student_number = ? AND status IN ('received','re-enrolled')
               AND prev_school_name IS NOT NULL AND TRIM(prev_school_name) != ''",
            [$student['student_number']]
        );
        foreach ($encRows as $enc) {
            $school = trim((string) $enc['prev_school_name']);
            if ($school === '') continue;
            $exists = $db->fetchOne(
                "SELECT id FROM academic_history WHERE student_id = ? AND TRIM(school_name) = ?",
                [$studentId, $school]
            );
            if ($exists) continue;
            $db->insert('academic_history', [
                'student_id'  => $studentId,
                'school_name' => $school,
                'school_year' => ($enc['prev_school_graduated_sy'] ?? '') !== '' ? $enc['prev_school_graduated_sy'] : null,
                'grade_level' => ($enc['prev_school_last_year'] ?? '') !== '' ? $enc['prev_school_last_year'] : null,
            ]);
            $imported++;
        }
        echo json_encode([
            'success' => true,
            'message' => $imported > 0 ? 'Imported ' . $imported . ' previous-school record(s).' : 'No new records to import.',
            'data'    => ['imported' => $imported],
        ]);
        exit;
    }

    // ─── SAVE HEALTH RECORD ────────────────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save-health') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        if (!$studentId) {
            echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
            exit;
        }
        $data = [
            'blood_type'              => $input['blood_type'] ?? null,
            'allergies'               => $input['allergies'] ?? null,
            'pre_existing_conditions' => $input['pre_existing_conditions'] ?? null,
            'immunization_records'    => $input['immunization_records'] ?? null,
            'height'                  => ($input['height'] ?? '') !== '' ? (float) $input['height'] : null,
            'weight'                  => ($input['weight'] ?? '') !== '' ? (float) $input['weight'] : null,
            'notes'                   => $input['notes'] ?? null
        ];
        $existing = $db->fetchOne("SELECT id FROM health_records WHERE student_id = ?", [$studentId]);
        if ($existing) {
            $db->update('health_records', $data, 'student_id = ?', [$studentId]);
            $recordId = $existing['id'];
        } else {
            $data['student_id'] = $studentId;
            $recordId = $db->insert('health_records', $data);
        }
        echo json_encode(['success' => true, 'message' => 'Health record saved.', 'data' => ['id' => $recordId]]);
        exit;
    }

    // ─── HEALTH RECORD ── include blood_pressure / dietary_restrictions
    //     (columns added by registrar_upgrade.sql — guarded with branch)
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save-health-full') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        if (!$studentId) { echo json_encode(['success' => false, 'message' => 'Student ID is required.']); exit; }
        $data = [
            'blood_type'              => $input['blood_type'] ?? null,
            'allergies'               => $input['allergies'] ?? null,
            'pre_existing_conditions' => $input['pre_existing_conditions'] ?? null,
            'immunization_records'    => $input['immunization_records'] ?? null,
            'height'                  => ($input['height'] ?? '') !== '' ? (float) $input['height'] : null,
            'weight'                  => ($input['weight'] ?? '') !== '' ? (float) $input['weight'] : null,
            'blood_pressure'          => $input['blood_pressure'] ?? null,
            'dietary_restrictions'    => $input['dietary_restrictions'] ?? null,
            'notes'                   => $input['notes'] ?? null
        ];
        $existing = $db->fetchOne("SELECT id FROM health_records WHERE student_id = ?", [$studentId]);
        if ($existing) {
            $db->update('health_records', $data, 'student_id = ?', [$studentId]);
            $recordId = $existing['id'];
        } else {
            $data['student_id'] = $studentId;
            $recordId = $db->insert('health_records', $data);
        }
        echo json_encode(['success' => true, 'message' => 'Health record saved.', 'data' => ['id' => $recordId]]);
        exit;
    }

    // ─── HEALTH VISITS (Subsystem 4 — timeline) ────────────────
    // GET ?action=visits&student_id=N   → list visits
    // POST ?action=add-visit            → create a visit
    // POST ?action=delete-visit         → remove a visit
    $hasVisits = (int) $db->fetchColumn("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits'") === 1;
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'visits' && isset($_GET['student_id'])) {
        if (!$hasVisits) { echo json_encode(['success' => true, 'data' => []]); exit; }
        $visits = $db->fetchAll(
            "SELECT * FROM health_visits WHERE student_id = ? ORDER BY visit_date DESC, id DESC",
            [intval($_GET['student_id'])]);
        echo json_encode(['success' => true, 'data' => $visits]);
        exit;
    }

    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'add-visit') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        if (!$studentId) { echo json_encode(['success' => false, 'message' => 'Student ID is required.']); exit; }
        if (!$hasVisits) { echo json_encode(['success' => false, 'message' => 'Run registrar_upgrade.sql first (health_visits table missing).']); exit; }
        $id = $db->insert('health_visits', [
            'student_id'     => $studentId,
            'visit_date'     => ($input['visit_date'] ?? '') !== '' ? $input['visit_date'] : date('Y-m-d'),
            'complaint'      => $input['complaint'] ?? null,
            'diagnosis'      => $input['diagnosis'] ?? null,
            'temperature'    => ($input['temperature'] ?? '') !== '' ? (float) $input['temperature'] : null,
            'blood_pressure' => $input['blood_pressure'] ?? null,
            'treatment'      => $input['treatment'] ?? null,
            'medication'     => $input['medication'] ?? null,
            'physician'      => $input['physician'] ?? null,
            'notes'          => $input['notes'] ?? null,
            'created_at'     => date('Y-m-d H:i:s')
        ]);
        // bump clinic_visits counter
        $db->getConnection()->exec("UPDATE health_records hr SET hr.clinic_visits = COALESCE(hr.clinic_visits,0) + 1 WHERE hr.student_id = " . intval($studentId));
        echo json_encode(['success' => true, 'message' => 'Visit logged.', 'data' => ['id' => $id]]);
        exit;
    }

    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete-visit') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Visit ID required.']); exit; }
        $db->delete('health_visits', 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Visit deleted.']);
        exit;
    }

    // ─── BULK STATUS UPDATE ────────────────────────────────────
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'bulk-status') {
        $input  = json_decode(file_get_contents('php://input'), true);
        $ids    = $input['ids'] ?? [];
        $status = $input['status'] ?? '';
        if (empty($ids) || !$status) {
            echo json_encode(['success' => false, 'message' => 'Students and status required.']);
            exit;
        }
        // Reject anything the column cannot store.
        //
        // This check was missing, and the column is an ENUM, so the write
        // "succeeded" for any string at all. MySQL does not raise an error on an
        // out-of-enum value here - it truncates to '' - so a typo in the client,
        // or a stale page still offering probation, wrote an empty status onto
        // real students. The response said "updated", the rows became invisible
        // to every status filter, and nothing anywhere reported an error.
        if (!isValidStudentStatus($status)) {
            echo json_encode([
                'success' => false,
                'message' => '"' . $status . '" is not a valid status. Use one of: ' . implode(', ', studentStatuses()) . '.',
            ]);
            exit;
        }
        // A reason is now required, because the journal is the only record
        // of why a status changed and "Bulk status update" is not one. It
        // was optional here and the desk's Apply button never sent one, so
        // every recommendation applied from the status page was logged
        // with a null reason — the audit trail could show that a student
        // changed and nothing about why.
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            echo json_encode(['success' => false, 'message' => 'A reason is required for the status history.']);
            exit;
        }
        $meta = [];
        foreach (['effective_date', 'end_date'] as $d) {
            if (array_key_exists($d, $input)) {
                $v = trim((string) $input[$d]);
                if ($v !== '') {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                        echo json_encode(['success' => false, 'message' => 'Invalid ' . str_replace('_', ' ', $d) . '.']);
                        exit;
                    }
                    $meta[$d] = $v;
                } else {
                    $meta[$d] = null;
                }
            }
        }
        foreach ($ids as $sid) {
            $db->update('students', ['status' => $status], 'id = ?', [intval($sid)]);
            trackStatusChange(intval($sid), $status, $reason, $meta);
        }
        echo json_encode(['success' => true, 'message' => count($ids) . ' student(s) updated.']);
        exit;
    }

    // ─── CREATE STUDENT ────────────────────────────────────────
    // Delegates to the shared createStudentFromInput() helper (shared/
    // functions.php) so the manual Add form and the Receive-Student
    // Accept flow share one code path.
    // Resend the portal welcome email (also resets the temporary password).
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'resend_welcome_email') {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = intval($input['student_id'] ?? 0);
        if (!$studentId) { echo json_encode(['success' => false, 'message' => 'Student required.']); exit; }
        $student = $db->fetchOne("SELECT * FROM students WHERE id = ?", [$studentId]);
        if (!$student) { echo json_encode(['success' => false, 'message' => 'Student not found.']); exit; }
        if (trim((string) ($student['email'] ?? '')) === '' || !isValidEmail((string) $student['email'])) {
            echo json_encode(['success' => false, 'message' => 'This student has no valid email address - add one first.']); exit;
        }
        $portalUser = $db->fetchOne("SELECT * FROM users WHERE student_id = ? AND role = 'student' ORDER BY id ASC LIMIT 1", [$studentId]);
        $firstName = trim((string) $student['first_name']);
        $birthYear = (int) substr(trim((string) $student['birth_date']), 0, 4);
        $firstTwo = mb_strtolower(mb_substr($firstName, 0, 2));
        $password = '#' . $firstTwo . $birthYear;
        if ($portalUser) {
            $username = (string) ($portalUser['username'] ?? '');
            $db->update('users', [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'updated_at'    => date('Y-m-d H:i:s'),
            ], 'id = ?', [$portalUser['id']]);
        } else {
            $idDigits = preg_replace('/[^0-9]/', '', (string) $student['student_number']);
            $id9 = substr($idDigits, -9);
            $username = mb_strtolower(mb_substr($firstName, 0, 1)) . $id9;
            $dup = $db->fetchOne("SELECT id FROM users WHERE username = ?", [$username]);
            if ($dup) { $username .= '_' . date('ymd'); }
            $db->insert('users', [
                'email'         => strtolower(trim((string) $student['email'])),
                'username'      => strtolower($username),
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'full_name'     => trim((string) $student['first_name'] . ' ' . $student['last_name']),
                'role'          => 'student',
                'student_id'    => (int) $student['id'],
                'is_active'     => 1,
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
        }
        $account = [
            'username' => $username,
            'email'    => strtolower(trim((string) $student['email'])),
            'password' => $password,
            'full_name'=> trim((string) $student['first_name'] . ' ' . $student['last_name']),
        ];
        if (!function_exists('sendStudentWelcomeEmail')) {
            $ml = __DIR__ . '/../shared/mail_client.php';
            if (is_file($ml)) require_once $ml;
        }
        if (!function_exists('sendStudentWelcomeEmail') || !emailConfigured()) {
            echo json_encode(['success' => false, 'message' => 'Email sending is not configured (SMTP).']); exit;
        }
        try {
            $sent = sendStudentWelcomeEmail(
                ['id' => (int) $student['id'], 'student_number' => (string) $student['student_number']],
                $account,
                (int) ($_SESSION['user_id'] ?? 0)
            );
        } catch (Throwable $e) {
            error_log('[students.php] resend welcome failed: ' . $e->getMessage());
            $sent = ['sent' => false];
        }
        logActivity($_SESSION['user_id'] ?? 0, 'student_welcome_resend', json_encode(['student_id' => $studentId, 'sent' => !empty($sent['sent'])]), 'users', (int) ($portalUser['id'] ?? $studentId));
        echo json_encode([
            'success' => !empty($sent['sent']),
            'message' => !empty($sent['sent']) ? 'Welcome email sent to ' . trim((string) $student['email']) . '. Temporary password was reset.' : 'Email could not be sent. Check the PHP error log.'
        ]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request payload.']);
            exit;
        }

        try {
            $created = createStudentFromInput($input, $db);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        } catch (RuntimeException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
            error_log('[students.php] createStudentFromInput failed: ' . $e->getMessage());
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Student added successfully.',
            'data' => [
                'id'             => $created['id'],
                'student_number' => $created['student_number'],
                'portal_account' => $created['portal_account'],
            ],
        ]);
        exit;
    }

    // ─── UPDATE STUDENT ────────────────────────────────────────
    if ($method === 'PUT' && $id) {
        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!is_array($input)) {
                echo json_encode(['success' => false, 'message' => 'Invalid request payload.']);
                exit;
            }

            $existing = $db->fetchOne("SELECT id FROM students WHERE id = ?", [$id]);
            if (!$existing) {
                echo json_encode(['success' => false, 'message' => 'Student not found.']);
                exit;
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading student: ' . $e->getMessage()]);
            exit;
        }

        try {
            $data = [];
            // 'section' is deliberately absent.
            //
            // Section is Class Scheduling's field (DEPARTMENTS.md, #297). This
            // endpoint is the Registrar's, and it must not be able to write a
            // scheduling decision — not by a stale client, not by a crafted
            // request, not by anyone who still has the old modal open. Leaving
            // the key out of the allow-list means the UPDATE cannot include
            // the column, so the rule holds at the SQL boundary instead of
            // depending on every caller being up to date. The value is also
            // not read for validation, so a request carrying one is ignored
            // rather than rejected: an old form should still save everything
            // it legitimately owns.
            $allowedFields = ['first_name', 'middle_name', 'last_name', 'gender', 'civil_status', 'birth_date', 'place_of_birth',
                              'birth_country', 'lrn', 'name_suffix', 'mother_name', 'father_name',
                              'nationality', 'religion', 'address', 'contact_number', 'email',
                              'course', 'major', 'year_level', 'school_year', 'semester', 'adviser_id', 'status',
                              'student_number'];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $input)) {
                    $value = $input[$field];
                    if ($value === '' && in_array($field, ['birth_date', 'middle_name', 'place_of_birth', 'birth_country', 'nationality', 'religion', 'contact_number', 'email', 'course', 'major', 'year_level', 'school_year', 'semester', 'adviser_id', 'lrn', 'name_suffix', 'mother_name', 'father_name'], true)) {
                        $value = null;
                    }
                    if ($field === 'birth_date' && $value === '0000-00-00') {
                        $value = null;
                    }
                    if ($field === 'year_level' && $value !== null && $value !== '') {
                        $value = (int)$value;
                    } elseif ($field === 'year_level' && $value === '') {
                        $value = null;
                    }
                    if ($field === 'adviser_id' && $value !== null) {
                        $value = (int)$value;
                    }
                    if ($field === 'lrn' && $value !== null && $value !== '') {
                        $value = strtoupper(preg_replace('/[^0-9]/', '', (string)$value));
                    }
                    // B3: normalize text fields before saving.
                    if ($value !== null && $value !== '') {
                        if (in_array($field, ['first_name', 'middle_name', 'last_name', 'place_of_birth', 'religion', 'mother_name', 'father_name'], true)) {
                            $value = normalizeNameCase((string) $value);
                        } elseif ($field === 'contact_number') {
                            $value = normalizePhone((string) $value);
                            if ($value === '' || !isValidPhone($value)) {
                                echo json_encode(['success' => false, 'message' => 'Contact number is required and must be an 11-digit mobile number (e.g. 09171234567).']);
                                exit;
                            }
                        } elseif ($field === 'email') {
                            $value = strtolower(trim((string) $value));
                        } elseif ($field === 'course') {
                            $value = courseStandardize((string) $value);
                        } elseif ($field === 'address' || $field === 'nationality' || $field === 'major' || $field === 'school_year' || $field === 'birth_country' || $field === 'name_suffix') {
                            $value = trim((string) $value);
                        }
                    }
                    $data[$field] = $value;
                }
            }

            if (empty($data)) {
                echo json_encode(['success' => false, 'message' => 'No data to update.']);
                exit;
            }

            // Email is required on every update (welcome email needs it).
            if (array_key_exists('email', $data)) {
                if ($data['email'] === null || trim((string) $data['email']) === '') {
                    echo json_encode(['success' => false, 'message' => 'Email is required.']);
                    exit;
                }
                if (!isValidEmail((string) $data['email'])) {
                    echo json_encode(['success' => false, 'message' => 'A valid email address is required.']);
                    exit;
                }
            }
            $db->update('students', $data, 'id = ?', [$id]);
            // Track status change in status_tracker
            if (array_key_exists('status', $data)) {
                trackStatusChange($id, $data['status'], $input['status_reason'] ?? null);
            }
            // Update guardian if provided
            $guardianName = trim($input['guardian_name'] ?? '');
            $gContact = trim((string) ($input['guardian_contact'] ?? ''));
            if ($gContact !== '') {
                $gContact = normalizePhone($gContact);
                if (!isValidPhone($gContact)) {
                    echo json_encode(['success' => false, 'message' => 'Guardian contact number must be an 11-digit mobile number (e.g. 09171234567).']);
                    exit;
                }
            }
            if ($guardianName !== '') {
                if ($gContact === '') {
                    echo json_encode(['success' => false, 'message' => 'Guardian contact number is required and must be an 11-digit mobile number (e.g. 09171234567).']);
                    exit;
                }
                $existingGuardian = $db->fetchOne("SELECT id FROM guardians WHERE student_id = ?", [$id]);
                $gData = [
                    'full_name' => $guardianName,
                    'relationship' => $input['guardian_relationship'] ?? 'guardian',
                    'contact_number' => $gContact,
                    'email' => $input['guardian_email'] ?? null
                ];
                if ($existingGuardian) {
                    $db->update('guardians', $gData, 'student_id = ?', [$id]);
                } else {
                    $gData['student_id'] = $id;
                    $db->insert('guardians', $gData);
                }
            }
            // NOTE: Father/Mother → guardian auto-sync runs at ENROLLMENT
            // only (in the create path). It deliberately does NOT run on
            // updates here: re-running it would resurrect a parent guardian
            // the registrar deleted on the Contacts page, whenever the
            // student record is saved again. After enrollment the guardian
            // list is owned by the registrar (Contacts page + the
            // "Auto-fill from Enrollment" action).
            echo json_encode(['success' => true, 'message' => 'Student updated successfully.']);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error updating student: ' . $e->getMessage()]);
            exit;
        }
    }

    // ─── SOFT DELETE STUDENT ────────────────────────────────────
    //
    // This used to set status = 'archived'. 'archived' was never in the ENUM,
    // and MySQL does not raise an error on an out-of-enum value - it truncates
    // to ''. So "deactivating" a student succeeded, reported success, wrote a
    // status_tracker row saying they had been deactivated, and left the student
    // with an empty status: still in the masterlist, matching no status filter,
    // and impossible to restore to anything meaningful.
    //
    // Verified on this schema before the fix: INSERT with 'archived' reported
    // success, and reading the row back gave ''.
    //
    // A withdrawal is what the office actually records when someone leaves, so
    // that is what this writes. Real archival - hiding a record without
    // asserting anything about the student - belongs in a deleted_at column,
    // which this schema does not have; adding one is a separate decision and is
    // deliberately not smuggled in here.
    if ($method === 'DELETE' && $id) {
        $existing = $db->fetchOne("SELECT id FROM students WHERE id = ?", [$id]);
        if (!$existing) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }

        $db->update('students', ['status' => 'dropped'], 'id = ?', [$id]);
        trackStatusChange($id, 'dropped', 'Student withdrawn');
        echo json_encode(['success' => true, 'message' => 'Student marked as dropped.']);
        exit;
    }

    // ─── INVALID REQUEST ───────────────────────────────────────
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);

} catch (Exception $e) {
    json_error($e);
}
?>
