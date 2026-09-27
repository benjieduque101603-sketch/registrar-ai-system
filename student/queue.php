<?php
// ============================================================
//  STUDENT/QUEUE.PHP
//  Student's own queue ticket — number, status, position,
//  who's serving, and how many are ahead.
//  Live data via api/student-queue.php (identity-based).
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';

$page_title = 'My Queue';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_queue';
$extra_css = ['student.css'];

require_once __DIR__ . '/_guard.php';
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <header class="header">
            <div class="title"><h1>My Queue</h1><p>See where you are in line at the Registrar's Office.</p></div>
        </header>

        <div class="panel" style="background:transparent;border:none;box-shadow:none;">
            <div id="queuePanel">
                <div class="ticket-hero">
                    <div class="ticket-top" style="background:linear-gradient(120deg,#f8fafc,#eef4ff);">
                        <div class="t-label" style="color:#2563eb;">Checking your queue status</div>
                        <div style="color:#2563eb;font-size:40px;font-weight:800;margin-top:10px;"><i class="fa-solid fa-spinner fa-spin"></i></div>
                    </div>
                    <div class="ticket-body" style="text-align:center;color:#64748b;">Loading your queue ticket...</div>
                </div>
            </div>
        </div>

    </div>
</main>

<!-- This page builds its own shell rather than using includes/header.php,
     so the shared confirm dialog is loaded here explicitly. It must come
     before the script below, which calls confirmAction(). -->
<script src="<?= $APP_ROOT ?>js/confirm.js?v=<?= is_file(__DIR__ . '/../js/confirm.js') ? filemtime(__DIR__ . '/../js/confirm.js') : time() ?>"></script>

<script>
const queuePanel = document.getElementById('queuePanel');
const STATUS_META = {
    waiting:   { label: 'Waiting',      cls: 'pending',   icon: 'clock' },
    serving:   { label: 'Now Serving',  cls: 'active',    icon: 'bell-concierge' },
    completed: { label: 'Completed',    cls: 'completed', icon: 'circle-check' },
    'no-show': { label: 'No-show',      cls: 'inactive',  icon: 'user-clock' },
    removed:   { label: 'Removed',      cls: 'denied',    icon: 'ban' },
    cancelled: { label: 'Cancelled',    cls: 'denied',    icon: 'ban' }
};

function statusBadge(status) {
    const m = STATUS_META[status] || { label: status, cls: 'pending', icon: 'clock' };
    return '<span class="pill ' + m.cls + '"><i class="fa-solid fa-' + m.icon + '"></i> ' + m.label + '</span>';
}

