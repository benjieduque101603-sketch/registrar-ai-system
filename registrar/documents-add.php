<?php
// ============================================================
//  REGISTRAR/DOCUMENTS-ADD.PHP
//  New document request form — v2 catalog + workflow fields.
//  Registrar-initiated requests (walk-ins) pick the student and
//  submit through api/student-documents.php with student_id.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
requireRole('registrar');

require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/schema.php';

$db = Database::getInstance();
// Same status filter as documents.php: the column default is 'enrolled', so
// `status = 'active'` hid most students from the picker. See the note there.
$students = $db->fetchAll(
    "SELECT id, student_number, CONCAT(first_name, ' ', last_name) AS name
       FROM students
      WHERE status IS NULL OR status NOT IN ('archived')
      ORDER BY name"
);
$catalog = db_fill_optional(
    $db->fetchAll("SELECT * FROM document_catalog WHERE is_active = 1 ORDER BY id"),
    'document_catalog',
    ['sla_days', 'requirement']
);

$page_title = 'New Document Request';
$APP_ROOT = '../';
$ACTIVE_NAV = 'documents';
$extra_css = ['documents.css'];

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main">
<div class="dashboard-container">

<header class="header">
    <div class="title">
        <h1>New Document Request</h1>
        <p>File a document request on behalf of a student (walk-in / phone).</p>
    </div>
    <div class="header-actions">
        <a href="documents.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</header>

<div class="panel" style="max-width: 720px; margin: 0 auto;">
    <div class="panel-toolbar">
        <div class="panel-title"><i class="fas fa-file-circle-plus"></i> Request Details</div>
    </div>
    <form id="addDocumentForm" style="margin-top:16px;">

        <div class="form-group">
            <label>Student <span style="color:#dc2626;">*</span></label>
            <select name="student_id" class="form-control" data-searchable required>
                <option value="">Select a student</option>
                <?php foreach ($students as $student): ?>
                    <option value="<?= $student['id'] ?>">
                        <?= htmlspecialchars($student['student_number']) ?> - <?= htmlspecialchars($student['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Document <span style="color:#dc2626;">*</span></label>
            <select name="catalog_id" id="catalogSelect" class="form-control" required>
                <option value="">Select a document</option>
                <?php foreach ($catalog as $c):
                    $feeTxt = $c['fee_type'] === 'flat'
                        ? '₱' . number_format((float) $c['base_fee'], 2)
                        : '₱' . number_format((float) $c['base_fee'], 2) . ' per ' . str_replace('_', ' ', $c['fee_type']); ?>
                    <option value="<?= $c['id'] ?>"
                        data-fee="<?= (float) $c['base_fee'] ?>"
                        data-fee-type="<?= htmlspecialchars($c['fee_type']) ?>"
                        data-req="<?= htmlspecialchars((string) $c['requirement'], ENT_QUOTES) ?>">
                        <?= htmlspecialchars($c['name']) ?> (<?= $feeTxt ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <div id="catalogHint" class="req-hint"></div>
        </div>

        <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group" id="qtyGroup" style="display:none;">
                <label>Quantity (pages / syllabi)</label>
                <input type="number" name="quantity" id="quantity" class="form-control" value="1" min="1" max="100" />
            </div>
            <div class="form-group">
                <label>Priority <span style="color:#dc2626;">*</span></label>
                <select name="request_type" class="form-control" required>
                    <option value="Regular">Regular</option>
                    <option value="Express">Express</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Fulfillment <span style="color:#dc2626;">*</span></label>
            <select name="fulfillment_type" id="fulfillSelect" class="form-control" required>
                <option value="Pickup">Pickup at the Registrar</option>
                <option value="Digital">Digital download (encrypted PDF)</option>
            </select>
        </div>

        

        <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
                <label>Purpose</label>
                <input type="text" name="purpose" class="form-control" placeholder="e.g., Transfer to UP Manila" />
            </div>
            <div class="form-group">
                <label>Recipient</label>
                <input type="text" name="recipient" class="form-control" placeholder="e.g., UP Manila Registrar" />
            </div>
        </div>

        <div class="fee-preview">
            <span class="fp-label"><i class="fa-solid fa-coins"></i> Estimated fee</span>
            <span class="fp-amount" id="feePreview">₱0.00</span>
        </div>

        <div class="modal-footer" style="padding-top:16px;border-top:1px solid #e2e8f0;margin-top:16px;">
            <a href="documents.php" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Submit Request</button>
        </div>
    </form>
</div>

</div>
</main>

<script>
(function () {
    const form = document.getElementById('addDocumentForm');
    const catalogSel = document.getElementById('catalogSelect');
    const qtyGroup = document.getElementById('qtyGroup');
    const quantity = document.getElementById('quantity');
    const fulfillSelect = document.getElementById('fulfillSelect');
    const feePreview = document.getElementById('feePreview');
    const hint = document.getElementById('catalogHint');

    function currentOpt() {
        const opt = catalogSel.selectedOptions[0];
        return opt && opt.value ? opt : null;
    }
    function updateFee() {
        const opt = currentOpt();
        if (!opt) { feePreview.textContent = '₱0.00'; return; }
        const fee = parseFloat(opt.dataset.fee || '0');
        const type = opt.dataset.feeType;
        const qty = Math.max(1, parseInt(quantity.value || '1', 10));
        const total = type === 'flat' ? fee : fee * qty;
        feePreview.textContent = '₱' + total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    catalogSel.addEventListener('change', function () {
        const opt = currentOpt();
        qtyGroup.style.display = opt && opt.dataset.feeType !== 'flat' ? '' : 'none';
        if (opt && opt.dataset.req) {
            hint.textContent = 'Required: ' + opt.dataset.req;
            hint.classList.add('visible');
        } else {
            hint.textContent = '';
            hint.classList.remove('visible');
        }
        updateFee();
    });
    quantity.addEventListener('input', updateFee);

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        data.quantity = Math.max(1, parseInt(data.quantity || '1', 10));
        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        try {
            const res = await fetch('../api/student-documents.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await res.json();
            if (result.success) {
                showToast(result.message, 'success');
                window.location.href = 'documents.php';
            } else {
                showToast(result.message || 'Error submitting request.', 'error');
            }
        } catch (error) {
            showToast('Network error. Please try again.', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Submit Request';
        }
    });
})();
</script>

<?php include '../includes/footer.php'; ?>
