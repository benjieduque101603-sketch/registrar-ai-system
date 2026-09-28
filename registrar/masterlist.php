<?php
// ============================================================
//  REGISTRAR/MASTERLIST.PHP
//  Masterlist generator — filter, sort, view student profile,
//  bulk actions, export (CSV/Excel/PDF), print
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
requireRole('registrar');

require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';

$db = Database::getInstance();

// ─── FILTERS ────────────────────────────────────────────────
$filterCourse     = isset($_GET['course']) ? trim((string) $_GET['course']) : '';
$filterYear       = isset($_GET['year_level']) ? trim((string) $_GET['year_level']) : '';
$filterSchoolYear = isset($_GET['school_year']) ? trim((string) $_GET['school_year']) : '';
$filterSemester   = isset($_GET['semester']) ? trim((string) $_GET['semester']) : '';
$filterStatus     = isset($_GET['status']) ? trim((string) $_GET['status']) : '';

// Every enrolled student appears; grouping stops at course + year level
// because sectioning belongs to another department.
$sql = "SELECT * FROM students WHERE 1=1";
$params = [];
if ($filterCourse !== '') {
    $sql .= " AND TRIM(course) = ?";
    $params[] = $filterCourse;
}
if ($filterYear !== '' && is_numeric($filterYear)) {
    $sql .= " AND year_level = ?";
    $params[] = (int) $filterYear;
}
if ($filterSchoolYear !== '') {
    $sql .= " AND school_year = ?";
    $params[] = $filterSchoolYear;
}
if ($filterSemester !== '') {
    $sql .= " AND semester = ?";
    $params[] = $filterSemester;
}
if ($filterStatus !== '') {
    $sql .= " AND status = ?";
    $params[] = $filterStatus;
}
$sql .= " ORDER BY TRIM(course) ASC, COALESCE(year_level, 0) ASC, last_name ASC, first_name ASC";

$students = $db->fetchAll($sql, $params);

// Adviser name lookup (users.id → full_name)
$advisers = $db->fetchAll("SELECT id, full_name FROM users WHERE role = 'staff' ORDER BY full_name");
$adviserNames = [];
foreach ($advisers as $ad) { $adviserNames[(int)$ad['id']] = $ad['full_name']; }

// Attach adviser names to student rows for display
foreach ($students as &$row) {
    $row['adviser_name'] = !empty($row['adviser_id']) ? ($adviserNames[(int)$row['adviser_id']] ?? null) : null;
}
unset($row);

$groups = groupStudentsForMasterlist($students);

// Dropdown data
$courses = $db->fetchAll(
    "SELECT DISTINCT TRIM(course) AS course FROM students
     WHERE course IS NOT NULL AND TRIM(course) != ''
     ORDER BY course"
);
$years = $db->fetchAll(
    "SELECT DISTINCT year_level FROM students WHERE year_level IS NOT NULL ORDER BY year_level"
);
$schoolYears = $db->fetchAll(
    "SELECT DISTINCT school_year FROM students WHERE school_year IS NOT NULL AND school_year != '' ORDER BY school_year DESC"
);
$statusOptions = ['enrolled', 'active', 'probation', 'at-risk', 'loa', 'graduated', 'transferred', 'dropped'];

// Offered courses (shared with students.php)
$offeredCourses = function_exists('getOfferedCourses') ? getOfferedCourses() : $courses;

// RFID lookup for profile modal (student_id → card info)
$rfidCards = $db->fetchAll("SELECT student_id, card_uid, status, expiry_date FROM rfid_cards");
$rfidMap = [];
foreach ($rfidCards as $rc) {
    if ($rc['student_id']) $rfidMap[$rc['student_id']] = $rc;
}

