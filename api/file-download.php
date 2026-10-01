<?php
// ============================================================
//  API/FILE-DOWNLOAD.PHP
//  Authenticated, authorised download for files under uploads/.
//
//  Why this exists (F1)
//  --------------------
//  Student files live in uploads/student_files/<student_id>/ INSIDE the
//  web root and Apache serves that directory directly. The per-upload
//  .htaccess blocks SCRIPT EXECUTION, but nothing stopped anyone from
//  READING the file. Names are <student_id>_<unixtime>_<original>, and
//  both the student id and the timestamp are guessable, so a student's PSA
//  birth certificate or health record could be fetched by anyone who
//  guessed a filename — no login required.
//
//  OWASP's File Upload guidance is explicit that files needing read
//  access must be served through an authorising script, not placed where
//  the web server will hand them out to anyone.
//
//  This endpoint authorises, then streams:
//    · requires a session (any authenticated role)
//    · admin / registrar / staff → any file
//    · student                   → only files on their own record
//    · nurse / teacher           → refused (file storage is not theirs)
//    · forces Content-Disposition: attachment with a safe filename
//    · sends nosniff + a neutral Content-Type, so a stored .txt or .svg
//      can never execute in the app's origin (stored XSS)
//
//  Usage: GET api/file-download.php?id=<documents.id>
//
//  Note: ?path= is deliberately NOT supported. Resolving straight from a
//  caller-supplied path is what made the direct-directory exposure
//  dangerous; requiring a row id means ownership can always be checked
//  against the database before a byte is read.
// ============================================================

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/stored_file.php';

function jsonFail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// ── 1. Authentication ─────────────────────────────────────────
if (!isLoggedIn()) {
    jsonFail(401, 'Unauthorized.');
}

$role    = (string) getCurrentUserRole();
$isStaff = in_array($role, ['admin', 'registrar', 'staff'], true);

// ── 2. Resolve the requested row ──────────────────────────────
// Only a documents.id is accepted, so ownership can always be checked
// against the database before any byte is read.
$docId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($docId <= 0) {
    jsonFail(400, 'Nothing requested.');
}

$db  = Database::getInstance();
$doc = $db->fetchOne(
    "SELECT d.id, d.student_id, d.filename, d.file_path
     FROM documents d
     WHERE d.id = ?",
    [$docId]
);
if (!$doc) {
    jsonFail(404, 'File not found.');
}

$stored = (string) ($doc['file_path'] ?? '');
if ($stored === '') {
    jsonFail(404, 'That file is not available on this server.');
}

// ── 3. Resolve to a real path, confined to the app root ───────
$appRoot = realpath(dirname(__DIR__));
$abs     = storedFileDiskPath($stored, $appRoot);

if ($abs === null || !is_file($abs) || !is_readable($abs)) {
    jsonFail(404, 'That file is not available on this server.');
}

// Final boundary check immediately before the read. readfile() is the
// irreversible operation, so the guard sits right next to it.
$realAbs  = realpath($abs);
$realRoot = realpath($appRoot);
if ($realAbs === false || $realRoot === false || strpos($realAbs, $realRoot) !== 0) {
    error_log('[file-download] refused path outside app root: ' . $stored);
    jsonFail(403, 'Forbidden.');
}

// ── 4. Authorisation ──────────────────────────────────────────
if (!$isStaff) {
    if ($role !== 'student') {
        // nurse, teacher, or anything else: file storage is not theirs.
        jsonFail(403, 'Forbidden.');
    }
    // A student may only fetch their own documents. The id comes from the
    // session, never from the request (CWE-639).
    $own   = getCurrentStudentId();
    $owner = (int) ($doc['student_id'] ?? 0);
    if ($owner === 0 || $own === null || (int) $own !== $owner) {
        error_log('[file-download] denied student uid=' . (int) ($_SESSION['user_id'] ?? 0)
            . ' doc=' . $docId . ' owner=' . $owner);
        jsonFail(403, 'Forbidden.');
    }
}

// ── 5. Stream it safely ───────────────────────────────────────
// The download name is attacker-influenced (it came from the upload), so
// strip anything that could break out of the header or the filesystem.
$downloadName = basename((string) ($doc['filename'] ?? basename($realAbs)));
$downloadName = preg_replace('/[^\w.\- ]+/u', '_', $downloadName) ?: 'download';
$downloadName = trim($downloadName, '. ');
if ($downloadName === '') {
    $downloadName = 'download';
}
if (strlen($downloadName) > 120) {
    $downloadName = substr($downloadName, 0, 120);
}

// Neutral type + nosniff + attachment. Never serve a stored file inline:
// a .txt or .svg returned as its own MIME type in this origin is stored
// XSS, which is why Content-Type is not taken from the file.
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store, max-age=0');
header('Content-Length: ' . (string) filesize($realAbs));

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

$fp = fopen($realAbs, 'rb');
if ($fp === false) {
    error_log('[file-download] fopen failed: ' . $realAbs);
    http_response_code(500);
    exit;
}
while (!feof($fp)) {
    $chunk = fread($fp, 8192);
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    flush();
}
fclose($fp);
exit;