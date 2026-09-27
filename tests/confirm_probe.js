// ============================================================
//  TESTS/CONFIRM_PROBE.JS
//
//  Checks that the styled confirm dialog (js/confirm.js) works on a
//  real page, replacing native confirm().
//
//  Speaks the DevTools protocol over a WebSocket, same as
//  view_toggle_probe.js — no npm install needed.
//
//  Checks four things, because any can fail alone:
//    1. js/confirm.js loaded and defined confirmAction
//    2. the modal opens with the caller's title, body and label
//    3. it RESOLVES — true on confirm, false on cancel. A dialog
//       that never settles leaves the caller's await hanging forever,
//       which no static check catches and no screenshot would show.
//    4. a second call reuses the same node instead of stacking
// ============================================================
'use strict';

const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const PORT = Number(process.argv[2] || 9444);
const PAGE = process.argv[3] || 'http://localhost/registrar-ai-system/registrar/documents.php';
const COOKIE = process.argv[4] || '';
const SHOT = process.argv[5] || '';

const CANDIDATES = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
];
const browser = CANDIDATES.find((p) => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dq-ca-'));
const child = spawn(browser, [
    '--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
    `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, 'about:blank',
], { stdio: 'ignore' });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
function cleanup(code) {
    try { child.kill(); } catch (_) {}
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (_) {}
    process.exit(code);
}

(async () => {
    let list;
    for (let i = 0; i < 40; i++) {
        try {
            const res = await fetch(`http://127.0.0.1:${PORT}/json/list`);
            list = await res.json();
            if (list.length) break;
        } catch (_) {}
        await sleep(250);
    }
    if (!list || !list.length) { console.error('RESULT=NO_TARGET'); return cleanup(1); }
    const page = list.find((t) => t.type === 'page');
    if (!page) { console.error('RESULT=NO_PAGE'); return cleanup(1); }

    const ws = new WebSocket(page.webSocketDebuggerUrl);
    let id = 0;
    const pending = new Map();
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const msgId = ++id;
        pending.set(msgId, { resolve, reject });
        ws.send(JSON.stringify({ id: msgId, method, params }));
    });
    ws.addEventListener('message', (ev) => {
        const msg = JSON.parse(ev.data);
        if (msg.id && pending.has(msg.id)) {
            const { resolve, reject } = pending.get(msg.id);
            pending.delete(msg.id);
            msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
        }
    });
    await new Promise((r) => ws.addEventListener('open', r));
    await send('Page.enable');
    await send('Runtime.enable');

    if (COOKIE) {
        const eq = COOKIE.indexOf('=');
        await send('Network.enable');
        await send('Network.setCookie', {
            name: COOKIE.slice(0, eq), value: COOKIE.slice(eq + 1),
            domain: 'localhost', path: '/',
        });
    }
    await send('Page.navigate', { url: PAGE });

    const evaluate = async (expression) => {
        const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        return r && r.result && r.result.value;
    };
    const waitFor = async (expr, want) => {
        for (let i = 0; i < 25; i++) {
            const v = await evaluate(expr);
            if (v === want) return v;
            await sleep(200);
        }
        return evaluate(expr);
    };

    // 1. Loaded?
    let loaded = false;
    for (let i = 0; i < 40; i++) {
        if (await evaluate('typeof window.confirmAction') === 'function') { loaded = true; break; }
        await sleep(250);
    }
    console.log('RESULT=confirmAction=' + (loaded ? 'function' : 'MISSING'));
    if (!loaded) { console.error('RESULT=NOT_LOADED on ' + (await evaluate('location.href'))); return cleanup(1); }

    // 2. Open it exactly as a page would, and read what rendered.
    await evaluate(`(() => {
        window.__ca = null;
        confirmAction({
            title: 'Collect document',
            body: 'Hand <strong>Certificate of Enrollment</strong> to the student and take payment?',
            confirmLabel: 'Collect & settle',
            tone: 'primary'
        }).then(function (v) { window.__ca = v; });
    })()`);
    await sleep(400);

    const state = await evaluate(`(() => {
        const m = document.getElementById('confirmActionModal');
        if (!m) return { present: false };
        const ok = document.getElementById('confirmActionOk');
        return {
            present: true,
            active: m.classList.contains('active'),
            title: (document.getElementById('confirmActionTitle') || {}).textContent,
            body: ((document.getElementById('confirmActionBody') || {}).innerText || '').slice(0, 55),
            okLabel: ok ? ok.textContent : null,
            okClass: ok ? ok.className : null,
            focused: document.activeElement === ok
        };
    })()`);
    console.log('RESULT=' + JSON.stringify(state));

    if (SHOT) {
        await send('Emulation.setDeviceMetricsOverride', { width: 1500, height: 1000, deviceScaleFactor: 1, mobile: false });
        await sleep(300);
        const img = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(SHOT, Buffer.from(img.data, 'base64'));
        console.log('SHOT=' + SHOT);
    }

    // 3. Cancel must resolve false, not hang.
    await evaluate(`document.querySelector('#confirmActionModal [data-ca="cancel"]').click()`);
    const cancelled = await waitFor('window.__ca', false);
    console.log('RESULT=cancel_resolves=' + cancelled);

    // 4. Confirm must resolve true, and the danger tone must apply.
    await evaluate(`(() => {
        window.__ca = null;
        confirmAction({ title: 'Delete file', body: 'Delete <strong>transcript.pdf</strong>?', confirmLabel: 'Delete file', tone: 'danger' })
            .then(function (v) { window.__ca = v; });
    })()`);
    await sleep(300);
    const tone = await evaluate(`(() => {
        const ok = document.getElementById('confirmActionOk');
        return { label: ok.textContent, cls: ok.className };
    })()`);
    console.log('RESULT=danger_tone=' + JSON.stringify(tone));
    await evaluate(`document.getElementById('confirmActionOk').click()`);
    const confirmed = await waitFor('window.__ca', true);
    console.log('RESULT=confirm_resolves=' + confirmed);

    // 5. A second call must reuse the node, not stack overlays.
    const reused = await evaluate(`(() => {
        const before = document.querySelectorAll('.modal-overlay').length;
        window.__ca = null;
        confirmAction({ title: 'Again', body: 'second' });
        const after = document.querySelectorAll('.modal-overlay').length;
        return before === after ? 'reused' : 'stacked:' + before + '->' + after;
    })()`);
    console.log('RESULT=' + reused);

    const ok = state.present && state.active === true
        && cancelled === false && confirmed === true
        && /btn-danger/.test(tone.cls || '') && reused === 'reused';

    console.log(ok ? 'RESULT=CONFIRM_PROBE_OK' : 'RESULT=CONFIRM_PROBE_FAIL');
    cleanup(ok ? 0 : 1);
})().catch((e) => { console.error('RESULT=ERROR ' + e.message); cleanup(1); });

