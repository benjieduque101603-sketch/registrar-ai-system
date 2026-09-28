<?php
// ============================================================
//  SIDEBAR.PHP  (includes/)
//  Clean sidebar with collapse, no dropdowns
// ============================================================

$APP_ROOT   = $APP_ROOT   ?? '../';
$ACTIVE_NAV = $ACTIVE_NAV ?? '';
$USER_ROLE  = $_SESSION['role'] ?? '';

// Student profile info for sidebar mini-card (student role only)
if ($USER_ROLE === 'student') {
    $_sbFN       = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    if (empty($_sbFN)) $_sbFN = $_SESSION['full_name'] ?? 'Student';
    $_sbInitial  = strtoupper(substr(trim($_sbFN), 0, 1));
    $_sbStudNo   = $_SESSION['student_number'] ?? '';
    $_sbPhoto    = $_SESSION['photo'] ?? '';
    $_sbPhotoUrl = $_sbPhoto ? $APP_ROOT . ltrim($_sbPhoto, './') : '';
}
?>
<!-- Sidebar Toggle Button -->
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle Sidebar">
    <i class="fa-solid fa-bars"></i>
</button>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <a href="<?= $APP_ROOT ?>dashboard.php" title="Go to Dashboard" class="sidebar-logo-link">
                <img src="<?= $APP_ROOT ?>assets/images/BCP_LOGO.png" alt="BCP Logo" class="sidebar-logo-img"/>
            </a>
            <span class="sidebar-notif" id="bellBtn" title="Notifications">
                <i class="fa-solid fa-bell"></i>
                <span class="sidebar-notif-badge" id="bellBadge">0</span>
            </span>
        </div>
        <button class="sidebar-collapse-btn" id="sidebarCollapse" title="Collapse Sidebar">
            <i class="fa-solid fa-angles-left"></i>
        </button>
    </div>

    <div class="sidebar-nav">

    <?php if ($USER_ROLE === 'student'): ?>
        <!-- ══════════ STUDENT PORTAL MENU ══════════ -->
        <div class="sidebar-brand">
            <div class="brand-title">Navigation</div>
        </div>

        <a href="<?= $APP_ROOT ?>student/dashboard.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_dashboard' ? 'active' : '' ?>">
            <i class="fa-solid fa-house-chimney"></i>
            <span class="sidebar-text">Dashboard</span>
        </a>

        <div class="sidebar-divider"></div>

        <div class="sidebar-brand">
            <div class="brand-title">Academics &amp; Records</div>
        </div>

        <a href="<?= $APP_ROOT ?>student/profile.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_profile' ? 'active' : '' ?>">
            <i class="fa-solid fa-id-card-clip"></i>
            <span class="sidebar-text">My Profile</span>
        </a>

        <a href="<?= $APP_ROOT ?>student/grades.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_grades' ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-simple"></i>
            <span class="sidebar-text">Grades &amp; History</span>
        </a>

        <a href="<?= $APP_ROOT ?>student/academic-records.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_academic' ? 'active' : '' ?>">
            <i class="fa-solid fa-graduation-cap"></i>
            <span class="sidebar-text">Academic Records</span>
        </a>

        <a href="<?= $APP_ROOT ?>student/documents.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_documents' ? 'active' : '' ?>">
            <i class="fa-solid fa-folder-closed"></i>
            <span class="sidebar-text">Document Requests</span>
        </a>

        <a href="<?= $APP_ROOT ?>student/ids.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_ids' ? 'active' : '' ?>">
            <i class="fa-solid fa-id-badge"></i>
            <span class="sidebar-text">Digital ID Cards</span>
        </a>

        <a href="<?= $APP_ROOT ?>student/health-records.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_health' ? 'active' : '' ?>">
            <i class="fa-solid fa-heart-pulse"></i>
            <span class="sidebar-text">Health Records</span>
        </a>

        <a href="<?= $APP_ROOT ?>student/contacts.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_contacts' ? 'active' : '' ?>">
            <i class="fa-solid fa-people-roof"></i>
            <span class="sidebar-text">Emergency &amp; Contacts</span>
        </a>

        <div class="sidebar-divider"></div>

        <div class="sidebar-brand">
            <div class="brand-title">Services &amp; AI</div>
        </div>

        <a href="<?= $APP_ROOT ?>student/queue.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_queue' ? 'active' : '' ?>">
            <i class="fa-solid fa-ticket"></i>
            <span class="sidebar-text">Live Queue System</span>
        </a>

        <button type="button" class="sidebar-item ai-trigger-item" onclick="if(window.toggleStudentChat) window.toggleStudentChat();">
            <i class="fa-solid fa-wand-magic-sparkles" style="color: #60a5fa;"></i>
            <span class="sidebar-text">AI Registrar Assistant</span>
            <span class="sidebar-badge-ai">AI</span>
        </button>

        <div class="sidebar-divider"></div>

        <div class="sidebar-brand">
            <div class="brand-title">Account</div>
        </div>

        <a href="<?= $APP_ROOT ?>settings.php" class="sidebar-item <?= $ACTIVE_NAV === 'settings' ? 'active' : '' ?>">
            <i class="fa-solid fa-gear"></i>
            <span class="sidebar-text">Account Settings</span>
        </a>

    </div><!-- end sidebar-nav -->

    <div class="sidebar-footer">
        <a href="<?= $APP_ROOT ?>logout.php" class="sidebar-item logout-link" id="logoutBtn" data-logout-url="<?= $APP_ROOT ?>logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span class="sidebar-text">Logout</span>
        </a>
    </div>

    <?php elseif ($USER_ROLE === 'nurse'): ?>

        <!-- ══════════ CLINIC / NURSE PORTAL MENU ══════════ -->
        <div class="sidebar-brand">
            <div class="brand-title">Clinic</div>
        </div>

        <a href="<?= $APP_ROOT ?>nurse/dashboard.php" class="sidebar-item <?= $ACTIVE_NAV === 'nurse_dashboard' ? 'active' : '' ?>">
            <i class="fa-solid fa-house-chimney"></i>
            <span class="sidebar-text">Clinic Dashboard</span>
        </a>

        <a href="<?= $APP_ROOT ?>nurse/records.php" class="sidebar-item <?= $ACTIVE_NAV === 'nurse_records' ? 'active' : '' ?>">
            <i class="fa-solid fa-heart-pulse"></i>
            <span class="sidebar-text">Health Records</span>
        </a>

        <a href="<?= $APP_ROOT ?>nurse/student-lookup.php" class="sidebar-item <?= $ACTIVE_NAV === 'nurse_lookup' ? 'active' : '' ?>">
            <i class="fa-solid fa-magnifying-glass"></i>
            <span class="sidebar-text">Student Lookup</span>
        </a>

        <a href="<?= $APP_ROOT ?>nurse/statistics.php" class="sidebar-item <?= $ACTIVE_NAV === 'nurse_stats' ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-simple"></i>
            <span class="sidebar-text">Visit Statistics</span>
        </a>

        <a href="<?= $APP_ROOT ?>nurse/supplies.php" class="sidebar-item <?= $ACTIVE_NAV === 'nurse_supplies' ? 'active' : '' ?>">
            <i class="fa-solid fa-boxes-stacked"></i>
            <span class="sidebar-text">Supplies</span>
        </a>

        <a href="<?= $APP_ROOT ?>nurse/incidents.php" class="sidebar-item <?= $ACTIVE_NAV === 'nurse_incidents' ? 'active' : '' ?>">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span class="sidebar-text">Incidents</span>
        </a>

        <div class="sidebar-divider"></div>

        <div class="sidebar-brand">
            <div class="brand-title">Account</div>
        </div>

        <a href="<?= $APP_ROOT ?>settings.php" class="sidebar-item <?= $ACTIVE_NAV === 'settings' ? 'active' : '' ?>">
            <i class="fa-solid fa-gear"></i>
            <span class="sidebar-text">Account Settings</span>
        </a>

    </div><!-- end sidebar-nav -->

    <div class="sidebar-footer">
        <a href="<?= $APP_ROOT ?>logout.php" class="sidebar-item logout-link" id="logoutBtn" data-logout-url="<?= $APP_ROOT ?>logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span class="sidebar-text">Logout</span>
        </a>
    </div>

    <?php else: ?>

        <!-- GROUP 1 — Main Navigation -->
        <div class="sidebar-brand">
            <div class="brand-title">Main</div>
        </div>

        <a href="<?= $APP_ROOT ?>dashboard.php" class="sidebar-item <?= $ACTIVE_NAV === 'dashboard' ? 'active' : '' ?>">
            <i class="fa-solid fa-gauge-high"></i>
            <span class="sidebar-text">Dashboard</span>
        </a>

        <div class="sidebar-divider"></div>

        <!-- GROUP 2 — Registrar -->
        <div class="sidebar-brand">
            <div class="brand-title">Registrar</div>
        </div>

        <!-- Masterlist is the main working list for the office, so it
             sits directly under Dashboard rather than part-way down the
             group where it was easy to miss. -->
        <a href="<?= $APP_ROOT ?>registrar/masterlist.php" class="sidebar-item <?= $ACTIVE_NAV === 'masterlist' ? 'active' : '' ?>">
            <i class="fa-solid fa-table-list"></i>
            <span class="sidebar-text">Masterlist</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/students.php" class="sidebar-item <?= $ACTIVE_NAV === 'students' ? 'active' : '' ?>">
            <i class="fa-solid fa-user-graduate"></i>
            <span class="sidebar-text">Students</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/guardians.php" class="sidebar-item <?= $ACTIVE_NAV === 'guardians' ? 'active' : '' ?>">
            <i class="fa-solid fa-users"></i>
            <span class="sidebar-text">Guardians &amp; Contacts</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/rfid-cards.php" class="sidebar-item <?= $ACTIVE_NAV === 'rfid' ? 'active' : '' ?>">
            <i class="fa-solid fa-credit-card"></i>
            <span class="sidebar-text">RFID Cards</span>
        </a>


        <a href="<?= $APP_ROOT ?>registrar/rfid-scan-logs.php" class="sidebar-item <?= $ACTIVE_NAV === 'scanlogs' ? 'active' : '' ?>">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span class="sidebar-text">Scan Logs</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/documents.php" class="sidebar-item <?= $ACTIVE_NAV === 'documents' ? 'active' : '' ?>">
            <i class="fa-solid fa-file-lines"></i>
            <span class="sidebar-text">Documents</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/queue.php" class="sidebar-item <?= $ACTIVE_NAV === 'queue' ? 'active' : '' ?>">
            <i class="fa-solid fa-display"></i>
            <span class="sidebar-text">Queue</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/file-storage.php" class="sidebar-item <?= $ACTIVE_NAV === 'filestorage' ? 'active' : '' ?>">
            <i class="fa-solid fa-folder-open"></i>
            <span class="sidebar-text">File Storage</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/status-tracker.php" class="sidebar-item <?= $ACTIVE_NAV === 'tracker' ? 'active' : '' ?>">
            <i class="fa-solid fa-arrows-rotate"></i>
            <span class="sidebar-text">Status Tracker</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/health-records.php" class="sidebar-item <?= $ACTIVE_NAV === 'health' ? 'active' : '' ?>">
            <i class="fa-solid fa-heartbeat"></i>
            <span class="sidebar-text">Health Records</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/academic-history.php" class="sidebar-item <?= $ACTIVE_NAV === 'academic' ? 'active' : '' ?>">
            <i class="fa-solid fa-school"></i>
            <span class="sidebar-text">Academic History</span>
        </a>

        <div class="sidebar-divider"></div>

        <!-- GROUP 3 — AI Tools -->
        <div class="sidebar-brand">
            <div class="brand-title">AI Tools</div>
        </div>

        <a href="<?= $APP_ROOT ?>ai/insights.php" class="sidebar-item <?= $ACTIVE_NAV === 'insights' ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-line"></i>
            <span class="sidebar-text">Intelligent Analytics and Reports</span>
        </a>

        <?php if ($USER_ROLE !== 'admin'): ?>
        <div class="sidebar-divider"></div>

        <div class="sidebar-brand">
            <div class="brand-title">Account</div>
        </div>

        <a href="<?= $APP_ROOT ?>settings.php" class="sidebar-item <?= $ACTIVE_NAV === 'settings' ? 'active' : '' ?>">
            <i class="fa-solid fa-gear"></i>
            <span class="sidebar-text">Account Settings</span>
        </a>
        <?php endif; ?>

        <?php if ($USER_ROLE === 'admin'): ?>
        <div class="sidebar-divider"></div>

        <!-- GROUP 4 — System (admin only) -->
        <div class="sidebar-brand">
            <div class="brand-title">System</div>
        </div>

        <a href="<?= $APP_ROOT ?>settings.php" class="sidebar-item <?= $ACTIVE_NAV === 'settings' ? 'active' : '' ?>">
            <i class="fa-solid fa-gear"></i>
            <span class="sidebar-text">Settings</span>
        </a>
        <a href="<?= $APP_ROOT ?>registrar/users.php" class="sidebar-item <?= $ACTIVE_NAV === 'users' ? 'active' : '' ?>">
            <i class="fa-solid fa-user-gear"></i>
            <span class="sidebar-text">Users</span>
        </a>
        <a href="<?= $APP_ROOT ?>registrar/smtp-status.php" class="sidebar-item <?= $ACTIVE_NAV === 'smtp' ? 'active' : '' ?>">
            <i class="fa-solid fa-envelope-circle-check"></i>
            <span class="sidebar-text">SMTP Status</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/audit-logs.php" class="sidebar-item <?= $ACTIVE_NAV === 'audit' ? 'active' : '' ?>">
            <i class="fa-solid fa-file-shield"></i>
            <span class="sidebar-text">Audit Logs</span>
        </a>
        <?php endif; ?>

    </div><!-- end sidebar-nav -->

    <div class="sidebar-footer">
        <a href="<?= $APP_ROOT ?>logout.php" class="sidebar-item logout-link" id="logoutBtn" data-logout-url="<?= $APP_ROOT ?>logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span class="sidebar-text">Logout</span>
        </a>
    </div>

    <?php endif; ?>
