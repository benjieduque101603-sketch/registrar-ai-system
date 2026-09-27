<?php
// End-to-end check: does dt_collect_context() return a usable
// context against the live database, and does every catalog SKU
// render? Run from the project root:
//   php tests/render_smoke.php
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/document_templates.php';

$db = Database::getInstance();

$skus = $db->fetchAll('SELECT sku, name FROM document_catalog WHERE is_active = 1 ORDER BY id');
if (!$skus) {
    fwrite(STDERR, "No active catalog rows.\n");
    exit(1);
}
echo "Active catalog SKUs: " . count($skus) . "\n\n";

// A real student with a real request, if one exists.
$req = $db->fetchOne(
    'SELECT dr.id, dr.request_id, dr.document_status, s.student_number
       FROM document_requests dr
       LEFT JOIN students s ON s.id = dr.student_id
      ORDER BY dr.id DESC LIMIT 1'
);

if (!$req) {
    echo "No document_requests rows yet — rendering every SKU with a stub context.\n\n";
    $req = ['id' => 0, 'request_id' => 'DOC-0000-0000', 'document_status' => 'Processing', 'student_number' => '(none)'];
    $ctx = null;
}


echo "Using request #{$req['id']} ({$req['request_id']}) — status {$req['document_status']}\n";
echo "Student: {$req['student_number']}\n\n";

$ctx = dt_collect_context((int) $req['id'], ['app_root' => '../']);
if ($ctx === null) {
    // No request row to collect from. Build a stub so the catalog SKUs
    // can still be exercised against a real connection.
    $ctx = [
        'request' => ['id' => 0, 'request_id' => 'DOC-0000-0000', 'counter' => 1,
                      'purpose' => null, 'requirement_file_path' => null,
                      'claimed_at' => null, 'release_date' => null],
        'student' => ['last_name' => null, 'first_name' => null, 'student_number' => null],
        'catalog' => [], 'terms' => [], 'total_units' => null, 'career_gwa' => null,
        'tor_remarks' => null, 'moral_checks' => [], 'clearances' => [],
        'clearance_done' => null, 'ctc' => [], 'course_desc' => [],
        'signatory' => 'Office of the Registrar', 'logo_url' => null,
        'meta' => [],
    ];
}

printf("%-12s %-26s %-7s %-5s %-6s %-5s %-5s\n",
    'SKU', 'DOCUMENT', 'BYTES', 'N/A', 'SEAL', 'AUTH', 'LOGO');
echo str_repeat('-', 72) . "\n";

$fail = 0;
foreach ($skus as $s) {
    $sku = (string) $s['sku'];
    // Render each catalog SKU through the live context.
    $one = $ctx;
    $one['catalog']['sku'] = $sku;
    $one['catalog']['name'] = $s['name'];

    try {
        $html = dt_render($one);
    } catch (Throwable $e) {
        printf("%-12s %-26s FATAL %s\n", $sku, substr((string) $s['name'], 0, 26), $e->getMessage());
        $fail++;
        continue;
    }

    $seal = (int) (strpos($html, 'dt-seal') !== false);   // must now be absent
    $auth = (int) (strpos($html, 'dt-auth') !== false);
    $logo = (int) (strpos($html, 'BCP_LOGO.png') !== false);
    $ok   = !$seal && $auth && $logo && strlen($html) > 800;
    if (!$ok) { $fail++; }

    printf("%-12s %-26s %-7d %-5d %-6d %-5d %-5d %s\n",
        $sku, substr((string) $s['name'], 0, 26), strlen($html),
        substr_count($html, DT_NA), $seal, $auth, $logo,
        $ok ? '' : ' <-- CHECK');

    // ── Certificate of Good Moral: redesign checks ──────────
    if ($sku === 'DOC-GM') {
        $rows = substr_count($html, '<tr>');
        // Scope the count to the LEDGER table. Outside it, dt_strip and
        // dt_tot reuse short class names, so a whole-document count is
        // not a measure of this component.
        preg_match('#<div class="dt-standing">.*(<table>.*?</table>)#s', $html, $t);
        $ledger = $t[1] ?? '';
        $verified = substr_count($ledger, 'class="dt-v"');
        $unverified = substr_count($ledger, 'class="dt-unverified"');
        printf("    standing rows: %d (verified %d, unverified %d) %s\n",
            $rows, $verified, $unverified,
            ($rows === 5 && $verified + $unverified === 5) ? 'OK' : '<-- CHECK');

        // The name must appear exactly ONCE: repeating it reads as a fault.
        $name = dt_cert_name($ctx['student']);
        $nameCount = $name === DT_NA ? 0 : substr_count($html, $name);
        printf("    name occurrences: %d %s\n", $nameCount,
            $nameCount === 1 ? 'OK (not duplicated)' : '<-- CHECK');

        $head = strpos($html, 'Standing at the time of issuance') !== false;
        printf("    ledger heading: %s %s\n", $head ? 'present' : 'MISSING',
            $head ? 'OK' : '<-- CHECK');
    }
}

echo "\n";
$h = dt_standalone_html($ctx);
printf("standalone HTML: %d bytes, @page %s, DOCTYPE %s\n",
    strlen($h),
    strpos($h, '@page') !== false ? 'yes' : 'NO',
    strpos($h, '<!DOCTYPE') !== false ? 'yes' : 'NO');

echo $fail === 0 ? "\nOK — every catalog SKU rendered.\n" : "\n{$fail} SKU(s) failed.\n";
exit($fail === 0 ? 0 : 1);
