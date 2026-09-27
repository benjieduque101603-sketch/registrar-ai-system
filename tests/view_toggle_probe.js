// ============================================================
//  TESTS/VIEW_TOGGLE_PROBE.JS
//
//  Clicks the View button on the registrar document desk and reports
//  whether the detail row opened.
//
//  This exists because the whole feature was dead while every
//  static check passed. The markup was right, the handler was wired,
//  the CSS applied — and toggleDetail read `row.style.display`, which
//  starts as "none", concluded the row was already open, and set it
//  to "none" again. Clicking it was the only way to see that.
//
//  Speaks the DevTools protocol over a WebSocket to a browser that is
//  already installed, so the project gains no dependency. Node 22+
//  ships a global WebSocket, which is all this needs.
//
//  Usage: node view_toggle_probe.js <debugPort> [url] [cookie]
//  Prints RESULT=VIEW_TOGGLE_OK only if the row really opened.
// ============================================================
'use strict';

const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const PORT = Number(process.argv[2] || 9333);
const PAGE = process.argv[3] || 'http://localhost/registrar-ai-system/registrar/__shot.html';
// "NAME=VALUE". Needed to load the LIVE desk, which is behind login.
const COOKIE = process.argv[4] || '';

const CANDIDATES = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
];
const browser = CANDIDATES.find((p) => fs.existsSync(p));
if (!browser) {
    console.error('RESULT=NO_BROWSER');
    process.exit(1);
}

