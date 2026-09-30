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

// Adviser names are NOT joined here any more. The Adviser column reads N/A
// because naming an adviser is Faculty Management's (#296) to do, not the
// Registrar's - see DEPARTMENTS.md. The lookup survives only for the View
// Student modal below, which reads the profile the registrar maintains and is
// not part of the handed-off masterlist.
$advisers = $db->fetchAll("SELECT id, full_name FROM users WHERE role = 'staff' ORDER BY full_name");
$adviserNames = [];
foreach ($advisers as $ad) { $adviserNames[(int)$ad['id']] = $ad['full_name']; }

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
/* The block heading. A block is a program-year cohort, so the heading names
   only that. It carries no section: the code is Class Scheduling's to assign,
   and putting one here would claim a section that does not exist yet. */
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
@media(max-width:640px){.masterlist-header{padding:21px 18px}.masterlist-header h1{font-size:25px}.masterlist-actionbar{flex-direction:column}.masterlist-action-group{width:100%}.masterlist-action-buttons .btn{flex:1 1 100%;justify-content:center}.masterlist-toolbar{align-items:stretch}.masterlist-search{flex-basis:100%}.masterlist-ai,.masterlist-filter-btn{justify-content:center}}
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
            <p>Search, filter, and send the full student list. Section codes and advisers belong to other departments, so they are not recorded here.</p>
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
    </div>

    <?php if (!empty($students)): ?>
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
    ['gender',         'Gender'],
    ['contact',        'Contact'],
    ['course',         'Course'],
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
            w.document.write('<table><tr><th>#</th><th>Student No.</th><th>Name</th><th>Gender</th><th>Contact</th><th>Course</th><th>Birthdate</th><th>Status</th><th>Email</th></tr>');
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
                    + '</td><td>' + cellText(row, 'birthdate')
                    + '</td><td>' + cellText(row, 'status')
                    + '</td><td>' + cellText(row, 'email') + '</td></tr>');
            });
            w.document.write('</table>');
            // No scope note is printed. It was removed from the screen along with
            // the columns it explained; leaving it on the printed sheet would put
            // a paragraph about Section and Adviser onto a page that no longer
            // mentions either, for a reader who was never going to look for them.
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
