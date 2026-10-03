<?php
// login.php - Phase 5 hardened sign-in:
//   Step 1: ID number / username + password
//   Step 2: one-time code (OTP) try lang muna
//   Forgot password: reveal email → OTP → set new password
//   10-min lockout after 5 failed attempts (handled server-side).
//   CSRF tokens are enforced on every POST (see shared/csrf_guard.php).

require_once __DIR__ . '/shared/security_headers.php';
require_once __DIR__ . '/shared/session_config.php';
require_once __DIR__ . '/shared/csrf_guard.php';

if (isLoggedIn()) {
    $role = $_SESSION['role'] ?? '';
    header('Location: ' . ($role === 'student' ? 'student/dashboard.php' : 'dashboard.php'));
    exit;
}

$timeout = isset($_GET['timeout']) ? true : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Sign In – BCP Registrar System</title>

    <meta name='csrf-token' content='<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>' />

    <meta name="loader-logo" content="assets/images/BCP_LOGO.png" />
    <link rel="icon" type="image/png" href="assets/images/BCP_LOGO.png" />
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico" />

    <link rel="stylesheet" href="css/auth.css" />
    <link rel="stylesheet" href="css/page-loader.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

    <style>
        /* Password Toggle Button */
        .password-field {
            position: relative;
            width: 100%;
        }

        .password-field input {
            width: 100%;
            padding-right: 45px;
        }

        /* Hide the browser's OWN reveal control.
           Chromium-based browsers (Edge especially) still draw a native
           eye inside a type="password" field, and Firefox/Chrome can add
           one via the password manager. That control is not part of this
           page, so it cannot be styled to match, and it sits in the same
           corner as .password-toggle-btn - the user saw two show-password
           buttons side by side.

           There is one page toggle, wired in the script at the bottom of
           this file, and it works in every browser including the ones
           with no native control at all. */
        .password-field input::-ms-reveal,
        .password-field input::-ms-clear {
            display: none;
            width: 0;
            height: 0;
        }
        .password-field input::-webkit-credentials-manager-button,
        .password-field input::-webkit-contacts-auto-fill-button,
        .password-field input::-webkit-inner-spin-button,
        .password-field input::-webkit-search-cancel-button,
        .password-field input::-webkit-search-decoration {
            -webkit-appearance: none;
            appearance: none;
            display: none !important;
        }
        /* Chromium has no pseudo-element for the native eye, so the
           field must stop advertising itself as a password field to get
           rid of it. -moz-text-security keeps the value masked in
           Firefox while the type stays "text", so the characters are
           still hidden and still never render as plain text. */
        .password-field input[data-masked="1"] {
            -webkit-text-security: disc !important;
            -moz-text-security: disc !important;
            text-security: disc !important;
        }

        .password-toggle-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #94a3b8;
            font-size: 16px;
            padding: 6px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .password-toggle-btn:hover {
            color: #2563eb;
            transform: translateY(-50%) scale(1.1);
        }

        .password-toggle-btn:active {
            transform: translateY(-50%) scale(0.95);
        }

        /* Terms and Conditions Modal */
        .tc-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .tc-modal-overlay.active {
            display: flex;
        }

        .tc-modal {
            background: white;
            border-radius: 16px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            max-width: 700px;
            width: 100%;
            max-height: 85vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: slideUp 0.3s ease-out;
        }

        .tc-modal-header {
            background: linear-gradient(135deg, #1a3a8c 0%, #2563eb 100%);
            color: white;
            padding: 24px;
            text-align: center;
        }

        .tc-modal-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .tc-modal-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
            font-size: 15px;
            line-height: 1.8;
            color: #1f2937;
        }

        .tc-modal-body h3 {
            font-size: 17px;
            color: #0f172a;
            margin-top: 18px;
            margin-bottom: 10px;
            font-weight: 700;
            letter-spacing: -0.3px;
        }

        .tc-modal-body h3:first-child {
            margin-top: 0;
        }

        .tc-modal-body p {
            margin-bottom: 14px;
            text-align: justify;
        }

        .tc-modal-body ul {
            margin-left: 20px;
            margin-bottom: 14px;
        }

        .tc-modal-body li {
            margin-bottom: 10px;
            color: #1f2937;
        }

        .tc-modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .tc-modal-btn {
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .tc-modal-btn-close {
            background: #e2e8f0;
            color: #1e293b;
        }

        .tc-modal-btn-close:hover {
            background: #cbd5e1;
        }

        @media (max-width: 640px) {
            .tc-modal {
                border-radius: 12px;
                max-height: 90vh;
            }

            .tc-modal-header {
                padding: 16px;
            }

            .tc-modal-header h2 {
                font-size: 18px;
            }

            .tc-modal-body {
                padding: 16px;
                font-size: 13px;
            }

            .tc-modal-footer {
                padding: 12px 16px;
            }
        }
    </style>

    <script src='js/csrf.js'></script>
    <script src="js/page-loader.js"></script>
</head>
<body>

<div class="outer">
    <div class="card">
        <!-- Left Panel -->
        <div class="left">
            <div class="left-top">
                <img src="assets/images/BCP_LOGO.png" alt="BCP Logo" class="left-logo" />
                <p class="left-school">Bestlink College of the Philippines</p>
            </div>
            <div class="left-body">
                <h1>Registrar Management System</h1>
                <p class="subtitle">AI-Supported Records Management</p>
                <p><span>Registrar</span> is the process of managing student records, documents, RFID cards, and academic history.</p>
            </div>
        </div>

        <!-- Right Panel -->
        <div class="right">
            <img class="bcp-logo" src="assets/images/BCP_LOGO.png" alt="BCP Logo" />
            <h2 id="formTitle">Sign In Account</h2>

            <?php if ($timeout): ?>
                <div class="auth-error" id="timeoutMsg" style="display:block;">
                    <i class="fa-solid fa-clock"></i> Your session has expired. Please log in again.
                </div>
            <?php endif; ?>

            <div class="auth-error" id="authError" style="display:none;"></div>
            <div class="auth-success" id="authSuccess" style="display:none;"></div>

            <!-- STEP 1: ID / username + password -->
            <form id="step1Form" style="width:100%">
                <div class="form-group">
                    <label><i class="fa-solid fa-id-badge"></i> ID Number / Username</label>
                    <input type="text" id="credential" autocomplete="username" />
                </div>

                <div class="form-group">
                    <label><i class="fa-solid fa-lock"></i> Password</label>
                    <div class="password-field">
                        <!-- type="text" with a CSS mask, not type="password".
                             Chromium draws a native reveal eye that cannot be
                             styled away, which is the second "show password"
                             button. A text field gets no native eye, and
                             -webkit-text-security: disc keeps the characters
                             masked until the toggle is used. autocomplete is
                             kept so password managers still recognise it. -->
                        <input type="text" id="password" data-masked="1"
                               autocomplete="current-password" name="password" />
                        <button type="button" class="password-toggle-btn" id="togglePassword"
                                title="Show Password" aria-label="Show password" aria-pressed="false">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-signin" id="btnSignin">
                    Sign In <i class="fa-solid fa-arrow-right"></i>
                </button>

                <div class="register-link" style="text-align:center;">
                    <a href="#" id="forgotLink"><i class="fa-solid fa-circle-question"></i> Forgot password?</a>
                </div>

                <div style="text-align:center;margin-top:16px;padding-top:12px;border-top:1px solid #e2e8f0;">
                    <a href="terms-and-conditions.php" target="_blank" style="font-size:.75rem;color:#2563eb;text-decoration:none;"><i class="fa-solid fa-file-contract"></i> Terms and Conditions</a>
                </div>
            </form>

            <!-- STEP 2: OTP -->
            <form id="otpForm" style="width:100%;display:none;">
                <p style="font-size:.8rem;color:#64748b;margin-bottom:14px;text-align:center;">
                    We sent a one-time code to <span id="otpMasked" style="color:#2563eb;font-weight:600;"></span>
                    <span id="otpResentMsg" style="display:block;color:#16a34a;"></span>
                </p>

                <div class="form-group">
                    <label><i class="fa-solid fa-key"></i> One-Time Code</label>
                    <input type="text" id="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code"
                           placeholder="6-digit code" style="letter-spacing:6px;text-align:center;font-size:1.2rem;font-weight:700;" />
                </div>

                <button type="submit" class="btn-signin" id="btnVerify">
                    Verify &amp; Continue <i class="fa-solid fa-check"></i>
                </button>

                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:.78rem;color:#64748b;">
                    <span class="resend" id="resendOtp"><i class="fa-solid fa-rotate"></i> Resend code</span>
                    <a href="#" id="backToLogin" style="color:#2563eb;text-decoration:none;"><i class="fa-solid fa-arrow-left"></i> Back</a>
                </div>
            </form>

            <!-- FORGOT: email (this is where the email field appears) -->
            <form id="forgotForm" style="width:100%;display:none;">
                <p style="font-size:.8rem;color:#64748b;margin-bottom:14px;text-align:center;">
                    Enter the email registered to your account and we'll send a reset code.
                </p>

                <div class="form-group">
                    <label><i class="fa-solid fa-envelope"></i> Email</label>
                    <input type="email" id="forgotEmail" autocomplete="email" placeholder="you@bestlink.edu.ph" />
                </div>

                <button type="submit" class="btn-signin" id="btnForgot">
                    Send Reset Code <i class="fa-solid fa-paper-plane"></i>
                </button>

                <div style="text-align:center;margin-top:14px;font-size:.78rem;">
                    <a href="#" id="backToLogin2" style="color:#2563eb;text-decoration:none;"><i class="fa-solid fa-arrow-left"></i> Back to sign in</a>
                </div>
            </form>

            <!-- RESET PASSWORD: new password step -->
            <form id="resetForm" style="width:100%;display:none;">
                <p style="font-size:.8rem;color:#64748b;margin-bottom:14px;text-align:center;">
                    Set a new password for your account.
                </p>

                <div class="form-group">
                    <label><i class="fa-solid fa-lock"></i> New Password</label>
                    <input type="password" id="newPassword" autocomplete="new-password" placeholder="At least 6 characters" />
                </div>

                <div class="form-group">
                    <label><i class="fa-solid fa-lock"></i> Confirm Password</label>
                    <input type="password" id="confirmPassword" autocomplete="new-password" placeholder="Repeat new password" />
                </div>

                <button type="submit" class="btn-signin" id="btnReset">
                    Save New Password <i class="fa-solid fa-check"></i>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Terms and Conditions Modal -->
<div class="tc-modal-overlay" id="tcModal">
    <div class="tc-modal">
        <div class="tc-modal-header">
            <h2><i class="fa-solid fa-file-contract"></i> Terms and Conditions</h2>
        </div>
        <div class="tc-modal-body">
            <h3>1. Acceptance of Terms</h3>
            <p>By accessing and using the Bestlink College of the Philippines (BCP) Registrar Management System ("System"), you acknowledge that you have read, understood, and agree to be bound by these Terms and Conditions.</p>

            <h3>2. System Purpose</h3>
            <p>The Registrar Management System is provided by Bestlink College of the Philippines for the exclusive purpose of managing student records, academic history, health records, RFID access control, and document requests.</p>

            <h3>3. User Responsibilities</h3>
            <ul>
                <li>You are responsible for maintaining the confidentiality of your login credentials</li>
                <li>You agree not to share your credentials with any other person</li>
                <li>You agree to immediately notify the IT department if you suspect unauthorized access</li>
                <li>You are responsible for all activities that occur under your account</li>
                <li>You agree to use the System only for authorized educational and administrative purposes</li>
            </ul>

            <h3>4. Prohibited Activities</h3>
            <p>You agree not to:</p>
            <ul>
                <li>Attempt to gain unauthorized access to the System or its data</li>
                <li>Modify, copy, or distribute System content without authorization</li>
                <li>Use the System for any illegal, harmful, or harassing purpose</li>
                <li>Attempt to reverse-engineer, decompile, or discover the source code</li>
                <li>Interfere with or disrupt the normal operation of the System</li>
                <li>Attempt to bypass security measures or access controls</li>
                <li>Use automated tools, scripts, or bots to access the System without authorization</li>
            </ul>

            <h3>5. Privacy and Data Protection</h3>
            <p>Your personal information, academic records, and health data are protected under applicable data privacy laws. The System implements industry-standard security measures including encryption, access controls, and audit logging.</p>

            <h3>6. Intellectual Property</h3>
            <p>All content, design, and functionality of the Registrar Management System are the intellectual property of Bestlink College of the Philippines. You may not reproduce, modify, or distribute any part of the System without explicit written permission.</p>

            <h3>7. Limitation of Liability</h3>
            <p>The Registrar Management System is provided "as is" without warranties of any kind. Bestlink College of the Philippines shall not be liable for any indirect, incidental, special, or consequential damages arising from your use of or inability to use the System.</p>

            <h3>8. System Availability</h3>
            <p>While we strive to maintain continuous availability of the System, we make no guarantee of uninterrupted service. The System may be temporarily unavailable for maintenance, updates, or due to unforeseen circumstances.</p>

            <h3>9. Changes to Terms</h3>
            <p>Bestlink College of the Philippines reserves the right to modify these Terms and Conditions at any time. Your continued use of the System following notification of changes constitutes your acceptance of the revised terms.</p>

            <h3>10. Termination of Access</h3>
            <p>The college reserves the right to suspend or terminate your access to the System at any time, with or without cause, including but not limited to violations of these Terms and Conditions.</p>

            <h3>11. Governing Law</h3>
            <p>These Terms and Conditions shall be governed by and construed in accordance with the laws of the Republic of the Philippines.</p>

            <h3>12. Contact Information</h3>
            <p><strong>Office of the Registrar</strong><br>Bestlink College of the Philippines<br>Email: registrar@bestlink.edu.ph</p>
        </div>
        <div class="tc-modal-footer">
            <button class="tc-modal-btn tc-modal-btn-close" id="tcModalCloseBtn">Close</button>
        </div>
    </div>
</div>

<script>
let session = { user_id: null, purpose: 'login', otp: null, status: 'idle' };

const $ = (id) => document.getElementById(id);

// ── Password Toggle ──
const togglePasswordBtn = $('togglePassword');
const passwordInput = $('password');

if (togglePasswordBtn && passwordInput) {
    togglePasswordBtn.addEventListener('click', function(e) {
        e.preventDefault();
        // The field is a text input wearing a CSS mask, because a
        // type="password" field makes the browser draw its own reveal eye
        // and the user ends up with two show-password buttons. So the
        // reveal is done by taking the mask off, not by changing the type.
        const isMasked = passwordInput.dataset.masked === '1';

        if (isMasked) {
            passwordInput.removeAttribute('data-masked');
        } else {
            passwordInput.dataset.masked = '1';
        }

        // Toggle icon
        const icon = this.querySelector('i');
        if (isMasked) {
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
            this.title = 'Hide Password';
            this.setAttribute('aria-label', 'Hide password');
            this.setAttribute('aria-pressed', 'true');
        } else {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
            this.title = 'Show Password';
            this.setAttribute('aria-label', 'Show password');
            this.setAttribute('aria-pressed', 'false');
        }
    });
}

// ── Terms and Conditions Modal ──
const tcModal = document.getElementById('tcModal');
const tcModalCloseBtn = document.getElementById('tcModalCloseBtn');

function openTcModal(e) {
    e.preventDefault();
    tcModal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeTcModal() {
    tcModal.classList.remove('active');
    document.body.style.overflow = '';
}

// Find T&C link and attach event
const tcLink = document.querySelector('a[href="terms-and-conditions.php"]');
if (tcLink) {
    tcLink.addEventListener('click', openTcModal);
}

if (tcModalCloseBtn) tcModalCloseBtn.addEventListener('click', closeTcModal);

// Close modal when clicking outside
if (tcModal) {
    tcModal.addEventListener('click', function(e) {
        if (e.target === this) closeTcModal();
    });
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && tcModal && tcModal.classList.contains('active')) {
        closeTcModal();
    }
});

