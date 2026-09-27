<?php
// Create one document request through the same API the desk uses, so the
// empty desk can be verified end to end after tests/clear_doc_requests.php.
//
//   php tests/seed_doc_request.php [student_id] [catalog_id]
//
// Goes over HTTP with a real session and the real CSRF token, rather than
// inserting a row directly. A direct INSERT would prove the page renders,
// but not that the intake path still works — and the intake path is what
// needs checking after a wipe.
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

// Reuse the proven session forger rather than minting one here. A second
// implementation of the same trick is a second chance to get it subtly
// wrong — the first version of this file opened its own session and
// wrote an EMPTY file, because the id calls were rejected once output
// had started. tests/desk_session.php already does this correctly and
// asserts the id it asked for is the id in force.
$minted = (string) shell_exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/desk_session.php') . ' 2>&1'
);
if (!preg_match('/COOKIE=(\S+)/', $minted, $cm)) {
    fwrite(STDERR, "Could not mint a registrar session.\n");
    exit(1);
}
$cookie = $cm[1];
$sid = substr($cookie, strpos($cookie, '=') + 1);
$id = $sid;

$base = 'http://localhost/registrar-ai-system';
$jar  = tempnam(sys_get_temp_dir(), 'seedck');
register_shutdown_function(static function () use ($id, $jar) {
    @unlink($jar);
    @unlink(session_save_path() . DIRECTORY_SEPARATOR . 'sess_' . $id);
});

function http_get(string $url, string $cookie, string $jar): string
{
    return (string) shell_exec(
        'curl.exe -s -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($cookie)
        . ' ' . escapeshellarg($url) . ' 2>&1'
    );
}

$page = http_get($base . '/registrar/documents.php', $cookie, $jar);

if (getenv('SEED_DEBUG')) {
    fwrite(STDERR, "session file:\n  "
        . @file_get_contents(session_save_path() . DIRECTORY_SEPARATOR . 'sess_' . $id) . "\n");
    fwrite(STDERR, 'desk page bytes: ' . strlen($page) . "\n");
    fwrite(STDERR, 'has login form: '
        . var_export(str_contains($page, 'showForm'), true) . "\n");
}

// The header renders the token with SINGLE quotes and a self-closing
// slash. Matching double quotes only silently found nothing.
if (!preg_match('/<meta\s+name=[\'"]csrf-token[\'"]\s+content=[\'"]([^\'"]+)[\'"]/', $page, $m)) {
    fwrite(STDERR, "No CSRF token in the desk page; cannot post safely.\n");
    exit(1);
}
$token = $m[1];

$studentId = (int) ($argv[1] ?? 1);
$catalogId = (int) ($argv[2] ?? 1);

$payload = json_encode([
    'student_id'       => $studentId,
    'catalog_id'       => $catalogId,
    'quantity'         => 1,
    'purpose'          => 'Seeded after clearing the desk.',
    // The endpoint validates these against a fixed vocabulary
    // (Express|Regular, Pickup|Digital) and rejects anything else. Guessed
    // values here fail as "Invalid request type", which reads like a
    // server fault rather than a bad argument.
    'request_type'     => 'Regular',
    'fulfillment_type' => 'Pickup',
]);

// Write the body to a file and post with -d @file. Passing JSON inline
// through the shell mangled it — the endpoint then saw no student_id and
// reported "No student account is linked to this session", which reads
// like an auth problem but is really a broken request body.
$bodyFile = tempnam(sys_get_temp_dir(), 'seedbody');
file_put_contents($bodyFile, $payload);
register_shutdown_function(static function () use ($bodyFile) {
    @unlink($bodyFile);
});

$out = (string) shell_exec(
    'curl.exe -s -X POST -b ' . escapeshellarg($cookie)
    . ' -H "Content-Type: application/json"'
    . ' -H "X-CSRF-Token: ' . escapeshellarg($token) . '"'
    . ' -d @' . escapeshellarg($bodyFile)
    . ' ' . escapeshellarg($base . '/api/student-documents.php') . ' 2>&1'
);

echo 'response: ' . trim($out) . "\n";
$d = json_decode($out, true);
if (is_array($d) && !empty($d['success'])) {
    echo "OK — request created.\n";
    exit(0);
}
fwrite(STDERR, "Intake did not succeed.\n");
exit(1);
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

$before = (int) $db->fetchOne('SELECT COUNT(*) c FROM document_requests')['c'];
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

$after = (int) $db->fetchOne('SELECT COUNT(*) c FROM document_requests')['c'];
$evAfter = (int) $db->fetchOne('SELECT COUNT(*) c FROM document_request_events')['c'];
echo "\n  now: document_requests=$after, document_request_events=$evAfter\n";

$cat = (int) $db->fetchOne('SELECT COUNT(*) c FROM document_catalog')['c'];
echo "  document_catalog intact: $cat SKUs\n";
echo $after === 0 && $evAfter === 0 ? "OK — the desk is empty.\n" : "WARNING — rows remain.\n";