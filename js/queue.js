/* ============================================================
   JS/QUEUE.JS — Queue Management System
   Page-driven by <body data-page="kiosk|monitor|console|dashboard">.
   Polling is visibility-aware: paused while the tab is hidden.
   ============================================================ */
(function () {
    'use strict';

    var PAGE = document.body.getAttribute('data-page') || '';

    // Where the API lives, as told by the server that rendered this page.
    //
    // It used to be guessed from the URL:
    //
    //     var depth = (window.location.pathname.match(/\//g) || []).length - 1;
    //     var API = (depth > 1 ? '../' : '') + 'api/queue-public.php';
    //
    // which is right on exactly one deployment shape. The app is mounted one
    // level deep on localhost (/registrar-ai-system/queue/monitor.php → depth
    // 2 → '../api/…') and at the domain root on the live host
    // (/queue/monitor.php → depth 1 → 'api/…', resolved against /queue/ →
    // /queue/api/queue-public.php). So on production every call from the
    // monitor and the serving console 404'd, and the kiosk's tap-in got
    // 'Network error' because a 404 HTML page is not JSON. The pages are
    // public and cannot ask PHP for anything, so they pass it down in
    // data-api-base instead of letting JS re-derive it.
    //
    // The heuristic survives only as a fallback, so a page that forgets the
    // attribute still works on a subdirectory install.
    var API_BASE = document.body.getAttribute('data-api-base') || '';
    if (!API_BASE) {
        var depth = (window.location.pathname.match(/\//g) || []).length - 1;
        API_BASE = (depth > 1 ? '../' : '') + 'api/';
        console.warn('[queue] no data-api-base on <body>; fell back to guessing from the URL.');
    }
    var API = API_BASE + 'queue-public.php';
    var API_AUTH = API_BASE + 'queue.php';

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

    // One place where a queue request can fail, so one place that says WHY.
    //
    // It used to be:
    //     return fetch(url, opts).then(function (r) { return r.json(); });
    //
    // which collapses three very different failures into the same rejection,
    // and the pages then showed "Network error." to a student standing at a
    // kiosk:
    //
    //   · the API is at the wrong URL  -> 404, body is HTML, .json() throws
    //   · the API is unreachable       -> fetch rejects
    //   · the API threw                -> 500, body is HTML or JSON
    //
    // The 404 case is the one that bit production: the client had guessed the
    // API path wrong, asked for /queue/api/queue-public.php, and the reported
    // symptom was an unhelpful one-liner while the real fault - a URL that does
    // not exist - was invisible without a network tab and the server logs.
    //
    // So the status and the URL now travel with the error. Anything that shows
    // the message to a user, or prints it to the console, names the request
    // that failed, which is reportable by someone with no server access.
    // Report a failed queue request once, with enough detail to act on.
    //
    // Five of the call sites used to end in `.catch(function () {})`, which is
    // the reason this class of fault was so hard to pin down: the monitor
    // simply stayed blank, and the console showed nothing. A silent catch is
    // only acceptable when nothing could be done about the failure; here the
    // URL and status are usually the whole answer.
    //
    // Deduplicated, because two of these run on a 3-second poll and an
    // unchanged 404 would otherwise fill the console faster than anyone reads.
    var lastReported = {};
    function reportQueueError(err, where) {
        var msg = (err && err.message) || String(err);
        var key = where + '|' + msg;
        if (lastReported[key]) return;
        lastReported[key] = true;
        console.error('[queue] ' + where + ': ' + msg);
    }

    // Short, readable form for something shown on a wall display or read over
    // a student's shoulder. Keeps the status code - "error 404" is something a
    // person can report - and drops the URL and body, which are noise there.
    function shortReason(err) {
        var msg = (err && err.message) || String(err || '');
        var code = msg.match(/HTTP (\d{3})/);
        if (code) return "Can't reach the queue service (error " + code[1] + '). Please see the registrar.';
        return "Can't reach the queue service. Please see the registrar.";
    }

    function fetchJson(url, opts) {
        return fetch(url, opts).then(function (r) {
            return r.text().then(function (body) {
                if (r.ok) {
                    try {
                        return JSON.parse(body);
                    } catch (e) {
                        throw new Error('Bad JSON from ' + url + ' (HTTP ' + r.status + ')');
                    }
                }
                var snippet = body.replace(/\s+/g, ' ').trim().slice(0, 120);
                throw new Error('HTTP ' + r.status + ' from ' + url
                    + (snippet ? ' - ' + snippet : ''));
            });
        }, function (netErr) {
            throw new Error('Cannot reach ' + url + ' (' + (netErr && netErr.message || 'network error') + ')');
        });
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

    // The lane class that colours a window slot (monitor) or chip
    // (console). Priority wins over the service/claim tint, because
    // "is this the amber one" is the question a student scans the wall
    // to answer; the transaction type is the secondary one.
    //
    // One function, read by both boards, so the monitor and the console
    // can never disagree about what a colour means.
    function laneTone(txn, prio) {
        if (prio === 'priority') return 'lane-priority';
        return txn === 'claim' ? 'lane-claim' : 'lane-service';
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
            var screens = ['tap', 'result', 'board', 'standing', 'pick', 'closed'];
            screens.forEach(function (s) {
                var el = document.getElementById('screen-' + s);
                if (!el) return;
                var on = (s === screen);
                el.style.display = on ? 'block' : 'none';
                // A class, not a style attribute, so the closed sign's
                // entrance animation can key off it. Matching on
                // `[style*="display: none"]` would be reading the browser's
                // serialised inline style back out of the DOM, which breaks
                // the moment the value is written differently.
                el.classList.toggle('is-open', on);
            });
            // pick/closed are not tab destinations, so every tab reads
            // inactive while either is up. Toggling by data-tab alone
            // would leave "Tap Card" lit behind the lane picker.
            document.querySelectorAll('.q-tab-btn').forEach(function (b) {
                b.classList.toggle('active', b.dataset.tab === screen);
            });
            if (screen === 'board') startBoard();
            else if (boardTimer) { boardTimer.stop(); boardTimer = null; }
            // Back to the tap prompt: put the reader's focus back, or the
            // next tap is swallowed by whatever the last click left focused.
            if (screen === 'tap' && cardInput) cardInput.focus();
        }

        function returnToTap(ms) {
            setTimeout(function () {
                if (activeScreen === 'result') show('tap');
                if (cardInput) cardInput.focus();
            }, ms || 6000);
        }

        // The lane flow. Two questions, asked in order, because the kiosk is
// read from a distance and a four-way grid forces someone to read four
// labels before they can commit to one.
//
//   step 1  WHAT   service | claim
//   step 2  WHO    student | priority
//
// The two combine into exactly one of the four desks. Keeping the
// questions separate (rather than four buttons) is what makes a
// mis-tap recoverable in one press instead of four.
var LANE_STEPS = [
    {
        key: 'txn_type',
        step: '1',
        question: 'What do you need?',
        sub: 'Choose one to continue.',
        choices: [
            { letter: 'A', tone: 'service', title: 'Service',
              meta: 'Enrolment, payments, records, and other registrar work.',
              value: 'service' },
            { letter: 'B', tone: 'claim', title: 'Claim',
              meta: 'Pick up a document you already filed for.',
              value: 'claim' }
        ]
    },
    {
        key: 'priority_group',
        step: '2',
        question: 'Are you a priority client?',
        sub: 'Choose the one that applies to you.',
        choices: [
            { letter: 'A', tone: 'service', title: 'Student',
              meta: 'Joining as a regular student.',
              value: 'student' },
            { letter: 'B', tone: 'priority', title: 'Priority',
              meta: 'PWD · Senior Citizen · Pregnant · Parent',
              value: 'priority' }
        ]
    }
];

// Pending tap: the card read but not yet turned into a ticket.
var pendingUid = null;
var laneStep = 0;
var laneChoice = { txn_type: null, priority_group: null };
var queueClosed = false;   // set from the board feed
var closedInfo = null;

function renderLaneStep() {
    var cfg = LANE_STEPS[laneStep];
    document.getElementById('laneStep').textContent = cfg.step;
    document.getElementById('laneQuestion').textContent = cfg.question;
    document.getElementById('laneSub').textContent = cfg.sub;

    var box = document.getElementById('laneChoices');
    box.innerHTML = '';
    cfg.choices.forEach(function (c) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'lane-choice t-' + c.tone;
        btn.innerHTML =
            '<span class="lane-letter">' + esc(c.letter) + '</span>' +
            '<span class="lane-body">' +
                '<span class="lane-title">' + esc(c.title) + '</span>' +
                '<span class="lane-meta">' + esc(c.meta) + '</span>' +
            '</span>' +
            '<i class="fas fa-chevron-right lane-go"></i>';
        btn.addEventListener('click', function () { pickLane(c.value); });
        box.appendChild(btn);
    });

    var back = document.getElementById('laneBack');
    // On the first question there is nowhere to go back to, so the
    // control is hidden rather than shown and inert.
    if (laneStep === 0) {
        back.style.display = 'none';
    } else {
        back.style.display = '';
        document.getElementById('laneBackLabel').textContent = LANE_STEPS[0].question;
    }
}

function pickLane(value) {
    var cfg = LANE_STEPS[laneStep];
    laneChoice[cfg.key] = value;

    if (laneStep < LANE_STEPS.length - 1) {
        laneStep++;
        renderLaneStep();
        return;
    }
    submitJoin(pendingUid);
}

function laneBack() {
    if (laneStep === 0) return;
    laneStep--;
    renderLaneStep();
}

// The closed sign replaces the tap prompt entirely, so nobody starts a
// tap that cannot finish and then gets an error card in front of a line
// of people. Three closed states, three titles: "not open yet" and
// "closed for today" are different facts and a student told the wrong
// one turns up at the wrong time tomorrow.
var CLOSED_TITLES = {
    before_open: 'The queue has not opened yet',
    after_close: 'The queue is closed for today',
    forced: 'The queue is closed'
};

// The sentence under the title, mirroring queueClosedMessage() in
// api/queue-public.php. The board poll returns a reason and a time but no
// ready-made sentence, so without this the kiosk fell back to the generic
// "The queue is closed right now." even for "has not opened yet" — the exact
// case where the student most needs to be told to come back later, and the one
// most likely to send them to the counter to ask. Two copies of one rule is a
// cost; a student turning up at the wrong time is a bigger one.
var CLOSED_MESSAGES = {
    before_open: function (when) {
        return 'The queue opens at ' + (when || '—') + '. Please come back then.';
    },
    after_close: function (when) {
        return 'The queue closed at ' + (when || '—') + '. Please come back tomorrow.';
    },
    forced: function () {
        return 'The queue was closed today. Numbers already issued are still being served.';
    }
};

function closedMessageFor(reason, when) {
    var f = CLOSED_MESSAGES[reason];
    return f ? f(when) : 'The queue is closed right now.';
}

function showClosed(reason, message, when) {
    queueClosed = true;
    closedInfo = { reason: reason, at: when };
    document.getElementById('closedTitle').textContent = CLOSED_TITLES[reason] || 'The queue is closed';
    // An explicit message from the server wins — the join path sends a
    // considered sentence. Otherwise derive one from the reason and time, so
    // a sign raised by the poll is as specific as one raised by a refused tap.
    document.getElementById('closedMessage').textContent = message || closedMessageFor(reason, when);
    document.getElementById('closedWhen').textContent = '';
    show('closed');
}

// Called from the board poll, which runs on a short interval, so the
// sign appears on its own the moment a registrar hits CUT OFF — nobody
// has to be at the kiosk to trigger it.
function refreshClosed(data) {
    var wasClosed = queueClosed;
    queueClosed = !!(data && data.queue_closed);

    if (queueClosed) {
        var when = data.closed_at ? queueTimeLabel(data.closed_at) : '';
        // Re-render only when it would change something. The poll runs
        // every few seconds; rewriting the DOM each time would restart
        // any text selection and is wasted work.
        if (!wasClosed || !closedInfo || closedInfo.reason !== data.closed_reason
            || closedInfo.at !== data.closed_at) {
            showClosed(data.closed_reason, null, when);
        } else if (when) {
            document.getElementById('closedWhen').textContent = when;
        }
        return;
    }

    // Reopened: drop back to the tap prompt and clear the stale copy so
    // the next close does not show yesterday's reason.
    closedInfo = null;
    document.getElementById('closedMessage').textContent = 'The queue is closed right now.';
    document.getElementById('closedTitle').textContent = 'The queue is closed';
    if (activeScreen === 'closed' || activeScreen === 'pick') show('tap');
}

// "17:00:00" / "2026-02-10 17:00:00" -> "5:00 PM"
function queueTimeLabel(t) {
    if (!t) return '';
    var s = String(t);
    var d = new Date(s.indexOf(' ') > 0 ? s.replace(' ', 'T') : s);
    if (isNaN(d.getTime())) return '';
    var h = d.getHours();
    var m = d.getMinutes();
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12;
    if (h === 0) h = 12;
    return h + ':' + (m < 10 ? '0' : '') + m + ' ' + ampm;
}

function showLanePicker(uid) {
    pendingUid = uid;
    laneStep = 0;
    laneChoice = { txn_type: null, priority_group: null };
    renderLaneStep();
    show('pick');
}

function submitJoin(uid) {
    if (!uid || submitting) return;
    submitting = true;
    if (cardInput) cardInput.value = '';
    fetchJson(API + '?action=join', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            card_uid: uid,
            txn_type: laneChoice.txn_type || 'service',
            priority_group: laneChoice.priority_group || 'student'
        })
    }).then(function (d) {
        renderResult(d);
        returnToTap(d.success || d.code === 'cooldown' ? 7000 : 4500);
    }).catch(function (e) {
        // The tap reached nothing, so say why rather than "Network
        // error" - and keep the detail in the console for whoever is
        // standing at the machine.
        reportQueueError(e, 'kiosk join');
        renderResult({ success: false, message: shortReason(e), code: 'network' });
        returnToTap(4500);
    }).finally(function () { submitting = false; }
    );
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
                // Which desk, in words a student can act on. "Window 2" on its own is
                // not enough once there are four desks with different jobs —
                // the label says what that desk is FOR.
                var where = (d.data && d.data.window)
                    ? ' — go to Window ' + d.data.window + ' (' + d.data.lane_label + ')'
                    : '';
                if (d.data && d.data.re_queued) {
                    sub.textContent = 'Your new number is ' + d.data.display_number + ' — line up at the back' + where + '.';
                } else {
                    sub.textContent = d.data
                        ? 'You are #' + d.data.position + ' in line for ' + (d.data.lane_label || 'Service')
                          + where + '. Please wait for your number.'
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
                } else if (d.code === 'queue_closed') {
                    // The queue shut between the card read and the join.
                    // Show the sign rather than a red error card: the
                    // student's number was never the problem.
                    icon.className = 'result-icon warn fas fa-clock';
                    icon.style.display = '';
                    sub.textContent = d.message || 'The queue is closed.';
                } else if (d.code === 'tap_limit') {
                    icon.className = 'result-icon warn fas fa-hourglass-half';
                    icon.style.display = '';
                    sub.textContent = d.message || 'You have used all of your numbers for today.';
                } else if (d.code === 'bad_lane') {
                    icon.className = 'result-icon error fas fa-circle-question';
                    icon.style.display = '';
                    sub.textContent = d.message || 'That option is not available.';
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
                }).catch(function (e) { reportQueueError(e, 'kiosk board'); });
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
            }).catch(function (e) {
                reportQueueError(e, 'standing lookup');
                renderStanding({ success: false, message: shortReason(e) });
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
            function beginTap(uid) {
                if (!uid) return;
                // The picker comes BEFORE the join. A tap while the queue
                // is closed must not open a picker that leads to a
                // guaranteed failure — it goes straight to the sign.
                if (queueClosed) {
                    showClosed(closedInfo && closedInfo.reason, null,
                        closedInfo && closedInfo.at);
                    return;
                }
                showLanePicker(uid);
            }

            // RFID keystroke capture: hidden input types the UID, Enter submits
            if (cardInput) {
                cardInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        var uid = cardInput.value.trim();
                        cardInput.value = '';
                        if (uid) beginTap(uid);
                    }
                });
                cardInput.addEventListener('input', function () {
                    // A reader may not send Enter; submit when 10 digits are buffered
                    if (cardInput.value.length >= 10) {
                        var uid = cardInput.value.trim();
                        cardInput.value = '';
                        beginTap(uid);
                    }
                });
            }
            document.querySelectorAll('.q-tab-btn').forEach(function (b) {
                b.addEventListener('click', function () {
                    // The Tap Card tab must not talk the kiosk out of the
                    // closed sign. It did, and the result was a student being
                    // invited to tap a card while the queue was shut: the poll
                    // had put the sign up, one tap on the tab threw it away,
                    // and the tap was then refused at the last step with a
                    // card they were already holding. The two other tabs stay
                    // available on purpose — reading the board or checking a
                    // number still work when no numbers are being issued.
                    if (b.dataset.tab === 'tap') {
                        if (queueClosed) { showClosed(closedInfo && closedInfo.reason, null, closedInfo && closedInfo.at); return; }
                        show('tap');
                    }
                    else if (b.dataset.tab === 'board') show('board');
                    else if (b.dataset.tab === 'standing') show('standing');
                    if (cardInput) cardInput.focus();
                });
            });

            var laneBackBtn = document.getElementById('laneBack');
            if (laneBackBtn) laneBackBtn.addEventListener('click', laneBack);

            // Keyboard shortcuts for the picker. A reader keyboard
            // (and the tab bar on a touch kiosk) both work better with
            // A/B than with a hunt for a button.
            document.addEventListener('keydown', function (e) {
                if (activeScreen !== 'pick') return;
                var k = (e.key || '').toUpperCase();
                if (k !== 'A' && k !== 'B') return;
                var cfg = LANE_STEPS[laneStep];
                var idx = k === 'A' ? 0 : 1;
                if (cfg.choices[idx]) { e.preventDefault(); pickLane(cfg.choices[idx].value); }
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

        // Exposed for tests/queue_strip_probe.js. This whole block is an
        // `if (PAGE === 'kiosk') { ... }`, so nothing in it reaches `window`
        // and the probe could not reach showClosed() or the screen switcher at
        // all. showLanePicker() was called by that probe and silently did
        // nothing for the same reason, which is why the picker screenshot it
        // wrote was of whatever screen happened to be up. Named for the
        // probe's benefit, not for the application's.
        window.queueKioskShow = show;
        window.queueKioskClosed = showClosed;
        window.queueKioskMessage = closedMessageFor;
        window.queueKioskLanePicker = showLanePicker;

        // A background poll for the open/closed state only. Without it the
        // closed sign appears only after somebody taps and is refused, so
        // the kiosk would invite a tap all afternoon that cannot succeed.
        // 15 s is deliberate: this state changes a handful of times a day,
        // so it does not need the board's 3 s cadence.
        startPoll(function () {
            fetchJson(API + '?action=board').then(function (d) {
                if (d.success && d.data) refreshClosed(d.data);
            }).catch(function (e) { reportQueueError(e, 'kiosk status'); });
        }, 15000);
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
                    sh += '<div class="win-slot ' + laneTone(slot.txn_type, slot.priority_group)
                        + (s ? ' busy' : ' idle') + (isNew ? ' calling' : '') + '">' +
                        '<div class="win-head">Window ' + slot.window +
                          (slot.label ? ' <span class="win-lane">' + esc(slot.label) + '</span>' : '') + '</div>' +
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
            }).catch(function (e) { reportQueueError(e, 'monitor board'); });
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

        // The history date the console is looking at. Tracked here rather than
