<?php
// ============================================================
//  SHARED/SECTION_CODE.PHP
//  Pure section-code derivation, kept free of database
//  dependencies so it can be unit tested in isolation.
//
//  A section code encodes the year level:
//    [year digit][semester digit][### section number]
//  e.g. 11001 = year 1, 1st semester, section 1.
// ============================================================

// ── Prevent direct access ──
if (defined('SECTION_CODE_LOADED')) {
    return;
}
define('SECTION_CODE_LOADED', true);

/**
 * Semester digit for a semester label ('' → 1, '1st' → 1, '2nd' → 2, 'summer' → 3).
 */
function semesterDigit(?string $semester): int {
    $semester = strtolower(trim((string) $semester));
    if ($semester === '2nd') return 2;
    if ($semester === 'summer') return 3;
    return 1; // '' or '1st'
}

/**
 * Section code for a year/semester/section-number combo, e.g. 11001 =
 * year 1, semester 1, section 1. Format: [year][sem][###] (5 digits).
 *
 * A year level is REQUIRED: without one there is no meaningful section, and
 * silently coercing a missing year to 1 would stamp Year-1 section codes onto
 * students who have no year level at all.
 *
 * @throws InvalidArgumentException if the year level is outside 1-9.
 */
function sectionCodeFromParts(int $year, ?string $semester, int $sectionNumber): string {
    if ($year < 1 || $year > 9) {
        throw new InvalidArgumentException('Year level is required to build a section code (got ' . $year . ').');
    }
    $semDigit = semesterDigit($semester);
    $num = max(1, min(999, $sectionNumber));
    return $year . $semDigit . str_pad((string) $num, 3, '0', STR_PAD_LEFT);
}
