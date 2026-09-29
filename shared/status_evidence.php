<?php
// ============================================================
//  SHARED/STATUS_EVIDENCE.PHP
//  What the status page cannot see on its own.
//
//  A student's status lives in `students.status` — one column, eight
//  values. Almost nothing that justifies a change lives there. The
//  disciplinary case is in discipline_records. The money is in finance.
//  The leave window is in status_tracker.end_date. The grades are in
//  academic_history. The pending paperwork is in document_requests.
//
//  A registrar decides status by reading across all of those, which is
//  exactly the work nobody has time to do twice a day. This module does
//  the gathering. It decides nothing: every function here returns
//  evidence and a suggested question, and the human answers it.
//
//  Two rules, and they are the whole point of this file:
//
//    1. Read-only. Nothing here writes. Not a status, not a hold, not a
//       dismissed flag. A machine that cannot write cannot quietly
//       change a student's record.
//    2. Contradictions, not summaries. "12 students are at-risk" is a
//       number already on screen. "This student is at-risk with an
//       unresolved disciplinary case and money outstanding" is something
//       only this file can say, and it is the kind of thing a person
//       misses at 4pm on enrolment week.
//
//  Every rule is deterministic. The LLM is used by the caller to phrase
//  what these rules found, never to decide whether a rule fired.
// ============================================================

// This file calls isTerminalStudentStatus() / isCurrentStudentStatus() from
// shared/functions.php. It used to have no requires at all and relied entirely
// on its caller having loaded them - which held only by accident, for the one
// page that includes it today. Declaring the dependency here is what stops the
// Status Tracker's attention queue dying with "Call to undefined function
// isTerminalStudentStatus()" if a second caller appears.
require_once __DIR__ . '/functions.php';

if (defined('STATUS_EVIDENCE_LOADED')) {
    return;
}
define('STATUS_EVIDENCE_LOADED', true);

/**
 * Statuses that mean the student is no longer currently enrolled.
 *
 * Was a literal array written out twice in this file
 * (['graduated','alumni','transferred','dropped']) and it had already drifted
 * from the column: `graduated` and `transferred` are no longer values, so both
 * copies had quietly stopped matching a real graduate. A student who had
 * finished and still owed money was being reported as an ordinary balance
 * against a live status, which is the opposite of the finding's purpose.
 *
 * Note what is and is not terminal. `alumni` IS terminal - a former student is
 * not currently enrolled. `dropped` is terminal. `graduate` is terminal.
 * `enrolled` and `active` are not.
 */
function isTerminalStudentStatus(?string $status): bool
{
    return in_array(strtolower(trim((string) $status)), ['graduate', 'alumni', 'dropped'], true);
}

/** Statuses meaning the student is currently on the books and attending-or-likely. */
function isCurrentStudentStatus(?string $status): bool
{
    return in_array(strtolower(trim((string) $status)), ['enrolled', 'active'], true);
}

/**
 * Read the whole picture of one student. Every source is optional and
 * wrapped: a missing table or column on an un-migrated server must cost
 * a section, not the page. This is the same discipline the desk uses
 * for sla_days — a feature that cannot run must not take the room down
 * with it.
 *
 * @param  int   $studentId
 * @return array Evidence, grouped for display.
 */