$page_title = 'Masterlist';
$page_description = 'Enrolled student masterlist';
$body_page = 'masterlist';
$APP_ROOT = '../';
$ACTIVE_NAV = 'masterlist';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
body[data-page="masterlist"]{background:#f5f7fb;color:#0f172a}
body[data-page="masterlist"] .main{padding:24px clamp(18px,2.5vw,38px) 48px;background:linear-gradient(180deg,#eef4ff 0,#f8faff 300px,#f8faff 100%)}
.masterlist-header{display:flex;flex-direction:column;gap:14px;margin-bottom:16px;padding:25px 27px;border:1px solid #c7d7fe;border-radius:19px;background:linear-gradient(120deg,#eff6ff,#fff 68%);box-shadow:0 10px 30px rgba(37,99,235,.08)}
.masterlist-kicker{display:flex;align-items:center;gap:7px;margin-bottom:7px;color:#1d4ed8;font-size:10.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.masterlist-header h1{margin:0 0 5px;font-size:28px;line-height:1.1;letter-spacing:-.03em;color:#172554}
.masterlist-header p{max-width:680px;margin:0;font-size:12.5px;line-height:1.5;color:#64748b}
.masterlist-actionbar{display:flex;align-items:stretch;gap:12px;flex-wrap:wrap;margin:0 0 16px;padding:12px 14px;border:1px solid #dbeafe;border-radius:16px;background:#fff;box-shadow:0 7px 24px rgba(15,23,42,.04)}
.masterlist-action-group{flex:1 1 320px;min-width:0;display:flex;flex-direction:column;gap:8px;padding:11px 13px;border:1px solid #e2e8f0;border-radius:13px;background:#f8faff}
.masterlist-action-label{padding-left:2px;color:#1d4ed8;font-size:9.5px;font-weight:800;letter-spacing:.09em;text-transform:uppercase}
.masterlist-action-buttons{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.masterlist-action-buttons .btn{min-height:34px;padding:0 12px;font-size:12px}
.masterlist-action-buttons .export-wrap{display:flex}
.masterlist-toolbar{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin:0 0 16px;padding:13px 16px;border:1px solid #dbeafe;border-radius:14px;background:#fff;box-shadow:0 7px 24px rgba(15,23,42,.04)}
.masterlist-search{position:relative;flex:1 1 300px;min-width:220px}
.masterlist-search i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#64748b;font-size:13px;pointer-events:none}
.masterlist-search input{width:100%;height:40px;box-sizing:border-box;padding:0 12px 0 36px;border:1px solid #cbd5e1;border-radius:9px;background:#f8faff;color:#1e293b;font:13px Inter,sans-serif}
.masterlist-search input:focus{outline:0;border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.1)}
.masterlist-ai{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
.masterlist-ai i{color:#7c3aed}
.masterlist-filter-btn{display:inline-flex;align-items:center;gap:7px;white-space:nowrap}
body[data-page="masterlist"] .card{border:1px solid #dbeafe!important;border-radius:16px!important;background:#fff!important;box-shadow:0 8px 24px rgba(15,23,42,.045)!important}
body[data-page="masterlist"] .masterlist-section-block{overflow:hidden;margin-bottom:16px!important;border:1px solid #dbeafe!important;border-radius:16px!important;box-shadow:0 8px 24px rgba(15,23,42,.045)!important}
body[data-page="masterlist"] .masterlist-section-block>div:first-child{background:#f8faff;border-bottom-color:#e5e7eb}
body[data-page="masterlist"] .masterlist-table th{background:#f8fafc!important;color:#475569!important;padding:11px 12px!important;font-size:10px!important;letter-spacing:.05em}
body[data-page="masterlist"] .masterlist-table td{padding:10px 12px!important}
body[data-page="masterlist"] .masterlist-table tbody tr:hover{background:#eff6ff!important}
@media(max-width:640px){.masterlist-header{padding:21px 18px}.masterlist-header h1{font-size:25px}.masterlist-actionbar{flex-direction:column}.masterlist-action-group{width:100%}.masterlist-action-buttons .btn{flex:1 1 100%;justify-content:center}.masterlist-toolbar{align-items:stretch}.masterlist-search{flex-basis:100%}.masterlist-ai,.masterlist-filter-btn{justify-content:center}}
</style>

<main class="main">
    <header class="masterlist-header">
        <div>
            <div class="masterlist-kicker"><i class="fas fa-table-list"></i> Registrar directory</div>
            <h1>Masterlist</h1>
            <p>Search, filter, and manage enrolled students.</p>
        </div>
    </header>

    <!-- Action bar: view tools + output -->
    <section class="masterlist-actionbar" aria-label="Masterlist actions">
        <div class="masterlist-action-group">
            <span class="masterlist-action-label">Masterlist</span>
            <div class="masterlist-action-buttons">
                <button class="btn btn-secondary" onclick="openGenerateModal()">
                    <i class="fas fa-sliders"></i> Generate
                </button>
            </div>
        </div>
        <div class="masterlist-action-group">
            <span class="masterlist-action-label">Output &amp; handoff</span>
            <div class="masterlist-action-buttons">
                <button class="btn btn-secondary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
                <button type="button" class="btn btn-primary" onclick="sendList()" title="Send the masterlist to the Academic Strand / Course Assignment module (CMS)">
                    <i class="fas fa-paper-plane"></i> Send List
                </button>
                <div class="export-wrap" style="position:relative;">
                    <button class="btn btn-secondary" id="exportBtn"><i class="fas fa-download"></i> Export</button>
                    <div class="export-menu" id="exportMenu" style="position:absolute;top:100%;right:0;z-index:50;background:white;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.1);min-width:160px;padding:4px;margin-top:4px;display:none;">
                        <a href="#" onclick="exportCSV()" style="display:block;padding:8px 12px;font-size:12px;font-weight:600;color:#1e293b;text-decoration:none;border-radius:6px;"><i class="fas fa-file-csv"></i> Export CSV</a>
                        <a href="#" onclick="exportExcel()" style="display:block;padding:8px 12px;font-size:12px;font-weight:600;color:#1e293b;text-decoration:none;border-radius:6px;"><i class="fas fa-file-excel"></i> Export Excel</a>
                        <a href="#" onclick="window.print()" style="display:block;padding:8px 12px;font-size:12px;font-weight:600;color:#1e293b;text-decoration:none;border-radius:6px;"><i class="fas fa-file-pdf"></i> Export PDF</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Toolbar: search bar + filter button -->
    <section class="masterlist-toolbar" aria-label="Masterlist filters">
        <div class="masterlist-search">
            <i class="fas fa-search"></i>
            <input type="text" id="masterlistSearch" name="q" class="form-control" placeholder="Search by name, student no., course…">
        </div>
        <button type="button" class="btn btn-secondary masterlist-ai" id="aiSearchBtn" title="Ask AI to build the filters for you - e.g. 'at-risk BSIT 3rd year'">
            <i class="fas fa-wand-magic-sparkles"></i> AI
        </button>
        <button type="button" class="btn btn-primary masterlist-filter-btn" onclick="openFilterSearchModal()">
            <i class="fas fa-sliders"></i> Filter
            <?php if ($filterCourse !== '' || $filterYear !== '' || $filterSchoolYear !== '' || $filterSemester !== '' || $filterStatus !== ''): ?>
                <span style="background:#dc2626;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;">Active</span>
            <?php endif; ?>
        </button>
        <?php if (!empty($groups)): ?>
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#475569;cursor:pointer;">
            <input type="checkbox" id="selectAllPage" style="width:16px;height:16px;accent-color:#2563eb;"> Select all shown
        </label>
        <span style="font-size:13px;color:#64748b;">Showing <strong id="showingCount"><?= count($students) ?></strong> student(s)</span>
        <?php endif; ?>
    </section>

    <!-- AI interpretation banner (below the search bar) -->
    <div id="aiInterpretation" style="display:none;padding:10px 14px;background:#eef4ff;border:1px solid #bfdbfe;border-radius:10px;margin-bottom:16px;box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
        <i class="fas fa-brain" style="color:#2563eb;"></i>
        <span id="aiExplanation" style="color:#1e40af;margin-left:8px;font-size:13px;"></span>
    </div>

    <!-- Bulk action bar -->
    <div class="bulk-bar" id="bulkBar" style="display:none;padding:10px 16px;background:#eef4ff;border:1px solid #bfdbfe;border-radius:12px;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
        <span style="font-size:13px;font-weight:600;color:#1d4ed8;" id="bulkCount">0 selected</span>
        <button class="btn btn-secondary btn-sm" onclick="exportSelectedCSV()"><i class="fas fa-file-csv"></i> Export CSV</button>
        <button class="btn btn-secondary btn-sm" onclick="printSelected()"><i class="fas fa-print"></i> Print</button>
        <button class="btn btn-danger btn-sm" onclick="bulkArchive()"><i class="fas fa-archive"></i> Archive</button>
        <a class="btn btn-secondary btn-sm" href="rfid-cards.php"><i class="fas fa-credit-card"></i> Assign RFID</a>
    </div>

    <div id="masterlistContent">
        <?php if (empty($groups)): ?>
            <div class="card" style="box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="padding: 48px 24px; text-align: center; color: #64748b;">
                    <i class="fas fa-layer-group" style="font-size:40px;color:#e2e8f0;display:block;margin-bottom:14px;"></i>
                    <p style="font-size:16px;font-weight:600;color:#334155;margin:0 0 8px;">No students found</p>
                    <p style="margin:0;">No students match your filters, or no students have been enrolled yet.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($groups as $group):
                $count = count($group['students']);
                $groupTitle = htmlspecialchars($group['course']) . ' — Year ' . htmlspecialchars($group['year_level']);
                $groupAdviser = '';
                foreach ($group['students'] as $st) {
                    if (!empty($st['adviser_name'])) { $groupAdviser = $st['adviser_name']; break; }
                }
            ?>
                <div class="card masterlist-section-block" style="margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.12);">
                    <div style="padding: 14px 16px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <h2 style="margin: 0; font-size: 15px; font-weight: 700; color: #0f172a;">
                            <i class="fas fa-users" style="color: #2563eb; margin-right: 8px;"></i><?= $groupTitle ?>
                        </h2>
                        <span class="badge badge-success" style="font-size: 12px;">
                            <?= $count ?> student<?= $count === 1 ? '' : 's' ?>
                        </span>
                        <?php if ($groupAdviser !== ''): ?>
                            <span style="font-size: 12px; color: #475569;"><i class="fas fa-chalkboard-user" style="color: #7c3aed; margin-right: 6px;"></i>Adviser: <strong><?= htmlspecialchars($groupAdviser) ?></strong></span>
                        <?php endif; ?>
                        <button class="btn btn-secondary btn-sm" style="padding:5px 12px;font-size:12px;" onclick='handoffGroup(<?= htmlspecialchars(json_encode($groupTitle), ENT_QUOTES) ?>)' title="Hand off this group to the CMS (Academic Strand module)"><i class="fas fa-paper-plane"></i> HAND-OFF</button>
                    </div>
                    <div style="overflow-x: auto; border-radius: 12px; overflow: hidden;">
                        <table class="masterlist-table" style="width: 100%; border-collapse: collapse; font-size: 13px; word-wrap: break-word; word-break: break-word;">
                            <thead>
                                <tr style="background: #1a2d4a; color: white;">
                                    <th style="padding: 10px 12px; text-align: center; width: 34px;"><input type="checkbox" class="block-select-all" style="width:15px;height:15px;accent-color:#2563eb;" title="Select this block"></th>
                                    <th style="padding: 10px 12px; text-align: left; white-space: nowrap;">#</th>
                                    <th style="padding: 10px 12px; text-align: left; cursor:pointer; white-space: nowrap;" data-sort="student_number"><i class="fas fa-sort" style="font-size:10px;margin-right:4px;"></i>Student ID</th>
                                    <th style="padding: 10px 12px; text-align: left; cursor:pointer; white-space: nowrap;" data-sort="name"><i class="fas fa-sort" style="font-size:10px;margin-right:4px;"></i>Name</th>
                                    <th style="padding: 10px 12px; text-align: left; cursor:pointer; white-space: nowrap;" data-sort="course"><i class="fas fa-sort" style="font-size:10px;margin-right:4px;"></i>Course</th>
                                    <th style="padding: 10px 12px; text-align: left; cursor:pointer; white-space: nowrap;" data-sort="year_level"><i class="fas fa-sort" style="font-size:10px;margin-right:4px;"></i>Year</th>
                                    <th style="padding: 10px 12px; text-align: left; white-space: nowrap;">S.Y.</th>
                                    <th style="padding: 10px 12px; text-align: left; white-space: nowrap;">Sem</th>
                                    <th style="padding: 10px 12px; text-align: left; white-space: nowrap;">Adviser</th>
                                    <th style="padding: 10px 12px; text-align: left; white-space: nowrap;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($group['students'] as $student): ?>
                                    <tr style="border-bottom: 1px solid #e2e8f0;" data-student-id="<?= (int)$student['id'] ?>">
                                        <td style="padding: 8px 12px; text-align: center;"><input type="checkbox" class="student-cb" value="<?= (int)$student['id'] ?>" style="width:15px;height:15px;accent-color:#2563eb;"></td>
                                        <td style="padding: 8px 12px; white-space: nowrap;"><?= $i++ ?></td>
                                        <td style="padding: 8px 12px; font-weight:600; font-size:12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($student['student_number']) ?>"><?= htmlspecialchars($student['student_number']) ?></td>
                                        <td style="padding: 8px 12px; max-width: 200px; white-space: normal; word-break: break-word;"><a href="javascript:void(0)" onclick="viewStudent(<?= (int)$student['id'] ?>)" style="color:#2563eb;font-weight:600;text-decoration:none;cursor:pointer;"><?= htmlspecialchars($student['last_name']) ?>, <?= htmlspecialchars($student['first_name']) ?></a></td>
                                        <td style="padding: 8px 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($student['course'] ?? 'N/A') ?>"><?= htmlspecialchars($student['course'] ?? 'N/A') ?></td>
                                        <td style="padding: 8px 12px; white-space: nowrap;"><?= htmlspecialchars($student['year_level'] ?? 'N/A') ?></td>
                                        <td style="padding: 8px 12px; white-space: nowrap;"><?= htmlspecialchars($student['school_year'] ?? '—') ?></td>
                                        <td style="padding: 8px 12px; white-space: nowrap;"><?= htmlspecialchars($student['semester'] ?? '—') ?></td>
                                        <td style="padding: 8px 12px; max-width: 150px; white-space: normal; word-break: break-word; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($student['adviser_name'] ?? '—') ?>"><?= htmlspecialchars($student['adviser_name'] ?? '—') ?></td>
                                        <td style="padding: 8px 12px; white-space: nowrap;">
                                            <span class="badge badge-<?= in_array($student['status'], ['active', 'enrolled'], true) ? 'success' : ($student['status'] === 'at-risk' || $student['status'] === 'probation' ? 'warning' : 'neutral') ?>">
                                                <?= ucfirst($student['status'] ?? 'Active') ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($groups)): ?>
    <div class="card" style="margin-top: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div class="table-footer">
            <div class="info-text">
                Total: <strong><?= count($students) ?></strong> students in <strong><?= count($groups) ?></strong> group(s)
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<!-- Filter Masterlist Modal -->
<div class="modal-overlay" id="filterSearchModal">
    <div class="modal-content" style="max-width: 620px;">
        <div class="modal-header"><h2 style="font-size:18px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:10px;"><i class="fas fa-filter" style="color:#2563eb;"></i> Filter Masterlist</h2><button class="modal-close" onclick="closeFilterSearchModal()"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <form method="get" action="masterlist.php" id="filterSearchForm">
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group"><label>Course</label>
                        <select name="course" id="filterCourse" class="form-control">
                            <option value="">All courses</option>
                            <?php foreach ($courses as $row): ?>
                                <option value="<?= htmlspecialchars($row['course']) ?>" <?= $filterCourse === $row['course'] ? 'selected' : '' ?>><?= htmlspecialchars($row['course']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                
                    <div class="form-group"><label>Year</label>
                        <select name="year_level" id="filterYear" class="form-control">
                            <option value="">All years</option>
                            <?php foreach ($years as $row): ?>
                                <option value="<?= (int) $row['year_level'] ?>" <?= $filterYear === (string) $row['year_level'] ? 'selected' : '' ?>>Year <?= (int) $row['year_level'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>School Year</label>
                        <select name="school_year" id="filterSchoolYear" class="form-control">
                            <option value="">All</option>
                            <?php foreach ($schoolYears as $row): ?>
                                <option value="<?= htmlspecialchars($row['school_year']) ?>" <?= $filterSchoolYear === $row['school_year'] ? 'selected' : '' ?>><?= htmlspecialchars($row['school_year']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Semester</label>
                        <select name="semester" id="filterSemester" class="form-control">
                            <option value="">All</option>
                            <option value="1st" <?= $filterSemester === '1st' ? 'selected' : '' ?>>1st Sem</option>
                            <option value="2nd" <?= $filterSemester === '2nd' ? 'selected' : '' ?>>2nd Sem</option>
                            <option value="summer" <?= $filterSemester === 'summer' ? 'selected' : '' ?>>Summer</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Status</label>
                        <select name="status" id="filterStatus" class="form-control">
                            <option value="">All statuses</option>
                            <?php foreach ($statusOptions as $st): ?>
                                <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeFilterSearchModal()">Cancel</button>
            <?php if ($filterCourse !== '' || $filterYear !== '' || $filterSchoolYear !== '' || $filterSemester !== '' || $filterStatus !== ''): ?>
                <a class="btn btn-light" href="masterlist.php">Clear</a>
            <?php endif; ?>
            <button type="submit" form="filterSearchForm" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
        </div>
    </div>
</div>

<!-- Generate Masterlist Modal -->
<div class="modal-overlay" id="generateModal">
    <div class="modal-content" style="max-width: 620px;">
        <div class="modal-header"><h2 style="font-size:18px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:10px;"><i class="fas fa-sliders" style="color:#2563eb;"></i> Generate Masterlist</h2><button class="modal-close" onclick="closeGenerateModal()"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <form id="generateForm">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group"><label>School Year</label><select id="genSchoolYear" class="form-control"><option value="">All</option><?php foreach ($schoolYears as $row): ?><option value="<?= htmlspecialchars($row['school_year']) ?>"><?= htmlspecialchars($row['school_year']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Semester</label><select id="genSemester" class="form-control"><option value="">All</option><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="summer">Summer</option></select></div>
                    <div class="form-group"><label>Course</label><select id="genCourse" class="form-control"><option value="">All courses</option><?php foreach ($courses as $row): ?><option value="<?= htmlspecialchars($row['course']) ?>"><?= htmlspecialchars($row['course']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Year Level</label><select id="genYear" class="form-control"><option value="">All years</option><?php foreach ($years as $row): ?><option value="<?= (int)$row['year_level'] ?>">Year <?= (int)$row['year_level'] ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Status</label><select id="genStatus" class="form-control"><option value="">All statuses</option><?php foreach ($statusOptions as $st): ?><option value="<?= $st ?>"><?= ucfirst($st) ?></option><?php endforeach; ?></select></div>
                </div>
                <p style="font-size:12px;color:#94a3b8;margin-top:8px;"><i class="fas fa-info-circle"></i> Course serves as the department filter. Leave fields blank to include all.</p>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeGenerateModal()">Cancel</button>
            <button class="btn btn-primary" onclick="applyGenerate()"><i class="fas fa-table-list"></i> Generate</button>
        </div>
    </div>
</div>

<!-- Student Profile Modal (tabbed) -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-content" style="max-width:620px;">
        <div class="modal-header"><h2 style="font-size:18px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:10px;"><i class="fas fa-id-card" style="color:#2563eb;"></i> Student Profile</h2><button class="modal-close" onclick="closeViewModal()"><i class="fas fa-times"></i></button></div>
        <div style="display:flex;gap:4px;margin-bottom:14px;border-bottom:1px solid #e2e8f0;">
            <button class="vtab active" onclick="switchVTab(this,'profile')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#2563eb;cursor:pointer;border-bottom:2px solid #2563eb;font-family:inherit;"><i class="fas fa-user"></i> Profile</button>
            <button class="vtab" onclick="switchVTab(this,'academic')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit;"><i class="fas fa-school"></i> Academic</button>
            <button class="vtab" onclick="switchVTab(this,'health')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit;"><i class="fas fa-heartbeat"></i> Health</button>
            <button class="vtab" onclick="switchVTab(this,'documents')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit;"><i class="fas fa-file"></i> Documents</button>
            <button class="vtab" onclick="switchVTab(this,'rfid')" style="padding:8px 14px;border:none;background:none;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit;"><i class="fas fa-credit-card"></i> RFID</button>
        </div>
        <div class="modal-body">
            <div class="vtab-content active" id="tabProfile">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Name</div><div class="val" id="vName" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Student No.</div><div class="val" id="vStudentId" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Course</div><div class="val" id="vCourse" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Year Level</div><div class="val" id="vYearSection" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">S.Y. / Sem</div><div class="val" id="vSchoolYearSem" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Status</div><div class="val" id="vStatus" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Gender</div><div class="val" id="vGender" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Adviser</div><div class="val" id="vAdviser" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Email</div><div class="val" id="vEmail" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Contact</div><div class="val" id="vContact" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                    <div style="grid-column:span 2;"><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Address</div><div class="val" id="vAddress" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
                </div>
            </div>
            <div class="vtab-content" id="tabAcademic" style="display:none;"><div id="vAcademic" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
            <div class="vtab-content" id="tabHealth" style="display:none;"><div id="vHealth" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
            <div class="vtab-content" id="tabDocuments" style="display:none;"><div id="vDocuments" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
            <div class="vtab-content" id="tabRfid" style="display:none;"><div id="vRfid" style="padding:8px 0;"><p style="color:#94a3b8;font-size:13px;">Loading...</p></div></div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary" onclick="closeViewModal()">Close</button></div>
    </div>
</div>

<script>
const RFID_MAP = <?= json_encode(array_map(fn($c) => ['card_uid' => $c['card_uid'], 'status' => $c['status'], 'expiry_date' => $c['expiry_date']], $rfidMap)) ?>;
const ADVISER_NAMES = <?= json_encode($adviserNames) ?>;
const OFFERED_COURSES = <?= json_encode(array_keys($offeredCourses)) ?>;

// ─── SEARCH (client-side) ────────────────────────────────────
const searchInput = document.getElementById('masterlistSearch');
searchInput?.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('#masterlistContent .masterlist-table tbody tr').forEach(row => {
        const text = row.textContent.toLowerCase();
        const show = !q || text.includes(q);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('showingCount').textContent = visible;
});

// ─── SORT (within each group block) ───────────────────────────
document.querySelectorAll('#masterlistContent .masterlist-table th[data-sort]').forEach(th => {
    th.addEventListener('click', function () {
        const key = this.dataset.sort;
        const tbody = this.closest('table').querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const dir = this._dir === 'asc' ? 'desc' : 'asc';
        this._dir = dir;
        rows.forEach(r => r._key = rowSortKey(r, key));
        rows.sort((a, b) => (a._key < b._key ? -1 : a._key > b._key ? 1 : 0) * (dir === 'asc' ? 1 : -1));
        rows.forEach(r => tbody.appendChild(r));
        // re-number
        tbody.querySelectorAll('tr').forEach((r, idx) => { const cells = r.querySelectorAll('td'); if (cells.length > 1) cells[1].textContent = idx + 1; });
    });
});
function rowSortKey(row, key) {
    const cells = row.querySelectorAll('td');
    const idx = { name: 3, student_number: 2, course: 4, year_level: 5 }[key] ?? 2;
    const v = cells[idx] ? cells[idx].textContent.trim() : '';
    if (key === 'year_level') return String(parseInt(v) || 0).padStart(3, '0');
    return v.toLowerCase();
}

// ─── GENERATE MODAL ──────────────────────────────────────────
function openGenerateModal() {
    document.getElementById('generateModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeGenerateModal() {
    document.getElementById('generateModal').classList.remove('active');
    document.body.style.overflow = '';
}
function applyGenerate() {
    const p = new URLSearchParams();
    const set = (id, name) => { const v = document.getElementById(id).value; if (v) p.set(name, v); };
    set('genSchoolYear', 'school_year');
    set('genSemester', 'semester');
    set('genCourse', 'course');
    set('genYear', 'year_level');
    set('genStatus', 'status');
    window.location.href = 'masterlist.php?' + p.toString();
}
document.getElementById('generateModal').addEventListener('click', function (e) { if (e.target === this) closeGenerateModal(); });

// ─── EXPORT DROPDOWN ─────────────────────────────────────────
document.getElementById('exportBtn').addEventListener('click', function (e) {
    e.stopPropagation();
    document.getElementById('exportMenu').style.display = document.getElementById('exportMenu').style.display === 'block' ? 'none' : 'block';
});
document.addEventListener('click', function () { document.getElementById('exportMenu').style.display = 'none'; });

// ─── BULK SELECT ─────────────────────────────────────────────
document.getElementById('selectAllPage')?.addEventListener('change', function () {
    document.querySelectorAll('#masterlistContent .student-cb').forEach(cb => cb.checked = this.checked);
    updateBulkBar();
});
document.querySelectorAll('.block-select-all').forEach(cb => {
    cb.addEventListener('change', function () {
        this.closest('table').querySelectorAll('.student-cb').forEach(rowCb => rowCb.checked = this.checked);
        updateBulkBar();
    });
});
document.querySelectorAll('#masterlistContent .student-cb').forEach(cb => cb.addEventListener('change', updateBulkBar));
function updateBulkBar() {
    const checked = document.querySelectorAll('#masterlistContent .student-cb:checked').length;
    const bar = document.getElementById('bulkBar');
    bar.style.display = checked > 0 ? 'flex' : 'none';
    document.getElementById('bulkCount').textContent = checked + ' selected';
    document.getElementById('selectAllPage').checked = checked > 0 && checked === document.querySelectorAll('#masterlistContent .student-cb').length;
}
function selectedRows() {
    return Array.from(document.querySelectorAll('#masterlistContent .student-cb:checked'))
        .map(cb => cb.closest('tr'));
}
function exportSelectedCSV() { exportCSV(selectedRows()); }
function printSelected() { printRows(selectedRows()); }
async function bulkArchive() {
    const rows = selectedRows();
    if (!rows.length) return;
    if (!await confirmAction({
        title: 'Archive students',
        body: 'Archive <strong>' + rows.length + '</strong> selected student' + (rows.length === 1 ? '' : 's') +
              '? This can be undone by restoring.',
        confirmLabel: 'Archive'
    })) return;
    const ids = rows.map(r => r.dataset.studentId);
    fetch('../api/students.php?action=bulk-status', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids, status: 'archived' })
    }).then(r => r.json()).then(d => {
        if (d.success) { showToast(d.message || 'Archived.', 'success'); window.location.reload(); }
        else showToast(d.message || 'Archive failed.', 'error');
    }).catch(() => showToast('Network error.', 'error'));
}

// ─── EXPORT CSV ──────────────────────────────────────────────
function collectRowData(rows) {
    const out = [];
    rows.forEach(row => {
        const cols = row.querySelectorAll('td');
        if (cols.length < 10) return;
        const t = i => cols[i].textContent.trim();
        out.push([t(2), t(3), t(4), t(5), t(6), t(7), t(8), t(9)]);
    });
    return out;
}
function exportCSV(rows) {
    rows = rows || Array.from(document.querySelectorAll('#masterlistContent .masterlist-table tbody tr'));
    let csv = 'Student ID,Name,Course,Year,S.Y.,Semester,Adviser,Status\n';
    const escape = v => '"' + String(v).replace(/"/g, '""') + '"';
    collectRowData(rows).forEach(r => csv += r.map(escape).join(',') + '\n');
    downloadBlob(new Blob([csv], { type: 'text/csv' }), 'masterlist.csv');
}
function exportExcel() {
    const rows = Array.from(document.querySelectorAll('#masterlistContent .masterlist-table tbody tr'));
    let html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body><table border="1"><tr><th>#</th><th>Student ID</th><th>Name</th><th>Course</th><th>Year</th><th>S.Y.</th><th>Sem</th><th>Adviser</th><th>Status</th></tr>';
    rows.forEach(row => {
        const cols = row.querySelectorAll('td');
        if (cols.length < 10) return;
        const cells = Array.from(cols).slice(0, 10).map(c => '<td>' + String(c.textContent.trim()).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</td>');
        html += '<tr>' + cells.join('') + '</tr>';
    });
    html += '</table></body></html>';
    downloadBlob(new Blob([html], { type: 'application/vnd.ms-excel' }), 'masterlist.xls');
}
function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = filename; a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

// ─── PRINT (Official Masterlist sheet w/ logo + signature) ────
function printRows(rows) {
    const w = window.open();
    const sy = document.getElementById('filterSchoolYear') ? document.getElementById('filterSchoolYear').value : '';
    w.document.write('<!DOCTYPE html><html><head><title>Masterlist</title><style>');
    w.document.write('@page { size: A4 landscape; margin: 12mm; }');
    w.document.write('body { font-family: Arial, sans-serif; font-size: 11px; color: #0f172a; -webkit-print-color-adjust: exact; }');
    w.document.write('.letterhead { display:flex; align-items:center; gap:12px; border-bottom:3px double #1a2d4a; padding-bottom:8px; margin-bottom:10px; }');
    w.document.write('.letterhead img { width:52px; height:52px; object-fit:contain; }');
    w.document.write('.lh-text { flex:1; text-align:center; }');
    w.document.write('.lh-text .school { font-size:15px; font-weight:700; letter-spacing:.3px; }');
    w.document.write('.lh-text .sub { font-size:10px; color:#475569; margin-top:2px; }');
    w.document.write('.lh-text .title { font-size:12px; font-weight:700; margin-top:6px; }');
    w.document.write('h3.group { margin:14px 0 6px; font-size:12px; background:#1a2d4a; color:#fff; padding:5px 8px; border-radius:3px; }');
    w.document.write('table { width:100%; border-collapse:collapse; margin-bottom:6px; }');
    w.document.write('th,td { padding:5px 7px; border:1px solid #999; text-align:left; font-size:10px; }');
    w.document.write('th { background:#eef2f7; color:#0f172a; font-weight:700; }');
    w.document.write('.sig { display:flex; justify-content:space-between; margin-top:26px; padding-top:6px; }');
    w.document.write('.sig .box { text-align:center; width:44%; }');
    w.document.write('.sig .line { border-top:1px solid #0f172a; margin-top:28px; padding-top:4px; font-size:10px; }');
    w.document.write('</style></head><body>');
    w.document.write('<div class="letterhead"><img src="../assets/images/BCP_LOGO.png" alt="BCP" onerror="this.style.display=\'none\'">' +
        '<div class="lh-text"><div class="school">BESTLINK COLLEGE OF THE PHILIPPINES</div>' +
        '<div class="sub">812 A. Luna St., Barangay Tatalon, Quezon City · registrar@bestlink.edu.ph</div>' +
        '<div class="title">OFFICIAL MASTERLIST OF STUDENTS' + (sy ? ' — S.Y. ' + sy : '') + '</div></div></div>');

    if (rows && rows.length) {
        rows.forEach(row => {
            const cols = row.querySelectorAll('td');
            if (cols.length < 11) return;
            const t = i => cols[i].textContent.trim();
            const course = t(4), year = t(5), name = t(3), sn = t(2), gender = t(7), status = t(8);
            w.document.write('<h3>' + course + ' — Year ' + (year || '—') + '</h3>');
            w.document.write('<table><tr><th>#</th><th>Student No.</th><th>Name</th><th>Gender</th><th>Status</th></tr>');
            // Single row per matched row
            w.document.write('<tr><td>1</td><td>' + sn + '</td><td>' + name + '</td><td>' + gender + '</td><td>' + status + '</td></tr></table>');
        });
        if (!rows.length) w.document.write('<p>No records to print.</p>');
    } else {
        w.document.write('<p>No records to print.</p>');
    }
    w.document.write('<div class="sig"><div class="box"><div class="line">Prepared by:<br>Registrar</div></div>' +
        '<div class="box"><div class="line">Approved by:<br>School Head / President</div></div></div>');
    w.document.write('</body></html>');
    w.document.close();
    w.print();
}

// ─── VIEW STUDENT PROFILE ────────────────────────────────────
let currentViewId = null;
function viewStudent(id) {
    currentViewId = id;
    fetch('../api/students.php?id=' + id).then(r => r.json()).then(d => {
        if (!d.success || !d.data) return;
        const s = d.data;
        document.getElementById('vName').textContent = s.first_name + ' ' + s.last_name;
        document.getElementById('vStudentId').textContent = s.student_number || 'ID not yet assigned';
        document.getElementById('vCourse').textContent = s.course || '—';
        document.getElementById('vYearSection').textContent = s.year_level ? s.year_level + ' Year' : '—';
        document.getElementById('vSchoolYearSem').textContent = (s.school_year ? s.school_year : '—') + (s.semester ? ' — ' + s.semester : '');
        document.getElementById('vStatus').innerHTML = '<span class="badge badge-' + (s.status === 'active' ? 'success' : s.status === 'at-risk' || s.status === 'probation' ? 'warning' : 'neutral') + '">' + ucfirst(s.status || 'Active') + '</span>';
        document.getElementById('vGender').textContent = s.gender || '—';
        document.getElementById('vAdviser').textContent = (s.adviser_id && ADVISER_NAMES[s.adviser_id]) ? ADVISER_NAMES[s.adviser_id] : '—';
        document.getElementById('vEmail').textContent = s.email || '—';
        document.getElementById('vContact').textContent = s.contact_number || '—';
        document.getElementById('vAddress').textContent = s.address || '—';
        // Reset tabs
        document.querySelectorAll('#viewModal .vtab').forEach(t => { t.style.borderBottomColor = 'transparent'; t.style.color = '#64748b'; });
        document.querySelector('#viewModal .vtab').style.borderBottomColor = '#2563eb';
        document.querySelector('#viewModal .vtab').style.color = '#2563eb';
        document.querySelectorAll('#viewModal .vtab-content').forEach(t => t.style.display = 'none');
        document.getElementById('tabProfile').style.display = '';
        loadAcademic(s.id);
        loadHealth(s.id);
        loadDocuments(s.id);
        loadRfid(s.id);
        document.getElementById('viewModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }).catch(() => showToast('Failed to load.', 'error'));
}
function switchVTab(btn, tab) {
    document.querySelectorAll('#viewModal .vtab').forEach(t => { t.style.borderBottomColor = 'transparent'; t.style.color = '#64748b'; });
    btn.style.borderBottomColor = '#2563eb';
    btn.style.color = '#2563eb';
    document.querySelectorAll('#viewModal .vtab-content').forEach(t => t.style.display = 'none');
    document.getElementById('tab' + tab.charAt(0).toUpperCase() + tab.slice(1)).style.display = '';
}
function closeViewModal() { document.getElementById('viewModal').classList.remove('active'); document.body.style.overflow = ''; }
document.getElementById('viewModal').addEventListener('click', function (e) { if (e.target === this) closeViewModal(); });

function loadAcademic(sid) {
    fetch('../api/students.php?action=academic&student_id=' + sid).then(r => r.json()).then(d => {
        const el = document.getElementById('vAcademic');
        if (!d.success || !d.data || !d.data.length) { el.innerHTML = '<p style="color:#94a3b8;font-size:13px;">No academic history found.</p>'; return; }
        el.innerHTML = '<table style="width:100%;font-size:12px;"><tr style="color:#64748b;font-weight:600;"><td>School</td><td>Year</td><td>GWA</td></tr>' + d.data.map(a => '<tr style="border-bottom:1px solid #f1f5f9;"><td>' + (a.school_name || '') + '</td><td>' + (a.school_year || '') + '</td><td>' + (a.gwa || '—') + '</td></tr>').join('') + '</table>';
    }).catch(() => {});
}
function loadHealth(sid) {
    fetch('../api/students.php?action=health&student_id=' + sid).then(r => r.json()).then(d => {
        const el = document.getElementById('vHealth');
        if (!d.success || !d.data) { el.innerHTML = '<p style="color:#94a3b8;font-size:13px;">No health record.</p>'; return; }
        const h = d.data;
        el.innerHTML = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;"><div><div class="lbl" style="font-size:10px;color:#94a3b8;">Blood Type</div><div class="val" style="font-weight:600;">' + (h.blood_type || '—') + '</div></div><div><div class="lbl" style="font-size:10px;color:#94a3b8;">Height / Weight</div><div class="val" style="font-weight:600;">' + (h.height ? h.height + 'cm' : '—') + ' / ' + (h.weight ? h.weight + 'kg' : '—') + '</div></div><div style="grid-column:span 2;"><div class="lbl" style="font-size:10px;color:#94a3b8;">Allergies</div><div class="val">' + (h.allergies || 'None') + '</div></div><div style="grid-column:span 2;"><div class="lbl" style="font-size:10px;color:#94a3b8;">Conditions</div><div class="val">' + (h.pre_existing_conditions || 'None') + '</div></div></div>';
    }).catch(() => {});
}
function loadDocuments(sid) {
    fetch('../api/students.php?action=documents&student_id=' + sid).then(r => r.json()).then(d => {
        const el = document.getElementById('vDocuments');
        if (!d.success || !d.data || !d.data.length) { el.innerHTML = '<p style="color:#94a3b8;font-size:13px;">No document requests.</p>'; return; }
        el.innerHTML = '<table style="width:100%;font-size:12px;"><tr style="color:#64748b;font-weight:600;"><td>Type</td><td>Status</td><td>Date</td></tr>' + d.data.map(dr => '<tr style="border-bottom:1px solid #f1f5f9;"><td>' + ucfirst((dr.document_type || '').replace('_', ' ')) + '</td><td><span class="badge badge-' + (dr.status === 'pending' ? 'warning' : dr.status === 'completed' || dr.status === 'approved' ? 'success' : 'neutral') + '">' + ucfirst(dr.status || '') + '</span></td><td>' + (dr.request_date ? new Date(dr.request_date).toLocaleDateString() : '') + '</td></tr>').join('') + '</table>';
    }).catch(() => {});
}
function loadRfid(sid) {
    fetch('../api/rfid.php?student_id=' + sid).then(r => r.json()).then(d => {
        const el = document.getElementById('vRfid');
        if (!d.success || !d.data || !d.data.length) {
            el.innerHTML = '<p style="color:#94a3b8;font-size:13px;">No RFID card assigned. <a href="rfid-cards.php" style="color:#2563eb;">Assign a card →</a></p>';
            return;
        }
        const card = d.data[0];
        el.innerHTML = '<table style="width:100%;font-size:12px;"><tr style="color:#64748b;font-weight:600;"><td>Card UID</td><td>Status</td><td>Expiry</td></tr><tr><td><code>' + card.card_uid + '</code></td><td><span class="badge badge-' + (card.status === 'active' ? 'success' : 'warning') + '">' + ucfirst(card.status) + '</span></td><td>' + (card.expiry_date || '—') + '</td></tr></table><p style="margin-top:10px;"><a href="rfid-scan-logs.php?search=' + encodeURIComponent(card.card_uid) + '" class="btn btn-secondary btn-sm"><i class="fas fa-clock-rotate-left"></i> View Scan Logs</a></p>';
    }).catch(() => {});
}

function ucfirst(s) { return s.charAt(0).toUpperCase() + s.slice(1); }


// ─── ESC CLOSE ───────────────────────────────────────────────
// ??? SEARCH & FILTER MODAL ????????????????????????????????
// SMART SEARCH: natural language -> masterlist filters
const aiSearchBtn = document.getElementById('aiSearchBtn');
const aiInterpretation = document.getElementById('aiInterpretation');
const aiExplanation = document.getElementById('aiExplanation');

function urlParamSafe(val) {
    return val !== undefined && val !== null && String(val).trim() !== '';
}

async function runAiSearch() {
    const query = searchInput.value.trim();
    if (query.length < 3) {
        showToast('Type at least 3 characters for the AI search.', 'warning');
        return;
    }
    aiSearchBtn.disabled = true;
    aiSearchBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> AI';
    aiInterpretation.style.display = 'none';
    try {
        const res = await fetch('../api/masterlist-ai-search.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ query })
        });
        const data = await res.json();
        if (!data.success || !data.data) {
            throw new Error(data.message || 'AI search failed.');
        }
        const f = data.data.filter || {};
        const p = new URLSearchParams();
        if (urlParamSafe(f.course)) p.set('course', f.course);
        if (urlParamSafe(f.year_level)) p.set('year_level', f.year_level);
        if (urlParamSafe(f.school_year)) p.set('school_year', f.school_year);
        if (urlParamSafe(f.semester)) p.set('semester', f.semester);
        if (urlParamSafe(f.status)) p.set('status', f.status);
        if (Array.isArray(f.keywords) && f.keywords.length) p.set('q', f.keywords.join(' '));
        aiExplanation.textContent = f.explanation || 'Filters applied.';
        aiInterpretation.style.display = 'block';
        const target = 'masterlist.php' + (p.toString() ? '?' + p.toString() : '');
        setTimeout(() => { window.location.href = target; }, 700);
    } catch (err) {
        console.error(err);
        aiExplanation.textContent = 'AI search failed. Check that the AI server is running, or use the filters below.';
        aiExplanation.style.color = '#b91c1c';
        aiInterpretation.style.background = '#fef2f2';
        aiInterpretation.style.borderColor = '#fecaca';
        aiInterpretation.style.display = 'block';
    } finally {
        aiSearchBtn.disabled = false;
        aiSearchBtn.innerHTML = '<i class="fas fa-wand-magic-sparkles" style="color:#7c3aed;"></i> AI';
    }
}

if (aiSearchBtn) {
    aiSearchBtn.addEventListener('click', runAiSearch);
}

// Restore a search query passed via ?q=
const qParam = new URLSearchParams(window.location.search).get('q');
if (qParam && searchInput) {
    searchInput.value = qParam;
    searchInput.dispatchEvent(new Event('input'));
}

function openFilterSearchModal() {
    document.getElementById('filterSearchModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeFilterSearchModal() {
    document.getElementById('filterSearchModal').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('filterSearchModal').addEventListener('click', function (e) { if (e.target === this) closeFilterSearchModal(); });

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeViewModal(); closeGenerateModal(); closeFilterSearchModal(); }
});

// ---- SEND LIST / HAND-OFF (CMS) ----
function handoffApi(payload) {
    return fetch('../api/masterlist-handoff.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).then(r => r.json());
}
async function sendList() {
    if (!await confirmAction({
        title: 'Send to CMS',
        body: 'Send the masterlist to the <strong>Academic Strand / Course Assignment</strong> module?',
        confirmLabel: 'Send'
    })) return;
    handoffApi({ program: '' }).then(d => {
        showToast(d.message || (d.success ? 'Sent.' : 'Failed.'), d.success ? 'success' : 'error');
    }).catch(() => { showToast('Network error.', 'error'); });
}
async function handoffGroup(label) {
    if (!await confirmAction({
        title: 'Hand off group',
        body: 'Hand off this group to the CMS?<br><br>' + escText(label),
        confirmLabel: 'Hand off'
    })) return;
    handoffApi({ program: label }).then(d => {
        showToast(d.message || (d.success ? 'Handed off.' : 'Failed.'), d.success ? 'success' : 'error');
    }).catch(() => { showToast('Network error.', 'error'); });
}</script>

<style>
.bulk-bar a { text-decoration: none; }
/* Filter dropdowns should look clickable */
#filterCourse, #filterYear, #filterSchoolYear, #filterSemester, #filterStatus,
#genSchoolYear, #genSemester, #genCourse, #genYear, #genStatus,
select.form-control { cursor: pointer !important; }

/* Masterlist table styling */
.masterlist-table {
    color: #1e293b !important;
    background: #fff !important;
    border-radius: 12px !important;
    overflow: hidden !important;
}
body[data-page="masterlist"] .masterlist-table thead th {
    background: #f8fafc !important;
    color: #475569 !important;
}
body[data-page="masterlist"] .masterlist-table tbody tr:hover {
    background: #eff6ff !important;
}
.masterlist-table thead th:first-child {
    border-radius: 12px 0 0 0 !important;
}
.masterlist-table thead th:last-child {
    border-radius: 0 12px 0 0 !important;
}
.masterlist-table tbody td {
    color: #1e293b !important;
    background: #fff !important;
}
.masterlist-table tbody tr:last-child td:first-child {
    border-radius: 0 0 0 12px !important;
}
.masterlist-table tbody tr:last-child td:last-child {
    border-radius: 0 0 12px 0 !important;
}

@media print {
    .sidebar, .header-actions, .masterlist-actionbar, .form-row, .btn, .bulk-bar, #masterlistSearch, #selectAllPage, .modal-overlay { display: none !important; }
    .masterlist-section-block { break-inside: avoid; page-break-inside: avoid; }
    .masterlist-table th[data-sort] i { display: none; }
    #masterlistContent { margin: 0; }
}
</style>

<?php include '../includes/footer.php'; ?>
