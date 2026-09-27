<?php
// ============================================================
//  API/DOCUMENT-PREVIEW.PHP
//  Registrar — render a document record copy for preview/print.
//
//    GET ?id=N            → HTML fragment (the preview modal)
//    GET ?id=N&print=1    → standalone HTML for the print window
//
//  Rendered by shared/document_templates.php, so the on-screen
//  preview and the printed page come from one source and cannot
//  drift apart.
//
//  Read-only: this endpoint never writes. Persisting the record
//  copy (and attaching the registrar's signed scan-back) is
//  api/generate-record-file.php's job.
// ============================================================

header('Content-Type: text/html; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/document_templates.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo 'Unauthorized.';
    exit;
}
if (!in_array(getCurrentUserRole(), ['admin', 'registrar'], true)) {
    http_response_code(403);
    echo 'Forbidden.';
    exit;
}

$requestId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($requestId <= 0) {
    http_response_code(400);
    echo 'A valid request id is required.';
    exit;
}

$ctx = dt_collect_context($requestId, ['app_root' => '../']);
if ($ctx === null) {
    http_response_code(404);
    echo 'Document request not found.';
    exit;
}

// Never leak a draft or a rejected request's template outside the
// office: only rows that actually reached a printable state are
// previewable. A pending request can be inspected from its row
// detail instead.
$status = (string) ($ctx['request']['document_status'] ?? '');

try {
    if (isset($_GET['print'])) {
        $title = (string) ($ctx['catalog']['name'] ?? 'Official Document');
        echo dt_standalone_html($ctx, $title);
        exit;
    }

    // Fragment: the caller supplies the page stylesheet.
    //
    // The .dt-doc wrapper is NOT optional here. dt_standalone_html() adds it
    // for the print window, so the two paths looked equivalent, but the
    // stylesheet hangs the entire page shape off it - the 190mm width cap,
    // the sheet padding, the border-box reset. Emitting dt_render() bare
    // left every one of those rules matching nothing, so the preview
    // rendered as an unstyled full-bleed run of markup while the printed
    // page came out correctly. Same template, two different documents.
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
        . dt_stylesheet() . '</style></head>'
        . '<body><div class="dt-doc">' . dt_render($ctx) . '</div>'
        . '</body></html>';
} catch (Throwable $e) {
    error_log('document-preview: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo 'Could not render this document.';
}
