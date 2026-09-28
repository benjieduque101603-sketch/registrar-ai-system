<?php
// The TOR and the status rules must read the same GWA.
//
//   php tests/gwa_agreement_check.php
//
// The stored academic_history.gwa is what status_evidence.php queries.
// The transcript prints a career figure that used to be computed by a
// separate loop in document_templates.php, with a looser test ($fr > 0
// rather than 1.00-5.00). Two implementations of one number is how a
// student ends up with a GWA on the status page that does not match the
// GWA on the TOR.
//
// So this drives both through the real data, on synthetic cases chosen
// to expose the difference: out-of-scale ratings, missing ratings, and
// subjects with no units.

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/term_grades.php';

$fail = 0;
$ok   = 0;
function check(string $label, $cond): void
{
    global $fail, $ok;
    if ($cond) { $ok++;  printf("  ok    %s\n", $label); }
    else       { $fail++; printf("  FAIL  %s\n", $label); }
}

echo "gwa agreement - one implementation for every consumer\n";

/** The loop that was in document_templates.php before the rework. */
function oldTorGwa(array $terms): ?float
{
    $weighted = 0.0;
    $units = 0.0;
    foreach ($terms as $t) {
        foreach (($t['subjects'] ?? []) as $sub) {
            $fr = (float) ($sub['final_rating'] ?? 0);
            $un = (float) ($sub['units'] ?? 0);
            if ($fr > 0 && $un > 0) { $weighted += $fr * $un; $units += $un; }
        }
    }
    return $units > 0 ? round($weighted / $units, 4) : null;
}

$cases = [
    'ordinary term' => [
        ['subjects' => [
            ['units' => 3, 'final_rating' => 1.5],
            ['units' => 3, 'final_rating' => 2.5],
            ['units' => 2, 'final_rating' => 3.0],
        ]],
    ],
    'a missing rating' => [
        ['subjects' => [
            ['units' => 3, 'final_rating' => 1.5],
            ['units' => 3, 'final_rating' => null],
        ]],
    ],
    'a subject with no units' => [
        ['subjects' => [
            ['units' => 3, 'final_rating' => 2.0],
            ['units' => 0, 'final_rating' => 5.0],
        ]],
    ],
    'a 5.00, the worst legal rating' => [
        ['subjects' => [['units' => 3, 'final_rating' => 5.0]]],
    ],
    'no ratings at all' => [
        ['subjects' => [['units' => 3, 'final_rating' => null]]],
    ],
    'two terms' => [
        ['subjects' => [['units' => 3, 'final_rating' => 1.5]]],
        ['subjects' => [['units' => 4, 'final_rating' => 2.5]]],
    ],
];

foreach ($cases as $label => $terms) {
    $now = careerGwa($terms);
    $was = oldTorGwa($terms);
    // 2dp vs 4dp: the same figure to the precision anything displays.
    $agree = ($now === null && $was === null)
        || ($now !== null && $was !== null && abs($now - $was) < 0.005);
    check("unchanged: $label", $agree);
}

// The old loop's looser bound is the reason it had to be replaced. These
// are the inputs where the two genuinely disagreed, and the new rule has
// to refuse them rather than quietly reproduce the old number.
$outOfScale = [['subjects' => [
    ['units' => 3, 'final_rating' => 2.0],
    ['units' => 3, 'final_rating' => 9.0],   // not a legal rating
]]];
check('an out-of-scale rating is excluded now', careerGwa($outOfScale) === 2.0);
check('the old loop would have averaged it in', oldTorGwa($outOfScale) === 5.5);

$zeroRating = [['subjects' => [
    ['units' => 3, 'final_rating' => 2.0],
    ['units' => 3, 'final_rating' => 0],     // below the scale
]]];
check('a rating below the scale is excluded now', careerGwa($zeroRating) === 2.0);

// The stored figure is what the status rules read, so it must be exactly
// the computed one for any term the new save path produced.
$db   = Database::getInstance();
$rows = $db->fetchAll("
    SELECT ah.id, ah.gwa FROM academic_history ah
     WHERE ah.gwa IS NOT NULL LIMIT 200
");
$checked = 0;
$mismatch = 0;
foreach ($rows as $r) {
    $subjects = $db->fetchAll(
        "SELECT units, final_rating FROM academic_grades WHERE academic_history_id = ?",
        [(int) $r['id']]
    );
    if (!$subjects) { continue; }
    $checked++;
    $computed = termGwa($subjects);
    if ($computed === null) { continue; }
    if (abs((float) $r['gwa'] - $computed) >= 0.005) {
        $mismatch++;
        printf("       record %d: stored %s, subjects give %s\n",
            (int) $r['id'], $r['gwa'], $computed);
    }
}
if ($checked === 0) {
    printf("  ok    no stored terms on file to reconcile yet\n");
} elseif ($mismatch === 0) {
    check("every stored GWA on file matches its subjects ($checked terms)", true);
} else {
    check("$mismatch of $checked stored GWAs disagree with their subjects", false);
}

printf("\n  %d passed, %d failed\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
