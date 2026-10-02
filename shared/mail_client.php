<?php
// ============================================================
//  SHARED/MAIL_CLIENT.PHP
//  Emergency & Contacts module — email transport + senders.
//
//  Depends on PHPMailer (composer) and the SMTP_* config block in
//  shared/config.php (Gmail App Password via shared/email_secret.local).
//
//  Every sender writes a communication_log row (the Registrar audit
//  trail) and touches contact_recipients.last_emailed. When SMTP is
//  not configured (EMAIL_CONFIGURED === false) every sender no-ops and
//  returns sent=false — the app keeps working, exactly like the
//  PayMongo mock fallback. A mail failure never throws: senders
//  return the outcome and log it.
// ============================================================

if (defined('MAIL_CLIENT_LOADED')) {
    return;
}
define('MAIL_CLIENT_LOADED', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/document_pdf.php'; // buildInvoicePdf / buildTranscriptPdf
// PHPMailer is only needed for the SMTP fallback transport. The Brevo
// and Gmail API paths use cURL directly and need no composer packages.
//
// This require is GUARDED on purpose. vendor/ is gitignored, so a
// git-based deploy that forgets `composer install` has no autoload.php —
// and an unguarded require_once on a missing file is a FATAL error, not
// a warning. That took the whole mail module (and any page that sent
// mail) down on a host where Brevo alone was configured and working.
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    error_log('mail: vendor/autoload.php missing — run composer install. Brevo/Gmail API still work; SMTP is disabled.');
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/** True when SMTP creds are present — flips the module on. */
function emailConfigured(): bool {
    return defined('EMAIL_CONFIGURED') && EMAIL_CONFIGURED;
}

/**
 * Record why the last send failed, so the diagnostic page can show the real
 * reason instead of "see the error log". The user has no shell access on
 * shared hosting, so an error that only lives in the PHP log is invisible to
 * them — which is exactly how a broken setup ends up looking "configured but
 * silently failing" for hours.
 */
function mailSetLastError(string $transport, string $detail): void {
    $GLOBALS['MAIL_LAST_ERROR'] = $transport . ' — ' . $detail;
}

/** The recorded failure reason for the most recent send attempt. */
function mailLastError(): string {
    return (string) ($GLOBALS['MAIL_LAST_ERROR'] ?? '');
}

/**
 * Low-level email send — prefers Brevo API, then Gmail API, then SMTP.
 * HTML body, UTF-8. One optional attachment: ['data' => string, 'name' => string, 'mime' => string|null].
 * Returns true on successful send; failure is error_logged.
 */
function sendEmail(array $to, string $subject, string $htmlBody, ?array $attachment = null): bool {
    if (!emailConfigured()) {
        error_log('mail: EMAIL_CONFIGURED is false — refusing to send to ' . ($to['email'] ?? ''));
        return false;
    }

    // ── Brevo API path (preferred — reliable, no bounce issues) ──
    // A failure here does NOT end the attempt. Previously any Brevo error
    // (unverified sender, wrong key, transient 5xx) returned false straight
    // away, so a perfectly good SMTP fallback underneath was never tried and
    // the admin only saw "Test failed". We now remember why and keep going.
    if (BREVO_CONFIGURED) {
        $result = sendViaBrevo($to, $subject, $htmlBody, $attachment);
        if ($result === true) return true;
        if ($result === null) {
            error_log('mail: Brevo API not configured, trying next transport');
        } else {
            error_log('mail: Brevo API failed (' . mailLastError() . '), trying next transport');
        }
    }

    // ── Gmail API path ──
    if (GMAIL_API_CONFIGURED) {
        $result = sendViaGmailApi($to, $subject, $htmlBody, $attachment);
        if ($result === true) return true;
        if ($result === null) {
            error_log('mail: Gmail API not configured, falling back to SMTP');
        } else {
            error_log('mail: Gmail API failed (' . mailLastError() . '), falling back to SMTP');
        }
    }

    // ── SMTP fallback (PHPMailer) ──
    // Only reachable if PHPMailer is actually installed. Without the
    // composer autoloader the class is undefined, so guard before
    // instantiating rather than fataling on a missing class.
    if (!defined('SMTP_HOST') || SMTP_HOST === '' || SMTP_USER === '' || SMTP_PASS === '') {
        mailSetLastError('Config', 'No usable transport. Brevo, Gmail API and SMTP_* are all unavailable.');
        error_log('mail: ' . mailLastError());
        return false;
    }
    if (!class_exists(PHPMailer::class)) {
        mailSetLastError('Config', 'PHPMailer is not installed (vendor/autoload.php missing) — run composer install.');
        error_log('mail: ' . mailLastError());
        return false;
    }
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout    = 30;

        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM !== '' ? MAIL_FROM : SMTP_USER, MAIL_FROM_NAME !== '' ? MAIL_FROM_NAME : 'BCP Registrar System');
        $mail->addAddress($to['email'], $to['name'] ?? '');
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        $mail->AltBody = trim(strip_tags(str_replace(['</p>', '</li>', '<br>', '<br/>', '<br />'], "\n", $htmlBody)));

        if (is_array($attachment) && isset($attachment['data'])) {
            $mail->addStringAttachment(
                $attachment['data'],
                $attachment['name'] ?? 'attachment.pdf',
                'base64',
                $attachment['mime'] ?? 'application/pdf'
            );
        }

        $sent = $mail->send();
        if (!$sent) {
            mailSetLastError('SMTP', ($mail->ErrorInfo ?: 'unknown SMTP error') . ' (host ' . SMTP_HOST . ':' . SMTP_PORT . ')');
            error_log('mail: ' . mailLastError());
        }
        return $sent;
    } catch (PHPMailerException $e) {
        // The host is appended on BOTH failure paths, not just the
        // non-throwing one below. PHPMailer's own message says only
        // "Failed to connect to server", which is unactionable: with
        // three transports configured, a registrar staring at
        // pickup_notify_error cannot tell WHICH host was unreachable.
        // (Caught by the test: the exception path was missing this.)
        mailSetLastError('SMTP', trim(($mail->ErrorInfo ?: '') . ' | ' . $e->getMessage())
            . ' (host ' . SMTP_HOST . ':' . SMTP_PORT . ')');
        error_log('mail: PHPMailer send failed → ' . mailLastError());
        return false;
    } catch (Throwable $e) {
        mailSetLastError('SMTP', $e->getMessage() . ' (host ' . SMTP_HOST . ':' . SMTP_PORT . ')');
        error_log('mail: unexpected send failure → ' . mailLastError());
        return false;
    }
}

