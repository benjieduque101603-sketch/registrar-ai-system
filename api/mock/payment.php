<?php
// ============================================================
//  API/MOCK/PAYMENT.PHP
//  Payment gateway entry point for the Document Request module.
//
//  Dual gateway behind one endpoint (the browser only talks to this):
//    gateway 'mock'     → the built-in simulated provider; runs the
//                         whole flow with no keys at all:
//                         action=create  → {payment_url, TXN-MOCK-####}
//                         action=webhook → simulate COMPLETED/FAILED
//    gateway 'paymongo' → real GCash via PayMongo sandbox when a
//                         secret key is configured (paymongoConfigured()).
//                         create  → hosted GCash checkout (Payment
//                                   Source); webhook/check_status confirm
//                                   via PayMongo.
//  Other actions: cod_collected, status, check_status.
// ============================================================

// config.php must load FIRST: corsSameOrigin() is defined there, and the
// session cookie flags in security_headers.php must be set before
// session_config.php calls session_start().
require_once __DIR__ . '/../../shared/config.php';
require_once __DIR__ . '/../../shared/security_headers.php';
require_once __DIR__ . '/../../shared/session_config.php';
require_once __DIR__ . '/../../shared/csrf_guard.php';
require_once __DIR__ . '/../../shared/database.php';
require_once __DIR__ . '/../../shared/functions.php';
require_once __DIR__ . '/../../shared/paymongo_client.php';

header('Content-Type: application/json');
// Same-origin only. This was 'Access-Control-Allow-Origin: *', which is
// not directly exploitable while no Allow-Credentials header is sent
// (browsers withhold the session cookie then), but it breaks the moment
// credentials are added — and it advertises the endpoint to any origin.
corsSameOrigin();
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = trim($input['action'] ?? ($_GET['action'] ?? ''));

