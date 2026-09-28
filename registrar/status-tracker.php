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
$pageNum      = max(1, (int) ($_GET['page'] ?? 1));

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

// ── Directory: server-side search, filter and paging ─────────────
//
// Search used to run in the browser against rows already in the DOM.
// That only worked because every student was rendered: it cannot be
// carried over to paging, where a name on page 4 would be invisible to
// a client-side filter on page 1 and the search would appear to return
// nothing. It is a URL parameter now, which also makes a filtered view
// shareable and the back button meaningful.
$PER_PAGE = 25;
$where = []; $params = [];
if ($filterStatus !== '' && in_array($filterStatus, $DB_STATUSES, true)) {
    $where[] = 's.status = ?';
    $params[] = $filterStatus;
}
if ($search !== '') {
    $where[] = '(s.student_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name," ",s.last_name) LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$matchedTotal = (int) $db->fetchColumn("SELECT COUNT(*) FROM students s" . $whereSql, $params);
$totalPages   = max(1, (int) ceil($matchedTotal / $PER_PAGE));
$pageNum      = min($pageNum, $totalPages);
$offset       = ($pageNum - 1) * $PER_PAGE;

// ── Sorted by need, not by recency ───────────────────────────────
//
// This was ORDER BY last_change DESC, which put the most recently
// touched student first - very nearly the opposite of urgent, since a
// record nobody has touched in eight months outranked one flagged that
// morning. Now the flagged students sort first, and within each band
// the stalest is likeliest to be the one nobody got to.
//
// The flag is the same contradiction set the left rail shows, carried
// into SQL so paging stays correct. Sorting in PHP instead would order
// a page of results rather than the whole set, which silently reorders
// the last page.
//
// The no-flags case must produce valid SQL: a bare "0" as the first
// ORDER BY term is read by MySQL as a column position, not a literal,
// and fails with "Unknown column '0' in 'order clause'". The CASE
// wrapper is what makes it a constant rather than a reference.
$flaggedIds = array_map('intval', array_column($queueRows, 'student_id'));
if ($flaggedIds) {
    $flagSql = 'CASE WHEN s.id IN (' . implode(',', array_fill(0, count($flaggedIds), '?')) . ') THEN 0 ELSE 1 END';
    $orderParams = array_merge($flaggedIds, $params);
} else {
    $flagSql = 'CASE WHEN 1 = 1 THEN 0 ELSE 1 END';
    $orderParams = $params;
}
$lastTs = 'IFNULL(MAX(st.created_at), "1970-01-01 00:00:00")';

$sql = "SELECT s.id, s.student_number, s.first_name, s.middle_name, s.last_name,
               s.course, s.year_level, s.status, s.photo,
               MAX(st.created_at) AS last_change
        FROM students s
        LEFT JOIN status_tracker st ON st.student_id = s.id"
     . $whereSql . "
        GROUP BY s.id
        ORDER BY $flagSql ASC, $lastTs ASC, s.id DESC
        LIMIT $PER_PAGE OFFSET $offset";
$students = $db->fetchAll($sql, $orderParams);

// The distribution, and the filter, collapsed into one list in the rail.
// Recent Activity is gone: with a work queue above it and a case panel
// carrying the full history, a third timeline repeated the same rows in
// a column that pushed the directory off-screen.
$distData = [];
foreach ($DB_STATUSES as $s) { $distData[$s] = $totalStudents > 0 ? round(($counts[$s] / $totalStudents) * 100, 1) : 0; }

// Flagged ids, for the marker in the table row. Set before the ORDER BY
// above consumes them, so it is read from the queue rather than re-run.
$queueRank = [];
foreach ($queueRows as $qr) {
    $queueRank[(int) $qr['student_id']] = $qr;
}

// Builds a directory URL that preserves the other two parameters.
// Without this, filtering or searching drops the page number and the
// view jumps back to the first page, losing the reader's place.
$dirUrl = static function (array $over = []) use ($filterStatus, $search, $pageNum): string {
    $p = ['status' => $filterStatus, 'q' => $search, 'page' => $pageNum];
    foreach ($over as $k => $v) {
        $p[$k] = ($v === null || $v === '') ? '' : $v;
    }
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== null);
    return $p ? '?' . http_build_query($p) : '';
};


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
<!--
  The house header. Every other registrar page (documents, queue, users,
  academic history) uses a card: bordered, rounded, a soft blue gradient
  and align-items:flex-end, with the kicker above a 28px title. This page
  was using the plain global .header, which is why it read as a different
  screen from the ones beside it in the sidebar. Values below are copied
  from .q-head in registrar/queue.php so they match exactly rather than
  approximately.