/**
 * Send via Brevo (Sendinblue) transactional API. Returns true/false or
 * null when config missing (caller tries next transport).
 */
function sendViaBrevo(array $to, string $subject, string $htmlBody, ?array $attachment = null): ?bool {
    if (!BREVO_CONFIGURED) return null;

    // Brevo rejects a request whose sender is missing or unverified with
    // an opaque HTTP 400 ("sender email is invalid"). A Brevo-only setup
    // has no SMTP_USER to fall back on, so an unset MAIL_FROM silently
    // produced an empty sender here. Fail loudly and specifically instead.
    $senderEmail = (defined('MAIL_FROM') ? MAIL_FROM : '') ?: (defined('SMTP_USER') ? SMTP_USER : '');
    if ($senderEmail === '') {
        error_log('mail: Brevo API skipped — no sender address. Set MAIL_FROM to an address verified in Brevo.');
        return false;
    }

    $payload = [
        'sender'      => ['email' => $senderEmail, 'name' => MAIL_FROM_NAME ?: 'BCP Registrar System'],
        'to'          => [['email' => $to['email'], 'name' => $to['name'] ?? '']],
        'subject'     => $subject,
        'htmlContent' => $htmlBody,
    ];

    if (is_array($attachment) && isset($attachment['data'])) {
        $payload['attachment'] = [[
            'name'    => $attachment['name'] ?? 'attachment.pdf',
            'content' => base64_encode($attachment['data']),
        ]];
    }

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'api-key: ' . BREVO_API_KEY,
            'accept: application/json',
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    // Brevo error bodies are terse ("unauthorized", "sender email is
    // invalid"). Translated into an actionable hint, because "Test failed"
    // plus a log line the admin cannot read is not a diagnosis.
    if ($curlErr) { mailSetLastError('Brevo', 'Network error: ' . $curlErr); error_log('mail: ' . mailLastError()); return false; }
    if ($httpCode >= 400) {
        $detail = json_decode((string) $response, true);
        $msg    = is_array($detail) ? (string) ($detail['message'] ?? $response) : (string) $response;
        $hint   = match (true) {
            $httpCode === 401 => 'The Brevo API key is invalid or was revoked. Regenerate it at app.brevo.com → SMTP & API → API Keys.',
            $httpCode === 400 && stripos($msg, 'sender') !== false
                          => 'Brevo rejected the sender address "' . $senderEmail . '". Verify it in Brevo under Senders & Domains and click the confirmation link for that address.',
            $httpCode === 403 => 'This Brevo key cannot send transactional email (account not validated / sending paused).',
            $httpCode >= 500  => 'Brevo server error — safe to retry shortly.',
            default           => $msg,
        };
        mailSetLastError('Brevo', 'HTTP ' . $httpCode . ': ' . $msg . ' — ' . $hint);
        error_log('mail: ' . mailLastError());
        return false;
    }
    return true;
}

/**
 * Send via Gmail API (OAuth2). Returns true/false or null on config-missing
 * (caller falls back to SMTP).
 */
function sendViaGmailApi(array $to, string $subject, string $htmlBody, ?array $attachment = null): ?bool {
    if (!GMAIL_API_CONFIGURED) return null;

    $accessToken = gmailGetAccessToken();
    if ($accessToken === null) return null;

    // Build raw MIME
    $fromEmail = GMAIL_SENDER_EMAIL !== '' ? GMAIL_SENDER_EMAIL : SMTP_USER;
    $fromName  = MAIL_FROM_NAME !== '' ? MAIL_FROM_NAME : 'BCP Registrar System';
    $boundary  = 'boundary_' . bin2hex(random_bytes(16));

    $headers  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>\r\n";
    $headers .= "To: " . (($to['name'] ?? '') !== '' ? "=?UTF-8?B?" . base64_encode($to['name'] ?? '') . "?= " : '') . "<{$to['email']}>\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
    $headers .= "Date: " . date('r') . "\r\n\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody), 76, "\r\n");

    if (is_array($attachment) && isset($attachment['data'])) {
        $attName = $attachment['name'] ?? 'attachment.pdf';
        $attMime = $attachment['mime'] ?? 'application/pdf';
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$attMime}; name=\"{$attName}\"\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$attName}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($attachment['data']), 76, "\r\n");
    }
    $body .= "--{$boundary}--\r\n";

    $rawEncoded = rtrim(strtr(base64_encode($headers . $body), '+/', '-_'), '=');

    $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['raw' => $rawEncoded]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) { mailSetLastError('Gmail API', 'Network error: ' . $curlErr); error_log('mail: ' . mailLastError()); return false; }
    if ($httpCode !== 200) {
        $detail = json_decode((string) $response, true);
        $msg    = is_array($detail) ? (string) ($detail['error']['message'] ?? $response) : (string) $response;
        mailSetLastError('Gmail API', 'HTTP ' . $httpCode . ': ' . $msg);
        error_log('mail: ' . mailLastError());
        return false;
    }
    return true;
}

