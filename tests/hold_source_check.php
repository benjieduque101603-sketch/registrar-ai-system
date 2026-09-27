<?php
// ─────────────────────────────────────────────────────────────────────
//  Who set the hold: registrar judgment vs derived balance
//
//  A hold used to be one idea in one column. It was in fact two: something
//  the desk worked out from the finance balance, and something a person
//  decided. They were written to the same place, so any Re-check — which
//  only knows how to recompute the balance — silently overwrote a person's
//  judgment with NULL, with no event row and no trace that it had ever
//  been made.
//
//  These checks pin the precedence: a decision outranks a derivation, and
//  re-checking can never delete one. The DB-backed half creates a finance
//  row if the student has none and removes it again, so the resting data
//  is unchanged either way.
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/document_process.php';

$db = Database::getInstance();
$ok = 0; $bad = 0;
function t(string $name, $got, $want): void
{
    global $ok, $bad;
    $pass = ($got === $want);
    $pass ? $ok++ : $bad++;
    printf("  [%s] %-56s got=%s want=%s\n", $pass ? 'PASS' : 'FAIL', $name,
        var_export($got, true), var_export($want, true));
}

$now  = '2026-09-27 09:00:00';
$peso = html_entity_decode('&#8369;', ENT_QUOTES, 'UTF-8');

// ── Pure logic, no database ──────────────────────────────────────────
t('balance still blocks with no manual hold',
    doc_blocker(['document_status'=>'Filed','balance'=>250.0,'request_date'=>$now])['reason'] ?? null,
    'Outstanding balance: ' . $peso . '250.00');

t('registrar hold wins over zero balance',
    doc_blocker(['document_status'=>'Filed','balance'=>0.0,'request_date'=>$now,
                 'blocked_source'=>'registrar','blocked_reason'=>"Dean's office has the affidavit"])['reason'] ?? null,
    "Dean's office has the affidavit");

t('registrar hold wins over unpaid balance',
    doc_blocker(['document_status'=>'Filed','balance'=>500.0,'request_date'=>$now,
                 'blocked_source'=>'registrar','blocked_reason'=>'ID being reprinted'])['reason'] ?? null,
    'ID being reprinted');

t('nothing held when clean',
    doc_blocker(['document_status'=>'Filed','balance'=>0.0,'request_date'=>$now]), null);

// A reason whose source is not 'registrar' is a leftover, not a decision.
// Honouring it is how a stale derived value would creep back in.
t('registrar reason without source=registrar is ignored',
    doc_blocker(['document_status'=>'Filed','balance'=>0.0,'request_date'=>$now,
                 'blocked_source'=>'balance','blocked_reason'=>'stale note']), null);

t('blank registrar reason is not a hold',
    doc_blocker(['document_status'=>'Filed','balance'=>0.0,'request_date'=>$now,
                 'blocked_source'=>'registrar','blocked_reason'=>'   ']), null);

// Settled work is settled. A person cannot hold a request the student has
// already collected, and must not be able to by typing a note.
t('Claimed ignores a registrar hold',
    doc_blocker(['document_status'=>'Claimed','balance'=>0.0,'request_date'=>$now,
                 'blocked_source'=>'registrar','blocked_reason'=>'x']), null);

// ── Against the database ─────────────────────────────────────────────
// Creates its own request rather than borrowing whatever the desk happens
// to hold. The earlier version looked for a Filed row and exited early if
// it found none — which is how it came to report "no Filed request to
// test against" and still exit 0, i.e. a green run that had asserted
// nothing at all. A check that can silently check nothing is worse than no
// check, because it looks like coverage.
$stu = (int) $db->fetchColumn('SELECT id FROM students ORDER BY id LIMIT 1');
$cat = (int) $db->fetchColumn('SELECT id FROM document_catalog WHERE is_active = 1 ORDER BY id LIMIT 1');
if (!$stu || !$cat) {
    echo "  cannot build a fixture: need a student and an active catalog row\n";
    exit(1);
}
$sid = $stu;
$id  = $db->insert('document_requests', [
    'request_date'     => $now,
    'student_id'       => $sid,
    'document_type'    => 'transcript',
    'purpose'          => 'hold_source_check fixture',
    'status'           => 'pending',
    'catalog_id'       => $cat,
    'quantity'         => 1,
    'request_type'     => 'Regular',
    'fulfillment_type' => 'Pickup',
    'document_status'  => 'Filed',
]);

$hadFinance = (int) $db->fetchColumn('SELECT COUNT(*) FROM finance WHERE student_id = ?', [$sid]) > 0;
if (!$hadFinance) $db->insert('finance', ['student_id' => $sid, 'balance' => 0]);

// Removed on the way out however the run ends, so the resting data is
// untouched even if a check throws partway through.
register_shutdown_function(function () use ($db, $id, $sid, $hadFinance) {
    try {
        $db->query('DELETE FROM document_request_events WHERE request_id = ?', [$id]);
        $db->query('DELETE FROM document_requests WHERE id = ?', [$id]);
        if (!$hadFinance) $db->query('DELETE FROM finance WHERE student_id = ? AND balance = 0', [$sid]);
    } catch (Throwable $e) {
        // Never mask the real result with a cleanup failure.
    }
});

$reason = fn() => $db->fetchColumn('SELECT blocked_reason FROM document_requests WHERE id = ?', [$id]);
$source = fn() => $db->fetchColumn('SELECT blocked_source FROM document_requests WHERE id = ?', [$id]);

// The regression this file exists for.
doc_set_registrar_hold($id, 'Guidance Office signature', 1);
$db->query('UPDATE finance SET balance = 0 WHERE student_id = ?', [$sid]);
doc_refresh_blocker($id);
t('recheck with a clean balance keeps a registrar hold', $reason(), 'Guidance Office signature');
t('recheck keeps the source', $source(), 'registrar');

doc_set_registrar_hold($id, '', 1);
t('clearing lifts the hold', $reason(), null);
t('clearing resets the source', $source(), null);

// A derived hold is not the registrar's to lift by hand.
$db->query('UPDATE finance SET balance = 750 WHERE student_id = ?', [$sid]);
doc_refresh_blocker($id);
t('balance hold is labelled balance', $source(), 'balance');
t('balance hold shows the amount', $reason(), 'Outstanding balance: ' . $peso . '750.00');
doc_set_registrar_hold($id, '', 1);
t('cannot clear a balance hold by hand', $reason(), 'Outstanding balance: ' . $peso . '750.00');

// A decision over an unpaid balance must survive a re-check too.
doc_set_registrar_hold($id, 'Dean office approval', 1);
$db->query('UPDATE finance SET balance = 0 WHERE student_id = ?', [$sid]);
doc_refresh_blocker($id);
t('recheck cannot delete a hold over a balance', $reason(), 'Dean office approval');

doc_set_registrar_hold($id, '', 1);
t('cleanup leaves the request unheld', $reason(), null);

// The fixture and any finance row it created are removed by the shutdown
// handler above, which also runs if a check above throws.
printf("\n  %d passed, %d failed\n", $ok, $bad);
exit($bad ? 1 : 0);