</aside>

<!-- Logout Confirmation Modal -->
<div class="logout-modal-overlay" id="logoutModal">
    <div class="logout-modal">
        <div class="logout-modal-icon">
            <i class="fa-solid fa-right-from-bracket"></i>
        </div>
        <h3 class="logout-modal-title">Confirm Logout</h3>
        <p class="logout-modal-message">Are you sure you want to sign out of your account?</p>
        <div class="logout-modal-actions">
            <button type="button" class="logout-btn-cancel" id="logoutCancel">Cancel</button>
            <button type="button" class="logout-btn-confirm" id="logoutConfirm">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </button>
        </div>
    </div>
</div>

<!-- Notification Modal -->
<div class="logout-modal-overlay" id="notifModal">
    <div class="logout-modal" style="max-width: 460px;">
        <div class="logout-modal-icon" style="background: #dbeafe;">
            <i class="fa-solid fa-bell" style="color: #2563eb;"></i>
        </div>
        <h3 class="logout-modal-title">Notifications</h3>
        <div id="notifList" style="text-align: left; max-height: 320px; overflow-y: auto; margin-bottom: 16px;">
            <p style="color: #64748b; font-size: 14px; text-align: center;">No notifications yet.</p>
        </div>
        <div class="logout-modal-actions">
            <button type="button" class="logout-btn-cancel" id="notifClose">Close</button>
            <button type="button" class="logout-btn-confirm" id="notifMarkRead" style="background: #2563eb;">
                <i class="fa-solid fa-check"></i> Mark All as Read
            </button>
        </div>
    </div>
