<?php
// ============================================================
//  STUDENT/IDS.PHP
//  Student's issued ID card(s) + QR codes + status info.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';
// resolveStudentQrUrl() lives here and is used for the QR <img> below.
require_once __DIR__ . '/../shared/qr_generator.php';
require_once __DIR__ . '/../shared/functions.php';   // e() for output escaping

$page_title = 'ID &amp; Status';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_ids';
$extra_css = ['student.css'];

require_once __DIR__ . '/_guard.php';

$db = Database::getInstance();

$ids = $db->fetchAll(
    "SELECT * FROM student_ids WHERE student_id = ? ORDER BY id DESC",
    [$student['id']]
);

// RFID cards (optional: show card status if present)
$rfid = $db->fetchAll(
    "SELECT * FROM rfid_cards WHERE student_id = ? ORDER BY id DESC LIMIT 1",
    [$student['id']]
);

$typeLabel = ['school_id' => 'School ID', 'library' => 'Library', 'cafeteria' => 'Cafeteria'];
$statusBadge = ['active' => 'active', 'inactive' => 'inactive', 'lost' => 'inactive'];

$photo = $student['photo'] ?? '';
$photoUrl = $photo ? $APP_ROOT . ltrim($photo, './') : '';
$firstLast = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
$initial  = strtoupper(substr(trim($firstLast), 0, 1));
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <header class="header">
            <div class="title"><h1>ID &amp; Status</h1><p>Your issued IDs and card status.</p></div>
        </header>

        <?php if (empty($ids)): ?>
            <div class="panel">
                <div class="empty-state"><i class="fa-solid fa-id-badge"></i><p>No ID has been issued to you yet</p><span>Once the Registrar issues your ID, it will appear here.</span></div>
            </div>
        <?php else: ?>

        <div class="id-grid">
            <?php foreach ($ids as $id): ?>
            <div class="id-card">
                <div class="id-header">
                    <div style="position:relative;z-index:1;">
                        <div class="id-org">Bestlink College of the Philippines</div>
                        <div class="id-type"><?= htmlspecialchars($typeLabel[$id['id_type']] ?? ucfirst($id['id_type'])) ?></div>
                    </div>
                    <div class="id-pic">
                        <?php if ($photoUrl): ?>
                            <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Student photo">
                        <?php else: ?>
                            <?= htmlspecialchars($initial) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="id-body">
                    <div class="id-name-row">
                        <div class="id-name"><?= htmlspecialchars($firstLast) ?></div>
                        <span class="pill <?= $statusBadge[$id['status']] ?? 'active' ?>"><i class="fa-solid fa-circle"></i> <?= ucfirst($id['status']) ?></span>
                    </div>
                    <div class="id-number"><?= $id['id_number'] ? '#' . htmlspecialchars($id['id_number']) : 'ID not yet assigned' ?></div>

                    <div class="id-meta">
                        <div class="id-meta-item"><div class="m-label">Issued</div><div class="m-value"><?= htmlspecialchars($id['issue_date'] ?? '—') ?></div></div>
                        <div class="id-meta-item"><div class="m-label">Expires</div><div class="m-value"><?= htmlspecialchars($id['expiry_date'] ?? '—') ?></div></div>
                        <div class="id-meta-item"><div class="m-label">School Year</div><div class="m-value"><?= htmlspecialchars($id['school_year'] ?? '—') ?></div></div>
                        <div class="id-meta-item"><div class="m-label">Card Color</div><div class="m-value"><?= htmlspecialchars(ucfirst($id['card_color'] ?? 'Blue')) ?></div></div>
                    </div>

                    // D2: this wrote a raw DB value straight into a src attribute, bypassing
// the resolveStudentQrUrl() helper every other page uses for this exact
// column. A stored value containing a quote could break out of the
// attribute and inject markup (stored XSS). It is server-generated today,
// so it is not directly attacker-controlled — but it is a DB value in an
// unescaped HTML attribute, which is exactly the shape that becomes
// exploitable the moment any other code path writes the column.
//
// Escaped with e() and routed through the shared resolver, which also
// makes it '' (renders nothing) if the file is not on disk.
$qrUrl = resolveStudentQrUrl($id['qr_code_path'] ?? null, $APP_ROOT); ?>
                    <?php if ($qrUrl !== ''): ?>
                    <div class="id-sep"></div>
                    <div class="id-footer">
                        <span class="chip gray"><i class="fa-solid fa-qrcode"></i> Scan to verify</span>
                        <div class="id-qr"><img src="<?= e($qrUrl) ?>" alt="ID QR code"></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>

        <?php if ($rfid): $r = $rfid[0]; ?>
        <div class="panel" style="margin-top:24px;">
            <div class="panel-toolbar">
                <div class="panel-title"><i class="fa-solid fa-credit-card" style="color:#7c3aed;"></i> RFID Card</div>
                <div class="panel-actions">
                    <span class="chip <?= ($r['status'] ?? '') === 'active' ? 'green' : 'gray' ?>"><i class="fa-solid fa-circle"></i> <?= ucfirst($r['status'] ?? '—') ?></span>
                </div>
            </div>
            <div style="padding:20px;">
                <div class="detail-grid">
                    <div class="view-item"><div class="lbl">Card UID</div><div class="val" style="font-family:monospace;"><?= htmlspecialchars($r['card_uid'] ?? '—') ?></div></div>
                    <div class="view-item"><div class="lbl">Issued</div><div class="val"><?= htmlspecialchars($r['issued_date'] ?? $r['created_at'] ?? '—') ?></div></div>
                </div>
                <div style="margin-top:14px;padding:12px 14px;background:#f8fafc;border-radius:10px;font-size:13px;color:#64748b;">
                    <i class="fa-solid fa-circle-info" style="color:#7c3aed;"></i> Tap your RFID card at the Registrar kiosk to join the queue faster.
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</main>

<?php include '../includes/footer.php'; ?>