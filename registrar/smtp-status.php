<?php
// ============================================================
//  REGISTRAR/SMTP-STATUS.PHP
//  SMTP diagnostic page (admin only). Shows whether email is
//  configured, where the credentials come from, and offers two
//  live tests: SMTP connection + a real test email. Passwords
//  are never printed.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('admin');
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

$hasVendor   = is_file(__DIR__ . '/../vendor/autoload.php');
$hasPhpMailer = false;
if ($hasVendor) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $hasPhpMailer = class_exists('PHPMailer\PHPMailer\PHPMailer');
}

/** Never print the password — scrub it from error text. */
function smtpRedact(string $text): string {
    return defined('SMTP_PASS') && SMTP_PASS !== '' ? str_replace(SMTP_PASS, '********', $text) : $text;
}

/** Which transport will actually carry a send right now. */
function smtpActiveTransport(): string {
    if (defined('BREVO_CONFIGURED') && BREVO_CONFIGURED) {
        $sender = (defined('MAIL_FROM') ? MAIL_FROM : '') ?: (defined('SMTP_USER') ? SMTP_USER : '');
        return $sender !== '' ? 'Brevo API' : 'Brevo API (no sender address)';
    }
    if (defined('GMAIL_API_CONFIGURED') && GMAIL_API_CONFIGURED) return 'Gmail API';
    if (defined('SMTP_HOST') && SMTP_HOST !== '' && defined('SMTP_USER') && SMTP_USER !== '') {
        return class_exists('PHPMailer\PHPMailer\PHPMailer') ? 'SMTP' : 'SMTP (PHPMailer not installed)';
    }
    return 'None';
}

/** Connect + authenticate only (no message is sent). */
function smtpRunConnectionTest(): array {
    $transport = smtpActiveTransport();
    // The old test only spoke SMTP, so a working Brevo-only setup reported
    // "SMTP is not configured" and looked broken when it was not.
    if (strpos($transport, 'Brevo') === 0) {
        $sender = (defined('MAIL_FROM') ? MAIL_FROM : '') ?: (defined('SMTP_USER') ? SMTP_USER : '');
        if ($sender === '') {
            return ['ok' => false, 'message' => 'Brevo API key is present, but no sender address is set. Add MAIL_FROM to an address verified in Brevo.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'The PHP cURL extension is not enabled on this host. Brevo needs cURL — enable php_curl.'];
        }
        return ['ok' => true, 'message' => 'Email is sent via the Brevo API (not SMTP). No SMTP connection test applies. Sender: ' . $sender . '. Use "Send Test Email" to verify delivery.'];
    }
    if (strpos($transport, 'Gmail') === 0) {
        return ['ok' => true, 'message' => 'Email is sent via the Gmail API (not SMTP). No SMTP connection test applies. Use "Send Test Email" to verify delivery.'];
    }
    if (!defined('SMTP_HOST') || SMTP_HOST === '') {
        return ['ok' => false, 'message' => 'No email transport is configured. Set BREVO_API_KEY (simplest) or the SMTP_* variables.'];
    }
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return ['ok' => false, 'message' => 'PHPMailer is not installed (vendor/autoload.php missing).'];
    }
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout    = 15;
        $ok = $mail->smtpConnect();
        if ($ok) {
            $mail->smtpClose();
            return ['ok' => true, 'message' => 'Connected and authenticated to ' . SMTP_HOST . ':' . SMTP_PORT . ' successfully.'];
        }
        return ['ok' => false, 'message' => smtpRedact('Connection/auth failed: ' . $mail->ErrorInfo)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => smtpRedact('Exception: ' . $e->getMessage())];
    }
}