/**
 * Refresh access token from Google OAuth2. Cached ~50 min.
 */
function gmailGetAccessToken(): ?string {
    static $cached = null;
    if ($cached !== null && $cached['expires'] > time()) return $cached['token'];

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => GMAIL_API_CLIENT_ID,
            'client_secret' => GMAIL_API_CLIENT_SECRET,
            'refresh_token' => GMAIL_REFRESH_TOKEN,
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || $httpCode !== 200) {
        error_log('mail: Gmail token refresh failed → HTTP ' . $httpCode . ($curlErr ? " ({$curlErr})" : '') . ' ' . ($response ?: ''));
        return null;
    }
    $data  = json_decode($response, true);
    $token = $data['access_token'] ?? null;
    if ($token === null) { error_log('mail: Gmail token missing access_token'); return null; }

    $cached = ['token' => $token, 'expires' => time() + ((int)($data['expires_in'] ?? 3600)) - 300];
    return $token;
}


/**
 * Write a communication_log row. $fields: student_id, contact_id,
 * recipient_email, recipient_name, message_type, subject, status, ref,
 * detail, sent_by. created_at is always PHP wall-clock (Asia/Manila).
 */
function contactLog(array $fields): int {
    $data = [
        'student_id'      => (int) ($fields['student_id'] ?? 0),
        'contact_id'      => isset($fields['contact_id']) && $fields['contact_id'] !== null ? (int) $fields['contact_id'] : null,
        'recipient_email' => (string) ($fields['recipient_email'] ?? ''),
        'recipient_name'  => ($fields['recipient_name'] ?? null),
        'message_type'    => (string) ($fields['message_type'] ?? 'test'),
        'subject'         => ($fields['subject'] ?? null),
        'status'          => (string) ($fields['status'] ?? 'sent'),
        'ref'             => ($fields['ref'] ?? null),
        'detail'          => ($fields['detail'] ?? null),
        'sent_by'         => ($fields['sent_by'] ?? null),
        'created_at'      => date('Y-m-d H:i:s'),
    ];
    return (int) Database::getInstance()->insert('communication_log', $data);
}

/** Stamp last_emailed on a contact. */
function contactTouch(int $contactId, ?string $at = null): void {
    if ($contactId <= 0) return;
    Database::getInstance()->update(
        'contact_recipients',
        ['last_emailed' => $at !== null ? $at : date('Y-m-d H:i:s')],
        'id = ?',
        [$contactId]
    );
}

/** Absolute app URL for email links (scheme + host + app_url). */
function emailAppUrl(string $path): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . app_url($path);
}

/**
 * Student portal account welcome email (auto-created on enrollment).
 * Emails the student their portal credentials. No-ops gracefully when
 * SMTP is not configured (EMAIL_CONFIGURED=false) — the registrar still
 * sees the credentials in the UI, just no email goes out.
 */
function sendStudentWelcomeEmail(array $student, array $account, ?int $sentBy = null): array {
    $email = (string) ($account['email'] ?? '');
    $now   = date('Y-m-d H:i:s');
    if ($email === '') {
        return ['sent' => false, 'message' => 'No recipient email.', 'log_id' => null];
    }
    if (!emailConfigured()) {
        contactLog([
            'student_id'      => (int) ($student['id'] ?? 0),
            'recipient_email' => $email,
            'recipient_name'  => ($account['full_name'] ?? null),
            'message_type'    => 'portal_welcome',
            'subject'         => 'Your Student Portal account — BCP Registrar',
            'status'          => 'failed',
            'detail'          => 'Email is not configured (EMAIL_CONFIGURED=false).',
            'sent_by'         => $sentBy,
        ]);
        return ['sent' => false, 'message' => 'Email sending is not configured yet.', 'log_id' => null];
    }

    $studentName = getStudentFullName($student);
    $portalUrl   = emailAppUrl('login.php');
    $studentNo   = ($student['student_number'] ?? '—');
    $username    = (string) ($account['username'] ?? '');
    $html = '<p>Hello <strong>' . e_($studentName) . '</strong>,</p>'
        . '<p>Your student portal account has been created for the '
        . '<strong>Bestlink College of the Philippines — Office of the Registrar</strong>.</p>'
        . '<table cellspacing="0" cellpadding="8" style="border-collapse:collapse;margin:20px 0;width:100%;max-width:440px;border:1px solid #e2e8f0;border-radius:8px;">'
        . '<tr><td style="background:#f8fafc;font-weight:600;width:40%;">Student No.</td><td>' . e_($studentNo) . '</td></tr>'
        . '<tr><td style="background:#f8fafc;font-weight:600;">Username</td><td>' . e_($username) . '</td></tr>'
        . '<tr><td style="background:#f8fafc;font-weight:600;">Temporary password</td><td>' . e_($account['password'] ?? '') . '</td></tr>'
        . '</table>'
        . '<p style="margin:20px 0;">Sign in at the Student Portal using the <strong>username</strong> above and the '
        . 'temporary password. Please <strong>change your password after your first login</strong>.</p>'
        . '<p style="margin:24px 0;"><a href="' . e_($portalUrl) . '" style="background:#2563eb;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">Open the Student Portal</a></p>'
        . '<p style="color:#64748b;font-size:13px;">If you did not expect this message, you can safely ignore it.</p>';

    $sent = sendEmail(['email' => $email, 'name' => ($account['full_name'] ?? $studentName)], 'Your Student Portal account — BCP Registrar', $html);
    $logId = contactLog([
        'student_id'      => (int) ($student['id'] ?? 0),
        'recipient_email' => $email,
        'recipient_name'  => ($account['full_name'] ?? null),
        'message_type'    => 'portal_welcome',
        'subject'         => 'Your Student Portal account — BCP Registrar',
        'status'          => $sent ? 'sent' : 'failed',
        'detail'          => $sent ? 'Portal welcome email sent.' : 'SMTP send failed.',
        'sent_by'         => $sentBy,
    ]);

    return [
        'sent'    => $sent,
        'message' => $sent ? 'Welcome email sent to the student.' : 'Account created, but the welcome email could not be sent.',
        'log_id'  => $logId,
    ];
}

