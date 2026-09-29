<?php
// ============================================================
//  REGISTRAR/STUDENT-IDS.PHP
//  Student ID card management (school / library / cafeteria IDs)
//  with QR code generation.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/qr_generator.php';

$db = Database::getInstance();
$ids = $db->fetchAll("
    SELECT si.*, CONCAT(s.first_name, ' ', s.last_name) AS student_name,
           s.student_number, s.course, s.year_level, s.photo
    FROM student_ids si
    LEFT JOIN students s ON si.student_id = s.id
    ORDER BY si.id DESC
");
$students = $db->fetchAll("SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name, course, photo FROM students WHERE status != 'archived' ORDER BY last_name, first_name");

// ── Card-back data: address, emergency contacts, primary guardian ──
$cardExtras = [];
$idStudentIds = array_values(array_unique(array_filter(array_map(fn($r) => (int) $r['student_id'], $ids))));
if ($idStudentIds) {
    $in = implode(',', $idStudentIds);
    foreach ($db->fetchAll("SELECT id, address FROM students WHERE id IN ($in)") as $st) {
        $cardExtras[$st['id']]['address'] = (string) ($st['address'] ?? '');
    }
    foreach ($db->fetchAll("SELECT student_id, full_name, relationship, contact_number FROM emergency_contacts WHERE student_id IN ($in) ORDER BY is_primary DESC, id ASC") as $e) {
        $cardExtras[$e['student_id']]['emergency'][] = [
            'name' => (string) $e['full_name'],
            'rel'  => (string) ($e['relationship'] ?? ''),
            'phone'=> (string) ($e['contact_number'] ?? ''),
        ];
    }
    foreach ($db->fetchAll("SELECT student_id, full_name, relationship, contact_number FROM guardians WHERE student_id IN ($in) AND is_primary = 1 ORDER BY id ASC") as $g) {
        if (!empty($cardExtras[$g['student_id']]['emergency'])) continue;   // emergency contacts take priority
        $cardExtras[$g['student_id']]['emergency'][] = [
            'name' => (string) $g['full_name'],
            'rel'  => (string) ($g['relationship'] ?? 'Guardian'),
            'phone'=> (string) ($g['contact_number'] ?? ''),
        ];
    }
}

$page_title = 'Student IDs';
$APP_ROOT = '../';
$ACTIVE_NAV = 'studentids';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
.badge { display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600; }
.badge.active { background:#dcfce7; color:#16a34a; }
.badge.inactive { background:#f1f5f9; color:#64748b; }
.badge.lost { background:#fee2e2; color:#dc2626; }
.idtype-chip { display:inline-block; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600; background:#eef4ff; color:#2563eb; }
.qr-thumb { width:36px; height:36px; border:1px solid #e2e8f0; border-radius:8px; object-fit:contain; background:white; cursor:pointer; transition:transform .15s,box-shadow .15s; }
.qr-thumb:hover { transform:scale(1.15); box-shadow:0 2px 8px rgba(37,99,235,.2); }
#qrModalOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); backdrop-filter:blur(4px); z-index:10000; align-items:center; justify-content:center; }
#qrModalOverlay.active { display:flex; }
#qrModalOverlay .qr-modal-box { background:#fff; border-radius:18px; padding:24px; text-align:center; max-width:320px; width:90%; box-shadow:0 24px 60px rgba(0,0,0,.25); animation:modalSlide .25s ease; }
#qrModalOverlay .qr-modal-box img { width:220px; height:220px; object-fit:contain; border:1px solid #e2e8f0; border-radius:12px; margin-bottom:12px; }
#qrModalOverlay .qr-modal-box .qr-modal-name { font-size:14px; font-weight:700; color:#0f172a; margin-bottom:4px; }
#qrModalOverlay .qr-modal-box .qr-modal-sub { font-size:12px; color:#94a3b8; margin-bottom:14px; }
#qrModalOverlay .qr-modal-close { border:none; background:#f1f5f9; border-radius:10px; padding:8px 20px; font-size:13px; font-weight:600; cursor:pointer; color:#475569; }
#qrModalOverlay .qr-modal-close:hover { background:#e2e8f0; }

/* ── Toolbar ─────────────────────────────────────── */
.ids-toolbar { display:flex; align-items:center; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
.ids-search { position:relative; flex:1; min-width:220px; max-width:360px; }
.ids-search i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:13px; }
.ids-search input { width:100%; box-sizing:border-box; height:38px; padding:0 12px 0 34px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:13px; font-family:inherit; background:#fff; outline:none; }
.ids-search input:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.1); }

/* ── ID card (view modal) — 3D flip card ─────────── */
.idcard-flip { width:340px; height:430px; margin:0 auto; perspective:1400px; }
.idcard-inner { position:relative; width:100%; height:100%; transform-style:preserve-3d; transition:transform .8s cubic-bezier(.4,.15,.2,1); }
.idcard-flip.flipped .idcard-inner { transform:rotateY(180deg); }
.idcard, .idcard-back {
    position:absolute; inset:0; width:100%; height:100%; margin:0;
    border-radius:18px; overflow:hidden; background:#fff;
    border:1px solid #e2e8f0; box-shadow:0 12px 32px rgba(15,23,42,.12);
    display:flex; flex-direction:column;
    backface-visibility:hidden; -webkit-backface-visibility:hidden;
}
.idcard-back { transform:rotateY(180deg); }
.idcard-head { background:linear-gradient(135deg,#1a3a8c 0%,#2563eb 100%); color:#fff; padding:14px 18px; display:flex; align-items:center; gap:11px; position:relative; overflow:hidden; }
.idcard-head::after { content:''; position:absolute; right:-30px; top:-30px; width:110px; height:110px; border-radius:50%; background:radial-gradient(circle,rgba(255,255,255,.18),transparent 70%); }
.idcard-head img { width:34px; height:34px; border-radius:8px; background:#fff; object-fit:contain; position:relative; z-index:1; }
.idcard-head .school { font-size:12.5px; font-weight:800; letter-spacing:.3px; position:relative; z-index:1; }
.idcard-head .school small { display:block; font-size:9.5px; font-weight:500; opacity:.85; margin-top:1px; }
.idcard-photo-wrap { display:flex; justify-content:center; padding-top:16px; position:relative; z-index:2; }
.idcard-photo { width:86px; height:86px; border-radius:50%; object-fit:cover; background:#e2e8f0; border:4px solid #fff; box-shadow:0 4px 12px rgba(15,23,42,.18); display:block; }
.idcard-initials { width:86px; height:86px; border-radius:50%; background:linear-gradient(135deg,#e2e8f0,#cbd5e1); border:4px solid #fff; box-shadow:0 4px 12px rgba(15,23,42,.18); display:flex; align-items:center; justify-content:center; font-size:26px; font-weight:800; color:#475569; }
.idcard-center { text-align:center; padding:10px 20px 0; }
.idcard-name { font-size:16px; font-weight:800; color:#0f172a; margin-top:4px; line-height:1.3; }
.idcard-meta { font-size:11.5px; color:#64748b; margin-top:3px; }
.idcard-sec { display:flex; align-items:flex-start; gap:14px; margin:14px 18px 0; padding:12px 14px; background:#f8fafc; border:1px solid #eef2f7; border-radius:14px; }
.idcard-fields { flex:1; min-width:0; }
.idcard-field { margin-bottom:7px; }
.idcard-field:last-child { margin-bottom:0; }
.idcard-field .k { font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.6px; color:#94a3b8; }
.idcard-field .v { font-size:12.5px; font-weight:700; color:#0f172a; }
.idcard-field .v.mono { font-family:'JetBrains Mono',monospace; letter-spacing:.5px; }
.idcard-qrbox { text-align:center; flex-shrink:0; }
.idcard-qrbox img { width:78px; height:78px; background:#fff; padding:4px; border-radius:10px; border:1px solid #e2e8f0; display:block; }
.idcard-qrbox .cap { font-size:8.5px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#94a3b8; margin-top:3px; }
.idcard-qrbox .cap.ok { color:#16a34a; }
.idcard-foot { margin-top:auto; background:#f8fafc; border-top:1px solid #eef2f7; padding:8px 20px; text-align:center; font-size:9px; color:#94a3b8; line-height:1.5; }

/* ── Card back ───────────────────────────────────── */
.idcard-back { width:340px; margin:0 auto; border-radius:18px; overflow:hidden; background:#fff; border:1px solid #e2e8f0; box-shadow:0 12px 32px rgba(15,23,42,.12); }
.idcard-back-head { background:#0f172a; color:#fff; padding:10px 18px; display:flex; align-items:center; justify-content:space-between; }
.idcard-back-head .b-school { font-size:10.5px; font-weight:800; letter-spacing:.4px; }
.idcard-back-head .b-school small { display:block; font-size:8.5px; font-weight:500; color:#94a3b8; margin-top:1px; }
.idcard-back-sec { padding:12px 18px 4px; }
.idcard-back-sec + .idcard-back-sec { padding-top:0; }
.idcard-sec-title { display:flex; align-items:center; gap:6px; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.7px; color:#94a3b8; margin-bottom:6px; }
.idcard-sec-title i { color:#2563eb; font-size:10px; }
.idcard-emg-row { display:flex; align-items:flex-start; gap:9px; padding:6px 0; }
.idcard-emg-row + .idcard-emg-row { border-top:1px dashed #eef2f7; }
.idcard-emg-ico { width:26px; height:26px; border-radius:8px; background:#fef2f2; color:#dc2626; display:flex; align-items:center; justify-content:center; font-size:11px; flex-shrink:0; }
.idcard-emg-name { font-size:12px; font-weight:700; color:#0f172a; }
.idcard-emg-sub { font-size:10.5px; color:#64748b; margin-top:1px; }
.idcard-addr { font-size:11.5px; color:#334155; line-height:1.55; background:#f8fafc; border:1px solid #eef2f7; border-radius:10px; padding:8px 12px; }
.idcard-addr em { color:#94a3b8; }
.idcard-sig { text-align:center; padding:4px 24px 14px; }
.idcard-sig .line { border-top:1px solid #334155; margin:0 24px 4px; }
.idcard-sig .who { font-size:8.5px; color:#475569; text-transform:uppercase; letter-spacing:.5px; }
.idcard-back-foot { margin-top:auto; background:#f8fafc; border-top:1px solid #eef2f7; padding:8px 20px; text-align:center; font-size:9px; color:#94a3b8; line-height:1.5; }

/* ── Print sheet ─────────────────────────────────── */
.print-sheet .card { width:86mm; border-radius:4mm; overflow:hidden; border:.3mm solid #dbeafe; margin:6mm auto; background:#fff; }
</style>

<main class="dashboard-main">
    <header class="header">
        <div class="title">
            <h1>Student IDs</h1>
            <p>Issue and manage student ID cards with QR codes</p>
        </div>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="openAdd()"><i class="fas fa-plus"></i> Issue ID</button>
        </div>
    </header>

    <div class="ids-toolbar">
        <div class="ids-search">
            <i class="fas fa-search"></i>
            <input type="text" id="idsSearch" placeholder="Search by student name or ID number…">
        </div>
        <span style="font-size:12px;color:#94a3b8;" id="idsCount"></span>
    </div>
    <table class="table">
            <thead>
            <tr>
                <th>Student</th>
                <th>ID Number</th>
                <th>Type</th>
                <th>Issued</th>
                <th>Expiry</th>
                <th>Status</th>
                <th>QR</th>
                <th style="text-align:center;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($ids)): ?>
                <tr><td colspan="8" style="text-align:center;padding:30px;color:#94a3b8;">No student IDs issued yet.</td></tr>
            <?php else: foreach ($ids as $i): ?>
                <tr data-id="<?= (int)$i['id'] ?>"
                    data-student-id="<?= (int)$i['student_id'] ?>"
                    data-number="<?= htmlspecialchars($i['student_number'], ENT_QUOTES) ?>"
                    data-name="<?= htmlspecialchars($i['student_name'], ENT_QUOTES) ?>"
                    data-idnumber="<?= htmlspecialchars($i['id_number'], ENT_QUOTES) ?>"
                    data-idtype="<?= htmlspecialchars($i['id_type'], ENT_QUOTES) ?>"
                    data-issued="<?= htmlspecialchars($i['issue_date'] ?? '', ENT_QUOTES) ?>"
                    data-expiry="<?= htmlspecialchars($i['expiry_date'] ?? '', ENT_QUOTES) ?>"
                    data-status="<?= htmlspecialchars($i['status'], ENT_QUOTES) ?>"
                    <?php // Resolved, for the same reason as the thumbnail below: the card
                          // view falls back to the generated QR only when data-qr is empty,
                          // and a raw column value would send it to a file that 404s. ?>
                    data-qr="<?= htmlspecialchars(resolveStudentQrUrl($i['qr_code_path'] ?? '', $APP_ROOT), ENT_QUOTES) ?>"
                    data-photo="<?= htmlspecialchars($i['photo'] ?? '', ENT_QUOTES) ?>"
                    data-course="<?= htmlspecialchars($i['course'] ?? '', ENT_QUOTES) ?>"
                    data-search="<?= htmlspecialchars(strtolower($i['student_name'] . ' ' . $i['student_number'] . ' ' . $i['id_number']), ENT_QUOTES) ?>">
                    <td><strong><?= htmlspecialchars($i['student_name']) ?></strong><br><span style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($i['student_number']) ?></span></td>
                    <td><code style="background:#f1f5f9;padding:3px 8px;border-radius:5px;font-size:12px;"><?= $i['id_number'] ? htmlspecialchars($i['id_number']) : '—' ?></code></td>
                    <td><span class="idtype-chip"><?= ucfirst(str_replace('_', ' ', $i['id_type'])) ?></span></td>
                    <td><?= $i['issue_date'] ? date('M d, Y', strtotime($i['issue_date'])) : '—' ?></td>
                    <td><?= $i['expiry_date'] ? date('M d, Y', strtotime($i['expiry_date'])) : '—' ?></td>
                    <td><span class="badge <?= $i['status'] ?>"><?= ucfirst($i['status']) ?></span></td>
                    <?php // Resolved, not raw: a stored qr_code_path can name a file this
                          // host does not have (uploads/ is gitignored, so a database
                          // carried over from elsewhere points at files that were never
                          // deployed). Branching on the column asked the browser for a file
                          // that cannot exist - a 404 and a broken image. resolveStudentQrUrl()
                          // returns '' for that, and the qr-auto branch draws the QR from the
                          // student id with the bundled library instead. ?>
                    <?php $qrUrl = resolveStudentQrUrl($i['qr_code_path'] ?? '', $APP_ROOT); ?>
                    <td><?php if ($qrUrl !== ''): ?><img class="qr-thumb" src="<?= htmlspecialchars($qrUrl) ?>" alt="QR" onclick="showQrModal(this)" data-name="<?= htmlspecialchars($i['student_name'], ENT_QUOTES) ?>" data-id="<?= htmlspecialchars($i['student_number'], ENT_QUOTES) ?>"><?php elseif (!empty($i['student_id'])): ?><img class="qr-thumb qr-auto" data-qr-student-id="<?= (int)$i['student_id'] ?>" data-qr-name="<?= htmlspecialchars($i['student_name'], ENT_QUOTES) ?>" data-qr-number="<?= htmlspecialchars($i['student_number'], ENT_QUOTES) ?>" alt="QR" style="cursor:pointer;" onclick="showQrModal(this)"><?php else: ?>—<?php endif; ?></td>
                    <td><div class="action-group">
                        <button class="action-btn view" onclick="viewCard(this)" title="View ID Card"><i class="fas fa-id-card"></i></button>
                        <button class="action-btn edit" onclick="editStatus(this)" title="Update Status"><i class="fas fa-pen"></i></button>
                        <button class="action-btn delete" onclick="deleteId(this)" title="Delete"><i class="fas fa-trash-alt"></i></button>
                    </div></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</main>

<!-- Issue ID Modal -->
<div class="modal-overlay" id="issueModal">
    <div class="modal-content">
        <div class="modal-header"><h3><i class="fas fa-id-card" style="color:#2563eb;"></i> Issue Student ID</h3><button class="modal-close" onclick="closeModal('issueModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <div class="form-group">
                <label>Student <span style="color:#dc2626;">*</span></label>
                <select id="issueStudent" class="form-control" data-searchable required>
                    <option value="">Select a student</option>
                    <?php foreach ($students as $st): ?>
                        <option value="<?= (int)$st['id'] ?>"><?= htmlspecialchars($st['student_number'] . ' — ' . $st['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>ID Type</label>
                    <select id="issueType" class="form-control">
                        <option value="school_id">School ID</option>
                        <option value="library">Library</option>
                        <option value="cafeteria">Cafeteria</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="issueStatus" class="form-control">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="lost">Lost</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Expiry Date</label><input type="date" id="issueExpiry" class="form-control">
                </div>
            </div>
            <div class="form-group"><label>Student ID</label><input type="text" id="issueNumber" class="form-control" placeholder="Assigned by enrollment department"></div>
            <p style="font-size:11px;color:#64748b;margin:0;"><i class="fas fa-info-circle"></i> Leave blank if not yet assigned by the enrollment department.</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('issueModal')">Cancel</button>
            <button class="btn btn-primary" id="issueSubmit" onclick="submitIssue()"><i class="fas fa-save"></i> Issue ID</button>
        </div>
    </div>
</div>

<!-- View ID Card Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-content" style="max-width:400px;">
        <div class="modal-header"><h3><i class="fas fa-id-card" style="color:#2563eb;"></i> Student ID Card</h3><button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <div class="idcard-flip" id="cardFlip">
              <div class="idcard-inner">
                <div class="idcard">
                    <div class="idcard-head">
                        <img src="<?= $APP_ROOT ?>assets/images/BCP_LOGO.png" alt="BCP" onerror="this.style.display='none'">
                        <div class="school">BESTLINK COLLEGE OF THE PHILIPPINES<small>Official Student Identification</small></div>
                    </div>
                    <div class="idcard-photo-wrap">
                        <img class="idcard-photo" id="cardPhoto" src="" alt="photo" style="display:none;" onerror="showInitialsFallback()">
                        <div class="idcard-initials" id="cardInitials" style="display:none;">—</div>
                    </div>
                    <div class="idcard-center">
                        <div class="idcard-name" id="cardName">—</div>
                    </div>
                    <div class="idcard-sec">
                        <div class="idcard-fields">
                            <div class="idcard-field"><div class="k">Course</div><div class="v" id="cardCourse">—</div></div>
                            <div class="idcard-field"><div class="k">Student ID</div><div class="v mono" id="cardNumber">—</div></div>
                            <div style="display:flex;gap:16px;">
                                <div class="idcard-field" style="flex:1;"><div class="k">Issued</div><div class="v" id="cardIssued">—</div></div>
                                <div class="idcard-field" style="flex:1;"><div class="k">Valid Until</div><div class="v" id="cardExpiry">—</div></div>
                            </div>
                        </div>
                        <div class="idcard-qrbox">
                            <img id="cardQr" src="" alt="QR">
                            <div class="cap" id="cardQrCap">Scan to verify</div>
                        </div>
                    </div>
                    <div class="idcard-foot">This ID is property of Bestlink College of the Philippines.<br>If found, please return to the Registrar's Office.</div>
                </div>

                <div class="idcard-back">
                    <div class="idcard-back-head">
                        <div class="b-school">BESTLINK COLLEGE OF THE PHILIPPINES<small>Registrar's Office — Caypombo, Sta. Maria, Bulacan</small></div>
                    </div>
                    <div class="idcard-back-sec">
                        <div class="idcard-sec-title"><i class="fa-solid fa-triangle-exclamation"></i> In case of emergency, please contact</div>
                        <div id="cardBackEmg"></div>
                    </div>
                    <div class="idcard-back-sec">
                        <div class="idcard-sec-title"><i class="fa-solid fa-house"></i> Address</div>
                        <div class="idcard-addr" id="cardBackAddr"><em>Not on file</em></div>
                    </div>
                    <div class="idcard-back-sec">
                        <div class="idcard-sec-title"><i class="fa-solid fa-file-shield"></i> Reminders</div>
                        <div class="idcard-addr" style="font-size:10.5px;">
                            • This card is non-transferable and must be worn at all times inside the campus.<br>
                            • Not valid without the signature of the Registrar.<br>
                            • Report lost cards immediately to the Registrar's Office.
                        </div>
                    </div>
                    <div class="idcard-sig">
                        <div class="line"></div>
                        <div class="who">Signature of Student — Not valid without signature</div>
                    </div>
                    <div class="idcard-back-foot">If this card is found, please return to the Registrar's Office or call the school hotline.</div>
                </div>
              </div>
            </div>
            <div style="text-align:center;margin-top:14px;">
                <button type="button" class="btn btn-light" onclick="toggleFlip()" style="min-width:150px;"><i class="fas fa-rotate"></i> Flip Card</button>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('viewModal')">Close</button>
            <button class="btn btn-primary" onclick="printCard()"><i class="fas fa-print"></i> Print ID</button>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal-content">
        <div class="modal-header"><h3><i class="fas fa-pen" style="color:#b45309;"></i> Update ID</h3><button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <input type="hidden" id="editId">
            <div class="detail-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;"><span style="color:#64748b;font-size:12px;">Student</span><span style="font-weight:600;" id="editStudent">—</span></div>
            <div class="detail-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;"><span style="color:#64748b;font-size:12px;">ID Number</span><span style="font-weight:600;" id="editNumber">—</span></div>
            <div class="form-row" style="margin-top:12px;">
                <div class="form-group"><label>Status</label><select id="editStatus" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option><option value="lost">Lost</option></select></div>
                <div class="form-group"><label>Expiry Date</label><input type="date" id="editExpiry" class="form-control"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitEdit()"><i class="fas fa-save"></i> Save</button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
<script>
function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow = ''; }
function openModal(id) { document.getElementById(id).classList.add('active'); document.body.style.overflow = 'hidden'; }
// Back-of-card data keyed by student_id: {address, emergency:[{name,rel,phone}]}
const CARD_EXTRAS = <?= json_encode($cardExtras ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
['issueModal','viewModal','editModal'].forEach(function(id) {
    document.getElementById(id).addEventListener('click', function(e) { if (e.target === this) closeModal(id); });
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { closeModal('issueModal'); closeModal('viewModal'); closeModal('editModal'); } });

// ── Table search ─────────────────────────────────────────────
(function () {
    const input = document.getElementById('idsSearch');
    const count = document.getElementById('idsCount');
    if (!input) return;
    const rows = Array.from(document.querySelectorAll('table.table tbody tr[data-search]'));
    function apply() {
        const q = input.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach(r => {
            const hit = !q || r.dataset.search.indexOf(q) !== -1;
            r.style.display = hit ? '' : 'none';
            if (hit) shown++;
        });
        if (count) count.textContent = shown + ' of ' + rows.length + ' IDs';
    }
    input.addEventListener('input', apply);
    apply();
})();

function openAdd() {
    document.getElementById('issueStudent').value = '';
    document.getElementById('issueType').value = 'school_id';
    document.getElementById('issueStatus').value = 'active';
    document.getElementById('issueNumber').value = '';
    document.getElementById('issueExpiry').value = '';
    openModal('issueModal');
}

function submitIssue() {
    const studentId = document.getElementById('issueStudent').value;
    if (!studentId) { showToast('Select a student.', 'warning'); return; }
    const btn = document.getElementById('issueSubmit');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Issuing...';
    fetch('../api/student-ids.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            student_id: studentId,
            id_type: document.getElementById('issueType').value,
            status: document.getElementById('issueStatus').value,
            id_number: document.getElementById('issueNumber').value,
            expiry_date: document.getElementById('issueExpiry').value
        })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) window.location.reload();
        else showToast(d.message || 'Error issuing ID.', 'error');
    })
    .catch(() => showToast('Network error.', 'error'))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Issue ID'; });
}

// ── Helpers: photo path + client-side QR fallback ────────────
function normalizePhotoPath(p) {
    if (!p) return '';
    p = p.trim();
    if (/^(https?:|data:|blob:)/i.test(p)) return p;
    if (p.startsWith('../') || p.startsWith('/')) return p;
    return '../' + p.replace(/^\.?\//, '');   // "uploads/students/x.jpg" → "../uploads/students/x.jpg"
}
function initialsOf(name) {
    return (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('') || '?';
}
// Always produce a QR data-URL client-side for fallback when server SVG fails to load
function generateQrDataUrl(studentId) {
    try {
        const qr = qrcode(0, 'M');
        qr.addData('https://registrar.bcpsms2.com/verify-student.php?student_id=' + encodeURIComponent(studentId || ''));
        qr.make();
        return qr.createDataURL(8, 8);
    } catch (e) {
        return '';
    }
}

// Auto-populate QR thumbnails for rows where server QR is missing
document.querySelectorAll('img.qr-auto[data-qr-student-id]').forEach(function(img) {
    var sid = img.getAttribute('data-qr-student-id');
    var url = generateQrDataUrl(sid);
    if (url) {
        img.src = url;
        img.setAttribute('data-name', img.getAttribute('data-qr-name') || '');
        img.setAttribute('data-id', img.getAttribute('data-qr-number') || '');
    } else {
        img.style.display = 'none';
    }
});

function showInitialsFallback() {
    const img = document.getElementById('cardPhoto');
    const ini = document.getElementById('cardInitials');
    if (img) img.style.display = 'none';
    if (ini) { ini.textContent = initialsOf(currentCardData ? currentCardData.name : ''); ini.style.display = 'flex'; }
}

// ── Back of card: emergency contacts, address (from server data) ──
function fillCardBack(studentId) {
    const emgBox = document.getElementById('cardBackEmg');
    const addrBox = document.getElementById('cardBackAddr');
    const extras = (typeof CARD_EXTRAS !== 'undefined' ? CARD_EXTRAS[studentId] : null) || {};
    const list = extras.emergency || [];
    if (!list.length) {
        emgBox.innerHTML = '<div class="idcard-addr"><em>No emergency contact on file</em></div>';
    } else {
        emgBox.innerHTML = list.map(c =>
            '<div class="idcard-emg-row">'
            + '<span class="idcard-emg-ico"><i class="fa-solid fa-phone-volume"></i></span>'
            + '<div><div class="idcard-emg-name">' + escHtml(c.name || '—') + '</div>'
            + '<div class="idcard-emg-sub">' + escHtml(c.rel || 'Emergency contact') + (c.phone ? ' · ' + escHtml(c.phone) : '') + '</div></div>'
            + '</div>'
        ).join('');
    }
    addrBox.innerHTML = extras.address ? escHtml(extras.address) : '<em>Not on file</em>';
}
function escHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function viewCard(btn) {
    const d = btn.closest('tr').dataset;
    currentCardData = {
        name: d.name, studentId: d.studentId, photo: d.photo, course: d.course,
        year: d.year, idnumber: d.idnumber, qr: d.qr, idtype: d.idtype,
        issued: d.issued, expiry: d.expiry, status: d.status, qrData: ''
    };

    // ── Photo: real photo if available, initials fallback otherwise ──
    const img = document.getElementById('cardPhoto');
    const ini = document.getElementById('cardInitials');
    const src = normalizePhotoPath(d.photo);
    ini.style.display = 'none';
    if (src) {
        img.style.display = 'block';
        img.src = src;   // onerror → showInitialsFallback()
    } else {
        img.style.display = 'none';
        ini.textContent = initialsOf(d.name);
        ini.style.display = 'flex';
    }

    document.getElementById('cardName').textContent = d.name;
    document.getElementById('cardCourse').textContent = d.course || '—';
    document.getElementById('cardNumber').textContent = d.idnumber || '—';
    document.getElementById('cardIssued').textContent = d.issued ? new Date(d.issued).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
    document.getElementById('cardExpiry').textContent = d.expiry ? new Date(d.expiry).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'No expiry';

    // ── QR: use the stored file when it loads; generate client-side otherwise ──
    const qrImg = document.getElementById('cardQr');
    const cap = document.getElementById('cardQrCap');
    cap.textContent = 'Scan to verify';
    cap.classList.remove('ok');
    qrImg.style.display = 'block';
    currentCardData.qrData = generateQrDataUrl(d.studentId);
    if (d.qr) {
        qrImg.onerror = function () { this.onerror = null; if (currentCardData.qrData) { this.src = currentCardData.qrData; cap.classList.add('ok'); } };
        qrImg.onload = function () { cap.classList.add('ok'); };
        qrImg.src = normalizePhotoPath(d.qr);
    } else {
        qrImg.onerror = null;
        qrImg.src = currentCardData.qrData;
        if (!currentCardData.qrData) { qrImg.style.display = 'none'; cap.textContent = 'QR unavailable'; }
        else cap.classList.add('ok');
    }

    fillCardBack(d.studentId);
    document.getElementById('cardFlip').classList.remove('flipped');   // always start on the front
    openModal('viewModal');
}

function toggleFlip() {
    document.getElementById('cardFlip').classList.toggle('flipped');
}

function editStatus(btn) {
    const d = btn.closest('tr').dataset;
    document.getElementById('editId').value = d.id;
    document.getElementById('editStudent').textContent = d.name;
    document.getElementById('editNumber').textContent = d.idnumber;
    document.getElementById('editStatus').value = d.status;
    document.getElementById('editExpiry').value = d.expiry || '';
    openModal('editModal');
}

// Print a clean official ID card sheet (school logo, photo, QR, signature line)
function printCard() {
    const d = currentCardData || {};
    const photo = normalizePhotoPath(d.photo) || '';
    const initials = initialsOf(d.name);
    const name = d.name || '\u2014';
    const course = d.course || '';   // year level shown by card color, not text
    const id = d.idnumber || '';
    const qr = d.qrData || (d.qr ? normalizePhotoPath(d.qr) : '');
    const type = (d.idtype || 'school_id').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    const expiry = d.expiry ? new Date(d.expiry).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'No expiry';
    /* Resolve relative paths to absolute so images work in pop-up */
    const _base = window.location.origin + window.location.pathname.replace(/[^\/]*$/, '');
    function _abs(p) { if (!p || p.startsWith('data:') || p.startsWith('http')) return p; return new URL(p, _base).href; }
    const _logoUrl = _abs('../assets/images/BCP_LOGO.png');
    const _photoUrl = _abs(photo);
    const _qrUrl = _abs(qr);
    const css = ''
        + '@page { size: A4 portrait; margin: 20mm; } '
        + '@media print { @page { size: A4 portrait; margin: 20mm; } body { margin: 0; width: 170mm; height: 257mm; } } '
        + 'body { margin: 0; font-family: Arial, sans-serif; -webkit-print-color-adjust: exact; } '
        + '.card { width: 86mm; height: 54mm; border-radius: 4mm; overflow: hidden; '
        + 'background: #fff; border: .3mm solid #dbeafe; box-sizing: border-box; position: relative; } '
        + '.h { background: linear-gradient(135deg,#1a3a8c,#2563eb); color:#fff; padding: 2.4mm 4mm; '
        + 'display:flex; align-items:center; gap:3mm; } '
        + '.h img { width: 7mm; height: 7mm; border-radius: 1.5mm; background:#fff; object-fit:contain; } '
        + '.h .s { font-size: 4mm; font-weight: 700; letter-spacing: .2mm; line-height:1.1; } '
        + '.h .s small { display:block; font-size:2.4mm; font-weight:400; opacity:.85; } '
        + '.b { display:flex; align-items:center; gap:3.4mm; padding: 2.6mm 4mm; } '
        + '.b img.p, .b .pini { width: 12mm; height: 15mm; object-fit: cover; border-radius: 1.5mm; '
        + 'border: .5mm solid #e2e8f0; box-sizing:border-box; } '
        + '.b .pini { display:flex; align-items:center; justify-content:center; font-size:4.5mm; font-weight:700; color:#94a3b8; background:#f1f5f9; } '
        + '.i { flex:1; min-width:0; } '
        + '.i .n { font-size: 4mm; font-weight: 700; color:#0f172a; } '
        + '.i .m { font-size: 2.6mm; color:#475569; margin-top:.5mm; } '
        + '.i .d { font-size: 3.2mm; font-weight: 700; color:#1d4ed8; letter-spacing:.4mm; margin-top:.8mm; } '
        + '.i .t { font-size:2.4mm; color:#64748b; text-transform:uppercase; letter-spacing:.3mm; margin-top:.5mm; } '
        + '.i .e { font-size:2.2mm; color:#64748b; margin-top:.5mm; } '
        + '.qr { text-align:center; flex-shrink:0; } '
        + '.qr img { width: 14mm; height: 14mm; background:#fff; padding:.8mm; border-radius:1.5mm; border:.3mm solid #e2e8f0; box-sizing:border-box; } '
        + '.qr .cap { font-size:1.8mm; font-weight:700; text-transform:uppercase; letter-spacing:.3mm; color:#94a3b8; margin-top:.5mm; } '
        + '.back { border-color:#e2e8f0; page-break-before: always; } '
        + '.bh { background:#0f172a; color:#fff; padding:2mm 4mm; display:flex; align-items:center; justify-content:space-between; } '
        + '.bh .s { font-size:3.2mm; font-weight:700; } '
        + '.bh .s small { display:block; font-size:2.1mm; font-weight:400; color:#94a3b8; } '
        + '.bh .tag { font-size:1.9mm; font-weight:700; text-transform:uppercase; letter-spacing:.3mm; background:#1e293b; color:#93c5fd; padding:.8mm 2mm; border-radius:2mm; } '
        + '.bb { padding:1.6mm 4mm 0; } '
        + '.bb .bt { font-size:2mm; font-weight:700; text-transform:uppercase; letter-spacing:.3mm; color:#94a3b8; margin-bottom:.8mm; } '
        + '.bb .er { font-size:2.5mm; color:#0f172a; padding:.5mm 0; } '
        + '.bb .er b { font-weight:700; } '
        + '.bb .ea { font-size:2.4mm; color:#334155; line-height:1.45; background:#f8fafc; border:.3mm solid #e2e8f0; border-radius:1.5mm; padding:1mm 2mm; } '
        + '.sig { text-align:center; padding:2.4mm 4mm 0; } '
        + '.sig .line { border-top:.3mm solid #334155; margin:0 14mm 1mm; } '
        + '.sig .who { font-size:2mm; color:#475569; text-transform:uppercase; letter-spacing:.3mm; } '
        + '.f { background:#fff; border-top:.3mm solid #e2e8f0; padding:1mm 4mm; text-align:center; '
        + 'font-size:2.2mm; color:#64748b; } '
        + '@media print { .card { margin: 0; border: none; } body { -webkit-print-color-adjust: exact; } }';
    // ── Back of card ──
    const extras = CARD_EXTRAS[d.studentId] || {};
    const emgList = extras.emergency || [];
    const emgLines = emgList.length
        ? emgList.map(c => '<div class="er"><b>' + escHtml(c.name || '\u2014') + '</b> \u2014 ' + escHtml(c.rel || 'Emergency contact') + (c.phone ? ' \u00b7 ' + escHtml(c.phone) : '') + '</div>').join('')
        : '<div class="er" style="color:#94a3b8;font-style:italic;">No emergency contact on file</div>';
    const address = extras.address ? escHtml(extras.address) : 'Not on file';
    let front = '<div class="card">';
    front += '<div class="h"><img src="' + _logoUrl + '" alt="BCP"><div class="s">BESTLINK COLLEGE OF THE PHILIPPINES<small>Official ' + type + '</small></div></div>';
    front += '<div class="b">' + (_photoUrl ? '<img class="p" src="' + _photoUrl + '" alt="photo">' : '<div class="pini">' + initials + '</div>');
    front += '<div class="i"><div class="n">' + name + '</div><div class="m">' + course + '</div><div class="d">' + id + '</div><div class="t">' + type + '</div><div class="e">Valid until: ' + expiry + '</div></div>';
    front += (_qrUrl ? '<div class="qr"><img src="' + _qrUrl + '" alt="QR"><div class="cap">Scan to verify</div></div>' : '') + '</div>';
    front += '<div class="f">This ID is property of Bestlink College of the Philippines. If found, return to the Registrar\'s Office.</div></div>';
    let back = '<div class="card back">';
    back += '<div class="bh"><div class="s">BESTLINK COLLEGE OF THE PHILIPPINES<small>Registrar&#39;s Office \u2014 Caypombo, Sta. Maria, Bulacan</small></div><span class="tag">ID Card</span></div>';
    back += '<div class="bb"><div class="bt">In case of emergency, please contact</div>' + emgLines + '</div>';
    back += '<div class="bb"><div class="bt">Address</div><div class="ea">' + address + '</div></div>';
    back += '<div class="bb"><div class="bt">Reminders</div><div class="ea">This card is non-transferable. Not valid without the signature of the student and the Registrar. Report lost cards immediately.</div></div>';
    back += '<div class="sig"><div class="line"></div><div class="who">Signature of Student</div></div>';
    back += '<div class="f">If found, please return to the Registrar&#39;s Office.</div></div>';
    let doc = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Student ID \u2014 ' + name + '</title><style>' + css + '</style></head><body>' + front + back + '</body></html>';
    const blob = new Blob([doc], {type:'text/html'});
    const blobUrl = URL.createObjectURL(blob);
    const w = window.open(blobUrl, '_blank', 'width=700,height=900');
    if (!w) { showToast('Pop-up blocked.','error'); return; }
    w.onload = function(){ setTimeout(function(){ w.print(); URL.revokeObjectURL(blobUrl); }, 500); };
}

// Hold the currently-viewed card for print
let currentCardData = null;

function submitEdit() {
    const id = document.getElementById('editId').value;
    fetch('../api/student-ids.php?action=update', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id,
            status: document.getElementById('editStatus').value,
            card_color: document.getElementById('editYear').value,
            expiry_date: document.getElementById('editExpiry').value
        })
    })
    .then(r => r.json())
    .then(d => { if (d.success) window.location.reload(); else showToast(d.message || 'Error updating.', 'error'); })
    .catch(() => showToast('Network error.', 'error'));
}

async function deleteId(btn) {
    const d = btn.closest('tr').dataset;
    if (!await confirmAction({
        title: 'Delete ID',
        body: 'Delete ID <strong>' + escText(d.idnumber) + '</strong> for ' + escText(d.name) + '? This cannot be undone.',
        confirmLabel: 'Delete',
        tone: 'danger'
    })) return;
    fetch('../api/student-ids.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: d.id })
    })
    .then(r => r.json())
    .then(d => { if (d.success) window.location.reload(); else showToast(d.message || 'Error deleting.', 'error'); })
    .catch(() => showToast('Network error.', 'error'));
}

// ── QR Modal ──────────────────────────────────────────────────────
function showQrModal(img) {
    const overlay = document.getElementById('qrModalOverlay');
    overlay.querySelector('img').src = img.src;
    overlay.querySelector('.qr-modal-name').textContent = img.dataset.name || '';
    overlay.querySelector('.qr-modal-sub').textContent = img.dataset.id ? 'Student #: ' + img.dataset.id : '';
    overlay.classList.add('active');
}
function closeQrModal() {
    document.getElementById('qrModalOverlay').classList.remove('active');
}
document.getElementById('qrModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeQrModal();
});
</script>

<!-- QR Preview Modal -->
<div id="qrModalOverlay">
    <div class="qr-modal-box">
        <img src="" alt="QR Code">
        <div class="qr-modal-name"></div>
        <div class="qr-modal-sub"></div>
        <button class="qr-modal-close" onclick="closeQrModal()"><i class="fas fa-times"></i> Close</button>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
