<?php
// Arithmetic and rules for shared/term_grades.php.
//
//   php tests/term_grades_check.php
//
// The GWA is now computed rather than typed, which means every consumer -
// the status rules, the TOR, this page - trusts one function. A rounding
// error here is not a display fault: it moves a student across the 3.00
// line that status_evidence.php reads. So the weighting is checked
// against hand-worked numbers rather than against itself.

require_once __DIR__ . '/../shared/term_grades.php';

$fail = 0;
$ok   = 0;
function check(string $label, $cond): void
{
    global $fail, $ok;
    if ($cond) { $ok++;  printf("  ok    %s\n", $label); }
    else       { $fail++; printf("  FAIL  %s\n", $label); }
}

echo "term_grades - computation and audit\n";

// ── termGwa ──────────────────────────────────────────────────
// Hand-worked: (3x1.50 + 3x2.50 + 2x3.00) / 8 = 18/8 = 2.25
$gwa = termGwa([
    ['units' => 3, 'final_rating' => 1.5],
    ['units' => 3, 'final_rating' => 2.5],
    ['units' => 2, 'final_rating' => 3.0],
]);
check('weights by units', $gwa === 2.25);

// A subject with no rating is missing data, not a zero. Averaging it in
// would drag every GWA down, which is the most damaging way this function
// could be wrong.
check('a subject with no rating is excluded, not zeroed',
    termGwa([['units' => 3, 'final_rating' => 1.5], ['units' => 3, 'final_rating' => null]]) === 1.5);

// A subject with no units cannot shift an average.
check('a subject with no units is excluded',
    termGwa([['units' => 3, 'final_rating' => 1.5], ['units' => 0, 'final_rating' => 5.0]]) === 1.5);

check('no usable data yields null, never 0.00',
    termGwa([['units' => 3, 'final_rating' => null]]) === null);
check('an empty list yields null', termGwa([]) === null);
check('a 5.00 is a legal rating, not a missing one',
    termGwa([['units' => 3, 'final_rating' => 5.0]]) === 5.0);

// ── careerGwa ────────────────────────────────────────────────
check('career GWA weights across terms',
    careerGwa([
        ['subjects' => [['units' => 3, 'final_rating' => 1.5]]],
        ['subjects' => [['units' => 3, 'final_rating' => 2.5]]],
    ]) === 2.0);
check('career GWA over nothing is null', careerGwa([]) === null);

// ── Rating validation ────────────────────────────────────────
check('1.00 is valid',    termRatingValid(1.0) === true);
check('5.00 is valid',    termRatingValid(5.0) === true);
check('0.00 is not valid', termRatingValid(0.0) === false);
check('5.01 is not valid', termRatingValid(5.01) === false);
check('a letter grade is not a number', termRatingValid('A') === false);
check('empty is not valid', termRatingValid('') === false);
check('null is not valid',  termRatingValid(null) === false);

// ── Audit ────────────────────────────────────────────────────
$roster = [
    [
        'name' => 'Dela Cruz', 'number' => '2024-0117', 'gwa' => 1.75,
        'subjects' => [
            ['subject' => 'IT 101', 'units' => 3, 'final_rating' => 1.5, 'grade_status' => 'passed'],
            ['subject' => 'IT 102', 'units' => 3, 'final_rating' => 2.0, 'grade_status' => 'passed'],
        ],
    ],
    [
        'name' => 'Reyes, J.', 'number' => '2024-0180', 'gwa' => null,
        'subjects' => [
            ['subject' => 'IT 101', 'units' => 3, 'final_rating' => 1.5, 'grade_status' => 'passed'],
            ['subject' => 'IT 102', 'units' => 3, 'final_rating' => null, 'grade_status' => 'passed'],
        ],
    ],
    ['name' => 'Lim, A.', 'number' => '2024-0155', 'gwa' => null, 'subjects' => []],
    [
        'name' => 'Santos, M.', 'number' => '2024-0166', 'gwa' => 3.40,
        'subjects' => [['subject' => 'PE', 'units' => 2, 'final_rating' => 3.4, 'grade_status' => 'passed']],
    ],
];

$a     = termAudit('2026-2027', '1st', $roster);
$codes = array_column($a['blocking'], 'code');
$adv   = array_column($a['advisory'], 'code');

check('a complete record is not blocking', !in_array('gwa_mismatch', $codes, true));
check('a missing rating blocks', in_array('missing_rating', $codes, true));
check('passed with no rating blocks separately', in_array('passed_without_rating', $codes, true));
check('an empty subject list blocks', in_array('no_subjects', $codes, true));
check('a GWA at or above 3.00 is advisory, not blocking', in_array('gwa_at_risk', $adv, true));
check('a GWA over 3.00 is not itself a block', !in_array('gwa_at_risk', $codes, true));
check('audit counts the roster', $a['stats']['students'] === 4);
check('audit counts the students with subjects', $a['stats']['with_grades'] === 3);
check('audit sums units across the term', $a['stats']['total_units'] > 0);

// A stored GWA that disagrees with the subjects must block: the TOR prints
// one figure and the status rules read the other.
$mismatch = termAudit('2026-2027', '1st', [[
    'name' => 'Wrong', 'number' => 'X', 'gwa' => 1.00,
    'subjects' => [['subject' => 'A', 'units' => 3, 'final_rating' => 2.50, 'grade_status' => 'passed']],
]]);
check('a stored GWA that disagrees blocks', in_array('gwa_mismatch', array_column($mismatch['blocking'], 'code'), true));

$outOfScale = termAudit('2026-2027', '1st', [[
    'name' => 'Bad', 'number' => 'Y', 'gwa' => null,
    'subjects' => [['subject' => 'A', 'units' => 3, 'final_rating' => 9.0, 'grade_status' => 'passed']],
]]);
check('a rating outside the scale blocks', in_array('rating_out_of_scale', array_column($outOfScale['blocking'], 'code'), true));

$empty = termAudit('2026-2027', '1st', []);
check('an empty term says so', str_contains($empty['summary'], 'No grades'));
check('an empty term raises nothing', $empty['blocking'] === []);

// Every finding must name an action. A finding with no next step is just an
// accusation.
$all = array_merge($a['blocking'], $a['advisory']);
check('every finding says what to do about it',
    array_filter($all, fn($f) => trim((string) ($f['action'] ?? '')) === '') === []);
check('every finding states the fact',
    array_filter($all, fn($f) => trim((string) ($f['detail'] ?? '')) === '') === []);

printf("\n  %d passed, %d failed\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);

check('numeric string is valid', termRatingValid('2.25') === true);

// ── Labels and ordering ──────────────────────────────────────
check('term label reads naturally', termLabel('2026-2027', '1st') === '1st · 2026-2027');
check('a missing year still labels', termLabel('', '2nd') === '2nd');
check('an empty term does not look real', termLabel('', '') === 'Unspecified term');

check('within a year, 1st precedes 2nd',
    termSortKey('2026-2027', '1st') < termSortKey('2026-2027', '2nd'));
check('an "SY 2026-2027" prefix still sorts',
    termSortKey('SY 2026-2027', '1st') === termSortKey('2026-2027', '1st'));
