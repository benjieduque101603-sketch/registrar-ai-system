<?php
// ============================================================
//  SHARED/DOCUMENT_PDF.PHP
//  Digital document security — builds the encrypted, signed PDF
//  for a released document request (spec §7).
//
//    * TCPDF with setProtection() — user password = the student's
//      birth date (e.g. 2005-05-15), AES-256 encryption.
//    * Registrar signature overlay — assets/registrar-signature.png
//      when the user has dropped one in; otherwise a styled text
//      signature block using the acting registrar's name.
//    * Footer QR (chillerlan/php-qrcode) linking to the public
//      verification portal + the document's SHA-256 fingerprint.
//
//  Pure function: takes request/student/catalog rows, returns the
//  raw PDF bytes + filename + fingerprint. No side effects.
// ============================================================

// Guarded: vendor/ is gitignored, so a deploy without `composer install`
// has no autoload.php, and an unguarded require_once on a missing file is
// a FATAL error (not a warning). That killed the whole mail module on
// hosts where the Brevo/Gmail API paths would otherwise have worked.
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// NOTE: TCPDF's config (loaded via composer autoload) sets
// K_TCPDF_THROW_EXCEPTION_ERROR=false, so raster Image() failures would
// hard-die(). We therefore only call Image() for a signature PNG when GD
// is actually present (see signature block below); everything else is
// drawn as vector/text and never needs GD.

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Data\QRMatrix;

/**
 * Flatten an 8-bit RGBA PNG onto a solid background and re-encode it as a
 * color-type-2 (opaque RGB) PNG, entirely in pure PHP (zlib only).
 *
 * This stack runs Apache PHP without the GD/Imagick extensions, and TCPDF's
 * raster Image() will fatal on a PNG that carries an alpha channel. TCPDF's
 * GD-free parser *does* handle an opaque RGB PNG, so we strip the alpha here
 * and hand it an embeddable file. Falls back to null when the PNG isn't a
 * simple 8-bit non-interlaced RGBA/RGB image (caller keeps its fallback).
 *
 * @param string $path path to the source PNG
 * @param array  $bg   background [R, G, B] the transparency is composited onto
 * @return string|null opaque RGB PNG binary, or null if it can't be decoded
 */