function statusStudentEvidence(int $studentId): array
{
    $db = Database::getInstance();
    $ev = [
        'student'  => null,
        'history'  => [],
        'discipline' => ['pending' => [], 'resolved' => 0],
        'finance'  => null,
        'grades'   => [],
        'documents' => ['open' => 0, 'held' => 0],
        'guardian' => null,
        'activity' => null,
        'window'   => null,
        'errors'   => [],
    ];

    // Runs a query and records — rather than throws — the reason it could
    // not. This is what makes the whole file safe to point at a server
    // whose migrations are incomplete.
    $try = function (string $key, callable $fn) use (&$ev) {
        try {
            return $fn();
        } catch (Throwable $e) {
            $ev['errors'][] = $key . ': ' . $e->getMessage();
            return null;
        }
    };

    $ev['student'] = $try('student', fn() => $db->fetchOne(
        "SELECT id, student_number, first_name, middle_name, last_name, course,
                year_level, status, birth_date, email, contact_number
         FROM students WHERE id = ?",
        [$studentId]
    ));
    if (!$ev['student']) {
        return $ev;
    }

    $ev['history'] = $try('history', fn() => $db->fetchAll(
        "SELECT st.previous_status, st.current_status, st.reason,
                st.effective_date, st.end_date, st.created_at,
                u.full_name AS changed_by_name
         FROM status_tracker st
         LEFT JOIN users u ON u.id = st.changed_by
         WHERE st.student_id = ?
         ORDER BY st.created_at DESC, st.id DESC
         LIMIT 20",
        [$studentId]
    )) ?? [];

    // ── Disciplinary cases ────────────────────────────────────────
    //
    // The table no page in the application read, and the single most
    // consequential fact about a student's standing. A pending case
    // alongside an "active" status is the contradiction this module
    // exists to surface.
    $disc = $try('discipline', fn() => $db->fetchAll(
        "SELECT id, recorded_at, nature, resolution, status, remarks
         FROM discipline_records
         WHERE student_id = ?
         ORDER BY (status = 'pending') DESC, recorded_at DESC, id DESC",
        [$studentId]
    ));
    if (is_array($disc)) {
        foreach ($disc as $d) {
            if (($d['status'] ?? '') === 'pending') {
                $ev['discipline']['pending'][] = $d;
            } else {
                $ev['discipline']['resolved']++;
            }
        }
    }

    $ev['finance'] = $try('finance', fn() => $db->fetchOne(
        "SELECT balance FROM finance WHERE student_id = ?",
        [$studentId]
    ));

    // GWA as a series, not a single number. The trend is the finding:
    // a GWA that fell from 2.1 to 1.4 over three terms says something
    // the latest value never will.
    $ev['grades'] = $try('grades', fn() => $db->fetchAll(
        "SELECT gwa, school_year, semester, created_at
         FROM academic_history
         WHERE student_id = ? AND gwa IS NOT NULL
         ORDER BY created_at DESC, id DESC
         LIMIT 6",
        [$studentId]
    )) ?? [];

    $doc = $try('documents', fn() => $db->fetchOne(
        "SELECT
            SUM(document_status NOT IN ('Claimed','Rejected')) AS open_count,
            SUM(blocked_reason IS NOT NULL) AS held_count
         FROM document_requests
         WHERE student_id = ?",
        [$studentId]
    ));
    if (is_array($doc)) {
        $ev['documents']['open']  = (int) ($doc['open_count'] ?? 0);
        $ev['documents']['held']  = (int) ($doc['held_count'] ?? 0);
    }

    $ev['guardian'] = $try('guardian', fn() => $db->fetchOne(
        "SELECT id FROM guardians WHERE student_id = ? LIMIT 1",
        [$studentId]
    ));

    // rfid_scan_logs carries its own student_id, so this needs no join to
    // rfid_cards. The column is scanned_at — naming it scan_time threw
    // "Unknown column" here, and because every source in this file is
    // wrapped, the symptom was one missing line of evidence in the panel
    // rather than a failed request. Corrected against the schema.
    $ev['activity'] = $try('activity', fn() => $db->fetchOne(
        "SELECT MAX(scanned_at) AS last_scan
         FROM rfid_scan_logs
         WHERE student_id = ?",
        [$studentId]
    ));

    // The most recent row that carries a window. This is what makes an
    // expired leave visible at all.
    $ev['window'] = $try('window', fn() => $db->fetchOne(
        "SELECT st.current_status, st.effective_date, st.end_date, st.created_at
         FROM status_tracker st
         WHERE st.student_id = ? AND st.end_date IS NOT NULL
         ORDER BY st.created_at DESC, st.id DESC
         LIMIT 1",
        [$studentId]
    ));

    return $ev;
}

/**
 * Turn one student's evidence into findings: named contradictions, each
 * with the facts that produced it and a question for the registrar.
 *
 * Every finding answers "is this consistent?", never "change this to
 * that". There is deliberately no suggested_status field — proposing a
 * destination is how a tool stops being read-only in people's heads.
 *
 * @param array $ev Output of statusStudentEvidence().
 * @return array
 */
