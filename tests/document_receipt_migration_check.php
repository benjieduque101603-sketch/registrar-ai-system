<?php
// Confirms migrations/document_receipt_upload.sql actually landed on the live
// table, and that document_status came through untouched.
//
// WHY A FILE AND NOT php -r: these checks need double-quoted SQL strings with
// embedded single quotes, which is three levels of shell escaping deep when the
// command is piped through PowerShell. An unclosed paren there is a parse error
// about YOUR command, not about the schema, which is a genuinely confusing way
// to learn a column is missing. Put the code in a file and quote nothing.
//
//   php tests/document_receipt_migration_check.php
require __DIR__ . '/../shared/config.php';
require __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$want = [];
// Derived from the code that actually WRITES these columns, not hand-listed.
//
// This list used to be typed out by hand, and it passed while the feature was
// broken: api/student-documents.php also writes payment_receipt_filename,
// the migration never created it, and this check could not notice because
// the name was not in the list. Every upload returned "Could not save the
// receipt." -- caught by the e2e test, not by the check written to catch
// exactly this class of mistake.
//
// So the list is scraped from the sources that write these columns. Adding a
// column to the code now fails here until the migration catches up, which is
// the direction that matters.
$sources = [
    __DIR__ . '/../api/student-documents.php',
    __DIR__ . '/../api/documents.php',
    __DIR__ . '/../shared/document_process.php',
    __DIR__ . '/../registrar/documents.php',
];
// Aliases in the sources that LOOK like columns but are not stored ones.
//
// MUST be a lookup map, not a list: isset($list['name']) on a
// numerically-indexed array is always false, so a plain [ ... ] here
// silently filtered nothing.
//
// There were four phantom names once. Two are genuine SELECT aliases
// joining users.full_name onto the *_by ids, listed below. The other
// two were not aliases at all — the registrar panel read a key that
// matched no column and had no alias, so the receipt's original
// filename and the waiver reason both rendered as nothing. Those were
// real bugs and were fixed at the call site, not here: "the filename is
// missing" and "the waiver reason was lost" are both things a registrar
// needs to be able to rely on.
$notColumns = array_flip([
    'payment_receipt_verified_by_name',
    'payment_receipt_waived_by_name',
]);

foreach ($sources as $src) {
    if (!is_file($src)) { continue; }
    $code = (string) file_get_contents($src);

    // Strip comments BEFORE scraping. A comment that mentions a column by
    // name is documentation, not a write: listing one is enough to make
    // this check demand a column nobody reads or writes, and the failure
    // message then names a column that does not exist. This very file's
    // comments had to be worded carefully because of it.
    $code = preg_replace('~/\*.*?\*/~s', ' ', $code);
    $code = preg_replace('~(^|\s)//[^\n]*~', '$1', (string) $code);
    $code = preg_replace('~(^|\s)#(?!\[)[^\n]*~', '$1', (string) $code);

    // Word-boundary anchored, so payment_receipt_path does not also match
    // payment_receipt_pathway or a suffix like _path_backup.
    if (preg_match_all('/\b(payment_receipt_[a-z0-9_]+|pickup_notify[a-z0-9_]*|pickup_notified_[a-z0-9_]+)\b/i', $code, $m)) {
        foreach ($m[1] as $col) {
            $lc = strtolower($col);
            if (isset($notColumns[$lc])) { continue; }
            $want[$lc] = true;
        }
    }
}
$want = array_keys($want);
sort($want);

$cols = array_column($db->fetchAll('SHOW COLUMNS FROM document_requests'), 'Field');
$missing = array_values(array_diff($want, $cols));

echo "== document_receipt_upload migration ==\n";
echo '  columns present : ' . count(array_intersect($want, $cols)) . ' / ' . count($want) . "\n";
echo '  columns missing : ' . ($missing ? implode(', ', $missing) : 'none') . "\n";

$status = $db->fetchAll("SHOW COLUMNS FROM document_requests LIKE 'document_status'");
$enum = $status ? $status[0]['Type'] : '(column not found)';

// The whole reason this migration refuses to touch document_status. If either
// value is gone, some writer ran MODIFY COLUMN using registrar_ai.sql's older
// six-value enum instead of SHOW COLUMNS, and every row using those statuses
// was silently rewritten to ''. Checked on every run because the damage is
// invisible until payments stop flowing.
echo "\n== document_status enum (must keep both) ==\n";
echo '  ' . $enum . "\n";
$enumOk = true;
foreach (['Awaiting_Payment', 'Shipped'] as $v) {
    $ok = strpos($enum, "'" . $v . "'") !== false;
    if (!$ok) { $enumOk = false; }
    echo '  contains ' . str_pad($v, 18) . ': ' . ($ok ? 'yes' : 'NO  <-- DATA LOSS') . "\n";
}

echo "\n";
exit(($missing || !$enumOk) ? 1 : 0);