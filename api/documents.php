<?php
// ============================================================
//  API/DOCUMENTS.PHP
//  Document management API — lifecycle transitions.
//
//  Progress and blockage are two independent facts, stored as two
//  independent fields:
//
//    document_status  where the work is: Filed → Processing →
//                     Ready → Claimed, plus Rejected.
//    blocked_reason   what it is waiting on; blocked_since is when
//                     that started. Both null = nothing blocking it.
//
//  A request can sit at Filed AND be blocked on the Dean's clearance
//  at the same time. Collapsing those into one status is what made
//  multi-day requests invisible: they looked identical to ones the
//  clerk simply had not started yet.
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/document_process.php';

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

    // ─── DIGITAL FILE STORAGE (Subsystem 9) ─────────────────────
    // Routed FIRST so ?section=files requests are never captured by
    // the generic document-request handlers below.
    // GET ?section=files&student_id=N            → list student's stored files
    // GET ?section=files&all=1                   → list all stored files
    // POST ?section=files (multipart)            → upload a file
    // POST ?section=files&action=delete          → delete a file
    if (isset($_GET['section']) && $_GET['section'] === 'files') {
        $db = Database::getInstance();

        // ── LIST FILES ──
        if ($method === 'GET') {
            $studentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
            if ($studentId) {
                $files = $db->fetchAll(
                    "SELECT d.*, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.student_number
                     FROM documents d
                     LEFT JOIN students s ON d.student_id = s.id
                     WHERE d.student_id = ?
                     ORDER BY d.created_at DESC",
                    [$studentId]
                );
            } else {
                $files = $db->fetchAll(
                    "SELECT d.*, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.student_number
                     FROM documents d
                     LEFT JOIN students s ON d.student_id = s.id
                     ORDER BY d.created_at DESC"
                );
            }
            echo json_encode(['success' => true, 'data' => $files]);
            exit;
        }

        // ── UPLOAD FILE ──
        if ($method === 'POST' && !isset($_GET['action'])) {
            $studentId = intval($_POST['student_id'] ?? 0);
            $enrollNo  = trim((string) ($_POST['enroll_no'] ?? ''));
            $docType   = trim($_POST['doc_type'] ?? 'other');
            $category  = trim($_POST['category'] ?? '');
            $desc      = trim($_POST['description'] ?? '');

            // Either a student or an enrollment number must be provided.
            if (!$studentId && $enrollNo === '') {
                echo json_encode(['success' => false, 'message' => 'Select a student or enter an enrollment number.']);
                exit;
            }
            if (!isset($_FILES['file'])) {
                echo json_encode(['success' => false, 'message' => 'File is required.']);
                exit;
            }

            // Enrollment-number staging: if the number matches a student,
            // link the document immediately; otherwise store it pending.
            $enrollStatus = 'linked';
            if (!$studentId) {
                $matched = $db->fetchOne("SELECT id, student_number FROM students WHERE student_number = ?", [$enrollNo]);
                if ($matched) {
                    $studentId = (int) $matched['id'];
                } else {
                    $enrollStatus = 'pending';
                }
            }
            if (!$studentId) $enrollNo = $enrollNo;
            $file = $_FILES['file'];
            $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','webp','txt','odt','ods','zip','rar'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                echo json_encode(['success' => false, 'message' => 'File type not allowed.']);
                exit;
            }
            if ($file['size'] > 25 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'File too large (max 25 MB).']);
                exit;
            }
            if (!in_array($docType, ['enrollment','transcript','health','photo','clearance','other','form_137','psa'], true)) {
                $docType = 'other';
            }

            // Compute SHA-256 hash for duplicate detection
            $fileHash = hash_file('sha256', $file['tmp_name']);

            // Pre-upload duplicate check: warn if same hash exists for this student
            $existingDupe = null;
            if ($studentId && $fileHash) {
                $existingDupe = $db->fetchOne(
                    "SELECT id, filename, doc_type FROM documents WHERE student_id = ? AND file_hash = ?",
                    [$studentId, $fileHash]
                );
            }

            $dirKey = $studentId ? ('student_files/' . $studentId) : ('student_files/staged_' . preg_replace('/[^A-Za-z0-9._-]/', '-', $enrollNo));
            $dir = __DIR__ . '/../uploads/' . $dirKey;
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $filename = ($studentId ? (string) $studentId : $enrollNo) . '_' . time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '-', basename($file['name']));
            $dest = $dir . '/' . $filename;

            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $filePath = '../uploads/' . $dirKey . '/' . $filename;
                // Guard against missing columns on older schemas
                $cols = $db->fetchAll("SHOW COLUMNS FROM documents");
                $colNames = array_column($cols, 'Field');
                $ins = [
                    'student_id'  => $studentId ?: null,
                    'enroll_no'   => $studentId ? null : $enrollNo,
                    'enroll_status' => $enrollStatus,
                    'doc_type'    => $docType,
                    'filename'    => basename($file['name']),
                    'file_path'   => $filePath,
                    'file_size'   => $file['size'],
                    'file_type'   => $ext,
                    'description' => $desc,
                    'uploaded_by' => $_SESSION['user_id'] ?? null,
                    'created_at'  => date('Y-m-d H:i:s')
                ];
                if (in_array('category', $colNames, true)) {
                    $ins['category'] = $category !== '' ? $category : $docType;
                }
                if (in_array('file_hash', $colNames, true)) {
                    $ins['file_hash'] = $fileHash;
                }
                $id = $db->insert('documents', $ins);
                $response = ['success' => true, 'message' => 'File uploaded.', 'data' => ['id' => $id]];
                if ($existingDupe) {
                    $response['duplicate_warning'] = "This file is identical to an existing upload: {$existingDupe['filename']} ({$existingDupe['doc_type']})";
                }
                echo json_encode($response);
            } else {
                echo json_encode(['success' => false, 'message' => 'Upload failed.']);
            }
            exit;
        }

        // ── DELETE FILE ──
        if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete') {
            $input = json_decode(file_get_contents('php://input'), true);
            $id = intval($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'File ID required.']);
                exit;
            }
            $row = $db->fetchOne("SELECT file_path FROM documents WHERE id = ?", [$id]);
            if ($row) {
                $abs = __DIR__ . '/../' . ltrim($row['file_path'], './');
                $abs = str_replace(['\\', '//'], ['/', '/'], $abs);
                if (file_exists($abs)) @unlink($abs);
                $db->delete('documents', 'id = ?', [$id]);
            }
            echo json_encode(['success' => true, 'message' => 'File deleted.']);
            exit;
        }

        // --- NOTIFY STUDENTS WITH MISSING DOCUMENTS ---
        if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'notify_missing') {
            $requiredTypes = ['enrollment','transcript','health','photo','clearance'];
            $students = $db->fetchAll("SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name FROM students WHERE status != 'archived'");
            $presentRows = $db->fetchAll("SELECT DISTINCT student_id, doc_type FROM documents WHERE doc_type IS NOT NULL");
            $presentByStudent = [];
            foreach ($presentRows as $pr) {
                $presentByStudent[(int)$pr['student_id']][] = $pr['doc_type'];
            }
            $notified = 0;
            foreach ($students as $st) {
                $present = $presentByStudent[(int)$st['id']] ?? [];
                $missing = array_diff($requiredTypes, $present);
                if (count($missing) === 0) continue;
                $message = 'Reminder: you have missing required documents in the Digital File Storage (' . implode(', ', $missing) . '). Please upload them as soon as possible.';
                logActivity(
                    $_SESSION['user_id'],
                    'documents_missing_reminder',
                    json_encode(['student_id' => (int)$st['id'], 'student' => $st['name'], 'missing' => array_values($missing), 'message' => $message]),
                    'documents',
                    (int)$st['id']
                );
                $notified++;
            }
            echo json_encode(['success' => true, 'message' => $notified > 0 ? ($notified . ' student(s) notified about missing documents.') : 'All students have complete documents.']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown file action.']);
        exit;
    }

    // ─── GET ALL DOCUMENT REQUESTS ─────────────────────────────
    if ($method === 'GET' && !$id) {
        $documents = $db->fetchAll("
            SELECT 
                dr.*,
                CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                s.student_number,
                u.full_name AS processed_by_name
            FROM document_requests dr
            LEFT JOIN students s ON dr.student_id = s.id
            LEFT JOIN users u ON dr.processed_by = u.id
            ORDER BY dr.id DESC
        ");
        echo json_encode(['success' => true, 'data' => $documents]);
        exit;
    }

    // ─── GET SINGLE DOCUMENT REQUEST ───────────────────────────
    if ($method === 'GET' && $id) {
        $document = $db->fetchOne(
            "SELECT 
                dr.*,
                CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                s.student_number,
                u.full_name AS processed_by_name
            FROM document_requests dr
            LEFT JOIN students s ON dr.student_id = s.id
            LEFT JOIN users u ON dr.processed_by = u.id
            WHERE dr.id = ?",
            [$id]
        );
        if ($document) {
            echo json_encode(['success' => true, 'data' => $document]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Document request not found.']);
        }
        exit;
    }

    // ─── CREATE DOCUMENT REQUEST ───────────────────────────────
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $studentId = $input['student_id'] ?? null;
        $documentType = $input['document_type'] ?? null;
        $purpose = $input['purpose'] ?? null;
        $recipient = $input['recipient'] ?? null;
        $feeAmount = isset($input['fee_amount']) && $input['fee_amount'] !== '' ? (float) $input['fee_amount'] : 0.00;
        $officialReceipt = trim($input['official_receipt'] ?? '');

        if (!$studentId || !$documentType) {
            echo json_encode(['success' => false, 'message' => 'Student ID and document type are required.']);
            exit;
        }

        $data = [
            'student_id' => $studentId,
            'document_type' => $documentType,
            'purpose' => $purpose,
            'recipient' => $recipient,
            'status' => 'pending',
            'fee_amount' => $feeAmount,
            'official_receipt' => $officialReceipt !== '' ? $officialReceipt : null,
            'request_date' => date('Y-m-d H:i:s'),
            // The fee is taken at the counter when the request is filed,
            // so this is the moment payment happens. It used to be stamped
            // when the student claimed the document, which made every
            // revenue figure depend on how quickly people picked up their
            // paperwork rather than on when they actually paid.
            'paid_at' => date('Y-m-d H:i:s')
        ];

        // Only insert fee/receipt columns if they exist (idempotent migration guard)
        $cols = $db->fetchAll("SHOW COLUMNS FROM document_requests");
        $colNames = array_column($cols, 'Field');
        if (!in_array('fee_amount', $colNames, true)) unset($data['fee_amount']);
        if (!in_array('official_receipt', $colNames, true)) unset($data['official_receipt']);
        if (!in_array('paid_at', $colNames, true)) unset($data['paid_at']);

        $id = $db->insert('document_requests', $data);

        echo json_encode(['success' => true, 'message' => 'Document request submitted.', 'data' => ['id' => $id]]);
        exit;
    }

    // ─── UPDATE DOCUMENT REQUEST STATUS ────────────────────────
    if ($method === 'PUT' && $id) {
        $input = json_decode(file_get_contents('php://input'), true);
        $v2Action = $input['action'] ?? null;

        // ── v2 workflow transitions (document_status) ──────────────
        if ($v2Action === 'recheck') {
            // Re-derive the blockage from current data. The old
            // Pending_Clearance was decided once at intake and never
            // revisited, so a student who paid their balance the next
            // day stayed held indefinitely with nobody to notice.
            // This asks the question again, on demand.
            //
            // It only ever recomputes the BALANCE. A hold a person set is
            // left exactly as they left it — doc_refresh_blocker() returns
            // early on one — so the message here must not claim the hold
            // was re-derived. It says what is actually true: still held,
            // and by whose decision.
            $before = $db->fetchOne(
                "SELECT document_status, blocked_reason FROM document_requests WHERE id = ?", [$id]
            );
            $wasManual = doc_hold_source((int) $id) === 'registrar';
            doc_refresh_blocker((int) $id);
            $after = $db->fetchOne(
                "SELECT document_status, blocked_reason FROM document_requests WHERE id = ?", [$id]
            );
            $isManual = doc_hold_source((int) $id) === 'registrar';
            $held = ($after['blocked_reason'] ?? null) !== null;
            $why  = ($isManual ? 'Held at your discretion: ' : 'Still waiting: ') . $after['blocked_reason'];
            echo json_encode([
                'success' => true,
                'message' => !$held
                    ? 'Nothing is holding this request now.'
                    : ($isManual ? $why . ' — re-checking will not clear it.' : $why),
                'data'    => [
                    'id'              => (int) $id,
                    'was'             => $before['blocked_reason'] ?? null,
                    'document_status' => $after['document_status'],
                    'blocked_reason'  => $after['blocked_reason'],
                    'manual'          => $isManual,
                    'changed'         => ($before['blocked_reason'] ?? null) !== ($after['blocked_reason'] ?? null),
                ],
            ]);
            exit;
        }

        // ── Lift a hold the registrar set ──────────────────────────
        // The counterpart to the Waiting-on field on the new-request
        // form. A decision a person made has to be reversible by a
        // person, and there was no way to undo it: the only controls on
        // a held row were Re-check (which, correctly, ignores a manual
        // hold) and the status buttons. A hold set by mistake, or for a
        // reason that has since been resolved, was permanent.
        //
        // Only a 'registrar' hold can be lifted this way. A balance
        // hold is not the clerk's to clear from the desk; it goes when
        // the money lands, and doc_set_registrar_hold() refuses it.
        if ($v2Action === 'lift_hold') {
            // SELECT * again, for the same reason as doc_set_registrar_hold():
            // naming blocked_source here would fatal on a server where the
            // migration has not been applied. The check below uses ?? so a
            // missing column reads as "not a manual hold" — which is the
            // correct answer, because without the column no manual hold can
            // have been recorded.
            $current = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$id]);
            if (!$current) {
                echo json_encode(['success' => false, 'message' => 'Document request not found.']);
                exit;
            }
            if (($current['blocked_source'] ?? null) !== 'registrar') {
                $balanceHeld = ($current['blocked_reason'] ?? null) !== null;
                echo json_encode([
                    'success' => false,
                    'message' => $balanceHeld
                        ? 'This is held by the student\'s balance, not by you. It clears itself once the balance is settled.'
                        : 'Nothing is holding this request.',
                ]);
                exit;
            }
            $was = $current['blocked_reason'];
            doc_set_registrar_hold((int) $id, '', $_SESSION['user_id'] ?? null);
            echo json_encode([
                'success' => true,
                'message' => 'Hold lifted. This request is back on your queue.',
                'data'    => ['id' => (int) $id, 'was' => $was, 'blocked_reason' => null],
            ]);
            exit;
        }
        if (in_array($v2Action, ['process', 'ready', 'reject', 'claim'], true)) {
            $req = $db->fetchOne(
                "SELECT dr.*
                   FROM document_requests dr
                  WHERE dr.id = ?",
                [$id]
            );
            if (!$req) {
                echo json_encode(['success' => false, 'message' => 'Document request not found.']);
                exit;
            }
            $cur = $req['document_status'];
            $now = date('Y-m-d H:i:s');
            $userId = $_SESSION['user_id'];

            $newStatus = null; $legacy = null; $note = null; $reason = null;
            $approvalReason = null; $releaseDate = null; $receipt = null;
            switch ($v2Action) {
                case 'process':
                    // Filed and Pending_Clearance are both actionable by the
                    // registrar: the first has no block, the second has been
                    // cleared of its balance since it was filed.
                    if (!in_array($cur, ['Filed', 'Pending_Clearance', 'Processing'], true)) {
                        echo json_encode(['success' => false, 'message' => 'Only Filed or Pending Clearance requests can be started.']);
                        exit;
                    }
                    $newStatus = 'Processing'; $legacy = 'processing'; $note = 'Started preparing the document';
                    break;
                case 'ready':
                    if ($cur !== 'Processing') {
                        echo json_encode(['success' => false, 'message' => 'Only requests being prepared can be marked ready.']);
                        exit;
                    }
                    $approvalReason = trim($input['approval_reason'] ?? '');
                    $releaseDate    = trim($input['release_date'] ?? '');
                    if ($approvalReason === '') {
                        echo json_encode(['success' => false, 'message' => 'An approval reason is required.']);
                        exit;
                    }
                    if ($releaseDate === '') {
                        echo json_encode(['success' => false, 'message' => 'A release date must be set by the registrar.']);
                        exit;
                    }
                    $newStatus = 'Ready'; $legacy = 'approved';
                    $note = 'Signed and ready for collection (' . $releaseDate . ') — ' . $approvalReason;
                    break;
                case 'reject':
                    $reason = trim($input['rejection_reason'] ?? '');
                    if ($reason === '') {
                        echo json_encode(['success' => false, 'message' => 'Rejection reason is required.']);
                        exit;
                    }
                    $newStatus = 'Rejected'; $legacy = 'denied'; $note = 'Rejected — ' . $reason;
                    break;
                case 'claim':
                    // Payment is taken when the request is filed, so by the
                    // time a document is claimed the money is already in.
                    // Claiming records the hand-over and the receipt that
                    // identifies it; it does not settle anything, and saying
                    // it does would put a second, later charge in front of a
                    // student who has already paid.
                    if ($cur !== 'Ready') {
                        echo json_encode(['success' => false, 'message' => 'Only requests marked ready can be claimed.']);
                        exit;
                    }
                    $receipt = trim($input['official_receipt'] ?? '');
                    $newStatus = 'Claimed'; $legacy = 'released';
                    $note = 'Claimed by the student'
                          . ($receipt !== '' ? ' — OR ' . $receipt : '');
                    break;
            }

            $data = ['document_status' => $newStatus, 'status' => $legacy];
            if ($v2Action === 'ready') {
                $data['ready_at']        = $now;
                $data['approval_reason'] = $approvalReason;
                $data['release_date']    = $releaseDate;
            }
            if ($v2Action === 'claim') {
                $data['claimed_at'] = $now;
                // paid_at is deliberately NOT set here. It is stamped when
                // the request is filed, because that is when the fee is
                // taken. Writing it again here would overwrite the real
                // payment time with the hand-over time, and a report on
                // "when are fees collected" would then be measuring the
                // wrong moment.
                if ($receipt !== '') {
                    $data['official_receipt'] = $receipt;
                }
            }
            if ($v2Action === 'reject') $data['rejection_reason'] = $reason;
            if (in_array($v2Action, ['ready', 'reject', 'claim'], true)) {
                $data['processed_date'] = $now;
                $data['processed_by']   = $userId;
                $data['released_by']    = $userId;
                $data['counter']        = (int) ($input['counter'] ?? ($req['counter'] ?? 1));
            }
            if ($v2Action === 'claim') {
                $data['completed_date'] = $now;
            }

            // Guard: only update columns that exist in the table.
            $cols = $db->fetchAll('SHOW COLUMNS FROM document_requests');
            $colNames = array_column($cols, 'Field');
            foreach (array_keys($data) as $k) {
                if (!in_array($k, $colNames, true)) unset($data[$k]);
            }

            $db->update('document_requests', $data, 'id = ?', [$id]);

            // Moving the work forward resolves whatever was holding it:
            // a started document is no longer "awaiting" its requirement,
            // and a collected one is waiting on nobody. Without this the
            // blockage outlives the fact that caused it.
            if (in_array($v2Action, ['process', 'ready', 'claim', 'reject'], true)
                && in_array('blocked_reason', $colNames, true)) {
                $clearAt = $v2Action === 'process' ? $now : null;
                $db->update('document_requests', [
                    'blocked_reason' => null,
                    'blocked_since'  => $clearAt,
                ], 'id = ?', [$id]);
            }

            $db->insert('document_request_events', [
                'request_id' => $id,
                'status'     => $newStatus,
                'note'       => $note,
                'created_by' => $userId,
                'created_at' => $now,
            ]);

            logActivity($userId, 'document_request_' . $v2Action, null, 'document_requests', $id);

            // Tell the student their request moved. Without this the portal
            // bell only ever carried data-quality reminders, so a student
            // had no in-app way to learn their document was ready or refused.
            $docLabel = documentTypeLabel($req['document_type'] ?? '');
            $studentId = (int) ($req['student_id'] ?? 0);
            if ($studentId && $docLabel !== '') {
                $notifyMap = [
                    'process' => ['Your document is being prepared', 'info'],
                    'ready'   => ['Your document is ready for pickup', 'success'],
                    'reject'  => ['Your document request was rejected', 'error'],
                    'claim'   => ['Your document was released', 'success'],
                ];
                if (isset($notifyMap[$v2Action])) {
                    [$nTitle, $nType] = $notifyMap[$v2Action];
                    notifyStudent(
                        $studentId,
                        $nTitle . ' — ' . $docLabel,
                        $note,
                        $nType,
                        (int) $id,
                        $userId
                    );
                }
            }

            echo json_encode(['success' => true, 'message' => 'Request updated.', 'data' => ['id' => $id, 'document_status' => $newStatus]]);
            exit;
        }

        $status = $input['status'] ?? null;
        $denialReason = $input['denial_reason'] ?? null;

        if (!$status) {
            echo json_encode(['success' => false, 'message' => 'Status is required.']);
            exit;
        }

        $data = ['status' => $status];
        // Optional fee / receipt / release fields (guarded for old schema)
        if (array_key_exists('fee_amount', $input) && $input['fee_amount'] !== '') {
            $data['fee_amount'] = (float) $input['fee_amount'];
        }
        if (array_key_exists('official_receipt', $input)) {
            $data['official_receipt'] = trim($input['official_receipt']) !== '' ? trim($input['official_receipt']) : null;
        }
        if ($status === 'approved' || $status === 'completed' || $status === 'released') {
            $data['processed_date'] = date('Y-m-d H:i:s');
            $data['processed_by'] = $_SESSION['user_id'];
        }
        if ($status === 'denied') {
            $data['denial_reason'] = $denialReason;
            $data['processed_date'] = date('Y-m-d H:i:s');
            $data['processed_by'] = $_SESSION['user_id'];
        }
        if ($status === 'completed' || $status === 'released') {
            $data['completed_date'] = date('Y-m-d H:i:s');
        }
        if ($status === 'released') {
            $data['release_date'] = date('Y-m-d H:i:s');
        }

        // Guard: only update columns that exist in the table
        $cols = $db->fetchAll("SHOW COLUMNS FROM document_requests");
        $colNames = array_column($cols, 'Field');
        foreach (array_keys($data) as $k) {
            if (!in_array($k, $colNames, true)) unset($data[$k]);
        }

        $db->update('document_requests', $data, 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Document request updated.']);
        exit;
    }

    // ─── DELETE DOCUMENT REQUEST ───────────────────────────────
    if ($method === 'DELETE' && $id) {
        $db->delete('document_requests', 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Document request deleted.']);
        exit;
    }

    // ─── INVALID REQUEST ───────────────────────────────────────
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);

} catch (Exception $e) {
    json_error($e);
}
?>
