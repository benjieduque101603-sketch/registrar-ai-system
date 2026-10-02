// ============================================================
//  TESTS/shot_page.js  (design reference tool)
//
//  Screenshots any page in the portal under an authenticated session, so the
//  house style can be LOOKED at rather than inferred from class names.
//
//    node tests/shot_page.js <port> <cookie> <path> <out.png> [clickSelector]
//
//  The optional clickSelector runs before the capture, which is how a
//  collapsed row or an unopened panel gets photographed open.
//
//  This exists because a redesign can be wrong in a way no test catches. The
//  tests can confirm a class is present; only a picture shows whether the
//  page still looks like the rest of the system.
// ============================================================

'use strict';
const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const PORT = Number(process.argv[2] || 9690);
const COOKIE = process.argv[3] || '';
const PAGE_PATH = process.argv[4] || 'registrar/documents.php';
const OUT = process.argv[5] || 'shot';
const CLICK = process.argv[6] || '';
// Optional viewport width, so responsive rules can actually be looked at
// rather than assumed. A media query verified only at desktop width is not
// a verified media query.
const WIDTH = Number(process.argv[7] || 1440);

const PAGE = 'http://localhost/registrar-ai-system/' + PAGE_PATH.replace(/^\//, '');

const CANDS = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
];
const browser = CANDS.find(p => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dq-page-'));
const child = spawn(browser, ['--headless=new', '--disable-gpu', '--no-sandbox',
    '--no-first-run', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
    // A template literal, not single quotes: '${WIDTH}' inside '…' is a
    // literal, the browser gets a nonsense size, and it silently falls back
    // to its default viewport. That failure looks like a layout bug.
    `--window-size=${WIDTH},1400`, 'about:blank'], { stdio: 'ignore' });

const sleep = ms => new Promise(r => setTimeout(r, ms));
function done(c) {
    try { child.kill(); } catch (_) {}
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (_) {}
    process.exit(c);
}

(async () => {
    let target = null;
    for (let i = 0; i < 60 && !target; i++) {
        await sleep(200);
        try {
            const l = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
            target = l.find(t => t.type === 'page');
        } catch (_) {}
    }
    if (!target) { console.error('RESULT=NO_TARGET'); done(1); }

    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise(r => (ws.onopen = r));
    let id = 0; const pending = new Map();
    const send = (m, p) => {
        const i = ++id;
        ws.send(JSON.stringify({ id: i, method: m, params: p }));
        return new Promise((res, rej) => pending.set(i, { res, rej }));
    };
    ws.onmessage = e => {
        const m = JSON.parse(e.data);
        if (m.id && pending.has(m.id)) {
            const p = pending.get(m.id); pending.delete(m.id);
            m.error ? p.rej(new Error(JSON.stringify(m.error))) : p.res(m.result);
        }
    };
    await send('Page.enable');
    await send('Runtime.enable');

    const kv = COOKIE.split('=');
    await send('Network.setCookie', {
        name: kv[0].trim(), value: kv[1], domain: 'localhost', path: '/'
    });
    const ev = async x => (await send('Runtime.evaluate',
        { expression: x, returnByValue: true, awaitPromise: true })).result.value;

    await send('Page.navigate', { url: PAGE });
    await sleep(3600);

    // Fonts must settle before capture, or the picture misrepresents the
    // type treatment the page actually uses.
    await ev('document.fonts.ready.then(()=>1)');
    await sleep(700);

    if (CLICK) {
        // A selector may carry a ">>N" suffix to pick the Nth match. Rows on
        // this page are interleaved with their detail rows, so :nth-of-type
        // counts the wrong elements â€” index selection is what actually
        // addresses a student.
        //
        // An explicit index always wins. Falling back to querySelector first
        // looked like it worked and silently clicked the first match anyway.
        const parts = CLICK.split('>>');
        const rawSel = parts[0];
        const hasIdx = parts.length > 1;
        const idx = hasIdx ? Number(parts[1]) : 0;
        const r = await ev(`(function(){
            var sel = ${JSON.stringify(rawSel)};
            var all = document.querySelectorAll(sel);
            if (!all.length) return 'NO_MATCH';
            var el = all[${idx}];
            if (!el) return 'OUT_OF_RANGE(' + all.length + ')';
            el.click();
            return 'CLICKED[' + ${idx} + ' of ' + all.length + ']';
        })()`);
        console.log('click ' + CLICK + ' -> ' + r);
        await sleep(1100);
    }

    const shot = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(OUT, Buffer.from(shot.data, 'base64'));
    console.log('SHOT=' + OUT);
    done(0);
})().catch(e => { console.error('ERR ' + e.message); done(1); });