// read from the input on every tick so the 3 s poll and the date picker
// can never disagree about which day is on screen.
var histDate = null;
var lastSettings = null;

function timeLabel(t) {
    if (!t) return '';
    var s = String(t);
    var d = new Date(s.indexOf(' ') > 0 ? s.replace(' ', 'T') : s);
    if (isNaN(d.getTime())) return '';
    var h = d.getHours();
    var m = d.getMinutes();
    var ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12;
    if (h === 0) h = 12;
    return h + ':' + (m < 10 ? '0' : '') + m + ' ' + ap;
}

// Shown next to each ticket so a registrar can see which desk a student
// belongs to without cross-referencing the window strip. Priority is
// amber here too, matching the kiosk.
function laneChip(txn, prio) {
    var isPrio = prio === 'priority';
    var cls = isPrio ? 'lane-priority' : (txn === 'claim' ? 'lane-claim' : 'lane-service');
    var label = (txn === 'claim' ? 'Claim' : 'Service') + (isPrio ? ' · Priority' : ' · Student');
    return '<span class="chip ' + cls + '">' + esc(label) + '</span>';
}

function renderOpenState(d) {
    var bar = document.getElementById('openBar');
    if (!bar) return;
    var txt = document.getElementById('openBarText');
    var sub = document.getElementById('openBarSub');
    var btnOff = document.getElementById('btnCutOff');
    var btnOn = document.getElementById('btnReopen');
    var s = d.settings || {};
    var hours = timeLabel(s.opens_time) + ' – ' + timeLabel(s.closes_time);

    if (d.queue_closed) {
        bar.classList.add('is-closed');
        if (d.closed_reason === 'forced') {
            txt.textContent = 'Closed now';
            sub.textContent = 'Cut off at ' + timeLabel(d.closed_at) + '. Numbers already issued are still being served.';
        } else if (d.closed_reason === 'before_open') {
            txt.textContent = 'Not open yet';
            sub.textContent = 'Opens at ' + timeLabel(s.opens_time) + '. The kiosk is showing a closed sign.';
        } else {
            txt.textContent = 'Closed for today';
            sub.textContent = 'Closed at ' + timeLabel(s.closes_time) + '. The kiosk is showing a closed sign.';
        }
        btnOff.style.display = 'none';
        btnOn.style.display = '';
    } else {
        bar.classList.remove('is-closed');
        txt.textContent = 'Open';
        sub.textContent = 'Taking numbers ' + hours + '.'
            + (s.max_taps_student ? ' Student limit ' + s.max_taps_student + '/day.' : '')
            + (s.max_taps_priority ? ' Priority limit ' + s.max_taps_priority + '/day.' : '');
        btnOff.style.display = '';
        btnOn.style.display = 'none';
    }
    lastSettings = s;
}

