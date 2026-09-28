<?php
// ============================================================
//  REGISTRAR/STUDENTS.PHP
//  Student management — inline view/edit, bulk actions, RFID
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/normalize.php';

$db = Database::getInstance();

// Fetch students
$students = $db->fetchAll("SELECT * FROM students ORDER BY id DESC");

// Stats with real MoM trends
$totalStudents = count($students);
$activeStudents = count(array_filter($students, fn($s) => $s['status'] === 'active'));
$atRiskStudents = count(array_filter($students, fn($s) => $s['status'] === 'at-risk' || $s['status'] === 'probation'));
$graduatedStudents = count(array_filter($students, fn($s) => $s['status'] === 'graduated'));
// Per-year-level counts for the Year 1-4 cards.
$yearLevelCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
$unassignedStudents = 0;
foreach ($students as $s) {
    $yl = (int) ($s['year_level'] ?? 0);
    if (isset($yearLevelCounts[$yl])) { $yearLevelCounts[$yl]++; }
    else { $unassignedStudents++; }
}

// Cohort ribbon: one proportional stacked bar instead of four
// same-weight year cards. Percentages are of the total, so the
// segments read as parts of a whole rather than four tallies.
$ribbonSegments = [];
foreach ([1, 2, 3, 4] as $yl) {
    $ribbonSegments[] = [
        'key'  => 'y' . $yl,
        'year' => $yl,
        'tone' => ['y1', 'y2', 'y3', 'y4'][$yl - 1],
        'count' => $yearLevelCounts[$yl],
    ];
}
if ($unassignedStudents > 0) {
    $ribbonSegments[] = ['key' => 'unassigned', 'year' => null, 'tone' => 'unassigned', 'count' => $unassignedStudents];
}
$ribbonBase = max(1, $totalStudents); // never divide by zero on an empty list

$thisMonth = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')") ?: 0;
$lastMonth = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH) AND created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')") ?: 0;
$trendTotal = $lastMonth > 0 ? round(($thisMonth - $lastMonth) / $lastMonth * 100) : ($thisMonth > 0 ? 100 : 0);

// Courses for filter
$courses = $db->fetchAll("SELECT DISTINCT course FROM students WHERE course IS NOT NULL AND course != '' ORDER BY course");

// ─── OFFERED COURSES & MAJORS ────────────────────────────────
// Single source of truth (shared with the Masterlist via shared/functions.php).
$offeredCourses = getOfferedCourses();

// Advisers for the adviser dropdown (active staff accounts)
$advisers = $db->fetchAll("SELECT id, full_name FROM users WHERE role = 'staff' AND is_active = 1 ORDER BY full_name");

// RFID cards lookup for indicator
$rfidCards = $db->fetchAll("SELECT student_id, card_uid, status FROM rfid_cards");
$rfidMap = [];
foreach ($rfidCards as $rc) {
    if ($rc['student_id']) $rfidMap[$rc['student_id']] = $rc;
}

$page_title = 'Students';
$APP_ROOT = '../';
$ACTIVE_NAV = 'students';
$body_page = 'students';   // scopes the registrar-blue layer below
include '../includes/header.php';
include '../includes/sidebar.php';
?><style>
:root { --sidebar-width:260px; --sidebar-collapsed-width:72px; }
.dashboard-main { margin-left:var(--sidebar-width); padding:24px 32px; min-height:100vh; width:calc(100% - var(--sidebar-width)); max-width:calc(100% - var(--sidebar-width)); overflow-x:hidden; transition:margin-left .3s,width .3s,max-width .3s; }
.sidebar.collapsed~.dashboard-main,body.sidebar-collapsed .dashboard-main { margin-left:var(--sidebar-collapsed-width); width:calc(100% - var(--sidebar-collapsed-width)); max-width:calc(100% - var(--sidebar-collapsed-width)); }

/* ============================================================
   STUDENT RECORDS — registrar-blue layer.
   Same header, same connected metric strip, same toolbar band
   as ai/insights.php and registrar/masterlist.php, so the three
   read as one product. Scoped to body[data-page="students"].

   The year-level figures become one proportional ribbon rather
   than four same-weight cards: year level is ordinal, so it gets
   a one-hue sequential ramp and segment widths that mean share.
   The icon tiles are gone — they carried colour but no
   information, and the strip now reads as one unit instead.
   ============================================================ */
