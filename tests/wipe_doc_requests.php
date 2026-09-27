<?php
// Clear document-request data so the desk can be exercised from empty.
//
//   php tests/clear_doc_requests.php            (dry run — changes nothing)
//   php tests/clear_doc_requests.php --execute  (actually deletes)
//
// Scope, deliberately narrow:
//   document_request_events   child rows (FK)
//   document_requests         the requests themselves
//
// NOT touched: students, users, document_catalog (the 7 SKUs the desk
// offers), documents, finance, queue_tickets, document_ai_audit.
// Catalog in particular must survive — the desk's New Request form and
// the whole preview/render path are built from it, and emptying it would
// break testing rather than enable it.
//
// Take a backup first:
//   mysqldump -u root --single-transaction registrar_ai \
//     document_requests document_request_events > backup.sql
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

$execute = in_array('--execute', $argv, true);
$db = Database::getInstance();

$before   = (int) $db->fetchOne('SELECT COUNT(*) c FROM document_requests')['c'];
$evBefore = (int) $db->fetchOne('SELECT COUNT(*) c FROM document_request_events')['c'];

echo $execute ? "DELETING\n" : "DRY RUN — pass --execute to apply\n";
echo "  document_requests       $before rows -> 0\n";
echo "  document_request_events $evBefore rows -> 0\n";

if (!$execute) {
    echo "\nNothing was changed.\n";
    exit(0);
}
if ($before === 0 && $evBefore === 0) {
    echo "\nAlready empty.\n";
    exit(0);
}

$conn = $db->getConnection();
$conn->beginTransaction();
try {
    // Children first, so the foreign keys stay satisfied throughout and a
    // failure halfway leaves both tables untouched.
    $conn->exec('DELETE FROM document_request_events');
    $conn->exec('DELETE FROM document_requests');
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollBack();
    fwrite(STDERR, "\nRolled back: " . $e->getMessage() . "\n");
    exit(1);
}

// Reset the auto-increment so ids restart at 1. Otherwise the next request
// is DOC-2026-0008 and any test written against id 1 finds nothing.
try {
    $conn->exec('ALTER TABLE document_requests AUTO_INCREMENT = 1');
    $conn->exec('ALTER TABLE document_request_events AUTO_INCREMENT = 1');
} catch (Throwable $e) {
    echo "  (auto-increment reset skipped: " . $e->getMessage() . ")\n";
}

$after   = (int) $db->fetchOne('SELECT COUNT(*) c FROM document_requests')['c'];
$evAfter = (int) $db->fetchOne('SELECT COUNT(*) c FROM document_request_events')['c'];
echo "\n  now: document_requests=$after, document_request_events=$evAfter\n";

$cat = (int) $db->fetchOne('SELECT COUNT(*) c FROM document_catalog')['c'];
echo "  document_catalog intact: $cat SKUs\n";
echo $after === 0 && $evAfter === 0 ? "OK — the desk is empty.\n" : "WARNING — rows remain.\n";