// ── Academic data helpers (shared by snapshot + transcript) ─────

/** Terms (academic_history) with their subjects (academic_grades). */
function contactBuildTerms(int $studentId): array {
    $db = Database::getInstance();
    $terms = $db->fetchAll(
        "SELECT * FROM academic_history WHERE student_id = ? ORDER BY IFNULL(school_year,''), IFNULL(semester,''), id",
        [$studentId]
    );
    foreach ($terms as &$t) {
        $t['subjects'] = $db->fetchAll(
            "SELECT subject, subject_code, units, final_rating, grade, remarks
               FROM academic_grades WHERE academic_history_id = ?
              ORDER BY subject_code, subject",
            [(int) $t['id']]
        );
    }
    return $terms;
}

/** Simple GWA from numeric final ratings (falls back to grade). */
function contactComputeGwa(array $terms): ?float {
    $vals = [];
    foreach ($terms as $t) {
        foreach ($t['subjects'] as $s) {
            $g = trim((string) ($s['final_rating'] !== '' && $s['final_rating'] !== null ? $s['final_rating'] : ($s['grade'] ?? '')));
            if ($g !== '' && is_numeric($g) && (float) $g > 0 && (float) $g <= 5) {
                $vals[] = (float) $g;
            }
        }
    }
    return count($vals) > 0 ? round(array_sum($vals) / count($vals), 2) : null;
}

// ── Feature senders ────────────────────────────────────────────

/**
 * Test-email verification. Requires an auth_token already set on the
 * contact (the API generates it). Returns ['sent'=>bool,'message'=>…].
 */
function sendContactTestEmail(array $contact, array $student, ?int $sentBy = null): array {
    $now   = date('Y-m-d H:i:s');
    $token = trim((string) ($contact['auth_token'] ?? ''));
    if ($token === '' || !emailConfigured()) {
        contactLog([
            'student_id'      => (int) $contact['student_id'],
            'contact_id'      => (int) $contact['id'],
            'recipient_email' => $contact['email'],
            'recipient_name'  => $contact['full_name'],
            'message_type'    => 'test',
            'subject'         => 'Confirm your email address — BCP Registrar',
            'status'          => 'failed',
            'detail'          => emailConfigured() ? 'Missing verification token.' : 'Email is not configured (EMAIL_CONFIGURED=false).',
            'sent_by'         => $sentBy,
        ]);
        return ['sent' => false, 'message' => emailConfigured() ? 'Could not prepare verification email.' : 'Email sending is not configured yet.'];
    }

    $confirmUrl = emailAppUrl('api/contacts-confirm.php?token=' . urlencode($token));
    $studentName = getStudentFullName($student);
    $html = '<p>Hello <strong>' . e_($contact['full_name']) . '</strong>,</p>'
        . '<p><strong>' . e_($studentName) . '</strong> (Student No. ' . e_($student['student_number'] ?? '—') . ') '
        . 'has listed this email address as a contact for receiving communications from '
        . '<strong>Bestlink College of the Philippines — Office of the Registrar</strong>.</p>'
        . '<p>Please confirm that this is the right address by clicking the button below:</p>'
        . '<p style="margin:24px 0;"><a href="' . e_($confirmUrl) . '" style="background:#2563eb;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">Confirm Receipt</a></p>'
        . '<p style="color:#64748b;font-size:13px;">If you were not expecting this message, you can safely ignore it — '
        . 'no student information will be sent to this address unless you confirm.</p>';
    $subject = 'Confirm your email address — BCP Registrar';

    $sent = sendEmail(['email' => $contact['email'], 'name' => $contact['full_name']], $subject, $html);
    $logId = contactLog([
        'student_id'      => (int) $contact['student_id'],
        'contact_id'      => (int) $contact['id'],
        'recipient_email' => $contact['email'],
        'recipient_name'  => $contact['full_name'],
        'message_type'    => 'test',
        'subject'         => $subject,
        'status'          => $sent ? 'sent' : 'failed',
        'ref'             => $token,
        'detail'          => $sent ? 'Verification email sent.' : 'SMTP send failed.',
        'sent_by'         => $sentBy,
    ]);
    if ($sent) contactTouch((int) $contact['id'], $now);

    return [
        'sent'    => $sent,
        'message' => $sent ? 'Verification email sent. Ask the contact to click “Confirm Receipt”.' : 'Email could not be sent.',
        'log_id'  => $logId,
    ];
}

