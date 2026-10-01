<?php
// Gmail OAuth Setup — run ONCE to get refresh token.
require_once __DIR__ . '/shared/security_headers.php';
require_once __DIR__ . '/shared/session_config.php';
require_once __DIR__ . '/shared/config.php';
$clientID = GMAIL_API_CLIENT_ID;
$clientSecret = GMAIL_API_CLIENT_SECRET;
$redirectUri = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
$tokenResult = null;
$tokenError = null;
if (isset($_GET['code'])) {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $_GET['code'], 'client_id' => $clientID,
            'client_secret' => $clientSecret, 'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    $data = json_decode($resp, true);
    if (!empty($data['refresh_token'])) { $tokenResult = $data['refresh_token']; }
    else { $tokenError = $data['error_description'] ?? $data['error'] ?? 'Unknown'; }
} elseif (isset($_GET['error'])) {
    $tokenError = $_GET['error_description'] ?? $_GET['error'];
}
$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => $clientID, 'redirect_uri' => $redirectUri,
    'response_type' => 'code', 'scope' => 'https://www.googleapis.com/auth/gmail.send',
    'access_type' => 'offline', 'prompt' => 'consent',
]);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Gmail OAuth Setup</title>
<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:system-ui,sans-serif;background:#f1f5f9;color:#1e293b;padding:40px 20px}.ct{max-width:680px;margin:0 auto}h1{font-size:22px;margin-bottom:8px}.d{color:#64748b;font-size:14px;margin-bottom:24px;line-height:1.6}.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;margin-bottom:20px}.card h2{font-size:16px;margin-bottom:12px}.s{margin-bottom:10px;padding:10px 12px;background:#f8fafc;border-radius:8px;border-left:3px solid #2563eb;font-size:14px}.s b{color:#2563eb}code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:13px}.pre{background:#1e293b;color:#e2e8f0;padding:14px;border-radius:8px;font-size:13px;overflow-x:auto;white-space:pre;line-height:1.5;margin-top:8px}.btn{display:inline-block;padding:12px 24px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;text-decoration:none}.btn:hover{background:#1d4ed8}.ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;border-radius:8px;padding:14px;margin-top:12px}.err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:8px;padding:14px;margin-top:12px}.sm{font-size:12px;color:#94a3b8;margin-top:4px}</style></head><body><div class="ct">
<h1>Gmail OAuth2 Setup</h1>
<p class="d">One-time setup. After this, emails go through Gmail API — no more SMTP bounces.</p>

<div class="card"><h2>Step 1 — Google Cloud Console</h2>
<div class="s"><b>1a.</b> Go to <a href="https://console.cloud.google.com/" target="_blank">console.cloud.google.com</a>, create or select a project.</div>
<div class="s"><b>1b.</b> Enable <strong>Gmail API</strong>: APIs &amp; Services → Library → search "Gmail" → Enable.</div>
<div class="s"><b>1c.</b> Create OAuth2 credentials: Credentials → Create Credentials → OAuth client ID → <strong>Web application</strong>.</div>
<div class="s"><b>1d.</b> Add as <strong>Authorized redirect URI</strong>:<br><code><?= htmlspecialchars($redirectUri) ?></code></div>
<div class="s"><b>1e.</b> Copy the <strong>Client ID</strong> and <strong>Client Secret</strong>.</div>
</div>
<div class="card"><h2>Step 2 — Save to email_secret.local</h2>
<div class="pre">GMAIL_API_CLIENT_ID=your-client-id.apps.googleusercontent.com
GMAIL_API_CLIENT_SECRET=GOCSPX-your-client-secret
GMAIL_SENDER_EMAIL=roldantiu89@gmail.com</div>
<p class="sm">Save, then reload this page and do Step 3.</p>
</div>
<div class="card"><h2>Step 3 — Authorize &amp; Get Refresh Token</h2>
<?php if ($tokenResult): ?>
<div class="ok"><strong>Success!</strong> Copy this into <code>email_secret.local</code>:</div>
<div class="pre" style="margin-top:12px">GMAIL_REFRESH_TOKEN=<?= htmlspecialchars($tokenResult) ?></div>
<p style="font-size:14px;color:#64748b;margin-top:12px">Done! Emails now go through Gmail API.</p>
<?php elseif ($tokenError): ?>
<div class="err"><strong>Failed:</strong> <?= htmlspecialchars($tokenError) ?></div>
<?php elseif ($clientID === '' || $clientSecret === ''): ?>
<div class="err"><strong>Missing credentials.</strong> Add GMAIL_API_CLIENT_ID and GMAIL_API_CLIENT_SECRET to <code>email_secret.local</code>, then reload.</div>
<?php else: ?>
<p style="font-size:14px;color:#64748b;margin-bottom:14px">Click to authorize sending as <strong><?= htmlspecialchars(GMAIL_SENDER_EMAIL) ?></strong>.</p>
<a href="<?= htmlspecialchars($authUrl) ?>" class="btn">Authorize with Google</a>
<p class="sm" style="margin-top:12px">You'll be redirected back with a refresh token.</p>
<?php endif; ?>
</div>
<div class="card"><h2>Final email_secret.local</h2>
<div class="pre"># Brevo (recommended - no SMTP password needed, better bounce handling)
BREVO_API_KEY=xkeysib-your-brevo-key
MAIL_FROM=no-reply@your-domain
MAIL_FROM_NAME=BCP Registrar System

# SMTP fallback ONLY (Gmail requires an App Password, not your login password)
# SMTP_HOST=smtp.gmail.com
# SMTP_PORT=587
# SMTP_USER=you@gmail.com
# SMTP_PASS=xxxx xxxx xxxx xxxx

# Gmail OAuth2 alternative
# GMAIL_API_CLIENT_ID=your-client-id
# GMAIL_API_CLIENT_SECRET=your-secret
# GMAIL_REFRESH_TOKEN=your-refresh-token
# GMAIL_SENDER_EMAIL=you@gmail.com</div>
<p class="sm">Never commit real credentials. This file writes to
shared/email_secret.local, which is gitignored.</p>
<p class="sm"><b>Security:</b> if a real credential was ever pasted into this
file, or into registrar/smtp-debug.php (now deleted), rotate it at the provider
immediately &mdash; rotating is the only reliable remediation.</p>
</div>
</div></body></html>