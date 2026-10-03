<?php
// ============================================================================
//  CLEANUP: e2e test residue
//
//  The e2e scripts under tests/ create real students, users, guardians and
//  document requests, then delete them again in a cleanup() call. When a run
//  dies before cleanup, or is interrupted, those rows survive. This database
//  had accumulated 57 of them.
//
//  NOT THE SAME AS THE SEEDS. migrations/seed_receive_students.sql is marked
//  with `SEEDDATA-%` + `%@seed.receive.test` and matches ZERO rows here, so
//  it was never applied. What this removes is the e2e residue, identified by
//  the reserved-TLD domain the scripts use.
//
//  HOW TO RUN
//    php tests/clear_e2e_residue.php            dry run, deletes nothing
//    php tests/clear_e2e_residue.php --execute  deletes, after a backup
//  ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

$execute = in_array('--execute', $argv, true);
$db      = Database::getInstance();

// ----------------------------------------------------------------------------
//  WHO IS A TEST ROW
//  Two markers, and the second one matters:
//
//  1. `email LIKE '%@example.test'` - a reserved TLD (RFC 6761) that cannot
//     be a real domain.
//  2. `username LIKE 'pickup_staff_%'` - tests/document_pickup_email.php
//     creates a desk account with a username and NO email at all, so an
//     email-only filter leaves it behind forever. It was found still sitting
//     in this database after the first pass.
//
//  Real users are @bestlink.edu.ph or gmail.com with human names; none of
//  them match either marker.
// ----------------------------------------------------------------------------
$TEST_MAIL = '%@example.test';

// A test user can outlive its student row if a run died between the two
// inserts, so both directions are matched.
$testStudents = $db->fetchAll(
    'SELECT DISTINCT s.id, s.student_number, s.email
       FROM students s
       LEFT JOIN users u ON u.student_id = s.id
      WHERE s.email LIKE ? OR u.email LIKE ?
      ORDER BY s.id',
    [$TEST_MAIL, $TEST_MAIL]
);
$testUsers = $db->fetchAll(
    "SELECT id, email, username, full_name, role
       FROM users
      WHERE email LIKE ?
         OR username LIKE 'pickup_staff\_%'
      ORDER BY id",
    [$TEST_MAIL]
);
$testUserIds = array_map('intval', array_column($testUsers, 'id'));
$studentIds  = array_map('intval', array_column($testStudents, 'id'));

echo PHP_EOL, '  FOREIGN KEYS POINTING AT users.id', PHP_EOL;
foreach ($db->fetchAll(
    "SELECT TABLE_NAME AS t, COLUMN_NAME AS c
       FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = DATABASE()
        AND REFERENCED_TABLE_NAME = 'users'
        AND REFERENCED_COLUMN_NAME = 'id'
      ORDER BY TABLE_NAME"
) as $fk) {
    printf('    %-24s .%s%s', $fk['t'], $fk['c'], PHP_EOL);
}

echo PHP_EOL, '  FOREIGN KEYS POINTING AT students.id', PHP_EOL;
foreach ($db->fetchAll(
    "SELECT TABLE_NAME AS t, COLUMN_NAME AS c
       FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = DATABASE()
        AND REFERENCED_TABLE_NAME = 'students'
        AND REFERENCED_COLUMN_NAME = 'id'
      ORDER BY TABLE_NAME"
) as $fk) {
    printf('    %-24s .%s%s', $fk['t'], $fk['c'], PHP_EOL);
}

echo str_repeat('=', 74), PHP_EOL;

// ----------------------------------------------------------------------------
//  COUNT FIRST, so the report says what it matched before it changes anything.
// ----------------------------------------------------------------------------
// ----------------------------------------------------------------------------
//  CHILD ROWS - DISCOVERED, NOT GUESSED
//
//  A hardcoded list of child tables is how the first attempt at this script
//  failed: it missed audit_logs.user_id and the DELETE was refused by the FK.
//  So the tables are read out of information_schema instead, which cannot
//  fall behind the schema. A table added later is cleaned up automatically.
//
//  Every (table, column) here points at users.id or students.id. Deleting
//  these first is what lets the parent rows go.
// ----------------------------------------------------------------------------
$fkRows = $db->fetchAll(
    "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS ref
       FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = DATABASE()
        AND REFERENCED_TABLE_NAME IN ('users', 'students')
        AND REFERENCED_COLUMN_NAME = 'id'
      ORDER BY TABLE_NAME, COLUMN_NAME"
);

$studentChildFks = [];   // table.column => holds a student id we are deleting
$userChildFks    = [];   // table.column => holds a user id we are deleting
foreach ($fkRows as $fk) {
    $key = $fk['t'] . '.' . $fk['c'];

    // users.student_id is the one FK that must NOT be deleted through. Wiping
    // it here would remove the user row itself, and the whole point of the
    // NULL-ing in the delete step is that the link is broken while the row
    // lives until its own deliberate delete. So it is skipped here.
    if ($fk['ref'] === 'students' && $fk['t'] === 'users') {
        continue;
    }

    if ($fk['ref'] === 'students') {
        $studentChildFks[$key] = $studentIds;
    } elseif ($fk['ref'] === 'users') {
        $userChildFks[$key] = $testUserIds;
    }
}

$plan = [];
foreach ($studentChildFks as $key => $ids) {
    if (!$ids) {
        continue;
    }
    [$t, $c] = explode('.', $key);
    $in      = implode(',', $ids);
    $n       = (int) $db->fetchColumn("SELECT COUNT(*) FROM `$t` WHERE `$c` IN ($in)");
    if ($n > 0) {
        $plan[$key] = $n;
    }
}
$planUser = [];
foreach ($userChildFks as $key => $ids) {
    if (!$ids) {
        continue;
    }
    [$t, $c] = explode('.', $key);
    $in      = implode(',', $ids);
    $n       = (int) $db->fetchColumn("SELECT COUNT(*) FROM `$t` WHERE `$c` IN ($in)");
    if ($n > 0) {
        $planUser[$key] = $n;
    }
}

