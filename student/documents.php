<?php
// ============================================================
//  STUDENT/DOCUMENTS.PHP
//  Student document requests — catalog, new-request modal,
//  payment flow, request table with stepper + timeline.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';

$page_title = 'My Documents';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_documents';
$extra_css = ['student.css', 'documents.css'];

require_once __DIR__ . '/_guard.php';

$db = Database::getInstance();

// ── Catalog (active only)
$catalog = $db->fetchAll(
    "SELECT * FROM document_catalog WHERE is_active = 1 ORDER BY id ASC"
);

// ── Finance balance
$balance = (float) ($db->fetchColumn('SELECT balance FROM finance WHERE student_id = ?', [$student['id']]) ?? 0.00);

// ── Student requests
$requests = $db->fetchAll(
    "SELECT dr.*, c.name AS catalog_name, c.sku, c.fee_type, c.base_fee, c.requirement
       FROM document_requests dr
       LEFT JOIN document_catalog c ON c.id = dr.catalog_id
      WHERE dr.student_id = ?
      ORDER BY dr.id DESC",
    [$student['id']]
);

// ── Status events (grouped by request)
$eventsByRequest = [];
if ($requests) {
    $ids = array_map('intval', array_column($requests, 'id'));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach ($db->fetchAll("SELECT * FROM document_request_events WHERE request_id IN ($ph) ORDER BY id ASC", $ids) as $ev) {
        $eventsByRequest[(int) $ev['request_id']][] = $ev;
    }
}

// ── Display maps
$statusPill = [
    'Pending_Clearance' => ['pending-clearance', 'fa-triangle-exclamation'],
    'Awaiting_Payment'  => ['awaiting-payment',  'fa-clock'],
    'Filed'             => ['filed',             'fa-folder-open'],
    'Processing'        => ['processing',        'fa-gear'],
    'Ready'             => ['ready',             'fa-circle-check'],
    'Shipped'           => ['shipped',           'fa-truck-fast'],
    'Claimed'           => ['claimed',           'fa-box-check'],
    'Rejected'          => ['rejected',          'fa-xmark'],
];
$statusLabel = [
    'Pending_Clearance' => 'Pending Clearance',
    'Awaiting_Payment'  => 'Awaiting Payment',
    'Filed'             => 'Filed',
    'Processing'        => 'Being prepared',
    'Ready'             => 'Ready for collection',
    'Shipped'           => 'On its way',
    'Claimed'           => 'Collected',
    'Rejected'          => 'Rejected',
];
$catIcon = [
    'DOC-TOR' => ['linear-gradient(135deg,#2563eb,#1d4ed8)', 'fa-file-invoice'],
    'DOC-COE' => ['linear-gradient(135deg,#16a34a,#15803d)', 'fa-certificate'],
    'DOC-GM'  => ['linear-gradient(135deg,#0d9488,#0f766e)', 'fa-handshake-angle'],
    'DOC-CTC' => ['linear-gradient(135deg,#4f46e5,#4338ca)', 'fa-copy'],
];

function feeLabel($c) {
    $p = '&#8369;' . number_format((float) $c['base_fee'], 2);
    if ($c['fee_type'] === 'per_page')     return $p . ' / page';
    if ($c['fee_type'] === 'per_syllabus') return $p . ' / syllabus';
    return $p . ' one-time';
}

/**
 * The steps a student watches, in order.
 *
 * "Payment" leads for a request that is waiting on money. Without it a
 * student in Awaiting_Payment matched no step, renderStepper returned an
 * empty string, and the row showed no progress at all — the one stage
 * where they personally have something to do was the one stage with
 * nothing drawn.
 *
 * A request paid at the counter never enters Awaiting_Payment, so the
 * step is never shown to a student who owes nothing. "On its way" is
 * drawn only for a courier request; a pickup request skips it.
 *
 * There was once a fifth "Clearance" step prepended for exit-clearance
 * documents, so a student could see three offices signing off. Exit
 * clearance has been removed, and with it any step the desk cannot
 * itself move — a student watching a step that no one at the counter
 * controls was told to wait for something the desk had no way to
 * report on.
 */
