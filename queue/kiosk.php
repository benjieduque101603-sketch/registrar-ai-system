<?php
// ============================================================
//  QUEUE/KIOSK.PHP
//  Public self-service queue kiosk (NO login required).
//  Students tap their RFID card to get a queue number,
//  or use the tabs to see the full line / check a number.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
// For app_url() only. Deliberately NOT config.php: this page is public and
// must render on a wall display even when the database is unreachable, and
// config.php opens a mysqli connection at include time.
require_once __DIR__ . '/../shared/app_path.php';

$APP_ROOT = '../';
$page_title = 'Queue Kiosk';
// Where the API actually is, told to the client by the server that knows.
// See the note in js/queue.js: counting slashes in window.location works on
// one deployment and 404s on another.
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
<body class="queue-body" data-page="kiosk" data-api-base="<?= htmlspecialchars($api_base, ENT_QUOTES) ?>">
<div class="queue-screen">

    <div class="queue-brand">
        <img src="<?= $APP_ROOT ?>assets/images/BCP_LOGO.png" alt="BCP Logo" />
        <div>
            <h1>BCP Registrar — Queue Kiosk</h1>
            <p>Tap your student card to join the line</p>
        </div>
    </div>

    <div class="q-tabs">
        <button class="q-tab-btn" data-tab="tap"><i class="fas fa-credit-card"></i> Tap Card</button>
        <button class="q-tab-btn" data-tab="board"><i class="fas fa-list-ol"></i> Full Queue</button>
        <button class="q-tab-btn" data-tab="standing"><i class="fas fa-magnifying-glass"></i> Check my number</button>
    </div>

    <!-- TAP screen -->
    <div id="screen-tap">
        <div class="tap-card">
            <div class="tap-icon"><i class="fas fa-credit-card"></i></div>
            <h2>Tap your student card</h2>
            <p>Hold your card on the reader to get a queue number.</p>
            <div class="hint">Your name will appear next to your number on the board.</div>
        </div>
    </div>

    <!-- RESULT screen -->
    <div id="screen-result" style="display:none;">
        <div class="result-card" id="resultCard">
            <div class="result-icon success" id="rIcon" style="display:none;"><i class="fas fa-circle-check"></i></div>
            <div class="ticket-number" id="rNumber" style="display:none;"></div>
            <div class="ticket-name" id="rName" style="display:none;"></div>
            <div class="ticket-sub" id="rSub"></div>
        </div>
    </div>

    <!-- FULL QUEUE board -->
    <div id="screen-board" style="display:none;">
        <div class="q-panel">
            <h3><i class="fas fa-list-ol"></i> Current line</h3>
            <div class="q-grid" id="boardList">
                <div style="color:#64748b;text-align:center;padding:24px;grid-column:1/-1;">Loading…</div>
            </div>
        </div>
    </div>

    <!-- STANDING screen -->
    <div id="screen-standing" style="display:none;">
        <div class="q-panel">
            <h3><i class="fas fa-magnifying-glass"></i> Check my number</h3>
            <div class="standing-input-wrap">
                <input type="text" id="standingNumber" inputmode="numeric" placeholder="Enter number, e.g. 001" />
                <button class="q-tab-btn active" id="standingCheck" style="border-radius:12px;">Check</button>
            </div>
            <div id="standingResult"></div>
        </div>
    </div>

</div>

<!-- Hidden RFID capture input: reader types the UID and presses Enter -->
<input type="text" id="cardInput" class="q-hidden-input" autocomplete="off" />

<script src="<?= $APP_ROOT ?>js/queue.js?v=<?= filemtime(__DIR__ . '/../js/queue.js') ?>"></script>
</body>
</html>
