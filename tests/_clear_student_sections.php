<?php
require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();
$n = $db->query("UPDATE students SET section = NULL WHERE section IS NOT NULL AND TRIM(section) <> ''")->rowCount();
echo "cleared {$n}\n";
