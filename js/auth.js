// ============================================================
//  AUTH.JS
//  Authentication logic for login, registration, and logout
// ============================================================

(function() {
    'use strict';

    // ── DOM Elements ──
    const loginForm = document.getElementById('signinForm');
    const registerForm = document.getElementById('registerForm');
    const logoutBtn = document.getElementById('logoutBtn');

    // ── Error/Toast Helpers ──
    function showError(elementId, message) {
        const el = document.getElementById(elementId);
        if (el) {
            el.textContent = message;
            el.style.display = 'block';
        }
    }

    function hideError(elementId) {
        const el = document.getElementById(elementId);
        if (el) {
            el.style.display = 'none';
        }
    }

    function showToast(message, type) {
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = 'toast ' + (type || 'success');
        
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-triangle-exclamation',
            info: 'fa-info-circle'
        };

        toast.innerHTML = `
            <i class="fas ${icons[type] || icons.success} toast-icon"></i>
            <div class="toast-content">
                <div class="toast-message">${message}</div>
            </div>
            <button class="toast-close" onclick="this.closest('.toast').remove()">
                <i class="fas fa-times"></i>
            </button>
        `;

        container.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('hiding');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    // Expose showToast globally. It was only ever callable inside this IIFE,
    // so every inline handler that relied on the "global 2-arg showToast"
    // (registrar/documents.php, student/documents.php, documents-add.php, …)
    // threw ReferenceError mid-success-handler — the toast never showed and
    // location.reload() never ran, so actions appeared to hang until a manual
    // refresh. Pages that define their own showToast shadow this one as before.
    window.showToast = showToast;

    // ── Login Handler ──
    if (loginForm) {
        loginForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            const errorBox = document.getElementById('authError');
            const btn = document.getElementById('btnSignin');

            // Hide previous errors
            if (errorBox) errorBox.style.display = 'none';

            // Validate
            if (!username || !password) {
                showError('authError', 'Please enter your username and password.');
                return;
            }

            // Show loading state
            btn.disabled = true;
            btn.innerHTML = 'Signing in… <i class="fa-solid fa-spinner fa-spin"></i>';

            // Prepare form data
            const fd = new FormData();
            fd.set('action', 'login');
            fd.set('username', username);
            fd.set('password', password);

            try {
                const response = await fetch('../shared/auth_actions.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await response.json();

                if (data.success) {
                    // Login successful - use server-returned redirect URL
                    window.location.href = data.data?.redirect || '../dashboard/dashboard.php';
                } else {
                    showError('authError', data.message || 'Invalid username or password.');
                    btn.disabled = false;
                    btn.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right"></i>';
                }
            } catch (error) {
                showError('authError', 'Request failed. Please try again.');
                btn.disabled = false;
                btn.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right"></i>';
            }
        });

        // Enter key support for login
        document.getElementById('password').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                loginForm.dispatchEvent(new Event('submit'));
            }
        });
    }

    // ── Register Handler ──
    if (registerForm) {
        registerForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const firstName = document.getElementById('firstName').value.trim();
            const lastName = document.getElementById('lastName').value.trim();
            const email = document.getElementById('email').value.trim();
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            const errorBox = document.getElementById('authError');
            const successBox = document.getElementById('authSuccess');
            const btn = document.getElementById('btnRegister');

            // Hide previous messages
            if (errorBox) errorBox.style.display = 'none';
            if (successBox) successBox.style.display = 'none';

            // Validation
            if (!firstName || !lastName || !email || !username || !password) {
                showError('authError', 'Please fill in all required fields.');
                return;
            }

            if (password !== confirmPassword) {
                showError('authError', 'Passwords do not match.');
                return;
            }

            if (password.length < 6) {
                showError('authError', 'Password must be at least 6 characters.');
                return;
            }

            // Show loading state
            btn.disabled = true;
            btn.innerHTML = 'Registering… <i class="fa-solid fa-spinner fa-spin"></i>';

            // Prepare form data
            const fd = new FormData();
            fd.set('action', 'register');
            fd.set('first_name', firstName);
            fd.set('last_name', lastName);
            fd.set('email', email);
            fd.set('username', username);
            fd.set('password', password);
            fd.set('role', 'student'); // Default role

            try {
                const response = await fetch('../shared/auth_actions.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await response.json();

                if (data.success) {
                    if (successBox) {
                        successBox.textContent = data.message || 'Registration successful! Redirecting to login...';
                        successBox.style.display = 'block';
                    }
                    btn.disabled = false;
                    btn.innerHTML = 'Register <i class="fa-solid fa-arrow-right"></i>';

                    // Redirect to login after 2 seconds
                    setTimeout(() => {
                        window.location.href = 'login.php';
                    }, 2000);
                } else {
                    showError('authError', data.message || 'Registration failed. Please try again.');
                    btn.disabled = false;
                    btn.innerHTML = 'Register <i class="fa-solid fa-arrow-right"></i>';
                }
            } catch (error) {
                showError('authError', 'Request failed. Please try again.');
                btn.disabled = false;
                btn.innerHTML = 'Register <i class="fa-solid fa-arrow-right"></i>';
            }
        });
    }



    // ── Password Toggle ──
    // Matches both spellings on purpose. login.php marks its button
    // .password-toggle-btn, so querying only .password-toggle matched
    // nothing there; had a page ever carried both this handler and an
    // inline one, two listeners on the same button would each flip the
    // field, and the reveal would look broken.
    document.querySelectorAll('.password-toggle, .password-toggle-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const input = this.parentElement.querySelector('input');
            const icon = this.querySelector('i');
            if (!input || !icon) return;

            // A field that masks via CSS (data-masked) has no type to
            // flip, so toggle the attribute instead. Otherwise fall back
            // to switching type on a real password input.
            if (input.hasAttribute('data-masked')) {
                if (input.dataset.masked === '1') input.removeAttribute('data-masked');
                else input.dataset.masked = '1';
            } else {
                input.type = input.type === 'password' ? 'text' : 'password';
            }

            const revealed = icon.classList.contains('fa-eye');
            icon.classList.toggle('fa-eye', !revealed);
            icon.classList.toggle('fa-eye-slash', revealed);
        });
    });

    // ── Input Icon Active State ──
    document.querySelectorAll('.input-field').forEach(function(input) {
        // Focus state
        input.addEventListener('focus', function() {
            const icon = this.parentElement.querySelector('.input-icon');
            if (icon) icon.classList.add('active');
        });

        // Blur state
        input.addEventListener('blur', function() {
            const icon = this.parentElement.querySelector('.input-icon');
            if (icon && !this.value) icon.classList.remove('active');
        });

        // Check on load
        if (input.value) {
            const icon = input.parentElement.querySelector('.input-icon');
            if (icon) icon.classList.add('active');
        }
    });

})();