-->
<header class="st-head">
    <div>
        <div class="st-kicker"><i class="fas fa-chart-line"></i> Registrar intelligence</div>
        <h1>Status Tracker</h1>
        <p>Monitor student status changes, review activity, and identify students who need attention.</p>
    </div>
    <div class="header-actions">
        <span class="st-head-chip <?= $queueHigh > 0 ? 'warn' : 'ok' ?>">
            <i class="fas <?= $queueHigh > 0 ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i>
            <b><?= $queueHigh ?></b> need<?= $queueHigh === 1 ? 's' : '' ?> a decision
        </span>
    </div>
</header>
<div class="st-wrap">
<!--
  One row, two columns. The directory is the page; the rail is the work
  that makes it worth opening.

  This replaced six full-width bands stacked above the table - work
  queue, KPI strip, review-tool bar, distribution bar, status pill grid,
  recent activity - which came to roughly 1400px of scrolling before a
  single student was visible. Three of those six were the same fact set:
  a distribution bar, four count tiles and eight status pills all
  describing one column of data. The counts are now the filter list, so
  they are readable and clickable rather than decorative.
-->
<div class="st-desk-grid">
  <!-- Left rail: the work -->
  <aside class="st-rail" aria-label="Students needing a decision">
    <!-- st-queue carries the state: a red top edge while there is work,
         green once there is none. -->
    <section class="st-rail-sec st-queue<?= $queueRows ? '' : ' clear' ?>">
      <div class="st-rail-head">
        <h2>Needs a decision<?php if ($queueHigh > 0): ?> <span class="st-rail-n"><?= $queueHigh ?></span><?php endif; ?></h2>
        <button type="button" class="st-rail-check" id="btnAIMissed"
                title="Re-read every record for contradictions"><i class="fas fa-rotate"></i> Check what I missed</button>
      </div>
      <?php if (!$queueRows): ?>
        <p class="st-rail-clear">
          <i class="fas fa-circle-check"></i>
          <?= number_format((int) ($queue['scanned'] ?? 0)) ?> students checked. Nothing contradicts itself.
        </p>
      <?php else: ?>
        <ul class="st-rail-list">
          <?php foreach (array_slice($queueRows, 0, 12) as $q): $m = $STATUS_META[$q['current_status']] ?? $STATUS_META['inactive']; ?>
            <li>
              <button type="button" class="st-rail-item"
                      onclick="openStudentModal(<?= (int)$q['student_id'] ?>,'<?= htmlspecialchars(addslashes($q['student_name'])) ?>','<?= htmlspecialchars($q['student_number']) ?>')">
                <span class="st-rail-item-top">
                  <span class="st-rail-item-name"><?= htmlspecialchars($q['student_name']) ?></span>
                  <span class="st-rail-item-badge" style="background:<?= $m['bg'] ?>;color:<?= $m['color'] ?>"><?= htmlspecialchars($q['current_status'] ?: 'unset') ?></span>
                </span>
                <span class="st-rail-item-why"><?= htmlspecialchars(implode(' Â· ', array_column($q['issues'], 'title'))) ?></span>
              </button>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($queueRows) > 12): ?>
          <p class="st-rail-more"><?= count($queueRows) - 12 ?> more flagged. Use <strong>Check what I missed</strong>.</p>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (!empty($queue['errors'])): ?>
        <p class="st-rail-partial" title="<?= htmlspecialchars(implode(' | ', $queue['errors'])) ?>">
          <i class="fas fa-triangle-exclamation"></i> Some records could not be read
        </p>
      <?php endif; ?>
    </section>

    <!-- Counts and filters are the same list. The inline --fill turns the
         count into a proportion bar, so this doubles as the distribution
         that used to be a separate band above the table. -->
    <section class="st-rail-sec st-filters">
      <div class="st-rail-head"><h2>By status</h2></div>
      <ul class="st-rail-flist">
        <li>
          <a class="st-rail-f<?= $filterStatus === '' ? ' on' : '' ?>"
             style="--fill:<?= $totalStudents > 0 ? 100 : 0 ?>%;--fill-color:var(--brand-400)"
             href="<?= htmlspecialchars($dirUrl(['status' => null, 'page' => 1])) ?>">
            <span class="st-rail-fdot" style="background:var(--brand-500)"></span>
            <span class="st-rail-fname">All students</span>
            <span class="st-rail-fc"><?= number_format($totalStudents) ?></span>
          </a>
        </li>
        <?php foreach ($DB_STATUSES as $s): $m = $STATUS_META[$s] ?? $STATUS_META['inactive']; ?>
          <li>
            <a class="st-rail-f<?= $filterStatus === $s ? ' on' : '' ?>"
               style="--fill:<?= (float) ($distData[$s] ?? 0) ?>%;--fill-color:<?= $m['color'] ?>"
               href="<?= htmlspecialchars($dirUrl(['status' => $s, 'page' => 1])) ?>">
              <span class="st-rail-fdot" style="background:<?= $m['color'] ?>"></span>
              <span class="st-rail-fname"><?= htmlspecialchars(ucwords(str_replace('-', ' ', $s))) ?></span>
              <span class="st-rail-fc"><?= number_format($counts[$s]) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </aside>

  <!-- Centre: the directory -->
  <div class="st-directory">
    <form class="st-dirbar" method="get" action="status-tracker.php">
      <?php if ($filterStatus !== ''): ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
      <?php endif; ?>
      <div class="st-search">
        <i class="fas fa-search"></i>
        <input type="search" name="q" placeholder="Search name or student ID"
               value="<?= htmlspecialchars($search) ?>" autocomplete="off">
      </div>
      <button type="submit" class="st-dirbar-go">Search</button>
      <?php if ($search !== '' || $filterStatus !== ''): ?>
        <a class="st-dirbar-clear" href="status-tracker.php">Clear</a>
      <?php endif; ?>
    </form>

    <div class="st-table-wrap">
      <table>
        <thead>
          <tr>
            <th>Student</th>
            <th>Course</th>
            <th>Status</th>
            <th>Last change</th>
            <th>Attention</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$students): ?>
            <tr><td colspan="5">
              <div class="st-empty">
                <i class="fas fa-inbox"></i>
                <strong>No students match</strong>
                <span><?= $search !== ''
                      ? 'Nothing found for "' . htmlspecialchars($search) . '".'
                      : 'No student has that status.' ?></span>
                <a href="status-tracker.php">Show all students</a>
              </div>
            </td></tr>
          <?php endif; ?>
          <?php foreach ($students as $s):
            $meta = $STATUS_META[$s['status']] ?? $STATUS_META['inactive'];
            $flag = isset($queueRank[(int) $s['id']]);
            $initials = strtoupper(mb_substr((string) $s['first_name'], 0, 1) . mb_substr((string) $s['last_name'], 0, 1));
            $initials = $initials !== '' ? $initials : '?';
          ?>
            <tr class="<?= $flag ? 'st-flagged' : '' ?>"
                onclick="openStudentModal(<?= (int) $s['id'] ?>,'<?= htmlspecialchars(addslashes(trim($s['first_name'].' '.$s['last_name']))) ?>','<?= htmlspecialchars($s['student_number']) ?>')"
                tabindex="0"
                onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
              <td>
                <div class="st-cell-id">
                  <span class="st-avatar" style="background:<?= $meta['color'] ?>22;color:<?= $meta['color'] ?>"><?= htmlspecialchars($initials) ?></span>
                  <span class="st-cell-who">
                    <span class="st-cell-name"><?= htmlspecialchars(trim($s['first_name'].' '.$s['last_name'])) ?></span>
                    <span class="st-cell-num"><?= htmlspecialchars($s['student_number'] ?: 'No ID assigned') ?></span>
                  </span>
                  <?php if ($flag): ?><span class="st-flagdot" title="This record contradicts itself"></span><?php endif; ?>
                </div>
              </td>
              <td><?= htmlspecialchars($s['course'] ?: 'â€”') ?></td>
              <td><span class="st-badge" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>"><?= htmlspecialchars(ucwords(str_replace('-', ' ', (string) $s['status']))) ?></span></td>
              <td class="st-cell-when"><?= $s['last_change']
                    ? date('M j, Y', strtotime((string) $s['last_change']))
                    : '<span class="st-muted">No history</span>' ?></td>
              <td><span class="st-rdot loading" data-student-id="<?= (int) $s['id'] ?>"></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Paging. The count is of what matched, not of the whole roster,
         so a filtered view never claims 100 students in a three-row
         result. -->
    <div class="st-pager">
      <span class="st-pager-info">
        <?php if ($matchedTotal === 0): ?>
          No matches
        <?php else: ?>
          <?= ($offset + 1) ?>&ndash;<?= min($offset + $PER_PAGE, $matchedTotal) ?> of <?= number_format($matchedTotal) ?>
        <?php endif; ?>
      </span>
      <?php if ($totalPages > 1): ?>
        <nav class="st-pager-nav" aria-label="Directory pages">
          <?php if ($pageNum > 1): ?>
            <a href="<?= htmlspecialchars($dirUrl(['page' => $pageNum - 1])) ?>" rel="prev" aria-label="Previous page">&lsaquo;</a>
          <?php else: ?><span class="off" aria-hidden="true">&lsaquo;</span><?php endif; ?>
          <?php for ($i = 1; $i <= $totalPages; $i++):
            if ($i === 1 || $i === $totalPages || abs($i - $pageNum) <= 1): ?>
              <a class="<?= $i === $pageNum ? 'on' : '' ?>" href="<?= htmlspecialchars($dirUrl(['page' => $i])) ?>"
                 <?= $i === $pageNum ? 'aria-current="page"' : '' ?>><?= $i ?></a>
            <?php elseif ($i === 2 || $i === $totalPages - 1): ?>
              <span class="gap" aria-hidden="true">&hellip;</span>
            <?php endif;
          endfor; ?>
          <?php if ($pageNum < $totalPages): ?>
            <a href="<?= htmlspecialchars($dirUrl(['page' => $pageNum + 1])) ?>" rel="next" aria-label="Next page">&rsaquo;</a>
          <?php else: ?><span class="off" aria-hidden="true">&rsaquo;</span><?php endif; ?>
        </nav>
      <?php endif; ?>
    </div>
  </div>
