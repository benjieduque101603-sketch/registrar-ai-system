<?php
// Pickup-notice end-to-end check.
//
//   php tests/document_pickup_email.php
//
// WHY THIS IS NOT A UNIT TEST
// ---------------------------
// The interesting failures in sendDocumentPickupEmail() are all about
// WHICH addresses it picks and what it does when mail is unavailable,
// and none of those are visible without the real tables: a placeholder
// address on the student row, a bounced address, a guardian who is also
// a contact recipient. A stubbed DB would let all of them pass.
//
// Mail is NOT configured in this environment (EMAIL_CONFIGURED=false),
// so every send is expected to report failure. That is the useful case:
// it proves the recipient SELECTION is right, and it proves the
// not-configured path logs and returns cleanly instead of throwing.

ini_set('display_errors','stderr');
ini_set('log_errors','0');

// ── Make the mail transport fail fast ──────────────────────
//
// This box HAS real SMTP credentials (shared/email_secret.local), and
// EMAIL_CONFIGURED is computed as true from them. Left alone, every
// sendDocumentPickupEmail() call below opens a real TLS connection to
// smtp.gmail.com — 30s per attempt, dependent on the network, and
// pointed at example.test addresses.
//
// env() returns an EMPTY value straight to emailSecretFromLocal(), so
// putenv('SMTP_HOST=') cannot switch mail off — it just falls through
// to the credentials file. What DOES work is putting a value in: env()
// prefers a non-empty getenv() result over the file. 127.0.0.1:1 is
// refused instantly (connection refused, no route), which is exactly
// the transport-failure path worth testing — a registrar whose mail is
// misconfigured must still end up with a Ready document, a logged
// failure, and a message that says so, rather than a 30-second hang.
//
// Recipients are all @example.test / @placeholder.local, RFC 2606
// reserved names that cannot resolve, so nothing is deliverable even
// if a transport did accept the message.
putenv('SMTP_HOST=127.0.0.1');
putenv('SMTP_PORT=1');
putenv('BREVO_API_KEY=');
putenv('GMAIL_API_CLIENT_ID=');
putenv('GMAIL_API_CLIENT_SECRET=');
putenv('GMAIL_REFRESH_TOKEN=');

require __DIR__ . '/../shared/config.php';
require __DIR__ . '/../shared/database.php';
require __DIR__ . '/../shared/functions.php';
require __DIR__ . '/../shared/mail_client.php';

$db = Database::getInstance();

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  --  $detail" : '') . "\n"; }
}

