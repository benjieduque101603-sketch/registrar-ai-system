<?php
// ============================================================
//  SHARED/DOCUMENT_TEMPLATES.PHP
//  Registrar Office — printable record-copy templates.
//
//  Seven documents, one shared authentication block, one print
//  stylesheet. Each renderer takes a context array and returns an
//  HTML fragment, so the on-screen preview modal and the print
//  window render from the SAME source and cannot drift apart.
//
//  ── The N/A convention ──────────────────────────────────────
//  Every template prints its full structure. Any field with no
//  data renders the literal string "N/A". Sections are never
//  hidden and there is no "no data" empty state, so a registrar
//  can tell a genuinely empty record apart from a rendering
//  failure.
//
//  This applies to ACADEMIC AND SOURCE-DOCUMENT DATA ONLY.
//  Request provenance — request no., walk-in timestamp, counter,
//  releasing officer, ticket numbers, signature and seal — is
//  recorded at intake and is ALWAYS present. A missing value
//  there is a data defect, and dt_provenance() logs it rather
//  than papering over it with "N/A".
//
//  ── Ownership ───────────────────────────────────────────────
//  Per DEPARTMENTS.md, four of the seven are printed as N/A
//  bodies because the content belongs to another department:
//    DOC-COE     → Enrollment Management #291
//    DOC-DIPLOMA → unassigned
//    DOC-HD      → unassigned
//    DOC-CD      → Curriculum & Subject Management #293
//  They still exist as requestable SKUs and still follow the full
//  walk-in process; only their body content is deferred.
//
//  Pure rendering: no writes, no side effects beyond the read-only
//  context collector at the bottom of this file.
// ============================================================

// ─────────────────────────────────────────────────────────────
//  Value helpers
// ─────────────────────────────────────────────────────────────

/** The literal printed in place of a missing value. */
if (!defined('DT_NA')) {
    define('DT_NA', 'N/A');
}

/** HTML-escape, treating null/false/'' as absent. */
function dt_esc($v): string
{
    if ($v === null || $v === false || $v === '') {
        return DT_NA;
    }
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/**
 * Format a value for print, or N/A when absent.
 *
 * @param mixed  $value
 * @param string $format  Any date() format, or '' to print as-is
 */
function dt_val($value, string $format = ''): string
{
    if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
        return DT_NA;
    }
    if ($format !== '') {
        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
        if ($ts === false) {
            return DT_NA;
        }
        return date($format, $ts);
    }
    return (string) $value;
}

/** Escaped dt_val — what most call sites want. */
function dt_e($value, string $format = ''): string
{
    return htmlspecialchars(dt_val($value, $format), ENT_QUOTES, 'UTF-8');
}

/** Currency, or N/A. A 0.00 balance is a real figure and prints. */
function dt_money($value): string
{
    if ($value === null || $value === '') {
        return DT_NA;
    }
    return '₱' . number_format((float) $value, 2);
}

/**
 * A value that MUST exist on a live request. Missing here means a
 * real defect, so it is logged and marked so it cannot be mistaken
 * for ordinary empty data.
 */
