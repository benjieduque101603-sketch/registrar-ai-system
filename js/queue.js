/* ============================================================
   JS/QUEUE.JS — Queue Management System
   Page-driven by <body data-page="kiosk|monitor|console|dashboard">.
   Polling is visibility-aware: paused while the tab is hidden.
   ============================================================ */
(function () {
    'use strict';

    var PAGE = document.body.getAttribute('data-page') || '';
    // Root pages (dashboard.php) sit at the app root; kiosk/monitor/console
    // pages live in /queue and /registrar subfolders, so resolve the API
    // directory based on the current page's depth — same ../api convention
    // the rest of the app uses.
    var depth = (window.location.pathname.match(/\//g) || []).length - 1;
    var API = (depth > 1 ? '../' : '') + 'api/queue-public.php';
    var API_AUTH = (depth > 1 ? '../' : '') + 'api/queue.php';

    // ── Polling helper ───────────────────────────────────────
    function startPoll(fn, ms, bindEl) {
        var timer = null;
        var paused = false;
        var stopped = false;
        function tick() {
            if (!paused) fn();
        }
        function onVis() {
            if (stopped) return;
            if (document.hidden) {
                paused = true;
                if (timer) { clearInterval(timer); timer = null; }
            } else {
                paused = false;
                if (timer === null) {
                    timer = setInterval(tick, ms);
                    fn(); // refresh immediately on return
                }
            }
        }
        document.addEventListener('visibilitychange', onVis);
        onVis();
        return { stop: function () {
            stopped = true;
            if (timer) { clearInterval(timer); timer = null; }
            document.removeEventListener('visibilitychange', onVis);
        } };
    }

    function fetchJson(url, opts) {
        return fetch(url, opts).then(function (r) { return r.json(); });
    }

    function pad(n) {
        n = parseInt(n, 10) || 0;
        return n < 10 ? '00' + n : (n < 100 ? '0' + n : '' + n);
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // ==========================================================
    //  KIOSK
    // ==========================================================
    if (PAGE === 'kiosk') {
        var cardInput = document.getElementById('cardInput');
        var activeScreen = 'tap';
        var boardTimer = null;
        var standingTimer = null;
        var submitting = false;

        function show(screen) {
            activeScreen = screen;
            var screens = ['tap', 'result', 'board', 'standing'];
            screens.forEach(function (s) {
                var el = document.getElementById('screen-' + s);
                if (el) el.style.display = (s === screen) ? 'block' : 'none';
            });
            document.querySelectorAll('.q-tab-btn').forEach(function (b) {
                b.classList.toggle('active', b.dataset.tab === screen);
            });
            if (screen === 'board') startBoard();
            else if (boardTimer) { boardTimer.stop(); boardTimer = null; }
        }

        function returnToTap(ms) {
            setTimeout(function () {
                if (activeScreen === 'result') show('tap');
                if (cardInput) cardInput.focus();
            }, ms || 6000);
        }

        function submitJoin(uid) {
            if (submitting) return;
            submitting = true;
            if (cardInput) cardInput.value = '';
            fetchJson(API + '?action=join', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ card_uid: uid })
            }).then(function (d) {
                renderResult(d);
                returnToTap(d.success || d.code === 'cooldown' ? 7000 : 4500);
            }).catch(function () {
                renderResult({ success: false, message: 'Network error. Please try again.', code: 'network' });
                returnToTap(4500);
            }).finally(function () { submitting = false; });
        }

        function renderResult(d) {
            var icon = document.getElementById('rIcon');
            var num = document.getElementById('rNumber');
            var name = document.getElementById('rName');
            var sub = document.getElementById('rSub');
            show('result');

            if (d.success) {
                icon.className = 'result-icon success fas fa-circle-check';
                icon.style.display = '';
                num.style.display = d.data ? '' : 'none';
                name.style.display = d.data ? '' : 'none';
                num.textContent = d.data ? d.data.display_number : '';
                name.textContent = d.data ? d.data.student_name : '';
                if (d.data && d.data.re_queued) {
                    sub.textContent = 'Your new number is ' + d.data.display_number + ' — line up at the back.';
                } else {
                    sub.textContent = d.data
                        ? 'You are #' + d.data.position + ' in line — please wait for your number to be called.'
                        : '';
                }
                document.getElementById('resultCard').className = 'result-card big-number';
            } else {
                num.style.display = 'none';
                name.style.display = 'none';
                if (d.code === 'already_queued' && d.data) {
                    // Student tapped again while already holding a live
                    // number. Show the number they already have rather than an
                    // error — the tap was harmless, not a failure.
                    icon.className = 'result-icon warn fas fa-hourglass-half';
                    icon.style.display = '';
                    sub.textContent = d.message;
                    name.style.display = '';
                    name.textContent = 'Your current number: ' + d.data.display_number;
                } else if (d.code === 'now_serving' && d.data) {
                    icon.className = 'result-icon success fas fa-circle-check';
                    icon.style.display = '';
                    num.style.display = '';
                    num.textContent = d.data.display_number;
                    name.style.display = '';
                    name.textContent = d.data.student_name;
                    sub.textContent = 'You are being served at Window ' + (d.data.counter || 1) + '.';
                } else if (d.code === 'cooldown' && d.data) {
                    icon.className = 'result-icon warn fas fa-hourglass-half';
                    icon.style.display = '';
                    sub.textContent = d.message;
                    name.style.display = '';
                    name.textContent = 'Your current number: ' + d.data.display_number;
                } else if (d.code === 'bounce') {
                    icon.className = 'result-icon info fas fa-clock';
                    icon.style.display = '';
                    sub.textContent = d.message;
                } else if (d.code === 'denied') {
                    icon.className = 'result-icon error fas fa-credit-card';
                    icon.style.display = '';
                    sub.textContent = d.message || 'Unable to process this card.';
                } else {
                    icon.className = 'result-icon error fas fa-ban';
                    icon.style.display = '';
                    sub.textContent = d.message || 'Unable to join the queue.';
                }
                document.getElementById('resultCard').className = 'result-card';
            }
        }

        function startBoard() {
            if (boardTimer) boardTimer.stop();
            function load() {
                fetchJson(API + '?action=board').then(function (d) {
                    if (!d.success || !d.data) return;
                    renderBoard(d.data);
                }).catch(function () {});
            }
            load();
            boardTimer = startPoll(load, 3000);
        }

        function renderBoard(data) {
            var el = document.getElementById('boardList');
            if (!el) return;
            var html = '';
            // One tile per window so the kiosk board matches the monitor —
            // a single "now serving" tile made it look like only one desk
            // existed, and hid the fact that other desks were busy.
            (data.windows || []).forEach(function (slot) {
                var s = slot.serving;
                html += '<div class="q-tile serving-tile win-tile' + (s ? '' : ' idle') + '">' +
                    '<div class="win-tag">Window ' + slot.window + '</div>' +
                    (s
                        ? '<div class="num">' + esc(s.display_number) + '</div>' +
                          '<div class="who"><div class="name">' + esc(s.student_name) + '</div><div class="pos">Now serving</div></div>'
                        : '<div class="num idle-num">—</div>' +
                          '<div class="who"><div class="name idle-name">Available</div><div class="pos">Ready</div></div>') +
                    '</div>';
            });
            (data.waiting || []).forEach(function (w) {
                html += '<div class="q-tile' + (w.next_up ? ' next-up' : '') + '">' +
                    '<div class="num">' + esc(w.number) + '</div>' +
                    '<div class="who"><div class="name">' + esc(w.name) + '</div>' +
                    '<div class="pos">' + (w.next_up ? 'Next up' : 'Position ' + w.position) + '</div></div></div>';
            });
            if (!html) html = '<div class="monitor-empty"><i class="fas fa-people-group"></i><p>No one is in line yet</p><span>The lineup will appear here</span></div>';
            el.innerHTML = html;
        }

        function doStandingCheck() {
            var numEl = document.getElementById('standingNumber');
            var n = (numEl ? numEl.value : '').trim();
            if (!n) return;
            fetchJson(API + '?action=my_ticket&number=' + encodeURIComponent(n)).then(function (d) {
                renderStanding(d);
            }).catch(function () {
                renderStanding({ success: false, message: 'Network error.' });
            });
        }

        function renderStanding(d) {
            var el = document.getElementById('standingResult');
            if (!el) return;
            if (!d.success || !d.data) {
                el.innerHTML = '<div style="color:#f87171;text-align:center;padding:20px;">' + esc(d.message || 'Number not found for today.') + '</div>';
                return;
            }
            var dt = d.data;
            var statusTxt = dt.status === 'waiting' ? 'Waiting in line' :
                            dt.status === 'serving' ? 'Now being served' :
                            dt.status === 'completed' ? 'Completed' :
                            dt.status === 'no-show' ? 'Marked as no-show' :
                            dt.status === 'cancelled' ? 'Cancelled' : 'Removed';
            var html = '<div class="q-tile you" style="margin-bottom:12px;">' +
                '<div class="num">' + esc(dt.display_number) + '</div>' +
                '<div class="who"><div class="name">' + esc(dt.student_name) + '</div>' +
                '<div class="pos">' + esc(statusTxt) + (dt.next_up ? ' — you are next!' : '') + '</div></div></div>';
            html += '<div style="font-size:14px;color:#cbd5e1;margin-bottom:10px;">';
            if (dt.status === 'waiting') {
                html += 'People ahead of you: <strong>' + dt.waiting_ahead + '</strong></div>';
            } else if (dt.status === 'serving') {
                html += 'You are being served now — proceed to the window.</div>';
            } else {
                html += 'This ticket has already been ' + statusTxt.toLowerCase() + '.</div>';
            }
            if ((dt.lineup || []).length) {
                html += '<div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;font-weight:700;margin:14px 0 8px;">Upcoming</div>';
                html += '<div class="q-grid">';
                (dt.lineup || []).forEach(function (l) {
                    html += '<div class="q-tile">' +
                        '<div class="num" style="font-size:18px;">' + esc(l.number) + '</div>' +
                        '<div class="who"><div class="name" style="font-size:13px;">' + esc(l.name) + '</div></div></div>';
                });
                html += '</div>';
            }
            el.innerHTML = html;
        }

        function bindKiosk() {
            // RFID keystroke capture: hidden input types the UID, Enter submits
            if (cardInput) {
                cardInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        var uid = cardInput.value.trim();
                        cardInput.value = '';
                        if (uid) submitJoin(uid);
                    }
                });
                cardInput.addEventListener('input', function () {
                    // A reader may not send Enter; submit when 10 digits are buffered
                    if (cardInput.value.length >= 10) {
                        var uid = cardInput.value.trim();
                        cardInput.value = '';
                        submitJoin(uid);
                    }
                });
            }
            document.querySelectorAll('.q-tab-btn').forEach(function (b) {
                b.addEventListener('click', function () {
                    if (b.dataset.tab === 'tap') show('tap');
                    else if (b.dataset.tab === 'board') show('board');
                    else if (b.dataset.tab === 'standing') show('standing');
                    if (cardInput) cardInput.focus();
                });
            });
            var checkBtn = document.getElementById('standingCheck');
            if (checkBtn) checkBtn.addEventListener('click', doStandingCheck);
            var enterBtn = document.getElementById('standingEnter');
            if (enterBtn) enterBtn.addEventListener('click', doStandingCheck);
            var numEl = document.getElementById('standingNumber');
            if (numEl) numEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') doStandingCheck(); });
        }

        bindKiosk();
        show('tap');
        if (cardInput) cardInput.focus();
    }

    // ==========================================================
    //  MONITOR
    // ==========================================================
    else if (PAGE === 'monitor') {
        var lastServing = {};

        function render(data) {
            var strip = document.getElementById('windowStrip');
            var waitList = document.getElementById('waitList');
            var recentList = document.getElementById('recentList');

            // One slot per window, always rendered — including idle desks, so
            // the board reads as "3 windows, 2 busy" instead of hiding that a
            // desk exists but is free. Each window animates on its OWN key; a
            // single shared key made the whole strip flash when any desk called.
            var slots = data.windows || [];
            if (strip) {
                var sh = '';
                slots.forEach(function (slot) {
                    var s = slot.serving;
                    var key = s ? (slot.window + ':' + s.number + ':' + s.name) : '';
                    var isNew = s && lastServing[slot.window] !== undefined && lastServing[slot.window] !== key;
                    lastServing[slot.window] = key;
                    sh += '<div class="win-slot' + (s ? ' busy' : ' idle') + (isNew ? ' calling' : '') + '">' +
                        '<div class="win-head"><i class="fas fa-door-open"></i> Window ' + slot.window + '</div>' +
                        (s
                            ? '<div class="win-num">' + esc(s.display_number) + '</div>' +
                              '<div class="win-name">' + esc(s.student_name) + '</div>' +
                              '<div class="win-state">Now serving</div>'
                            : '<div class="win-num idle-num">\u2014</div>' +
                              '<div class="win-name idle-name">Available</div>' +
                              '<div class="win-state">Ready</div>') +
                        '</div>';
                });
                strip.innerHTML = sh || '<div class="win-slot idle"><div class="win-head">Windows</div><div class="win-num idle-num">\u2014</div><div class="win-name idle-name">Loading</div></div>';
            }

            var wh = '';
            (data.waiting || []).forEach(function (w) {
                wh += '<div class="monitor-wait-row' + (w.next_up ? ' next-up' : '') + '">' +
                    '<span class="pos">' + (w.next_up ? 'Next' : '#' + w.position) + '</span>' +
                    '<span class="num">' + esc(w.number) + '</span>' +
                    '<span class="name">' + esc(w.name) + '</span></div>';
            });
            if (!wh) wh = '<div class="monitor-empty"><i class="fas fa-users"></i><p>No one is waiting</p></div>';
            waitList.innerHTML = wh;

            var rh = '';
            (data.recently_served || []).forEach(function (r) {
                var tagClass = r.status === 'completed' ? 'completed' : 'no-show';
                rh += '<div class="monitor-recent-row">' +
                    '<span class="num">' + esc(r.number) + '</span>' +
                    '<span class="name">' + esc(r.name) + '</span>' +
                    '<span class="tag ' + tagClass + '">' + esc(r.status) + '</span></div>';
            });
            if (!rh) rh = '<div class="monitor-empty"><i class="fas fa-clock"></i><p>No records yet</p></div>';
            recentList.innerHTML = rh;
        }

        function loadBoard() {
            fetchJson(API + '?action=board').then(function (d) {
                if (d.success && d.data) render(d.data);
            }).catch(function () {});
        }

        // Clock
        var clockEl = document.getElementById('clock');
        if (clockEl) {
            var tickClock = function () {
                clockEl.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            };
            tickClock();
            setInterval(tickClock, 1000);
        }

        loadBoard();
        startPoll(loadBoard, 3000);
    }

    // ==========================================================
    //  CONSOLE (registrar/queue.php)
    // ==========================================================
    else if (PAGE === 'console') {
        var skipTarget = null;

        // Toast helper (self-contained). The app's global showToast lives
        // inside js/auth.js's closure, so it is NOT accessible here.
        function showToast(message, type) {
            var container = document.getElementById('toastContainer');
            if (!container) return;
            var toast = document.createElement('div');
            toast.className = 'toast ' + (type || 'success');
            var icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', warning: 'fa-triangle-exclamation', info: 'fa-info-circle' };
            toast.innerHTML =
                '<i class="fas ' + (icons[type] || icons.success) + ' toast-icon"></i>' +
                '<div class="toast-content"><div class="toast-message">' + esc(message) + '</div></div>' +
                '<button class="toast-close"><i class="fas fa-times"></i></button>';
            container.appendChild(toast);
            toast.querySelector('.toast-close').addEventListener('click', function () { toast.remove(); });
            setTimeout(function () {
                toast.classList.add('hiding');
                setTimeout(function () { toast.remove(); }, 300);
            }, 4000);
        }

        function post(action, body) {
            return fetchJson(API_AUTH + '?action=' + action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body || {})
            });
        }

        function render(data) {
            var d = data;
            // Stats
            ['waiting', 'serving', 'completed', 'no_show'].forEach(function (k) {
                var el = document.getElementById('stat-' + k);
                if (el) el.textContent = (d.stats && d.stats[k] != null) ? d.stats[k] : 0;
            });

            var nsWinLbl = document.getElementById('nsWinLabel');
            if (nsWinLbl) nsWinLbl.textContent = d.my_window ? '— Window ' + d.my_window : '';

            // Window summary — all desks at a glance, so a registrar can see
            // which windows are free without switching the selector.
            var wstrip = document.getElementById('winStrip');
            if (wstrip) {
                var wh2 = '';
                (d.windows || []).forEach(function (slot) {
                    var s = slot.serving;
                    wh2 += '<div class="win-chip' + (s ? ' busy' : ' idle') + '">' +
                        '<span class="wc-n">W' + slot.window + '</span>' +
                        (s ? '<span class="wc-t">' + esc(s.display_number) + '</span>' +
                             '<span class="wc-name">' + esc(s.student_name) + '</span>'
                           : '<span class="wc-t idle-num">—</span><span class="wc-name idle-name">Available</span>') +
                        '</div>';
                });
                wstrip.innerHTML = wh2;
            }

            // Now serving card
            var panel = document.getElementById('nowServingBody');
            if (panel) {
                if (d.serving) {
                    document.getElementById('nsEmpty').style.display = 'none';
                    document.getElementById('nsContent').style.display = 'block';
                    document.getElementById('nsNumber').textContent = d.serving.display_number;
                    document.getElementById('nsName').textContent = d.serving.student_name;
                    document.getElementById('nsNumber2').textContent = d.serving.student_number || '—';
                    document.getElementById('nsCourse').textContent = d.serving.course || '—';
                    document.getElementById('nsElapsed').textContent = d.serving.called_at
                        ? elapsed(d.serving.called_at) : '—';
                    var nsWin = document.getElementById('nsWindow');
                    if (nsWin) { var w = d.serving.counter || 1; nsWin.textContent = 'Window ' + w; nsWin.parentElement.style.display = ''; }
                    document.getElementById('nsSkip').dataset.ticketId = d.serving.ticket_id;
                    document.getElementById('nsComplete').dataset.ticketId = d.serving.ticket_id;
                    var btnCall = document.getElementById('btnCallNext');
                    if (btnCall) btnCall.style.display = 'none';
                } else {
                    document.getElementById('nsEmpty').style.display = 'block';
                    document.getElementById('nsContent').style.display = 'none';
                    var nsWin2 = document.getElementById('nsWindow');
                    if (nsWin2) nsWin2.parentElement.style.display = 'none';
                    var btnCall2 = document.getElementById('btnCallNext');
                    if (btnCall2) btnCall2.style.display = '';
                }
            }

            // Waiting table
            var tbody = document.getElementById('waitingBody');
            if (tbody) {
                var html = '';
                if (!(d.waiting || []).length) {
                    html = '<tr class="empty-state-row"><td colspan="7" style="height:60vh;text-align:center;"><div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;"><i class="fas fa-people-group" style="font-size:40px;color:#cbd5e1;margin-bottom:12px;"></i><p style="font-size:15px;font-weight:600;color:#64748b;margin:0 0 4px;">No students waiting</p><span style="font-size:13px;color:#94a3b8;">Tickets appear here when students tap at the kiosk</span></div></td></tr>';
                } else {
                    (d.waiting || []).forEach(function (w) {
                        html += '<tr>' +
                            '<td><span class="chip blue">#' + w.position + '</span></td>' +
                            '<td><strong>' + esc(w.display_number) + '</strong></td>' +
                            '<td><div class="student-info"><div class="student-avatar blue">' + esc((w.student_name || '?').charAt(0).toUpperCase()) + '</div><div><div class="student-name">' + esc(w.student_name) + '</div></div></div></td>' +
                            '<td style="font-size:13px;">' + esc(w.student_number || '—') + '</td>' +
                            '<td style="font-size:13px;color:#64748b;">' + esc(w.course || '—') + '</td>' +
                            '<td style="font-size:12px;color:#64748b;">' + esc(timeAgo(w.joined_at)) + '</td>' +
                            '<td style="text-align:center;"><div class="action-group">' +
                            '<button class="action-btn delete" data-skip="' + w.ticket_id + '" data-name="' + esc(w.student_name) + '" title="Skip (not present)"><i class="fas fa-forward"></i></button>' +
                            '</div></td></tr>';
                    });
                }
                tbody.innerHTML = html;
                // Bind skip buttons
                tbody.querySelectorAll('button[data-skip]').forEach(function (b) {
                    b.addEventListener('click', function () { openSkip(b.dataset.skip, b.dataset.name); });
                });
            }

            // Completed table
            var cbody = document.getElementById('completedBody');
            if (cbody) {
                var ch = '';
                if (!(d.completed || []).length) {
                    ch = '<tr><td colspan="4" class="empty-state"><i class="fas fa-inbox"></i><p>Nothing served yet today</p></td></tr>';
                } else {
                    (d.completed || []).forEach(function (c) {
                        ch += '<tr>' +
                            '<td><strong>' + esc(c.display_number) + '</strong></td>' +
                            '<td>' + esc(c.student_name) + '</td>' +
                            '<td><span class="pill ' + (c.status === 'completed' ? 'active' : 'inactive') + '">' + esc(ucfirst(c.status)) + '</span></td>' +
                            '<td style="font-size:12px;color:#64748b;">' + esc(c.served_at ? timeAgo(c.served_at) : '—') + '</td></tr>';
                    });
                }
                cbody.innerHTML = ch;
            }
        }

        // Parse MySQL DATETIME 'YYYY-MM-DD HH:MM:SS' (server local, Asia/Manila)
        // into a local ms timestamp.
        function parseDbDt(dt) {
            if (!dt) return null;
            var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/.exec(String(dt));
            if (!m) return null;
            // Treat the wall-clock as Asia/Manila (UTC+8) then convert to local ms
            return Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]) - 8 * 3600 * 1000;
        }

        function elapsed(dt) {
            var ts = parseDbDt(dt);
            if (ts === null) return '—';
            var sec = Math.floor((Date.now() - ts) / 1000);
            if (sec < 0) return '0s';
            var m = Math.floor(sec / 60);
            var s = sec % 60;
            return (m > 0 ? m + 'm ' : '') + s + 's';
        }

        function timeAgo(dt) {
            var ts = parseDbDt(dt);
            if (ts === null) return '—';
            var sec = Math.floor((Date.now() - ts) / 1000);
            if (sec < 60) return 'just now';
            var m = Math.floor(sec / 60);
            if (m < 60) return m + 'm ago';
            return Math.floor(m / 60) + 'h ago';
        }

        function ucfirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }

        function openSkip(id, name) {
            skipTarget = id;
            document.getElementById('skipName').textContent = name;
            document.getElementById('skipModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeSkip() {
            skipTarget = null;
            document.getElementById('skipModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        window.queueCloseSkip = closeSkip;

        function bindConsole() {
            var btnCall = document.getElementById('btnCallNext');
            if (btnCall) btnCall.addEventListener('click', function () {
                btnCall.disabled = true;
                var winSel = document.getElementById('windowSelect'); var winNum = winSel ? parseInt(winSel.value, 10) || 1 : 1;
                post('call_next', { window: winNum }).then(function (d) {
                    if (d.success) showToast(d.message, 'success');
                    else showToast(d.message || 'Error.', 'error');
                }).catch(function () { showToast('Network error.', 'error'); })
                .finally(function () { btnCall.disabled = false; });
            });

            var nsComplete = document.getElementById('nsComplete');
            if (nsComplete) nsComplete.addEventListener('click', function () {
                var id = nsComplete.dataset.ticketId;
                if (!id) return;
                post('complete', { ticket_id: parseInt(id, 10) }).then(function (d) {
                    if (d.success) showToast(d.message, 'success');
                    else showToast(d.message || 'Error.', 'error');
                }).catch(function () { showToast('Network error.', 'error'); });
            });

            var nsSkip = document.getElementById('nsSkip');
            if (nsSkip) nsSkip.addEventListener('click', function () {
                var id = nsSkip.dataset.ticketId;
                if (!id) return;
                openSkip(id, document.getElementById('nsName').textContent);
            });

            var skipConfirm = document.getElementById('skipConfirm');
            if (skipConfirm) skipConfirm.addEventListener('click', function () {
                if (!skipTarget) return;
                skipConfirm.disabled = true;
                post('skip', { ticket_id: skipTarget }).then(function (d) {
                    if (d.success) showToast(d.message, 'success');
                    else showToast(d.message || 'Error.', 'error');
                    closeSkip();
                }).catch(function () { showToast('Network error.', 'error'); closeSkip(); })
                .finally(function () { skipConfirm.disabled = false; });
            });

            var skipCancel = document.getElementById('skipCancel');
            if (skipCancel) skipCancel.addEventListener('click', closeSkip);
            var skipModal = document.getElementById('skipModal');
            if (skipModal) skipModal.addEventListener('click', function (e) { if (e.target === this) closeSkip(); });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { closeSkip(); }
            });
        }

        function loadState() {
            // Scope the poll to the registrar's own window so the "now serving"
            // card shows the person at THIS desk. Without it the server fell
            // back to the globally-newest serving ticket, so two desks open at
            // once each saw the other's student.
            var ws = document.getElementById('windowSelect');
            var w = ws ? (parseInt(ws.value, 10) || 1) : 1;
            fetchJson(API_AUTH + '?action=state&window=' + w).then(function (d) {
                if (d.success && d.data) render(d.data);
            }).catch(function () {});
        }

        // Persist window selection
        var winSel = document.getElementById('windowSelect');
        if (winSel) {
            var saved = localStorage.getItem('queue_window');
            if (saved && [1,2,3].indexOf(parseInt(saved,10)) !== -1) winSel.value = saved;
            winSel.addEventListener('change', function() {
                localStorage.setItem('queue_window', this.value);
                // Switching desks must repaint the now-serving card for the new
                // window immediately, not on the next 3 s poll.
                loadState();
            });
        }
        bindConsole();
        loadState();
        startPoll(loadState, 3000);
    }

    // ==========================================================
    //  DASHBOARD (Live Queue widget)
    // ==========================================================
    else if (PAGE === 'dashboard') {
        function loadWidget() {
            fetchJson(API_AUTH + '?action=state').then(function (d) {
                if (!d.success || !d.data) return;
                var el = document.getElementById('liveQueueWidget');
                if (!el) return;
                var st = d.data.stats || {};
                var serving = d.data.serving;
                var html = '<div class="lq-row"><span>Waiting</span><strong>' + (st.waiting || 0) + '</strong></div>' +
                    '<div class="lq-row"><span>Now serving</span>' +
                    (serving
                        ? '<strong class="lq-big">' + esc(serving.display_number) + '</strong>'
                        : '<span style="color:#94a3b8;">—</span>') +
                    '</div>' +
                    '<div class="lq-row"><span>Completed today</span><strong>' + (st.completed || 0) + '</strong></div>';
                if (serving) html += '<div class="lq-row"><span style="color:#64748b;">' + esc(serving.student_name) + '</span></div>';
                el.innerHTML = html;
            }).catch(function () {});
        }
        loadWidget();
        startPoll(loadWidget, 5000);
    }

})();
