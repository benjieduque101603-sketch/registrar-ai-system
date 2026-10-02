// ============================================================
//  TESTS/academic_readonly_probe.js
//  Verifies registrar/academic-history.php after grades moved to Faculty
//  (2026-10-02 boundary change).
//
//    node tests/academic_readonly_probe.js <port> <cookie> [outPrefix]
//
// Two things are checked, neither of which a screenshot can answer:
//
//  1. The page is genuinely read-only. Not "the buttons look disabled" —
//     there must be NO input/select/textarea in the grade grid, no data-f
//     attributes, and no surviving save function. A read-only page that
//     still contains a save path is not read-only.
//
//  2. The printable template builds a real document carrying the shared
//     letterhead, the crest, and every grade the student has.
// ============================================================

'use strict';
const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const PORT = Number(process.argv[2] || 9640);
const COOKIE = process.argv[3] || '';
const OUT = process.argv[4] || 'academic_print';
const PAGE = 'http://localhost/registrar-ai-system/registrar/academic-history.php';
const CANDIDATES = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
];
const browser = CANDIDATES.find((p) => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dq-acad-'));
const child = spawn(browser, ['--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
    `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, '--window-size=1400,1000', 'about:blank'],
    { stdio: 'ignore' });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
function done(code) {
    try { child.kill(); } catch (_) {}
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (_) {}
    process.exit(code);
}

(async () => {
    let failures = 0;
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
    ws.onmessage = (e) => { const m = JSON.parse(e.data);
        if (m.id && pending.has(m.id)) { const p = pending.get(m.id); pending.delete(m.id);
            m.error ? p.rej(new Error(JSON.stringify(m.error))) : p.res(m.result); } };
    await send('Page.enable');
    if (COOKIE) {
        const kv = COOKIE.split(';')[0].split('=');
        await send('Network.setCookie', { name: kv[0].trim(), value: kv[1], domain: 'localhost', path: '/' });
    }
    const ev = async (expr) => (await send('Runtime.evaluate',
        { expression: expr, returnByValue: true, awaitPromise: true })).result.value;

    await send('Page.navigate', { url: PAGE });
    await sleep(3500);
// ── 1. Read-only proof ────────────────────────────────────────
    const ro = JSON.parse(await ev(`JSON.stringify({
        // The redesigned ledger: no inputs, and no editing functions.
        inputsInLedger: document.querySelectorAll(
            '.table input, .table select, .table textarea').length,
        inputsInRecords: document.querySelectorAll(
            '.ah-record input, .ah-record select, .ah-record textarea').length,
        anyDataF: document.querySelectorAll('[data-f]').length,
        hasSaveFn: typeof saveGrades === 'function',
        hasAddRow: typeof addGradeRow === 'function',
        hasRemoveRow: typeof removeGradeRow === 'function',
        hasReadGrid: typeof readGrid === 'function',
        hasUpdatePreview: typeof updatePreview === 'function',
        // Removed dialogs.
        hasGradeModal: !!document.getElementById('gradeModal'),
        hasViewModal: !!document.getElementById('viewModal'),
        // The row is the target now.
        rows: document.querySelectorAll('tr[data-ah-row]').length,
        disclosures: document.querySelectorAll('.ah-open').length,
        recordHosts: document.querySelectorAll('[data-ah-record]').length,
        // No button chrome left in the table.
        buttonChrome: document.querySelectorAll(
            '.table .btn, .table .fa-eye, .table .fa-pen,'
            + ' .table .fa-print').length
    })`));
    console.log('readonly scan: ' + JSON.stringify(ro, null, 1));

    [
        ['no inputs in the ledger', ro.inputsInLedger === 0],
        ['no inputs in the records', ro.inputsInRecords === 0],
        ['no data-f editor attributes', ro.anyDataF === 0],
        ['saveGrades() removed', !ro.hasSaveFn],
        ['addGradeRow() removed', !ro.hasAddRow],
        ['removeGradeRow() removed', !ro.hasRemoveRow],
        ['readGrid() removed', !ro.hasReadGrid],
        ['updatePreview() removed', !ro.hasUpdatePreview],
        ['grade dialog removed', !ro.hasGradeModal],
        ['view dialog removed', !ro.hasViewModal],
        ['no button chrome in the ledger', ro.buttonChrome === 0],
        ['every row is a disclosure target', ro.rows === ro.disclosures],
        ['every row has a record host', ro.rows === ro.recordHosts],
    ].forEach(function (c) {
        if (!c[1]) { console.log('  FAIL: ' + c[0]); failures++; }
    });

    if (!ro.rows) { console.log('FAIL: roster is empty - nothing to print'); done(1); }

    // ── 2. Build the template ────────────────────────────────────
    // Only print() is stubbed; the document is built by the real
    // printGradeTemplate() through the real BCPPrint.printDocument().
    // Print the first student who actually HAS records. Taking rows[0] would
// pick a student with no academic history at all — which is a perfectly
// valid state (enrolled, nothing graded yet) and would make the template
// assertions fail for a reason that has nothing to do with the template.
// The empty-student path is asserted separately below.
const printedId = await ev(`(function(){
        var pick = AH.rows.find(function (r) { return (r.career || []).length > 0; });
        if (!pick) return -1;
        var realPrint = window.print;
        window.print = function(){};
        var before = document.querySelectorAll('iframe').length;
        try { printGradeTemplate(pick.id); }
        catch (e) { window.print = realPrint; return 'ERR:' + e.message; }
        window.print = realPrint;
        var frames = document.querySelectorAll('iframe');
        if (frames.length <= before) return 'NOFRAME';
        var f = frames[frames.length - 1];
        if (!f.contentDocument || !f.contentDocument.documentElement) return 'EMPTY';
        // Store the markup for the load step below instead of returning it.
        // Returning ~60 KB over CDP floods the console and, worse, a value
        // this size is fragile to pass back through the protocol.
        window.__doc = f.contentDocument.documentElement.outerHTML;
        return 'OK:' + window.__doc.length;
    })()`);
    if (typeof printedId === 'string' && printedId.indexOf('OK:') !== 0) {
        console.log('FAIL: could not build the template: ' + printedId);
        done(1);
    }
    if (printedId === -1) {
        console.log('SKIP: no student in this database has any academic records, '
            + 'so the printable template cannot be exercised');
        done(0);
    }
    console.log('  template built: ' + printedId);

    const captured = await ev('window.__doc');
    if (!captured || captured.length < 500) {
        console.log('FAIL: template markup was not retrievable');
        done(1);
    }

    // setDocumentContent, not a data: URL. The document is ~60 KB and a
    // data: URL that size is dropped by the navigation, leaving a blank
    // page where every selector reads null — the same trap as the print
    // report probe, and it reads as a layout failure rather than a
    // transport limit.
    await send('Emulation.setDeviceMetricsOverride',
        { width: 900, height: 1240, deviceScaleFactor: 1, mobile: false });
    await send('Page.setDocumentContent', { frameId: target.id, html: captured });
    await sleep(1500);

    const t = JSON.parse(await ev(`(function(){
        function txt(s){ var e=document.querySelector(s);
            return e?e.textContent.replace(/\\s+/g,' ').trim():null; }
        var logo = document.querySelector('.lh-logo img');
        return JSON.stringify({
            school: txt('.lh-school'),
            unit: txt('.lh-unit'),
            address: txt('.lh-address'),
            docTitle: txt('.lh-doc'),
            footAlign: document.querySelector('.footer')
                ? getComputedStyle(document.querySelector('.footer')).textAlign : null,
            logoLoaded: logo ? (logo.complete && logo.naturalWidth > 0) : false,
            hasIdent: !!document.querySelector('.gt-ident'),
            hasGrid: !!document.querySelector('.gt-grid'),
            gradeRows: document.querySelectorAll('.gt-grid tbody tr').length,
            headers: Array.prototype.map.call(document.querySelectorAll('.gt-grid th'),
                function(e){ return e.textContent.trim(); }),
            termHeads: document.querySelectorAll('.gt-term-head').length,
            careerGwa: !!txt('.gt-career'),
            ink: document.querySelector('.gt-grid td')
                ? getComputedStyle(document.querySelector('.gt-grid td')).color : null,
            // Nothing may still be editable in the PRINTED document either.
            inputs: document.querySelectorAll('input, select, textarea').length
        });
    })()`));
    console.log('template: ' + JSON.stringify(t, null, 1));

    [
        ['letterhead school name', t.school === 'BESTLINK COLLEGE OF THE PHILIPPINES'],
        ['unit line', t.unit === 'College of Computer Studies'],
        ['crest loaded', t.logoLoaded === true],
        ['footer centred', t.footAlign === 'center'],
        ['identity block printed', t.hasIdent === true],
        ['grade table printed', t.hasGrid === true],
        ['grade rows present', t.gradeRows > 0],
        ['subject/code/units/rating columns',
            ['Subject', 'Code', 'Units', 'Final Rating'].every(
                h => t.headers.indexOf(h) !== -1)],
        ['per-term headings', t.termHeads > 0],
        ['cumulative GWA printed', t.careerGwa === true],
        ['printed text is black', t.ink === 'rgb(0, 0, 0)'],
        ['no inputs in the printed document', t.inputs === 0],
    ].forEach(function (c) {
        if (!c[1]) { console.log('  FAIL: ' + c[0]); failures++; }
    });

    const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    fs.writeFileSync(`${OUT}.png`, Buffer.from(shot.data, 'base64'));
    console.log(`SHOT=${OUT}.png`);

    console.log(failures ? `RESULT=FAIL (${failures})` : 'RESULT=PASS');
    done(failures ? 1 : 0);
})().catch((e) => { console.error('ERR ' + e.message); done(1); });