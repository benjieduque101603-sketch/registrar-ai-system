<?php
// ============================================================
//  STUDENT/DASHBOARD.PHP
//  Student portal home — welcome hero, quick status,
//  and quick actions.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';

$page_title = 'Student Dashboard';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_dashboard';
$extra_css = ['student.css'];

require_once __DIR__ . '/_guard.php';

$db = Database::getInstance();

// Document request count + pending count
$docTotal = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM document_requests WHERE student_id = ?",
    [$student['id']]
);
$docPending = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM document_requests WHERE student_id = ? AND status = 'pending'",
    [$student['id']]
);

// Today's queue ticket (own)
$today = date('Y-m-d');
$queue = $db->fetchOne(
    "SELECT * FROM queue_tickets
     WHERE queue_date = ? AND student_id = ?
       AND status IN ('waiting', 'serving')
     ORDER BY joined_at DESC LIMIT 1",
    [$today, $student['id']]
);
$queuePosition = 0;
if ($queue && $queue['status'] === 'waiting') {
    $queuePosition = (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM queue_tickets
         WHERE queue_date = ? AND status = 'waiting' AND ticket_number <= ?",
        [$today, (int) $queue['ticket_number']]
    );
}

// Canonical status label
$statusLabel = getStudentStatusLabel($student['status'] ?? 'enrolled');
$enrollmentLabel = ($student['status'] ?? '') === 'enrolled'
    ? 'Currently Enrolled'
    : ucfirst(str_replace('-', ' ', strtolower((string) $student['status'])));

$fullName = trim(trim($student['first_name'] ?? '') . ' ' . trim($student['last_name'] ?? ''));
if (empty($fullName)) $fullName = htmlspecialchars($_SESSION['full_name'] ?? 'Student');
$initial  = strtoupper(substr(trim($fullName), 0, 1));
$photo    = $student['photo'] ?? '';
$photoUrl = $photo ? $APP_ROOT . ltrim($photo, './') : '';

