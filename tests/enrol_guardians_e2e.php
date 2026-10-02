<?php
/**
 * ENROL-STEP: guardians (many) + emergency contact, end to end.
 *
 * WHY THIS IS A SCRIPT AND NOT A PHPUNIT TEST
 * ------------------------------------------
 * createStudentFromInput() is the ONE function that writes the student,
 * the guardians and the emergency contact, and it is only reachable over
 * HTTP behind a login and a CSRF token. Asserting on the source instead
 * would be the usual shortcut, and it is exactly the shortcut that let
 * the single-guardian limitation survive: the source did contain a
 * guardians insert, so "the form has a guardians table" looked true.
 * What was false was "the form can record a second guardian", and only
 * a real insert-and-read-back can tell those apart.
 *
 * So this creates students for real, reads the rows back, and deletes
 * them again. It leaves nothing behind and it reports every failure.
 *
 * Requires Apache + MySQL. Run: php tests\enrol_guardians_e2e.php
 *
 * SIDE EFFECT TO EXPECT: createStudentFromInput() auto-creates the
 * student's portal account and sends the welcome email. On a box with
 * SMTP configured that is a live send per created student, to a
 * non-routable @example.test address, and each one burns up to 30s on
 * the connection before failing. Point SMTP at a closed local port to
 * make the failures instant:
 *
 *     $env:SMTP_HOST='127.0.0.1'; $env:SMTP_PORT='1';
 *     php tests\enrol_guardians_e2e.php
 *
 * The email is a side effect of enrolment, not of this feature; none of
 * the assertions below depend on it.
 */

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/normalize.php';
require_once __DIR__ . '/../shared/functions.php';

$db = Database::getInstance();

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  $label\n";
    } else {
        $fail++;
        echo "  FAIL  $label" . ($detail !== '' ? "  --  $detail" : '') . "\n";
    }
}

$createdStudentIds = [];
$createdUserIds    = [];

/** Remove everything this script made, guardians and all (FK CASCADE). */
function cleanup(array $studentIds, array $userIds, $db): void
{
    foreach ($studentIds as $sid) {
        $u = $db->fetchOne('SELECT id FROM users WHERE student_id = ?', [$sid]);
        if ($u) { $userIds[] = (int) $u['id']; }
    }
    foreach ($studentIds as $sid) {
        $db->query('DELETE FROM guardians WHERE student_id = ?', [$sid]);
        $db->query('DELETE FROM emergency_contacts WHERE student_id = ?', [$sid]);
        $db->query('DELETE FROM students WHERE id = ?', [$sid]);
    }
    foreach (array_unique($userIds) as $uid) {
        $db->query('DELETE FROM users WHERE id = ?', [$uid]);
    }
}

/** A unique-enough payload. createStudentFromInput() hard-requires these. */
function basePayload(array $over = []): array
{
    return array_merge([
        'first_name'     => 'E2e',
        'middle_name'    => '',
        'last_name'      => 'Testerson',
        'birth_date'     => '2007-05-14',
        'gender'         => 'male',
        'address'        => '1 Test St, Test City',
        'contact_number' => '09171234567',
        // Unique per run so repeat runs do not collide on users.email.
        'email'          => 'e2e.guardian.' . bin2hex(random_bytes(4)) . '@example.test',
        'course'         => 'BSED',
        'year_level'     => 1,
        'school_year'    => '2026-2027',
        'semester'       => '1st',
    ], $over);
}

