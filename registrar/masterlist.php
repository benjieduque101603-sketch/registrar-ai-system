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

// Every student belongs on the masterlist. Section codes are written by the
// department that assigns them, not by the registrar, so a blank section is
// the normal state here and must never hide a student.
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

// ─── BLOCKS: course + year level + semester, never section ───
// A block is what the registrar can actually vouch for: the program,
// the year level, and the term they belong to. Section codes are the
// receiving department's to write, so they are not part of the key - a
// block is a group to be sectioned, not a section.
//
// Semester IS part of the key. Keying on course + year alone put a 1st
// sem and a 2nd sem cohort of the same program and year into one table,
// which is not a list anyone can hand off: a section is assigned within
// one term, and a printed sheet holding both terms at once cannot be
// signed off as either. The academic year is included for the same
// reason - a retained 2025-2026 row is a different cohort from a
// 2026-2027 one, and merging them silently mixes two intakes.
//
// The acronym (courseAcronym) leads the heading because the long program
// name is a whole line of its own at heading size; the full name rides
// along in the title attribute.
$blocks = [];
foreach ($students as $student) {
    $course   = trim((string) ($student['course'] ?? ''));
    $year     = trim((string) ($student['year_level'] ?? ''));
    $semester = trim((string) ($student['semester'] ?? ''));
    $schoolYear = trim((string) ($student['school_year'] ?? ''));
    $key      = $course . "\x1F" . $year . "\x1F" . $schoolYear . "\x1F" . $semester;

    if (!isset($blocks[$key])) {
        $blocks[$key] = [
            'course'      => $course,
            'year_level'  => $year,
            'semester'    => $semester,
            'school_year' => $schoolYear,
            'acronym'     => courseAcronym($course),
            'students'    => [],
        ];
    }
    $blocks[$key]['students'][] = $student;
}

// Order the blocks the way a registrar reads them: by program, then year,
// then academic year, then term. Semester sorts by the order of the school
// year rather than alphabetically, or "2nd" would come before "1st".
$semesterOrder = ['1st' => 1, '2nd' => 2, 'summer' => 3];
uksort($blocks, function ($a, $b) use ($semesterOrder) {
    $A = explode("\x1F", $a);
    $B = explode("\x1F", $b);
    $cmp = strcasecmp($A[0], $B[0]);
    if ($cmp !== 0) return $cmp;
    $cmp = (int) $A[1] <=> (int) $B[1];
    if ($cmp !== 0) return $cmp;
    $cmp = strcmp($A[2], $B[2]);
    if ($cmp !== 0) return $cmp;
    return ($semesterOrder[strtolower($A[3])] ?? 9) <=> ($semesterOrder[strtolower($B[3])] ?? 9);
});

// ─── TABLES: one per MAX_STUDENTS_PER_SECTION students ───────
// 50 is not a cap this page enforces, it is the size of one list the
// department can act on. So a block that runs past 50 does not overflow into
// one long table - it starts a new one, and the new one is a separate sheet
// that can be sent on its own. 91 students means "Table 1 of 2" (50) and
// "Table 2 of 2" (41), two lists, each within the cap.
//
// No padding. A block with 41 students is a 41-row table, not a 50-row one
// with 9 empty lines: a numbered table holding one student reads as students
// that failed to load, and an empty row is not a student. The last table is
// simply short, and says so.
$sectionCap = defined('MAX_STUDENTS_PER_SECTION') ? max(1, (int) MAX_STUDENTS_PER_SECTION) : 50;
$tableCount = 0;

foreach ($blocks as &$block) {
    $chunked = array_chunk($block['students'], $sectionCap);
    $block['tables'] = [];
    foreach ($chunked as $idx => $chunk) {
        $block['tables'][] = [
            'rows' => $chunk,
            'n'    => count($chunk),                       // how many rows this table has
            'no'   => $idx + 1,                            // "Table 2 of 2"
            'total' => count($chunked),
        ];
        $tableCount++;
    }
}
unset($block);

$totalStudents = count($students);
$totalBlocks   = count($blocks);

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

