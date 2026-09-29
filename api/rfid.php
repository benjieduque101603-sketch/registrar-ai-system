<?php
// ============================================================
//  API/RFID.PHP
//  RFID card management + inventory API
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/ai_client.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
if (!in_array(getCurrentUserRole(), ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? intval($_GET['id']) : null;
$action = $_GET['action'] ?? '';

try {
    $db = Database::getInstance();

    // CHECK UID EXISTS
    if ($method === 'GET' && isset($_GET['check_uid'])) {
        $uid = trim($_GET['check_uid']);
        $card = $db->fetchOne(
            "SELECT rf.id, rf.status, CONCAT(s.first_name, ' ', s.last_name) AS student FROM rfid_cards rf LEFT JOIN students s ON rf.student_id = s.id WHERE rf.card_uid = ?",
            [$uid]
        );
        echo json_encode(['exists' => !!$card, 'student' => $card ? $card['student'] : null, 'status' => $card ? $card['status'] : null]);
        exit;
    }

    // GET AVAILABLE CARDS
    if ($method === 'GET' && $action === 'available') {
        $cards = $db->fetchAll(
            "SELECT id, card_uid, registered_at FROM rfid_cards WHERE status = 'available' AND student_id IS NULL ORDER BY card_uid ASC LIMIT 500"
        );
        echo json_encode(['success' => true, 'data' => $cards]);
        exit;
    }

    // PREVIEW REGISTER — validate UIDs without inserting
    if ($method === 'POST' && $action === 'preview-register') {
        $input = json_decode(file_get_contents('php://input'), true);
        $uids = $input['uids'] ?? [];

        if (empty($uids) || !is_array($uids)) {
            echo json_encode(['success' => false, 'message' => 'No card UIDs provided.']);
            exit;
        }
        if (count($uids) > 1000) {
            echo json_encode(['success' => false, 'message' => 'Maximum 1000 cards per batch.']);
            exit;
        }

        $valid = [];
        $formatErrors = [];
        $dupesInList = [];
        $alreadyRegistered = [];
        $seen = [];
        foreach ($uids as $raw) {
            $uid = trim((string) $raw);
            if ($uid === '') continue;
            if (!preg_match('/^\d{10}$/', $uid)) {
                $formatErrors[] = $uid;
                continue;
            }
            if (in_array($uid, $seen, true)) {
                $dupesInList[] = $uid;
                continue;
            }
            $seen[] = $uid;
            $valid[] = $uid;
        }

        if (!empty($valid)) {
            $placeholders = implode(',', array_fill(0, count($valid), '?'));
            $existingRows = $db->fetchAll("SELECT card_uid, status FROM rfid_cards WHERE card_uid IN ($placeholders)", $valid);
            foreach ($existingRows as $row) {
                $alreadyRegistered[] = ['uid' => $row['card_uid'], 'status' => $row['status']];
            }
        }

        $existingUids = array_column($alreadyRegistered, 'uid');
        $newUids = array_values(array_diff($valid, $existingUids));

        echo json_encode([
            'success' => true,
            'summary' => [
                'total_input' => count($uids),
                'new' => count($newUids),
                'already_registered' => count($alreadyRegistered),
                'duplicates_in_list' => count($dupesInList),
                'format_errors' => count($formatErrors),
            ],
            'new_uids' => $newUids,
            'already_registered' => $alreadyRegistered,
            'dupes_in_list' => $dupesInList,
            'format_errors' => $formatErrors,
        ]);
        exit;
    }
    // BULK REGISTER CARDS
    if ($method === 'POST' && $action === 'register') {
        $input = json_decode(file_get_contents('php://input'), true);
        $uids = $input['uids'] ?? [];
        $notes = trim($input['notes'] ?? '');

        if (empty($uids) || !is_array($uids)) {
            echo json_encode(['success' => false, 'message' => 'No card UIDs provided.']);
            exit;
        }
        if (count($uids) > 1000) {
            echo json_encode(['success' => false, 'message' => 'Maximum 1000 cards per batch.']);
            exit;
        }

        $clean = [];
        $dupes = [];
        $errors = [];
        $seen = [];
        foreach ($uids as $raw) {
            $uid = trim((string) $raw);
            if ($uid === '') continue;
            if (!preg_match('/^\d{10}$/', $uid)) {
                $errors[] = $uid . ' (not 10 digits)';
                continue;
            }
            if (in_array($uid, $seen, true)) {
                $dupes[] = $uid . ' (duplicate in list)';
                continue;
            }
            $seen[] = $uid;
            $clean[] = $uid;
        }

        if (empty($clean)) {
            echo json_encode(['success' => false, 'message' => 'No valid UIDs.', 'errors' => $errors, 'dupes' => $dupes]);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($clean), '?'));
        $existingRows = $db->fetchAll("SELECT card_uid FROM rfid_cards WHERE card_uid IN ($placeholders)", $clean);
        $existingUids = array_column($existingRows, 'card_uid');

        $registered = 0;
        $skipped = [];
        foreach ($clean as $uid) {
            if (in_array($uid, $existingUids, true)) {
                $skipped[] = $uid;
                continue;
            }
            $db->insert('rfid_cards', [
                'card_uid' => $uid,
                'card_type' => 'rfid',
                'status' => 'available',
                'notes' => $notes ?: null,
                'registered_at' => date('Y-m-d H:i:s'),
            ]);
            $registered++;
        }

        echo json_encode([
            'success' => true,
            'message' => "$registered card(s) registered.",
            'registered' => $registered,
            'skipped' => $skipped,
            'errors' => $errors,
            'dupes_in_list' => $dupes,
        ]);
        exit;
    }

    // DISTRIBUTION ASSISTANT: PREVIEW
    if ($method === 'GET' && $action === 'distribution-preview') {
        $available = $db->fetchAll("SELECT id, card_uid, registered_at FROM rfid_cards WHERE status = 'available' AND student_id IS NULL ORDER BY registered_at ASC, card_uid ASC LIMIT 1000");
        // Which students to offer a card to, and in what order.
        //
        // This named 'archived' (which the column could not store, so the guard
        // never excluded anything) and ordered by probation / at-risk / loa,
        // which no longer exist. The CASE therefore ranked every real student
        // as 6 and fell through to name order, and the status filter it applied
        // to rfid_cards.status mixed student statuses into a card-status list.
        //
        // Now: only students who could actually attend get a card, ordered
        // active first then enrolled, and the card-status filter is the card's
        // own vocabulary.
        $studentsWithoutCards = $db->fetchAll("SELECT s.id, s.student_number, s.first_name, s.middle_name, s.last_name, s.course, s.year_level, s.status FROM students s WHERE COALESCE(s.status, 'enrolled') IN ('active','enrolled') AND NOT EXISTS (SELECT 1 FROM rfid_cards r WHERE r.student_id = s.id AND r.status IN ('active','enrolled')) ORDER BY CASE COALESCE(s.status, 'enrolled') WHEN 'active' THEN 1 WHEN 'enrolled' THEN 2 ELSE 3 END, s.last_name, s.first_name, s.id LIMIT 1000");
        // Active students get first pick; enrolled follow. A graduate, an alum
        // and a withdrawal are not offered a card at all - they are not on
        // campus, and the filter above now excludes them for real.
        $priority = ['active' => 1, 'enrolled' => 2];
        usort($studentsWithoutCards, static function (array $a, array $b) use ($priority): int { return ($priority[$a['status'] ?? 'enrolled'] ?? 3) <=> ($priority[$b['status'] ?? 'enrolled'] ?? 3) ?: strcasecmp((string)$a['last_name'], (string)$b['last_name']) ?: (int)$a['id'] <=> (int)$b['id']; });
        $assignments = [];
        foreach (array_slice($studentsWithoutCards, 0, count($available)) as $index => $student) {
            $card = $available[$index];
            $fullName = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
            $assignments[] = ['card_id' => (int)$card['id'], 'card_uid' => (string)$card['card_uid'], 'student_id' => (int)$student['id'], 'student_name' => $fullName, 'student_number' => (string)($student['student_number'] ?? ''), 'course' => (string)($student['course'] ?? ''), 'year_level' => $student['year_level'], 'status' => (string)($student['status'] ?? 'active'), 'eligible' => $fullName !== '', 'reason' => $fullName === '' ? 'Needs review: student has no usable name.' : 'Eligible student with no active RFID card.'];
        }
        $skipped = array_values(array_filter($assignments, static fn(array $row): bool => !$row['eligible']));
        $assignments = array_values(array_filter($assignments, static fn(array $row): bool => $row['eligible']));
        $aiSummary = aiGenerate('You are a registrar RFID distribution reviewer. Summarize the proposed plan in two concise sentences. Do not invent students or cards, do not make changes, and remind the registrar to review every proposed pair.', json_encode(['available_cards' => count($available), 'eligible_students' => count($studentsWithoutCards), 'proposed' => count($assignments), 'needs_review' => count($skipped)]), ['max_tokens' => 180, 'temperature' => 0.1]);
        $fallbackSummary = sprintf('%d available card(s) can be reviewed for %d student(s) without an active card.', count($available), count($studentsWithoutCards));
        echo json_encode(['success' => true, 'data' => ['summary' => $fallbackSummary, 'ai_summary' => $aiSummary !== '' ? $aiSummary : $fallbackSummary, 'available_cards' => count($available), 'eligible_students' => count($studentsWithoutCards), 'proposed' => count($assignments), 'needs_review' => count($skipped), 'unpaired_cards' => max(0, count($available) - count($assignments)), 'assignments' => $assignments, 'skipped' => $skipped]]);
        exit;
    }

    // DISTRIBUTION ASSISTANT: APPLY CONFIRMED PAIRS
    if ($method === 'POST' && $action === 'distribution-apply') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $pairs = $input['assignments'] ?? [];
        $issuedDate = trim((string)($input['issued_date'] ?? date('Y-m-d')));
        $expiryDate = trim((string)($input['expiry_date'] ?? date('Y-m-d', strtotime('+1 year'))));
        $notes = trim((string)($input['notes'] ?? 'Bulk RFID distribution'));
        if (!is_array($pairs) || empty($pairs) || count($pairs) > 1000) {
            echo json_encode(['success' => false, 'message' => 'Select at least one valid assignment (maximum 1000).']);
            exit;
        }
        require_once __DIR__ . '/../shared/qr_generator.php';
        $conn = $db->getConnection();
        $conn->beginTransaction();
        $applied = [];
        try {
            foreach ($pairs as $pair) {
                $cardId = (int)($pair['card_id'] ?? 0);
                $studentId = (int)($pair['student_id'] ?? 0);
                $expectedCardUid = trim((string)($pair['expected_card_uid'] ?? ''));
                $expectedStudentNumber = trim((string)($pair['expected_student_number'] ?? ''));
                if (!$cardId || !$studentId) throw new RuntimeException('Each assignment needs a card and student.');
                $card = $db->fetchOne("SELECT id, card_uid, status, student_id FROM rfid_cards WHERE id = ? FOR UPDATE", [$cardId]);
                $student = $db->fetchOne("SELECT id, student_number, status FROM students WHERE id = ? FOR UPDATE", [$studentId]);
                if ($expectedCardUid !== '' && (!$card || $card['card_uid'] !== $expectedCardUid)) throw new RuntimeException('One selected card changed after the preview.');
                if ($expectedStudentNumber !== '' && (!$student || (string)($student['student_number'] ?? '') !== $expectedStudentNumber)) throw new RuntimeException('One selected student changed after the preview.');
                if (!$card || $card['status'] !== 'available' || $card['student_id'] !== null) throw new RuntimeException('One selected card is no longer available.');
                // Eligibility, and the "already has a card" check.
                //
                // The eligibility guard compared the student to 'archived', a
                // value students.status could not hold, so it never excluded
                // anyone. The duplicate-card check then listed probation, at-risk
                // and loa inside a query against rfid_cards.status - a different
                // enum entirely - so it was checking a card status for names that
                // belong to a student's record. Both are now stated in terms of
                // the statuses that actually exist, and the card check uses the
                // card's own vocabulary.
                if (!$student || !in_array($student['status'] ?? '', ['active', 'enrolled'], true)) throw new RuntimeException('One selected student is no longer eligible.');
                $existing = $db->fetchOne("SELECT id FROM rfid_cards WHERE student_id = ? AND status IN ('active','enrolled') LIMIT 1", [$studentId]);
                if ($existing) throw new RuntimeException('One selected student already has an active RFID card.');
                $db->update('rfid_cards', ['student_id' => $studentId, 'status' => 'active', 'issued_date' => $issuedDate, 'expiry_date' => $expiryDate, 'assigned_at' => date('Y-m-d H:i:s'), 'notes' => $notes], 'id = ?', [$cardId]);
                $qrPath = generateStudentQrFile($studentId);
                $existingSid = $db->fetchOne("SELECT id, id_number, qr_code_path FROM student_ids WHERE student_id = ? AND id_type = 'school_id' AND status = 'active'", [$studentId]);
                if ($existingSid) {
                    $sidUpdates = [];
                    if (empty($existingSid['qr_code_path']) && $qrPath) $sidUpdates['qr_code_path'] = $qrPath;
                    if ($sidUpdates) $db->update('student_ids', $sidUpdates, 'id = ?', [$existingSid['id']]);
                } else {
                    $sidData = ['student_id' => $studentId, 'id_type' => 'school_id', 'id_number' => '', 'issue_date' => $issuedDate, 'expiry_date' => $expiryDate, 'status' => 'active', 'rfid_card_id' => $cardId];
                    if ($qrPath) $sidData['qr_code_path'] = $qrPath;
                    $db->insert('student_ids', $sidData);
                }
                $db->insert('audit_logs', ['user_id' => (int)($_SESSION['user_id'] ?? 0), 'action' => 'rfid_bulk_assignment', 'table_name' => 'rfid_cards', 'record_id' => $cardId, 'old_values' => json_encode(['status' => 'available', 'student_id' => null]), 'new_values' => json_encode(['status' => 'active', 'student_id' => $studentId, 'card_uid' => $card['card_uid']]), 'created_at' => date('Y-m-d H:i:s')]);
                $applied[] = ['card_id' => $cardId, 'card_uid' => $card['card_uid'], 'student_id' => $studentId];
            }
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        echo json_encode(['success' => true, 'message' => count($applied) . ' RFID card(s) assigned successfully.', 'data' => ['applied' => $applied]]);
        exit;
    }


    // QUICK ASSIGN
    if ($method === 'POST' && $action === 'quick-assign') {
        $input = json_decode(file_get_contents('php://input'), true);
        $cardId = intval($input['card_id'] ?? 0);
        $studentId = intval($input['student_id'] ?? 0);
        $issuedDate = $input['issued_date'] ?? date('Y-m-d');
        $expiryDate = $input['expiry_date'] ?? date('Y-m-d', strtotime('+1 year'));
        $notes = $input['notes'] ?? '';

        if (!$cardId || !$studentId) {
            echo json_encode(['success' => false, 'message' => 'Card ID and Student ID are required.']);
            exit;
        }

        $card = $db->fetchOne("SELECT id, card_uid, status FROM rfid_cards WHERE id = ?", [$cardId]);
        if (!$card || $card['status'] !== 'available') {
            echo json_encode(['success' => false, 'message' => 'Card is not available for assignment.']);
            exit;
        }

        $existingStudent = $db->fetchOne("SELECT id FROM rfid_cards WHERE student_id = ? AND status = 'active'", [$studentId]);
        if ($existingStudent) {
            echo json_encode(['success' => false, 'message' => 'Student already has an active card.']);
            exit;
        }

        $db->update('rfid_cards', [
            'student_id' => $studentId,
            'status' => 'active',
            'issued_date' => $issuedDate,
            'expiry_date' => $expiryDate,
            'assigned_at' => date('Y-m-d H:i:s'),
            'notes' => $notes ?: null,
        ], 'id = ?', [$cardId]);

        require_once __DIR__ . '/../shared/qr_generator.php';
        $qrPath = generateStudentQrFile($studentId);

        $existingSid = $db->fetchOne("SELECT id, id_number, qr_code_path FROM student_ids WHERE student_id = ? AND id_type = 'school_id' AND status = 'active'", [$studentId]);
        if ($existingSid) {
            $updates = [];
            if (empty($existingSid['qr_code_path']) && $qrPath) $updates['qr_code_path'] = $qrPath;
            if ($updates) $db->update('student_ids', $updates, 'id = ?', [$existingSid['id']]);
        } else {
            $sidData = ['student_id' => $studentId, 'id_type' => 'school_id', 'id_number' => '', 'issue_date' => $issuedDate, 'expiry_date' => $expiryDate, 'status' => 'active', 'rfid_card_id' => $cardId];
            if ($qrPath) $sidData['qr_code_path'] = $qrPath;
            $db->insert('student_ids', $sidData);
        }

        echo json_encode(['success' => true, 'message' => 'Card ' . $card['card_uid'] . ' assigned successfully.', 'data' => ['id' => $cardId]]);
        exit;
    }

    // ARCHIVE CARD
    if ($method === 'POST' && $action === 'archive') {
        $input = json_decode(file_get_contents('php://input'), true);
        $cardId = intval($input['card_id'] ?? 0);
        $reason = trim($input['reason'] ?? '');

        if (!$cardId) {
            echo json_encode(['success' => false, 'message' => 'Card ID is required.']);
            exit;
        }

        $card = $db->fetchOne("SELECT id, status FROM rfid_cards WHERE id = ?", [$cardId]);
        if (!$card) {
            echo json_encode(['success' => false, 'message' => 'Card not found.']);
            exit;
        }

        $db->update('rfid_cards', ['status' => 'archived', 'archive_reason' => $reason ?: null], 'id = ?', [$cardId]);
        $db->query("UPDATE student_ids SET status = 'inactive' WHERE rfid_card_id = ?", [$cardId]);

        echo json_encode(['success' => true, 'message' => 'Card archived.']);
        exit;
    }

    // INVENTORY STATS
    if ($method === 'GET' && $action === 'inventory-stats') {
        $counts = $db->fetchOne("SELECT COUNT(*) AS total, SUM(status = 'active') AS active, SUM(status = 'available' AND student_id IS NULL) AS available, SUM(status = 'expired') AS expired, SUM(status = 'lost') AS lost, SUM(status = 'archived') AS archived FROM rfid_cards");

        $weeklyRows = $db->fetchAll("SELECT YEAR(assigned_at) AS yr, WEEK(assigned_at) AS wk, COUNT(*) AS cnt FROM rfid_cards WHERE assigned_at IS NOT NULL AND assigned_at >= DATE_SUB(NOW(), INTERVAL 12 WEEK) GROUP BY yr, wk ORDER BY yr, wk");
        $avgWeekly = 0;
        if (!empty($weeklyRows)) {
            $totalAssigned = array_sum(array_column($weeklyRows, 'cnt'));
            $avgWeekly = round($totalAssigned / count($weeklyRows), 1);
        }
        $runway = $avgWeekly > 0 && ($counts['available'] ?? 0) > 0 ? round($counts['available'] / $avgWeekly, 1) : (($counts['available'] ?? 0) > 0 ? 999 : 0);

        $batches = $db->fetchAll("SELECT DATE(registered_at) AS batch_date, COUNT(*) AS cnt, MIN(notes) AS notes FROM rfid_cards WHERE registered_at IS NOT NULL GROUP BY DATE(registered_at) ORDER BY batch_date DESC LIMIT 5");
        $expiringSoon = $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE status = 'active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)") ?: 0;

        echo json_encode(['success' => true, 'data' => [
            'total' => (int)($counts['total'] ?? 0), 'active' => (int)($counts['active'] ?? 0), 'available' => (int)($counts['available'] ?? 0), 'expired' => (int)($counts['expired'] ?? 0), 'lost' => (int)($counts['lost'] ?? 0), 'archived' => (int)($counts['archived'] ?? 0),
            'weekly_rate' => $avgWeekly, 'runway_weeks' => $runway, 'batches' => $batches, 'expiring_soon' => (int)$expiringSoon,
        ]]);
        exit;
    }

    // GET ALL RFID CARDS
    if ($method === 'GET' && !$id && !$action) {
        $studentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : null;
        $sel = "rf.*, CONCAT(s.first_name, ' ', s.last_name) AS student_name, s.student_number, s.course, s.year_level, s.photo AS student_photo, (SELECT d.file_path FROM documents d WHERE d.student_id = s.id AND d.doc_type = 'photo' AND LOWER(d.file_type) IN ('jpg', 'jpeg', 'png', 'webp', 'gif') ORDER BY d.created_at DESC, d.id DESC LIMIT 1) AS id_photo, si.id_number AS student_id_number, si.qr_code_path, si.id_type AS id_type, si.issue_date AS id_issue_date, si.expiry_date AS id_expiry_date, si.status AS id_status";
        $from = "FROM rfid_cards rf LEFT JOIN students s ON rf.student_id = s.id LEFT JOIN student_ids si ON si.rfid_card_id = rf.id AND si.id_type = 'school_id'";
        if ($studentId) {
            $cards = $db->fetchAll("SELECT $sel $from WHERE rf.student_id = ? ORDER BY rf.id DESC", [$studentId]);
        } else {
            $cards = $db->fetchAll("SELECT $sel $from ORDER BY rf.id DESC");
        }
        echo json_encode(['success' => true, 'data' => $cards]);
        exit;
    }

    // GET SINGLE RFID CARD
    if ($method === 'GET' && $id) {
        $card = $db->fetchOne(
            "SELECT rf.*, CONCAT(s.first_name, ' ', s.last_name) AS student_name, s.student_number, s.course, s.year_level, s.photo AS student_photo, (SELECT d.file_path FROM documents d WHERE d.student_id = s.id AND d.doc_type = 'photo' AND LOWER(d.file_type) IN ('jpg', 'jpeg', 'png', 'webp', 'gif') ORDER BY d.created_at DESC, d.id DESC LIMIT 1) AS id_photo, si.id_number AS student_id_number FROM rfid_cards rf LEFT JOIN students s ON rf.student_id = s.id LEFT JOIN student_ids si ON si.rfid_card_id = rf.id AND si.id_type = 'school_id' WHERE rf.id = ?",
            [$id]
        );
        if ($card) {
            echo json_encode(['success' => true, 'data' => $card]);
        } else {
            echo json_encode(['success' => false, 'message' => 'RFID card not found.']);
        }
        exit;
    }

    // ASSIGN RFID CARD (legacy: type UID)
    if ($method === 'POST' && !$action) {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentId = $input['student_id'] ?? null;
        $cardUid = trim($input['card_uid'] ?? '');
        $issuedDate = $input['issued_date'] ?? date('Y-m-d');
        $expiryDate = $input['expiry_date'] ?? date('Y-m-d', strtotime('+1 year'));
        $notes = $input['notes'] ?? '';

        if (!$studentId) { echo json_encode(['success' => false, 'message' => 'Student ID is required.']); exit; }
        if (empty($cardUid)) { echo json_encode(['success' => false, 'message' => 'Card UID is required.']); exit; }
        if (!preg_match('/^\d{10}$/', $cardUid)) { echo json_encode(['success' => false, 'message' => 'Card UID must be exactly 10 digits.']); exit; }

        $existing = $db->fetchOne("SELECT id, status FROM rfid_cards WHERE card_uid = ?", [$cardUid]);
        if ($existing && $existing['status'] !== 'available') {
            echo json_encode(['success' => false, 'message' => 'Card UID already exists.']);
            exit;
        }
        $existingStudent = $db->fetchOne("SELECT id FROM rfid_cards WHERE student_id = ? AND status = 'active'", [$studentId]);
        if ($existingStudent) {
            echo json_encode(['success' => false, 'message' => 'Student already has an active card.']);
            exit;
        }

        if ($existing && $existing['status'] === 'available') {
            $newId = $existing['id'];
            $db->update('rfid_cards', ['student_id' => $studentId, 'status' => 'active', 'issued_date' => $issuedDate, 'expiry_date' => $expiryDate, 'assigned_at' => date('Y-m-d H:i:s'), 'notes' => $notes ?: null], 'id = ?', [$newId]);
        } else {
            $newId = $db->insert('rfid_cards', ['student_id' => $studentId, 'card_uid' => $cardUid, 'card_type' => 'rfid', 'status' => 'active', 'issued_date' => $issuedDate, 'expiry_date' => $expiryDate, 'notes' => $notes, 'assigned_at' => date('Y-m-d H:i:s')]);
        }

        require_once __DIR__ . '/../shared/qr_generator.php';
        $qrPath = generateStudentQrFile(intval($studentId));
        $existingSid = $db->fetchOne("SELECT id, id_number, qr_code_path FROM student_ids WHERE student_id = ? AND id_type = 'school_id' AND status = 'active'", [$studentId]);
        if ($existingSid) {
            $updates = [];
            if (empty($existingSid['qr_code_path']) && $qrPath) $updates['qr_code_path'] = $qrPath;
            if ($updates) $db->update('student_ids', $updates, 'id = ?', [$existingSid['id']]);
        } else {
            $sidData = ['student_id' => $studentId, 'id_type' => 'school_id', 'id_number' => '', 'issue_date' => $issuedDate, 'expiry_date' => $expiryDate, 'status' => 'active', 'rfid_card_id' => $newId];
            if ($qrPath) $sidData['qr_code_path'] = $qrPath;
            $db->insert('student_ids', $sidData);
        }

        echo json_encode(['success' => true, 'message' => 'Card assigned successfully.', 'data' => ['id' => $newId]]);
        exit;
    }

    // UPDATE RFID CARD
    if ($method === 'PUT' && $id) {
        $input = json_decode(file_get_contents('php://input'), true);
        $data = [];
        $allowedFields = ['student_id', 'status', 'issued_date', 'expiry_date', 'notes', 'archive_reason'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) $data[$field] = $input[$field];
        }
        if (empty($data)) { echo json_encode(['success' => false, 'message' => 'No data to update.']); exit; }
        $db->update('rfid_cards', $data, 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Card updated successfully.']);
        exit;
    }

    // DELETE RFID CARD
    if ($method === 'DELETE' && $id) {
        $db->query("UPDATE student_ids SET status = 'inactive' WHERE rfid_card_id = ?", [$id]);
        $db->delete('rfid_cards', 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Card deleted successfully.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
} catch (Exception $e) {
    json_error($e);
}
?>
