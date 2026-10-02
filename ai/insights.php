<?php
// ============================================================
//  AI/INSIGHTS.PHP
//  Intelligent Analytics and Reports
//
//  The core AI function of the registrar system:
//    structured dashboard → historical comparison → AI interpretation
//    → printable executive report.
//
//  Layout reuses the dashboard's own classes (css/dashboard.css is
//  loaded globally by includes/header.php) so the two pages stay
//  visually identical: .header/.title, .stats-grid/.stat-card,
//  .chart-grid/.chart-card.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/analytics.php';

// Registrar analytics is staff work — student and nurse accounts are
// bounced the same way requireRole() does it, just with the extra roles
// this page allows (admin, registrar, staff).
if (!in_array(getCurrentUserRole(), aiInsightRoles(), true)) {
    header('Location: ../dashboard.php?error=access_denied');
    exit;
}

$period      = aiInsightPeriodFromRequest();
$build       = aiInsightBuild($period);
$cards       = $build['cards'];
$charts      = $build['charts'];
$currentYear = (int) date('Y');

// Trend badge glyph per direction.
$trendIcon = ['up' => 'fa-arrow-up', 'down' => 'fa-arrow-down', 'flat' => 'fa-minus'];

$page_title = 'Intelligent Analytics and Reports';
$body_page = 'insights';
$APP_ROOT   = '../';
$ACTIVE_NAV = 'insights';
// Footer wiring: Chart.js CDN + this page's frontend logic.
$use_chart = true;
// bcp-letterhead.js first: insights.js calls BCPPrint.printDocument() at
// click time, but load order still matters for clarity and for any future
// top-level call. footer.php cache-busts each by mtime.
$page_scripts = ['bcp-letterhead.js', 'insights.js'];

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
/* ============================================================
   Intelligent Analytics — registrar-blue layer.
   Mirrors registrar/guardians.php and registrar/academic-history.php:
   same header, same connected metric strip, same panel/toolbar band.
   Scoped to body[data-page="insights"].

   The chart series colours come from shared/analytics.php and are
   shared with the dashboard, so they are deliberately NOT redefined
   here. Only the neutral chrome (type, grid, tick colour) is aligned.
   ============================================================ */
body[data-page="insights"]{background:#f5f7fb;color:#0f172a}

/* ── Page header ───────────────────────────────── */
body[data-page="insights"] .header{
    display:flex;align-items:flex-end;justify-content:space-between;gap:20px;
    flex-wrap:wrap;margin:0 0 16px;padding:25px 27px;
    border:1px solid #c7d7fe;border-radius:19px;
    background:linear-gradient(120deg,#eff6ff,#fff 68%);
    box-shadow:0 10px 30px rgba(37,99,235,.08);
}
body[data-page="insights"] .header .title h1{
    margin:0 0 5px;font-size:28px;line-height:1.1;letter-spacing:-.03em;color:#172554;
}
body[data-page="insights"] .header .title h1 i{display:none}
body[data-page="insights"] .header .title p{
    margin:0;max-width:620px;font-size:12.5px;line-height:1.5;color:#64748b;
}
body[data-page="insights"] .ins-kicker{
    display:flex;align-items:center;gap:7px;margin-bottom:7px;color:#1d4ed8;
    font-size:10.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;
}
body[data-page="insights"] .ins-kicker i{font-size:11px}
body[data-page="insights"] .header-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}

/* ── Reporting Period control ─────────────────────── */
body[data-page="insights"] .period-control{
    display:flex;align-items:center;gap:8px;background:#fff;
    border:1px solid #e2e8f0;border-radius:12px;padding:6px 10px;
    box-shadow:0 1px 3px rgba(15,23,42,.04);
}
body[data-page="insights"] .period-control label{
    font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;
    color:#64748b;display:flex;align-items:center;gap:6px;white-space:nowrap;margin:0;
}
body[data-page="insights"] .period-control select{
    border:1.5px solid #e2e8f0;border-radius:8px;padding:6px 10px;font-size:13px;
    font-family:inherit;color:#1e293b;background:#fff;outline:none;cursor:pointer;
}
body[data-page="insights"] .period-control select:focus{
    border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);
}
body[data-page="insights"] .period-control.is-busy{opacity:.6;pointer-events:none}