function openDayPanel() {
    var s = lastSettings || {};
    var set = function (id, v) { var el = document.getElementById(id); if (el) el.value = v; };
    // The API returns H:i:s; <input type="time"> wants HH:MM.
    var hhmm = function (t) { return t ? String(t).slice(0, 5) : ''; };
    set('dayOpens', hhmm(s.opens_time) || '08:00');
    set('dayCloses', hhmm(s.closes_time) || '17:00');
    set('dayMaxStudent', s.max_taps_student != null ? s.max_taps_student : 0);
    set('dayMaxPriority', s.max_taps_priority != null ? s.max_taps_priority : 0);
    var en = document.getElementById('dayEnabled');
    if (en) en.checked = s.cutoff_enabled === undefined ? true : !!Number(s.cutoff_enabled);
    document.getElementById('dayModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeDay() {
    document.getElementById('dayModal').classList.remove('active');
    document.body.style.overflow = '';
}
window.queueCloseDay = closeDay;

function saveDaySettings() {
    var btn = document.getElementById('daySave');
    btn.disabled = true;
    post('save_day_settings', {
        opens_time: document.getElementById('dayOpens').value,
        closes_time: document.getElementById('dayCloses').value,
        cutoff_enabled: document.getElementById('dayEnabled').checked,
        max_taps_student: document.getElementById('dayMaxStudent').value,
        max_taps_priority: document.getElementById('dayMaxPriority').value
    }).then(function (d) {
        if (d.success) { showToast(d.message, 'success'); closeDay(); loadState(); }
        else showToast(d.message || 'Could not save.', 'error');
    }).catch(function (e) {
        reportQueueError(e, 'save day settings');
        showToast(shortReason(e), 'error');
    }).finally(function () { btn.disabled = false; });
}

function shiftHistory(days) {
    var base = histDate ? new Date(histDate + 'T00:00:00') : new Date();
    base.setDate(base.getDate() + days);
    var y = base.getFullYear();
    var m = String(base.getMonth() + 1).padStart(2, '0');
    var d = String(base.getDate()).padStart(2, '0');
    setHistoryDate(y + '-' + m + '-' + d);
}

function setHistoryDate(iso) {
    var today = new Date();
    var todayIso = today.getFullYear() + '-'
        + String(today.getMonth() + 1).padStart(2, '0') + '-'
        + String(today.getDate()).padStart(2, '0');
    // Never look at a future day: there is nothing there, and an empty
    // table reads as "the records were lost".
    if (!iso || iso > todayIso) iso = todayIso;
    histDate = iso;
    var input = document.getElementById('histDate');
    if (input) input.value = iso;
    var next = document.getElementById('histNext');
    if (next) next.disabled = (iso >= todayIso);
    loadState();
}

function render(data) {
            var d = data;
            renderOpenState(d);

            if (histDate === null) {
                histDate = d.history_date || d.today;
                var input = document.getElementById('histDate');
                if (input) input.value = histDate;
                var next = document.getElementById('histNext');
                if (next) next.disabled = !!(d.is_today);
            }
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
                    // slot.label is "Service · Priority". Shown in full here
                    // rather than as "W1", because a registrar moving between
                    // desks needs to know which desk this is without
                    // switching the selector to find out.
                    wh2 += '<div class="win-chip ' + laneTone(slot.txn_type, slot.priority_group)
                        + (s ? ' busy' : ' idle') + '" title="Window ' + slot.window + ' — ' + esc(slot.label || '') + '">' +
                        '<span class="wc-n">W' + slot.window + '</span>' +
                        '<span class="wc-lane">' + esc(slot.label || '') + '</span>' +
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
                    html = '<tr class="empty-state-row"><td colspan="8" style="height:60vh;text-align:center;"><div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;"><i class="fas fa-people-group" style="font-size:40px;color:#cbd5e1;margin-bottom:12px;"></i><p style="font-size:15px;font-weight:600;color:#64748b;margin:0 0 4px;">No students waiting</p><span style="font-size:13px;color:#94a3b8;">Tickets appear here when students tap at the kiosk</span></div></td></tr>';
                } else {
                    (d.waiting || []).forEach(function (w) {
                        html += '<tr>' +
                            '<td><span class="chip blue">#' + w.position + '</span></td>' +
                            '<td><strong>' + esc(w.display_number) + '</strong></td>' +
                            '<td><div class="student-info"><div class="student-avatar blue">' + esc((w.student_name || '?').charAt(0).toUpperCase()) + '</div><div><div class="student-name">' + esc(w.student_name) + '</div></div></div></td>' +
                            '<td>' + laneChip(w.txn_type, w.priority_group) +
                              (w.window ? ' <span style="font-size:11px;color:#94a3b8;">W' + w.window + '</span>' : '') + '</td>' +
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
                    ch = '<tr><td colspan="5" class="empty-state"><i class="fas fa-inbox"></i><p>'
                        + (d.is_today ? 'Nothing served yet today' : 'Nothing served on ' + esc(d.history_date))
                        + '</p></td></tr>';
                } else {
                    // "3h ago" is meaningless for a day that is already over,
                    // so anything but today shows the clock time instead.
                    var when = function (ts) {
                        if (!ts) return '—';
                        var parsed = parseDbDt(ts);
                        if (d.is_today) return timeAgo(ts);
                        return parsed === null ? '—' : new Date(parsed).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                    };
                    (d.completed || []).forEach(function (c) {
                        ch += '<tr>' +
                            '<td><strong>' + esc(c.display_number) + '</strong></td>' +
                            '<td>' + esc(c.student_name) + '</td>' +
                            '<td>' + laneChip(c.txn_type, c.priority_group) + '</td>' +
                            '<td><span class="pill ' + (c.status === 'completed' ? 'active' : 'inactive') + '">' + esc(ucfirst(c.status)) + '</span></td>' +
                            '<td style="font-size:12px;color:#64748b;">' + esc(when(c.served_at)) + '</td></tr>';
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
                }).catch(function (e) {
                    reportQueueError(e, 'call next');
                    showToast(shortReason(e), 'error');
                })
                .finally(function () { btnCall.disabled = false; });
            });

            var nsComplete = document.getElementById('nsComplete');
            if (nsComplete) nsComplete.addEventListener('click', function () {
                var id = nsComplete.dataset.ticketId;
                if (!id) return;
                post('complete', { ticket_id: parseInt(id, 10) }).then(function (d) {
                    if (d.success) showToast(d.message, 'success');
                    else showToast(d.message || 'Error.', 'error');
                }).catch(function (e) {
                    reportQueueError(e, 'complete ticket');
                    showToast(shortReason(e), 'error');
                });
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
                }).catch(function (e) {
                    reportQueueError(e, 'skip ticket');
                    showToast(shortReason(e), 'error');
                    closeSkip();
                })
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
            //
            // The date rides along because the history table is filtered. The
            // server scopes ONLY history to it — live counts and the now-serving
            // card stay on today, so browsing last Tuesday does not stop the
            // desk from serving the student standing in front of it.
            var ws = document.getElementById('windowSelect');
            var w = ws ? (parseInt(ws.value, 10) || 1) : 1;
            var url = API_AUTH + '?action=state&window=' + w
                + (histDate ? '&date=' + encodeURIComponent(histDate) : '');
            fetchJson(url).then(function (d) {
                if (d.success && d.data) render(d.data);
            }).catch(function (e) { reportQueueError(e, 'serving console state'); });
        }

        // Persist window selection
        var winSel = document.getElementById('windowSelect');
        if (winSel) {
            var saved = localStorage.getItem('queue_window');
            if (saved && [1, 2, 3, 4].indexOf(parseInt(saved, 10)) !== -1) winSel.value = saved;
            winSel.addEventListener('change', function() {
                localStorage.setItem('queue_window', this.value);
                // Switching desks must repaint the now-serving card for the new
                // window immediately, not on the next 3 s poll.
                loadState();
            });
        }

        // ── Open / closed ──────────────────────────────────────
        // CUT OFF is destructive-ish: it stops every student at the kiosk
        // from getting a number. It asks first, and the dialog names what
        // it does to people already holding a number.
        //
        // Uses the shared confirmAction() rather than native confirm():
        // the native box is drawn by the OS, so it arrives as an unstyled
        // "localhost says" dialog that blocks the tab and reads as an
        // error state rather than a question. confirm.js already exists
        // for exactly this and is loaded on every page by header.php.
        var btnOff = document.getElementById('btnCutOff');
        if (btnOff) btnOff.addEventListener('click', async function () {
            var ok = await confirmAction({
                title: 'Cut off the queue now?',
                body: 'The kiosk will stop issuing numbers for today.'
                    + '<br><br>Students who already hold a number are <b>still served</b>'
                    + ' &mdash; this stops new tickets only.',
                confirmLabel: 'Cut off now',
                tone: 'danger'
            });
            if (!ok) return;
            btnOff.disabled = true;
            post('set_cutoff', {}).then(function (d) {
                showToast(d.message, d.success ? 'success' : 'error');
                if (d.success) loadState();
            }).catch(function (e) {
                reportQueueError(e, 'cut off queue');
                showToast(shortReason(e), 'error');
            }).finally(function () { btnOff.disabled = false; });
        });

        var btnOn = document.getElementById('btnReopen');
        if (btnOn) btnOn.addEventListener('click', function () {
            btnOn.disabled = true;
            post('clear_cutoff', {}).then(function (d) {
                // Reopening past the closing time does NOT reopen the queue,
                // and the server says so in the message. Show it either way
                // rather than optimistically flipping the banner.
                showToast(d.message, d.success ? 'success' : 'error');
                loadState();
            }).catch(function (e) {
                reportQueueError(e, 'reopen queue');
                showToast(shortReason(e), 'error');
            }).finally(function () { btnOn.disabled = false; });
        });

        var btnPanel = document.getElementById('btnDayPanel');
        if (btnPanel) btnPanel.addEventListener('click', openDayPanel);
        var btnDaySave = document.getElementById('daySave');
        if (btnDaySave) btnDaySave.addEventListener('click', saveDaySettings);
        var dayModal = document.getElementById('dayModal');
        if (dayModal) dayModal.addEventListener('click', function (e) {
            if (e.target === dayModal) closeDay();
        });

        // ── History date filter ────────────────────────────────
        var histInput = document.getElementById('histDate');
        if (histInput) histInput.addEventListener('change', function () {
            setHistoryDate(this.value);
        });
        var histPrev = document.getElementById('histPrev');
        if (histPrev) histPrev.addEventListener('click', function () { shiftHistory(-1); });
        var histNext = document.getElementById('histNext');
        if (histNext) histNext.addEventListener('click', function () { shiftHistory(1); });
        var histToday = document.getElementById('histToday');
        if (histToday) histToday.addEventListener('click', function () {
            var n = new Date();
            setHistoryDate(n.getFullYear() + '-'
                + String(n.getMonth() + 1).padStart(2, '0') + '-'
                + String(n.getDate()).padStart(2, '0'));
        });

        // Enter inside the settings modal saves, matching every other form
        // in this app.
        if (dayModal) {
            dayModal.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
                    e.preventDefault();
                    saveDaySettings();
                }
                if (e.key === 'Escape') closeDay();
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
            }).catch(function (e) { reportQueueError(e, 'dashboard widget'); });
        }
        loadWidget();
        startPoll(loadWidget, 5000);
    }

})();