printf("  students matched      %d%s", count($studentIds), PHP_EOL);
printf("  users matched        %d%s", count($testUserIds), PHP_EOL);
echo '  child rows to remove:', PHP_EOL;
foreach ($plan as $key => $n) {
    printf('    %-30s %d%s', $key, $n, PHP_EOL);
}
foreach ($planUser as $key => $n) {
    printf('    %-30s %d%s', $key, $n, PHP_EOL);
}

echo PHP_EOL, '  STUDENTS TO BE REMOVED', PHP_EOL;
foreach ($testStudents as $s) {
    printf(
        '    #%-4s %-14s %s%s',
        $s['id'],
        ($s['student_number'] === '' || $s['student_number'] === null) ? '(no number)' : $s['student_number'],
        $s['email'],
        PHP_EOL
    );
}

if (!$execute) {
    echo PHP_EOL, '  Re-run with --execute to delete these.', PHP_EOL;
    exit(0);
}

// ----------------------------------------------------------------------------
//  BACKUP
//  These are deletions from `students`. The dump is written beside the script
//  so a mistake is recoverable without going back to the database.
// ----------------------------------------------------------------------------
$pdo    = $db->getConnection();
$backup = __DIR__ . '/../backups/e2e_residue_' . date('Ymd_His') . '.sql';
$dump   = [];

$statementFor = static function (string $table, array $rows) use ($pdo): string {
    if (!$rows) {
        return '';
    }
    $cols = array_keys($rows[0]);
    $out  = [];
    foreach ($rows as $r) {
        $out[] = sprintf(
            "INSERT INTO `%s` (%s) VALUES (%s);",
            $table,
            '`' . implode('`,`', $cols) . '`',
            implode(',', array_map(
                static fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                array_values($r)
            ))
        );
    }
    return implode("\n", $out) . "\n";
};

$dump[] = $statementFor('students', $studentIds
    ? $db->fetchAll('SELECT * FROM students WHERE id IN (' . implode(',', $studentIds) . ')')
    : []);
$dump[] = $statementFor('users', $testUserIds
    ? $db->fetchAll('SELECT * FROM users WHERE id IN (' . implode(',', $testUserIds) . ')')
    : []);
if ($studentIds) {
    foreach (array_keys($plan) as $key) {
        [$t, $c] = explode('.', $key);
        $in      = implode(',', $studentIds);
        $dump[]  = $statementFor($t, $db->fetchAll("SELECT * FROM `$t` WHERE `$c` IN ($in)"));
    }
}
foreach (array_keys($planUser) as $key) {
    [$t, $c] = explode('.', $key);
    $in      = implode(',', $testUserIds);
    $dump[]  = $statementFor($t, $db->fetchAll("SELECT * FROM `$t` WHERE `$c` IN ($in)"));
}

if (!is_dir(dirname($backup))) {
    mkdir(dirname($backup), 0777, true);
}
file_put_contents($backup, "-- e2e residue backup, taken before deletion\n" . implode("\n", $dump));
printf("  backup written        %s (%d statements)%s", $backup, count($dump), PHP_EOL);

// ----------------------------------------------------------------------------
//  DELETE
//  children first, though the FKs cascade anyway. Being explicit means the
//  script is still correct if someone relaxed a constraint later.
// ----------------------------------------------------------------------------
$pdo->beginTransaction();
try {
    // Children of students, then children of users, then the parents. Both
    // loops come from information_schema, so a table this script has never
    // heard of is still emptied before its parent is removed.
    $wipe = static function (array $map, array $ids) use ($pdo): void {
        foreach ($map as $key => $_) {
            if (!$ids) {
                continue;
            }
            [$t, $c] = explode('.', $key);
            $in      = implode(',', $ids);
            $pdo->exec("DELETE FROM `$t` WHERE `$c` IN ($in)");
        }
    };
    $wipe($studentChildFks, $studentIds);
    $wipe($userChildFks, $testUserIds);

    // users.student_id is ON DELETE SET NULL, so a portal login would survive
    // its student as an orphan and still authenticate. Break the link
    // explicitly before removing the user.
    if ($studentIds && $testUserIds) {
        $pdo->exec(
            'UPDATE users SET student_id = NULL
              WHERE id IN (' . implode(',', $testUserIds) . ')'
        );
    }

    if ($studentIds) {
        $pdo->exec('DELETE FROM students WHERE id IN (' . implode(',', $studentIds) . ')');
    }
    if ($testUserIds) {
        $pdo->exec('DELETE FROM users WHERE id IN (' . implode(',', $testUserIds) . ')');
    }
    $pdo->commit();
    echo '  deleted, transaction committed', PHP_EOL;
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, '  FAILED, rolled back: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// ----------------------------------------------------------------------------
//  VERIFY - and prove the real staff survived.
// ----------------------------------------------------------------------------
echo PHP_EOL, '  AFTER', PHP_EOL;
foreach (['students', 'users'] as $t) {
    printf('    %-12s %d%s', $t, (int) $db->fetchColumn("SELECT COUNT(*) FROM `$t`"), PHP_EOL);
}
echo PHP_EOL, '  USERS THAT REMAIN', PHP_EOL;
foreach ($db->fetchAll('SELECT id, email, role FROM users ORDER BY id') as $u) {
    printf('    #%-4s %-34s %s%s', $u['id'], $u['email'], $u['role'], PHP_EOL);
}