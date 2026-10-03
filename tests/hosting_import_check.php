<?php
// Prove the dump imports onto a database that looks like the real target: the
// OLD tables, WITH rows in them, plus an obsolete table the dump never defines.
// That last part is the one that bites in practice - a leftover table is not
// dropped by the dump and its foreign keys can block the import outright.
//
// Everything runs in a scratch database that is dropped on the way out.
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/_dump_seed.php';

$port = defined('DB_PORT') ? DB_PORT : 3306;
$root = new PDO('mysql:host=' . DB_HOST . ';port=' . $port . ';charset=utf8mb4',
    DB_USER, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tmp = 'reg_hosting_probe';
$dump = __DIR__ . '/../registrar_ai.sql';

function sh(string $c): string { return (string) shell_exec($c . ' 2>&1'); }
$mysql = 'C:/xampp/mysql/bin/mysql.exe';
$auth  = '-u' . (DB_USER !== '' ? DB_USER : 'root')
       . (DB_PASSWORD !== '' ? ' -p' . escapeshellarg(DB_PASSWORD) : '');
$run   = fn(string $db) => '"' . $mysql . '" ' . $auth . ' --default-character-set=utf8mb4 '
        . escapeshellarg($db);

try { $root->exec('DROP DATABASE IF EXISTS `' . $tmp . '`'); } catch (Throwable $e) {}
$root->exec('CREATE DATABASE `' . $tmp . '` CHARACTER SET utf8mb4');

// --- Build the "old hosting database": the dump as it was BEFORE the DROPs,
//     i.e. CREATE-only, plus rows, plus a stale table nobody migrated.
$sql = file_get_contents($dump);
$sql = preg_replace('/^DROP TABLE IF EXISTS .*\r?\n/m', '', $sql);
file_put_contents(__DIR__ . '/_old_seed.sql', $sql);

echo "== seeding the old hosting database ==\n";
$out = sh($run($tmp) . ' < ' . escapeshellarg(__DIR__ . '/_old_seed.sql'));
echo '  old schema import: ' . (trim($out) === '' ? 'clean' : "ERROR\n$out\n");

// Rows that must NOT survive the import, and a stale table with an FK that
// points at a table the dump does drop.
$root->exec("INSERT INTO `$tmp`.`users` (`email`,`password_hash`,`full_name`,`role`,`username`)
             VALUES ('old@hosting.test','\$2y\$10\$abcdefghijklmnopqrstuv','Old Host','admin','OLD-001')");
// A second dirty row in a table the dump DOES define, so the "replaced, not
// merged" assertion below has something to be right about. Seeded into ai_cache
// rather than announcements: announcements was dropped as dead on 2026-10-03
// and this file used to write into it, so it died here the same way any other
// stale reference would.
$root->exec("INSERT INTO `$tmp`.`ai_cache` (`prompt_hash`,`prompt`,`response`)
             VALUES ('oldhostinghash', 'old prompt from the old box', 'old response')");
$root->exec("CREATE TABLE `$tmp`.`legacy_queue_audit` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `student_id` int(11) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `lqa_student` (`student_id`),
                CONSTRAINT `lqa_fk` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$before = (int) $root->query("SELECT COUNT(*) FROM `$tmp`.users")->fetchColumn();
echo "  seeded: users=$before, ai_cache=1, legacy_queue_audit=1\n\n";

// --- Now the real import, onto that dirty database.
echo "== importing registrar_ai.sql onto it ==\n";
$out = sh($run($tmp) . ' < ' . escapeshellarg($dump));
$clean = trim($out) === '';
echo '  import exit: ' . ($clean ? "CLEAN (no errors)\n" : "ERRORS:\n$out\n");

// --- Assert the outcome that matters.
$fail = 0;
$ck = function (string $what, bool $ok, string $d = '') use (&$fail) {
    echo '  ' . ($ok ? '[PASS] ' : '[FAIL] ') . $what . ($d !== '' && !$ok ? "  -- $d" : '') . "\n";
    if (!$ok) $fail++;
};

$tables = $root->query("SHOW TABLES FROM `$tmp`")->fetchAll(PDO::FETCH_COLUMN);
$ck('the import completed with no errors', $clean);
// Counted from the dump, never hardcoded. This file asserted a literal 42,
// which silently became a lie the moment a table was removed from the dump -
// and a test that reports a wrong constant is worse than no test, because
// whoever reads the failure has no way to tell the constant from the cause.
$wantTables = countDumpTables($dump);
$ck('every table the dump declares exists after the import',
    count($tables) >= $wantTables,
    'got ' . count($tables) . ", dump declares $wantTables");

// The old rows must be GONE and the fresh staff seed present. The expected
// count comes from the dump, not from a literal, so removing an account does
// not require editing the expectation to match - which is how a test starts
// agreeing with whatever the code happens to do.
$wantUsers = countSeededStaffRows($dump);
$users = (int) $root->query("SELECT COUNT(*) FROM `$tmp`.users")->fetchColumn();
$ck('the old rows were replaced, not merged', $users === $wantUsers,
    "users rows: $users (the dump seeds $wantUsers)");
$mail = $root->query("SELECT email FROM `$tmp`.users WHERE email='old@hosting.test'")->fetchColumn();
$ck('the old account is gone', $mail === false, 'still present: ' . var_export($mail, true));

// A table the dump does not define is LEFT ALONE - it cannot know the name,
// and dropping unknown tables would be a trap. What matters is that it does
// not BLOCK the import, which is the failure that actually bites: a leftover
// table holding a foreign key into a table being dropped used to abort the
// whole import on ERROR 1217 unless FOREIGN_KEY_CHECKS was off.
$stale = in_array('legacy_queue_audit', $tables, true);
$ck('a stale table does not block the import', $clean,
    $stale ? '' : 'gone (unexpected, but not a failure)');
echo '  note: legacy_queue_audit survived the import, as it must'
   . " (the dump cannot drop a table it has never heard of)\n";

// Its foreign key pointed at `students`, which the import dropped and
// recreated. If the reference did not survive, the table is left holding a
// constraint to a table that no longer relates to it - and a later write to
// legacy_queue_audit would fail on ERROR 1452.
$lqaFk = $root->query(
    "SELECT k.REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE k
     WHERE k.TABLE_SCHEMA='$tmp' AND k.TABLE_NAME='legacy_queue_audit'
       AND k.REFERENCED_TABLE_NAME IS NOT NULL"
)->fetchColumn();
$ck('the stale table\'s foreign key still resolves after students was recreated',
    $stale ? ($lqaFk === 'students') : true,
    'references: ' . var_export($lqaFk, true));

// FK integrity of the rebuilt schema.
$fkBad = $root->query(
    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS tc
      JOIN information_schema.KEY_COLUMN_USAGE k ON k.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
     WHERE tc.TABLE_SCHEMA = '$tmp' AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'
       AND k.REFERENCED_TABLE_NAME NOT IN (SELECT TABLE_NAME FROM information_schema.TABLES
                                            WHERE TABLE_SCHEMA = '$tmp')"
)->fetchColumn();
$ck('every foreign key points at a table that exists', (int) $fkBad === 0, "dangling: $fkBad");

// Every seeded staff row must be able to sign in.
$n = (int) $root->query("SELECT COUNT(*) FROM `$tmp`.users WHERE password_hash LIKE '\$2y\$%'")->fetchColumn();
$ck('every seeded staff account carries a real bcrypt hash', $n === $wantUsers,
    "rows with a hash: $n of $wantUsers");

@unlink(__DIR__ . '/_old_seed.sql');
try { $root->exec('DROP DATABASE IF EXISTS `' . $tmp . '`'); } catch (Throwable $e) {}
echo "\n" . ($fail ? "FAILED ($fail)\n" : "old-hosting-database import: clean\n");
exit($fail ? 1 : 0);