function renderStepper(string $status): string {
    $awaitingPayment = $status === 'Awaiting_Payment';
    $shipped         = $status === 'Shipped';

    $steps = [
        ['key' => 'Awaiting_Payment', 'label' => 'Payment',   'icon' => 'fa-credit-card'],
        ['key' => 'Filed',            'label' => 'Filed',     'icon' => 'fa-file-signature'],
        ['key' => 'Processing',       'label' => 'In progress','icon' => 'fa-gear'],
        ['key' => 'Ready',            'label' => 'Ready',     'icon' => 'fa-circle-check'],
        ['key' => 'Shipped',          'label' => 'On its way','icon' => 'fa-truck-fast'],
        ['key' => 'Claimed',          'label' => 'Collected', 'icon' => 'fa-box-check'],
    ];

    // Drop the steps that do not apply, so a pickup request is not shown a
    // courier leg it will never take and a paid request is not shown a
    // payment step it has already cleared.
    if (!$awaitingPayment) {
        $steps = array_values(array_filter($steps, fn($s) => $s['key'] !== 'Awaiting_Payment'));
    }
    if (!$shipped) {
        $steps = array_values(array_filter($steps, fn($s) => $s['key'] !== 'Shipped'));
    }

    $activeIdx = null;
    foreach ($steps as $i => $s) {
        if ($s['key'] === $status) { $activeIdx = $i; break; }
    }
    if ($activeIdx === null) return '';
    $html = '<div class="flow-track">';
    foreach ($steps as $i => $s) {
        $cls = $i < $activeIdx ? 'done' : ($i === $activeIdx ? 'active' : '');
        $html .= '<div class="flow-step ' . $cls . '"><div class="step-dot"><i class="fa-solid ' . $s['icon'] . '"></i></div><span class="step-label">' . $s['label'] . '</span></div>';
        if ($i < count($steps) - 1) $html .= '<div class="flow-link"></div>';
    }
    $html .= '</div>';
    return $html;
}

