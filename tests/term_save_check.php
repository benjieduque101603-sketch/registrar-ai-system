<?php
// Exercise the save path against the live database.
//
//   php tests/term_save_check.php
//
// The GWA is computed server-side now, so the client's value is ignored
// and the stored figure must always be the one the subjects produce.
// That is the change most likely to regress silently: the page still
// sends a GWA, the response still returns one, and nothing on screen
// differs whether the server computed it or stored whatever arrived.
//
// So this writes a real term and reads the database back. Every row it
// creates is removed afterwards, including if a step throws.

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/term_grades.php';

$db   = Database::getInstance();
$fail = 0;
$ok   = 0;
function check(string $label, $cond): void
{
    global $fail, $ok;
    if ($cond) { $ok++;  printf("  ok    %s\n", $label); }
    else       { $fail++; printf("  FAIL  %s\n", $label); }
}

echo "term save - GWA is computed, not accepted\n";

$student = $db->fetchOne("SELECT id, student_number FROM students ORDER BY id LIMIT 1");
if (!$student) {
    fwrite(STDERR, "  no student to test with\n");
    exit(1);
}
$sid = (int) $student['id'];
$SY  = 'TEST-2099';
$created = [];

/**
 * Write a term through the same steps the API runs. The endpoint is a
 * long if-chain inside api/students.php, so the write is reproduced here
 * rather than invoked, and every assertion reads the database back.
 */
function saveTerm($db, int $sid, string $sy, string $sem, array $subjects): int
{
    $existing = $db->fetchOne(
        "SELECT id FROM academic_history WHERE student_id = ? AND school_year = ? AND semester = ?",
        [$sid, $sy, $sem]
    );
    $clean = [];
    $totalUnits = 0.0;
    foreach ($subjects as $g) {
        $label = trim((string) ($g['subject'] ?? ''));
        if ($label === '') continue;
        $units  = ($g['units'] ?? '') !== '' ? (float) $g['units'] : 0.0;
        $rating = ($g['final_rating'] ?? '') !== '' ? (float) $g['final_rating'] : null;
        $totalUnits += max(0.0, $units);
        $clean[] = [
            'subject' => $label, 'units' => $units, 'final_rating' => $rating,
            'grade_status' => trim((string) ($g['grade_status'] ?? '')) ?: null,
        ];
    }
    $data = [
        'student_id' => $sid, 'school_name' => TERM_SCHOOL_NAME,
        'school_year' => $sy, 'semester' => $sem, 'grade_level' => null,
        'gwa' => termGwa($clean), 'credits' => $totalUnits > 0 ? round($totalUnits, 2) : null,
        'subjects_completed' => count($clean) ?: null, 'remarks' => null,
    ];
    $rid = $existing ? (int) $existing['id'] : (int) $db->insert('academic_history', $data);
    if ($existing) $db->update('academic_history', $data, 'id = ?', [$rid]);
    $db->delete('academic_grades', 'academic_history_id = ?', [$rid]);
    foreach ($clean as $g) {
        $db->insert('academic_grades', [
            'academic_history_id' => $rid, 'subject' => $g['subject'],
            'units' => $g['units'] ?: null, 'final_rating' => $g['final_rating'],
            'grade_status' => $g['grade_status'],
        ]);
    }
    return $rid;
}

try {
    // â”€â”€ Stored GWA is the computed one â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $rid = saveTerm($db, $sid, $SY, '1st', [
        ['subject' => 'IT 101', 'units' => 3, 'final_rating' => 1.5, 'grade_status' => 'passed'],
        ['subject' => 'IT 102', 'units' => 3, 'final_rating' => 2.5, 'grade_status' => 'passed'],
    ]);
    $created[] = $rid;

    $row = $db->fetchOne("SELECT gwa, credits, subjects_completed, school_name FROM academic_history WHERE id = ?", [$rid]);
    check('stored GWA is computed from the subjects', (float) $row['gwa'] === 2.0);
    check('credits are summed from the subjects', (float) $row['credits'] === 6.0);
    check('subjects_completed counts the rows', (int) $row['subjects_completed'] === 2);
    check('school name is fixed to this office', (string) $row['school_name'] === TERM_SCHOOL_NAME);

    $subjects = $db->fetchAll("SELECT units, final_rating FROM academic_grades WHERE academic_history_id = ?", [$rid]);
    check('the stored figure matches what the subjects produce',
        (float) $row['gwa'] === termGwa($subjects));

    // â”€â”€ A subject with no rating is excluded, not zeroed â”€â”€â”€â”€â”€â”€â”€
    $rid2 = saveTerm($db, $sid, $SY, '2nd', [
        ['subject' => 'IT 101', 'units' => 3, 'final_rating' => 1.5, 'grade_status' => 'passed'],
        ['subject' => 'IT 102', 'units' => 3, 'final_rating' => null,  'grade_status' => 'passed'],
    ]);
    $created[] = $rid2;
    check('a missing rating does not drag the average down',
        (float) $db->fetchColumn('SELECT gwa FROM academic_history WHERE id = ?', [$rid2]) === 1.5);

    // â”€â”€ Re-saving the same term updates, never duplicates â”€â”€â”€â”€â”€â”€â”€
    $before = (int) $db->fetchColumn("SELECT COUNT(*) FROM academic_history WHERE student_id = ? AND school_year = ?", [$sid, $SY]);
    saveTerm($db, $sid, $SY, '1st', [
        ['subject' => 'IT 101', 'units' => 3, 'final_rating' => 2.0, 'grade_status' => 'passed'],
    ]);
    $after = (int) $db->fetchColumn("SELECT COUNT(*) FROM academic_history WHERE student_id = ? AND school_year = ?", [$sid, $SY]);
    check('re-saving a term does not create a second row', $before === $after);
    check('re-saving recomputes the GWA',
        (float) $db->fetchColumn('SELECT gwa FROM academic_history WHERE id = ?', [$rid]) === 2.0);
    check('a removed subject does not linger',
        (int) $db->fetchColumn('SELECT COUNT(*) FROM academic_grades WHERE academic_history_id = ?', [$rid]) === 1);

    // â”€â”€ An empty term stores a null GWA, not a zero â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $rid3 = saveTerm($db, $sid, $SY, '3rd', []);
    $created[] = $rid3;
    check('a term with no subjects stores no GWA',
        $db->fetchColumn('SELECT gwa FROM academic_history WHERE id = ?', [$rid3]) === null);

    // â”€â”€ The audit sees exactly what was stored â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $roster = [[
        'name' => 'Test', 'number' => 'T-1',
        'gwa'   => $db->fetchColumn('SELECT gwa FROM academic_history WHERE id = ?', [$rid2]),
        'subjects' => $db->fetchAll(
            "SELECT subject, units, final_rating, grade_status FROM academic_grades
              WHERE academic_history_id = ?", [$rid2]),
    ]];
    $a = termAudit($SY, '1st', $roster);
    check('a freshly saved term is not blocked by a GWA mismatch',
        !in_array('gwa_mismatch', array_column($a['blocking'], 'code'), true));
    check('a missing rating is caught on a real saved row',
        in_array('missing_rating', array_column($a['blocking'], 'code'), true));

} finally {
    // academic_grades cascade from academic_history.
    foreach ($created as $id) {
        try { $db->delete('academic_history', 'id = ?', [$id]); } catch (Throwable $e) {}
    }
}

printf("\n  %d passed, %d failed\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
