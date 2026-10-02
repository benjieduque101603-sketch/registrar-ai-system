<?php
// Rehearse a migration the way a deploy will, before it is a deploy.
//
//   php tests/migration_dryrun.php [migrations/foo.sql]
//
// Builds a disposable database from the live schema with no data, applies
// the migration to it TWICE, and checks that the columns the application
// reads are actually there. The second run is the point: every migration
// in this project is written to be re-runnable, and that is a claim about
// the file, so it should be tested rather than assumed.
//
// The live database is only ever read. The scratch database is dropped on
// the way out, including if a step throws.
//
// Schema is copied with mysqldump rather than by replaying SHOW CREATE
// TABLE in PHP: a hand-rolled replay creates tables in SHOW TABLES order,
// which breaks the moment a table's foreign key points at one created
// later. mysqldump sorts for us and wraps the copy in FK checks off.
require_once __DIR__ . '/../shared/config.php';

$file = $argv[1] ?? (__DIR__ . '/../migrations/document_walkin_only.sql');
if (!is_file($file)) { echo "  no such migration: $file\n"; exit(1); }

$port = defined('DB_PORT') ? DB_PORT : 3306;
$dsn  = 'mysql:host=' . DB_HOST . ';port=' . $port . ';charset=utf8mb4';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$root = new PDO($dsn, DB_USER, DB_PASSWORD, $opts);
$tmp  = 'registrar_ai_migtest';

// Find the MySQL client binaries. They live in different places depending
// on how MySQL was installed, so check the usual roots before falling back
// to PATH.
function find_bin(string $name): ?string
{
    $homes = [];
    foreach ([getenv('MYSQL_HOME'), getenv('XAMPP_ROOTPATH'), 'C:/xampp', '/usr/local/mysql', '/usr'] as $h) {
        if ($h) $homes[] = $h;
    }
    foreach ($homes as $home) {
        $home = rtrim(str_replace(chr(92), '/', $home), '/');
        foreach ([$home . '/bin/' . $name, $home . '/mysql/bin/' . $name] as $try) {
            foreach ([$try, $try . '.exe'] as $p) {
                if (@is_file($p)) return $p;
            }
        }
    }
    $which = @shell_exec('where ' . escapeshellarg($name) . ' 2>NUL');
    return $which ? trim(explode(chr(10), trim($which))[0]) : null;
}
$dump = find_bin('mysqldump');
$cli  = find_bin('mysql');
if (!$dump || !$cli) {
    echo "  SKIP: mysqldump/mysql client not found. Run this on a machine that has them.\n";
    exit(0);
}

$cleaned = false;
register_shutdown_function(function () use ($root, $tmp, &$cleaned) {
    if ($cleaned) return;
    try { $root->exec('DROP DATABASE IF EXISTS `' . $tmp . '`'); } catch (Throwable $e) {}
});

$root->exec('DROP DATABASE IF EXISTS `' . $tmp . '`');
$root->exec('CREATE DATABASE `' . $tmp . '` CHARACTER SET utf8mb4');
echo '  created disposable database ' . $tmp . "\n";

$auth = '-u' . (DB_USER !== '' ? DB_USER : 'root');
$pass = DB_PASSWORD !== '' ? ('-p' . escapeshellarg(DB_PASSWORD)) : '';

// Schema only, no rows.
$schemaCmd = '"' . $dump . '" ' . $auth . ' ' . $pass . ' --no-data --routines --triggers --no-tablespaces '
    . escapeshellarg(DB_NAME) . ' 2>NUL';
$schema = shell_exec($schemaCmd);
if (!$schema) { echo "  could not dump the schema\n"; exit(1); }
file_put_contents($tmp . '_schema.sql', $schema);
shell_exec('"' . $cli . '" ' . $auth . ' ' . $pass . ' --default-character-set=utf8mb4 '
    . escapeshellarg($tmp) . ' < ' . escapeshellarg($tmp . '_schema.sql') . ' 2>&1');
@unlink($tmp . '_schema.sql');
$count = (int) $root->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$tmp'")->fetchColumn();
echo "  copied schema of $count tables (no rows)\n";
if ($count < 5) { echo "  schema copy looks wrong ($count tables)\n"; exit(1); }

$apply = function () use ($cli, $auth, $pass, $tmp, $file) {
    $cmd = '"' . $cli . '" ' . $auth . ' ' . $pass . ' --default-character-set=utf8mb4 '
        . escapeshellarg($tmp) . ' < ' . escapeshellarg($file) . ' 2>&1';
    $out = shell_exec($cmd);
    return trim((string) $out);
};

$e1 = $apply();
echo '  run 1: ' . ($e1 === '' ? 'clean' : "ERROR -> $e1") . "\n";
$e2 = $apply();
echo '  run 2: ' . ($e2 === '' ? 'clean' : "ERROR -> $e2") . "\n";

