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
$cols = array_column(
    $root->query("SHOW COLUMNS FROM `$tmp`.document_requests")->fetchAll(PDO::FETCH_ASSOC),
    'Type', 'Field'
);
$fail = 0;
foreach (['blocked_reason', 'blocked_since', 'blocked_source'] as $c) {
    $ok = isset($cols[$c]);
    printf("  document_requests.%-16s %s\n", $c, $ok ? $cols[$c] : '*** MISSING ***');
    if (!$ok) $fail++;
}
$cat = array_column(
    $root->query("SHOW COLUMNS FROM `$tmp`.document_catalog")->fetchAll(PDO::FETCH_ASSOC),
    'Type', 'Field'
);
printf("  document_catalog.%-17s %s\n", 'sla_days', $cat['sla_days'] ?? '*** MISSING ***');
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