// ── Counts
$counts = array_fill_keys(array_keys($statusPill), 0);
foreach ($requests as $r) {
    if (isset($counts[$r['document_status']])) $counts[$r['document_status']]++;
}
$isBlocked = $balance > 0;
$hasHeld = $counts['Pending_Clearance'] > 0;
$totalRequests = count($requests);
$awaitingPay = $counts['Awaiting_Payment'];
$processing  = $counts['Processing'] + $counts['Ready'];
$claimed     = $counts['Claimed'];
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <header class="header">
            <div class="title"><h1>My Documents</h1><p>Request and track documents from the Registrar.</p></div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="openRequestModal()"><i class="fas fa-plus"></i> New Request</button>
            </div>
        </header>

        <!-- Stats cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:linear-gradient(135deg,#3b82f6,#2563eb);"><i class="fa-solid fa-file-lines"></i></div>
                <div class="stat-info"><span class="stat-value"><?= $totalRequests ?></span><span class="stat-label">Total Requests</span></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706);"><i class="fa-solid fa-credit-card"></i></div>
                <div class="stat-info"><span class="stat-value"><?= $awaitingPay ?></span><span class="stat-label">Awaiting Payment</span></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed);"><i class="fa-solid fa-gear"></i></div>
                <div class="stat-info"><span class="stat-value"><?= $processing ?></span><span class="stat-label">Processing</span></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:linear-gradient(135deg,#22c55e,#16a34a);"><i class="fa-solid fa-box-check"></i></div>
                <div class="stat-info"><span class="stat-value"><?= $claimed ?></span><span class="stat-label">Claimed</span></div>
            </div>
        </div>

        <?php if ($isBlocked): ?>
        <div class="block-banner">
            <div class="banner-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
            <div>
                <div class="banner-title">Action required &mdash; outstanding balance of &#8369;<?= number_format($balance, 2) ?></div>
                <div class="banner-text">Your account has a balance due on record. A request is held at the desk until the Registrar's Office settles the balance.</div>
            </div>
        </div>
        <?php elseif ($hasHeld): ?>
        <div class="block-banner">
            <div class="banner-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div>
                <div class="banner-title">Request on hold</div>
                <div class="banner-text">One or more of your requests is waiting on an outstanding balance. The Registrar's Office will release it once the account is settled. No action needed from you right now.</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Document Requirements Checklist -->
        <?php
        $studentId = $student['id'];
        $requiredDocs = [
            'form_137' => ['label' => 'Form 137', 'icon' => 'fa-file-alt', 'color' => '#2563eb'],
            'psa'      => ['label' => 'PSA Birth Certificate', 'icon' => 'fa-certificate', 'color' => '#7c3aed'],
            'photo'    => ['label' => '1x1 ID Photo', 'icon' => 'fa-camera', 'color' => '#db2777'],
        ];
        $studentDocs = $db->fetchAll("SELECT doc_type FROM documents WHERE student_id = ?", [$studentId]);
        $presentTypes = array_column($studentDocs, 'doc_type');
        $reqMissing = [];
        foreach ($requiredDocs as $type => $info) {
            if (!in_array($type, $presentTypes)) $reqMissing[$type] = $info;
        }
        ?>
        <?php if (!empty($reqMissing)): ?>
        <div class="panel" style="margin-bottom:16px;border-left:4px solid #f59e0b;">
            <div style="padding:16px;">
                <div style="font-weight:600;font-size:14px;color:#92400e;margin-bottom:10px;"><i class="fas fa-triangle-exclamation" style="color:#f59e0b;"></i> Required Documents — Action Needed</div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <?php foreach ($requiredDocs as $type => $info): ?>
                    <?php $uploaded = in_array($type, $presentTypes); ?>
                    <div style="display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:8px;background:<?= $uploaded ? '#f0fdf4' : '#fef3c7' ?>;border:1px solid <?= $uploaded ? '#bbf7d0' : '#fde68a' ?>;">
                        <i class="fas <?= $uploaded ? 'fa-circle-check' : $info['icon'] ?>" style="color:<?= $uploaded ? '#16a34a' : $info['color'] ?>;"></i>
                        <span style="font-size:13px;<?= $uploaded ? 'text-decoration:line-through;color:#16a34a;' : 'font-weight:500;color:#92400e;' ?>"><?= $info['label'] ?></span>
                        <?= $uploaded ? '<span style="font-size:10px;color:#16a34a;">✓ Uploaded</span>' : '<span style="font-size:10px;color:#dc2626;">Missing</span>' ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Document Catalog -->
        <div class="panel">
            <div class="panel-header">
                <div><h3><i class="fa-solid fa-tags"></i> Document Catalog &amp; Pricing</h3>
                    <p style="font-size:12.5px;color:#64748b;margin-top:2px;">Select a document to request. Fees set by the Registrar's Office.</p></div>
            </div>
            <div class="catalog-grid">
                <?php foreach ($catalog as $c):
                    $ci = $catIcon[$c['sku']] ?? ['linear-gradient(135deg,#64748b,#475569)', 'fa-file-lines']; ?>
                    <div class="catalog-card" onclick="pickFromCatalog(<?= (int) $c['id'] ?>)">
                        <div class="cat-top">
                            <div class="cat-icon" style="background:<?= $ci[0] ?>;"><i class="fa-solid <?= $ci[1] ?>"></i></div>
                            <div>
                                <div class="cat-sku"><?= htmlspecialchars($c['sku']) ?></div>
                                <div class="cat-name"><?= htmlspecialchars($c['name']) ?></div>
                            </div>
                        </div>
                        <div class="cat-desc"><?= htmlspecialchars($c['description'] ?? '') ?></div>
                        <div class="cat-meta">
                            <div class="cat-fee"><?= feeLabel($c) ?></div>
                        </div>
                        <?php if (!empty($c['requirement'])): ?>
                            <div class="cat-req"><i class="fa-solid fa-file-shield"></i> <?= htmlspecialchars($c['requirement']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- My requests -->
        <div class="panel">
            <div class="search-toolbar">
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" id="docSearch" placeholder="Search by request ID, document, or purpose...">
                </div>
                <select id="statusFilter" class="form-control" style="width:auto;min-width:180px;">
                    <option value="">All statuses</option>
                    <?php foreach ($statusLabel as $k => $v): ?>
                        <option value="<?= $k ?>"><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="panel-actions" style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;">
                    <span class="chip blue"><i class="fa-solid fa-file-lines"></i> <?= $totalRequests ?> request<?= $totalRequests === 1 ? '' : 's' ?></span>
                </div>
            </div>
            <div class="table-responsive" style="overflow-x:auto;">
                <table class="table">
                    <thead>
                        <tr><th>Request</th><th>Fee</th><th>Type</th><th>Fulfillment</th><th>Status</th><th>Submitted</th><th style="text-align:right;">Action</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($requests)): ?>
                        <tr><td colspan="7" class="empty-state"><i class="fa-solid fa-file-lines"></i><p>No document requests yet</p><span>Click "New Request" above to request a document.</span></td></tr>
                    <?php else: foreach ($requests as $r):
                        $pill = $statusPill[$r['document_status']] ?? ['awaiting-payment', 'fa-clock'];
                        $label = $statusLabel[$r['document_status']] ?? str_replace('_', ' ', $r['document_status']);
                        $ci = $catIcon[$r['sku']] ?? ['linear-gradient(135deg,#64748b,#475569)', 'fa-file-lines'];
                        $reqEvents = $eventsByRequest[(int) $r['id']] ?? [];
                        $isRejected = $r['document_status'] === 'Rejected';
                        $payable = $r['document_status'] === 'Awaiting_Payment'
                            && (float) $r['fee_amount'] > 0
                            && ($r['payment_method'] ?? 'Online') !== 'Cash_on_Delivery';
                        $isCod = ($r['payment_method'] ?? 'Online') === 'Cash_on_Delivery';
                    ?>
                        <tr data-doc="<?= (int) $r['id'] ?>" data-status="<?= htmlspecialchars((string) $r['document_status']) ?>" class="doc-row" onclick="toggleDetail(<?= (int) $r['id'] ?>)">
                            <td>
                                <div class="student-info">
                                    <div class="student-avatar" style="background:<?= $ci[0] ?>;"><i class="fa-solid <?= $ci[1] ?>"></i></div>
                                    <div>
                                        <div class="student-name"><?= htmlspecialchars($r['catalog_name'] ?? ucwords(str_replace('_', ' ', $r['document_type']))) ?></div>
                                        <div class="student-sub"><i class="fa-solid fa-hashtag"></i> <?= htmlspecialchars($r['request_id'] ?? '') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="font-size:13px;font-weight:700;color:#0f172a;">&#8369;<?= number_format((float) ($r['fee_amount'] ?? 0), 2) ?></div>
                                <?php if ($isCod): ?>
                                    <div style="font-size:11px;color:#dc2626;"><i class="fa-solid fa-hand-holding-dollar"></i> Cash on delivery</div>
                                <?php endif; ?>
                            </td>
                            <td><span class="chip regular"><i class="fa-solid fa-clock"></i> Regular</span></td>
                            <td><span class="chip pickup"><i class="fa-solid fa-store"></i> Pickup</span></td>
                            <td><span class="pill <?= $pill[0] ?>"><i class="fa-solid <?= $pill[1] ?>"></i> <?= htmlspecialchars($label) ?></span></td>
                            <td style="font-size:12px;color:#64748b;"><?= date('M d, Y', strtotime($r['request_date'])) ?></td>
                            <td style="text-align:right;white-space:nowrap;">
                                <?php if ($payable): ?>
                                    <button class="btn btn-sm btn-primary" onclick="event.stopPropagation();openPaymentModal(<?= (int) $r['id'] ?>, '<?= htmlspecialchars($r['request_id']) ?>', <?= (float) $r['fee_amount'] ?>);"><i class="fa-solid fa-credit-card"></i> Pay Online</button>
                                <?php elseif ($isRejected): ?>
                                    <span class="pill rejected"><i class="fa-solid fa-xmark"></i> Rejected</span>
                                <?php else: ?>
                                    <span style="font-size:12px;color:#94a3b8;"><i class="fa-solid fa-chevron-down"></i></span>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <tr class="doc-detail-row" id="detail-<?= (int) $r['id'] ?>" style="display:none;">
                            <td colspan="7" style="padding:0;">
                                <div class="doc-detail" style="padding:18px 22px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                                    <?php if ($isRejected): ?>
                                        <div class="block-banner" style="margin-bottom:12px;">
                                            <div class="banner-icon"><i class="fa-solid fa-xmark"></i></div>
                                            <div>
                                                <div class="banner-title">Request rejected</div>
                                                <div class="banner-text"><?= htmlspecialchars($r['rejection_reason'] ?? 'No reason provided.') ?></div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <?= renderStepper((string) $r['document_status']) ?>
                                    <?php endif; ?>
                                    <div style="display:flex;flex-wrap:wrap;gap:14px;margin-top:14px;">
                                        <?php if ($isCod): ?>
                                            <div style="font-size:12.5px;color:#b45309;"><i class="fa-solid fa-hand-holding-dollar" style="color:#dc2626;"></i> <b>Cash on delivery</b> &mdash; pay at the office.</div>
                                        <?php endif; ?>
                                        <?php if (!empty($r['purpose'])): ?>
                                            <div style="font-size:12.5px;color:#475569;"><i class="fa-solid fa-note-sticky" style="color:#64748b;"></i> <b>Purpose:</b> <?= htmlspecialchars($r['purpose']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($r['quantity']) && (int) $r['quantity'] > 1): ?>
                                            <div style="font-size:12.5px;color:#475569;"><i class="fa-solid fa-copy"></i> <b>Qty:</b> <?= (int) $r['quantity'] ?></div>
                                        <?php endif; ?>
                                        <?php if ($r['document_status'] === 'Ready' && $r['fulfillment_type'] === 'Pickup'): ?>
                                            <div style="font-size:12.5px;color:#16a34a;"><i class="fa-solid fa-store"></i> <b>Ready for pickup</b> at the Registrar's Office.</div>
                                        <?php endif; ?>
                                        <?php if ($payable): ?>
                                            <div style="font-size:12.5px;color:#2563eb;"><i class="fa-solid fa-credit-card"></i> <b>Payment needed.</b> Click "Pay Online" to pay via GCash.</div>
                                        <?php endif; ?>
                                        <?php if ($r['document_status'] === 'Processing'): ?>
                                            <div style="font-size:12.5px;color:#6d28d9;"><i class="fa-solid fa-gear"></i> Being prepared by the Registrar.</div>
                                        <?php endif; ?>
                                    </div>
                                    <h4 style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin:14px 0 8px;"><i class="fa-solid fa-timeline"></i> Status timeline</h4>
                                    <ul class="timeline">
                                        <?php if (empty($reqEvents)): ?>
                                            <li><div class="tl-dot" style="background:#94a3b8;border-color:#e2e8f0;"></div><div class="tl-status">Submitted</div><div class="tl-when"><?= date('M d, Y h:i A', strtotime($r['request_date'])) ?></div></li>
                                        <?php else: foreach ($reqEvents as $ev): ?>
                                            <li>
                                                <div class="tl-dot"></div>
                                                <div class="tl-status"><?= htmlspecialchars($statusLabel[$ev['status']] ?? str_replace('_', ' ', $ev['status'])) ?></div>
                                                <?php if (!empty($ev['note'])): ?><div class="tl-note"><?= htmlspecialchars($ev['note']) ?></div><?php endif; ?>
                                                <div class="tl-when"><?= date('M d, Y h:i A', strtotime($ev['created_at'])) ?></div>
                                            </li>
                                        <?php endforeach; endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-footer">
                <div class="info-text">Showing <strong><?= $totalRequests ?></strong> of <strong><?= $totalRequests ?></strong> requests</div>
            </div>
        </div>

    </div>
</main>

<!-- New Request Modal -->
<div class="modal-overlay" id="requestModal">
    <div class="modal-content" style="max-width:640px;">
        <div class="modal-header"><h2><i class="fas fa-file-circle-plus"></i> New Document Request</h2><button class="modal-close" onclick="closeRequestModal()"><i class="fas fa-times"></i></button></div>
        <form id="requestForm"><div class="modal-body">
            <div class="form-group">
                <label>Document <span class="required">*</span></label>
                <div class="catalog-picker" id="catalogPicker">
                    <?php foreach ($catalog as $c):
                        $ci = $catIcon[$c['sku']] ?? ['linear-gradient(135deg,#64748b,#475569)', 'fa-file-lines']; ?>
                    <div class="catalog-option" data-id="<?= (int) $c['id'] ?>" onclick="selectCatalogOption(this,<?= (int) $c['id'] ?>)">
                        <div class="catalog-option-icon" style="background:<?= $ci[0] ?>;"><i class="fa-solid <?= $ci[1] ?>"></i></div>
                        <div><div class="catalog-option-name"><?= htmlspecialchars($c['name']) ?></div><div class="catalog-option-fee"><?= feeLabel($c) ?></div></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="catalog_id" id="catalogId">
            </div>
            <div class="form-group" id="qtyGroup" style="display:none;">
                <label>Quantity</label>
                <input type="number" id="reqQty" class="form-control" min="1" max="20" value="1">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group"><label>Request Type</label><input type="text" class="form-control" value="Regular" readonly style="background:#f1f5f9;cursor:not-allowed;"><small style="color:#94a3b8;">Students can only submit regular requests.</small></div>
                <div class="form-group">
                    <label>Fulfillment <span class="required">*</span></label>
                    <select id="reqFulfillment" class="form-control">
                        <option value="Pickup" selected>Pickup at Registrar</option>
                        <option value="Delivery">Courier delivery</option>
                        <option value="Digital">Digital copy (email)</option>
                    </select>
                </div>
            </div>

            <!-- Courier needs somewhere to send it. Hidden until Delivery is
                 chosen, so the form does not ask a question that has no
                 bearing on a pickup or a digital copy. -->
            <div class="form-group" id="addressGroup" style="display:none;">
                <label>Delivery Address <span class="required">*</span></label>
                <textarea id="reqAddress" class="form-control" rows="2" placeholder="House no., street, barangay, city, province"></textarea>
            </div>

            <div class="form-group">
                <label>Payment Method <span class="required">*</span></label>
                <div style="display:flex;gap:12px;margin-top:4px;">
                    <label class="payment-option" style="display:flex;align-items:center;gap:8px;padding:10px 16px;border:2px solid #2563eb;border-radius:10px;cursor:pointer;flex:1;">
                        <input type="radio" name="payment_method" value="Online" checked style="accent-color:#2563eb;">
                        <div><div style="font-weight:700;font-size:13px;color:#1e293b;"><i class="fa-solid fa-mobile-screen-button" style="color:#2563eb;"></i> Pay Online (GCash)</div><div style="font-size:11px;color:#94a3b8;">Pay now, collect when ready</div></div>
                    </label>
                    <label class="payment-option" style="display:flex;align-items:center;gap:8px;padding:10px 16px;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;flex:1;">
                        <input type="radio" name="payment_method" value="Cash_on_Delivery" style="accent-color:#dc2626;">
                        <div><div style="font-weight:700;font-size:13px;color:#1e293b;"><i class="fa-solid fa-hand-holding-dollar" style="color:#dc2626;"></i> Payment Upon Pickup</div><div style="font-size:11px;color:#94a3b8;">Pay cash at the office</div></div>
                    </label>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <?php // Recipient is gone. A document requested here is
                      // released to the student who asked for it, at the
                      // counter, on their student ID — there is no courier
                      // and no third-party collection, so the field could
                      // only ever have been filled with the student's own
                      // name or an office's. The column is still in
                      // document_requests; nothing writes to it from here
                      // any more. Purpose now takes the full width. ?>
                <div class="form-group" style="grid-column:1 / -1;"><label>Purpose <span class="required">*</span></label><input type="text" name="purpose" id="reqPurpose" class="form-control" required placeholder="e.g. Job application, Transfer"></div>
            </div>
            <div class="form-group" id="reqFileGroup" style="display:none;"><label>Requirement File</label><input type="file" name="requirement_file" id="reqFile" class="form-control" accept=".pdf,.jpg,.jpeg,.png"><div class="req-hint" id="reqHint"></div></div>
            <div class="fee-preview"><div><div class="fp-label">Total fee</div><div style="font-size:11px;color:#94a3b8;" id="feeNote">Select a document to see the fee.</div></div><div class="fp-amount" id="feePreview">&mdash;</div></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" onclick="closeRequestModal()">Cancel</button>
            <button type="submit" class="btn btn-primary" id="submitReqBtn"><i class="fas fa-paper-plane"></i> Submit Request</button>
        </div></form>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal-overlay" id="payModal">
    <div class="modal-content" style="max-width:460px;">
        <div class="modal-header"><h2><i class="fa-solid fa-credit-card"></i> Pay Online</h2><button class="modal-close" onclick="closePayModal()"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <div id="payLoading" style="text-align:center;padding:26px 0;"><i class="fa-solid fa-spinner fa-spin" style="font-size:22px;color:#2563eb;"></i><p style="color:#64748b;font-size:13px;margin-top:8px;">Contacting payment gateway...</p></div>
            <div id="payContent" style="display:none;">
                <div class="pay-gateway">
                    <div class="pay-brand" id="payGatewayBrand"><i class="fa-solid fa-bolt"></i> Mock Payment Gateway</div>
                    <div class="pay-amount" id="payAmount">&#8369;0.00</div>
                    <div class="pay-row"><span>Fee</span><b id="payDocFee">&mdash;</b></div>
                    <div class="pay-row"><span>Request</span><b id="payReq">&mdash;</b></div>
                    <div class="pay-row"><span>Transaction ID</span><b id="payTxn">&mdash;</b></div>
                    <div class="pay-row"><span>Status</span><b style="color:#fde68a;">PENDING</b></div>
                </div>
                <span class="gateway-chip"><i class="fa-solid fa-link"></i> <span id="payUrl">&mdash;</span></span>
                <p style="font-size:12.5px;color:#64748b;margin:12px 0 4px;" id="payNote">This is a mock gateway. Press the button below to simulate.</p>
                <div class="simulate-actions" id="paymongoActions" style="display:none;">
                    <button class="btn btn-primary" style="flex:1;" id="payNowBtn"><i class="fa-solid fa-mobile-screen-button"></i> Pay with GCash</button>
                    <button class="btn btn-light" id="checkStatusBtn"><i class="fa-solid fa-rotate"></i> Check status</button>
                </div>
                <div class="simulate-actions" id="mockActions">
                    <button class="btn btn-primary" style="flex:1;" id="simulateSuccessBtn"><i class="fa-solid fa-circle-check"></i> Simulate Success</button>
                    <button class="btn btn-light" id="simulateFailBtn"><i class="fa-solid fa-xmark"></i> Fail</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const CATALOG = <?= json_encode(array_map(function ($c) {
    return ['id' => (int) $c['id'], 'name' => $c['name'], 'base_fee' => (float) $c['base_fee'],
            'fee_type' => $c['fee_type'], 'requirement' => $c['requirement'] ?? ''];
}, $catalog)) ?>;
const STUDENT_ID = <?= (int) $student['id'] ?>;
let selectedCatalogId = 0;
let currentTxn = null;

function openRequestModal(presetId) {
    document.getElementById('requestForm').reset();
    document.getElementById('requestModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    document.querySelectorAll('.catalog-option').forEach(o => o.classList.remove('sel'));
    if (presetId) selectCatalogOption(document.querySelector('.catalog-option[data-id="'+presetId+'"]'), presetId);
    else { selectedCatalogId = 0; document.getElementById('catalogId').value = ''; updateFeePreview(); }
}
function closeRequestModal() { document.getElementById('requestModal').classList.remove('active'); document.body.style.overflow = ''; }
function pickFromCatalog(id) { openRequestModal(id); }
function selectCatalogOption(el, id) {
    document.querySelectorAll('.catalog-option').forEach(o => o.classList.remove('sel'));
    if (el) el.classList.add('sel');
    selectedCatalogId = id;
    document.getElementById('catalogId').value = id;
    updateFeePreview();
}
function updateFeePreview() {
    const opt = CATALOG.find(c => c.id === selectedCatalogId);
    const qtyGroup = document.getElementById('qtyGroup');
    const qtyInput = document.getElementById('reqQty');
    if (!opt) { document.getElementById('feePreview').innerHTML = '&mdash;'; document.getElementById('feeNote').textContent = 'Select a document to see the fee.'; qtyGroup.style.display = 'none'; document.getElementById('reqFileGroup').style.display = 'none'; return; }
    const perUnit = opt.fee_type !== 'flat';
    qtyGroup.style.display = perUnit ? 'block' : 'none';
    if (!perUnit) qtyInput.value = 1;
    const qty = Math.max(1, parseInt(qtyInput.value) || 1);
    const docFee = opt.base_fee * (perUnit ? qty : 1);
    document.getElementById('feePreview').innerHTML = '&#8369;' + docFee.toLocaleString('en-PH', {minimumFractionDigits:2});
    document.getElementById('feeNote').textContent = opt.name + (perUnit ? ' x ' + qty : ' (one-time)');
    const fileGroup = document.getElementById('reqFileGroup');
    const hint = document.getElementById('reqHint');
    if (opt.requirement) { fileGroup.style.display = 'block'; hint.textContent = 'Required: ' + opt.requirement; hint.classList.add('visible'); }
    else { fileGroup.style.display = 'none'; hint.classList.remove('visible'); }
}
document.getElementById('reqQty').addEventListener('input', updateFeePreview);
// Courier is the only mode that needs an address, so the field appears
// only for it and is cleared when switching away — a stale address left
// in a hidden field would ship with a pickup request.
document.getElementById('reqFulfillment')?.addEventListener('change', function () {
    const isDelivery = this.value === 'Delivery';
    document.getElementById('addressGroup').style.display = isDelivery ? 'block' : 'none';
    if (!isDelivery) document.getElementById('reqAddress').value = '';
    updateFeePreview();
});
document.querySelectorAll('.payment-option input[type=radio]').forEach(r => {
    r.addEventListener('change', function() {
        document.querySelectorAll('.payment-option').forEach(l => l.style.borderColor = '#e2e8f0');
        this.closest('.payment-option').style.borderColor = '#2563eb';
    });
});
document.getElementById('requestModal').addEventListener('click', function(e) { if (e.target === this) closeRequestModal(); });
document.getElementById('payModal').addEventListener('click', function(e) { if (e.target === this) closePayModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { closeRequestModal(); closePayModal(); } });
</script>

<script>
document.getElementById('requestForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    if (!selectedCatalogId) { showToast('Please select a document from the catalog.', 'error'); return; }
    const fulfillment = document.getElementById('reqFulfillment').value;
    if (fulfillment === 'Delivery' && !document.getElementById('reqAddress').value.trim()) {
        showToast('Please enter a delivery address for courier delivery.', 'error'); return;
    }
    const btn = document.getElementById('submitReqBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    try {
        const fd = new FormData(this);
        fd.set('quantity', document.getElementById('reqQty').value || '1');
        fd.set('request_type', 'Regular');
        // Read what the student actually chose. This used to be pinned to
        // 'Pickup' in JS, which silently discarded the payment radio they
        // had just clicked — the request was filed as paid at the counter
        // while the screen said GCash.
        fd.set('fulfillment_type', fulfillment);
        fd.set('delivery_address', document.getElementById('reqAddress').value.trim());
        fd.set('payment_method', (document.querySelector('input[name="payment_method"]:checked') || {}).value || 'Online');
        const res = await fetch('../api/student-documents.php', { method: 'POST', body: fd });
        const d = await res.json();
        if (d.success) { showToast(d.message, d.data && d.data.document_status === 'Pending_Clearance' ? 'warning' : 'success'); setTimeout(() => location.reload(), 900); }
        else { showToast(d.message || 'Submission failed.', 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request'; }
    } catch (err) { showToast('Network error.', 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request'; }
});

function openPaymentModal(requestId, requestLabel, amount) {
    const modal = document.getElementById('payModal');
    modal.classList.add('active'); document.body.style.overflow = 'hidden';
    document.getElementById('payLoading').style.display = 'block';
    document.getElementById('payContent').style.display = 'none';
    document.getElementById('payAmount').innerHTML = '&#8369;' + amount.toLocaleString('en-PH', {minimumFractionDigits:2});
    document.getElementById('payDocFee').innerHTML = '&#8369;' + amount.toLocaleString('en-PH', {minimumFractionDigits:2});
    document.getElementById('payReq').textContent = requestLabel;
    document.getElementById('simulateSuccessBtn').disabled = true;
    document.getElementById('simulateFailBtn').disabled = true;
    document.getElementById('mockActions').style.display = '';
    document.getElementById('paymongoActions').style.display = 'none';
    fetch('../api/mock/payment.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'create', request_id: requestId, student_id: STUDENT_ID })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            currentTxn = d.data.transaction_id;
            document.getElementById('payTxn').textContent = currentTxn;
            document.getElementById('payUrl').textContent = d.data.payment_url;
            document.getElementById('payLoading').style.display = 'none';
            document.getElementById('payContent').style.display = 'block';
            if (d.data.gateway === 'paymongo') {
                document.getElementById('payGatewayBrand').innerHTML = '<i class="fa-solid fa-bolt"></i> PayMongo &middot; GCash (test mode)';
                document.getElementById('payUrl').textContent = d.data.intent_id || d.data.payment_url;
                document.getElementById('mockActions').style.display = 'none';
                document.getElementById('paymongoActions').style.display = '';
                document.getElementById('payNote').textContent = "You'll pay on PayMongo's hosted GCash page (test mode). After paying, click Check status.";
                document.getElementById('payNowBtn').onclick = function() { window.open(d.data.payment_url, '_blank'); startStatusPolling(currentTxn); };
                document.getElementById('checkStatusBtn').onclick = function() {
                    var btn = document.getElementById('checkStatusBtn'); if (btn.disabled) return;
                    btn.disabled = true; var orig = btn.innerHTML;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';
                    pollStatusOnce(currentTxn).finally(function() { btn.disabled = false; btn.innerHTML = orig; });
                };
            } else {
                document.getElementById('payGatewayBrand').innerHTML = '<i class="fa-solid fa-bolt"></i> Mock Payment Gateway &middot; GCash / Maya';
                document.getElementById('simulateSuccessBtn').disabled = false;
                document.getElementById('simulateFailBtn').disabled = false;
            }
        } else { closePayModal(); showToast(d.message || 'Could not start payment.', 'error'); }
    }).catch(() => { closePayModal(); showToast('Payment gateway unreachable.', 'error'); });
}
function closePayModal() { stopStatusPolling(); document.getElementById('payModal').classList.remove('active'); document.body.style.overflow = ''; }
function simulatePayment(status) {
    if (!currentTxn) return;
    const btn = status === 'COMPLETED' ? document.getElementById('simulateSuccessBtn') : document.getElementById('simulateFailBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    fetch('../api/mock/payment.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'webhook', transaction_id: currentTxn, status: status }) })
    .then(r => r.json()).then(d => {
        if (d.success) { showToast(d.message, status === 'COMPLETED' ? 'success' : 'info'); setTimeout(() => location.reload(), 900); }
        else { showToast(d.message || 'Simulation failed.', 'error'); btn.disabled = false; btn.innerHTML = status === 'COMPLETED' ? '<i class="fa-solid fa-circle-check"></i> Simulate Success' : '<i class="fa-solid fa-xmark"></i> Fail'; }
    }).catch(() => { showToast('Network error.', 'error'); btn.disabled = false; });
}
document.getElementById('simulateSuccessBtn').addEventListener('click', () => simulatePayment('COMPLETED'));
document.getElementById('simulateFailBtn').addEventListener('click', () => simulatePayment('FAILED'));
</script>