function render(d) {
    if (!d || !d.ticket) {
        queuePanel.innerHTML =
            '<div class="ticket-hero">' +
                '<div class="ticket-top">' +
                    '<div class="t-label">Registrar Queue</div>' +
                    '<div style="font-size:56px;line-height:1.1;color:#fff;margin:4px 0;"><i class="fa-solid fa-ticket"></i></div>' +
                    '<div class="t-label" style="color:rgba(255,255,255,.85);">No queue ticket today</div>' +
                '</div>' +
                '<div class="ticket-body">' +
                    '<div class="ticket-note">You have not joined the queue at the Registrar\'s Office today.<br>' +
                    'If you need assistance, tap your RFID card at the kiosk to get a number.</div>' +
                    '<div class="ticket-actions">' +
                        '<a href="queue-public.php?action=join" class="btn btn-primary" style="display:none;">Join the queue</a>' +
                        '<button class="btn btn-primary" onclick="refreshQueue()"><i class="fa-solid fa-rotate"></i> Refresh</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        return;
    }
    const t = d.ticket;
    const serving = d.serving;

    let statusChip = '';
    if (t.status === 'waiting') {
        statusChip =
            '<span class="pill pending" style="font-size:13px;padding:5px 14px;"><i class="fa-solid fa-user-clock"></i> Position ' + t.position + ' in line</span>' +
            (t.next_up ? '<span class="pill active" style="font-size:13px;padding:5px 14px;"><i class="fa-solid fa-star"></i> You are next!</span>' : '');
    }

    let statusNote = '';
    if (t.status === 'waiting') {
        statusNote = t.next_up
            ? 'You are <strong>NEXT</strong> — please stay near the Registrar\'s Office.'
            : 'There ' + (t.waiting_ahead === 1 ? 'is' : 'are') + ' <strong>' + t.waiting_ahead + '</strong> student' + (t.waiting_ahead === 1 ? '' : 's') + ' ahead of you.';
    } else if (t.status === 'serving') {
        statusNote = 'It\'s your turn — please proceed to <strong><span class="win-badge">Window ' + (t.counter || 1) + '</span></strong>.';
    } else {
        statusNote = 'Your ticket is <strong>' + (STATUS_META[t.status] || {label: t.status}).label + '</strong>.';
    }

    // Date + time joined / completed lines.
    const whenJoined = (t.joined_date || t.joined_time)
        ? '<div style="text-align:center;font-size:12px;color:#94a3b8;margin-top:12px;"><i class="fa-solid fa-clock"></i> ' +
            'Joined ' + (t.joined_date || '') + (t.joined_time ? ' at ' + t.joined_time : '') + '</div>'
        : '';
    const whenServed = (t.served_date || t.served_time)
        ? '<div style="text-align:center;font-size:12px;color:#64748b;margin-top:4px;"><i class="fa-solid fa-circle-check"></i> ' +
            (t.status === 'cancelled' ? 'Cancelled' : 'Time Completed') + ' · ' + (t.served_date || '') + (t.served_time ? ' at ' + t.served_time : '') + '</div>'
        : '';

    const cancelBtn = (t.status === 'waiting')
        ? '<button class="btn btn-light btn-danger-text" onclick="cancelTicket()"><i class="fa-solid fa-ban"></i> Cancel my ticket</button>'
        : '';

    queuePanel.innerHTML =
        '<div class="ticket-hero">' +
            '<div class="ticket-top">' +
                '<div class="t-label">Your queue number</div>' +
                '<div class="t-number">#' + t.display_number + '</div>' +
                '<div class="ticket-status-line">' + statusBadge(t.status) + (statusChip || '') + '</div>' +
            '</div>' +
            '<div class="ticket-body">' +
                '<div class="ticket-note">' + statusNote + '</div>' +
                (serving
                    ? '<div class="ticket-serving"><span class="chip blue"><i class="fa-solid fa-bell-concierge"></i> Now serving #' + serving.number + (serving.counter ? ' — Window ' + serving.counter : '') + '</span>' +
                        (serving.name ? '<span style="color:#475569;">' + serving.name + '</span>' : '') + '</div>'
                    : '') +
                whenJoined + whenServed +
                '<div class="ticket-actions">' +
                    '<button class="btn btn-light" onclick="refreshQueue()"><i class="fa-solid fa-rotate"></i> Refresh (30s auto)</button>' + cancelBtn +
                '</div>' +
            '</div>' +
        '</div>';
}

async function cancelTicket() {
    if (!await confirmAction({
        title: 'Cancel queue ticket',
        body: 'Cancel your current queue ticket? You will lose your place in line.',
        confirmLabel: 'Cancel ticket',
        tone: 'danger'
    })) return;
    fetch('../api/student-queue.php?action=cancel', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            if (d.success) { refreshQueue(); showToast('Ticket cancelled.', 'success'); }
            else { showToast(d.message || 'Unable to cancel your ticket.', 'error'); }
        })
        .catch(() => showToast('Unable to cancel your ticket. Please try again.', 'error'));
}

function refreshQueue() {
    fetch('../api/student-queue.php')
        .then(r => r.json())
        .then(d => render(d.success ? d.data : null))
        .catch(() => {
            queuePanel.innerHTML =
                '<div class="ticket-hero"><div class="ticket-body" style="text-align:center;">' +
                    '<p style="color:#dc2626;margin:0 0 12px;"><i class="fa-solid fa-triangle-exclamation"></i> Unable to load your queue status.</p>' +
                    '<button class="btn btn-light" onclick="refreshQueue()"><i class="fa-solid fa-rotate"></i> Retry</button>' +
                '</div></div>';
        });
}

refreshQueue();
setInterval(refreshQueue, 30000); // auto-refresh every 30s
</script>

<?php include '../includes/footer.php'; ?>