<?php
// Keep registrar_ai.sql honest.
//
//   php tests/dump_freshness.php
//
// The install SQL is what a new host imports, so "the schema is right in
// the dump" is a claim that rots the moment someone adds a column to the
// live database and forgets the dump. This asserts the two agree, so the
// drift is caught at commit time rather than on a fresh server at 2am.
//
// It also proves the dump actually imports, and that the migrations in
// migrations/ are no-ops against it -- which is what makes a single-file
// install safe.
require_once __DIR__ . '/../shared/config.php';

$port = defined('DB_PORT') ? DB_PORT : 3306;
$dsn  = 'mysql:host=' . DB_HOST . ';port=' . $port . ';charset=utf8mb4';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$root = new PDO($dsn, DB_USER, DB_PASSWORD, $opts);
$tmp  = 'registrar_ai_dumptest';
$dump = __DIR__ . '/../registrar_ai.sql';

function find_bin(string $name): ?string
{
    foreach ([getenv('MYSQL_HOME'), getenv('XAMPP_ROOTPATH'), 'C:/xampp', '/usr/local/mysql', '/usr'] as $h) {
        if (!$h) continue;
        $h = rtrim(str_replace(chr(92), '/', $h), '/');
        foreach ([$h . '/bin/' . $name, $h . '/mysql/bin/' . $name] as $t) {
            foreach ([$t, $t . '.exe'] as $p) if (@is_file($p)) return $p;
        }
    }
    $w = @shell_exec('where ' . escapeshellarg($name) . ' 2>NUL');
    return $w ? trim(explode(chr(10), trim($w))[0]) : null;
}

$ok = 0; $bad = 0;
function t(string $what, bool $pass, string $detail = ''): void {
    global $ok, $bad;
    if ($pass) { $ok++; echo "  [PASS] $what\n"; }
    else       { $bad++; echo "  [FAIL] $what" . ($detail !== '' ? "  -- $detail" : '') . "\n"; }
}

// countSeededStaffRows() lives in tests/_dump_seed.php, shared with
// hosting_import_check.php - see the note there on why it is not a literal.
require_once __DIR__ . '/_dump_seed.php';

$cli = find_bin('mysql');
if (!$cli) { echo "  SKIP: the mysql client is not on this machine.\n"; exit(0); }

$auth = '-u' . (DB_USER !== '' ? DB_USER : 'root');
$pass = DB_PASSWORD !== '' ? ('-p' . escapeshellarg(DB_PASSWORD)) : '';
$cleaned = false;
register_shutdown_function(function () use ($root, $tmp, &$cleaned) {
    if ($cleaned) return;
    try { $root->exec('DROP DATABASE IF EXISTS `' . $tmp . '`'); } catch (Throwable $e) {}
});

// -- 1. The dump imports into an empty database, with no errors.
$root->exec('DROP DATABASE IF EXISTS `' . $tmp . '`');
$root->exec('CREATE DATABASE `' . $tmp . '` CHARACTER SET utf8mb4');
$out = shell_exec('"' . $cli . '" ' . $auth . ' ' . $pass . ' --default-character-set=utf8mb4 '
    . escapeshellarg($tmp) . ' < ' . escapeshellarg($dump) . ' 2>&1');
t('the dump imports into an empty database', trim((string) $out) === '', trim((string) $out));

// -- 1a. The dump must be RE-IMPORTABLE onto a database that already has its
//        tables. This is the whole reason every statement is DROP-then-CREATE.
//
//        The case that motivates it is the real one: a hosting box holding the
//        old tables. Importing a CREATE-only dump there stopped at the first
//        "table already exists" and left a half-built schema, so the fix was to
//        prefix each table with DROP TABLE IF EXISTS.
//
//        That fix is only trustworthy if a second import is actually clean.
//        Asserting it rather than assuming it, because the failure mode is
//        quiet in the worst way: the first ~40 tables import fine, the client
//        stops at the first error, and the operator gets a partial schema plus
//        a message they scroll past.
//
//        The staff INSERT is included in this deliberately. The users table has
//        UNIQUE keys on email and username, and the seed inserts four rows with
//        fixed ids, so if the DROP were ever dropped for one table the re-import
//        would die on "Duplicate entry" - which is exactly the regression this
//        catches.
$schema = fn(string $db, string $t) => $root->query("SHOW COLUMNS FROM `$db`.`$t`")->fetchAll(PDO::FETCH_ASSOC);
$names = fn(string $db, string $t) => array_column($schema($db, $t), 'Field');

