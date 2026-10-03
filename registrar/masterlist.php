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
// studentQualityScore() and studentAnomalies() from normalize.php, the same two
// the Students roster uses, so a student does not score one way on one page and
// another way on the other. normalize.php is already pulled in by functions.php,
// but it is required by name so this page's dependency on it is visible.
require_once __DIR__ . '/../shared/normalize.php';
// mlf_rows() / mlf_row_visible() - the folder table below. Pure
// helpers, so the grouping rules are unit tested without a browser.
require_once __DIR__ . '/../shared/masterlist_folders.php';
// studentQualityNormalizePhone(), which formats 09XXXXXXXXX as 09XX-XXX-XXXX for
// the Contact column. Without it the raw stored digits ship in the export.
require_once __DIR__ . '/../shared/student_quality.php';

$db = Database::getInstance();

// ─── FILTERS ────────────────────────────────────────────────
$filterCourse     = isset($_GET['course']) ? trim((string) $_GET['course']) : '';
$filterYear       = isset($_GET['year_level']) ? trim((string) $_GET['year_level']) : '';
$filterSchoolYear = isset($_GET['school_year']) ? trim((string) $_GET['school_year']) : '';
$filterSemester   = isset($_GET['semester']) ? trim((string) $_GET['semester']) : '';
$filterStatus     = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$filterSection    = isset($_GET['section']) ? trim((string) $_GET['section']) : '';

// ─── WHICH VIEW? ───────────────────────────────────────────
// 'list'    the flat, printable, signable blocks
// 'folders' the same records as ONE table in which a folder is a
//           row you expand
// 'explore' the same records as a FOLDER BROWSER: you stand at
//           exactly one folder at a time, click BSIT and go INTO
//           it, and a section folder opens the roster it holds
//
// A query parameter rather than a JS flag, so the view is a real
// address: the Back button works and either view can be linked to.
//
// 'explore' is the DEFAULT. It is the shape a registrar actually
// reads the list in - open a program, open a year, open a section,
// read the roster - and it needs no filter set to be useful, where
// the flat list opens as one long sheet of every cohort on file.
// The printable blocks are still one click away and are still what
// gets signed; they were the default only because they were the
// first thing built.
//
// Anything unrecognised falls back to the default rather than
// rendering nothing, because a stale bookmark must not dead-end.
$defaultView = 'explore';
$view = (isset($_GET['view']) && in_array($_GET['view'], ['list', 'folders', 'explore'], true))
    ? (string) $_GET['view']
    : $defaultView;

// The folder to open on arrival in the FOLDERS TABLE. Its ancestors
// open too, so a link to one section actually reveals that section
// instead of landing on a collapsed tree that shows the reader
// nothing.
$focusPath = $view === 'folders' && isset($_GET['open'])
    ? trim((string) $_GET['open'])
    : '';

// Where the BROWSER is standing. This is a location, not a
// disclosure: `?path=BSIT/Year 1/11001` means "open this folder",
// and clicking BSIT in the browser rewrites it. mlf_resolve()
// matches it against folders that already exist and never builds a
// query from it, so a crafted path cannot reach rows the reader
// could not already see.
$explorePath = $view === 'explore' && isset($_GET['path'])
    ? trim((string) $_GET['path'])
    : '';

// The list below only holds students who HAVE a section.
//
// A student with a blank section is a real enrolment, but this list is not
// the enrolment record - it is the section list that gets handed off and
// signed. A row nobody could sign (no code, no block membership) has no
// place on it, and one such row is enough to make the whole sheet look
// unsigned: a block that is otherwise complete, printed, sent out.
//
// So the rule is: assigned = on the list, unassigned = counted, not listed.
// The unassigned count is still reported in the header status line, which
// is how a registrar knows there is work left to do; the "assign students"
// picker on the Create/Edit Section modals reads the students table directly
// ($assignableStudents below), so hiding these rows here costs no ability to
// place them.
//
// NULL and whitespace-only both count as unassigned: a section saved as a
// space is exactly as unplaced as one saved as NULL, and TRIM() is what the
// rest of this page already compares on.
$sql = "SELECT * FROM students
        WHERE section IS NOT NULL AND TRIM(section) != ''";
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
if ($filterSection !== '') {
    $sql .= " AND TRIM(section) = ?";
    $params[] = $filterSection;
}
// section before last_name: one section's students have to land
// together in the printed list, since a section is the unit the list
// is handed off in. Sorting by name first would interleave them.
$sql .= " ORDER BY TRIM(course) ASC, COALESCE(year_level, 0) ASC, section ASC, last_name ASC, first_name ASC";

$students = $db->fetchAll($sql, $params);

// ─── THE FOLDER VIEWS ───────────────────────────────────────
//
// The two folder surfaces need a DIFFERENT set of rows from the
// blocks above, and the difference is the whole point of having
// them.
//
// $students above is the printable roster: it already dropped every
// student with no section, because a row nobody could sign has no
// place on a signed sheet. A folder view is an INVENTORY - it must
// show those students too, filed under "Unassigned Section", or a
// cohort would silently vanish the moment someone opened it.
//
// So it runs its own query WITHOUT the section predicate, reusing
// the filters already parsed above. Both folder views are driven
// from ONE tree built from those rows: the table flattens it
// (mlf_rows), the browser stands inside it (mlf_resolve). The list
// view pays nothing for it.
$folderRows  = [];
$folderOpen  = [];
$folderTotal = 0;
$folderNode  = null;

if ($view === 'folders' || $view === 'explore') {
    $fsql = "SELECT id, student_number, first_name, middle_name, last_name,
                    name_suffix, course, year_level, section, school_year,
                    semester, gender, status, contact_number, email
             FROM students";
    $fwhere  = [];
    $fparams = [];

    // The same dimensions as the blocks, under the same names, so a
    // filter set means the same thing in both views.
    if ($filterCourse !== '') {
        $fwhere[]  = 'TRIM(course) = ?';
        $fparams[] = $filterCourse;
    }
    if ($filterYear !== '' && is_numeric($filterYear)) {
        $fwhere[]  = 'year_level = ?';
        $fparams[] = (int) $filterYear;
    }
    if ($filterSchoolYear !== '') {
        $fwhere[]  = 'school_year = ?';
        $fparams[] = $filterSchoolYear;
    }
    if ($filterSemester !== '') {
        $fwhere[]  = 'semester = ?';
        $fparams[] = $filterSemester;
    }
    if ($filterSection !== '') {
        $fwhere[]  = 'TRIM(section) = ?';
        $fparams[] = $filterSection;
    }
    if ($fwhere) {
        $fsql .= ' WHERE ' . implode(' AND ', $fwhere);
    }
    $fsql .= ' ORDER BY TRIM(course) ASC, COALESCE(year_level, 0) ASC, section ASC, last_name ASC, first_name ASC';

    $allStudents = $db->fetchAll($fsql, $fparams);
    $folderTree  = mlf_build_tree($allStudents);
    $folderTotal = count($allStudents);

    // The BROWSER stands at one folder; the TABLE flattens the whole
    // tree into rows. Both read the same tree, so the two views can
    // never disagree about who is filed where.
    if ($view === 'explore') {
        $folderNode = mlf_resolve($folderTree, $explorePath);
    }

    // Which folders start open: the focus and its ancestors, or -
    // with no focus - every PROGRAM. Starting on the programs is the
    // shape a drive opens in: the reader sees the programs without a
    // click, and the cohorts stay folded away until asked for.
    if ($view === 'folders') {
        $folderRows = mlf_rows($folderTree, $focusPath);
        foreach ($folderRows as $row) {
            if ($row['type'] === 'folder' && (int) $row['level'] === 0) {
                $folderOpen[$row['path']] = true;
            }
        }
        if ($focusPath !== '') {
            $parts = explode('/', trim(str_replace('\\', '/', $focusPath), '/'));
            $walk  = '';
            foreach ($parts as $part) {
                $walk = $walk === '' ? $part : $walk . '/' . $part;
                $folderOpen[$walk] = true;
            }
        }
    }
}

// Adviser names are NOT joined here any more. The Adviser column reads N/A
// because naming an adviser is Faculty Management's (#296) to do, not the
// Registrar's - see DEPARTMENTS.md. The lookup survives only for the View
// Student modal below, which reads the profile the registrar maintains and is
// not part of the handed-off masterlist.
$advisers = $db->fetchAll("SELECT id, full_name FROM users WHERE role = 'staff' ORDER BY full_name");
$adviserNames = [];
foreach ($advisers as $ad) { $adviserNames[(int)$ad['id']] = $ad['full_name']; }

// ─── BLOCKS: course + year level + school year + semester ────
// A block is what the registrar can actually vouch for: the program,
// the year level, and the term they belong to.
//
// Semester IS part of the key. Keying on course + year alone put a 1st
// sem and a 2nd sem cohort of the same program and year into one table,
// which is not a list anyone can hand off: a section is assigned within
// one term, and a printed sheet holding both terms at once cannot be
// signed off as either. The academic year is included for the same
// reason - a retained 2025-2026 row is a different cohort from a
// 2026-2027 one, and merging them silently mixes two intakes.
//
// Section is deliberately NOT in the key. The code is [year][sem][###]
// and carries no S.Y. digit, so one program+year+term has ONE section
// space no matter how many intakes sit in it - splitting the block on
// section would renumber a single space into several and print "11001"
// twice. The section shows as a column instead, and a block past the
// cap still starts a new list further down.
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

// ─── SECTION ROLL-UP (per block, for the heading and the modals) ──
// Computed after the blocks exist so a block can say which codes it
// holds and how full each one is. A code that appears twice inside one
// block would mean the same section is split across two intakes, so
// the count is shown but the over-cap tone is reserved for a block
// that is genuinely over the list size.
//
// The list is already section-only, so this roll-up finds no gaps. It is
// kept because it is what the block heading renders from, and because it
// keeps the heading correct if the list rule above is ever relaxed again.
foreach ($blocks as &$block) {
    $bySection = [];
    foreach ($block['students'] as $student) {
        $code = trim((string) ($student['section'] ?? ''));
        if ($code === '') continue;
        $bySection[$code] = ($bySection[$code] ?? 0) + 1;
    }
    ksort($bySection);
    $block['section_counts'] = $bySection;
    $block['sections']       = array_keys($bySection);
    $block['unassigned']     = count($block['students']) - array_sum($bySection);
}
unset($block);

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

// ─── SECTION TOTALS (for the toolbar readout and the modals) ──
// Counted across the whole table, not the filtered view: "how many
// students still need a section" is a fact about the cohort, and it
// would be wrong to report it as smaller just because a filter is on.
$sectionStats = $db->fetchOne(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN section IS NULL OR TRIM(section) = '' THEN 1 ELSE 0 END) AS unassigned,
            COUNT(DISTINCT NULLIF(TRIM(section), '')) AS section_count
     FROM students"
);
$studentsTotal   = (int) ($sectionStats['total'] ?? 0);
$unassignedCount = (int) ($sectionStats['unassigned'] ?? 0);
$sectionTotal    = (int) ($sectionStats['section_count'] ?? 0);

// Per-section roll-up for the Create/Edit modals. Scoped to the
// year level too, because that is what the code encodes - the same
// code in two year levels is two different sections.
//
// This is what the Edit Section modal checks a typed code against, so
// a code that is already taken is reported as it is typed rather than
// only when Save is pressed and the server rejects the write.
$sectionSummaries = $db->fetchAll(
    "SELECT TRIM(course) AS course, year_level, semester, school_year,
            TRIM(section) AS section, COUNT(*) AS count
     FROM students
     WHERE section IS NOT NULL AND TRIM(section) != ''
     GROUP BY TRIM(course), year_level, semester, school_year, TRIM(section)
     ORDER BY TRIM(course), year_level, section"
);

// Section codes offered by the filter dropdown. A free-text filter on
// a 5-digit code nobody can predict is unusable, so the list is
// whatever codes actually exist.
$sectionOptions = $db->fetchAll(
    "SELECT DISTINCT TRIM(section) AS section FROM students
     WHERE section IS NOT NULL AND TRIM(section) != ''
     ORDER BY section"
);

// Candidates for the "assign students to this section" picker. Every
// student who can legally hold a code is included - no section, or a
// different one - because moving a student between sections is a
// normal correction, not an edge case. Year-less students are left
// out: a section code is derived from the year level, so they cannot
// hold one, and the API rejects them by name if they are sent anyway.
$assignableStudents = $db->fetchAll(
    "SELECT id, student_number, first_name, middle_name, last_name,
            TRIM(course) AS course, year_level, semester, TRIM(section) AS section
     FROM students
     WHERE year_level IS NOT NULL AND TRIM(IFNULL(year_level, '')) != ''
     ORDER BY TRIM(course), year_level, last_name, first_name"
);

$offeredCourses = getOfferedCourses();

// One flag for "is anything filtered", so the toolbar badge and the
// filter modal cannot disagree about it. Adding a sixth filter meant
// editing two separate five-term conditions, and the pair had already
// drifted once.
$anyFilterActive = $filterCourse !== '' || $filterYear !== ''
    || $filterSchoolYear !== '' || $filterSemester !== ''
    || $filterStatus !== '' || $filterSection !== '';

// Dropdown data
$courses = $db->fetchAll(
    "SELECT DISTINCT TRIM(course) AS course FROM students
     WHERE course IS NOT NULL AND TRIM(course) != ''
     ORDER BY course"
);

// THE PROGRAM LEGEND.
//
// Programs are printed as acronyms everywhere they are listed - the
// folder tiles, the breadcrumb, the roster. That is right for
// scanning and wrong for reading: "BSIT" is a compression, and a
// compression with nowhere to be decompressed is a barrier. So this
// is the one place on the page where the full name is the point.
//
// It is derived from the same `course` values the tiles are, so a
// program cannot appear in one and not the other. The count is per
// program across the WHOLE table, not the current filter: this
// answers "what is BSIT and how many are in it", and a count that
// moved with the filter would answer a different question each time.
//
// The work-queue pseudo-programs (Unassigned Program) are excluded -
// they are not programs and have no acronym to decode.
$programLegend = [];
foreach ($db->fetchAll(
    "SELECT TRIM(course) AS course, COUNT(*) AS n
     FROM students
     WHERE course IS NOT NULL AND TRIM(course) != ''
       AND TRIM(course) NOT LIKE 'Unassigned%'
     GROUP BY TRIM(course)
     ORDER BY TRIM(course)"
) as $r) {
    $programLegend[] = [
        'full'   => (string) $r['course'],
        'short'  => courseDisplay((string) $r['course']),
        'count'  => (int) $r['n'],
        'abbrev' => courseDisplayTitle((string) $r['course']) !== '',
    ];
}
$years = $db->fetchAll(
    "SELECT DISTINCT year_level FROM students WHERE year_level IS NOT NULL ORDER BY year_level"
);
$schoolYears = $db->fetchAll(
    "SELECT DISTINCT school_year FROM students WHERE school_year IS NOT NULL AND school_year != '' ORDER BY school_year DESC"
);
$statusOptions = ['enrolled', 'active', 'probation', 'at-risk', 'loa', 'graduated', 'transferred', 'dropped'];

// RFID lookup for the list column and the profile modal (student_id → card).
//
// A student can hold more than one card over their time here - a replacement
// after a loss, an archived card kept for its scan history - so this is not a
// plain "last row wins" map. A naive $map[$id] = $row lets an ARCHIVED or
// LOST card overwrite the ACTIVE one, and the list then shows a dead card
// number against a student who is carrying a working one. Ordered by status
// rank, so the card a registrar could actually use today is the one that wins.
$rfidCards = $db->fetchAll("SELECT student_id, card_uid, status, expiry_date FROM rfid_cards");
$rfidMap = [];
// Lower rank = preferred. Only a card the student could present today is
// considered; archived and lost cards never displace a live one.
$rfidRank = ['active' => 0, 'inactive' => 1, 'expired' => 2, 'lost' => 3, 'available' => 4, 'archived' => 5];
foreach ($rfidCards as $rc) {
    if (empty($rc['student_id'])) continue;
    $sid  = (int) $rc['student_id'];
    $rank = $rfidRank[$rc['status'] ?? ''] ?? 9;
    if (!isset($rfidMap[$sid]) || $rank < $rfidMap[$sid]['rank']) {
        $rc['rank'] = $rank;
        $rfidMap[$sid] = $rc;
    }
}