/** Send a real test email to the configured MAIL_FROM address. */
function smtpRunSendTest(): array {
    if (!defined('EMAIL_CONFIGURED') || !EMAIL_CONFIGURED) {
        return ['ok' => false, 'message' => 'Email is not configured — add SMTP credentials via env vars or shared/email_secret.local.'];
    }
    if (!function_exists('sendEmail')) {
        $mailClient = __DIR__ . '/../shared/mail_client.php';
        if (is_file($mailClient)) require_once $mailClient;
    }
    if (!function_exists('sendEmail')) {
        return ['ok' => false, 'message' => 'shared/mail_client.php could not be loaded.'];
    }
    $to = (defined('MAIL_FROM') && MAIL_FROM !== '') ? MAIL_FROM : (SMTP_USER ?? '');
    if ($to === '') {
        return ['ok' => false, 'message' => 'No sender address configured (MAIL_FROM / SMTP_USER).'];
    }
    $ok = sendEmail(
        ['email' => $to, 'name' => defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Registrar'],
        'Registrar Email Test - ' . date('Y-m-d H:i'),
        '<p>This is a test email from the Registrar system to verify email delivery.</p>'
    );
    if ($ok) {
        return ['ok' => true, 'message' => 'Test email sent to ' . htmlspecialchars($to) . '. Check the inbox (and spam folder).'];
    }
    // Show the transport's own reason. Pointing at the PHP error log was
    // useless on shared hosting, where the admin has no way to read it —
    // the page said "test failed" and nothing else, so a simple Brevo
    // sender-verification step looked like a server-side mystery.
    $reason = trim(smtpRedact(function_exists('mailLastError') ? mailLastError() : ''));
    return ['ok' => false, 'message' => 'Test email could not be sent. '
        . ($reason !== '' ? 'Reason: ' . $reason . ' ' : '')
        . 'Each transport is tried in turn (Brevo → Gmail API → SMTP), so this message is the last failure. See the "Check transport" button for the live configuration.'];
}

// ---- handle POST actions (CSRF enforced) ----
$actionResult = null;
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'test_connection') {
        $actionResult = smtpRunConnectionTest();
    } elseif ($action === 'test_send') {
        $actionResult = smtpRunSendTest();
    } else {
        $actionResult = ['ok' => false, 'message' => 'Unknown action.'];
    }
}

// ---- diagnostics ----
// The old check only looked at SMTP_HOST, so a Brevo-only deployment
// reported "Not found (falling back to empty)" while mail was in fact
// configured and working. Probe every transport that can enable email.
$brevoSet = env('BREVO_API_KEY') !== '' && env('BREVO_API_KEY') !== false;
$envSet   = $brevoSet || (env('SMTP_HOST') !== '' && env('SMTP_HOST') !== false);
$fileSet  = is_file(__DIR__ . '/../shared/email_secret.local');
$credSource = $envSet ? 'Environment variables' : ($fileSet ? 'shared/email_secret.local' : 'Not found (falling back to empty)');
$configured = defined('EMAIL_CONFIGURED') && EMAIL_CONFIGURED;
$hasOpenssl = extension_loaded('openssl');
$hasCurl    = function_exists('curl_init');
$transport  = smtpActiveTransport();
$mailFrom   = (defined('MAIL_FROM') ? MAIL_FROM : '') ?: (defined('SMTP_USER') ? SMTP_USER : '');