/**
 * Invoice forwarding — feature A. Builds the encrypted invoice PDF
 * (password = student number) and emails one contact. Re-fetches the
 * request/catalog rows so callers can pass light data.
 */
function sendContactInvoice(array $contact, int $requestId, ?int $sentBy = null): array {
    $db = Database::getInstance();
    $request = $db->fetchOne(
        "SELECT dr.*, c.name AS catalog_name, c.fee_type, c.base_fee, c.sku
           FROM document_requests dr
           LEFT JOIN document_catalog c ON c.id = dr.catalog_id
          WHERE dr.id = ?",
        [$requestId]
    );
    if (!$request) {
        return ['sent' => false, 'message' => 'Request not found.', 'log_id' => null];
    }
    $student = $db->fetchOne('SELECT * FROM students WHERE id = ?', [(int) $request['student_id']]);
    if (!$student) {
        return ['sent' => false, 'message' => 'Student record not found.', 'log_id' => null];
    }
    if (!emailConfigured()) {
        contactLog([
            'student_id'      => (int) $student['id'],
            'contact_id'      => (int) $contact['id'],
            'recipient_email' => $contact['email'],
            'recipient_name'  => $contact['full_name'],
            'message_type'    => 'invoice',
            'subject'         => 'Invoice ' . ($request['request_id'] ?? '') . ' for ' . getStudentFullName($student),
            'status'          => 'failed',
            'detail'          => 'Email is not configured (EMAIL_CONFIGURED=false).',
            'ref'             => $request['request_id'] ?? null,
            'sent_by'         => $sentBy,
        ]);
        return ['sent' => false, 'message' => 'Email sending is not configured yet.', 'log_id' => null];
    }

    $catalog = $request['catalog_name'] ?? null;
    $deliveryFee = (float) ($request['delivery_fee'] ?? 0);
    $fee = (float) ($request['fee_amount'] ?? 0);
    if ($fee <= 0) {
        $fee = round((float) ($request['base_fee'] ?? 0) * max(1, (int) ($request['quantity'] ?? 1)), 2);
    }
    $pdf = buildInvoicePdf(
        $request,
        $student,
        $catalog !== null
            ? ['name' => $catalog, 'sku' => $request['sku'] ?? '', 'base_fee' => (float) ($request['base_fee'] ?? 0)]
            : null,
        $deliveryFee
    );

    $studentName = getStudentFullName($student);
    $total = $fee + $deliveryFee;
    $html = '<p>Hello <strong>' . e_($contact['full_name']) . '</strong>,</p>'
        . '<p>A new invoice has been generated for <strong>' . e_($studentName) . '</strong> (Student No. ' . e_($student['student_number'] ?? '—') . '):</p>'
        . '<ul>'
        . '<li>Document: ' . e_($catalog ?: 'Document request') . '</li>'
        . '<li>Request No.: ' . e_($request['request_id'] ?? '—') . '</li>'
        . '<li>Document fee: PHP ' . number_format($fee, 2) . '</li>'
        . ($deliveryFee > 0 ? '<li>Delivery fee: PHP ' . number_format($deliveryFee, 2) . '</li>' : '')
        . '<li><strong>Total: PHP ' . number_format($total, 2) . '</strong></li>'
        . '</ul>'
        . '<p>The invoice is attached as a secure PDF. It opens with the student’s <strong>ID number</strong> as the password.</p>';
    $subject = 'Invoice ' . ($request['request_id'] ?? '') . ' for ' . $studentName;

    $sent = sendEmail(['email' => $contact['email'], 'name' => $contact['full_name']], $subject, $html, [
        'data' => $pdf['bytes'],
        'name' => $pdf['filename'],
        'mime' => 'application/pdf',
    ]);
    $logId = contactLog([
        'student_id'      => (int) $student['id'],
        'contact_id'      => (int) $contact['id'],
        'recipient_email' => $contact['email'],
        'recipient_name'  => $contact['full_name'],
        'message_type'    => 'invoice',
        'subject'         => $subject,
        'status'          => $sent ? 'sent' : 'failed',
        'ref'             => $request['request_id'] ?? null,
        'detail'          => $sent ? 'Invoice forwarded (fee ' . number_format($total, 2) . ').' : 'SMTP send failed.',
        'sent_by'         => $sentBy,
    ]);
    if ($sent) contactTouch((int) $contact['id']);

    return ['sent' => $sent, 'message' => $sent ? 'Invoice emailed to ' . $contact['email'] . '.' : 'Email could not be sent.', 'log_id' => $logId];
}

/**
 * Grade snapshot — feature B. Clean HTML email: GWA + subject list.
 * No attachment (the spec keeps the snapshot lightweight).
 */
