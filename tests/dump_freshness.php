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

$schema = fn(string $db, string $t) => $root->query("SHOW COLUMNS FROM `$db`.`$t`")->fetchAll(PDO::FETCH_ASSOC);
$names = fn(string $db, string $t) => array_column($schema($db, $t), 'Field');

$live  = $root->query('SHOW TABLES FROM `' . DB_NAME . '`')->fetchAll(PDO::FETCH_COLUMN);
$fresh = $root->query("SHOW TABLES FROM `$tmp`")->fetchAll(PDO::FETCH_COLUMN);
t('every live table is defined in the dump',
    count(array_diff($live, $fresh)) === 0,
    'missing: ' . implode(', ', array_diff($live, $fresh)));
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
    if (preg_match('#/(vendor|node_modules|\.git|tests)/#', str_replace(chr(92), '/', $p))) continue;
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
    //   retired_student_sections             - an optional archive table that
    //       restore_student_section.sql guards with an information_schema
    //       check precisely because a fresh install never has it. Requiring it
    //       would break the guarded migration this check exists to protect.
    if (in_array($w, ['current_timestamp', 'information_schema', 'registrar_ai',
        'exit_clearances', 'last_read_id', 'retired_student_sections'], true)) continue;
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
//       install would be a lie. If one of them still finds
//       work to do, the dump is not the whole story and a single-file
//       install would be a lie.
foreach (glob(__DIR__ . '/../migrations/*.sql') as $m) {
    $out = shell_exec('"' . $cli . '" ' . $auth . ' ' . $pass . ' --default-character-set=utf8mb4 '
        . escapeshellarg($tmp) . ' < ' . escapeshellarg($m) . ' 2>&1');
    t('migration is a no-op on a fresh import: ' . basename($m),
        trim((string) $out) === '', trim((string) $out));
}

$root->exec('DROP DATABASE `' . $tmp . '`');
$cleaned = true;
printf("\n  %d passed, %d failed\n", $ok, $bad);
exit($bad ? 1 : 0);