try { $now = date('Y-m-d H:i:s');
$sid   = null;
$userId = null;

try {
    // ── Fixture ────────────────────────────────────────────────────
    // A student whose OWN address is a placeholder — the exact case the
    // student-email rules exist for — plus two guardians, one of whom is
    // the same human twice (case-differing address).
    $db->insert('students', [
        'first_name'           => 'Pickup',
        'last_name'            => 'Testerson',
        'email'                => 'pickup.testerson@placeholder.local',
        'email_is_placeholder' => 1,
        'status'               => 'Active',
        'created_at'           => $now,
    ]);
    $sid = (int) $db->lastInsertId();

    $db->insert('guardians', [
        'student_id' => $sid, 'full_name' => 'Ana Guardian',
        'relationship' => 'mother', 'contact_number' => '09171234567',
        'email' => 'ana.guardian@example.test',
        'is_primary' => 1, 'is_emergency' => 0,
    ]);
    $db->insert('guardians', [
        'student_id' => $sid, 'full_name' => 'Ben Guardian',
        'relationship' => 'father', 'contact_number' => '09181234567',
        // Same human as Ana, differing case. Must NOT be sent twice.
        'email' => 'ANA.Guardian@Example.test',
        'is_primary' => 0, 'is_emergency' => 0,
    ]);
    $db->insert('guardians', [
        'student_id' => $sid, 'full_name' => 'No Address Guardian',
        'relationship' => 'guardian', 'contact_number' => '09191234567',
        'is_primary' => 0, 'is_emergency' => 0,
    ]);

    // A previous run that was killed (timeout, Ctrl-C) leaves the catalog
// row behind, and uq_sku then refuses the insert on every subsequent
// run. Clear the SKU first so the test is re-runnable without a manual
// cleanup step.
$db->query('DELETE FROM document_catalog WHERE sku = ?', ['DOC-TEST-PICKUP']);
    $db->insert('document_catalog', [
        'sku' => 'DOC-TEST-PICKUP', 'name' => 'Test Certificate (Pickup)',
        'base_fee' => 100.00, 'is_active' => 1, 'created_at' => $now,
    ]);
    $catId = (int) $db->lastInsertId();

    $db->insert('users', [
        'username'  => 'pickup_staff_' . substr(md5((string) $sid), 0, 8),
        'full_name' => 'Pickup Staff', 'role' => 'registrar', 'created_at' => $now,
    ]);
    $userId = (int) $db->lastInsertId();

    $db->insert('document_requests', [
        'student_id' => $sid, 'catalog_id' => $catId,
        'document_type' => 'certificate', 'request_id' => 'PU-' . $sid,
        'document_status' => 'Ready', 'status' => 'approved',
        'fee_amount' => 100.00, 'quantity' => 1,
        'release_date' => '2026-03-04 00:00:00',
        'request_date' => $now, 'ready_at' => $now,
    ]);
    $reqId = (int) $db->lastInsertId();
    echo "\n=== 1. Recipient selection ===\n";
    $res = sendDocumentPickupEmail($reqId, $userId);
    $recips = $res['recipients'];

    check('placeholder student address is not used',
        !in_array('pickup.testerson@placeholder.local', $recips, true),
        implode(', ', $recips));
    check('guardian with an address IS used',
        in_array('ana.guardian@example.test', $recips, true), implode(', ', $recips));
    check('same address in different case is sent once',
        count(array_filter($recips, fn($e) => mb_strtolower($e) === 'ana.guardian@example.test')) === 1,
        implode(', ', $recips));
    check('guardian with no address is skipped', count($recips) === 1, implode(', ', $recips));

    echo "\n=== 2. A failing transport is reported, not thrown ===\n";
    // The transport above (127.0.0.1:1) always refuses, so EVERY send
    // here fails. What must still hold: no exception escapes, and the
    // caller is told it failed rather than being handed a cheerful
    // 'sent' it will report to a parent that never got an email.
    check('call returned without throwing', is_array($res));
    check('failure is counted, not reported as sent',
        $res['sent'] === 0 && $res['failed'] === 1,
        'sent=' . $res['sent'] . ' failed=' . $res['failed']);
    check('the underlying transport error is surfaced',
        $res['errors'] !== [] && stripos(implode(' ', $res['errors']), '127.0.0.1') !== false,
        implode(' | ', $res['errors']));
    check('message reports the failure', stripos($res['message'], 'failed') !== false,
        $res['message']);

    echo "\n=== 3. Every attempt is logged ===\n";
    $logs = $db->fetchAll(
        "SELECT * FROM communication_log
          WHERE student_id = ? AND message_type = 'pickup' AND ref = ?",
        [$sid, 'PU-' . $sid]
    );
    check('a communication_log row exists per recipient', count($logs) === 1, 'rows=' . count($logs));
    check('log records the request reference',
        $logs !== [] && $logs[0]['ref'] === 'PU-' . $sid);
    check('log records the sending staff member',
        $logs !== [] && (int) $logs[0]['sent_by'] === $userId);

    echo "\n=== 4. A student address that IS real is used ===\n";
    $db->update('students', [
        'email' => 'pickup.real@example.test', 'email_is_placeholder' => 0,
    ], 'id = ?', [$sid]);
    $res2 = sendDocumentPickupEmail($reqId, $userId);
    check('real student address joins the send',
        in_array('pickup.real@example.test', $res2['recipients'], true),
        implode(', ', $res2['recipients']));
    check('guardian still included alongside it', count($res2['recipients']) === 2,
        implode(', ', $res2['recipients']));

    echo "\n=== 5. A bounced address is skipped ===\n";
    $db->update('students', [
        'email_bounced_at' => $now, 'email_bounce_reason' => '550 mailbox unavailable',
    ], 'id = ?', [$sid]);
    $res3 = sendDocumentPickupEmail($reqId, $userId);
    check('bounced student address not used',
        !in_array('pickup.real@example.test', $res3['recipients'], true),
        implode(', ', $res3['recipients']));
    check('guardian still reached despite the bounce', count($res3['recipients']) === 1,
        implode(', ', $res3['recipients']));

    echo "\n=== 6. Nobody to email is a clean no-op ===\n";
    $db->update('guardians', ['email' => null], 'student_id = ?', [$sid]);
    $res4 = sendDocumentPickupEmail($reqId, $userId);
    check('no recipients and no throw', $res4['recipients'] === [] && $res4['sent'] === 0);
    check('message states the reason',
        stripos($res4['message'], 'bounced') !== false, $res4['message']);

    echo "\n=== 7. A missing request is handled ===\n";
    $res5 = sendDocumentPickupEmail(999999999, $userId);
    check('unknown request returns a message, no fatal',
        $res5['sent'] === 0 && stripos($res5['message'], 'not found') !== false,
        $res5['message']);

    echo "\n" . str_repeat('-', 52);
    echo "\n  $pass passed, $fail failed\n";
    echo str_repeat('-', 52) . "\n";
} finally {
    // ── Cleanup ────────────────────────────────────────────────────
    // communication_log FKs to the student, so it has to go first or
    // the DELETE is refused and the fixture is left behind for the
    // next run to trip over.
    if ($sid) {
        foreach ([
            "DELETE FROM communication_log       WHERE student_id = ?",
            "DELETE FROM document_request_events WHERE request_id IN (SELECT id FROM document_requests WHERE student_id = ?)",
            "DELETE FROM document_requests       WHERE student_id = ?",
            "DELETE FROM guardians               WHERE student_id = ?",
        ] as $sql) {
            try { $db->query($sql, [$sid]); } catch (Throwable $e) { /* best effort */ }
        }
        try { $db->query('DELETE FROM document_catalog WHERE sku = ?', ['DOC-TEST-PICKUP']); } catch (Throwable $e) {}
        try { $db->query('DELETE FROM students WHERE id = ?', [$sid]); } catch (Throwable $e) {}
    }
    if ($userId) {
        try { $db->query('DELETE FROM audit_logs WHERE user_id = ?', [$userId]); } catch (Throwable $e) {}
        try { $db->query('DELETE FROM users WHERE id = ?', [$userId]); } catch (Throwable $e) {}
    }
    echo "\n  test data cleaned up\n";
}

exit($fail === 0 ? 0 : 1);
} catch (Throwable $e) { fwrite(STDERR, 'UNCAUGHT: '.$e->getMessage().'
'.$e->getTraceAsString().'
'); exit(3); }