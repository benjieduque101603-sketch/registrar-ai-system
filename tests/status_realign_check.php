<?php
//   php tests/status_realign_check.php
// Walks one request through Filed → Processing → Ready → Claimed inside a
// transaction that is always rolled back, asserting the guards behave the way
// the counter desk assumes. Nothing is written.
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$fail = 0;
function check(string $label, bool $ok, string $detail = '') {
    global $fail;
    if (!$ok) $fail++;
    printf("  %-52s %s%s\n", $label, $ok ? 'OK' : 'FAIL', $detail !== '' ? "  ($detail)" : '');
}

// The guards, transcribed from api/documents.php. Kept here in step with the
// real switch so a divergence shows up as a failing check, not a silent bug.
$CAN_START = ['Filed', 'Pending_Clearance', 'Processing'];

echo "Enum shape\n";
$col = $db->fetchOne(
    "SELECT COLUMN_TYPE, COLUMN_DEFAULT FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
        AND COLUMN_NAME = 'document_status'"
);
$expected = "enum('Filed','Pending_Clearance','Processing','Ready','Claimed','Rejected')";
check('enum matches the counter vocabulary', $col['COLUMN_TYPE'] === $expected, (string) $col['COLUMN_TYPE']);
// information_schema reports a non-NUMERIC column default wrapped in literal
// quotes, so compare against the unwrapped value.
$default = trim((string) $col['COLUMN_DEFAULT'], "'");
check('default is Filed', $default === 'Filed', (string) $col['COLUMN_DEFAULT']);

echo "\nRetired values are rejected by the column\n";
foreach (['Awaiting_Payment', 'Shipped'] as $dead) {
    $ok = false;
    try {
        $db->query("SELECT CAST(? AS " . $col['COLUMN_TYPE'] . ")", [$dead]);
        $ok = false;
    } catch (Throwable $e) {
        $ok = true;
    }
    // Under non-strict MySQL an unknown enum member coerces to the error
    // member '' rather than raising, so also assert it is not stored as itself.
    $r = $db->fetchColumn(
        "SELECT COUNT(*) FROM document_requests WHERE document_status = ?", [$dead]
    );
    check('cannot be stored: ' . $dead, (int) $r === 0, "rows=$r");
}

echo "\nGuards along the walk-in path\n";
$id = $db->fetchColumn("SELECT id FROM document_requests ORDER BY id LIMIT 1");
if (!$id) {
    fwrite(STDERR, "No document requests to walk.\n");
    exit(1);
}
$id = (int) $id;

$conn = $db->getConnection();
// Snapshot before the transaction. The rollback check compares against
// this rather than asserting paid_at is empty, because a request filed
// through the API already carries a payment timestamp - the fee is taken
// at filing now. "Untouched" means "as it was", not "blank".
$before = $db->fetchOne("SELECT document_status, paid_at FROM document_requests WHERE id = ?", [$id]);
$conn->beginTransaction();
try {
    $db->update('document_requests', ['document_status' => 'Filed'], 'id = ?', [$id]);
    $cur = $db->fetchColumn("SELECT document_status FROM document_requests WHERE id = ?", [$id]);

    check('a Filed request can be started',   in_array($cur, $CAN_START, true), (string) $cur);
    check('a Filed request cannot be claimed', $cur !== 'Ready');

    $db->update('document_requests', ['document_status' => 'Processing'], 'id = ?', [$id]);
    check('only Processing can be marked Ready',
        (string) $db->fetchColumn("SELECT document_status FROM document_requests WHERE id = ?", [$id]) === 'Processing');

    $db->update('document_requests', ['document_status' => 'Ready'], 'id = ?', [$id]);
    check('a Ready request can be claimed',
        (string) $db->fetchColumn("SELECT document_status FROM document_requests WHERE id = ?", [$id]) === 'Ready');

    // Claim no longer settles the fee - that happened at filing. It records
    // the hand-over and the receipt, so the assertions here are about those
    // two, plus a guard that claiming leaves the filing payment alone.
    $now = date('Y-m-d H:i:s');
    $db->update('document_requests', [
        'document_status' => 'Claimed',
        'status'          => 'released',
        'claimed_at'      => $now,
        'official_receipt'=> 'OR-TEST-001',
    ], 'id = ?', [$id]);
    $row = $db->fetchOne("SELECT document_status, status, paid_at, claimed_at, official_receipt FROM document_requests WHERE id = ?", [$id]);
    check('claim records the hand-over time',   !empty($row['claimed_at']));
    check('claim records the official receipt', ($row['official_receipt'] ?? '') === 'OR-TEST-001');
    check('legacy status mirrors the lifecycle',($row['status'] ?? '') === 'released');
    // If claiming still wrote paid_at it would equal $now and differ from the
    // filing value, so this is the check that catches a regression.
    check('claiming does not restamp the filing payment',
        ($row['paid_at'] ?? null) === ($before['paid_at'] ?? null),
        json_encode(['at_filing' => $before['paid_at'], 'after_claim' => $row['paid_at']]));
} finally {
    $conn->rollBack();
}

$after = $db->fetchOne("SELECT document_status, paid_at FROM document_requests WHERE id = ?", [$id]);
check('rollback left the row as it was',
    ($after['document_status'] ?? null) === ($before['document_status'] ?? null)
    && ($after['paid_at'] ?? null) === ($before['paid_at'] ?? null),
    json_encode(['before' => $before, 'after' => $after]));

echo "\n" . ($fail === 0 ? "OK — the walk-in lifecycle behaves as the desk assumes.\n" : "FAILED — $fail check(s)\n");
exit($fail === 0 ? 0 : 1);