$live  = $root->query('SHOW TABLES FROM `' . DB_NAME . '`')->fetchAll(PDO::FETCH_COLUMN);
$fresh = $root->query("SHOW TABLES FROM `$tmp`")->fetchAll(PDO::FETCH_COLUMN);

// Re-import onto the schema the first import just built. This is the check
// that makes DROP TABLE IF EXISTS trustworthy rather than hopeful - see the
// block comment above.
$out2 = (string) shell_exec('"' . $cli . '" ' . $auth . ' ' . $pass
    . ' --default-character-set=utf8mb4 ' . escapeshellarg($tmp)
    . ' < ' . escapeshellarg($dump) . ' 2>&1');
t('the dump re-imports onto a database that already has the tables',
    trim($out2) === '', trim($out2));

// The schema must be IDENTICAL afterwards. A re-import that errors is one
// problem; a re-import that quietly yields a different schema is worse.
$after = $root->query("SHOW TABLES FROM `$tmp`")->fetchAll(PDO::FETCH_COLUMN);
$sortedFresh = $fresh;
sort($after); sort($sortedFresh);
t('a re-import produces the same table set',
    $after === $sortedFresh,
    'added: ' . implode(',', array_diff($after, $sortedFresh))
          . ' / lost: ' . implode(',', array_diff($sortedFresh, $after)));

// Seeded rows must not double up either. users has UNIQUE keys on email and
// username, so a missing DROP surfaces here as "Duplicate entry" on the staff
// INSERT - the loudest possible signal, which is why it is asserted.
//
// The expected count is COUNTED FROM THE DUMP, never hardcoded. A literal 4
// here had to be edited by hand every time an account was added or removed,
// and the day someone changed the seed without changing the test the failure
// message would say "want 4" and mean nothing to whoever had to read it.
$wantStaff = countSeededStaffRows($dump);
$staff = (int) $root->query("SELECT COUNT(*) FROM `$tmp`.users")->fetchColumn();
t('a re-import does not duplicate the staff accounts',
    $staff === $wantStaff,
    "users rows after re-import: $staff (the dump seeds $wantStaff)");

// retired_student_sections is excluded, and deliberately.
//
// It is a leftover archive table from drop_student_section.sql, the
// migration that removed the students.section column and stashed the
// values here before doing so. That file has since been deleted, so no
// migration creates this table any more, and
// migrations/restore_student_section.sql explicitly guards every one of
// its UPDATEs on the table existing — precisely because it cannot assume
// the table is there.
//
// So a fresh install not having it is correct, not drift. Listing it here
// would mean adding a table that exists only to hold data from a migration
// nobody can run, and re-creating the archive table would make
// restore_student_section.sql run its UPDATEs against an empty table,
// which is harmless but means the restore "succeeds" while restoring
// nothing. Requiring it would be the wrong kind of correct.
//
// It remains in the LIVE database, which is why it shows up in the diff at
// all. Nothing reads it: only dump_freshness.php mentions it.
$knownAbsent = ['retired_student_sections'];
$missing = array_diff($live, $fresh, $knownAbsent);
t('every live table is defined in the dump',
    count($missing) === 0,
    'missing: ' . implode(', ', $missing));