function sendContactGradeSnapshot(array $contact, array $student, array $terms, ?int $sentBy = null): array {
    $studentName = getStudentFullName($student);
    $gwa = contactComputeGwa($terms);

    $rows = '';
    foreach ($terms as $t) {
        $subjects = $t['subjects'] ?? [];
        if (count($subjects) === 0) continue;
        $termLabel = trim(($t['school_name'] ?? '') . ' ' . ($t['school_year'] ?? '') . ' ' . ($t['semester'] ?? ''));
        $rows .= '<tr><td colspan="3" style="background:#f1f5f9;font-weight:600;color:#334155;">' . e_($termLabel) . '</td></tr>';
        foreach ($subjects as $s) {
            $g = trim((string) ($s['final_rating'] !== '' && $s['final_rating'] !== null ? $s['final_rating'] : ($s['grade'] ?? '')));
            $rows .= '<tr><td>' . e_($s['subject'] ?? '') . '</td>'
                . '<td style="text-align:center;">' . e_($s['units'] ?? '') . '</td>'
                . '<td style="text-align:center;">' . e_($g) . '</td></tr>';
        }
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="3" style="color:#64748b;text-align:center;">No graded subjects on file.</td></tr>';
    }

    $html = '<p>Hello <strong>' . e_($contact['full_name']) . '</strong>,</p>'
        . '<p>Here is a current grade snapshot for <strong>' . e_($studentName) . '</strong> (Student No. ' . e_($student['student_number'] ?? '—') . '), as of ' . date('F d, Y h:i A') . ':</p>'
        . '<p><strong>Computed GWA:</strong> ' . ($gwa !== null ? number_format($gwa, 2) : '—') . '</p>'
        . '<table style="border-collapse:collapse;width:100%;font-size:14px;">'
        . '<thead><tr style="background:#eef4ff;"><th style="text-align:left;padding:6px 8px;">Subject</th><th style="padding:6px 8px;">Units</th><th style="padding:6px 8px;">Final</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>'
        . '<p style="color:#64748b;font-size:12px;">This is an unofficial snapshot for your reference. Official records are available from the Office of the Registrar.</p>';
    $subject = 'Grade snapshot for ' . $studentName;

    $sent = emailConfigured() ? sendEmail(['email' => $contact['email'], 'name' => $contact['full_name']], $subject, $html) : false;
    $logId = contactLog([
        'student_id'      => (int) $student['id'],
        'contact_id'      => (int) $contact['id'],
        'recipient_email' => $contact['email'],
        'recipient_name'  => $contact['full_name'],
        'message_type'    => 'snapshot',
        'subject'         => $subject,
        'status'          => $sent ? 'sent' : 'failed',
        'detail'          => $sent ? 'Grade snapshot sent (GWA ' . ($gwa !== null ? number_format($gwa, 2) : 'n/a') . ').' : (emailConfigured() ? 'SMTP send failed.' : 'Email is not configured (EMAIL_CONFIGURED=false).'),
        'sent_by'         => $sentBy,
    ]);
    if ($sent) contactTouch((int) $contact['id']);

    return ['sent' => $sent, 'message' => $sent ? 'Grade snapshot emailed.' : 'Email could not be sent.', 'log_id' => $logId];
}

/**
 * Transcript delivery — encrypted PDF attachment (password = student
 * number), per the Secure PDF feature (§D).
 */
function sendContactTranscript(array $contact, array $student, array $terms, ?int $sentBy = null): array {
    $studentName = getStudentFullName($student);
    $gwa = contactComputeGwa($terms);
    $pdf = buildTranscriptPdf($student, $terms);

    $html = '<p>Hello <strong>' . e_($contact['full_name']) . '</strong>,</p>'
        . '<p>As requested, here is the current transcript for <strong>' . e_($studentName) . '</strong> (Student No. ' . e_($student['student_number'] ?? '—') . ').</p>'
        . '<p>Computed GWA: <strong>' . ($gwa !== null ? number_format($gwa, 2) : '—') . '</strong></p>'
        . '<p>The transcript is attached as a secure PDF. It opens with the student’s <strong>ID number</strong> as the password.</p>';
    $subject = 'Transcript for ' . $studentName;

    $sent = emailConfigured() ? sendEmail(['email' => $contact['email'], 'name' => $contact['full_name']], $subject, $html, [
        'data' => $pdf['bytes'],
        'name' => $pdf['filename'],
        'mime' => 'application/pdf',
    ]) : false;
    $logId = contactLog([
        'student_id'      => (int) $student['id'],
        'contact_id'      => (int) $contact['id'],
        'recipient_email' => $contact['email'],
        'recipient_name'  => $contact['full_name'],
        'message_type'    => 'transcript',
        'subject'         => $subject,
        'status'          => $sent ? 'sent' : 'failed',
        'detail'          => $sent ? 'Transcript PDF emailed.' : (emailConfigured() ? 'SMTP send failed.' : 'Email is not configured (EMAIL_CONFIGURED=false).'),
        'sent_by'         => $sentBy,
    ]);
    if ($sent) contactTouch((int) $contact['id']);

    return ['sent' => $sent, 'message' => $sent ? 'Transcript emailed.' : 'Email could not be sent.', 'log_id' => $logId];
}

/**
 * Emergency blast — feature C. One email to every verified contact with
 * send_emergency on. Returns ['sent'=>int,'failed'=>int].
 */
