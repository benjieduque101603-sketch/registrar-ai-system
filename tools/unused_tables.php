<?php
// Which tables in registrar_ai.sql does the application never read?
//
// Scans every PHP file for the table name in SQL position, then reports the
// ones with zero hits. Comments and single-quoted literals are stripped first,
// because a prose mention of a table is not a use of it.
//
// Deliberately NOT a deletion tool. "No code reads it" means the table is dead
// to THIS codebase; it says nothing about whether the data is needed, and a
// table nobody queries is exactly what an audit trail looks like.
$root = __DIR__ . '/..';
$skip = '#/(vendor|node_modules|\.git|tests|backups)/#';

$sql = file_get_contents($root . '/registrar_ai.sql');
preg_match_all('/^CREATE TABLE `([a-z_][a-z0-9_]*)`/m', $sql, $m);
$tables = $m[1];

// Table names mentioned anywhere in the source, in any SQL-ish position.
$hits = array_fill_keys($tables, []);
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($rii as $file) {
    $p = $file->getPathname();
    if (!is_file($p) || substr($p, -4) !== '.php') continue;
    if (preg_match($skip, str_replace(chr(92), '/', $p))) continue;
    $src = @file_get_contents($p);
    if (!$src) continue;
    $src = preg_replace('#/\*.*?\*/#s', ' ', $src);
    $src = preg_replace('#(^|\s)//[^\n]*#', '$1', $src);
    $src = preg_replace('#(^|\s)\#[^\n]*#', '$1', $src);
    // Do NOT strip single-quoted literals here.
    //
    // The obvious version of this script strips them, and that is WRONG in a
    // way that deletes working tables: shared/database.php exposes
    // insert($table) / update($table), so a query against a table the codebase
    // uses daily is written as
    //
    //     $db->insert('contact_change_requests', [...]);
    //     $db->update('mock_lalamove_orders', [...], 'id = ?');
    //
    // The table name is a single-quoted STRING. Strip literals and those three
    // tables read as unused - while contact_change_requests backs the student
    // "Request a Change" queue and enrollment_history is written on every
    // re-enrolment. A scan that cannot see a table must never be allowed to
    // delete one.
    //
    // Comments still go, so prose mentioning a table is not a use of it.
    foreach ($tables as $t) {
        if (preg_match('/\b' . preg_quote($t, '/') . '\b/i', $src)) {
            $hits[$t][] = basename($p);
        }
    }
}

$unused = array_keys(array_filter($hits, fn($v) => $v === []));
$thin   = array_keys(array_filter($hits, fn($v) => count($v) <= 1));

echo "tables in the dump: " . count($tables) . "\n\n";
echo "== NEVER MENTIONED IN ANY PHP FILE ==\n";
foreach ($unused as $t) echo "  $t\n";
if (!$unused) echo "  (none)\n";

echo "\n== MENTIONED IN ONLY ONE FILE ==\n";
foreach ($thin as $t) echo "  " . str_pad($t, 26) . implode(', ', $hits[$t]) . "\n";
if (!$thin) echo "  (none)\n";