<?php
// ============================================================
//  SHARED/DOCUMENT_PROCESS.PHP
//  The walk-in document process, in one place.
//
//  Two questions, kept deliberately separate because they are
//  genuinely different facts about a request:
//
//    1. Where is the work?     → document_status
//       Filed → Processing → Ready → Claimed, plus Rejected.
//
//    2. What is it waiting on? → blocked_reason / blocked_since
//       NULL means nothing is blocking it.
//
//  Collapsing (2) into (1) is what made multi-day requests
//  invisible. A request parked on the Dean's clearance looked exactly
//  like one the clerk had simply not started, so nothing on the desk
//  could tell "waiting on someone else" from "waiting on us" — and
//  the request aged quietly either way.
//
//  Two real causes of a long wait, both derived from data rather than
//  typed in by hand:
//
//    · Exit clearance  the catalog SKU needs Alumni + Dean + Property
//                      to sign off first
//    · Balance owing   the student has an unpaid finance balance
//
//  A named requirement used to be the third. It is not a blockage and no
//  longer counts as one — see doc_blocker() and doc_requirement_note().
//
//  Include this rather than re-deriving the logic: intake
//  (api/student-documents.php), the desk (registrar/documents.php)
//  and the API (api/documents.php) must agree, or the desk will
//  promise something intake never checked.
// ============================================================

/**
 * Is this request waiting on something outside the desk's control?
 *
 * Terminal requests are never "blocked" — they are done, and a
 * collected document is not late however long the pickup took.
 *
 * @param array $row  A document_requests row (ideally joined to
 *                    document_catalog for sku / sla_days), with
 *                    `balance` and/or `pending_offices` supplied.
 * @return array{reason:string,since:?string}|null
 */
function doc_blocker(array $row): ?array
{
    $st = (string) ($row['document_status'] ?? '');

    // Settled work, and work already signed and set aside, is not
    // blocked: Ready is waiting on the student, which is not a
    // blockage the desk can clear.
    if (in_array($st, ['Claimed', 'Rejected', 'Ready'], true)) {
        return null;
    }

    // When the blockage began. Falls back to the filing date so a
    // request always has a start for its clock.
    //
    // Written with explicit null handling rather than
    // `$row['blocked_since'] ?: $row['request_date'] ?? ''`: `?:` binds
    // tighter than `??`, so a key that is present but null never reached
    // the fallback, and an absent key warned. Both cases are real — the
    // desk passes a full row, callers often pass a hand-built array.
    $since = !empty($row['blocked_since'])
        ? (string) $row['blocked_since']
        : (string) ($row['request_date'] ?? '');

    // 0. A hold a person set by hand, and the only kind that outranks
    //    anything derived. The registrar knows things the balance cannot
    //    know — the Dean's office is holding the affidavit, the ID is being
    //    reprinted — and that is a decision, not an inference.
    //
    //    It is checked FIRST, and that ordering is the whole point. When
    //    this reason shared a column with the derived one, any Re-check
    //    re-derived the balance, found nothing, and wrote NULL over a
    //    person's note: the judgment disappeared with no event row and no
    //    way to tell it had ever been made. Keyed on blocked_source so the
    //    two can never be confused again.
    if (($row['blocked_source'] ?? null) === 'registrar'
        && !empty($row['blocked_reason'])
        && trim((string) $row['blocked_reason']) !== '') {
        return [
            'reason' => (string) $row['blocked_reason'],
            'since'  => $since,
        ];
    }

    // 1. Outstanding balance. A real block: the office cannot issue against
    //    an unpaid account, and it clears itself the moment money lands.
    if (isset($row['balance']) && (float) $row['balance'] > 0) {
        return [
            'reason' => 'Outstanding balance: ₱' . number_format((float) $row['balance'], 2),
            'since'  => $since,
        ];
    }

    // 2. A requirement the catalog names is NOT a blocker, and used to be.
    //    It was, and it was wrong: the requirement is never supplied at
    //    filing, so every request for a document that names one was held
    //    from the moment it was created, with blocked_since stamped at the
    //    filing date. The log then read "held since <filed today>" and the
    //    row counted in "Needs something first" — recording a decision the
    //    registrar never made. Holding is the registrar's call, and the
    //    desk never actually enforced it: processDoc() accepts Filed
    //    regardless of any blocker, so the request was fully workable while
    //    the log insisted it was stalled.
    //
    //    A missing requirement is now advisory - a "Bring: …" note on the
    //    row (see doc_requirement_note) that informs without holding.

    return null;
}