// -- 1b. Every table the CODE reads is defined in the dump.
//
// The check above compares the dump against the live database, so a table
// missing from BOTH passes it. That is exactly how card_readers got here:
// five files read it without a guard - api/card-readers.php,
// registrar/rfid-readers.php, registrar/rfid-kiosk.php, api/rfid-scan.php and
// shared/rfid_helpers.php - and it existed in neither the dump nor the live
// database, so a fresh install would have had a dead Readers page, a dead
// Kiosk, and a card-readers endpoint answering "table not found".
//
// Scans the application source for table names in SQL position and checks them
// against the freshly imported schema. SQL keywords are filtered out, so what
// is left are identifiers the code expects to be real tables.
// Comments and string literals are stripped before the scan. Without that,
// prose in a docblock matches too: document_process.php says
// "Deliberately separate from doc_blocker()", and that was reported as a
// missing table. Real table names here are lower_snake case with at least one
// underscore, which filters the remaining single English words.
$sqlWords = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__)));
foreach ($rii as $file) {
    $p = $file->getPathname();
    if (!is_file($p) || substr($p, -4) !== '.php' && substr($p, -4) !== '.sql') continue;
    // migrations/ is excluded for the same reason tests/ and backups/ are: this
    // check asks what the APPLICATION reads, and a migration is not the
    // application. A migration targets a database that already exists; it
    // legitimately names tables a fresh install does not ship (it may purge
    // one, or upgrade one), and demanding the dump define every table a
    // migration happens to mention turns an upgrade note into a requirement
    // on the fresh install.
    if (preg_match('#/(vendor|node_modules|\.git|tests|backups|migrations)/#', str_replace(chr(92), '/', $p))) continue;
    $src = @file_get_contents($p);
    if (!$src) continue;
    // Comments and SINGLE-quoted literals are stripped; double-quoted strings
    // are NOT. A naive "#[^"]*"# pairs up quotes across line boundaries and
    // swallows whole queries - which is how this check passed while every table
    // was missing: the scanner never saw a single one. Single quotes do not
    // have that problem, because they are balanced within a line in practice.
    $src = preg_replace('#/\*.*?\*/#s', ' ', $src);
    $src = preg_replace('#(^|\s)//[^\n]*#', '$1', $src);
    $src = preg_replace('#\'[^\']*\'#', "''", $src);
    if (preg_match_all('#\b(?:FROM|JOIN|INTO|UPDATE)\s+`?([a-z_][a-z0-9_]*)`?#i', $src, $m)) {
        foreach ($m[1] as $w) $sqlWords[strtolower($w)] = true;
    }
}
$absent = [];
foreach (array_keys($sqlWords) as $w) {
    if (!preg_match('#^[a-z][a-z0-9]*(_[a-z0-9]+)+$#', $w)) continue;
    // Not tables:
    //   current_timestamp / information_schema - SQL, not storage.
    //   registrar_ai                         - the database's own name.
    //   exit_clearances                      - named in a document_templates.php
    //       docblock as a table that was planned and never built.
    //   last_read_id                         - a COLUMN in an
    //       ON DUPLICATE KEY UPDATE clause, which the FROM/JOIN scan cannot
    //       tell apart from a table reference.
    //   opens_time, cutoff_forced_at         - the same false positive, from
    //       api/queue.php's two ON DUPLICATE KEY UPDATE clauses on
    //       queue_day_settings. queue_day_settings itself IS in the dump;
    //       these two are its columns, picked up for the same reason
    //       last_read_id is.
    //   retired_student_sections             - an optional archive table that
    //       restore_student_section.sql guards with an information_schema
    //       check precisely because a fresh install never has it. Requiring it
    //       would break the guarded migration this check exists to protect.
    if (in_array($w, ['current_timestamp', 'information_schema', 'registrar_ai',
        'exit_clearances', 'last_read_id', 'opens_time', 'cutoff_forced_at',
        'retired_student_sections'], true)) continue;
    if (in_array($w, $fresh, true)) continue;
    $absent[] = $w . (in_array($w, $live, true) ? ' (also absent from live)' : '');
}
sort($absent);
t('every table the code reads is defined in the dump',
    count($absent) === 0,
    'absent: ' . implode(', ', array_slice($absent, 0, 8)));

// -- 2. Every column the code reads exists in both. This is the check that
//       would have caught the missing sla_days / blocked_* columns.
$drift = array();
foreach (array_intersect($live, $fresh) as $t) {
    $miss = array_diff($names(DB_NAME, $t), $names($tmp, $t));
    if ($miss) $drift[] = "$t: " . implode(',', $miss);
}
t('no live column is missing from the dump', count($drift) === 0, implode(' | ', array_slice($drift, 0, 4)));

// -- 3. The columns this feature depends on, named explicitly so the
//       intent survives someone refactoring the loop above.
$need = [
    ['document_catalog', 'sla_days'],
    ['document_requests', 'blocked_reason'],
    ['document_requests', 'blocked_since'],
    ['document_requests', 'blocked_source'],
    ['document_requests', 'source'],
    ['document_requests', 'counter'],
];
$absent = array();
foreach ($need as list($t, $c)) {
    if (!in_array($c, $names($tmp, $t), true)) $absent[] = "$t.$c";
}
t('the walk-in document columns are present', count($absent) === 0, implode(', ', $absent));

// -- 4. Seeded data is limited to the staff accounts. Two rules:
//         - only users rows, and only for admin / registrar / nurse
//         - never a student, because a student login is personal data
//       Everything else the dump touches would be someone else's data or
//       someone else's business policy arriving on a new install.
$allowed = array('admin', 'registrar', 'nurse');
$seededTables = array();
foreach ($fresh as $tbl) {
    $n = (int) $root->query("SELECT COUNT(*) FROM `$tmp`.`$tbl`")->fetchColumn();
    if ($n > 0) $seededTables[$tbl] = $n;
}
t('only the users table is seeded', array_keys($seededTables) === array('users'),
    implode(', ', array_keys($seededTables)));