function _pdf_flatten_png_rgba(string $path, array $bg = [255, 255, 255]): ?string
{
    $d = @file_get_contents($path);
    if ($d === false || substr($d, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        return null;
    }

    // Walk the chunk list and collect IHDR / IDAT (concatenated) / PLTE.
    $pos = 8;
    $len = strlen($d);
    $ihdr = null;
    $idat = '';
    while ($pos + 8 <= $len) {
        $clen = unpack('N', substr($d, $pos, 4))[1];
        $type = substr($d, $pos + 4, 4);
        $data = substr($d, $pos + 8, $clen);
        $pos += 12 + $clen;
        if ($type === 'IHDR') {
            $ihdr = $data;
        } elseif ($type === 'IDAT') {
            $idat .= $data;
        }
    }
    if ($ihdr === null || $idat === '') {
        return null;
    }

    $u = unpack('Nw/Nh/Cbit/Cct/Ccomp/Cfilter/Cinter', $ihdr);
    if ($u['bit'] !== 8 || $u['comp'] !== 0 || $u['filter'] !== 0 || $u['inter'] !== 0) {
        return null; // only plain 8-bit non-interlaced images are supported
    }
    $w = $u['w'];
    $h = $u['h'];
    $ct = $u['ct'];
    if ($ct === 2) {
        return $d; // already opaque RGB — embed as-is
    }
    if ($ct !== 6) {
        return null; // would need palette/grayscale handling — not our logo
    }

    $raw = @gzuncompress($idat);
    if ($raw === false) {
        return null;
    }

    // Unfilter scanlines (None / Sub / Up / Average / Paeth).
    $bpp = 4; // RGBA, 8-bit
    $stride = $w * $bpp;
    if (strlen($raw) < $h * ($stride + 1)) {
        return null;
    }
    $px = str_repeat("\0", $h * $stride);
    $off = 0;
    for ($y = 0; $y < $h; $y++) {
        $filter = ord($raw[$off]);
        $off++;
        $srcLine = substr($raw, $off, $stride);
        $off += $stride;
        $prevLine = $y > 0 ? substr($px, ($y - 1) * $stride, $stride) : str_repeat("\0", $stride);
        $dstOffset = $y * $stride;
        for ($x = 0; $x < $stride; $x++) {
            $a = $x >= $bpp ? ord($srcLine[$x - $bpp]) : 0;
            $b = ord($prevLine[$x]);
            $c = $x >= $bpp ? ord($prevLine[$x - $bpp]) : 0;
            $cur = ord($srcLine[$x]);
            if ($filter === 0) {
                $v = $cur;
            } elseif ($filter === 1) {
                $v = $cur + $a;
            } elseif ($filter === 2) {
                $v = $cur + $b;
            } elseif ($filter === 3) {
                $v = $cur + (int) (($a + $b) / 2);
            } elseif ($filter === 4) {
                $p = $a + $b - $c;
                $pa = abs($p - $a);
                $pb = abs($p - $b);
                $pc = abs($p - $c);
                $pr = ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
                $v = $cur + $pr;
            } else {
                return null;
            }
            $px[$dstOffset + $x] = chr($v & 0xFF);
        }
    }

    // Composite onto the background colour, then rebuild an opaque RGB PNG.
    $rgb = str_repeat("\0", $w * $h * 3);
    for ($i = 0, $o = 0; $i < $w * $h; $i++, $o += 3) {
        $p = $i * 4;
        $alpha = ord($px[$p + 3]) / 255;
        $rgb[$o]     = chr((int) round(ord($px[$p]) * $alpha + $bg[0] * (1 - $alpha)));
        $rgb[$o + 1] = chr((int) round(ord($px[$p + 1]) * $alpha + $bg[1] * (1 - $alpha)));
        $rgb[$o + 2] = chr((int) round(ord($px[$p + 2]) * $alpha + $bg[2] * (1 - $alpha)));
    }

    $scan = '';
    for ($y = 0; $y < $h; $y++) {
        $scan .= "\x00" . substr($rgb, $y * $w * 3, $w * 3);
    }
    $chunk = function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNC5', $w, $h, 8, 2, 0, 0, 0))
        . $chunk('IDAT', gzcompress($scan, 6))
        . $chunk('IEND', '');
}

/**
 * Build the encrypted PDF for a document request.
 *
 * @param array  $request  document_requests row
 * @param array  $student  students row (must include birth_date)
 * @param array|null $catalog document_catalog row
 * @param string $signatory Registrar display name for the signature block
 *
 * @return array{bytes: string, filename: string, fingerprint: string, qr_hash: string}
 */
function buildDocumentPdf(array $request, array $student, ?array $catalog, string $signatory = ''): array
{
    $qrHash = trim((string) ($request['qr_hash'] ?? ''));
    if ($qrHash === '') {
        $qrHash = hash('sha256', ($request['request_id'] ?? 'DOC') . '|' . random_bytes(16));
    }

    $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('BCP Registrar System');
    $pdf->SetAuthor('Bestlink College of the Philippines — Office of the Registrar');
    $pdf->SetTitle('Document Request ' . ($request['request_id'] ?? ''));
    $pdf->SetSubject((string) ($catalog['name'] ?? 'Official Document'));
    $pdf->SetKeywords('BCP, Registrar, Official Document, Verified');

    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);

    // ── Encryption: user password = student birth date, AES-256 (mode 3) ──
    $birthPass = (string) ($student['birth_date'] ?? '');
    if ($birthPass !== '' && $birthPass !== '0000-00-00') {
        $pdf->setProtection(['print', 'copy'], $birthPass, null, 3);
    }

    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 26);
    $pdf->SetMargins(18, 16, 18);

    // ── Letterhead ─────────────────────────────────────────────
    // Embed the actual college logo (assets/images/BCP_LOGO.png). The server
    // has no GD extension, and TCPDF fatals on an alpha-channel PNG, so the
    // RGBA logo is flattened onto the paper colour in pure PHP first and then
    // embedded as an opaque RGB PNG. Falls back to the old maroon monogram if
    // the file is ever unreadable.
    $logoPng = _pdf_flatten_png_rgba(__DIR__ . '/../assets/images/BCP_LOGO.png', [255, 255, 255]);
    if ($logoPng !== null) {
        $pdf->Image('@' . $logoPng, 18, 12, 18, 0, 'PNG');
    } else {
        $pdf->SetFillColor(120, 40, 30);
        $pdf->Rect(18, 12, 18, 18, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY(18, 15);
        $pdf->Cell(18, 12, 'BCP', 0, 0, 'C');
    }
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(120, 40, 30);
    $pdf->SetXY(40, 14);
    $pdf->Cell(0, 5, 'REPUBLIC OF THE PHILIPPINES', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 15);
    $pdf->SetTextColor(20, 20, 40);
    $pdf->SetXY(40, 19);
    $pdf->Cell(0, 7, 'BESTLINK COLLEGE OF THE PHILIPPINES', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(90, 90, 100);
    $pdf->SetXY(40, 26);
    $pdf->Cell(0, 4, 'Office of the Registrar  |  Quezon City, Philippines  |  registrar@bestlink.edu.ph', 0, 0, 'L');

    $pdf->SetDrawColor(120, 40, 30);
    $pdf->SetLineWidth(0.6);
    $pdf->Line(18, 34, 192, 34);
    $pdf->SetLineWidth(0.2);
    $pdf->SetDrawColor(200, 200, 205);
    $pdf->Line(18, 35.2, 192, 35.2);

    // ── Document title block ────────────────────────────────────
    $pdf->Ln(14);
    $docName = (string) ($catalog['name'] ?? ucwords(str_replace('_', ' ', (string) ($request['document_type'] ?? 'Official Document'))));
    $pdf->SetFont('times', 'B', 17);
    $pdf->SetTextColor(20, 20, 40);
    $pdf->Cell(0, 9, mb_strtoupper($docName, 'UTF-8'), 0, 0, 'C');
    $pdf->Ln(7);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(110, 110, 120);
    $pdf->Cell(0, 5, 'Request No. ' . ($request['request_id'] ?? '—') . '   ·   ' . ($request['request_type'] ?? '') . '   ·   ' . ($request['fulfillment_type'] ?? '') . ' fulfillment', 0, 0, 'C');
    $pdf->Ln(4);
    $pdf->SetTextColor(110, 110, 120);
    $pdf->Cell(0, 5, 'This official document is verifiable online — scan the QR code at the bottom of this page.', 0, 0, 'C');
    $pdf->Ln(9);

    // ── Student record strip ────────────────────────────────────
    $pdf->SetDrawColor(220, 225, 232);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(30, 41, 59);
    $name = trim(($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '') . ' ' . ($student['last_name'] ?? '') . ' ' . ($student['name_suffix'] ?? ''));
    $info = [
        'Student Name'   => $name,
        'Student No.'    => (string) ($student['student_number'] ?? '—'),
        'Course'         => (string) ($student['course'] ?? '—'),
        'Year Level'     => $student['year_level'] !== '' && $student['year_level'] !== null ? (string) $student['year_level'] : '—',
        'Section'        => (string) ($student['section'] ?? '—'),
        'School Year'    => (string) ($student['school_year'] ?? '—') . ' · ' . (string) ($student['semester'] ?? ''),
    ];
    foreach ($info as $label => $value) {
        $y = $pdf->GetY();
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetTextColor(80, 90, 110);
        $pdf->SetX(18);
        $pdf->Cell(34, 7, strtoupper($label), 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(0, 7, $value, 0, 1, 'L');
        $pdf->SetDrawColor(235, 238, 243);
        $pdf->SetLineWidth(0.15);
        $pdf->Line(18, $y + 7.4, 192, $y + 7.4);
    }
    $pdf->Ln(4);

    // ── Document body ───────────────────────────────────────────
    $pdf->SetFont('helvetica', '', 10.5);
    $pdf->SetTextColor(30, 41, 59);
    $sku = $catalog['sku'] ?? '';
    $purpose = trim((string) ($request['purpose'] ?? ''));
    $qty = (int) ($request['quantity'] ?? 1);
    $course = (string) ($student['course'] ?? '');
    $sy = (string) ($student['school_year'] ?? '');
    $sem = (string) ($student['semester'] ?? '');
    $now = date('F d, Y');

    $body = '';

    switch ($sku) {
        case 'DOC-COE':
            $body = 'THIS IS TO CERTIFY that ' . $name . ', with student number ' . ($student['student_number'] ?? '—') . ', is officially enrolled at Bestlink College of the Philippines for the Academic Year ' . $sy . ($sem !== '' ? ', ' . $sem . ' Semester' : '') . ' under the ' . $course . ' program, ' . ($student['year_level'] ?? '') . ' year level, section ' . ($student['section'] ?? '—') . '.';
            if ($purpose !== '') $body .= "\n\nThis certificate is issued upon the request of the student for the following purpose: " . $purpose . '.';
            $body .= "\n\nIssued this " . $now . ' at the Office of the Registrar, Bestlink College of the Philippines.';
            break;

        case 'DOC-GM':
            $body = 'THIS IS TO CERTIFY that ' . $name . ', with student number ' . ($student['student_number'] ?? '—') . ', has maintained good moral character during the period of attendance at Bestlink College of the Philippines. To the knowledge of this Office, the above-named student has no record of disciplinary action.';
            if ($purpose !== '') $body .= "\n\nThis certificate is issued upon the request of the student for the following purpose: " . $purpose . '.';
            $body .= "\n\nIssued this " . $now . ' at the Office of the Registrar, Bestlink College of the Philippines.';
            break;

        case 'DOC-TOR':
            $body = 'REPUBLIC OF THE PHILIPPINES' . "\n" . 'BESTLINK COLLEGE OF THE PHILIPPINES' . "\n" . 'TRANSCRIPT OF RECORDS' . "\n\n";
            $body .= 'This Transcript of Records of ' . $name . ', student number ' . ($student['student_number'] ?? '—') . ', covering the ' . $course . ' program, is issued as a true copy of the permanent academic record kept by this Office.';
            $body .= "\n\nTotal pages in this set: " . max(1, $qty) . '.';
            if ($purpose !== '') $body .= "\n\nRequested for: " . $purpose . '.';
            break;

        case 'DOC-CTC':
            $body = 'CERTIFIED TRUE COPY' . "\n\n" . 'This is to certify that the attached ' . max(1, $qty) . ' page(s) is/are a true and exact reproduction of the original record on file with the Office of the Registrar, Bestlink College of the Philippines, pertaining to ' . $name . ', student number ' . ($student['student_number'] ?? '—') . '.';
            if ($purpose !== '') $body .= "\n\nRequested for: " . $purpose . '.';
            $body .= "\n\nCertified this " . $now . '.';
            break;

        case 'DOC-DIPLOMA':
            $body = 'DIPLOMA REPLACEMENT' . "\n\n" . 'This certifies that ' . $name . ' has satisfactorily completed the requirements for the ' . $course . ' program at Bestlink College of the Philippines. This replacement diploma is issued in lieu of the original, in accordance with the notarized Affidavit of Loss on file with this Office.';
            if ($purpose !== '') $body .= "\n\nRequested for: " . $purpose . '.';
            $body .= "\n\nIssued this " . $now . '.';
            break;

        case 'DOC-HD':
            $body = 'HONORABLE DISMISSAL' . "\n\n" . 'This is to certify that ' . $name . ', student number ' . ($student['student_number'] ?? '—') . ', of the ' . $course . ' program, has completed the exit clearance requirements of Bestlink College of the Philippines and is hereby dismissed in good standing with no financial or administrative obligations to the institution.';
            if ($purpose !== '') $body .= "\n\nThis certificate is issued upon the request of the student for the following purpose: " . $purpose . '.';
            $body .= "\n\nIssued this " . $now . '.';
            break;

        case 'DOC-CD':
            $body = 'COURSE DESCRIPTION' . "\n\n" . 'The following ' . max(1, $qty) . ' course description(s) is/are issued as the official syllabi prescribed in the ' . $course . ' curriculum of Bestlink College of the Philippines for the Academic Year ' . $sy . ($sem !== '' ? ', ' . $sem . ' Semester' : '') . '.';
            if ($purpose !== '') $body .= "\n\nRequested for: " . $purpose . '.';
            $body .= "\n\nIssued this " . $now . '.';
            break;

        default:
            $body = 'This certifies that the official record pertaining to ' . $name . ', student number ' . ($student['student_number'] ?? '—') . ', as requested, is issued by the Office of the Registrar, Bestlink College of the Philippines.';
            if ($purpose !== '') $body .= "\n\nRequested for: " . $purpose . '.';
    }

    $pdf->MultiCell(0, 6, $body, 0, 'J', false, 1);
    $pdf->Ln(4);

    // ── Signature block ─────────────────────────────────────────
    $sigFile = __DIR__ . '/../assets/registrar-signature.png';
    $canRaster = function_exists('imagecreatefrompng'); // GD present?
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(90, 90, 100);
    $pdf->Cell(0, 5, 'Issued this ' . $now . ' at the Office of the Registrar.', 0, 0, 'L');
    $pdf->Ln(14);

    if (is_file($sigFile) && $canRaster) {
        $pdf->Image($sigFile, 130, $pdf->GetY() - 14, 50, 0, 'PNG');
    } else {
        // Styled text signature: a ruled line + italic name + title.
        $y0 = $pdf->GetY();
        $pdf->SetDrawColor(60, 60, 70);
        $pdf->SetLineWidth(0.4);
        $pdf->Line(130, $y0, 190, $y0);
        $pdf->SetFont('times', 'I', 13);
        $pdf->SetTextColor(20, 20, 40);
        $pdf->SetXY(130, $y0 + 1);
        $pdf->Cell(60, 6, $signatory !== '' ? $signatory : 'Registrar', 0, 0, 'C');
    }
    $pdf->SetXY(130, $pdf->GetY() + 6);
    $pdf->SetFont('helvetica', 'B', 8.5);
    $pdf->SetTextColor(60, 60, 70);
    $pdf->Cell(60, 5, 'REGISTRAR', 0, 0, 'C');
    $pdf->Ln(2);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(110, 110, 120);
    $pdf->Cell(60, 5, 'Bestlink College of the Philippines', 0, 0, 'C');
    $pdf->Ln(8);

    // ── Footer verification strip ───────────────────────────────
    $verifyUrl = documentVerifyUrl($qrHash);
    $stripY = $pdf->GetY();

    // Outer strip panel (28mm tall, so the QR tile + text column both fit).
    $pdf->SetFillColor(242, 244, 249);
    $pdf->SetDrawColor(214, 220, 230);
    $pdf->Rect(18, $stripY, 174, 28, 'DF');

    // QR in its own white tile (vector modules — no GD required).
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->RoundedRect(20, $stripY + 2.5, 24, 24, 2.5, '1111', 'DF');
    $qrOpts = new QROptions([
        'eccLevel' => QRCode::ECC_L,
        'version'  => QRCode::VERSION_AUTO,
    ]);
    $qrMatrix = (new QRCode($qrOpts))->getQRMatrix($verifyUrl);
    $size = $qrMatrix->size();
    $cell = 20 / $size; // 20mm QR
    $baseX = 22;
    $baseY = $stripY + 4.5;
    $pdf->SetFillColor(12, 14, 18);
    for ($y = 0; $y < $size; $y++) {
        $row = $qrMatrix->matrix()[$y];
        for ($x = 0; $x < $size; $x++) {
            if (($row[$x] & QRMatrix::IS_DARK) !== 0) {
                $pdf->Rect($baseX + $x * $cell, $baseY + $y * $cell, $cell, $cell, 'F');
            }
        }
    }

    // Text column to the right of the QR tile. Single-line Cells only — no
    // MultiCell wrapping, so nothing spills past the strip's edges.
    $tx = 50;
    $pdf->SetXY($tx, $stripY + 4);
    $pdf->SetFont('helvetica', 'B', 9.5);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(0, 5, 'VERIFICATION PORTAL', 0, 0, 'L');

    $pdf->SetXY($tx, $stripY + 10);
    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->SetTextColor(90, 100, 115);
    $pdf->Cell(0, 4, 'Scan the QR code, or open the portal and enter the code below.', 0, 0, 'L');

    $pdf->SetXY($tx, $stripY + 15.5);
    $pdf->SetFont('courier', '', 7);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(0, 5, 'Code: ' . $qrHash, 0, 0, 'L');

    $pdf->SetXY($tx, $stripY + 21.5);
    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->SetTextColor(90, 100, 115);
    $pdf->Cell(0, 4, 'Verify at: ' . preg_replace('/[?&]qr=.*$/', '', $verifyUrl), 0, 0, 'L');

    // ── Output ─────────────────────────────────────────────────
    $bytes = $pdf->Output('', 'S');
    $fingerprint = hash('sha256', $bytes);
    $pdf->close();

    $filename = ($request['request_id'] ?? 'DOC') . '-' . ($catalog['sku'] ?? '') . '.pdf';
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename);

    return [
        'bytes'       => $bytes,
        'filename'    => $filename,
        'fingerprint' => $fingerprint,
        'qr_hash'     => $qrHash,
    ];
}

/**
 * Absolute URL to the public verification portal for a QR hash.
 * Uses app_url() (config.php) so it resolves correctly under any
 * sub-folder deployment, plus the request scheme/host.
 */
function documentVerifyUrl(string $qrHash): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . app_url('verify.php?qr=' . urlencode($qrHash));
}