// The columns the application reads with ?? must exist. The code is
// written to tolerate the column being ABSENT (an un-migrated server), so
// this failure is silent at runtime and has to be asserted here.
//
// The list is derived from the migration being rehearsed rather than
// hardcoded to one file, because a hardcoded list is only ever right for
// the migration it was written for: pointing this at a different file
// (the default, document_walkin_only.sql, does have blocked_*) silently
// stops testing anything.
$fail = 0;
// Expected columns, parsed per (TABLE, COLUMN) pair.
//
// Scoping matters: a bare /COLUMN_NAME = 'x'/ picks up every guarded column
// in the file regardless of which table it belongs to, and then asserts them
// all on document_requests. Pointing this at document_walkin_only.sql then
// "fails" on sla_days, graduation_date and file_sha256 - all real columns,
// just not on that table. That is a broken test, not a broken migration.
preg_match_all(
    "/TABLE_NAME\s*=\s*'([a-z0-9_]+)'.*?COLUMN_NAME\s*=\s*'([a-z0-9_]+)'/is",
    file_get_contents($file),
    $mm,
    PREG_SET_ORDER
);
$expected = [];
foreach ($mm as $m) { $expected[$m[1]][$m[2]] = true; }
$expectedCount = array_sum(array_map('count', $expected));
echo '  asserting ' . $expectedCount . " column(s) across " . count($expected) . " table(s), declared by $file\n";
if (!$expectedCount) { echo "  *** migration declares no guarded columns ***\n"; $fail++; }

foreach ($expected as $tbl => $cols) {
    $have = array_column(
        $root->query("SHOW COLUMNS FROM `$tmp`.`$tbl`")->fetchAll(PDO::FETCH_ASSOC),
        'Type', 'Field'
    );
    foreach (array_keys($cols) as $c) {
        $ok = isset($have[$c]);
        printf("  %-22s.%-28s %s\n", $tbl, $c, $ok ? $have[$c] : '*** MISSING ***');
        if (!$ok) $fail++;
    }
}

// Structure check: every guarded column block must actually be EXECUTEd.
//
// Each block reads information_schema into @has, builds an ALTER into @s, and
// ends with PREPARE/EXECUTE/DEALLOCATE. Drop that last line and the migration
// still reports "clean" - the ALTER is built into a variable, never run, and
// the next block overwrites @s. The column is simply never created, with no
// error anywhere. That is not hypothetical: payment_receipt_waived_by was
// missing for exactly this reason.
//
// So count them. A guarded block that does not set @s, or a SET @s with no
// following EXECUTE, is a silent no-op and has to fail the rehearsal.
$sql = file_get_contents($file);
$blocks = preg_split('/SET @has :=/i', $sql);
array_shift($blocks);                       // preamble
$noPrepare = 0;
foreach ($blocks as $i => $b) {
    if (!preg_match("/SET \@s\s*:=/i", $b)) { continue; }
    if (!preg_match('/PREPARE\s+\w+\s+FROM\s+@s\s*;\s*EXECUTE/i', $b)) {
        $noPrepare++;
        $col = preg_match("/COLUMN_NAME = '([a-z0-9_]+)'/i", $b, $c) ? $c[1] : '(unknown)';
        echo "  *** block for $col never executes (@s built, no EXECUTE) ***\n";
    }
}
printf("  every guarded column is actually EXECUTEd: %s\n", $noPrepare === 0 ? 'yes' : "*** NO ($noPrepare) ***");
if ($noPrepare) $fail++;

// (The per-table column assertions above replaced an earlier block that
// hard-coded document_requests and walked $expected as a flat list. Once
// $expected became table-scoped, $c there was an array and
// isset($cols[$c]) threw "Illegal offset type" — a fatal that killed the
// run AFTER the columns had already printed, so it read as a pass with a
// stray exit code and the enum checks below never ran.)

// The enum-safety claim in the migration header, checked rather than
// trusted. This migration deliberately does NOT redefine document_status,
// so the rehearsal must leave the live enum byte-identical - including
// 'Awaiting_Payment' and 'Shipped', which registrar_ai.sql no longer
// lists. If someone later "fixes" this by adding a MODIFY COLUMN, this
// is what catches them.
$liveEnum = $root->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA='" . DB_NAME . "' AND TABLE_NAME='document_requests'
        AND COLUMN_NAME='document_status'"
)->fetchColumn();
$testEnum = $root->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA='$tmp' AND TABLE_NAME='document_requests'
        AND COLUMN_NAME='document_status'"
)->fetchColumn();
$enumKept = ($liveEnum === $testEnum);
echo '  document_status enum unchanged: ' . ($enumKept ? 'yes' : "*** NO ($liveEnum -> $testEnum) ***") . "\n";
if (!$enumKept) $fail++;
foreach (['Awaiting_Payment', 'Shipped'] as $v) {
    $has = $testEnum !== false && strpos($testEnum, "'" . $v . "'") !== false;
    echo "  enum still contains $v: " . ($has ? 'yes' : '*** NO ***') . "\n";
    if (!$has) $fail++;
}

$cat = array_column(
    $root->query("SHOW COLUMNS FROM `$tmp`.document_catalog")->fetchAll(PDO::FETCH_ASSOC),
    'Type', 'Field'
);
printf("  document_catalog.%-30s %s\n", 'sla_days', $cat['sla_days'] ?? '*** MISSING ***');
if (!isset($cat['sla_days'])) $fail++;

// And the application must not fatal against the result.
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/document_process.php';
$b = doc_blocker(['document_status' => 'Filed', 'balance' => 500.0, 'request_date' => date('Y-m-d H:i:s')]);
$ok = ($b['reason'] ?? null) !== null;
echo '  doc_blocker runs against the migrated schema: ' . ($ok ? 'yes' : '*** NO ***') . "\n";
if (!$ok) $fail++;
$src = doc_hold_source(999999);
echo '  doc_hold_source on a missing row returns null: ' . ($src === null ? 'yes' : '*** NO ***') . "\n";
if ($src !== null) $fail++;

$root->exec('DROP DATABASE `' . $tmp . '`');
$cleaned = true;
echo '  dropped ' . $tmp . "\n";
echo ($fail ? "  FAILED ($fail)\n" : "  migration is deployable and idempotent\n");
exit($fail ? 1 : 0);
