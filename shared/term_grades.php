<?php
// ============================================================
//  SHARED/TERM_GRADES.PHP
//  One place that computes a GWA, and one place that audits a term.
//
//  Two problems this file exists to remove.
//
//  1. There were two GWA numbers. academic_history.gwa was typed by hand;
//     document_templates.php computed its own weighted average from
//     final_rating x units and never read the stored one. A student typed
//     at 2.50 and computing to 2.91 looked fine to the status rules and
//     different on the TOR. termGwa() is the only implementation, and the
//     save path stores its result, so the two can no longer disagree - the
//     status rules read the same number the TOR prints.
//
//  2. Nobody could see a term was incomplete before closing it. The
//     commonest failure in term grading is a missing rating, and it is
//     silent: a student with no grade looks exactly like a student with a
//     good one. termAudit() reports what is missing while it is still
//     editable.
//
//  Read-only. It computes and it reports; it never writes.
// ============================================================

/** The rating scale: 1.00 (best) to 5.00 (worst), lower is better. */
const GRADE_SCALE_MIN = 1.0;
const GRADE_SCALE_MAX = 5.0;

/** A GWA at or above this is treated as a concern by the status rules. */
const GWA_AT_RISK = 3.0;

/**
 * The office these term records belong to.
 *
 * academic_history.school_name is NOT NULL, and the TOR prints it, so it
 * cannot simply be dropped. It is also the reason this page was wrong: a
 * free-text school name let a term row be filed against a previous school,
 * which is what "Previous schools" was describing. The registrar manages
 * one school's terms, so the value is fixed here rather than asked for on
 * every save.
 */
if (!defined('TERM_SCHOOL_NAME')) {
    define('TERM_SCHOOL_NAME', 'Bestlink College of the Philippines');
}

/**
 * True when a rating is a usable number on the 1.0-5.0 scale.
 * Anything outside is a data-entry fault, not a grade.
 */
function termRatingValid($rating): bool
{
    if ($rating === null || $rating === '') {
        return false;
    }
    if (!is_numeric($rating)) {
        return false;
    }
    $r = (float) $rating;
    return $r >= GRADE_SCALE_MIN && $r <= GRADE_SCALE_MAX;
}

/**
 * Weighted GWA for one term, from its subject rows.
 *
 * Only subjects with a usable rating AND a positive unit count can
 * contribute. A subject with no rating is missing data, not a zero, and
 * averaging it in would drag every GWA down; a subject with no units
 * cannot move an average. Both are excluded rather than treated as zero.
 *
 * @param  array $subjects Rows with 'units' and 'final_rating'.
 * @return float|null      Null when nothing can be averaged, so callers
 *                         print "no data" rather than a fabricated 0.00.
 */
function termGwa(array $subjects): ?float
{
    $weighted = 0.0;
    $units    = 0.0;
    foreach ($subjects as $s) {
        $rating = $s['final_rating'] ?? null;
        $u      = (float) ($s['units'] ?? 0);
        if (!termRatingValid($rating) || $u <= 0) {
            continue;
        }
        $weighted += (float) $rating * $u;
        $units    += $u;
    }
    return $units > 0 ? round($weighted / $units, 2) : null;
}

/**
 * Career GWA across every term, weighting by units. The audit compares
 * against this so a person who assumed a figure can see the real one.
 */
function careerGwa(array $terms): ?float
{
    $weighted = 0.0;
    $units    = 0.0;
    foreach ($terms as $t) {
        foreach (($t['subjects'] ?? []) as $s) {
            $rating = $s['final_rating'] ?? null;
            $u      = (float) ($s['units'] ?? 0);
            if (!termRatingValid($rating) || $u <= 0) {
                continue;
            }
            $weighted += (float) $rating * $u;
            $units    += $u;
        }
    }
    return $units > 0 ? round($weighted / $units, 2) : null;
}

/** Human label for a term, e.g. "1st · 2026-2027". */
function termLabel($schoolYear, $semester): string
{
    $parts = array_filter(
        [trim((string) $semester), trim((string) $schoolYear)],
        static fn($p) => $p !== ''
    );
    return $parts ? implode(' · ', $parts) : 'Unspecified term';
}

/**
 * Sort key so terms order correctly as text: 2025-2026 before 2026-2027,
 * and 1st before 2nd within a year. The two are keyed separately and
 * joined, because a plain string comparison of "1st" against a year gets
 * the ordering right only by accident.
 */
function termSortKey($schoolYear, $semester): string
{
    $order = [
        '1st' => '1', 'first' => '1', '1' => '1',
        '2nd' => '2', 'second' => '2', '2' => '2',
        '3rd' => '3', 'third' => '3', '3' => '3',
        'summer' => '4', 'midterm' => '4',
    ];
    $se   = strtolower(trim((string) $semester));
    $rank = $order[$se] ?? '9';
    $sy   = trim((string) $schoolYear);
    // Pull the leading year out of forms like "2026-2027" or "SY 2026-2027".
    if (preg_match('/(\d{4})/', $sy, $m)) {
        $sy = $m[1];
    }
    return str_pad($sy, 6, '0') . '-' . $rank;
}