<script>
let pollTimer = null;
function fetchPaymentStatus(txnId) {
    return fetch('../api/mock/payment.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'check_status', transaction_id: txnId }) }).then(r => r.json());
}
function stopStatusPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
function startStatusPolling(txnId) { stopStatusPolling(); pollTimer = setInterval(function() { pollStatusOnce(txnId); }, 4000); }
function pollStatusOnce(txnId) {
    return fetchPaymentStatus(txnId).then(function(d) {
        if (!d.success) { if (d.timeout) { stopStatusPolling(); window.location.href = '../login.php?timeout=1'; return; } stopStatusPolling(); showToast(d.message || 'Could not check payment status.', 'error'); return; }
        const st = d.data && d.data.status;
        if (st === 'completed') { stopStatusPolling(); showToast('Payment confirmed. Request is now being processed.', 'success'); setTimeout(function() { location.reload(); }, 900); }
        else if (st === 'failed') { stopStatusPolling(); showToast('Payment failed. Try paying again.', 'error'); }
    }).catch(function() {});
}

function toggleDetail(id) { const row = document.getElementById('detail-' + id); if (row) row.style.display = row.style.display === 'none' ? '' : 'none'; }

function applyFilters() {
    const q = (document.getElementById('docSearch').value || '').trim().toLowerCase();
    const st = document.getElementById('statusFilter').value;
    let visible = 0;
    document.querySelectorAll('table tbody tr[data-doc]').forEach(tr => {
        const matchQ = !q || tr.textContent.toLowerCase().includes(q);
        const matchS = !st || tr.dataset.status === st;
        tr.style.display = (matchQ && matchS) ? '' : 'none';
        const detail = document.getElementById('detail-' + tr.dataset.doc);
        if (detail) detail.style.display = 'none';
        if (matchQ && matchS) visible++;
    });
    document.querySelector('.table-footer .info-text').innerHTML = 'Showing <strong>' + visible + '</strong> of <strong>' + document.querySelectorAll('table tbody tr[data-doc]').length + '</strong> requests';
}
document.getElementById('docSearch').addEventListener('input', applyFilters);
document.getElementById('statusFilter').addEventListener('change', applyFilters);
</script>

<?php include '../includes/footer.php'; ?>
