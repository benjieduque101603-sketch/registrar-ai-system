<?php
// ============================================================
//  REGISTRAR/STATUS-TRACKER.PHP
//  Student Status Tracker Ã¢â‚¬â€ AI-powered decision console.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/status_evidence.php';

// Who needs a decision today, found by the same contradiction rules the
// "Check what I missed" button runs â€” computed here so the first screen
// answers that question without a click, and so the count is not a
// client-side guess. A source that is missing is reported rather than
// counted as zero findings.
$queue = [];
try {
    $queue = statusCohortFindings();
} catch (Throwable $e) {
    error_log('[status-tracker] attention queue unavailable: ' . $e->getMessage());
    $queue = ['findings' => [], 'scanned' => 0, 'errors' => ['queue: ' . $e->getMessage()]];
}
$queueRows = $queue['findings'] ?? [];
$queueHigh  = count($queueRows);

$db = Database::getInstance();

$ALL_STATUSES = ['inactive','enrolled','active','dropped','graduated','alumni','probation','at-risk','loa','transferred','archived'];
$DB_STATUSES  = ['active','probation','at-risk','loa','enrolled','graduated','transferred','dropped'];
$STATUS_META = [
    'inactive'    => ['color'=>'#94a3b8','bg'=>'#f1f5f9','icon'=>'fas fa-user-slash'],
    'enrolled'    => ['color'=>'#2563eb','bg'=>'#eff6ff','icon'=>'fas fa-user-plus'],
    'active'      => ['color'=>'#16a34a','bg'=>'#f0fdf4','icon'=>'fas fa-user-check'],
    'dropped'     => ['color'=>'#dc2626','bg'=>'#fef2f2','icon'=>'fas fa-user-xmark'],
    'graduated'   => ['color'=>'#7c3aed','bg'=>'#f5f3ff','icon'=>'fas fa-graduation-cap'],
    'alumni'      => ['color'=>'#0891b2','bg'=>'#ecfeff','icon'=>'fas fa-users'],
    'probation'   => ['color'=>'#d97706','bg'=>'#fffbeb','icon'=>'fas fa-exclamation-triangle'],
    'at-risk'     => ['color'=>'#ef4444','bg'=>'#fef2f2','icon'=>'fas fa-shield-halved'],
    'loa'         => ['color'=>'#6366f1','bg'=>'#eef2ff','icon'=>'fas fa-pause-circle'],
    'transferred' => ['color'=>'#0d9488','bg'=>'#f0fdfa','icon'=>'fas fa-right-left'],
    'archived'    => ['color'=>'#6b7280','bg'=>'#f9fafb','icon'=>'fas fa-box-archive'],
];

$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$search       = isset($_GET['q']) ? trim($_GET['q']) : '';