$page_title = 'SMTP Status';
$APP_ROOT   = '../';
$ACTIVE_NAV = 'smtp';
$body_page  = 'smtp-status';          // scopes the admin-blue layer
$extra_css  = ['admin.css'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main">
    <div class="dashboard-container">

        <header class="adm-head">
            <div>
                <div class="adm-kicker"><i class="fas fa-envelope-open-text"></i> Outbound mail</div>
                <h1>Email delivery</h1>
                <p>Which transport carries outgoing mail, where the sender comes from, and whether the host can actually make the call.</p>
            </div>
            <div class="adm-head-actions">
                <span class="adm-chip <?= $configured ? ($transport === 'None' ? 'bad' : 'ok') : 'bad' ?>">
                    <i class="fas <?= $configured && $transport !== 'None' ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
                    <?= $configured && $transport !== 'None' ? 'Ready' : 'Not sending' ?>
                </span>
            </div>
        </header>

        <?php if ($actionResult): ?>
        <div class="adm-panel" style="margin-bottom:18px;border-left:3px solid <?= $actionResult['ok'] ? '#16a34a' : '#dc2626' ?>;">
            <div class="adm-panel-body" style="display:flex;gap:11px;align-items:flex-start;">
                <i class="fas <?= $actionResult['ok'] ? 'fa-circle-check' : 'fa-circle-xmark' ?>" style="font-size:17px;margin-top:1px;color:<?= $actionResult['ok'] ? '#16a34a' : '#dc2626' ?>;"></i>
                <div>
                    <div style="font-weight:700;font-size:13px;color:#172554;"><?= $actionResult['ok'] ? 'Test passed' : 'Test failed' ?></div>
                    <div style="font-size:12.5px;line-height:1.5;color:#475569;margin-top:2px;"><?= htmlspecialchars($actionResult['message']) ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="adm-stats" style="--adm-cols:3" aria-label="Mail transport summary">
            <div class="adm-stat <?= $configured && $transport !== 'None' ? 'tone-green' : 'tone-red' ?>">
                <div class="adm-stat-icon"><i class="fas fa-envelope"></i></div>
                <div><div class="adm-stat-value" style="font-size:19px;"><?= htmlspecialchars($transport) ?></div><div class="adm-stat-label">Active transport</div></div>
            </div>
            <div class="adm-stat <?= $mailFrom !== '' ? '' : 'tone-red' ?>">
                <div class="adm-stat-icon"><i class="fas fa-signature"></i></div>
                <div><div class="adm-stat-value" style="font-size:15px;overflow-wrap:anywhere;"><?= $mailFrom !== '' ? htmlspecialchars($mailFrom) : 'Not set' ?></div><div class="adm-stat-label">Sender address</div></div>
            </div>
            <div class="adm-stat <?= $hasCurl ? 'tone-green' : 'tone-red' ?>">
                <div class="adm-stat-icon"><i class="fas fa-plug"></i></div>
                <div><div class="adm-stat-value" style="font-size:19px;"><?= $hasCurl ? 'Enabled' : 'Disabled' ?></div><div class="adm-stat-label">cURL on this host</div></div>
            </div>
        </div>

        <div class="adm-panel">
            <div class="adm-panel-title"><i class="fas fa-sliders"></i> Configuration</div>
            <dl class="adm-kv">
                <dt>Credential source</dt>
                <dd class="<?= $envSet ? 'val-ok' : 'val-warn' ?>"><?= htmlspecialchars($credSource) ?></dd>
                <dt>Active transport</dt>
                <dd><strong><?= htmlspecialchars($transport) ?></strong></dd>
                <dt>Sender address</dt>
                <dd class="<?= $mailFrom !== '' ? 'val-ok' : 'val-bad' ?>"><?= $mailFrom !== ''
                        ? htmlspecialchars($mailFrom) . ' <span style="color:#64748b;font-weight:500;">(must be verified in Brevo)</span>'
                        : 'Not set — every send will be rejected. Set MAIL_FROM.' ?></dd>
                <dt>Brevo API key</dt>
                <dd class="<?= (defined('BREVO_CONFIGURED') && BREVO_CONFIGURED) ? 'val-ok' : 'val-mute' ?>">
                    <?= (defined('BREVO_CONFIGURED') && BREVO_CONFIGURED) ? 'Present' : 'Not set' ?>
                </dd>
                <dt>SMTP host</dt>
                <dd class="<?= (defined('SMTP_HOST') && SMTP_HOST !== '') ? 'val-ok' : 'val-mute' ?>">
                    <?= (defined('SMTP_HOST') && SMTP_HOST !== '') ? '<code>' . htmlspecialchars(SMTP_HOST) . ':' . intval(SMTP_PORT ?? 587) . '</code>' : 'Not used' ?>
                </dd>
                <dt>PHPMailer (vendor)</dt>
                <dd class="<?= $hasPhpMailer ? 'val-ok' : 'val-warn' ?>">
                    <?= $hasPhpMailer ? 'Installed' : 'Missing — run composer install on this host' ?>
                </dd>
                <dt>cURL extension</dt>
                <dd class="<?= $hasCurl ? 'val-ok' : 'val-bad' ?>">
                    <?= $hasCurl ? 'Enabled' : 'Disabled — the Brevo transport cannot work without it' ?>
                </dd>
                <dt>OpenSSL extension</dt>
                <dd class="<?= $hasOpenssl ? 'val-ok' : 'val-warn' ?>">
                    <?= $hasOpenssl ? 'Enabled' : 'Disabled (STARTTLS needs it)' ?>
                </dd>
            </dl>
        </div>

        <div class="adm-panel">
            <div class="adm-panel-title"><i class="fas fa-vial-circle-check"></i> Send a test</div>
            <div class="adm-panel-body">
                <form method="post" class="adm-test-row">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" name="action" value="test_connection" class="btn btn-secondary"><i class="fas fa-plug"></i> Check transport</button>
                    <button type="submit" name="action" value="test_send" class="btn btn-primary" <?= $configured ? '' : 'disabled' ?>><i class="fas fa-paper-plane"></i> Send test email</button>
                    <?php if (!$configured): ?>
                    <span style="font-size:12px;color:#b45309;"><i class="fas fa-triangle-exclamation"></i> Set BREVO_API_KEY or the SMTP_* variables, then reload.</span>
                    <?php endif; ?>
                </form>
                <p class="adm-note">A test email goes to <?= htmlspecialchars($mailFrom !== '' ? $mailFrom : 'the sender address') ?>. Check the spam folder if it does not arrive. Failures are written to the PHP error log with a <code>mail:</code> prefix.</p>
            </div>
        </div>

    </div>
</main>
<?php include '../includes/footer.php'; ?>