function sendEmergencyBlast(string $subject, string $message, ?int $sentBy = null, ?int $studentId = null): array {
    $db = Database::getInstance();
    $sql = "SELECT * FROM contact_recipients WHERE verified = 1 AND send_emergency = 1";
    $params = [];
    if ($studentId) {
        $sql .= " AND student_id = ?";
        $params[] = (int) $studentId;
    }
    $contacts = $db->fetchAll($sql, $params);

    $sent = 0;
    $failed = 0;
    foreach ($contacts as $contact) {
        $html = '<p><strong>' . e_($subject) . '</strong></p>'
            . '<p>' . nl2br(e_(trim($message))) . '</p>'
            . '<p style="color:#64748b;font-size:12px;">Bestlink College of the Philippines — Office of the Registrar</p>';
        $ok = emailConfigured() ? sendEmail(['email' => $contact['email'], 'name' => $contact['full_name']], $subject, $html) : false;
        contactLog([
            'student_id'      => (int) $contact['student_id'],
            'contact_id'      => (int) $contact['id'],
            'recipient_email' => $contact['email'],
            'recipient_name'  => $contact['full_name'],
            'message_type'    => 'emergency',
            'subject'         => $subject,
            'status'          => $ok ? 'sent' : 'failed',
            'detail'          => $ok ? 'Emergency blast delivered.' : (emailConfigured() ? 'SMTP send failed.' : 'Email is not configured (EMAIL_CONFIGURED=false).'),
            'sent_by'         => $sentBy,
        ]);
        if ($ok) { $sent++; contactTouch((int) $contact['id']); }
        else { $failed++; }
    }
    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Automatic invoice forwarding — hooks into api/student-documents.php
 * when a request enters Awaiting_Payment. Emails every VERIFIED contact
 * with send_billing on for that student. Never throws (the caller runs
 * this inside try/catch, but be safe anyway).
 *
 * @return array{sent:int, billing_contacts:int, message:string}
 */
function contactAutoForwardInvoice(int $requestId, int $studentId): array {
    $db = Database::getInstance();
    $contacts = $db->fetchAll(
        "SELECT * FROM contact_recipients
          WHERE student_id = ? AND verified = 1 AND send_billing = 1",
        [$studentId]
    );
    $sent = 0;
    foreach ($contacts as $contact) {
        $res = sendContactInvoice($contact, $requestId, null);
        if ($res['sent']) $sent++;
    }
    return [
        'sent'             => $sent,
        'billing_contacts' => count($contacts),
        'message'          => count($contacts) > 0
            ? 'Invoice forwarded to ' . $sent . '/' . count($contacts) . ' billing contact(s).'
            : 'No verified billing contacts for this student.',
    ];
}

/**
 * Document pickup / availability notice.
 *
 * Fired from api/documents.php the moment a request goes to Ready. The
 * portal already showed the status change (notifyStudent), but a student
 * who does not log in — or who lives with a parent who does — needs to
 * be told in the one channel people actually read.
 *
 * Recipients, in order: the student's own address when it is usable,
 * then every guardian with an address, so the person who usually
 * physically collects the document is covered.
 *
 * Two reasons the student's own address is sometimes skipped:
 *   - email_is_placeholder = 1. Portal accounts are auto-created at
 *     enrolment and many carry a synthetic address. Sending a real
 *     notice to one bounces, and a bounce here looks to the registrar
 *     like "the student was told and ignored it".
 *   - email_bounced_at is set, meaning we have already been told it is bad.
 * Neither stops the send — each only drops that one recipient, so a
 * placeholder student address still leaves the guardians covered.
 *
 * Each address is sent to individually rather than BCC'd: sendEmail()
 * takes a single recipient, and a shared To: line would leak one
 * parent's pickup details to the other.
 *
 * Never throws. A mail failure must not roll back a status change the
 * registrar already committed — the caller writes the outcome into
 * pickup_notified_* so a failure is visible rather than silent.
 *
 * @return array{sent:int, failed:int, recipients:array, errors:array, message:string}
 */
function sendDocumentPickupEmail(int $requestId, ?int $sentBy = null): array {
    $db  = Database::getInstance();
    $out = ['sent' => 0, 'failed' => 0, 'recipients' => [], 'errors' => [], 'message' => ''];

    $request = $db->fetchOne(
        "SELECT dr.*, c.name AS catalog_name
           FROM document_requests dr
           LEFT JOIN document_catalog c ON c.id = dr.catalog_id
          WHERE dr.id = ?",
        [$requestId]
    );
    if (!$request) {
        $out['message'] = 'Request not found.';
        return $out;
    }
    $student = $db->fetchOne('SELECT * FROM students WHERE id = ?', [(int) $request['student_id']]);
    if (!$student) {
        $out['message'] = 'Student record not found.';
        return $out;
    }

    $ref   = (string) ($request['request_id'] ?? ('#' . $requestId));
    $label = (string) ($request['catalog_name'] ?? documentTypeLabel((string) ($request['document_type'] ?? '')));
    if ($label === '') { $label = 'Your requested document'; }
    $name  = getStudentFullName($student);
    // Recipients in the order the notice matters. Each entry is
    // [address, display name, why] — the "why" goes into the log so a
    // registrar can see afterwards why somebody was or was not told.
    $recipients = [];
    $skipReason = '';
    $studentMail = trim((string) ($student['email'] ?? ''));
    if ($studentMail === '') {
        $skipReason = 'no email on the student record';
    } elseif (!empty($student['email_is_placeholder'])) {
        $skipReason = 'student address is an enrolment placeholder';
    } elseif (!empty($student['email_bounced_at'])) {
        $skipReason = 'student address previously bounced';
    } else {
        $recipients[] = [$studentMail, $name, 'student'];
    }

    // Guardians: primary first, so the money-relevant parent leads. No
    // is_emergency filter — an emergency contact is exactly the person
    // who may be standing at the counter on pickup day.
    $guardians = $db->fetchAll(
        "SELECT full_name, email FROM guardians
          WHERE student_id = ? AND email IS NOT NULL AND email <> ''
          ORDER BY is_primary DESC, id ASC",
        [(int) $student['id']]
    );
    foreach ($guardians as $g) {
        $recipients[] = [trim((string) $g['email']), trim((string) $g['full_name']), 'guardian'];
    }

    // De-duplicate on the lowercased address. The same parent is often
    // both a guardian and a contact recipient, and sending twice makes a
    // registrar think two people were reached.
    $seen = [];
    $unique = [];
    foreach ($recipients as $r) {
        $key = mb_strtolower($r[0]);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $unique[] = $r;
    }

    if (!$unique) {
        $out['message'] = $skipReason !== ''
            ? 'No pickup email sent (' . $skipReason . '), and no guardian address on file.'
            : 'No pickup email sent: no usable email address on file.';
        return $out;
    }

    $subject = 'Your ' . $label . ' is ready for pickup (' . $ref . ')';

    // Built once, with a %NAME% token rather than a fixed greeting. Each
    // send replaces the token with THAT recipient's name; a shared body
    // with the student's name in it would greet the parent by the
    // student's name, which reads as the wrong letter entirely.
    $htmlBase = '';
    if (emailConfigured()) {
        // release_date is set by the registrar on the Ready action and is
        // a datetime, so only the date part is shown — "available
        // 2026-02-10 00:00:00" would read as nonsense to a student.
        $releaseRaw  = trim((string) ($request['release_date'] ?? ''));
        $releaseText = '';
        if ($releaseRaw !== '') {
            $ts = strtotime($releaseRaw);
            $releaseText = $ts !== false ? date('M j, Y', $ts) : $releaseRaw;
        }
        $htmlBase =
            '<p>Hello %NAME%,</p>'
            . '<p>Good news — <strong>' . e_($label) . '</strong> for <strong>' . e_($name) . '</strong>'
            . ' (Student No. ' . e_($student['student_number'] ?? '—') . ') is signed and ready.</p>'
            . '<ul>'
            . '<li>Document: <strong>' . e_($label) . '</strong></li>'
            . '<li>Request No.: ' . e_($ref) . '</li>'
            . ($releaseText !== '' ? '<li>Available from: <strong>' . e_($releaseText) . '</strong></li>' : '')
            . '</ul>'
            . '<p>You may collect it at the Registrar’s Office during office hours. '
            . 'Please bring a valid ID and your Student Number. If somebody is coming on your behalf, '
            . 'send them a signed authorisation letter with a copy of your ID — the office cannot '
            . 'release a document to an unverified third party.</p>'
            . '<p>You can also see the current status and the release date here: '
            . '<a href="' . e_(emailAppUrl('student/documents.php')) . '">your document requests</a>.</p>'
            . '<p>If you think this is wrong — for example you are no longer enrolled — please '
            . 'reply to this message or tell the Registrar’s Office.</p>';
    }
    foreach ($unique as $rr) {
        $body = str_replace('%NAME%', e_($rr[1] !== '' ? $rr[1] : $name), $htmlBase);
        $ok   = false;
        $err  = '';
        try {
            $ok = sendEmail(['email' => $rr[0], 'name' => $rr[1]], $subject, $body);
            if (!$ok) { $err = mailLastError(); }
        } catch (Throwable $e) {
            // One address throwing must not stop the rest being tried — a
            // single bad record would otherwise silently cost every
            // guardian below it their pickup notice.
            $err = $e->getMessage();
        }
        $out['recipients'][] = $rr[0];
        if ($ok) {
            $out['sent']++;
        } else {
            $out['failed']++;
            if ($err !== '') { $out['errors'][] = $rr[0] . ': ' . $err; }
        }
        contactLog([
            'student_id'      => (int) $student['id'],
            'recipient_email' => $rr[0],
            'recipient_name'  => $rr[1],
            'message_type'    => 'pickup',
            'subject'         => $subject,
            'status'          => $ok ? 'sent' : 'failed',
            'ref'             => $ref,
            'detail'          => $ok
                ? 'Pickup notice sent (' . $rr[2] . ').'
                : 'Pickup notice failed (' . $rr[2] . '): '
                  . ($err !== '' ? $err : 'unknown transport error'),
            'sent_by'         => $sentBy,
        ]);
    }

    $out['message'] = $out['failed'] === 0
        ? 'Pickup notice emailed to ' . $out['sent'] . ' recipient(s).'
        : 'Pickup notice emailed to ' . $out['sent'] . ' recipient(s); ' . $out['failed'] . ' failed.';
    return $out;
}

/** HTML-escape helper (short alias used in the senders above). */
// Defined in shared/functions.php so every page can use it, not just the
// mail module. The guard keeps this file self-contained if it is loaded on
// its own without functions.php.
if (!function_exists('e_')) {
    function e_(?string $value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
