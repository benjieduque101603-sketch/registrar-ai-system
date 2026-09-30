<?php
// ============================================================
//  QUEUE/MONITOR.PHP
//  Public on-site queue monitor (TV display, NO login required).
//  Shows now-serving + the full waiting line, refreshes every 3 s.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
// For app_url() only. Deliberately NOT config.php: this display must render
// even when the database is unreachable, and config.php opens a mysqli
// connection at include time.
require_once __DIR__ . '/../shared/app_path.php';

$APP_ROOT = '../';
$page_title = 'Queue Monitor';
// Where the API actually is, told to the client by the server that knows.
$api_base = rtrim(app_url('/api'), '/') . '/';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($page_title) ?> — BCP Registrar System</title>
    <link rel="icon" type="image/x-icon" href="<?= $APP_ROOT ?>assets/images/favicon.ico" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="<?= $APP_ROOT ?>css/queue.css" />
</head>
<body class="monitor-body" data-page="monitor" data-api-base="<?= htmlspecialchars($api_base, ENT_QUOTES) ?>">
<div class="monitor-screen">

    <div class="monitor-top">
        <div class="brand">
            <img src="<?= $APP_ROOT ?>assets/images/BCP_LOGO.png" alt="BCP Logo" />
            <h1>Registrar Queue</h1>
        </div>
        <div class="clock" id="clock"></div>
    </div>

    <!-- NOW SERVING — one slot per window -->
    <div class="window-strip" id="windowStrip"></div>

    <div class="monitor-cols">
        <div>
            <div class="monitor-col-title"><i class="fas fa-list-ol"></i> Waiting line</div>
            <div class="monitor-wait-list" id="waitList">
                <div style="color:#64748b;text-align:center;padding:16px;">Loading…</div>
            </div>
        </div>
        <div>
            <div class="monitor-col-title"><i class="fas fa-clock-rotate-left"></i> Recently served</div>
            <div class="monitor-recent-list" id="recentList">
                <div style="color:#64748b;text-align:center;padding:16px;">Loading…</div>
            </div>
        </div>
    </div>

</div>

<script src="<?= $APP_ROOT ?>js/queue.js?v=<?= filemtime(__DIR__ . '/../js/queue.js') ?>"></script>
</body>
</html>