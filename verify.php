<?php
// ============================================================
//  VERIFY.PHP
//  Public document verification portal (spec §7).
//
//    GET  /verify.php?qr=<qr_hash>            → verification card
//    GET  /verify/<qr_hash>  (.htaccess rewrite) → same card
//    POST /verify.php (file upload)           → fingerprint check
//
//  No login required. Self-contained page shell (only
//  security_headers.php), modeled on queue/monitor.php.
// ============================================================

require_once __DIR__ . '/shared/security_headers.php';
require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/database.php';

$db = Database::getInstance();
$lookup = null;
$notFound = false;
$check = null; // ['authentic' => bool, 'sha256' => string]

// ── Fingerprint check (POST) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file'])) {
    if (($_FILES['pdf_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $check = ['authentic' => false, 'error' => 'No file received (or upload failed).'];
    } elseif ((int) $_FILES['pdf_file']['size'] > 25 * 1024 * 1024) {
        $check = ['authentic' => false, 'error' => 'File is too large (max 25 MB).'];
    } else {
        $sha = hash_file('sha256', $_FILES['pdf_file']['tmp_name']);
        $targetHash = trim((string) ($_POST['target_hash'] ?? ''));
        $row = $db->fetchOne(
            'SELECT pdf_fingerprint, qr_hash, document_status FROM document_requests WHERE pdf_fingerprint = ? LIMIT 1',
            [$sha]
        );
        $authentic = $row !== null || ($targetHash !== '' && hash_equals($targetHash, $sha));
        $check = ['authentic' => $authentic, 'sha256' => $sha];
    }
}

// ── QR lookup (GET) ───────────────────────────────────────────
$qr = trim((string) ($_GET['qr'] ?? ''));
if ($qr === '') {
    $path = trim((string) ($_SERVER['REQUEST_URI'] ?? ''), '/');
    // Support /verify/<hash> (rewritten to verify.php?qr=) as a fallback
    // for deployments without mod_rewrite.
    if (preg_match('#/verify/([A-Za-z0-9]{32,64})$#', $path, $m)) {
        $qr = $m[1];
    }
}
if ($qr !== '') {
    // Someone pasted the whole verify URL — extract just the hash.
    if (preg_match('/[?&]qr=([A-Za-z0-9]{32,64})/', $qr, $m)) {
        $qr = $m[1];
    }
    $lookup = $db->fetchOne(
        "SELECT dr.*, c.name AS catalog_name, c.sku,
                REGEXP_REPLACE(CONCAT(COALESCE(s.first_name, ''), ' ', COALESCE(s.middle_name, ''), ' ', COALESCE(s.last_name, ''), ' ', COALESCE(s.name_suffix, '')), ' +', ' ') AS student_name,
                s.student_number, u.full_name AS registrar_name
           FROM document_requests dr
           LEFT JOIN document_catalog c ON c.id = dr.catalog_id
           LEFT JOIN students s ON dr.student_id = s.id
           LEFT JOIN users u ON dr.processed_by = u.id
          WHERE dr.qr_hash = ?
          LIMIT 1",
        [$qr]
    );
    if (!$lookup) {
        $notFound = true;
    }
}

$statusLabel = [
    'Pending_Clearance' => 'Pending Clearance',
    'Filed'             => 'Filed',
    'Processing'        => 'Processing',
    'Ready'             => 'Ready for Release',
    'Shipped'           => 'Shipped',
    'Claimed'           => 'Claimed',
    'Rejected'          => 'Rejected',
];
$APP_ROOT = '';
$page_title = 'Document Verification';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title><?= htmlspecialchars($page_title) ?> — Bestlink College of the Philippines</title>
<link rel="icon" type="image/png" href="<?= $APP_ROOT ?>assets/images/BCP_LOGO.png" />
<link rel="icon" type="image/x-icon" href="<?= $APP_ROOT ?>assets/images/favicon.ico" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet" />
<style>
    :root { --blue:#2563eb; --green:#16a34a; --red:#dc2626; --ink:#0f172a; --mut:#64748b; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: 'Inter', 'Segoe UI', -apple-system, sans-serif;
        background: linear-gradient(160deg, #f1f5f9 0%, #e2e8f0 100%);
        min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 28px 16px;
        color: var(--ink);
    }
    .wrap { width: 100%; max-width: 560px; }
    .brand { text-align: center; margin-bottom: 18px; }
    .brand .mono {
        width: 46px; height: 46px; margin: 0 auto 10px; border-radius: 12px;
        background: #782a20; color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: 900; font-size: 15px; letter-spacing: .03em;
        box-shadow: 0 8px 18px rgba(120,42,32,.25);
    }
    .brand h1 { font-size: 15px; font-weight: 800; letter-spacing: .02em; }
    .brand p { font-size: 12px; color: var(--mut); margin-top: 2px; }
    .card {
        background: #fff; border-radius: 18px; box-shadow: 0 18px 46px rgba(15,23,42,.12);
        border: 1px solid #e2e8f0; overflow: hidden;
    }
    .card-head {
        padding: 18px 22px; background: linear-gradient(135deg,#1e3a8a,#2563eb); color: #fff;
        display: flex; align-items: center; gap: 12px;
    }
    .card-head .ic { width: 40px; height: 40px; border-radius: 11px; background: rgba(255,255,255,.16); display:flex; align-items:center; justify-content:center; font-size:17px; }
    .card-head h2 { font-size: 15px; font-weight: 800; }
    .card-head span { font-size: 11.5px; opacity: .85; }
    .card-body { padding: 22px; }
    .result { display:flex; align-items:center; gap:14px; padding:14px 16px; border-radius: 12px; margin-bottom: 18px; font-weight:700; font-size:14px; }
    .result.ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; }
    .result.bad { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; }
    .result .ri { font-size: 26px; }
    .row { display:flex; justify-content:space-between; gap:16px; padding:9px 0; border-bottom:1px solid #f1f5f9; font-size:13px; }
    .row:last-of-type { border-bottom:none; }
    .row .k { color: var(--mut); font-weight:600; flex:0 0 auto; }
    .row .v { text-align:right; font-weight:700; word-break:break-word; }
    .row .v.mono { font-family: ui-monospace, Consolas, monospace; font-size:11px; font-weight:600; color:#475569; }
    .pill { display:inline-block; padding:3px 10px; border-radius:99px; font-size:11px; font-weight:700; }
    .pill.ok { background:#dcfce7; color:#15803d; } .pill.act { background:#dbeafe; color:#1d4ed8; } .pill.warn { background:#fef3c7; color:#b45309; } .pill.bad { background:#fee2e2; color:#b91c1c; }
    .check-box { margin-top: 20px; padding-top: 16px; border-top: 1px dashed #e2e8f0; }
    .check-box h3 { font-size: 12px; text-transform: uppercase; letter-spacing:.06em; color:var(--mut); margin-bottom:10px; }
    .check-box form { display:flex; gap:8px; }
    .check-box input[type=file] { flex:1; font-size: 12px; padding: 8px 10px; border:1px solid #cbd5e1; border-radius:9px; }
    .btn { border:none; border-radius:9px; padding: 0 14px; font-size:12.5px; font-weight:700; cursor:pointer; background:var(--blue); color:#fff; }
    .btn:hover { filter:brightness(.96); }
    .chip-ok { display:inline-flex; gap:7px; align-items:center; background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; border-radius:10px; padding:10px 12px; font-size:12.5px; font-weight:700; margin-top:10px; width:100%; }
    .chip-bad { display:inline-flex; gap:7px; align-items:center; background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; border-radius:10px; padding:10px 12px; font-size:12.5px; font-weight:700; margin-top:10px; width:100%; }
    .search { display:flex; gap:8px; }
    .search input { flex:1; padding: 11px 13px; border:1px solid #cbd5e1; border-radius:10px; font-size:13px; font-family: inherit; }
    .empty { text-align:center; padding: 26px 10px; color: var(--mut); font-size: 13px; }
    .empty .ei { font-size: 34px; margin-bottom: 10px; opacity:.5; }
    .foot { text-align:center; margin-top:14px; font-size:11px; color:#94a3b8; }
</style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <div class="mono">BCP</div>
        <h1>Bestlink College of the Philippines</h1>
        <p>Official Document Verification Portal</p>
    </div>

    <div class="card">
        <div class="card-head">
            <div class="ic"><i class="fa-solid fa-shield-halved"></i></div>
            <div><h2>Verify an Official Document</h2><span>Scan the QR on the document or enter its verification code.</span></div>
        </div>

        <div class="card-body">
            <?php if ($check): ?>
                <div class="result <?= $check['authentic'] ? 'ok' : 'bad' ?>">
                    <div class="ri"><i class="fa-solid <?= $check['authentic'] ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i></div>
                    <div><?= $check['authentic'] ? 'The uploaded PDF is authentic — its SHA-256 matches the record kept by the Registrar.' : ($check['error'] ?? 'The uploaded PDF does not match any official document record (fingerprint mismatch).') ?></div>
                </div>
                <div class="row"><span class="k">Computed SHA-256</span><span class="v mono"><?= htmlspecialchars($check['sha256']) ?></span></div>
                <a href="verify.php" style="display:inline-block;margin-top:14px;font-size:12.5px;font-weight:700;color:#2563eb;text-decoration:none;"><i class="fa-solid fa-rotate-left"></i> Verify another</a>
            <?php elseif ($notFound): ?>
                <div class="empty">
                    <div class="ei"><i class="fa-solid fa-magnifying-glass-location"></i></div>
                    <b>No matching record.</b><br>
                    This verification code does not match any official document issued by the Office of the Registrar.<br>
                    <a href="verify.php" style="color:#2563eb;font-weight:700;text-decoration:none;">Try again</a>
                </div>
            <?php elseif ($lookup): ?>
                <div class="result ok"><div class="ri"><i class="fa-solid fa-circle-check"></i></div>
                    <div>Record found — this document was issued by the Office of the Registrar.
                        <span class="pill <?= $lookup['document_status'] === 'Claimed' ? 'ok' : 'act' ?>" style="margin-left:6px;">
                            <?= htmlspecialchars($statusLabel[$lookup['document_status']] ?? str_replace('_', ' ', $lookup['document_status'])) ?>
                        </span>
                    </div>
                </div>

                <div class="row"><span class="k">Student</span><span class="v"><?= htmlspecialchars(trim((string) $lookup['student_name'])) ?></span></div>
                <div class="row"><span class="k">Student No.</span><span class="v"><?= htmlspecialchars((string) $lookup['student_number']) ?></span></div>
                <div class="row"><span class="k">Document</span><span class="v"><?= htmlspecialchars($lookup['catalog_name'] ?? ucwords(str_replace('_', ' ', (string) $lookup['document_type']))) ?></span></div>
                <div class="row"><span class="k">Request No.</span><span class="v mono"><?= htmlspecialchars((string) $lookup['request_id']) ?></span></div>
                <div class="row"><span class="k">Issued</span><span class="v"><?= $lookup['ready_at'] ? date('F d, Y', strtotime($lookup['ready_at'])) : date('F d, Y', strtotime($lookup['request_date'])) ?></span></div>
                <div class="row"><span class="k">Registrar</span><span class="v"><?= htmlspecialchars((string) ($lookup['registrar_name'] ?? 'Office of the Registrar')) ?></span></div>
                <div class="row"><span class="k">Fulfillment</span><span class="v"><?= htmlspecialchars((string) $lookup['fulfillment_type']) ?></span></div>
                <?php if ($lookup['pdf_fingerprint']): ?>
                    <div class="row"><span class="k">Record SHA-256</span><span class="v mono"><?= htmlspecialchars((string) $lookup['pdf_fingerprint']) ?></span></div>
                <?php endif; ?>

                <div class="check-box">
                    <h3><i class="fa-solid fa-file-import"></i> Fingerprint check — upload the received PDF</h3>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="target_hash" value="<?= htmlspecialchars((string) $lookup['pdf_fingerprint']) ?>">
                        <input type="file" name="pdf_file" accept="application/pdf,.pdf" required>
                        <button class="btn" type="submit"><i class="fa-solid fa-lock"></i> Check</button>
                    </form>
                    <div class="foot" style="margin-top:8px;">The file is hashed locally by the server and never stored.</div>
                </div>
            <?php else: ?>
                <form method="get" action="verify.php" class="search">
                    <input type="text" name="qr" placeholder="Paste verification code or URL, e.g. 64-char hash" required />
                    <button class="btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Verify</button>
                </form>
                <div class="empty">
                    <div class="ei"><i class="fa-solid fa-qrcode"></i></div>
                    Enter the verification code printed on your official document,<br>or scan the QR code on it with your phone.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="foot">Bestlink College of the Philippines — Office of the Registrar · Quezon City · <?= date('Y') ?></div>
</div>
</body>
</html>
