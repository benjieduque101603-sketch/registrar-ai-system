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

// ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ Which term? ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
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

// ── Which term ──────────────────────────────────────────────────
// The term is typed, not chosen from a list. A registrar works in
// terms, and "2026-2028 1st" is how they say one out loud; making
// them open a dropdown to find the string they already know was the
// friction.
//
// Typing it means it can be wrong, so the parser is written to be
// forgiving about the shape and strict about the result. "2026-2028",
// "1st", "2026-2028 1st", "1st 2026-2028" and "2026-2028, 1st" all
// land on the same term, because a registrar should not have to
// remember which order the box was in.
//
// An unrecognised year is not silently swapped for the newest one.
// That would show a populated roster for a term nobody asked for,
// which is the one failure a grading screen must not have: the
// registrar would enter real grades against the wrong term. It falls
// back to the newest year and says so.
$SEMESTERS = ['1st', '2nd', 'Summer'];

$termQuery = isset($_GET['term']) ? trim((string) $_GET['term']) : '';
$sy  = '';
$sem = '1st';
$termProblem = '';

if ($termQuery !== '') {
    // Pull the year out by pattern, the semester out by its own name.
    if (preg_match('/(\d{4})\s*[-–—\/]\s*(\d{2,4})/', $termQuery, $m)) {
        $sy = $m[1] . '-' . $m[2];
    }
    foreach ($SEMESTERS as $candidate) {
        // Case-insensitive so "1ST" and "summer" resolve, and matched
        // on a boundary so "1st" cannot be found inside a longer word.
        if (preg_match('/(?<![a-z0-9])' . strtolower($candidate) . '(?![a-z0-9])/i', $termQuery)) {
            $sem = $candidate;
            break;
        }
    }
    if ($sy !== '' && !in_array($sy, $years, true)) {
        $termProblem = 'There are no records for ' . htmlspecialchars($sy)
            . ' yet. Showing the most recent term instead.';
        $sy = '';
    }
    if ($sy === '' && $termProblem === '') {
        $termProblem = 'Could not read a school year from "' . htmlspecialchars($termQuery)
            . '". Try a year like 2026-2028, optionally with 1st, 2nd or Summer.';
    }
}

if ($sy === '' && $years) {
    $sy = $years[0];
}

// The canonical string, so the box always shows a term the page can
// actually resolve rather than echoing back a typo.
$termCanonical = $sy . ' ' . $sem;

// ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ Roster filters ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
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


// ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ What has been recorded for this term? ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
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

// ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ Per-student picture, and the audit over it ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
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

// The screenshot harness stands a sample roster in for the live one.
//
// The live database holds a single student, and one row cannot show a
// broken grid, a ledger with gaps in it, or a term that is half graded
// - which is the whole reason the harness builds a sample at all. The
// branch fires only when tests/ah_shot.php defines the constant; with
// nothing defined this is the database result, unchanged.
//
// The career GWA below is still read from the database, because it is
// computed over every term the filtered students have and a sample
// roster has no history to compute it from. A screenshot therefore shows
// a real career figure above a sample roster. That is a property of the
// harness, not of the page, and it is why these images are for looking
// at layout and not at data.
if (defined('AH_SHOT_ROWS')) {
    $rows           = json_decode(AH_SHOT_ROWS, true);
    $termComplete   = 0;
    $termMissing    = 0;
    $termUnits      = 0.0;
    $rosterForAudit = [];
    foreach ($rows as $r) {
        $r['state'] === 'complete' ? $termComplete++ : $termMissing++;
        $termUnits += (float) $r['units'];
        $rosterForAudit[] = [
            'name'     => $r['name'],
            'number'   => $r['number'],
            'gwa'      => $r['stored'],
            'subjects' => $r['subjects'],
        ];
    }
    $visible = array_map(static fn($r) => ['id' => $r['id']], $rows);
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

/**
 * Two-letter initials from a name, for the student tile.
 *
 * The other registrar pages each carry their own copy of this, so this is
 * not shared with them today. It is defined once here rather than inlined
 * at both use sites on this page, which is the part that matters: the
 * dialog tile and the roster cannot disagree.
 */
function ah_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) {
        return '?';
    }
    $sub = fn($s) => function_exists('mb_substr') ? mb_substr($s, 0, 1, 'UTF-8') : substr($s, 0, 1);
    $up  = fn($s) => function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    $init = $up($sub($parts[0]));
    if (count($parts) > 1) {
        $init .= $up($sub(end($parts)));
    }
    return $init;
}

