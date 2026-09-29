<?php
// ============================================================
//  API/STUDENT-DOCUMENTS.PHP
//  Student portal — submit a document request (v2 workflow).
//
//  Accepts multipart/form-data (requirement file) or JSON.
//  Server-side responsibilities:
//    * resolve student_id from the session (never from the client)
//    * validate the catalog item + workflow fields
//    * compute the fee (flat / per_page / per_syllabus)
//    * generate request_id (DOC-YYYY-NNNN) + qr_hash
//    * an outstanding finance balance holds the request
//    * record WHY the request is blocked, if it is
//    * persist requirement upload + status event + audit log
// ============================================================

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/document_process.php';
require_once __DIR__ . '/../shared/schema.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
$role = getCurrentUserRole();
if (!in_array($role, ['student', 'admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Accept both multipart/form-data and raw JSON bodies.
$input = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?: []);

// Student is resolved from the session for portal users; a registrar/admin
// filing on behalf of a walk-in may pass student_id explicitly.
$studentId = (int) ($input['student_id'] ?? 0);
if (in_array($role, ['admin', 'registrar'], true) && $studentId > 0) {
    $exists = Database::getInstance()->fetchOne('SELECT id FROM students WHERE id = ?', [$studentId]);
    if (!$exists) {
        echo json_encode(['success' => false, 'message' => 'Selected student not found.']);
        exit;
    }
} else {
    $studentId = getCurrentStudentId();
}
if (!$studentId) {
    echo json_encode(['success' => false, 'message' => 'No student account is linked to this session. Contact the Registrar.']);
    exit;
}

$catalogId    = (int) ($input['catalog_id'] ?? 0);
$quantity     = max(1, (int) ($input['quantity'] ?? 1));
$requestType  = trim($input['request_type'] ?? 'Regular');
$fulfillment  = trim($input['fulfillment_type'] ?? 'Pickup');
$purpose      = trim($input['purpose'] ?? '');
// Recipient is no longer collected by the desk. A walk-in document is
// always picked up by the student it was filed for, so the field had one
// possible answer. The column stays in the schema and student intake
// still writes it; the registrar's form no longer sends it, so it lands
// as NULL here.
$recipient    = trim($input['recipient'] ?? '');
// What the clerk knows that no query can derive: the Dean's office has
// the affidavit, the ID is being reprinted, Guidance owes a signature.
// Optional, and empty means "nothing is holding this" — the common case,
// and the one that leaves the request fully actionable.
$waitingOn    = trim((string) ($input['waiting_on'] ?? ''));
if (mb_strlen($waitingOn) > 160) {
    echo json_encode(['success' => false, 'message' => 'The waiting-on note is too long (160 characters max).']);
    exit;
}
$address      = ''; // no courier — pick-up only
// Payment method: Online (mock GCash/Maya gateway) or Cash on Delivery.
// Keep the canonical DB casing ('Cash_on_Delivery') — do not uppercase, the
// enum is case-sensitive.
$paymentMethod = 'Online'; // pick-up only — paid online (GCash)

if (!in_array($requestType, ['Express', 'Regular'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request type.']);
    exit;
}
if (!in_array($fulfillment, ['Pickup', 'Digital'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid fulfillment type.']);
    exit;
}
if ($purpose === '') {
    echo json_encode(['success' => false, 'message' => 'Purpose is required.']);
    exit;
}


try {
    $db = Database::getInstance();

    $catalog = db_fill_optional(
        $db->fetchOne('SELECT * FROM document_catalog WHERE id = ? AND is_active = 1', [$catalogId]),
        'document_catalog',
        ['sla_days', 'requirement']
    );
    if (!$catalog) {
        echo json_encode(['success' => false, 'message' => 'Invalid or inactive document in the catalog.']);
        exit;
    }

    // Fee: flat → base_fee; per_page / per_syllabus → base_fee × quantity.
    $fee = round((float) $catalog['base_fee'] * ($catalog['fee_type'] === 'flat' ? 1 : $quantity), 2);

    // Courier delivery fee — quoted up-front and borne by the student.
    // Never trust the client's number: the server recomputes the same
    // deterministic quote the student saw in the fee preview.
    $deliveryFee = null; // no courier — pick-up only

    // Per-year sequence: DOC-2026-0001, DOC-2026-0002, …
    $year = date('Y');
    $seq = (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM document_requests WHERE request_id LIKE ?",
        ['DOC-' . $year . '-%']
    );
    $requestId = 'DOC-' . $year . '-' . str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT);

    $qrHash = hash('sha256', $requestId . '|' . random_bytes(16));

    // ── Balance gate (spec: SELECT balance FROM finance WHERE student_id = …) ──
    // A walk-in request is Filed. The fee is settled at the counter when
    // the document is collected, so there is no "awaiting payment" stage
    // to wait in — it blocked every request indefinitely.
    //
    // Pending_Clearance is reserved for a balance that genuinely blocks
    // issue, and it is only a LABEL: the authoritative reason lives in
    // blocked_reason and is re-derived on every desk load, so paying the
    // balance releases the request instead of stranding it here.
    $balance = (float) ($db->fetchColumn('SELECT balance FROM finance WHERE student_id = ?', [$studentId]) ?? 0.00);
    $status = $balance > 0 ? 'Pending_Clearance' : 'Filed';

    // Legacy document_type vocabulary, kept for the old column.
    $legacyTypeMap = [
        'DOC-TOR'     => 'transcript',
        'DOC-COE'     => 'certificate',
        'DOC-GM'      => 'good_moral',
        'DOC-DIPLOMA' => 'diploma',
        'DOC-CTC'     => 'ctc',
        'DOC-HD'      => 'honorable_dismissal',
        'DOC-CD'      => 'course_description',
    ];
    $legacyType = $legacyTypeMap[$catalog['sku']] ?? strtolower($catalog['sku']);

    // Requirement file upload (scanned ID / affidavit) — optional but expected.
    $reqFilePath = null;
    if (!empty($_FILES['requirement_file']) && ($_FILES['requirement_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['requirement_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Requirement file upload failed.']);
            exit;
        }
        if (!isAllowedFile($_FILES['requirement_file']['name'])) {
            echo json_encode(['success' => false, 'message' => 'Requirement file must be a PDF, JPG, or PNG image.']);
            exit;
        }
        $dir = __DIR__ . '/../uploads/document_requirements/';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = generateFilename($_FILES['requirement_file']['name']);
        if (!move_uploaded_file($_FILES['requirement_file']['tmp_name'], $dir . $name)) {
            echo json_encode(['success' => false, 'message' => 'Could not save the requirement file.']);
            exit;
        }
        $reqFilePath = 'uploads/document_requirements/' . $name;
    } elseif ($catalog['requirement']) {
        // Catalog row advertises a requirement but none was uploaded — soft warn.
        $reqFilePath = null;
    }

    $now = date('Y-m-d H:i:s');
    $conn = $db->getConnection();
    $conn->beginTransaction();

    try {
        $data = [
            'request_date'          => $now,
            'student_id'            => $studentId,
            'document_type'         => $legacyType,
            'purpose'               => $purpose,
            'recipient'             => $recipient !== '' ? $recipient : null,
            'status'                => 'pending',
            'fee_amount'            => $fee,
            'official_receipt'      => null,
            // v2 workflow fields
            'request_id'            => $requestId,
            'catalog_id'            => $catalogId,
            'quantity'              => $quantity,
            'request_type'          => $requestType,
            'fulfillment_type'      => $fulfillment,
            'delivery_address'      => $fulfillment === 'Courier' ? $address : null,
            'payment_method'        => $paymentMethod,
            'delivery_fee'          => $deliveryFee,
            'document_status'       => $status,
            'qr_hash'               => $qrHash,
            'requirement_file_path' => $reqFilePath,
            // The fee is taken at the counter when the request is filed, so
            // this is the moment payment happens. It used to be stamped when
            // the student claimed the document, which meant every revenue
            // figure was really measuring how quickly people collected
            // their paperwork. Stamping it here keeps "when were fees
            // collected" meaning what the reports say it means.
            'paid_at'               => $now,
        ];

        $id = $db->insert('document_requests', $data);

        // Record the blockage up front so the desk can see WHY a request
        // is held rather than having to infer it from a status label.
        // Derived from the same helper the desk reads, so the two agree.
        //
        // A waiting-on note from the clerk is checked BEFORE the derived
        // blocker and kept in preference to it, because it is the only one
        // of the two that is an actual decision rather than an inference.
        // It is also written under blocked_source='registrar' so a later
        // Re-check — which only knows how to recompute the balance — leaves
        // it alone instead of overwriting the clerk's judgment with NULL.
        $blocker = doc_blocker([
            'document_status'       => $status,
            'sku'                   => $catalog['sku'],
            'requirement'           => $catalog['requirement'],
            'requirement_file_path' => $reqFilePath,
            'balance'               => $balance,
            'request_date'          => $now,
            'blocked_source'        => $waitingOn !== '' ? 'registrar' : null,
            'blocked_reason'        => $waitingOn !== '' ? $waitingOn : null,
        ]);
        $blockedReason = $blocker['reason'] ?? null;
        $blockedSince  = $blocker ? (string) ($blocker['since'] ?: $now) : null;
        $blockedSource = $blockedReason !== null
            ? ($waitingOn !== '' ? 'registrar' : 'balance')
            : null;

        $holdData = [
            'blocked_reason' => $blockedReason,
            'blocked_since'  => $blockedSince,
        ];
        $holdCols = array_column($db->fetchAll('SHOW COLUMNS FROM document_requests'), 'Field');
        if (in_array('blocked_source', $holdCols, true)) $holdData['blocked_source'] = $blockedSource;
        $db->update('document_requests', $holdData, 'id = ?', [$id]);

        // Cash on Delivery — record the amount owed up front so there is a
        // payment trail; it is marked completed when the document is claimed.
        

        // Initial status event. Say plainly what this request is waiting
        // on, if anything — an event log that records only the label
        // leaves the next person to guess why it stalled.
        //
        // A named requirement is asked for, not held on. The old wording
        // read "held: Awaiting: Scanned copy of valid ID", which claimed a
        // decision nobody had made, doubled the word up, and dated the
        // hold from filing so it read as stalled on arrival.
        $needsNote = doc_requirement_note([
            'document_status'       => $status,
            'requirement'           => $catalog['requirement'],
            'requirement_file_path' => $reqFilePath,
        ]);
        $filedNote = 'Request filed at the counter (' . $requestId . ') — fee ₱'
            . number_format($fee, 2) . ', paid now';
        if ($blockedReason) {
            // Distinguish the two in the log too. "held: <reason>" is
            // accurate for a balance, but for a registrar's own note it
            // reads as an automatic system state that will clear itself.
            $filedNote .= $blockedSource === 'registrar'
                ? ' — held at the clerk\'s discretion: ' . $blockedReason
                : ' — held: ' . $blockedReason;
        }
        if ($needsNote) {
            $filedNote .= ' — ask the student to bring: ' . $needsNote;
        }
        $db->insert('document_request_events', [
            'request_id' => $id,
            'status'     => $status,
            'note'       => $filedNote,
            'created_by' => $_SESSION['user_id'] ?? null,
            'created_at' => $now,
        ]);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        // Clean up the uploaded file so nothing orphaned is left behind.
        if ($reqFilePath && file_exists(__DIR__ . '/../' . $reqFilePath)) {
            @unlink(__DIR__ . '/../' . $reqFilePath);
        }
        throw $e;
    }

    logActivity($_SESSION['user_id'], 'document_request_submit', null, 'document_requests', $id,
        null, ['request_id' => $requestId, 'catalog_id' => $catalogId, 'fee' => $fee, 'document_status' => $status]);

    // ── Emergency & Contacts: auto-forward the invoice to verified
    //    billing contacts when the request awaits payment. A mail
    //    failure must never break the submission response.
    if ($status === 'Awaiting_Payment') {
        try {
            require_once __DIR__ . '/../shared/mail_client.php';
            contactAutoForwardInvoice((int) $id, (int) $studentId);
        } catch (Throwable $e) {
            error_log('auto-invoice-forward: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'message' => $status === 'Pending_Clearance'
            ? 'Request submitted, but you have an outstanding balance (₱' . number_format($balance, 2) . ') pending clearance.'
            : 'Document request submitted.',
        'data' => ['id' => $id, 'request_id' => $requestId, 'document_status' => $status,
                   'fee' => $fee, 'delivery_fee' => $deliveryFee, 'payment_method' => $paymentMethod],
    ]);
} catch (Throwable $e) {
    json_error($e, 'Unable to submit request. Please check for outstanding balance or account status.');
}
