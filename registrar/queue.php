<?php
// ============================================================
//  REGISTRAR/QUEUE.PHP
//  Queue serving console (registrar/admin). Live view of the
//  waiting line, now-serving ticket, and history. Actions:
//  Call Next, Complete, Skip (absent / failed to comply).
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');

require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

$page_title = 'Queue';
$page_description = 'Live queue serving console for the registrar';
$APP_ROOT = '../';
$ACTIVE_NAV = 'queue';
$body_page = 'console';
$extra_css = ['queue.css'];
$page_scripts = ['queue.js'];

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
/* Queue console — registrar-blue system.
   Scoped to data-page="console" because queue.css is also loaded by the
   public kiosk and monitor screens, which must keep their own look. */
body[data-page="console"]{background:#f5f7fb;color:#0f172a}
body[data-page="console"] .dashboard-main{padding:24px clamp(18px,2.5vw,38px) 48px;background:linear-gradient(180deg,#eef4ff 0,#f8faff 300px,#f8faff 100%);min-height:auto}

/* ── Header ───────────────────────────────────────────── */
.q-head{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap;margin:0 0 16px;padding:25px 27px;border:1px solid #c7d7fe;border-radius:19px;background:linear-gradient(120deg,#eff6ff,#fff 68%);box-shadow:0 10px 30px rgba(37,99,235,.08)}
.q-kicker{display:flex;align-items:center;gap:7px;margin-bottom:7px;color:#1d4ed8;font-size:10.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.q-head h1{margin:0 0 5px;font-size:28px;line-height:1.1;letter-spacing:-.03em;color:#172554}
.q-head p{max-width:620px;margin:0;font-size:12.5px;line-height:1.5;color:#64748b}
.q-head .header-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
/* box-sizing matters here: the shared stylesheets have no global
   `* { box-sizing }` reset, and the UA sheet applies border-box to <button>
   but not to <a>. The two anchors below would otherwise be ~18px taller
   than they intend, since min-height would resolve against the content box. */
.q-head .header-actions .btn{box-sizing:border-box;min-height:36px;font-size:12px}
.q-window{display:flex;align-items:center;gap:8px;padding:7px 8px 7px 13px;border:1px solid #dbeafe;border-radius:11px;background:#fff}
.q-window label{font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:#1d4ed8;white-space:nowrap}
.q-window select{padding:4px 8px;border:1px solid #cbd5e1;border-radius:7px;background:#f8faff;color:#1e293b;font:600 13px Inter,sans-serif;cursor:pointer}
.q-window select:focus{outline:0;border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.1)}

/* ── Stat strip ───────────────────────────────────────── */
.q-strip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));margin:0 0 16px;background:#fff;border:1px solid #dbeafe;border-radius:16px;box-shadow:0 6px 22px rgba(15,23,42,.04);overflow:hidden}
.q-metric{position:relative;padding:18px 20px;border-right:1px solid #e2e8f0}
.q-metric:last-child{border-right:0}
.q-metric::after{content:"";position:absolute;left:20px;right:20px;bottom:0;height:3px;background:#dbeafe}
.q-metric.is-waiting::after{background:#d97706}
.q-metric.is-serving::after{background:#1d4ed8}
.q-metric.is-done::after{background:#16a34a}
.q-metric.is-noshow::after{background:#dc2626}
.q-metric .q-label{font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#64748b}
.q-metric .q-value{margin-top:6px;font-size:30px;font-weight:800;line-height:1;color:#0f172a;font-variant-numeric:tabular-nums}
.q-metric.is-serving .q-value{color:#1d4ed8}

/* ── Panels ───────────────────────────────────────────── */
body[data-page="console"] .panel{border:1px solid #dbeafe;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.045);margin-bottom:16px;overflow:hidden}
body[data-page="console"] .panel-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 18px;background:#f8faff;border-bottom:1px solid #e5e7eb}
body[data-page="console"] .panel-title{display:flex;align-items:center;gap:8px;padding:0;font-size:13px;font-weight:700;letter-spacing:-.01em;color:#1e293b}
body[data-page="console"] .panel-title i{color:#2563eb;font-size:12px}
body[data-page="console"] .table-responsive{margin:0}
body[data-page="console"] .table{margin:0;width:100%;border-collapse:collapse}
body[data-page="console"] .table thead th{padding:11px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#475569;font-size:10px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;text-align:left;white-space:nowrap}
body[data-page="console"] .table tbody td{padding:11px 14px;border-bottom:1px solid #f1f5f9;color:#1e293b;font-size:13px;vertical-align:middle}
body[data-page="console"] .table tbody tr:last-child td{border-bottom:0}
body[data-page="console"] .table tbody tr:hover{background:#eff6ff}
body[data-page="console"] .student-avatar.blue{background:#dbeafe;color:#1d4ed8}
body[data-page="console"] .chip.blue{background:#dbeafe;color:#1d4ed8}
body[data-page="console"] .action-btn.delete{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
body[data-page="console"] .action-btn.delete:hover{background:#dc2626;color:#fff}
body[data-page="console"] .empty-state{color:#94a3b8}
body[data-page="console"] td.empty-state{display:table-cell;height:300px;padding:0;vertical-align:middle;text-align:center}

/* ── Side-by-side: now serving + waiting line ──────────── */
.q-split{display:grid;grid-template-columns:minmax(0,0.9fr) minmax(0,1.5fr);gap:16px;align-items:start;margin-bottom:16px}
.q-split>.panel{margin-bottom:0;display:flex;flex-direction:column;min-width:0}

/* The waiting table scrolls rather than stretching the page. */
.q-queue-table{min-width:560px}
.q-split>.panel>.table-responsive{max-height:60vh;overflow-y:auto;overflow-x:auto}
.q-split>.panel>.table-responsive thead th{position:sticky;top:0;z-index:2}

/* The empty row is built by queue.js with an inline height; override it so
   it matches the serving panel instead of forcing 60vh. */
.q-queue-table .empty-state-row td{height:auto!important;padding:0}
.q-waiting-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;min-height:260px;padding:34px 20px;text-align:center}
.q-waiting-empty i{font-size:34px;color:#cbd5e1}
.q-waiting-empty p{margin:0;font-size:14px;font-weight:600;color:#64748b}
.q-waiting-empty span{font-size:12.5px;color:#94a3b8;max-width:32ch}

/* ── Now serving: the call board ──────────────────────── */
.q-serving{display:grid;grid-template-columns:1fr;align-items:center;gap:18px;padding:20px}
.q-ticket{position:relative;display:grid;place-items:center;padding:20px 10px 18px;border:1px solid #bfdbfe;border-radius:15px;background:linear-gradient(150deg,#eff6ff,#fff 70%)}
.q-ticket::before{content:"";position:absolute;left:12px;right:12px;top:10px;height:2px;background:repeating-linear-gradient(90deg,#c7d7fe 0 6px,transparent 6px 12px)}
.q-ticket-no{font:800 clamp(40px,5vw,56px)/1 JetBrains Mono,ui-monospace,monospace;letter-spacing:-.04em;color:#1d4ed8;font-variant-numeric:tabular-nums}
.q-ticket-cap{margin-top:9px;font-size:9.5px;font-weight:800;letter-spacing:.11em;text-transform:uppercase;color:#64748b}
.q-who{min-width:0;text-align:center}
.q-who-name{font-size:23px;font-weight:700;letter-spacing:-.02em;line-height:1.15;color:#0f172a;overflow-wrap:anywhere}
.q-who-meta{margin:7px 0 0;font-size:12.5px;color:#64748b;overflow-wrap:anywhere}
.q-when{margin:4px 0 0;font-size:12px;color:#94a3b8}
.q-window-badge{display:inline-flex;margin-top:11px;padding:5px 11px;border:1px solid #c7d7fe;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:800;letter-spacing:.04em}
.q-serving-actions{display:flex;gap:9px;margin-top:16px;flex-wrap:wrap;justify-content:center}
.q-serving-actions .btn{flex:1 1 auto;min-height:40px;padding:0 18px;font-size:13px}
.q-idle{display:grid;place-items:center;gap:5px;padding:38px 20px;text-align:center}
.q-idle i{font-size:30px;color:#cbd5e1}
.q-idle p{margin:0;font-size:15px;font-weight:600;color:#64748b}
.q-idle span{font-size:12.5px;color:#94a3b8}
.q-idle .btn{margin-top:14px;min-height:40px;padding:0 20px;font-size:13px}

/* ── Responsive ───────────────────────────────────────── */
@media(max-width:1000px){.q-strip{grid-template-columns:repeat(2,minmax(0,1fr))}.q-metric:nth-child(2){border-right:0}.q-metric:nth-child(-n+2){border-bottom:1px solid #e2e8f0}.q-metric::after{display:none}}
@media(max-width:980px){.q-split{grid-template-columns:1fr}}
@media(max-width:820px){.q-head{flex-direction:column;align-items:flex-start}.q-head .header-actions{width:100%;justify-content:flex-start}}
@media(max-width:560px){.q-head{padding:21px 18px}.q-head h1{font-size:25px}.q-head .header-actions{flex-direction:column;align-items:stretch}.q-head .btn{justify-content:center}.q-window{justify-content:center}.q-strip{grid-template-columns:1fr}.q-metric{border-right:0;border-bottom:1px solid #e2e8f0}.q-metric:last-child{border-bottom:0}.q-ticket-no{font-size:44px}.q-who-name{font-size:21px}.q-serving-actions .btn{flex:1 1 100%}}
</style>
<main class="dashboard-main">
<div class="dashboard-container">

<header class="q-head">
    <div>
        <div class="q-kicker"><i class="fas fa-bullhorn"></i> Registrar console</div>
        <h1>Queue</h1>
        <p>Call tickets at the window, clear the line, and keep a record of everyone served today.</p>
    </div>
    <div class="header-actions">
        <div class="q-window">
            <label for="windowSelect">Window</label>
            <select id="windowSelect">
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
            </select>
        </div>
        <a href="../queue/monitor.php" target="_blank" class="btn btn-secondary"><i class="fas fa-tv"></i> Open Monitor</a>
        <a href="../queue/kiosk.php" target="_blank" class="btn btn-secondary"><i class="fas fa-credit-card"></i> Open Kiosk</a>
    </div>
</header>

<!-- Counts -->
<div class="q-strip">
    <div class="q-metric is-waiting">
        <div class="q-label">Waiting</div>
        <div class="q-value" id="stat-waiting">0</div>
    </div>
    <div class="q-metric is-serving">
        <div class="q-label">Now serving</div>
        <div class="q-value" id="stat-serving">0</div>
    </div>
    <div class="q-metric is-done">
        <div class="q-label">Completed today</div>
        <div class="q-value" id="stat-completed">0</div>
    </div>
    <div class="q-metric is-noshow">
        <div class="q-label">No-shows</div>
        <div class="q-value" id="stat-no_show">0</div>
    </div>
</div>

<!-- All windows at a glance -->
<div class="win-strip" id="winStrip"></div>

<!-- Serving + waiting, side by side -->
<div class="q-split">

<!-- Now serving -->
<div class="panel" id="nowServingBody">
    <div class="panel-toolbar">
        <div class="panel-title"><i class="fas fa-bullhorn"></i> Now Serving <span class="q-kicker-inline" id="nsWinLabel"></span></div>
    </div>

    <div id="nsEmpty" class="q-idle">
        <i class="fas fa-circle-notch"></i>
        <p>No one at the window</p>
        <span>Call the next ticket to start serving</span>
        <button class="btn btn-primary" id="btnCallNext"><i class="fas fa-forward"></i> Call Next</button>
    </div>

    <div id="nsContent" style="display:none">
        <div class="q-serving">
            <div class="q-ticket">
                <div class="q-ticket-no" id="nsNumber">000</div>
                <div class="q-ticket-cap">Ticket</div>
            </div>
            <div class="q-who">
                <div class="q-who-name" id="nsName">&mdash;</div>
                <div class="q-who-meta">ID <span id="nsNumber2">&mdash;</span> &middot; <span id="nsCourse">&mdash;</span></div>
                <div class="q-when">Called <span id="nsElapsed">&mdash;</span> ago</div>
                <div style="display:none;"><span class="q-window-badge" id="nsWindow">Window 1</span></div>
                <div class="q-serving-actions">
                    <button class="btn btn-success" id="nsComplete"><i class="fas fa-check"></i> Complete</button>
                    <button class="btn btn-danger" id="nsSkip"><i class="fas fa-forward"></i> Skip</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Waiting line -->
<div class="panel">
    <div class="panel-toolbar">
        <div class="panel-title"><i class="fas fa-list-ol"></i> Waiting Line</div>
    </div>
    <div class="table-responsive" style="overflow-x:auto;">
    <table class="table q-queue-table">
        <thead><tr>
            <th>#</th><th>Number</th><th>Student</th><th>ID No.</th><th>Course</th><th>Joined</th><th style="text-align:center;">Action</th>
        </tr></thead>
        <tbody id="waitingBody">
            <tr class="empty-state-row"><td colspan="7"><div class="q-waiting-empty"><i class="fas fa-people-group"></i><p>No students waiting</p><span>Tickets appear here when students tap at the kiosk</span></div></td></tr>
        </tbody>
    </table>
    </div>
</div>

</div>

<!-- HISTORY -->
<div class="panel">
    <div class="panel-toolbar">
        <div class="panel-title"><i class="fas fa-clock-rotate-left"></i> Today's History</div>
    </div>
    <div class="table-responsive" style="overflow-x:auto;">
    <table class="table">
        <thead><tr><th>Number</th><th>Student</th><th>Status</th><th>Served At</th></tr></thead>
        <tbody id="completedBody">
            <tr><td colspan="4" class="empty-state"><i class="fas fa-inbox"></i><p>Nothing served yet today</p></td></tr>
        </tbody>
    </table>
    </div>
</div>

</div>
</main>

<!-- Skip Confirm Modal -->
<div class="modal-overlay" id="skipModal">
    <div class="modal-content" style="max-width:420px;"><div class="modal-header"><h2><i class="fas fa-forward"></i> Skip Ticket</h2><button class="modal-close" onclick="window.queueCloseSkip()"><i class="fas fa-times"></i></button></div>
    <div class="modal-body">
        <p style="font-size:14px;color:#64748b;margin:0 0 14px;" id="skipMsg">Mark <strong id="skipName">—</strong> as no-show and call the next ticket?</p>
        <p style="font-size:12px;color:#94a3b8;margin:0;">Use this when the student is not present or failed to comply within 5 minutes.</p>
    </div>
    <div class="modal-footer">
        <button class="btn btn-light" id="skipCancel">Cancel</button>
        <button class="btn btn-danger" id="skipConfirm"><i class="fas fa-forward"></i> Skip &amp; Next</button>
    </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
