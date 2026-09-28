<?php
// Exercise shared/status_evidence.php against the live database.
//
//   php tests/status_evidence_check.php
//
// Builds a disposable set of students, gives each one a record that
// trips exactly one contradiction rule, and asserts the rule fired for
// that student and not for a clean one. Writes are confined to students
// this file creates, which it deletes on the way out — including if a
// step throws.
//
// The point of the test is the negative cases. A contradiction check
// that fires on everything is indistinguishable from one that works, and
// a registrar stops reading it within a week.

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/status_evidence.php';

$db   = Database::getInstance();
$ids  = [];
$fail = 0;
$ok   = 0;

function check(string $label, bool $cond): void
{
    global $fail, $ok;
    if ($cond) { $ok++;  printf("  ok    %s\n", $label); }
    else       { $fail++; printf("  FAIL  %s\n", $label); }
}

function mkStudent($db, string $status, ?string $birth = null): int
{
    static $n = 0;
    $n++;
    $db->insert('students', [
        'student_number' => 'ZEP' . str_pad((string) $n, 4, '0', STR_PAD_LEFT) . random_int(10, 99),
        'first_name'     => 'ZZ Probe ' . $n . ' ' . bin2hex(random_bytes(3)),
        'last_name'      => 'Evidence',
        'course'         => 'BSED',
        'year_level'     => 2,
        'status'         => $status,
        'birth_date'     => $birth,
        'email'          => 'probe' . $n . '@example.invalid',
    ]);
    return (int) $db->getConnection()->lastInsertId();
}

$users = $db->fetchAll('SELECT id FROM users ORDER BY id LIMIT 1');
$uid   = $users ? (int) $users[0]['id'] : null;

echo "status_evidence - contradiction rules\n";

