<?php
// Generate the fixtures tests/term_gwa_parity.js compares against.
//
//   php tests/term_gwa_cases.php
//
// The client preview and the server helper must produce the same figure
// for the same subjects. Writing the cases once here and the expected
// values from the PHP implementation means the JavaScript test cannot
// quietly agree with a broken copy of the rule: PHP decides what the
// answer is, and the client has to match it.
//
// Cases include the ones that separate the two rules, not just the easy
// ones, because a parity test that only checks ordinary data proves
// nothing about the edge.

require_once __DIR__ . '/../shared/term_grades.php';

$cases = [
    // Ordinary: (3x1.50 + 3x2.50 + 2x3.00) / 8 = 2.25
    [
        ['units' => 3, 'final_rating' => 1.5],
        ['units' => 3, 'final_rating' => 2.5],
        ['units' => 2, 'final_rating' => 3.0],
    ],
    // A single subject, so there is nothing to weight against.
    [['units' => 3, 'final_rating' => 2.0]],

    // Missing data. A blank must not be averaged in as a zero: doing so
    // would drag a real student's GWA down on an unsaved cell.
    [
        ['units' => 3, 'final_rating' => 1.5],
        ['units' => 3, 'final_rating' => null],
    ],
    [
        ['units' => 3, 'final_rating' => 1.5],
        ['units' => 3, 'final_rating' => ''],
    ],

    // A subject with no units cannot move an average.
    [
        ['units' => 3, 'final_rating' => 2.0],
        ['units' => 0, 'final_rating' => 5.0],
    ],

    // The ends of the legal scale.
    [['units' => 3, 'final_rating' => 1.0]],
    [['units' => 3, 'final_rating' => 5.0]],

    // Out of scale in both directions. Neither may be averaged.
    [
        ['units' => 3, 'final_rating' => 2.0],
        ['units' => 3, 'final_rating' => 9.0],
    ],
    [
        ['units' => 3, 'final_rating' => 2.0],
        ['units' => 3, 'final_rating' => 0],
    ],
    [
        ['units' => 3, 'final_rating' => 2.0],
        ['units' => 3, 'final_rating' => -1],
    ],

    // Nothing to average at all.
    [],
    [['units' => 3, 'final_rating' => null]],
    [['units' => 0, 'final_rating' => 2.0]],

    // A rating that needs rounding, so the 2dp rounding is exercised
    // rather than assumed. 1x1 + 1x2 + 1x2 + 1x2 = 7/4 = 1.75.
    [
        ['units' => 1, 'final_rating' => 1.0],
        ['units' => 1, 'final_rating' => 2.0],
        ['units' => 1, 'final_rating' => 2.0],
        ['units' => 1, 'final_rating' => 2.0],
    ],
    // 1x1.33 + 1x1.34 + 1x1.33 = 4.0/3 = 1.3333 -> 1.33
    [
        ['units' => 1, 'final_rating' => 1.33],
        ['units' => 1, 'final_rating' => 1.34],
        ['units' => 1, 'final_rating' => 1.33],
    ],
];

$expected = array_map(static fn($s) => termGwa($s), $cases);

file_put_contents(__DIR__ . '/term_gwa_cases.json', json_encode($cases, JSON_PRETTY_PRINT));
file_put_contents(__DIR__ . '/term_gwa_expected.json', json_encode($expected, JSON_PRETTY_PRINT));

printf("wrote %d cases to term_gwa_cases.json / term_gwa_expected.json\n", count($cases));
