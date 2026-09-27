<?php
// Authenticated render check: build a context for a real request as
// a real registrar and confirm the document renders end to end.
//   php tests/preview_check.php [request_id]
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/document_templates.php';

$db = Database::getInstance();
$id = isset($argv[1]) ? (int) $argv[1] : 1;

$u = $db->fetchColumn(
    "SELECT id FROM users WHERE role IN ('admin','registrar') AND is_active = 1 ORDER BY id LIMIT 1"
);
if (!$u) {
    fwrite(STDERR, "No active registrar/admin user to simulate.\n");
    exit(1);
}
$_SESSION = ['user_id' => (int) $u];

$ctx = dt_collect_context($id, ['app_root' => '../']);
if ($ctx === null) {
    fwrite(STDERR, "Request #$id not found.\n");
    exit(1);
}

$html = dt_render($ctx);

printf("simulated user_id  %d\n", (int) $u);
printf("request            #%d  %s\n", $id, (string) $ctx['request']['request_id']);
printf("status             %s\n", (string) $ctx['request']['document_status']);
printf("student            %s\n", trim(
    (string) $ctx['student']['first_name'] . ' ' . (string) $ctx['student']['last_name']
) ?: '(none)');
printf("document           %s / %s\n", (string) $ctx['catalog']['sku'], (string) $ctx['catalog']['name']);
printf("signatory          %s\n", (string) $ctx['signatory']);
printf("render bytes       %d\n", strlen($html));
printf("dry-seal block     %s\n", strpos($html, 'dt-seal') !== false ? 'PRESENT (should be gone)' : 'removed');
printf("auth block         %s\n", strpos($html, 'dt-auth') !== false ? 'yes' : 'NO');
printf("verify strip       %s\n", strpos($html, 'dt-verify') !== false ? 'yes' : 'NO');

// The QR: inline SVG, and the URL it must encode.
$hasSvg = strpos($html, '<svg') !== false;
printf("QR inline svg      %s\n", $hasSvg ? 'yes' : 'NO');
printf("QR encoded url     %s\n", (string) ($ctx['meta']['qr_url'] ?? '(none)'));
printf("logo url           %s\n", (string) ($ctx['logo_url'] ?? '(none)'));

// Persist the rendered QR so it can be opened and scanned directly.
$svg = (string) ($ctx['meta']['qr_img'] ?? '');
if ($svg !== '') {
    $out = __DIR__ . '/preview_qr.svg';
    file_put_contents($out, $svg);
    printf("QR written to      %s (%d bytes)\n", $out, strlen($svg));
    // The SVG must carry no fixed size, or the print CSS is ignored.
    printf("QR has hard width  %s\n",
        preg_match('/<svg[^>]*\swidth=/i', $svg) ? 'YES (bug)' : 'no (CSS sizes it)');
    printf("QR module count    %d rect/path elements\n",
        substr_count($svg, '<rect') + substr_count($svg, '<path'));
} else {
    echo "QR written to      (none — nothing to verify)\n";
}
printf("N/A occurrences    %d\n", substr_count($html, DT_NA));

// The three fields that must always be present on a live request.
foreach (['request_id' => 'Request No.', 'counter' => 'Counter'] as $col => $label) {
    $v = (string) ($ctx['request'][$col] ?? '');
    printf("%-19s %s\n", $label, $v === '' ? 'MISSING (logged)' : $v);
}
