<?php
// Map everything that would be affected by clearing document requests.
// Read-only. Run before any destructive change:
//   php tests/doc_request_impact.php
//
// Answers: which tables hold request data, which reference the requests
// (so a delete would violate a foreign key rather than cascade silently),
// and which hold rows a person would recognise as real work.
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

echo "=== tables referencing document_requests (FK) ===\n";
$fks = $db->fetchAll(
    "SELECT TABLE_NAME t, COLUMN_NAME c, CONSTRAINT_NAME k
       FROM information_schema.KEY_COLUMN_USAGE
      WHERE REFERENCED_TABLE_NAME = 'document_requests'
        AND TABLE_SCHEMA = DATABASE()"
);
if (!$fks) {
    echo "  (none)\n";
} else {
    foreach ($fks as $r) {
        echo '  ' . $r['t'] . '.' . $r['c'] . "  [" . $r['k'] . "]\n";
    }
}

echo "\n=== document tables and their row counts ===\n";
$tables = $db->fetchAll(
    "SELECT TABLE_NAME t FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
        AND TABLE_NAME LIKE '%document%'"
);
foreach ($tables as $row) {
    $t = $row['t'];
    $n = (int) $db->fetchOne("SELECT COUNT(*) c FROM `$t`")['c'];
    echo '  ' . str_pad($t, 32) . $n . " rows\n";
}

echo "\n=== other tables holding document-related rows ===\n";
foreach (['finance', 'queue_tickets', 'activity_logs'] as $t) {
    try {
        $n = (int) $db->fetchOne("SELECT COUNT(*) c FROM `$t`")['c'];
        echo '  ' . str_pad($t, 32) . $n . " rows\n";
    } catch (Throwable $e) {
        echo '  ' . str_pad($t, 32) . "absent\n";
    }
}

echo "\n=== the requests that would be removed ===\n";
foreach ($db->fetchAll(
    "SELECT id, request_id, document_status, student_id, request_date
       FROM document_requests ORDER BY id"
) as $r) {
    printf("  #%-3d %-18s %-12s student=%-5s %s\n",
        $r['id'], $r['request_id'], $r['document_status'],
        $r['student_id'] ?? '-', $r['request_date'] ?? '');
}