</div>

<!-- Styles -->
<style>
.logout-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(8px);
    z-index: 99999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.logout-modal-overlay.active { display: flex; }
.logout-modal {
    background: white;
    border-radius: 24px;
    padding: 36px 40px 32px;
    max-width: 420px;
    width: 100%;
    text-align: center;
    box-shadow: 0 24px 64px rgba(0,0,0,0.15);
    animation: slideUp 0.3s ease;
}
@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.logout-modal-icon {
    width: 64px; height: 64px;
    border-radius: 50%;
    background: #fee2e2;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
    font-size: 28px;
    color: #dc2626;
}
.logout-modal-title {
    font-size: 20px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 4px;
}
.logout-modal-message {
    font-size: 14px;
    color: #64748b;
    margin-bottom: 24px;
}
.logout-modal-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
}
.logout-modal-actions button {
    padding: 10px 28px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    font-family: inherit;
}
.logout-btn-cancel {
    background: #f1f5f9;
    color: #64748b;
}
.logout-btn-cancel:hover {
    background: #e2e8f0;
    color: #1e293b;
}
.logout-btn-confirm {
    background: #dc2626;
    color: white;
    display: flex;
    align-items: center;
    gap: 8px;
}
.logout-btn-confirm:hover {
    background: #b91c1c;
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(220,38,38,0.3);
}
@media (max-width: 480px) {
    .logout-modal { padding: 24px 20px; }
    .logout-modal-actions { flex-direction: column; }
    .logout-modal-actions button { width: 100%; justify-content: center; }
}

