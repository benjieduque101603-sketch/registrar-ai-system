<?php
// Renders the Certificate of Good Moral in isolation so the standing
// ledger can be inspected row by row.
//   php tests/gm_preview.php [verified|blank|mixed]
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/document_templates.php';

$mode = $argv[1] ?? 'mixed';

$checks = [
    'Pending disciplinary cases'       => null,
    'Pending academic deficiency'      => null,
    'Outstanding financial obligation' => null,
    'Administrative hold on file'      => 'No',
    'Exit clearance'                   => null,
];
if ($mode === 'verified') {
    $checks = [
        'Pending disciplinary cases'       => '0',
        'Pending academic deficiency'      => 'None on file',
        'Outstanding financial obligation' => '₱0.00',
        'Administrative hold on file'      => 'No',
        'Exit clearance'                   => 'CLEARED',
    ];
} elseif ($mode === 'mixed') {
    $checks['Pending disciplinary cases']  = '0';
    $checks['Exit clearance']            = 'Pending';
}

$ctx = [
    'request' => [
        'id' => 1, 'request_id' => 'DOC-2026-0001', 'counter' => 2,
        'purpose' => 'Employment requirement', 'requirement_file_path' => null,
        'claimed_at' => null, 'release_date' => null,
    ],
    'student' => [
        'last_name' => 'DELA CRUZ', 'first_name' => 'JUAN', 'middle_name' => 'S',
        'name_suffix' => 'JR.', 'student_number' => '2024-00137',
        'course' => 'BS Information Technology', 'year_level' => 3,
        'section' => 'BSIT-3A', 'school_year' => '2025-2026',
        'semester' => '2nd', 'graduation_date' => null,
        'previous_school' => null, 'last_year_level_completed' => null,
    ],
    'catalog' => ['sku' => 'DOC-GM', 'name' => 'Certificate of Good Moral'],
    'terms' => [], 'total_units' => null, 'career_gwa' => null, 'tor_remarks' => null,
    'moral_checks' => $checks,
    'clearances' => [], 'clearance_done' => null,
    'ctc' => [], 'course_desc' => [],
    'signatory' => 'R. Bautista', 'logo_url' => null,
    'meta' => ['walkin_at' => null, 'walkin_by' => '', 'released_at' => null,
               'released_by' => '', 'tickets' => '', 'sha256' => '', 'qr_img' => ''],
];

$html = dt_render($ctx);

echo "MODE: {$mode}\n";
echo str_repeat('-', 52) . "\n";
echo "Standing ledger rows:\n";
preg_match_all('#<td class="(dt-k|dt-v|dt-unverified)">([^<]*)</td>#', $html, $m, PREG_SET_ORDER);
foreach ($m as $row) {
    $kind = $row[1] === 'dt-k' ? '   label  ' : ($row[1] === 'dt-v' ? 'VERIFIED ' : '  N/A    ');
    printf("  %s %s\n", $kind, html_entity_decode($row[2], ENT_QUOTES, 'UTF-8'));
}

$verified = preg_match_all('/class="dt-v"/', $html);
$blank    = substr_count($html, 'class="dt-unverified"');
printf("\n  rows: %d  verified: %d  unverified: %d\n",
    count($m) / 2, $verified, $blank);

// Count only the LEDGER cells. Extract the ledger's <table> specifically:
// a non-greedy match on the wrapping <div> stops at the first nested
// </div> and yields an empty slice.
preg_match('#<div class="dt-standing">.*(<table>.*?</table>)#s', $html, $tbl);
$ledger = $tbl[1] ?? '';
$ledgerVerified = substr_count($ledger, 'class="dt-v"');
$ledgerBlank    = substr_count($ledger, 'class="dt-unverified"');
printf("  ledger only: %d rows, verified %d, unverified %d — %s\n",
    substr_count($ledger, '<tr>'), $ledgerVerified, $ledgerBlank,
    ($ledgerVerified + $ledgerBlank === 5) ? 'OK, all 5 classified' : 'MISMATCH');

file_put_contents(__DIR__ . '/gm_preview.html', dt_standalone_html($ctx));
echo "  written: tests/gm_preview.html\n";

/**
 * Double-escaping check.
 *
 * A pre-escaped "&amp;" passed into a function that escapes again renders
 * on the printout as a literal "&amp;" — the entity is shown, not
 * rendered. Every document is checked so this cannot come back.
 */
echo "\nDouble-escaping audit:\n";
$skus = ['DOC-TOR', 'DOC-COE', 'DOC-GM', 'DOC-DIPLOMA', 'DOC-CTC', 'DOC-HD', 'DOC-CD'];
$bad = 0;
foreach ($skus as $s) {
    $one = $ctx;
    $one['catalog']['sku'] = $s;
    $one['catalog']['name'] = $s;
    $h = dt_render($one);

    // "&amp;amp;" is a pre-escaped string that got escaped again: the
    // document shows a literal "&amp;". That is always a bug.
    $double = strpos($h, '&amp;amp;') !== false;

    // A lone "&amp;" is CORRECT — it is how a plain "&" is carried in
    // HTML, and the browser renders it as "&". It only becomes a visible
    // bug if it survives a second decode, which "&amp;amp;" catches. So
    // decode the markup and look for a literal entity left in the TEXT.
    $asText = html_entity_decode($h, ENT_QUOTES, 'UTF-8');
    $leftover = strpos($asText, '&amp;') !== false || strpos($asText, '&amp;amp;') !== false;

    $flag = $double || $leftover;
    if ($flag) { $bad++; }
    printf("  %-12s %s\n", $s, $flag
        ? 'LITERAL ENTITY — ' . ($double ? 'double-escaped' : 'survives decode') . ' (bug)'
        : 'clean');
}
echo $bad === 0
    ? "\n  OK — no document prints a literal entity.\n"
    : "\n  {$bad} document(s) affected.\n";