$counts = [];
foreach ($ALL_STATUSES as $s) {
    $counts[$s] = in_array($s, $DB_STATUSES, true) ? (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = ?", [$s]) : 0;
}

$totalStudents    = (int) $db->fetchColumn("SELECT COUNT(*) FROM students");
$monthStart       = date('Y-m-01 00:00:00');
$prevMonthStart   = date('Y-m-01 00:00:00', strtotime('-1 month'));
$changesThisMonth = (int) $db->fetchColumn("SELECT COUNT(*) FROM status_tracker WHERE created_at >= ?", [$monthStart]);
$changesPrevMonth = (int) $db->fetchColumn("SELECT COUNT(*) FROM status_tracker WHERE created_at >= ? AND created_at < ?", [$prevMonthStart, $monthStart]);
$changesLast7d    = (int) $db->fetchColumn("SELECT COUNT(*) FROM status_tracker WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$attentionNeeded  = $counts['at-risk'] + $counts['probation'];

$distData = [];
foreach ($DB_STATUSES as $s) { $distData[$s] = $totalStudents > 0 ? round(($counts[$s] / $totalStudents) * 100, 1) : 0; }

$activityFeed = $db->fetchAll("SELECT st.*, s.first_name, s.last_name, s.student_number, s.status AS current_student_status, u.full_name AS changed_by_name FROM status_tracker st JOIN students s ON s.id = st.student_id LEFT JOIN users u ON u.id = st.changed_by ORDER BY st.created_at DESC LIMIT 20");

// Sorted by need, not by recency.
//
// This was ORDER BY last_change DESC, which put the most recently
// touched student first â€” very nearly the opposite of urgent. A student
// untouched for eight months outranked one flagged at-risk that morning.
// The flag column is the same rules the desk list runs, so a row that
// matters here also appears at the top of "Needs a decision".
$sql = "SELECT s.id, s.student_number, s.first_name, s.middle_name, s.last_name, s.course, s.year_level, s.status, s.photo, MAX(st.created_at) AS last_change FROM students s LEFT JOIN status_tracker st ON st.student_id = s.id";
$params = []; $where = [];
if ($filterStatus !== '' && in_array($filterStatus, $ALL_STATUSES, true)) { $where[] = "s.status = ?"; $params[] = $filterStatus; }
if ($search !== '') { $where[] = "(s.student_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name,' ',s.last_name) LIKE ?)"; $like = "%{$search}%"; $params = array_merge($params, [$like, $like, $like, $like]); }
if (!empty($where)) { $sql .= " WHERE " . implode(' AND ', $where); }
$sql .= " GROUP BY s.id ORDER BY last_change DESC, s.id DESC";
$students = $db->fetchAll($sql, $params);

// Rank in PHP rather than in SQL. The flag set is already in memory
// from the queue pass, so folding it in here costs a lookup per row and
// keeps the ORDER BY compatible with the GROUP BY the join requires â€”
// a window function over a grouped query would have needed a derived
// table, and this query has to keep working on the same MySQL the rest
// of the app runs on.
$queueRank = [];
foreach ($queueRows as $qr) {
    $queueRank[(int) $qr['student_id']] = $qr;
}
$rankOf = static function (array $s) use ($queueRank): int {
    return isset($queueRank[(int) $s['id']]) ? 0 : 1;
};
usort($students, static function ($a, $b) use ($rankOf) {
    $ra = $rankOf($a);
    $rb = $rankOf($b);
    if ($ra !== $rb) {
        return $ra <=> $rb;   // flagged rows first
    }
    // Within each band, the least recently touched is more likely to be
    // the one nobody got to.
    $ta = $a['last_change'] ? strtotime((string) $a['last_change']) : 0;
    $tb = $b['last_change'] ? strtotime((string) $b['last_change']) : 0;
    return $ta <=> $tb;
});


$page_title = 'Status Tracker';
$page_description = 'Student status monitoring and activity tracker';
$body_page = 'status-tracker';
$APP_ROOT   = '../';
$ACTIVE_NAV = 'tracker';
$extra_css = ['status-tracker.css'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main">
<header class="header">
    <div class="title">
      <div class="st-kicker"><i class="fas fa-scale-balanced"></i> Registrar's desk</div>
      <h1>Status Tracker</h1>
      <p>Every status change is a decision you make. This page gathers the evidence, points at what disagrees with itself, and leaves the call to you.</p>
    </div>
</header>
<div class="st-wrap">
<!-- â”€â”€ Needs a decision â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     First on the page, because it is the job. The four tiles below
     describe the whole population; this list describes the handful
     whose record contradicts itself, which is what actually gets
     worked through in a morning. -->
<section class="st-desk" aria-labelledby="deskTitle">
  <div class="st-desk-head">
    <div>
      <h2 id="deskTitle">Needs a decision</h2>
      <p><?= $queueHigh === 0
            ? 'No records currently contradict themselves.'
            : $queueHigh . ' student' . ($queueHigh === 1 ? '' : 's') . ' of ' . number_format((int)($queue['scanned'] ?? 0)) . ' flagged. Open a case to read the evidence.' ?></p>
    </div>
    <?php if (!empty($queue['errors'])): ?>
      <span class="st-desk-partial" title="<?= htmlspecialchars(implode(' | ', $queue['errors'])) ?>">
        <i class="fas fa-triangle-exclamation"></i> Partial check
      </span>
    <?php endif; ?>
  </div>
  <?php if (!$queueRows): ?>
    <div class="st-desk-empty">
      <i class="fas fa-circle-check"></i>
      <div>
        <strong>Nothing is flagging.</strong>
        <span>No pending cases, expired windows, or balances against a closed status.</span>
      </div>
    </div>
  <?php else: ?>
    <ul class="st-desk-list">
      <?php foreach (array_slice($queueRows, 0, 8) as $q): $m = $STATUS_META[$q['current_status']] ?? $STATUS_META['inactive']; ?>
        <li class="st-desk-row">
          <button type="button" class="st-desk-open"
                  onclick="openStudentModal(<?= (int)$q['student_id'] ?>,'<?= htmlspecialchars(addslashes($q['student_name'])) ?>','<?= htmlspecialchars($q['student_number']) ?>')">
            <span class="st-desk-name"><?= htmlspecialchars($q['student_name']) ?></span>
            <span class="st-desk-num"><?= htmlspecialchars($q['student_number'] ?: 'No ID') ?></span>
          </button>
          <span class="st-desk-status" style="background:<?= $m['bg'] ?>;color:<?= $m['color'] ?>"><?= htmlspecialchars($q['current_status'] ?: 'unset') ?></span>
          <span class="st-desk-why">
            <?= htmlspecialchars(implode(' Â· ', array_column($q['issues'], 'title'))) ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if (count($queueRows) > 8): ?>
      <p class="st-desk-more"><?= count($queueRows) - 8 ?> more flagged. Use <strong>Check what I missed</strong> for the full list.</p>
    <?php endif; ?>
  <?php endif; ?>
</section>

<!-- Population at a glance. Secondary on purpose: these are counts, not tasks. -->
<div class="st-kpi-strip">
  <div class="st-kpi">
    <div class="st-kpi-icon" style="background:var(--brand-50);color:var(--brand-500)"><i class="fas fa-users"></i></div>
    <div>
      <div class="st-kpi-num"><?= number_format($totalStudents) ?></div>
      <div class="st-kpi-label">Total Students</div>
    </div>
  </div>
  <div class="st-kpi">
    <div class="st-kpi-icon" style="background:var(--success-100);color:var(--success-600)"><i class="fas fa-arrow-right-arrow-left"></i></div>
    <div>
      <div class="st-kpi-num"><?= number_format($changesThisMonth) ?>
        <?php $delta = $changesThisMonth - $changesPrevMonth; if ($delta !== 0): ?>
          <span class="st-delta <?= $delta > 0 ? 'up' : 'down' ?>"><?= $delta > 0 ? '+' : '' ?><?= $delta ?></span>
        <?php endif; ?>
      </div>
      <div class="st-kpi-label">Changes This Month</div>
    </div>
  </div>
  <div class="st-kpi">
    <div class="st-kpi-icon" style="background:var(--danger-100);color:var(--danger-600)"><i class="fas fa-triangle-exclamation"></i></div>
    <div>
      <div class="st-kpi-num"><?= number_format($attentionNeeded) ?></div>
      <div class="st-kpi-label">Attention Needed</div>
    </div>
  </div>
  <div class="st-kpi">
    <div class="st-kpi-icon" style="background:#eef2ff;color:var(--purple-500)"><i class="fas fa-calendar-week"></i></div>
    <div>
      <div class="st-kpi-num"><?= number_format($changesLast7d) ?></div>
      <div class="st-kpi-label">Last 7 Days</div>
    </div>
  </div>
</div>


<!-- Review tools -->
<!--
  Labelled for what each one actually does. Two of these are pure
  database rules and say so; the ones that call a model say so too. A
  button that claims to be AI and returns a sprintf() is worse than no
  button, because it spends the reader's trust for nothing.
-->
<section class="st-ai-section" aria-labelledby="stAiToolsTitle">
  <div class="st-ai-bar">
    <div class="st-ai-bar-label"><span class="st-ai-mark"><i class="fas fa-magnifying-glass"></i></span><span><strong id="stAiToolsTitle">Review tools</strong><small>Read-only unless you apply a change yourself</small></span></div>
  <button class="st-ai-btn st-ai-btn-primary" onclick="runAI('missed')" id="btnAIMissed"><i class="fas fa-triangle-exclamation"></i> Check what I missed</button>
  <button class="st-ai-btn" onclick="runAI('recommendations')" id="btnAIRecs"><i class="fas fa-lightbulb"></i> Suggested transitions</button>
  <div class="st-ai-sep"></div>
  <button class="st-ai-btn" onclick="runAI('anomalies')" id="btnAIAnomalies" title="Database rules, no AI"><i class="fas fa-gauge-high"></i> Activity check</button>
  <button class="st-ai-btn" onclick="runAI('risks')" id="btnAIRisks" title="Database rules decide the level"><i class="fas fa-shield-halved"></i> Attention</button>
  <div class="st-ai-sep"></div>
  <button class="st-ai-btn st-ai-run-all" onclick="runAI('all')" id="btnAIAll"><i class="fas fa-bolt"></i> Run all</button>
  </div>
</section>

<!-- AI Output Panel -->
<div class="st-ai-output" id="aiOutput">
  <div class="st-ai-output-hdr">
    <div class="st-ai-output-title" id="aiOutputTitle"><i class="fas fa-robot"></i> <span id="aiOutputLabel">Output</span> <span class="st-ai-output-badge" id="aiOutputCount" style="display:none">0</span></div>
    <button class="st-ai-output-close" onclick="closeAIOutput()"><i class="fas fa-xmark"></i></button>
  </div>
  <div class="st-ai-output-body" id="aiOutputBody"></div>
</div>

<!-- Status Distribution -->
<section class="st-dist" aria-labelledby="statusDistributionTitle">
  <div class="st-panel-heading"><div><h2 id="statusDistributionTitle">Status distribution</h2><p>Current student status mix</p></div><span class="st-panel-chip"><i class="fas fa-chart-pie"></i> Live</span></div>
  <div class="st-dist-bar" id="distBar"></div>
  <div class="st-dist-legend" id="distLegend"></div>
</section>

<!-- Filters -->
<section class="st-filter-panel" aria-labelledby="statusFiltersTitle">
  <div class="st-filter-heading">
    <div><div class="st-filter-kicker"><i class="fas fa-filter"></i> Directory controls</div><h2 id="statusFiltersTitle">Status categories</h2><p>Filter the student directory by current status.</p></div>
    <div class="st-filter-count"><span><?= number_format(array_sum($counts)) ?></span><small>Total students</small></div>
  </div>
  <div class="st-category-layout">
    <div class="st-category-group" aria-label="Status filters">
      <div class="st-category-label">All statuses</div>
      <div class="st-pill-grid">
        <a href="?" class="st-pill st-pill-all <?= $filterStatus === '' ? 'active' : '' ?>"><span class="st-pill-icon"><i class="fas fa-layer-group"></i></span><span>All</span><strong><?= number_format($totalStudents) ?></strong></a>
        <?php foreach ($DB_STATUSES as $s): $meta = $STATUS_META[$s] ?? $STATUS_META['inactive']; ?>
          <a href="?status=<?= $s ?>" class="st-pill <?= $filterStatus === $s ? 'active' : '' ?>" style="--pill-color:<?= $meta['color'] ?>;--pill-bg:<?= $meta['bg'] ?>"><span class="st-pill-icon"><i class="<?= $meta['icon'] ?>"></i></span><span><?= ucfirst(str_replace('-', ' ', $s)) ?></span><strong><?= number_format($counts[$s]) ?></strong></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="st-search-group">
      <label class="st-category-label" for="searchInput">Search directory</label>
      <div class="st-search"><i class="fas fa-search"></i><input type="text" id="searchInput" placeholder="Search name or student ID" value="<?= htmlspecialchars($search) ?>"></div>
      <small>Search updates the directory results.</small>
    </div>
  </div>
</section>

<!-- Two Panel Layout -->
<div class="st-panels">
  <!-- Student Table -->
  <div class="st-table-wrap">
    <div class="st-panel-heading"><div><h2>Student directory</h2><p>Select a student to review status history</p></div><span class="st-panel-chip"><i class="fas fa-users"></i> <?= number_format(count($students)) ?> shown</span></div>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Student</th>
          <th>Student ID</th>
          <th>Course</th>
          <th>Year</th>
          <th>Status</th>
          <th>Risk</th>
          <th>Last Changed</th>
        </tr>
      </thead>
      <tbody id="stStudentBody">
        <?php if (empty($students)): ?>
          <tr><td colspan="8" class="st-empty" style="text-align:center;padding:48px;color:var(--text-subtle)"><i class="fas fa-users-slash" style="font-size:32px;display:block;margin-bottom:12px"></i>No students found</td></tr>
        <?php else: ?>
          <?php $rowNum = 0; foreach ($students as $s):
            $rowNum++;
            $initials = strtoupper(substr($s['first_name'],0,1) . substr($s['last_name'],0,1));
            $sm = $STATUS_META[$s['status']] ?? $STATUS_META['inactive'];
          ?>
          <tr data-student-row style="cursor:pointer" onclick="openStudentModal(<?= $s['id'] ?>,'<?= htmlspecialchars(addslashes($s['first_name'].' '.$s['last_name'])) ?>','<?= htmlspecialchars($s['student_number']) ?>')">
            <td style="font-weight:600;font-size:12px;color:var(--text-faint)"><?= $rowNum ?></td>
            <td>
              <div class="st-t-info">
                <div class="st-t-av" style="background:<?= $sm['bg'] ?>;color:<?= $sm['color'] ?>"><?= $initials ?></div>
                <div>
                  <div class="st-t-name"><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></div>
                  <?php if (!empty($s['middle_name'])): ?>
                    <div class="st-t-num"><?= htmlspecialchars($s['middle_name']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td style="font-weight:600;font-size:12px;white-space:nowrap"><?= htmlspecialchars($s['student_number']) ?></td>
            <td style="white-space:nowrap"><?= htmlspecialchars($s['course'] ?? 'N/A') ?></td>
            <td style="white-space:nowrap"><?= htmlspecialchars($s['year_level'] ?? 'N/A') ?></td>
            <td>
              <div class="st-badge" style="background:<?= $sm['bg'] ?>;color:<?= $sm['color'] ?>">
                <span class="st-badge-dot" style="background:<?= $sm['color'] ?>"></span>
                <?= ucfirst($s['status']) ?>
              </div>
            </td>
            <td style="text-align:center">
              <span class="st-rdot loading" data-student-id="<?= $s['id'] ?>"></span>
            </td>
            <td style="font-size:12px;color:var(--text-faint);white-space:nowrap">
              <?php if (!empty($s['last_change'])): ?>
                <?= date('M d, Y', strtotime($s['last_change'])) ?>
              <?php else: ?>
                <span class="st-no-change">Not recorded</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Activity Timeline -->
  <section class="st-tl" aria-labelledby="recentActivityTitle">
    <div class="st-panel-heading"><div><h2 id="recentActivityTitle">Recent activity</h2><p>Latest status changes across students</p></div><span class="st-panel-chip"><i class="fas fa-clock-rotate-left"></i> Timeline</span></div>
    <?php if (empty($activityFeed)): ?>
      <div class="st-empty"><i class="fas fa-inbox"></i>No activity yet</div>
    <?php else: ?>
    <div class="st-tl-b">
      <?php foreach ($activityFeed as $af):
        $meta = $STATUS_META[$af['current_status']] ?? $STATUS_META['inactive'];
      ?>
      <div class="st-tl-i">
        <div class="st-tl-dot" style="background:<?= $meta['color'] ?>"></div>
        <div class="st-tl-nm"><?= htmlspecialchars($af['first_name'].' '.$af['last_name']) ?></div>
        <div class="st-tl-chg">
          <span class="st-badge" style="background:<?= ($STATUS_META[$af['previous_status']]??$STATUS_META['inactive'])['bg'] ?>;color:<?= ($STATUS_META[$af['previous_status']]??$STATUS_META['inactive'])['color'] ?>;padding:2px 6px;font-size:10px"><?= ucfirst($af['previous_status']) ?></span>
          <i class="fas fa-arrow-right" style="color:var(--text-subtle);font-size:10px;margin:0 4px"></i>
          <span class="st-badge" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>;padding:2px 6px;font-size:10px"><?= ucfirst($af['current_status']) ?></span>
        </div>
        <?php if (!empty($af['reason'])): ?>
          <div class="st-tl-rsn"><?= htmlspecialchars($af['reason']) ?></div>
        <?php endif; ?>
        <div class="st-tl-time"><i class="fas fa-user" style="font-size:9px"></i> <?= htmlspecialchars($af['changed_by_name'] ?? 'System') ?> &middot; <?= date('M d, g:i A', strtotime($af['created_at'])) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</div><!-- /st-panels -->
</div><!-- /st-wrap -->
</main><!-- /dashboard-main -->

<!-- History Modal -->
<div class="st-modal-overlay" id="studentModal">
  <div class="st-modal">
    <div class="st-modal-header">
      <div class="st-modal-header-info">
        <div id="modalAvatar" class="st-modal-header-avatar" style="background:var(--brand-500)"></div>
        <div><div class="st-modal-header-name" id="modalName"></div><div class="st-modal-header-num" id="modalNumber"></div></div>
      </div>
      <div class="st-modal-header-actions">
        <button class="st-btn-sm st-btn-apply" id="btnModalCase" onclick="assembleCase()"><i class="fas fa-folder-open"></i> Assemble case</button>
        <button class="st-modal-close" onclick="closeModal()" aria-label="Close student status history"><i class="fas fa-xmark"></i></button>
      </div>
    </div>
    <!--
      The findings and the evidence they were read from, side by side.
      The note is the only part a language model writes, and it is handed
      findings that have already fired. There is no Apply control in this
      panel on purpose: a status change is made in the form below it, by
      the person, with a reason attached.
    -->
    <div class="st-case" id="modalCase">
      <div class="st-case-head">
        <div class="st-modal-ai-lbl"><i class="fas fa-robot"></i> What to verify</div>
        <span class="st-case-src" id="modalCaseSrc"></span>
      </div>
      <div class="st-case-note" id="modalCaseNote">Assembling the recordâ€¦</div>
      <div class="st-case-body">
        <div class="st-case-col">
          <h4>Flags</h4>
          <div id="modalCaseFlags"><div class="st-modal-empty"><i class="fas fa-inbox"></i> Nothing flagged</div></div>
        </div>
        <div class="st-case-col">
          <h4>Evidence</h4>
          <div id="modalCaseEvidence"><div class="st-modal-empty"><i class="fas fa-inbox"></i> Not read yet</div></div>
        </div>
      </div>
    </div>
    <div class="st-modal-timeline">
      <div class="st-modal-timeline-h"><i class="fas fa-clock-rotate-left"></i> Status History</div>
      <div id="modalTimeline">
        <div class="st-modal-empty"><i class="fas fa-spinner fa-spin"></i> Loading history...</div>
      </div>
    </div>

    <!--
      The one place on this page a status can change, and it is a form a
      person fills in rather than a button a panel offers.

      A reason is required, because this journal is the only record of
      why a student moved. The old Apply button sent none, so every
      transition applied from this page was logged with a null reason.

      The two date fields are what make a leave of absence end. The
      columns existed and nothing wrote them, so an LOA had no return
      date and could never be known to have expired. They only appear
      for the statuses that are time-boxed by nature; a graduation has
      no expiry, and asking for one would be asking the wrong question.
    -->
    <form class="st-change" id="stChangeForm" onsubmit="return false;">
      <div class="st-change-head">
        <h4>Record a status change</h4>
        <span class="st-change-hint">Recorded under your name, with the reason you give.</span>
      </div>
      <div class="st-change-grid">
        <div class="st-field">
          <label for="chStatus">New status</label>
          <select id="chStatus" onchange="toggleWindowFields()">
            <?php foreach ($DB_STATUSES as $s): ?>
              <option value="<?= $s ?>"><?= htmlspecialchars(ucwords(str_replace('-', ' ', $s))) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="st-field st-field-wide">
          <label for="chReason">Reason <span class="st-req">required</span></label>
          <input type="text" id="chReason" maxlength="255" placeholder="What prompted this change?">
        </div>
        <div class="st-field st-window" id="chEffectiveWrap" hidden>
          <label for="chEffective">Effective from</label>
          <input type="date" id="chEffective">
        </div>
        <div class="st-field st-window" id="chEndWrap" hidden>
          <label for="chEnd">Window ends <span class="st-req">for a timed leave</span></label>
          <input type="date" id="chEnd">
        </div>
      </div>
      <div class="st-change-foot">
        <span class="st-change-err" id="chError" role="alert"></span>
        <button type="button" class="st-btn-dismiss" onclick="closeModal()">Cancel</button>
        <button type="button" class="st-btn-apply" id="chSubmit" onclick="submitStatusChange()">
          Record change
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Toast -->
<div class="st-toast" id="toast"></div>

<script>
'use strict';
(function(){
const STATUS_META=<?= json_encode($STATUS_META) ?>;
const ALL_STATUSES=<?= json_encode($ALL_STATUSES) ?>;
const DB_STATUSES=<?= json_encode($DB_STATUSES) ?>;
const SEARCH_DELAY=250;
let searchTimer=null;

/* --- Distribution Bar --- */
(function(){
const distData=<?= json_encode($distData) ?>;
const bar=document.getElementById('distBar');
const legend=document.getElementById('distLegend');
if(!bar)return;
DB_STATUSES.forEach(s=>{
const pct=distData[s]||0;
const meta=STATUS_META[s]||{color:'#94a3b8'};
const seg=document.createElement('div');
seg.className='st-dist-seg';
seg.style.width=pct+'%';
seg.style.background=meta.color;
seg.title=s+': '+pct+'%';
bar.appendChild(seg);
const item=document.createElement('div');
item.className='st-dist-item';
item.innerHTML='<span class="st-dist-dot" style="background:'+meta.color+'"></span>'+s.charAt(0).toUpperCase()+s.slice(1)+' ('+pct+'%)';
legend.appendChild(item);
});
})();

/* --- Helpers --- */
function escapeHTML(str){const d=document.createElement('div');d.textContent=str;return d.innerHTML;}
function toast(msg,type){
const el=document.getElementById('toast');
if(!el)return;
el.textContent=msg;
el.className='st-toast '+(type||'')+' show';
setTimeout(()=>el.classList.remove('show'),3000);
}
window.toast=toast;

/* --- AI Command Bar --- */
let activeAITab=null;

function setActiveTab(tab){
document.querySelectorAll('.st-ai-btn').forEach(b=>b.classList.remove('active'));
if(tab==='all'){document.getElementById('btnAIAll')?.classList.add('active');return;}
const btnMap={report:'btnAIReport',anomalies:'btnAIAnomalies',risks:'btnAIRisks',recommendations:'btnAIRecs'};
const btn=document.getElementById(btnMap[tab]);
if(btn)btn.classList.add('active');
}

function showAILoading(label){
const out=document.getElementById('aiOutput');
const title=document.getElementById('aiOutputLabel');
const body=document.getElementById('aiOutputBody');
const badge=document.getElementById('aiOutputCount');
if(!out)return;
out.classList.add('show');
title.textContent=label||'Output';
badge.style.display='none';
body.innerHTML='<div class="st-ai-output-loading"><i class="fas fa-spinner fa-spin"></i> Running AI analysis...</div>';
}

function closeAIOutput(){
const o=document.getElementById('aiOutput');
if(o)o.classList.remove('show');
document.querySelectorAll('.st-ai-btn').forEach(b=>b.classList.remove('active'));
activeAITab=null;
}
window.closeAIOutput=closeAIOutput;

function renderRecCards(recs){
if(!recs||!recs.length)return'<div style="text-align:center;padding:16px;color:var(--text-subtle);font-size:13px"><i class="fas fa-check-circle" style="color:#16a34a"></i> No transitions suggested</div>';
let h='';recs.forEach((rec,i)=>{
const sv=rec.severity||'low';
const cls=sv==='high'?'sv-high':sv==='med'?'sv-med':'sv-low';
// The title read rec.type, a key no endpoint has ever returned, so every
// card fell back to the same generic heading and the panel said nothing
// about what it was suggesting. The transition itself is the useful part.
const to=rec.recommended_status?String(rec.recommended_status).replace(/-/g,' '):'review only';
const from=String(rec.current_status||'').replace(/-/g,' ');
h+='<div class="st-rec '+cls+'" id="rec-'+i+'"><div class="st-rec-info"><div class="st-rec-title">'+escapeHTML(from)+' <i class="fas fa-arrow-right" style="font-size:9px"></i> '+escapeHTML(to)+'</div>';
h+='<div class="st-rec-name">'+escapeHTML(rec.student_name)+' <span class="st-ai-src">'+escapeHTML(rec.student_number||'')+'</span></div>';
h+='<div class="st-rec-reason">'+escapeHTML(rec.reason)+'</div>';
h+='<div class="st-rec-acts"><button class="st-btn-apply" onclick="applyRec(\''+i+'\','+parseInt(rec.student_id)+',\''+escapeHTML(rec.recommended_status||'')+'\')">Review case</button>';
h+='<button class="st-btn-dismiss" onclick="dismissRec(\''+i+'\')">Dismiss</button></div></div></div>';
});return h;
}

/* --- What I missed ---
   One student per card, each contradiction underneath it, and a way in
   to the full evidence. Nothing here changes anything: the buttons open
   a read-only case, and any status change is made from the form inside
   it, by the person, with a reason. */
function renderMissedCards(data){
const d=(data&&data.data)||data||{};
const list=d.findings||[];
const count=list.length;
let h='';
if(d.headline){
h+='<div class="st-missed-head"><i class="fas fa-robot"></i><p>'+escapeHTML(d.headline)+'</p>';
h+='<span class="st-case-src">'+(d.source==='ai'?'phrasing by AI':'rule text')+'</span></div>';
}
if(d.partial){
h+='<div class="st-desk-partial" style="margin:0 0 12px"><i class="fas fa-triangle-exclamation"></i> Some records could not be read, so this list is incomplete.</div>';
}
if(!count){
return{html:h+'<div class="st-modal-empty"><i class="fas fa-circle-check"></i> No contradictions found</div>',count:0};
}
list.forEach((f,i)=>{
h+='<div class="st-rec sv-high" id="missed-'+i+'"><div class="st-rec-info">';
h+='<div class="st-rec-title"><i class="fas fa-triangle-exclamation"></i> '+escapeHTML(f.student_name)+'</div>';
h+='<div class="st-rec-name"><span class="st-ai-src">'+escapeHTML(f.student_number||'No ID')+'</span> currently '+escapeHTML(f.current_status||'unset')+'</div>';
(f.issues||[]).forEach(iss=>{
h+='<div class="st-missed-issue"><strong>'+escapeHTML(iss.title)+'</strong> â€” '+escapeHTML(iss.detail)+'</div>';
h+='<div class="st-missed-q">'+escapeHTML(iss.question)+'</div>';
});
h+='<div class="st-rec-acts"><button class="st-btn-apply" onclick="openFromCard(\'missed-'+i+'\','+parseInt(f.student_id)+')">Read the case</button>';
h+='<button class="st-btn-dismiss" onclick="dismissRec(\'missed-'+i+'\')">Dismiss</button></div></div></div>';
});
return{html:h,count:count};
}

/* Open the case for a student named in a panel card, reusing the name and
   number already rendered there rather than re-fetching a list. */
function openFromCard(cardId,studentId){
const card=document.getElementById(cardId);
const nameEl=card?card.querySelector('.st-rec-title'):null;
const numEl=card?card.querySelector('.st-ai-src'):null;
let name=nameEl?nameEl.textContent:'';
if(nameEl){const clone=nameEl.cloneNode(true);const ic=clone.querySelector('i');if(ic)ic.remove();name=clone.textContent.trim();}
openStudentModal(studentId,name,numEl?numEl.textContent.trim():'');
}

function renderAnomCards(anoms){
if(!anoms||!anoms.length)return'<div style="text-align:center;padding:16px;color:var(--text-subtle);font-size:13px"><i class="fas fa-check-circle" style="color:#16a34a"></i> No anomalies</div>';
let h='';anoms.forEach((a,i)=>{
const msg=a.label||a.message||JSON.stringify(a);
h+='<div class="st-rec sv-med" id="anom-'+i+'"><div class="st-rec-info"><div class="st-rec-title"><i class="fas fa-magnifying-glass-chart"></i> Anomaly</div>';
h+='<div class="st-rec-reason">'+escapeHTML(msg)+'</div></div></div>';
});return h;
}

function renderRiskCards(risks){
if(!risks||typeof risks!=='object'||!Object.keys(risks).length)return'<div style="text-align:center;padding:16px;color:var(--text-subtle);font-size:13px"><i class="fas fa-check-circle" style="color:#16a34a"></i> No risk data</div>';
let h='';Object.keys(risks).forEach(id=>{
const risk=risks[id];const level=risk.risk||'low';
const cls=level==='high'?'sv-high':level==='medium'?'sv-med':'sv-low';
h+='<div class="st-rec '+cls+'" id="risk-'+id+'"><div class="st-rec-info"><div class="st-rec-title"><i class="fas fa-shield-halved"></i> Student #'+escapeHTML(id)+'</div>';
h+='<div class="st-rec-name">'+escapeHTML(risk.name||'Student #'+id)+' <span class="st-ai-src">Risk: '+escapeHTML(level)+'</span></div>';
h+='<div class="st-rec-reason">'+escapeHTML(risk.reason||'No reason')+'</div></div></div>';
});return h;
}

async function fetchAIEndpoint(action,body){
let url='../api/ai-tools.php?action='+action;
const opts={method:'GET'};
if(body){opts.method='POST';opts.headers={'Content-Type':'application/json'};opts.body=JSON.stringify(body);}
const r=await fetch(url,opts);
if(!r.ok)throw new Error('API error');
return await r.json();
}

async function runAI(tab){
activeAITab=tab;setActiveTab(tab);
const labels={missed:'What I missed',anomalies:'Activity check',risks:'Attention',recommendations:'Suggested transitions',all:'All checks'};
const label=labels[tab]||'Check';
showAILoading(label);
const badge=document.getElementById('aiOutputCount');
const body=document.getElementById('aiOutputBody');
try{
if(tab==='all'){
const results=await Promise.allSettled([
fetchAIEndpoint('missed_checks'),
fetchAIEndpoint('status_recommendations'),
fetchAIEndpoint('status_anomalies'),
fetchAIEndpoint('student_risks',{student_ids:[]})
]);
let html='',count=0;
const sections=['What I missed','Suggested transitions','Activity check','Attention'];
results.forEach((res,i)=>{
let content='';let cnt=0;
if(res.status==='fulfilled'){
const d=res.value;
if(i===0){const r=renderMissedCards(d);content=r.html;cnt=r.count;}
else if(i===1){const r=d.data?.recommendations||d.recommendations||[];content=renderRecCards(r);cnt=r.length;}
else if(i===2){const a=d.data?.anomalies||d.anomalies||[];content=renderAnomCards(a);cnt=a.length;}
else if(i===3){const r=d.data?.risks||d.risks||{};content=renderRiskCards(r);cnt=Object.keys(r).length;}
}else{
content='<div class="st-panel-fail"><i class="fas fa-exclamation-circle"></i> This check did not return. The others are unaffected.</div>';
}
html+='<div class="st-ai-section"><div class="st-ai-section-hdr">'+sections[i]+' ('+cnt+')</div>'+content+'</div>';
count+=cnt;
});
body.innerHTML=html;badge.textContent=count;badge.style.display=count>0?'inline-block':'none';
}else{
const epMap={missed:'missed_checks',anomalies:'status_anomalies',risks:'student_risks',recommendations:'status_recommendations'};
const postBody=(tab==='risks')?{student_ids:[]}:undefined;
const data=await fetchAIEndpoint(epMap[tab]||'missed_checks',postBody);
let html='',count=0;
if(tab==='missed'){const r=renderMissedCards(data);html=r.html;count=r.count;}
else if(tab==='recommendations'){const r=data.data?.recommendations||data.recommendations||[];html=renderRecCards(r);count=r.length;}
else if(tab==='anomalies'){const a=data.data?.anomalies||data.anomalies||[];html=renderAnomCards(a);count=a.length;}
else if(tab==='risks'){const r=data.data?.risks||data.risks||{};html=renderRiskCards(r);count=Object.keys(r).length;}
body.innerHTML=html;badge.textContent=count;badge.style.display=count>0?'inline-block':'none';
}
}catch(e){
body.innerHTML='<div style="text-align:center;padding:24px;color:var(--text-subtle)"><i class="fas fa-exclamation-circle"></i> Unable to load this check.</div>';
}
}
window.runAI=runAI;

/* --- Suggested transitions ---
   Opens the case panel rather than applying. The old button posted to
   bulk-status the moment it was clicked, with no confirmation and no
   reason, so the change landed and the journal recorded nothing about
   why. A suggestion is now a place to read, not a shortcut to commit. */
function applyRec(idx,studentId,status){
const card=document.getElementById('rec-'+idx);
const nameEl=card?card.querySelector('.st-rec-name'):null;
const numEl=card?card.querySelector('.st-ai-src'):null;
const label=(nameEl?nameEl.textContent:'').replace(numEl?numEl.textContent:'',' ').trim();
openStudentModal(studentId,label,numEl?numEl.textContent:'');
const sel=document.getElementById('chStatus');
if(sel){sel.value=status;toggleWindowFields();}
const reason=document.getElementById('chReason');
if(reason){reason.focus();}
toast('Review the case, then record the change with a reason.','info');
}
window.applyRec=applyRec;

function dismissRec(idx){
const card=document.getElementById('rec-'+idx);
if(card){card.style.opacity='0';setTimeout(()=>card.remove(),300);}
}
window.dismissRec=dismissRec;

/* --- Attention Dots Loader ---
   The endpoint this calls was named status_risks, and the API only ever
   defined student_risks â€” so every request fell through to "Unknown
   action". The reply was HTTP 200, so nothing threw; the JSON simply had
   no risks key, and the whole Risk column sat blank with no error
   anywhere. Both names now resolve, and an empty id list means "the
   whole roster" rather than a rejection. */
async function loadRisks(){
const dots=document.querySelectorAll('.st-rdot.loading');
if(!dots.length)return;
try{
const r=await fetch('../api/ai-tools.php?action=student_risks',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({student_ids:[]})});
if(!r.ok)throw new Error();
const data=await r.json();
if(!data.success)throw new Error(data.message||'failed');
const risks=(data.data&&data.data.risks)||data.risks||{};
dots.forEach(dot=>{
const id=dot.dataset.studentId;
if(risks[id]){const level=risks[id].risk||'low';
dot.className='st-rdot '+(level==='high'?'high':level==='medium'?'med':'low');
dot.title=risks[id].reason||level;
}else{dot.classList.remove('loading');dot.classList.add('unk');}
});
}catch(e){dots.forEach(dot=>{dot.classList.remove('loading');dot.classList.add('unk');});}
}

/* Search filters the rendered directory locally; it never reloads the page. */
const searchInput=document.getElementById('searchInput');
const studentBody=document.getElementById('stStudentBody');
if(searchInput && studentBody){
  const rows=Array.from(studentBody.querySelectorAll('tr[data-student-row]'));
  const applySearch=()=>{
    const query=searchInput.value.trim().toLowerCase();
    let visible=0;
    rows.forEach(row=>{
      const text=(row.textContent || '').toLowerCase();
      const match=!query || text.includes(query);
      row.hidden=!match;
      if(match) visible++;
    });
    let empty=studentBody.querySelector('tr[data-search-empty]');
    if(query && visible===0){
      if(!empty){
        empty=document.createElement('tr');
        empty.dataset.searchEmpty='true';
        empty.innerHTML='<td colspan="8" class="st-search-empty"><i class="fas fa-user-slash"></i><strong>No students match this search</strong><span>Try a different name, ID, or course.</span></td>';
        studentBody.appendChild(empty);
      }
      empty.hidden=false;
    }else if(empty){empty.hidden=true;}
  };
  searchInput.addEventListener('input',applySearch);
  applySearch();
}

/* --- Student Modal --- */
window.openStudentModal=function(id,name,number){
const modal=document.getElementById('studentModal');
window._currentModalStudentId=id;
document.getElementById('modalName').textContent=name;
document.getElementById('modalNumber').textContent=number;
const av=document.getElementById('modalAvatar');
if(av){const parts=name.split(' ');const initials=(parts[0]?parts[0][0]:'')+(parts[1]?parts[1][0]:'');av.textContent=initials.toUpperCase();}
document.getElementById('modalTimeline').innerHTML='<div class="st-modal-empty"><i class="fas fa-spinner fa-spin"></i> Loading history...</div>';
// Reset the case panel and the change form every time, so a previous
// student's evidence is never read as this one's.
const note=document.getElementById('modalCaseNote');
if(note)note.textContent='Press Assemble case to read this record.';
const flags=document.getElementById('modalCaseFlags');
if(flags)flags.innerHTML='<div class="st-modal-empty"><i class="fas fa-inbox"></i> Not read yet</div>';
const ev=document.getElementById('modalCaseEvidence');
if(ev)ev.innerHTML='<div class="st-modal-empty"><i class="fas fa-inbox"></i> Not read yet</div>';
const src=document.getElementById('modalCaseSrc');
if(src)src.textContent='';
const reason=document.getElementById('chReason');
if(reason)reason.value='';
const err=document.getElementById('chError');
if(err)err.textContent='';
modal.classList.add('show');
document.body.style.overflow='hidden';
fetchStudentHistory(id);
};

/* --- Assemble a case ---
   Replaces the old "Generate AI profile". It called action=profile, which
   built its brief with sprintf('%s is currently listed as %s...') and
   returned source:'rules' â€” it never called a model at all, so a button
   labelled AI was showing a fill-in-the-blank. This panel renders real
   cross-module evidence and the questions that evidence raises. */
async function assembleCase(){
const id=window._currentModalStudentId||0;
if(!id)return;
const btn=document.getElementById('btnModalCase');
const noteEl=document.getElementById('modalCaseNote');
const flagEl=document.getElementById('modalCaseFlags');
const evEl=document.getElementById('modalCaseEvidence');
const srcEl=document.getElementById('modalCaseSrc');
if(btn){btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Reading';btn.disabled=true;}
if(noteEl)noteEl.textContent='Reading the recordâ€¦';
if(flagEl)flagEl.innerHTML='<div class="st-modal-empty"><i class="fas fa-spinner fa-spin"></i> Working</div>';
if(evEl)evEl.innerHTML='<div class="st-modal-empty"><i class="fas fa-inbox"></i> Not read yet</div>';
try{
const r=await fetch('../api/ai-tools.php?action=case_brief',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id})});
if(!r.ok)throw new Error();
const d=await r.json();
if(!d.success)throw new Error(d.message||'unavailable');
const dd=d.data||{};
if(noteEl)noteEl.textContent=dd.note||'No summary available.';
if(srcEl)srcEl.textContent=dd.source==='ai'?'phrasing by AI':'rule text';
renderCaseFlags(dd.findings||[]);
renderCaseEvidence(dd.evidence||{},!!dd.partial);
}catch(e){
if(noteEl)noteEl.textContent='The case could not be assembled. A record may be missing on this server.';
if(flagEl)flagEl.innerHTML='<div class="st-modal-empty"><i class="fas fa-exclamation-circle"></i> Nothing read</div>';
}
if(btn){btn.innerHTML='<i class="fas fa-folder-open"></i> Assemble case';btn.disabled=false;}
}
window.assembleCase=assembleCase;

function renderCaseFlags(findings){
const el=document.getElementById('modalCaseFlags');
if(!el)return;
if(!findings.length){el.innerHTML='<div class="st-modal-empty"><i class="fas fa-circle-check"></i> Nothing contradicts itself</div>';return;}
let h='';
findings.forEach(f=>{
h+='<div class="st-case-flag w-'+escapeHTML(f.weight||'low')+'">';
h+='<div class="st-case-flag-t">'+escapeHTML(f.title)+'</div>';
h+='<div class="st-case-flag-d">'+escapeHTML(f.detail)+'</div>';
h+='<div class="st-case-flag-q"><i class="fas fa-circle-question"></i> '+escapeHTML(f.question)+'</div>';
h+='</div>';
});
el.innerHTML=h;
}

function renderCaseEvidence(ev,partial){
const el=document.getElementById('modalCaseEvidence');
if(!el)return;
const rows=[];
const bal=parseFloat(ev.balance||0);
if(bal>0)rows.push(['Outstanding balance','PHP '+bal.toFixed(2)]);
const disc=ev.discipline||{};
const pend=(disc.pending||[]).length;
const res=disc.resolved||0;
if(pend||res)rows.push(['Disciplinary cases',pend+' pending, '+res+' closed']);
const docs=ev.documents||{};
if(docs.open||docs.held)rows.push(['Document requests',docs.open+' open'+(docs.held?(' Â· '+docs.held+' on hold'):'')]);
const grades=ev.grades||[];
if(grades.length)rows.push(['GWA (newest first)',grades.slice(0,4).map(g=>Number(g.gwa).toFixed(2)).join(' â†’ ')]);
if(ev.window&&ev.window.end_date)rows.push(['Status window','ended '+String(ev.window.end_date).slice(0,10)]);
rows.push(['Guardian',ev.has_guardian?'on file':'none on file']);
if(ev.last_scan)rows.push(['Last card scan',String(ev.last_scan).slice(0,16).replace('T',' ')]);
const hist=ev.history||[];
if(hist.length)rows.push(['Recorded changes',String(hist.length)]);
let h='<dl class="st-case-ev">';
rows.forEach(r=>{h+='<div><dt>'+escapeHTML(r[0])+'</dt><dd>'+escapeHTML(r[1])+'</dd></div>';});
h+='</dl>';
if(partial)h+='<div class="st-desk-partial" style="margin-top:10px"><i class="fas fa-triangle-exclamation"></i> Part of this record could not be read.</div>';
el.innerHTML=h;
}

async function fetchStudentHistory(id){
try{
const r=await fetch('../api/status-history.php?student_id='+id);
if(!r.ok)throw new Error();
const d=await r.json();
const history=d.data||d.history||[];
const container=document.getElementById('modalTimeline');
if(history.length===0){container.innerHTML='<div class="st-modal-empty"><i class="fas fa-inbox"></i> No status history</div>';return;}
let html='';
history.forEach(h=>{
const meta=STATUS_META[h.current_status]||STATUS_META['inactive'];
const prevMeta=STATUS_META[h.previous_status]||STATUS_META['inactive'];
html+='<div class="st-tl-i"><div class="st-tl-dot" style="background:'+meta.color+'"></div><div class="st-tl-chg">';
html+='<span class="st-badge" style="background:'+prevMeta.bg+';color:'+prevMeta.color+';padding:2px 6px;font-size:10px">'+(h.previous_status||'N/A')+'</span>';
html+=' <i class="fas fa-arrow-right" style="color:var(--text-subtle);font-size:10px"></i> ';
html+='<span class="st-badge" style="background:'+meta.bg+';color:'+meta.color+';padding:2px 6px;font-size:10px">'+h.current_status+'</span>';
html+='</div>';
if(h.reason)html+='<div class="st-tl-rsn">'+escapeHTML(h.reason)+'</div>';
html+='<div class="st-tl-time">'+escapeHTML(h.changed_by_name||'System')+' \u00b7 '+new Date(h.created_at).toLocaleString()+'</div></div>';
});
container.innerHTML=html;
}catch(e){document.getElementById('modalTimeline').innerHTML='<div class="st-modal-empty"><i class="fas fa-exclamation-circle"></i> Failed to load history</div>';}
}

window.closeModal=function(){
document.getElementById('studentModal').classList.remove('show');
document.body.style.overflow='';
};
document.getElementById('studentModal').addEventListener('click',function(e){if(e.target===this)closeModal();});

/* --- Recording a status change ---
   The only write path on this page, and it is a form a person fills in.

   Two rules the old Apply button broke. It posted the new status the
   moment it was clicked, with no confirmation — one mis-click changed a
   student's record. And it sent no reason, so trackStatusChange() logged
   a null and the journal could show that someone changed and nothing
   about why. Both are fixed here: the reason is required client-side and
   rejected server-side, and the window fields are only offered for
   statuses that are actually time-boxed. */
const WINDOWED=['loa','probation','transferred'];

function toggleWindowFields(){
const sel=document.getElementById('chStatus');
const wrapped=sel?WINDOWED.indexOf(sel.value)!==-1:false;
const eff=document.getElementById('chEffectiveWrap');
const end=document.getElementById('chEndWrap');
if(eff)eff.hidden=!wrapped;
if(end)end.hidden=!wrapped;
}
window.toggleWindowFields=toggleWindowFields;

async function submitStatusChange(){
const id=window._currentModalStudentId||0;
const sel=document.getElementById('chStatus');
const reasonEl=document.getElementById('chReason');
const errEl=document.getElementById('chError');
const btn=document.getElementById('chSubmit');
const effEl=document.getElementById('chEffective');
const endEl=document.getElementById('chEnd');
const err=msg=>{if(errEl){errEl.textContent=msg;}};
err('');
if(!id){err('Open a student first.');return;}
const status=sel?sel.value:'';
const reason=reasonEl?reasonEl.value.trim():'';
if(!reason){
err('Give a reason — the status history is the only record of why this changed.');
if(reasonEl)reasonEl.focus();
return;
}
const payload={ids:[parseInt(id,10)],status:status,reason:reason};
if(WINDOWED.indexOf(status)!==-1){
const effVal=effEl?effEl.value.trim():'';
const endVal=endEl?endEl.value.trim():'';
if(!endVal){
err('A timed status needs an end date, or the window can never be seen to have closed.');
if(endEl)endEl.focus();
return;
}
payload.end_date=endVal;
if(effVal)payload.effective_date=effVal;
}
if(btn){btn.disabled=true;btn.textContent='Recording...';}
try{
const r=await fetch('../api/students.php?action=bulk-status',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
const d=await r.json();
if(!d.success)throw new Error(d.message||'The change was not recorded.');
toast('Status recorded for '+(document.getElementById('modalName').textContent||'student'),'success');
setTimeout(()=>location.reload(),900);
}catch(e){
err(e.message||'The change was not recorded.');
if(btn){btn.disabled=false;btn.textContent='Record change';}
}
}
window.submitStatusChange=submitStatusChange;

/* --- Init --- */
toggleWindowFields();
loadRisks();

})();
</script>