/* ── Notification Items ── */
.notif-item {
    display: flex;
    gap: 12px;
    padding: 12px;
    border-radius: 10px;
    background: #f8fafc;
    margin-bottom: 8px;
    transition: background 0.2s;
}
.notif-item:hover { background: #f1f5f9; }
.notif-item.unread { border-left: 3px solid #2563eb; }
.notif-icon {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: #dbeafe;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 14px;
}
.notif-content { flex: 1; }
.notif-title { font-size: 13px; font-weight: 600; color: #0f172a; margin-bottom: 2px; }
.notif-message { font-size: 12px; color: #64748b; line-height: 1.4; }
.notif-time { font-size: 11px; color: #94a3b8; margin-top: 4px; }
</style>

<!-- Logout modal wiring moved to js/logout.js -->
<!-- Notification Modal JavaScript -->
<script>
(function() {
    'use strict';

    var bellBtn = document.getElementById('bellBtn');
    var notifModal = document.getElementById('notifModal');
    var notifClose = document.getElementById('notifClose');
    var notifMarkRead = document.getElementById('notifMarkRead');
    var notifList = document.getElementById('notifList');
    var bellBadge = document.getElementById('bellBadge');

    var notifications = [];

    var USER_ROLE = '<?= $USER_ROLE ?>';

    // Resolved from the page's own $APP_ROOT. These endpoints used to be
    // hardcoded as '../api/...', which is only correct one directory deep:
    // root pages (dashboard.php, settings.php) sit at $APP_ROOT = './', so
    // the relative path climbed above the web root and 404'd — the bell was
    // silently dead there because failures are swallowed below.
    var API_ROOT = '<?= $APP_ROOT ?>api/';
    var FEED_URL = USER_ROLE === 'student'
        ? API_ROOT + 'student-notifications.php'
        : API_ROOT + 'notifications.php';

    // Notification text is authored by staff and can contain markup.
    // Escaped before it reaches innerHTML.
    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Read the CSRF token from the page and send it on the POST directly.
    // This used to rely entirely on js/csrf.js monkey-patching window.fetch
    // to attach the header, which silently depends on that script having
    // loaded and patched first. If it had not, the POST went out without a
    // token, the server rejected it with 419, and "mark all as read" did
    // nothing. Reading the token here makes the call self-sufficient.
    var CSRF_TOKEN = (function() {
        var meta = document.querySelector('meta[name=csrf-token]');
        return meta ? meta.getAttribute('content') || '' : '';
    })();

    function loadNotifications() {
        fetch(FEED_URL)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success && d.data) {
                    notifications = d.data;
                    renderNotifications();
                    if (typeof d.unread !== 'undefined') {
                        bellBadge.textContent = d.unread;
                        bellBadge.style.display = d.unread > 0 ? 'flex' : 'none';
                    }
                }
            })
            .catch(function() {});
    }

    function renderNotifications() {
        if (!notifList) return;
        var unreadCount = 0;
        if (!notifications.length) {
            notifList.innerHTML = '<p style="color:#64748b;font-size:14px;text-align:center;">No notifications yet.</p>';
            if (bellBadge) { bellBadge.textContent = '0'; bellBadge.style.display = 'none'; }
            return;
        }
        var html = '';
        notifications.forEach(function(n) {
            if (n.unread) unreadCount++;
            html += '<div class="notif-item ' + (n.unread ? 'unread' : '') + '" data-notif-id="' + esc(n.id) + '">' +
                '<div class="notif-icon"><i class="fas ' + esc(n.icon || 'fa-circle-info') + '"></i></div>' +
                '<div class="notif-content">' +
                    '<div class="notif-title">' + esc(n.title) + '</div>' +
                    '<div class="notif-message">' + esc(n.message) + '</div>' +
                    '<div class="notif-time">' + esc(n.time) + '</div>' +
                '</div>' +
            '</div>';
        });
        notifList.innerHTML = html;
        if (bellBadge) {
            bellBadge.textContent = unreadCount;
            bellBadge.style.display = unreadCount > 0 ? 'flex' : 'none';
        }
    }

    function openNotifModal(e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        if (notifModal) { notifModal.classList.add('active'); document.body.style.overflow = 'hidden'; }
    }

    function closeNotifModal() {
        if (notifModal) { notifModal.classList.remove('active'); document.body.style.overflow = ''; }
    }

    function markAllRead() {
        // The response body decides the outcome, not the promise resolving.
        // fetch() only rejects on a network failure — an HTTP 419 (CSRF) or
        // 500 still resolves. Clearing the badge on resolution alone made
        // "mark all as read" look like it worked while the server had
        // changed nothing, so the count came straight back on the next page.
        if (notifMarkRead) {
            notifMarkRead.disabled = true;
            notifMarkRead.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving';
        }

        fetch(FEED_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: 'action=read_all'
        }).then(function(r) {
            return r.json().catch(function() { return { success: false }; });
        }).then(function(d) {
            if (!d || d.success !== true) {
                throw new Error((d && d.message) || 'Could not mark notifications as read.');
            }
            notifications.forEach(function(n) { n.unread = false; });
            renderNotifications();
            closeNotifModal();
        }).catch(function(err) {
            // Leave the list untouched so the true state stays on screen,
            // and say why rather than closing as if it had worked.
            if (notifList) {
                var alreadyUnread = notifications.filter(function(n) { return n.unread; }).length;
                notifList.innerHTML = '<p style="color:#dc2626;font-size:14px;text-align:center;">'
                    + esc(err && err.message ? err.message : 'Could not mark notifications as read.')
                    + (alreadyUnread ? '<br><span style="color:#64748b;">' + alreadyUnread + ' notification' + (alreadyUnread === 1 ? '' : 's') + ' still unread.</span>' : '')
                    + '</p>';
            }
        }).finally(function() {
            if (notifMarkRead) {
                notifMarkRead.disabled = false;
                notifMarkRead.innerHTML = '<i class="fa-solid fa-check"></i> Mark All as Read';
            }
        });
    }

    if (bellBtn) bellBtn.addEventListener('click', openNotifModal);
    if (notifClose) notifClose.addEventListener('click', closeNotifModal);
    if (notifMarkRead) notifMarkRead.addEventListener('click', markAllRead);
    if (notifModal) notifModal.addEventListener('click', function(e) { if (e.target === this) closeNotifModal(); });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && notifModal && notifModal.classList.contains('active')) closeNotifModal();
    });

    loadNotifications();
})();
</script>