try {
    // -- A clean record. Every rule must stay quiet about this one. --
    $clean = mkStudent($db, 'active', '2004-03-02');
    $ids[] = $clean;
    $db->insert('finance', ['student_id' => $clean, 'balance' => 0]);
    $db->insert('guardians', ['student_id' => $clean, 'full_name' => 'Probe Guardian', 'contact_number' => '09171234567']);

    // -- Pending disciplinary case, status otherwise fine --
    $disciplined = mkStudent($db, 'active', '2004-05-11');
    $ids[] = $disciplined;
    $db->insert('discipline_records', [
        'student_id'  => $disciplined,
        'recorded_at' => date('Y-m-d', strtotime('-30 days')),
        'nature'      => 'Repeated absence',
        'status'      => 'pending',
    ]);

    // -- A closed case must NOT read as pending --
    $resolved = mkStudent($db, 'active', '2004-06-12');
    $ids[] = $resolved;
    $db->insert('discipline_records', [
        'student_id'  => $resolved,
        'recorded_at' => date('Y-m-d', strtotime('-60 days')),
        'nature'      => 'Late submission',
        'status'      => 'resolved',
    ]);

    // -- Balance against a status that should be terminal --
    $owing = mkStudent($db, 'graduated', '2002-01-01');
    $ids[] = $owing;
    $db->insert('finance', ['student_id' => $owing, 'balance' => 4250.50]);

    // -- A window that closed in the past --
    $expired = mkStudent($db, 'loa', '2003-04-04');
    $ids[] = $expired;
    $db->insert('status_tracker', [
        'student_id'     => $expired,
        'previous_status'=> 'active',
        'current_status' => 'loa',
        'reason'         => 'Medical leave',
        'changed_by'     => $uid,
        'created_at'     => date('Y-m-d H:i:s', strtotime('-120 days')),
        'effective_date' => date('Y-m-d', strtotime('-120 days')),
        'end_date'       => date('Y-m-d', strtotime('-10 days')),
    ]);

    // -- A window still running must not read as expired --
    $current = mkStudent($db, 'loa', '2003-08-08');
    $ids[] = $current;
    $db->insert('status_tracker', [
        'student_id'     => $current,
        'previous_status'=> 'active',
        'current_status' => 'loa',
        'reason'         => 'Family matter',
        'changed_by'     => $uid,
        'created_at'     => date('Y-m-d H:i:s', strtotime('-20 days')),
        'end_date'       => date('Y-m-d', strtotime('+60 days')),
    ]);

    // -- Minor with no guardian --
    $minor = mkStudent($db, 'enrolled', date('Y-m-d', strtotime('-17 years')));
    $ids[] = $minor;

    // -- GWA falling across two terms --
    $falling = mkStudent($db, 'active', '2003-02-02');
    $ids[] = $falling;
    $db->insert('academic_history', [
        'student_id' => $falling, 'school_name' => 'Probe School',
        'gwa' => 2.10, 'created_at' => date('Y-m-d H:i:s', strtotime('-200 days')),
    ]);
    $db->insert('academic_history', [
        'student_id' => $falling, 'school_name' => 'Probe School',
        'gwa' => 1.40, 'created_at' => date('Y-m-d H:i:s', strtotime('-20 days')),
    ]);

    $codes = static fn(array $f): array => array_column($f, 'code');

    // -- Per-student rules --
    $evClean = statusStudentEvidence($clean);
    check('clean record raises nothing', statusEvidenceFindings($evClean) === []);
    check('clean record had no read errors', $evClean['errors'] === []);

    check('pending case is flagged',
        in_array('discipline_pending', $codes(statusEvidenceFindings(statusStudentEvidence($disciplined))), true));

    check('resolved case is not flagged as pending',
        !in_array('discipline_pending', $codes(statusEvidenceFindings(statusStudentEvidence($resolved))), true));

    check('balance on a closed status is flagged',
        in_array('finance_balance', $codes(statusEvidenceFindings(statusStudentEvidence($owing))), true));

    check('closed window is flagged',
        in_array('window_expired', $codes(statusEvidenceFindings(statusStudentEvidence($expired))), true));

    $evOpen = statusEvidenceFindings(statusStudentEvidence($current));
    check('open window is not flagged expired', !in_array('window_expired', $codes($evOpen), true));
    check('open window well ahead is not flagged closing', !in_array('window_closing', $codes($evOpen), true));

    check('minor without a guardian is flagged',
        in_array('no_guardian', $codes(statusEvidenceFindings(statusStudentEvidence($minor))), true));

    check('falling GWA is flagged',
        in_array('gwa_decline', $codes(statusEvidenceFindings(statusStudentEvidence($falling))), true));

    // -- No finding may name a destination status --
    $all = array_merge(
        statusEvidenceFindings(statusStudentEvidence($disciplined)),
        statusEvidenceFindings(statusStudentEvidence($owing)),
        statusEvidenceFindings(statusStudentEvidence($expired)),
        statusEvidenceFindings(statusStudentEvidence($minor)),
        statusEvidenceFindings(statusStudentEvidence($falling))
    );
    $leaked = array_filter($all, static fn(array $f): bool =>
        isset($f['recommended_status']) || isset($f['suggested_status']));
    check('no finding proposes a status', $leaked === []);

    // -- Cohort sweep --
    $cohort = statusCohortFindings($ids);
    $found  = [];
    foreach ($cohort['findings'] as $f) {
        $found[(int) $f['student_id']] = array_column($f['issues'], 'code');
    }
    check('sweep scanned the probe students', $cohort['scanned'] >= count($ids));
    check('sweep found the disciplined student',
        isset($found[$disciplined]) && in_array('discipline_pending', $found[$disciplined], true));
    check('sweep found the expired window',
        isset($found[$expired]) && in_array('window_expired', $found[$expired], true));
    check('sweep found the balance on a closed status',
        isset($found[$owing]) && in_array('finance_balance', $found[$owing], true));
    check('sweep leaves the clean student alone', !isset($found[$clean]));
    check('sweep leaves the resolved case alone', !isset($found[$resolved]));
    check('sweep leaves the open window alone', !isset($found[$current]));
    check('sweep reports no read errors', $cohort['errors'] === []);

    $one = statusCohortFindings([$disciplined]);
    check('sweep honours an id filter', count($one['findings']) <= 1);

} finally {
    // Probe students cascade their child rows away with them. Deleted by
    // id so a collision with real data is impossible.
    foreach ($ids as $pid) {
        try {
            $db->delete('students', 'id = ?', [$pid]);
        } catch (Throwable $e) {
            // Nothing useful to do; the ids are ours.
        }
    }
}

printf("\n  %d passed, %d failed\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);

    $db->insert('status_tracker', [
        'student_id'     => $current,
        'previous_status'=> 'active',
        'current_status' => 'loa',
        'reason'         => 'Family matter',
        'changed_by'     => $uid,
        'created_at'     => date('Y-m-d H:i:s', strtotime('-20 days')),
        'end_date'       => date('Y-m-d', strtotime('+60 days')),
    ]);

    // -- Minor with no guardian --
    $minor = mkStudent($db, 'enrolled', date('Y-m-d', strtotime('-17 years')));
    $ids[] = $minor;

    // -- GWA falling across two terms --
    $falling = mkStudent($db, 'active', '2003-02-02');
    $ids[] = $falling;
    $db->insert('academic_history', [
        'student_id' => $falling, 'school_name' => 'Probe School',
        'gwa' => 2.10, 'created_at' => date('Y-m-d H:i:s', strtotime('-200 days')),
    ]);
    $db->insert('academic_history', [
        'student_id' => $falling, 'school_name' => 'Probe School',
        'gwa' => 1.40, 'created_at' => date('Y-m-d H:i:s', strtotime('-20 days')),
    ]);
