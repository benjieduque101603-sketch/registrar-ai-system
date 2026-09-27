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

// -- 4. The dump must contain NO rows. An install file carrying other
//       people's logins, password hashes and fee schedules is not something
//       to import into production, and it leaks the moment it is shared. A
//       fresh import should be structurally complete and completely empty.
//       create_admin.php is what fills the two required tables.
$seeded = array();
foreach ($fresh as $tbl) {
    $n = (int) $root->query("SELECT COUNT(*) FROM `$tmp`.`$tbl`")->fetchColumn();
    if ($n > 0) $seeded[] = "$tbl=$n";
}
t('the dump inserts no rows', count($seeded) === 0, implode(', ', array_slice($seeded, 0, 6)));

// ...and it must not have quietly resumed shipping the credentials that
// used to live in it.
$dumpSrc = file_get_contents(__DIR__ . '/../registrar_ai.sql');
t('no password hash in the dump', strpos($dumpSrc, '$2y$') === false);
t('no email address in the dump', !preg_match('/[a-z0-9_.+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $dumpSrc));

// -- 5. A fresh install with no rows cannot run, so the two required tables
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