/**
 * The advisory note for a requirement the catalog names but nobody has
 * supplied.
 *
 * Deliberately separate from doc_blocker(). This says "the student still
 * owes you this paper"; that says "this cannot proceed". Keeping them in
 * one function was what let a filing-time fact masquerade as a registrar
 * decision, because both rendered as an hourglass and a hold date.
 *
 * @param array $row  A document_requests row joined to document_catalog,
 *                    carrying `requirement` and `requirement_file_path`.
 * @return string|null  What to ask the student for, or null if nothing.
 */
function doc_requirement_note(array $row): ?string
{
    // Settled work has no outstanding ask.
    if (in_array((string) ($row['document_status'] ?? ''), ['Claimed', 'Rejected'], true)) {
        return null;
    }
    if (empty($row['requirement']) || !empty($row['requirement_file_path'])) {
        return null;
    }
    return (string) $row['requirement'];
}

/**
 * How long a request has been open, and whether it has overrun the
 * target set on its catalog SKU.
 *
 * The clock starts when the document was filed and stops when it is
 * COLLECTED, not when it was signed. A document prepared in an hour
 * but left three days on the shelf still cost the student three days,
 * so only collection truly ends the wait.
 *
 * @return array{days:float,hours:int,target:?int,overdue:bool,due:?string}
 */
function doc_age(array $row): array
{
    $start = strtotime((string) ($row['request_date'] ?? '')) ?: time();
    $end   = strtotime((string) ($row['claimed_at'] ?? '')) ?: time();
    $secs  = max(0, $end - $start);

    $raw = $row['sla_days'] ?? null;
    $target = ($raw === null || $raw === '') ? null : (int) $raw;

    $settled = in_array((string) ($row['document_status'] ?? ''), ['Claimed', 'Rejected'], true);

    return [
        'days'    => round($secs / 86400, 1),
        'hours'   => (int) floor($secs / 3600),
        'target'  => $target,
        // A settled request cannot be late — it arrived.
        'overdue' => !$settled && $target !== null && ($secs / 86400) > $target,
        'due'     => $target === null ? null : date('M d', strtotime("+{$target} days", $start)),
    ];
}

/**
 * Human phrasing for elapsed time. Short by design: this sits in a
 * table cell, not in a sentence.
 */
function doc_age_label(array $age): string
{
    $d = (float) $age['days'];
    if ($d < 1) {
        $h = (int) $age['hours'];
        return $h < 1 ? 'just now' : $h . 'h';
    }
    if ($d < 2) {
        return '1 day';
    }
    return ((int) $d) . ' days';
}

/**
 * The lifecycle as an ordered track, for the desk's process rail.
 *
 * A status pill answers "what is this?" — one word, no memory. The
 * question a clerk actually asks is "how far along is it, and what do
 * I do now?", which needs the whole track drawn. So the rail is
 * rendered from this map, and `doc_next_step()` decides which station
 * is the current one — one source of truth, so the drawn track and
 * the offered action cannot disagree.
 *
 * Ordered, because the walk-in lifecycle genuinely is a sequence:
 * filed at the counter, prepared, signed and ready, handed over.
 *
 * @return array<int,array{key:string,label:string,verb:string}>
 */
function doc_stage_track(): array
{
    return [
        ['key' => 'Filed',      'label' => 'Filed',      'verb' => 'File'],
        ['key' => 'Processing', 'label' => 'Preparing',  'verb' => 'Prepare'],
        ['key' => 'Ready',      'label' => 'Ready',      'verb' => 'Sign'],
        ['key' => 'Claimed',    'label' => 'Claimed',    'verb' => 'Claim'],
    ];
}

/**
 * Where a request sits on the track, and whether it came off it.
 *
 * A rejected request has no station — it left the track rather than
 * advancing along it, so it is reported as index -1 with stopped=true
 * and the rail draws every station as missed instead of pretending it
 * reached the end. A blocked request is NOT off the track: it sits at
 * its station, held, which is the whole point of keeping blocked_reason
 * separate from document_status.
 *
 * @return array{index:int,stopped:bool}
 */
function doc_stage_position(string $status): array
{
    if ($status === 'Rejected') {
        return ['index' => -1, 'stopped' => true];
    }
    foreach (doc_stage_track() as $i => $stage) {
        if ($stage['key'] === $status) {
            return ['index' => $i, 'stopped' => false];
        }
    }
    return ['index' => 0, 'stopped' => false];
}

/**
 * The one legal next step for a request, or null when it is finished.
 *
 * Returning the step rather than a boolean keeps the guard in the API
 * and the button on the desk reading from the same function, so the
 * two cannot drift into disagreeing about what may happen next.
 *
 * Each step carries BOTH names, and they are deliberately different:
 *
 *   action  the API verb          → "process"
 *   handler the desk's JS entry   → "processDoc"
 *   wants   extra JS args the
 *           handler expects       → "btn" (the clicked button, so it
 *                                    can show progress and refuse a
 *                                    second click)
 *
 * `action` is the wire protocol and must not be used as a function
 * name. Doing that emitted onclick="process(7)" when the handler is
 * processDoc, so the button threw a ReferenceError and silently did
 * nothing — the API verb and the JS function only happen to differ by
 * a suffix, which is exactly the kind of coincidence that rots.
 * Carrying both makes the two namespaces explicit, and the desk
 * asserts (tests/process_check.php) that every handler it emits is
 * actually defined in the page.
 *
 * @return array{action:string,handler:string,wants?:string,label:string,icon:string}|null
 */