$page_title = 'Masterlist';
$page_description = 'Enrolled student masterlist, with section codes assigned in batches of up to ' . (int) $sectionCap;
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
.masterlist-filter-btn{display:inline-flex;align-items:center;gap:7px;white-space:nowrap}
body[data-page="masterlist"] .card{border:1px solid #dbeafe!important;border-radius:16px!important;background:#fff!important;box-shadow:0 8px 24px rgba(15,23,42,.045)!important}
body[data-page="masterlist"] .masterlist-section-block{overflow:hidden;margin-bottom:16px!important;border:1px solid #dbeafe!important;border-radius:16px!important;box-shadow:0 8px 24px rgba(15,23,42,.045)!important}
/* The rule that tinted the block head is gone. It targeted
   .masterlist-section-block > div:first-child, which IS .ml-block-head, and it
   painted #f8faff over the colour the .ml-block-head rule sets - so the heading
   redesign could not be seen for the same reason the table header one could
   not. The heading's own rule owns its own background now, and nothing reaches
   in to repaint it. */
/* The roster is a ledger: rows read as horizontal lines of type, and the eye
   needs to find one thing fast - whose name this is. Everything else is a
   short token that should sit in a fixed track and never move. */
body[data-page="masterlist"] .masterlist-table{width:100%;border-collapse:collapse;font-size:15px;table-layout:fixed}
/* Column tracks. Fixed for the tokens so a value's width never shifts the
   columns beside it; the name takes the slack and is the only flexible one.
   Sized for 15px type: a 9-character student id at this size needs ~116px of
   monospace, and the pill tracks are measured from the widest word they hold. */
.masterlist-table .c-pick{width:42px}
.masterlist-table .c-no{width:48px}
.masterlist-table .c-num{width:128px}
.masterlist-table .c-name{width:auto;min-width:240px}
.masterlist-table .c-gender{width:96px}
.masterlist-table .c-contact{width:150px}
/* Email is a variable-length string, so the track is generous and the value
   truncates from the LEFT: an address reads by its domain, and "…@school.edu"
   still identifies it where "roldanti…gmail.com" does not. left-overflow needs
   direction:rtl on the cell to render the ellipsis at the start. */
.masterlist-table .c-email{width:210px}
/* Email truncates from the START. An address reads by its domain, and
   "…@school.edu" still identifies the account where "roldanti…gmail.com" does
   not - the local part is the part the reader already knows. A cell only clips
   the overflow on the leading edge under direction:rtl, so the value is set
   rtl and then re-anchored left, which keeps the text itself in normal LTR
   order; an address has no brackets or mixed-direction runs to reorder. */
.ml-email{color:#334155;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;direction:rtl;text-align:left}
.masterlist-table .c-bday{width:140px}
.masterlist-table .c-rfid{width:190px}
.masterlist-table .c-course{width:102px}
.masterlist-table .c-section{width:106px}
.masterlist-table .c-status{width:128px}
body[data-page="masterlist"] .masterlist-table th{
  /* The header is a ruled band, not a strip of labels. A ledger's column
     headings are separated from the entries by a firm rule and by nothing else,
     so that is what this does: a recessed band, a 2px rule in a darker tone
     than the row hairlines, and no letterspacing. Sentence case at 600, not
     800 - a heading that shouts competes with the names it sits above. */
  background:#eef2f7;color:#334155;padding:12px 14px;
  font-size:12.5px;font-weight:600;letter-spacing:0;text-transform:none;
  text-align:left;border:0;border-bottom:2px solid #9fb0c4;
  white-space:nowrap;position:relative;vertical-align:bottom
}
/* The columns group into three kinds of question - who is this, how do I reach
   them, what state is the record in. They USED to be separated by a hairline at
   each boundary (Contact and Email), letting the eye read a row in three passes.
   Removed at the office's request: the boundaries read as a grid, and a
   registrar scanning for one name saw cells first and a roster second. A
   vertical rule inside a row is a cell boundary, not a separation of meaning.

   The .ml-col-start class stays on the th/td. Removing it from the markup would
   mean touching the header, the body and the column-alignment regression test
   for no visible gain, and it is a hook the print path may want back. It now
   carries no rule at all, which is what "no boundaries" means. */
/* The sort arrow lives in the cell's right padding, not inline before the
   label. Inline it pushed "Name" two characters right of every other label, so
   the column of headings stopped aligning with the column of values - and it
   was the only tell that three of the nine columns were sortable at all.
   In the margin it marks the affordance without stealing any text width. */
/* Sort state is carried by the RULE under the heading, not by tinting the cell.
   Filling a cell with blue on hover meant a column lit up as a large flat block
   the moment the pointer crossed it, which fought the ruled look of the band and
   made the header the loudest thing in the table. A 3px accent on the bottom
   edge reads as "this is the active column" and takes no vertical space. */
body[data-page="masterlist"] .masterlist-table th[data-sort]{cursor:pointer;user-select:none}
body[data-page="masterlist"] .masterlist-table th[data-sort] i{
  position:absolute;right:8px;top:50%;transform:translateY(-50%);
  font-size:9px;color:#a8b6c6;opacity:0;transition:opacity .12s ease
}
body[data-page="masterlist"] .masterlist-table th[data-sort]:hover{color:#1d4ed8}
body[data-page="masterlist"] .masterlist-table th[data-sort]:hover i,
body[data-page="masterlist"] .masterlist-table th[data-sort][aria-sort] i{opacity:1}
body[data-page="masterlist"] .masterlist-table th[aria-sort="ascending"],
body[data-page="masterlist"] .masterlist-table th[aria-sort="descending"]{
  color:#1d4ed8;box-shadow:inset 0 -3px 0 #2563eb
}
body[data-page="masterlist"] .masterlist-table th[aria-sort] i{color:#2563eb}
body[data-page="masterlist"] .masterlist-table th[data-sort]:focus-visible{outline:2px solid #2563eb;outline-offset:-2px}
body[data-page="masterlist"] .masterlist-table td{padding:13px 14px;border-bottom:1px solid #eef2f6;color:#1e293b;vertical-align:middle}
/* Row rhythm. A hairline rather than a full border: the row reads as one line
   of type, and a full rule under every row would fight the name's caps. */
body[data-page="masterlist"] .masterlist-table tbody tr:nth-child(even){background:#fbfcfe}
body[data-page="masterlist"] .masterlist-table tbody tr:hover{background:#eff6ff!important}
body[data-page="masterlist"] .masterlist-table tbody tr:last-child td{border-bottom:0}
/* Tokens. Monospaced digits so a column of IDs and year numbers aligns
   vertically without the reader having to track it. Set at 13.5px, not the
   11.5px the table used to run: monospace carries a small apparent size at any
   given point size, so shrinking it again on top of a small table left the
   student ids - the thing a registrar reads to call a student up - as the
   smallest text on the page. It now matches the body size; the monospace face
   being narrower means a token takes no more room than it did at 11.5px in a
   15px-interleaved row, because the tracks were measured against it. */
.ml-tok{font-family:'JetBrains Mono',ui-monospace,SFMono-Regular,Menlo,monospace;font-size:15px;font-variant-numeric:tabular-nums;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ml-rowno{font-family:'JetBrains Mono',ui-monospace,monospace;font-size:13.5px;font-variant-numeric:tabular-nums;color:#94a3b8}
/* The name is the anchor, and it is set in caps. Three reasons, in order:

   1. A roster is a register, not a contact list. Filipino school and office
      records set names in caps for exactly this reason - it is the convention
      of the document, and a name that breaks it reads as informally typed.
   2. It gives the one column a texture nothing else has, so a clerk can find
      their place in a fifty-row list by shape alone. Every other column is
      short tokens of similar weight; this is the only block of unbroken type.
   3. Caps let the SURNAME carry the weight instead of the size. Both halves
      stay at 15px - the earlier version set the given names at 11.5px grey
      under a caps surname, and that read as a headline over a caption, which
      is not what a name is.

   The caps are applied in CSS, not baked into the stored value. Uppercasing in
   PHP would write AQUINO into the DOM text, and then a search for "Aquino" - the
   name as it is actually written in the database and on every form the student
   signs - would find nobody, because the box matches the rendered text. CSS
   text-transform is presentation: the markup still carries "Aquino".

   The name wraps rather than truncating. Every row must show the whole name. */
.ml-name{display:block;min-width:0;text-decoration:none}
.ml-name .sur{font-weight:700;color:#0f172a}
.ml-name .giv{font-weight:400;color:#1e293b;overflow-wrap:anywhere}
.ml-name .sur,.ml-name .giv{text-transform:uppercase;letter-spacing:.015em}
.ml-name:hover .sur{color:#1d4ed8;text-decoration:underline}
.ml-name:hover .giv{color:#1d4ed8}
.ml-name:focus-visible{outline:2px solid #2563eb;outline-offset:2px;border-radius:3px}
/* Gender. A short word in the ordinary body face, not a pill: it is a
   two-valued attribute, and a badge for every row would make the column shout
   about the least interesting thing in the table. The muted tone for a blank
   value matches "N/A" elsewhere rather than inventing a colour. */
.ml-gender{color:#1e293b}
.ml-gender:empty{color:#94a3b8}
/* RFID. The card number is an identifier, so it is set as one - monospace, on
   the same footing as the student id beside it. The tone is carried by the
   state, not by the digits: a live card is plain type and only a card in
   trouble is coloured, because most of a roster is live cards and colouring
   those would hide the exceptions. "Not issued" is worded rather than blank,
   since a blank cell in an id column reads as a fault. */
.ml-card{font-family:inherit}
.ml-card-none{font-style:italic;color:#94a3b8}
.ml-card-lost,.ml-card-expired,.ml-card-archived{color:#b91c1c}
.ml-card-ok{color:#334155}
/* Data quality. Removed from this table: the score was a single number standing
   in for a dozen fields, and the reader could not act on it from here. The two
   fields it was really flagging - contact number and birth date - are both
   columns now, so the gap it warned about is visible directly. The Students
   roster still carries the score, where the modal to fix a record sits next to
   it. */
/* The program reads as its acronym. The full name is a whole line of itself
   at column width and is already on the block heading above, so it rides
   along in the title attribute instead. */
.ml-course{display:inline-block;padding:3px 10px;border-radius:6px;background:#e8effd;color:#1d4ed8;font-size:14px;font-weight:800;letter-spacing:.05em}
/* The section code. Inherits .ml-tok's monospace, which is the point: a
   code has to be read digit by digit, and 11001 vs 11011 in a
   proportional face is a coin toss. Not a pill - it is a code, not a
   status, and the roster already has two pill columns beside it.
   "Unassigned" is a worded grey, never a blank cell, so nobody reads a
   student nobody has placed yet as a student whose code failed to load. */
.ml-section{color:#0f172a;font-weight:700;letter-spacing:.02em}
.ml-section-none{color:#94a3b8;font-weight:600;font-style:italic;letter-spacing:0}
/* Status. Four tones, all quiet: a roster is not an alert dashboard, and a
   column of saturated pills would out-shout the names it sits beside. "Not
   recorded" is deliberately greyed and worded, not blanked - an empty pill
   reads as a rendering failure, which is the one thing it must not do. */
.ml-status{display:inline-block;padding:3px 10px;border-radius:6px;font-size:14px;font-weight:700;letter-spacing:.03em;white-space:nowrap}
.ml-status-good{background:#e7f6ec;color:#15803d}
.ml-status-watch{background:#fef3c7;color:#b45309}
.ml-status-plain{background:#eef2f6;color:#475569}
/* Not recorded is real information - "no status on file" - not decoration, so it
   is worded and toned like the other three rather than faded to the point where
   it reads as a smudge beside text that is now 15px. */
.ml-status-unknown{background:transparent;color:#64748b;font-style:italic;font-weight:600;padding-left:0}
/* The block heading. A block is a program-year-TERM cohort, so the heading
   names exactly that and nothing more. The section codes are a roll-up of
   the students inside it, not part of what defines it - one program+year+term
   is one section space, so a block can hold several codes at once once it
   outgrows the cap. They ride in the meta row as chips, which keeps the
   heading a single filing label however many blocks a program has. */
/* The block heading names a cohort: which program, which year, which term, how
   many. That is a filing label, not a headline, so it is built like one.

   The four facts are not peers. The PROGRAM is what the sheet is, so it leads
   and is the largest thing here. Year and term define the cohort inside that
   program, so they sit with it. The school year and the count are the frame
   around the cohort - true of it, not part of its name - so they drop to a
   second line in a lighter register. Setting them all at one weight and one
   size, as a single run of chips, made the heading read as four unrelated
   labels and gave the reader no way to tell which part names the cohort.

   A 3px accent bar at the left, running the full height, keys the heading to
   the table directly beneath it. It replaces the users icon, which was the same
   glyph on every block and so told the reader nothing.
   .ml-block-head is a flex row, so the bar is a ::before rather than an element
   of its own - one less node in the markup for a decorative rule. */
.ml-block-head{
  display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;
  padding:14px 18px 13px;background:#f7f9fc;border-bottom:1px solid #dbe3ee;
  position:relative
}
.ml-block-head::before{
  content:"";position:absolute;left:0;top:0;bottom:0;width:3px;
  background:linear-gradient(180deg,#2563eb,#1e40af)
}
.ml-block-id{min-width:0;flex:1 1 320px}
.ml-block-lead{
  display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;
  margin:0;font-size:19px;font-weight:800;letter-spacing:-.015em;line-height:1.2
}
/* The acronym leads. It is the one token a registrar scans for, and the full
   program name is a whole line of itself at this size - it rides along in the
   h2's title attribute, which is also what the printed sheet reads. */
.ml-block-acronym{color:#0f172a}
.ml-block-cohort{font-size:15px;font-weight:700;color:#1d4ed8;letter-spacing:0}
/* The frame: school year and count, in the body's own grey. A count is a fact
   about the block, not its name, so it is the quietest thing in the heading. */
.ml-block-meta{
  display:flex;align-items:center;gap:9px;flex-wrap:wrap;
  margin-top:4px;font-size:13px;font-weight:500;color:#64748b
}
.ml-block-sy{font-family:'JetBrains Mono',ui-monospace,Menlo,monospace;font-size:12.5px;letter-spacing:-.01em}
.ml-block-meta-sep{color:#c3ced9}
.ml-block-count{font-variant-numeric:tabular-nums;font-weight:700;color:#475569}
.ml-block-count-over{color:#b45309}
/* Section chips in the block heading. They are buttons, not labels:
   clicking one opens that section for editing, which is the only way
   to rename a code or move a whole section. The count sits inside the
   chip rather than beside it so the heading cannot reflow as codes are
   added - a heading that jumps when auto-assign runs is a heading
   nobody trusts. Over-cap is amber, the same tone the block count uses
   for "past the list size", so the two warnings read as one language. */
.ml-block-sections{display:inline-flex;align-items:center;gap:5px;flex-wrap:wrap}
.ml-section-chip{
  display:inline-flex;align-items:center;gap:5px;padding:2px 7px;
  border:1px solid #c7d7fe;border-radius:7px;background:#eff6ff;color:#1d4ed8;
  font-family:'JetBrains Mono',ui-monospace,Menlo,monospace;font-size:12px;font-weight:700;
  letter-spacing:.02em;line-height:1.4;cursor:pointer;white-space:nowrap;
  transition:background .12s ease,border-color .12s ease
}
.ml-section-chip:hover{background:#dbeafe;border-color:#93c5fd}
.ml-section-chip:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
.ml-section-chip-n{
  padding:0 4px;border-radius:4px;background:#dbeafe;color:#1e40af;
  font-size:10.5px;font-weight:800;font-variant-numeric:tabular-nums
}
.ml-section-chip-over{border-color:#fcd34d;background:#fffbeb;color:#b45309}
.ml-section-chip-over .ml-section-chip-n{background:#fde68a;color:#92400e}
.ml-block-unassigned{color:#b45309;font-weight:600}
/* The count line under the buttons. Quiet by default: it is a status
   readout, not a call to action, and the buttons above already say
   what to do about it. */
.masterlist-section-status{margin:2px 0 0;font-size:12px;color:#64748b;line-height:1.5}
.masterlist-section-status i{margin-right:4px;color:#b45309}
.masterlist-section-status strong{color:#b45309;font-variant-numeric:tabular-nums}
/* Narrow: the lead wraps rather than shrinking, so the cohort name stays the
   largest text on the line down to a phone. */
@media(max-width:640px){
  .ml-block-lead{font-size:17px}
  .ml-block-cohort{font-size:14px}
}
/* The scroll container. overflow-x:auto ONLY - see the note at the div: adding
   `overflow: hidden` after it to round the corners was silently disabling the
   scroll and clipping the right-hand columns. clip-path rounds without
   touching overflow. */
.ml-table-scroll{margin-bottom:10px;overflow-x:auto;clip-path:inset(0 round 12px);-webkit-overflow-scrolling:touch}
/* "Table 2 of 2" — shown only when a block runs past the cap and starts a new
   list. A continuation of the block above, not a heading in its own right, so it
   is quiet: white, a hairline, and the same small grey as the count. It was an
   amber band on its own row, and once the block heading below it was given a
   real hierarchy that band became the loudest thing in the block - a clerk's
   eye went to "TABLE 1 OF 2" before it went to the cohort the table belongs to.
   Amber is now reserved for the one thing on this page that IS a warning: a
   count past the cap. */
.ml-table-tag{
  display:flex;align-items:center;gap:8px;padding:6px 18px;
  background:#fbfcfe;border-bottom:1px solid #e8edf3;color:#64748b;
  font-size:12.5px;font-weight:600;letter-spacing:0;text-transform:none
}
.ml-table-tag i{color:#94a3b8;font-size:11px}
.ml-table-tag span{color:#475569;font-weight:700;font-variant-numeric:tabular-nums}
@media(max-width:640px){.masterlist-header{padding:21px 18px}.masterlist-header h1{font-size:25px}.masterlist-actionbar{flex-direction:column}.masterlist-action-group{width:100%}.masterlist-action-buttons .btn{flex:1 1 100%;justify-content:center}.masterlist-toolbar{align-items:stretch}.masterlist-search{flex-basis:100%}.masterlist-filter-btn{justify-content:center}}
/* Narrow widths. The fixed tracks add up to more than a phone can show, and
   a fixed-layout table squeezed below that does not reflow - it crushes the
   one flexible track, which is the name, down to a single letter. So the table
   is given a floor and the wrapper's existing overflow-x:auto takes over.

   Dropping the narrow columns instead was tried and rejected: hiding a <col>
   is not reliably supported, and hiding a <th> under table-layout:fixed leaves
   the colgroup and the header row disagreeing about how many columns exist.
   The parent already scrolls; a horizontally scrolling data table is a
   well-understood affordance and it keeps every column readable, which a
   silently hidden one does not. */
body[data-page="masterlist"] .masterlist-table{min-width:1390px}
@media(prefers-reduced-motion:reduce){body[data-page="masterlist"] .masterlist-table tbody tr{transition:none}}
</style>

<main class="main">
    <header class="masterlist-header">
        <div>
            <div class="masterlist-kicker"><i class="fas fa-table-list"></i> Registrar directory</div>
            <h1>Masterlist</h1>
            <p>Search, filter, and send the full student list. Open a section chip in a block heading to rename it or change who is in it. Codes follow the format 11001 (year 1, 1st semester, section 1).</p>
        </div>

        <!-- THE VIEWS OF THE SAME RECORDS.
             There are three: the flat list is what gets printed and
             signed, the Folders table shows every folder on one
             expandable page, and the Folders browser walks into one
             folder at a time the way a shared drive does — click BSIT
             and you are inside BSIT.

             There is NO view toggle in the header any more. All three
             buttons are gone: Browse, Table, List. What replaced a
             choice is a default — the browser is simply what this page
             IS, and ?view=folders and ?view=list still render in full
             for anyone holding the URL.

             So the header no longer states which view you are in. That
             is a real loss and it is deliberate: the one button that
             was left said nothing the page did not already say, and
             three buttons advertised two ways into a module that has
             one. The view a reader is in is now visible from the page
             itself — folder tiles, or a ledger.

             $carryFilters stays. The toggle was its first caller, but
             the BROWSER's folder links (below) are the second: without
             it, clicking from a filtered folder tree into another
             folder would silently drop the filters. -->
        <?php
        // The filter part of a link that changes WHERE you are looking:
        // the dimensions the reader already chose, minus the
        // view/location keys that are about to be replaced anyway.
        //
        // This used to be only the view toggle. That toggle is gone,
        // so this closure looks vestigial at first glance and is not —
        // the folder links further down are what still need it. Do not
        // delete it on the grounds that "the toggle went away".
        $carryFilters = static function (array $drop) use ($anyFilterActive): string {
            if (!$anyFilterActive) {
                return '';
            }
            $kept = array_diff_key($_GET, $drop);
            return $kept ? '&amp;' . http_build_query($kept) : '';
        };
        ?>
    </header>

    <!-- Action bar: one group. Both section-creation entry points (auto-assign
         and Create Section) are gone, so the bar no longer splits into
         "section tools" and "output". What is left is the hand-off, and the
         status line under it still reports the work that has yet to be done. -->
    <section class="masterlist-actionbar" aria-label="Masterlist actions">
        <div class="masterlist-action-group">
            <span class="masterlist-action-label">Output &amp; handoff</span>
            <div class="masterlist-action-buttons">
                <button type="button" class="btn btn-primary" id="btnPrepareList"
                        title="Show every student who has a section, with no filters applied">
                    <i class="fas fa-list-check"></i> Prepare Full List
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
            <p class="masterlist-section-status" id="sectionStatus">
                <?php if ($studentsTotal === 0): ?>
                    No students on file yet.
                <?php elseif ($unassignedCount === 0): ?>
                    <i class="fas fa-check-circle"></i> All <?= $studentsTotal ?> student(s) are in a section.
                <?php else: ?>
                    <i class="fas fa-user-clock"></i>
                    <strong><?= $unassignedCount ?></strong> of <?= $studentsTotal ?> student(s) still need a section
                    &mdash; not listed below
                    <?php if ($sectionTotal > 0): ?>
                        &middot; <?= $sectionTotal ?> section(s) so far
                    <?php endif; ?>
                <?php endif; ?>
            </p>
        </div>
    </section>

    <?php if ($prepared): ?>
        <div class="card" style="margin-bottom: 16px; padding: 12px 16px; background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <i class="fas fa-check-circle"></i> Full list prepared. It holds every student who has a section, sorted by course, year, and section, with no filters applied.
        </div>
    <?php endif; ?>

    <!-- Toolbar: the search box and Filter. Create Section is gone - a new
         section code is no longer minted from this page; the chips in the
         block headings still open Edit / Manage Students for sections that
         already exist.

         "Select all shown" and the "Showing N student(s)" count describe the
         PRINTABLE LIST, not a folder: they counted rows in every cohort on
         file and fed the bulk bar, which acts on those rows. Leaving them on
         the Browse view offered a checkbox that ticked nothing and a count
         that did not describe the folder actually open, so they are shown
         only where they are true. The search box is kept on both - it is
         wired to whichever table the current view renders. -->
    <section class="masterlist-toolbar" aria-label="Masterlist filters">
        <div class="masterlist-search">
            <i class="fas fa-search"></i>
            <input type="text" id="masterlistSearch" name="q" class="form-control"
                   placeholder="<?= $view === 'explore'
                       ? 'Search this folder by name, student no., email…'
                       : 'Search by name, student no., course…' ?>">
        </div>
        <button type="button" class="btn btn-primary masterlist-filter-btn" onclick="openFilterSearchModal()">
            <i class="fas fa-sliders"></i> Filter
            <?php if ($anyFilterActive): ?>
                <span style="background:#dc2626;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;">Active</span>
            <?php endif; ?>
        </button>
        <?php if ($view === 'list'): ?>
            <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#475569;cursor:pointer;">
                <input type="checkbox" id="selectAllPage" style="width:16px;height:16px;accent-color:#2563eb;"> Select all shown
            </label>
            <span style="font-size:13px;color:#64748b;">Showing <strong id="showingCount"><?= $totalStudents ?></strong> student(s)</span>
        <?php else: ?>
            <span style="font-size:13px;color:#64748b;">
                <?php if ($view === 'explore' && $folderNode): ?>
                    In <strong><?= htmlspecialchars((string) $folderNode['name']) ?></strong>:
                    <strong id="showingCount"><?= $view === 'explore' ? count($folderNode['students']) : 0 ?></strong> student(s)
                <?php endif; ?>
            </span>
        <?php endif; ?>
    </section>

    <!-- Bulk action bar -->
    <div class="bulk-bar" id="bulkBar" style="display:none;padding:10px 16px;background:#eef4ff;border:1px solid #bfdbfe;border-radius:12px;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
        <span style="font-size:13px;font-weight:600;color:#1d4ed8;" id="bulkCount">0 selected</span>
        <button class="btn btn-secondary btn-sm" onclick="exportSelectedCSV()"><i class="fas fa-file-csv"></i> Export CSV</button>
        <button class="btn btn-secondary btn-sm" onclick="printSelected()"><i class="fas fa-print"></i> Print</button>
        <button class="btn btn-danger btn-sm" onclick="bulkArchive()"><i class="fas fa-archive"></i> Archive</button>
        <a class="btn btn-secondary btn-sm" href="rfid-cards.php"><i class="fas fa-credit-card"></i> Assign RFID</a>
    </div>

    <div id="masterlistContent">
<?php if ($view === 'explore' && $folderNode): ?>
        <?php
        // THE FOLDER BROWSER.
        //
        // Not a table with folders in it — a real browser. The page
        // is always standing at exactly ONE folder: the root holding
        // program folders, or inside BSIT holding year folders, or
        // inside a section holding the roster it contains. Clicking a
        // folder goes INTO it.
        //
        // mlf_resolve() already decided what that one folder holds —
        // its breadcrumbs, its sub-folders, and for a section, its
        // students. This markup only places them. The alternative,
        // rendering the whole tree on one long page, was tried and is
        // the wrong shape: it cannot answer "show me BSIT" without
        // making the reader find BSIT in the middle of everything.
        //
        // Navigation is real links, not JS. The location is the URL,
        // so a folder can be bookmarked, pasted into an email to a
        // department, and reached with Back.
        $node       = $folderNode;
        $isLeaf     = $node['level'] === 'section';
        $carryQuery = $carryFilters(['view' => 1, 'open' => 1, 'path' => 1]);
        $toFolder   = static function (string $path) use ($carryQuery): string {
            return 'masterlist.php?view=explore'
                 . ($path === '' ? '' : '&amp;path=' . rawurlencode($path))
                 . $carryQuery;
        };
        // mlf_resolve() hands back breadcrumbs that END at the folder
        // you are standing in. So the ancestors are the links and the
        // LAST crumb is the one you are already on — it renders as
        // plain text. Appending $node['name'] as well, as an earlier
        // draft of this markup did, printed "BSIT / Year 1 / 11001 /
        // 11001".
        //
        // The second branch is the stale-link case: mlf_resolve()
        // returns the root with exists=false when the path names
        // nothing. Showing that root as-is would render an empty
        // folder screen under a message promising a list, so the root
        // is re-resolved here and the programs are shown for real.
        $nodeMissing = !$node['exists'];
        if ($nodeMissing) {
            $node = mlf_resolve(mlf_build_tree($allStudents), '');
        }
        $crumbs  = $node['breadcrumbs'];
        $lastCrumb = array_key_last($crumbs);
        ?>
        <div class="mlx-browser card">
            <!-- Where you are. The root crumb is always present so
                 there is always one click back to the top, which is
                 the thing a drive always gives you and a tree does
                 not. -->
            <nav class="mlx-crumbs" aria-label="Folder path">
                <a class="mlx-crumb mlx-crumb-root" href="<?= $toFolder('') ?>">
                    <i class="fas fa-hard-drive"></i> Masterlist
                </a>
                <?php foreach ($crumbs as $crumbIndex => $crumb): ?>
                    <i class="fas fa-chevron-right mlx-crumb-sep" aria-hidden="true"></i>
                    <?php
                    // The program crumb is printed as its acronym. The
                    // path in the URL keeps the full name - only the
                    // label changes - so every link still resolves.
                    // $crumbIndex 0 IS the program, by the shape
                    // mlf_resolve() builds: program, year, section.
                    $cIsProgram = ($crumbIndex === 0);
                    $cLabel = $cIsProgram
                        ? courseDisplay((string) $crumb['name'])
                        : (string) $crumb['name'];
                    $cTitle = $cIsProgram
                        ? courseDisplayTitle((string) $crumb['name'])
                        : '';
                    ?>
                    <?php if ($crumbIndex === $lastCrumb): ?>
                        <span class="mlx-crumb mlx-crumb-here"
                              <?php if ($cTitle !== ''): ?> title="<?= htmlspecialchars($cTitle) ?>"<?php endif; ?>>
                            <?= htmlspecialchars($cLabel) ?>
                        </span>
                    <?php else: ?>
                        <a class="mlx-crumb" href="<?= $toFolder((string) $crumb['path']) ?>"
                           <?php if ($cTitle !== ''): ?>
                               title="<?= htmlspecialchars($cTitle) ?>"
                               aria-label="<?= htmlspecialchars((string) $crumb['name']) ?>"
                           <?php endif; ?>>
                            <?= htmlspecialchars($cLabel) ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>

            <?php if ($nodeMissing): ?>
                <!-- The URL named a folder that is not there — a stale
                     link, or a section that has since been renamed.
                     Landing on the root and staying silent would look
                     exactly like the link having worked, so the page
                     says so, and then shows the root so the reader is
                     not stranded. -->
                <div class="mlx-missing">
                    <i class="fas fa-folder-open"></i>
                    <p><strong>That folder is not here.</strong></p>
                    <p>It may have been renamed, or the link may be out of date. Everything on file is listed below.</p>
                </div>
            <?php endif; ?>

            <div class="mlx-head">
                <div>
                    <div class="mlx-title">
                        <i class="fas <?= $isLeaf ? 'fa-file-lines' : ($node['level'] === 'root' ? 'fa-hard-drive' : 'fa-folder-open') ?>"></i>
                        <?php
                        // The heading is the program name when the reader
                        // is standing in a program. Shown as its acronym,
                        // for the same reason the tile is: this is a
                        // list of programs in the reader's mind, and the
                        // full title is the tooltip.
                        $hTitle = $node['level'] === 'program'
                            ? courseDisplayTitle((string) $node['name'])
                            : '';
                        ?>
                        <span <?php if ($hTitle !== ''): ?>
                            title="<?= htmlspecialchars($hTitle) ?>"
                            aria-label="<?= htmlspecialchars((string) $node['name']) ?>"
                        <?php endif; ?>><?= htmlspecialchars(
                            $node['level'] === 'program'
                                ? courseDisplay((string) $node['name'])
                                : (string) $node['name']
                        ) ?></span>
                    </div>
                    <div class="mlx-sub">
                        <?php if ($isLeaf): ?>
                            <?= (int) $node['count'] ?> student<?= (int) $node['count'] === 1 ? '' : 's' ?>
                            &middot; section <strong><?= htmlspecialchars((string) ($node['section'] ?? '')) ?></strong>
                            &middot; <?php
                                // The program named beside the section,
                                // as its acronym - same rule as the tile
                                // and the breadcrumb above.
                                $pName  = (string) ($node['program'] ?? '');
                                $pTitle = courseDisplayTitle($pName);
                                ?><span <?php if ($pTitle !== ''): ?>
                                    title="<?= htmlspecialchars($pTitle) ?>"
                                    aria-label="<?= htmlspecialchars($pName) ?>"
                                <?php endif; ?>><?= htmlspecialchars(courseDisplay($pName)) ?></span>
                            / <?= htmlspecialchars((string) ($node['year'] ?? '')) ?>
                        <?php elseif ($node['level'] === 'root'): ?>
                            <?= count($node['folders']) ?> program folder<?= count($node['folders']) === 1 ? '' : 's' ?>
                            &middot; <?= (int) $folderTotal ?> student<?= (int) $folderTotal === 1 ? '' : 's' ?> on file
                        <?php else: ?>
                            <?= count($node['folders']) ?> folder<?= count($node['folders']) === 1 ? '' : 's' ?>
                            &middot; <?= (int) $node['count'] ?> student<?= (int) $node['count'] === 1 ? '' : 's' ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mlx-actions">
                    <?php if ((string) $node['path'] !== ''): ?>
                        <a class="btn btn-secondary btn-sm" href="<?= $toFolder((string) $node['parent']) ?>">
                            <i class="fas fa-arrow-left"></i> Up one level
                        </a>
                    <?php endif; ?>
                    <?php if ((int) $node['count'] > 0): ?>
                        <!-- The whole subtree, not just what is on screen. -->
                        <a class="btn btn-secondary btn-sm"
                           href="../api/masterlist-folders.php?action=export&amp;path=<?= rawurlencode((string) $node['path']) ?>">
                            <i class="fas fa-file-zipper"></i> Download
                        </a>
                    <?php endif; ?>
                </div>
            </div>
<?php if ($node['folders']): ?>
                <!-- FOLDERS. Each one is a link to go INTO it, which is
                     the whole interaction: there is no expand arrow
                     here, because nothing on this screen reveals
                     anything else on this screen. -->
                <div class="mlx-folders">
                    <?php foreach ($node['folders'] as $folder):
                        $fCount  = (int) $folder['count'];
                        $fInside = (int) $folder['subfolders'];
                        // A PROGRAM tile is printed as its acronym -
                        // "BSIT", not 55 characters of degree title.
                        // Every list of programs is scanned for the
                        // acronym, never read, and the root is exactly
                        // that list.
                        //
                        // Year and section folders are NOT abbreviated.
                        // "Year 1" has no shorter true form, and a
                        // section code IS the name.
                        $fIsProgram = ($folder['kind'] ?? '') === 'program';
                        $fName   = $fIsProgram
                            ? courseDisplay((string) $folder['name'])
                            : (string) $folder['name'];
                        $fTitle  = $fIsProgram
                            ? courseDisplayTitle((string) $folder['name'])
                            : '';
                        ?>
                        <a class="mlx-tile <?= $folder['unassigned'] ? 'is-unassigned' : '' ?>"
                           href="<?= $toFolder((string) $folder['path']) ?>"
                           <?php if ($fTitle !== ''): ?>
                               title="<?= htmlspecialchars($fTitle) ?>"
                               aria-label="<?= htmlspecialchars((string) $folder['name']) ?>"
                           <?php endif; ?>>
                            <i class="fas fa-folder mlx-tile-icon"></i>
                            <span class="mlx-tile-body">
                                <span class="mlx-tile-name"><?= htmlspecialchars($fName) ?></span>
                                <span class="mlx-tile-meta">
                                    <?= $fCount ?> student<?= $fCount === 1 ? '' : 's' ?>
                                    <?php if ($fInside > 0): ?>
                                        &middot; <?= $fInside ?> folder<?= $fInside === 1 ? '' : 's' ?> inside
                                    <?php endif; ?>
                                </span>
                                <?php if ($folder['unassigned']): ?>
                                    <span class="mlf-tag">not yet placed</span>
                                <?php endif; ?>
                            </span>
                            <i class="fas fa-chevron-right mlx-tile-go" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>


        <?php if ($isLeaf): ?>
                <?php
                // THE ROSTER THIS FOLDER HOLDS.
                //
                // Reached by clicking a section folder — this is the
                // "show me the table of all the details" the reader
                // asked for. Columns come from mlf_roster_columns(),
                // the same set the ZIP export writes, so what is read
                // here is exactly what gets handed off.
                //
                // These rows were NOT filtered by "has a section" —
                // they are the inventory, so a student filed under
                // "Unassigned Section" is visible and fixable rather
                // than missing.
                ?>
                <div class="mlx-roster-scroll">
                    <!-- Deliberately NOT .masterlist-table.
                         That class is the printable List's ledger: it is
                         table-layout:fixed with hand-measured per-column
                         tracks (.c-name, .c-contact, .c-email …) and a
                         1390px floor. This table sets none of those
                         tracks, so every one of its fourteen columns
                         fell to an equal share and the floor forced a
                         needless sideways scroll. It also had the List's
                         search, sort and CSV-export JavaScript attaching
                         to it by class, which exported the roster through
                         the List's column map. .mlx-table is its own
                         thing and is styled below. -->
                    <table class="mlx-table">
                        <thead>
                            <tr>
                                <th class="mlx-x-no">#</th>
                                <?php foreach (mlf_roster_columns() as $colLabel): ?>
                                    <th><?= htmlspecialchars((string) $colLabel) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rowNo = 1; foreach ($node['students'] as $student): ?>
                                <tr>
                                    <td class="mlx-x-no"><?= (int) $rowNo++ ?></td>
                                    <?php foreach (mlf_roster_row((array) $student) as $fieldValue): ?>
                                        <td><?= htmlspecialchars(trim((string) $fieldValue)) ?: '<span class="mlx-na">&mdash;</span>' ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$node['students']): ?>
                                <tr><td colspan="<?= 1 + count(mlf_roster_columns()) ?>" class="mlx-empty">This folder is empty.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($node['exists'] && !$node['folders']): ?>
                <div class="mlx-missing">
                    <i class="fas fa-folder-open"></i>
                    <p>This folder is empty.</p>
                </div>
            <?php endif; ?>

            <p class="mlf-table-note">
                <i class="fas fa-circle-info"></i>
                Click a folder to open it. This browser shows <strong>every</strong> student on file, including any not yet
                placed in a section; the <strong>List</strong> view is the printable roster and leaves those out.
            </p>
        </div>
<?php endif; ?>

<?php if ($programLegend): ?>
        <?php
        // THE PROGRAM LEGEND - the key to the acronyms.
        //
        // Every program above this point is printed as its acronym,
        // which is what makes a list of fifteen scannable. This table
        // is the other half of that decision: it is where a
        // registrar looks up what one of those acronyms MEANS, and
        // cannot be abbreviated without defeating the point.
        //
        // It sits below the browser rather than above it because it is
        // a reference, not the work. The reader came to open a folder;
        // the legend is what they consult once an acronym stops being
        // obvious.
        //
        // The count is deliberately NOT the filtered count. It is each
        // program's size across the whole table, because the question
        // a legend answers is "what is BSIT and how many are in it",
        // and a number that moved with the filter would answer a
        // different question every time the reader looked at it.
        //
        // Rows whose program has no acronym (stored short already) are
        // still listed - the reader needs to see that it is there, and
        // the two columns simply read the same.
        ?>
        <section class="mlx-legend card" aria-labelledby="mlxLegendTitle">
            <div class="mlx-legend-head">
                <h2 id="mlxLegendTitle" class="mlx-legend-title">
                    <i class="fas fa-book-open"></i> Programs
                </h2>
                <p class="mlx-legend-note">
                    Programs are shown as acronyms above. This is what each one stands for.
                </p>
            </div>
            <div class="mlx-legend-scroll">
                <table class="mlx-legend-table">
                    <thead>
                        <tr>
                            <th scope="col" class="mlx-legend-c-short">Acronym</th>
                            <th scope="col" class="mlx-legend-c-full">Program</th>
                            <th scope="col" class="mlx-legend-c-n">Students</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($programLegend as $p): ?>
                            <tr>
                                <td class="mlx-legend-c-short">
                                    <span class="mlx-legend-tag"><?= htmlspecialchars($p['short']) ?></span>
                                </td>
                                <td class="mlx-legend-c-full">
                                    <?php if ($p['abbrev']): ?>
                                        <span title="<?= htmlspecialchars($p['full']) ?>"><?= htmlspecialchars($p['full']) ?></span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($p['full']) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="mlx-legend-c-n"><?= (int) $p['count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
<?php endif; ?>
<?php if ($view === 'folders' && $folderRows): ?>
        <?php
        // THE FOLDER TABLE.
        //
        // ONE table. A program is a row, its years are rows inside
        // it, its sections are rows inside those, and the students
        // are rows inside a section. Clicking a folder row expands
        // or collapses whatever is under it.
        //
        // The rows arrive pre-flattened from mlf_rows(), which is
        // unit tested, so the ordering and the nesting are decided
        // once in shared code rather than re-decided by this markup.
        $folderDlBase = '../api/masterlist-folders.php?action=export';
        $folderCount  = count(array_filter($folderRows, static fn($r) => $r['type'] === 'folder'));
        ?>
        <div class="card mlf-table-card">
            <div class="mlf-table-toolbar">
                <div class="mlf-table-actions">
                    <button type="button" class="btn btn-secondary btn-sm" id="mlfExpandAll">
                        <i class="fas fa-angle-double-down"></i> Expand all
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" id="mlfCollapseAll">
                        <i class="fas fa-angle-double-up"></i> Collapse all
                    </button>
                    <?php if ($focusPath !== ''): ?>
                        <a class="btn btn-secondary btn-sm"
                           href="masterlist.php?view=folders<?= $anyFilterActive ? '&amp;' . http_build_query(array_diff_key($_GET, ['view' => 1, 'open' => 1])) : '' ?>">
                            <i class="fas fa-times"></i> Show whole drive
                        </a>
                    <?php endif; ?>
                </div>
                <span class="mlf-table-count">
                    <strong><?= (int) $folderTotal ?></strong> student<?= (int) $folderTotal === 1 ? '' : 's' ?>
                    in <strong><?= $folderCount ?></strong> folder<?= $folderCount === 1 ? '' : 's' ?>
                </span>
            </div>

            <table class="mlf-table" id="mlfTable">
                <thead>
                    <tr>
                        <th class="mlf-c-name">Folder / Student</th>
                        <th class="mlf-c-type">Type</th>
                        <th class="mlf-c-num">Students</th>
                        <th class="mlf-c-sub">Inside</th>
                        <th class="mlf-c-num">Year</th>
                        <th class="mlf-c-tok">Section</th>
                        <th class="mlf-c-status">Status</th>
                        <th class="mlf-c-contact">Contact</th>
                        <th class="mlf-c-email">Email</th>
                        <th class="mlf-c-dl">Download</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($folderRows as $row):
                        $isFolder = $row['type'] === 'folder';
                        $level    = $isFolder ? (int) $row['level'] : 3;
                        $hasKids  = $isFolder && ($row['subfolders'] > 0 || $row['count'] > 0);
                        $isOpen   = isset($folderOpen[$row['path']]);
                        $hidden   = !mlf_row_visible($row, $folderOpen);
                        $parent   = (string) $row['parent'];
                        ?>
                        <tr class="mlf-row mlf-row-<?= $isFolder ? 'folder' : 'student' ?>"
                            data-parent="<?= htmlspecialchars($parent) ?>"
                            data-path="<?= htmlspecialchars((string) $row['path']) ?>"
                            data-open="<?= $isOpen ? '1' : '0' ?>"
                            <?= $hidden ? 'style="display:none"' : '' ?>><td class="mlf-c-name" style="padding-left:<?= 12 + ($level * 22) ?>px">
                                <?php if ($hasKids): ?>
                                    <button type="button" class="mlf-caret<?= $isOpen ? ' is-open' : '' ?>"
                                            data-caret="<?= htmlspecialchars((string) $row['path']) ?>"
                                            aria-expanded="<?= $isOpen ? 'true' : 'false' ?>"
                                            title="<?= $isOpen ? 'Collapse' : 'Expand' ?> this folder">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                <?php else: ?>
                                    <span class="mlf-caret mlf-caret-none" aria-hidden="true"></span>
                                <?php endif; ?>
                                <i class="fas <?= $isFolder
                                    ? ($level === 0 ? 'fa-folder' : ($level === 1 ? 'fa-calendar' : 'fa-users'))
                                    : 'fa-user' ?> mlf-row-icon<?= $row['unassigned'] ? ' is-unassigned' : '' ?>"></i>
                                <?php
                                // A program row prints as its acronym. Level
                                // 0 IS a program; years and sections are
                                // left alone, because "Year 1" has no
                                // shorter true form.
                                $rowLabel = $isFolder && $level === 0
                                    ? courseDisplay(trim((string) $row['name']))
                                    : trim((string) $row['name']);
                                $rowTitle = $isFolder && $level === 0
                                    ? courseDisplayTitle(trim((string) $row['name']))
                                    : '';
                                ?>
                                <span class="mlf-row-name"
                                      <?php if ($rowTitle !== ''): ?>
                                          title="<?= htmlspecialchars($rowTitle) ?>"
                                          aria-label="<?= htmlspecialchars(trim((string) $row['name'])) ?>"
                                      <?php endif; ?>><?= htmlspecialchars($rowLabel ?: 'N/A') ?></span>
                                <?php if ($isFolder && $row['unassigned']): ?>
                                    <span class="mlf-tag">not yet placed</span>
                                <?php endif; ?>
                            </td>
                            <td class="mlf-c-type">
                                <span class="mlf-type mlf-type-<?= $isFolder ? $level : 'student' ?>">
                                    <?= $isFolder
                                        ? ($level === 0 ? 'Program' : ($level === 1 ? 'Year' : 'Section'))
                                        : 'Student' ?>
                                </span>
                            </td>
                            <td class="mlf-c-num"><?= $isFolder ? (int) $row['count'] : '' ?></td>
                            <td class="mlf-c-sub">
                                <?= $isFolder && $row['subfolders'] > 0
                                    ? (int) $row['subfolders'] . ' folder' . ($row['subfolders'] === 1 ? '' : 's')
                                    : '&mdash;' ?>
                            </td>
                            <?php if ($isFolder): ?>
                                <td class="mlf-c-num">&mdash;</td>
                                <td class="mlf-c-tok"><?= $level === 2 ? htmlspecialchars((string) $row['name']) : '&mdash;' ?></td>
                                <td class="mlf-c-status">&mdash;</td>
                                <td class="mlf-c-contact">&mdash;</td>
                                <td class="mlf-c-email">&mdash;</td>
                                <td class="mlf-c-dl">
                                    <?php if ((int) $row['count'] > 0): ?>
                                        <a class="mlf-dl" title="Download this folder and everything under it"
                                           href="<?= htmlspecialchars($folderDlBase . '&path=' . rawurlencode((string) $row['path'])) ?>">
                                            <i class="fas fa-file-zipper"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            <?php else:
                                $s       = (array) $row['student'];
                                $contact = trim((string) ($s['contact_number'] ?? ''));
                                $email   = trim((string) ($s['email'] ?? ''));
                                ?>
                                <td class="mlf-c-num"><?= htmlspecialchars(trim((string) ($s['year_level'] ?? '')) ?: 'N/A') ?></td>
                                <td class="mlf-c-tok"><?= htmlspecialchars(trim((string) ($s['section'] ?? '')) ?: 'N/A') ?></td>
                                <td class="mlf-c-status"><?= htmlspecialchars(trim((string) ($s['status'] ?? '')) ?: 'N/A') ?></td>
                                <td class="mlf-c-contact"><?= htmlspecialchars($contact !== '' ? $contact : 'N/A') ?></td>
                                <td class="mlf-c-email"><?= htmlspecialchars($email !== '' ? $email : 'N/A') ?></td>
                                <td class="mlf-c-dl">&mdash;</td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="mlf-table-note">
                <i class="fas fa-circle-info"></i>
                This view is an <strong>inventory</strong> &mdash; it lists every student on file, including
                any not yet placed in a section. The <strong>List</strong> view is the printable roster and
                leaves those out, because a row nobody could sign has no place on a signed sheet.
            </p>
        </div>
    <?php else: ?>
    <?php endif; ?>
    <?php
    // THE PRINTABLE LIST is the only thing below, and it is scoped to
    // ?view=list deliberately.
    //
    // The chain above ends in an `else`, which made this branch the
    // fallback for ANY view that is not the folder table - and the
    // folder BROWSER is not that branch, it is an independent `if`
    // above. So ?view=explore fell through to here and printed the
    // whole printable ledger underneath the browser: two complete
    // renderings of the same students on one page, the browser's
    // roster sitting on top of a roster the reader never asked for.
    //
    // Gating on $view rather than on emptiness is what makes the
    // three views mutually exclusive. tests/explore_render_check.php
    // asserts the ledger class is absent from a browser page, because
    // this class of bug is invisible in the markup until you render.
    if ($view === 'list'): ?>
        <?php if (empty($students)): ?>
            <div class="card" style="box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="padding: 48px 24px; text-align: center; color: #64748b;">
                    <i class="fas fa-users-slash" style="font-size:40px;color:#e2e8f0;display:block;margin-bottom:14px;"></i>
                    <p style="font-size:16px;font-weight:600;color:#334155;margin:0 0 8px;">No students found</p>
                    <?php if ($anyFilterActive): ?>
                        <p style="margin:0;">No students match your filters. Clear the filters to see the full list.</p>
                    <?php elseif ($unassignedCount > 0): ?>
                        <!-- The empty state has to say WHICH kind of empty this
                             is. "No students match your filters" is false when
                             no filter is on and the reason is simply that
                             nobody has placed these students in a section yet,
                             so the reader would go looking for a filter to
                             clear and find nothing to clear. -->
                        <p style="margin:0;">
                            <?= (int) $unassignedCount ?> student(s) on file have not been assigned to a section yet,
                            and this list only shows students who have one.
                            Open a section chip in a block heading and use <strong>Manage Students</strong> to place them.
                        </p>
                    <?php else: ?>
                        <p style="margin:0;">There are no students on file yet.</p>
                    <?php endif; ?>
                </div>
            </div>
    <?php else: ?>            <?php foreach ($blocks as $block): ?>
                <div class="card masterlist-section-block" style="margin-bottom: 16px;">
                    <!-- Names the program, the year, the term and the academic
                         year - everything the key groups by. It must show them
                         all: the blocks are separate tables now, and two
                         headings that read identically would leave the
                         registrar unable to tell which cohort a sheet is for.

                         The section codes are a roll-up, not part of the key:
                         a block is one program+year+term, which is one section
                         space, and a block can hold several codes at once
                         because it outgrew the cap. They ride in the meta row
                         as chips so the heading stays one line. -->
                    <div class="ml-block-head">
                        <div class="ml-block-id">
                        <h2 class="ml-block-lead" title="<?= htmlspecialchars(($block['course'] !== '' ? $block['course'] : 'No program recorded')
                                    . ($block['year_level'] !== '' ? ' — Year ' . $block['year_level'] : '')
                                    . ($block['semester'] !== '' ? ' — ' . $block['semester'] . ' Semester' : '')
                                    . ($block['school_year'] !== '' ? ' (' . $block['school_year'] . ')' : '')) ?>">
                            <span class="ml-block-acronym"><?= htmlspecialchars($block['acronym'] !== '' ? $block['acronym'] : 'N/A') ?></span>
                            <span class="ml-block-cohort"><?= htmlspecialchars('Year ' . ($block['year_level'] !== '' ? $block['year_level'] : '—')) ?><?php if ($block['semester'] !== ''): ?> · <?= htmlspecialchars($block['semester']) ?> sem<?php endif; ?></span>
                        </h2>
                        <div class="ml-block-meta">
                            <?php if ($block['school_year'] !== ''): ?>
                                <span class="ml-block-sy">S.Y. <?= htmlspecialchars($block['school_year']) ?></span>
                            <?php endif; ?>
                            <span class="ml-block-meta-sep" aria-hidden="true">·</span>
                            <span class="ml-block-count<?= count($block['students']) > (int) $sectionCap ? ' ml-block-count-over' : '' ?>"><?= count($block['students']) ?> <?= count($block['students']) === 1 ? 'student' : 'students' ?></span>
                            <?php if (!empty($block['sections'])): ?>
                                <span class="ml-block-meta-sep" aria-hidden="true">·</span>
                                <span class="ml-block-sections">
                                    <?php foreach ($block['section_counts'] as $code => $n): ?>
                                        <button type="button" class="ml-section-chip<?= $n > (int) $sectionCap ? ' ml-section-chip-over' : '' ?>"
                                                data-section="<?= htmlspecialchars((string) $code) ?>"
                                                data-course="<?= htmlspecialchars($block['course']) ?>"
                                                data-year="<?= htmlspecialchars($block['year_level']) ?>"
                                                data-semester="<?= htmlspecialchars($block['semester']) ?>"
                                                data-school-year="<?= htmlspecialchars($block['school_year']) ?>"
                                                title="Section <?= htmlspecialchars((string) $code) ?> — <?= (int) $n ?> student(s). Click to edit this section.">
                                            <?= htmlspecialchars((string) $code) ?><span class="ml-section-chip-n"><?= (int) $n ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </span>
                            <?php elseif ($unassignedCount > 0): ?>
                                <span class="ml-block-meta-sep" aria-hidden="true">·</span>
                                <span class="ml-block-unassigned">No sections yet</span>
                            <?php endif; ?>
                        </div>
                        </div>
                    </div>
                    <?php foreach ($block['tables'] as $tbl): ?>
                    <!-- The scroll container. `overflow: hidden` was written after
                         `overflow-x: auto` to square off the corners under the
                         block's border radius, and it silently won - the
                         shorthand resets overflow-x back to hidden, so on a
                         narrow screen the table was clipped with no way to
                         reach the last columns. `clip-path` rounds the corners
                         without touching overflow, so the axis keeps working. -->
                    <div class="ml-table-scroll">
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
                        <!-- Column widths are declared once, here, rather than left
                             to the browser's auto algorithm. With eight columns
                             of mixed content, auto sizing gave the name whatever
                             was left over - which is how "Dela Cruz, Juan Pedro"
                             ended up broken mid-word in a 200px cell. Fixed
                             tracks for the tokens, one flexible track for the
                             name: the name takes the slack, and every other
                             column keeps the same width on every row of every
                             table, so the eye can run straight down a column.

                             word-wrap/word-break: break-word is deliberately NOT
                             set on this table. It was what forced the mid-word
                             breaks; the name cell truncates with an ellipsis
                             instead, and carries the full name in its title. -->
                        <table class="masterlist-table">
                            <colgroup>
                                <col class="c-pick">
                                <col class="c-no">
                                <col class="c-num">
                                <col class="c-name">
                                <col class="c-gender">
                                <col class="c-contact">
                                <col class="c-course">
                                <col class="c-section">
                                <col class="c-bday">
                                <col class="c-status">
                                <col class="c-email">
                                <col class="c-rfid">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th style="text-align:center;"><input type="checkbox" class="block-select-all" style="width:15px;height:15px;accent-color:#2563eb;" title="Select all"></th>
                                    <th>#</th>
                                    <th data-field="student_number" data-sort="student_number"><i class="fas fa-sort"></i>Student ID</th>
                                    <th data-field="name" data-sort="name"><i class="fas fa-sort"></i>Name</th>
                                    <th data-field="gender">Gender</th>
                                    <th data-field="contact" class="ml-col-start">Contact</th>
                                    <th data-field="course" data-sort="course"><i class="fas fa-sort"></i>Course</th>
                                    <th data-field="section" data-sort="section"><i class="fas fa-sort"></i>Section</th>
                                    <th data-field="birthdate">Birthdate</th>
                                    <th data-field="status">Status</th>
                                    <th data-field="email" class="ml-col-start">Email</th>
                                    <th data-field="rfid">RFID</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php // Numbering restarts at 1 in every table, because
                                     // each table is its own list. Carrying 51-91
                                     // into "Table 2 of 2" would imply the two tables
                                     // are fragments of one numbered sheet, when
                                     // they are two separate lists. ?>
                                <?php $i = 1; foreach ($tbl['rows'] as $student):
                                    // The block heading already spells the program out in
                                    // full, so the column carries the acronym. Computed
                                    // per row rather than per block because two blocks
                                    // can share a heading only by accident, and a
                                    // student whose stored course is blank still has to
                                    // print N/A rather than inherit its neighbour's.
                                    $acronym = courseAcronym((string) ($student['course'] ?? ''));

                                    // Section. Blank means "nobody has
                                    // placed this student yet", which is a
                                    // real state and not a rendering fault,
                                    // so it says so rather than showing an
                                    // empty cell. Monospaced: it is a code,
                                    // and a proportional face makes 11001
                                    // and 11011 hard to tell apart at a glance.
                                    $section = trim((string) ($student['section'] ?? ''));

                                    // Status is a vocabulary, not free text, and 42 of
                                    // the seeded rows carry an EMPTY string rather
                                    // than null - so `?? 'Active'` never fired and the
                                    // cell rendered a blank grey pill. ucfirst('') is
                                    // '', so the badge said nothing at all. An empty
                                    // status means "not recorded", which is a real
                                    // state on this list and gets its own word; it
                                    // must never be confused with enrolled.
                                    $statusRaw = trim((string) ($student['status'] ?? ''));
                                    if ($statusRaw === '') {
                                        $statusLabel = 'Not recorded';
                                        $statusTone  = 'unknown';
                                    } elseif (in_array($statusRaw, ['active', 'enrolled'], true)) {
                                        $statusLabel = ucfirst($statusRaw);
                                        $statusTone  = 'good';
                                    } elseif (in_array($statusRaw, ['at-risk', 'probation'], true)) {
                                        $statusLabel = ucfirst($statusRaw);
                                        $statusTone  = 'watch';
                                    } else {
                                        $statusLabel = ucfirst($statusRaw);
                                        $statusTone  = 'plain';
                                    }

                                    // Gender. An empty value is "not recorded",
                                    // not "unknown" and not a guess - the column
                                    // says what is on file.
                                    $gender = trim((string) ($student['gender'] ?? ''));

                                    // RFID. Most students on a roster have no card
                                    // yet, and a blank cell there would read as a
                                    // rendering fault. "Not issued" is the fact.
                                    $card   = $rfidMap[(int) $student['id']] ?? null;
                                    $cardUid   = trim((string) ($card['card_uid'] ?? ''));
                                    $cardState = trim((string) ($card['status']  ?? ''));
                                    if ($cardUid === '') {
                                        $cardLabel = 'Not issued';
                                        $cardTone  = 'none';
                                    } else {
                                        $cardLabel = $cardUid;
                                        // Only a card in trouble is toned. An
                                        // active card is plain type: the column
                                        // is mostly live cards, and colouring the
                                        // healthy ones would make the exceptions
                                        // invisible.
                                        $cardTone = in_array($cardState, ['lost', 'expired', 'archived'], true) ? $cardState : 'ok';
                                    }
                                    // Contact. The block heading says which year
                                    // and term these students belong to, so Year
                                    // and S.Y. were saying it a second time, once
                                    // per row. The phone is what the clerk
                                    // actually needs off this list - it is how a
                                    // student gets called about the section they
                                    // were placed in - and it is one of the fields
                                    // the quality score is docking points for.
                                    $phone = studentQualityNormalizePhone((string) ($student['contact_number'] ?? ''));

                                    // Email. Replaces the LRN column. LRN is the
                                    // DepEd identifier that travels with a student
                                    // between schools, and it is the better field
                                    // in principle - but every current student has
                                    // students.lrn NULL, so the column would have
                                    // read N/A down the entire list. Email is
                                    // populated on the records that exist, and it
                                    // is what an office actually sends to: a
                                    // section notice, a schedule change, a
                                    // documents-request update. A column that is
                                    // empty for everyone teaches the reader
                                    // nothing; this one answers "can we reach them
                                    // in writing".
                                    $email = trim((string) ($student['email'] ?? ''));

                                    // Birthdate. The heaviest single field in the
                                    // data-quality weighting, and the one that
                                    // settles whether a Year level is plausible -
                                    // a 40-year-old listed as 1st year is an
                                    // enrolment error, and this is where it shows.
                                    $bdayRaw = trim((string) ($student['birth_date'] ?? ''));
                                    $bday    = '';
                                    if ($bdayRaw !== '' && $bdayRaw !== '0000-00-00') {
                                        $ts = strtotime($bdayRaw);
                                        // Format as d M Y so the column is
                                        // sortable-ish by eye and does not read
                                        // as a second date field the page
                                        // invented; YYYY-MM-DD is unambiguous
                                        // but twice as wide for no gain here.
                                        $bday = $ts !== false ? date('d M Y', $ts) : '';
                                    }
                                ?>
                                    <tr data-student-id="<?= (int)$student['id'] ?>">
                                        <td style="text-align:center;"><input type="checkbox" class="student-cb" value="<?= (int)$student['id'] ?>" style="width:15px;height:15px;accent-color:#2563eb;"></td>
                                        <td data-field="rowno" class="ml-rowno"><?= $i++ ?></td>
                                        <td data-field="student_number" class="ml-tok" title="<?= htmlspecialchars($student['student_number']) ?>"><?= htmlspecialchars($student['student_number']) ?></td>
                                        <td data-field="name"><a class="ml-name" href="javascript:void(0)" onclick="viewStudent(<?= (int)$student['id'] ?>)" title="<?= htmlspecialchars(trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''))) ?>"><span class="sur"><?= htmlspecialchars(trim($student['last_name'] ?? '')) ?></span> <span class="giv"><?= htmlspecialchars(trim(($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''))) ?></span></a></td>
                                        <td data-field="gender" class="ml-gender"><?= htmlspecialchars($gender !== '' ? $gender : 'N/A') ?></td>
                                        <td data-field="contact" class="ml-tok ml-col-start" title="<?= htmlspecialchars($phone !== '' ? $phone : 'No contact number on file') ?>"><?= htmlspecialchars($phone !== '' ? $phone : 'N/A') ?></td>
                                        <td data-field="course" title="<?= htmlspecialchars($student['course'] ?? 'No program recorded') ?>"><span class="ml-course"><?= htmlspecialchars($acronym !== '' ? $acronym : 'N/A') ?></span></td>
                                        <td data-field="section" data-sort="section" class="ml-tok" title="<?= htmlspecialchars($section !== '' ? 'Section ' . $section : 'Not yet assigned to a section') ?>"><span class="ml-section<?= $section === '' ? ' ml-section-none' : '' ?>"><?= htmlspecialchars($section !== '' ? $section : 'Unassigned') ?></span></td>
                                        <td data-field="birthdate" class="ml-tok" title="<?= htmlspecialchars($bdayRaw !== '' && $bdayRaw !== '0000-00-00' ? 'Born ' . $bdayRaw : 'No birth date on file') ?>"><?= htmlspecialchars($bday !== '' ? $bday : 'N/A') ?></td>
                                        <td data-field="status"><span class="ml-status ml-status-<?= $statusTone ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                                        <td data-field="email" class="ml-email ml-col-start" title="<?= htmlspecialchars($email !== '' ? $email : 'No email on file') ?>"><?= htmlspecialchars($email !== '' ? $email : 'N/A') ?></td>
                                        <td data-field="rfid" class="ml-tok" title="<?= htmlspecialchars($cardUid !== '' ? 'Card ' . $cardUid . ' — ' . ucfirst($cardState) : 'No card issued to this student') ?>"><span class="ml-card ml-card-<?= $cardTone ?>"><?= htmlspecialchars($cardLabel) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
    </div>

    <?php
    // The footer's counts ($totalBlocks, $tableCount, $sectionCap) all
    // describe the printable List. On a folder page it would quote block
    // and table totals for a view that has no blocks, so it is scoped to
    // the same view as the list itself.
    if ($view === 'list' && !empty($students)): ?>
    <div class="card" style="margin-top: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div class="table-footer">
            <div class="info-text">
                Total: <strong><?= $totalStudents ?></strong> student(s) in <strong><?= $totalBlocks ?></strong> program-year block(s), listed in <strong><?= $tableCount ?></strong> list(s) of up to <?= (int) $sectionCap ?>
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
                            <?php foreach ($courses as $row):
                                // The dropdown prints the acronym; the option
                                // VALUE stays the full course string,
                                // because that is what the filter compares
                                // against the `course` column. Abbreviating
                                // the value would silently match nothing.
                                $cFull  = (string) $row['course'];
                                $cTitle = courseDisplayTitle($cFull);
                                ?>
                                <option value="<?= htmlspecialchars($row['course']) ?>"
                                        <?= $cTitle !== '' ? 'title="' . htmlspecialchars($cTitle) . '"' : '' ?>
                                        <?= $filterCourse === $row['course'] ? 'selected' : '' ?>><?= htmlspecialchars(courseDisplay($cFull)) ?></option>
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
                    <div class="form-group"><label>Section</label>
                        <select name="section" id="filterSection" class="form-control">
                            <option value="">All sections</option>
                            <?php foreach ($sectionOptions as $row): ?>
                                <option value="<?= htmlspecialchars($row['section']) ?>" <?= $filterSection === $row['section'] ? 'selected' : '' ?>><?= htmlspecialchars($row['section']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeFilterSearchModal()">Cancel</button>
            <?php if ($anyFilterActive): ?>
                <a class="btn btn-light" href="masterlist.php">Clear</a>
            <?php endif; ?>
            <button type="submit" form="filterSearchForm" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
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

<!-- Edit Section Modal -->
<div class="modal-overlay" id="editSectionModal">
    <div class="modal-content" style="max-width: 560px;">
        <div class="modal-header"><h2 style="font-size:18px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:10px;"><i class="fas fa-pen" style="color:#b45309;"></i> Edit Section</h2><button class="modal-close" onclick="closeEditSection()"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <p style="font-size:13px;color:#64748b;margin-bottom:14px;">Changes to section <strong id="esOldSection" style="font-family:'JetBrains Mono',ui-monospace,Menlo,monospace;">—</strong> apply to <strong id="esStudentCount">0</strong> student(s) at once.</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group"><label>Section Code</label><input type="text" id="esSection" class="form-control" placeholder="11001" style="font-family:'JetBrains Mono',ui-monospace,Menlo,monospace;"></div>
                <div class="form-group"><label>School Year</label><input type="text" id="esSchoolYear" class="form-control" placeholder="2026-2027" list="csSyOptions"></div>
                <div class="form-group"><label>Course</label><select id="esCourse" class="form-control"><option value="">Select course</option><?php
                            // Printed as the acronym, with the full name in
                            // the option title. The VALUE stays the full
                            // course string because esCourse is submitted
                            // and written back to the `course` column -
                            // abbreviating it there would save a program
                            // name no query would ever match again.
                            foreach (array_keys($offeredCourses) as $cname): ?>
                            <option value="<?= htmlspecialchars($cname) ?>"
                                    <?= courseDisplayTitle($cname) !== '' ? 'title="' . htmlspecialchars(courseDisplayTitle($cname)) . '"' : '' ?>><?= htmlspecialchars(courseDisplay($cname)) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Year Level</label><select id="esYear" class="form-control"><option value="">Select</option><?php foreach ($years as $row): ?><option value="<?= (int)$row['year_level'] ?>">Year <?= (int)$row['year_level'] ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Semester</label><select id="esSemester" class="form-control"><option value="">Select</option><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="summer">Summer</option></select></div>
                <div class="form-group"><label>Adviser</label><select id="esAdviser" class="form-control"><option value="">Not set</option><?php foreach ($advisers as $ad): ?><option value="<?= (int)$ad['id'] ?>"><?= htmlspecialchars($ad['full_name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <p style="font-size:12px;color:#94a3b8;margin-top:8px;"><i class="fas fa-info-circle" style="margin-right:4px;"></i> The code's first two digits encode the year and the term. Changing them moves the whole section to a different block.</p>
            <p id="esError" style="color:#dc2626;font-size:13px;margin-top:8px;display:none;"></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeEditSection()">Cancel</button>
            <button class="btn btn-primary" id="esSaveBtn" onclick="saveEditSection()"><i class="fas fa-save"></i> Save Changes</button>
            <button class="btn btn-secondary" onclick="manageSectionStudents()"><i class="fas fa-users"></i> Manage Students</button>
        </div>
    </div>
</div>

<!-- Section Workspace Modal: pick students for one section -->
<div class="modal-overlay" id="sectionWorkspaceModal">
    <div class="modal-content" style="max-width: 720px;">
        <div class="modal-header"><h2 style="font-size:18px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:10px;"><i class="fas fa-users" style="color:#2563eb;"></i> <span id="wsTitle">Section</span></h2><button class="modal-close" onclick="closeSectionWorkspace()"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">
                <div style="flex:1;min-width:200px;position:relative;"><i class="fas fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px;"></i><input type="text" id="wsAssignSearch" class="form-control" style="padding-left:34px;" placeholder="Search by name or student no.…"></div>
                <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#475569;cursor:pointer;"><input type="checkbox" id="wsIncludeOthers" style="width:15px;height:15px;accent-color:#2563eb;"> Include already-assigned</label>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px;">
                <div style="flex:1;min-width:200px;"><label style="display:block;font-size:11px;color:#64748b;margin-bottom:3px;font-weight:600;">Assign to</label><input type="text" id="wsTargetSection" class="form-control" readonly></div>
                <button class="btn btn-primary" id="wsAssignBtn" onclick="assignSelectedToSection()"><i class="fas fa-user-check"></i> Assign Selected</button>
            </div>
            <p style="font-size:12px;color:#94a3b8;margin-bottom:8px;"><i class="fas fa-info-circle" style="margin-right:4px;"></i><span id="wsCount">0</span> student(s) listed. Students with no year level are not listed — a section code is built from the year level, so they cannot hold one.</p>
            <div id="wsAssignList" style="max-height:320px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:10px;"></div>
        </div>
    </div>
</div>

<script>
const ASSIGNABLE_STUDENTS = <?= json_encode($assignableStudents) ?>;
const RFID_MAP = <?= json_encode(array_map(fn($c) => ['card_uid' => $c['card_uid'], 'status' => $c['status'], 'expiry_date' => $c['expiry_date']], $rfidMap)) ?>;
const ADVISER_NAMES = <?= json_encode($adviserNames) ?>;
const SECTION_SUMMARIES = <?= json_encode(array_map(fn($s) => [
    'course'    => (string) $s['course'],
    'year'      => (string) ($s['year_level'] ?? ''),
    'semester'  => (string) ($s['semester'] ?? ''),
    'section'   => (string) $s['section'],
    'count'     => (int) $s['count'],
], $sectionSummaries)) ?>;

// ─── DUPLICATE SECTION CODE ───────────────────────────────────
// A code is only unique within one program + year + term, which is
// exactly the scope api/masterlist.php enforces on write. Checking
// here means the registrar is told while typing, not after Save has
// already been pressed and the round trip refused the change.
//
// A duplicate is a WARNING, not a block. Renaming a section onto a
// code that already exists is a legitimate move - it merges the two
// blocks - and only the server can say how big that merge is. So this
// names the section that already holds the code and lets the person
// decide, and Save still goes through to the server for the real
// answer.
//
// The same conflict must not shout twice. Every keystroke re-runs this,
// so a held-down key or a backspace-and-retype would otherwise stack
// up toasts and fight the inline message. One toast per distinct
// conflict, and a new one only when the conflict itself changes.
let warnedDuplicateKey = null;
let lastDuplicateHit = null;

// The conflict currently shown inline, so the inline line can be
// cleared when the code stops being a duplicate.
function findSectionCollision() {
    if (!editSectionContext) return null;
    const code = document.getElementById('esSection').value.trim();
    if (!/^[0-9]{5}$/.test(code)) return null;

    const ctx = editSectionContext;
    const course = document.getElementById('esCourse').value;
    const year = document.getElementById('esYear').value;
    const semester = document.getElementById('esSemester').value;

    // The section being edited cannot collide with itself, and neither
    // can a row that is only unchanged because nothing was edited.
    const unchanged = code === ctx.section
        && course === (ctx.course || '')
        && year === String(ctx.year_level || '')
        && semester === (ctx.semester || '');
    if (unchanged) return null;

    const hit = SECTION_SUMMARIES.find(function (s) {
        return s.course === course
            && s.year === String(year)
            && s.semester === semester
            && s.section === code;
    });
    if (!hit) return null;

    return {
        key: course + '|' + year + '|' + semester + '|' + code,
        code: code,
        course: course,
        year: year,
        semester: semester,
        count: hit.count
    };
}

function warnDuplicateSection(hit) {
    const errEl = document.getElementById('esError');
    const termLabel = hit.semester === 'summer' ? 'Summer'
        : (hit.semester === '2nd' ? '2nd Semester' : '1st Semester');

    errEl.textContent = 'Section ' + hit.code + ' is already in use for '
        + hit.course + ' / Year ' + hit.year + ' (' + termLabel + ') - '
        + hit.count + ' student(s) already hold it. Saving will move '
        + 'this section onto that one and merge them.';
    errEl.style.display = 'block';

    // Toast once per distinct conflict, not once per keystroke. The
    // key includes the code and the block, so correcting one and
    // colliding with a different section warns again - which is the
    // point - while retyping the same colliding code stays quiet.
    if (warnedDuplicateKey === hit.key) return;
    warnedDuplicateKey = hit.key;
    showToast('Section ' + hit.code + ' is already in use.', 'warning');
}

function checkDuplicateSection() {
    const hit = findSectionCollision();
    // Clear the previous duplicate line before deciding, so correcting
    // the code removes the warning instead of leaving it up next to a
    // code that no longer conflicts. The format error in saveEditSection
    // owns this same element, but that is not reachable while typing.
    if (lastDuplicateHit) {
        const errEl = document.getElementById('esError');
        if (errEl.textContent.indexOf('already in use') !== -1) {
            errEl.textContent = '';
            errEl.style.display = 'none';
        }
    }
    lastDuplicateHit = hit;
    if (!hit) return;
    warnDuplicateSection(hit);
}

// Fires on every keystroke, so it must stay cheap and must not toast
// more than once per conflict - see warnDuplicateSection.
['esSection', 'esCourse', 'esYear', 'esSemester'].forEach(function (id) {
    document.getElementById(id)?.addEventListener('input', checkDuplicateSection);
    document.getElementById(id)?.addEventListener('change', checkDuplicateSection);
});

// ─── EDIT SECTION ──────────────────────────────────────────────
let editSectionContext = null;

// A section chip in a block heading. The block knows the course, year
// and term; the chip carries the code, so one delegated listener reads
// them off data attributes instead of a handler being re-bound on
// every server render.
document.addEventListener('click', function (e) {
    const chip = e.target.closest('.ml-section-chip');
    if (!chip) return;
    openEditSection({
        section: chip.dataset.section,
        course: chip.dataset.course,
        year_level: chip.dataset.year,
        semester: chip.dataset.semester,
        school_year: chip.dataset.schoolYear
    });
});

function openEditSection(ctx) {
    editSectionContext = ctx;
    document.getElementById('esOldSection').textContent = ctx.section;
    document.getElementById('esSection').value = ctx.section;
    document.getElementById('esSchoolYear').value = ctx.school_year || '';
    document.getElementById('esCourse').value = ctx.course || '';
    document.getElementById('esYear').value = ctx.year_level || '';
    document.getElementById('esSemester').value = ctx.semester || '';
    document.getElementById('esAdviser').value = '';

    // Counted from the full student list, not from whatever the page is
    // filtered to. This number is what the user is about to move, so a
    // section filter hiding 40 of its 50 members must not report "10".
    const n = ASSIGNABLE_STUDENTS.filter(function (s) {
        return (s.course || '') === (ctx.course || '')
            && String(s.year_level || '') === String(ctx.year_level || '')
            && (s.section || '') === (ctx.section || '');
    }).length;
    document.getElementById('esStudentCount').textContent = n;
    document.getElementById('esError').style.display = 'none';
    // Each modal session gets a fresh warning budget: reopening a
    // section should be able to warn about the same code again.
    warnedDuplicateKey = null;
    lastDuplicateHit = null;
    document.getElementById('editSectionModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeEditSection() {
    document.getElementById('editSectionModal').classList.remove('active');
    document.body.style.overflow = '';
    // editSectionContext is deliberately kept. "Manage Students" reads
    // it after this closes, and clearing it here broke that button.
}
document.getElementById('editSectionModal')?.addEventListener('click', function (e) {
    if (e.target === this) closeEditSection();
});
function manageSectionStudents() {
    const ctx = editSectionContext;
    if (!ctx) return;
    closeEditSection();
    openSectionWorkspace(ctx);
}

async function saveEditSection() {
    if (!editSectionContext) return;
    const ctx = editSectionContext;
    const newSection = document.getElementById('esSection').value.trim();
    const newCourse  = document.getElementById('esCourse').value;
    const newYear    = document.getElementById('esYear').value;
    const newSem     = document.getElementById('esSemester').value;
    const newSy      = document.getElementById('esSchoolYear').value.trim();
    const newAdviser = document.getElementById('esAdviser').value;
    const errEl = document.getElementById('esError');
    const count = document.getElementById('esStudentCount').textContent;

    if (!/^[0-9]{5}$/.test(newSection)) {
        errEl.textContent = 'A section code is 5 digits, e.g. 11001.';
        errEl.style.display = 'block';
        return;
    }
    if (!newCourse || !newYear || !newSem) {
        errEl.textContent = 'Course, year level and semester are all required.';
        errEl.style.display = 'block';
        return;
    }
    if (!await confirmAction({
        title: 'Update section',
        body: 'Move <strong>' + count + '</strong> student(s) from section <strong>' + escText(ctx.section)
            + '</strong> to <strong>' + escText(newSection) + '</strong>?',
        confirmLabel: 'Update section'
    })) return;

    const btn = document.getElementById('esSaveBtn');
    btn.disabled = true;
    try {
        const r = await fetch('../api/masterlist.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'edit_section',
                old_course: ctx.course, old_year_level: ctx.year_level,
                old_semester: ctx.semester, old_section: ctx.section,
                course: newCourse, year_level: newYear, semester: newSem,
                section: newSection, school_year: newSy,
                adviser_id: newAdviser || null
            })
        });
        const d = await r.json();
        if (d.success) {
            showToast(d.message || 'Section updated.', 'success');
            setTimeout(() => window.location.reload(), 600);
        } else {
            errEl.textContent = d.message || 'Failed to update section.';
            errEl.style.display = 'block';
            btn.disabled = false;
        }
    } catch (e) {
        showToast('Network error. Please try again.', 'error');
        btn.disabled = false;
    }
}

// ─── SECTION WORKSPACE ─────────────────────────────────────────
// Where a section actually gets its members. Reached from Create
// Section (a brand-new code) and from Edit Section → Manage Students
// (an existing one), so it has to work for a section that holds
// nobody yet and for one that is already full.
let wsContext = null;

function openSectionWorkspace(ctx) {
    wsContext = ctx;
    const semLabel = ctx.semester || '1st';
    document.getElementById('wsTitle').textContent =
        'Section ' + ctx.section + ' — ' + (ctx.course || 'No program') + ' · Year ' + (ctx.year_level || '?') + ' · ' + semLabel;
    document.getElementById('wsTargetSection').value =
        ctx.section + ' — ' + (ctx.course || 'No program') + ' · Y' + (ctx.year_level || '?') + ' · ' + semLabel;

    document.getElementById('wsAssignSearch').value = '';
    // A section created a moment ago holds nobody, so defaulting to
    // "everyone" would bury the list under every assigned student in
    // the program. Off means: show me who still needs placing.
    document.getElementById('wsIncludeOthers').checked = false;
    renderAssignList();
    document.getElementById('sectionWorkspaceModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeSectionWorkspace() {
    document.getElementById('sectionWorkspaceModal').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('sectionWorkspaceModal')?.addEventListener('click', function (e) {
    if (e.target === this) closeSectionWorkspace();
});
document.getElementById('wsAssignSearch')?.addEventListener('input', renderAssignList);
document.getElementById('wsIncludeOthers')?.addEventListener('change', renderAssignList);

function renderAssignList() {
    const q = (document.getElementById('wsAssignSearch').value || '').toLowerCase().trim();
    const includeAssigned = document.getElementById('wsIncludeOthers').checked;
    const listEl = document.getElementById('wsAssignList');

    let students = ASSIGNABLE_STUDENTS;
    if (!includeAssigned) {
        // Off: only students with no section at all. The point of the
        // default is to answer "who still needs placing", and a list of
        // the 400 who already have a code does not answer it.
        students = students.filter(function (s) { return !(s.section || '').trim(); });
    }
    // Narrowed to this section's own program and year while the list is
    // untouched, because those are the only students who can hold this
    // code. Searching, or asking for everyone, lifts the narrowing.
    if (!q && wsContext) {
        students = students.filter(function (s) {
            return (s.course || '') === (wsContext.course || '')
                && String(s.year_level || '') === String(wsContext.year_level || '');
        });
    }
    if (q) {
        students = students.filter(function (s) {
            return ((s.first_name || '') + ' ' + (s.last_name || '') + ' ' + (s.middle_name || '')
                + ' ' + (s.student_number || '') + ' ' + (s.section || '')).toLowerCase().indexOf(q) !== -1;
        });
    }

    document.getElementById('wsCount').textContent = students.length;

    if (!students.length) {
        listEl.innerHTML = '<p style="text-align:center;color:#94a3b8;padding:30px;margin:0;">'
            + 'No students to show. Tick <strong>Include already-assigned</strong> to see everyone in this program and year.</p>';
        return;
    }

    let html = '<table style="width:100%;font-size:12px;border-collapse:collapse;">'
        + '<tr style="background:#f8fafc;color:#64748b;font-weight:600;">'
        + '<th style="padding:8px;"></th><th style="padding:8px;text-align:left;">Student</th>'
        + '<th style="padding:8px;text-align:left;">Program</th><th style="padding:8px;text-align:left;">Yr</th>'
        + '<th style="padding:8px;text-align:left;">Section</th></tr>';
    students.forEach(function (s) {
        const inTarget = (s.section || '') === (wsContext ? wsContext.section : '');
        html += '<tr style="border-bottom:1px solid #f1f5f9;">'
            + '<td style="padding:6px;"><input type="checkbox" class="ws-assign-cb" value="' + s.id
            + '" style="width:15px;height:15px;accent-color:#2563eb;"></td>'
            + '<td style="padding:6px;"><strong>' + escText(trim(s.last_name || '') + ', ' + trim(s.first_name || ''))
            + '</strong><br><span style="color:#94a3b8;">' + escText(s.student_number || 'No student no.') + '</span></td>'
            + '<td style="padding:6px;">' + escText(s.course || '—') + '</td>'
            + '<td style="padding:6px;">' + escText(s.year_level || '—') + '</td>'
            + '<td style="padding:6px;">'
            + (inTarget
                ? '<span style="color:#15803d;font-weight:700;">' + escText(s.section) + '</span>'
                : escText(s.section || '—'))
            + '</td></tr>';
    });
    html += '</table>';
    listEl.innerHTML = html;
}

async function assignSelectedToSection() {
    if (!wsContext) { showToast('No target section.', 'warning'); return; }
    const ids = Array.from(document.querySelectorAll('.ws-assign-cb:checked')).map(function (cb) { return cb.value; });
    if (!ids.length) { showToast('Select at least one student.', 'warning'); return; }

    const section = wsContext.section;
    if (!await confirmAction({
        title: 'Assign to section',
        body: 'Move <strong>' + ids.length + '</strong> student' + (ids.length === 1 ? '' : 's')
            + ' to section <strong>' + escText(section) + '</strong>?'
            + '<br><br>Their program, year and term are set to match the section, so the list stays coherent.',
        confirmLabel: 'Assign'
    })) return;

    const btn = document.getElementById('wsAssignBtn');
    btn.disabled = true;
    try {
        const r = await fetch('../api/masterlist.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'bulk_assign_section',
                ids: ids,
                section: section,
                course: wsContext.course,
                year_level: wsContext.year_level,
                semester: wsContext.semester,
                school_year: wsContext.school_year || ''
            })
        });
        const d = await r.json();
        if (!d.success) { showToast(d.message || 'Failed to assign.', 'error'); return; }
        showToast(d.message || 'Assigned.', 'success');
        setTimeout(() => window.location.reload(), 600);
    } catch (e) {
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.disabled = false;
    }
}

// ─── PREPARE FULL LIST ───────────────────────────────────────
// Clears every filter so the whole roster is on screen, ready to hand off.
// The registrar does not write section codes — the receiving department does.
// The blocks are handed off whole; nothing here cuts them into lists.
document.getElementById('btnPrepareList')?.addEventListener('click', function () {
    window.location.href = 'masterlist.php?prepared=1';
});

// ─── SEARCH (client-side) ────────────────────────────────────
//
// Both tables are searched by one box, so the selector names whichever
// one this view rendered: the printable List's ledger (.masterlist-table)
// or the folder roster (.mlx-table). Scoping it to .masterlist-table
// alone - as it was - left the box on the Browse view doing nothing at
// all, silently, which is worse than not offering it.
const searchRows = document.querySelectorAll(
    '#masterlistContent .masterlist-table tbody tr, #masterlistContent .mlx-table tbody tr'
);
const searchInput = document.getElementById('masterlistSearch');
searchInput?.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    let visible = 0;
    searchRows.forEach(row => {
        // row.textContent reads the surname and given names with the markup
        // between them, so a search for "Cruz Juan" or "Cruz, Juan" would
        // silently find nobody. The whitespace is collapsed first, so the box
        // matches the name as it is written on the page.
        const parts = row.querySelectorAll('.ml-name > span');
        let text = row.textContent;
        if (parts.length) {
            const named = Array.from(parts).map(p => p.textContent.trim()).filter(Boolean).join(' ');
            text = text.replace(parts[0].parentElement.textContent, named);
        }
        text = text.replace(/\s+/g, ' ').toLowerCase();
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
    // The counter only exists where the toolbar renders one, and it is
    // optional-chained because a search must never throw on a view that
    // does not show a count.
    const counter = document.getElementById('showingCount');
    if (counter) counter.textContent = visible;
});

// ─── SORT (within the table) ─────────────────────────────────
document.querySelectorAll('#masterlistContent .masterlist-table th[data-sort]').forEach(th => {
    th.addEventListener('click', function () {
        const key = this.dataset.sort;
        const tbody = this.closest('table').querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const dir = this._dir === 'asc' ? 'desc' : 'asc';
        this._dir = dir;
        // aria-sort is what the new header CSS keys off to keep the arrow
        // visible on the sorted column, and it is the only thing that tells a
        // screen reader which way the list went. Without it the arrow showed on
        // hover and vanished the moment the pointer left - so a user who sorted
        // and looked away had no way to tell the list was no longer in its
        // original order.
        this.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
        // Only one column is sorted at a time, so the others give up the state.
        this.closest('table').querySelectorAll('th[data-sort]').forEach(other => {
            if (other !== this) other.removeAttribute('aria-sort');
        });
        const arrow = this.querySelector('i');
        if (arrow) arrow.className = 'fas ' + (dir === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
        rows.forEach(r => r._key = rowSortKey(r, key));
        rows.sort((a, b) => (a._key < b._key ? -1 : a._key > b._key ? 1 : 0) * (dir === 'asc' ? 1 : -1));
        rows.forEach(r => tbody.appendChild(r));
        // re-number
        tbody.querySelectorAll('tr').forEach((r, idx) => { const cells = r.querySelectorAll('td'); if (cells.length > 1) cells[1].textContent = idx + 1; });
    });
});
function rowSortKey(row, key) {
    // Addressed by data-field, not by cell position. The positional map this
    // replaced broke the moment a column was inserted, and it had no way to
    // notice: sorting by the wrong letter is invisible until someone reads a
    // roster in the wrong order. Same rule the CSV and print sheet follow.
    const c = row.querySelector('[data-field="' + key + '"]');
    const v = c ? c.textContent.trim() : '';
    if (key === 'year_level') return String(parseInt(v) || 0).padStart(3, '0');
    if (key === 'course') {
        // The cell shows the acronym, so this sorts programs by the label the
        // reader actually sees rather than by a hidden full name.
        return v.toLowerCase();
    }
    return v.toLowerCase();
}

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
    ['gender',         'Gender'],
    ['contact',        'Contact'],
    ['course',         'Course'],
    ['section',        'Section'],
    ['birthdate',      'Birthdate'],
    ['status',         'Status'],
    ['email',          'Email'],
    ['rfid',           'RFID'],
];

function exportFields() {
    // Only the FIRST table's header. The blocks are separate tables, so
    // querySelectorAll returned one set of <th> per block and the CSV came out
    // with the same columns repeated N times - 28 columns for 4 blocks, with
    // every row's value written four times over. A block is a display grouping,
    // not a set of extra fields, and the columns are identical across them by
    // construction.
    //
    // Scoped to a single table via querySelector, not :first-of-type: each
    // table sits alone inside its own wrapper div, so every one of them is a
    // first-of-type and that selector would match all of them, changing nothing.
    const first = document.querySelector('#masterlistContent .masterlist-table');
    if (!first) return FALLBACK_FIELDS;
    const ths = first.querySelectorAll('thead th[data-field]');
    if (!ths.length) return FALLBACK_FIELDS;

    const out = [];
    ths.forEach(th => {
        // A header may carry a <small> naming the owning department. That is
        // screen furniture explaining the N/A beneath it - it must not end up
        // glued into the CSV header as "Section CodeClass Scheduling", where
        // there is no column under it to explain. data-export is the override;
        // without one, the <small> subtree is stripped.
        const label = (th.dataset.export !== undefined
            ? th.dataset.export
            : (() => {
                const c = th.cloneNode(true);
                c.querySelectorAll('small').forEach(s => s.remove());
                return c.textContent;
            })()
        ).replace(/\s+/g, ' ').trim();
        if (label === '') return;           // a header with no words carries nothing
        out.push([th.dataset.field, label]);
    });
    return out.length ? out : FALLBACK_FIELDS;
}

function cellText(row, field) {
    const c = row.querySelector('[data-field="' + field + '"]');
    if (!c) return '';
    // The Name cell holds a .ml-name element with two stacked children
    // (surname over given names), so its textContent has no whitespace between
    // them and would export as "DELA CRUZJUAN PEDRO". The children are joined
    // with a space: in the file the name stays on one line, separated.
    const holder = c.querySelector('.ml-name') || c;
    // The name is already one run of text with a real space between the spans,
    // so the two parts are collapsed on any stray whitespace rather than joined
    // with another space - otherwise the CSV ships "AQUINO  Ana" and the search
    // for a two-part name misses.
    const blocks = holder.querySelectorAll(':scope > span');
    if (blocks.length) {
        return Array.from(blocks).map(b => b.textContent.trim()).filter(Boolean).join(' ');
    }
    return holder.textContent.replace(/\s+/g, ' ').trim();
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

    // \x3C is written instead of a literal "<" so the "?xml" and
    // "?mso-application" processing instructions can never be read as a
    // PHP short open tag on servers with short_open_tag = On.
    let xml = '\x3C?xml version="1.0"?>\n'
        + '\x3C?mso-application progid="Excel.Sheet"?>\n'
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
    w.document.write('p.scope { margin:4px 0 12px; font-size:9px; color:#475569; }');
    w.document.write('p.scope em { color:#64748b; }');
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
        // Names each printed table after its cohort. It reads the heading's
        // `title` first, because that holds the full program name - the screen
        // heading is deliberately compact ("BSIT Year 1 · 1st sem") and that
        // compact form is what used to print, leaving the full course name off
        // the sheet. title falls back to the h2 text so a heading without one
        // still prints something.
        const blockTitleOf = block => {
            const h = block && block.querySelector('.ml-block-head h2');
            if (!h) return '';
            const full = (h.getAttribute('title') || '').trim();
            return (full || h.textContent).replace(/\s+/g, ' ').trim();
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
            // narrower shape than the CSV: it is a signed document, so it
            // carries only what a receiving office signs off - who, which
            // program, what state - plus the two things a clerk works from
            // all day, contact and the two identity fields. Year and Semester are
            // absent because the sheet's own heading already names them, and
            // repeating them on every row of a signed document is noise.
            //
            // Year was dropped from here at the same time as the column itself.
            // It is addressed by data-field, so once the cell was gone from the
            // table cellText() returned an empty string and every printed row
            // carried a blank Year cell under a header that promised one - a
            // document that looks like it has a gap in it.
            //
            // The cell VALUES still come from data-field, so this header
            // cannot drift out of step with the table it prints.
            // Section sits next to Course because that is how the sheet is
            // filed - a reader looks for the program and the block together.
            // The export reads this table's header, so a column added here
            // without a matching cell would print an empty column under a
            // heading that promises a value.
            w.document.write('<table><tr><th>#</th><th>Student No.</th><th>Name</th><th>Gender</th><th>Contact</th><th>Course</th><th>Section</th><th>Birthdate</th><th>Status</th><th>Email</th></tr>');
            // Numbering restarts per printed table, matching the screen: each
            // table is its own list, not a page of a longer numbered run.
            let seq = 0;
            groupRows.forEach(row => {
                // By field, for the same reason the CSV does. t(2), t(3)...
                // printed the wrong column as soon as one was inserted.
                if (!row.querySelector('[data-field="student_number"]')) return;
                w.document.write('<tr><td>' + (++seq) + '</td><td>' + cellText(row, 'student_number')
                    + '</td><td>' + cellText(row, 'name')
                    + '</td><td>' + cellText(row, 'gender')
                    + '</td><td>' + cellText(row, 'contact')
                    + '</td><td>' + cellText(row, 'course')
                    + '</td><td>' + cellText(row, 'section')
                    + '</td><td>' + cellText(row, 'birthdate')
                    + '</td><td>' + cellText(row, 'status')
                    + '</td><td>' + cellText(row, 'email') + '</td></tr>');
            });
            w.document.write('</table>');
            // Still no scope note. It was rejected for repeating itself on
            // every block heading, and the printed sheet inherits that: a
            // paragraph of policy on a signed document helps nobody. The
            // Section column speaks for itself, and Adviser is not printed
            // at all because this office does not record it.
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
        // Year and Section are one field here, so the em dash between
        // them has to be conditional too. Unconditionally it rendered
        // "1 Year — " for a student with no section yet: a trailing
        // dash reading as a value that failed to arrive.
        document.getElementById('vYearSection').textContent = (s.year_level ? s.year_level + ' Year' : 'Year not set') + (s.section ? ' — Section ' + s.section : ' — No section');
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
    if (e.key === 'Escape') { closeViewModal(); closeFilterSearchModal(); closeEditSection(); closeSectionWorkspace(); }
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
// ─── FOLDER TABLE: expand / collapse ─────────────────────────
//
// A row's visibility is decided entirely by whether the folder named
// in its data-parent is open, so showing, hiding, expanding all and
// collapsing all are one operation rather than four. The server has
// already rendered which folders start open (the focus and its
// ancestors, or every program); this only changes it afterwards.
(function () {
    var table = document.getElementById('mlfTable');
    if (!table) return;

    var rows = Array.prototype.slice.call(table.querySelectorAll('tr.mlf-row'));
    var open = {};
    rows.forEach(function (r) {
        if (r.dataset.open === '1') open[r.dataset.path] = true;
    });

    function render() {
        rows.forEach(function (r) {
            var parent = r.dataset.parent || '';
            var level = r.classList.contains('mlf-row-folder') && !r.querySelector('.mlf-caret-none');
            // A program row has no parent, so it is always visible.
            var show = parent === '' || !!open[parent];
            r.style.display = show ? '' : 'none';

            var caret = r.querySelector('.mlf-caret');
            if (caret && !caret.classList.contains('mlf-caret-none')) {
                var isOpen = !!open[r.dataset.path];
                caret.classList.toggle('is-open', isOpen);
                caret.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                caret.title = (isOpen ? 'Collapse' : 'Expand') + ' this folder';
            }
        });
    }

    // One folder's descendants, however deep. Walks parents upward so
    // a program's children are found in one pass rather than needing a
    // separate rule per level.
    function setFolder(path, isOpen) {
        if (isOpen) { open[path] = true; } else { delete open[path]; }
        rows.forEach(function (r) {
            if (isDescendantOf(r.dataset.parent, path)) {
                r.style.display = isOpen ? '' : 'none';
                if (isOpen && r.classList.contains('mlf-row-student')) {
                    r.style.display = '';
                }
            }
        });
        // Re-render from the open map rather than from the toggles just
        // set, so a descendant that was itself closed stays closed.
        render();
    }

    function isDescendantOf(candidateParent, ancestor) {
        if (!candidateParent) return false;
        return candidateParent === ancestor || candidateParent.indexOf(ancestor + '/') === 0;
    }

    table.addEventListener('click', function (e) {
        var caret = e.target.closest('.mlf-caret');
        if (!caret || caret.classList.contains('mlf-caret-none')) return;
        e.preventDefault();
        var path = caret.dataset.caret;
        setFolder(path, !open[path]);
    });

    var expandAll = document.getElementById('mlfExpandAll');
    if (expandAll) {
        expandAll.addEventListener('click', function () {
            rows.forEach(function (r) {
                if (r.classList.contains('mlf-row-folder')) open[r.dataset.path] = true;
            });
            render();
        });
    }

    var collapseAll = document.getElementById('mlfCollapseAll');
    if (collapseAll) {
        collapseAll.addEventListener('click', function () {
            // Keep the programs open: collapsing everything would
            // leave a table with nothing in it and no obvious way
            // back, which is the one state a browser cannot get out
            // of without reloading.
            rows.forEach(function (r) {
                if (r.classList.contains('mlf-row-folder') && !r.querySelector('.mlf-caret-none')
                    && r.dataset.parent === '') {
                    open[r.dataset.path] = true;
                } else {
                    delete open[r.dataset.path];
                }
            });
            render();
        });
    }

    render();
})();</script>

<style>
/* The List / Folders / Browse toggle is GONE from this page, and so is
   its CSS (.masterlist-views, .mlv-btn). The header no longer offers a
   choice of view.

   These rules used to be duplicated from masterlist-folders.php so
   that "the same toggle" would look identical on each view. That file
   never grew its own copy of the markup - it kept its own styling and
   the two had already drifted - so the "shared" rules were only ever
   read here, by markup that no longer exists. They are deleted rather
   than left behind, because dead CSS for a removed control is exactly
   the kind of thing that gets "reused" the next time someone adds a
   button and inherits rules written for a toggle that is not there. */

/* The folder table: one table in which a folder is a row. The
   indentation is the only thing carrying the nesting, and it is set
   inline per level because there are exactly three of them. */
.mlf-table-card { padding:0; overflow:hidden; }
.mlf-table-toolbar { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; padding:12px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
.mlf-table-actions { display:flex; gap:8px; flex-wrap:wrap; }
.mlf-table-count { font-size:13px; color:#64748b; }
.mlf-table-count strong { color:#0f172a; }

.mlf-table { width:100%; border-collapse:collapse; font-size:13px; }
.mlf-table thead th { background:#fbfcfe; color:#475569; font-size:11px; text-transform:uppercase; letter-spacing:.05em; text-align:left; padding:9px 14px; border-bottom:1px solid #e2e8f0; font-weight:700; white-space:nowrap; }
.mlf-table td { padding:7px 14px; border-bottom:1px solid #f1f5f9; color:#334155; vertical-align:middle; }
.mlf-table tbody tr:last-child td { border-bottom:none; }
.mlf-c-num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
.mlf-c-tok { font-variant-numeric:tabular-nums; white-space:nowrap; }

/* A folder row is heavier than a student row, so the shape of the
   tree is legible before you read any of it. */
.mlf-row-folder { background:#fff; }
.mlf-row-folder:hover { background:#f8fafc; }
.mlf-row-folder td { font-weight:600; color:#1e293b; }
.mlf-row-student:hover { background:#f8fbff; }

.mlf-caret { width:18px; height:18px; margin-right:6px; border:0; background:none; color:#64748b; cursor:pointer; padding:0; font-size:11px; transition:transform .12s; }
.mlf-caret.is-open { transform:rotate(90deg); color:#1a3a8c; }
.mlf-caret:hover { color:#2563eb; }
.mlf-caret-none { display:inline-block; }

.mlf-row-icon { margin-right:8px; color:#2563eb; }
.mlf-row-icon.is-unassigned { color:#d97706; }
.mlf-row-folder .mlf-row-name { font-weight:700; }
.mlf-tag { font-size:10.5px; font-weight:700; color:#92400e; background:#fef3c7; border-radius:99px; padding:2px 8px; margin-left:8px; }

.mlf-type { font-size:10.5px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#64748b; background:#f1f5f9; border-radius:5px; padding:2px 7px; white-space:nowrap; }
.mlf-type-0 { color:#1e3a8a; background:#dbeafe; }
.mlf-type-1 { color:#065f46; background:#d1fae5; }
.mlf-type-2 { color:#7c2d12; background:#ffedd5; }
.mlf-type-student { color:#475569; background:transparent; }

.mlf-dl { color:#64748b; text-decoration:none; font-size:13px; padding:4px 7px; border-radius:6px; }
.mlf-dl:hover { background:#eff6ff; color:#2563eb; }
.mlf-table-note { font-size:12.5px; color:#64748b; margin:0; padding:12px 16px; background:#fbfcfe; border-top:1px solid #eef2f7; line-height:1.6; }

/* ─── THE FOLDER BROWSER (view=explore) ────────────────────────
   A different shape from the folder TABLE on purpose: that one is a
   spreadsheet you read top to bottom, this one is a drive you move
   through. So the chrome here is a path bar and a grid of tiles,
   not rows and carets. */
.mlx-browser { padding:0; overflow:hidden; }

.mlx-crumbs {
    display:flex; align-items:center; gap:6px; flex-wrap:wrap;
    padding:11px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0;
    font-size:13px;
}
.mlx-crumb { color:#475569; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:6px; padding:3px 7px; border-radius:7px; }
.mlx-crumb:hover { background:#e0ecff; color:#1d4ed8; }
.mlx-crumb-root { color:#1a3a8c; }
.mlx-crumb-sep { color:#cbd5e1; font-size:10px; }
/* Where you are, as opposed to where you can go. Not a link, so it
   is visibly not clickable rather than silently doing nothing. */
.mlx-crumb-here { color:#0f172a; font-weight:700; background:#e2e8f0; cursor:default; }
.mlx-crumb-here:hover { background:#e2e8f0; color:#0f172a; }

.mlx-head { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; padding:18px 20px 14px; }
.mlx-title { display:flex; align-items:center; gap:10px; font-size:19px; font-weight:700; color:#0f172a; }
.mlx-title i { color:#2563eb; font-size:17px; }
.mlx-sub { margin-top:5px; font-size:12.5px; color:#64748b; }
.mlx-actions { display:flex; gap:8px; flex-wrap:wrap; }

/* The grid. auto-fill with a minmax floor rather than a fixed
   column count, so the tiles reflow on a narrow screen instead of
   squeezing every one of them. */
.mlx-folders { display:grid; grid-template-columns:repeat(auto-fill, minmax(248px, 1fr)); gap:12px; padding:6px 20px 20px; }
.mlx-tile {
    display:flex; align-items:center; gap:12px; text-decoration:none;
    padding:14px 15px; border:1px solid #e2e8f0; border-radius:12px;
    background:#fff; transition:border-color .12s, background .12s, box-shadow .12s;
}
.mlx-tile:hover { border-color:#93c5fd; background:#f8fbff; box-shadow:0 2px 8px rgba(30,64,175,.08); }
.mlx-tile-icon { font-size:26px; color:#f0b429; flex:none; }
.mlx-tile.is-unassigned .mlx-tile-icon { color:#d97706; }
.mlx-tile-body { display:flex; flex-direction:column; gap:4px; min-width:0; flex:1; }
.mlx-tile-name { font-size:14.5px; font-weight:700; color:#1e293b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.mlx-tile-meta { font-size:12px; color:#64748b; }
.mlx-tile-go { color:#cbd5e1; font-size:12px; flex:none; }
.mlx-tile:hover .mlx-tile-go { color:#2563eb; }

.mlx-missing { padding:34px 20px; text-align:center; color:#64748b; }
.mlx-missing i { font-size:30px; color:#cbd5e1; display:block; margin-bottom:12px; }
.mlx-missing p { margin:0 0 5px; font-size:13.5px; }

/* The roster inside a section folder.
   This is its own table (.mlx-table), NOT the printable List's
   ledger (.masterlist-table). That is deliberate. The ledger is
   table-layout:fixed with hand-measured per-column tracks and a
   1390px floor, sized for the List's own eleven columns; a
   fourteen-column roster that borrowed the class set none of those
   tracks, so every column fell to an equal share and the floor
   forced a sideways scroll for no reason.

   It also avoids inheriting the List's search, sort and CSV-export
   handlers, which key off .masterlist-table and would otherwise
   read this roster through the List's column map and write the
   wrong headers over it.

   These tracks are chosen by what each column CONTAINS rather than
   by a fixed layout: the browser sizes them, so a table that fits
   the window uses the space instead of scrolling, and the ones
   that must not wrap (codes, dates, numbers) are pinned. */
.mlx-roster-scroll {
    margin:0 20px 20px;
    border:1px solid #e2e8f0;
    border-radius:12px;
    overflow:auto;
    max-height:min(70vh, 780px);
}
.mlx-table {
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    font-size:13.5px;
    color:#1e293b;
}
/* The heading band repeats on scroll (position:sticky), so the
   columns stay identifiable however long the roster is - which is
   the whole reason a 300-student section needs a scroll box. */
.mlx-table thead th {
    position:sticky; top:0; z-index:2;
    background:#f1f5f9;
    color:#334155;
    font-size:11.5px; font-weight:700;
    letter-spacing:.04em; text-transform:uppercase;
    text-align:left; white-space:nowrap;
    padding:11px 13px;
    border-bottom:2px solid #cbd5e1;
}
.mlx-table td {
    padding:10px 13px;
    border-bottom:1px solid #eef2f6;
    vertical-align:middle;
    background:#fff;
}
/* Banding, and a hover that actually reads on a white row. */
.mlx-table tbody tr:nth-child(even) td { background:#fafbfd; }
.mlx-table tbody tr:hover td { background:#eff6ff; }
.mlx-table tbody tr:last-child td { border-bottom:none; }

/* The row number is furniture, not data: it is quiet and sits out
   of the way so the eye starts at the student, not the count. */
.mlx-x-no {
    width:1%;
    white-space:nowrap;
    font-family:'JetBrains Mono',ui-monospace,monospace;
    font-variant-numeric:tabular-nums;
    font-size:12.5px;
    color:#94a3b8;
}
/* Codes and numbers must never wrap: a section code broken across
   two lines, or "2024-2025" split, reads as two different values.
   tabular-nums keeps a column of digits vertically aligned. */
.mlx-table td:nth-child(2),
.mlx-table td:nth-child(7),
.mlx-table td:nth-child(8),
.mlx-table td:nth-child(9),
.mlx-table td:nth-child(10),
.mlx-table td:nth-child(11),
.mlx-table td:nth-child(13) {
    white-space:nowrap;
    font-variant-numeric:tabular-nums;
}
/* The student number and the section code are monospaced, exactly
   as they are on the printed sheet: 11001 and 11011 are hard to tell
   apart in a proportional face. */
.mlx-table td:nth-child(2),
.mlx-table td:nth-child(8) {
    font-family:'JetBrains Mono',ui-monospace,SFMono-Regular,Menlo,monospace;
    color:#334155;
}
/* Surname and first name are the anchor of the row and carry the
   weight; the tokens around them stay quiet. */
.mlx-table td:nth-child(3),
.mlx-table td:nth-child(4) { font-weight:600; color:#0f172a; }
/* "Not recorded" is a real state and is shown as a muted dash, so
   it never reads as a value the registrar typed. */
.mlx-na { color:#cbd5e1; }
.mlx-empty {
    text-align:center; color:#94a3b8;
    padding:26px 14px !important;
    font-size:13.5px;
}
/* An email is the one value long enough to need help. It truncates
   from the START, because an address reads by its domain and
   "…@school.edu" still identifies the account where
   "roldanti…gmail.com" does not. direction:rtl is what puts the
   ellipsis on the leading edge; text-align:left keeps the address
   itself in normal reading order. */
.mlx-table td:last-child {
    max-width:230px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    direction:rtl;
    text-align:left;
    color:#334155;
}

/* THE PROGRAM LEGEND.
   The key to every acronym printed above it. It is a quiet table at
   the end of the page, not a card in the middle of the work: it is
   consulted when a reader meets an acronym they do not know, and then
   they go back to what they were doing. */
.mlx-legend {
    margin:0 20px 20px;
    border:1px solid #e2e8f0;
    border-radius:12px;
    overflow:hidden;
}
.mlx-legend-head {
    padding:14px 20px 12px;
    border-bottom:1px solid #e2e8f0;
    background:#f8fafc;
}
.mlx-legend-title {
    margin:0;
    font-size:13px;
    font-weight:700;
    color:#0f172a;
    letter-spacing:.04em;
    text-transform:uppercase;
}
.mlx-legend-title i { color:#64748b; margin-right:4px; }
.mlx-legend-note {
    margin:5px 0 0;
    font-size:12.5px;
    color:#64748b;
}
/* The scroll box only matters if there are many programs; with three it
   never engages, and the table simply sits at its natural height. */
.mlx-legend-scroll { overflow:auto; max-height:min(50vh, 460px); }
.mlx-legend-table {
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    font-size:13.5px;
    color:#1e293b;
}
/* Sticky for the same reason the roster's heading is: the point of a
   legend is looking one acronym up, and it should not have to scroll
   back to the header to read the next column. */
.mlx-legend-table thead th {
    position:sticky; top:0; z-index:1;
    background:#f1f5f9;
    color:#334155;
    font-size:11.5px; font-weight:700;
    letter-spacing:.04em; text-transform:uppercase;
    text-align:left; white-space:nowrap;
    padding:10px 14px;
    border-bottom:2px solid #cbd5e1;
}
.mlx-legend-table td {
    padding:9px 14px;
    border-bottom:1px solid #eef2f6;
    vertical-align:middle;
    background:#fff;
}
.mlx-legend-table tbody tr:last-child td { border-bottom:none; }
.mlx-legend-table tbody tr:hover td { background:#f8fafc; }
/* The acronym is the key, so it is the thing that gets the weight and
   the tint - the same pill language the roster's Program column uses,
   so the two read as the same value. */
.mlx-legend-tag {
    display:inline-block;
    padding:3px 10px;
    border-radius:6px;
    background:#e8effd;
    color:#1d4ed8;
    font-size:13px;
    font-weight:800;
    letter-spacing:.05em;
    white-space:nowrap;
}
.mlx-legend-c-full { color:#334155; }
/* The count is a number, not a word: tabular so a column of them lines
   up, and right-aligned so the eye can compare magnitudes. */
.mlx-legend-c-n {
    text-align:right;
    font-variant-numeric:tabular-nums;
    white-space:nowrap;
    color:#475569;
}

@media print {
    /* The legend is a reading aid on screen. It is not part of any
       printed roster, and printing it would add a page of reference
       material under a sheet that gets signed. */
    .mlx-legend { display:none !important; }
}

@media print {
    /* The browser chrome is not on the printed sheet. The roster
       itself is: printing a section folder should print its roster. */
    .mlx-crumbs, .mlx-actions, .mlx-head .btn { display:none !important; }
    .mlx-roster-scroll { max-height:none; overflow:visible; border:none; margin:0; }
    .mlx-table thead th { position:static; }
    .mlx-table tbody tr { break-inside:avoid; }
}

.bulk-bar a { text-decoration: none; }
/* Filter dropdowns should look clickable */
#filterCourse, #filterYear, #filterSchoolYear, #filterSemester, #filterStatus, #filterSection,
select.form-control { cursor: pointer !important; }

/* Masterlist table styling */
.masterlist-table {
    color: #1e293b !important;
    background: #fff !important;
    border-radius: 12px !important;
    overflow: hidden !important;
}
/* The thead colours and the row hover now come from the page's own rule for
   .masterlist-table th, which is where the sort states live. They were repeated
   here with !important, which quietly won: the header rendered #f8fafc on
   #475569 no matter what the main block said, so a redesign of the band could
   not be seen on screen and only showed up in a print preview. Anything set
   here has to be a plain override, never !important on a property the main
   block owns. */
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
