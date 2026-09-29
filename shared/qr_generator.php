<?php
// ============================================================
//  SHARED/QR_GENERATOR.PHP
//  Shared QR code generation for student IDs.
//  Used by api/rfid.php and api/student-ids.php.
//  QR payload = verification URL (not JSON) for phone scanning.
// ============================================================

/**
 * Resolve a stored student_ids.qr_code_path into a URL the current page can load.
 *
 * generateStudentQrFile() returns a path relative to the api/ folder
 * ("../uploads/ids/x.svg"), but that same string is consumed from pages one level
 * up. It happens to resolve correctly only while the app sits at a fixed depth;
 * on a deployment at a different depth every QR thumbnail 404s. Rebuild the URL
 * from the filename plus the caller's $APP_ROOT so it works wherever the app is
 * mounted. Absolute URLs and data:/blob: URIs are passed through untouched, and
 * traversal segments cannot escape uploads/ids/.
 *
 * EXISTENCE-AWARE, and that is the whole point of it.
 *
 * uploads/ is gitignored: QR files are runtime artifacts, generated on the host
 * that issues the card. So a database whose rows were created elsewhere - a
 * migrated server, a staging copy promoted to production - carries filenames
 * that do not exist here, and every one of them is a 404 in the browser and a
 * broken image where the QR should be.
 *
 * Returning '' for a file that is not there lets the caller take the branch it
 * already has for "no QR on file": render the thumbnail from the student id with
 * the bundled QR library instead. The QR is correct, nothing 404s, and no file
 * is written during a page render. What it deliberately does NOT do is quietly
 * regenerate the SVG and update the row - that is a write inside a GET, done
 * once per card per page load, to work around a deployment step that belongs in
 * a migration rather than in a page render.
 *
 * @param string|null $stored  Raw qr_code_path value
 * @param string      $appRoot Prefix from the including page, e.g. '../'
 * @return string  A loadable URL, or '' when there is nothing loadable to point at.
 */
function resolveStudentQrUrl(?string $stored, string $appRoot = './'): string {
    $stored = trim((string) $stored);
    if ($stored === '') return '';
    if (preg_match('#^(https?:)?//|^data:|^blob:#i', $stored)) return $stored;
    $file = basename(str_replace('\\', '/', $stored));
    if ($file === '' || $file === '.' || $file === '..') return '';

    // Only ever looked up under uploads/ids/, whatever the stored string claimed.
    $abs = __DIR__ . '/../uploads/ids/' . $file;
    if (!is_file($abs)) return '';

    return rtrim($appRoot, '/') . '/uploads/ids/' . rawurlencode($file);
}

/**
 * Generate a QR code SVG pointing to the student verification page.
 *
 * @param int $studentId The student's DB id (primary key)
 * @return string|null   Web-relative path (e.g. '../uploads/ids/xyz.svg') or null on failure
 */
function generateStudentQrFile(int $studentId): ?string {
    $dir = __DIR__ . '/../uploads/ids/';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $filename = 'id_' . $studentId . '_' . time() . '.svg';
    $path = $dir . $filename;

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('QR generation skipped: vendor/autoload.php missing (run composer install).');
        return null;
    }

    try {
        require_once $autoload;
        $opts = new \chillerlan\QRCode\QROptions([
            'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class,
            'outputBase64'    => false,
            'eccLevel'        => 0, // ECC_M
            'scale'           => 6,
        ]);
        $qrcode = new \chillerlan\QRCode\QRCode($opts);
        $verifyUrl = 'https://registrar.bcpsms2.com/verify-student.php?student_id=' . intval($studentId);
        $svg = $qrcode->render($verifyUrl);
        if (file_put_contents($path, $svg) === false) {
            error_log('QR generation failed: could not write ' . $path);
            return null;
        }
        return '../uploads/ids/' . $filename;
    } catch (\Throwable $e) {
        error_log('QR generation failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Auto-generate next ID number: YYYY-XXXX format.
 *
 * @param Database $db
 * @return string  e.g. "2026-0001"
 */
function generateNextIdNumber(Database $db): string {
    $year = date('Y');
    $last = $db->fetchColumn(
        "SELECT id_number FROM student_ids WHERE id_number LIKE ? ORDER BY id DESC LIMIT 1",
        [$year . '%']
    );
    $num = $last ? intval(substr($last, -4)) + 1 : 1;
    return $year . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);
}