function statusEvidenceFindings(array $ev): array
{
    $findings = [];
    if (empty($ev['student'])) {
        return $findings;
    }

    $s      = $ev['student'];
    $status = strtolower((string) ($s['status'] ?? ''));
    $name   = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));

    $add = function (string $code, string $title, string $detail, string $question, string $weight) use (&$findings) {
        $findings[] = [
            'code' => $code, 'title' => $title, 'detail' => $detail,
            'question' => $question, 'weight' => $weight,
        ];
    };

    // ── A pending disciplinary case is the loudest signal on the page ──
    $pending = $ev['discipline']['pending'] ?? [];
    if ($pending) {
        $oldest  = $pending[0]['recorded_at'] ?? null;
        $days    = $oldest ? (int) floor((time() - strtotime($oldest)) / 86400) : null;
        $ageTxt  = $days !== null ? ('open ' . $days . ' day' . ($days === 1 ? '' : 's')) : 'open';
        $natures = array_values(array_unique(array_filter(array_map(
            fn($d) => trim((string) ($d['nature'] ?? '')) ?: 'case not described',
            $pending
        ))));
        $add(
            'discipline_pending',
            'Unresolved disciplinary case' . (count($pending) > 1 ? 's' : ''),
            count($pending) . ' pending (' . $ageTxt . '): ' . implode('; ', $natures)
                . '. Current status is ' . ($status !== '' ? $status : 'unset') . '.',
            'Resolve or dismiss each case before this status is reviewed — the record shows no outcome, so the standing cannot be confirmed.',
            'high'
        );
    }

    // ── Money owed ────────────────────────────────────────────────
    $balance = (float) ($ev['finance']['balance'] ?? 0);
    if ($balance > 0) {
        $terminal = isTerminalStudentStatus($status);
        $add(
            'finance_balance',
            'Outstanding balance',
            'PHP ' . number_format($balance, 2) . ' outstanding while the status is ' . ($status !== '' ? $status : 'unset') . '.',
            $terminal
                ? 'This status is normally terminal — confirm the account is settled or that a payment arrangement is on file.'
                : 'Confirm whether the balance blocks this status, or is being carried separately.',
            $terminal ? 'high' : 'medium'
        );
    }

    // ── A window that has closed ──────────────────────────────────
    $w = $ev['window'];
    if (!empty($w['end_date']) && !empty($w['current_status'])) {
        $endTs = strtotime((string) $w['end_date']);
        $today = strtotime(date('Y-m-d'));
        if ($endTs !== false && $today > $endTs) {
            $over = (int) floor(($today - $endTs) / 86400);
            $add(
                'window_expired',
                'Leave window has closed',
                'Recorded as ' . str_replace('_', ' ', (string) $w['current_status'])
                    . ' with an end date of ' . date('M j, Y', $endTs) . ' — ' . $over
                    . ' day' . ($over === 1 ? '' : 's') . ' ago. The status has not changed since.',
                'Decide whether the student returns to active or the leave is extended. The window closed on its own; nobody has decided yet.',
                'high'
            );
        } elseif ($endTs !== false && $today <= $endTs) {
            $left = (int) floor(($endTs - $today) / 86400);
            if ($left <= 14) {
                $add(
                    'window_closing',
                    'Leave window closes soon',
                    'Ends ' . date('M j, Y', $endTs) . ' — ' . $left . ' day' . ($left === 1 ? '' : 's') . ' from now.',
                    'Start the return-to-class process before the window closes, or extend it deliberately.',
                    'medium'
                );
            }
        }
    }

    // ── Academic direction, not just the latest GWA ───────────────
    $grades = $ev['grades'] ?? [];
    if (count($grades) >= 2) {
        $latest = (float) $grades[0]['gwa'];
        $prior  = (float) $grades[1]['gwa'];
        $drop   = $prior - $latest;
        if ($drop >= 0.5) {
            $add(
                'gwa_decline',
                'GWA is falling',
                'Went from ' . number_format($prior, 2) . ' to ' . number_format($latest, 2)
                    . ' between the last two recorded terms.',
                'Check whether this is a health, family or load issue before treating it as academic — the decline is the finding, the cause is not in this record.',
                'medium'
            );
        } elseif ($latest > 3.0) {
            $add(
                'gwa_failing',
                'GWA above 3.0',
                'Latest recorded GWA is ' . number_format($latest, 2) . ' on the 1.0-5.0 scale.',
                'Confirm the current term before acting on an older record, and whether academic load is the cause.',
                'medium'
            );
        }
    }

    // ── Status that quietly contradicts the paperwork ─────────────
    $open = (int) ($ev['documents']['open'] ?? 0);
    $held = (int) ($ev['documents']['held'] ?? 0);
    if ($held > 0) {
        $add(
            'documents_held',
            'Document request on hold',
            $held . ' request' . ($held === 1 ? '' : 's') . ' waiting on something, with '
                . $open . ' still open in total.',
            'A held document may be why this status stalled. Check the request log before concluding the student is inactive.',
            'low'
        );
    }

    // ── Contact safety net ────────────────────────────────────────
    //
    // A minor with no guardian on file is not a status question, but it
    // is the kind of gap that matters most and gets checked least.
    if (empty($ev['guardian']) && !empty($s['birth_date'])) {
        $dob = strtotime((string) $s['birth_date']);
        if ($dob !== false && $dob > strtotime('-19 years')) {
            $age = (int) floor((time() - $dob) / 31557600);
            $add(
                'no_guardian',
                'No guardian on file',
                $name . ' is ' . $age . ' with no guardian recorded.',
                'A guardian is needed before any status change that may require parental consent.',
                'medium'
            );
        }
    }

    // ── Dormant record ────────────────────────────────────────────
    if (!empty($ev['history'])) {
        $last = $ev['history'][0]['created_at'] ?? null;
        if ($last) {
            $days = (int) floor((time() - strtotime((string) $last)) / 86400);
            if ($days > 180 && isCurrentStudentStatus($status)) {
                $add(
                    'dormant',
                    'No status activity in ' . $days . ' days',
                    'Last recorded change was ' . date('M j, Y', strtotime((string) $last)) . '.',
                    'Confirm the student is still attending before treating a quiet record as a problem.',
                    'low'
                );
            }
        }
    }

    // Churn, or a records problem being corrected repeatedly.
    $changes30 = count(array_filter(
        $ev['history'],
        fn($h) => !empty($h['created_at'])
            && (time() - strtotime((string) $h['created_at'])) <= 30 * 86400
    ));
    if ($changes30 >= 3) {
        $add(
            'churn',
            'Status changed ' . $changes30 . ' times in 30 days',
            'Frequent transitions usually mean a correction, not a trajectory.',
            'Read the reasons in order — the pattern may show the record was repeatedly wrong, rather than the student changing.',
            'medium'
        );
    }

    return $findings;
}