function showForm(which) {
    $('step1Form').style.display    = which === 'step1'   ? '' : 'none';
    $('otpForm').style.display      = which === 'otp'     ? '' : 'none';
    $('forgotForm').style.display   = which === 'forgot'  ? '' : 'none';
    $('resetForm').style.display    = which === 'reset'   ? '' : 'none';

    const titles = {
        step1:  'Sign In Account',
        otp:    'One-Time Code',
        forgot: 'Forgot Password',
        reset:  'Reset Password'
    };
    $('formTitle').textContent = titles[which] || 'Sign In Account';
}

function showError(msg) {
    const e = $('authError');
    e.innerHTML = msg;
    e.style.display = 'block';
    $('authSuccess').style.display = 'none';
}

function showSuccess(msg) {
    const s = $('authSuccess');
    s.textContent = msg;
    s.style.display = 'block';
    $('authError').style.display = 'none';
}

async function post(action, body) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(body || {})) fd.append(k, v);
    const res = await fetch('shared/auth_actions.php', { method: 'POST', body: fd });
    return res.json();
}

// ── Step 1: ID/username + password (Direct Login) ──
$('step1Form').addEventListener('submit', async function (e) {
    e.preventDefault();
    const credential = $('credential').value.trim();
    const password = $('password').value;
    const btn = $('btnSignin');

    $('authError').style.display = 'none';
    $('authSuccess').style.display = 'none';

    if (!credential || !password) {
        showError('Please enter your ID number / username and password.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = 'Signing in… <i class="fa-solid fa-spinner fa-spin"></i>';
    try {
        const data = await post('login', { username: credential, password });
        if (data.success) {
            showSuccess('Login successful! Redirecting…');
            window.location.href = data.data?.redirect || 'dashboard.php';
        } else {
            showError(data.message || 'Invalid ID / username or password.');
        }
    } catch (err) {
        showError('Request failed. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right"></i>';
    }
});

// ── Step 2: verify OTP ──
$('otpForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const otp = $('otp').value.trim();
    const btn = $('btnVerify');

    if (!otp) { showError('Please enter the one-time code.'); return; }

    btn.disabled = true;
    btn.innerHTML = 'Verifying… <i class="fa-solid fa-spinner fa-spin"></i>';
    try {
        const data = await post('verify_otp', { user_id: session.user_id, otp, purpose: session.purpose });
        if (data.success && data.data && data.data.step === 'reset_password') {
            session.user_id = data.data.user_id;
            // Carry the single-use reset grant to the final step. The
            // server will not accept reset_password without it — that is
            // what makes the flow safe rather than trusting the client.
            session.reset_token = data.data.reset_token;
            session.status = 'reset_pending';
            showForm('reset');
            showSuccess('Code verified. Set your new password.');
            $('newPassword').focus();
        } else if (data.success) {
            window.location.href = data.data?.redirect || 'dashboard.php';
        } else {
            showError(data.message || 'Invalid code. Please try again.');
        }
    } catch (err) {
        showError('Request failed. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Verify & Continue <i class="fa-solid fa-check"></i>';
    }
});

// ── Resend code ──
$('resendOtp').addEventListener('click', async function (e) {
    e.preventDefault();
    this.innerHTML = 'Sending…';
    try {
        const data = await post('resend_otp', { user_id: session.user_id, purpose: session.purpose });
        if (data.success) {
            // The server never returns the code, not even in dev — see the
            // resend_otp handlers. 'delivered' is safe to surface because
            // it carries no secret.
            $('otpResentMsg').textContent = data.data?.delivered === false
                ? '⚠ We could not reach the mail server. Please contact the registrar.'
                : 'A new code was sent. Check your inbox.';
            $('authError').style.display = 'none';
        } else {
            showError(data.message || 'Unable to resend.');
        }
    } catch (err) {
        showError('Unable to resend the code.');
    } finally {
        this.innerHTML = '<i class="fa-solid fa-rotate"></i> Resend code';
    }
});

// ── Forgot password: reveal email field ──
$('forgotLink').addEventListener('click', function (e) {
    e.preventDefault();
    session.purpose = 'reset';
    showForm('forgot');
    $('forgotEmail').focus();
});

$('forgotForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const email = $('forgotEmail').value.trim();
    const btn = $('btnForgot');

    if (!email) { showError('Please enter your email address.'); return; }

    btn.disabled = true;
    btn.innerHTML = 'Sending… <i class="fa-solid fa-spinner fa-spin"></i>';
    try {
        const data = await post('forgot', { email });
        if (data.success && data.data && data.data.step === 'otp') {
            session.user_id = data.data.user_id;
            session.purpose = 'reset';
            $('otpMasked').textContent = data.data.masked_email || 'your email';
            $('otpResentMsg').textContent = data.data.otp ? ('⚠ Dev mode: your code is ' + data.data.otp) : '';
            if (data.data.otp) $('otp').value = data.data.otp;
            showForm('otp');
            $('otp').focus();
        } else {
            showError(data.message || 'Unable to send reset code.');
        }
    } catch (err) {
        showError('Request failed. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Send Reset Code <i class="fa-solid fa-paper-plane"></i>';
    }
});

// ── Reset password (after reset OTP) ──
$('resetForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const np = $('newPassword').value;
    const cp = $('confirmPassword').value;
    const btn = $('btnReset');

    if (np.length < 8) { showError('Password must be at least 8 characters.'); return; }
    if (np !== cp) { showError('Passwords do not match.'); return; }
    // The server is authoritative; this is only an early hint.
    if (!session.reset_token) { showError('Your reset session expired. Please request a new code.'); return; }

    btn.disabled = true;
    btn.innerHTML = 'Saving… <i class="fa-solid fa-spinner fa-spin"></i>';
    try {
        const data = await post('reset_password', { reset_token: session.reset_token, new_password: np, confirm_password: cp });
        if (data.success) {
            showSuccess('Password reset. You can now sign in.');
            setTimeout(() => { showForm('step1'); $('authSuccess').style.display = 'none'; }, 1800);
        } else {
            showError(data.message || 'Unable to reset password.');
        }
    } catch (err) {
        showError('Request failed. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Save New Password <i class="fa-solid fa-check"></i>';
    }
});

// ── Back links ──
$('backToLogin').addEventListener('click', function (e) { e.preventDefault(); session.purpose = 'login'; showForm('step1'); });
$('backToLogin2').addEventListener('click', function (e) { e.preventDefault(); session.purpose = 'login'; showForm('step1'); });
</script>

</body>
</html>