// RFID lookup for profile modal (student_id → card info)
$rfidCards = $db->fetchAll("SELECT student_id, card_uid, status, expiry_date FROM rfid_cards");
$rfidMap = [];
foreach ($rfidCards as $rc) {
    if ($rc['student_id']) $rfidMap[$rc['student_id']] = $rc;
}

$page_title = 'Masterlist';
$page_description = 'Enrolled student masterlist, prepared for section assignment by the receiving department';
$body_page = 'masterlist';
$APP_ROOT = '../';
$ACTIVE_NAV = 'masterlist';
$prepared = isset($_GET['prepared']) && $_GET['prepared'] === '1';

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
/* The block heading. A block is a program-year cohort, so the heading names
   only that - the section code is the receiving department's to write, and
   putting it in the heading would claim a section that does not exist yet. */
.ml-block-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:13px 16px;background:#f8faff;border-bottom:1px solid #e5e7eb}
.ml-block-head h2{display:flex;align-items:center;gap:9px;margin:0;font-size:15px;font-weight:800;letter-spacing:-.01em;color:#172554}
.ml-block-head h2 i{color:#2563eb}
.ml-block-acronym{display:inline-block;padding:2px 8px;border-radius:7px;background:#1d4ed8;color:#fff;font-size:11.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
/* The Section Code column is a form field, not a value. It is drawn as an
   empty ruled box: visibly writable, and clearly holding nothing yet. The
   dashed rule says "to be filled in" - a solid one would read as data. */
.ml-section-slot{width:118px;min-width:118px}
.ml-section-slot span{display:block;min-height:20px;padding:2px 0 3px;border-bottom:1px dashed #cbd5e1}
/* "Table 2 of 2" — only shown when a block runs past the cap and starts a new
   list. Amber so it reads as a continuation of the block above, not as a
   heading in its own right. */
.ml-table-tag{display:flex;align-items:center;gap:8px;padding:7px 13px;background:#fefce8;border-bottom:1px solid #fde68a;color:#854d0e;font-size:11.5px;font-weight:800;letter-spacing:.03em;text-transform:uppercase}
.ml-table-tag i{color:#d97706}
.ml-table-tag span{font-weight:600;letter-spacing:0;text-transform:none;color:#a16207}
@media(max-width:640px){.masterlist-header{padding:21px 18px}.masterlist-header h1{font-size:25px}.masterlist-actionbar{flex-direction:column}.masterlist-action-group{width:100%}.masterlist-action-buttons .btn{flex:1 1 100%;justify-content:center}.masterlist-toolbar{align-items:stretch}.masterlist-search{flex-basis:100%}.masterlist-ai,.masterlist-filter-btn{justify-content:center}}
</style>

<main class="main">
    <header class="masterlist-header">
        <div>
            <div class="masterlist-kicker"><i class="fas fa-table-list"></i> Registrar directory</div>
            <h1>Masterlist</h1>
            <p>Search, filter, and send the full student list. Section codes are assigned by the department that receives this list.</p>
        </div>
    </header>

    <!-- Action bar: list tools + output -->
    <section class="masterlist-actionbar" aria-label="Masterlist actions">
        <div class="masterlist-action-group">
            <span class="masterlist-action-label">List tools</span>
            <div class="masterlist-action-buttons">
                <button type="button" class="btn btn-primary" id="btnPrepareList" title="Show every student, with no filters applied, ready to hand off for section assignment">
                    <i class="fas fa-list-check"></i> Prepare Full List
                </button>
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

    <?php if ($prepared): ?>
        <div class="card" style="margin-bottom: 16px; padding: 12px 16px; background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <i class="fas fa-check-circle"></i> Full list prepared. It is sorted by course and year, ready to hand off for section assignment.
        </div>
    <?php endif; ?>

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
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#475569;cursor:pointer;">
            <input type="checkbox" id="selectAllPage" style="width:16px;height:16px;accent-color:#2563eb;"> Select all shown
        </label>
        <span style="font-size:13px;color:#64748b;">Showing <strong id="showingCount"><?= $totalStudents ?></strong> student(s)</span>
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
        <?php if (empty($students)): ?>
            <div class="card" style="box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="padding: 48px 24px; text-align: center; color: #64748b;">
                    <i class="fas fa-users-slash" style="font-size:40px;color:#e2e8f0;display:block;margin-bottom:14px;"></i>
                    <p style="font-size:16px;font-weight:600;color:#334155;margin:0 0 8px;">No students found</p>
                    <p style="margin:0;">No students match your filters. Clear the filters to see the full list.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($blocks as $block): ?>
                <div class="card masterlist-section-block" style="margin-bottom: 16px;">
                    <!-- Names the program, the year, the term and the academic
                         year - everything the key groups by. It must show them
                         all: the blocks are separate tables now, and two
                         headings that read identically would leave the
                         registrar unable to tell which cohort a sheet is for.
                         It names no section, because none exists yet - the
                         receiving department writes those. -->
                    <div class="ml-block-head">
                        <h2 title="<?= htmlspecialchars(($block['course'] !== '' ? $block['course'] : 'No program recorded')
                                    . ($block['year_level'] !== '' ? ' — Year ' . $block['year_level'] : '')
                                    . ($block['semester'] !== '' ? ' — ' . $block['semester'] . ' Semester' : '')
                                    . ($block['school_year'] !== '' ? ' (' . $block['school_year'] . ')' : '')) ?>">
                            <i class="fas fa-users"></i>
                            <span class="ml-block-acronym"><?= htmlspecialchars($block['acronym'] !== '' ? $block['acronym'] : 'N/A') ?></span>
                            <?= htmlspecialchars('Year ' . ($block['year_level'] !== '' ? $block['year_level'] : '—')) ?>
                            <?php if ($block['semester'] !== ''): ?>
                                <span class="ml-block-term"><?= htmlspecialchars($block['semester']) ?> Sem</span>
                            <?php endif; ?>
                        </h2>
                        <?php if ($block['school_year'] !== ''): ?>
                            <span class="ml-block-sy"><?= htmlspecialchars($block['school_year']) ?></span>
                        <?php endif; ?>
                        <span class="badge <?= count($block['students']) > (int) $sectionCap ? 'badge-warning' : 'badge-success' ?>" style="font-size: 12px;"><?= count($block['students']) ?> students</span>
                        <span style="font-size: 12px; color: #475569;"><i class="fas fa-circle-info" style="color: #2563eb; margin-right: 6px;"></i>Section codes are left blank for the receiving department to fill in</span>
                    </div>
                    <?php foreach ($block['tables'] as $tbl): ?>
                    <div style="margin-bottom: 10px; overflow-x: auto; border-radius: 12px; overflow: hidden;">
                        <?php if (count($block['tables']) > 1): ?>
                            <!-- A block past 50 becomes several tables. The tag is
                                 what tells "Table 2 of 2" apart from a second
                                 block that happens to share the heading, and it
                                 carries the row count so the short last table
                                 does not look like a load error. -->
                            <div class="ml-table-tag">
                                <i class="fas fa-table"></i> Table <?= (int) $tbl['no'] ?> of <?= (int) $tbl['total'] ?>
                                <span><?= (int) $tbl['n'] ?> students</span>
                            </div>
                        <?php endif; ?>
                        <table class="masterlist-table" style="width: 100%; border-collapse: collapse; font-size: 13px; word-wrap: break-word; word-break: break-word;">
                            <thead>
                                <tr style="background: #1a2d4a; color: white;">
                                    <th style="padding: 10px 12px; text-align: center; width: 34px;"><input type="checkbox" class="block-select-all" style="width:15px;height:15px;accent-color:#2563eb;" title="Select all"></th>
                                    <th style="padding: 10px 12px; text-align: left; white-space: nowrap;">#</th>
                                    <th data-field="student_number" style="padding: 10px 12px; text-align: left; cursor:pointer; white-space: nowrap;" data-sort="student_number"><i class="fas fa-sort" style="font-size:10px;margin-right:4px;"></i>Student ID</th>
                                    <th data-field="name" style="padding: 10px 12px; text-align: left; cursor:pointer; white-space: nowrap;" data-sort="name"><i class="fas fa-sort" style="font-size:10px;margin-right:4px;"></i>Name</th>
                                    <th data-field="course" style="padding: 10px 12px; text-align: left; cursor:pointer; white-space: nowrap;" data-sort="course"><i class="fas fa-sort" style="font-size:10px;margin-right:4px;"></i>Course</th>
                                    <th data-field="year_level" style="padding: 10px 12px; text-align: left; cursor:pointer; white-space: nowrap;" data-sort="year_level"><i class="fas fa-sort" style="font-size:10px;margin-right:4px;"></i>Year</th>
                                    <th data-field="school_year" style="padding: 10px 12px; text-align: left; white-space: nowrap;">S.Y.</th>
                                    <th data-field="semester" style="padding: 10px 12px; text-align: left; white-space: nowrap;">Sem</th>
                                    <th data-field="section" style="padding: 10px 12px; text-align: left; white-space: nowrap;">Section Code</th>
                                    <th data-field="adviser" style="padding: 10px 12px; text-align: left; white-space: nowrap;">Adviser</th>
                                    <th data-field="status" style="padding: 10px 12px; text-align: left; white-space: nowrap;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php // Numbering restarts at 1 in every table, because
                                     // each table is its own list. Carrying 51-91
                                     // into "Table 2 of 2" would imply the two tables
                                     // are fragments of one numbered sheet, when
                                     // they are two separate lists. ?>
                                <?php $i = 1; foreach ($tbl['rows'] as $student): ?>
                                    <tr style="border-bottom: 1px solid #e2e8f0;" data-student-id="<?= (int)$student['id'] ?>">
                                        <td style="padding: 8px 12px; text-align: center;"><input type="checkbox" class="student-cb" value="<?= (int)$student['id'] ?>" style="width:15px;height:15px;accent-color:#2563eb;"></td>
                                        <td data-field="rowno" style="padding: 8px 12px; white-space: nowrap;"><?= $i++ ?></td>
                                        <td data-field="student_number" style="padding: 8px 12px; font-weight:600; font-size:12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($student['student_number']) ?>"><?= htmlspecialchars($student['student_number']) ?></td>
                                        <td data-field="name" style="padding: 8px 12px; max-width: 200px; white-space: normal; word-break: break-word;"><a href="javascript:void(0)" onclick="viewStudent(<?= (int)$student['id'] ?>)" style="color:#2563eb;font-weight:600;text-decoration:none;cursor:pointer;"><?= htmlspecialchars($student['last_name']) ?>, <?= htmlspecialchars($student['first_name']) ?></a></td>
                                        <td data-field="course" style="padding: 8px 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($student['course'] ?? 'N/A') ?>"><?= htmlspecialchars($student['course'] ?? 'N/A') ?></td>
                                        <td data-field="year_level" style="padding: 8px 12px; white-space: nowrap;"><?= htmlspecialchars($student['year_level'] ?? 'N/A') ?></td>
                                        <td data-field="school_year" style="padding: 8px 12px; white-space: nowrap;"><?= htmlspecialchars($student['school_year'] ?? '—') ?></td>
                                        <td data-field="semester" style="padding: 8px 12px; white-space: nowrap;"><?= htmlspecialchars($student['semester'] ?? '—') ?></td>
                                        <td data-field="section" class="ml-section-slot" style="padding: 8px 12px; white-space: nowrap;" title="Assigned by the receiving department"><?= htmlspecialchars($student['section'] ?? '') ?></td>
                                        <td data-field="adviser" style="padding: 8px 12px; max-width: 150px; white-space: normal; word-break: break-word; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($student['adviser_name'] ?? '—') ?>"><?= htmlspecialchars($student['adviser_name'] ?? '—') ?></td>
                                        <td data-field="status" style="padding: 8px 12px; white-space: nowrap;">
                                            <span class="badge badge-<?= in_array($student['status'], ['active', 'enrolled'], true) ? 'success' : ($student['status'] === 'at-risk' || $student['status'] === 'probation' ? 'warning' : 'neutral') ?>">
                                                <?= ucfirst($student['status'] ?? 'Active') ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($students)): ?>
    <div class="card" style="margin-top: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div class="table-footer">
            <div class="info-text">
                Total: <strong><?= $totalStudents ?></strong> student(s) in <strong><?= $totalBlocks ?></strong> program-year block(s), listed in <strong><?= $tableCount ?></strong> list(s) of up to <?= (int) $sectionCap ?> — section codes are assigned by the receiving department
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
                    <div><div class="lbl" style="font-size:10px;color:#94a3b8;text-transform:uppercase;">Year / Section</div><div class="val" id="vYearSection" style="font-size:14px;font-weight:600;color:#1e293b;">—</div></div>
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

// ─── PREPARE FULL LIST ───────────────────────────────────────
// Clears every filter so the whole roster is on screen, ready to hand off.
// The registrar does not write section codes — the receiving department does.
// The blocks are handed off whole; nothing here cuts them into lists.
document.getElementById('btnPrepareList')?.addEventListener('click', function () {
    window.location.href = 'masterlist.php?prepared=1';
});

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
    // A block whose every row was filtered out must go with them, otherwise
    // the heading is left sitting above nothing — it would read as a cohort
    // that still has students in it.
    document.querySelectorAll('#masterlistContent .masterlist-section-block').forEach(block => {
        const shown = Array.from(block.querySelectorAll('tbody tr'))
            .filter(r => r.style.display !== 'none').length;
        block.style.display = shown ? '' : 'none';
    });
    document.getElementById('showingCount').textContent = visible;
});

// ─── SORT (within the table) ─────────────────────────────────
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
// A selection gets its own filename. Exporting twice used to write
// masterlist-full-list.csv both times, so the second download landed on
// the first and the registrar lost whichever one they wanted to keep.
function exportSelectedCSV() {
    const rows = selectedRows();
    if (!rows.length) { showToast('Select at least one student first.', 'warning'); return; }
    exportCSV(rows, 'masterlist-selection-' + rows.length + '-' + exportStamp() + '.csv');
}
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
// Columns are addressed by data-field, not by position. The old version
// read t(2), t(3), t(4)... so inserting or reordering a column silently
// exported the wrong data under a plausible header - the kind of mistake
// nobody notices until a signed sheet is wrong.
//
// The header and the row are generated from the same list, so they cannot
// drift apart either.
// The export columns come from the table's own header, so a column added to
// the roster appears in the CSV, the Excel file and the print sheet without
// anyone editing this file. The old list was hand-written: adding a column
// to the table silently left it out of every export, which is the quieter
// half of the same bug that reading by position caused.
//
// Each <th> carries the same data-field as the cells beneath it, and its
// visible text is the header. The two control columns - the select-all
// checkbox and the row number - have no data-field, so they are skipped
// rather than exported as a blank column.
//
// FALLBACK_FIELDS is only used when there is no table to read, so a list
// filtered to empty still produces the right headers instead of a file with
// no columns at all.
const FALLBACK_FIELDS = [
    ['student_number', 'Student ID'],
    ['name',           'Name'],
    ['course',         'Course'],
    ['year_level',     'Year'],
    ['school_year',    'S.Y.'],
    ['semester',       'Semester'],
    ['section',        'Section Code'],
    ['adviser',        'Adviser'],
    ['status',         'Status'],
];

function exportFields() {
    const ths = document.querySelectorAll(
        '#masterlistContent .masterlist-table thead th[data-field]'
    );
    if (!ths.length) return FALLBACK_FIELDS;

    const out = [];
    ths.forEach(th => {
        const label = th.textContent.replace(/\s+/g, ' ').trim();
        if (label === '') return;           // a header with no words carries nothing
        out.push([th.dataset.field, label]);
    });
    return out.length ? out : FALLBACK_FIELDS;
}

function cellText(row, field) {
    const c = row.querySelector('[data-field="' + field + '"]');
    return c ? c.textContent.trim() : '';
}

/**
 * Pull the exportable rows out of the table.
 * Returns { rows, skipped } so a short or malformed row is reported to
 * the registrar rather than vanishing from the file without a word - a
 * missing student in an official list is worse than a noisy export.
 */
function collectRowData(rows, fields) {
    fields = fields || exportFields();
    const out = [];
    let skipped = 0;
    rows.forEach(row => {
        // A row with no student id cell is not a student row.
        if (!row.querySelector('[data-field="student_number"]')) { skipped++; return; }
        out.push(fields.map(f => cellText(row, f[0])));
    });
    return { rows: out, skipped: skipped };
}

/** RFC 4180 quoting, plus a guard against a value starting the file as a formula. */
function csvCell(v) {
    let s = String(v == null ? '' : v);
    // Excel treats =, +, -, @ at the start of a cell as a formula. A name
    // like "-Dela Cruz" would otherwise execute on open.
    if (/^[=+\-@\t\r]/.test(s)) s = "'" + s;
    return '"' + s.replace(/"/g, '""') + '"';
}

function exportStamp() {
    const d = new Date();
    const p = n => String(n).padStart(2, '0');
    return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes());
}

function exportCSV(rows, filename) {
    const list = rows || Array.from(document.querySelectorAll('#masterlistContent .masterlist-table tbody tr'));
    const fields = exportFields();
    const { rows: data, skipped } = collectRowData(list, fields);
    if (!data.length) {
        showToast('Nothing to export.', 'warning');
        return;
    }
    // CRLF and a UTF-8 BOM. Without the BOM Excel on Windows reads the
    // file as the local code page and mangles every accented name
    // (ñ, é) and the em dash used for a blank field.
    const header = fields.map(f => csvCell(f[1])).join(',');
    const body = data.map(r => r.map(csvCell).join(',')).join('\r\n');
    const csv = '\uFEFF' + header + '\r\n' + body + '\r\n';
    downloadBlob(
        new Blob([csv], { type: 'text/csv;charset=utf-8;' }),
        filename || ('masterlist-full-list-' + exportStamp() + '.csv')
    );
    if (skipped > 0) {
        showToast('Exported ' + data.length + ' student(s). ' + skipped + ' row(s) were malformed and left out.', 'warning');
    } else {
        showToast('Exported ' + data.length + ' student(s).', 'success');
    }
}

// A real SpreadsheetML workbook, not an HTML table wearing an .xls
// extension. The old file was HTML, so Excel opened it with the
// "the file format and extension don't match" warning and a yellow bar,
// which reads as a corrupt download and trains people not to trust it.
function exportExcel() {
    const list = Array.from(document.querySelectorAll('#masterlistContent .masterlist-table tbody tr'));
    const fields = exportFields();
    const { rows: data, skipped } = collectRowData(list, fields);
    if (!data.length) {
        showToast('Nothing to export.', 'warning');
        return;
    }
    const esc = v => String(v == null ? '' : v)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const xcell = v => '<Cell><Data ss:Type="String">' + esc(v) + '</Data></Cell>';

    let xml = '<?xml version="1.0"?>\n'
        + '<?mso-application progid="Excel.Sheet"?>\n'
        + '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"\n'
        + '          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">\n'
        + '<Styles><Style ss:ID="hdr"><Font ss:Bold="1"/></Style></Styles>\n'
        + '<Worksheet ss:Name="Masterlist"><Table>\n'
        + '<Row>' + fields.map(f => '<Cell ss:StyleID="hdr"><Data ss:Type="String">' + esc(f[1]) + '</Data></Cell>').join('') + '</Row>\n';
    data.forEach(r => { xml += '<Row>' + r.map(xcell).join('') + '</Row>\n'; });
    xml += '</Table></Worksheet></Workbook>';

    downloadBlob(
        new Blob(['\uFEFF' + xml], { type: 'application/vnd.ms-excel;charset=utf-8;' }),
        'masterlist-full-list-' + exportStamp() + '.xls'
    );
    showToast('Exported ' + data.length + ' student(s).'
        + (skipped ? ' ' + skipped + ' malformed row(s) left out.' : ''), skipped ? 'warning' : 'success');
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
    // The Section Code cell is printed as an empty ruled box, the same as on
    // screen: the department fills it in by hand on the printed sheet.
    w.document.write('td.code { width:90px; } td.code span { display:block; height:13px; border-bottom:1px solid #666; }');
    w.document.write('.sig { display:flex; justify-content:space-between; margin-top:26px; padding-top:6px; }');
    w.document.write('.sig .box { text-align:center; width:44%; }');
    w.document.write('.sig .line { border-top:1px solid #0f172a; margin-top:28px; padding-top:4px; font-size:10px; }');
    w.document.write('</style></head><body>');
    w.document.write('<div class="letterhead"><img src="../assets/images/BCP_LOGO.png" alt="BCP" onerror="this.style.display=\'none\'">' +
        '<div class="lh-text"><div class="school">BESTLINK COLLEGE OF THE PHILIPPINES</div>' +
        '<div class="sub">812 A. Luna St., Barangay Tatalon, Quezon City · registrar@bestlink.edu.ph</div>' +
        '<div class="title">OFFICIAL MASTERLIST OF STUDENTS' + (sy ? ' — S.Y. ' + sy : '') + '</div></div></div>');

    if (rows && rows.length) {
        // Group by the block each row came from, so the printed sheet keeps
        // the on-screen blocks. Print used to emit one single-row table per
        // student, which both lost the grouping and pulled the wrong cells:
        // t(6)/t(7) are S.Y. and Semester, not section and gender, so the old
        // headings printed a school year where a section belonged.
        const blockTitleOf = block => {
            const h = block && block.querySelector('.ml-block-head h2');
            return h ? h.textContent.replace(/\s+/g, ' ').trim() : '';
        };
        // A block past the cap is several tables, and the printed sheet has to
        // break the same way the screen does — otherwise a 91-student block
        // prints as one 91-row table, which is the very thing the split exists
        // to prevent. The key is block + table, so each printed table carries
        // its own heading and the count that says how many rows it has.
        const groupKeyOf = row => {
            const block = row.closest('.masterlist-section-block');
            const title = blockTitleOf(block) || 'Unassigned program';
            const tables = block ? block.querySelectorAll('table') : [];
            if (tables.length < 2) return title;
            const table = row.closest('table');
            let index = 0;
            tables.forEach((t, i) => { if (t === table) index = i; });
            return title + ' — Table ' + (index + 1) + ' of ' + tables.length;
        };
        const groups = new Map();
        rows.forEach(row => {
            const key = groupKeyOf(row);
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(row);
        });

        groups.forEach((groupRows, title) => {
            w.document.write('<h3>' + title + '</h3>');
            // Deliberately NOT exportFields(). The printed sheet is a
            // narrower shape than the CSV: it drops S.Y. and Semester
            // because the sheet's own heading already names the term, and
            // repeating it on every row of a signed document is noise.
            // The cell VALUES still come from data-field, so this header
            // cannot drift out of step with the table it prints.
            w.document.write('<table><tr><th>#</th><th>Student No.</th><th>Name</th><th>Course</th><th>Year</th><th>Section Code</th><th>Adviser</th><th>Status</th></tr>');
            // Numbering restarts per printed table, matching the screen: each
            // table is its own list, not a page of a longer numbered run.
            let seq = 0;
            groupRows.forEach(row => {
                // By field, for the same reason the CSV does. t(2), t(3)...
                // printed the wrong column as soon as one was inserted.
                if (!row.querySelector('[data-field="student_number"]')) return;
                w.document.write('<tr><td>' + (++seq) + '</td><td>' + cellText(row, 'student_number')
                    + '</td><td>' + cellText(row, 'name')
                    + '</td><td>' + cellText(row, 'course')
                    + '</td><td>' + cellText(row, 'year_level')
                    // The Section Code cell prints as an empty ruled box, the
                    // same as on screen: the department fills it in by hand.
                    + '</td><td class="code"><span></span></td><td>' + cellText(row, 'adviser')
                    + '</td><td>' + cellText(row, 'status') + '</td></tr>');
            });
            w.document.write('</table>');
        });
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
        document.getElementById('vYearSection').textContent = (s.year_level ? s.year_level + ' Year' : '') + (s.section ? ' — ' + s.section : '');
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
        if (urlParamSafe(f.section)) p.set('section', f.section);
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