try {
    $db = Database::getInstance();
    $now = date('Y-m-d H:i:s');
    $role = getCurrentUserRole();

    if ($action === 'create') {
        $studentId = (int) ($input['student_id'] ?? 0);
        $requestId = (int) ($input['request_id'] ?? 0);
        $currency  = strtoupper(trim($input['currency'] ?? 'PHP'));
        $callback  = trim($input['callback_url'] ?? '');
        // Payment method: Online (GCash/Maya gateway) or Cash_on_Delivery.
        // Keep the canonical DB casing — do not uppercase, the enum is case-sensitive.
        $method = 'Online'; // pick-up only — no cash on delivery

        // A student may only create payments against their own record.
        if ($role === 'student') {
            $studentId = getCurrentStudentId();
        } elseif (!in_array($role, ['admin', 'registrar'], true)) {
            echo json_encode(['success' => false, 'message' => 'Forbidden.']);
            exit;
        }
        if (!$studentId) {
            echo json_encode(['success' => false, 'message' => 'A valid student_id is required.']);
            exit;
        }

        // Request-backed payments must use the amount stored with the request.
        // Never trust a client-supplied amount: otherwise a student can pay
        // PHP 0.01 and still advance the request to Processing.
        $amount = 0.0;

        if ($requestId) {
            $req = $db->fetchOne(
                "SELECT dr.*, c.fee_type, c.base_fee
                   FROM document_requests dr
                   LEFT JOIN document_catalog c ON c.id = dr.catalog_id
                  WHERE dr.id = ?",
                [$requestId]
            );
            if (!$req) {
                echo json_encode(['success' => false, 'message' => 'Document request not found.']);
                exit;
            }
            if ((int) $req['student_id'] !== $studentId && !in_array($role, ['admin', 'registrar'], true)) {
                echo json_encode(['success' => false, 'message' => 'Forbidden.']);
                exit;
            }
            if (($req['document_status'] ?? '') === 'Pending_Clearance') {
                echo json_encode([
                    'success' => false,
                    'message' => 'Cannot pay while clearance is pending.',
                    'data'    => ['blocked' => true],
                ]);
                exit;
            }
            if (($req['document_status'] ?? '') !== 'Awaiting_Payment') {
                echo json_encode(['success' => false, 'message' => 'This request is not awaiting payment.']);
                exit;
            }
            // fee_amount is authoritative (base_fee × qty already applied at
            // submit); the student also pays the courier delivery fee.
            $amount = round((float) ($req['fee_amount'] ?? 0), 2);
            if (isset($req['delivery_fee']) && (float) $req['delivery_fee'] > 0) {
                $amount += round((float) $req['delivery_fee'], 2);
            }
            if ($amount <= 0 && isset($req['base_fee'])) {
                $amount = round((float) $req['base_fee'] * max(1, (int) $req['quantity']), 2);
                if (isset($req['delivery_fee']) && (float) $req['delivery_fee'] > 0) {
                    $amount += round((float) $req['delivery_fee'], 2);
                }
            }
        }

        if ($amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'A positive amount is required.']);
            exit;
        }

        // ── Gateway selection ──────────────────────────────────────
        // gateway 'paymongo' → real GCash (PayMongo sandbox) when a secret
        // key is configured; otherwise 'mock' keeps the simulated gateway
        // working exactly as before. COD never hits a gateway.
        $gateway          = 'mock';
        $paymongoIntentId = null;
        $resumeTxn        = null;

        if ($method === 'Online' && paymongoConfigured()) {
            // Resume an existing pending intent for this request instead of
            // creating a duplicate on repeated "Pay Online" clicks.
            $resumeTxn = $db->fetchOne(
                "SELECT * FROM mock_payment_transactions
                  WHERE request_id = ? AND gateway = 'paymongo' AND status = 'pending'
                  ORDER BY id DESC LIMIT 1",
                [$requestId]
            );
        }

        if ($resumeTxn) {
            $gateway          = 'paymongo';
            $paymongoIntentId = $resumeTxn['paymongo_intent_id'];
            $txn              = $resumeTxn['transaction_id'];
            $paymentUrl       = $resumeTxn['payment_url'];
            $amount           = round((float) $resumeTxn['amount'], 2);
        } else {
            if ($method === 'Online' && paymongoConfigured()) {
                $txn = 'TXN-PM-' . strtoupper(bin2hex(random_bytes(5)));
                // Hosted GCash checkout lives on the Payment Sources API
                // (Payment Intents don't return a checkout_url in sandbox).
                // redirect URLs must be absolute — build them from this request.
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $retUrl = $scheme . '://' . $host . app_url('student/documents.php');
                $source = paymongoCreateSource(
                    (int) round($amount * 100),
                    'Document request ' . ($req['request_id'] ?? ('#' . $requestId)),
                    $retUrl,
                    $retUrl,
                    'txn-' . $txn
                );
                $paymongoIntentId = is_array($source) ? (string) ($source['id'] ?? '') : '';
                $checkoutUrl      = is_array($source) ? ($source['attributes']['redirect']['checkout_url'] ?? '') : '';
                if ($paymongoIntentId === '' || $checkoutUrl === '') {
                    error_log('paymongo: create source returned no id/checkout_url for request ' . $requestId);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Payment gateway is temporarily unavailable. Please try again.',
                    ]);
                    exit;
                }
                $gateway          = 'paymongo';
                $paymentUrl       = $checkoutUrl;
            } else {
                $txn = 'TXN-MOCK-' . mt_rand(1000, 9999);
                for ($i = 0; $i < 5 && $db->fetchOne('SELECT id FROM mock_payment_transactions WHERE transaction_id = ?', [$txn]); $i++) {
                    $txn = 'TXN-MOCK-' . mt_rand(1000, 9999);
                }
                // COD has no gateway link — the money is handed to the courier on delivery.
                $paymentUrl = 'http://mock-gateway.com/pay/' . $txn;
            }

            // NOTE: for paymongo txns, 'paymongo_intent_id' holds the src_…
            // source id (Payment Sources API), and payment_url holds the
            // hosted checkout URL returned at creation.
            $db->insert('mock_payment_transactions', [
                'transaction_id'     => $txn,
                'request_id'         => $requestId ?: null,
                'student_id'         => $studentId,
                'amount'             => $amount,
                'currency'           => $currency,
                'status'             => 'pending',
                'method'             => $method,
                'due_on'             => 'now',
                'payment_url'        => $paymentUrl,
                'callback_url'       => $callback !== '' ? $callback : null,
                'gateway'            => $gateway,
                'paymongo_intent_id' => $paymongoIntentId,
                'raw_request'        => json_encode($input),
                'created_at'         => $now,
            ]);
        }

        $resp = [
            'payment_url'    => $paymentUrl,
            'transaction_id' => $txn,
            'status'         => 'pending',
            'amount'         => $amount,
            'currency'       => $currency,
            'method'         => $method,
            'due_on'         => 'now',
            'gateway'        => $gateway,
            'intent_id'      => $paymongoIntentId,
        ];
        echo json_encode(['success' => true, 'message' => 'Payment created.', 'data' => $resp]);
        exit;
    }

    if ($action === 'webhook') {
        $txn    = trim($input['transaction_id'] ?? '');
        $status = strtoupper(trim($input['status'] ?? ''));
        $paidAt = trim($input['paid_at'] ?? '') !== '' ? trim($input['paid_at']) : $now;

        if ($txn === '') {
            echo json_encode(['success' => false, 'message' => 'transaction_id is required.']);
            exit;
        }
        if ($status !== 'COMPLETED' && $status !== 'FAILED') {
            echo json_encode(['success' => false, 'message' => 'Invalid status (expected COMPLETED or FAILED).']);
            exit;
        }

        $row = $db->fetchOne('SELECT * FROM mock_payment_transactions WHERE transaction_id = ?', [$txn]);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Unknown transaction.']);
            exit;
        }

        $result = confirmGatewayPayment(
            (int) $row['id'],
            $status === 'COMPLETED' ? 'completed' : 'failed',
            $status === 'COMPLETED' ? $paidAt : null,
            $txn,
            (int) ($_SESSION['user_id'] ?? 0) ?: null,
            json_encode($input)
        );

        if ($result['blocked_clearance']) {
            echo json_encode([
                'success' => false,
                'message' => 'Request still pending clearance; payment cannot complete.',
                'data'    => ['blocked' => true],
            ]);
            exit;
        }
        if ($result['already']) {
            // Re-fired confirmation — report the current state, don't error.
            $cur = $db->fetchOne('SELECT status, paid_at FROM mock_payment_transactions WHERE id = ?', [(int) $row['id']]);
            echo json_encode([
                'success' => true,
                'message' => 'Payment ' . ($cur['status'] ?? 'completed') . '.',
                'data'    => ['transaction_id' => $txn, 'status' => strtoupper($cur['status'] ?? 'completed'), 'paid_at' => $cur['paid_at'] ?? null],
            ]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Payment ' . strtolower($status) . '.',
            'data'    => ['transaction_id' => $txn, 'status' => $status, 'paid_at' => $status === 'COMPLETED' ? $paidAt : null],
        ]);
        exit;
    }

    if ($action === 'cod_collected') {
        // Cash on delivery was removed — pick-up only. This action is a no-op.
        echo json_encode(['success' => false, 'message' => 'Cash on delivery is no longer used (pick-up only).']);
        exit;
    }

    if ($action === 'check_status') {
        // Confirm a payment by polling the real gateway (or re-reading the
        // local txn). Used by the frontend when the student returns from the
        // PayMongo checkout; mock txns are already local and pass through.
        $txn = trim($input['transaction_id'] ?? '');
        if ($txn === '') {
            echo json_encode(['success' => false, 'message' => 'transaction_id is required.']);
            exit;
        }
        $row = $db->fetchOne('SELECT * FROM mock_payment_transactions WHERE transaction_id = ?', [$txn]);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Unknown transaction.']);
            exit;
        }

        // Real PayMongo sources are polled server-side (capturing when the
        // student has authorized); mock txns are already local and pass through.
        if ($row['gateway'] === 'paymongo' && !empty($row['paymongo_intent_id']) && !in_array($row['status'], ['completed', 'failed'], true)) {
            // Create the payment (capture) once the student authorizes.
            $payId = paymongoCaptureAndStore($row);
            $source = paymongoGetSource($row['paymongo_intent_id']);
            if (is_array($source)) {
                // PayMongo Source status enum: pending, chargeable, cancelled,
                // expired, paid. A captured source is 'paid' (NOT 'charged' —
                // verified live 2026-08-29); 'chargeable' means the student
                // authorized and capture was just attempted above.
                $srcStatus = (string) ($source['attributes']['status'] ?? '');
                if ($srcStatus === 'paid' || $srcStatus === 'charged') {
                    $ref = $row['paymongo_payment_id'] ?: ($payId ?: $row['paymongo_intent_id']);
                    confirmGatewayPayment(
                        (int) $row['id'],
                        'completed',
                        $now,
                        $ref,
                        (int) ($_SESSION['user_id'] ?? 0) ?: null,
                        json_encode($source)
                    );
                } elseif (in_array($srcStatus, ['failed', 'cancelled', 'expired'], true)) {
                    confirmGatewayPayment(
                        (int) $row['id'],
                        'failed',
                        null,
                        $row['paymongo_intent_id'],
                        null,
                        json_encode($source)
                    );
                }
                // pending / chargeable → not authorized yet (or capture is
                // still settling); leave pending and let the poll retry.
            }
            // Network failure → return the DB state as-is; the client retries.
        }

        $cur = $db->fetchOne(
            'SELECT transaction_id, status, method, due_on, paid_at, amount, currency, gateway
               FROM mock_payment_transactions WHERE transaction_id = ?',
            [$txn]
        );
        if (!$cur) {
            echo json_encode(['success' => false, 'message' => 'Unknown transaction.']);
            exit;
        }
        echo json_encode(['success' => true, 'data' => $cur]);
        exit;
    }

    if ($action === 'status') {
        $txn = trim($input['transaction_id'] ?? ($_GET['transaction_id'] ?? ''));
        if ($txn === '') {
            echo json_encode(['success' => false, 'message' => 'transaction_id is required.']);
            exit;
        }
        $row = $db->fetchOne(
            'SELECT transaction_id, status, method, due_on, paid_at, amount, currency, gateway FROM mock_payment_transactions WHERE transaction_id = ?',
            [$txn]
        );
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Unknown transaction.']);
            exit;
        }
        echo json_encode(['success' => true, 'data' => $row]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    json_error($e, 'Unable to process the mock payment.');
}