/* ── KPI metric strip ─────────────────────────────
   Same connected strip as the other registrar pages. The trend
   badge sits in the reserved top slot and the breakdown sits
   under a hairline; the per-card tone colour becomes the inset
   accent bar, which is what the icon tile used to carry (the
   tile is gone, so the strip reads as one unit).
   The inner class names are kept as-is: js/insights.js
   renderCards() queries .stat-value/.stat-label/.stat-trend/
   .stat-footer/.dot and the [data-card] attribute. */
body[data-page="insights"] .stats-grid.ins-stats{
    display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:0;
    margin:0 0 16px;background:#fff;border:1px solid #dbeafe;border-radius:16px;
    box-shadow:0 6px 22px rgba(15,23,42,.04);overflow:hidden;
}
body[data-page="insights"] .ins-stat{
    position:relative;background:transparent;border:0;border-radius:0;
    padding:17px 20px 15px;border-right:1px solid #e2e8f0;
    box-shadow:none;transition:none;margin:0;
}
body[data-page="insights"] .ins-stat:last-child{border-right:0}
body[data-page="insights"] .ins-stat::after{
    content:"";position:absolute;left:20px;right:20px;bottom:0;height:3px;background:#dbeafe;
}
body[data-page="insights"] .ins-stat:hover{
    transform:none;box-shadow:none;border-color:transparent;background:transparent;
}
body[data-page="insights"] .ins-stat.tone-blue::after{background:#1d4ed8}
body[data-page="insights"] .ins-stat.tone-green::after{background:#16a34a}
body[data-page="insights"] .ins-stat.tone-yellow::after{background:#d97706}
body[data-page="insights"] .ins-stat.tone-purple::after{background:#7c3aed}
body[data-page="insights"] .ins-stat .stat-top{
    display:flex;align-items:center;justify-content:space-between;gap:8px;
    margin:0 0 6px;min-height:20px;
}
body[data-page="insights"] .ins-stat .stat-trend{
    display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;
    font-size:10.5px;font-weight:700;line-height:1.5;white-space:nowrap;
}
body[data-page="insights"] .ins-stat .stat-trend i{font-size:9px}
body[data-page="insights"] .ins-stat .stat-trend.up{color:#15803d;background:#dcfce7}
body[data-page="insights"] .ins-stat .stat-trend.down{color:#b91c1c;background:#fee2e2}
body[data-page="insights"] .ins-stat .stat-trend.flat{color:#64748b;background:#f1f5f9}
body[data-page="insights"] .ins-stat .stat-value{
    font-size:28px;font-weight:800;line-height:1.1;color:#0f172a;
    font-variant-numeric:tabular-nums;
}
body[data-page="insights"] .ins-stat .stat-label{
    color:#64748b;font-size:10px;font-weight:800;letter-spacing:.07em;
    text-transform:uppercase;margin-top:2px;line-height:1.3;
}
body[data-page="insights"] .ins-stat .stat-footer{
    display:flex;align-items:center;gap:6px;margin-top:11px;padding-top:9px;
    border-top:1px solid #f1f5f9;font-size:11px;color:#64748b;line-height:1.4;
}
body[data-page="insights"] .ins-stat .stat-footer .dot{width:6px;height:6px;flex:0 0 6px;border-radius:50%}
body[data-page="insights"] .ins-stat .stat-footer .dot.green{background:#16a34a}
body[data-page="insights"] .ins-stat .stat-footer .dot.purple{background:#7c3aed}
body[data-page="insights"] .ins-stat .stat-footer .dot.blue{background:#2563eb}
body[data-page="insights"] .ins-stat .stat-footer .dot.yellow{background:#d97706}
body[data-page="insights"] .ins-stat .stat-footer .dot.red{background:#dc2626}
body[data-page="insights"] .ins-stat .stat-footer-text{
    font-size:11px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}

/* ── Section divider ────────────────────────────── */
body[data-page="insights"] .ai-section-title{
    display:flex;align-items:center;gap:14px;margin:22px 0 14px;
}
body[data-page="insights"] .ai-section-title span{
    font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;
    color:#64748b;white-space:nowrap;
}
body[data-page="insights"] .ai-section-title::before,
body[data-page="insights"] .ai-section-title::after{content:'';height:1px;background:#e2e8f0;flex:1}
/* Left-aligned label + trailing rule only; the leading rule pushed the
   label away from the panel edge it describes. */
body[data-page="insights"] .ai-section-title::before{display:none}

/* ── Chart cards ──────────────────────────────────
   The card header becomes the same #f8faff band the list
   pages use as a toolbar, so charts and lists share a spine. */
body[data-page="insights"] .chart-card{
    background:#fff;border:1px solid #dbeafe;border-radius:16px;
    box-shadow:0 6px 22px rgba(15,23,42,.04);overflow:hidden;padding:0;
}
body[data-page="insights"] .chart-card:hover{
    transform:none;box-shadow:0 6px 22px rgba(15,23,42,.04);border-color:#dbeafe;
}
body[data-page="insights"] .chart-card .card-header{
    display:flex;align-items:flex-start;justify-content:space-between;gap:14px;
    /* dashboard.css sets flex-wrap:wrap + margin-bottom on this element.
       Left alone, the period badge wraps to a second line inside the
       narrow 1fr column and the stale 22px margin opens a gap under the
       band. Pin the header to one row and let the title shrink instead. */
    flex-wrap:nowrap;margin:0;
    padding:15px 20px;background:#f8faff;border-bottom:1px solid #e2e8f0;
}
body[data-page="insights"] .chart-card .card-header > div{min-width:0}
body[data-page="insights"] .chart-card .card-title{
    display:flex;align-items:center;gap:9px;font-size:15px;font-weight:800;
    color:#0d1b2e;letter-spacing:-.3px;margin:0;
}
body[data-page="insights"] .chart-card .card-title i{color:#2563eb;font-size:14px}
body[data-page="insights"] .chart-card .card-subtitle{
    margin:4px 0 0;font-size:11.5px;color:#64748b;line-height:1.45;
}
body[data-page="insights"] .chart-card .card-badge{
    flex:0 0 auto;padding:3px 10px;border-radius:999px;background:#e0ecff;
    color:#1d4ed8;font-size:11px;font-weight:800;letter-spacing:0;
    font-variant-numeric:tabular-nums;white-space:nowrap;
}
body[data-page="insights"] .chart-card .card-body{padding:18px 20px 20px;background:#fff}
/* Eight ranked rows need more room than the shared 280px default. */
body[data-page="insights"] .ai-program-body{height:330px}
body[data-page="insights"] .ai-program-empty{
    display:none;flex-direction:column;align-items:center;justify-content:center;
    height:100%;text-align:center;padding:0 16px;
}
body[data-page="insights"] .ai-program-empty.is-empty{display:flex}
body[data-page="insights"] .ai-program-empty i{font-size:26px;color:#cbd5e1;margin-bottom:11px}
body[data-page="insights"] .ai-program-empty p{margin:0 0 5px;font-size:13.5px;font-weight:600;color:#64748b}
body[data-page="insights"] .ai-program-empty span{
    font-size:11.5px;color:#94a3b8;line-height:1.5;max-width:290px;
}
body[data-page="insights"] .chart-grid{gap:16px}
/* Cards size to their own content; a short card stretched to match a
   taller neighbour reads as a broken panel with dead space inside it. */
body[data-page="insights"] .chart-grid{align-items:start}
body[data-page="insights"] .chart-grid.pie-first{grid-template-columns:1fr 2fr}

/* ── RFID card: doughnut + activity trend stacked ── */
body[data-page="insights"] .ai-rfid-body{height:auto !important;display:flex;flex-direction:column;gap:18px}
body[data-page="insights"] .ai-mini-chart{position:relative;height:150px}
body[data-page="insights"] .ai-mini-label{
    font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;
    color:#64748b;margin-bottom:8px;
}
body[data-page="insights"] .ai-empty-note{font-size:12px;color:#94a3b8;text-align:center;padding:16px 8px}

/* ── AI ANALYSIS REPORT ──
   The report is a printable artifact with its own section colours, so it
   is left largely alone — only the shell is brought into the system. */
body[data-page="insights"] .ai-report-card .card-body{height:auto}
body[data-page="insights"] .ai-report-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
body[data-page="insights"] .ai-report-empty{text-align:center;padding:48px 20px;color:#94a3b8}
body[data-page="insights"] .ai-report-empty i{font-size:34px;color:#cbd5e1;display:block;margin-bottom:12px}
body[data-page="insights"] .ai-report-empty p{margin:0 0 4px;font-weight:600;color:#64748b;font-size:14px}
body[data-page="insights"] .ai-report-empty span{font-size:12px}
body[data-page="insights"] .ai-report-loading{display:none;text-align:center;padding:48px 20px}
body[data-page="insights"] .ai-report-loading .spinner{
    width:38px;height:38px;border:3px solid #e2e8f0;border-top-color:#2563eb;
    border-radius:50%;animation:ai-spin .8s linear infinite;margin:0 auto 12px;
}
@keyframes ai-spin{to{transform:rotate(360deg)}}
body[data-page="insights"] .ai-report-error{
    display:none;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;
    border-radius:12px;padding:12px 16px;font-size:13px;margin-bottom:14px;
}
body[data-page="insights"] .ai-report-meta{
    display:none;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;
    font-size:12px;color:#64748b;
}
body[data-page="insights"] .ai-source-badge{
    font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;
    background:#ecfdf5;color:#047857;
}
body[data-page="insights"] .ai-source-badge.is-fallback{background:#fef3c7;color:#b45309}
body[data-page="insights"] .ai-report-output{font-size:14px;line-height:1.7;color:#1e293b}
body[data-page="insights"] .ai-report-output .ai-report-title{
    font-size:15px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;
    color:#0f172a;margin:0 0 14px;display:flex;align-items:center;gap:9px;
}
body[data-page="insights"] .ai-report-output .ai-report-title i{color:#7c3aed}
body[data-page="insights"] .report-section{
    background:#fff;border:1px solid #e2e8f0;border-radius:14px;
    padding:14px 18px 12px;margin-bottom:12px;
}
body[data-page="insights"] .report-section-head{display:flex;align-items:center;gap:10px;margin-bottom:4px}
body[data-page="insights"] .report-section-num{
    width:24px;height:24px;flex:0 0 24px;border-radius:7px;background:#2563eb;
    color:#fff;font-size:12px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;
}
body[data-page="insights"] .report-section-2 .report-section-num{background:#0d9488}
body[data-page="insights"] .report-section-3 .report-section-num{background:#7c3aed}
body[data-page="insights"] .report-section h2{
    font-size:13px;font-weight:700;color:#0f172a;margin:0;text-transform:uppercase;letter-spacing:.3px;
}
body[data-page="insights"] .report-section ul{margin:4px 0 2px;padding-left:18px}
body[data-page="insights"] .report-section li{margin-bottom:6px}
body[data-page="insights"] .report-section p{margin:6px 0 2px}
body[data-page="insights"] .report-section strong{color:#0f172a}
body[data-page="insights"] .ai-report-footer{
    display:none;font-size:11px;color:#94a3b8;text-align:center;
    padding:12px 12px 0;border-top:1px dashed #e2e8f0;margin-top:16px;
}
body[data-page="insights"] .ai-report-footer strong{color:#64748b}

/* ── Export dropdown (mirrors the masterlist export menu) ── */
body[data-page="insights"] .ai-export-wrap{position:relative}
body[data-page="insights"] .ai-export-menu{
    display:none;position:absolute;top:100%;right:0;z-index:50;background:#fff;
    border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 12px 30px rgba(15,23,42,.12);
    min-width:180px;padding:5px;margin-top:6px;
}
body[data-page="insights"] .ai-export-menu a{
    display:block;padding:8px 12px;font-size:12px;font-weight:600;
    color:#1e293b;text-decoration:none;border-radius:7px;
}
body[data-page="insights"] .ai-export-menu a:hover{background:#f1f5f9}
body[data-page="insights"] .ai-export-menu.is-open{display:block}

@media (max-width:1200px){
    body[data-page="insights"] .stats-grid.ins-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
    body[data-page="insights"] .ins-stat:nth-child(2n){border-right:0}
    body[data-page="insights"] .ins-stat:nth-child(-n+2){border-bottom:1px solid #e2e8f0}
}
@media (max-width:1100px){
    body[data-page="insights"] .chart-grid.pie-first{grid-template-columns:1fr}
}
@media (max-width:640px){
    body[data-page="insights"] .stats-grid.ins-stats{grid-template-columns:1fr}
    body[data-page="insights"] .ins-stat{border-right:0}
    body[data-page="insights"] .ins-stat + .ins-stat{border-bottom:1px solid #e2e8f0}
    body[data-page="insights"] .header{padding:21px 18px}
    body[data-page="insights"] .header-actions{width:100%}
    body[data-page="insights"] .period-control{width:100%;justify-content:space-between}
    body[data-page="insights"] .period-control select{flex:1;min-width:0}
    body[data-page="insights"] .chart-card .card-header{flex-direction:column;gap:10px}
}
@media (prefers-reduced-motion:reduce){
    body[data-page="insights"] .ins-stat{transition:none}
    body[data-page="insights"] .chart-card{transition:none}
}
</style>

<main class="dashboard-main">
    <div class="dashboard-container">

        <!-- ── Page header ─────────────────────────────────────── -->
        <header class="header">
            <div class="title">
                <div class="ins-kicker"><i class="fa-solid fa-chart-line"></i> Registrar intelligence</div>
                <h1><i class="fas fa-chart-line" style="color:#2563eb;margin-right:8px;"></i>Intelligent Analytics and Reports</h1>
                <p>AI-assisted interpretation of registrar data for the selected reporting period</p>
            </div>
            <div class="header-actions">
                <div class="period-control" id="periodControl">
                    <label for="reportMonth"><i class="fas fa-calendar-days"></i> Reporting Period</label>
                    <select id="reportMonth" aria-label="Reporting month">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m === $period['month'] ? 'selected' : '' ?>>
                                <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <select id="reportYear" aria-label="Reporting year">
                        <?php for ($y = $currentYear; $y >= $currentYear - 4; $y--): ?>
                            <option value="<?= $y ?>" <?= $y === $period['year'] ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="button" class="btn btn-primary" id="generateBtn">
                    <i class="fas fa-wand-magic-sparkles"></i> Generate AI Insight
                </button>
            </div>
        </header>

        <!-- ── KPI metric strip ────────────────────────────────────
             Connected strip, same as the other registrar pages. The
             icon tile is gone: the tone colour now rides the inset
             accent bar, so the four cells read as one unit.
             Class names inside are unchanged because
             js/insights.js renderCards() targets them. -->
        <div class="stats-grid ins-stats dashboard-section" id="statCards">
            <?php foreach ($cards as $card): ?>
                <div class="ins-stat tone-<?= htmlspecialchars($card['tone']) ?>" data-card="<?= htmlspecialchars($card['key']) ?>">
                    <div class="stat-top">
                        <span class="stat-trend <?= htmlspecialchars($card['badge']['dir']) ?>" title="Compared with <?= htmlspecialchars($period['prev_label']) ?>">
                            <i class="fas <?= $trendIcon[$card['badge']['dir']] ?? 'fa-minus' ?>"></i>
                            <span class="stat-trend-text"><?= htmlspecialchars($card['badge']['text']) ?></span>
                        </span>
                    </div>
                    <div class="stat-value"><?= number_format((int) $card['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($card['label']) ?></div>
                    <div class="stat-footer">
                        <span class="dot <?= htmlspecialchars($card['footer']['dot']) ?>"></span>
                        <span class="stat-footer-text"><?= htmlspecialchars($card['footer']['text']) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ── Student Population Overview ─────────────────────── -->
        <div class="ai-section-title"><span>Student Population Overview</span></div>

        <!-- 1 & 2 · Student status + program distribution -->
        <div class="chart-grid pie-first dashboard-section">
            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-chart-pie"></i> Student Status Distribution</div>
                        <div class="card-subtitle">Active, enrolled, alumni, graduate, dropped, transferred</div>
                    </div>
                    <span class="card-badge" id="statusBadge"><?= htmlspecialchars($period['label']) ?></span>
                </div>
                <div class="card-body"><canvas id="statusChart"></canvas></div>
            </div>
            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-th-large"></i> Student Program Distribution</div>
                        <div class="card-subtitle">Ranked by enrollment — the dark cap marks students who arrived this period</div>
                    </div>
                    <span class="card-badge" id="programBadge">Top 8</span>
                </div>
                <div class="card-body ai-program-body">
                    <canvas id="programChart"></canvas>
                    <div class="ai-program-empty" id="programEmptyNote">
                        <i class="fas fa-graduation-cap"></i>
                        <p>No students on record for this period</p>
                        <span>Program counts appear here once students are assigned a program.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3 & 4 · Document transactions + RFID -->
        <div class="chart-grid dashboard-section">
            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-file-lines"></i> Document Transaction Overview</div>
                        <div class="card-subtitle">Requests by document type and workflow stage</div>
                    </div>
                    <span class="card-badge" id="docBadge"><?= htmlspecialchars($period['label']) ?></span>
                </div>
                <div class="card-body"><canvas id="docChart"></canvas></div>
            </div>
            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-id-card"></i> RFID Overview</div>
                        <div class="card-subtitle" id="rfidSubtitle">Card lifecycle and activity</div>
                    </div>
                    <span class="card-badge" id="rfidBadge">12 Months</span>
                </div>
                <div class="card-body ai-rfid-body">
                    <div>
                        <div class="ai-mini-label">Cards by status</div>
                        <div class="ai-mini-chart">
                            <canvas id="rfidChart"></canvas>
                            <div class="ai-empty-note" id="rfidEmptyNote" style="display:none;">No RFID cards issued yet.</div>
                        </div>
                    </div>
                    <div>
                        <div class="ai-mini-label" id="rfidTrendLabel">Activity — last 12 months</div>
                        <div class="ai-mini-chart"><canvas id="rfidTrendChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── AI ANALYSIS REPORT ──────────────────────────────── -->
        <div class="chart-card ai-report-card dashboard-section">
            <div class="card-header">
                <div>
                    <div class="card-title"><i class="fas fa-robot" style="color:#7c3aed;"></i> AI ANALYSIS REPORT</div>
                    <div class="card-subtitle">Generate an AI-assisted analysis of the registrar data.</div>
                </div>
                <div class="ai-report-actions">
                    <button type="button" class="btn btn-secondary" id="printBtn" style="display:none;">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <div class="ai-export-wrap">
                        <button type="button" class="btn btn-secondary" id="exportBtn" style="display:none;">
                            <i class="fas fa-download"></i> Export <i class="fas fa-caret-down" style="margin-left:4px;"></i>
                        </button>
                        <div class="ai-export-menu" id="exportMenu">
                            <a href="#" id="exportPdf"><i class="fas fa-file-pdf"></i> Export PDF</a>
                            <a href="#" id="exportCsv"><i class="fas fa-file-csv"></i> Export CSV</a>
                            <a href="#" id="exportTxt"><i class="fas fa-file-lines"></i> Export TXT</a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="ai-report-error" id="reportError"></div>

                <div class="ai-report-meta" id="reportMeta">
                    <span class="ai-source-badge" id="reportSourceBadge">AI-generated</span>
                    <span id="reportMetaText"></span>
                </div>

                <div class="ai-report-empty" id="reportEmpty">
                    <i class="fas fa-chart-simple"></i>
                    <p>No analysis generated yet</p>
                    <span>Choose a reporting period, then select Generate AI Insight.</span>
                </div>

                <div class="ai-report-loading" id="reportLoading">
                    <div class="spinner"></div>
                    <p style="margin:0;font-size:13px;color:#64748b;" id="reportLoadingText">Analysing registrar data…</p>
                </div>

                <div class="ai-report-output" id="reportOutput" style="display:none;"></div>

                <div class="ai-report-footer" id="reportFooter">
                    Generated by: <strong>Registrar Information System</strong><br>
                    AI-generated information is provided for administrative reference.
                </div>
            </div>
        </div>

        <!-- Period + chart payload for js/insights.js (first paint is server-rendered) -->
        <script type="application/json" id="insightsData"><?= json_encode([
            'period'    => [
                'month'      => $period['month'],
                'year'       => $period['year'],
                'label'      => $period['label'],
                'prev_label' => $period['prev_label'],
            ],
            'cards'     => $cards,
            'charts'    => $charts,
            'endpoints' => [
                'data'   => '../api/ai-insights-data.php',
                'report' => '../api/ai-insights-report.php',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

    </div><!-- /.dashboard-container -->
</main>

<?php include '../includes/footer.php'; ?>
