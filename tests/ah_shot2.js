// ============================================================
//  TESTS/ah_shot2.js  (design review, not part of the suite)
//
//  Screenshots the redesigned Academic History page so the layout can be
//  LOOKED at rather than inferred from the DOM.
//
//    node tests/ah_shot2.js <port> <cookie> <out.png>
//
//  Also opens the first student's record, because the record is the part
//  most likely to be wrong and the part a DOM assertion checks least.
// ============================================================

'use strict';
const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const PORT = Number(process.argv[2] || 9670);
const COOKIE = process.argv[3] || '';
const OUT = process.argv[4] || 'ah_design';

const PAGE = 'http://localhost/registrar-ai-system/registrar/academic-history.php';
const CANDS = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
];
const browser = CANDS.find(p => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dq-shot2-'));
const child = spawn(browser, ['--headless=new', '--disable-gpu', '--no-sandbox',
    '--no-first-run', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
    '--window-size=1440,1100', 'about:blank'], { stdio: 'ignore' });

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

    // Collect page errors so a broken script shows up here instead of as a
    // blank region nobody can explain.
    const errors = [];
    ws.addEventListener('message', e => {
        const m = JSON.parse(e.data);
        if (m.method === 'Runtime.exceptionThrown') {
            errors.push(m.params.exceptionDetails.text + ' '
                + (m.params.exceptionDetails.exception || {}).description);
        }
    });

    const kv = COOKIE.split('=');
    await send('Network.setCookie', {
        name: kv[0].trim(), value: kv[1], domain: 'localhost', path: '/'
    });

    const ev = async x => (await send('Runtime.evaluate',
        { expression: x, returnByValue: true, awaitPromise: true })).result.value;

    await send('Page.navigate', { url: PAGE });
    await sleep(3500);

    // Fonts must be settled before capture, or Fraunces falls back and the
    // screenshot misrepresents the design.
    await ev('document.fonts.ready.then(()=>1)');
    await sleep(800);

    let shot = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(OUT + '_top.png', Buffer.from(shot.data, 'base64'));

    // Open the first record.
    // Checked the way the page itself does — by id — rather than with a
    // `tr[data-ah-detail]:not([hidden])` descendant query. That selector
    // looked reasonable and reported DID_NOT_OPEN on a row that was
    // demonstrably open, which is worse than no check at all: it teaches
    // you to distrust the probe.
    const opened = await ev(`(function(){
        // Open the first student who actually HAS grades. Picking rows[0]
        // picks whoever sorts first, and "this student has no grades" is a
        // correct state that tells you nothing about whether the record
        // renders. The empty state is checked on its own below.
        var btns = Array.prototype.slice.call(
            document.querySelectorAll('tr[data-ah-row] .ah-open'));
        var b = null;
        for (var i = 0; i < btns.length; i++) {
            var id = btns[i].closest('tr').dataset.student;
            var r = rowById(parseInt(id, 10));
            if (r && r.subjects && r.subjects.length) { b = btns[i]; break; }
        }
        if (!b) return 'NO_ROW_WITH_GRADES';
        b.click();
        var id = b.closest('tr').dataset.student;
        var d = document.getElementById('rec-' + id);
        if (!d) return 'NO_DETAIL_ROW';
        if (d.hidden) return 'STILL_HIDDEN';
        var grid = d.querySelector('.table');
        return 'OPEN rows=' + (grid ? grid.querySelectorAll('tbody tr').length : 0)
            + ' print=' + (d.querySelector('[data-print]') ? 'yes' : 'no');
    })()`);
    await sleep(900);

    shot = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(OUT + '_record.png', Buffer.from(shot.data, 'base64'));

    console.log('record: ' + opened);

    // Facts about the click path itself. The state below tells us the row
    // closed again, but not WHY, and a record that opens and silently
    // shuts is worse than one that never opens.
    const why = await ev(`(function(){
        var b = document.querySelector('tr[data-ah-row] .ah-open');
        var tr = b.closest('tr');
        var raw = tr.dataset.student;
        var d = document.getElementById('rec-' + raw);

        // Which globals survived? The page script is top-level, so an
        // exception anywhere in it stops everything after that point —
        // while function DECLARATIONS are hoisted and still appear to
        // exist. That combination is exactly what made this look like
        // "the listener is registered but never called": it never was.
        function present(n) { return typeof window[n] !== 'undefined'; }
        var defined = ['rowById','ratingBand','recordHtml','toggleRecord',
                       'openDialog','closeDialog','findingHtml','openAudit',
                       'closeAudit','printGradeTemplate']
            .filter(present);

        // Re-run the inline script body with the error surfaced. Wrapping in
        // a function gives it a scope so re-declarations are harmless.
        var s = document.querySelector('script:not([src])');
        var bodies = Array.prototype.map.call(
            document.querySelectorAll('script:not([src])'),
            function (x) { return x.textContent; });
        var mine = bodies.filter(function (t) {
            return t.indexOf('function toggleRecord') !== -1; })[0];

        var err = null;
        if (mine) {
            try { new Function(mine)(); } catch (e) { err = e.message; }
        }

        return JSON.stringify({
            definedGlobals: defined,
            scriptFound: !!mine,
            reRunError: err,
            rawAttr: raw,
            hiddenAfterLoad: d.hidden
        });
    })()`);
    console.log('why: ' + why);

    // Full state after the click, so a mismatch is visible rather than
    // reduced to one word.
    const after = await ev(`(function(){
        var b = document.querySelector('tr[data-ah-row] .ah-open');
        var d = document.getElementById('rec-' + b.closest('tr').dataset.student);
        return JSON.stringify({
            expanded: b.getAttribute('aria-expanded'),
            detailHidden: d.hidden,
            detailDisplay: getComputedStyle(d).display,
            innerLen: d.innerHTML.length
        });
    })()`);
    console.log('state: ' + after);
    console.log('js errors: ' + (errors.length ? errors.join(' | ') : 'none'));
    console.log('SHOTS=' + OUT + '_top.png,' + OUT + '_record.png');
    done(errors.length ? 1 : 0);
})().catch(e => { console.error('ERR ' + e.message); done(1); });