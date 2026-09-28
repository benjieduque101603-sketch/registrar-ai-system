<?php
// Smoke test for the masterlist after sectioning was removed: the
// grouping helper must still work and must not reference section.
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';

$db = Database::getInstance();
$rows = $db->fetchAll("SELECT * FROM students");
echo "students: " . count($rows) . PHP_EOL;

$groups = groupStudentsForMasterlist($rows);
echo "groups: " . count($groups) . PHP_EOL;
foreach ($groups as $g) {
    echo "  " . $g['course'] . " | Year " . $g['year_level']
       . " => " . count($g['students']) . " student(s)" . PHP_EOL;
    echo "    keys: " . implode(', ', array_keys($g)) . PHP_EOL;
}

// The removed helpers must really be gone.
foreach (['autoAssignStudentSections', 'sectionExists', 'nextSectionNumber',
          'sectionCodeFromParts', 'masterlistGroupHasSection',
          'filterAssignedMasterlistGroups'] as $fn) {
    echo str_pad($fn, 30) . ': ' . (function_exists($fn) ? 'STILL PRESENT' : 'removed') . PHP_EOL;
}
echo 'section_code.php loaded: ' . (defined('SECTION_CODE_LOADED') ? 'yes' : 'no') . PHP_EOL;