$roles = $root->query("SELECT DISTINCT role FROM `$tmp`.users")->fetchAll(PDO::FETCH_COLUMN);
sort($roles);
$want = $allowed; sort($want);
t('every seeded account is a staff role', $roles === $want, 'found: ' . implode(',', $roles));

// EXACTLY ONE account per role. The distinct-role check above passes just as
// happily with two admins, which is what the seed used to carry: a second
// admin sharing the FIRST admin's password hash, on an address that had
// already collided with a real student's and silently destroyed their portal
// account. So assert the per-role count, not just the set of roles.
//
// This is the assertion that would have caught it, and it is cheap: a query
// against the imported database, no new machinery.
$perRole = $root->query(
    "SELECT role, COUNT(*) AS n FROM `$tmp`.users GROUP BY role HAVING n > 1"
)->fetchAll(PDO::FETCH_KEY_PAIR);
t('exactly one seeded account per role', count($perRole) === 0,
    'roles with duplicates: '
    . implode(', ', array_map(fn($r, $n) => "$r x$n", array_keys($perRole), $perRole)));

// And the shared-hash check, because "one per role" and "distinct passwords"
// are different claims: a single admin is still a problem if its hash is
// published in the repository, which is why the header tells the operator to
// change it. Duplicated hashes across accounts are the version of this that
// can be caught here, and cannot be fixed by anything but seeding fewer rows.
$dupHashes = $root->query(
    "SELECT COUNT(*) FROM (SELECT password_hash FROM `$tmp`.users
                            GROUP BY password_hash HAVING COUNT(*) > 1) d"
)->fetchColumn();
t('no two seeded accounts share a password', (int) $dupHashes === 0,
    "duplicate hash groups: $dupHashes");

$students = (int) $root->query("SELECT COUNT(*) FROM `$tmp`.users WHERE role = 'student'")->fetchColumn();
t('no student account is seeded', $students === 0, "rows: $students");

// A student row would also need a students row behind it, so this catches
// an accidental reseed of that table too.
$stu = (int) $root->query("SELECT COUNT(*) FROM `$tmp`.students")->fetchColumn();
t('no student records are seeded', $stu === 0, "rows: $stu");

$cat = (int) $root->query("SELECT COUNT(*) FROM `$tmp`.document_catalog")->fetchColumn();
t('no document catalog is seeded', $cat === 0, "rows: $cat");

// The seeded accounts must actually be able to sign in, or the dump is
// shipping rows that only look like a way in.
$hasHash = 0;
foreach ($root->query("SELECT password_hash FROM `$tmp`.users") as $u) {
    if (strpos($u['password_hash'], '$2y$') === 0) $hasHash++;
}
t('seeded accounts carry a real bcrypt hash', $hasHash > 0, "rows with a hash: $hasHash");

// -- 5. A fresh install still needs the catalog, and with no staff row it
//       would have no way in at all. The bootstrap is what makes the
//       no-catalog choice safe.// -- 5. A fresh install with no rows cannot run, so the two required tables
//       must be reachable without the browser: login.php resolves against
//       the users table and api/users.php needs an existing admin, so an
//       empty one is a dead end rather than a fresh start. The bootstrap is
//       what makes "no seed data" safe to choose.
$bootPath = __DIR__ . '/../create_admin.php';
$boot = is_file($bootPath) ? file_get_contents($bootPath) : '';
t('create_admin.php exists', $boot !== '');
t('the bootstrap is CLI only', strpos($boot, "php_sapi_name() !== 'cli'") !== false);
t('the bootstrap can create an account', strpos($boot, "'users'") !== false);
t('the bootstrap can load a document catalog', strpos($boot, 'document_catalog') !== false);
t('the bootstrap enforces the app password policy', strpos($boot, 'checkPasswordPolicy') !== false);
t('the bootstrap defaults to creating no catalog', strpos($boot, "'N'") !== false);