function dt_provenance($label, $value, int $requestId = 0): string
{
    if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
        if ($requestId > 0) {
            error_log("document_provenance_gap: request {$requestId} missing '{$label}'");
        }
        return '<span class="dt-missing" title="Data defect — not ordinary empty data">' . DT_NA . '</span>';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────
//  Print stylesheet — shared by the preview modal and the print
//  window so the two always match. Millimetre units throughout so
//  the layout is physical, not screen-relative.
// ============================================================

function dt_stylesheet(): string
{
    return <<<'CSS'
/* One page, always.

   @page margin is 0 and the sheet carries its own padding. That is
   deliberate: when the page margin is zero the browser has no space to
   draw its automatic header/footer, so the date, page title and URL
   that Chrome otherwise stamps on the printout do not appear.

   Paper size is left to the printer (size: auto) so a counter machine
   loaded with Letter or Legal prints correctly instead of being forced
   onto A4. The sheet is fluid, so it fits whichever paper is selected. */
@page { size: auto; margin: 0; }

.dt-doc {
  font-family: "Times New Roman", Times, serif;
  color: #14142a;
  font-size: 9.5pt;
  line-height: 1.34;
  width: 100%;
  /* Stands in for the @page margin, now that @page is 0. */
  padding: 12mm 12mm;
  background: #fff;
}
/* .dt-doc ITSELF must be border-box, not just its children. In the
   default content-box it measured 100% + 12mm padding on each side =
   210mm + 24mm on A4, so 24mm ran off the right edge and the QR and
   last table column were cropped off the printout. */
.dt-doc, .dt-doc * { box-sizing: border-box; }

/* Nothing may break across a page boundary. */
.dt-doc table, .dt-doc .dt-strip, .dt-doc .dt-tot,
.dt-doc .dt-auth, .dt-doc .dt-verify,
.dt-doc .dt-nabody, .dt-doc .dt-checks { break-inside: avoid; page-break-inside: avoid; }
.dt-doc tr, .dt-doc .dt-row, .dt-doc .dt-signrow { break-inside: avoid; page-break-inside: avoid; }
.dt-doc h1, .dt-doc h2 { break-after: avoid; page-break-after: avoid; }

/* ── Letterhead ─────────────────────────────────────────── */
.dt-head { text-align: center; border-bottom: 1.5px solid #1a3a8c; padding-bottom: 3px; }
.dt-head .rep { font-size: 6.5pt; letter-spacing: .08em; text-transform: uppercase; color: #445; }
.dt-head .inst { font-size: 11pt; font-weight: 700; color: #1a3a8c; margin-top: 1px; }
.dt-head .office { font-size: 7pt; color: #556; margin-top: 0; }
.dt-head img.dt-logo { height: 26px; margin-bottom: 2px; }

/* ── Title ──────────────────────────────────────────────── */
.dt-title { text-align: center; margin: 7px 0 2px; }
.dt-title h2 { font-size: 12.5pt; margin: 0; letter-spacing: .04em; text-transform: uppercase; }

/* ── Field strip ────────────────────────────────────────── */
.dt-strip { margin: 6px 0; border-top: 1px solid #ccc; border-bottom: 1px solid #ccc; }
.dt-strip .dt-row { display: flex; border-bottom: 1px solid #eee; }
.dt-strip .dt-row:last-child { border-bottom: 0; }
.dt-strip .dt-k {
  flex: 0 0 40mm; padding: 1.5px 6px; font-size: 6.5pt; font-weight: 700;
  text-transform: uppercase; letter-spacing: .05em; color: #475; background: #f7f8fb;
}
.dt-strip .dt-v { flex: 1 1 auto; padding: 1.5px 6px; font-size: 9pt; }

/* ── Body ───────────────────────────────────────────────── */
.dt-body { margin: 6px 0; text-align: justify; }
.dt-body p { margin: 0 0 5px; }
.dt-body .dt-hd { text-align: center; font-weight: 700; letter-spacing: .04em; }
.dt-nabody { border: 1px solid #ccc; background: #fafbfd; padding: 4px 8px; text-align: center; font-style: italic; color: #445; }
/* Centred paragraph inside justified text, e.g. the certificate
   preamble's "THIS IS TO CERTIFY" lead-in. */
.dt-body p.dt-center { text-align: center; }

/* ── Tables ─────────────────────────────────────────────── */
.dt-table { width: 100%; border-collapse: collapse; margin: 4px 0; font-size: 8pt; }
.dt-table th, .dt-table td { border: 1px solid #b9bfcb; padding: 1.5px 4px; }
.dt-table th { background: #eef2f9; font-size: 6.5pt; text-transform: uppercase; letter-spacing: .04em; }
.dt-table td.dt-na { color: #8a8f9c; font-style: italic; text-align: center; }
.dt-table .dt-term { background: #f2f5fa; font-weight: 700; font-size: 7.5pt; }
.dt-tot { display: flex; flex-wrap: wrap; margin: 4px 0; border: 1px solid #b9bfcb; }
.dt-tot > div { flex: 1 1 50%; border-right: 1px solid #e4e8ef; padding: 1.5px 6px; display: flex; }
.dt-tot > div:nth-child(2n) { border-right: 0; }
.dt-tot .k { flex: 0 0 38mm; font-size: 6.5pt; font-weight: 700; text-transform: uppercase; color: #475; }
.dt-tot .v { font-size: 9pt; }

/* ── Clausal list (good moral) ──────────────────────────── */
.dt-checks { margin: 4px 0 4px 5mm; padding-left: 4mm; }
.dt-checks li { margin-bottom: 1px; }

/* ── Certificate of Good Moral (DOC-GM) ──────────────────────
   Scoped to .dt-gm so the other six documents are untouched.

   The design argument: on a character certificate the student's name
   IS the document — it is what the employer or CHED officer reads
   first — so it is the only display element. Everything else is set
   smaller and quieter so the name carries the page.

   The findings are a ruled LEDGER, not bullets, because a table
   genuinely encodes "items, each with a finding" where a list does
   not. And they are headed "Standing at the time of issuance",
   which is the legally correct framing: these are a point-in-time
   record, not a guarantee about the student's future conduct. */
.dt-gm .dt-title { margin-bottom: 10px; }
.dt-gm .dt-title h2 { font-size: 14pt; letter-spacing: .1em; }

/* The attestation, centred. The lead-in is small and letterspaced so
   the name beneath it reads as the subject rather than as a heading. */
.dt-gm .dt-attest { text-align: center; margin: 14px 0 0; }
.dt-gm .dt-attest .dt-lead {
  font-size: 7pt; letter-spacing: .18em; text-transform: uppercase;
  color: #556; margin-bottom: 6px;
}
.dt-gm .dt-attest .dt-rule {
  width: 34mm; margin: 0 auto 7px; border-top: 1px solid #b9bfcb;
}
.dt-gm .dt-attest .dt-name {
  font-size: 15pt; font-weight: 700; letter-spacing: .01em;
  color: #14142a; line-height: 1.25;
}
.dt-gm .dt-attest .dt-sub {
  font-size: 8pt; color: #556; margin-top: 4px;
}

/* The claim itself: justified prose, indented from the ledger below so
   the two read as different kinds of statement. */
.dt-gm .dt-claim {
  margin: 12px auto 0; max-width: 130mm; text-align: justify;
  font-size: 9.5pt; line-height: 1.5;
}

/* ── The standing ledger ─────────────────────────────────── */
.dt-gm .dt-standing { margin: 14px 0 0; }
.dt-gm .dt-standing .dt-shead {
  font-size: 6.5pt; font-weight: 700; letter-spacing: .12em;
  text-transform: uppercase; color: #475; text-align: center;
  padding-bottom: 3px; border-bottom: 1.5px solid #1a3a8c;
}
.dt-gm .dt-standing table { width: 100%; border-collapse: collapse; margin-top: 2px; }
.dt-gm .dt-standing td {
  padding: 2.5px 6px; font-size: 8.5pt; border-bottom: 1px solid #e7eaf0;
}
/* The check label is the subject; the finding is the value. */
.dt-gm .dt-standing td.dt-k { color: #334; width: 62mm; }
/* A VERIFIED finding is a real attestation and is set in the accent
   so a reader can tell at a glance which lines the office actually
   stands behind. */
.dt-gm .dt-standing td.dt-v { text-align: right; font-weight: 600; color: #1a3a8c; }
/* N/A is NOT a defect here — it means the office cannot attest to
   that item, which is different from having checked and found
   nothing. It is therefore quiet, not the red .dt-missing used for a
   genuinely missing provenance field. Printing it in alarm colours
   would misrepresent an honest blank as a broken document. */
.dt-gm .dt-standing td.dt-unverified { text-align: right; color: #8a8f9c; font-style: italic; font-weight: 400; }

.dt-gm .dt-purpose {
  margin: 10px 0 0; font-size: 8.5pt; color: #334; text-align: justify;
}
.dt-gm .dt-purpose .dt-lbl {
  font-size: 6.5pt; font-weight: 700; letter-spacing: .1em;
  text-transform: uppercase; color: #556;
}

/* ── Authentication block ───────────────────────────────── */
.dt-auth { margin-top: 8px; border-top: 1px solid #ccc; padding-top: 5px; }
.dt-issued { font-size: 8.5pt; }
.dt-signrow { display: flex; justify-content: center; margin-top: 10px; }
/* The signature block is a fixed-width column centred on the sheet.
   It used to be a flex child sized against the dry-seal circle beside
   it; with the seal removed that left it stretched edge to edge. */
.dt-sign { flex: 0 0 70mm; text-align: center; }
.dt-sign .dt-line { border-bottom: 1px solid #333; height: 16px; }
.dt-sign .dt-who { font-size: 7pt; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; text-align: center; margin-top: 1px; }
.dt-sign .dt-org { font-size: 6.5pt; color: #667; text-align: center; }
.dt-signrow-date { margin-top: 0; }
.dt-signrow-date .dt-sign { border-bottom: 1px solid #333; font-size: 8pt; padding-bottom: 1px; }

/* ── Verification strip ─────────────────────────────────── */
.dt-verify { display: flex; gap: 6px; align-items: stretch; margin-top: 6px; border: 1px solid #cbd3e1; background: #f6f8fc; padding: 4px 6px; }
.dt-verify .dt-vcol { flex: 1 1 auto; font-size: 6.5pt; color: #445; line-height: 1.45; }
.dt-verify .dt-vcol b { display: block; font-size: 7pt; color: #1a3a8c; text-transform: uppercase; letter-spacing: .04em; }
.dt-verify .dt-vq { flex: 0 0 20mm; text-align: center; }
/* The QR is inline SVG (chillerlan QRMarkupSVG), so size it with CSS.
   width/height attributes are stripped at render time for this reason. */
.dt-verify .dt-vq svg { width: 20mm; height: 20mm; display: block; margin: 0 auto; background: #fff; }
.dt-verify .dt-vq .cap { font-size: 5.5pt; color: #778; margin-top: 1px; }
/* A printed QR must be black-on-white to scan. chillerlan emits TWO sets
   of paths — .dark modules and .light modules — so only the .dark set may
   be forced black. Recolouring svg * paints the light modules black too
   and produces a solid square that cannot be scanned. */
.dt-verify .dt-vq svg path.dark { fill: #000; }
.dt-verify .dt-vq svg path.light { fill: #fff; }

.dt-missing { color: #b91c1c; font-weight: 700; }

/* Screen-only: keep the sheet a sane width in the preview modal.
   The print window gets the real page width instead. */
@media screen { .dt-doc { max-width: 190mm; } }
/* Print-width safety.
   `overflow: hidden` is deliberately NOT used here: it does not prevent
   overflow, it silently CROPS it — which is exactly how 24mm of the right
   edge went missing. The sheet is correctly sized by the border-box rule
   above, so the guards below only stop a single over-long cell or string
   from widening the page. */
@media print {
  html, body { max-width: 100%; }
  .dt-doc { max-width: 100%; }
  /* Print hygiene for the sheet itself. The registrar's preview modal
     draws a grey mat and a drop shadow around the document so it reads
     as a sheet on a desk; that mat is screen-only and must never reach
     the paper, or the print looks like a screenshot pasted onto a page
     rather than a document the office issued. The desk now injects its
     mat inside @media screen so this is belt-and-braces, but the reset
     is stated here — beside the rules that govern the sheet — because a
     viewer may carry a background or a shadow of its own. */
  html, body { background: #fff !important; }
  .dt-doc { box-shadow: none !important; background: #fff !important; }
  /* Tables are the usual culprit; let long cells wrap rather than widen. */
  .dt-table { table-layout: fixed; width: 100%; }
  .dt-table td, .dt-table th { overflow-wrap: break-word; word-wrap: break-word; }
  /* A single unbroken token (a hash, a long filename) must not force the
     table wider than the sheet. */
  .dt-strip .dt-v, .dt-tot .v { overflow-wrap: break-word; word-wrap: break-word; }
}
CSS;
}

/**
 * Scales the sheet to fit a single printed page.
 *
 * The compact print CSS handles nearly every template, but a long grade
 * table can still overrun. Rather than let it spill onto a second sheet,
 * this shrinks the sheet just enough to fit — down to a floor of 70% so
 * text never becomes illegible.
 *
 * Emitted only into the standalone print window. The on-screen preview
 * keeps full size, because there the registrar is reading, not filing.
 */
function dt_autofit_script(): string
{
    return <<<'JS'
<script>
(function () {
  // Floor for the fit-to-page scale, so text never becomes illegible.
  var MIN_SCALE = 0.70;

  function fit() {
    var doc = document.querySelector('.dt-doc');
    if (!doc) return;

    // Reset first, or a previous run's transform is measured too.
    doc.style.transform = '';
    doc.style.marginBottom = '';
    doc.style.width = '';

    // Measure the printable box from the viewport rather than assuming a
    // paper size: @page is `size: auto`, so the page may be A4, Letter or
    // Legal depending on the printer loaded at the counter.
    var vw = window.innerWidth || 794;
    var vh = window.innerHeight || 1123;

    var h = doc.scrollHeight;
    var w = doc.scrollWidth;
    if (h <= vh && w <= vw) return;

    // Fit on whichever axis is worse, so neither the foot of the sheet
    // nor its right edge is lost.
    var scale = Math.min(vh / h, vw / w);
    if (!isFinite(scale) || scale >= 1) return;
    if (scale < MIN_SCALE) scale = MIN_SCALE;

    // Width must stay 100%: a percentage width is a RENDERED width, so
    // setting 100/scale% would push the right edge past the printable
    // width and crop the QR and the last table column.
    doc.style.width = '100%';
    doc.style.transform = 'scale(' + scale + ')';
    doc.style.transformOrigin = 'top left';
    // Collapse the layout height the transform no longer occupies, so
    // only the visible (scaled) height is paginated.
    doc.style.marginBottom = ((h * (1 - scale)) * -1) + 'px';
  }

  if (document.readyState === 'complete') { fit(); }
  else { window.addEventListener('load', fit); }
  window.addEventListener('beforeprint', fit);
})();
</script>
JS;
}


// ─────────────────────────────────────────────────────────────
//  Shared chrome
// ─────────────────────────────────────────────────────────────

/** College letterhead. $logoUrl must be ABSOLUTE — the print window is
 *  a separate document, so a web-relative path would not resolve there. */
function dt_letterhead(?string $logoUrl = null): string
{
    $logo = '';
    if ($logoUrl !== null && trim($logoUrl) !== '') {
        $logo = '<img class="dt-logo" src="' . htmlspecialchars($logoUrl, ENT_QUOTES) . '" alt="BCP">';
    }
    return '<div class="dt-head">' . $logo
        . '<div class="rep">Republic of the Philippines</div>'
        . '<div class="inst">Bestlink College of the Philippines</div>'
        . '<div class="office">Office of the Registrar</div>'
        . '</div>';
}

/**
 * Document title block.
 *
 * Just the document name. The request number, counter, SKU and owning
 * department are deliberately NOT printed here — all of that appears in
 * the verification strip at the foot of the sheet, and the duplication
 * crowded the masthead. Keeping the title clean leaves room for the body.
 *
 * @param string      $title    Printed document name
 * @param array       $request  document_requests row
 * @param array       $catalog  document_catalog row
 */
function dt_title(string $title, array $request, array $catalog): string
{
    return '<div class="dt-title"><h2>'
        . htmlspecialchars($title, ENT_QUOTES)
        . '</h2></div>';
}

/**
 * The label/value strip under the title.
 *
 * @param array $pairs  label => raw value
 */
function dt_strip(array $pairs): string
{
    if (!$pairs) {
        return '';
    }
    $out = '<div class="dt-strip">';
    foreach ($pairs as $k => $v) {
        $out .= '<div class="dt-row"><div class="dt-k">' . htmlspecialchars((string) $k, ENT_QUOTES)
             . '</div><div class="dt-v">' . dt_esc($v) . '</div></div>';
    }
    return $out . '</div>';
}

/**
 * The two-column totals row (TOR units/GWA, etc.).
 *
 * @param array $pairs  label => already-formatted, unescaped value
 */
function dt_totals(array $pairs): string
{
    if (!$pairs) {
        return '';
    }
    $out = '<div class="dt-tot">';
    foreach ($pairs as $k => $v) {
        $out .= '<div><div class="k">' . htmlspecialchars((string) $k, ENT_QUOTES)
             . '</div><div class="v">' . $v . '</div></div>';
    }
    return $out . '</div>';
}

/**
 * The authentication block: signature rule and date line. Identical on
 * all seven documents — this is what makes the printout a record copy
 * rather than a generic letter.
 *
 * There is deliberately NO dry-seal placeholder. The registrar affixes
 * the real seal to the physical sheet by hand, so a printed circle would
 * only be a target that some sheets get stamped and others do not.
 *
 * @param string $issuedLine  Sentence introducing the issue date
 */
function dt_auth_block(array $request, string $issuedLine, ?string $signatory = null): string
{
    $who = trim((string) ($signatory ?? '')) ?: 'Office of the Registrar';

    $out  = '<div class="dt-auth">';
    $out .= '<div class="dt-issued">' . $issuedLine . '</div>';
    $out .= '<div class="dt-signrow">';
    $out .= '<div class="dt-sign"><div class="dt-line"></div>'
         . '<div class="dt-who">' . htmlspecialchars($who, ENT_QUOTES) . '</div>'
         . '<div class="dt-org">Registrar &middot; Bestlink College of the Philippines</div></div>';
    $out .= '</div>';
    // Date line sits under the signature rule, same fixed width so the
    // two read as one centred block.
    $out .= '<div class="dt-signrow dt-signrow-date"><span class="dt-sign">'
         . htmlspecialchars(date('F d, Y'), ENT_QUOTES) . '</span></div>';
    $out .= '</div>';
    return $out;
}


/**
 * The public base URL of the deployed app.
 *
 * The QR on a record copy is scanned by the STUDENT or a third party
 * (a CHED officer, an employer), often months later and from a phone on
 * a different network. It must therefore point at the public domain, not
 * at whatever host happens to be serving the preview — a `localhost`
 * URL on a printed certificate is worthless.
 *
 * APP_PUBLIC_URL is read from the environment (or a gitignored
 * shared/config.local.php) and wins when set. Otherwise we fall back to
 * the request host, which is correct on a single-host deployment.
 */
function dt_public_base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $configured = trim((string) (getenv('APP_PUBLIC_URL') ?: ''));
    if ($configured === '' && is_file(__DIR__ . '/config.local.php')) {
        // A gitignored local override, matching the existing
        // shared/config.local.php convention in this project.
        $local = @include __DIR__ . '/config.local.php';
        if (is_array($local)) {
            $configured = trim((string) ($local['APP_PUBLIC_URL'] ?? ''));
        }
    }
    if ($configured === '') {
        $configured = 'https://registrar.bcpsms2.com';
    }
    $base = rtrim($configured, '/');
    return $base;
}

/**
 * Join the public base URL with an app-relative path.
 *
 * app_url() returns a ROOT-RELATIVE path (e.g. "/tests/verify.php"), so
 * it must be appended to the base with an explicit separator. Naive
 * concatenation yields "https://hosttests/verify.php".
 */
function dt_public_url(string $path = ''): string
{
    $base = dt_public_base_url();
    $path = (string) app_url($path);
    if ($path === '' || $path === '/') {
        return $base . '/';
    }
    return $base . '/' . ltrim($path, '/');
}

/**
 * The verification URL encoded into the QR code.
 *
 * Points at verify.php?qr=<qr_hash>, the public page that resolves a
 * document request from its hash and shows its status and authenticity.
 * Returns an empty string when the request has no hash yet (nothing to
 * verify), in which case no QR is drawn.
 */
function dt_verify_url(?string $qrHash): string
{
    $hash = trim((string) $qrHash);
    if ($hash === '') {
        return '';
    }
    return dt_public_url('verify.php?qr=' . urlencode($hash));
}

/**
 * Render the verification QR as inline SVG.
 *
 * Drawn as a self-contained SVG rather than an <img> to a generated
 * file: the print window is a separate document, and an external file
 * would need an absolute URL and would 404 if the file was never
 * written. Inline markup always prints.
 *
 * @return string SVG markup, or '' when the library is unavailable
 */
function dt_qr_svg(string $url, int $scale = 4): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('dt_qr_svg: vendor/autoload.php missing (run composer install).');
        return '';
    }
    try {
        require_once $autoload;
        $opts = new \chillerlan\QRCode\QROptions([
            'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class,
            'outputBase64'    => false,
            // ECC_H: the QR is a verification handle, so it is worth
            // spending redundancy on — it survives a small blemish or a
            // partial photocopy.
            'eccLevel'        => 2,   // QRCode::ECC_H
            'scale'           => max(1, $scale),
        ]);
        $svg = (new \chillerlan\QRCode\QRCode($opts))->render($url);
        // Strip a fixed width/height so CSS can size it for print.
        $svg = preg_replace('/\s(width|height)="[^"]*"/i', '', (string) $svg);
        return (string) $svg;
    } catch (\Throwable $e) {
        error_log('dt_qr_svg: ' . $e->getMessage());
        return '';
    }
}

// ─────────────────────────────────────────────────────────────
//  TABLE RENDERER
// ─────────────────────────────────────────────────────────────

/**
 * Render a data table where EVERY missing cell prints N/A rather
 * than being skipped. $rows may be empty — the table still prints,
 * with N/A filler rows, so a genuinely empty grade table looks
 * empty rather than absent.
 *
 * @param array  $cols  column label => raw value key
 * @param array  $rows  list of associative rows
 * @param int    $filler  N/A-only rows to emit when $rows is empty
 */
function dt_table(array $cols, array $rows, int $filler = 3): string
{
    $out  = '<table class="dt-table"><thead><tr>';
    foreach (array_keys($cols) as $label) {
        $out .= '<th>' . htmlspecialchars((string) $label, ENT_QUOTES) . '</th>';
    }
    $out .= '</tr></thead><tbody>';

    $emit = static function (array $row) use ($cols, &$out): void {
        $out .= '<tr>';
        foreach ($cols as $key) {
            $v = $row[$key] ?? null;
            if ($v === null || $v === '' || (is_string($v) && trim($v) === '')) {
                $out .= '<td class="dt-na">' . DT_NA . '</td>';
            } else {
                $out .= '<td>' . htmlspecialchars((string) $v, ENT_QUOTES) . '</td>';
            }
        }
        $out .= '</tr>';
    };

    if ($rows) {
        foreach ($rows as $r) {
            $emit((array) $r);
        }
    } else {
        // No rows: print the structure with N/A filler so the section
        // is visibly empty rather than missing.
        for ($i = 0; $i < $filler; $i++) {
            $out .= '<tr>';
            foreach (array_keys($cols) as $_) {
                $out .= '<td class="dt-na">' . DT_NA . '</td>';
            }
            $out .= '</tr>';
        }
    }
    return $out . '</tbody></table>';
}

// ─────────────────────────────────────────────────────────────
//  1. TRANSCRIPT OF RECORDS  ·  DOC-TOR
//     Registrar issues the TOR. The grade DATA belongs to
//     Curriculum & Subject Management #293, so every cell prints
//     N/A until that module populates academic_grades.
// ============================================================

function dt_tor(array $ctx): string
{
    $s = $ctx['student'];
    $r = $ctx['request'];

    $out  = dt_letterhead($ctx['logo_url']);
    $out .= dt_title('Transcript of Records', $r, $ctx['catalog']);
    $out .= dt_strip([
        'Student Name' => dt_cert_name($s),
        'Student No.'  => $s['student_number'] ?? null,
        'Program'      => $s['course'] ?? null,
        'Year Level'   => $s['year_level'] ?? null,
    ]);

    $out .= '<div class="dt-body">';
    $out .= '<p class="dt-hd">ACADEMIC RECORD</p>';

    $terms = $ctx['terms'] ?? [];
    if ($terms) {
        foreach ($terms as $t) {
            $t = (array) $t;
            $sy = trim((string) ($t['school_year'] ?? ''));
            $sem = trim((string) ($t['semester'] ?? ''));
            $label = trim(($sy !== '' ? $sy : '') . ($sem !== '' ? ' · ' . $sem : ''));
            $out .= '<div class="dt-term">' . htmlspecialchars($label !== '' ? $label : DT_NA, ENT_QUOTES) . '</div>';
            $out .= dt_table(
                ['Code' => 'subject_code', 'Subject' => 'subject', 'Units' => 'units',
                 'Final' => 'final_rating', 'Grade' => 'grade', 'Remarks' => 'remarks'],
                $t['subjects'] ?? []
            );
            $out .= '<div style="font-size:9pt;color:#445;margin:4px 0 10px">'
                 . 'Term units earned: ' . dt_e($t['credits'] ?? null)
                 . ' &nbsp;&nbsp;&middot;&nbsp;&nbsp; Term GWA: ' . dt_e($t['gwa'] ?? null)
                 . '</div>';
        }
    } else {
        // No academic_history rows at all. Print the structure with
        // N/A filler rather than an empty page section.
        $out .= '<div class="dt-term">' . DT_NA . ' &nbsp;·&nbsp; ' . DT_NA . '</div>';
        $out .= dt_table(
            ['Code' => 'subject_code', 'Subject' => 'subject', 'Units' => 'units',
             'Final' => 'final_rating', 'Grade' => 'grade', 'Remarks' => 'remarks'],
            [],
            4
        );
        $out .= '<div style="font-size:9pt;color:#445;margin:4px 0 10px">'
             . 'Term units earned: ' . DT_NA . ' &nbsp;&nbsp;&middot;&nbsp;&nbsp; Term GWA: ' . DT_NA
             . '</div>';
    }

    $out .= dt_totals([
        'Total Units Earned' => dt_e($ctx['total_units'] ?? null),
        'Career GWA'         => dt_e($ctx['career_gwa'] ?? null),
        'Date Graduated'     => dt_e($s['graduation_date'] ?? null, 'F d, Y'),
        'Remarks'            => dt_e($ctx['tor_remarks'] ?? null),
    ]);
    $out .= dt_strip([
        'Previous School'          => $s['previous_school'] ?? null,
        'Last Year Level Completed' => $s['last_year_level_completed'] ?? null,
        'Purpose'                  => $r['purpose'] ?? null,
    ]);
    $out .= '</div>';

    $out .= dt_auth_block($r, 'Issued this ' . date('F d, Y') . ' at the Office of the Registrar.', $ctx['signatory']);
    $out .= dt_verify_strip($r, $ctx['meta']);
    return $out;
}

/**
 *  2. CERTIFICATE OF GOOD MORAL  ·  DOC-GM
 *     Fully Registrar-owned (Group #292 explicitly names it).
 *
 *     The name is the only display element on the page: on a character
 *     certificate it is what the employer or CHED officer reads first.
 *     The findings sit in a ruled ledger headed "Standing at the time of
 *     issuance", which frames them as a point-in-time record rather than
 *     a guarantee about future conduct.
 */
function dt_good_moral(array $ctx): string
{
    $s = $ctx['student'];
    $r = $ctx['request'];
    $checks = $ctx['moral_checks'] ?? [];

    $out  = '<div class="dt-gm">';
    $out .= dt_letterhead($ctx['logo_url']);
    $out .= dt_title('Certificate of Good Moral', $r, $ctx['catalog']);

    // Record context. The student's NAME is deliberately not repeated
    // here: it is set large in the attestation below, and printing it
    // twice within 3cm reads as a layout fault rather than as emphasis.
    $out .= dt_strip([
        'Student No.' => $s['student_number'] ?? null,
        'Program'     => $s['course'] ?? null,
        'Year Level'  => $s['year_level'] ?? null,
        'School Year' => trim(
            (string) ($s['school_year'] ?? '')
            . (($s['semester'] ?? '') !== '' ? ' · ' . (string) $s['semester'] : '')
        ),
    ]);

    // ── The attestation ────────────────────────────────────
    $name = dt_cert_name($s);
    $out .= '<div class="dt-attest">';
    $out .= '<div class="dt-lead">This is to certify that</div>';
    $out .= '<div class="dt-rule"></div>';
    $out .= '<div class="dt-name">' . htmlspecialchars($name, ENT_QUOTES) . '</div>';
    $out .= '<div class="dt-sub">Student No. ' . dt_esc($s['student_number'] ?? null) . '</div>';
    $out .= '</div>';

    $out .= '<div class="dt-claim">is a bona fide student of Bestlink College of the '
         . 'Philippines, and is of good moral character.</div>';

    // ── The standing ledger ────────────────────────────────
    $out .= '<div class="dt-standing">';
    $out .= '<div class="dt-shead">Standing at the time of issuance</div>';
    $out .= '<table><tbody>';
    foreach (['Pending disciplinary cases', 'Pending academic deficiency',
              'Outstanding financial obligation', 'Administrative hold on file',
              'Exit clearance'] as $label) {
        // Keyed lookup: an absent key must still print N/A, never be
        // skipped. "0 cases" is a verified zero; N/A means the office
        // cannot attest to that item at all — a materially different
        // statement, and the reason the two are styled differently.
        $val = array_key_exists($label, $checks) ? $checks[$label] : null;
        $blank = ($val === null || $val === '');

        $out .= '<tr>';
        $out .= '<td class="dt-k">' . htmlspecialchars($label, ENT_QUOTES) . '</td>';
        $out .= '<td class="' . ($blank ? 'dt-unverified' : 'dt-v') . '">'
             . dt_esc($val) . '</td>';
        $out .= '</tr>';
    }
    $out .= '</tbody></table></div>';

    // ── Purpose ────────────────────────────────────────────
    $out .= '<div class="dt-purpose"><span class="dt-lbl">Issued upon the request of the student for</span><br>'
         . dt_e($r['purpose'] ?? null) . '</div>';

    $out .= '</div>';   // /.dt-gm

    $out .= dt_auth_block($r, 'Issued this ' . date('F d, Y') . '.', $ctx['signatory']);
    $out .= dt_verify_strip($r, $ctx['meta']);
    return $out;
}

// ─────────────────────────────────────────────────────────────
//  3. CERTIFIED TRUE COPY  ·  DOC-CTC
//     Certifies a COPY, never the authenticity of the original.
//     The wording is a fixed constant: a free-text certification
//     box would be a forgery vector, and omitting the limiting
//     clause makes the document legally wrong.
// ============================================================

/** The non-negotiable certification wording. */
if (!defined('DT_CTC_CLAUSE')) {
    define('DT_CTC_CLAUSE',
        'This certification covers the copy only. It does not certify, warrant, or '
        . 'attest to the authenticity, genuineness, or validity of the original document itself.'
    );
}

function dt_ctc(array $ctx): string
{
    $s  = $ctx['student'];
    $r  = $ctx['request'];
    $ct = $ctx['ctc'] ?? [];

    $out  = dt_letterhead($ctx['logo_url']);
    $out .= dt_title('Certified True Copy', $r, $ctx['catalog']);
    $out .= dt_strip([
        'Student Name' => dt_cert_name($s),
        'Student No.'  => $s['student_number'] ?? null,
    ]);

    $out .= '<div class="dt-body">';
    $out .= '<p>I hereby certify that the document described below is a true and correct '
         . 'copy of the original document in the custody of the Office of the Registrar, '
         . 'Bestlink College of the Philippines.</p>';

    // Fixed wording — never interpolated, never user-editable.
    $out .= '<p style="font-style:italic"><strong>' . htmlspecialchars(DT_CTC_CLAUSE, ENT_QUOTES) . '</strong></p>';

    $pages = $ct['pages'] ?? null;
    $out .= dt_totals([
        'Document Certified' => dt_esc($ct['doc_name'] ?? null),
        'Control / File No.' => dt_esc($ct['control_no'] ?? null),
        'Date of Original'   => dt_e($ct['date_original'] ?? null, 'F d, Y'),
        'Pages Certified'    => dt_esc($pages),
        'Original SHA-256'   => ($ct['sha'] ?? '') !== ''
                                ? htmlspecialchars(substr((string) $ct['sha'], 0, 32) . '…', ENT_QUOTES)
                                : DT_NA,
        'Certified By'       => dt_esc($ctx['signatory'] ?: 'Office of the Registrar'),
        'Purpose'            => dt_esc($r['purpose'] ?? null),
    ]);

    $out .= '<p style="text-align:center;font-style:italic;color:#445">'
         . '[ The certified copy is reproduced on the following page and bears this '
         . 'certification on every page. ]</p>';
    $out .= '</div>';

    $out .= dt_auth_block($r, 'Certified this ' . date('F d, Y') . '.', $ctx['signatory']);
    $out .= dt_verify_strip($r, $ctx['meta']);
    return $out;
}

// ─────────────────────────────────────────────────────────────
//  DEFERRED BODIES  (N/A content, owned by another department)
//
//  The document still EXISTS and still follows the full walk-in
//  process — fee, queue, record copy, retention. Only the
//  department-owned content is deferred, and it prints as N/A.
//
//  There is deliberately no on-document "produced by department X"
//  notice. A Certificate of Enrollment carrying a disclaimer that the
//  Office of the Registrar does not produce it contradicts the
//  signature block directly beneath it, and it is internal system
//  plumbing that means nothing to the student or employer holding the
//  document. The ownership is recorded in DEPARTMENTS.md instead.
// =================================────────────────────────────

// ─────────────────────────────────────────────────────────────
//  4. CERTIFICATE OF ENROLLMENT  ·  DOC-COE
//     Content owned by Enrollment Management System #291.
// ============================================================

function dt_coe(array $ctx): string
{
    $s = $ctx['student'];
    $r = $ctx['request'];

    $out  = dt_letterhead($ctx['logo_url']);
    $out .= dt_title('Certificate of Enrollment', $r, $ctx['catalog']);
    $out .= dt_strip([
        'Student Name' => dt_cert_name($s),
        'Student No.'  => $s['student_number'] ?? null,
    ]);

    $out .= '<div class="dt-body">';
    $out .= dt_strip([
        'Enrollment Data'    => null,
        'Enrollment Date'    => null,
        'Program / Year / Section' => null,
        'Purpose'            => $r['purpose'] ?? null,
    ]);
    $out .= '</div>';

    $out .= dt_auth_block($r, 'Issued this ' . date('F d, Y') . ' at the Office of the Registrar.', $ctx['signatory']);
    $out .= dt_verify_strip($r, $ctx['meta']);
    return $out;
}

// ─────────────────────────────────────────────────────────────
//  5. DIPLOMA REPLACEMENT  ·  DOC-DIPLOMA
//     Not assigned to any department. Body N/A; the affidavit
//     upload DOES populate — requirement_file_path is real.
// ============================================================

function dt_diploma(array $ctx): string
{
    $s = $ctx['student'];
    $r = $ctx['request'];

    $out  = dt_letterhead($ctx['logo_url']);
    $out .= dt_title('Replacement Diploma', $r, $ctx['catalog']);
    $out .= dt_strip([
        'Student Name' => dt_cert_name($s),
        'Student No.'  => $s['student_number'] ?? null,
    ]);

    $out .= '<div class="dt-body">';
    $out .= '<p>This is to certify that <strong>' . htmlspecialchars(dt_cert_name($s), ENT_QUOTES)
         . '</strong> is a graduate of Bestlink College of the Philippines, having '
         . 'completed the degree program of ' . dt_esc($s['course'] ?? null) . '.</p>';

    $out .= dt_strip([
        'Program Awarded'        => $s['course'] ?? null,
        'Original Diploma No.'   => null,
        'Date of Graduation'     => $s['graduation_date'] ?? null,
        'Date of Original Issuance' => null,
        'Replacement Serial No.' => null,
    ]);
    $out .= '<hr style="border:0;border-top:1px solid #e2e8f0;margin:12px 0">';
    $out .= dt_strip([
        'Reason for Replacement' => $r['purpose'] ?? null,
        // Real: the counter captures the notarized affidavit of loss.
        'Affidavit of Loss'      => ($r['requirement_file_path'] ?? '') !== ''
                                    ? 'On file at the Office of the Registrar'
                                    : null,
        'Certified By'           => $ctx['signatory'] ?: 'Office of the Registrar',
    ]);
    $out .= '<p>This issuance is recorded in the Office of the Registrar.</p>';
    $out .= '</div>';

    $out .= dt_auth_block($r, 'Issued this ' . date('F d, Y') . '.', $ctx['signatory']);
    $out .= dt_verify_strip($r, $ctx['meta']);
    return $out;
}

// ─────────────────────────────────────────────────────────────
//  6. HONORABLE DISMISSAL  ·  DOC-HD
//     Not assigned to a department. The three-office EXIT CLEARANCE
//     block that used to sit here has been removed with the rest of the
//     feature; the good-moral checks below are the whole record now.
// =================================────────────────────────────

function dt_honorable_dismissal(array $ctx): string
{
    $s = $ctx['student'];
    $r = $ctx['request'];
    $cl = $ctx['clearances'] ?? [];

    $out  = dt_letterhead($ctx['logo_url']);
    $out .= dt_title('Honorable Dismissal', $r, $ctx['catalog']);
    $out .= dt_strip([
        'Student Name' => dt_cert_name($s),
        'Student No.'  => $s['student_number'] ?? null,
    ]);

    $out .= '<div class="dt-body">';
    $out .= '<p>This is to certify that <strong>' . htmlspecialchars(dt_cert_name($s), ENT_QUOTES)
         . '</strong> has been honorably dismissed from Bestlink College of the '
         . 'Philippines.</p>';
    $out .= '<p>The student was duly admitted to the ' . dt_esc($s['course'] ?? null)
         . ' program and, after completing all academic and administrative requirements '
         . 'of the institution, is hereby transferred to another institution of learning.</p>';

    $out .= dt_strip([
        'Program'              => $s['course'] ?? null,
        'Date of Dismissal'    => null,
        'Date of Admission'    => null,
        'Units Earned'         => $ctx['total_units'] ?? null,
        'Purpose'              => $r['purpose'] ?? null,
    ]);

    $out .= '<p class="dt-hd">EXIT CLEARANCE</p>';
    $out .= dt_totals([
        'Alumni Office'   => dt_esc($cl['Alumni'] ?? null),
        'Dean Office'     => dt_esc($cl['Dean'] ?? null),
        'Property Office' => dt_esc($cl['Property'] ?? null),
        'Clearance Completed' => dt_esc($ctx['clearance_done'] ?? null),
    ]);
    $out .= '</div>';

    $out .= dt_auth_block($r, 'Issued this ' . date('F d, Y') . '.', $ctx['signatory']);
    $out .= dt_verify_strip($r, $ctx['meta']);
    return $out;
}

// ─────────────────────────────────────────────────────────────
//  7. COURSE DESCRIPTION  ·  DOC-CD
//     Content owned by Curriculum & Subject Management #293.
//     academic_grades has every column needed EXCEPT syllabus
//     text, which does not exist anywhere in the schema.
// =================================────────────────────────────

function dt_course_description(array $ctx): string
{
    $s = $ctx['student'];
    $r = $ctx['request'];
    $cd = $ctx['course_desc'] ?? [];

    $out  = dt_letterhead($ctx['logo_url']);
    $out .= dt_title('Course Description', $r, $ctx['catalog']);
    $out .= dt_strip([
        'Student Name' => dt_cert_name($s),
    ]);

    $out .= '<div class="dt-body">';
    $out .= dt_strip([
        'Course Code'    => $cd['subject_code'] ?? null,
        'Course Title'   => $cd['subject'] ?? null,
        'Units'          => $cd['units'] ?? null,
        'Term'           => $cd['semester_taken'] ?? null,
    ]);

    $out .= '<p class="dt-hd" style="margin-top:12px">COURSE DESCRIPTION</p>';
    // No syllabus_text column exists in any table — this is
    // permanently N/A, and prints inside its own bordered block so
    // nobody mistakes an absent syllabus for a missing section.
    $out .= '<div class="dt-nabody">' . DT_NA . '</div>';

    $out .= dt_strip([
        'Prerequisite'  => $cd['prerequisite'] ?? null,
        'Subject Type'  => $cd['subject_type'] ?? null,
        'Instructor'    => $cd['instructor'] ?? null,
        'Schedule'      => $cd['schedule'] ?? null,
        'Room'          => $cd['room'] ?? null,
        'Midterm Grade' => $cd['midterm_grade'] ?? null,
        'Final Grade'   => $cd['final_grade'] ?? null,
        'Grade Status'  => $cd['grade_status'] ?? null,
        'Remarks'       => $cd['remarks'] ?? null,
        'Purpose'       => $r['purpose'] ?? null,
    ]);
    $out .= '</div>';

    $out .= dt_auth_block($r, 'Issued this ' . date('F d, Y') . '.', $ctx['signatory']);
    $out .= dt_verify_strip($r, $ctx['meta']);
    return $out;
}

// ─────────────────────────────────────────────────────────────
//  DISPATCHER
// =================================────────────────────────────

/** SKU → renderer. */
function dt_render(array $ctx): string
{
    $sku = strtoupper(trim((string) ($ctx['catalog']['sku'] ?? '')));
    switch ($sku) {
        case 'DOC-TOR':      return dt_tor($ctx);
        case 'DOC-COE':      return dt_coe($ctx);
        case 'DOC-GM':       return dt_good_moral($ctx);
        case 'DOC-DIPLOMA':  return dt_diploma($ctx);
        case 'DOC-CTC':      return dt_ctc($ctx);
        case 'DOC-HD':       return dt_honorable_dismissal($ctx);
        case 'DOC-CD':       return dt_course_description($ctx);
    }
    // Unknown SKU: still print a complete document rather than
    // failing — the record copy must exist for every request.
    $name = $ctx['catalog']['name'] ?? 'Official Document';
    $out  = dt_letterhead($ctx['logo_url']);
    $out .= dt_title((string) $name, $ctx['request'], $ctx['catalog']);
    $out .= dt_strip(['Student Name' => dt_cert_name($ctx['student'])]);
    $out .= '<div class="dt-body"><div class="dt-nabody">' . DT_NA . '</div></div>';
    $out .= dt_auth_block($ctx['request'], 'Issued this ' . date('F d, Y') . '.', $ctx['signatory']);
    $out .= dt_verify_strip($ctx['request'], $ctx['meta'] ?? []);
    return $out;
}

/** A complete standalone HTML document for the print window. */
function dt_standalone_html(array $ctx, string $title = ''): string
{
    $title = $title !== '' ? $title : (string) ($ctx['catalog']['name'] ?? 'Official Document');
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
        . '<style>' . dt_stylesheet()
        . 'html,body{background:#fff;margin:0;padding:0;}'
        // The sheet is the only thing on the page; nothing may follow it
        // onto a second sheet.
        . '@media print{html,body{height:auto;overflow:hidden;}}'
        . '</style></head><body><div class="dt-doc">'
        . dt_render($ctx)
        . '</div>'
        // Fit-to-one-page runs only in the print window.
        . dt_autofit_script()
        . '</body></html>';
}

// ─────────────────────────────────────────────────────────────
//  CONTEXT COLLECTOR  (read-only)
//
//  Gathers everything a template needs. Every lookup tolerates a
//  pre-migration schema: the walk-in columns do not exist until
//  migrations/document_walkin_only.sql is applied, and this file
//  must not fatal on a database that predates it.
// =================================────────────────────────────

/** Which of the given columns actually exist on a table. */
function dt_existing_columns($db, string $table, array $wanted): array
{
    try {
        $rows = $db->fetchAll('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
    } catch (Throwable $e) {
        return [];
    }
    return array_values(array_intersect($wanted, array_column($rows, 'Field')));
}
/**
 * Build the render context for one document request.
 *
 * @param int   $requestId
 * @param array $opt  'app_root' for asset URLs, 'signatory' override
 */
function dt_collect_context(int $requestId, array $opt = []): ?array
{
    $db = Database::getInstance();

    // Build the select list from columns that actually exist. A
    // database that predates any given migration must still render —
    // a missing column is a reason to print N/A, never to fatal.
    $studentCols = ['first_name', 'middle_name', 'last_name', 'name_suffix',
                    'student_number', 'course', 'year_level',
                    'school_year', 'semester', 'status', 'previous_school',
                    'last_year_level_completed', 'graduation_date'];
    $present = dt_existing_columns($db, 'students', $studentCols);

    $want = ['dr.*', 'c.sku', 'c.name AS catalog_name', 'c.description',
             'c.base_fee', 'c.fee_type', 'c.requirement'];
    foreach ($present as $col) {
        $want[] = 's.' . $col;
    }

    $row = $db->fetchOne(
        'SELECT ' . implode(', ', $want) . '
           FROM document_requests dr
           LEFT JOIN document_catalog c ON c.id = dr.catalog_id
           LEFT JOIN students s       ON s.id = dr.student_id
          WHERE dr.id = ?',
        [$requestId]
    );
    if (!$row) {
        return null;
    }
    $studentId = (int) ($row['student_id'] ?? 0);

    // ── Terms + subjects (TOR) ─────────────────────────────
    $terms = [];
    foreach ($db->fetchAll(
        'SELECT * FROM academic_history WHERE student_id = ?
          ORDER BY IFNULL(school_year,""), IFNULL(semester,""), id',
        [$studentId]
    ) as $t) {
        $t['subjects'] = $db->fetchAll(
            'SELECT subject, subject_code, units, final_rating, grade, remarks
               FROM academic_grades WHERE academic_history_id = ?
              ORDER BY subject_code, subject',
            [(int) $t['id']]
        );
        $terms[] = $t;
    }
    // Weighted GWA over numeric final ratings. NULL when nothing
    // numeric exists, so the template prints N/A rather than a
    // fabricated 0.00.
    $weighted = 0.0; $units = 0.0;
    foreach ($terms as $t) {
        foreach ($t['subjects'] as $sub) {
            $fr = (float) ($sub['final_rating'] ?? 0);
            $un = (float) ($sub['units'] ?? 0);
            if ($fr > 0 && $un > 0) { $weighted += $fr * $un; $units += $un; }
        }
    }
    $careerGwa = $units > 0 ? round($weighted / $units, 4) : null;

    $totalUnits = 0.0; $anyUnits = false;
    foreach ($terms as $t) {
        $c = $t['credits'] ?? null;
        if ($c !== null && $c !== '') { $totalUnits += (float) $c; $anyUnits = true; }
    }

    // -- Good moral checks ----------------------------------
    $balance = $db->fetchColumn('SELECT balance FROM finance WHERE student_id = ?', [$studentId]);

    // An absent disciplinary module is NOT a verified zero. Until
    // discipline_records exists (or has rows) these stay N/A — a
    // signed certificate must not assert a fact never checked.
    $hasDiscipline = false;
    $openCases = 0;
    try {
        $hasDiscipline = (int) $db->fetchColumn(
            'SELECT COUNT(*) FROM discipline_records WHERE student_id = ?', [$studentId]
        ) > 0;
        if ($hasDiscipline) {
            $openCases = (int) $db->fetchColumn(
                "SELECT COUNT(*) FROM discipline_records
                  WHERE student_id = ? AND status = 'pending'",
                [$studentId]
            );
        }
    } catch (Throwable $e) {
        $hasDiscipline = false;   // not migrated yet
    }

    $moralChecks = [
        'Disciplinary Cases'   => $hasDiscipline ? (string) $openCases : null,
        'Outstanding Balance'   => $balance === null ? null : dt_money($balance),
        'Administrative Hold'   => in_array($row['status'] ?? '', ['at-risk', 'dropped'], true) ? 'Yes' : 'No',
    ];

    // -- Exit clearances -------------------------------------
    //     Exit clearance has been removed, so there is no
    //     exit_clearances table to read and no signature block to
    //     print. The template's N/A convention carries the rest: the
    //     document still shows its full structure, so a missing
    //     section reads as "no data" rather than a broken render.
    $clearances = [];
    $allCleared = null;

    // -- CTC source -----------------------------------------
    $ctc = [];
    $ctcDocId = (int) ($row['ctc_document_id'] ?? 0);
    if ($ctcDocId > 0) {
        $d = $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$ctcDocId]);
        if ($d) {
            $ctc = [
                'doc_name'      => $d['filename'] ?? null,
                'control_no'    => basename((string) ($d['file_path'] ?? '')),
                'date_original' => $d['created_at'] ?? null,
                'sha'           => $d['file_sha256'] ?? null,
                'pages'         => 1,
            ];
        }
    }

    // -- Course description source (first grade row) ---------
    $cd = [];
    $g = $db->fetchOne('SELECT * FROM academic_grades LIMIT 1');
    if ($g && (int) ($g['academic_history_id'] ?? 0) > 0) {
        $ownerTerm = $db->fetchOne(
            'SELECT student_id FROM academic_history WHERE id = ?',
            [(int) $g['academic_history_id']]
        );
        if ((int) ($ownerTerm['student_id'] ?? 0) === $studentId) {
            $cd = $g;
        }
    }

    // -- Provenance -----------------------------------------
    // The walk-in columns arrive with the migration, so read them
    // only when present.
    $walkinBy = '';
    $releaseBy = '';
    if (dt_existing_columns($db, 'document_requests', ['walkin_at', 'walkin_by'])) {
        $wid = (int) ($row['walkin_by'] ?? 0);
        if ($wid > 0) {
            $walkinBy = (string) $db->fetchColumn('SELECT full_name FROM users WHERE id = ?', [$wid]);
        }
        $rBy = (int) ($row['released_by'] ?? 0);
        if ($rBy > 0) {
            $releaseBy = (string) $db->fetchColumn('SELECT full_name FROM users WHERE id = ?', [$rBy]);
        }
    }

    // Tickets linked to this request. One request legitimately
    // spans TWO visits (file it, then collect it days later), so
    // this is a list, never a single number.
    $tickets = [];
    try {
        foreach ($db->fetchAll(
            'SELECT ticket_number FROM queue_tickets
              WHERE document_request_id = ? ORDER BY id',
            [$requestId]
        ) as $t) {
            $tickets[] = '#' . str_pad((string) $t['ticket_number'], 3, '0', STR_PAD_LEFT);
        }
    } catch (Throwable $e) {
        $tickets = [];
    }

    $signatory = trim((string) ($opt['signatory'] ?? ''));
    if ($signatory === '' && !empty($_SESSION['user_id'])) {
        $signatory = (string) $db->fetchColumn(
            'SELECT full_name FROM users WHERE id = ?', [$_SESSION['user_id']]
        );
    }

    // The logo is referenced RELATIVELY, not via the public domain.
    //
    // The same markup is consumed from two places: the preview modal
    // (rendered into registrar/documents.php) and the print window
    // (served from api/document-preview.php). "../assets/..." is one
    // level up from BOTH, so it resolves correctly in each — and it
    // keeps working on a local machine, where the production domain
    // is not reachable and the image would simply 404.
    $logoUrl = '../assets/images/BCP_LOGO.png';

    return [
        'request'   => $row,
        'student'   => $row,
        'catalog'   => [
            'sku'         => $row['sku'] ?? null,
            'name'        => $row['catalog_name'] ?? null,
            'description' => $row['description'] ?? null,
            'base_fee'    => $row['base_fee'] ?? null,
            'fee_type'    => $row['fee_type'] ?? null,
            'requirement' => $row['requirement'] ?? null,
        ],
        'terms'      => $terms,
        'career_gwa' => $careerGwa,
        'total_units'=> $anyUnits ? (string) $totalUnits : null,
        'tor_remarks'=> $terms ? ($terms[count($terms) - 1]['remarks'] ?? null) : null,
        'moral_checks'=> $moralChecks,
        'clearances' => $clearances,
        'clearance_done' => $allCleared,
        'ctc'        => $ctc,
        'course_desc'=> $cd,
        'signatory'  => $signatory,
        'logo_url'   => $logoUrl,
        'meta' => [
            'walkin_at'   => $row['walkin_at'] ?? null,
            'walkin_by'   => $walkinBy,
            'released_at' => $row['claimed_at'] ?? ($row['release_date'] ?? null),
            'released_by' => $releaseBy,
            'tickets'     => implode(' · ', $tickets),
            'sha256'      => $row['record_file_sha256'] ?? null,
            // The QR is drawn from qr_hash, which every request already
            // carries. An empty string means there is nothing to verify
            // yet, and the strip prints without a code.
            'qr_img'      => dt_qr_svg(dt_verify_url($row['qr_hash'] ?? null)),
            'qr_url'      => dt_verify_url($row['qr_hash'] ?? null),
        ],
    ];
}

// -------------------------------------------------------------
//  DEFINITIONS restored below the collector: these two are called
//  by every template but declared after the collector so the file
//  reads top-down (helpers ? chrome ? templates ? dispatcher ?
//  collector). PHP hoists top-level function declarations, so the
//  call order above is safe.
// ============================================================

/** Full name in the SURNAME, Given format used on certificates. */
function dt_cert_name(array $student): string
{
    $last  = trim((string) ($student['last_name'] ?? ''));
    $first = trim((string) ($student['first_name'] ?? ''));
    $mid   = trim((string) ($student['middle_name'] ?? ''));
    $sfx   = trim((string) ($student['name_suffix'] ?? ''));

    if ($last === '' && $first === '') {
        return DT_NA;
    }
    $given = trim($first . ' ' . $mid);
    $out   = $last . ($given !== '' ? ', ' . $given : '');
    return $out . ($sfx !== '' ? ' ' . $sfx : '');
}

/**
 * Verification strip: request identity, walk-in provenance and the
 * QR that resolves to /verify.php. Identical on all seven documents.
 */
function dt_verify_strip(array $request, array $meta = []): string
{
    $id  = (int) ($request['id'] ?? 0);
    $rid = (string) ($request['request_id'] ?? '');

    $walkin     = trim((string) ($meta['walkin_at'] ?? ''));
    $walkinBy   = trim((string) ($meta['walkin_by'] ?? ''));
    $released   = trim((string) ($meta['released_at'] ?? ''));
    $releasedBy = trim((string) ($meta['released_by'] ?? ''));
    $tickets    = trim((string) ($meta['tickets'] ?? ''));
    $sha        = trim((string) ($meta['sha256'] ?? ''));
    $qr         = trim((string) ($meta['qr_img'] ?? ''));

    $out  = '<div class="dt-verify">';
    $out .= '<div class="dt-vcol">';
    $out .= '<b>Official Record Copy</b>';
    $out .= 'Request: ' . dt_provenance('request_id', $rid === '' ? null : $rid, $id) . '<br>';
    $out .= 'Walked in: ' . dt_provenance('walkin_at', $walkin === '' ? null : dt_val($walkin, 'M d, Y H:i'), $id)
         . ($walkinBy !== '' ? ' (' . htmlspecialchars($walkinBy, ENT_QUOTES) . ')' : '') . '<br>';
    $out .= 'Released: ' . dt_provenance('released_at', $released === '' ? null : dt_val($released, 'M d, Y H:i'), $id)
         . ($releasedBy !== '' ? ' (' . htmlspecialchars($releasedBy, ENT_QUOTES) . ')' : '') . '<br>';
    $out .= 'Counter: ' . dt_provenance('counter', $request['counter'] ?? null, $id);
    if ($tickets !== '') {
        $out .= ' &middot; Tickets: ' . htmlspecialchars($tickets, ENT_QUOTES);
    }
    $out .= '<br>SHA-256: ' . ($sha === '' ? DT_NA : htmlspecialchars(substr($sha, 0, 12) . '…', ENT_QUOTES));
    $out .= '</div>';
    $out .= '<div class="dt-vq">' . $qr . '<div class="cap">Scan to verify</div></div>';
    $out .= '</div>';
    return $out;
}