body[data-page="students"]{background:#f5f7fb;color:#0f172a}
body[data-page="students"] .main{background:linear-gradient(180deg,#eef4ff 0,#f8faff 300px,#f8faff 100%)}

/* ── Page header ───────────────────────────────────────────
   Masthead only — every action moved to the action bar below,
   so this is a single left-aligned block, not a flex row. */
body[data-page="students"] .header{
    display:block;margin:0 0 16px;padding:25px 27px;
    border:1px solid #c7d7fe;border-radius:19px;
    background:linear-gradient(120deg,#eff6ff,#fff 68%);
    box-shadow:0 10px 30px rgba(37,99,235,.08);
}
body[data-page="students"] .header .title h1{
    margin:0 0 5px;font-size:28px;line-height:1.1;letter-spacing:-.03em;color:#172554;
}
body[data-page="students"] .header .title p{margin:0;max-width:620px;font-size:12.5px;line-height:1.5;color:#64748b}
body[data-page="students"] .stu-kicker{
    display:flex;align-items:center;gap:7px;margin-bottom:7px;color:#1d4ed8;
    font-size:10.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;
}
/* ── Action bar ───────────────────────────────────────────
   Four header buttons split into two kinds of work, so they
   are grouped rather than lined up: intake creates records,
   tools operate on the student list you are already looking at.
   Same grouped pattern as registrar/masterlist.php. */
body[data-page="students"] .stu-actionbar{
    display:flex;align-items:stretch;gap:12px;flex-wrap:wrap;
    margin:0 0 16px;padding:12px 14px;border:1px solid #dbeafe;border-radius:16px;
    background:#fff;box-shadow:0 7px 24px rgba(15,23,42,.04);
}
body[data-page="students"] .stu-action-group{
    flex:1 1 300px;min-width:0;display:flex;flex-direction:column;gap:8px;
    padding:11px 13px;border:1px solid #e2e8f0;border-radius:13px;background:#f8faff;
}
body[data-page="students"] .stu-action-label{
    padding-left:2px;color:#1d4ed8;font-size:9.5px;font-weight:800;
    letter-spacing:.09em;text-transform:uppercase;
}
body[data-page="students"] .stu-action-buttons{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
body[data-page="students"] .stu-action-buttons .btn{min-height:34px;padding:0 12px;font-size:12px}
body[data-page="students"] .stu-action-buttons .export-wrap{display:flex}
/* The dropdown is anchored to this group, not the viewport, so it
   cannot open off-screen when the bar wraps on narrow screens. */
body[data-page="students"] .stu-action-buttons .export-wrap{position:relative}
body[data-page="students"] .stu-action-buttons .export-menu{right:0;left:auto}

/* ── Connected metric strip ───────────────────────────────
   One unit, hairline-separated cells, no gaps between them.
   At-risk / graduated / intake are rendered here for the first
   time — lines 22-34 already queried them but nothing displayed
   the result. */
body[data-page="students"] .stu-strip{
    display:grid;grid-template-columns:repeat(4,minmax(0,1fr));
    margin:0 0 16px;background:#fff;border:1px solid #dbeafe;border-radius:16px;
    box-shadow:0 6px 22px rgba(15,23,42,.04);overflow:hidden;
}
body[data-page="students"] .stu-metric{
    position:relative;padding:16px 20px 15px;border-right:1px solid #e2e8f0;
}
body[data-page="students"] .stu-metric:last-child{border-right:0}
body[data-page="students"] .stu-metric::after{
    content:"";position:absolute;left:20px;right:20px;bottom:0;height:3px;background:#dbeafe;
}
body[data-page="students"] .stu-metric.tone-blue::after{background:#1d4ed8}
body[data-page="students"] .stu-metric.tone-green::after{background:#16a34a}
body[data-page="students"] .stu-metric.tone-amber::after{background:#d97706}
body[data-page="students"] .stu-metric.tone-violet::after{background:#7c3aed}
body[data-page="students"] .stu-metric .stu-metric-label{
    font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;
    color:#64748b;line-height:1.3;margin:0 0 5px;
}
body[data-page="students"] .stu-metric .stu-metric-value{
    font-size:27px;font-weight:800;line-height:1.1;color:#0f172a;
    font-variant-numeric:tabular-nums;
}
body[data-page="students"] .stu-metric .stu-metric-foot{
    display:flex;align-items:center;gap:6px;margin-top:9px;padding-top:8px;
    border-top:1px solid #f1f5f9;font-size:11px;color:#64748b;line-height:1.4;
}
body[data-page="students"] .stu-trend{
    display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;
    font-size:10.5px;font-weight:700;line-height:1.5;white-space:nowrap;
}
body[data-page="students"] .stu-trend i{font-size:9px}
body[data-page="students"] .stu-trend.up{color:#15803d;background:#dcfce7}
body[data-page="students"] .stu-trend.down{color:#b91c1c;background:#fee2e2}
body[data-page="students"] .stu-trend.flat{color:#64748b;background:#f1f5f9}
/* Inline meter next to the footer text. display:inline-block is
   required — as a bare <span> the width is ignored and it renders
   as a full-width rule sitting on top of the accent bar. */
body[data-page="students"] .stu-meter{
    display:inline-block;vertical-align:middle;flex:1 1 40px;min-width:34px;max-width:120px;
    height:4px;border-radius:999px;background:#eef2f7;overflow:hidden;
}
body[data-page="students"] .stu-meter > i{display:block;height:100%;border-radius:999px}

/* ── Cohort ribbon ─────────────────────────────────────────
   The one loud element on the page. Four categorical hues
   rather than one sequential ramp: a registrar looks up
   "the Year 3s" as a discrete thing, so distinct hues let a
   segment be named by pointing at it. Order is carried by
   position (Y1→Y4) plus the legend, not by lightness.
   Counts sit on the segment only when it is wide enough to
   hold them; otherwise the legend carries the number. */
body[data-page="students"] .stu-ribbon{
    background:#fff;border:1px solid #dbeafe;border-radius:16px;
    box-shadow:0 6px 22px rgba(15,23,42,.04);margin:0 0 16px;overflow:hidden;
}
body[data-page="students"] .stu-ribbon-head{
    display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap;
    padding:14px 20px 12px;border-bottom:1px solid #e2e8f0;background:#f8faff;
}
body[data-page="students"] .stu-ribbon-title{
    display:flex;align-items:center;gap:9px;margin:0;font-size:14px;font-weight:800;
    color:#0d1b2e;letter-spacing:-.3px;
}
body[data-page="students"] .stu-ribbon-title i{color:#2563eb;font-size:13px}
body[data-page="students"] .stu-ribbon-note{font-size:11.5px;color:#64748b}
body[data-page="students"] .stu-ribbon-bar{
    display:flex;height:34px;margin:16px 20px 0;border-radius:9px;overflow:hidden;background:#f1f5f9;
}
body[data-page="students"] .stu-seg{position:relative;min-width:3px;transition:filter .2s}
/* Adjacent segments sit within ~1.1:1 luminance of each other, so
   hue alone leaves a soft, ambiguous edge. This inset ring is what
   makes the boundaries read. Painted, not a border, so it never
   shifts the segment widths that encode share. */
body[data-page="students"] .stu-seg::after{
    content:"";position:absolute;inset:0;pointer-events:none;
    box-shadow:inset -2px 0 0 rgba(255,255,255,.92);
}
body[data-page="students"] .stu-seg:last-child::after{box-shadow:none}
body[data-page="students"] .stu-seg:hover{filter:brightness(1.08)}
/* Cool analogous set: cyan → blue → indigo → violet. Held to
   the blue family on purpose, so the four are told apart by hue
   (~50° of spread) rather than by lightness. Measured adjacent
   luminance ratios are only ~1.1–1.2:1, so the 2px white rule
   below is what actually guarantees the edges read. Every
   colour clears 4.5:1 against white for the on-segment count.
   Y1 uses sky-700, not sky-600 (#0284c7, only 4.10:1). */
body[data-page="students"] .stu-seg.tone-y1{background:#0369a1}
body[data-page="students"] .stu-seg.tone-y2{background:#2563eb}
body[data-page="students"] .stu-seg.tone-y3{background:#4f46e5}
body[data-page="students"] .stu-seg.tone-y4{background:#7c3aed}
body[data-page="students"] .stu-seg.tone-unassigned{background:repeating-linear-gradient(135deg,#cbd5e1 0 5px,#e2e8f0 5px 10px)}
body[data-page="students"] .stu-seg b{
    position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    font-size:11.5px;font-weight:800;color:#fff;font-variant-numeric:tabular-nums;
    text-shadow:0 1px 2px rgba(15,23,42,.28);pointer-events:none;
}
/* Only the pale hatch needs dark ink; the four year hues all
   carry white labels. */
body[data-page="students"] .stu-seg.tone-unassigned b{color:#12336f;text-shadow:none}
body[data-page="students"] .stu-ribbon-legend{
    display:flex;flex-wrap:wrap;gap:0;margin:0;padding:0 20px;list-style:none;
}
body[data-page="students"] .stu-ribbon-legend li{flex:1 1 118px;min-width:0;padding:12px 14px 14px}
body[data-page="students"] .stu-legend-key{
    display:flex;align-items:center;gap:7px;margin-bottom:5px;
    font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#64748b;
}
body[data-page="students"] .stu-legend-key i{width:9px;height:9px;flex:0 0 9px;border-radius:2px;display:inline-block}
body[data-page="students"] .stu-legend-key i.tone-y1{background:#0369a1}
body[data-page="students"] .stu-legend-key i.tone-y2{background:#2563eb}
body[data-page="students"] .stu-legend-key i.tone-y3{background:#4f46e5}
body[data-page="students"] .stu-legend-key i.tone-y4{background:#7c3aed}
body[data-page="students"] .stu-legend-key i.tone-unassigned{background:#cbd5e1}
body[data-page="students"] .stu-legend-val{
    font-size:19px;font-weight:800;color:#0f172a;line-height:1.1;font-variant-numeric:tabular-nums;
}
body[data-page="students"] .stu-legend-pct{font-size:11.5px;color:#94a3b8;margin-left:5px;font-weight:600}
body[data-page="students"] .stu-ribbon-empty{
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    padding:30px 20px 32px;text-align:center;
}
body[data-page="students"] .stu-ribbon-empty i{font-size:24px;color:#cbd5e1;margin-bottom:9px}
body[data-page="students"] .stu-ribbon-empty p{margin:0 0 4px;font-size:13.5px;font-weight:600;color:#64748b}
body[data-page="students"] .stu-ribbon-empty span{font-size:11.5px;color:#94a3b8;line-height:1.5}

/* Search + Table container */
body[data-page="students"] .search-table-container{
    background:#fff;border:1px solid #dbeafe;border-radius:16px;overflow:hidden;
    box-shadow:0 6px 22px rgba(15,23,42,.04);
}
body[data-page="students"] .search-bar{
    padding:13px 16px;background:#fff;border-bottom:1px solid #e2e8f0;
    display:flex;align-items:center;gap:9px;flex-wrap:wrap;row-gap:10px;
}
.search-bar .search-wrapper{flex:1 1 320px;min-width:240px;max-width:100%;position:relative;display:flex;align-items:center;height:40px}
.search-bar .search-wrapper i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px;pointer-events:none;z-index:2}
.search-bar .search-wrapper input{width:100%;height:40px;padding:0 38px 0 38px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;font-family:inherit;outline:none;background:white;color:#1e293b;box-sizing:border-box}
.search-bar .search-wrapper input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,0.10)}
.search-bar .search-wrapper input::placeholder{color:#94a3b8}
.search-bar .search-wrapper .search-clear{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;width:24px;height:24px;display:none;border-radius:50%;align-items:center;justify-content:center;z-index:2}
.search-bar .search-wrapper .search-clear.visible{display:flex}
.search-bar .search-wrapper .search-clear:hover{background:#f1f5f9;color:#1e293b}
.search-bar .search-actions{display:flex;gap:8px;flex-wrap:nowrap;flex-shrink:0;align-items:center;height:40px}
.search-bar .search-actions .btn{height:40px;padding:0 16px;font-size:13px;display:inline-flex;align-items:center;justify-content:center}

/* Bulk bar */
.bulk-bar{display:none;padding:10px 20px;background:#eef4ff;border-bottom:1px solid #bfdbfe;align-items:center;gap:12px;flex-wrap:wrap}
.bulk-bar.show{display:flex}
.bulk-bar .count{font-size:13px;font-weight:600;color:#1d4ed8}
.bulk-bar .bulk-actions{display:flex;gap:8px;margin-left:auto}

/* Table */
body[data-page="students"] .table-responsive th{
    position:sticky;top:0;z-index:3;background:#f8faff;border-bottom:1px solid #dbeafe;
    color:#64748b;
}
body[data-page="students"] .table-responsive tbody tr:hover{background:#f5f9ff}
body[data-page="students"] .table-footer{background:#fff;border-top:1px solid #e2e8f0}
body[data-page="students"] #emptyState{min-height:320px}
body[data-page="students"] #emptyState i{color:#cbd5e1}
body[data-page="students"] .table-responsive td.num,
body[data-page="students"] .table-responsive th.num{text-align:center;font-variant-numeric:tabular-nums}
body[data-page="students"] .table-responsive td.rownum{color:#94a3b8;font-size:11px;font-variant-numeric:tabular-nums}
body[data-page="students"] .student-id{font-variant-numeric:tabular-nums;letter-spacing:-.1px}
body[data-page="students"] .student-name{font-size:13.5px}
body[data-page="students"] .student-email{font-size:11px;margin-top:1px}
/* A flagged row earns a full-width rail, not just a warning glyph. */
body[data-page="students"] .table-responsive tbody tr.q-flag td:first-child{box-shadow:inset 3px 0 0 #f59e0b}
body[data-page="students"] .q-dot{width:8px;height:8px;display:inline-block;border-radius:50%;vertical-align:middle}
/* Same three thresholds the column header legend documents. */
body[data-page="students"] .q-dot.q-good{background:#22c55e}
body[data-page="students"] .q-dot.q-warn{background:#f59e0b}
body[data-page="students"] .q-dot.q-bad{background:#ef4444}

.table-responsive{overflow-x:auto}
.table-responsive table{width:100%;border-collapse:collapse}
.table-responsive th{text-align:left;padding:10px 10px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#64748b;background:#fafcfd;border-bottom:2px solid #e8edf4;white-space:nowrap}
.table-responsive td{padding:10px 10px;font-size:13px;color:#1e293b;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.table-responsive tbody tr{transition:background .15s ease}
.table-responsive tbody tr:hover{background:#f8fafc}
.table-responsive tbody tr:last-child td{border-bottom:none}
.table-responsive tbody tr.archived{opacity:.5;background:#f8fafc}

/* Checkbox */
.cb-wrap{display:flex;align-items:center;justify-content:center}
.cb-wrap input[type=checkbox]{width:16px;height:16px;cursor:pointer;accent-color:#2563eb}

/* Student info */
.student-info{display:flex;align-items:center;gap:10px}
.student-avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:11px;flex-shrink:0}
.student-avatar.blue{background:linear-gradient(135deg,#2563eb,#1d4ed8)} .student-avatar.green{background:linear-gradient(135deg,#16a34a,#15803d)}
.student-avatar.purple{background:linear-gradient(135deg,#7c3aed,#6d28d9)} .student-avatar.orange{background:linear-gradient(135deg,#b45309,#92400e)}
.student-avatar.pink{background:linear-gradient(135deg,#db2777,#be185d)}
.student-name{font-weight:600;color:#0f172a;font-size:13px}
.student-email{font-size:11px;color:#94a3b8}

/* RFID chip */
.rfid-chip{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:600;text-decoration:none}
.rfid-chip.active{background:#dcfce7;color:#16a34a}
.rfid-chip.none{background:#f1f5f9;color:#94a3b8}

/* Status badges */
.status-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:9999px;font-size:11px;font-weight:600;white-space:nowrap;border:none;cursor:pointer;font-family:inherit}
.status-badge.active{background:#dcfce7;color:#16a34a}
.status-badge.probation{background:#fef3c7;color:#b45309}
.status-badge.at-risk{background:#fee2e2;color:#dc2626}
.status-badge.graduated{background:#dbeafe;color:#2563eb}
.status-badge.loa{background:#f3e8ff;color:#7c3aed}
.status-badge.transferred{background:#fce7f3;color:#db2777}
.status-badge.dropped{background:#fef2f2;color:#dc2626}
.status-badge.archived{background:#f1f5f9;color:#64748b}
.status-dot{width:6px;height:6px;border-radius:50%;display:inline-block}
.status-dot.active{background:#16a34a} .status-dot.probation{background:#b45309} .status-dot.at-risk{background:#dc2626}
.status-dot.graduated{background:#2563eb} .status-dot.loa{background:#7c3aed} .status-dot.transferred{background:#db2777}
.status-dot.dropped{background:#dc2626} .status-dot.archived{background:#94a3b8}

.status-card .status-archived{background:#f1f5f9;color:#64748b}

/* Quick status dropdown */
.quick-status-wrap{position:relative;display:inline-block}
.quick-status-menu{display:none;position:absolute;top:100%;left:0;z-index:50;background:white;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.1);min-width:150px;padding:4px;margin-top:4px}
.quick-status-menu.show{display:block}
.quick-status-menu button{display:block;width:100%;padding:8px 12px;border:none;background:none;font-size:12px;font-weight:600;text-align:left;cursor:pointer;border-radius:6px;font-family:inherit}
.quick-status-menu button:hover{background:#f1f5f9}
.quick-status-menu button.active{background:#eef4ff;color:#2563eb}

/* Action buttons */
.action-group{display:flex;gap:3px;justify-content:center}
.action-btn{width:30px;height:30px;border:none;border-radius:8px;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;font-size:12px;background:transparent;color:#94a3b8}
.action-btn:hover{background:#f1f5f9;color:#1e293b;transform:scale(1.05)}
.action-btn.view{color:#2563eb} .action-btn.view:hover{background:#eef4ff}
.action-btn.edit{color:#b45309} .action-btn.edit:hover{background:#fef3c7}
.action-btn.delete{color:#dc2626} .action-btn.delete:hover{background:#fee2e2}
.action-btn.restore{color:#16a34a} .action-btn.restore:hover{background:#dcfce7}

/* Table footer */
.table-footer{padding:10px 20px;background:#fafcfd;border-top:1px solid #e8edf4;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.table-footer .info-text{font-size:13px;color:#64748b}
.table-footer .info-text strong{color:#0f172a}

/* Data Quality review modal */
#qualityModal .modal-content{max-width:1040px;height:min(760px,88vh);padding:0;overflow:hidden;display:flex;flex-direction:column}
.quality-shell{display:flex;flex:1;min-height:0}
.quality-queue{width:330px;flex:0 0 330px;border-right:1px solid #e5e7eb;background:#f8fafc;display:flex;flex-direction:column;min-height:0}
.quality-queue-head{padding:18px;border-bottom:1px solid #e2e8f0}
.quality-queue-head h3{font-family:Fraunces,serif;font-size:19px;color:#14213d;margin:0 0 3px}.quality-queue-head p{font-size:12px;color:#64748b;margin:0 0 12px}
.quality-search{position:relative}.quality-search i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px}.quality-search input{width:100%;padding:9px 10px 9px 32px;border:1px solid #dbe2ea;border-radius:9px;font-size:13px;background:#fff}
.quality-filters{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.quality-filter{border:1px solid #dbe2ea;background:#fff;color:#64748b;border-radius:999px;padding:5px 10px;font-size:11px;font-weight:600;cursor:pointer}.quality-filter.active{background:#14213d;border-color:#14213d;color:#fff}
.quality-list{overflow:auto;padding:8px;flex:1}.quality-student{width:100%;border:1px solid transparent;background:transparent;border-radius:11px;padding:11px;text-align:left;cursor:pointer;display:grid;grid-template-columns:1fr auto;gap:4px 8px;margin-bottom:4px}.quality-student:hover{background:#fff}.quality-student.active{background:#fff;border-color:#bfdbfe;box-shadow:0 3px 12px rgba(15,23,42,.06)}
.quality-student-name{font-size:13px;font-weight:700;color:#0f172a}.quality-student-num{font-size:11px;color:#64748b;font-family:inherit}.quality-student-count{grid-row:1/3;align-self:center;min-width:25px;height:25px;border-radius:999px;display:grid;place-items:center;background:#fee2e2;color:#b91c1c;font-size:11px;font-weight:800;font-family:inherit}
.quality-detail{flex:1;min-width:0;overflow:auto;background:#fff;position:relative}.quality-detail-empty{height:100%;display:grid;place-items:center;padding:40px;text-align:center;color:#94a3b8}
.quality-detail-inner{padding:24px 26px 34px}.quality-person{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;padding-bottom:18px;border-bottom:1px solid #e2e8f0}.quality-person h2{font-family:Fraunces,serif;font-size:25px;line-height:1.15;color:#14213d;margin:0 0 6px}.quality-person p{font-size:12px;line-height:1.55;color:#64748b;margin:0}
.quality-score{text-align:right;flex:0 0 auto}.quality-score strong{display:block;font-family:inherit;font-size:31px;line-height:1;color:#14213d}.quality-score span{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.5px}
.quality-ai{margin:18px 0;padding:15px 16px;background:#f5f8ff;border:1px solid #c7d7fe;border-left:4px solid #2563eb;border-radius:10px}.quality-ai-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:7px}.quality-ai-head strong{font-size:12px;color:#1e40af}.quality-ai p{font-size:13px;line-height:1.65;color:#334155;margin:0}.quality-ai-actions{display:flex;gap:8px;margin-top:11px}
.quality-section-title{font-size:11px;font-weight:800;letter-spacing:.45em;color:#64748b;margin:22px 0 9px}
.quality-issue{border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin-bottom:8px;border-left-width:4px}.quality-issue.high{border-left-color:#b91c1c}.quality-issue.medium{border-left-color:#b45309}.quality-issue.low{border-left-color:#2563eb}.quality-issue.identity .quality-issue-top>span{color:#4f46e5}.quality-issue.contact .quality-issue-top>span{color:#0f766e}.quality-issue.academic .quality-issue-top>span{color:#7c3aed}.quality-issue.duplicate .quality-issue-top>span{color:#c2410c}.quality-issue-top{display:flex;justify-content:space-between;gap:12px}.quality-issue-top>span{font-size:10px;font-weight:800;letter-spacing:.4em;text-transform:uppercase}.quality-issue strong{font-size:13px;color:#0f172a}.quality-issue p{font-size:12px;line-height:1.5;color:#64748b;margin:4px 0 0}.quality-value-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:9px}.quality-value{padding:8px 10px;background:#f8fafc;border-radius:7px}.quality-value label{display:block;font-size:9px;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:2px}.quality-value span{font-size:12px;color:#334155;word-break:break-word}.quality-value.suggested{background:#f0fdf4}.quality-value.suggested label{color:#15803d}
.quality-duplicate{padding:12px 14px;border:1px solid #fed7aa;background:#fffaf1;border-radius:10px;font-size:12px;line-height:1.5;color:#7c2d12}.quality-duplicate + .quality-duplicate{margin-top:7px}
.quality-actions{flex:0 0 68px;padding:12px 26px;border-top:1px solid #e2e8f0;background:#fff;display:flex;justify-content:flex-end;align-items:center;gap:8px;box-shadow:0 -4px 12px rgba(15,23,42,.04);z-index:2}.quality-actions .btn{min-width:112px}.quality-actions .btn-primary{min-width:150px}
.quality-empty{padding:32px;text-align:center;color:#64748b;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px}.quality-empty i{font-size:30px;color:#94a3b8;display:block;margin-bottom:8px}
@media(max-width:760px){#qualityModal .modal-content{height:94vh;max-height:94vh}.quality-shell{display:flex;flex-direction:column;overflow:hidden}.quality-queue{width:100%;height:220px;flex:0 0 220px;border-right:0;border-bottom:1px solid #e2e8f0}.quality-detail{overflow:auto;min-height:0}.quality-detail-inner{padding:18px 16px 28px}.quality-actions{flex-basis:auto;min-height:62px;padding:10px 16px;gap:6px}.quality-actions .btn{min-width:0;flex:1;padding-left:9px;padding-right:9px;font-size:12px}.quality-actions .btn-primary{min-width:0}.quality-person{display:block}.quality-score{text-align:left;margin-top:10px}.quality-value-grid{grid-template-columns:1fr}.quality-ai-actions{flex-wrap:wrap}}
@media(prefers-reduced-motion:reduce){.quality-student{transition:none}}
/* Empty state */
.vtab.active{border-bottom-color:#2563eb !important;color:#2563eb !important;}

.vtab-content.active{display:block}
.empty-state{text-align:center;padding:30px 20px;color:#94a3b8;min-height:200px}
.empty-state i{font-size:36px;color:#e2e8f0;display:block;margin-bottom:8px}
.empty-state p{font-size:15px;font-weight:500;color:#94a3b8}
.empty-state span{font-size:13px;color:#cbd5e1}
#emptyState{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:50vh;margin:0 auto;padding:40px 20px}

/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:20px}
.modal-overlay.active{display:flex}
.modal-content{background:white;border-radius:20px;padding:28px 32px;max-width:560px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,0.15);animation:modalSlide .3s ease;scrollbar-width:thin;scrollbar-color:#cbd5e1 transparent}
.modal-content::-webkit-scrollbar{width:5px}.modal-content::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:999px}
@keyframes modalSlide{from{opacity:0;transform:translateY(20px) scale(0.96)}to{opacity:1;transform:translateY(0) scale(1)}}
.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.modal-header h2{font-size:18px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:10px}
.modal-header h2 i{color:#2563eb}
.modal-close{width:34px;height:34px;border:none;background:#f1f5f9;border-radius:50%;cursor:pointer;font-size:15px;color:#94a3b8;transition:all .2s;display:flex;align-items:center;justify-content:center}
.modal-close:hover{background:#e2e8f0;color:#1e293b}
.modal-body{margin-bottom:16px}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;padding-top:14px;border-top:1px solid #e8edf4}
.modal-footer .btn{min-width:100px;justify-content:center}

/* View modal profile */
.view-profile{display:flex;flex-direction:column;align-items:center;padding:12px 0}
.view-profile .big-avatar{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:28px;margin-bottom:10px}
.view-profile .big-avatar.blue{background:linear-gradient(135deg,#2563eb,#1d4ed8)} .view-profile .big-avatar.green{background:linear-gradient(135deg,#16a34a,#15803d)}
.view-profile .big-avatar.purple{background:linear-gradient(135deg,#7c3aed,#6d28d9)} .view-profile .big-avatar.orange{background:linear-gradient(135deg,#b45309,#92400e)}
.view-profile .big-avatar.pink{background:linear-gradient(135deg,#db2777,#be185d)}
.view-profile .vp-name{font-size:20px;font-weight:700;color:#0f172a}
.view-profile .vp-id{font-size:13px;color:#64748b}

.view-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px;padding-top:14px;border-top:1px solid #f1f5f9}
.view-item{text-align:left}
.view-item .lbl{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.3px}
.view-item .val{font-size:14px;font-weight:600;color:#1e293b;margin-top:2px}

/* Edit modal forms */
.form-row{display:flex;gap:12px;margin-bottom:12px;flex-wrap:wrap}
.form-group{flex:1;min-width:160px}
.form-group label{display:block;font-size:12px;color:#475569;margin-bottom:4px;font-weight:600}
.form-control{width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:inherit;outline:none;background:white;color:#1e293b;box-sizing:border-box}
.form-control:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,0.10)}
select.form-control{cursor:pointer;appearance:auto;-webkit-appearance:auto;}
/* ─── Course selection: scrollable dropdown ─────────────────── */
.course-select-wrap{ position:relative; }
/* Hide the native select's default arrow; it's replaced by a styled
   scrollable list, but the select itself stays functional (keeps value,
   required, and form submission working). */
.course-select-wrap select.form-control{
  appearance:none;-webkit-appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4 6l4 4 4-4'/%3E%3C/svg%3E");
  background-position:right 12px center;
  background-repeat:no-repeat;
  background-size:14px;
  padding-right:32px;
  cursor:pointer;
}
/* Scrollable list: max 5 options (~5*34px) tall, then scrolls */
.course-select-list{
  position:absolute;top:100%;left:0;right:0;z-index:100;
  margin-top:2px;background:#fff;border:1.5px solid #e2e8f0;
  border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.1);
  max-height:170px;overflow-y:auto;
}
.course-select-list .cs-option{
  padding:7px 12px;font-size:13px;color:#1e293b;cursor:pointer;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  border-bottom:1px solid #f1f5f9;
}
.course-select-list .cs-option:last-child{border-bottom:none;}
.course-select-list .cs-option:hover{ background:#eef4ff; color:#2563eb; }
.course-select-list .cs-option.active{ background:#eef4ff; color:#2563eb; font-weight:600; }
.modal-overlay select.form-control,
.modal-overlay select { cursor:pointer !important; appearance:auto !important; -webkit-appearance:auto !important; }
/* bulk bar select inside page (not modal) — force native */
.bulk-bar select.form-control { appearance:auto !important; -webkit-appearance:auto !important; cursor:pointer !important; }

/* Delete icon */
.delete-icon{width:60px;height:60px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:26px;color:#dc2626}

/* Export dropdown */
.export-wrap{position:relative}
.export-menu{display:none;position:absolute;top:100%;right:0;z-index:50;background:white;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.1);min-width:160px;padding:4px;margin-top:4px}
.export-menu.show{display:block}
.export-menu a{display:block;padding:8px 12px;font-size:12px;font-weight:600;color:#1e293b;text-decoration:none;border-radius:6px}
.export-menu a:hover{background:#f1f5f9}

/* Responsive */
@media(max-width:992px){
 .dashboard-main{padding:20px}
 .stu-strip{grid-template-columns:repeat(2,1fr)!important}
 .stu-metric:nth-child(2){border-right:0}
 .stu-metric:nth-child(-n+2){border-bottom:1px solid #e2e8f0}
}
@media(max-width:900px){.search-bar .search-wrapper{flex:1 1 240px;min-width:200px}
body[data-page="students"] .stu-action-group{flex:1 1 100%}}
@media(max-width:768px){
.dashboard-main{margin-left:0;padding:16px;width:100%;max-width:100%}
body[data-page="students"] .header{padding:21px 18px;border-radius:16px}
body[data-page="students"] .header .title h1{font-size:25px}
body[data-page="students"] .stu-metric{padding:14px 16px 13px}
body[data-page="students"] .stu-metric .stu-metric-value{font-size:23px}
body[data-page="students"] .stu-ribbon-bar{height:28px;margin:14px 16px 0}
body[data-page="students"] .stu-ribbon-legend{padding:0 16px}
body[data-page="students"] .stu-ribbon-legend li{flex:1 1 50%}
.search-bar{flex-direction:column;flex-wrap:nowrap;align-items:stretch;gap:10px}
.search-bar .search-wrapper{flex:1 1 auto;min-width:0;max-width:100%;width:100%}
.search-bar .search-actions{width:100%;height:auto;justify-content:flex-end;flex-wrap:wrap}
.search-bar .search-actions .btn{height:38px;justify-content:center}
.table-responsive table{min-width:600px}
.table-responsive th,.table-responsive td{padding:8px 8px;font-size:12px}
.student-avatar{width:28px;height:28px;font-size:10px}
.student-email{display:none}
}
@media(max-width:480px){
.dashboard-main{padding:12px}
body[data-page="students"] .stu-strip{grid-template-columns:1fr!important}
body[data-page="students"] .stu-metric{border-right:0;border-bottom:1px solid #e2e8f0}
body[data-page="students"] .stu-metric:last-child{border-bottom:0}
body[data-page="students"] .stu-ribbon-legend li{flex:1 1 45%;padding:9px 6px 10px}
body[data-page="students"] .stu-legend-val{font-size:16px}
.search-bar{padding:10px 14px;gap:8px}
.search-bar .search-wrapper{height:38px}
.search-bar .search-wrapper input{height:38px;font-size:13px}
.search-bar .search-actions{gap:6px}
.search-bar .search-actions .btn{padding:0 14px;font-size:12px;height:36px}
.table-responsive th,.table-responsive td{padding:6px 6px;font-size:11px}
}
.quality-legend{position:relative;display:inline-flex;margin-left:3px;}
.quality-legend .quality-legend-box{display:none;position:absolute;top:20px;left:50%;transform:translateX(-50%);z-index:50;background:#0f172a;color:#f8fafc;font-size:12px;line-height:1.6;padding:10px 12px;border-radius:8px;white-space:nowrap;box-shadow:0 8px 24px rgba(15,23,42,.2);font-weight:500;text-align:left;}
.quality-legend:hover .quality-legend-box{display:block;}
.quality-legend-box::before{content:'';position:absolute;top:-5px;left:50%;transform:translateX(-50%);border:6px solid transparent;border-bottom-color:#0f172a;}
</style>
<main class="dashboard-main">
<header class="header">
<div class="title">
    <div class="stu-kicker"><i class="fas fa-user-graduate"></i> Student records</div>
    <h1>Students</h1>
    <p>Every enrolled record with year level, standing and record quality. Search, filter and update without leaving the page.</p>
  </div>
</header>

<!-- Action bar: intake creates records, tools operate on the student list -->
<section class="stu-actionbar" aria-label="Student actions">
  <div class="stu-action-group">
    <span class="stu-action-label">Add students</span>
    <div class="stu-action-buttons">
      <button type="button" class="btn btn-primary" onclick="openReceiveModal()" title="Enroll students who have a pending enrollment record"><i class="fas fa-inbox"></i> Receive Student</button>
      <button type="button" class="btn btn-secondary" onclick="openAddModal()" title="Create a single student record by hand"><i class="fas fa-user-plus"></i> Add Student</button>
    </div>
  </div>
  <div class="stu-action-group">
    <span class="stu-action-label">Student tools</span>
    <div class="stu-action-buttons">
      <button type="button" class="btn btn-secondary" onclick="openQualityPanel()" title="Review incomplete or inconsistent student records"><i class="fas fa-shield-halved"></i> Data Quality</button>
      <div class="export-wrap">
        <button type="button" class="btn btn-secondary" id="exportBtn"><i class="fas fa-download"></i> Export</button>
        <div class="export-menu" id="exportMenu">
        <a href="#" onclick="exportCSV()"><i class="fas fa-file-csv"></i> Export CSV</a>
        <a href="#" onclick="exportFiltered()"><i class="fas fa-filter-circle-dollar"></i> Export Filtered</a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Metric strip + cohort ribbon -->
<div class="stu-strip">
<div class="stu-metric tone-blue">
<p class="stu-metric-label">Total students</p>
<div class="stu-metric-value"><?= $totalStudents ?></div>
<div class="stu-metric-foot">
<?php if ($trendTotal > 0): ?>
<span class="stu-trend up"><i class="fas fa-arrow-up"></i><?= $trendTotal ?>%</span><span><?= $thisMonth ?> joined this month</span>
<?php elseif ($trendTotal < 0): ?>
<span class="stu-trend down"><i class="fas fa-arrow-down"></i><?= abs($trendTotal) ?>%</span><span><?= $thisMonth ?> joined this month</span>
<?php else: ?>
<span class="stu-trend flat"><i class="fas fa-minus"></i>0%</span><span><?= $thisMonth ?> joined this month</span>
<?php endif; ?>
</div>
</div>
<div class="stu-metric tone-green">
<p class="stu-metric-label">Active</p>
<div class="stu-metric-value"><?= $activeStudents ?></div>
<div class="stu-metric-foot">
<span><?= $totalStudents > 0 ? round($activeStudents / $totalStudents * 100) : 0 ?>% of students</span>
<span class="stu-meter"><i style="width:<?= $totalStudents > 0 ? round($activeStudents / $totalStudents * 100) : 0 ?>%;background:#16a34a"></i></span>
</div>
</div>
<div class="stu-metric tone-amber">
<p class="stu-metric-label">At risk or probation</p>
<div class="stu-metric-value"><?= $atRiskStudents ?></div>
<div class="stu-metric-foot">
<?php if ($atRiskStudents > 0): ?>
<span class="stu-trend down"><i class="fas fa-triangle-exclamation"></i>Needs follow-up</span>
<?php else: ?>
<span class="stu-trend up"><i class="fas fa-circle-check"></i>None flagged</span>
<?php endif; ?>
</div>
</div>
<div class="stu-metric tone-violet">
<p class="stu-metric-label">Graduated</p>
<div class="stu-metric-value"><?= $graduatedStudents ?></div>
<div class="stu-metric-foot">
<span><?= $totalStudents > 0 ? round($graduatedStudents / $totalStudents * 100) : 0 ?>% of all records</span>
<span class="stu-meter"><i style="width:<?= $totalStudents > 0 ? round($graduatedStudents / $totalStudents * 100) : 0 ?>%;background:#7c3aed"></i></span>
</div>
</div>
</div>

<div class="stu-ribbon">
<div class="stu-ribbon-head">
<h2 class="stu-ribbon-title"><i class="fas fa-layer-group"></i> Cohort by year level</h2>
<span class="stu-ribbon-note">Each segment is that year's share of all <?= $totalStudents ?> records</span>
</div>
<?php if ($totalStudents > 0): ?>
<div class="stu-ribbon-bar" role="img" aria-label="<?= htmlspecialchars(implode(', ', array_map(fn($sg) => ($sg['year'] ? 'Year ' . $sg['year'] : 'Unassigned') . ': ' . $sg['count'] . ' students', $ribbonSegments)), ENT_QUOTES) ?>">
<?php foreach ($ribbonSegments as $sg):
$pct = round($sg['count'] / $ribbonBase * 100, 2);
$segLabel = $sg['year'] ? 'Year ' . $sg['year'] : 'Unassigned year level';
?>
<div class="stu-seg tone-<?= $sg['tone'] ?>" style="width:<?= $pct ?>%" title="<?= htmlspecialchars($segLabel . ' — ' . $sg['count'] . ' students (' . round($pct) . '%)', ENT_QUOTES) ?>">
<?php /* Label the segment only when it is wide enough to hold the number; the legend always carries it. */ if ($pct >= 7): ?><b><?= $sg['count'] ?></b><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<ul class="stu-ribbon-legend">
<?php foreach ($ribbonSegments as $sg): ?>
<li>
<div class="stu-legend-key"><i class="tone-<?= $sg['tone'] ?>"></i><?= $sg['year'] ? 'Year ' . $sg['year'] : 'Unassigned' ?></div>
<div class="stu-legend-val"><?= $sg['count'] ?><span class="stu-legend-pct"><?= round($sg['count'] / $ribbonBase * 100) ?>%</span></div>
</li>
<?php endforeach; ?>
</ul>
<?php else: ?>
<div class="stu-ribbon-empty">
<i class="fas fa-user-graduate"></i>
<p>No cohort to show yet</p>
<span>Receive or add a student and the year-level split appears here.</span>
</div>
<?php endif; ?>
</div>

<!-- Search + Table -->
<div class="search-table-container">
<div class="search-bar">
<div class="search-wrapper">
<i class="fas fa-search"></i>
<input type="text" id="studentSearch" placeholder="Search by name, ID, course..." />
<button class="search-clear" id="searchClear"><i class="fas fa-times"></i></button>
</div>
<div class="search-actions">
<button class="btn btn-secondary" onclick="printTable()"><i class="fas fa-print"></i> Print</button>
<button class="btn btn-secondary" id="filterToggle"><i class="fas fa-sliders"></i> Filter</button>
</div>
</div>

<!-- Bulk bar -->
<div class="bulk-bar" id="bulkBar">
<span class="count" id="bulkCount">0 selected</span>
<select class="form-control" style="width:auto;display:inline-block;padding:6px 10px;font-size:12px;" id="bulkActionSelect"><option value="">Bulk action...</option><option value="active">Set Active</option><option value="at-risk">Set At Risk</option><option value="probation">Set Probation</option><option value="graduated">Set Graduated</option><option value="loa">Set LOA</option><option value="transferred">Set Transferred</option><option value="dropped">Set Dropped</option></select>
<button class="btn btn-secondary" style="height:32px;padding:0 12px;font-size:12px;" onclick="applyBulkAction()"><i class="fas fa-check"></i> Apply</button>
</div>

<div class="table-responsive" id="studentTableWrap">
<table id="studentTable">
<thead>
<tr><th style="width:30px;"><div class="cb-wrap"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></div></th><th class="rownum">#</th><th>Student ID</th><th>Name</th><th>Course</th><th class="num">Year</th><th class="num">Section</th><th class="num">Gender</th><th>RFID</th><th>Status</th><th class="num">Quality <span class="quality-legend" title=""><i class="fas fa-circle-info" style="cursor:help;"></i><span class="quality-legend-box">Quality score = % of required student fields filled.
<span style="color:#22c55e;">●</span> 85–100% &nbsp; Complete
<span style="color:#f59e0b;">●</span> 60–84% &nbsp; Some fields missing
<span style="color:#ef4444;">●</span> &lt;60% &nbsp; Many fields missing
<span style="color:#f59e0b;">⚠</span> Anomaly worth checking (hover the row)</span></span></th><th class="num">Actions</th></tr>
</thead>
<tbody id="studentTableBody">
<?php if (!empty($students)): ?>
<?php
$avatarColors = ['blue','green','purple','orange','pink'];
foreach ($students as $i => $s):
$initials = strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1));
$ac = $avatarColors[$i % count($avatarColors)];
$hasRfid = isset($rfidMap[$s['id']]);
$rfidStatus = $hasRfid && $rfidMap[$s['id']]['status'] === 'active' ? 'active' : ($hasRfid ? 'inactive' : 'none');
// Data quality score + anomaly flags (deterministic)
$qScore = studentQualityScore($s);
$qAnoms = studentAnomalies($s);
$qDotClass = $qScore >= 85 ? 'good' : ($qScore >= 60 ? 'warn' : 'bad');
?>
<tr data-student='<?= htmlspecialchars(json_encode($s),ENT_QUOTES,'UTF-8') ?>' class="<?= $s['status']==='archived'?'archived':'' ?><?= !empty($qAnoms) ? ' q-flag' : '' ?>">
<td><div class="cb-wrap"><input type="checkbox" class="student-cb" value="<?= (int)$s['id'] ?>" onchange="updateBulkBar()"></div></td>
<td class="rownum"><?= (int)$s['id'] ?></td>
<td class="student-id" style="font-weight:600;font-size:12px;"><?= htmlspecialchars($s['student_number'] ?: '—') ?></td>
<td><div class="student-info"><div class="student-avatar <?= $ac ?>"><?= $initials ?: '?' ?></div><div><div class="student-name"><?= htmlspecialchars($s['first_name']." ".$s['last_name']) ?></div><div class="student-email"><?= htmlspecialchars($s['email'] ?? '') ?></div></div></div></td>
<td><?= htmlspecialchars($s['course'] ?? 'N/A') ?></td>
<td class="num"><?= htmlspecialchars($s['year_level'] ?? 'N/A') ?></td>
<td class="num"><?= htmlspecialchars($s['section'] ?? '—') ?></td>
<td class="num"><?= htmlspecialchars(($s['gender'] ?? '') ?: '—') ?></td>
<td><a href="../registrar/rfid-cards.php?search=<?= urlencode($s['student_number']) ?>" class="rfid-chip <?= $rfidStatus ?>"><i class="fas fa-<?= $rfidStatus==='active'?'check-circle':'credit-card' ?>"></i> <?= $rfidStatus==='active'?($rfidMap[$s['id']]['card_uid']):($rfidStatus==='none'?'—':$rfidMap[$s['id']]['status']) ?></a></td>
<td><div class="quick-status-wrap"><button class="status-badge <?= $s['status']??'active' ?>" onclick="toggleQuickMenu(<?= (int)$s['id'] ?>)"><span class="status-dot <?= $s['status']??'active' ?>"></span><?= ucfirst($s['status']??'Active') ?></button><div class="quick-status-menu" id="qsm_<?= (int)$s['id'] ?>"><?php $statuses=['active','probation','at-risk','graduated','loa','transferred','dropped']; if($s['status']==='archived')$statuses[]='archived'; foreach($statuses as $st): ?><button onclick="quickStatus(<?= (int)$s['id'] ?>,'<?= $st ?>')" class="<?= ($s['status']??'active')===$st?'active':'' ?>"><?= ucfirst($st) ?></button><?php endforeach; ?></div></div></td>
<td class="num">
<?php
$qAnomLabels = array_map(function ($k) {
    return [
        'age_mismatch' => 'Age doesn\'t match year level',
        'future_birthdate' => 'Birth date is in the future',
        'no_address' => 'Missing address',
        'no_contact' => 'Missing contact number',
        'no_gender' => 'Missing gender',
        'course_nonstandard' => 'Course name not standardized',
    ][$k] ?? $k;
}, $qAnoms);
$qTitle = 'Quality ' . $qScore . '%' . (!empty($qAnoms) ? ' — ' . implode('; ', $qAnomLabels) : ' — all key fields filled');
?>
<span class="q-dot q-<?= $qDotClass ?>" title="<?= htmlspecialchars($qTitle) ?>"></span>
<?php if (!empty($qAnoms)): ?><i class="fas fa-exclamation-triangle" style="color:#f59e0b;margin-left:4px;font-size:11px;" title="<?= htmlspecialchars(implode('; ', $qAnomLabels)) ?>"></i><?php endif; ?>
</td>
<td><div class="action-group"><button class="action-btn view" onclick="viewStudent(<?= (int)$s['id'] ?>)" title="View"><i class="fas fa-eye"></i></button><button class="action-btn edit" onclick="editStudent(<?= (int)$s['id'] ?>)" title="Edit"><i class="fas fa-pen"></i></button><?php if ($s['status']==='archived'): ?><button class="action-btn restore" onclick="restoreStudent(<?= (int)$s['id'] ?>,'<?= htmlspecialchars($s['first_name']." ".$s['last_name'],ENT_QUOTES) ?>')" title="Restore"><i class="fas fa-undo"></i></button><?php endif; ?></div></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
<div class="empty-state" id="emptyState" style="<?= empty($students) ? 'display:flex' : 'display:none' ?>">
  <i class="fas fa-user-graduate"></i>
  <p>No students found</p>
  <span>Add your first student to get started</span>
</div>
</div>

<div class="table-footer">
<div class="info-text">Showing <strong id="showingCount"><?= count($students) ?></strong> of <strong id="totalCount"><?= count($students) ?></strong> students</div>
</div>
</div>
</main>

<!-- Filter Modal -->
<div class="modal-overlay" id="filterModal">
<div class="modal-content">
<div class="modal-header"><h2><i class="fas fa-sliders"></i> Filter Students</h2><button class="modal-close" onclick="closeFilterModal()"><i class="fas fa-times"></i></button></div>
<div class="modal-body">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
<div class="form-group"><label>Status</label><select id="filterStatus" class="form-control"><option value="">All Status</option><option value="enrolled">Enrolled</option><option value="active">Active</option><option value="probation">Probation</option><option value="at-risk">At Risk</option><option value="graduated">Graduated</option><option value="loa">LOA</option><option value="transferred">Transferred</option><option value="dropped">Dropped</option><option value="archived">Archived</option></select></div>
<div class="form-group"><label>Year Level</label><select id="filterYear" class="form-control"><option value="">All Year</option><option value="1">1st</option><option value="2">2nd</option><option value="3">3rd</option><option value="4">4th</option></select></div>
<div class="form-group"><label>Course</label><select id="filterCourse" class="form-control"><option value="">All Courses</option><?php foreach($courses as $c): ?><option value="<?= htmlspecialchars($c['course']) ?>"><?= htmlspecialchars($c['course']) ?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>Section</label><input type="text" id="filterSection" class="form-control" placeholder="Enter section..." /></div>
</div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" onclick="closeFilterModal()">Cancel</button><button class="btn btn-secondary" onclick="clearFilters()">Clear All</button><button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-check"></i> Apply</button></div>
</div>
</div>

<!-- View Modal (tabbed) -->
<div class="modal-overlay" id="viewModal"><div class="modal-content" style="max-width:600px;"><div class="modal-header"><h2><i class="fas fa-id-card"></i> Student Profile</h2><button class="modal-close" onclick="closeViewModal()"><i class="fas fa-times"></i></button></div>
<div style="display:flex;gap:4px;margin-bottom:14px;border-bottom:1px solid #e2e8f0;padding-bottom:0;">
<button class="vtab active" onclick="switchVTab(this,'profile')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#2563eb;cursor:pointer;border-bottom:2px solid #2563eb;font-family:inherit;"><i class="fas fa-user"></i> Profile</button>
<button class="vtab" onclick="switchVTab(this,'documents')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit;"><i class="fas fa-file"></i> Documents</button>
<button class="vtab" onclick="switchVTab(this,'academic')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit;"><i class="fas fa-school"></i> Academic</button>
<button class="vtab" onclick="switchVTab(this,'health')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit;"><i class="fas fa-heartbeat"></i> Health</button>
</div>
<div class="modal-body">
<!-- Tab: Profile -->
<div class="vtab-content active" id="tabProfile">
<div class="view-profile">
<div class="big-avatar blue" id="vAvatar" style="position:relative;"><span id="vAvatarText">JD</span></div>
<input type="file" id="photoInput" accept="image/*" style="display:none">
<button class="btn btn-secondary" style="margin:-4px auto 10px;padding:4px 12px;font-size:11px;" onclick="document.getElementById('photoInput').click()"><i class="fas fa-camera"></i> Change Photo</button>
<div class="vp-name" id="vName">—</div>
<div class="vp-id" id="vStudentId">—</div>
<div class="vp-id" id="vDbId" style="font-size:11px;color:#94a3b8;margin-top:2px;">—</div>
<div id="vLastScan" style="font-size:12px;color:#64748b;margin-top:6px;display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap;"></div>
<div id="vAiSummary" style="display:none;margin-top:12px;background:linear-gradient(135deg,#eef4ff,#f5f3ff);border:1px solid #dbeafe;border-radius:10px;padding:12px 14px;font-size:13px;color:#1e40af;"></div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px;padding-top:12px;border-top:1px solid #f1f5f9">
<div class="view-item"><div class="lbl">Status</div><div class="val" id="vStatus">—</div></div>
<div class="view-item"><div class="lbl">LRN</div><div class="val" id="vLrn">—</div></div>
<div class="view-item"><div class="lbl">Gender</div><div class="val" id="vGender">—</div></div>
<div class="view-item"><div class="lbl">Civil Status</div><div class="val" id="vCivilStatus">—</div></div>
<div class="view-item"><div class="lbl">Birth Date</div><div class="val" id="vBirthDate">—</div></div>
<div class="view-item"><div class="lbl">Place of Birth</div><div class="val" id="vBirthPlace">—</div></div>
<div class="view-item"><div class="lbl">Nationality</div><div class="val" id="vNationality">—</div></div>
<div class="view-item"><div class="lbl">Religion</div><div class="val" id="vReligion">—</div></div>
<div class="view-item"><div class="lbl">Course</div><div class="val" id="vCourse">—</div></div>
<div class="view-item"><div class="lbl">Year / Section</div><div class="val" id="vYearSection">—</div></div>
<div class="view-item"><div class="lbl">School Year / Sem</div><div class="val" id="vSchoolYearSem">—</div></div>
<div class="view-item"><div class="lbl">Adviser</div><div class="val" id="vAdviser">—</div></div>
<div class="view-item"><div class="lbl">Email</div><div class="val" id="vEmail">—</div></div>
<div class="view-item"><div class="lbl">Contact</div><div class="val" id="vContact">—</div></div>
<div class="view-item"><div class="lbl">Father</div><div class="val" id="vFather">—</div></div>
<div class="view-item"><div class="lbl">Mother</div><div class="val" id="vMother">—</div></div>
<div class="view-item" style="grid-column:span 2;"><div class="lbl">Address</div><div class="val" id="vAddress">—</div></div>
</div>
<div id="vGuardianSection" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9;"><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:6px;">Guardian</div><div id="vGuardianInfo" style="font-size:13px;color:#475569;"></div></div>
<div id="vRfidSection" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9;text-align:center;display:flex;gap:8px;justify-content:center;"><a id="vRfidLink" href="#" class="btn btn-secondary" style="padding:6px 14px;font-size:12px;"><i class="fas fa-credit-card"></i> RFID Card</a> <a id="vScanLink" href="#" class="btn btn-secondary" style="padding:6px 14px;font-size:12px;"><i class="fas fa-clock-rotate-left"></i> Scan Logs</a></div>
</div>
<!-- Tab: Documents -->
<div class="vtab-content" id="tabDocuments" style="display:none;"><div id="vDocuments" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
<!-- Tab: Academic -->
<div class="vtab-content" id="tabAcademic" style="display:none;"><div id="vAcademic" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
<!-- Tab: Health -->
<div class="vtab-content" id="tabHealth" style="display:none;"><div id="vHealth" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" onclick="resendWelcomeEmail()" id="resendWelcomeBtn"><i class="fas fa-envelope"></i> Resend Welcome Email</button> <button class="btn btn-primary" onclick="closeViewModal()"><i class="fas fa-times"></i> Close</button></div></div></div>

<!-- Data Quality Review Desk -->
<div class="modal-overlay" id="qualityModal" role="dialog" aria-modal="true" aria-labelledby="qualityModalTitle">
    <div class="modal-content">
        <div class="modal-header" style="padding:18px 22px;margin:0;border-bottom:1px solid #e2e8f0">
            <div><h2 id="qualityModalTitle"><i class="fas fa-shield-halved"></i> Student Data Quality</h2><p style="font-size:12px;color:#64748b;margin:4px 0 0">Review incomplete and inconsistent student records.</p></div>
            <div style="display:flex;gap:8px;align-items:center"><button type="button" class="btn btn-secondary" id="qualityRefreshBtn" style="padding:8px 12px;font-size:12px"><i class="fas fa-rotate"></i> Refresh</button><button class="modal-close" aria-label="Close Data Quality" onclick="closeQualityPanel()"><i class="fas fa-times"></i></button></div>
        </div>
        <div class="quality-shell">
            <section class="quality-queue" aria-label="Students needing review">
                <div class="quality-queue-head">
                    <h3>Records to review</h3><p id="qualityQueueMeta">Checking student records…</p>
                    <div class="quality-search"><i class="fas fa-search"></i><input id="qualitySearch" type="search" placeholder="Search name or student #" autocomplete="off"></div>
                    <div class="quality-filters" id="qualityFilters">
                        <button type="button" class="quality-filter active" data-filter="all">All</button>
                        <button type="button" class="quality-filter" data-filter="identity">Identity</button>
                        <button type="button" class="quality-filter" data-filter="contact">Contact</button>
                        <button type="button" class="quality-filter" data-filter="academic">Academic</button>
                        <button type="button" class="quality-filter" data-filter="duplicate">Duplicates</button>
                    </div>
                </div>
                <div class="quality-list" id="qualityList"></div>
            </section>
            <section class="quality-detail" id="qualityDetail" aria-live="polite"><div class="quality-detail-empty"><div><i class="fas fa-user-check"></i><p>Select a student to review their record.</p></div></div></section>
        </div>
        <div class="quality-actions" aria-label="Data Quality actions">
            <button class="btn btn-secondary" onclick="closeQualityPanel()">Close</button>
            <button class="btn btn-secondary" id="qualityOpenStudentBtn" type="button" onclick="openQualityStudent()"><i class="fas fa-user"></i> Open student</button>
            <button class="btn btn-primary" id="qualityApplyBtn" type="button" onclick="applySelectedQualityRepairs()" disabled><i class="fas fa-check"></i> Apply selected</button>
        </div>
    </div>
</div>

<!-- Add Modal (inline, with guardian) -->
<div class="modal-overlay" id="addModal"><div class="modal-content" style="max-width:760px;"><div class="modal-header"><h2><i class="fas fa-plus-circle"></i> Enroll New Student</h2><div style="display:flex;gap:8px;align-items:center;"><button class="btn btn-secondary" style="padding:6px 12px;font-size:12px;" onclick="openPasteModal()"><i class="fas fa-magic"></i> Paste to Fill</button><button class="modal-close" onclick="closeAddModal()"><i class="fas fa-times"></i></button></div></div><form id="addForm"><div class="modal-body">
<div class="form-row"><div class="form-group"><label>Academic Status</label><select id="addStatus" class="form-control"><option value="enrolled">Enrolled</option><option value="active">Active</option><option value="probation">Probation</option><option value="at-risk">At Risk</option><option value="loa">LOA</option><option value="graduated">Graduated</option><option value="transferred">Transferred</option><option value="dropped">Dropped</option></select></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:0 0 12px;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-user"></i> Personal Information</div>
<div class="form-row"><div class="form-group"><label>First Name <span style="color:#dc2626;">*</span></label><input type="text" id="addFirstName" class="form-control" required></div><div class="form-group"><label>Middle Name</label><input type="text" id="addMiddleName" class="form-control"></div><div class="form-group"><label>Last Name <span style="color:#dc2626;">*</span></label><input type="text" id="addLastName" class="form-control" required></div></div>
<div class="form-row"><div class="form-group"><label>Name Suffix</label><select id="addSuffix" class="form-control"><option value="">—</option><option value="Jr.">Jr.</option><option value="Sr.">Sr.</option><option value="II">II</option><option value="III">III</option><option value="IV">IV</option></select></div><div class="form-group"><label>LRN (optional)</label><input type="text" id="addLrn" class="form-control" placeholder="12-digit Learner Reference No." maxlength="12"></div><div class="form-group"><label>Gender</label><select id="addGender" class="form-control"><option value="">Select</option><option value="Male">Male</option><option value="Female">Female</option></select></div></div>
<div class="form-row"><div class="form-group"><label>Civil Status</label><select id="addCivilStatus" class="form-control"><option value="">Select</option><option value="Single">Single</option><option value="Married">Married</option><option value="Widowed">Widowed</option><option value="Separated">Separated</option></select></div><div class="form-group"><label>Birth Date <span style="color:#dc2626;">*</span></label><input type="date" id="addBirthDate" class="form-control" required></div><div class="form-group"><label>Place of Birth</label><input type="text" id="addBirthPlace" class="form-control" placeholder="City, Province"></div></div>
<div class="form-row"><div class="form-group"><label>Nationality</label><input type="text" id="addNationality" class="form-control" value="Filipino"></div><div class="form-group"><label>Religion</label><input type="text" id="addReligion" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Father's Name</label><input type="text" id="addFather" class="form-control" placeholder="Full name of father"></div><div class="form-group"><label>Mother's Name</label><input type="text" id="addMother" class="form-control" placeholder="Full name of mother"></div></div>
<div class="form-row"><div class="form-group"><label>Email <span style="color:#dc2626;">*</span></label><input type="email" id="addEmail" class="form-control" placeholder="student@school.edu.ph" required></div><div class="form-group"><label>Contact No. <span style="color:#dc2626;">*</span></label><input type="text" id="addContact" class="form-control" placeholder="09XXXXXXXXX" required pattern="09[0-9]{9}" title="11-digit mobile number (e.g. 09171234567)"></div></div>
<div class="form-row"><div class="form-group"><label>Address <span style="color:#dc2626;">*</span></label><textarea id="addAddress" class="form-control" rows="2" required></textarea></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:12px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-book"></i> Enrollment Details</div>
<div class="form-row"><div class="form-group" style="flex:1 1 220px;min-width:150px;"><label>Course <span style="color:#dc2626;">*</span></label><div class="course-select-wrap"><select id="addCourse" class="form-control" required><option value="">Select course</option><?php foreach ($offeredCourses as $cname => $majors): ?><option value="<?= htmlspecialchars($cname) ?>"><?= htmlspecialchars($cname) ?></option><?php endforeach; ?></select><div class="course-select-list" style="display:none;"></div></div></div><div class="form-group" style="flex:0 0 150px;"><label>Year Level <span style="color:#dc2626;">*</span></label><select id="addYearLevel" class="form-control" required><option value="">Select</option><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div><div class="form-group" id="addMajorGroup" style="display:none;flex:1 1 200px;"><label>Major</label><select id="addMajor" class="form-control"><option value="">Select major</option></select></div></div>
<div class="form-row"><div class="form-group"><label>School Year</label><input type="text" id="addSchoolYear" class="form-control" placeholder="2026-2027" value="2026-2027"></div><div class="form-group"><label>Semester <span style="color:#dc2626;">*</span></label><select id="addSemester" class="form-control" required><option value="">—</option><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="summer">Summer</option></select></div><div class="form-group"><label>Section <button type="button" style="background:none;border:none;color:#2563eb;cursor:pointer;font-size:11px;padding:0;" onclick="suggestSection()"><i class="fas fa-magic"></i> Suggest</button></label><input type="text" id="addSection" class="form-control" placeholder="Set a year level first" disabled><small style="color:#64748b;font-size:11px;">A section is derived from the year level.</small></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:12px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-users"></i> Guardian / Parent</div>
<div class="form-row"><div class="form-group"><label>Full Name <span style="color:#dc2626;">*</span></label><input type="text" id="addGuardianName" class="form-control" required></div><div class="form-group"><label>Relationship</label><select id="addGuardianRel" class="form-control"><option value="father">Father</option><option value="mother">Mother</option><option value="guardian">Guardian</option></select></div></div>
<div class="form-row"><div class="form-group"><label>Contact No. <span style="color:#dc2626;">*</span></label><input type="text" id="addGuardianContact" class="form-control" placeholder="09XXXXXXXXX" pattern="09[0-9]{9}" title="11-digit mobile number (e.g. 09171234567)"></div><div class="form-group"><label>Email (optional)</label><input type="email" id="addGuardianEmail" class="form-control"></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeAddModal()">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enroll</button></div></form></div></div>

<!-- Auto-created Student Portal Account Modal -->
<div class="modal-overlay" id="acctModal"><div class="modal-content" style="max-width:520px;"><div class="modal-header"><h2><i class="fas fa-user-graduate"></i> Student Portal Account Created</h2><button class="modal-close" onclick="closeAcctModal()"><i class="fas fa-times"></i></button></div><div class="modal-body" style="padding:20px;">
<p style="font-size:13px;color:#64748b;margin-bottom:16px;">A student portal account was automatically created. Share these credentials with the student so they can log in to the <strong>Student Portal</strong>. They can change the password after first login.</p>
<div id="acctEmailNote" style="display:none;background:#fef3c7;border:1px solid #fde047;color:#92400e;border-radius:8px;padding:10px 12px;font-size:12px;margin-bottom:14px;"><i class="fas fa-triangle-exclamation"></i> The welcome email could not be sent (SMTP not configured) — please share the credentials below with the student manually.</div>
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;">
<div class="form-group" style="margin-bottom:12px;"><label>Username</label><div style="display:flex;gap:8px;align-items:center;"><code id="acctEmail" style="flex:1;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px;"></code><button type="button" class="btn btn-secondary" style="padding:6px 12px;font-size:12px;" onclick="copyAcct('acctEmail')">Copy</button></div></div>
<div class="form-group" style="margin-bottom:12px;"><label>Temporary Password</label><div style="display:flex;gap:8px;align-items:center;"><code id="acctPassword" style="flex:1;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px;"></code><button type="button" class="btn btn-secondary" style="padding:6px 12px;font-size:12px;" onclick="copyAcct('acctPassword')">Copy</button></div></div>
</div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeAcctModal()" style="margin-right:auto;border:none;background:none;color:#94a3b8;">Don't show again</button><button type="button" class="btn btn-primary" onclick="closeAcctModal()"><i class="fas fa-check"></i> Done</button></div></div></div>

<!-- AI Document Reader / Paste-to-Fill Modal -->
<div class="modal-overlay" id="pasteModal"><div class="modal-content" style="max-width:680px;"><div class="modal-header"><h2><i class="fas fa-file-import"></i> AI Document Reader</h2><button class="modal-close" onclick="closePasteModal()"><i class="fas fa-times"></i></button></div><div class="modal-body">
<p style="font-size:13px;color:#64748b;margin-bottom:12px;">Download the form template, have the student fill it out, then <strong>drop the file here</strong> (PDF, Word, or text). AI extracts the details for you to review and apply.</p>
<div style="display:flex;gap:10px;margin-bottom:12px;"><a href="../api/student-template.php" class="btn btn-secondary" style="cursor:pointer;"><i class="fas fa-file-word"></i> Download Word Template</a></div>
<div id="pasteDropzone" style="border:2px dashed #cbd5e1;border-radius:12px;padding:26px 16px;text-align:center;color:#64748b;background:#f8fafc;cursor:pointer;transition:all .15s;margin-bottom:8px;">
<i class="fas fa-cloud-arrow-up" style="font-size:26px;display:block;margin-bottom:8px;color:#94a3b8;"></i>
<div style="font-size:13px;"><strong>Drag &amp; drop a file here</strong> or <span style="color:#2563eb;text-decoration:underline;">click to browse</span></div>
<div style="font-size:12px;color:#94a3b8;margin-top:4px;">PDF, DOCX, TXT, or image (PNG/JPG) · up to 15 MB</div>
<input type="file" id="pasteFile" accept=".pdf,.docx,.txt,.png,.jpg,.jpeg,.webp" style="display:none;">
</div>
<div id="pasteFileName" style="font-size:12px;color:#16a34a;margin-bottom:8px;"></div>
<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;color:#94a3b8;font-size:12px;"><span style="flex:1;border-top:1px solid #e2e8f0;"></span> or paste text <span style="flex:1;border-top:1px solid #e2e8f0;"></span></div>
<textarea id="pasteText" class="form-control" rows="5" placeholder="Paste student info text here..." style="margin-bottom:12px;"></textarea>
<div id="pastePreview" style="display:none;border:1px solid #dbeafe;background:#f8fbff;border-radius:10px;padding:14px;margin-bottom:12px;"></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" onclick="closePasteModal()">Cancel</button><button id="pasteExtractBtn" type="button" class="btn btn-primary" onclick="extractPaste()"><i class="fas fa-magic"></i> Extract</button><button id="pasteApplyBtn" type="button" class="btn btn-primary" style="display:none;" onclick="applyPaste()"><i class="fas fa-check"></i> Apply to Form</button></div></div></div>

<!-- Edit Modal (same structure) -->
<div class="modal-overlay" id="editModal"><div class="modal-content" style="max-width:760px;"><div class="modal-header"><h2><i class="fas fa-pen"></i> Edit Student</h2><button class="modal-close" onclick="closeEditModal()"><i class="fas fa-times"></i></button></div><form id="editForm"><input type="hidden" id="editId" value=""><div class="modal-body">
<div class="form-row"><div class="form-group" style="flex:0 0 160px;"><label>Student ID (Enrollment Dept)</label><input type="text" id="editStudentNumber" class="form-control" placeholder="Assigned by enrollment" style="font-size:12px;"></div><div class="form-group"><label>Academic Status</label><select id="editStatus" class="form-control"><option value="enrolled">Enrolled</option><option value="active">Active</option><option value="probation">Probation</option><option value="at-risk">At Risk</option><option value="graduated">Graduated</option><option value="loa">LOA</option><option value="transferred">Transferred</option><option value="dropped">Dropped</option><option value="archived">Archived</option></select></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:0 0 12px;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-user"></i> Personal Information</div>
<div class="form-row"><div class="form-group"><label>First Name <span style="color:#dc2626;">*</span></label><input type="text" id="editFirstName" class="form-control" required></div><div class="form-group"><label>Middle Name</label><input type="text" id="editMiddleName" class="form-control"></div><div class="form-group"><label>Last Name <span style="color:#dc2626;">*</span></label><input type="text" id="editLastName" class="form-control" required></div></div>
<div class="form-row"><div class="form-group"><label>Name Suffix</label><select id="editSuffix" class="form-control"><option value="">—</option><option value="Jr.">Jr.</option><option value="Sr.">Sr.</option><option value="II">II</option><option value="III">III</option><option value="IV">IV</option></select></div><div class="form-group"><label>LRN</label><input type="text" id="editLrn" class="form-control" placeholder="12-digit LRN" maxlength="12"></div><div class="form-group"><label>Gender</label><select id="editGender" class="form-control"><option value="">Select</option><option value="Male">Male</option><option value="Female">Female</option></select></div></div>
<div class="form-row"><div class="form-group"><label>Civil Status</label><select id="editCivilStatus" class="form-control"><option value="">Select</option><option value="Single">Single</option><option value="Married">Married</option><option value="Widowed">Widowed</option><option value="Separated">Separated</option></select></div><div class="form-group"><label>Birth Date</label><input type="date" id="editBirthDate" class="form-control"></div><div class="form-group"><label>Place of Birth</label><input type="text" id="editBirthPlace" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Nationality</label><input type="text" id="editNationality" class="form-control"></div><div class="form-group"><label>Religion</label><input type="text" id="editReligion" class="form-control"></div><div class="form-group"><label>Father's Name</label><input type="text" id="editFather" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Mother's Name</label><input type="text" id="editMother" class="form-control"></div></div>
<div class="form-row"><div class="form-group"><label>Email <span style="color:#dc2626;">*</span></label><input type="email" id="editEmail" class="form-control" required></div><div class="form-group"><label>Contact No. <span style="color:#dc2626;">*</span></label><input type="text" id="editContact" class="form-control" required pattern="09[0-9]{9}" title="11-digit mobile number (e.g. 09171234567)"></div></div>
<div class="form-row"><div class="form-group"><label>Address <span style="color:#dc2626;">*</span></label><textarea id="editAddress" class="form-control" rows="2" required></textarea></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:12px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-book"></i> Enrollment Details</div>
<div class="form-row"><div class="form-group" style="flex:1 1 220px;min-width:150px;"><label>Course</label><div class="course-select-wrap"><select id="editCourse" class="form-control"><option value="">Select course</option><?php foreach ($offeredCourses as $cname => $majors): ?><option value="<?= htmlspecialchars($cname) ?>"><?= htmlspecialchars($cname) ?></option><?php endforeach; ?></select><div class="course-select-list" style="display:none;"></div></div></div><div class="form-group" style="flex:0 0 150px;"><label>Year Level <span style="color:#dc2626;">*</span></label><select id="editYearLevel" class="form-control" required><option value="">Select</option><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div><div class="form-group" id="editMajorGroup" style="display:none;flex:1 1 200px;"><label>Major</label><select id="editMajor" class="form-control"><option value="">Select major</option></select></div></div>
<div class="form-row"><div class="form-group"><label>School Year</label><input type="text" id="editSchoolYear" class="form-control" placeholder="2026-2027"></div><div class="form-group"><label>Semester <span style="color:#dc2626;">*</span></label><select id="editSemester" class="form-control" required><option value="">—</option><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="summer">Summer</option></select></div></div>
<hr style="border:none;border-top:1px solid #f1f5f9;margin:12px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-users"></i> Guardian</div>
<div class="form-row"><div class="form-group"><label>Full Name</label><input type="text" id="editGuardianName" class="form-control"></div><div class="form-group"><label>Relationship</label><select id="editGuardianRel" class="form-control"><option value="">Select</option><option value="father">Father</option><option value="mother">Mother</option><option value="guardian">Guardian</option></select></div></div>
<div class="form-row"><div class="form-group"><label>Contact No. <span style="color:#dc2626;">*</span></label><input type="text" id="editGuardianContact" class="form-control" placeholder="09XXXXXXXXX" pattern="09[0-9]{9}" title="11-digit mobile number (e.g. 09171234567)"></div><div class="form-group"><label>Email</label><input type="email" id="editGuardianEmail" class="form-control"></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeEditModal()">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div></form></div></div>

<!-- Receive Student Modal (enrollment intake) -->
<div class="modal-overlay" id="receiveModal"><div class="modal-content" style="max-width:900px;"><div class="modal-header"><h2><i class="fas fa-inbox"></i> Receive Student</h2><button class="modal-close" onclick="closeReceiveModal()"><i class="fas fa-times"></i></button></div>
<div class="modal-body">
<p style="font-size:13px;color:#64748b;margin-bottom:12px;">Applicants from the Enrollment System. Run a <strong>Duplication Check</strong> first, then <strong>Accept</strong> (or <strong>Re-enroll</strong> for returning students).</p>
<div id="receiveTableWrap">
<p style="text-align:center;color:#94a3b8;padding:24px;"><i class="fas fa-spinner fa-spin"></i> Loading applicants...</p>
</div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" onclick="loadEnrollments()"><i class="fas fa-rotate"></i> Refresh</button><button class="btn btn-light" onclick="closeReceiveModal()"><i class="fas fa-times"></i> Close</button></div></div></div>

<script src="<?= $APP_ROOT ?>js/student-data-quality.js?v=<?= is_file(__DIR__ . '/../js/student-data-quality.js') ? filemtime(__DIR__ . '/../js/student-data-quality.js') : time() ?>"></script>
<script>
// ─── DATA ────────────────────────────────────────────────────
const searchInput = document.getElementById('studentSearch');
const searchClear = document.getElementById('searchClear');
const tableBody = document.getElementById('studentTableBody');
const showingCount = document.getElementById('showingCount');
const totalCount = document.getElementById('totalCount');
let allStudents = [];

// id → name map of advisers, injected from PHP
const ADVISER_MAP = <?= json_encode(array_column($advisers, 'full_name', 'id')) ?>;

document.querySelectorAll('#studentTableBody tr').forEach(row => {
    try {
        const data = JSON.parse(row.dataset.student);
        if (data) allStudents.push({ ...data, element: row });
    } catch(e) {}
});

// ─── SEARCH & FILTER ─────────────────────────────────────────
function updateTable(students) {
    allStudents.forEach(s => { if (s.element) s.element.style.display = 'none'; });
    let visible = 0;
    students.forEach(s => { if (s.element) { s.element.style.display = ''; visible++; } });
    showingCount.textContent = visible;
    const emptyState = document.getElementById('emptyState');
    const tblWrap = document.getElementById('studentTableWrap');
    if (visible === 0) {
        if (tblWrap) tblWrap.querySelector('table').style.display = 'none';
        if (emptyState) {
            emptyState.style.display = 'flex';
            emptyState.querySelector('p').textContent = 'No students found';
            emptyState.querySelector('span').textContent = (allStudents.length > 0) ? 'Try adjusting search or filters' : 'Add your first student to get started';
            emptyState.querySelector('i').className = (allStudents.length > 0) ? 'fas fa-search' : 'fas fa-user-graduate';
        }
    } else {
        if (tblWrap) tblWrap.querySelector('table').style.display = '';
        if (emptyState) emptyState.style.display = 'none';
    }
    updateBulkBar();
}

function performSearch() {
    const query = searchInput.value.trim().toLowerCase();
    const status = document.getElementById('filterStatus')?.value || '';
    const year = document.getElementById('filterYear')?.value || '';
    const course = document.getElementById('filterCourse')?.value || '';
    const section = document.getElementById('filterSection')?.value?.toLowerCase() || '';
    let filtered = allStudents;
    if (query) filtered = filtered.filter(s => (s.first_name||'').toLowerCase().includes(query)||(s.last_name||'').toLowerCase().includes(query)||(s.student_number||'').toLowerCase().includes(query)||(s.course||'').toLowerCase().includes(query));
    if (status) filtered = filtered.filter(s => s.status === status);
    if (year) filtered = filtered.filter(s => String(s.year_level) === year);
    if (course) filtered = filtered.filter(s => s.course === course);
    if (section) filtered = filtered.filter(s => (s.section||'').toLowerCase().includes(section));
    updateTable(filtered);
    searchClear.classList.toggle('visible', query.length > 0);
}
searchInput.addEventListener('input', performSearch);
searchClear.addEventListener('click', () => { searchInput.value = ''; performSearch(); });

// ─── FILTER MODAL ────────────────────────────────────────────
document.getElementById('filterToggle').addEventListener('click', () => { document.getElementById('filterModal').classList.add('active'); document.body.style.overflow = 'hidden'; });
function closeFilterModal() { document.getElementById('filterModal').classList.remove('active'); document.body.style.overflow = ''; }
function applyFilters() { performSearch(); closeFilterModal(); }
function clearFilters() { document.getElementById('filterStatus').value = ''; document.getElementById('filterYear').value = ''; document.getElementById('filterCourse').value = ''; document.getElementById('filterSection').value = ''; performSearch(); }
document.getElementById('filterModal').addEventListener('click', function(e) { if (e.target === this) closeFilterModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeFilterModal(); closeViewModal(); closeEditModal(); }});

// ─── CHECKBOX BULK ───────────────────────────────────────────
function toggleSelectAll() {
    const checked = document.getElementById('selectAll').checked;
    document.querySelectorAll('.student-cb').forEach(cb => cb.checked = checked);
    updateBulkBar();
}
function updateBulkBar() {
    const checked = document.querySelectorAll('.student-cb:checked').length;
    const bar = document.getElementById('bulkBar');
    document.getElementById('bulkCount').textContent = checked + ' selected';
    bar.classList.toggle('show', checked > 0);
}
// async because it awaits confirmAction(). A top-level `await` outside an
// async function is a SyntaxError, and one SyntaxError discards the WHOLE
// script block - which is why openReceiveModal, openAddModal and
// aiToolsPost were all reported as "not defined" even though they are
// defined. They never ran.
async function applyBulkAction() {
    const action = document.getElementById('bulkActionSelect').value;
    if (!action) { showToast('Select an action first.', 'warning'); return; }
    const ids = Array.from(document.querySelectorAll('.student-cb:checked')).map(cb => cb.value);
    if (!ids.length) return;
    if (!await confirmAction({
        title: 'Change status',
        body: 'Change the status of <strong>' + ids.length + ' student' + (ids.length === 1 ? '' : 's') +
              '</strong> to "' + escText(action) + '"?',
        confirmLabel: 'Change status',
        tone: action === 'inactive' || action === 'archived' ? 'danger' : 'primary'
    })) return;
    fetch('../api/students.php?action=bulk-status', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids, status: action })
    }).then(r => r.json()).then(d => { if (d.success) window.location.reload(); else showToast(d.message || 'Failed.', 'error'); }).catch(() => showToast('Network error.', 'error'));
}

// ─── VIEW MODAL (full profile) ──────────────────────────────
const viewModal = document.getElementById('viewModal');
var currentViewId = null;

function viewStudent(id) {
    currentViewId = id;
    fetch('../api/students.php?id=' + id).then(r => r.json()).then(d => {
        if (!d.success || !d.data) return;
        const s = d.data;
        const name = s.first_name + ' ' + s.last_name;
        const initials = (s.first_name||'')[0] + (s.last_name||'')[0];
        const colors = ['blue','green','purple','orange','pink'];
        const c = colors[Math.abs((s.first_name||'a').charCodeAt(0)) % colors.length];
        const avatarEl = document.getElementById('vAvatar');
        const avatarText = document.getElementById('vAvatarText');
        avatarEl.className = 'big-avatar ' + c;
        if (s.photo) { avatarEl.style.background = 'transparent'; avatarEl.style.backgroundImage = 'url('+s.photo+')'; avatarEl.style.backgroundSize = 'cover'; avatarText.style.display = 'none'; }
        else { avatarEl.style.backgroundImage = ''; avatarText.style.display = ''; avatarText.textContent = initials.toUpperCase(); }
        document.getElementById('vName').textContent = name;
        document.getElementById('vStudentId').textContent = s.student_number || 'ID not yet assigned';
        document.getElementById('vDbId').textContent = 'Record #' + s.id;
        document.getElementById('vStatus').innerHTML = '<span class="status-badge '+(s.status||'active')+'"><span class="status-dot '+(s.status||'active')+'"></span>'+ucfirst(s.status||'Active')+'</span>';
        document.getElementById('vLrn').textContent = s.lrn || '—';
        document.getElementById('vGender').textContent = s.gender || '—';
        document.getElementById('vCivilStatus').textContent = s.civil_status||'—';
        document.getElementById('vBirthDate').textContent = s.birth_date?new Date(s.birth_date).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'}):'—';
        document.getElementById('vBirthPlace').textContent = s.place_of_birth||'—';
        document.getElementById('vNationality').textContent = s.nationality||'—';
        document.getElementById('vReligion').textContent = s.religion||'—';
        document.getElementById('vCourse').textContent = s.course||'—';
        document.getElementById('vYearSection').textContent = (s.year_level?s.year_level+' Year':'')+(s.section?' — '+s.section:'');
        document.getElementById('vSchoolYearSem').textContent = (s.school_year?s.school_year:'—')+(s.semester?' — '+s.semester:'');
        document.getElementById('vAdviser').textContent = (s.adviser_id && ADVISER_MAP[s.adviser_id]) ? ADVISER_MAP[s.adviser_id] : '—';
        document.getElementById('vEmail').textContent = s.email||'—';
        document.getElementById('vContact').textContent = s.contact_number||'—';
        document.getElementById('vFather').textContent = s.father_name || '—';
        document.getElementById('vMother').textContent = s.mother_name || '—';
        document.getElementById('vAddress').textContent = s.address||'—';
        // Guardian
        fetch('../api/students.php?action=guardian&student_id='+s.id).then(r=>r.json()).then(gd=>{
            const gs=document.getElementById('vGuardianSection'),gi=document.getElementById('vGuardianInfo');
            if(gd.success&&gd.data){gs.style.display='block';gi.innerHTML=(gd.data.full_name||'')+(gd.data.relationship?' <span style=\"color:#94a3b8\">('+gd.data.relationship+')</span>':'')+'<br>'+(gd.data.contact_number?'<i class=\"fas fa-phone\" style=\"color:#94a3b8\"></i> '+gd.data.contact_number:'')+(gd.data.email?' <i class=\"fas fa-envelope\" style=\"color:#94a3b8\"></i> '+gd.data.email:'');}
        }).catch(()=>{});
        // Last scan
        fetch('../api/students.php?action=lastscan&student_id='+s.id).then(r=>r.json()).then(sd=>{
            const el=document.getElementById('vLastScan');
            if(sd.success&&sd.data){const ls=sd.data;const ei=ls.event_type==='entry'?'fa-right-to-bracket':ls.event_type==='exit'?'fa-right-from-bracket':'fa-circle';el.innerHTML='<span style=\"display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;\"><i class=\"fas '+ei+'\" style=\"color:'+(ls.event_type==='entry'?'#2563eb':'#b45309')+';\"></i> <strong>'+ucfirst(ls.event_type||'scan')+'</strong> <span style=\"color:#94a3b8\">·</span> '+(ls.scanned_at?new Date(ls.scanned_at).toLocaleString():'')+' <span style=\"color:#94a3b8\">·</span> '+(ls.location||'')+' <span class=\"status-badge '+(ls.status||'')+'\" style=\"font-size:10px;padding:1px 8px;\">'+ucfirst(ls.status||'')+'</span></span>';}
        }).catch(()=>{});
        // RFID
        const rfidSec = document.getElementById('vRfidSection');
        <?php if (!empty($rfidMap)): ?>
        const hasRfid = <?= json_encode(array_keys($rfidMap)) ?>.includes(String(s.id));
        <?php else: ?>
        const hasRfid = false;
        <?php endif; ?>
        if (hasRfid) { rfidSec.style.display = 'flex'; document.getElementById('vRfidLink').href = 'rfid-cards.php?search='+encodeURIComponent(s.student_number); document.getElementById('vScanLink').href = 'rfid-scan-logs.php?search='+encodeURIComponent(s.student_number); }
        else rfidSec.style.display = 'none';
        // Load other tabs
        loadDocuments(s.id);
        loadAcademic(s.id);
        loadHealth(s.id);
        // AI profile summary — non-blocking, cached, graceful on slowness.
        const aiSum = document.getElementById('vAiSummary');
        aiSum.style.display = 'block';
        aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span> <span style="color:#64748b;font-size:12px;margin-left:4px;"><i class="fas fa-spinner fa-spin"></i> Generating...</span>';
        let summaryTimedOut = false;
        const summaryTimer = setTimeout(() => {
            if (!summaryTimedOut) {
                aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span><p style="margin:6px 0 0;color:#94a3b8;">Summary is taking a moment — it will appear when ready.</p>';
            }
        }, 4000);
        aiToolsPost('profile', { id: s.id }).then(d => {
            clearTimeout(summaryTimer);
            summaryTimedOut = true;
            if (d.success && d.data && d.data.summary) {
                aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span><p style="margin:6px 0 0;color:#334155;">' + d.data.summary + '</p>';
                aiSum.style.display = 'block';
            } else {
                aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span><p style="margin:6px 0 0;color:#94a3b8;">AI summary unavailable for this student.</p>';
                aiSum.style.display = 'block';
            }
        }).catch(() => {
            clearTimeout(summaryTimer);
            summaryTimedOut = true;
            aiSum.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#3b82f6;"><i class="fas fa-brain"></i> AI Summary</span><p style="margin:6px 0 0;color:#94a3b8;">AI summary unavailable right now.</p>';
            aiSum.style.display = 'block';
        });
        // Reset to profile tab
        document.querySelectorAll('.vtab').forEach(t=>t.classList.remove('active'));
        document.querySelector('.vtab[data-tab="profile"]')?.classList.add('active');
        document.querySelectorAll('.vtab-content').forEach(t=>t.style.display='none');
        document.getElementById('tabProfile').style.display = '';
        viewModal.classList.add('active'); document.body.style.overflow = 'hidden';
    }).catch(() => showToast('Failed to load.', 'error'));
}

function switchVTab(btn, tab) {
    document.querySelectorAll('.vtab').forEach(t=>{t.style.borderBottomColor='transparent';t.style.color='#64748b'});
    btn.style.borderBottomColor='#2563eb';btn.style.color='#2563eb';
    document.querySelectorAll('.vtab-content').forEach(t=>t.style.display='none');
    document.getElementById('tab'+tab.charAt(0).toUpperCase()+tab.slice(1)).style.display='';
}

function loadDocuments(sid) {
    fetch('../api/students.php?action=documents&student_id='+sid).then(r=>r.json()).then(d=>{
        const el=document.getElementById('vDocuments');
        if(!d.success||!d.data||!d.data.length){el.innerHTML='<p style="color:#94a3b8;font-size:13px;">No document requests.</p>';return;}
        el.innerHTML='<table style="width:100%;font-size:12px;"><tr style="color:#64748b;font-weight:600;"><td>Type</td><td>Status</td><td>Date</td></tr>'+d.data.map(dr=>'<tr style="border-bottom:1px solid #f1f5f9;"><td>'+ucfirst(dr.document_type.replace('_',' '))+'</td><td><span class="status-badge '+(dr.status||'')+'" style="font-size:10px;">'+ucfirst(dr.status||'')+'</span></td><td>'+(dr.request_date?new Date(dr.request_date).toLocaleDateString():'')+'</td></tr>').join('')+'</table>';
    }).catch(()=>{});
}
function loadAcademic(sid) {
    fetch('../api/students.php?action=academic&student_id='+sid).then(r=>r.json()).then(d=>{
        const el=document.getElementById('vAcademic');
        if(!d.success||!d.data||!d.data.length){el.innerHTML='<p style="color:#94a3b8;font-size:13px;">No academic history found.</p>';return;}
        el.innerHTML='<table style="width:100%;font-size:12px;"><tr style="color:#64748b;font-weight:600;"><td>School</td><td>Year</td><td>GWA</td></tr>'+d.data.map(a=>'<tr style="border-bottom:1px solid #f1f5f9;"><td>'+a.school_name+'</td><td>'+(a.school_year||'')+'</td><td>'+(a.gwa||'—')+'</td></tr>').join('')+'</table>';
    }).catch(()=>{});
}
function loadHealth(sid) {
    fetch('../api/students.php?action=health&student_id='+sid).then(r=>r.json()).then(d=>{
        const el=document.getElementById('vHealth');
        if(!d.success||!d.data){el.innerHTML='<p style="color:#94a3b8;font-size:13px;">No health record.</p>';return;}
        const h=d.data;
        el.innerHTML='<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;"><div class="view-item"><div class="lbl">Blood Type</div><div class="val">'+(h.blood_type||'—')+'</div></div><div class="view-item"><div class="lbl">Height / Weight</div><div class="val">'+(h.height?h.height+'cm':'—')+' / '+(h.weight?h.weight+'kg':'—')+'</div></div><div class="view-item" style="grid-column:span 2;"><div class="lbl">Allergies</div><div class="val">'+(h.allergies||'None')+'</div></div><div class="view-item" style="grid-column:span 2;"><div class="lbl">Pre-existing Conditions</div><div class="val">'+(h.pre_existing_conditions||'None')+'</div></div></div>';
    }).catch(()=>{});
}
function uploadPhoto() {
    const input = document.getElementById('photoInput');
    if (!input.files[0] || !currentViewId) return;
    const fd = new FormData();
    fd.append('photo', input.files[0]);
    fd.append('student_id', currentViewId);
    fetch('../api/students.php?action=upload-photo', { method:'POST', body:fd })
    .then(r=>r.json()).then(d=>{ if(d.success) window.location.reload(); else showToast(d.message || 'Upload failed.', 'error'); })
    .catch(()=>showToast('Upload failed.', 'error'));
}
async function resendWelcomeEmail() {
    if (!currentViewId) return;
    const btn = document.getElementById('resendWelcomeBtn');
    if (!await confirmAction({
        title: 'Resend welcome email',
        body: 'Resend the portal welcome email? <strong>This resets the temporary password.</strong>',
        confirmLabel: 'Resend email'
    })) return;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...'; }
    try {
        const res = await fetch('../api/students.php?action=resend_welcome_email', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ student_id: currentViewId }) });
        const d = await res.json();
        if (d.success) showToast(d.message || 'Welcome email sent.', 'success');
        else showToast(d.message || 'Could not send the email.', 'error');
    } catch (e) { showToast('Network error.', 'error'); }
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-envelope"></i> Resend Welcome Email'; }
}

function closeViewModal() { viewModal.classList.remove('active'); document.body.style.overflow = ''; }
viewModal.addEventListener('click', function(e) { if (e.target === this) closeViewModal(); });

function printTable() {
    const w = window.open(); w.document.write('<html><head><style>table{width:100%;border-collapse:collapse}th,td{padding:8px;border:1px solid #ddd;text-align:left;font-size:12px}th{background:#f4f4f4}</style></head><body><h2>Student List</h2><table><tr><th>ID</th><th>Name</th><th>Course</th><th>Year</th><th>Status</th></tr>');
    allStudents.filter(s=>s.element&&s.element.style.display!=='none').forEach(s=>{
        w.document.write('<tr><td>'+(s.student_number||'')+'</td><td>'+(s.first_name||'')+' '+(s.last_name||'')+'</td><td>'+(s.course||'')+'</td><td>'+(s.year_level||'')+'</td><td>'+(s.status||'')+'</td></tr>');
    });
    w.document.write('</table></body></html>'); w.document.close(); w.print();
}

// ─── EDIT MODAL (full fields) ───────────────────────────────
function editStudent(id) {
    fetch('../api/students.php?id=' + id).then(r => r.json()).then(d => {
        if (!d.success || !d.data) return;
        const s = d.data;
        document.getElementById('editId').value = s.id;
        document.getElementById('editStudentNumber').value = s.student_number;
        document.getElementById('editStatus').value = s.status || 'active';
        document.getElementById('editFirstName').value = s.first_name;
        document.getElementById('editMiddleName').value = s.middle_name || '';
        document.getElementById('editLastName').value = s.last_name;
        document.getElementById('editSuffix').value = s.name_suffix || '';
        document.getElementById('editLrn').value = s.lrn || '';
        document.getElementById('editGender').value = s.gender || '';
        document.getElementById('editCivilStatus').value = s.civil_status || '';
        document.getElementById('editBirthDate').value = s.birth_date || '';
        document.getElementById('editBirthPlace').value = s.place_of_birth || '';
        document.getElementById('editNationality').value = s.nationality || '';
        document.getElementById('editReligion').value = s.religion || '';
        document.getElementById('editFather').value = s.father_name || '';
        document.getElementById('editMother').value = s.mother_name || '';
        document.getElementById('editCourse').value = s.course || '';
        refreshMajorOptions('edit');
        document.getElementById('editMajor').value = s.major || '';
        document.getElementById('editYearLevel').value = s.year_level || '';
        document.getElementById('editSchoolYear').value = s.school_year || '';
        document.getElementById('editSemester').value = s.semester || '';
        document.getElementById('editEmail').value = s.email || '';
        document.getElementById('editContact').value = s.contact_number || '';
        document.getElementById('editAddress').value = s.address || '';
        // Load guardian
        document.getElementById('editGuardianName').value = '';
        document.getElementById('editGuardianRel').value = '';
        document.getElementById('editGuardianContact').value = '';
        document.getElementById('editGuardianEmail').value = '';
        fetch('../api/students.php?action=guardian&student_id='+id).then(r=>r.json()).then(gd=>{
            if(gd.success&&gd.data){document.getElementById('editGuardianName').value=gd.data.full_name||'';document.getElementById('editGuardianRel').value=gd.data.relationship||'';document.getElementById('editGuardianContact').value=gd.data.contact_number||'';document.getElementById('editGuardianEmail').value=gd.data.email||'';}
        }).catch(()=>{});
        document.getElementById('editModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }).catch(() => showToast('Failed to load.', 'error'));
}
function closeEditModal() { document.getElementById('editModal').classList.remove('active'); document.body.style.overflow = ''; }
document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) closeEditModal(); });

document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const ec = document.getElementById('editContact').value;
    if (!ph11(ec)) { showToast('Student contact number is required and must be an 11-digit mobile number (e.g. 09171234567).', 'warning'); return; }
    const egn = document.getElementById('editGuardianName').value.trim();
    if (egn !== '' && !ph11(document.getElementById('editGuardianContact').value)) { showToast('Guardian contact number is required and must be an 11-digit mobile number (e.g. 09171234567).', 'warning'); return; }
    const ee = document.getElementById('editEmail').value.trim();
    if (!ee || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ee)) { showToast('Email is required and must be a valid address.', 'warning'); return; }
    const id = document.getElementById('editId').value;
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    try {
        const res = await fetch('../api/students.php?id=' + id, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                first_name: document.getElementById('editFirstName').value,
                middle_name: document.getElementById('editMiddleName').value,
                last_name: document.getElementById('editLastName').value,
                name_suffix: document.getElementById('editSuffix').value,
                lrn: document.getElementById('editLrn').value,
                father_name: document.getElementById('editFather').value,
                mother_name: document.getElementById('editMother').value,
                gender: document.getElementById('editGender').value,
                civil_status: document.getElementById('editCivilStatus').value,
                birth_date: document.getElementById('editBirthDate').value,
                place_of_birth: document.getElementById('editBirthPlace').value,
                nationality: document.getElementById('editNationality').value,
                religion: document.getElementById('editReligion').value,
                status: document.getElementById('editStatus').value,
                course: document.getElementById('editCourse').value,
                major: document.getElementById('editMajor').value || null,
                year_level: document.getElementById('editYearLevel').value,
                school_year: document.getElementById('editSchoolYear').value,
                semester: document.getElementById('editSemester').value,
                email: document.getElementById('editEmail').value,
                contact_number: document.getElementById('editContact').value,
                address: document.getElementById('editAddress').value,
                guardian_name: document.getElementById('editGuardianName').value,
                guardian_relationship: document.getElementById('editGuardianRel').value,
                guardian_contact: document.getElementById('editGuardianContact').value,
                guardian_email: document.getElementById('editGuardianEmail').value,
                student_number: document.getElementById('editStudentNumber').value
            })
        });
        const d = await res.json();
        if (d.success) { showToast('Student record updated.', 'success'); setTimeout(() => window.location.reload(), 800); }
        else { showToast(d.message || 'Failed to update.', 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes'; }
    } catch(e) { showToast('Network error.', 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes'; }
});

// ─── COURSE & MAJOR DROPDOWN (native select) ─────────────────
// Course data is rendered directly into the <select> options by PHP.
const COURSE_MAJORS = <?= json_encode($offeredCourses) ?>;

/**
 * Refresh the Major dropdown for a given prefix (add/edit).
 * Reads the selected course value from the native #<prefix>Course select.
 */
function refreshMajorOptions(prefix) {
    const course = document.getElementById(prefix + 'Course').value;
    const majorGroup = document.getElementById(prefix + 'MajorGroup');
    const majorEl = document.getElementById(prefix + 'Major');
    if (!majorGroup || !majorEl) return;
    const majors = (COURSE_MAJORS[course] || []);
    if (majors.length > 0) {
        majorGroup.style.display = '';
        majorEl.innerHTML = '<option value="">Select major</option>' +
            majors.map(m => '<option value="' + m.replace(/"/g, '&quot;') + '">' + m + '</option>').join('');
    } else {
        majorGroup.style.display = 'none';
        majorEl.innerHTML = '<option value="">Select major</option>';
    }
}

// Wire the course selects so changing the course refreshes the Major dropdown
['add', 'edit'].forEach(prefix => {
    const cEl = document.getElementById(prefix + 'Course');
    if (cEl) cEl.addEventListener('change', () => refreshMajorOptions(prefix));
});

// ─── Course select: scrollable custom list ────────────────────
// Builds a styled, scrollable option list (max ~5 rows) for the course
// <select>, syncs the hidden select value so form submission and the
// Major dropdown logic keep working unchanged.
['add', 'edit'].forEach(prefix => {
    const wrap = document.querySelector(`#${prefix}Modal .course-select-wrap`);
    const sel = document.getElementById(prefix + 'Course');
    const list = wrap ? wrap.querySelector('.course-select-list') : null;
    if (!wrap || !sel || !list) return;

    function renderOptions() {
        const opts = Array.from(sel.options);
        list.innerHTML = opts.map((o, i) =>
            '<div class="cs-option" data-idx="' + i + '">' +
              o.text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;') +
            '</div>'
        ).join('');
        list.querySelectorAll('.cs-option').forEach(opt => {
            opt.addEventListener('click', () => {
                const idx = parseInt(opt.dataset.idx, 10);
                sel.selectedIndex = idx;
                sel.dispatchEvent(new Event('change'));
                list.style.display = 'none';
            });
        });
    }
    function syncActive() {
        list.querySelectorAll('.cs-option').forEach(o =>
            o.classList.toggle('active', parseInt(o.dataset.idx, 10) === sel.selectedIndex));
    }

    renderOptions();

    // The select is the visual trigger — mousedown stops the native
    // dropdown from opening, then click toggles the styled list.
    sel.addEventListener('mousedown', e => e.preventDefault());
    sel.addEventListener('click', () => {
        const open = list.style.display === 'block';
        // close any other open course list
        document.querySelectorAll('.course-select-list').forEach(l => { if (l !== list) l.style.display = 'none'; });
        list.style.display = open ? 'none' : 'block';
        if (list.style.display === 'block') syncActive();
    });
    sel.addEventListener('change', () => { list.style.display = 'none'; refreshMajorOptions(prefix); });
    // Clicking outside closes it
    document.addEventListener('click', e => {
        if (!wrap.contains(e.target)) list.style.display = 'none';
    });
});

// ─── NOTE: Section is auto-generated by the Masterlist module
// ("Auto-assign sections"), so it is not a manual form field.

// ─── ADD MODAL ───────────────────────────────────────────────
function openAddModal() {
    document.getElementById('addModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    document.getElementById('addForm').reset();
    refreshMajorOptions('add');
}
function closeAddModal() { document.getElementById('addModal').classList.remove('active'); document.body.style.overflow = ''; }
document.getElementById('addModal').addEventListener('click', function(e) { if (e.target === this) closeAddModal(); });

function ph11(v) {
    let d = String(v || '').replace(/\D/g, '');
    if (d.length === 12 && d.startsWith('63')) d = '0' + d.slice(2);
    if (d.length === 13 && d.startsWith('63')) d = '0' + d.slice(2);
    return /^09\d{9}$/.test(d);
}

document.getElementById('addForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const ac = document.getElementById('addContact').value;
    if (!ph11(ac)) { showToast('Student contact number is required and must be an 11-digit mobile number (e.g. 09171234567).', 'warning'); return; }
    const agn = document.getElementById('addGuardianName').value.trim();
    if (agn !== '' && !ph11(document.getElementById('addGuardianContact').value)) { showToast('Guardian contact number is required and must be an 11-digit mobile number (e.g. 09171234567).', 'warning'); return; }
    const ae = document.getElementById('addEmail').value.trim();
    if (!ae || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ae)) { showToast('Email is required and must be a valid address.', 'warning'); return; }
    // A section code is built from year level + semester, so both are required.
    if (!document.getElementById('addYearLevel').value) { showToast('Year level is required.', 'warning'); return; }
    if (!document.getElementById('addSemester').value) { showToast('Semester is required.', 'warning'); return; }
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    try {
        const res = await fetch('../api/students.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                first_name: document.getElementById('addFirstName').value,
                middle_name: document.getElementById('addMiddleName').value,
                last_name: document.getElementById('addLastName').value,
                name_suffix: document.getElementById('addSuffix').value,
                lrn: document.getElementById('addLrn').value,
                father_name: document.getElementById('addFather').value,
                mother_name: document.getElementById('addMother').value,
                gender: document.getElementById('addGender').value,
                civil_status: document.getElementById('addCivilStatus').value,
                birth_date: document.getElementById('addBirthDate').value,
                place_of_birth: document.getElementById('addBirthPlace').value,
                nationality: document.getElementById('addNationality').value,
                religion: document.getElementById('addReligion').value,
                status: document.getElementById('addStatus').value,
                course: document.getElementById('addCourse').value,
                major: document.getElementById('addMajor').value || null,
                year_level: document.getElementById('addYearLevel').value,
                school_year: document.getElementById('addSchoolYear').value,
                semester: document.getElementById('addSemester').value,
                section: document.getElementById('addSection').value,
                email: document.getElementById('addEmail').value,
                contact_number: document.getElementById('addContact').value,
                address: document.getElementById('addAddress').value,
                guardian_name: document.getElementById('addGuardianName').value,
                guardian_relationship: document.getElementById('addGuardianRel').value,
                guardian_contact: document.getElementById('addGuardianContact').value,
                guardian_email: document.getElementById('addGuardianEmail').value
            })
        });
        const d = await res.json();
        if (d.success) {
            const acct = d.data && d.data.portal_account;
            if (acct) {
                // Show portal credentials in a modal so the registrar can
                // hand them to the student before reloading the page.
                document.getElementById('acctEmail').textContent = acct.username || acct.email;
                document.getElementById('acctPassword').textContent = acct.password;
                document.getElementById('acctEmailNote').style.display = acct.email_sent ? 'none' : '';
                document.getElementById('acctModal').classList.add('active');
                document.getElementById('addModal').classList.remove('active');
            } else {
                showToast('Student created successfully.', 'success');
                setTimeout(() => window.location.reload(), 800);
            }
        }
        else { showToast(d.message || 'Failed to add student.', 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Add Student'; }
    } catch(e) { showToast('Network error.', 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Add Student'; }
});

// ─── AUTO PORTAL ACCOUNT MODAL ───────────────────────────────
function closeAcctModal() {
    document.getElementById('acctModal').classList.remove('active');
    setTimeout(() => window.location.reload(), 300);
}
function copyAcct(id) {
    const el = document.getElementById(id);
    if (!el) return;
    navigator.clipboard.writeText(el.textContent.trim()).catch(() => {});
}

// ─── AI ASSIST ───────────────────────────────────────────────
async function aiPost(action, body) {
    const res = await fetch('../api/ai-assist.php?action=' + action, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
    return await res.json();
}

// Batch AI tools (data quality, standardization, duplicate scan) live
// in api/ai-tools.php, not ai-assist.php.
async function aiToolsPost(action, body) {
    const csrfMeta = document.querySelector('meta[name=csrf-token]');
    const headers = { 'Content-Type': 'application/json' };
    if (csrfMeta) headers['X-CSRF-Token'] = csrfMeta.getAttribute('content') || '';
    const res = await fetch('../api/ai-tools.php?action=' + action, {
        method: 'POST', headers: headers, body: JSON.stringify(body || {})
    });
    return await res.json();
}

// Document reader / paste-to-fill modal
function openPasteModal() {
    document.getElementById('pasteModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    document.getElementById('pasteText').value = '';
    document.getElementById('pasteFile').value = '';
    document.getElementById('pasteFileName').textContent = '';
    document.getElementById('pastePreview').style.display = 'none';
    document.getElementById('pasteApplyBtn').style.display = 'none';
    document.getElementById('pasteExtractBtn').style.display = '';
}
function closePasteModal() { document.getElementById('pasteModal').classList.remove('active'); document.body.style.overflow = ''; }
document.getElementById('pasteModal').addEventListener('click', function(e) { if (e.target === this) closePasteModal(); });

// Drag & drop dropzone
(function() {
    const dz = document.getElementById('pasteDropzone');
    const fileInput = document.getElementById('pasteFile');
    const nameEl = document.getElementById('pasteFileName');
    function showName() { nameEl.textContent = fileInput.files.length ? 'Selected: ' + fileInput.files[0].name : ''; }
    dz.addEventListener('click', function() { fileInput.click(); });
    fileInput.addEventListener('change', showName);
    ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, function(e) {
        e.preventDefault(); e.stopPropagation();
        dz.style.borderColor = '#2563eb'; dz.style.background = '#eef4ff';
    }));
    ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, function(e) {
        e.preventDefault(); e.stopPropagation();
        dz.style.borderColor = ''; dz.style.background = '';
    }));
    dz.addEventListener('drop', function(e) {
        const files = e.dataTransfer && e.dataTransfer.files;
        if (files && files.length) {
            fileInput.files = files;
            showName();
        }
    });
})();

let pasteData = null;
async function extractPaste() {
    const fileEl = document.getElementById('pasteFile');
    const text = document.getElementById('pasteText').value.trim();
    const hasFile = fileEl.files && fileEl.files.length > 0;
    if (!hasFile && !text) { showToast('Upload a file or paste some text first.', 'warning'); return; }
    const btn = document.getElementById('pasteExtractBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Extracting...';
    try {
        let d;
        if (hasFile) {
            const fd = new FormData();
            fd.append('file', fileEl.files[0]);
            const res = await fetch('../api/ai-assist.php?action=extract_doc', {
                method: 'POST', body: fd
            });
            d = await res.json();
        } else {
            d = await aiPost('paste_fill', { text });
        }
        if (!d.success) { showToast(d.message || 'Extraction failed.', 'error'); return; }
        pasteData = d.data || {};
        const keys = ['first_name','middle_name','last_name','gender','birth_date','place_of_birth','nationality','religion','email','contact_number','address','course','year_level','guardian_name','guardian_relationship'];
        let html = '<div style="font-size:12px;font-weight:700;color:#1e40af;margin-bottom:8px;">Extracted — review before applying</div>';
        let found = 0;
        keys.forEach(k => {
            const v = pasteData[k];
            if (v !== undefined && v !== null && v !== '') { found++; html += '<div style="font-size:13px;color:#334155;padding:2px 0;"><b style="color:#475569;display:inline-block;width:130px;">' + k.replace(/_/g,' ') + ':</b> ' + (typeof v === 'string' ? v : v) + '</div>'; }
        });
        if (found === 0) html += '<p style="color:#94a3b8;font-size:13px;">No fields recognized. Try more complete text.</p>';
        document.getElementById('pastePreview').innerHTML = html;
        document.getElementById('pastePreview').style.display = 'block';
        document.getElementById('pasteApplyBtn').style.display = '';
    } catch(e) { showToast('Extraction error: ' + e.message, 'error'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-magic"></i> Extract'; }
}

function applyPaste() {
    if (!pasteData) return;
    const map = {
        first_name: 'addFirstName', middle_name: 'addMiddleName', last_name: 'addLastName',
        gender: 'addGender', birth_date: 'addBirthDate', place_of_birth: 'addBirthPlace',
        nationality: 'addNationality', religion: 'addReligion', email: 'addEmail',
        contact_number: 'addContact', address: 'addAddress', course: 'addCourse',
        year_level: 'addYearLevel', guardian_name: 'addGuardianName', guardian_relationship: 'addGuardianRel'
    };
    for (const k in map) {
        const el = document.getElementById(map[k]);
        if (el && pasteData[k] !== undefined && pasteData[k] !== null && pasteData[k] !== '') {
            el.value = pasteData[k];
        }
    }
    refreshMajorOptions('add');
    // The year level may have been prefilled, which unlocks the section field.
    syncSectionAvailability();
    closePasteModal();
    showToast('Form pre-filled from extracted data.', 'success');
}

// Duplicate check on name blur (deterministic, no LLM)
// async: awaits confirmAction() before deciding whether to enroll.
async function checkDuplicateHint() {
    const fn = document.getElementById('addFirstName').value.trim();
    const ln = document.getElementById('addLastName').value.trim();
    const bd = document.getElementById('addBirthDate').value;
    if (!fn || !ln) return;
    aiPost('check_duplicate', { first_name: fn, last_name: ln, birth_date: bd })
    .then(async d => {
        if (d.success && d.data && d.data.length) {
            const hit = d.data[0];
            let msg = 'Possible duplicate: ' + hit.name + ' (' + (hit.student_number||'') + '). Enroll anyway?';
            let title = 'Possible duplicate';
            if (hit.score >= 0.9 && hit.birth_date === bd) {
                msg = 'Likely duplicate of ' + hit.name + ' (' + hit.student_number + ').';
                title = 'Likely duplicate';
            }
            await confirmAction({
                title: title,
                body: escText(msg),
                confirmLabel: 'Enroll anyway',
                tone: 'danger'
            });
        }
    }).catch(() => {});
}
document.getElementById('addFirstName').addEventListener('blur', checkDuplicateHint);
document.getElementById('addLastName').addEventListener('blur', checkDuplicateHint);

// Course auto-standardize on blur (deterministic)
// async: awaits confirmAction() before overwriting the course field.
async function standardizeCourse() {
    const el = document.getElementById('addCourse');
    const val = el.value.trim();
    if (!val) return;
    fetch('../api/ai-assist.php?action=suggest_field', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ field: 'course', value: val, context: 'student enrollment' })
    }).then(r => r.json()).then(async d => {
        if (d.success && d.data && d.data.suggested && d.data.suggested !== val) {
            const ok = await confirmAction({
                title: 'Standardize course',
                body: 'Standardize course to <strong>' + escText(d.data.suggested) + '</strong>?',
                confirmLabel: 'Standardize'
            });
            if (ok) {
                el.value = d.data.suggested;
                refreshMajorOptions('add');
            }
        }
    }).catch(() => {});
}
document.getElementById('addCourse').addEventListener('blur', standardizeCourse);

// Data Quality review behavior is defined in js/student-data-quality.js.

// ─── SECTION SUGGESTION ─────────────────────────────────────
// A section code encodes the year level, so the field stays locked until a
// year level is chosen. Clearing the year level clears the section too, so a
// stale code can't be submitted against a blank year.
function syncSectionAvailability() {
    const year = document.getElementById('addYearLevel').value;
    const section = document.getElementById('addSection');
    section.disabled = !year;
    if (!year) section.value = '';
    section.placeholder = year ? 'e.g. ' + year + '1001' : 'Set a year level first';
}
document.getElementById('addYearLevel').addEventListener('change', syncSectionAvailability);
document.getElementById('addForm').addEventListener('reset', syncSectionAvailability);
syncSectionAvailability();

function suggestSection() {
    const course = document.getElementById('addCourse').value;
    const year = document.getElementById('addYearLevel').value;
    const sem = document.getElementById('addSemester').value;
    if (!course || !year) { showToast('Choose a course and year level first.', 'warning'); return; }
    const btn = event.target.closest('button');
    if (btn) btn.disabled = true;
    aiPost('suggest_section', { course, year_level: year, semester: sem }).then(d => {
        if (d.success && d.data && d.data.suggestion) {
            document.getElementById('addSection').value = d.data.suggestion;
            showToast('Section ' + d.data.suggestion, 'success');
        } else {
            showToast(d.message || 'Could not suggest a section.', 'error');
        }
    }).catch(() => showToast('Error suggesting section.', 'error'))
      .finally(() => { if (btn) btn.disabled = false; });
}

// ─── GUARDIAN AUTO-FILL ─────────────────────────────────────
function guardianAutoFill() {
    const ln = document.getElementById('addLastName').value.trim();
    const g = document.getElementById('addGuardianName');
    if (!ln || g.value.trim()) return; // only fill if guardian name is empty
    // Common PH convention: guardian shares the student's surname.
    g.value = ln;
}
document.getElementById('addLastName').addEventListener('blur', guardianAutoFill);

// ─── QUICK STATUS ────────────────────────────────────────────
function toggleQuickMenu(id) { document.getElementById('qsm_'+id).classList.toggle('show'); }
document.addEventListener('click', e => { if (!e.target.closest('.quick-status-wrap')) document.querySelectorAll('.quick-status-menu').forEach(m => m.classList.remove('show')); });

function quickStatus(id, status) {
    fetch('../api/students.php?id=' + id, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status }) })
    .then(r => r.json()).then(d => { if (d.success) window.location.reload(); else showToast(d.message || 'Failed.', 'error'); }).catch(() => showToast('Error.', 'error'));
}

// ─── RESTORE ─────────────────────────────────────────────────
async function restoreStudent(id, name) {
    if (!await confirmAction({
        title: 'Restore student',
        body: 'Restore <strong>' + escText(name) + '</strong> to the active list?',
        confirmLabel: 'Restore'
    })) return;
    fetch('../api/students.php?id=' + id, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status: 'active' }) })
    .then(r => r.json()).then(d => { if (d.success) window.location.reload(); else showToast(d.message || 'Failed.', 'error'); }).catch(() => showToast('Error.', 'error'));
}

// ─── EXPORT ─────────────────────────────────────────────────
document.getElementById('exportBtn').addEventListener('click', function(e) {
    e.stopPropagation();
    document.getElementById('exportMenu').classList.toggle('show');
});
document.addEventListener('click', function() { document.getElementById('exportMenu').classList.remove('show'); });

function exportCSV() {
    exportStudents(allStudents);
}
function exportFiltered() {
    const visible = allStudents.filter(s => s.element && s.element.style.display !== 'none');
    exportStudents(visible);
}
function exportStudents(list) {
    let csv = "Student ID,Last Name,First Name,Middle Name,Course,Year Level,Section,Gender,Email,Contact,Status\n";
    list.forEach(s => {
        csv += (s.student_number||'')+','+(s.last_name||'')+','+(s.first_name||'')+','+(s.middle_name||'')+','+(s.course||'')+','+(s.year_level||'')+','+(s.section||'')+','+(s.gender||'')+','+(s.email||'')+','+(s.contact_number||'')+','+(s.status||'active')+'\n';
    });
    const blob = new Blob([csv], { type: 'text/csv' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'students_export.csv'; a.click();
    URL.revokeObjectURL(a.href);
}

function ucfirst(s) { return s.charAt(0).toUpperCase() + s.slice(1); }
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }
function fmtDate(v) {
    if (!v) return '—';
    const m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return '—';
    const d = new Date(+m[1], +m[2] - 1, +m[3]);
    return isNaN(d.getTime()) ? '—' : d.toLocaleDateString('en-US', {year:'numeric', month:'short', day:'numeric'});
}

// ─── RECEIVE STUDENT MODAL (enrollment intake) ──────────────
const receiveModal = document.getElementById('receiveModal');
const receiveWrap = document.getElementById('receiveTableWrap');
if (receiveModal) receiveModal.addEventListener('click', function(e) { if (e.target === this) closeReceiveModal(); });

function openReceiveModal() {
    receiveModal.classList.add('active');
    document.body.style.overflow = 'hidden';
    loadEnrollments();
}
function closeReceiveModal() {
    receiveModal.classList.remove('active');
    document.body.style.overflow = '';
}

async function enrollApi(action, body) {
    const res = await fetch('../api/enrollments.php?action=' + action, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {})
    });
    if (!res.ok) return { success: false, message: 'Server error (' + res.status + '). Please try again.' };
    return await res.json().catch(() => ({ success: false, message: 'Unexpected response from the server.' }));
}

async function loadEnrollments() {
    receiveWrap.innerHTML = '<p style="text-align:center;color:#94a3b8;padding:24px;"><i class="fas fa-spinner fa-spin"></i> Loading applicants...</p>';
    try {
        const res = await fetch('../api/enrollments.php?action=list');
        const d = await res.json().catch(() => null);
        if (!d || typeof d.success !== 'boolean') {
            receiveWrap.innerHTML = '<p style="text-align:center;color:#dc2626;padding:24px;">Unexpected response from the server. Please try again.</p>';
            return;
        }
        if (!d.success) { receiveWrap.innerHTML = '<p style="text-align:center;color:#dc2626;padding:24px;">' + (d.message || 'Failed to load.') + '</p>'; return; }
        const rows = d.data || [];
        if (!rows.length) {
            receiveWrap.innerHTML = '<div class="empty-state" style="display:flex;flex-direction:column;align-items:center;padding:40px 20px;"><i class="fas fa-inbox"></i><p>No applicants from the Enrollment System</p><span>Applicants will appear here when the Enrollment System sends them.</span></div>';
            return;
        }
        let html = '<div class="table-responsive"><table><thead><tr><th>Name</th><th>Enrollment No.</th><th>Sex</th><th>Birth Date</th><th>Course</th><th>Status</th><th style="text-align:center;">Actions</th></tr></thead><tbody>';
        rows.forEach(e => {
            const name = esc([e.first_name, e.middle_name, e.last_name, e.name_suffix].filter(Boolean).join(' '));
            const bd = fmtDate(e.birth_date);
            const statusBadge = e.status === 'pending' ? '<span class="status-badge active"><span class="status-dot active"></span>Pending</span>'
                : '<span class="status-badge ' + e.status + '"><span class="status-dot ' + e.status + '"></span>' + ucfirst(e.status) + '</span>';
            html += '<tr data-enrollment=\'' + JSON.stringify({ id: e.id, first_name: e.first_name, last_name: e.last_name, birth_date: e.birth_date, student_number: e.student_number }).replace(/'/g, '&#39;') + '\'>';
            html += '<td style="font-weight:600;color:#0f172a;font-size:13px;">' + name + '</td>';
            html += '<td style="font-family:\'JetBrains Mono\',monospace;font-size:12px;">' + esc(e.student_number || '—') + '</td>';
            html += '<td>' + esc(e.gender || '—') + '</td>';
            html += '<td>' + bd + '</td>';
            html += '<td>' + esc(e.course || '—') + '</td>';
            html += '<td>' + statusBadge + '</td>';
            html += '<td style="text-align:center;"><div class="action-group" style="justify-content:center;flex-wrap:wrap;gap:4px;">';
            html += '<button class="btn btn-secondary" style="height:30px;padding:0 12px;font-size:11px;" onclick="checkDuplicate(' + e.id + ')"><i class="fas fa-clone"></i> Duplicate Check</button>';
            if (e.status === 'pending') {
                html += '<button class="btn btn-primary" style="height:30px;padding:0 14px;font-size:11px;" onclick="acceptEnrollment(' + e.id + ')"><i class="fas fa-check"></i> Accept</button>';
                html += '<button class="btn btn-secondary" style="height:30px;padding:0 12px;font-size:11px;display:none;" id="reEnrollBtn_' + e.id + '" onclick="reenrollEnrollment(' + e.id + ')"><i class="fas fa-rotate"></i> Re-enroll</button>';
                html += '<button class="btn btn-secondary" style="height:30px;padding:0 12px;font-size:11px;display:none;" id="viewDupBtn_' + e.id + '" onclick="viewDuplicate(' + e.id + ')"><i class="fas fa-eye"></i> View Existing</button>';
            }
            html += '</div></td></tr>';
        });
        html += '</tbody></table></div>';
        receiveWrap.innerHTML = html;
    } catch (err) {
        receiveWrap.innerHTML = '<p style="text-align:center;color:#dc2626;padding:24px;">Failed to load applicants.</p>';
    }
}

// Dup-check state cache: enrollment_id → {exists, student}
const dupState = {};
async function checkDuplicate(id) {
    const btn = event && event.target && event.target.tagName === 'BUTTON' ? event.target : (event && event.currentTarget);
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...'; }
    try {
        const d = await enrollApi('duplicate-check', { enrollment_id: id });
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-clone"></i> Duplicate Check'; }
        if (!d.success) { showToast(d.message || 'Duplicate check failed.', 'error'); return; }
        dupState[id] = d;
        const reBtn = document.getElementById('reEnrollBtn_' + id);
        const viewBtn = document.getElementById('viewDupBtn_' + id);
        // Reveal the accept path when no duplicate exists
        const acceptBtn = Array.from(document.querySelectorAll('#receiveModal button')).find(b => b.getAttribute('onclick') === 'acceptEnrollment(' + id + ')');
        if (d.exists) {
            showToast('Student already exists.', 'info');
            if (reBtn) reBtn.style.display = 'inline-flex';
            if (viewBtn) viewBtn.style.display = 'inline-flex';
            if (acceptBtn) acceptBtn.style.display = 'none';
        } else {
            showToast('No existing record.', 'success');
            if (reBtn) reBtn.style.display = 'none';
            if (viewBtn) viewBtn.style.display = 'none';
            if (acceptBtn) acceptBtn.style.display = 'inline-flex';
        }
    } catch (err) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-clone"></i> Duplicate Check'; }
        showToast('Duplicate check failed.', 'error');
    }
}

async function acceptEnrollment(id) {
    if (!await confirmAction({
        title: 'Accept enrollment',
        body: 'Accept this student into the registrar records?',
        confirmLabel: 'Accept'
    })) return;
    try {
        const d = await enrollApi('accept', { enrollment_id: id });
        if (!d.success) { showToast(d.message || 'Failed.', 'error'); return; }
        const num = d.data && (d.data.student_number || '');
        showToast('Student accepted' + (num ? ' — ' + num : '') + '.', 'success');
        loadEnrollments();
    } catch (err) {
        showToast('Failed to accept student.', 'error');
    }
}

async function reenrollEnrollment(id) {
    const st = dupState[id] && dupState[id].student;
    const studentId = st && st.id;
    if (!studentId) { showToast('No existing record selected. Run Duplicate Check first.', 'error'); return; }
    if (!await confirmAction({
        title: 'Re-enroll student',
        body: 'Re-enroll this student with their existing student number? Their record will be updated rather than duplicated.',
        confirmLabel: 'Re-enroll'
    })) return;
    try {
        const d = await enrollApi('re-enroll', { enrollment_id: id, student_id: studentId });
        if (!d.success) { showToast(d.message || 'Failed.', 'error'); return; }
        showToast('Student re-enrolled successfully.', 'success');
        loadEnrollments();
    } catch (err) {
        showToast('Failed to re-enroll student.', 'error');
    }
}

function viewDuplicate(id) {
    const st = dupState[id] && dupState[id].student;
    if (st && st.id) viewStudent(st.id);
}

// ─── INIT ────────────────────────────────────────────────────
performSearch();
(function() {
    const params = new URLSearchParams(window.location.search);
    const success = params.get('success');
    if (!success) return;
    const msgs = { added: ['Student Added','Created successfully.'], updated: ['Student Updated','Record updated.'], archived: ['Student Deleted','Record archived.'] };
    if (msgs[success]) showToast(msgs[success][1], 'success');
    const url = new URL(window.location.href); url.searchParams.delete('success'); window.history.replaceState({}, '', url.toString());
})();
</script>
<?php include '../includes/footer.php'; ?>
