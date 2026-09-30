<?php
// ============================================================
//  HEADER.PHP  (includes/)
//  Reusable page header with meta tags, CSS, and loader.
//  Include this at the top of every page after session config.
//
//  Usage:
//    $page_title = 'Dashboard';  // Optional - sets page title
//    $page_description = '...';  // Optional - sets meta description
//    include 'includes/header.php';
// ============================================================

// Set default values if not defined
$page_title = $page_title ?? 'BCP Registrar System';
$page_description = $page_description ?? 'AI-Powered Registrar Management System';
$APP_ROOT = $APP_ROOT ?? './';

// CSRF guard: loads session config, exposes csrfToken(), and enforces
// tokens on every non-safe HTTP method when a page posts server-side.
if (!function_exists('csrfToken')) {
    require_once __DIR__ . '/../shared/csrf_guard.php';
}
$__csrfToken = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" />
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>" />
    <meta name="theme-color" content="#1a3a8c" />
    <meta name="mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
    
    <title><?= htmlspecialchars($page_title) ?> — BCP Registrar System</title>
    <meta name='csrf-token' content='<?= $__csrfToken ?>' />

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= $APP_ROOT ?>assets/images/BCP_LOGO.png" />
    <link rel="icon" type="image/x-icon" href="<?= $APP_ROOT ?>assets/images/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $APP_ROOT ?>assets/images/apple-touch-icon.png" />

    <!-- Loader Meta -->
    <meta name="loader-logo" content="<?= $APP_ROOT ?>assets/images/BCP_LOGO.png" />

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet" />

    <!-- CSS Files -->
    <link rel="stylesheet" href="<?= $APP_ROOT ?>css/page-loader.css" />
    <link rel="stylesheet" href="<?= $APP_ROOT ?>css/components.css" />
    <link rel="stylesheet" href="<?= $APP_ROOT ?>css/sidebar.css" />
    <link rel="stylesheet" href="<?= $APP_ROOT ?>css/registrar.css" />
    <link rel="stylesheet" href="<?= $APP_ROOT ?>css/registrar-premium.css" />
    <link rel="stylesheet" href="<?= $APP_ROOT ?>css/dashboard.css" />

    <!-- Confirmation dialog (replaces native confirm) - MUST come before
         any page script that might call confirmAction() -->
    <script src="<?= $APP_ROOT ?>js/confirm.js?v=<?= is_file(__DIR__ . '/../js/confirm.js') ? filemtime(__DIR__ . '/../js/confirm.js') : time() ?>"></script>

    <!-- Loader Script - MUST BE FIRST -->
    <script src='<?= $APP_ROOT ?>js/csrf.js'></script>
    <script src="<?= $APP_ROOT ?>js/page-loader.js"></script>

    <!-- Extra CSS for specific pages -->
    <?php if (isset($extra_css)): ?>
        <?php foreach ($extra_css as $css_file): ?>
            <?php
            $_cssPath = __DIR__ . '/../css/' . $css_file;
            $_cssVer  = (is_file($_cssPath)) ? '?v=' . filemtime($_cssPath) : '';
            ?>
            <link rel="stylesheet" href="<?= $APP_ROOT ?>css/<?= $css_file ?><?= $_cssVer ?>" />
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Custom page styles -->
    <?php if (isset($page_styles)): ?>
        <style>
            <?= $page_styles ?>
        </style>
    <?php endif; ?>
    <?php
    // Where the API actually is, told to the client by the server that knows.
    // The serving console talks to api/queue.php, and js/queue.js must not guess
    // that path from the URL: the guess (counting slashes) resolves to
    // /registrar/api/queue.php on a root-mounted host, which 404s. That is why a
    // ticket issued at the kiosk never appeared on the console - every state
    // poll failed and the console sat on a stale/empty list. See js/queue.js.
    //
    // app_path.php is safe to include here and is deliberately NOT config.php:
    // it defines no DB constants and opens no connection.
    require_once __DIR__ . '/../shared/app_path.php';
    $app_api_base = rtrim(app_url('/api'), '/') . '/';
    ?>
</head>
<body<?= !empty($body_page) ? ' data-page="' . htmlspecialchars($body_page) . '"' : '' ?> data-api-base="<?= htmlspecialchars($app_api_base, ENT_QUOTES, 'UTF-8') ?>">
