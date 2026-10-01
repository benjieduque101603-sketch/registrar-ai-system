<?php
// ============================================================
//  API/MOCK/LALAMOVE.PHP
//  Mock Lalamove (Philippine on-demand delivery) API for the
//  Document Request Module demo.
//
//    action=quotation         → {quotation_id: QUO-####, total_fee, distance_km}
//    action=order             → {order_id: LALA-DOC-####, driver_name, driver_phone,
//                                 status: ASSIGNING_RIDER, tracking_url}
//    action=simulate_delivery → flips an order DELIVERED + request Shipped→Claimed
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

header('Content-Type: application/json');
// Same-origin only; see the note in api/mock/payment.php.
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

// Quotations are harmless and deterministic (a student needs to see the
// delivery fee before submitting); booking + delivery simulation stay
// Registrar-only.
$role = getCurrentUserRole();
if ($action !== 'quotation' && !in_array($role, ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

try {
    $db = Database::getInstance();
    $now = date('Y-m-d H:i:s');

    if ($action === 'quotation') {
        $pickup  = trim($input['pickup'] ?? 'Bestlink College of the Philippines');
        $dropoff = trim($input['dropoff'] ?? '');
        $item    = trim($input['item'] ?? 'Document');

        if ($dropoff === '') {
            echo json_encode(['success' => false, 'message' => 'dropoff is required.']);
            exit;
        }

        // Deterministic mock pricing: ₱50 base + ₱20/km over a plausible
        // city distance, seeded from the dropoff address (see mockDeliveryQuote).
        $quote       = mockDeliveryQuote($dropoff);
        $distanceKm  = $quote['distance_km'];
        $totalFee    = $quote['total_fee'];
        $quotationId = 'QUO-' . mt_rand(1000, 9999);

        echo json_encode(['success' => true, 'data' => [
            'quotation_id' => $quotationId,
            'total_fee'    => $totalFee,
            'currency'     => 'PHP',
            'distance_km'  => $distanceKm,
        ]]);
        exit;
    }

    if ($action === 'order') {
        $requestId   = (int) ($input['request_id'] ?? 0);
        $quotationId = trim($input['quotation_id'] ?? '');
        $pickup      = trim($input['pickup'] ?? 'Bestlink College of the Philippines');
        $dropoff     = trim($input['dropoff'] ?? '');
        $item        = trim($input['item'] ?? 'Document');
        $totalFee    = round((float) ($input['total_fee'] ?? 0), 2);
        $distanceKm  = (isset($input['distance_km']) && $input['distance_km'] !== '')
            ? round((float) $input['distance_km'], 2) : null;

        if ($dropoff === '') {
            echo json_encode(['success' => false, 'message' => 'dropoff is required.']);
            exit;
        }

        if ($requestId) {
            $req = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$requestId]);
            if (!$req) {
                echo json_encode(['success' => false, 'message' => 'Document request not found.']);
                exit;
            }
            if (!in_array($req['document_status'], ['Ready', 'Processing'], true)) {
                echo json_encode(['success' => false, 'message' => 'Only Ready (or Processing) requests can be shipped.']);
                exit;
            }
            // The delivery fee was quoted to the student at submission and is
            // stored on the request — use it as the authoritative amount so
            // the order always matches what the student agreed to.
            if ((float) ($req['delivery_fee'] ?? 0) > 0) {
                $totalFee = round((float) $req['delivery_fee'], 2);
            }
        }

        $orderId = 'LALA-DOC-' . mt_rand(1000, 9999);
        for ($i = 0; $i < 5 && $db->fetchOne('SELECT id FROM mock_lalamove_orders WHERE order_id = ?', [$orderId]); $i++) {
            $orderId = 'LALA-DOC-' . mt_rand(1000, 9999);
        }

        $driverName  = 'Rider Mockington';
        $driverPhone = '0917-000-' . str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $trackingUrl = 'http://mock-lala.com/track/' . $orderId;

        $db->insert('mock_lalamove_orders', [
            'order_id'     => $orderId,
            'quotation_id' => $quotationId !== '' ? $quotationId : null,
            'request_id'   => $requestId ?: null,
            'pickup'       => $pickup,
            'dropoff'      => $dropoff,
            'item'         => $item,
            'total_fee'    => $totalFee,
            'distance_km'  => $distanceKm,
            'driver_name'  => $driverName,
            'driver_phone' => $driverPhone,
            'status'       => 'ASSIGNING_RIDER',
            'tracking_url' => $trackingUrl,
            'created_at'   => $now,
        ]);

        if ($requestId) {
            $db->update('document_requests', [
                'document_status'    => 'Shipped',
                'shipped_at'         => $now,
                'lalamove_order_ref' => $orderId,
            ], 'id = ?', [$requestId]);
            $db->insert('document_request_events', [
                'request_id' => $requestId,
                'status'     => 'Shipped',
                'note'       => 'Rider booked (' . $orderId . ' — ' . $driverName . ')',
                'created_by' => $_SESSION['user_id'] ?? null,
                'created_at' => $now,
            ]);
        }

        echo json_encode(['success' => true, 'message' => 'Delivery booked.', 'data' => [
            'order_id'     => $orderId,
            'driver_name'  => $driverName,
            'driver_phone' => $driverPhone,
            'status'       => 'ASSIGNING_RIDER',
            'tracking_url' => $trackingUrl,
        ]]);
        exit;
    }

    if ($action === 'simulate_delivery') {
        $orderId = trim($input['order_id'] ?? '');
        if ($orderId === '') {
            echo json_encode(['success' => false, 'message' => 'order_id is required.']);
            exit;
        }

        $row = $db->fetchOne('SELECT * FROM mock_lalamove_orders WHERE order_id = ?', [$orderId]);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Order not found.']);
            exit;
        }

        $db->update('mock_lalamove_orders', ['status' => 'DELIVERED'], 'id = ?', [$row['id']]);

        $requestId = (int) $row['request_id'];
        if ($requestId) {
            $db->update('document_requests', ['document_status' => 'Claimed', 'claimed_at' => $now], 'id = ?', [$requestId]);
            $db->insert('document_request_events', [
                'request_id' => $requestId,
                'status'     => 'Claimed',
                'note'       => 'Delivered (' . $orderId . ')',
                'created_by' => $_SESSION['user_id'] ?? null,
                'created_at' => $now,
            ]);

            // Cash on Delivery — money is collected on hand-off, so mark the
            // pending COD transaction completed alongside the claim.
            $txnCols = array_column($db->fetchAll('SHOW COLUMNS FROM mock_payment_transactions'), 'Field');
            if (in_array('method', $txnCols, true)) {
                $cod = $db->fetchOne(
                    "SELECT id FROM mock_payment_transactions
                      WHERE request_id = ? AND method = 'Cash_on_Delivery' AND status = 'pending'
                      ORDER BY id DESC LIMIT 1",
                    [$requestId]
                );
                if ($cod) {
                    $db->update('mock_payment_transactions', [
                        'status'       => 'completed',
                        'paid_at'      => $now,
                        'raw_response' => json_encode(['collected_on' => $now, 'collected_by' => $_SESSION['user_id'] ?? null, 'note' => 'COD collected at delivery']),
                    ], 'id = ?', [$cod['id']]);
                }
            }
        }

        echo json_encode(['success' => true, 'message' => 'Delivery completed.', 'data' => [
            'order_id' => $orderId,
            'status'   => 'DELIVERED',
        ]]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    json_error($e, 'Unable to process the mock Lalamove request.');
}