function doc_next_step(array $row): ?array
{
    switch ((string) ($row['document_status'] ?? '')) {
        case 'Filed':
        case 'Pending_Clearance':
            return ['action' => 'process', 'handler' => 'processDoc',      'wants' => 'btn',    'label' => 'Start preparing',   'icon' => 'fa-gear'];
        case 'Processing':
            return ['action' => 'ready',   'handler' => 'approveRelease',  'label' => 'Sign & mark ready', 'icon' => 'fa-circle-check'];
        case 'Ready':
            return ['action' => 'claim',   'handler' => 'claimDoc',        'wants' => 'btn',    'label' => 'Claim',             'icon' => 'fa-box-check'];
        case 'Claimed':
        case 'Rejected':
        default:
            return null;
    }
}

/**
 * Re-derive a request's blockage and persist it.
 *
 * Called whenever something that could release a blockage changes —
 * a clearance signed, a balance paid — so a request stops being held
 * by a fact that is no longer true. The old Pending_Clearance was
 * decided once, at intake, and never revisited: a student who paid
 * their balance the next day stayed blocked forever.
 *
 * @return bool  true if the stored blockage changed
 */
function doc_refresh_blocker(int $requestId): bool
{
    // Required here rather than at the top of the file: everything above
    // this function is pure logic that the unit checks exercise with no
    // database at all, and schema.php pulls in the connection.
    require_once __DIR__ . '/schema.php';   // db_optional_column()

    $db = Database::getInstance();
    // sla_days and requirement are named here but they are not what this
    // function decides - the balance is. Naming a column a migration has
    // not added yet makes the whole re-check fail, including the manual-hold
    // check below, which is a person's decision and must not depend on
    // schema vintage. The write side of this function already reads
    // SHOW COLUMNS before touching blocked_*; this is the read side
    // catching up, so the two agree on what "the column may be absent"
    // means.
    $row = $db->fetchOne(
        "SELECT dr.*, c.sku,
                " . db_optional_column('document_catalog', 'requirement', 'c') . ",
                " . db_optional_column('document_catalog', 'sla_days', 'c') . "
           FROM document_requests dr
           LEFT JOIN document_catalog c ON c.id = dr.catalog_id
          WHERE dr.id = ?",
        [$requestId]
    );
    if (!$row) return false;

    // A hold a person set is not re-derivable. Re-checking the balance
    // cannot confirm or refute "the Dean's office has the affidavit", and
    // the only thing this function could do with such a row is delete it.
    // It returns false (nothing changed) and leaves the reason, the clock
    // and the source exactly as the person left them. The registrar clears
    // their own hold by clearing the note.
    if (($row['blocked_source'] ?? null) === 'registrar'
        && trim((string) ($row['blocked_reason'] ?? '')) !== '') {
        return false;
    }

    $row['balance'] = (float) ($db->fetchColumn(
        'SELECT balance FROM finance WHERE student_id = ?', [$row['student_id']]
    ) ?? 0.0);

    $blocker = doc_blocker($row);
    $reason  = $blocker['reason'] ?? null;

    // Never shorten a blockage that started earlier: the clock began
    // when the FIRST blocker appeared, not when this one was noticed.
    $since = $blocker
        ? (string) ($row['blocked_since'] ?: $blocker['since'] ?: date('Y-m-d H:i:s'))
        : null;

    $cols = array_column($db->fetchAll('SHOW COLUMNS FROM document_requests'), 'Field');
    $data = [];
    if (in_array('blocked_reason', $cols, true)) $data['blocked_reason'] = $reason;
    if (in_array('blocked_since',  $cols, true)) $data['blocked_since']  = $since;
    // Labelled alongside the reason so the two can never be read as the
    // same kind of thing again. Only ever set to 'balance' here: this
    // function's whole remit is the balance, and a registrar hold is
    // written by a person through a different path.
    if (in_array('blocked_source', $cols, true)) $data['blocked_source'] = $reason !== null ? 'balance' : null;
    if (!$data) return false;

    if (($row['blocked_reason'] ?? null) === $reason
        && ($row['blocked_since'] ?? null) === $since
        && ($row['blocked_source'] ?? null) === ($reason !== null ? 'balance' : null)) {
        return false;
    }
    $db->update('document_requests', $data, 'id = ?', [$requestId]);

    // A request that was held only because of a balance is no longer
    // Pending_Clearance once that balance is settled. Release it to
    // the ordinary Filed queue so it stops looking held.
    if ($reason === null && (string) $row['document_status'] === 'Pending_Clearance') {
        $db->update('document_requests', ['document_status' => 'Filed'], 'id = ?', [$requestId]);
        $db->insert('document_request_events', [
            'request_id' => $requestId,
            'status'     => 'Filed',
            'note'       => 'Balance settled — released to the filing queue',
            'created_by' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    return true;
}

/**
 * Which kind of hold is on a request: 'balance', 'registrar', or null.
 *
 * Read by callers that need to tell the two apart in a message or a
 * control — the desk's Re-check toast, and the lift-hold endpoint. Neither
 * may claim a manual hold was re-derived, because it is not.
 *
 * Tolerates a missing blocked_source column: an un-migrated server cannot
 * have recorded a manual hold, so "not one" is the truthful answer rather
 * than a reason to fail.
 *
 * @param int $requestId
 * @return string|null
 */
function doc_hold_source(int $requestId): ?string
{
    $db = Database::getInstance();
    $row = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$requestId]);
    if (!$row) return null;
    if (trim((string) ($row['blocked_reason'] ?? '')) === '') return null;
    $src = $row['blocked_source'] ?? null;
    return in_array($src, ['balance', 'registrar'], true) ? $src : null;
}

/**
 * Record — or clear — a hold the registrar has decided on.
 *
 * This is the write path for a human judgment, and it is deliberately NOT
 * doc_refresh_blocker(): that function re-derives from the balance and
 * would overwrite whatever is here.
 *
 * Clearing (empty $reason) is a first-class operation rather than a
 * side-effect of filing a new request, because a hold a person set can
 * only honestly be lifted by a person. The event row records who and when,
 * so the log shows a hold being lifted rather than the request simply
 * appearing to unblock itself.
 *
 * @param int         $requestId
 * @param string      $reason    What it is waiting on. Empty clears the hold.
 * @param int|null    $userId    Who decided, for the event trail.
 * @return bool       True if the stored hold changed.
 */
function doc_set_registrar_hold(int $requestId, string $reason, ?int $userId = null): bool
{
    $db = Database::getInstance();
    $reason = trim($reason);

    // SELECT * rather than naming the columns. blocked_source is newer
    // than this code's other columns, and naming it here made this
    // function throw "Unknown column 'blocked_source'" on any server
    // where the migration had not been applied yet — a hard failure on
    // the one action that is entirely a person's decision, and the least
    // appropriate moment for a schema error. The function already treats
    // the column as optional below ($hasSource), so it must be selected
    // the same way. Every other read of this table does the same.
    $row = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$requestId]);
    if (!$row) return false;

    $now  = date('Y-m-d H:i:s');
    $cols = array_column($db->fetchAll('SHOW COLUMNS FROM document_requests'), 'Field');
    $hasSource = in_array('blocked_source', $cols, true);

    if ($reason === '') {
        // Only the registrar's own hold is lifted here. A balance-derived
        // hold is not theirs to clear, and nulling it here would hide a
        // real unpaid account until the next Re-check put it back.
        if (($row['blocked_source'] ?? null) !== 'registrar') return false;
        $data = ['blocked_reason' => null, 'blocked_since' => null];
        if ($hasSource) $data['blocked_source'] = null;
        $db->update('document_requests', $data, 'id = ?', [$requestId]);

        $db->insert('document_request_events', [
            'request_id' => $requestId,
            'status'     => (string) $row['document_status'],
            'note'       => 'Hold cleared by the registrar — back on the desk\'s queue',
            'created_by' => $userId,
            'created_at' => $now,
        ]);
        return true;
    }

    // Keep the original start if the same hold is being re-entered, so
    // re-saving the form does not silently reset the "held for N days"
    // clock and make a fortnight-old blockage look like this morning's.
    $sameHold = ($row['blocked_reason'] ?? null) === $reason;
    $since = $sameHold && !empty($row['blocked_since'])
        ? (string) $row['blocked_since']
        : $now;

    $data = ['blocked_reason' => $reason, 'blocked_since' => $since];
    if ($hasSource) $data['blocked_source'] = 'registrar';
    $db->update('document_requests', $data, 'id = ?', [$requestId]);

    $db->insert('document_request_events', [
        'request_id' => $requestId,
        'status'     => (string) $row['document_status'],
        'note'       => $sameHold
            ? 'Waiting on: ' . $reason . ' (unchanged)'
            : 'Waiting on: ' . $reason . ' — set by the registrar',
        'created_by' => $userId,
        'created_at' => $now,
    ]);
    return true;
}