/**
 * Draw the shared BCP letterhead (logo + college name + rule) used by the
 * invoice and transcript builders. Mirrors the block in buildDocumentPdf.
 * Caller must have called AddPage() first.
 */
function _pdf_draw_letterhead(\TCPDF $pdf): void
{
    $logoPng = _pdf_flatten_png_rgba(__DIR__ . '/../assets/images/BCP_LOGO.png', [255, 255, 255]);
    if ($logoPng !== null) {
        $pdf->Image('@' . $logoPng, 18, 12, 18, 0, 'PNG');
    } else {
        $pdf->SetFillColor(120, 40, 30);
        $pdf->Rect(18, 12, 18, 18, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY(18, 15);
        $pdf->Cell(18, 12, 'BCP', 0, 0, 'C');
    }
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(120, 40, 30);
    $pdf->SetXY(40, 14);
    $pdf->Cell(0, 5, 'REPUBLIC OF THE PHILIPPINES', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 15);
    $pdf->SetTextColor(20, 20, 40);
    $pdf->SetXY(40, 19);
    $pdf->Cell(0, 7, 'BESTLINK COLLEGE OF THE PHILIPPINES', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(90, 90, 100);
    $pdf->SetXY(40, 26);
    $pdf->Cell(0, 4, 'Office of the Registrar  |  Quezon City, Philippines  |  registrar@bestlink.edu.ph', 0, 0, 'L');

    $pdf->SetDrawColor(120, 40, 30);
    $pdf->SetLineWidth(0.6);
    $pdf->Line(18, 34, 192, 34);
    $pdf->SetLineWidth(0.2);
    $pdf->SetDrawColor(200, 200, 205);
    $pdf->Line(18, 35.2, 192, 35.2);
}

/**
 * Student full-name helper for the PDF builders (kept local so this file
 * does not need shared/functions.php).
 */
function _pdf_student_name(array $student): string
{
    return trim(($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '') . ' ' . ($student['last_name'] ?? '') . ' ' . ($student['name_suffix'] ?? ''));
}

/**
 * Build the invoice PDF for a document request (Emergency & Contacts
 * module, feature A). Encrypted with the student's ID number as the
 * PDF password (feature D).
 *
 * @param array $request  document_requests row (+ catalog join fields)
 * @param array $student  students row (must include student_number)
 * @param array|null $catalog document_catalog row (name/sku/base_fee)
 * @param float $deliveryFee delivery charge to include on the line
 *
 * @return array{bytes: string, filename: string}
 */
function buildInvoicePdf(array $request, array $student, ?array $catalog, float $deliveryFee = 0.0): array
{
    $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('BCP Registrar System');
    $pdf->SetAuthor('Bestlink College of the Philippines — Office of the Registrar');
    $pdf->SetTitle('Invoice ' . ($request['request_id'] ?? ''));
    $pdf->SetSubject('Document fee invoice');

    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);

    // Password = student number, AES-256 (mode 3) — Secure PDF feature.
    $pass = (string) ($student['student_number'] ?? '');
    if ($pass !== '') {
        $pdf->setProtection(['print', 'copy'], $pass, null, 3);
    }

    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 26);
    $pdf->SetMargins(18, 16, 18);

    _pdf_draw_letterhead($pdf);

    // ── Invoice header ──────────────────────────────────────────
    $pdf->Ln(14);
    $pdf->SetFont('times', 'B', 20);
    $pdf->SetTextColor(120, 40, 30);
    $pdf->Cell(0, 10, 'INVOICE', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(110, 110, 120);
    $pdf->Cell(0, 5, 'Document Fee — Office of the Registrar', 0, 1, 'C');
    $pdf->Ln(4);

    // Bill-to block + meta on one row.
    $fee = (float) ($request['fee_amount'] ?? 0);
    if ($fee <= 0 && $catalog !== null) {
        $fee = round((float) ($catalog['base_fee'] ?? 0) * max(1, (int) ($request['quantity'] ?? 1)), 2);
    }
    $deliveryFee = max(0.0, $deliveryFee);
    $total = round($fee + $deliveryFee, 2);

    $meta = [
        'Invoice No.'   => 'INV-' . ($request['request_id'] ?? '—'),
        'Request No.'   => (string) ($request['request_id'] ?? '—'),
        'Date Issued'   => date('F d, Y'),
        'Status'        => 'AWAITING PAYMENT',
        'Bill To'       => _pdf_student_name($student),
        'Student No.'   => (string) ($student['student_number'] ?? '—'),
    ];
    $pdf->SetFont('helvetica', 'B', 8.5);
    $pdf->SetTextColor(80, 90, 110);
    foreach ($meta as $label => $value) {
        $y = $pdf->GetY();
        $pdf->SetX(18);
        $pdf->Cell(40, 6, strtoupper($label), 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(0, 6, $value, 0, 1, 'L');
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetTextColor(80, 90, 110);
        $pdf->SetDrawColor(235, 238, 243);
        $pdf->SetLineWidth(0.15);
        $pdf->Line(18, $y + 6.3, 192, $y + 6.3);
    }
    $pdf->Ln(4);

    // ── Line items table ────────────────────────────────────────
    $catalogName = (string) ($catalog['name'] ?? ucwords(str_replace('_', ' ', (string) ($request['document_type'] ?? 'Document request'))));
    $qty = max(1, (int) ($request['quantity'] ?? 1));

    $pdf->SetFillColor(120, 40, 30);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(96, 8, 'DESCRIPTION', 0, 0, 'L', true);
    $pdf->Cell(24, 8, 'QTY', 0, 0, 'C', true);
    $pdf->Cell(36, 8, 'AMOUNT', 0, 0, 'R', true);
    $pdf->Cell(36, 8, 'TOTAL', 0, 1, 'R', true);

    $pdf->SetFillColor(248, 248, 250);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->SetFont('helvetica', '', 9.5);
    $pdf->Cell(96, 8, $catalogName . ' (Request ' . ($request['request_id'] ?? '') . ')', 0, 0, 'L', true);
    $pdf->Cell(24, 8, (string) $qty, 0, 0, 'C', true);
    $pdf->Cell(36, 8, number_format($fee / $qty, 2), 0, 0, 'R', true);
    $pdf->Cell(36, 8, number_format($fee, 2), 0, 1, 'R', true);

    if ($deliveryFee > 0) {
        $pdf->SetFont('helvetica', '', 9.5);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(120, 8, 'Delivery fee', 0, 0, 'L', true);
        $pdf->Cell(36, 8, '', 0, 0, 'R', true);
        $pdf->Cell(36, 8, number_format($deliveryFee, 2), 0, 1, 'R', true);
    }

    $pdf->SetDrawColor(203, 213, 225);
    $pdf->SetLineWidth(0.3);
    $pdf->Line(18, $pdf->GetY(), 192, $pdf->GetY());

    $pdf->SetFillColor(240, 232, 229);
    $pdf->SetFont('helvetica', 'B', 10.5);
    $pdf->SetTextColor(120, 40, 30);
    $pdf->Cell(156, 10, 'TOTAL DUE', 0, 0, 'R', true);
    $pdf->Cell(36, 10, 'PHP ' . number_format($total, 2), 0, 1, 'R', true);

    $pdf->Ln(6);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(90, 90, 100);
    $pdf->MultiCell(0, 5, 'This invoice is payable before the document is released. The document fee is charged per the published Office of the Registrar rate sheet. For payment questions, contact the Office of the Registrar.', 0, 'L');

    $bytes = $pdf->Output('', 'S');
    $pdf->close();

    $filename = 'INV-' . ($request['request_id'] ?? 'DOC') . '.pdf';
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename);

    return ['bytes' => $bytes, 'filename' => $filename];
}

/**
 * Build the transcript-of-records PDF for a student (Emergency & Contacts
 * module — transcript delivery). Encrypted with the student's ID number.
 *
 * @param array $student students row (must include student_number)
 * @param array $terms   academic_history rows, each with a 'subjects' key
 *                       (academic_grades rows) — same shape as contactBuildTerms()
 *
 * @return array{bytes: string, filename: string}
 */
function buildTranscriptPdf(array $student, array $terms): array
{
    $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('BCP Registrar System');
    $pdf->SetAuthor('Bestlink College of the Philippines — Office of the Registrar');
    $pdf->SetTitle('Transcript of Records — ' . _pdf_student_name($student));
    $pdf->SetSubject('Unofficial transcript snapshot');

    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);

    $pass = (string) ($student['student_number'] ?? '');
    if ($pass !== '') {
        $pdf->setProtection(['print', 'copy'], $pass, null, 3);
    }

    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 26);
    $pdf->SetMargins(18, 16, 18);

    _pdf_draw_letterhead($pdf);

    $pdf->Ln(14);
    $pdf->SetFont('times', 'B', 17);
    $pdf->SetTextColor(20, 20, 40);
    $pdf->Cell(0, 9, 'TRANSCRIPT OF RECORDS', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(110, 110, 120);
    $pdf->Cell(0, 5, 'Unofficial copy · generated ' . date('F d, Y h:i A'), 0, 1, 'C');
    $pdf->Ln(5);

    // Student record strip.
    $info = [
        'Student Name' => _pdf_student_name($student),
        'Student No.'  => (string) ($student['student_number'] ?? '—'),
        'Course'       => (string) ($student['course'] ?? '—'),
        'Year Level'   => $student['year_level'] !== '' && $student['year_level'] !== null ? (string) $student['year_level'] : '—',
        'Section'      => (string) ($student['section'] ?? '—'),
    ];
    $pdf->SetFont('helvetica', 'B', 8.5);
    $pdf->SetTextColor(80, 90, 110);
    foreach ($info as $label => $value) {
        $y = $pdf->GetY();
        $pdf->SetX(18);
        $pdf->Cell(34, 6, strtoupper($label), 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(0, 6, $value, 0, 1, 'L');
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetTextColor(80, 90, 110);
        $pdf->SetDrawColor(235, 238, 243);
        $pdf->SetLineWidth(0.15);
        $pdf->Line(18, $y + 6.3, 192, $y + 6.3);
    }
    $pdf->Ln(5);

    $colW = [42, 84, 22, 26, 18]; // Code / Subject / Units / Final / Remarks
    $header = ['CODE', 'SUBJECT', 'UNITS', 'FINAL', 'REMARKS'];

    $pdf->SetFillColor(120, 40, 30);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 8);
    $x0 = 18;
    $pdf->SetX($x0);
    foreach ($header as $i => $h) {
        $pdf->Cell($colW[$i], 7, $h, 0, 0, 'C', true);
    }
    $pdf->Ln(7);

    $n = 0;
    foreach ($terms as $t) {
        $subjects = $t['subjects'] ?? [];
        if (count($subjects) === 0) continue;
        $termLabel = trim(($t['school_name'] ?? '') . ' · ' . ($t['school_year'] ?? '') . ($t['semester'] !== '' && $t['semester'] !== null ? ' · ' . $t['semester'] : ''));
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetTextColor(120, 40, 30);
        $pdf->SetFillColor(245, 240, 238);
        $pdf->SetX($x0);
        $pdf->Cell($colW[0], 6, '', 0, 0, 'L', true);
        $pdf->Cell($colW[1], 6, $termLabel, 0, 0, 'L', true);
        $pdf->Cell($colW[2] + $colW[3] + $colW[4], 6, $t['gwa'] !== '' && $t['gwa'] !== null ? 'GWA ' . $t['gwa'] : '', 0, 1, 'R', true);
        $pdf->Ln(1);

        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(30, 41, 59);
        $rowFill = false;
        foreach ($subjects as $s) {
            $g = trim((string) ($s['final_rating'] !== '' && $s['final_rating'] !== null ? $s['final_rating'] : ($s['grade'] ?? '')));
            $pdf->SetFillColor(248, 248, 250);
            $pdf->SetX($x0);
            $pdf->Cell($colW[0], 6, (string) ($s['subject_code'] ?? ''), 0, 0, 'C', $rowFill);
            $pdf->Cell($colW[1], 6, (string) ($s['subject'] ?? ''), 0, 0, 'L', $rowFill);
            $pdf->Cell($colW[2], 6, (string) ($s['units'] ?? ''), 0, 0, 'C', $rowFill);
            $pdf->Cell($colW[3], 6, $g, 0, 0, 'C', $rowFill);
            $pdf->Cell($colW[4], 6, (string) ($s['remarks'] ?? ''), 0, 1, 'L', $rowFill);
            $rowFill = !$rowFill;
        }
        $pdf->Ln(3);
        $n++;
    }

    if ($n === 0) {
        $pdf->SetFont('helvetica', '', 9.5);
        $pdf->SetTextColor(110, 110, 120);
        $pdf->SetX($x0);
        $pdf->Cell(0, 8, 'No academic records on file for this student.', 0, 1, 'L');
    }

    $pdf->Ln(2);
    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(90, 90, 100);
    $pdf->MultiCell(0, 5, 'This is an unofficial transcript snapshot generated from the current academic records. Official transcripts are issued only by the Office of the Registrar.', 0, 'L');

    $bytes = $pdf->Output('', 'S');
    $pdf->close();

    $filename = 'TRANSCRIPT-' . ($student['student_number'] ?? 'student') . '.pdf';
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename);

    return ['bytes' => $bytes, 'filename' => $filename];
}