/**
 * The cohort sweep: run the contradiction rules across every student and
 * return only the students who actually trip one.
 *
 * Deliberately four queries, not N. The earlier risk check issued two
 * queries per student inside a loop, so a 500-student roster meant a
 * thousand round trips before the page finished drawing. Here each fact
 * arrives in one aggregate pass and the rules run over memory.
 *
 * @param  array $limitTo Optional student ids to restrict to.
 * @return array          ['findings' => [...], 'scanned' => int]
 */
function statusCohortFindings(array $limitTo = []): array
{
    $db = Database::getInstance();
    $out = ['findings' => [], 'scanned' => 0, 'errors' => []];

    $try = function (string $key, callable $fn) use (&$out) {
        try {
            return $fn();
        } catch (Throwable $e) {
            $out['errors'][] = $key . ': ' . $e->getMessage();
            return null;
        }
    };

    $params = [];
    $where  = '';
    if ($limitTo) {
        $ids = array_values(array_unique(array_map('intval', $limitTo)));
        if (!$ids) {
            return $out;
        }
        $where  = ' WHERE s.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = $ids;
    }

    $students = $try('students', fn() => $db->fetchAll(
        "SELECT s.id, s.student_number, s.first_name, s.last_name, s.status
         FROM students s" . $where,
        $params
    ));
    if (!is_array($students) || !$students) {
        return $out;
    }
    $out['scanned'] = count($students);

    $ids    = array_map(fn($r) => (int) $r['id'], $students);
    $idList = implode(',', array_fill(0, count($ids), '?'));

    // One query for every pending discipline case in the cohort.
    $pendingByStudent = [];
    $disc = $try('discipline', fn() => $db->fetchAll(
        "SELECT student_id, COUNT(*) AS n FROM discipline_records
         WHERE status = 'pending' AND student_id IN ($idList)
         GROUP BY student_id",
        $ids
    ));
    foreach (is_array($disc) ? $disc : [] as $d) {
        $pendingByStudent[(int) $d['student_id']] = (int) $d['n'];
    }

    // One query for every outstanding balance.
    $balByStudent = [];
    $fin = $try('finance', fn() => $db->fetchAll(
        "SELECT student_id, balance FROM finance
         WHERE balance > 0 AND student_id IN ($idList)",
        $ids
    ));
    foreach (is_array($fin) ? $fin : [] as $f) {
        $balByStudent[(int) $f['student_id']] = (float) $f['balance'];
    }

    // One query for every closed window. The newest row per student wins,
    // so an old expired window reads as history rather than an open problem.
    $expired = [];
    $win = $try('windows', fn() => $db->fetchAll(
        "SELECT student_id, current_status, end_date FROM status_tracker
         WHERE end_date IS NOT NULL AND end_date < CURDATE()
           AND student_id IN ($idList)
         ORDER BY student_id, created_at DESC, id DESC",
        $ids
    ));
    foreach (is_array($win) ? $win : [] as $w) {
        $sid = (int) $w['student_id'];
        if (!isset($expired[$sid])) {
            $expired[$sid] = $w;
        }
    }

    foreach ($students as $s) {
        $sid    = (int) $s['id'];
        $status = strtolower((string) ($s['status'] ?? ''));
        $hits   = [];

        if (!empty($pendingByStudent[$sid])) {
            $n = $pendingByStudent[$sid];
            $hits[] = [
                'code'     => 'discipline_pending',
                'title'    => 'Unresolved disciplinary case' . ($n > 1 ? 's' : ''),
                'detail'   => $n . ' pending while the status is ' . ($status !== '' ? $status : 'unset') . '.',
                'question' => 'The status and the case file disagree. Settle the case first, then revisit the status.',
                'weight'   => 'high',
            ];
        }

        if (!empty($balByStudent[$sid])
            && isTerminalStudentStatus($status)) {
            $hits[] = [
                'code'     => 'finance_balance',
                'title'    => 'Balance on a closed status',
                'detail'   => 'PHP ' . number_format($balByStudent[$sid], 2) . ' outstanding against ' . $status . '.',
                'question' => 'Confirm this is known and not a missed collection.',
                'weight'   => 'high',
            ];
        }

        if (!empty($expired[$sid])) {
            $w    = $expired[$sid];
            $over = (int) floor((time() - strtotime((string) $w['end_date'])) / 86400);
            $hits[] = [
                'code'     => 'window_expired',
                'title'    => 'Leave window closed ' . $over . ' day' . ($over === 1 ? '' : 's') . ' ago',
                'detail'   => 'Recorded as ' . str_replace('_', ' ', (string) $w['current_status'])
                    . ', ending ' . date('M j, Y', strtotime((string) $w['end_date'])) . '.',
                'question' => 'Nobody has decided whether this student returns. The date passed on its own.',
                'weight'   => 'high',
            ];
        }

        if ($hits) {
            $out['findings'][] = [
                'student_id'     => $sid,
                'student_name'   => trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')),
                'student_number' => (string) ($s['student_number'] ?? ''),
                'current_status' => $status,
                'weight'         => 'high',
                'issues'         => $hits,
            ];
        }
    }

    return $out;
}

