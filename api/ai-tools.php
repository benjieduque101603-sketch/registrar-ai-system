<?php
// ============================================================
//  API/AI-TOOLS.PHP
//  Batch AI tools for the student list page.
//  Actions:
//    action=quality           → deterministic student-record quality queue
//    action=quality_summary   → AI explanation of one student's detected issues
//    action=apply_safe_repairs → apply registrar-confirmed safe corrections
//    action=case_brief        → read-only: one student's evidence + contradictions
//    action=missed_checks     → read-only: contradictions across the whole roster
//    action=student_risks     → attention level per student (rules own the level)
//    action=status_recommendations → rule-detected transitions a person may make
//  LLM responses are cached in ai_cache. Deterministic checks create findings;
//  writes are limited to explicitly confirmed safe repairs.
//
//  Note on labelling: status_anomalies and student_risks are rules, not AI.
//  The model is used to phrase what a rule found, never to decide whether a
//  rule fires. status_recommendations is the only action that names a target
//  status, and applying it is always a separate, confirmed request.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/ai_client.php';
require_once __DIR__ . '/../shared/normalize.php';
require_once __DIR__ . '/../shared/student_quality.php';
require_once __DIR__ . '/../shared/status_evidence.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

// ── Authorization (A1: IDOR) ───────────────────────────────────
//
// Every action in this file is registrar/staff analytical work: data
// quality across the whole roster, status evidence, risk levels and
// anomaly scans. None of it belongs to a logged-in STUDENT, yet the
// endpoint previously accepted any authenticated session and only
// role-checked three of eleven actions.
//
// The result was an IDOR (CWE-639): a student could POST
// {"action":"case_brief","student_id":<any other id>} and read another
// student's status evidence, GWA history and profile, or pass an array
// of ids to student_risks to enumerate the roster.
//
// Fixed by allow-listing the roles that legitimately use these tools
// rather than deny-listing the actions, so a newly added action cannot
// silently default to "open to everyone". Only registrar/ pages call
// this endpoint (registrar/students.php, registrar/status-tracker.php),
// so staff-tier roles are the right boundary.
$AI_TOOLS_ROLES = ['admin', 'registrar', 'staff'];

if (!in_array(getCurrentUserRole(), $AI_TOOLS_ROLES, true)) {
    error_log('[ai-tools] denied action=' . ($action ?: '(none)')
        . ' role=' . (getCurrentUserRole() ?? '(none)')
        . ' uid=' . (int)($_SESSION['user_id'] ?? 0));
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Forbidden. These tools are limited to registrar staff.',
    ]);
    exit;
}

$db = Database::getInstance();

