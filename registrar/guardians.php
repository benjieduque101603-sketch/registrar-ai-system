<?php
// ============================================================
//  REGISTRAR/GUARDIANS.PHP
//  Registrar-authoritative contact management — the single place
//  the Registrar manages a student's guardians, emergency contacts,
//  AND email recipients, plus the queue of student change requests.
//
//  * Guardians + emergency contacts   → guardians / emergency_contacts
//  * Email recipients (verified + perms, Test Email, snapshot,
//    transcript)                      → contact_recipients
//  * Student "Request a Change" queue → contact_change_requests
//    (approve APPLIES the change to the real table)
//  * Auto-fill from enrollment        → pulls father/mother names
//    off the students record (api/contacts.php pull_enrollment)
//  Built on the shared registrar.css + registrar-premium.css system.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$students = $db->fetchAll(
    "SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name
     FROM students WHERE status != 'archived'
     ORDER BY last_name, first_name"
);

$rows = $db->fetchAll("
    SELECT s.id AS student_id, s.student_number, CONCAT(s.first_name,' ',s.last_name) AS student_name,
           g.id AS guardian_id, g.full_name, g.relationship, g.contact_number, g.email, g.is_primary, g.is_emergency
    FROM students s
    LEFT JOIN guardians g ON g.student_id = s.id
    WHERE s.status != 'archived'
    ORDER BY s.last_name, s.first_name, g.is_primary DESC, g.id
");
$byStudent = [];
foreach ($rows as $r) {
    if (!isset($byStudent[$r['student_id']])) {
        $byStudent[$r['student_id']] = [
            'student_id' => $r['student_id'],
            'student_number' => $r['student_number'],
            'student_name' => $r['student_name'],
            'guardians' => [],
        ];
    }
    if ($r['guardian_id']) $byStudent[$r['student_id']]['guardians'][] = $r;
}

// Emergency contacts, grouped by student (names shown inline in the table).
$emgByStudent = [];
foreach ($db->fetchAll("SELECT student_id, full_name, relationship, contact_number FROM emergency_contacts ORDER BY full_name") as $e) {
    $emgByStudent[$e['student_id']][] = $e;
}

// Email recipients (contact_recipients), grouped by student.
$contactByStudent = [];
try {
    foreach ($db->fetchAll("SELECT * FROM contact_recipients ORDER BY verified DESC, id DESC") as $c) {
        $contactByStudent[(int) $c['student_id']][] = $c;
    }
} catch (Throwable $e) {
    error_log('[guardians] contact_recipients query failed: ' . $e->getMessage());
    $contactByStudent = [];
}

// ── Overview counters ─────────────────────────────────────────
$statStudents = count($byStudent);
$statGuardians = 0;
foreach ($byStudent as $s) $statGuardians += count($s['guardians']);

$statMissing = count(array_filter($byStudent, fn($s) => empty($s['guardians'])));
$statEmgMissing = count(array_filter($byStudent, fn($s) => empty($emgByStudent[(int) $s['student_id']] ?? [])));

// Reachability, as a share of the student list. A bare "2" doesn't tell a registrar
// whether that's a rounding error or half the school; 60% does.
$statEmgPct = $statStudents > 0
    ? (int) round((($statStudents - $statEmgMissing) / $statStudents) * 100)
    : 0;
$statGuardPct = $statStudents > 0
    ? (int) round((($statStudents - $statMissing) / $statStudents) * 100)
    : 0;

/** Two-letter initials from a name, for the student avatar tile. */
function gdn_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return '?';
    $sub = fn($s) => function_exists('mb_substr') ? mb_substr($s, 0, 1, 'UTF-8') : substr($s, 0, 1);
    $up  = fn($s) => function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    $init = $up($sub($parts[0]));
    if (count($parts) > 1) $init .= $up($sub(end($parts)));
    return $init;
}

