<?php
// Guard the documents desk against dying on an un-migrated server.
//
//   php tests/documents_schema_check.php
//
// The failure this exists for is not hypothetical and it is not local.
// registrar/documents.php is the only page that SELECTs
// document_catalog.sla_days, so on a server where
// migrations/document_walkin_only.sql has not been applied it is the
// only page that answers 500 - the archive, the intake form and the
// public verification card keep working, which makes "the documents
// page is broken" look like a bug in that page rather than a database
// that is three migrations behind.
//
// Source-level, deliberately. The defect is a column name in a query,
// and the thing worth protecting is that the name is not hard-coded.
// Rendering the page against a deliberately broken schema is the only
// test that proves it end to end, and it needs a database this check
// deliberately does not assume is there.

$root = dirname(__DIR__);
$read = function (string $rel) use ($root): string {
    $p = $root . '/' . $rel;
    if (!is_file($p)) {
        fwrite(STDERR, "  missing file: $rel\n");
        exit(1);
    }
    return (string) file_get_contents($p);
};

$page = $read('registrar/documents.php');
$proc = $read('shared/document_process.php');
$add  = $read('registrar/documents-add.php');
$api  = $read('api/student-documents.php');
$schema = $read('shared/schema.php');

$fail = 0;
function check(string $label, bool $ok, string $detail = '') {
    global $fail;
    if (!$ok) $fail++;
    printf("  %-52s %s%s\n", $label, $ok ? 'OK' : 'FAIL', $detail !== '' ? "  ($detail)" : '');
}

echo "Documents desk: schema tolerance\n";

// -- 1. The desk must not name a migration-added column in a column list.
// The helper is what makes it safe; a literal is what breaks it.
check('the desk builds its catalog projection through the helper',
    substr_count($page, "db_optional_column('document_catalog', 'sla_days'") === 1
    && substr_count($page, "db_optional_column('document_catalog', 'requirement'") === 1);
check('no hard-coded c.sla_days in the desk',
    !preg_match('/\bc\.sla_days\b/', $page));
check('no hard-coded c.requirement in the desk',
    !preg_match('/\bc\.requirement\b/', $page));
check('no hard-coded c.sla_days in the shared refresh path',
    !preg_match('/\bc\.sla_days\b/', $proc));
check('the shared refresh path uses the helper too',
    substr_count($proc, "db_optional_column('document_catalog', 'sla_days'") === 1);

// -- 2. The tables the desk reads are not document tables and can be absent
// on their own. finance in particular: the desk needs it for balances only.
check('the balance read is guarded on the finance table',
    (bool) preg_match("/db_table_exists\('finance'\)/", $page)
    && strpos($page, 'FROM finance') !== false);
check('the activity log read is guarded on its table',
    (bool) preg_match("/db_table_exists\('document_request_events'\)/", $page));

// -- 3. SELECT * cannot be made tolerant, so its rows must be filled in.
// Both places that read a catalog row's requirement directly.
foreach ([
    'the desk'            => $page,
    'the intake form'     => $add,
    'the student intake'  => $api,
] as $label => $src) {
    check($label . ' fills optional catalog keys', strpos($src, 'db_fill_optional(') !== false);
}
check('the desk requires the schema helper', strpos($page, "shared/schema.php") !== false);

// -- 4. The helper qualifies by ALIAS, not table name. This is not a style
// point: MySQL resolves the alias and nothing else once a table is
// aliased, so a table-qualified fragment raises 1054 on EVERY server -
// including one that has the column. That bug shipped inside this very
// fix and was caught by the desk_check render, which is why it is pinned.
check('db_optional_column qualifies by the alias it is given',
    (bool) preg_match('/function db_optional_column\([^)]*\$alias/s', $schema)
    && strpos($schema, "(\$alias !== '' ? \$alias : \$table)") !== false);
check('every call site passes its alias',
    !preg_match("/db_optional_column\('document_catalog', '(?:sla_days|requirement)'\)/", $page . $proc));

// -- 5. A blank 500 is not a bug report. display_errors is 0 in
// production, so an uncaught throw is a white page and a log line the
// registrar cannot reach.
check('the desk catches a failed load', strpos($page, 'catch (Throwable $e)') !== false);
check('a failed load is logged server-side', strpos($page, "[documents] desk load failed:") !== false);
check('a failed load names the cause on screen',
    strpos($page, 'could not load its requests') !== false
    && strpos($page, 'htmlspecialchars($deskErrorDetail)') !== false);
check('a failed load points at the fix', strpos($page, 'migrations/document_walkin_only.sql') !== false);
check('a failed load still answers 500',
    (bool) preg_match('/if \(\$deskLoadError !== null\) \{\s*http_response_code\(500\);/', $page));

echo "\n" . ($fail === 0 ? "OK — the desk cannot be taken down by a missing column.\n" : "FAILED — $fail check(s)\n");
exit($fail === 0 ? 0 : 1);