echo "\n=== 1. Three guardians post as an ARRAY ===\n";
try {
    $res = createStudentFromInput(basePayload([
        'guardians' => [
            ['full_name' => 'Dela Cruz, Juan', 'relationship' => 'father',
             'contact_number' => '09171234567', 'email' => 'juan@example.test',
             'address' => '1 Test St', 'is_primary' => 1],
            ['full_name' => 'Dela Cruz, Maria', 'relationship' => 'mother',
             'contact_number' => '09181234567', 'email' => 'maria@example.test',
             'address' => '1 Test St'],
            ['full_name' => 'Santos, Lorna', 'relationship' => 'guardian',
             'contact_number' => '09191234567', 'address' => '2 Other St'],
            // The blank row the repeater's "Add another" leaves behind.
            ['full_name' => '', 'relationship' => 'guardian', 'contact_number' => '', 'email' => '', 'address' => ''],
        ],
    ]), $db);
    $sid = (int) $res['id'];
    $createdStudentIds[] = $sid;

    $rows = $db->fetchAll('SELECT * FROM guardians WHERE student_id = ? ORDER BY id', [$sid]);
    // 3, not 4: the empty row must not become a person.
    check('three real guardians stored, blank row dropped', count($rows) === 3,
          'got ' . count($rows));

    check('first row is primary', (int) ($rows[0]['is_primary'] ?? 0) === 1);
    check('later rows are not primary',
          (int) ($rows[1]['is_primary'] ?? 1) === 0 && (int) ($rows[2]['is_primary'] ?? 1) === 0);
    check('relationships kept in order',
          ($rows[0]['relationship'] ?? '') === 'father'
          && ($rows[1]['relationship'] ?? '') === 'mother'
          && ($rows[2]['relationship'] ?? '') === 'guardian',
          json_encode(array_column($rows, 'relationship')));
    check('per-guardian email stored', ($rows[0]['email'] ?? '') === 'juan@example.test');
    check('per-guardian address stored', trim((string) ($rows[1]['address'] ?? '')) === '1 Test St');
    check('no guardian flagged is_emergency',
          count(array_filter($rows, fn($r) => (int) $r['is_emergency'] === 1)) === 0);

    // Spaced input, the way it is typed: "0917 555 1234". normalizePhone()
    // formats to the house style 09XX-XXX-XXXX, which is what
    // isValidPhone() accepts, so that is what must land in the column.
    $res2 = createStudentFromInput(basePayload([
        'guardians' => [['full_name' => 'Spaced Number Test', 'relationship' => 'father',
                         'contact_number' => '0917 555 1234']],
    ]), $db);
    $createdStudentIds[] = (int) $res2['id'];
    $sp = $db->fetchOne('SELECT contact_number FROM guardians WHERE student_id = ?', [(int) $res2['id']]);
    check('spaced phone normalized to house format', ($sp['contact_number'] ?? '') === '0917-555-1234',
          'got ' . ($sp['contact_number'] ?? 'null'));
} catch (Throwable $e) {
    check('three guardians post without error', false, $e->getMessage());
}
echo "\n=== 2. Emergency contact lands in emergency_contacts, NOT guardians ===\n";
try {
    $res = createStudentFromInput(basePayload([
        'guardians' => [['full_name' => 'Only Guardian', 'relationship' => 'father',
                         'contact_number' => '09171234567']],
        'emergency_name'         => 'Reyes, Grandmother',
        'emergency_relationship' => 'Grandparent',
        'emergency_contact'      => '0920 999 8888',
        'emergency_address'      => '3 Faraway St',
    ]), $db);
    $sid = (int) $res['id'];
    $createdStudentIds[] = $sid;

    $ec = $db->fetchAll('SELECT * FROM emergency_contacts WHERE student_id = ?', [$sid]);
    check('one emergency contact stored', count($ec) === 1, 'got ' . count($ec));
    check('emergency phone normalized', ($ec[0]['contact_number'] ?? '') === '0920-999-8888',
          'got ' . ($ec[0]['contact_number'] ?? 'null'));
    check('emergency address stored', trim((string) ($ec[0]['address'] ?? '')) === '3 Faraway St',
          'got ' . trim((string) ($ec[0]['address'] ?? '')));
    check('emergency contact is primary', (int) ($ec[0]['is_primary'] ?? 0) === 1);

    // The point of keeping two tables: a grandmother who is NOT a
    // guardian must not appear among the guardians.
    $g = $db->fetchAll('SELECT full_name FROM guardians WHERE student_id = ?', [$sid]);
    check('emergency person did NOT leak into guardians',
          count($g) === 1 && $g[0]['full_name'] === 'Only Guardian',
          json_encode(array_column($g, 'full_name')));
} catch (Throwable $e) {
    check('emergency contact post without error', false, $e->getMessage());
}

echo "\n=== 3. The OLD flat shape still works (enrollment intake) ===\n";
try {
    $res = createStudentFromInput(basePayload([
        'guardian_name'         => 'Flat Shape Dad',
        'guardian_relationship' => 'father',
        'guardian_contact'      => '09171234567',
        'guardian_email'        => 'flat@example.test',
    ]), $db);
    $sid = (int) $res['id'];
    $createdStudentIds[] = $sid;
    $g = $db->fetchAll('SELECT * FROM guardians WHERE student_id = ?', [$sid]);
    check('flat guardian_name still stored', count($g) === 1 && $g[0]['full_name'] === 'Flat Shape Dad',
          'got ' . count($g));
    check('flat guardian_email still stored', ($g[0]['email'] ?? '') === 'flat@example.test');
} catch (Throwable $e) {
    check('flat shape still works', false, $e->getMessage());
}