/** HTML-escape, tolerant of null. */
function gdn_esc(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$page_title = 'Guardians & Contacts';
$APP_ROOT = '../';
$ACTIVE_NAV = 'guardians';
$body_page = 'guardians';   // scopes the registrar-blue layer below
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="dashboard-main">
    <div class="dashboard-container">
        <header class="header">
            <div class="title">
                <div class="gdn-kicker"><i class="fas fa-address-book"></i> Contact records</div>
                <h1>Guardians &amp; Contacts</h1>
                <p>Registrar-managed guardians, emergency contacts, and email recipients for every student</p>
            </div>
            <!-- The header used to carry a "Sync from student information"
                 button here. It called openManage() with no arguments, so
                 all it did was open this modal with the student picker
                 showing - it never synced anything, and the row-level
                 pencil button opens the same modal directly. Two buttons
                 for one modal, one of them mislabelled. Contacts are now
                 maintained per student from the list row, or from the
                 Add Student form. -->
        </header>

        <!-- ── Metric strip ────────────────────────────────────
             Mirrors the strip on the other registrar list pages
             (RFID Cards, Masterlist): one connected panel, hairline
             dividers, an inset accent underline per figure, and an
             optional badge in the top row. The badges carry coverage,
             since a bare gap count doesn't say whether it's a rounding
             error or half the school. -->
        <div class="gdn-stats">
            <div class="gdn-stat gk-students">
                <div class="gdn-stat-top"></div>
                <div class="gdn-stat-number"><?= $statStudents ?></div>
                <div class="gdn-stat-label">Students on file</div>
            </div>
            <div class="gdn-stat gk-guardians">
                <div class="gdn-stat-top"></div>
                <div class="gdn-stat-number"><?= $statGuardians ?></div>
                <div class="gdn-stat-label">Guardians on file</div>
            </div>
            <div class="gdn-stat gk-noemg">
                <div class="gdn-stat-top">
                    <span class="gdn-stat-badge down"><?= $statEmgPct ?>% covered</span>
                </div>
                <div class="gdn-stat-number"><?= $statEmgMissing ?></div>
                <div class="gdn-stat-label">No emergency contact</div>
            </div>
            <div class="gdn-stat gk-noguard">
                <div class="gdn-stat-top">
                    <span class="gdn-stat-badge warn"><?= $statGuardPct ?>% covered</span>
                </div>
                <div class="gdn-stat-number"><?= $statMissing ?></div>
                <div class="gdn-stat-label">No guardian recorded</div>
            </div>
        </div>

              <div class="panel gdn-panel">
            <div class="gdn-toolbar">
                <div class="gdn-toolbar-title">
                    <i class="fa-solid fa-address-book"></i> All students
                    <span class="gdn-pill"><?= count($byStudent) ?></span>
                </div>
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" id="guardSearch" placeholder="Search by student name or number...">
                </div>
            </div>

            <div class="table-responsive" style="overflow-x:auto;">
            <table class="ct-table">
                <thead><tr>
                    <th>Student</th>
                    <th><span class="gdn-h"><i class="fa-solid fa-people-roof"></i> Guardians</span></th>
                    <th><span class="gdn-h"><i class="fa-solid fa-truck-medical"></i> Emergency Contacts</span></th>
                    <th><span class="gdn-h"><i class="fa-solid fa-envelope-circle-check"></i> Email Recipients</span></th>
                    <th style="text-align:center;"><span class="gdn-h"><i class="fa-solid fa-pen-to-square"></i> Manage</span></th>
                </tr></thead>
                <tbody id="guardBody">
                <?php if (empty($byStudent)): ?>
                    <tr class="empty-state-row"><td colspan="5"><div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:300px;gap:8px;"><p style="font-size:16px;font-weight:600;color:#334155;margin:0;">No students found yet</p><p style="margin:0;color:#94a3b8;">Add students first to manage their contacts</p></div></td></tr>
                <?php else: foreach ($byStudent as $s): ?>
                    <tr data-id="<?= (int)$s['student_id'] ?>"
                        data-search="<?= htmlspecialchars(strtolower($s['student_name'].' '.$s['student_number']), ENT_QUOTES) ?>">
                        <td>
                            <div class="ct-who">
                                <span class="ct-avatar<?= empty($s['guardians']) ? ' none' : '' ?>"><?= gdn_initials($s['student_name']) ?></span>
                                <div>
                                    <div class="ct-name"><?= htmlspecialchars($s['student_name']) ?></div>
                                    <div class="ct-rel"><?= htmlspecialchars($s['student_number']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if (empty($s['guardians'])): ?>
                                <span class="ct-none ct-gap"><i class="fa-solid fa-circle-exclamation"></i> No guardian recorded</span>
                            <?php else: ?>
                                <ul class="ct-details">
                                <?php foreach ($s['guardians'] as $g): ?>
                                    <li class="ct-contact">
                                        <i class="fa-solid fa-people-roof"></i>
                                        <div>
                                            <div class="ct-contact-name">
                                                <?= htmlspecialchars($g['full_name']) ?>
                                                <span class="ct-tag t-blue"><?= htmlspecialchars(ucfirst((string) $g['relationship'])) ?></span>
                                                <?php if ($g['is_primary']): ?><span class="ct-tag t-green">Primary</span><?php endif; ?>
                                                <?php if ($g['is_emergency']): ?><span class="ct-tag t-red">Emergency</span><?php endif; ?>
                                            </div>
                                            <div class="ct-contact-sub">
                                                <?php if (trim((string) ($g['contact_number'] ?? '')) !== ''): ?>
                                                    <span><i class="fa-solid fa-phone"></i> <?= htmlspecialchars((string) $g['contact_number']) ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($g['email'])): ?>
                                                    <span><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars((string) $g['email']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $emgList = $emgByStudent[$s['student_id']] ?? []; ?>
                            <?php if (!$emgList): ?>
                                <span class="ct-none ct-empty"><i class="fa-solid fa-minus"></i> None on file</span>
                            <?php else: ?>
                                <ul class="ct-details">
                                <?php foreach ($emgList as $e): ?>
                                    <li class="ct-contact">
                                        <i class="fa-solid fa-truck-medical"></i>
                                        <div>
                                            <div class="ct-contact-name"><?= htmlspecialchars((string) $e['full_name']) ?></div>
                                            <div class="ct-contact-sub">
                                                <?php if (trim((string) ($e['relationship'] ?? '')) !== ''): ?>
                                                    <span><?= htmlspecialchars((string) $e['relationship']) ?></span>
                                                <?php endif; ?>
                                                <?php if (trim((string) ($e['contact_number'] ?? '')) !== ''): ?>
                                                    <span><i class="fa-solid fa-phone"></i> <?= htmlspecialchars((string) $e['contact_number']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $cList = $contactByStudent[$s['student_id']] ?? []; ?>
                            <?php if (!$cList): ?>
                                <span class="ct-none ct-empty"><i class="fa-solid fa-minus"></i> None on file</span>
                            <?php else: ?>
                                <ul class="ct-details">
                                <?php foreach ($cList as $c): ?>
                                    <li class="ct-contact">
                                        <i class="fa-solid fa-envelope-open-text"></i>
                                        <div>
                                            <div class="ct-contact-name">
                                                <?= htmlspecialchars((string) $c['full_name']) ?>
                                                <?php if ((int) ($c['verified'] ?? 0) === 1): ?><span class="ct-vdot" title="Verified"></span><?php endif; ?>
                                            </div>
                                            <div class="ct-contact-sub">
                                                <span><i class="fa-solid fa-at"></i> <?= htmlspecialchars((string) $c['email']) ?></span>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td><div class="action-group">
                            <button class="action-btn edit" onclick="openManage(<?= (int)$s['student_id'] ?>,'<?= htmlspecialchars($s['student_name'], ENT_QUOTES) ?>')" title="Manage"><i class="fas fa-user-pen"></i></button>
                        </div></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>

            <div class="table-footer">
                <div class="info-text">Showing <strong id="shownCount"><?= count($byStudent) ?></strong> students</div>
            </div>
        </div>
    </div>
</main>

<!-- Manage Contacts Modal -->
<div class="modal-overlay" id="manageModal"><div class="modal-content wide">
    <div class="modal-header mg-header">
        <h2><i class="fa-solid fa-address-book"></i> <span id="mgTitle">Manage Contacts</span></h2>
        <button class="modal-close" onclick="closeModal('manageModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <!-- The student picker used to live here, shown only when the modal
             was opened from the header button with no student in context.
             That button is gone, so no code path can reach the picker state
             any more; every remaining entry point (the list row's pencil,
             and the Add Student form) opens this modal already bound to one
             student. The markup is removed rather than left hidden so it
             cannot drift back into use - a visible control that does
             nothing is worse than no control. -->
        <input type="hidden" id="mgStudentId">

        <div class="mg-tabs">
            <button type="button" class="mg-tab active" data-tab="tab-guardians" onclick="switchTab('tab-guardians', this)"><i class="fa-solid fa-people-roof"></i> Guardians <span class="mg-count" id="mgGCount">0</span></button>
            <button type="button" class="mg-tab" data-tab="tab-emergency" onclick="switchTab('tab-emergency', this)"><i class="fa-solid fa-truck-medical"></i> Emergency <span class="mg-count" id="mgECount">0</span></button>
            <button type="button" class="mg-tab" data-tab="tab-email" onclick="switchTab('tab-email', this)"><i class="fa-solid fa-envelope-circle-check"></i> Email Recipients <span class="mg-count" id="mgCCount">0</span></button>
        </div>

        <div class="mg-tabpane active" id="tab-guardians">
            <div class="mg-pane-toolbar">
                <span class="mg-hint">Guardians / parents on file for this student.</span>
                <button class="btn btn-light btn-sm" onclick="pullEnrollment()" title="Create guardian rows from the student's enrollment record (father / mother name)."><i class="fa-solid fa-cloud-arrow-down"></i> Auto-fill from Enrollment</button>
            </div>
            <div id="mgGuardians"></div>
            <button class="btn btn-light btn-sm mg-add" onclick="addGuardianRow()"><i class="fas fa-plus"></i> Add Guardian</button>
        </div>

        <div class="mg-tabpane" id="tab-emergency">
            <div class="mg-pane-toolbar">
                <span class="mg-hint">Point-of-contact for health &amp; safety emergencies.</span>
            </div>
            <div id="mgEmergency"></div>
            <button class="btn btn-light btn-sm mg-add" onclick="addEmergencyRow()"><i class="fas fa-plus"></i> Add Emergency Contact</button>
        </div>

        <div class="mg-tabpane" id="tab-email">
            <div class="mg-pane-toolbar">
                <span class="mg-hint">Verified email recipients — invoices, grade snapshots, transcripts, alerts.</span>
            </div>
            <div id="mgEmail"></div>
            <button class="btn btn-light btn-sm mg-add" onclick="addEmailRow()"><i class="fas fa-plus"></i> Add Email Recipient</button>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-light" onclick="closeModal('manageModal')">Cancel</button>
        <button class="btn btn-primary" onclick="saveAll()"><i class="fas fa-save"></i> Save Contacts</button>
    </div>
</div></div>

<style>
/* ── Metric strip ───────────────────────────────── */
/* The overview figures reuse the shared registrar metric strip; see
   body[data-page="guardians"] .gdn-stats below and rfid-cards.php. */
.gdn-stats {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(165px,1fr)); gap:16px;
    margin-bottom:24px;
}

/* ── List panel ─────────────────────────────────── */
.gdn-panel { padding:0; overflow:hidden; }
.gdn-toolbar {
    display:flex; align-items:center; justify-content:space-between;
    gap:16px; flex-wrap:wrap; padding:18px 24px;
    border-bottom:1px solid #e8ecf3;
}
.gdn-toolbar-title { display:flex; align-items:center; gap:10px; font-size:15px; font-weight:800; color:#0d1b2e; letter-spacing:-.3px; }
.gdn-toolbar-title i { color:#2563eb; }
.gdn-pill {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:26px; height:22px; padding:0 8px; border-radius:999px;
    background:#eef4ff; color:#2563eb; font-size:12px; font-weight:800;
}
.gdn-pill.amber { background:#ffedd5; color:#ea580c; }
.gdn-toolbar .search-wrap { position:relative; flex:1 1 320px; min-width:220px; max-width:420px; margin-left:auto; }
.gdn-toolbar .search-wrap i { position:absolute; left:13px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:14px; pointer-events:none; }
.gdn-toolbar .search-wrap input {
    width:100%; height:42px; padding:0 14px 0 38px;
    border:1.5px solid #e2e8f0; border-radius:10px;
    font-size:14px; font-family:inherit; outline:none; background:#fff; color:#1e293b;
    box-sizing:border-box; transition:all .2s cubic-bezier(.16,1,.3,1);
}
.gdn-toolbar .search-wrap input:focus { border-color:#2563eb; box-shadow:0 0 0 4px rgba(37,99,235,.1); background:#fff; }

/* ── Student avatar tile ────────────────────────── */
.gdn-avatar {
    width:38px; height:38px; border-radius:12px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-weight:800; font-size:13px;
    background:linear-gradient(135deg,#1a3a8c,#2563eb);
    box-shadow:0 4px 10px rgba(26,58,140,.25);
}
.gdn-avatar.none { background:#e2e8f0; color:#94a3b8; box-shadow:none; }

/* ── Organized contacts table (ct-* — mirrors student portal) ── */
.ct-table{width:100%;border-collapse:collapse;background:#fff;margin:0;table-layout:fixed;min-width:820px;}
.ct-table th{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#64748b;background:#fbfcfe;padding:13px 18px;text-align:left;border-bottom:1px solid #eef2f7;font-weight:800;}
.ct-table td{padding:14px 18px;border-top:1px solid #eef2f7;color:#334155;font-size:13px;vertical-align:top;}
.ct-table td:last-child{vertical-align:middle;text-align:center;}
.ct-table tbody tr{transition:background .15s ease;}
.ct-table tbody tr:hover{background:#f8fafc;}
.ct-table thead th:nth-child(1){width:23%}
.ct-table thead th:nth-child(2){width:28%}
.ct-table thead th:nth-child(3){width:16%}
.ct-table thead th:nth-child(4){width:21%}
.ct-table thead th:nth-child(5){width:12%;text-align:center;}
.ct-who{display:flex;align-items:center;gap:12px;}
.ct-avatar{width:40px;height:40px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;background:linear-gradient(135deg,#1a3a8c,#2563eb);color:#fff;}
.ct-avatar.none{background:#e2e8f0;color:#94a3b8;}
.ct-name{font-weight:800;color:#0f172a;font-size:14px;}
.ct-rel{font-size:12px;color:#64748b;margin-top:2px;}
.ct-details{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;}
.ct-contact{display:flex;align-items:flex-start;gap:9px;padding:7px 0;}
.ct-contact + .ct-contact{border-top:1px solid #f1f5f9;}
.ct-contact > i{width:16px;color:#94a3b8;font-size:12px;flex-shrink:0;margin-top:2px;}
.ct-contact-name{font-weight:700;color:#0f172a;font-size:12.5px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;line-height:1.35;}
.ct-contact-sub{font-size:11px;color:#64748b;margin-top:2px;display:flex;gap:10px;flex-wrap:wrap;align-items:baseline;line-height:1.5;}
.ct-contact-sub i{color:#94a3b8;font-size:10px;}
.ct-vdot{width:7px;height:7px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.18);display:inline-block;flex-shrink:0;}
.ct-none{font-size:12px;color:#94a3b8;font-style:italic;}
.ct-tag{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:999px;letter-spacing:.2px;white-space:nowrap;}
.ct-tag.t-green{background:#dcfce7;color:#15803d;}
.ct-tag.t-red{background:#fee2e2;color:#dc2626;}
.ct-tag.t-purple{background:#f3e8ff;color:#7c3aed;}
.ct-tag.t-blue{background:#eef4ff;color:#2563eb;}

/* ── Approvals queue ─────────────────────────────── */
.ap-row { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:16px 24px; border-bottom:1px solid #f1f5f9; }
.ap-row:last-child { border-bottom:none; }
.ap-main { flex:1; min-width:0; }
.ap-head { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.ap-student { font-weight:800; color:#0d1b2e; font-size:14px; }
.ap-student small { font-weight:600; color:#94a3b8; margin-left:4px; font-size:11.5px; }
.ap-time { margin-left:auto; font-size:11.5px; color:#94a3b8; font-weight:600; }
.ap-body { margin-top:6px; font-size:13px; color:#334155; }
.ap-detail { color:#64748b; }
.ap-reason {
    margin-top:8px; font-size:12.5px; color:#92400e; background:#fffbeb;
    border:1px solid #fde68a; border-radius:8px; padding:6px 10px;
    display:inline-flex; gap:8px; align-items:flex-start;
}
.ap-reason i { color:#d97706; margin-top:2px; }
.ap-actions { display:flex; gap:8px; flex-shrink:0; }

/* ── "+N more" collapse chip ─────────────────────── */
.gdn-more {
    display: inline-flex; align-items: center; gap: 5px;
    margin-top: 6px; padding: 4px 10px;
    border: 1px dashed #cbd5e1; border-radius: 999px;
    background: #fff; color: #2563eb;
    font-size: 11.5px; font-weight: 700; font-family: inherit;
    cursor: pointer; transition: all .15s ease;
}
.gdn-more:hover { background: #eff6ff; border-color: #93c5fd; }

/* ── Modal header (scoped to #manageModal) ── */
#manageModal .modal-header.mg-header {
    background:#fff;
    margin:-28px -32px 0; padding:22px 28px;
    border-radius:20px 20px 0 0; color:#0f172a;
    border-bottom:1px solid #e8ecf3;
}
#manageModal .modal-header.mg-header h2 { color:#0f172a; font-size:18px; }
#manageModal .modal-header.mg-header h2 i { color:#2563eb; }
#manageModal .modal-close { background:#f1f5f9; color:#94a3b8; }
#manageModal .modal-close:hover { background:#e2e8f0; color:#1e293b; }
#manageModal .modal-body { padding:22px 0 4px; }

/* ── Tabs ────────────────────────────────────────── */
.mg-tabs {
    display:flex; gap:4px; flex-wrap:wrap;
    border-bottom:2px solid #f1f5f9; margin-bottom:16px; padding:0 2px;
}
.mg-tab {
    display:inline-flex; align-items:center; gap:8px;
    padding:10px 14px; border:none; background:transparent; cursor:pointer;
    border-bottom:2px solid transparent; margin-bottom:-2px;
    font-size:13px; font-weight:700; color:#64748b; font-family:inherit;
    border-radius:10px 10px 0 0; transition:all .18s ease;
}
.mg-tab:hover { color:#1d4ed8; background:#f8fafc; }
.mg-tab.active { color:#1d4ed8; border-bottom-color:#2563eb; background:#eff6ff; }
.mg-tabpane { display:none; }
.mg-tabpane.active { display:block; animation:fadein .2s ease; }
@keyframes fadein { from{opacity:0; transform:translateY(4px)} to{opacity:1; transform:none} }
.mg-pane-toolbar { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:10px; }
.mg-hint { font-size:12px; color:#94a3b8; }

/* ── Modal sections ──────────────────────────────── */
.mg-section { margin-bottom:20px; }
.mg-section-hd {
    display:flex; align-items:center; gap:8px;
    font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.6px;
    color:#64748b; margin-bottom:10px;
}
.mg-section-hd i { color:#2563eb; font-size:13px; }
.mg-count {
    min-width:22px; height:18px; padding:0 6px; border-radius:999px;
    background:#eef4ff; color:#2563eb; font-size:11px; font-weight:800;
    display:inline-flex; align-items:center; justify-content:center;
}
.mg-empty { color:#94a3b8; font-size:12.5px; padding:8px 2px 2px; }

/* ── Modal contact rows ──────────────────────────── */
.mg-row {
    border:1.5px solid #e8ecf3; border-radius:12px;
    padding:12px 14px; margin-bottom:10px; background:#f8fafc;
    transition:border-color .2s ease;
}
.mg-row:hover { border-color:#d4dce6; }
.mg-row-grid { display:grid; grid-template-columns:2fr 1fr 1fr; gap:10px; margin-bottom:10px; }
.mg-row-grid-e { grid-template-columns:2fr 1fr 1fr auto; align-items:center; margin-bottom:0; }
.mg-row-sub { display:grid; grid-template-columns:1fr auto; gap:10px; align-items:center; }
.mg-toggles { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

/* Email recipient row — flex so the badges/actions wrap on narrow modals */
.c-row .mg-row-sub { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
.c-actions { display:flex; align-items:center; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
.c-actions .btn-sm { padding:6px 9px; font-size:11.5px; }
.mg-verified, .mg-unverified {
    display:inline-flex; align-items:center; gap:5px;
    font-size:10.5px; font-weight:800; padding:4px 8px; border-radius:999px; letter-spacing:.3px;
}
.mg-verified   { background:#dcfce7; color:#15803d; }
.mg-unverified { background:#fef3c7; color:#b45309; }

/* Pill toggles (Primary / Emergency / permissions) */
.mg-toggle { display:inline-flex; align-items:center; cursor:pointer; position:relative; }
.mg-toggle input { position:absolute; opacity:0; pointer-events:none; }
.mg-toggle span {
    display:inline-flex; align-items:center; gap:6px;
    padding:7px 12px; border-radius:999px;
    background:#eef2f7; border:1.5px solid #e2e8f0; color:#64748b;
    font-size:11.5px; font-weight:700; user-select:none;
    transition:all .2s cubic-bezier(.16,1,.3,1);
}
.mg-toggle span i { font-size:11px; }
.mg-toggle input:checked + span {
    background:linear-gradient(135deg,#1a3a8c 0%, #2563eb 100%);
    border-color:transparent; color:#fff;
    box-shadow:0 4px 10px rgba(26,58,140,.28);
}
.mg-add { margin-top:2px; }

@media (max-width:1200px) { .gdn-stats { grid-template-columns:repeat(2,1fr); } }
@media (max-width:640px)  {
    .gdn-stats { grid-template-columns:1fr; }
    .gdn-toolbar { flex-direction:column; align-items:stretch; }
    /* The search box is `flex:1 1 300px` — a WIDTH basis. Once the toolbar
       flips to a column, that basis applies to height and inflates the
       wrapper. Reset it to a normal block. */
    .gdn-toolbar .search-wrap { flex:0 0 auto; width:100%; max-width:none; margin-left:0; }
    .mg-row-grid, .mg-row-sub, .mg-row-grid-e { grid-template-columns:1fr; }
    .ap-row { flex-direction:column; }
    .ap-actions { width:100%; justify-content:flex-end; }
}
</style>

<style>
/* ============================================================
   REGISTRAR-BLUE LAYER — Guardians & Contacts
   Appended after the page's own styles so it wins on cascade
   order without rewriting rules the modal JS depends on
   (.gdn-more, .ct-contact, .g-row, .e-row, .c-row are all
   queried at runtime, so their markup and counts must hold).

   The organising idea: the three contact columns are the same
   repeated pattern, so they are colour-coded once — guardians
   blue, emergency rose, email violet — in the column header, the
   row icons, and the empty state. That does the wayfinding work
   three identical grey blocks were failing to do.
   ============================================================ */

/* ── Page shell ──────────────────────────────── */
body[data-page="guardians"]{background:#f5f7fb;color:#0f172a}

/* ── Page header ─────────────────────────────── */
body[data-page="guardians"] .header{
    display:flex;align-items:flex-end;justify-content:space-between;gap:20px;
    flex-wrap:wrap;margin:0 0 16px;padding:25px 27px;
    border:1px solid #c7d7fe;border-radius:19px;
    background:linear-gradient(120deg,#eff6ff,#fff 68%);
    box-shadow:0 10px 30px rgba(37,99,235,.08);
}
body[data-page="guardians"] .header .title h1{
    margin:0 0 5px;font-size:28px;line-height:1.1;letter-spacing:-.03em;color:#172554;
}
body[data-page="guardians"] .header .title p{
    margin:0;max-width:620px;font-size:12.5px;line-height:1.5;color:#64748b;
}
/* Small caps kicker naming what the module holds — matches .rc-kicker on
   rfid-cards.php, which uses the same two-word noun-phrase voice. */
.gdn-kicker{
    display:flex;align-items:center;gap:7px;margin-bottom:7px;color:#1d4ed8;
    font-size:10.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;
}
body[data-page="guardians"] .header-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}

/* ── Metric strip ─────────────────────────────
   Mirrors registrar/rfid-cards.php: one connected panel whose cells are
   separated by hairlines rather than gaps, each carrying an inset 3px
   accent underline, with an optional badge in the top row. */
body[data-page="guardians"] .gdn-stats{
    display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:0;
    margin:0 0 16px;background:#fff;border:1px solid #dbeafe;border-radius:16px;
    box-shadow:0 6px 22px rgba(15,23,42,.04);overflow:hidden;
}
body[data-page="guardians"] .gdn-stat{
    position:relative;background:transparent;border:0;border-radius:0;padding:17px 20px;
    border-right:1px solid #e2e8f0;box-shadow:none;transition:none;
}
body[data-page="guardians"] .gdn-stat:last-child{border-right:0}
body[data-page="guardians"] .gdn-stat::after{
    content:"";position:absolute;left:20px;right:20px;bottom:0;height:3px;background:#dbeafe;
}
body[data-page="guardians"] .gdn-stat:hover{transform:none;box-shadow:none;border-color:transparent}
body[data-page="guardians"] .gdn-stat .gdn-stat-top{
    display:flex;align-items:center;justify-content:space-between;gap:8px;
    margin:0 0 6px;min-height:16px;
}
body[data-page="guardians"] .gdn-stat .gdn-stat-number{
    font-size:28px;font-weight:800;line-height:1.1;color:#0f172a;
    font-variant-numeric:tabular-nums;
}
body[data-page="guardians"] .gdn-stat .gdn-stat-label{
    color:#64748b;font-size:10px;font-weight:800;letter-spacing:.07em;
    text-transform:uppercase;margin-top:2px;line-height:1.3;
}
body[data-page="guardians"] .gdn-stat-badge{
    font-size:10px;font-weight:600;padding:1px 7px;border-radius:9999px;
    display:inline-flex;align-items:center;gap:4px;
}
body[data-page="guardians"] .gdn-stat-badge.down{color:#dc2626;background:#fee2e2}
body[data-page="guardians"] .gdn-stat-badge.warn{color:#b45309;background:#fef3c7}
/* Each accent must carry the same specificity as the base ::after rule
   above. Unprefixed (.gk-students = 0-1-0) loses to
   body[data-page] .gdn-stat (0-2-1), which would pin every card to the
   same pale blue. */
body[data-page="guardians"] .gdn-stat.gk-students::after{background:#1d4ed8}
body[data-page="guardians"] .gdn-stat.gk-guardians::after{background:#16a34a}
body[data-page="guardians"] .gdn-stat.gk-noemg::after{background:#dc2626}
body[data-page="guardians"] .gdn-stat.gk-noguard::after{background:#d97706}
/* ── List panel ───────────────────────────────── */
body[data-page="guardians"] .gdn-panel{
    padding:0;overflow:hidden;background:#fff;
    border:1px solid #e2e8f0;border-radius:16px;
    box-shadow:0 1px 3px rgba(15,23,42,.04);
}
body[data-page="guardians"] .gdn-toolbar{
    display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;
    padding:14px 18px;background:#fff;border-bottom:1px solid #e5e7eb;
}
body[data-page="guardians"] .gdn-toolbar-title{
    display:flex;align-items:center;gap:9px;font-size:13px;font-weight:700;
    letter-spacing:-.01em;color:#1e293b;
}
body[data-page="guardians"] .gdn-toolbar-title i{color:#2563eb;font-size:12px}
body[data-page="guardians"] .gdn-pill{
    display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:21px;
    padding:0 8px;border-radius:999px;background:#eff6ff;color:#1d4ed8;
    font-size:11px;font-weight:800;font-variant-numeric:tabular-nums;
}
body[data-page="guardians"] .gdn-toolbar .search-wrap{
    position:relative;flex:1 1 300px;min-width:210px;max-width:380px;margin-left:auto;
}
body[data-page="guardians"] .gdn-toolbar .search-wrap i{
    position:absolute;left:12px;top:50%;transform:translateY(-50%);
    color:#94a3b8;font-size:13px;pointer-events:none;
}
body[data-page="guardians"] .gdn-toolbar .search-wrap input{
    width:100%;height:38px;padding:0 12px 0 34px;box-sizing:border-box;
    border:1px solid #cbd5e1;border-radius:9px;background:#f8faff;
    font:13px Inter,sans-serif;color:#1e293b;outline:none;transition:all .18s ease;
}
body[data-page="guardians"] .gdn-toolbar .search-wrap input:focus{
    border-color:#2563eb;background:#fff;box-shadow:0 0 0 4px rgba(37,99,235,.1);
}

/* ── Table ─────────────────────────────────────── */
body[data-page="guardians"] .ct-table{min-width:900px;table-layout:fixed}
body[data-page="guardians"] .ct-table th{
    position:sticky;top:0;z-index:3;padding:11px 16px;background:#f8fafc;
    border-bottom:1px solid #e2e8f0;color:#475569;
    font-size:10px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;
}
/* Colour-coded column headers: the wayfinding cue for the whole table. */
body[data-page="guardians"] .ct-table th .gdn-h{display:inline-flex;align-items:center;gap:6px}
body[data-page="guardians"] .ct-table th .gdn-h i{font-size:10px}
body[data-page="guardians"] .ct-table th:nth-child(2) .gdn-h{color:#1d4ed8}
body[data-page="guardians"] .ct-table th:nth-child(2) .gdn-h i{color:#2563eb}
body[data-page="guardians"] .ct-table th:nth-child(3) .gdn-h{color:#be123c}
body[data-page="guardians"] .ct-table th:nth-child(3) .gdn-h i{color:#e11d48}
body[data-page="guardians"] .ct-table th:nth-child(4) .gdn-h{color:#6d28d9}
body[data-page="guardians"] .ct-table th:nth-child(4) .gdn-h i{color:#7c3aed}
body[data-page="guardians"] .ct-table th:last-child .gdn-h{color:#64748b}
body[data-page="guardians"] .ct-table th:last-child .gdn-h i{color:#94a3b8}

body[data-page="guardians"] .ct-table td{
    padding:11px 16px;border-top:1px solid #f1f5f9;color:#334155;
    font-size:12.5px;vertical-align:top;
}
body[data-page="guardians"] .ct-table td:last-child{vertical-align:middle;text-align:center}
body[data-page="guardians"] .ct-table tbody tr{transition:background .14s ease}
body[data-page="guardians"] .ct-table tbody tr:hover{background:#f8fbff}
body[data-page="guardians"] .ct-table thead th:nth-child(1){width:21%}
body[data-page="guardians"] .ct-table thead th:nth-child(2){width:28%}
body[data-page="guardians"] .ct-table thead th:nth-child(3){width:20%}
body[data-page="guardians"] .ct-table thead th:nth-child(4){width:22%}
body[data-page="guardians"] .ct-table thead th:nth-child(5){width:9%;text-align:center}
body[data-page="guardians"] .ct-avatar{
    width:34px;height:34px;border-radius:11px;font-size:12.5px;
    background:linear-gradient(140deg,#2563eb,#1d4ed8);
}
body[data-page="guardians"] .ct-avatar.none{background:#f1f5f9;color:#94a3b8}
body[data-page="guardians"] .ct-name{font-size:13.5px;font-weight:700;color:#0f172a}
body[data-page="guardians"] .ct-rel{
    margin-top:1px;font-family:'JetBrains Mono',ui-monospace,monospace;
    font-size:11px;color:#64748b;
}

body[data-page="guardians"] .ct-contact{padding:6px 0;gap:9px}
body[data-page="guardians"] .ct-contact + .ct-contact{border-top:1px solid #f1f5f9}
/* Tinted icon chips carry the column colour into the rows. */
body[data-page="guardians"] .ct-contact > i{
    display:grid;place-items:center;width:20px;height:20px;flex:0 0 20px;
    border-radius:6px;font-size:9.5px;margin-top:1px;
}
body[data-page="guardians"] td:nth-child(2) .ct-contact > i{background:#dbeafe;color:#1d4ed8}
body[data-page="guardians"] td:nth-child(3) .ct-contact > i{background:#ffe4e6;color:#be123c}
body[data-page="guardians"] td:nth-child(4) .ct-contact > i{background:#ede9fe;color:#6d28d9}
body[data-page="guardians"] .ct-contact-name{font-size:12.5px;font-weight:700;color:#0f172a;line-height:1.35}
body[data-page="guardians"] .ct-contact-sub{font-size:11px;color:#64748b;margin-top:2px;gap:9px;line-height:1.5}
body[data-page="guardians"] .ct-contact-sub i{color:#94a3b8;font-size:9.5px}
body[data-page="guardians"] .ct-vdot{background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.18)}

/* Empty states — one consistent chip, tinted per column, so a blank
   cell reads as "checked, nothing here" rather than as missing data. */
body[data-page="guardians"] .ct-none{
    display:inline-flex;align-items:center;gap:6px;padding:3px 9px;border-radius:999px;
    font-size:11px;font-style:normal;font-weight:700;line-height:1.5;
}
body[data-page="guardians"] .ct-none i{font-size:9px}
body[data-page="guardians"] td:nth-child(2) .ct-none{background:#fff7ed;color:#b45309}
body[data-page="guardians"] td:nth-child(3) .ct-none{background:#fff1f2;color:#be123c}
body[data-page="guardians"] td:nth-child(4) .ct-none{background:#f8fafc;color:#94a3b8}
body[data-page="guardians"] .ct-tag{font-size:10px;font-weight:700;padding:2px 8px;letter-spacing:.01em}

body[data-page="guardians"] .ct-table .action-group{
    gap:5px;justify-content:center !important;align-items:center;
}
body[data-page="guardians"] .ct-table .action-btn{
    width:30px;height:30px;border-radius:9px;border:1px solid #e2e8f0;
    background:#fff;color:#475569;transition:all .18s ease;
}
body[data-page="guardians"] .ct-table .action-btn:hover{
    background:#2563eb;border-color:#2563eb;color:#fff;
    box-shadow:0 5px 14px rgba(37,99,235,.28);transform:translateY(-1px);
}
body[data-page="guardians"] .gdn-more{
    margin-top:6px;padding:3px 9px;border:1px dashed #cbd5e1;border-radius:999px;
    background:#fff;color:#2563eb;font-size:10.5px;font-weight:700;
}
body[data-page="guardians"] .gdn-more:hover{background:#eff6ff;border-color:#93c5fd}

body[data-page="guardians"] .table-footer{padding:11px 18px;background:#f8faff;border-top:1px solid #e2e8f0}
body[data-page="guardians"] .table-footer .info-text{font-size:12px;color:#64748b}
body[data-page="guardians"] .table-footer .info-text strong{color:#0f172a;font-variant-numeric:tabular-nums}
/* ── Manage Contacts modal ─────────────────────── */
/* Pinned blue header, independently scrolling body, pinned footer —
   the same shell as the Documents / RFID modals. */
body[data-page="guardians"] #manageModal .modal-content.wide{
    box-sizing:border-box;display:flex;flex-direction:column;padding:0;
    max-width:760px;max-height:calc(100vh - 40px);overflow:hidden;
    border:1px solid #dbeafe;border-radius:19px;
    box-shadow:0 26px 64px rgba(15,23,42,.24);
}
body[data-page="guardians"] #manageModal .modal-header.mg-header{
    flex:0 0 auto;display:flex;align-items:center;gap:12px;margin:0;padding:18px 22px;
    background:linear-gradient(120deg,#eff6ff,#fff 70%);
    border-bottom:1px solid #dbeafe;border-radius:0;
}
body[data-page="guardians"] #manageModal .modal-header.mg-header h2{
    display:flex;align-items:center;gap:10px;margin:0;
    font-size:16px;font-weight:700;letter-spacing:-.02em;color:#172554;
}
body[data-page="guardians"] #manageModal .modal-header.mg-header h2 i{
    display:grid;place-items:center;width:34px;height:34px;flex:0 0 34px;
    border-radius:10px;background:linear-gradient(140deg,#2563eb,#1d4ed8);
    color:#fff;font-size:14px;box-shadow:0 6px 16px rgba(37,99,235,.26);
}
body[data-page="guardians"] #manageModal .modal-close{
    display:grid;place-items:center;width:32px;height:32px;margin-left:auto;
    border:1px solid #dbeafe;border-radius:9px;background:#fff;color:#64748b;
}
body[data-page="guardians"] #manageModal .modal-close:hover{background:#f1f5f9;color:#0f172a}
body[data-page="guardians"] #manageModal .modal-body{
    flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;
    margin:0;padding:20px 22px;background:#fff;
}
body[data-page="guardians"] #manageModal .modal-footer{
    flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:9px;
    margin:0;padding:14px 22px;background:#f8faff;border-top:1px solid #e2e8f0;
}

body[data-page="guardians"] .mg-tabs{
    display:flex;gap:5px;flex-wrap:wrap;margin:0 0 16px;padding:0 0 10px;
    border-bottom:1px solid #e2e8f0;
}
body[data-page="guardians"] .mg-tab{
    display:inline-flex;align-items:center;gap:8px;padding:9px 13px;
    border:1px solid transparent;background:transparent;cursor:pointer;
    border-radius:10px;font-family:inherit;font-size:12.5px;font-weight:700;
    color:#64748b;transition:all .16s ease;
}
body[data-page="guardians"] .mg-tab:hover{color:#1d4ed8;background:#f8fafc}
body[data-page="guardians"] .mg-tab.active{
    color:#1d4ed8;background:#eff6ff;border-color:#dbeafe;
    box-shadow:0 3px 10px rgba(37,99,235,.12);
}
body[data-page="guardians"] .mg-count{background:#e0e7ff;color:#4338ca}
body[data-page="guardians"] .mg-tab.active .mg-count{background:#2563eb;color:#fff}
body[data-page="guardians"] .mg-pane-toolbar{margin-bottom:12px}
body[data-page="guardians"] .mg-hint{font-size:12px;color:#64748b}

body[data-page="guardians"] .mg-empty{padding:14px 2px;color:#94a3b8;font-size:12.5px;font-style:italic}
body[data-page="guardians"] .mg-row{
    padding:12px 14px;margin-bottom:9px;background:#f8fafc;
    border:1px solid #e5e7eb;border-radius:12px;
}
body[data-page="guardians"] .mg-row:hover{border-color:#c7d7fe;background:#fff}
body[data-page="guardians"] .mg-toggle span{
    background:#f1f5f9;border-color:#e2e8f0;color:#64748b;padding:6px 11px;font-size:11px;
}
body[data-page="guardians"] .mg-toggle input:checked + span{
    background:linear-gradient(135deg,#2563eb,#1d4ed8) !important;
    border-color:transparent !important;
    color:#fff !important;
    box-shadow:0 4px 11px rgba(37,99,235,.3);
}
body[data-page="guardians"] .mg-add{
    width:100%;justify-content:center;margin-top:4px;padding:10px;
    border:1.5px dashed #cbd5e1;background:#fff;color:#2563eb;
}
body[data-page="guardians"] .mg-add:hover{border-color:#93c5fd;background:#eff6ff}

@media (max-width:1200px){
    body[data-page="guardians"] .gdn-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
    /* The panel is one border, so reflowing it means re-drawing the
       dividers — drop the trailing one in each row, add the row rule. */
    body[data-page="guardians"] .gdn-stat:nth-child(2n){border-right:0}
    body[data-page="guardians"] .gdn-stat:nth-child(-n+2){border-bottom:1px solid #e2e8f0}
}
@media (max-width:640px){
    body[data-page="guardians"] .gdn-stats{grid-template-columns:1fr}
    body[data-page="guardians"] .gdn-stat{border-right:0}
    body[data-page="guardians"] .gdn-stat + .gdn-stat{border-bottom:1px solid #e2e8f0}
    body[data-page="guardians"] .header{padding:21px 18px}
    body[data-page="guardians"] .gdn-toolbar .search-wrap{max-width:none;margin-left:0}
    body[data-page="guardians"] .mg-row-grid,
    body[data-page="guardians"] .mg-row-sub,
    body[data-page="guardians"] .mg-row-grid-e{grid-template-columns:1fr}
}
@media (prefers-reduced-motion:reduce){
    body[data-page="guardians"] .gdn-stat{transition:none}
}
</style>

<script>
// ─── DATA INJECTED FROM PHP ─────────────────────────────────
const ALL_STUDENTS = <?= json_encode(array_values(array_map(fn($s) => ['id'=>$s['id'],'label'=>$s['student_number'].' — '.$s['name']], $students))) ?>;

// ─── MODAL + SEARCH ─────────────────────────────────────────
function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow=''; }
function openModal(id) { document.getElementById(id).classList.add('active'); document.body.style.overflow='hidden'; }
document.getElementById('manageModal').addEventListener('click', e => { if (e.target === document.getElementById('manageModal')) closeModal('manageModal'); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal('manageModal'); });

const guardRows = Array.from(document.querySelectorAll('#guardBody tr[data-search]'));
document.getElementById('guardSearch').addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    let shown = 0;
    guardRows.forEach(r => {
        const ok = !q || r.dataset.search.includes(q);
        r.style.display = ok ? '' : 'none';
        if (ok) shown++;
    });
    document.getElementById('shownCount').textContent = shown;
});
// ─── TABLE: collapse long contact lists ("+N more") ─────────
const GDN_CARD_SEL = '.ct-contact';
const GDN_SHOWN = 2;   // cards visible before collapsing

function gdnCollapseCell(cell) {
    // Reset any previous collapse first (idempotent re-runs)
    cell.querySelectorAll('.gdn-more').forEach(b => b.remove());
    cell.querySelectorAll(GDN_CARD_SEL).forEach(c => c.style.display = '');

    const cards = Array.from(cell.querySelectorAll(GDN_CARD_SEL));
    if (cards.length <= GDN_SHOWN) return;

    cards.slice(GDN_SHOWN).forEach(c => c.style.display = 'none');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'gdn-more';
    btn.textContent = '+' + (cards.length - GDN_SHOWN) + ' more';
    btn.addEventListener('click', () => {
        const hidden = cards.slice(GDN_SHOWN).every(c => c.style.display === 'none');
        cards.slice(GDN_SHOWN).forEach(c => c.style.display = hidden ? '' : 'none');
        btn.textContent = hidden ? 'Show less' : '+' + (cards.length - GDN_SHOWN) + ' more';
    });
    cell.appendChild(btn);
}

function gdnCollapseAll() {
    document.querySelectorAll('#guardBody tr').forEach(tr => {
        // Columns 2–4: Guardians, Emergency, Email (0-based index 1..3)
        [1, 2, 3].forEach(i => {
            const cell = tr.cells[i];
            if (cell) gdnCollapseCell(cell);
        });
    });
}
gdnCollapseAll();

// ─── HELPERS ────────────────────────────────────────────────
function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function relOptions(sel){
    const rels=['father','mother','guardian','spouse','sibling'];
    return '<option value="">Relationship</option>'+rels.map(r=>'<option value="'+r+'"'+(r===sel?' selected':'')+'>'+r.charAt(0).toUpperCase()+r.slice(1)+'</option>').join('');
}
function refreshCounts() {
    const set = (id, n) => { const el = document.getElementById(id); if (el) el.textContent = n; };
    set('mgGCount', document.querySelectorAll('#mgGuardians .g-row').length);
    set('mgECount', document.querySelectorAll('#mgEmergency .e-row').length);
    set('mgCCount', document.querySelectorAll('#mgEmail .c-row').length);
}
function switchTab(tabId, btn) {
    document.querySelectorAll('.mg-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.mg-tabpane').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(tabId).classList.add('active');
}

// ─── OPEN MANAGE ────────────────────────────────────────────
let currentStudentId = null;
// Always called with a student id (the list row's pencil button). The
// no-argument branch and the student picker it fed are gone with the
// header button; a missing id now fails loudly rather than opening an
// empty modal that cannot save anything.
function openManage(id, name) {
    if (!id) {
        showToast('No student selected.', 'warning');
        return;
    }
    currentStudentId = id;
    document.getElementById('mgTitle').textContent = (name || 'Student') + ' — Manage Contacts';
    document.getElementById('mgStudentId').value = id;
    loadStudentContacts(id);
    openModal('manageModal');
}

function loadStudentContacts(id) {
    loadGuardians(id);
    loadEmergency(id);
    loadEmail(id);
}
function loadGuardians(id) {
    // Fetch live — GUARD_DATA is a page-load snapshot and goes stale the
    // moment anyone edits guardians (this modal, the portal, enrollment).
    fetch('../api/students.php?action=guardians&student_id=' + id)
    .then(r => r.json()).then(d => {
        const list = (d.success && d.data) ? d.data : [];
        document.getElementById('mgGuardians').innerHTML = list.length
            ? list.map(g => guardianRow(g)).join('')
            : '<div id="mgG-empty" class="mg-empty">No guardians recorded.</div>';
        refreshCounts();
    }).catch(() => {
        document.getElementById('mgGuardians').innerHTML = '<div style="color:#dc2626;font-size:12px;padding:6px 0;">Error loading guardians.</div>';
        refreshCounts();
    });
}
function loadEmergency(id) {
    fetch('../api/students.php?action=emergency&student_id=' + id)
    .then(r => r.json()).then(d => {
        const list = (d.success && d.data) ? d.data : [];
        document.getElementById('mgEmergency').innerHTML = list.length
            ? list.map(e => emergencyRow(e)).join('')
            : '<div id="mgE-empty" class="mg-empty">No emergency contacts.</div>';
        refreshCounts();
    }).catch(() => {
        document.getElementById('mgEmergency').innerHTML = '<div style="color:#dc2626;font-size:12px;padding:6px 0;">Error loading contacts.</div>';
        refreshCounts();
    });
}
function loadEmail(id) {
    // Fetch live — CONTACT_DATA is a page-load snapshot and goes stale the
    // moment anyone edits a recipient (this modal, the portal, or the API),
    // which can leave the wrong recipients showing until a full reload.
    fetch('../api/contacts.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action:'list', student_id: id }) })
    .then(r => r.json()).then(d => {
        const list = (d.success && d.data && d.data.contacts) ? d.data.contacts : [];
        document.getElementById('mgEmail').innerHTML = list.length
            ? list.map(c => emailRow(c)).join('')
            : '<div id="mgC-empty" class="mg-empty">No email recipients on file.</div>';
        refreshCounts();
    }).catch(() => {
        document.getElementById('mgEmail').innerHTML = '<div style="color:#dc2626;font-size:12px;padding:6px 0;">Error loading recipients.</div>';
        refreshCounts();
    });
}

// ─── ROW BUILDERS ───────────────────────────────────────────
let gSeq=1000, eSeq=1000, cSeq=2000;
function guardianRow(g) {
    gSeq++;
    const gid = (g.id || 0);
    return '<div class="g-row mg-row" data-gid="' + gid + '" data-key="' + gSeq + '">'
        + '<div class="mg-row-grid">'
        + '<input class="form-control gi-name" placeholder="Full name *" value="' + esc(g.full_name) + '">'
        + '<select class="form-control gi-rel">' + relOptions(g.relationship) + '</select>'
        + '<input class="form-control gi-contact" placeholder="Contact no. *" value="' + esc(g.contact_number) + '" required pattern="09[0-9]{9}" title="11-digit mobile number (e.g. 09171234567)"></div>'
        + '<div class="mg-row-sub">'
        + '<input class="form-control gi-email" placeholder="Email" value="' + esc(g.email) + '">'
        + '<div class="mg-toggles">'
        + '<label class="mg-toggle"><input type="checkbox" class="gi-primary" ' + (g.is_primary?'checked':'') + '><span><i class="fa-solid fa-star"></i> Primary</span></label>'
        + '<label class="mg-toggle"><input type="checkbox" class="gi-emergency" ' + (g.is_emergency?'checked':'') + '><span><i class="fa-solid fa-bell"></i> Emergency</span></label>'
        + '</div></div></div>';
}
function emergencyRow(e) {
    eSeq++;
    const eid = (e.id || 0);
    return '<div class="e-row mg-row" data-eid="' + eid + '" data-ekey="' + eSeq + '">'
        + '<div class="mg-row-grid mg-row-grid-e">'
        + '<input class="form-control ei-name" placeholder="Full name *" value="' + esc(e.full_name) + '">'
        + '<input class="form-control ei-rel" placeholder="Relationship" value="' + esc(e.relationship) + '">'
        + '<input class="form-control ei-contact" placeholder="Contact no. *" value="' + esc(e.contact_number) + '" required pattern="09[0-9]{9}" title="11-digit mobile number (e.g. 09171234567)">'
        + '</div></div>';
}
function emailRow(c) {
    cSeq++;
    const cid = (c.id || 0);
    const verified = Number(c.verified) === 1;
    const badge = verified
        ? '<span class="mg-verified"><i class="fa-solid fa-check-circle"></i> Verified</span>'
        : '<span class="mg-unverified"><i class="fa-solid fa-clock"></i> Awaiting</span>';
    return '<div class="c-row mg-row" data-cid="' + cid + '" data-ckey="' + cSeq + '">'
        + '<div class="mg-row-grid">'
        + '<input class="form-control ci-name" placeholder="Full name *" value="' + esc(c.full_name) + '">'
        + '<input class="form-control ci-email" placeholder="Email *" value="' + esc(c.email) + '">'
        + '<input class="form-control ci-phone" placeholder="Phone *" value="' + esc(c.phone) + '" required pattern="09[0-9]{9}" title="11-digit mobile number (e.g. 09171234567)"></div>'
        + '<div class="mg-row-sub">'
        + '<div class="mg-toggles">'
        + '<label class="mg-toggle"><input type="checkbox" class="ci-billing" ' + (Number(c.send_billing)===1?'checked':'') + '><span><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</span></label>'
        + '<label class="mg-toggle"><input type="checkbox" class="ci-grades" ' + (Number(c.send_grades)===1?'checked':'') + '><span><i class="fa-solid fa-chart-simple"></i> Grades</span></label>'
        + '<label class="mg-toggle"><input type="checkbox" class="ci-emg" ' + (Number(c.send_emergency)===1?'checked':'') + '><span><i class="fa-solid fa-triangle-exclamation"></i> Alerts</span></label>'
        + '</div>'
        + '<div class="c-actions">'
        + badge
        + (verified
            ? '<button type="button" class="btn btn-light btn-sm" onclick="sendContactAction(this,\'snapshot\')" title="Email grade snapshot"><i class="fa-solid fa-chart-simple"></i></button>'
              + '<button type="button" class="btn btn-light btn-sm" onclick="sendContactAction(this,\'transcript\')" title="Email transcript"><i class="fa-solid fa-file-lines"></i></button>'
            : '')
        + '<button type="button" class="btn btn-light btn-sm" onclick="sendContactAction(this,\'test\')" title="Send Test Email"><i class="fa-solid fa-envelope-circle-check"></i></button>'
        + '</div></div></div>';
}

// ─── ADD / REMOVE ROWS ──────────────────────────────────────
// NOTE: Delete/remove of guardians, emergency contacts, and email
// recipients is intentionally NOT available in this UI — it is reserved
// for the college system. Staff can add and edit, but cannot remove a
// contact record. (Backend delete endpoints remain for admin use.)

function addGuardianRow() {
    const empty = document.getElementById('mgG-empty'); if (empty) empty.remove();
    document.getElementById('mgGuardians').insertAdjacentHTML('beforeend', guardianRow({}));
    refreshCounts();
}
function addEmergencyRow() {
    const empty = document.getElementById('mgE-empty'); if (empty) empty.remove();
    document.getElementById('mgEmergency').insertAdjacentHTML('beforeend', emergencyRow({}));
    refreshCounts();
}
function addEmailRow() {
    const empty = document.getElementById('mgC-empty'); if (empty) empty.remove();
    document.getElementById('mgEmail').insertAdjacentHTML('beforeend', emailRow({}));
    refreshCounts();
}

// ─── AUTO-FILL FROM ENROLLMENT ──────────────────────────────
async function pullEnrollment() {
    if (!currentStudentId) { showToast('Select a student first.', 'warning'); return; }
    const d = await fetch('../api/contacts.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action:'pull_enrollment', student_id: currentStudentId }) }).then(r => r.json());
    showToast(d.message, d.success ? 'success' : 'error');
    if (!d.success) return;
    // Refetch the authoritative list — GUARD_DATA is a page-load snapshot and
    // does not include the rows just created server-side.
    const g = await fetch('../api/students.php?action=guardians&student_id=' + currentStudentId).then(r => r.json());
    document.getElementById('mgGuardians').innerHTML = (g.success && g.data && g.data.length)
        ? g.data.map(x => guardianRow(x)).join('')
        : '<div id="mgG-empty" class="mg-empty">No guardians recorded.</div>';
    refreshCounts();
}

// ─── PER-ROW EMAIL ACTIONS (Test / Snapshot / Transcript) ───
async function sendContactAction(btn, kind) {
    const row = btn.closest('.c-row');
    const id = parseInt(row ? row.dataset.cid : '0', 10) || 0;
    if (!id) { showToast('Save the contact before sending email.', 'error'); return; }
    const orig = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    const payload = { action: kind === 'test' ? 'test_email' : 'email_contact', id: id, student_id: currentStudentId };
    if (kind !== 'test') payload.kind = kind;
    try {
        const d = await fetch('../api/contacts.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) }).then(r => r.json());
        showToast(d.message, d.success ? 'success' : 'error');
    } catch (err) { showToast('Network error. Please try again.', 'error'); }
    btn.disabled = false; btn.innerHTML = orig;
}

// ─── SAVE ALL ───────────────────────────────────────────────
function ph11(v) {
    let d = String(v || '').replace(/\D/g, '');
    if (d.length === 12 && d.startsWith('63')) d = '0' + d.slice(2);
    if (d.length === 13 && d.startsWith('63')) d = '0' + d.slice(2);
    return /^09\d{9}$/.test(d);
}

async function saveAll() {
    if (!currentStudentId) { showToast('Select a student first.', 'warning'); return; }
    const btn = document.querySelector('#manageModal .modal-footer .btn-primary');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    const api = (url, payload) => fetch(url, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) }).then(r => r.json());
    const seqStudents = async (action, payload) => { const d = await api('../api/students.php?action=' + action, payload); if (!d.success) throw new Error(d.message || 'Save failed'); return d; };
    const seqContacts = async (payload) => { const d = await api('../api/contacts.php', payload); if (!d.success) throw new Error(d.message || 'Save failed'); return d; };

    try {
        // Deletion is intentionally not available in this UI (reserved for
        // the college system) — save new/updated rows only.

        // NOW save guardians (only process rows that have at least a name)
        const gRows = document.querySelectorAll('.g-row');
        for (const row of gRows) {
            const fullName = row.querySelector('.gi-name').value.trim();
            if (!fullName) continue; // Skip empty rows

            const contactNumber = row.querySelector('.gi-contact').value;
            if (!ph11(contactNumber)) {
                throw new Error('Guardian contact number is required and must be an 11-digit mobile number (e.g. 09171234567).');
            }

            const res = await seqStudents('save-guardian', {
                id: parseInt(row.dataset.gid || '0', 10) || 0,
                student_id: currentStudentId,
                full_name: fullName,
                relationship: row.querySelector('.gi-rel').value,
                contact_number: contactNumber,
                email: row.querySelector('.gi-email').value,
                is_primary: row.querySelector('.gi-primary').checked ? 1 : 0,
                is_emergency: row.querySelector('.gi-emergency').checked ? 1 : 0
            });
            // Remember the real DB id so a re-save updates instead of cloning.
            if (res && res.data && res.data.id) row.dataset.gid = res.data.id;
        }

        // NOW save emergency contacts (only process rows that have at least a name)
        const eRows = document.querySelectorAll('.e-row');
        for (const row of eRows) {
            const fullName = row.querySelector('.ei-name').value.trim();
            if (!fullName) continue; // Skip empty rows

            const contactNumber = row.querySelector('.ei-contact').value;
            if (!ph11(contactNumber)) {
                throw new Error('Emergency contact number is required and must be an 11-digit mobile number (e.g. 09171234567).');
            }

            await seqStudents('save-emergency', {
                id: parseInt(row.dataset.eid || '0', 10) || 0,
                student_id: currentStudentId,
                full_name: fullName,
                relationship: row.querySelector('.ei-rel').value,
                contact_number: contactNumber
            });
        }

        // NOW save email recipients (only process rows that have at least name and email)
        const cRows = document.querySelectorAll('.c-row');
        for (const row of cRows) {
            const fullName = row.querySelector('.ci-name').value.trim();
            const email = row.querySelector('.ci-email').value.trim();
            if (!fullName || !email) continue; // Skip incomplete rows

            const phone = row.querySelector('.ci-phone').value;
            if (!ph11(phone)) {
                throw new Error('Phone is required and must be an 11-digit mobile number (e.g. 09171234567).');
            }

            await seqContacts({
                action: 'save',
                id: parseInt(row.dataset.cid || '0', 10) || 0,
                student_id: currentStudentId,
                full_name: fullName,
                email: email,
                phone: phone,
                send_billing: row.querySelector('.ci-billing').checked ? 1 : 0,
                send_grades: row.querySelector('.ci-grades').checked ? 1 : 0,
                send_emergency: row.querySelector('.ci-emg').checked ? 1 : 0
            });
        }

        showToast('Contacts updated.', 'success');
        setTimeout(() => window.location.reload(), 700);
    } catch (err) {
        showToast(err.message || 'Error saving contacts.', 'error');
        // Reconcile to server truth: any earlier saves in this run may
        // have partially applied. A reload rebuilds the lists from the
        // authoritative data, so a removed row that wasn't deleted won't
        // silently vanish, and a saved row gets its real id (no clones
        // on the next save).
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Contacts';
        setTimeout(() => window.location.reload(), 1200);
    }
}

</script>

<?php include '../includes/footer.php'; ?>
