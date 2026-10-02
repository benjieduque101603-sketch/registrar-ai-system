// Screenshots the monitor window strip with one serving ticket per window,
// so the four-across layout and the lane colours can be looked at rather
// than assumed. Every row it creates is deleted on the way out.
//
//   node tests/queue_strip_probe.js <port> <baseUrl>
//
// Same CDP approach as modal_probe.js (no Playwright in this project).
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const PORT = Number(process.argv[2] || 9455);
const BASE = process.argv[3] || 'http://localhost/registrar-ai-system';
// 'monitor' (default) seeds a serving ticket per window so the strip can be
// photographed with content. 'kiosk' touches no rows at all — it only drives
// screens on the public page — which is why it is the cheap mode.
const MODE = process.argv[4] || 'monitor';
const CANDIDATES = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
];
const browser = CANDIDATES.find((p) => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

const PHP = 'C:/xampp/php/php.exe';
const ROOT = path.join(__dirname, '..');
let seeded = '';

function seed() {
    const script = `
require 'shared/config.php';
require 'shared/database.php';
$d = Database::getInstance();
$today = date('Y-m-d');
$sid = (int) $d->fetchColumn('SELECT id FROM students LIMIT 1');
$lanes = [['service','priority'],['service','student'],['claim','priority'],['claim','student']];
$n = (int) $d->fetchColumn('SELECT COALESCE(MAX(ticket_number),0) FROM queue_tickets WHERE queue_date = ?', [$today]);
$names = ['DELA CRUZ','SANTOS','REYES','BONIFACIO'];
$ids = [];
foreach ($lanes as $i => $l) {
    $n++;
    $ids[] = (int) $d->insert('queue_tickets', [
        'queue_date' => $today, 'ticket_number' => $n, 'student_id' => $sid ?: null,
        'student_name' => $names[$i], 'student_number' => '2026-00' . ($i + 1),
        'course' => 'BSIT', 'status' => 'serving', 'counter' => $i + 1,
        'txn_type' => $l[0], 'priority_group' => $l[1],
        'called_at' => date('Y-m-d H:i:s', time() - ($i + 1) * 240),
    ]);
}
echo implode(',', $ids);`;
    seeded = execFileSync(PHP, ['-r', script], { cwd: ROOT }).toString().trim();
    console.log('seeded ids: ' + seeded);
}

// Written to a temp .php file rather than passed to `php -r`: the delete
// interpolates an id list into SQL, and a multi-line -r payload with
// embedded quotes is exactly what fails silently through execFileSync.
const CLEANUP = path.join(os.tmpdir(), 'dq-strip-cleanup.php');
function unseed() {
    if (!seeded) return;
    fs.writeFileSync(CLEANUP, `<?php
require '${ROOT.replace(/\\/g, '/')}/shared/config.php';
require '${ROOT.replace(/\\/g, '/')}/shared/database.php';
$d = Database::getInstance();
$n = $d->query('DELETE FROM queue_tickets WHERE id IN (${seeded})')->rowCount();
echo $n;
`);
    try {
        execFileSync(PHP, [CLEANUP]);
        console.log('probe rows removed');
    } catch (e) { console.error('CLEANUP FAILED: ' + e.message); }
    finally { try { fs.rmSync(CLEANUP); } catch (_) {} }
    seeded = '';
}

process.on('exit', unseed);
process.on('SIGINT', () => { unseed(); process.exit(1); });
// A hard kill (a tool timeout) runs none of the above, which is how the
// first run left four rows behind. Kiosk mode seeds nothing at all, so it
// leaves nothing to clean up even if it dies hard.
if (MODE === 'monitor') seed();
else console.log('kiosk mode: no rows seeded');

const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dq-strip-'));
const child = spawn(browser, ['--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
    `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, '--window-size=1600,1000', 'about:blank'],
    { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
function done(code) {
    try { child.kill(); } catch (_) {}
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (_) {}
    unseed(); process.exit(code);
}

(async () => {
    let target = null;
    for (let i = 0; i < 60 && !target; i++) {
        await sleep(200);
        try {
            const l = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
            target = l.find((t) => t.type === 'page');
        } catch (_) {}
    }
    if (!target) { console.error('RESULT=NO_TARGET'); done(1); }
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((r) => (ws.onopen = r));
    let id = 0; const pending = new Map();
    const send = (m, p) => { const i = ++id; ws.send(JSON.stringify({ id: i, method: m, params: p }));
        return new Promise((res, rej) => pending.set(i, { res, rej })); };
    ws.onmessage = (e) => {
        const m = JSON.parse(e.data);
        if (m.id && pending.has(m.id)) { const p = pending.get(m.id); pending.delete(m.id);
            m.error ? p.rej(new Error(JSON.stringify(m.error))) : p.res(m.result); }
    };
    await send('Emulation.setDeviceMetricsOverride', { width: 1600, height: 1000, deviceScaleFactor: 1, mobile: false });
    await send('Page.enable'); await send('Network.enable');

    const shoot = async (name) => {
        await sleep(3500);   // let the 3 s board poll land
        const ev = async (expr) =>
            (await send('Runtime.evaluate', { expression: expr, returnByValue: true })).result.value;
        // Report measured geometry: equal y means one row, which is the
        // thing a screenshot alone makes hard to judge precisely.
        const raw = await ev(`JSON.stringify([...document.querySelectorAll('.win-slot')].map(e=>{
            const r=e.getBoundingClientRect();
            return {cls:e.className.replace('win-slot ',''),x:Math.round(r.x),y:Math.round(r.y),w:Math.round(r.width),h:Math.round(r.height)};
        }))`);
        const list = JSON.parse(raw);
        console.log(name + ' -> ' + list.length + ' slots');
        list.forEach((b) => console.log('   ' + b.cls.padEnd(28) + ' x=' + b.x + ' y=' + b.y + '  ' + b.w + 'x' + b.h));
        const rows = new Set(list.map((b) => b.y));
        console.log('   distinct rows: ' + rows.size
            + (rows.size === 1 ? '   <- single row, correct' : '   <- WRAPPED'));
        const s = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(`${name}.png`, Buffer.from(s.data, 'base64'));
        console.log('   wrote ' + name + '.png');
    };

    // The console strip (.win-chip) is a different renderer in the same
    // file, so it gets its own measurement.
    const shootChips = async (name) => {
        await sleep(3500);
        const ev = async (expr) =>
            (await send('Runtime.evaluate', { expression: expr, returnByValue: true })).result.value;
        const raw = await ev(`JSON.stringify([...document.querySelectorAll('.win-chip')].map(e=>{
            const r=e.getBoundingClientRect();
            return {cls:e.className.replace('win-chip ',''),x:Math.round(r.x),y:Math.round(r.y),w:Math.round(r.width),h:Math.round(r.height)};
        }))`);
        const list = JSON.parse(raw);
        console.log(name + ' -> ' + list.length + ' chips');
        list.forEach((b) => console.log('   ' + b.cls.padEnd(26) + ' x=' + b.x + ' y=' + b.y + '  ' + b.w + 'x' + b.h));
        const rows = new Set(list.map((b) => b.y));
        console.log('   distinct rows: ' + rows.size
            + (rows.size === 1 ? '   <- single row, correct' : '   <- WRAPPED'));
        const overflow = await ev(`JSON.stringify([...document.querySelectorAll('.win-chip')]
            .filter(e => e.scrollWidth > e.clientWidth + 1).length)`);
        console.log('   chips overflowing horizontally: ' + overflow);
        const s = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(`${name}.png`, Buffer.from(s.data, 'base64'));
        console.log('   wrote ' + name + '.png');
    };

    // Kiosk mode skips the monitor shots entirely — they need the seeded
    // serving rows this mode does not create.
    if (MODE === 'monitor') {
        await send('Page.navigate', { url: BASE + '/queue/monitor.php' });
        await sleep(3000);
        await shoot('probe_monitor');

        // A narrower window: the strip should fold to 2x2, not to one column.
        await send('Emulation.setDeviceMetricsOverride', { width: 1000, height: 1000, deviceScaleFactor: 1, mobile: false });
        await sleep(3500);
        await shoot('probe_monitor_narrow');
    }
    // The console strip (.win-chip) is a different renderer on the same
    // file, so it gets its own measurement.
    await send('Emulation.setDeviceMetricsOverride', { width: 1600, height: 1000, deviceScaleFactor: 1, mobile: false });
    await send('Page.navigate', { url: BASE + '/tests/_queue_console_shot.html' });
    await sleep(4000);
    await shootChips('probe_console');

    // The kiosk closed sign. The kiosk's own 15 s poll decides to show it, so
    // the probe drives it directly instead of waiting for the hours.
    const shootClosed = async (name, reduced) => {
        if (reduced) {
            await send('Emulation.setEmulatedMedia', {
                features: [{ name: 'prefers-reduced-motion', value: 'reduce' }]
            });
        }
        await sleep(1200);
        const ev = async (expr) =>
            (await send('Runtime.evaluate', { expression: expr, returnByValue: true })).result.value;
        const geom = await ev(`JSON.stringify((function(){
            var mark = document.querySelector('.closed-mark');
            var clock = document.querySelector('.closed-clock');
            var hand = document.querySelector('.cc-hand-h');
            if(!mark || !clock) return null;
            var m = mark.getBoundingClientRect(), c = clock.getBoundingClientRect();
            return { mark: Math.round(m.width)+'x'+Math.round(m.height),
                     clock: Math.round(c.width)+'x'+Math.round(c.height),
                     hands: document.querySelectorAll('.cc-hand').length,
                     anim: getComputedStyle(hand).animationName,
                     dur: getComputedStyle(hand).animationDuration };
        })())`);
        console.log(name + ' -> ' + geom);
        const s = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(`${name}.png`, Buffer.from(s.data, 'base64'));
        console.log('   wrote ' + name + '.png');
    };

    await send('Page.navigate', { url: BASE + '/queue/kiosk.php' });
    await sleep(2500);
    await shootClosed('probe_kiosk_closed', false);

    // Same screen with reduced motion: the clock must still read as a clock.
    await send('Emulation.setEmulatedMedia', {
        features: [{ name: 'prefers-reduced-motion', value: 'reduce' }]
    });
    await sleep(1500);
    await shootClosed('probe_kiosk_closed_reduced', true);
    await send('Emulation.setEmulatedMedia', { features: [] });

    // ── The Tap Card tab must not dismiss the closed sign ──────
    // The bug this guards: with the sign up, one press of "Tap Card" put the
    // kiosk back on the tap prompt, inviting a card tap that beginTap() then
    // refused. Driven through the real button, not by calling showClosed,
    // because the whole question is what the TAB does to the screen.
    await sleep(500);
    const ev2 = async (expr) =>
        (await send('Runtime.evaluate', { expression: expr, returnByValue: true })).result.value;

    await ev2("window.queueKioskClosed('before_open', null, '8:00 AM')");
    await sleep(600);
    const beforeTap = await ev2("document.getElementById('screen-closed').style.display");

    await ev2("document.querySelector('.q-tab-btn[data-tab=tap]').click()");
    await sleep(600);
    const afterTap = await ev2("JSON.stringify({"
        + "screen: document.getElementById('screen-closed').style.display,"
        + "tap: document.getElementById('screen-tap').style.display,"
        + "title: document.getElementById('closedTitle').textContent,"
        + "msg: document.getElementById('closedMessage').textContent})");

    // The other two tabs must still work while closed.
    await ev2("document.querySelector('.q-tab-btn[data-tab=standing]').click()");
    await sleep(400);
    const standingOpen = await ev2("document.getElementById('screen-standing').style.display");
    await ev2("document.querySelector('.q-tab-btn[data-tab=board]').click()");
    await sleep(400);
    const boardOpen = await ev2("document.getElementById('screen-board').style.display");

    console.log('   closed: ' + beforeTap + ' -> after Tap press: ' + afterTap);
    console.log('   standing=' + standingOpen + ' board=' + boardOpen);

    // The tap screen must not be showing, and the sign must be back up.
    const guardOk = afterTap.indexOf('"tap":"none"') !== -1
        && afterTap.indexOf('"screen":"block"') !== -1
        && standingOpen === 'block' && boardOpen === 'block';
    if (!guardOk) {
        console.log('   FAIL: the Tap Card tab dismissed the closed sign');
    }

    // The sentence must be specific, not the generic fallback. Asserted
    // against the pure helper rather than the DOM: the 15 s status poll
    // re-raises the sign from real server data, so a DOM read here races it
    // and reports whatever the live queue happens to be doing.
    const msgs = await ev2("JSON.stringify({"
        + "before: window.queueKioskMessage('before_open', '8:00 AM'),"
        + "after: window.queueKioskMessage('after_close', '5:00 PM'),"
        + "forced: window.queueKioskMessage('forced', null),"
        + "unknown: window.queueKioskMessage('what', null)})");
    console.log('   closed messages: ' + msgs);
    const msgOk = msgs.indexOf('8:00 AM') !== -1
        && msgs.indexOf('5:00 PM') !== -1
        && msgs.indexOf('come back tomorrow') !== -1
        && msgs.indexOf('right now') !== -1;   // only the unknown reason
    if (!guardOk || !msgOk) {
        console.log('   FAIL: closed-sign guard or message copy is wrong');
        done(1);
        return;
    }

    // The lane picker, which shares the same screen-switching code. Shot last,
    // after the assertions above, because it deliberately leaves the kiosk off
    // the closed sign and the next thing this probe does is photograph screens.
    await sleep(500);
    await ev2("window.queueKioskLanePicker('0000000000')");
    await sleep(1200);
    const pickShot = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync('probe_kiosk_picker.png', Buffer.from(pickShot.data, 'base64'));
    console.log('   wrote probe_kiosk_picker.png');

    done(0);
})().catch((e) => { console.error('ERR ' + e.message); done(1); });