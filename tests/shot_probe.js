// Screenshot-only probe: no interaction, so it can be pointed at any
// width without the View toggle firing a modal over the thing being
// measured. Used to check the rail's narrow-width fallback, which the
// toggle probe could never actually show - it opens the preview and
// that modal covers the column at the width under test.
//
//   node tests/shot_probe.js <port> <url> <cookie> <out.png> [width] [height]
'use strict';

const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const PORT = Number(process.argv[2] || 9333);
const PAGE = process.argv[3];
const COOKIE = process.argv[4] || '';
const OUT = process.argv[5] || 'shot.png';
const W = Number(process.argv[6] || 1500);
const H = Number(process.argv[7] || 1000);

const CANDIDATES = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
];
const browser = CANDIDATES.find((p) => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dq-shot-'));
const child = spawn(browser, [
    '--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
    `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
    `--window-size=${W},${H}`, 'about:blank',
], { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
function done(code) {
    try { child.kill(); } catch (_) {}
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (_) {}
    process.exit(code);
}

(async () => {
    let target = null;
    for (let i = 0; i < 60 && !target; i++) {
        await sleep(200);
        try {
            const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
            target = list.find((t) => t.type === 'page');
        } catch (_) {}
    }
    if (!target) { console.error('RESULT=NO_TARGET'); done(1); }

    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((r) => (ws.onopen = r));
    let id = 0; const pending = new Map();
    const send = (method, params) => {
        const mid = ++id;
        ws.send(JSON.stringify({ id: mid, method, params }));
        return new Promise((res, rej) => pending.set(mid, { res, rej }));
    };
    ws.onmessage = (e) => {
        const m = JSON.parse(e.data);
        if (m.id && pending.has(m.id)) {
            const p = pending.get(m.id); pending.delete(m.id);
            m.error ? p.rej(new Error(JSON.stringify(m.error))) : p.res(m.result);
        }
    };

    await send('Emulation.setDeviceMetricsOverride', { width: W, height: H, deviceScaleFactor: 1, mobile: false });
    await send('Page.enable');
    await send('Network.enable');
    if (COOKIE) {
        const kv = COOKIE.split(';')[0].split('=');
        await send('Network.setCookie', { name: kv[0].trim(), value: kv[1], domain: 'localhost', path: '/' });
    }
    await send('Page.navigate', { url: PAGE });
    // The desk shows a global loading veil until the data table lands.
    // Shooting before it clears measures the veil, not the page - which
    // is exactly what a fixed sleep got wrong the first time.
    let veil = null;
    for (let i = 0; i < 40; i++) {
        const st = await send('Runtime.evaluate', {
            expression: `(function(){
                // js/page-loader.js injects a splash div holding .pl-logo/.pl-text,
                // adds .hide to it, then removes it 380ms later.
                var t=document.querySelector('.pl-text');
                var v = t ? t.parentElement.parentElement : null;
                var vis = v && !v.classList.contains('hide');
                return (vis ? '1' : '0') + '|' + (document.querySelectorAll('.dq-station').length);
            })()`, returnByValue: true });
        const [vis, stations] = String(st.result.value).split('|');
        if (vis === '0' && Number(stations) > 0) { veil = stations; break; }
        await sleep(250);
    }
    console.log('STATIONS=' + veil);
    // Optional 7th arg: a CSS selector to scroll into view first, so a
    // narrow screenshot lands on the table instead of the stat cards.
    if (process.argv[8]) {
        await send('Runtime.evaluate', {
            expression: `(function(){var el=document.querySelector(${JSON.stringify(process.argv[8])});
                if(el){el.scrollIntoView({block:'start'});} return 1;})()`, returnByValue: true });
        await sleep(500);
    }
    await sleep(400);

    // Measure rather than eyeball: "hidden above 760px, shown below" is
    // a claim about computed styles, so read them back.
    const info = await send('Runtime.evaluate', {
        expression: `(function(){
            var rail=document.querySelector('.dq-rail'),fb=document.querySelector('.dq-rail-fallback');
            return JSON.stringify({
              rail: rail?getComputedStyle(rail).display:'absent',
              fallback: fb?getComputedStyle(fb).display:'absent',
              fallbackText: fb?fb.textContent.trim().replace(/\\s+/g,' '):'',
              vw: window.innerWidth
            });})()`,
        returnByValue: true,
    });
    const shot = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(OUT, Buffer.from(shot.data, 'base64'));
    console.log('RAIL=' + info.result.value);
    console.log('SHOT=' + OUT);
    done(0);
})().catch((e) => { console.error('RESULT=ERR ' + e.message); done(1); });



