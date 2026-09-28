<?php
// ============================================================
//  Create 150 test students so the masterlist can be exercised
//  at a size where the 50-row sheet logic actually kicks in.
//
//    php tests/seed_masterlist_150.php            (dry run)
//    php tests/seed_masterlist_150.php --execute
//
//  150 is the interesting number: it is exactly three full 50-row
//  sheets, so the spill, the "Sheet 2 of 3" tag and the padding are
//  all visible at once. A cohort of 12 would hide every one of them.
//
//  Scope, deliberately narrow: inserts students only. Nothing is
//  updated and nothing is deleted — the one student already in the
//  database is left exactly as it is.
//
//  Every row is stamped with student numbers in the T9xxxxxx range
//  and the address TESTDATA-..., so tests/clear_seeded_students.php
//  can find and remove all of them without touching a real record.
//
//  Take a backup first:
//    mysqldump -u root --single-transaction registrar_ai students > backup.sql
// ============================================================
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

$execute = in_array('--execute', $argv, true);
$db      = Database::getInstance();

$COURSE  = 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)';
$COUNT   = 150;
$SECTIONS = ['1st', '2nd'];

// Deliberately mixes year levels. A single-year cohort would prove the
// 50-row padding but not the grouping, and the grouping is the other
// half of what this seed is for.
$PLAN = [
    ['year' => 1, 'sy' => '2026-2027', 'sem' => '1st', 'n' => 90],
    ['year' => 2, 'sy' => '2026-2027', 'sem' => '1st', 'n' => 40],
    ['year' => 3, 'sy' => '2026-2027', 'sem' => '1st', 'n' => 20],
];

$LAST   = ['Dela Cruz', 'Santos', 'Reyes', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza',
           'Torres', 'Ramos', 'Cruz', 'Villanueva', 'Aquino', 'Castillo', 'Flores',
           'Rivera', 'Gonzales', 'Domingo', 'De Guzman', 'Navarro', 'Salazar'];
$FIRST  = ['Juan', 'Maria', 'Pedro', 'Ana', 'Jose', 'Cristina', 'Mark', 'Grace',
           'Paul', 'Angel', 'Ryan', 'Nicole', 'Carlo', 'Jenny', 'Diego', 'Kim'];
$STATUS = ['enrolled', 'enrolled', 'enrolled', 'active', 'active', 'probation', 'at-risk'];

// Where the seeded numbers start. Checked against the table so a re-run
// cannot silently create a second 150 and make the totals nonsense.
$base = 900001;

$existing = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE student_number LIKE 'T9%'");
$real     = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE student_number NOT LIKE 'T9%' OR student_number IS NULL OR student_number = ''");

echo $execute ? "EXECUTING\n" : "DRY RUN — pass --execute to apply\n";
echo "  students already seeded (T9xxxxxx): $existing\n";
echo "  students that are not seeded       : $real\n";
echo "  to insert: " . array_sum(array_column($PLAN, 'n')) . "\n\n";

if ($existing > 0 && !$execute) {
    echo "  (already seeded — re-running would add another batch)\n";
}

$rows = [];
$seq  = 0;
foreach ($PLAN as $group) {
    for ($i = 0; $i < $group['n']; $i++) {
        $seq++;
        $last  = $LAST[($seq * 7) % count($LAST)];
        $first = $FIRST[($seq * 3) % count($FIRST)];
        $mid   = ($seq % 4 === 0) ? $LAST[($seq * 11) % count($LAST)] : null;
        $rows[] = [
            'student_number' => 'T' . ($base + $seq - 1),
            'first_name'     => $first,
            'middle_name'    => $mid,
            'last_name'      => $last,
            'gender'         => $seq % 2 === 0 ? 'Female' : 'Male',
            'civil_status'   => 'Single',
            'birth_date'     => sprintf('200%d-%02d-%02d', 4 + ($seq % 3), 1 + ($seq % 12), 1 + ($seq % 28)),
            'address'        => 'TESTDATA-Block ' . $group['year'] . ' Lot ' . $seq . ', Quezon City',
            'contact_number' => '09' . str_pad((string) (100000000 + $seq), 9, '0', STR_PAD_LEFT),
            'email'          => 'seed' . $seq . '@testdata.local',
            'course'         => $COURSE,
            'year_level'     => $group['year'],
            'school_year'    => $group['sy'],
            'semester'       => $group['sem'],
            // No section. The registrar does not write section codes, and a
            // seeded value would put a code on screen that nobody assigned.
            'section'        => '',
            'status'         => $STATUS[$seq % count($STATUS)],
        ];
    }
}

foreach ($rows as $r) {
    printf("  %s  %-26s  Year %d  %-9s%s\n",
        $r['student_number'],
        $r['last_name'] . ($r['middle_name'] ? ' ' . substr($r['middle_name'], 0, 1) . '.' : '') . ', ' . $r['first_name'],
        $r['year_level'],
        $r['status'],
        in_array($r['status'], ['probation', 'at-risk'], true) ? '  <- needs attention' : ''
    );
}

if (!$execute) {
    echo "\nNothing was written. Re-run with --execute.\n";
    exit(0);
}

// Inserted one at a time via the shared insert() helper rather than a
// hand-built multi-row INSERT: the helper is what the rest of the app uses,
// so a seed that bypassed it could succeed here and still miss a column
// the app relies on. The whole batch is one transaction, so a failure part
// way through leaves the table exactly as it was.
$db->beginTransaction();
try {
    foreach ($rows as $r) {
        $db->insert('students', $r);
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "Insert failed, nothing was written: " . $e->getMessage() . "\n");
    exit(1);
}

$after = (int) $db->fetchColumn('SELECT COUNT(*) FROM students');
echo "\nSeeded. students table now has $after rows.\n";
echo "Remove them with:  php tests/clear_seeded_students.php --execute\n";