const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dq-cdp-'));
const child = spawn(browser, [
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--no-first-run',
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${profile}`,
    'about:blank',
], { stdio: 'ignore' });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function cleanup(code) {
    try { child.kill(); } catch (_) {}
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (_) {}
    process.exit(code);
}

async function targets() {
    const res = await fetch(`http://127.0.0.1:${PORT}/json/list`);
    return res.json();
}

(async () => {
    let list;
    for (let i = 0; i < 40; i++) {
        try { list = await targets(); if (list.length) break; } catch (_) {}
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

    // A JS error anywhere on the live page stops the inline script
    // from defining anything, so every onclick in the table becomes a
    // no-op — which looks exactly like a dead View button. Collect
    // them and report, instead of letting the click silently fail.
    const errors = [];
    ws.addEventListener('message', (ev) => {
        const m = JSON.parse(ev.data);
        if (m.method === 'Runtime.exceptionThrown') {
            const d = m.params.exceptionDetails || {};
            errors.push((d.exception && (d.exception.description || d.exception.value))
                || d.text || 'unknown error');
        }
    });

    if (COOKIE) {
        const eq = COOKIE.indexOf('=');
        await send('Network.enable');
        await send('Network.setCookie', {
            name: COOKIE.slice(0, eq),
            value: COOKIE.slice(eq + 1),
            domain: 'localhost',
            path: '/',
        });
    }

    await send('Page.navigate', { url: PAGE });

    // Wait for the button to exist rather than guessing with a fixed
    // sleep. A fixed delay makes this check flaky on a slow machine and,
    // worse, silently reports NO_BUTTON when the page simply had not
    // finished loading.
    let found = false;
    for (let i = 0; i < 40; i++) {
        const q = await send('Runtime.evaluate', {
            expression: '!!document.querySelector(".dq-view")',
            returnByValue: true,
        });
        if (q && q.result && q.result.value === true) { found = true; break; }
        await sleep(250);
    }
    if (!found) {
        const url = await send('Runtime.evaluate', {
            expression: 'location.href + " | rows=" + document.querySelectorAll("tr").length',
            returnByValue: true,
        });
        console.error('RESULT=NOT_LOADED', (url && url.result && url.result.value) || '?');
        return cleanup(1);
    }

    // The probe. Reads the detail row's computed visibility, clicks the
    // real View button, and reads it again. `click()` on the element
    // rather than dispatching an event, so a broken handler shows up.
    //
    // Counts handler invocations too. A single click used to call
    // toggleDetail twice — once on the button, once again as the click
    // bubbled to the row — so the row opened and closed in the same
    // tick and ended exactly where it started. Reading only the final
    // computed style reports that as "no change" without saying why;
    // the count names the cause.
    const expr = `(() => {
        const defined = typeof toggleDetail === 'function';
        let calls = 0;
        const real = typeof toggleDetail === 'function' ? toggleDetail : null;
        if (real) { window.toggleDetail = function (id) { calls++; return real(id); }; }
        const btn = document.querySelector('.dq-view');
        if (!btn) return 'RESULT=NO_BUTTON defined=' + defined;
        const id = btn.getAttribute('data-doc');
        const row = document.getElementById('detail-' + id);
        if (!row) return 'RESULT=NO_ROW id=' + id + ' defined=' + defined;
        const before = getComputedStyle(row).display;
        btn.click();
        const afterOpen = getComputedStyle(row).display;
        const aria = btn.getAttribute('aria-expanded');
        const afterFirst = calls;
        btn.click();
        const afterClose = getComputedStyle(row).display;
        return 'RESULT=DATA before=' + before
             + ' open=' + afterOpen + ' close=' + afterClose
             + ' aria=' + aria + ' defined=' + defined
             + ' calls=' + afterFirst + '/' + calls;
    })()`;

    const r = await send('Runtime.evaluate', {
        expression: expr,
        returnByValue: true,
    });
    const value = (r && r.result && r.result.value) || 'RESULT=NO_VALUE';
    console.log(value);

    // Pass only on a genuine closed -> open -> closed cycle, with the
    // handler firing exactly once per click. Two calls per click cancels
    // out visually — the row ends where it started — so the cycle alone
    // would not notice, but the button is doing nothing.
    const m = /open=([^ ]+) close=([^ ]+)/.exec(value);
    const c = /calls=(\d+)\/(\d+)/.exec(value);
    const ok = m && m[1] !== 'none' && m[2] === 'none'
        && /before=([^ ]+)/.exec(value)[1] === 'none'
        && c && c[1] === '1' && c[2] === '2';
    if (errors.length) {
        console.error('JS-ERRORS: ' + errors.slice(0, 3).join(' | '));
    }
    console.log(ok ? 'RESULT=VIEW_TOGGLE_OK' : 'RESULT=VIEW_TOGGLE_BROKEN');
    // Optional: capture the page for review by eye. Purely cosmetic.
    const shot = process.argv[5];
    if (shot) {
        await send('Emulation.setDeviceMetricsOverride', {
            width: 1500, height: 1000, deviceScaleFactor: 2, mobile: false,
        });
        // A filename containing 'rail' captures the rows as they stand,
        // which is what a design review needs to see. Anything else
        // expands a row and captures the detail/preview instead.
        if (String(shot).indexOf('rail') !== -1) {
            await send('Runtime.evaluate', {
                expression: `(() => {
                    const f = document.querySelector('.dq-rail');
                    if (f) f.closest('tr').scrollIntoView({block:'center'});
                    document.querySelectorAll('.dq-split').forEach(e => e.style.display = 'none');
                })()`,
            });
            await sleep(400);
            const img = await send('Page.captureScreenshot', { format: 'png' });
            fs.writeFileSync(shot, Buffer.from(img.data, 'base64'));
            console.log('SHOT=' + shot);
        }
    }
    // Optional extra step, run after the toggle check: exercise a named
    // control and report whether the thing it opens actually rendered.
    // Used to confirm the document preview iframe really loads under
    // the page's own CSP.
    const extra = process.argv[6];
    if (ok && extra) {
        const r2 = await send('Runtime.evaluate', {
            expression: `new Promise((res) => {
                // The detail row is a SIBLING <tr> of the data row, not a
                // descendant, so it cannot be reached with
                // row.querySelector() — the first version of this probe
                // did exactly that and reported a missing button that was
                // on screen the whole time.
                const btn = document.querySelector('.dq-view');
                if (!btn) return res('EXTRA=NO_VIEW_BUTTON');
                const id = btn.getAttribute('data-doc');
                const detail = document.getElementById('detail-' + id);
                if (!detail) return res('EXTRA=NO_DETAIL_ROW id=' + id);
                btn.click();
                const pv = detail.querySelector('button[onclick*="openPreview"]');
                if (!pv) return res('EXTRA=NO_PREVIEW_BUTTON');
                pv.click();
                setTimeout(() => {
                    const f = document.querySelector('.dq-preview-frame');
                    const modal = document.getElementById('docPreviewModal');
                    if (!f) return res('EXTRA=NO_IFRAME');
                    let inner = 'blocked';
                    try {
                        const d = f.contentDocument;
                        inner = d ? (d.body ? d.body.innerText.length : 0) : 'no-doc';
                    } catch (e) { inner = 'crossorigin'; }
                    res('EXTRA=PREVIEW modalOpen=' + (modal && modal.classList.contains('active'))
                        + ' src=' + f.getAttribute('src')
                        + ' innerLen=' + inner);
                }, 2500);
            })`,
            awaitPromise: true,
            returnByValue: true,
        });
        const v2 = (r2 && r2.result && r2.result.value) || 'EXTRA=NO_VALUE';
        console.log(v2);
        if (/innerLen=[1-9]/.test(v2)) console.log('RESULT=PREVIEW_OK');
        else console.log('RESULT=PREVIEW_BROKEN');
    }
    if (ok && shot) {
        await send('Emulation.setDeviceMetricsOverride', {
            width: 1500, height: 1000, deviceScaleFactor: 1, mobile: false,
        });
        await send('Runtime.evaluate', {
            expression: `(() => {
                const b = document.querySelector('.dq-view');
                if (!b) return;
                b.scrollIntoView({block: 'center'});
                b.click();
                document.querySelectorAll('.dq-split').forEach(e => e.style.display = 'none');
            })()`,
        });
        await sleep(400);
        const img = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(shot, Buffer.from(img.data, 'base64'));
        console.log('SHOT=' + shot);
    }
    // Optional: a TRUSTED click via the input pipeline, not .click().
    // Synthetic clicks are untrusted, so they bypass target=_blank
    // handling and popup heuristics — exactly the code paths that decide
    // whether a new tab appears. This observes real navigation the way a
    // user's click would.
    const trusted = process.argv[7];
    if (ok && trusted) {
        // Record every browsing context that appears. A new tab shows up
        // here as a new target that the desk did not create.
        const created = [];
        ws.addEventListener('message', (ev) => {
            const m = JSON.parse(ev.data);
            if (m.method === 'Target.targetCreated') {
                created.push(m.params.targetInfo.type + ':' + (m.params.targetInfo.url || ''));
            }
        });
        await send('Target.setDiscoverTargets', { discover: true });

        const box = await send('Runtime.evaluate', {
            expression: `(() => {
                const want = ${JSON.stringify(process.argv[8] || 'printDoc')};
                // If the preview modal is already open, click its Print.
                // Otherwise open the detail row, and for Print open the
                // preview first so the iframe exists to print from.
                //
                // The ordering matters: clicking a button inside a CLOSED
                // detail row gave scrollIntoView nothing on screen to aim
                // at, so the synthesised click missed entirely and the
                // test reported "no tab opened" for a click that never
                // landed. A green result that means nothing.
                const modal = document.getElementById('docPreviewModal');
                if (modal && modal.classList.contains('active')) {
                    const pb = document.querySelector('#docPreviewShell button[onclick*="printDoc"]');
                    if (!pb) return null;
                    pb.scrollIntoView({block:'center'});
                    const r = pb.getBoundingClientRect();
                    return {x: Math.round(r.left + r.width/2), y: Math.round(r.top + r.height/2),
                            label: pb.innerText.trim(), from:'modal'};
                }
                const btn = document.querySelector('.dq-view');
                btn.click();
                const d = document.getElementById('detail-' + btn.getAttribute('data-doc'));
                const pv = d.querySelector('button[onclick*="' + want + '"]');
                if (!pv) return null;
                if (want === 'printDoc') {
                    const op = d.querySelector('button[onclick*="openPreview"]');
                    if (op) op.click();
                }
                pv.scrollIntoView({block:'center'});
                const r = pv.getBoundingClientRect();
                return {x: Math.round(r.left + r.width/2), y: Math.round(r.top + r.height/2),
                        label: pv.innerText.trim(), from:'row'};
            })()`,
            returnByValue: true,
        });
        const b = box && box.result && box.result.value;
        if (!b) {
            console.log('TRUSTED=NO_BUTTON');
        } else {
            const before = created.length;
            // Count window.open calls. Headless browsers BLOCK popups, so
            // "no new target appeared" is not evidence on its own — a
            // blocked popup leaves no trace and the test would pass while
            // the user still gets a tab. Counting the call is the only
            // reliable signal, so wrap it and report the tally.
            await send('Runtime.evaluate', {
                expression: `(() => {
                    window.__opens = 0;
                    const real = window.open;
                    window.open = function () { window.__opens++; return real.apply(window, arguments); };
                    return 'wrapped';
                })()`,
            });
            await send('Input.dispatchMouseEvent', {
                type: 'mousePressed', x: b.x, y: b.y, button: 'left', clickCount: 1,
            });
            await send('Input.dispatchMouseEvent', {
                type: 'mouseReleased', x: b.x, y: b.y, button: 'left', clickCount: 1,
            });
            await sleep(1800);
            const opens = await send('Runtime.evaluate', {
                expression: 'String(window.__opens)', returnByValue: true,
            });
            const n = opens && opens.result && opens.result.value;
            const newTargets = created.slice(before);
            console.log('TRUSTED clicked="' + b.label + '" windowOpenCalls=' + n
                + ' newTargets=' + JSON.stringify(newTargets));
        }
    }

    cleanup(ok ? 0 : 1);
})().catch((e) => {
    console.error('RESULT=ERROR', e && e.message);
    cleanup(1);
});