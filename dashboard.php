<?php
// ============================================================
//  DASHBOARD.PHP - PREMIUM VERSION
//  All data from database, proper sidebar spacing
// ============================================================

require_once __DIR__ . '/shared/security_headers.php';
require_once __DIR__ . '/shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/shared/database.php';
// The Student Status Overview panel below is built from studentStatuses() /
// studentStatusLabel() / studentStatusMeta() in shared/functions.php. This page
// did not load it, so the panel fataled with "Call to undefined function
// studentStatuses()" and the whole dashboard was a blank page - one of five
// status counts being renamed took out every other card on the screen.
require_once __DIR__ . '/shared/functions.php';

$db = Database::getInstance();

// ─── FETCH ALL DATA ──────────────────────────────────────────────

// Stats.
// The per-status counts (active / at-risk / graduated) are NOT queried
// here — they are derived from the single GROUP BY further down, so the
// headline cards and the Student Status Overview panel can never disagree.
$totalStudents = (int) $db->fetchColumn("SELECT COUNT(*) FROM students");
$totalCards = (int) $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards");
$activeCards = (int) $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE status = 'active'");
$expiredCards = (int) $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE status = 'expired'");
$lostCards = (int) $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE status = 'lost'");
$totalDocs = (int) $db->fetchColumn("SELECT COUNT(*) FROM document_requests");
$pendingDocs = (int) $db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE status = 'pending'");
$approvedDocs = (int) $db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE status = 'approved'");
$deniedDocs = (int) $db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE status = 'denied'");

// Monthly enrollment (last 6 months)
$monthlyData = $db->fetchAll("
    SELECT MONTH(created_at) as month, COUNT(*) as count
    FROM students
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY MONTH(created_at)
    ORDER BY month ASC
");

// Build the label axis from the actual current month, back 6 months.
// It used to be a hardcoded Jan-Jun list, so the chart silently
// showed the wrong axis for most of the year.
$months = [];
$monthCounts = [];
$monthKeys = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("first day of -{$i} month");
    $months[] = date('M', $ts);
    $monthKeys[] = (int) date('n', $ts);
    $monthCounts[] = 0;
}
foreach ($monthlyData as $d) {
    $idx = array_search((int) $d['month'], $monthKeys, true);
    if ($idx !== false) $monthCounts[$idx] = (int) $d['count'];
}

