<?php
// ============================================================
//  Remove the students created by tests/seed_masterlist_150.php.
//
//    php tests/clear_seeded_students.php            (dry run)
//    php tests/clear_seeded_students.php --execute
//
//  Identifies seeded rows two ways, and needs BOTH to agree before it
//  deletes anything:
//
//    student_number LIKE 'T9%'   the marker written by the seed
//    address LIKE 'TESTDATA-%'   the marker written by the seed
//
//  One marker alone is not enough. student_number is assigned later by the
//  enrolment department and is not unique — two unrelated records can share
//  it, and a real student could eventually be issued a T9xxxxxx number.
//  Requiring the address marker too means this script can only ever remove
//  what the seed actually wrote, even if one of the two markers later
//  appears on a genuine record.
//
//  It reports exactly which rows it would touch, and refuses to run if it
//  finds seeded rows that the markers do not fully explain.
// ============================================================
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

$execute = in_array('--execute', $argv, true);
$db      = Database::getInstance();

$marked = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE student_number LIKE 'T9%' OR address LIKE 'TESTDATA-%'");
$both   = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE student_number LIKE 'T9%' AND address LIKE 'TESTDATA-%'");
$onlyNum = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE student_number LIKE 'T9%' AND (address IS NULL OR address NOT LIKE 'TESTDATA-%')");
$onlyAddr = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE address LIKE 'TESTDATA-%' AND (student_number IS NULL OR student_number NOT LIKE 'T9%')");
$total  = (int) $db->fetchColumn('SELECT COUNT(*) FROM students');

echo $execute ? "EXECUTING\n" : "DRY RUN — pass --execute to apply\n";
echo "  students in table                 : $total\n";
echo "  carrying either seed marker       : $marked\n";
echo "  carrying both markers (seeded)    : $both\n";
echo "  marker on number only (not ours)  : $onlyNum\n";
echo "  marker on address only (not ours) : $onlyAddr\n\n";

if ($onlyNum > 0 || $onlyAddr > 0) {
    echo "  Refusing to run: some rows carry one marker but not the other, which\n";
    echo "  this script did not write. Inspect them before deleting anything:\n\n";
    foreach ($db->fetchAll(
        "SELECT id, student_number, address FROM students
         WHERE (student_number LIKE 'T9%') <> (address LIKE 'TESTDATA-%')
         LIMIT 20"
    ) as $r) {
        echo "    id=" . $r['id'] . " num=" . ($r['student_number'] ?? '') . " addr=" . substr((string) $r['address'], 0, 40) . "\n";
    }
    echo "\n  Nothing was deleted.\n";
    exit(1);
}

if ($both === 0) {
    echo "  Nothing seeded to remove.\n";
    exit(0);
}

foreach ($db->fetchAll(
    "SELECT year_level, COUNT(*) c FROM students
     WHERE student_number LIKE 'T9%' AND address LIKE 'TESTDATA-%'
     GROUP BY year_level ORDER BY year_level"
) as $r) {
    echo "  Year {$r['year_level']}: {$r['c']} rows\n";
}

if (!$execute) {
    echo "\n  Nothing was deleted. Re-run with --execute.\n";
    exit(0);
}

$deleted = $db->delete(
    'students',
    "student_number LIKE 'T9%' AND address LIKE 'TESTDATA-%'",
    []
);
$after = (int) $db->fetchColumn('SELECT COUNT(*) FROM students');

echo "\n  Removed $deleted seeded row(s). students table now has $after rows.\n";