</div><!-- /st-desk-grid -->

<!--
  Review output opens as a slide-over rather than pushing the directory
  down. An inline panel meant the table moved every time a check ran, so
  the row you were reading was never in the same place twice.
-->
<div class="st-drawer" id="stDrawer" role="dialog" aria-modal="true" aria-labelledby="stDrawerTitle" hidden>
  <div class="st-drawer-scrim" onclick="closeDrawer()"></div>
  <div class="st-drawer-panel">
    <div class="st-drawer-head">
      <h2 id="stDrawerTitle"><i class="fas fa-magnifying-glass"></i> <span id="stDrawerLabel">Check</span></h2>
      <button type="button" class="st-drawer-x" onclick="closeDrawer()" aria-label="Close results"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="st-drawer-body" id="stDrawerBody">
      <div class="st-loading"><i class="fas fa-spinner fa-spin"></i> Reading records&hellip;</div>
    </div>
  </div>
</div>
</div><!-- /st-wrap -->
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

/* --- Distribution Bar ---
   Removed. The rail's "By status" list now carries the same counts, and
   a proportional bar is a third rendering of one column of data. It also
   took a full band of height above the directory, which is the thing
   this layout exists to reclaim. */

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

/* --- Review tools --- */
// The four-button bar is gone. "Check what I missed" is one link in the
// rail, because the rail already lists what it finds — the button
// explained the list rather than adding to it. The other three checks
// live behind the case panel's overflow menu.