$payload = json_encode([
    'sy'       => $sy,
    'sem'      => $sem,
    'csrf'     => $csrfToken,
    // Initials are computed here rather than in JS so the dialog tile and
    // the roster use one helper, the same one the other registrar pages
    // call, instead of two implementations of "first and last letter".
    'initials' => array_map(
        static fn($r) => ah_initials($r['name']),
        array_combine(
            array_column($rows, 'id'),
            $rows
        )
    ),
    'rows' => array_map(static fn($r) => [
        'id'       => $r['id'],
        'name'     => $r['name'],
        'number'   => $r['number'],
        'program'  => $r['program'],
        'level'    => $r['level'],
        'section'  => $r['section'],
        'record'   => $r['record'],
        'subjects' => $r['subjects'],
        // The read-only view shows the same figures the roster column
        // shows, so they travel in the payload rather than being
        // recomputed in the browser. A modal that disagreed with the
        // table beside it would be worse than no modal.
        'gwa'      => $r['gwa'],
        'units'    => $r['units'],
        'state'    => $r['state'],
        'missing'  => $r['missing'],
    ], $rows),
], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

$page_title = 'Academic History';
$page_description = 'Record term grades, computed GWA, and check a term before closing it';
$body_page = 'academic';
$APP_ROOT = '../';
// Scoped stylesheet, loaded by includes/header.php. The page itself
// carries no inline CSS, which is what keeps it looking like the rest
// of the portal rather than like a separate application.
$extra_css = ['academic-history.css'];
$ACTIVE_NAV = 'academic';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="dashboard-main">
<div class="dashboard-container">

<header class="ah-header">
    <div>
        <div class="ah-kicker"><i class="fa-solid fa-clipboard-check"></i> Term grading</div>
        <h1>Academic History</h1>
        <p>
            Record final ratings by term. The GWA is computed from the ratings you enter and
            stored with the term, so the transcript, the TOR and the status rules all read the
            same figure.
        </p>
    </div>
    <div class="header-actions">
        <span class="ah-scale-chip">
            <i class="fa-solid fa-arrow-down-wide-short"></i> 1.00 is best, 5.00 is worst
        </span>
        <button class="btn btn-light" type="button" onclick="openAudit()">
            <i class="fa-solid fa-stethoscope"></i> Check before closing
        </button>
    </div>
</header>

<?php
// The term and the search are not a bar of their own. They are the
// roster's own controls, so they live inside the roster panel: the term
// says which term this table is, and the search says which rows of it
// you are looking at. Sitting them between the header and the summary
// made them read as a separate thing, and it is not one.
//
// The term still submits as a GET, so the URL names the term: one
// term's roster can be shared, and Back returns to the previous one.
?>
<?php // "Awaiting" is the actionable figure, so it is the only cell that does
// not sit in the default blue. A mean is only shown once there is
// something to average: the average of nothing is not 0.00, it is
// absent, and the cell says so rather than reporting a number.
//
// Every card keeps its own tone whether or not it currently has something
// to report. Awaiting used to swap its tone for the neutral empty
// treatment, which made it the one card in the row that could go grey
// while the others stayed coloured - and the tone is the whole point of
// the row, because it is what tells you which figure you must act on. An
// empty card now steps back in weight instead of stepping out of colour.
?>
<section class="ah-stats" aria-label="Term summary">
    <div class="ah-stat" data-tone="view">
        <span class="ah-stat-icon"><i class="fa-solid fa-users"></i></span>
        <div>
            <p class="ah-stat-value"><?= count($visible) ?></p>
            <p class="ah-stat-label">In view</p>
            <p class="ah-stat-note"><?= $program !== '' ? htmlspecialchars($program) : 'All programs' ?></p>
        </div>
    </div>
    <div class="ah-stat" data-tone="done"<?= $termComplete === 0 ? ' data-empty="true"' : '' ?>>
        <span class="ah-stat-icon"><i class="fa-solid fa-circle-check"></i></span>
        <div>
            <p class="ah-stat-value"><?= $termComplete ?></p>
            <p class="ah-stat-label">Complete</p>
            <p class="ah-stat-note"><?= $termComplete === 0 ? 'none graded yet' : 'every subject has a final rating' ?></p>
        </div>
    </div>
    <div class="ah-stat" data-tone="wait"<?= $termMissing === 0 ? ' data-empty="true"' : '' ?>>
        <span class="ah-stat-icon"><i class="fa-solid fa-hourglass-half"></i></span>
        <div>
            <p class="ah-stat-value"><?= $termMissing ?></p>
            <p class="ah-stat-label">Awaiting grades</p>
            <p class="ah-stat-note"><?= $termMissing > 0 ? 'not started or incomplete' : 'nothing outstanding' ?></p>
        </div>
    </div>
    <div class="ah-stat" data-tone="mean"<?= $termMeanGwa === null ? ' data-empty="true"' : '' ?>>
        <span class="ah-stat-icon"><i class="fa-solid fa-chart-simple"></i></span>
        <div>
            <p class="ah-stat-value"><?= $termMeanGwa === null ? '&mdash;' : number_format($termMeanGwa, 2) ?></p>
            <p class="ah-stat-label">Mean term GWA</p>
            <p class="ah-stat-note"><?= $termMeanGwa === null ? 'nothing recorded' : 'across recorded terms' ?></p>
        </div>
    </div>
    <div class="ah-stat" data-tone="violet"<?= $career === null ? ' data-empty="true"' : '' ?>>
        <span class="ah-stat-icon"><i class="fa-solid fa-graduation-cap"></i></span>
        <div>
            <p class="ah-stat-value"><?= $career === null ? '&mdash;' : number_format($career, 2) ?></p>
            <p class="ah-stat-label">Career GWA</p>
            <p class="ah-stat-note">
                <?= $career === null
                    ? 'no ratings on file'
                    : 'over ' . rtrim(rtrim(number_format($careerUnits, 0), '0'), '.') . ' units' ?>
            </p>
        </div>
    </div>
</section>

<?php // The roster. The GWA shown is the one the server computed and stored, not a figure typed here. ?>
<section class="ah-panel">
    <form class="ah-panel-controls" method="get" action="academic-history.php" id="termForm">
        <div class="ah-term-field">
            <label for="fltTerm">Term</label>
            <?php // The input and its submit button share one control. The
                  // button is inside the form and inside the field, so
                  // pressing Enter in the box submits, and the grid still
                  // has three cells and one row. See the note on
                  // ah-term-control in the stylesheet. ?>
            <div class="ah-term-control">
                <input
                    class="form-control"
                    type="text"
                    id="fltTerm"
                    name="term"
                    value="<?= htmlspecialchars($termCanonical) ?>"
                    placeholder="2026-2028 1st"
                    autocomplete="off"
                    spellcheck="false"
                    list="termHints"
                    aria-describedby="termHelp"
                >
                <button class="ah-term-submit" type="submit" aria-label="Load this term">
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </button>
            </div>
            <?php // Real terms, as suggestions. Not a dropdown: the box
                  // accepts anything and resolves it, and this only saves
                  // typing the years that already exist. ?>
            <datalist id="termHints">
                <?php foreach ($years as $y): ?>
                    <?php foreach ($SEMESTERS as $s): ?>
                        <option value="<?= htmlspecialchars($y . ' ' . $s) ?>"></option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </datalist>
            <?php // Sighted readers get the placeholder and the
                  // suggestions; this is here so the box is not silent
                  // about what it accepts. Kept out of the flow: a third
                  // line under one cell is what broke the row alignment
                  // the first time round. ?>
            <span class="ah-sr-only" id="termHelp">
                School year and semester, in any order. For example 2026-2028 1st.
            </span>
        </div>

        <div class="ah-term-readout">
            <strong><?= htmlspecialchars(termLabel($sy, $sem)) ?></strong>
            <span id="ahCountReadout"><?= count($visible) ?> student<?= count($visible) === 1 ? '' : 's' ?> in view</span>
        </div>

        <div class="ah-search">
            <label class="ah-search-label" for="rosterSearch">Find in this term</label>
            <div class="ah-search-box">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input
                    type="search"
                    id="rosterSearch"
                    placeholder="Name, number, program, section"
                    autocomplete="off"
                    spellcheck="false"
                >
                <button class="ah-search-clear" type="button" id="ahSearchClear" hidden aria-label="Clear search">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </form>

    <?php if ($termProblem !== ''): ?>
        <p class="ah-term-warning" role="status">
            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
            <?= $termProblem ?>
        </p>
    <?php endif; ?>

    <div class="ah-panel-head">
        <h2>Roster</h2>
        <?php if ($visible): ?>
            <span class="ah-sub">
                <?= $termComplete ?> complete &middot; <?= $termMissing ?> still to grade
            </span>
        <?php endif; ?>
        <div class="spacer"></div>
        <?php if ($visible): ?>
            <span class="ah-sub">GWA is computed from the ratings you enter.</span>
        <?php endif; ?>
    </div>

    <?php if (!$visible): ?>
        <div class="ah-empty">
            <span class="ah-empty-icon"><i class="fa-solid fa-folder-open"></i></span>
            <?php if ($program !== '' || $section !== ''): ?>
                <h3>No students match these filters</h3>
                <p>
                    The program and section together match nobody. Clearing them shows the
                    whole roster.
                </p>
                <a class="btn btn-light" href="academic-history.php?term=<?= urlencode($termCanonical) ?>">
                    <i class="fa-solid fa-filter-circle-xmark"></i> Clear filters
                </a>
            <?php else: ?>
                <h3>No active students to grade</h3>
                <p>Archived students are left out of this page on purpose.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="ah-table-wrap">
            <table class="table">
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
                    // Lower is better on this scale, so the bands run the
                    // opposite way round from a school average.
                    $band = 'none';
                    if ($r['gwa'] !== null) {
                        $band = $r['gwa'] < GWA_AT_RISK - 0.5 ? 'good'
                              : ($r['gwa'] < GWA_AT_RISK ? 'warn' : 'poor');
                    }
                    $stateLabel = [
                        'complete' => 'Complete',
                        'partial'  => $r['missing'] . ' missing',
                        'none'     => 'Not started',
                    ][$r['state']];
                    $initials = ah_initials($r['name']);
                    ?>
                    <tr data-ah-search="<?= htmlspecialchars(strtolower(implode(' ', [
                        $r['name'], $r['number'], $r['program'],
                        $r['level'], $r['section'],
                    ]))) ?>">
                        <td>
                            <div class="ah-name"><?= htmlspecialchars($r['name']) ?></div>
                            <div class="ah-num"><?= htmlspecialchars($r['number']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($r['program'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($r['level'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($r['section'] ?: '—') ?></td>
                        <td class="ah-num"><?= count($r['subjects']) ?: '—' ?></td>
                        <td class="ah-num">
                            <?= $r['units'] > 0
                                ? rtrim(rtrim(number_format($r['units'], 2), '0'), '.')
                                : '—' ?>
                        </td>
                        <td>
                            <span class="ah-gwa" data-band="<?= $band ?>">
                                <?= $r['gwa'] === null ? 'no data' : number_format($r['gwa'], 2) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge ah-state" data-state="<?= $r['state'] ?>"><?= $stateLabel ?></span>
                        </td>
                        <td>
                            <div class="ah-actions">
                                <button class="btn btn-light" type="button" onclick="openView(<?= (int) $r['id'] ?>)">
                                    <i class="fa-solid fa-eye"></i> View
                                </button>
                                <button class="btn btn-light" type="button" onclick="openGrades(<?= (int) $r['id'] ?>)">
                                    <i class="fa-solid fa-pen"></i> Grades
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tbody class="ah-search-empty" id="ahSearchEmpty" hidden>
                    <tr>
                        <td colspan="8">
                            <h3>No student matches that</h3>
                            <p id="ahSearchEmptyText">Check the spelling, or clear the search to see the whole term.</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="ah-panel-foot">
            <?= count($visible) ?> student<?= count($visible) === 1 ? '' : 's' ?> in
            <?= htmlspecialchars(termLabel($sy, $sem)) ?>.
            Open a student's grades to enter final ratings; the GWA updates as you type.
        </div>
    <?php endif; ?>
</section>
</div>
</main>

<?php
// ah_initials() is defined above, next to the payload that uses it.
//
// Grade entry: one student's term. The GWA preview below the grid is
// recomputed as the user types, using the same weighting the server will
// apply, so what they see before saving is what gets stored.
?>
<div class="modal-overlay ah-dialog" id="gradeModal" role="dialog" aria-modal="true" aria-labelledby="gradeModalTitle">
    <div class="modal-content">
        <div class="ah-dialog-head">
            <div>
                <div class="ah-dialog-kicker">Final ratings</div>
                <h3 id="gradeModalTitle">Grade this term</h3>
            </div>
            <button class="modal-close" type="button" onclick="closeGrades()" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="ah-dialog-body">
            <div class="ah-who">
                <span class="ah-who-avatar" id="gradeInitials">—</span>
                <span class="ah-who-copy">
                    <strong id="gradeName">—</strong>
                    <span id="gradeMeta">—</span>
                </span>
                <span class="ah-who-term" id="gradeTerm">—</span>
            </div>

            <p class="ah-key">
                <span>Ratings run <b>1.00 to 5.00</b>, lower is better.</span>
                <span class="ah-key-item"><i class="ah-key-dot" data-band="good"></i> under 2.50</span>
                <span class="ah-key-item"><i class="ah-key-dot" data-band="warn"></i> 2.50 to 2.99</span>
                <span class="ah-key-item"><i class="ah-key-dot" data-band="poor"></i> 3.00 and above</span>
            </p>

            <table class="ah-grid">
                <thead>
                    <tr>
                        <th scope="col">Subject</th>
                        <th scope="col" class="ah-col-units">Units</th>
                        <th scope="col" class="ah-col-rating">Final rating</th>
                        <th scope="col" class="ah-col-result">Result</th>
                        <th scope="col" class="ah-col-drop"><span class="ah-sr">Remove</span></th>
                    </tr>
                </thead>
                <tbody id="gradeRows"></tbody>
            </table>
        </div>

        <div class="ah-dialog-foot">
            <button class="btn btn-light" type="button" onclick="addGradeRow()">
                <i class="fa-solid fa-plus"></i> Add subject
            </button>
            <div class="spacer"></div>
            <span class="ah-save-status" id="gradeGwaPreview"></span>
            <button class="btn btn-primary" type="button" id="btnSaveGrades" onclick="saveGrades()">
                <i class="fa-solid fa-floppy-disk"></i> Save term
            </button>
        </div>
    </div>
</div>

<?php
// The read-only view of the same term. It exists because "look at what
// is recorded" and "change what is recorded" are different jobs, and
// sharing one dialog makes the first one feel like a draft.
//
// It is read-only by construction, not by convention: there is not a
// single input in here. Nothing to save, no Add subject, no Remove,
// and the only control is Close. A registrar can open a term to check
// it without touching it, which is what checking a closed term means.
//
// It reads from the same payload the roster and the editor do, so the
// figures here are the stored ones, not a second computation.
?>
<div class="modal-overlay ah-dialog" id="viewModal" role="dialog" aria-modal="true" aria-labelledby="viewModalTitle">
    <div class="modal-content">
        <div class="ah-dialog-head">
            <div>
                <div class="ah-dialog-kicker">Recorded</div>
                <h3 id="viewModalTitle" tabindex="-1">Grade record</h3>
            </div>
            <button class="modal-close" type="button" onclick="closeView()" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="ah-dialog-body">
            <div class="ah-who">
                <span class="ah-who-avatar" id="viewInitials">—</span>
                <span class="ah-who-copy">
                    <strong id="viewName">—</strong>
                    <span id="viewMeta">—</span>
                </span>
                <span class="ah-who-term" id="viewTerm">—</span>
            </div>

            <table class="ah-view-grid">
                <thead>
                    <tr>
                        <th scope="col">Subject</th>
                        <th scope="col" class="ah-col-units">Units</th>
                        <th scope="col" class="ah-col-rating">Final rating</th>
                        <th scope="col" class="ah-col-result">Result</th>
                    </tr>
                </thead>
                <tbody id="viewRows"></tbody>
            </table>
        </div>

        <div class="ah-dialog-foot">
            <div class="spacer"></div>
            <button class="btn btn-light" type="button" onclick="closeView()">Close</button>
        </div>
    </div>
</div>

<?php
// The pre-close audit. Read-only: it reports what is missing or
// inconsistent and stops there. It assigns no grade, changes no status,
// and writes nothing. The GWA-at-3.00 finding is a question about the
// status rules, not a decision about this student.
?>
<div class="modal-overlay ah-dialog" id="auditModal" role="dialog" aria-modal="true" aria-labelledby="auditModalTitle">
    <div class="modal-content">
        <div class="ah-dialog-head">
            <div>
                <div class="ah-dialog-kicker">Before you close</div>
                <h3 id="auditModalTitle" tabindex="-1">Check this term</h3>
            </div>
            <button class="modal-close" type="button" onclick="closeAudit()" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="ah-dialog-body" id="auditBody"></div>
    </div>
</div>
<script>
'use strict';
// Server-computed figures. termGwa() in shared/term_grades.php is the
// reference; the preview below re-implements only the arithmetic so it can
// run on every keystroke, and tests/term_gwa_parity.js pins the two
// against the same cases. If one changes, the other has to.
const AH = <?= $payload ?>;

// The audit is computed server-side and rendered here. Findings arrive as
// data, not as prose written in JS, so the drawer and the save-time
// validation cannot drift apart.
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

// ── Search within the term ──────────────────────────────────────
// Program and section used to be dropdowns that reloaded the page on
// every change. Searching the roster in place is faster for the way
// this screen is actually used: a registrar is looking for one
// student in a term they are already on, not browsing a taxonomy.
//
// All the words are OR'd rather than AND'd, so "bsit a" finds every
// BSIT student in section A. AND-ing them is the behaviour people
// expect from a file dialog and never what they expect from a name
// box, where "mendoza" alone has to work.
(function () {
    const input    = document.getElementById('rosterSearch');
    const clearBtn = document.getElementById('ahSearchClear');
    const empty    = document.getElementById('ahSearchEmpty');
    const readout  = document.getElementById('ahCountReadout');
    const table    = document.querySelector('.ah-panel .table');
    if (!input || !table) return;

    const rows = Array.from(table.querySelectorAll('tbody tr[data-ah-search]'));
    const total = rows.length;

    function apply() {
        const terms = input.value.toLowerCase().split(/\s+/).filter(Boolean);
        let shown = 0;
        rows.forEach(row => {
            // The haystack is precomputed server-side, so this stays
            // off the row's own text - which would break the moment a
            // cell contained markup.
            const hay = row.dataset.ahSearch || '';
            const hit = terms.length === 0 || terms.some(t => hay.includes(t));
            row.hidden = !hit;
            if (hit) shown++;
        });

        if (empty) {
            empty.hidden = shown !== 0;
            const text = document.getElementById('ahSearchEmptyText');
            if (text && terms.length) {
                // Name what was looked for. "No results" alone leaves the
                // reader guessing whether the term is empty or the search
                // is wrong, and those need opposite responses.
                text.textContent = 'No student in this term matches “'
                    + input.value.trim() + '”. Clear the search to see all '
                    + total + '.';
            }
        }
        if (readout) {
            readout.textContent = shown === total
                ? total + (total === 1 ? ' student' : ' students') + ' in view'
                : shown + ' of ' + total + ' in view';
        }
        if (clearBtn) clearBtn.hidden = input.value === '';
    }

    input.addEventListener('input', apply);
    input.addEventListener('search', apply);
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            input.value = '';
            apply();
            input.focus();
        });
    }
    // The term box is a form control, so Enter navigates. The search
    // box filters what is already on screen, so Enter there should
    // hand the caret to the first match rather than reload the page.
    input.addEventListener('keydown', e => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const first = rows.find(r => !r.hidden);
        if (!first) return;
        const btn = first.querySelector('button');
        if (btn) btn.focus();
    });
    apply();
})();

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

/* The 1.00-5.00 bands. Lower is better, so the tints read the same way
   round here as they do in the roster column and the stat strip. */
function band(r) {
    if (!isFinite(r)) return null;
    if (r < 2.5) return 'good';
    if (r < 3.0) return 'warn';
    return 'poor';
}

// The shared overlay in registrar.css is display:none and toggled with
// .active, so the closed state is the absence of a class.
//
// Focus is handled here rather than at each call site, because the three
// requirements the WAI-ARIA dialog pattern puts on a modal are properties
// of "a modal is open", not of any one dialog. The page was carrying
// aria-modal="true" on dialogs that did not honour any of them, which is
// the specific combination the pattern warns about: assistive technology
// is told the rest of the page is inert, and the keyboard disagrees.
//
// js/confirm.js already returns focus the same way. Two dialogs that
// behave differently from the third is how this drifted.
let lastDialogFocus = null;

function dialogFocusables(dialog) {
    return Array.from(dialog.querySelectorAll(
        'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), ' +
        'textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter(el => el.offsetParent !== null || el === document.activeElement);
}

// Where focus lands depends on what the dialog is for, so the caller says
// rather than the helper guessing. A form wants its first field; a report
// wants a static element it can announce, because focusing its close
// button tells a screen reader nothing about what the dialog contains.
function openDialog(id, initialFocus) {
    const el = document.getElementById(id);
    if (!el) return;
    lastDialogFocus = document.activeElement;
    el.classList.add('active');
    document.body.style.overflow = 'hidden';

    const target = initialFocus === true
        ? dialogFocusables(el)[0]
        : (typeof initialFocus === 'string' ? el.querySelector(initialFocus) : null);
    if (target) target.focus();
}

function closeDialog(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('active');
    document.body.style.overflow = '';
    // Return focus to whatever opened the dialog, so a keyboard user is
    // not dropped at the top of the document and has to re-navigate the
    // roster. The element is re-checked because the roster is re-rendered
    // by the reload that follows a save.
    if (lastDialogFocus && document.contains(lastDialogFocus)) lastDialogFocus.focus();
    lastDialogFocus = null;
}

// Tab must not walk out of an open dialog. A modal is declared inert
// outside itself, so letting focus escape to the filters behind the
// scrim contradicts the declaration and strands a keyboard user in a part
// of the page they cannot see.
document.addEventListener('keydown', e => {
    if (e.key !== 'Tab') return;
    const open = document.querySelector('.modal-overlay.active');
    if (!open) return;
    const items = dialogFocusables(open);
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
});

function openGrades(studentId) {
    const r = rowById(studentId);
    if (!r) return;
    gradeStudent = r;

    document.getElementById('gradeInitials').textContent = AH.initials[studentId] || '—';
    document.getElementById('gradeName').textContent = r.name;
    document.getElementById('gradeMeta').textContent =
        r.number + (r.program ? ' · ' + r.program : '') + (r.section ? ' · Section ' + r.section : '');
    document.getElementById('gradeTerm').textContent = AH.sem + ' · ' + AH.sy;

    const body = document.getElementById('gradeRows');
    body.innerHTML = '';
    if (!r.subjects.length) {
        // One blank row rather than an empty table. A term nobody has
        // started is the state this page exists to move away from, and an
        // empty grid offers nowhere to type.
        body.insertAdjacentHTML('beforeend', gradeRowHtml({subject:'', units:'', final_rating:'', grade_status:''}));
    } else {
        r.subjects.forEach(s => body.insertAdjacentHTML('beforeend', gradeRowHtml(s)));
    }
    updatePreview();
    // The grid is filled before the dialog opens, so the helper can find
    // the first field itself. Focus is the helper's job now, not this
    // function's: the pattern's requirement belongs to "a modal is open".
    openDialog('gradeModal', '#gradeRows input');
}

function closeGrades() { closeDialog('gradeModal'); }

// ── Read-only view ──────────────────────────────────────────────
// Opens the same term the editor opens, with no way to change it. The
// figures come from AH.rows, which is the payload the roster table and
// the editor both read, so this cannot show a different term or a
// different GWA than the row you clicked.
function ratingBand(v) {
    // Mirrors the roster's banding: GWA_AT_RISK is 3.00 and the good
    // band stops half a point under it. term_js_check.js pins these
    // numbers against the server's, because a rating that changes
    // colour but not meaning is worse than one that never changes.
    if (v === null || v === undefined || v === '') return 'none';
    const n = Number(v);
    if (!isFinite(n)) return 'none';
    return n < 2.5 ? 'good' : (n < 3 ? 'warn' : 'poor');
}

function openView(studentId) {
    const r = rowById(studentId);
    if (!r) return;

    document.getElementById('viewInitials').textContent = AH.initials[studentId] || '—';
    document.getElementById('viewName').textContent = r.name;
    document.getElementById('viewMeta').textContent =
        r.number + (r.program ? ' · ' + r.program : '') + (r.section ? ' · Section ' + r.section : '');
    document.getElementById('viewTerm').textContent = AH.sem + ' · ' + AH.sy;

    const body = document.getElementById('viewRows');
    body.innerHTML = '';

    if (!r.subjects.length) {
        // Say so rather than showing an empty ruled table. A term with
        // no subjects is a real state and it is the reason this row is
        // in the roster at all.
        body.insertAdjacentHTML('beforeend',
            '<tr><td colspan="4" class="ah-view-blank">' +
            'No subjects recorded for this term yet.' +
            '</td></tr>');
    } else {
        r.subjects.forEach(s => body.insertAdjacentHTML('beforeend', viewRowHtml(s)));
        // The transcript's bottom line. A ledger has to end in a total,
        // otherwise the reader is left adding the column up by hand.
        const shown = r.units === null || r.units === undefined
            ? ''
            : ' · ' + String(r.units).replace(/\.00$/, '') + ' units';
        body.insertAdjacentHTML('beforeend',
            '<tr class="ah-view-total"><td>' + r.subjects.length + ' subject' +
            (r.subjects.length === 1 ? '' : 's') + shown + '</td>' +
            '<td class="ah-col-units"></td>' +
            '<td class="ah-col-rating"><span class="ah-gwa" data-band="' +
            ratingBand(r.gwa) + '">' +
            (r.gwa === null || r.gwa === undefined ? 'no data' : Number(r.gwa).toFixed(2)) +
            '</span></td>' +
            '<td class="ah-col-result"><span class="badge ah-state" data-state="' +
            esc(r.state) + '">' + esc(viewStateLabel(r)) + '</span></td></tr>');
    }

    // The title, not a control: this dialog has nothing to fill in, and
    // focusing its Close button tells a screen reader nothing about what
    // it contains. Same reasoning as the audit drawer.
    openDialog('viewModal', '#viewModalTitle');
}

function viewStateLabel(r) {
    if (r.state === 'complete') return 'Complete';
    if (r.state === 'partial') return r.missing + ' missing';
    return 'Not started';
}

function viewRowHtml(s) {
    const rated = s.final_rating !== null && s.final_rating !== undefined && s.final_rating !== '';
    const result = s.grade_status || '';
    // An unrated subject says "not rated" in words. A blank cell in a
    // ledger is read as "nothing to see here", which is the opposite of
    // what an empty final rating means.
    // A ledger column of ratings has to line up, so every figure is
    // written to two decimals. The stored value is 1.5, 2 and 1.75
    // depending on who typed it, and "1.5" sitting next to "1.75" in
    // the same column reads as two different precisions rather than
    // two different grades.
    const rating = rated
        ? '<span class="ah-view-rating" data-band="' + ratingBand(s.final_rating) + '">' +
            Number(s.final_rating).toFixed(2) + '</span>'
        : '<span class="ah-view-rating" data-band="none">not rated</span>';
    return '<tr>' +
        '<td>' + (esc(s.subject) || '<span class="ah-view-blank-inline">Untitled subject</span>') +
            (s.subject_code ? ' <span class="ah-view-code">' + esc(s.subject_code) + '</span>' : '') +
        '</td>' +
        '<td class="ah-col-units ah-num">' + (Number(s.units) > 0 ? esc(s.units) : '—') + '</td>' +
        '<td class="ah-col-rating">' + rating + '</td>' +
        '<td class="ah-col-result">' + (result
            ? '<span class="ah-view-result" data-result="' + esc(result) + '">' + esc(result) + '</span>'
            : '<span class="ah-view-blank-inline">—</span>') + '</td>' +
        '</tr>';
}

function closeView() { closeDialog('viewModal'); }

function gradeRowHtml(s) {
    const st = s.grade_status || '';
    const result = ['passed', 'failed', 'dropped', ''].map(o =>
        '<option value="' + o + '"' + (st === o ? ' selected' : '') + '>' +
        (o === '' ? 'Not set' : o.charAt(0).toUpperCase() + o.slice(1)) + '</option>').join('');
    return '<tr>' +
        '<td><input class="form-control" type="text" data-f="subject" value="' + esc(s.subject) + '" placeholder="Subject name"></td>' +
        '<td class="ah-col-units"><input class="form-control ah-in-num" type="number" data-f="units" min="0" max="12" step="0.5" value="' + esc(s.units) + '"></td>' +
        '<td class="ah-col-rating"><input class="form-control ah-in-num" type="number" data-f="final_rating" min="1" max="5" step="0.01" value="' +
            esc(s.final_rating === null ? '' : s.final_rating) + '"></td>' +
        '<td class="ah-col-result"><select class="form-control" data-f="grade_status">' + result + '</select></td>' +
        '<td class="ah-col-drop"><button class="ah-drop" type="button" onclick="removeGradeRow(this)" aria-label="Remove subject">' +
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
            // reason travels with the field the user is looking at.
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
   from here: it is computed server-side from the same ratings, so there is
   no value on the wire for a stale client to overwrite. */
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
            // saved row, and a partial client-side update would leave them
            // disagreeing until the next refresh.
            setTimeout(() => window.location.reload(), 700);
            return;
        }

        btn.disabled = false;
        out.dataset.tone = 'bad';
        const problems = Array.isArray(data.problems) ? data.problems : [];
        out.textContent = problems.length
            ? data.message + ' ' + problems.join(' ')
            : (data.message || 'The term was not saved.');
    } catch (err) {
        btn.disabled = false;
        out.dataset.tone = 'bad';
        out.textContent = 'The term was not saved. The server did not respond; check the connection and try again.';
    }
}

/* The pre-close audit. Rendered from the server's findings. The drawer
   only presents them; it decides nothing, and nothing in this path
   writes a record. */
function findingHtml(f, sev) {
    const icon = sev === 'blocking' ? 'fa-circle-exclamation' : 'fa-circle-info';
    return '<div class="ah-finding" data-sev="' + sev + '">' +
        '<i class="fa-solid ' + icon + '"></i>' +
        '<div class="ah-finding-body">' +
            '<p class="ah-finding-title">' + esc(f.title) + '</p>' +
            '<p class="ah-finding-detail">' + esc(f.detail) + '</p>' +
            '<p class="ah-finding-action">' + esc(f.action) + '</p>' +
        '</div>' +
    '</div>';
}

function openAudit() {
    const body = document.getElementById('auditBody');
    const a = AH_AUDIT;

    // What the check is and is not, stated on the surface rather than in
    // a tooltip. Someone about to close a term should not have to guess
    // whether this thing is allowed to act.
    let html =
        '<p class="ah-audit-note">' +
            '<i class="fa-solid fa-circle-info"></i>' +
            '<span>This check reads the records and reports. It assigns no grade, changes no ' +
            'status, and saves nothing. A finding about a GWA at 3.00 or above is a prompt to ' +
            'look; any status decision stays with you, on the status page.</span>' +
        '</p>' +
        '<p class="ah-audit-lead">' + esc(a.summary) + ' <strong>' +
        a.stats.students + '</strong> student' + (a.stats.students === 1 ? '' : 's') + ' in view, ' +
        '<strong>' + a.stats.with_grades + '</strong> with grades recorded.</p>';

    if (a.blocking.length) {
        html += '<p class="ah-findings-head" data-sev="blocking">' +
            '<i class="fa-solid fa-circle-exclamation"></i> Resolve before closing' +
            '<span class="ah-findings-count">' + a.blocking.length + '</span></p>';
        html += a.blocking.map(f => findingHtml(f, 'blocking')).join('');
    }
    if (a.advisory.length) {
        html += '<p class="ah-findings-head" data-sev="advisory">' +
            '<i class="fa-solid fa-circle-info"></i> Worth a look' +
            '<span class="ah-findings-count">' + a.advisory.length + '</span></p>';
        html += a.advisory.map(f => findingHtml(f, 'advisory')).join('');
    }
    if (!a.blocking.length && !a.advisory.length) {
        // A clean term says so plainly. Silence would read as "the check
        // did not run", which is the one conclusion this drawer must not
        // leave someone with.
        html += '<div class="ah-empty">' +
            '<i class="fa-solid fa-circle-check ah-clear-icon"></i>' +
            '<h3>Nothing to resolve</h3>' +
            '<p style="margin-bottom:0">Every student in view has a final rating for every ' +
            'subject, and the stored figures agree with them.</p></div>';
    }
    body.innerHTML = html;
    // A report, not a form. Focus goes to the heading rather than the
    // close button: the drawer is built from findings and paragraphs that
    // need to be read in order, and a focusable heading lets a screen
    // reader start at the top and walk out from there. tabindex="-1"
    // makes it programmatically focusable without adding it to the tab
    // sequence, and the pattern asks that aria-describedby be left off
    // when the content is structured this way.
    openDialog('auditModal', '#auditModalTitle');
}

function closeAudit() { closeDialog('auditModal'); }

// Repaint the preview as the user types. A change event is also needed:
// the result dropdown does not fire input, and picking a status has to
// update the count in the foot bar.
document.addEventListener('input', e => {
    if (e.target.closest && e.target.closest('#gradeRows')) updatePreview();
});
document.addEventListener('change', e => {
    if (e.target.closest && e.target.closest('#gradeRows')) updatePreview();
});

// Escape closes the topmost dialog, and only that one.
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (document.getElementById('gradeModal').classList.contains('active')) closeGrades();
    else if (document.getElementById('auditModal').classList.contains('active')) closeAudit();
});
</script>

<?php include '../includes/footer.php'; ?>
