<?php
// tools/migration_audit.php -- report which migrations/ files are applied to
// the live database.
//
//   php tools/migration_audit.php
//
// This project has no migration ledger (no `migrations` table, no artisan, no
// Laravel), and every migration is written to be idempotent so it can be re-run
// against a database that already has it. That is exactly why "is it applied?"
// cannot be answered by running the file: a re-run is a silent no-op by design.
// So this reads the live schema and compares it against what each file declares.
// Read-only: it never writes and never executes the .sql.

require_once __DIR__ . '/../shared/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo 'DB: ' . DB_NAME . ' @ ' . DB_HOST . ':' . DB_PORT . ' as ' . DB_USER . PHP_EOL . PHP_EOL;

// ---- live schema snapshot ----------------------------------------------
$tables = [];
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $r) {
    $tables[strtolower($r[0])] = true;
}
$columns = $indexes = [];
foreach (array_keys($tables) as $t) {
    $columns[$t] = $indexes[$t] = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $columns[$t][strtolower($c['Field'])] = $c;
    }
    foreach ($pdo->query("SHOW INDEX FROM `$t`")->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $indexes[$t][strtolower($i['Key_name'])] = true;
    }
}

// Strip SQL comments. Essential, not cosmetic: these files narrate in `--`
// comments, and prose like "so SHOW CREATE TABLE reads as the process"
// otherwise parses as a CREATE TABLE of a table named "reads".
function strip_comments(string $s): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', preg_replace('/^\s*--.*$/m', '', $s));
}

// Reduce a declared type to a comparable form (SHOW COLUMNS omits some attrs).
function norm_type(string $t): string
{
    $t = strtolower(trim((string) preg_replace('/\s+/', ' ', trim($t))));
    return trim((string) preg_replace('/\s*(character set|charset|collate)\s+[a-z0-9_]+/', '', $t));
}
/** Extract the schema objects a migration declares. */
function declared_objects(string $sql): array
{
    $sql = strip_comments($sql);
    $tables = $columns = $indexes = $types = [];

    if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sql, $m)) {
        foreach ($m[1] as $t) $tables[] = strtolower($t);
    }
    if (preg_match_all('/ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?(.*?);/is', $sql, $m, PREG_SET_ORDER)) {
        $skip = ['constraint','foreign','primary','unique','key','index','fulltext'];
        foreach ($m as $st) {
            $tbl = strtolower($st[1]);
            // Scan the whole ALTER body, not line by line: an ADD COLUMN list
            // wraps across lines, and a line scan drops every column but the first.
            if (preg_match_all('/ADD\s+(?:COLUMN\s+)?`?([A-Za-z0-9_]+)`?\s+[a-z]/i', $st[2], $cm)) {
                foreach ($cm[1] as $c) {
                    $c = strtolower($c);
                    if (!in_array($c, $skip, true)) $columns[] = "$tbl.$c";
                }
            }
            // MODIFY/CHANGE carries a *type* expectation, not just existence:
            // student_status_five_values.sql only narrows an ENUM, so the column
            // exists either way and existence alone would false-positive.
            //
            // The type is matched WHOLE, including the full value list of an
            // enum/set. An earlier version captured `([^,;]*)` after the type
            // name, which stops at the first comma - and the first comma in
            // `enum('walk_in','online')` is inside the type. Every enum was
            // therefore read as enum('walk_in and reported as a mismatch
            // against a live table that matched it exactly, so four applied
            // migrations showed as pending. Also not a real defect, just a
            // parser that could not see past a comma.
            $ty = "(?:enum|set)\\s*\\([^)]*\\)"
                . '|(?:var)?(?:char|binary|blob|text)\\s*(?:\\(\\s*\\d+\\s*\\))?'
                . '|(?:big|small|tiny|medium)?int(?:eger)?(?:\\s*\\(\\s*\\d+\\s*\\))?'
                . '|decimal(?:\\s*\\(\\s*\\d+\\s*,\\s*\\d+\\s*\\))?'
                . '|(?:double|float|real)(?:\\s*\\(\\s*\\d+\\s*,\\s*\\d+\\s*\\))?'
                . '|date|datetime|timestamp|time|year|json';
            if (preg_match_all('/(?:MODIFY|CHANGE)(?:\s+COLUMN)?\s+`?([A-Za-z0-9_]+)`?\s+(' . $ty . ')/i', $st[2], $tm, PREG_SET_ORDER)) {
                foreach ($tm as $x) {
                    $types[] = ['t' => $tbl, 'c' => strtolower($x[1]), 'ty' => norm_type($x[2])];
                }
            }
            if (preg_match_all('/ADD\s+(?:UNIQUE\s+|FULLTEXT\s+)?(?:INDEX|KEY)\s+`?([A-Za-z0-9_]+)`?/i', $st[2], $im)) {
                foreach ($im[1] as $i) $indexes[] = "$tbl." . strtolower($i);
            }
        }
    }
    if (preg_match_all('/CREATE\s+(?:UNIQUE\s+)?INDEX\s+`?([A-Za-z0-9_]+)`?\s+ON\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) $indexes[] = strtolower($x[2]) . '.' . strtolower($x[1]);
    }
    return [array_values(array_unique($tables)), array_values(array_unique($columns)),
            array_values(array_unique($indexes)), $types];
}