/**
 * The distinct terms a student actually has records for, newest first.
 *
 * Built from that student's own academic_history rows, so the picker can
 * only ever offer a term that exists for them. A hardcoded list of years
 * would show a student four empty years and make the empty state the
 * commonest thing on the page.
 *
 * Year level is deliberately NOT part of this. academic_history records
 * a term as school year + semester; a student's year level is a single
 * current value on the students row, not a per-term fact, so a year
 * level filter would have nothing truthful to filter on.
 *
 * @param  array $historyRows Rows carrying 'school_year' and 'semester'.
 * @return array<int,array{sy:string,sem:string,label:string}>
 */
function studentTermOptions(array $historyRows): array
{
    $seen = [];
    foreach ($historyRows as $r) {
        $sy  = trim((string) ($r['school_year'] ?? ''));
        $sem = trim((string) ($r['semester'] ?? ''));
        if ($sy === '' && $sem === '') {
            continue;
        }
        $seen[$sy . "\x1F" . $sem] = ['sy' => $sy, 'sem' => $sem];
    }

    $options = array_values($seen);
    usort($options, static function ($a, $b) {
        return strcmp(
            termSortKey($b['sy'], $b['sem']),
            termSortKey($a['sy'], $a['sem'])
        );
    });

    foreach ($options as &$o) {
        $o['label'] = termLabel($o['sy'], $o['sem']);
    }
    unset($o);

    return $options;
}

/**
 * Resolve a school-year / semester choice against the options a student
 * really has.
 *
 * An unrecognised or absent choice falls back to the most recent term
 * rather than showing an empty page: a stale bookmark should land the
 * student on their newest results, which is what they meant.
 *
 * @return array{sy:string,sem:string,label:string,adjusted:bool}
 */
function resolveStudentTerm(array $options, ?string $sy, ?string $sem): array
{
    $fallback = static function () use ($options): array {
        $o = $options[0] ?? ['sy' => '', 'sem' => ''];
        return [
            'sy'       => $o['sy'],
            'sem'      => $o['sem'],
            'label'    => $o['sy'] !== '' || $o['sem'] !== ''
                ? termLabel($o['sy'], $o['sem'])
                : 'No term selected',
            'adjusted' => false,
        ];
    };

    if (!$options) {
        return ['sy' => '', 'sem' => '', 'label' => 'No term selected', 'adjusted' => false];
    }

    $sy  = trim((string) $sy);
    $sem = trim((string) $sem);
    if ($sy === '' && $sem === '') {
        return $fallback();
    }

    // Match the semester loosely - a page may hand back "Summer" where the
    // row says "summer" - but keep the school year exact.
    foreach ($options as $o) {
        $semOk = $sem === '' || strcasecmp($o['sem'], $sem) === 0;
        $syOk  = $sy === '' || $o['sy'] === $sy;
        if ($semOk && $syOk) {
            return [
                'sy'       => $o['sy'],
                'sem'      => $o['sem'],
                'label'    => $o['label'],
                'adjusted' => false,
            ];
        }
    }

    $r = $fallback();
    $r['adjusted'] = true;
    return $r;
}

/**
 * Pre-flight audit of one term, run before it is closed.
 *
 * Two severities, and the split is deliberate:
 *
 *   blocking  - the term should not be closed. A missing rating is not a
 *               formatting issue, it is a transcript with a hole in it,
 *               and it is silent without this.
 *   advisory  - a fact worth looking at. Reported, never acted on. A term
 *               GWA crossing 3.00 is the line the status rules read
 *               (GWA_AT_RISK), so the check says a status "may" need
 *               review. It does not change one. That is a registrar's
 *               decision and it is made on the status page.
 *
 * @param  string $sy       School year being closed.
 * @param  string $sem      Semester being closed.
 * @param  array  $students Roster rows: name, number, gwa, and
 *                           'subjects' => [subject rows].
 * @param  array  $opts     ['min_units' => float] expected units per term.
 * @return array            ['blocking', 'advisory', 'summary', 'stats']
 */
