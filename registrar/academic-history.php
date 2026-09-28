<?php
// ============================================================
//  REGISTRAR/ACADEMIC-HISTORY.PHP
//  Term grading workspace.
//
//  This page used to import previous schools. That was wrong twice
//  over. academic_history is consumed as TERMS by the TOR, Form 137 and
//  the student grade views, and the save-academic endpoint behind it
//  could not record a semester subject, a final rating, or a computed
//  GWA at all. So the page described records nobody was making.
//
//  It is now the workspace for grading a term: pick the term, filter the
//  roster, enter final ratings, and check the term before closing it.
//
//  The GWA is computed in shared/term_grades.php and stored by the save
//  path from that same computation, so the status rules and the TOR read
//  one number rather than two. Staff do not type it.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/term_grades.php';
$db = Database::getInstance();

// The session-bound CSRF token, taken from the guard itself.
//
// Reading $_SESSION['csrf_token'] directly would be wrong: the token is
// created lazily by csrfToken() on first call, so a session that has never
// posted anything has no value there and the page would ship an empty
// token, and every save would be refused with a 419. Asking the guard
// gets the real one and creates it if it is missing.
require_once __DIR__ . '/../shared/csrf_guard.php';
$csrfToken = csrfToken();

// Ã¢â€â‚¬Ã¢â€â‚¬ Which term? Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
// The term is chosen first and everything below is scoped to it, because
// a term is the unit of work here. Grading one is not a per-student
// action repeated 200 times; it is a single pass over a list.
$years = array_map(
    static fn($r) => (string) $r['school_year'],
    $db->fetchAll("
        SELECT DISTINCT school_year FROM academic_history
         WHERE school_year IS NOT NULL AND school_year <> ''
         ORDER BY school_year DESC
    ")
);

// Offer the coming years so the current term can be started before it
// exists in the table.
$thisYear = (int) date('Y');
foreach ([$thisYear . '-' . $thisYear, $thisYear . '-' . ($thisYear + 1), $thisYear . '-' . ($thisYear + 2)] as $cand) {
    if (!in_array($cand, $years, true)) {
        $years[] = $cand;
    }
}
rsort($years);

$sy  = isset($_GET['sy'])  ? trim((string) $_GET['sy'])  : '';
$sem = isset($_GET['sem']) ? trim((string) $_GET['sem']) : '1st';
if ($sy === '' && $years) {
    $sy = $years[0];
}

// Ã¢â€â‚¬Ã¢â€â‚¬ Roster filters Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
// Grouped by program and year level, with section as an optional filter.
// Section is a within-cohort detail; defaulting to it would split the
// pass into fragments that have to be stitched back together before the
// term can be called complete.
$program = isset($_GET['program']) ? trim((string) $_GET['program']) : '';
$section = isset($_GET['section']) ? trim((string) $_GET['section']) : '';

$roster = $db->fetchAll("
    SELECT s.id, s.student_number, s.first_name, s.last_name,
           s.course AS program, s.year_level, s.section
      FROM students s
     WHERE s.status != 'archived'
     ORDER BY s.last_name, s.first_name
");

$programs = [];
$sections = [];
foreach ($roster as $r) {
    $p = trim((string) ($r['program'] ?? ''));
    if ($p !== '') { $programs[$p] = true; }
    $sec = trim((string) ($r['section'] ?? ''));
    if ($sec !== '') { $sections[$sec] = true; }
}
ksort($programs);
ksort($sections);

$visible = [];
foreach ($roster as $r) {
    if ($program !== '' && trim((string) ($r['program'] ?? '')) !== $program) { continue; }
    if ($section !== '' && trim((string) ($r['section'] ?? '')) !== $section) { continue; }
    $visible[] = $r;
}


// Ã¢â€â‚¬Ã¢â€â‚¬ What has been recorded for this term? Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
// Joined in one query rather than per student. The page shows a grid for
// every visible student, and fetching subjects one at a time is the
// difference between one round trip and two hundred.
$termRecords = [];
$termGrades  = [];
if ($sy !== '' && $visible) {
    $in = implode(',', array_map(static fn($r) => (int) $r['id'], $visible));
    foreach ($db->fetchAll("
        SELECT ah.* FROM academic_history ah
         WHERE ah.student_id IN ($in) AND ah.school_year = ? AND ah.semester = ?
    ", [$sy, $sem]) as $r) {
        $termRecords[(int) $r['student_id']] = $r;
    }
    if ($termRecords) {
        $rin = implode(',', array_map(static fn($r) => (int) $r['id'], $termRecords));
        foreach ($db->fetchAll("
            SELECT * FROM academic_grades WHERE academic_history_id IN ($rin) ORDER BY id ASC
        ") as $g) {
            $termGrades[(int) $g['academic_history_id']][] = $g;
        }
    }
}

// Ã¢â€â‚¬Ã¢â€â‚¬ Per-student picture, and the audit over it Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
$rosterForAudit = [];
$rows = [];
$termComplete = 0;
$termMissing  = 0;
$termUnits    = 0.0;

foreach ($visible as $r) {
    $sid  = (int) $r['id'];
    $rec  = $termRecords[$sid] ?? null;
    $rid  = $rec ? (int) $rec['id'] : 0;

    $subjects = [];
    foreach (($rid ? ($termGrades[$rid] ?? []) : []) as $g) {
        $subjects[] = [
            'subject'      => (string) $g['subject'],
            'subject_code' => (string) ($g['subject_code'] ?? ''),
            'units'        => (float) ($g['units'] ?? 0),
            'final_rating' => $g['final_rating'],
            'grade'        => (string) ($g['grade'] ?? ''),
            'remarks'      => (string) ($g['remarks'] ?? ''),
            'grade_status' => (string) ($g['grade_status'] ?? ''),
        ];
    }

    $gwa   = termGwa($subjects);
    $units = 0.0;
    $missingCount = 0;
    foreach ($subjects as $sub) {
        $units += max(0.0, (float) $sub['units']);
        if ($sub['final_rating'] === null || $sub['final_rating'] === '') {
            $missingCount++;
        }
    }
    $termUnits += $units;

    // Three states, not two. "Not started" and "started but incomplete"
    // are different problems and call for different responses: the first
    // needs a subject list built, the second needs a grade sheet chased.
    if (!$subjects) {
        $state = 'none';
    } elseif ($missingCount > 0) {
        $state = 'partial';
    } else {
        $state = 'complete';
    }
    $state === 'complete' ? $termComplete++ : $termMissing++;

    $rows[] = [
        'id'       => $sid,
        'number'   => (string) $r['student_number'],
        'name'     => trim($r['first_name'] . ' ' . $r['last_name']),
        'program'  => (string) ($r['program'] ?? ''),
        'level'    => (string) ($r['year_level'] ?? ''),
        'section'  => (string) ($r['section'] ?? ''),
        'record'   => $rid,
        'gwa'      => $gwa,
        'stored'   => $rec['gwa'] ?? null,
        'units'    => round($units, 2),
        'state'    => $state,
        'missing'  => $missingCount,
        'subjects' => $subjects,
    ];

    $rosterForAudit[] = [
        'name'     => trim($r['first_name'] . ' ' . $r['last_name']),
        'number'   => (string) $r['student_number'],
        'gwa'      => $rec['gwa'] ?? null,
        'subjects' => $subjects,
    ];
}

$audit = termAudit($sy, $sem, $rosterForAudit);

// Career GWA across every term on file for the filtered roster, so the
// figure someone remembers can be checked against what is actually held.
$career      = null;
$careerUnits = 0.0;
if ($visible) {
    $in = implode(',', array_map(static fn($r) => (int) $r['id'], $visible));
    $careerTerms = [];
    foreach ($db->fetchAll("SELECT id FROM academic_history WHERE student_id IN ($in)") as $h) {
        $subjectsForTerm = [];
        foreach ($db->fetchAll("SELECT units, final_rating FROM academic_grades WHERE academic_history_id = ?", [(int) $h['id']]) as $gs) {
            $subjectsForTerm[] = ['units' => (float) ($gs['units'] ?? 0), 'final_rating' => $gs['final_rating']];
        }
        $careerTerms[] = ['subjects' => $subjectsForTerm];
    }
    $career = careerGwa($careerTerms);
    foreach ($careerTerms as $t) {
        foreach ($t['subjects'] as $s) {
            if (termRatingValid($s['final_rating'])) {
                $careerUnits += max(0.0, (float) $s['units']);
            }
        }
    }
}

// The expected load, used only to raise a question and never to block.
$typicalUnits = null;
$termMeanGwa  = null;
if ($sy !== '') {
    $q = $db->fetchColumn("
        SELECT AVG(credits) FROM academic_history
         WHERE school_year = ? AND semester = ? AND credits IS NOT NULL AND credits > 0
    ", [$sy, $sem]);
    $typicalUnits = $q ? (float) $q : null;

    $m = $db->fetchColumn("
        SELECT AVG(gwa) FROM academic_history
         WHERE school_year = ? AND semester = ? AND gwa IS NOT NULL
    ", [$sy, $sem]);
    $termMeanGwa = $m ? round((float) $m, 2) : null;
}

$payload = json_encode([
    'sy'   => $sy,
    'sem'  => $sem,
    'csrf' => $csrfToken,
    'rows' => array_map(static fn($r) => [
        'id'       => $r['id'],
        'name'     => $r['name'],
        'number'   => $r['number'],
        'program'  => $r['program'],
        'level'    => $r['level'],
        'section'  => $r['section'],
        'record'   => $r['record'],
        'subjects' => $r['subjects'],
    ], $rows),
], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

$page_title = 'Academic History';
$body_page = 'academic';
$APP_ROOT = '../';
$ACTIVE_NAV = 'academic';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
/* ============================================================
   Term grading workspace - registrar-blue layer.
   Scoped to body[data-page="academic"] so nothing leaks into the
   other registrar pages.

   The rating scale is 1.00-5.00 with lower better, which is the
   opposite way round from the eye's first guess. A GWA figure is
   tinted by band so the direction is visible without reading a
   legend: green is good, amber is marginal, red is poor.
   ============================================================ */

body[data-page="academic"] .ah-kicker{
    display:inline-flex;align-items:center;gap:7px;
    font-size:.7rem;font-weight:700;letter-spacing:.09em;
    text-transform:uppercase;color:var(--primary,#1e5aa8);
}

/* Visually hidden, still announced. Used for the action column header,
   which labels nothing on screen but names the buttons below it. */
.ah-sr{
    position:absolute;width:1px;height:1px;padding:0;margin:-1px;
    overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;
}

/* ── Modals ──────────────────────────────────────────────
   Two dialogs share one shape. The audit drawer is wider and
   taller because it has a list to read; the grade grid is a
   form, so it is the same shell with a denser body. */
.ah-modal{position:fixed;inset:0;z-index:1200;display:flex;align-items:flex-start;justify-content:center;padding:5vh 16px}
.ah-modal[hidden]{display:none}
.ah-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.5);backdrop-filter:blur(2px)}
.ah-modal-card{
    position:relative;width:100%;max-width:880px;max-height:90vh;
    display:flex;flex-direction:column;overflow:hidden;
    background:#fff;border-radius:14px;
    box-shadow:0 18px 48px rgba(15,23,42,.24);
}
.ah-modal-head{
    display:flex;align-items:center;gap:10px;
    padding:15px 18px;border-bottom:1px solid var(--border,#e3e8ef);
}
.ah-modal-head h2{margin:0;font-size:1.05rem;font-weight:700;color:var(--text,#1f2937);flex:1 1 auto}

body[data-page="academic"] .ah-head-note{
    margin:.35rem 0 0;font-size:.86rem;color:var(--muted,#6b7280);
    max-width:62ch;line-height:1.5;
}

/* â”€â”€ Term bar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
   The term selector sits above everything because it scopes
   everything. It navigates on change rather than filtering in
   place: picking a term reloads the page, which keeps the URL
   shareable and the back button meaningful. */
body[data-page="academic"] .term-bar{
    display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px;
    padding:16px 18px;margin-bottom:18px;
    background:#fff;border:1px solid var(--border,#e3e8ef);
    border-radius:12px;
}
body[data-page="academic"] .term-field{display:flex;flex-direction:column;gap:5px;min-width:150px}
body[data-page="academic"] .term-field label{
    font-size:.68rem;font-weight:700;letter-spacing:.07em;
    text-transform:uppercase;color:var(--muted,#6b7280);
}
body[data-page="academic"] .term-field select,
body[data-page="academic"] .term-field input{
    padding:9px 11px;font-size:.9rem;font-family:inherit;
    border:1px solid var(--border,#d5dbe4);border-radius:8px;
    background:#fff;color:inherit;min-height:38px;
}
body[data-page="academic"] .term-field select:focus,
body[data-page="academic"] .term-field input:focus{
    outline:2px solid var(--primary,#1e5aa8);outline-offset:1px;
}
body[data-page="academic"] .term-spacer{flex:1 1 auto}
body[data-page="academic"] .term-current{
    align-self:center;text-align:right;font-size:.78rem;
    color:var(--muted,#6b7280);line-height:1.4;
}
body[data-page="academic"] .term-current strong{
    display:block;font-size:.95rem;color:var(--text,#1f2937);
}
body[data-page="academic"] .term-current a{
    color:var(--primary,#1e5aa8);font-size:.76rem;
}

/* â”€â”€ Metric strip â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
body[data-page="academic"] .ah-stats{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
    gap:1px;background:var(--border,#e3e8ef);
    border:1px solid var(--border,#e3e8ef);border-radius:12px;
    overflow:hidden;margin-bottom:18px;
}
body[data-page="academic"] .ah-stat{background:#fff;padding:15px 17px;position:relative}
body[data-page="academic"] .ah-stat::after{
    content:'';position:absolute;left:17px;right:17px;bottom:11px;height:2px;
    background:var(--primary,#1e5aa8);opacity:.22;border-radius:2px;
}
body[data-page="academic"] .ah-stat[data-tone="warn"]::after{background:#d97706;opacity:.45}
body[data-page="academic"] .ah-stat[data-tone="ok"]::after{background:#15803d;opacity:.4}
body[data-page="academic"] .ah-stat-label{
    font-size:.68rem;font-weight:700;letter-spacing:.07em;
    text-transform:uppercase;color:var(--muted,#6b7280);margin:0 0 6px;
}
body[data-page="academic"] .ah-stat-value{
    font-size:1.55rem;font-weight:700;line-height:1.1;
    color:var(--text,#1f2937);margin:0;
    font-variant-numeric:tabular-nums;
}
body[data-page="academic"] .ah-stat-sub{font-size:.76rem;color:var(--muted,#6b7280);margin:5px 0 0}

/* â”€â”€ Roster table â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
body[data-page="academic"] .ah-panel{
    background:#fff;border:1px solid var(--border,#e3e8ef);
    border-radius:12px;overflow:hidden;margin-bottom:18px;
}
body[data-page="academic"] .ah-panel-head{
    display:flex;flex-wrap:wrap;align-items:center;gap:10px;
    padding:14px 18px;border-bottom:1px solid var(--border,#e3e8ef);
}
body[data-page="academic"] .ah-panel-head h2{
    margin:0;font-size:1rem;font-weight:700;color:var(--text,#1f2937);
}
body[data-page="academic"] .ah-panel-head .spacer{flex:1 1 auto}
body[data-page="academic"] .ah-table-wrap{overflow-x:auto}
body[data-page="academic"] .ah-table{width:100%;border-collapse:collapse;font-size:.88rem}
body[data-page="academic"] .ah-table th{
    text-align:left;font-size:.68rem;font-weight:700;letter-spacing:.07em;
    text-transform:uppercase;color:var(--muted,#6b7280);
    padding:11px 14px;border-bottom:1px solid var(--border,#e3e8ef);
    background:#fafbfd;white-space:nowrap;
}
body[data-page="academic"] .ah-table td{
    padding:11px 14px;border-bottom:1px solid #f0f3f7;vertical-align:middle;
}
body[data-page="academic"] .ah-table tbody tr:last-child td{border-bottom:none}
body[data-page="academic"] .ah-table tbody tr{transition:background .12s ease}
body[data-page="academic"] .ah-table tbody tr:hover{background:#f7f9fc}
body[data-page="academic"] .ah-num{
    font-variant-numeric:tabular-nums;font-weight:600;color:var(--text,#1f2937);
}
body[data-page="academic"] .ah-sub{font-size:.76rem;color:var(--muted,#6b7280)}
body[data-page="academic"] .ah-gwa{font-variant-numeric:tabular-nums;font-weight:700;font-size:.95rem}
body[data-page="academic"] .ah-gwa[data-band="good"]{color:#15803d}
body[data-page="academic"] .ah-gwa[data-band="warn"]{color:#b45309}
body[data-page="academic"] .ah-gwa[data-band="poor"]{color:#b91c1c}
body[data-page="academic"] .ah-gwa[data-band="none"]{color:var(--muted,#9ca3af);font-weight:600}

/* â”€â”€ State pills â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
body[data-page="academic"] .ah-state{
    display:inline-flex;align-items:center;gap:5px;
    padding:3px 9px;border-radius:999px;
    font-size:.72rem;font-weight:700;letter-spacing:.02em;white-space:nowrap;
}
body[data-page="academic"] .ah-state[data-state="complete"]{background:#dcfce7;color:#15803d}
body[data-page="academic"] .ah-state[data-state="partial"]{background:#fef3c7;color:#b45309}
body[data-page="academic"] .ah-state[data-state="none"]{background:#f1f5f9;color:#64748b}

body[data-page="academic"] .ah-btn{
    display:inline-flex;align-items:center;gap:6px;
    padding:7px 13px;font-size:.82rem;font-weight:600;font-family:inherit;
    border:1px solid var(--border,#d5dbe4);border-radius:8px;
    background:#fff;color:var(--text,#1f2937);cursor:pointer;
    transition:background .12s ease,border-color .12s ease;
}
body[data-page="academic"] .ah-btn:hover{background:#f4f7fb;border-color:#c3ccd9}
body[data-page="academic"] .ah-btn:focus-visible{outline:2px solid var(--primary,#1e5aa8);outline-offset:2px}
body[data-page="academic"] .ah-btn-primary{
    background:var(--primary,#1e5aa8);border-color:var(--primary,#1e5aa8);color:#fff;
}
body[data-page="academic"] .ah-btn-primary:hover{background:#184a8c;border-color:#184a8c}
body[data-page="academic"] .ah-btn[disabled]{opacity:.5;cursor:not-allowed}

/* â”€â”€ Grade entry grid â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
   The grid is the work surface. Ratings are 1.00-5.00 with lower
   better, so each cell carries a band tint rather than a colour
   the reader has to interpret. A cell outside the scale is
   outlined in red, because it will not average and the save will
   refuse it. */
body[data-page="academic"] .ah-grid-wrap{overflow-x:auto;padding:0 18px 18px}
body[data-page="academic"] .ah-grid{width:100%;border-collapse:collapse;font-size:.86rem}
body[data-page="academic"] .ah-grid th{
    text-align:left;font-size:.68rem;font-weight:700;letter-spacing:.07em;
    text-transform:uppercase;color:var(--muted,#6b7280);
    padding:10px 12px;border-bottom:1px solid var(--border,#e3e8ef);
    background:#fafbfd;white-space:nowrap;
}
body[data-page="academic"] .ah-grid td{
    padding:8px 12px;border-bottom:1px solid #f0f3f7;vertical-align:middle;
}
body[data-page="academic"] .ah-grid tbody tr:last-child td{border-bottom:none}
body[data-page="academic"] .ah-in{
    width:100%;padding:7px 9px;font-size:.85rem;font-family:inherit;
    border:1px solid var(--border,#d5dbe4);border-radius:7px;
    background:#fff;color:inherit;min-height:34px;
}
body[data-page="academic"] .ah-in-num{
    width:74px;text-align:center;font-variant-numeric:tabular-nums;
}
body[data-page="academic"] .ah-in:focus{
    outline:2px solid var(--primary,#1e5aa8);outline-offset:1px;
}
body[data-page="academic"] .ah-in[data-band="good"]{border-color:#86c79b;background:#f2fbf5}
body[data-page="academic"] .ah-in[data-band="warn"]{border-color:#e0b168;background:#fffaf0}
body[data-page="academic"] .ah-in[data-band="poor"]{border-color:#e39a9a;background:#fdf4f4}
body[data-page="academic"] .ah-in[aria-invalid="true"]{border-color:#b91c1c;background:#fef2f2}
body[data-page="academic"] .ah-sel{
    padding:7px 8px;font-size:.82rem;font-family:inherit;
    border:1px solid var(--border,#d5dbe4);border-radius:7px;background:#fff;
    min-height:34px;
}
body[data-page="academic"] .ah-rm{
    border:none;background:transparent;color:var(--muted,#9ca3af);
    cursor:pointer;font-size:.95rem;padding:4px 7px;border-radius:6px;
}
body[data-page="academic"] .ah-rm:hover{background:#fef2f2;color:#b91c1c}
body[data-page="academic"] .ah-rm:focus-visible{outline:2px solid var(--primary,#1e5aa8);outline-offset:1px}
body[data-page="academic"] .ah-scale{
    display:flex;flex-wrap:wrap;gap:14px;align-items:center;
    padding:10px 18px;background:#fafbfd;
    border-bottom:1px solid var(--border,#e3e8ef);
    font-size:.76rem;color:var(--muted,#6b7280);
}
body[data-page="academic"] .ah-scale b{color:var(--text,#1f2937)}
body[data-page="academic"] .ah-legend{display:inline-flex;align-items:center;gap:5px}
body[data-page="academic"] .ah-dot{
    width:9px;height:9px;border-radius:50%;display:inline-block;
    border:1px solid rgba(0,0,0,.14);
}
body[data-page="academic"] .ah-dot[data-band="good"]{background:#dcfce7}
body[data-page="academic"] .ah-dot[data-band="warn"]{background:#fef3c7}
body[data-page="academic"] .ah-dot[data-band="poor"]{background:#fee2e2}

/* â”€â”€ Findings â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
   Blocking and advisory are visually distinct and never mixed.
   A finding that blocks a close and a finding that only raises a
   question must not read as the same weight. */
body[data-page="academic"] .ah-find{
    display:flex;gap:11px;padding:12px 14px;border-radius:9px;
    border:1px solid;margin-bottom:9px;align-items:flex-start;
}
body[data-page="academic"] .ah-find[data-sev="blocking"]{background:#fef2f2;border-color:#f3c6c6}
body[data-page="academic"] .ah-find[data-sev="advisory"]{background:#fffbeb;border-color:#f0dcae}
body[data-page="academic"] .ah-find i{flex:0 0 auto;margin-top:2px;font-size:.92rem}
body[data-page="academic"] .ah-find[data-sev="blocking"] i{color:#b91c1c}
body[data-page="academic"] .ah-find[data-sev="advisory"] i{color:#b45309}
body[data-page="academic"] .ah-find-body{min-width:0;flex:1 1 auto}
body[data-page="academic"] .ah-find-title{
    font-size:.85rem;font-weight:700;color:var(--text,#1f2937);margin:0 0 3px;
}
body[data-page="academic"] .ah-find-detail{
    font-size:.82rem;color:var(--text,#374151);margin:0 0 4px;line-height:1.45;
    overflow-wrap:anywhere;
}
body[data-page="academic"] .ah-find-action{
    font-size:.78rem;color:var(--muted,#6b7280);margin:0;line-height:1.45;font-style:italic;
}
body[data-page="academic"] .ah-audit-head{
    display:flex;flex-wrap:wrap;align-items:center;gap:10px;
    padding:14px 18px;border-bottom:1px solid var(--border,#e3e8ef);
}
body[data-page="academic"] .ah-audit-head h2{margin:0;font-size:1rem;font-weight:700;color:var(--text,#1f2937)}
body[data-page="academic"] .ah-audit-head .spacer{flex:1 1 auto}
body[data-page="academic"] .ah-audit-summary{
    padding:12px 18px;font-size:.85rem;color:var(--text,#374151);
    background:#fafbfd;border-bottom:1px solid var(--border,#e3e8ef);
}
body[data-page="academic"] .ah-audit-body{padding:16px 18px;max-height:56vh;overflow-y:auto}
body[data-page="academic"] .ah-audit-note{
    margin:0 18px 16px;padding:11px 13px;border-radius:9px;
    background:#f0f6ff;border:1px solid #cfe0f8;
    font-size:.79rem;color:#1e3a5f;line-height:1.5;
}

/* â”€â”€ Save bar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
body[data-page="academic"] .ah-savebar{
    display:flex;flex-wrap:wrap;align-items:center;gap:11px;
    padding:13px 18px;background:#fafbfd;
    border-top:1px solid var(--border,#e3e8ef);
}
body[data-page="academic"] .ah-savebar .spacer{flex:1 1 auto}
body[data-page="academic"] .ah-savebar-status{font-size:.8rem;color:var(--muted,#6b7280);line-height:1.4}
body[data-page="academic"] .ah-savebar-status[data-tone="bad"]{color:#b91c1c}
body[data-page="academic"] .ah-savebar-status[data-tone="good"]{color:#15803d}

/* â”€â”€ Empty state â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
   The commonest cause of an empty list is a filter left over from
   a previous visit, so the empty state offers the way out rather
   than only reporting the fact. */
body[data-page="academic"] .ah-empty{padding:44px 24px;text-align:center;color:var(--muted,#6b7280)}
body[data-page="academic"] .ah-empty i{font-size:1.9rem;opacity:.35;display:block;margin-bottom:12px}
body[data-page="academic"] .ah-empty h3{margin:0 0 6px;font-size:1rem;color:var(--text,#1f2937)}
body[data-page="academic"] .ah-empty p{margin:0 auto 16px;font-size:.87rem;max-width:46ch;line-height:1.5}

@media (max-width:900px){
    body[data-page="academic"] .term-current{text-align:left;align-self:flex-start}
    body[data-page="academic"] .term-spacer{display:none}
    body[data-page="academic"] .term-field{flex:1 1 140px;min-width:0}
}
@media (max-width:640px){
    body[data-page="academic"] .ah-stat-value{font-size:1.3rem}
    body[data-page="academic"] .ah-table th,
    body[data-page="academic"] .ah-table td{padding:9px 10px}
}
@media (prefers-reduced-motion:reduce){
    body[data-page="academic"] .ah-table tbody tr,
    body[data-page="academic"] .ah-btn{transition:none}
}
</style>
<main class="dashboard-main">
<div class="dashboard-container">

    <header class="header">
        <div class="title">
            <div class="ah-kicker"><i class="fa-solid fa-clipboard-check"></i> Term grading</div>
            <h1>Academic History</h1>
            <p class="ah-head-note">
                Record final ratings by term. The GWA is computed from the ratings you enter and
                stored with the term, so the transcript, the TOR and the status rules all read
                the same figure. Ratings run 1.00 to 5.00, where lower is better.
            </p>
        </div>
        <div class="header-actions">
            <button class="ah-btn ah-btn-primary" type="button" id="btnAudit"
                    onclick="openAudit()">
                <i class="fa-solid fa-stethoscope"></i> Check this term before you close
            </button>
        </div>
    </header>

    <!-- â”€â”€ Term and roster filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
         A form that navigates on change. Reloading rather than
         filtering in place keeps the term in the URL, so a view of
         one term can be shared and the back button returns to the
         previous term. -->
    <form class="term-bar" method="get" action="academic-history.php" id="termForm">
        <div class="term-field">
            <label for="fltSy">School year</label>
            <select name="sy" id="fltSy" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?>
                    <option value="<?= htmlspecialchars($y) ?>"<?= $y === $sy ? ' selected' : '' ?>>
                        <?= htmlspecialchars($y) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="term-field">
            <label for="fltSem">Semester</label>
            <select name="sem" id="fltSem" onchange="this.form.submit()">
                <?php foreach (['1st', '2nd', 'Summer'] as $s): ?>
                    <option value="<?= $s ?>"<?= $s === $sem ? ' selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="term-field">
            <label for="fltProgram">Program</label>
            <select name="program" id="fltProgram" onchange="this.form.submit()">
                <option value="">All programs</option>
                <?php foreach (array_keys($programs) as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>"<?= $p === $program ? ' selected' : '' ?>>
                        <?= htmlspecialchars($p) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="term-field">
            <label for="fltSection">Section</label>
            <select name="section" id="fltSection" onchange="this.form.submit()">
                <option value="">All sections</option>
                <?php foreach (array_keys($sections) as $sec): ?>
                    <option value="<?= htmlspecialchars($sec) ?>"<?= $sec === $section ? ' selected' : '' ?>>
                        <?= htmlspecialchars($sec) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="term-spacer"></div>
        <div class="term-current">
            <strong><?= htmlspecialchars(termLabel($sy, $sem)) ?></strong>
            <?= count($visible) ?> student<?= count($visible) === 1 ? '' : 's' ?> in view
            <?php if ($program !== '' || $section !== ''): ?>
                &middot; <a href="academic-history.php?sy=<?= urlencode($sy) ?>&amp;sem=<?= urlencode($sem) ?>">clear filters</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- â”€â”€ Metrics â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
         "Awaiting" is the actionable figure, so it carries the
         tone. A mean GWA is only shown once there is something to
         average - an average of nothing is not 0.00, it is absent. -->
    <section class="ah-stats" aria-label="Term summary">
        <div class="ah-stat">
            <p class="ah-stat-label">In view</p>
            <p class="ah-stat-value"><?= count($visible) ?></p>
            <p class="ah-stat-sub"><?= $program !== '' ? htmlspecialchars($program) : 'All programs' ?></p>
        </div>
        <div class="ah-stat" data-tone="ok">
            <p class="ah-stat-label">Complete</p>
            <p class="ah-stat-value"><?= $termComplete ?></p>
            <p class="ah-stat-sub">every subject has a final rating</p>
        </div>
        <div class="ah-stat"<?= $termMissing > 0 ? ' data-tone="warn"' : '' ?>>
            <p class="ah-stat-label">Awaiting grades</p>
            <p class="ah-stat-value"><?= $termMissing ?></p>
            <p class="ah-stat-sub"><?= $termMissing > 0 ? 'not started or incomplete' : 'nothing outstanding' ?></p>
        </div>
        <div class="ah-stat">
            <p class="ah-stat-label">Mean term GWA</p>
            <p class="ah-stat-value">
                <?= $termMeanGwa === null ? '&mdash;' : number_format($termMeanGwa, 2) ?>
            </p>
            <p class="ah-stat-sub">across recorded terms</p>
        </div>
        <div class="ah-stat">
            <p class="ah-stat-label">Career GWA</p>
            <p class="ah-stat-value">
                <?= $career === null ? '&mdash;' : number_format($career, 2) ?>
            </p>
            <p class="ah-stat-sub">
                <?= $career === null ? 'no ratings on file' : 'over ' . rtrim(rtrim(number_format($careerUnits, 0), '0'), '.') . ' units' ?>
            </p>
        </div>
    </section>

    <!-- â”€â”€ Roster â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
         One row per student with their computed GWA and state.
         The GWA shown is the one the server computed and stored,
         not a figure typed here. -->
    <section class="ah-panel">
        <div class="ah-panel-head">
            <h2>Roster</h2>
            <span class="ah-sub">
                <?= count($visible) ?> in view
                <?= $termComplete + $termMissing > 0
                    ? '&middot; ' . $termComplete . ' complete, ' . $termMissing . ' outstanding'
                    : '' ?>
            </span>
            <div class="spacer"></div>
        </div>

        <?php if (!$visible): ?>
            <div class="ah-empty">
                <i class="fa-regular fa-folder-open"></i>
                <h3>No students match this view</h3>
                <?php if ($program !== '' || $section !== ''): ?>
                    <p>
                        The program and section filters together match nobody. Clearing them
                        shows the whole roster.
                    </p>
                    <a class="ah-btn" href="academic-history.php?sy=<?= urlencode($sy) ?>&amp;sem=<?= urlencode($sem) ?>">
                        <i class="fa-solid fa-filter-circle-xmark"></i> Clear filters
                    </a>
                <?php else: ?>
                    <p>
                        There are no active students to grade. Archived students are left out
                        of this page on purpose.
                    </p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="ah-table-wrap">
                <table class="ah-table">
                    <thead>
                        <tr>
                            <th scope="col">Student</th>
                            <th scope="col">Program</th>
                            <th scope="col">Level</th>
                            <th scope="col">Section</th>
                            <th scope="col">Subjects</th>
                            <th scope="col">Units</th>
                            <th scope="col">Term GWA</th>
                            <th scope="col">State</th>
                            <th scope="col"><span class="ah-sr">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $band = 'none';
                        if ($r['gwa'] !== null) {
                            // Lower is better on this scale, so the bands run
                            // the opposite way round from a school average.
                            $band = $r['gwa'] < GWA_AT_RISK - 0.5 ? 'good'
                                  : ($r['gwa'] < GWA_AT_RISK ? 'warn' : 'poor');
                        }
                        $stateLabel = [
                            'complete' => 'Complete',
                            'partial'  => $r['missing'] . ' missing',
                            'none'     => 'Not started',
                        ][$r['state']];
                        ?>
                        <tr>
                            <td>
                                <div class="ah-num"><?= htmlspecialchars($r['name']) ?></div>
                                <div class="ah-sub"><?= htmlspecialchars($r['number']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($r['program'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($r['level'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($r['section'] ?: '—') ?></td>
                            <td><?= count($r['subjects']) ?: '<span class="ah-sub">—</span>' ?></td>
                            <td><?= $r['units'] > 0 ? rtrim(rtrim(number_format($r['units'], 2), '0'), '.') : '<span class="ah-sub">—</span>' ?></td>
                            <td>
                                <span class="ah-gwa" data-band="<?= $band ?>">
                                    <?= $r['gwa'] === null ? 'no data' : number_format($r['gwa'], 2) ?>
                                </span>
                            </td>
                            <td>
                                <span class="ah-state" data-state="<?= $r['state'] ?>"><?= $stateLabel ?></span>
                            </td>
                            <td>
                                <button class="ah-btn" type="button"
                                        onclick="openGrades(<?= (int) $r['id'] ?>)">
                                    <i class="fa-solid fa-pen"></i> Grades
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
</main>

<!-- â”€â”€ Grade entry â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     One student's term, opened from the roster. The GWA preview
     below the grid is recomputed as the user types, using the
     same weighting the server will apply, so what they see before
     saving is what gets stored. -->
<div class="ah-modal" id="gradeModal" role="dialog" aria-modal="true" aria-labelledby="gradeModalTitle" hidden>
    <div class="ah-modal-backdrop" onclick="closeGrades()"></div>
    <div class="ah-modal-card">
        <div class="ah-modal-head">
            <h2 id="gradeModalTitle">Final ratings</h2>
            <button class="ah-rm" type="button" onclick="closeGrades()" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="ah-audit-summary" id="gradeWho"></div>

        <div class="ah-scale">
            <span>Ratings <b>1.00 to 5.00</b>, lower is better.</span>
            <span class="ah-legend"><i class="ah-dot" data-band="good"></i> under 2.50</span>
            <span class="ah-legend"><i class="ah-dot" data-band="warn"></i> 2.50 to 2.99</span>
            <span class="ah-legend"><i class="ah-dot" data-band="poor"></i> 3.00 and above</span>
        </div>

        <div class="ah-grid-wrap">
            <table class="ah-grid">
                <thead>
                    <tr>
                        <th scope="col" style="width:34%">Subject</th>
                        <th scope="col" style="width:12%">Units</th>
                        <th scope="col" style="width:20%">Final rating</th>
                        <th scope="col" style="width:24%">Result</th>
                        <th scope="col"><span class="ah-sr">Remove</span></th>
                    </tr>
                </thead>
                <tbody id="gradeRows"></tbody>
            </table>
        </div>

        <div class="ah-savebar">
            <button class="ah-btn" type="button" onclick="addGradeRow()">
                <i class="fa-solid fa-plus"></i> Add subject
            </button>
            <div class="spacer"></div>
            <span class="ah-savebar-status" id="gradeGwaPreview"></span>
            <button class="ah-btn ah-btn-primary" type="button" id="btnSaveGrades" onclick="saveGrades()">
                <i class="fa-solid fa-floppy-disk"></i> Save term
            </button>
        </div>
    </div>
</div>

<!-- â”€â”€ Pre-close audit â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     Read-only. It reports what is missing or inconsistent and
     stops there: it assigns no grade, changes no status, and
     writes nothing. The GWA-at-3.00 finding is a question about
     the status rules, not a decision about this student. -->
<div class="ah-modal" id="auditModal" role="dialog" aria-modal="true" aria-labelledby="auditModalTitle" hidden>
    <div class="ah-modal-backdrop" onclick="closeAudit()"></div>
    <div class="ah-modal-card">
        <div class="ah-audit-head">
            <h2 id="auditModalTitle">Check before closing</h2>
            <div class="spacer"></div>
            <button class="ah-rm" type="button" onclick="closeAudit()" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="ah-audit-summary" id="auditSummary"></div>
        <div class="ah-audit-body" id="auditBody"></div>
        <p class="ah-audit-note">
            <i class="fa-solid fa-circle-info"></i>
            This check reads the records and reports. It does not assign grades, change any
            status, or save anything. Findings about a GWA at 3.00 or above are a prompt to
            look, and any status decision stays with you on the status page.
        </p>
    </div>
</div>

<script>
'use strict';
// Server-computed figures. termGwa() in shared/term_grades.php is the
// reference; the preview below re-implements only the arithmetic so it can
// run on every keystroke, and tests/term_grades_check.php pins the same
// weighting. If you change one, change the other.
const AH = <?= $payload ?>;

// The audit is computed server-side and rendered here. Findings are data,
// not prose written in JS, so the drawer and the save-time validation
// cannot drift apart.
const AH_AUDIT = <?= json_encode([
    'blocking' => $audit['blocking'],
    'advisory' => $audit['advisory'],
    'summary'  => $audit['summary'],
    'stats'    => $audit['stats'],
], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

let gradeStudent = null;

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

function rowById(id) {
    return AH.rows.find(r => r.id === id) || null;
}

/* Weighted term GWA, mirroring termGwa() on the server.
   A subject with no rating, no units, or an out-of-scale rating cannot
   contribute: that is missing data, not a zero. */
function previewGwa(subjects) {
    let weighted = 0, units = 0;
    for (const s of subjects) {
        const u = parseFloat(s.units);
        const r = s.final_rating === '' ? NaN : parseFloat(s.final_rating);
        if (!isFinite(u) || u <= 0) continue;
        if (!isFinite(r) || r < 1 || r > 5) continue;
        weighted += r * u;
        units += u;
    }
    return units > 0 ? Math.round((weighted / units) * 100) / 100 : null;
}

/* The 1.00-5.00 bands, tinted so the direction reads without a legend. */
function band(r) {
    if (!isFinite(r)) return null;
    if (r < 2.5) return 'good';
    if (r < 3.0) return 'warn';
    return 'poor';
}

function show(id) {
    const el = document.getElementById(id);
    if (el) { el.hidden = false; document.body.style.overflow = 'hidden'; }
}
function hide(id) {
    const el = document.getElementById(id);
    if (el) { el.hidden = true; document.body.style.overflow = ''; }
}

function openGrades(studentId) {
    const r = rowById(studentId);
    if (!r) return;
    gradeStudent = r;
    document.getElementById('gradeWho').innerHTML =
        '<strong>' + esc(r.name) + '</strong> &middot; ' + esc(r.number) +
        (r.program ? ' &middot; ' + esc(r.program) : '') +
        ' &middot; ' + esc(AH.sy) + ' ' + esc(AH.sem);
    const body = document.getElementById('gradeRows');
    body.innerHTML = '';
    if (!r.subjects.length) {
        // One blank row rather than an empty table. A term nobody has
        // started is the state this page exists to move away from, and
        // an empty grid offers nowhere to type.
        body.innerHTML = gradeRowHtml({subject:'', units:'', final_rating:'', grade_status:''});
    } else {
        r.subjects.forEach(s => { body.insertAdjacentHTML('beforeend', gradeRowHtml(s)); });
    }
    updatePreview();
    show('gradeModal');
    const first = body.querySelector('input');
    if (first) first.focus();
}

function closeGrades() { hide('gradeModal'); }

function gradeRowHtml(s) {
    const st = s.grade_status || '';
    return '<tr>' +
        '<td><input class="ah-in" type="text" data-f="subject" value="' + esc(s.subject) + '" placeholder="Subject name"></td>' +
        '<td><input class="ah-in ah-in-num" type="number" data-f="units" min="0" max="12" step="0.5" value="' + esc(s.units) + '"></td>' +
        '<td><input class="ah-in ah-in-num" type="number" data-f="final_rating" min="1" max="5" step="0.01" value="' +
            esc(s.final_rating === null ? '' : s.final_rating) + '"></td>' +
        '<td><select class="ah-sel" data-f="grade_status">' +
            ['passed','failed','dropped',''].map(o =>
                '<option value="' + o + '"' + (st === o ? ' selected' : '') + '>' +
                (o === '' ? 'Not set' : o.charAt(0).toUpperCase() + o.slice(1)) + '</option>').join('') +
        '</select></td>' +
        '<td><button class="ah-rm" type="button" onclick="removeGradeRow(this)" aria-label="Remove subject">' +
            '<i class="fa-solid fa-trash-can"></i></button></td>' +
    '</tr>';
}

function readGrid() {
    return Array.from(document.querySelectorAll('#gradeRows tr')).map(tr => {
        const o = {};
        tr.querySelectorAll('[data-f]').forEach(el => { o[el.dataset.f] = el.value.trim(); });
        return o;
    }).filter(s => s.subject !== '');
}

function addGradeRow() {
    const body = document.getElementById('gradeRows');
    body.insertAdjacentHTML('beforeend', gradeRowHtml({subject:'', units:'', final_rating:'', grade_status:''}));
    const rows = body.querySelectorAll('tr');
    rows[rows.length - 1].querySelector('input').focus();
}

function removeGradeRow(btn) {
    btn.closest('tr').remove();
    updatePreview();
}

/* Recompute the preview and repaint the rating cells.
   Out-of-scale and missing values are marked here, so the grid says the
   save will fail before the user presses it rather than after. */
function updatePreview() {
    const subjects = readGrid();
    const gwa = previewGwa(subjects);

    document.querySelectorAll('#gradeRows tr').forEach(tr => {
        const input = tr.querySelector('[data-f="final_rating"]');
        const raw = input.value.trim();
        input.removeAttribute('aria-invalid');
        input.removeAttribute('data-band');
        if (raw === '') return;
        const v = parseFloat(raw);
        if (!isFinite(v) || v < 1 || v > 5) {
            // Named on the cell itself, not only in the bar below, so the
            // reason travels with the field.
            input.setAttribute('aria-invalid', 'true');
            input.title = 'Ratings run from 1.00 to 5.00. This value cannot be averaged.';
            return;
        }
        const b = band(v);
        if (b) input.setAttribute('data-band', b);
    });

    const bad = subjects.filter(s => {
        if (s.final_rating === '') return true;
        const v = parseFloat(s.final_rating);
        return !isFinite(v) || v < 1 || v > 5;
    });

    const out = document.getElementById('gradeGwaPreview');
    if (bad.length) {
        out.dataset.tone = 'bad';
        out.textContent = bad.length + ' subject' + (bad.length === 1 ? '' : 's') +
            ' still need' + (bad.length === 1 ? 's' : '') + ' a valid rating before saving.';
    } else if (gwa === null) {
        out.dataset.tone = '';
        out.textContent = subjects.length ? 'No units to average yet.' : 'Add the subjects taken this term.';
    } else {
        out.dataset.tone = 'good';
        out.textContent = subjects.length + ' subject' + (subjects.length === 1 ? '' : 's') +
            ' · term GWA ' + gwa.toFixed(2);
    }
}

/* Saving posts the term and lets the server decide. The GWA is not sent
   from here: it is computed server-side from the same ratings, so there
   is no value on the wire for a stale client to overwrite. */
async function saveGrades() {
    if (!gradeStudent) return;
    const subjects = readGrid();
    const btn = document.getElementById('btnSaveGrades');
    const out = document.getElementById('gradeGwaPreview');
    btn.disabled = true;
    out.dataset.tone = '';
    out.textContent = 'Saving…';

    try {
        const res = await fetch('../api/students.php?action=save-academic', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                // Required by shared/csrf_guard.php. The token is bound to
                // the session, so a save from a stale tab is refused and the
                // user is told to reload rather than silently losing work.
                'X-CSRF-Token': AH.csrf || '',
            },
            body: JSON.stringify({
                student_id: gradeStudent.id,
                school_year: AH.sy,
                semester: AH.sem,
                grade_level: gradeStudent.level,
                grades: subjects,
            }),
        });
        const data = await res.json();

        if (data.success) {
            out.dataset.tone = 'good';
            out.textContent = data.message;
            // Reload rather than patching the table in place: the stored
            // GWA, the completion state and the audit all derive from the
            // saved row, and a partial client-side update would leave
            // them disagreeing until the next refresh.
            setTimeout(() => window.location.reload(), 700);
            return;
        }

        btn.disabled = false;
        out.dataset.tone = 'bad';
        const problems = Array.isArray(data.problems) ? data.problems : [];
        out.textContent = problems.length
            ? data.message + ' ' + problems.join(' ')
            : (data.message || 'The term could not be saved.');
    } catch (err) {
        btn.disabled = false;
        out.dataset.tone = 'bad';
        out.textContent = 'The term could not be saved. The server did not respond; check the connection and try again.';
    }
}

/* â”€â”€ The pre-close audit â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
   Rendered from the server's findings. The drawer only presents
   them; it decides nothing, and there is no call from here that
   writes anything. */
function findingHtml(f, sev) {
    const icon = sev === 'blocking' ? 'fa-circle-exclamation' : 'fa-circle-info';
    return '<div class="ah-find" data-sev="' + sev + '">' +
        '<i class="fa-solid ' + icon + '"></i>' +
        '<div class="ah-find-body">' +
            '<p class="ah-find-title">' + esc(f.title) + '</p>' +
            '<p class="ah-find-detail">' + esc(f.detail) + '</p>' +
            '<p class="ah-find-action">' + esc(f.action) + '</p>' +
        '</div>' +
    '</div>';
}

function openAudit() {
    const body = document.getElementById('auditBody');
    const sum = document.getElementById('auditSummary');
    const a = AH_AUDIT;

    sum.textContent = a.summary;

    let html = '';
    if (a.blocking.length) {
        html += '<p class="ah-find-title">Blocking — resolve before closing</p>';
        html += a.blocking.map(f => findingHtml(f, 'blocking')).join('');
    }
    if (a.advisory.length) {
        html += '<p class="ah-find-title">Advisory — worth a look</p>';
        html += a.advisory.map(f => findingHtml(f, 'advisory')).join('');
    }
    if (!a.blocking.length && !a.advisory.length) {
        // A clean term says so plainly. Silence would be read as "the
        // check did not run".
        html = '<div class="ah-empty" style="padding:26px 12px">' +
            '<i class="fa-solid fa-circle-check" style="color:#15803d;opacity:1"></i>' +
            '<h3>Nothing to resolve</h3>' +
            '<p style="margin-bottom:0">Every student in view has a final rating for every subject, '
            + 'and the stored figures agree with them.</p></div>';
    }
    body.innerHTML = html;
    show('auditModal');
}

function closeAudit() { hide('auditModal'); }

// Repaint the preview as the user types, and stop Escape closing the
// wrong dialog if both were somehow open.
document.addEventListener('input', e => {
    if (e.target.closest && e.target.closest('#gradeRows')) updatePreview();
});
document.addEventListener('change', e => {
    if (e.target.closest && e.target.closest('#gradeRows')) updatePreview();
});
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (!document.getElementById('gradeModal').hidden) closeGrades();
    else if (!document.getElementById('auditModal').hidden) closeAudit();
});
</script>

<?php include '../includes/footer.php'; ?>