/* --- Review drawer ---
   Results arrive in a slide-over. They used to render inline above the
   table, so the directory jumped down every time a check ran and the row
   you were reading was never in the same place twice. */
function openDrawer(label){
const d=document.getElementById('stDrawer');
if(!d)return;
d.hidden=false;
// Force a reflow so the opening transition runs on first paint.
void d.offsetWidth;
d.classList.add('open');
document.body.style.overflow='hidden';
const l=document.getElementById('stDrawerLabel');
if(l)l.textContent=label||'Check';
const x=document.querySelector('#stDrawer .st-drawer-x');
if(x)x.focus();
}
window.openDrawer=openDrawer;

function closeDrawer(){
const d=document.getElementById('stDrawer');
if(!d)return;
d.classList.remove('open');
document.body.style.overflow='';
// Wait out the transition before removing it from the tree, so the panel
// does not vanish mid-slide.
setTimeout(()=>{d.hidden=true;},200);
}
window.closeDrawer=closeDrawer;

document.addEventListener('keydown',e=>{
if(e.key==='Escape'){
const d=document.getElementById('stDrawer');
if(d&&!d.hidden){closeDrawer();return;}
}
});

function showAILoading(label){
openDrawer(label||'Check');
const body=document.getElementById('stDrawerBody');
if(body)body.innerHTML='<div class="st-loading"><i class="fas fa-spinner fa-spin"></i> Reading records…</div>';
}

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
const labels={missed:'What I missed',anomalies:'Activity check',risks:'Attention',recommendations:'Suggested transitions',all:'All checks'};
const label=labels[tab]||'Check';
showAILoading(label);
const body=document.getElementById('stDrawerBody');
if(!body)return;
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
if(count===0)html='<div class="st-empty"><i class="fas fa-circle-check"></i><strong>Nothing flagging</strong><span>These checks found nothing to act on.</span></div>';
body.innerHTML=html;
}else{
const epMap={missed:'missed_checks',anomalies:'status_anomalies',risks:'student_risks',recommendations:'status_recommendations'};
const postBody=(tab==='risks')?{student_ids:[]}:undefined;
const data=await fetchAIEndpoint(epMap[tab]||'missed_checks',postBody);
let html='';
if(tab==='missed'){html=renderMissedCards(data).html;}
else if(tab==='recommendations'){const r=data.data?.recommendations||data.recommendations||[];html=renderRecCards(r);}
else if(tab==='anomalies'){const a=data.data?.anomalies||data.anomalies||[];html=renderAnomCards(a);}
else if(tab==='risks'){const r=data.data?.risks||data.risks||{};html=renderRiskCards(r);}
body.innerHTML=html;
}
}catch(e){
body.innerHTML='<div class="st-panel-fail"><i class="fas fa-exclamation-circle"></i> Unable to run this check. The others are unaffected.</div>';
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

/* Search is server-side now.

   It used to hide rows already in the DOM, which only worked because
   every student was rendered. With paging that would silently fail: a
   name living on page 4 is not in the page-1 markup, so searching for
   it would return nothing and look like no student by that name exists.
   The input is in a GET form and submits, so the query reaches every
   row and the result is a shareable URL. */

/* --- Init --- */
const missedBtn=document.getElementById('btnAIMissed');
if(missedBtn)missedBtn.addEventListener('click',function(){runAI('missed');});

toggleWindowFields();
loadRisks();

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

})();
</script>