switch ($action) {

    // ─── STUDENT PAGE: DATA QUALITY SCAN ────────────────────────
    case 'quality':
        $students = $db->fetchAll(
            "SELECT id, student_number, first_name, middle_name, last_name, gender,
                    birth_date, nationality, address, contact_number, email, course,
                    major, year_level, school_year, semester, section, status
             FROM students ORDER BY last_name, first_name, id"
        );
        $reports = [];
        $issueCounts = ['identity' => 0, 'contact' => 0, 'academic' => 0, 'duplicate' => 0];
        foreach ($students as $student) {
            $report = buildStudentQualityReport($student);
            $report['duplicates'] = studentQualityDuplicateCandidates($student, $students);
            if (!empty($report['duplicates'])) $report['score'] = max(0, $report['score'] - 20);
            if (empty($report['issues']) && empty($report['duplicates'])) continue;
            $report['student'] = [
                'id' => (int)$student['id'],
                'student_number' => (string)($student['student_number'] ?? ''),
                'name' => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')),
                'course' => (string)($student['course'] ?? ''),
                'year_level' => $student['year_level'],
                'status' => (string)($student['status'] ?? ''),
            ];
            $categories = array_unique(array_column($report['issues'], 'category'));
            if (!empty($report['duplicates'])) $categories[] = 'duplicate';
            foreach ($categories as $category) $issueCounts[$category] = ($issueCounts[$category] ?? 0) + 1;
            $reports[] = $report;
        }
        usort($reports, static function (array $a, array $b): int {
            return count($b['issues']) <=> count($a['issues']) ?: $a['score'] <=> $b['score'];
        });
        echo json_encode(['success' => true, 'data' => [
            'total_students' => count($students),
            'needs_review' => count($reports),
            'issue_counts' => $issueCounts,
            'students' => $reports,
            'generated_at' => date('c'),
        ]]);
        exit;

    // ─── AI EXPLANATION OF ONE STUDENT'S DETECTED ISSUES ─────────
    case 'quality_summary':
        $studentId = (int)($input['student_id'] ?? 0);
        if (!$studentId) {
            echo json_encode(['success' => false, 'message' => 'Student is required.']);
            exit;
        }
        $student = $db->fetchOne("SELECT * FROM students WHERE id = ?", [$studentId]);
        if (!$student) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }
        $report = buildStudentQualityReport($student);
        $facts = [
            'name' => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')),
            'score' => $report['score'],
            'issues' => array_map(static fn(array $issue): array => [
                'field' => $issue['label'], 'severity' => $issue['severity'],
                'message' => $issue['message'],
            ], $report['issues']),
        ];
        $summary = aiGenerate(
            'You explain deterministic student data-quality findings to a registrar. Write one concise paragraph. Use only supplied facts, do not invent values, and do not recommend automatic identity changes.',
            json_encode($facts),
            ['max_tokens' => 220, 'temperature' => 0.1]
        );
        echo json_encode(['success' => true, 'data' => [
            'summary' => $summary !== '' ? $summary : $report['summary'],
            'source' => $summary !== '' ? 'ai' : 'rules',
            'report' => $report,
        ]]);
        exit;

    // ─── APPLY EXPLICITLY CONFIRMED SAFE REPAIRS ────────────────
    case 'apply_safe_repairs':
        $studentId = (int)($input['student_id'] ?? 0);
        $requested = $input['repairs'] ?? [];
        if (!$studentId || !is_array($requested) || empty($requested)) {
            echo json_encode(['success' => false, 'message' => 'Student and safe repairs are required.']);
            exit;
        }
        $conn = $db->getConnection();
        $conn->beginTransaction();
        try {
            $student = $db->fetchOne("SELECT * FROM students WHERE id = ? FOR UPDATE", [$studentId]);
            if (!$student) {
                $conn->rollBack();
                echo json_encode(['success' => false, 'message' => 'Student not found.']);
                exit;
            }
            $allowed = ['contact_number', 'email', 'course'];
            $report = buildStudentQualityReport($student);
            $safeByField = [];
            foreach ($report['safe_repairs'] as $repair) $safeByField[$repair['field']] = $repair;
            $updates = [];
            $oldValues = [];
            foreach ($requested as $request) {
                $field = (string)($request['field'] ?? '');
                $expected = (string)($request['expected_value'] ?? '');
                $suggested = (string)($request['suggested_value'] ?? '');
                if (!in_array($field, $allowed, true) || !isset($safeByField[$field])) continue;
                if ($safeByField[$field]['current_value'] !== $expected) {
                    $conn->rollBack();
                    echo json_encode(['success' => false, 'message' => 'The record changed. Refresh the quality check before applying.']);
                    exit;
                }
                if ($safeByField[$field]['suggested_value'] !== $suggested) continue;
                $updates[$field] = $suggested;
                $oldValues[$field] = $expected;
            }
            if (empty($updates)) {
                $conn->rollBack();
                echo json_encode(['success' => false, 'message' => 'No verified safe repairs were selected.']);
                exit;
            }
            $db->update('students', $updates, 'id = ?', [$studentId]);
            logActivity(
                (int)($_SESSION['user_id'] ?? 0), 'student_quality_safe_repair', null,
                'students', $studentId, $oldValues, $updates
            );
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            error_log('[ai-tools] safe repair failed: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Corrections could not be applied.']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Safe corrections applied.', 'data' => ['updated_fields' => array_keys($updates)]]);
        exit;



    // ─── BATCH AI REPORT (whole list summary) ─────────────────
    case 'report':

        $students = $db->fetchAll("SELECT * FROM students");
        $total = count($students);
        $active = 0; $atRisk = 0; $noGender = 0; $noCourse = 0;
        $byCourse = []; $byStatus = [];
        foreach ($students as $s) {
            if (($s['status'] ?? '') === 'active') $active++;
            if (($s['status'] ?? '') === 'at-risk') $atRisk++;
            if (empty(trim((string)($s['gender'] ?? '')))) $noGender++;
            if (empty(trim((string)($s['course'] ?? '')))) $noCourse++;
            $c = trim((string)($s['course'] ?? 'N/A'));
            $byCourse[$c] = ($byCourse[$c] ?? 0) + 1;
            $st = $s['status'] ?? 'N/A';
            $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
        }
        arsort($byCourse); arsort($byStatus);

        $system = "You are a registrar's reporting assistant. Write a concise 3-4 sentence summary of the student population, highlighting notable trends or concerns a registrar should know. Do not invent data.";
        $facts = "Total students: {$total}\n"
            . "Active: {$active}, At-risk: {$atRisk}, Missing gender: {$noGender}, Missing course: {$noCourse}\n"
            . "By course: " . json_encode($byCourse) . "\n"
            . "By status: " . json_encode($byStatus);

        $report = aiGenerate($system, $facts, ['max_tokens' => 300]);
        if ($report === '') {
            $report = "Total students: {$total}. Active: {$active}. No data issues found to report.";
        }

        echo json_encode(['success' => true, 'data' => ['report' => $report]]);
        exit;

    // ─── STATUS RECOMMENDATIONS (AI) ────────────────────────
    case 'status_recommendations':
        $allS = $db->fetchAll(
            "SELECT s.id,s.student_number,s.first_name,s.last_name,s.course,
                    s.year_level,s.status,MAX(st.created_at) AS last_change
             FROM students s LEFT JOIN status_tracker st ON st.student_id=s.id
             GROUP BY s.id ORDER BY s.id"
        );
        $recs = [];
        foreach ($allS as $s) {
            $sid = (int) $s['id'];
            $st  = strtolower(trim((string) ($s['status'] ?? '')));
            $nm  = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
            $ds  = $s['last_change'] ? (int) floor((time() - strtotime($s['last_change'])) / 86400) : 999;
            $ad = (int) ($db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE student_id=? AND status NOT IN ('completed','claimed')", [$sid]) ?? 0);
            $sc = (int) ($db->fetchColumn("SELECT COUNT(*) FROM rfid_scan_logs l JOIN rfid_cards c ON l.card_uid=c.card_uid WHERE c.student_id=? AND l.scan_time>=DATE_SUB(NOW(),INTERVAL 30 DAY)", [$sid]) ?? 0);
            $r = null;
            // These rules used to recommend probation, at-risk and inactive -
            // none of which is a status the column can hold any more, so every
            // recommendation they produced would have been rejected on apply, or
            // (before validation existed) written as ''. They now recommend only
            // real statuses, and the advisory signal that used to trigger a
            // change is a condition for a REVIEW instead: a machine should not
            // move a student's enrolment state because a card has not been
            // scanned.
            if ($st === 'graduate' && $ad === 0 && (int)($s['year_level'] ?? 0) >= 4)
                                                             $r = ['recommended_status'=>'alumni','severity'=>'medium',"reason"=>"$nm has graduated with no pending documents."];
            elseif (isCurrentStudentStatus($st) && $ds > 90 && $sc === 0)
                                                             $r = ['recommended_status'=>null,'severity'=>'low',"reason"=>"$nm has had no card activity for {$ds} days. Confirm they are still attending."];
            if ($r) { $r['student_id']=$sid; $r['student_name']=$nm; $r['student_number']=(string)($s['student_number']??''); $r['current_status']=$st; $r['action_type']=$r['recommended_status']?'change_status':'review'; $recs[]=$r; }
        }
        $src = 'rule';
        if (!empty($recs) && function_exists('aiGenerateJson')) {
            $ai = aiGenerateJson("Refine recommendations. JSON: {\"recommendations\":[{\"student_id\":int,\"student_name\":str,\"student_number\":str,\"current_status\":str,\"recommended_status\":str|null,\"severity\":\"low\"|\"medium\"|\"high\",\"reason\":str,\"action_type\":\"change_status\"|\"review\"}]}", json_encode(array_slice($recs, 0, 20)), [], ['max_tokens' => 1200]);
            if (is_array($ai) && !empty($ai['recommendations'])) { $recs = $ai['recommendations']; $src = 'ai'; }
        }
        usort($recs, fn($a,$b) => (['high'=>0,'medium'=>1,'low'=>2][$a['severity']??'low']??2) <=> (['high'=>0,'medium'=>1,'low'=>2][$b['severity']??'low']??2));
        echo json_encode(['success' => true, 'data' => ['recommendations' => array_slice($recs, 0, 15), 'source' => $src]]);
        exit;

    // ─── STUDENT PROFILE (registrar-safe read-only) ─────
    case 'profile':
        $studentId = (int)($input['id'] ?? 0);
        if (!$studentId) { echo json_encode(['success'=>false,'message'=>'Student is required.']); exit; }
        $student = $db->fetchOne("SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name, course, year_level, status FROM students WHERE id = ?", [$studentId]);
        if (!$student) { echo json_encode(['success'=>false,'message'=>'Student not found.']); exit; }
        $history = $db->fetchAll("SELECT previous_status, current_status, reason, created_at FROM status_tracker WHERE student_id=? ORDER BY created_at DESC LIMIT 12", [$studentId]);
        $current = strtolower((string)($student['status'] ?? 'inactive'));
        $attention = in_array($current, ['at-risk','probation'], true) ? 'Review recommended' : ($current === 'inactive' ? 'Inactive record' : 'Routine review');
        $summary = sprintf('%s is currently listed as %s with %d recorded status change(s).', $student['name'], $current, count($history));
        $recommendation = $attention === 'Routine review' ? 'No immediate status action is indicated by the available tracker records.' : 'Review the student’s status history and supporting registrar records before making any status change.';
        echo json_encode(['success'=>true,'data'=>['summary'=>$summary,'recommendation'=>$recommendation,'attention'=>$attention,'source'=>'rules','student'=>$student,'history_count'=>count($history)]]);
        exit;

    // ─── STUDENT RISKS ──────────────────────────────────────
    //
    // Answers "which of these students is worth my attention", for the
    // Risk column and the attention sort.
    //
    // Two changes from the version this replaces.
    //
    // It ran two queries per student inside the loop, on every page
    // load, for every student in the roster — a thousand round trips
    // before the table finished drawing. Both facts are now collected
    // in one pass each and the rules run over memory.
    //
    // And the LLM no longer decides the risk level. It used to be handed
    // the finished map and asked to reassess it, which meant a model
    // could quietly promote a low risk to high on a hunch — or, with
    // the gateway down, silently return the old numbers, so nobody
    // could tell whether the column was live. The rules now own the
    // level; the model is only allowed to add a clause of explanation.
    case 'student_risks':
    case 'status_risks':   // alias: the page called this name and got "Unknown action"
        $ids = $input['student_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            // An empty list means "every student" — the page asks for the
            // whole roster to paint the Risk column, and rejecting it for
            // being empty is what left that column permanently blank.
            $ids = array_map(fn($r) => (int) $r['id'], $db->fetchAll('SELECT id FROM students'));
            if (!$ids) {
                echo json_encode(['success' => true, 'data' => ['risks' => [], 'source' => 'rule']]);
                exit;
            }
        }
        $ids  = array_map('intval', $ids);
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $rows = $db->fetchAll(
            "SELECT s.id, s.first_name, s.last_name, s.status
             FROM students s WHERE s.id IN ($ph)",
            $ids
        );

        // One query for every recent status change in the set.
        $histBy = [];
        $hist = $db->fetchAll(
            "SELECT student_id, current_status, created_at
             FROM status_tracker
             WHERE student_id IN ($ph)
             ORDER BY student_id, created_at DESC, id DESC",
            $ids
        );
        foreach ($hist as $h) {
            $k = (int) $h['student_id'];
            if (!isset($histBy[$k])) $histBy[$k] = [];
            if (count($histBy[$k]) < 10) $histBy[$k][] = $h;
        }

        // One query for every latest GWA.
        $gwaBy = [];
        $gwas = $db->fetchAll(
            "SELECT h.student_id, h.gwa
             FROM academic_history h
             JOIN (
                 SELECT student_id, MAX(id) AS mid
                 FROM academic_history
                 WHERE student_id IN ($ph) AND gwa IS NOT NULL
                 GROUP BY student_id
             ) latest ON latest.mid = h.id",
            $ids
        );
        foreach ($gwas as $g) {
            $gwaBy[(int) $g['student_id']] = (float) $g['gwa'];
        }

        $rr = [];
        foreach ($rows as $s) {
            $sid = (int) $s['id'];
            $st  = strtolower(trim((string) ($s['status'] ?? '')));
            $h   = $histBy[$sid] ?? [];

            // A streak of consecutive at-risk/probation rows, counted from
            // the most recent backwards and stopping at the first status
            // that is neither.
            $cc = 0;
            foreach ($h as $x) {
                $cs = strtolower((string) ($x['current_status'] ?? ''));
                if (in_array($cs, ['at-risk', 'probation'], true)) $cc++;
                else break;
            }
            $last = $h[0]['created_at'] ?? null;
            $days = $last ? (int) floor((time() - strtotime($last)) / 86400) : 999;
            $gwa  = $gwaBy[$sid] ?? null;

            $r = 'low'; $reason = 'Stable, no red flags.';
            if (in_array($st, ['at-risk', 'probation'], true)) {
                $r = 'high';
                $reason = "Currently $st" . ($cc >= 2 ? " for $cc consecutive periods" : '') . '.';
            } elseif ($st === 'dropped') {
                $r = 'medium'; $reason = 'Dropped.';
            } elseif ($gwa !== null && $gwa > 3.0) {
                $r = 'medium'; $reason = 'GWA ' . $gwa . ' above 3.0.';
            } elseif ($days > 180) {
                $r = 'medium'; $reason = "No change for $days days.";
            }
            $rr[$sid] = [
                'risk'   => $r,
                'reason' => $reason,
                'name'   => trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')),
            ];
        }

        // The model may sharpen the wording. It may not change a level:
        // an answer that disagrees with the rule's own level is discarded
        // rather than merged, so a hallucinated "high" can never reorder
        // the registrar's attention queue.
        $src = 'rule';
        if (function_exists('aiGenerateJson') && count($rr) > 0 && count($rr) <= 20) {
            $facts = [];
            foreach ($rr as $k => $v) {
                $facts[] = ['id' => (int) $k, 'risk' => $v['risk'], 'facts' => $v['reason']];
            }
            $ai = aiGenerateJson(
                'Explain each student\'s risk level in one short clause, using only the facts given. '
                    . 'You must return the SAME risk level you were given and must not change it. '
                    . 'JSON: {"risks":{"id":{"risk":"low"|"medium"|"high","reason":str}}}',
                json_encode($facts),
                [],
                ['max_tokens' => 1200, 'temperature' => 0.1]
            );
            if (is_array($ai) && !empty($ai['risks']) && is_array($ai['risks'])) {
                foreach ($ai['risks'] as $k => $v) {
                    $k = (int) $k;
                    if (!isset($rr[$k]) || !is_array($v)) continue;
                    $proposed = strtolower(trim((string) ($v['risk'] ?? '')));
                    if ($proposed === $rr[$k]['risk'] && trim((string) ($v['reason'] ?? '')) !== '') {
                        $rr[$k]['reason'] = (string) $v['reason'];
                        $src = 'ai';
                    }
                }
            }
        }

        echo json_encode(['success' => true, 'data' => ['risks' => $rr, 'source' => $src]]);
        exit;


    // ─── ASSEMBLE A CASE (read-only, one student) ───────────────
    //
    // The replacement for the old "profile" action, which built its
    // "AI brief" with sprintf() and never called a model at all.
    //
    // It gathers the evidence a registrar would otherwise have to pull
    // from six tables by hand, runs the contradiction rules over it, and
    // then asks the model one narrow question: what should this person
    // look at before deciding? The model is given the findings as
    // finished facts and may only phrase them — it cannot add a finding
    // and cannot suggest a destination status.
    case 'case_brief':
        $studentId = (int) ($input['id'] ?? 0);
        if (!$studentId) {
            echo json_encode(['success' => false, 'message' => 'Student is required.']);
            exit;
        }
        $ev  = statusStudentEvidence($studentId);
        if (empty($ev['student'])) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }
        $finds = statusEvidenceFindings($ev);
        $s     = $ev['student'];

        $briefSource = 'rules';
        $note = '';
        if ($finds && function_exists('aiGenerate')) {
            $facts = [
                'student' => [
                    'name'   => trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')),
                    'status' => $s['status'] ?? '',
                    'course' => $s['course'] ?? '',
                    'year'   => $s['year_level'] ?? '',
                ],
                'flags' => array_map(static fn(array $f): array => [
                    'title'  => $f['title'],
                    'detail' => $f['detail'],
                ], $finds),
            ];
            $note = aiGenerate(
                'You are briefing a college registrar on one student before they decide a status. '
                    . 'In at most three sentences, state what the record shows and what the person should verify. '
                    . 'Use only the flags given. Do not invent facts, do not speculate about intent, '
                    . 'and do not recommend a status — the decision is theirs. If nothing is flagged, say so plainly.',
                json_encode($facts),
                ['max_tokens' => 220, 'temperature' => 0.1]
            );
            if (trim($note) !== '') $briefSource = 'ai';
        }
        if (trim($note) === '') {
            $note = $finds
                ? count($finds) . ' point' . (count($finds) === 1 ? '' : 's') . ' in this record need a decision.'
                : 'Nothing in this record contradicts itself.';
        }

        echo json_encode(['success' => true, 'data' => [
            'student'  => [
                'id' => (int) $s['id'],
                'name' => trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')),
                'student_number' => (string) ($s['student_number'] ?? ''),
                'status' => (string) ($s['status'] ?? ''),
                'course' => (string) ($s['course'] ?? ''),
                'year_level' => $s['year_level'] ?? '',
            ],
            'note'     => $note,
            'source'   => $briefSource,
            'findings' => $finds,
            'evidence' => [
                'history'      => $ev['history'],
                'discipline'   => $ev['discipline'],
                'balance'      => (float) ($ev['finance']['balance'] ?? 0),
                'grades'       => $ev['grades'],
                'documents'    => $ev['documents'],
                'has_guardian' => !empty($ev['guardian']),
                'last_scan'    => $ev['activity']['last_scan'] ?? null,
                'window'       => $ev['window'],
            ],
            // Surfaced rather than swallowed: on a server missing a
            // migration, a section silently reading as "nothing found" is
            // worse than a visible note that it could not be read.
            'partial'   => !empty($ev['errors']),
        ]]);
        exit;

    // ─── CHECK WHAT I MISSED (read-only, whole cohort) ─────────
    //
    // Runs the contradiction rules over every student and returns only
    // the ones that trip something. Advisory by construction: the
    // response carries no status field and offers no write path.
    case 'missed_checks':
        $limit = $input['student_ids'] ?? [];
        if (!is_array($limit)) $limit = [];
        $res = statusCohortFindings($limit);

        // One sentence over the top, so the panel opens with the answer
        // rather than making the reader count a badge.
        $headline = '';
        $source   = 'rules';
        $n = count($res['findings']);
        if ($n && function_exists('aiGenerate')) {
            $titles = [];
            foreach ($res['findings'] as $f) {
                foreach ($f['issues'] as $i) {
                    $titles[] = $i['title'];
                }
            }
            $headline = aiGenerate(
                'You are summarising an automated record check for a college registrar. In one sentence, '
                    . 'say how many students have a contradiction worth a person\'s attention and name the '
                    . 'single most common kind. Use only the counts given. Do not recommend any action — '
                    . 'this only reports what the check found.',
                json_encode([
                    'scanned' => $res['scanned'],
                    'flagged' => $n,
                    'by_type' => array_count_values($titles),
                ]),
                ['max_tokens' => 120, 'temperature' => 0.1]
            );
            if (trim($headline) !== '') $source = 'ai';
        }
        if (trim($headline) === '') {
            $headline = $n === 0
                ? 'No contradictions found across ' . $res['scanned'] . ' students.'
                : $n . ' of ' . $res['scanned'] . ' students have a record that contradicts itself.';
        }

        echo json_encode(['success' => true, 'data' => [
            'headline' => $headline,
            'source'   => $source,
            'findings' => $res['findings'],
            'scanned'  => $res['scanned'],
            'partial'  => !empty($res['errors']),
        ]]);
        exit;

    // ─── STATUS ANOMALIES (rule-based, not AI) ───────────────
    case 'status_anomalies':
        $anom = [];
        $freq = $db->fetchAll("SELECT st.student_id,s.first_name,s.last_name,s.student_number,COUNT(*) AS cnt FROM status_tracker st JOIN students s ON s.id=st.student_id WHERE st.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY st.student_id HAVING cnt>=3");
        if ($freq) $anom[] = ['type'=>'frequent_changes','label'=>count($freq).' student(s) changed 3+ times in 30 days','icon'=>'fas fa-sync-alt','color'=>'#f59e0b','students'=>array_map(fn($r)=>['id'=>(int)$r['student_id'],'name'=>trim(($r['first_name']??'').' '.($r['last_name']??'')),'student_number'=>(string)($r['student_number']??''),'count'=>(int)$r['cnt']],$freq)];
        $gp = $db->fetchAll("SELECT s.id,s.first_name,s.last_name,s.student_number FROM students s JOIN document_requests dr ON dr.student_id=s.id AND dr.status NOT IN ('completed','claimed') WHERE s.status='graduated' GROUP BY s.id");
        if ($gp) $anom[] = ['type'=>'grad_pending_docs','label'=>count($gp).' graduated with pending docs','icon'=>'fas fa-file-circle-exclamation','color'=>'#8b5cf6','students'=>array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>trim(($r['first_name']??'').' '.($r['last_name']??'')),'student_number'=>(string)($r['student_number']??'')],$gp)];
        $inact = $db->fetchAll("SELECT s.id,s.first_name,s.last_name,s.student_number FROM students s LEFT JOIN status_tracker st ON st.student_id=s.id LEFT JOIN rfid_cards rc ON rc.student_id=s.id LEFT JOIN rfid_scan_logs rl ON rl.card_uid=rc.card_uid WHERE s.status IN ('enrolled','active') AND (st.created_at IS NULL OR st.created_at<DATE_SUB(NOW(),INTERVAL 90 DAY)) GROUP BY s.id HAVING COUNT(DISTINCT rl.id)=0");
        if ($inact) $anom[] = ['type'=>'inactive_students','label'=>count($inact).' inactive 90+ days no activity','icon'=>'fas fa-ghost','color'=>'#6366f1','students'=>array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>trim(($r['first_name']??'').' '.($r['last_name']??'')),'student_number'=>(string)($r['student_number']??'')],$inact)];
        echo json_encode(['success' => true, 'data' => ['anomalies' => $anom]]);
        exit;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
}