// Time-aware greeting + dynamic body class
$hour = (int) date('G');
if ($hour < 10)       { $timeGreeting = 'Good morning';  $bodyClass = 'dawnday'; }
elseif ($hour < 16)   { $timeGreeting = 'Good afternoon'; $bodyClass = 'day'; }
elseif ($hour < 22)   { $timeGreeting = 'Good evening';   $bodyClass = 'dusk'; }
else                   { $timeGreeting = 'Good night';     $bodyClass = 'night'; }
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <!-- ── Welcome Hero ───────────────────────────────── -->
        <div class="student-intro" style="background-image: url('<?= $APP_ROOT ?>assets/images/bestlink%20banner.jpg'); background-size: cover; background-position: center;">
            <div class="intro-avatar">
                <?php if ($photoUrl): ?>
                    <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Student photo">
                <?php else: ?>
                    <?= htmlspecialchars($initial) ?>
                <?php endif; ?>
            </div>
            <div class="intro-body">
                <div class="intro-greet"><?= $timeGreeting ?></div>
                <div class="intro-title"><?= htmlspecialchars($fullName) ?> 👋</div>
                <div class="intro-meta">
                    <span class="intro-chip"><i class="fa-solid fa-id-card"></i> <?= htmlspecialchars($student['student_number'] ?? '—') ?></span>
                    <span class="intro-chip"><i class="fa-solid fa-graduation-cap"></i> <?= htmlspecialchars($student['course'] ?? '—') ?></span>
                    <span class="intro-chip"><i class="fa-solid fa-calendar-days"></i> Year <?= htmlspecialchars((string)($student['year_level'] ?? '—')) ?></span>
                    <?php if (!empty($student['section'])): ?>
                    <span class="intro-chip"><i class="fa-solid fa-people-group"></i> Section <?= htmlspecialchars($student['section']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Stat Strip ─────────────────────────────────── -->
        <div class="status-strip">
            <div class="s-item">
                <div class="s-icon blue"><i class="fa-solid fa-user-graduate"></i></div>
                <div>
                    <div class="s-value"><?= htmlspecialchars($statusLabel) ?></div>
                    <div class="s-label">Student Status</div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon green"><i class="fa-solid fa-file-lines"></i></div>
                <div>
                    <div class="s-value"><?= $docPending ?> <span style="font-size:13px;font-weight:600;color:#94a3b8;">/ <?= $docTotal ?></span></div>
                    <div class="s-label">Pending Documents</div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon purple"><i class="fa-solid fa-display"></i></div>
                <div>
                    <div class="s-value"><?= $queue ? str_pad((string)$queue['ticket_number'], 3, '0', STR_PAD_LEFT) : '—' ?></div>
                    <div class="s-label"><?= $queue ? ('Queue · ' . ucfirst($queue['status'])) : 'No queue today' ?></div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon amber"><i class="fa-solid fa-id-badge"></i></div>
                <div>
                    <div class="s-value"><?= $docTotal ?></div>
                    <div class="s-label">Total Requests</div>
                </div>
            </div>
        </div>

        <!-- ── Quick Actions ──────────────────────────────── -->
        <div class="student-quick-actions">
            <a href="<?= $APP_ROOT ?>student/grades.php" class="action-card ac-blue">
                <div class="action-icon-wrap"><i class="fa-solid fa-chart-simple"></i></div>
                <div class="action-title-row">
                    <span class="action-title">Your Grades</span>
                    <i class="fa-solid fa-arrow-right action-arrow"></i>
                </div>
            </a>
            <a href="<?= $APP_ROOT ?>student/documents.php" class="action-card ac-purple">
                <div class="action-icon-wrap"><i class="fa-solid fa-folder-closed"></i></div>
                <div class="action-title-row">
                    <span class="action-title">Request Docs</span>
                    <i class="fa-solid fa-arrow-right action-arrow"></i>
                </div>
            </a>
            <a href="<?= $APP_ROOT ?>student/queue.php" class="action-card ac-amber">
                <div class="action-icon-wrap"><i class="fa-solid fa-ticket"></i></div>
                <div class="action-title-row">
                    <span class="action-title">Queue Ticket</span>
                    <i class="fa-solid fa-arrow-right action-arrow"></i>
                </div>
            </a>
            <a href="<?= $APP_ROOT ?>student/ids.php" class="action-card ac-emerald">
                <div class="action-icon-wrap"><i class="fa-solid fa-id-badge"></i></div>
                <div class="action-title-row">
                    <span class="action-title">Digital ID</span>
                    <i class="fa-solid fa-arrow-right action-arrow"></i>
                </div>
            </a>
            <button type="button" class="action-card ac-ai" onclick="if(window.toggleStudentChat) window.toggleStudentChat();">
                <div class="action-icon-wrap"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                <div class="action-title-row">
                    <span class="action-title">Ask AI</span>
                    <i class="fa-solid fa-arrow-right action-arrow"></i>
                </div>
            </button>
        </div>

        <!-- ── Enrollment Status Panel ─────────────────────── -->
        <div class="panel" style="margin-bottom:28px;">
            <div class="panel-toolbar">
                <div class="panel-title"><i class="fa-solid fa-user-graduate" style="color:#2563eb;"></i> Enrollment Status</div>
                <?php if (($student['status'] ?? '') === 'enrolled'): ?>
                    <div class="panel-actions"><span class="pill active"><i class="fa-solid fa-circle-check"></i> Active</span></div>
                <?php endif; ?>
            </div>
            <div class="status-info-grid">
                <div class="status-info-cell">
                    <div class="sic-label">Student ID</div>
                    <div class="sic-value"><?= htmlspecialchars($student['student_number'] ?? '—') ?></div>
                </div>
                <div class="status-info-cell">
                    <div class="sic-label">Full Name</div>
                    <div class="sic-value"><?= htmlspecialchars($fullName) ?></div>
                </div>
                <div class="status-info-cell">
                    <div class="sic-label">Status</div>
                    <div class="sic-value"><?= htmlspecialchars($statusLabel) ?></div>
                </div>
                <div class="status-info-cell">
                    <div class="sic-label">Program / Course</div>
                    <div class="sic-value"><?= htmlspecialchars($student['course'] ?? '—') ?><?= !empty($student['major']) ? ' · ' . htmlspecialchars($student['major']) : '' ?></div>
                </div>
                <div class="status-info-cell">
                    <div class="sic-label">Year Level</div>
                    <div class="sic-value">Year <?= htmlspecialchars((string)($student['year_level'] ?? '—')) ?></div>
                </div>
                <div class="status-info-cell">
                    <div class="sic-label">Section</div>
                    <div class="sic-value"><?= htmlspecialchars($student['section'] ?? '—') ?></div>
                </div>
                <div class="status-info-cell">
                    <div class="sic-label">Academic Year</div>
                    <div class="sic-value"><?= htmlspecialchars($student['school_year'] ?? '—') ?></div>
                </div>
                <div class="status-info-cell">
                    <div class="sic-label">Semester</div>
                    <div class="sic-value"><?= htmlspecialchars($student['semester'] ?? '—') ?></div>
                </div>
            </div>
        </div>

        <!-- ── Queue Status ─────────────────────────────── -->
        <div class="student-grid">

            <!-- My Queue -->
            <div class="panel">
                <div class="panel-toolbar">
                    <div class="panel-title"><i class="fa-solid fa-display" style="color:#7c3aed;"></i> Queue Status</div>
                    <div class="panel-actions">
                        <a href="<?= $APP_ROOT ?>student/queue.php" class="btn btn-sm btn-light"><i class="fa-solid fa-arrow-right"></i> Manage</a>
                    </div>
                </div>
                <?php if (!$queue): ?>
                    <div style="padding:40px 24px;text-align:center;">
                        <i class="fa-solid fa-ticket" style="font-size:42px;color:#cbd5e1;margin-bottom:18px;display:block;"></i>
                        <p style="margin:0 0 8px;font-size:16px;font-weight:800;color:#475569;letter-spacing:-0.2px;">No Active Queue Ticket</p>
                        <p style="margin:0 0 20px;color:#94a3b8;font-size:14px;line-height:1.7;font-weight:500;">Scan your RFID card at any kiosk to join the queue and get a ticket number.</p>
                        <a href="<?= $APP_ROOT ?>student/queue.php" class="btn btn-sm btn-primary"><i class="fa-solid fa-display"></i> Join Queue</a>
                    </div>
                <?php else: ?>
                    <div class="queue-quick">
                        <div class="qq-number">#<?= str_pad((string)$queue['ticket_number'], 3, '0', STR_PAD_LEFT) ?></div>
                        <div class="qq-info">
                            <div class="qq-status">
                                <span class="pill <?= $queue['status'] === 'waiting' ? 'pending' : ($queue['status'] === 'serving' ? 'active' : 'inactive') ?>">
                                    <i class="fa-solid fa-<?= $queue['status'] === 'waiting' ? 'clock' : ($queue['status'] === 'serving' ? 'bell-concierge' : 'circle-check') ?>"></i>
                                    <?= ucfirst($queue['status']) ?>
                                </span>
                            </div>
                            <?php if ($queue['status'] === 'waiting'): ?>
                                <div class="qq-detail"><?= max(0, $queuePosition - 1) ?> students ahead · <?= $queuePosition === 1 ? "You're next!" : 'Position ' . $queuePosition ?></div>
                            <?php elseif ($queue['status'] === 'serving'): ?>
                                <div class="qq-detail" style="color:#059669;font-weight:800;">Your turn now · Counter <?= htmlspecialchars((string)$queue['counter']) ?></div>
                            <?php else: ?>
                                <div class="qq-detail">Completed today.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </div>
</main>

<?php include '../includes/footer.php'; ?>