// ---- compare each migration against the live schema ---------------------
$counts = ['APPLIED' => 0, 'PARTIAL' => 0, 'PENDING' => 0, 'N/A' => 0];
$report = [];

foreach (glob(__DIR__ . '/../migrations/*.sql') as $file) {
    $name = basename($file);
    [$tabs, $cols, $idxs, $types] = declared_objects(file_get_contents($file));

    $need = [];
    foreach ($tabs as $t) $need[] = ['k' => 'table', 't' => $t, 'n' => $t];
    foreach ($cols as $c) { [$a, $b] = explode('.', $c, 2); $need[] = ['k' => 'column', 't' => $a, 'n' => $b]; }
    foreach ($idxs as $i) { [$a, $b] = explode('.', $i, 2); $need[] = ['k' => 'index',  't' => $a, 'n' => $b]; }
    foreach ($types as $x)    $need[] = ['k' => 'type',   't' => $x['t'], 'n' => $x['c'], 'ty' => $x['ty']];

    if (!$need) { $report[$name] = ['N/A', [], 0]; $counts['N/A']++; continue; }

    $missing = [];
    foreach ($need as $n) {
        $t = $n['t'];
        if (!isset($tables[$t])) { $missing[] = "table $t (needed for {$n['n']})"; continue; }
        if ($n['k'] === 'table')  continue;                  // exists, checked above
        if ($n['k'] === 'column' && !isset($columns[$t][$n['n']])) { $missing[] = "$t.{$n['n']}"; continue; }
        if ($n['k'] === 'index'  && !isset($indexes[$t][$n['n']])) { $missing[] = "index $t.{$n['n']}"; continue; }
        if ($n['k'] === 'type') {
            $live = norm_type($columns[$t][$n['n']]['Type'] ?? '');
            $want = $n['ty'];
            // For an ENUM/SET the whole value list is the contract, so compare
            // it whole - these migrations' whole point is narrowing a list.
            // For anything else compare the type name only: a migration may
            // declare more (a trailing NOT NULL, an UNSIGNED) than SHOW COLUMNS
            // reports in Type, and that difference is not drift.
            if (preg_match('/^(enum|set)\s*\((.*)\)$/i', $want, $wm)
                && preg_match('/^(enum|set)\s*\((.*)\)$/i', $live, $lm)) {
                // The declaration usually sits inside a MySQL string literal
                // (SET @stmt := 'ALTER ... enum(''enrolled'',...)'), where each
                // quote is doubled to escape it. MySQL un-doubles that on
                // PREPARE, so un-double here too - otherwise the want side
                // reads ''enrolled'' and every enum looks like a mismatch.
                $wv = array_map('trim', explode(',', str_replace("''", "'", $wm[2])));
                $lv = array_map('trim', explode(',', $lm[2]));
                sort($wv); sort($lv);
                if ($wv !== $lv) {
                    $missing[] = "$t.{$n['n']} enum (want " . implode('|', $wv)
                                . ', live ' . implode('|', $lv) . ')';
                }
                continue;
            }
            $lh = preg_split('/\s+/', $live)[0];
            $wh = preg_split('/\s+/', $want)[0];
            if ($lh !== $wh) {
                $missing[] = "$t.{$n['n']} type (want $wh, live $lh)";
            }
        }
    }

    if (!$missing)                           { $st = 'APPLIED'; }
    elseif (count($missing) === count($need)) { $st = 'PENDING'; }
    else                                       { $st = 'PARTIAL'; }
    $counts[$st]++;
    $report[$name] = [$st, $missing, count($need)];
}

// ---- output --------------------------------------------------------------
$mark = ['APPLIED' => '[ OK ]', 'PARTIAL' => '[ !! ]', 'PENDING' => '[ -- ]', 'N/A' => '[n/a ]'];
echo "SCHEMA-DETECTABLE MIGRATIONS\n" . str_repeat('-', 72) . PHP_EOL;
foreach ($report as $name => $r) {
    [$st, $missing, $n] = $r;
    printf("%s %-38s %s\n", $mark[$st], $name, $n ? "$n object(s)" : 'no schema objects');
    if ($st === 'PARTIAL' || $st === 'PENDING') {
        foreach ($missing as $m) echo "         missing: $m\n";
    }
}

echo PHP_EOL . "NOT SCHEMA-DETECTABLE\n" . str_repeat('-', 72) . PHP_EOL;
foreach (glob(__DIR__ . '/../migrations/*') as $f) {
    if (substr($f, -4) !== '.sql') echo '[n/a ] ' . basename($f) . "  (notes, not SQL)\n";
}
foreach ($report as $name => $r) {
    if ($r[0] === 'N/A') echo "[n/a ] $name  (data-only -- presence is rows, not schema)\n";
}

echo PHP_EOL;
printf("applied %d | partial %d | pending %d | not-detectable %d\n",
    $counts['APPLIED'], $counts['PARTIAL'], $counts['PENDING'], $counts['N/A']);