function termAudit(string $sy, string $sem, array $students, array $opts = []): array
{
    $blocking = [];
    $advisory = [];
    $minUnits = isset($opts['min_units']) ? (float) $opts['min_units'] : 0.0;
    $withGrades = 0;
    $totalUnits = 0.0;

    foreach ($students as $st) {
        $name     = trim((string) ($st['name'] ?? 'Student'));
        $num      = trim((string) ($st['number'] ?? ''));
        $who      = $num !== '' ? "$name ($num)" : $name;
        $subjects = $st['subjects'] ?? [];

        if (!$subjects) {
            // No subjects at all is a different fault from a subject with
            // no rating: nothing was ever entered, rather than something
            // left blank, and it needs a different response.
            $blocking[] = [
                'code' => 'no_subjects', 'title' => 'Nothing entered',
                'detail' => "$who has no subjects recorded for this term.",
                'action' => 'Add the subject list, or confirm the student was not enrolled this term.',
            ];
            continue;
        }

        $withGrades++;
        $stuUnits         = 0.0;
        $missing          = [];
        $badScale         = [];
        $passedNoRating   = [];
        $failedWithRating = [];

        foreach ($subjects as $s) {
            $label  = trim((string) ($s['subject'] ?? 'subject'));
            $u      = (float) ($s['units'] ?? 0);
            $rating = $s['final_rating'] ?? null;
            $status = strtolower(trim((string) ($s['grade_status'] ?? '')));

            if ($u > 0) {
                $stuUnits += $u;
            }
            if ($rating === null || $rating === '') {
                $missing[] = $label;
                // Passed but carrying no rating is worse than a blank: the
                // record claims an outcome it cannot evidence, and the
                // weighted average silently omits it.
                if ($status === 'passed') {
                    $passedNoRating[] = $label;
                }
                continue;
            }
            if (!termRatingValid($rating)) {
                $badScale[] = "$label ($rating)";
            }
            if ($status === 'failed' || $status === 'dropped') {
                $failedWithRating[] = $label;
            }
        }

        $totalUnits += $stuUnits;

        if ($missing) {
            $blocking[] = [
                'code' => 'missing_rating', 'title' => 'No final rating',
                'detail' => $who . ' — ' . implode(', ', $missing),
                'action' => 'Check whether the subject was dropped or the grade sheet has not been received yet.',
            ];
        }
        if ($badScale) {
            $blocking[] = [
                'code' => 'rating_out_of_scale', 'title' => 'Rating outside 1.00-5.00',
                'detail' => $who . ' — ' . implode(', ', $badScale),
                'action' => 'Correct the entry. A value outside the scale cannot be averaged.',
            ];
        }
        if ($passedNoRating) {
            $blocking[] = [
                'code' => 'passed_without_rating', 'title' => 'Marked passed with no rating',
                'detail' => $who . ' — ' . implode(', ', $passedNoRating),
                'action' => 'The record claims an outcome it cannot evidence. Enter the rating or change the status.',
            ];
        }
        if ($failedWithRating) {
            $advisory[] = [
                'code' => 'failed_with_rating', 'title' => 'Failed subject carries a rating',
                'detail' => $who . ' — ' . implode(', ', $failedWithRating),
                'action' => 'Normal if the rating is a real final mark. Confirm it is not the mid-term copied over.',
            ];
        }

        // The stored GWA is written by the save path from this same
        // computation, so a mismatch means the row was written by
        // something else - an import, or an edit made before this existed.
        // It blocks because the TOR prints one figure and the status rules
        // read the other.
        $gwa = termGwa($subjects);
        if ($gwa !== null && isset($st['gwa']) && $st['gwa'] !== null && $st['gwa'] !== '') {
            if (round(abs((float) $st['gwa'] - $gwa), 2) >= 0.05) {
                $blocking[] = [
                    'code' => 'gwa_mismatch', 'title' => 'Stored GWA does not match the subjects',
                    'detail' => $who . ' — record says ' . number_format((float) $st['gwa'], 2)
                               . ', subjects compute to ' . number_format($gwa, 2) . '.',
                    'action' => 'Re-save the term so the stored figure is recomputed from the ratings.',
                ];
            }
        }

        if ($gwa !== null && $gwa >= GWA_AT_RISK) {
            $advisory[] = [
                'code' => 'gwa_at_risk', 'title' => 'Term GWA at or above 3.00',
                'detail' => $who . ' — ' . number_format($gwa, 2) . ' this term.',
                'action' => 'This is the line the status rules read. The status may need review on the status page.',
            ];
        }

        if ($minUnits > 0 && $stuUnits > 0 && $stuUnits < $minUnits) {
            $advisory[] = [
                'code' => 'units_below_expected', 'title' => 'Units below the expected load',
                'detail' => $who . ' — ' . rtrim(rtrim(number_format($stuUnits, 2), '0'), '.') . ' units.',
                'action' => 'Confirm the term is complete and nothing was dropped from the list.',
            ];
        }
    }

    $summary = $withGrades === 0
        ? 'No grades have been entered for this term yet.'
        : ($blocking === []
            ? $withGrades . ' students complete, nothing blocking.'
            : count($blocking) . ' issue' . (count($blocking) === 1 ? '' : 's')
              . ' to resolve across ' . $withGrades . ' students.');

    return [
        'blocking' => $blocking,
        'advisory' => $advisory,
        'summary'  => $summary,
        'stats'    => [
            'students'    => count($students),
            'with_grades' => $withGrades,
            'total_units' => round($totalUnits, 2),
        ],
    ];
}