// -- 6. The migrations must be no-ops here. If one of them still finds
//       work to do, the dump is not the whole story and a single-file
//       install would be a lie.
//
//       EXCEPT the two receive-student files, which are deliberately NOT
//       no-ops and must never be treated as if they were:
//
//         seed_receive_students.sql   inserts demo enrollment rows. It is
//           the substitute for the Enrollment System feed, which is a
//           separate system that writes that table. A fresh install has no
//           applicants, so this has real work to do by definition.
//
//         clear_receive_students.sql  reports what it would delete. Every
//           DELETE is behind @execute, which is 0 by default, so it
//           deletes nothing on a normal run — but it SELECTs to print the
//           report, and that output is the whole point of running it.
//
//       Both "fail" this check for printing or inserting rather than for
//       schema drift, which is why they were never counted as defects.
//       Asserting they are silent would mean deleting the report or the
//       demo data to satisfy a test, which is the wrong trade.
$notNoOps = ['seed_receive_students.sql', 'clear_receive_students.sql'];
foreach (glob(__DIR__ . '/../migrations/*.sql') as $m) {
    $out = shell_exec('"' . $cli . '" ' . $auth . ' ' . $pass . ' --default-character-set=utf8mb4 '
        . escapeshellarg($tmp) . ' < ' . escapeshellarg($m) . ' 2>&1');
    $name = basename($m);
    if (in_array($name, $notNoOps, true)) {
        // What matters for these two is that the schema is untouched and
        // nothing ERRORs. Row output is expected.
        $errors = preg_grep('/^ERROR/', array_filter(explode("\n", trim((string) $out))));
        t('data migration runs without error on a fresh import: ' . $name,
            count($errors) === 0, implode(' | ', $errors));
        continue;
    }
    t('migration is a no-op on a fresh import: ' . $name,
        trim((string) $out) === '', trim((string) $out));
}

// -- 7. The guarded migrations must still ADD their columns to an old
//       database. Guarding is only safe in one direction. The test above
//       proves a migration does nothing where the column already exists;
//       this one proves it still does the work where it does not.
//
//       This is the failure mode a guard introduces silently: wrap an
//       ALTER in an existence check, and if the check is ever wrong the
//       migration quietly becomes a no-op on the databases that actually
//       needed it — the pre-existing ones — while every fresh-install test
//       still passes. An upgrade path that silently stops upgrading is
//       worse than one that errors.
//
//       So: drop each guarded column from the fresh database, replay the
//       migration that owns it, and assert the column came back.
$guarded = [
    'security_hardening_phase1.sql' => [
        // Each group is dropped SEPARATELY and the migration replayed
        // between them. Dropping them all at once would never catch a
        // guard that checks one column and adds two: with everything
        // absent, that guard behaves correctly.
        //
        // The partial state is the one that breaks. A half-run migration,
        // a manual fix or an interrupted deploy leaves SOME columns
        // present, and the mysql client stops at the first error, so a
        // failed statement silently skips every statement after it.
        // That is how a migration ends up not installing the thing it
        // exists to install, with no error anyone would read.
        ['full', [['users', 'password_changed_at']]],
        ['full', [['otp_codes', 'verify_attempts']]],
        ['full', [['students', 'email_is_placeholder']]],
        ['partial', [['students', 'email_bounced_at']]],
        ['partial', [['users', 'email_bounced_at']]],
    ],
    'add_previous_school_fields.sql' => [
        ['partial', [['students', 'previous_school']]],
        ['partial', [['students', 'school_year_graduated']]],
        ['partial', [['students', 'last_year_level_completed']]],
        ['full', [['students', 'previous_school'], ['students', 'school_year_graduated'], ['students', 'last_year_level_completed']]],
    ],
];
foreach ($guarded as $file => $groups) {
    $path = __DIR__ . '/../migrations/' . $file;
    if (!is_file($path)) { continue; }
    foreach ($groups as [$kind, $columns]) {
        foreach ($columns as [$tbl, $col]) {
            try { $root->exec("ALTER TABLE `$tmp`.`$tbl` DROP COLUMN `$col`"); } catch (Throwable $e) {}
        }
        $out = (string) shell_exec('"' . $cli . '" ' . $auth . ' ' . $pass . ' --default-character-set=utf8mb4 '
            . escapeshellarg($tmp) . ' < ' . escapeshellarg($path) . ' 2>&1');
        t("migration runs clean with only some columns present: $file ($kind)",
            trim($out) === '', trim($out));
        foreach ($columns as [$tbl, $col]) {
            $have = $root->query("SELECT COUNT(*) AS n FROM information_schema.COLUMNS"
                . " WHERE TABLE_SCHEMA = '$tmp' AND TABLE_NAME = '$tbl' AND COLUMN_NAME = '$col'")
                ->fetch(PDO::FETCH_ASSOC);
            t("guard still adds $tbl.$col to an older database", (int) ($have['n'] ?? 0) === 1);
        }
    }
}

$root->exec('DROP DATABASE `' . $tmp . '`');
$cleaned = true;
printf("\n  %d passed, %d failed\n", $ok, $bad);
exit($bad ? 1 : 0);