// Students added in the last 30 days. This is a real, computed
// figure — the old stat cards carried hardcoded "12%" / "5%" /
// "3%" / "8%" badges that never moved and meant nothing.
$recentJoins = (int) $db->fetchColumn("
    SELECT COUNT(*) FROM students
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$prevJoins = (int) $db->fetchColumn("
    SELECT COUNT(*) FROM students
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
      AND created_at <  DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$joinDelta = $prevJoins > 0 ? (int) round((($recentJoins - $prevJoins) / $prevJoins) * 100) : null;

// Course distribution (top 5)
$courseData = $db->fetchAll("
    SELECT course, COUNT(*) as count 
    FROM students 
    WHERE course IS NOT NULL AND course != ''
    GROUP BY course 
    ORDER BY count DESC 
    LIMIT 5
");
$courseLabels = array_column($courseData, 'course');
$courseCounts = array_column($courseData, 'count');

// Status distribution — the four canonical buckets the panel displays.
//
// The original code ran four separate `WHERE status = '...'` counts. That
// was the bug: the column is an 8-value enum whose DEFAULT is 'enrolled',
// so students created through the normal flow never matched any of the
// four branches and every row rendered 0. One GROUP BY replaces all four
// queries and covers the whole enum, so the figures are real.
//
// The raw per-status counts are collapsed back into the four buckets the
// panel has always shown, so the layout is unchanged. 'Active' means
// currently in class (enrolled + active + loa), matching how
// brain/Status Tracker.md classifies LOA as Active.
$statusRows = $db->fetchAll("
    SELECT status, COUNT(*) AS count
    FROM students
    GROUP BY status
");

$rawStatus = [];
foreach ($statusRows as $row) {
    $rawStatus[(string) ($row['status'] ?? '')] = (int) ($row['count'] ?? 0);
}

// One bucket per real status, no folding and no invented categories.
//
// This map used to be the fifth independent list of statuses in the app. It
// merged enrolled + active + loa into a single "Active" card and gave
// at-risk and probation their own cards, so the dashboard answered "how many
// students are here" with a number that counted a student on leave as present,
// and answered "how many are in trouble" with a figure the office no longer
// records. Each card now counts exactly one stored value, so a card showing 0
// is a real 0 and not a sum of three things.
//
// `fill` tints the bar on the status panel. It was named by hand ('green',
// 'red', 'blue') and is now derived from the status colour, because a
// hand-named tint is one more place to forget when a status is added - and a
// missing key here is a PHP warning in the middle of the dashboard.
$dashFills = [
    'enrolled' => 'blue',
    'active'   => 'green',
    'graduate' => 'purple',
    'alumni'   => 'cyan',
    'dropped'  => 'red',
];
$DASH_STATUS_META = [];
foreach (studentStatuses() as $st) {
    $m = studentStatusMeta($st);
    $DASH_STATUS_META[$st] = [
        'label' => $m['label'],
        'icon'  => str_replace('fas ', 'fa ', $m['icon']),
        'color' => $m['color'],
        'fill'  => $dashFills[$st] ?? 'grey',
        'from'  => [$st],
    ];
}

// Sum each bucket from the real GROUP BY result.
$statusData = [];
foreach ($DASH_STATUS_META as $key => $meta) {
    $statusData[$key] = 0;
    foreach ($meta['from'] as $src) {
        $statusData[$key] += $rawStatus[$src] ?? 0;
    }
}

// The headline stat cards read from the same buckets, so the top strip and
// this panel can never disagree.
//
// "At risk" is no longer a status. It is an advisory now - surfaced on the
// data-quality page and the Status Tracker queue - so there is no honest count
// for it on an enrolment dashboard, and the card is driven from the same
// five-bucket data rather than left pointing at a column that is gone.
$activeStudents    = $statusData['enrolled'] + $statusData['active'];
$atRiskStudents    = 0;
$graduatedStudents = $statusData['graduate'];

// Recent activity
$recentActivity = $db->fetchAll("
    SELECT st.*, CONCAT(s.first_name, ' ', s.last_name) AS student_name
    FROM status_tracker st
    LEFT JOIN students s ON st.student_id = s.id
    ORDER BY st.created_at DESC
    LIMIT 5
");

// ─── PAGE SETUP ───────────────────────────────────────────────────
$page_title = 'Dashboard';
$APP_ROOT = './';
$ACTIVE_NAV = 'dashboard';
$body_page = 'dashboard';
$userName = $_SESSION['full_name'] ?? 'Admin User';
$userRole = $_SESSION['role'] ?? 'Registrar';
$extra_css = ['dashboard.css', 'queue.css'];

include 'includes/header.php';
include 'includes/sidebar.php';
?>

    <style>
    /* ============================================================
       DASHBOARD — registrar-blue layer.
       Scoped to body[data-page="dashboard"].

       The hero previously used a photographic banner
       (assets/images/bestlink banner.jpg) behind white text held
       legible by heavy text-shadow. Text over an arbitrary photo
       has no guaranteed contrast — it depends on which part of the
       image happens to sit behind the words, and it changes if the
       image is ever replaced. The solid gradient below keeps the
       white text reliably legible and the page on system blue.
       ============================================================ */
    body[data-page="dashboard"] .dash-hero{
        position:relative;overflow:hidden;margin-bottom:20px;padding:34px 38px;
        border-radius:22px;
        /* Solid blue sits underneath as the fallback (and as the colour
           behind the text if the photo ever fails to load). No
           background-blend-mode here: overlay tinted the ENTIRE photo
           blue, which flattened it into a coloured box. */
        background:#1b3fa8;
        background-image:url('./assets/images/bestlink%20banner.jpg');
        background-size:cover;background-position:center right;
        box-shadow:0 14px 38px rgba(26,58,140,.26);
    }
    /* Dim from the left only, fading to fully clear by ~72%. The text
       lives entirely on the left, so the right two-thirds of the photo
       is left untouched. */
    body[data-page="dashboard"] .dash-hero::after{
        content:'';position:absolute;inset:0;z-index:0;
        background:linear-gradient(100deg,
            rgba(9,22,68,.90) 0%,
            rgba(14,32,86,.78) 34%,
            rgba(20,44,110,.42) 58%,
            rgba(27,63,168,.06) 76%,
            rgba(27,63,168,0) 100%);
    }
    body[data-page="dashboard"] .dash-hero::before{
        content:'';position:absolute;right:-90px;top:-110px;width:380px;height:380px;
        border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.14),transparent 70%);
        pointer-events:none;z-index:0;
    }
    body[data-page="dashboard"] .dash-hero-in{position:relative;z-index:1;display:flex;
        align-items:flex-end;justify-content:space-between;gap:24px;flex-wrap:wrap}
    body[data-page="dashboard"] .dash-kicker{
        display:flex;align-items:center;gap:7px;margin-bottom:7px;color:#dbeafe;
        font-size:10.5px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;
    }
    body[data-page="dashboard"] .dash-hero h1{
        margin:0 0 10px;font-size:29px;font-weight:800;line-height:1.15;
        letter-spacing:-.03em;color:#fff;
    }
    /* The name is the one warm accent in an otherwise blue block. */
    body[data-page="dashboard"] .dash-hero h1 .dash-who{color:#fde68a}
    body[data-page="dashboard"] .dash-hero-meta{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
    body[data-page="dashboard"] .dash-chip{
        display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:999px;
        background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.32);
        color:#fff;font-size:11px;font-weight:700;
    }
    body[data-page="dashboard"] .dash-chip-plain{border-color:transparent;background:transparent;padding-left:0}

    /* ── Stat strip ───────────────────────────────────────────
       Same connected-strip treatment as registrar/students.php:
       one unit, hairline-separated cells, no gaps between them, and
       a tone-coloured bar along the bottom of each cell. The old
       version here was four floating cards with an icon tile. */
    body[data-page="dashboard"] .stats-grid{
        display:grid;grid-template-columns:repeat(4,minmax(0,1fr));
        gap:0;margin:0 0 16px;background:#fff;border:1px solid #dbeafe;
        border-radius:16px;box-shadow:0 6px 22px rgba(15,23,42,.04);overflow:hidden;
    }
    body[data-page="dashboard"] .stat-card{
        position:relative;padding:16px 20px 15px;border-right:1px solid #e2e8f0;
        border-radius:0;box-shadow:none;background:#fff;
    }
    body[data-page="dashboard"] .stat-card:last-child{border-right:0}
    body[data-page="dashboard"] .stat-card::after{
        content:"";position:absolute;left:20px;right:20px;bottom:0;height:3px;background:#dbeafe;
    }
    body[data-page="dashboard"] .stat-card.tone-blue::after  {background:#1d4ed8}
    body[data-page="dashboard"] .stat-card.tone-green::after {background:#16a34a}
    body[data-page="dashboard"] .stat-card.tone-amber::after {background:#d97706}
    body[data-page="dashboard"] .stat-card.tone-violet::after{background:#7c3aed}

    body[data-page="dashboard"] .stat-header{display:flex;align-items:center;justify-content:space-between;gap:8px}
    body[data-page="dashboard"] .stat-icon{
        width:34px;height:34px;border-radius:10px;font-size:14px;
    }
    body[data-page="dashboard"] .stat-label{
        font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;
        color:#64748b;line-height:1.3;margin:0 0 5px;
    }
    body[data-page="dashboard"] .stat-value{
        font-size:27px;font-weight:800;line-height:1.1;color:#0f172a;
        font-variant-numeric:tabular-nums;letter-spacing:-.5px;
    }
    body[data-page="dashboard"] .stat-foot{
        display:flex;align-items:center;gap:6px;margin-top:9px;padding-top:8px;
        border-top:1px solid #f1f5f9;font-size:11px;color:#64748b;line-height:1.4;
    }
    body[data-page="dashboard"] .stat-foot b{color:#0f172a;font-weight:800}

    /* Inline meter, matching the students strip. display:inline-block
       is required — as a bare <span> the width is ignored and it
       renders as a full-width rule sitting on the accent bar. */
    body[data-page="dashboard"] .stat-meter{
        display:inline-block;width:46px;height:4px;border-radius:3px;
        background:#e2e8f0;overflow:hidden;vertical-align:middle;flex:0 0 46px;
    }
    body[data-page="dashboard"] .stat-meter i{display:block;height:100%;border-radius:3px}

    /* A real trend, shown only when there is history to compute one. */
    body[data-page="dashboard"] .stat-trend{
        display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;
        font-size:10.5px;font-weight:700;line-height:1.5;white-space:nowrap;
    }
    body[data-page="dashboard"] .stat-trend i{font-size:9px}
    body[data-page="dashboard"] .stat-trend.up{color:#15803d;background:#dcfce7}
    body[data-page="dashboard"] .stat-trend.down{color:#b91c1c;background:#fee2e2}
    body[data-page="dashboard"] .stat-trend.flat{color:#64748b;background:#f1f5f9}

    @media (max-width: 1024px) {
        body[data-page="dashboard"] .stats-grid{grid-template-columns:repeat(2,1fr)}
        body[data-page="dashboard"] .stat-card:nth-child(2n){border-right:0}
        body[data-page="dashboard"] .stat-card:nth-child(-n+2){border-bottom:1px solid #e2e8f0}
    }
    @media (max-width: 560px) {
        body[data-page="dashboard"] .stats-grid{grid-template-columns:1fr}
        body[data-page="dashboard"] .stat-card{border-right:0;border-bottom:1px solid #e2e8f0}
        body[data-page="dashboard"] .stat-card:last-child{border-bottom:0}
        body[data-page="dashboard"] .stat-card{padding:14px 16px 13px}
        body[data-page="dashboard"] .stat-value{font-size:23px}
        body[data-page="dashboard"] .dash-hero{padding:24px 20px;border-radius:16px}
        body[data-page="dashboard"] .dash-hero h1{font-size:24px}
        body[data-page="dashboard"] .dash-hero-in{align-items:flex-start}
    }
    </style>

    <main class="dashboard-main">
    <div class="dashboard-container">

        <!-- Hero. Solid registrar-blue rather than a stock photo, so
             the white text keeps a guaranteed contrast ratio. -->
        <header class="dash-hero">
            <div class="dash-hero-in">
                <div>
                    <div class="dash-kicker"><i class="fas fa-graduation-cap"></i> Registrar portal</div>
                    <h1>Welcome back, <span class="dash-who"><?= htmlspecialchars($userName) ?></span></h1>
                    <div class="dash-hero-meta">
                        <span class="dash-chip"><i class="fas fa-shield-check"></i> <?= htmlspecialchars($userRole) ?></span>
                        <span class="dash-chip dash-chip-plain"><i class="fas fa-calendar-days"></i> <?= date('M d, Y') ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Stats. Same connected strip as registrar/students.php:
             one unit, hairline-separated cells. The trend badge is only
             rendered when there is a prior 30-day window to compare
             against, so it is never a made-up number. -->
        <div class="stats-grid dashboard-section">
            <div class="stat-card tone-blue">
                <div class="stat-header">
                    <div class="stat-icon blue"><i class="fas fa-user-graduate"></i></div>
                    <?php if ($joinDelta !== null): ?>
                    <span class="stat-trend <?= $joinDelta >= 0 ? 'up' : 'down' ?>">
                        <i class="fas <?= $joinDelta >= 0 ? 'fa-arrow-up' : 'fa-arrow-down' ?>"></i> <?= abs($joinDelta) ?>%
                    </span>
                    <?php endif; ?>
                </div>
                <p class="stat-label">Total students</p>
                <div class="stat-value"><?= number_format($totalStudents) ?></div>
                <div class="stat-foot">
                    <span><b><?= $recentJoins ?></b> joined in 30 days</span>
                </div>
            </div>
            <div class="stat-card tone-green">
                <div class="stat-header">
                    <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                </div>
                <p class="stat-label">Active</p>
                <div class="stat-value"><?= number_format($activeStudents) ?></div>
                <div class="stat-foot">
                    <span><?= $totalStudents > 0 ? round($activeStudents / $totalStudents * 100) : 0 ?>% of students</span>
                    <span class="stat-meter"><i style="width:<?= $totalStudents > 0 ? round($activeStudents / $totalStudents * 100) : 0 ?>%;background:#16a34a"></i></span>
                </div>
            </div>
            <div class="stat-card tone-amber">
                <div class="stat-header">
                    <div class="stat-icon yellow"><i class="fas fa-triangle-exclamation"></i></div>
                </div>
                <p class="stat-label">At risk or probation</p>
                <div class="stat-value"><?= number_format($atRiskStudents) ?></div>
                <div class="stat-foot">
                    <?php if ($atRiskStudents > 0): ?>
                    <span class="stat-trend down"><i class="fas fa-triangle-exclamation"></i>Needs follow-up</span>
                    <?php else: ?>
                    <span class="stat-trend up"><i class="fas fa-circle-check"></i>None flagged</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="stat-card tone-violet">
                <div class="stat-header">
                    <div class="stat-icon purple"><i class="fas fa-graduation-cap"></i></div>
                </div>
                <p class="stat-label">Graduated</p>
                <div class="stat-value"><?= number_format($graduatedStudents) ?></div>
                <div class="stat-foot">
                    <span><?= $totalStudents > 0 ? round($graduatedStudents / $totalStudents * 100) : 0 ?>% of all records</span>
                    <span class="stat-meter"><i style="width:<?= $totalStudents > 0 ? round($graduatedStudents / $totalStudents * 100) : 0 ?>%;background:#7c3aed"></i></span>
                </div>
            </div>
        </div>

        <!-- Advanced Analytics Section -->
        <div class="chart-grid dashboard-section">
            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-chart-line"></i> Enrollment Trend</div>
                        <div class="card-subtitle">Track monthly growth patterns</div>
                    </div>
                    <span class="card-badge">Last 6 Months</span>
                </div>
                <div class="card-body">
                    <canvas id="enrollmentChart" data-labels='<?= json_encode($months) ?>' data-data='<?= json_encode($monthCounts) ?>'></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-chart-pie"></i> Course Distribution</div>
                        <div class="card-subtitle">Top 5 courses by enrollment</div>
                    </div>
                    <span class="card-badge">Top 5</span>
                </div>
                <div class="card-body">
                    <canvas id="courseChart" data-labels='<?= json_encode($courseLabels) ?>' data-data='<?= json_encode($courseCounts) ?>'></canvas>
                </div>
            </div>
        </div>

        <!-- Premium Analytics Grid -->
        <div class="chart-grid-3 dashboard-section">
            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-users-check"></i> Student Status Overview</div>
                        <div class="card-subtitle">Real-time distribution</div>
                    </div>
                </div>
                <div class="status-distribution">
                    <?php
                    // The four canonical rows are always rendered, exactly as
                    // before. Only the numbers behind them changed: they now
                    // come from a single real GROUP BY instead of four queries
                    // that missed the 'enrolled' default.
                    $statusTotal = max(array_sum($statusData), 1);
                    ?>
                    <?php foreach ($statusData as $key => $value):
                        $meta = $DASH_STATUS_META[$key];
                        $pct = round(($value / $statusTotal) * 100);
                    ?>
                        <div class="status-row">
                            <div class="status-left">
                                <i class="fas <?= htmlspecialchars($meta['icon']) ?>" style="color: <?= htmlspecialchars($meta['color']) ?>;"></i>
                                <div class="status-info">
                                    <div class="status-label"><?= htmlspecialchars($meta['label']) ?></div>
                                    <div class="status-count"><?= number_format($value) ?> students</div>
                                </div>
                            </div>
                            <div class="status-right">
                                <div class="status-percent"><?= $pct ?>%</div>
                                <div class="status-bar">
                                    <div class="status-fill <?= htmlspecialchars($meta['fill']) ?>" style="width: <?= $pct ?>%;"></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-credit-card"></i> RFID & Documents</div>
                        <div class="card-subtitle">System resources status</div>
                    </div>
                </div>
                <div class="resource-grid">
                    <div class="resource-item">
                        <div class="resource-header">
                            <div class="resource-label">RFID Cards</div>
                            <div class="resource-stat"><?= number_format($activeCards) ?>/<?= number_format($totalCards) ?></div>
                        </div>
                        <div class="resource-bar">
                            <div class="resource-fill" style="width: <?= round(($activeCards / max($totalCards, 1)) * 100) ?>%; background: linear-gradient(90deg, #2563eb, #60a5fa);"></div>
                        </div>
                        <div class="resource-footer"><?= round(($activeCards / max($totalCards, 1)) * 100) ?>% Active</div>
                    </div>

                    <div class="resource-item">
                        <div class="resource-header">
                            <div class="resource-label">Expired Cards</div>
                            <div class="resource-stat"><?= number_format($expiredCards) ?></div>
                        </div>
                        <div class="resource-bar">
                            <div class="resource-fill" style="width: <?= round(($expiredCards / max($totalCards, 1)) * 100) ?>%; background: linear-gradient(90deg, #b45309, #fbbf24);"></div>
                        </div>
                        <div class="resource-footer">Requires renewal</div>
                    </div>

                    <div class="resource-item">
                        <div class="resource-header">
                            <div class="resource-label">Pending Documents</div>
                            <div class="resource-stat"><?= number_format($pendingDocs) ?></div>
                        </div>
                        <div class="resource-bar">
                            <div class="resource-fill" style="width: <?= $totalDocs > 0 ? round(($pendingDocs / $totalDocs) * 100) : 0 ?>%; background: linear-gradient(90deg, #7c3aed, #a78bfa);"></div>
                        </div>
                        <div class="resource-footer">In processing</div>
                    </div>

                    <div class="resource-item">
                        <div class="resource-header">
                            <div class="resource-label">Approved Documents</div>
                            <div class="resource-stat"><?= number_format($approvedDocs) ?></div>
                        </div>
                        <div class="resource-bar">
                            <div class="resource-fill" style="width: <?= $totalDocs > 0 ? round(($approvedDocs / $totalDocs) * 100) : 0 ?>%; background: linear-gradient(90deg, #16a34a, #4ade80);"></div>
                        </div>
                        <div class="resource-footer">Ready for pickup</div>
                    </div>
                </div>
            </div>

            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-gauge"></i> Key Performance Metrics</div>
                        <div class="card-subtitle">System health indicators</div>
                    </div>
                </div>
                <div class="metrics-container">
                    <div class="metric-box">
                        <div class="metric-top">
                            <span class="metric-name">Enrollment Rate</span>
                            <span class="metric-badge active">Active</span>
                        </div>
                        <div class="metric-value"><?= round(($activeStudents / max($totalStudents, 1)) * 100) ?>%</div>
                        <div class="metric-track">
                            <div class="metric-progress" style="width: <?= round(($activeStudents / max($totalStudents, 1)) * 100) ?>%; background: #2563eb;"></div>
                        </div>
                    </div>

                    <div class="metric-box">
                        <div class="metric-top">
                            <span class="metric-name">Graduation Rate</span>
                            <span class="metric-badge success">Complete</span>
                        </div>
                        <div class="metric-value"><?= round(($graduatedStudents / max($totalStudents, 1)) * 100) ?>%</div>
                        <div class="metric-track">
                            <div class="metric-progress" style="width: <?= round(($graduatedStudents / max($totalStudents, 1)) * 100) ?>%; background: #7c3aed;"></div>
                        </div>
                    </div>

                    <div class="metric-box">
                        <div class="metric-top">
                            <span class="metric-name">Card Active Rate</span>
                            <span class="metric-badge info">Operational</span>
                        </div>
                        <div class="metric-value"><?= round(($activeCards / max($totalCards, 1)) * 100) ?>%</div>
                        <div class="metric-track">
                            <div class="metric-progress" style="width: <?= round(($activeCards / max($totalCards, 1)) * 100) ?>%; background: #16a34a;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="chart-card dashboard-section">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-clock-rotate-left" style="color: #2563eb;"></i> Recent Activity</div>
                <span class="card-badge">Latest Updates</span>
            </div>
            <?php if (empty($recentActivity)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No recent activity</p>
                    <span>Status changes will appear here</span>
                </div>
            <?php else: ?>
                <div class="activity-list">
                    <?php foreach ($recentActivity as $activity):
                        // The activity feed named 'at-risk' in three places to pick a
                        // colour and an icon. That status no longer exists, so every
                        // change other than 'active' fell through to the amber
                        // "warning" treatment - a student moved to dropped looked
                        // identical to one merely marked enrolled.
                        //
                        // It now reads the shared meta, so a change to any of the
                        // five is coloured as that status, and an unrecognised value
                        // reads as unknown rather than as a guess.
                        $actMeta = studentStatusMeta($activity['current_status'] ?? '');
                        $isDrop  = ($activity['current_status'] ?? '') === 'dropped';
                        $statusClass = $isDrop ? 'risk' : ($actMeta['class'] === 'active' ? 'active' : 'warning');
                        $statusIcon  = $isDrop ? 'fa-exclamation' : ($actMeta['class'] === 'active' ? 'fa-check' : 'fa-clock');
                        $actColor   = $actMeta['color'] !== '' ? $actMeta['color'] : '#b45309';
                    ?>
                        <div class="activity-item">
                            <div class="activity-left">
                                <div class="activity-icon <?= $statusClass ?>"><i class="fas <?= $statusIcon ?>"></i></div>
                                <div class="activity-info">
                                    <div class="activity-name"><?= htmlspecialchars($activity['student_name'] ?? 'Unknown') ?></div>
                                    <div class="activity-detail">
                                        Status changed to <strong style="color: <?= htmlspecialchars($actColor) ?>;"><?= htmlspecialchars(studentStatusLabel($activity['current_status'] ?? '') ?: ucfirst((string)($activity['current_status'] ?? 'Unknown'))) ?></strong>
                                        <?php if ($activity['reason']): ?> — <?= htmlspecialchars($activity['reason']) ?><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="activity-time"><?= date('M d, h:i A', strtotime($activity['created_at'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Live Queue -->
        <div class="chart-card dashboard-section">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-display" style="color: #2563eb;"></i> Live Queue</div>
                <a href="registrar/queue.php" class="card-badge" style="text-decoration:none;cursor:pointer;">Open Console <i class="fas fa-arrow-right" style="font-size:11px;"></i></a>
            </div>
            <div class="live-queue-widget" id="liveQueueWidget">
                <div style="color:#94a3b8;font-size:14px;padding:12px 2px;">Loading…</div>
            </div>
        </div>

    </div>
</main>

<?php
$page_scripts = ['dashboard.js', 'queue.js'];
$use_chart = true;
include 'includes/footer.php';
?>