echo "\n=== 4. Row-1 duplication: array + flat together ===\n";
// The Enrol modal deliberately posts BOTH -- guardians[] with all rows,
// and the flat fields mirrored from row 1 -- because api/enrollments.php
// and the Receive-Student flow still read the flat keys. Without
// de-duplication the primary guardian would appear twice on Contacts.
try {
    $res = createStudentFromInput(basePayload([
        'guardians' => [
            ['full_name' => 'Both Shapes', 'relationship' => 'father', 'contact_number' => '09171234567'],
            ['full_name' => 'Second Person', 'relationship' => 'mother', 'contact_number' => '09181234567'],
        ],
        'guardian_name'         => 'Both Shapes',
        'guardian_relationship' => 'father',
        'guardian_contact'      => '09171234567',
    ]), $db);
    $sid = (int) $res['id'];
    $createdStudentIds[] = $sid;
    $g = $db->fetchAll('SELECT full_name FROM guardians WHERE student_id = ? ORDER BY id', [$sid]);
    // Two, not three: the mirrored flat entry is the same person.
    check('mirrored row-1 not duplicated', count($g) === 2,
          'got ' . count($g) . ': ' . json_encode(array_column($g, 'full_name')));
} catch (Throwable $e) {
    check('array + flat dedup', false, $e->getMessage());
}
echo "\n=== 5. Bad data is REJECTED, not silently dropped ===\n";
// Each of these must throw. A guardian row that vanishes quietly is the
// failure mode that produced a school calling a parent who was on file.
$rejectCases = [
    'named guardian with no number' => [
        ['guardians' => [['full_name' => 'No Number', 'relationship' => 'father']]],
        'needs a mobile number',
    ],
    'guardian number not 11 digits' => [
        ['guardians' => [['full_name' => 'Bad Number', 'relationship' => 'father',
                          'contact_number' => '12345']]],
        '11-digit mobile',
    ],
    'emergency name with no number' => [
        ['emergency_name' => 'Lonely Name', 'emergency_contact' => ''],
        'needs a mobile number',
    ],
    'emergency number with no name' => [
        ['emergency_name' => '', 'emergency_contact' => '09171234567'],
        'needs a name',
    ],
    'emergency number not 11 digits' => [
        ['emergency_name' => 'Bad Emergency', 'emergency_contact' => '555'],
        '11-digit mobile',
    ],
];
foreach ($rejectCases as $label => [$over, $expect]) {
    try {
        $r = createStudentFromInput(basePayload($over), $db);
        // Got a student back, so the input was NOT rejected. Undo it.
        $createdStudentIds[] = (int) $r['id'];
        check($label . ' is rejected', false, 'no exception thrown');
    } catch (InvalidArgumentException $e) {
        check($label . ' is rejected', strpos($e->getMessage(), $expect) !== false,
              'message was: ' . $e->getMessage());
    } catch (Throwable $e) {
        check($label . ' is rejected', false, 'wrong exception type: ' . $e->getMessage());
    }
}

echo "\n=== 6. Optional data stays optional ===\n";
// A student with NO guardians and NO emergency contact must still save.
// The Contact page reports the gap; refusing to enrol does not.
try {
    $res = createStudentFromInput(basePayload([]), $db);
    $sid = (int) $res['id'];
    $createdStudentIds[] = $sid;
    $g = $db->fetchAll('SELECT id FROM guardians WHERE student_id = ?', [$sid]);
    $e = $db->fetchAll('SELECT id FROM emergency_contacts WHERE student_id = ?', [$sid]);
    check('student saves with no contacts at all', count($g) === 0 && count($e) === 0);
} catch (Throwable $e) {
    check('student saves with no contacts', false, $e->getMessage());
}

echo "\n=== 7. Unknown relationship degrades, does not abort ===\n";
// guardians.relationship is an ENUM. A value outside it makes MySQL throw
// and would take the whole enrolment with it -- the student is already
// written by then. It must be coerced instead.
try {
    $res = createStudentFromInput(basePayload([
        'guardians' => [['full_name' => 'Odd Relationship', 'relationship' => 'Neighbour',
                         'contact_number' => '09171234567']],
    ]), $db);
    $sid = (int) $res['id'];
    $createdStudentIds[] = $sid;
    $g = $db->fetchOne('SELECT relationship FROM guardians WHERE student_id = ?', [$sid]);
    check('off-enum relationship coerced to guardian', ($g['relationship'] ?? '') === 'guardian',
          'got ' . ($g['relationship'] ?? 'null'));
    check('the student itself still saved', (int) $res['id'] > 0);
} catch (Throwable $e) {
    check('off-enum relationship degrades gracefully', false, $e->getMessage());
}

cleanup($createdStudentIds, $createdUserIds, $db);

// Nothing left behind.
$leftover = 0;
foreach ($createdStudentIds as $sid) {
    $leftover += (int) $db->fetchOne('SELECT COUNT(*) c FROM students WHERE id = ?', [$sid])['c'];
}
echo "\n";
check('test data cleaned up', $leftover === 0, "$leftover students left");

echo "\n" . str_repeat('-', 52) . "\n";
echo "  {$pass} passed, {$fail} failed\n";
echo str_repeat('-', 52) . "\n";
exit($fail === 0 ? 0 : 1);