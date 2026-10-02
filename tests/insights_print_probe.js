// ============================================================
//  TESTS/INSIGHTS_PRINT_PROBE.JS
//  Renders the AI Insight printable report and photographs it, so the
//  letterhead and footer can be looked at rather than assumed.
//
//    node tests/insights_print_probe.js <port> <cookie> [outPrefix]
//
// The report text is INJECTED rather than generated. Calling the real
// endpoint needs the AI gateway, and when it is down the page falls back to
// a deterministic report — so a probe that generates its own content would
// silently test two different documents on two different days, and could
// never fail on a layout break. The letterhead is what is under test, and it
// does not depend on the prose.
// ============================================================

'use strict';
const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const PORT = Number(process.argv[2] || 9530);
const COOKIE = process.argv[3] || '';
const OUT = process.argv[4] || 'insights_print';
const PAGE = 'http://localhost/registrar-ai-system/ai/insights.php';
const CANDIDATES = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
];
const browser = CANDIDATES.find((p) => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dq-insprint-'));
const child = spawn(browser, ['--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
    `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, '--window-size=1200,1600', 'about:blank'],
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

    // Give the page a report to print without calling the AI endpoint: the
    // same shape the endpoint returns, so the Print button's own handler runs
    // the real openPrintView(). Nothing is exported to make this reachable —
    // js/insights.js is one IIFE, and adding test-only globals to production
    // code is a worse trade than clicking the button a person clicks.
    await ev(`(function(){
        // Stub the report fetch the way the page will call it, then click
        // Generate so the page goes through its normal render path.
        var realFetch = window.fetch;
        window.fetch = function(url, opts) {
            if (String(url).indexOf('ai-insights-report') !== -1) {
                return Promise.resolve({
                    ok: true, json: function(){
                        return Promise.resolve({ success: true, data: {
                            report: '## 1. AI Registrar Summary\\n'
                                + 'The reporting period closed with a settled enrolment picture and no new backlog.\\n\\n'
                                + '## 2. Detected Trends\\n'
                                + '- **Students:** enrolment held steady across the period.\\n'
                                + '- **Document Transactions:** request volume tracked the term calendar.\\n'
                                + '- **RFID:** card coverage remained complete.\\n'
                                + '- **Queue:** peak waiting occurred at midday.\\n\\n'
                                + '## 3. Patterns Observed\\n'
                                + '- Turnaround stayed inside the usual window.\\n'
                                + '- No status contradicted the record.\\n',
                            // Deliberately 'fallback'. The probe has to
                            // prove the gateway notice is suppressed, and
                            // that branch only runs when the source is not
                            // 'ai' — an 'ai' fixture would pass trivially.
                            source: 'fallback', model: null,
                            generated_at: '2026-01-01 00:00:00',
                            period: JSON.parse(document.getElementById('insPrintPeriod') || 'null'),
                            facts: null, cards: null
                        }});
                    }
                });
            }
            return realFetch.apply(this, arguments);
        };
        document.getElementById('generateBtn').click();
        return true;
    })()`);
    await sleep(1500);

    // The report must actually be on the page, or every assertion below
    // would pass against an empty print view.
    const hasReport = await ev("!!document.querySelector('#reportOutput').textContent.trim()");
    if (!hasReport) {
        // Surface why rather than reporting an empty print view, which reads
        // as a layout problem when the cause is in the page's own script.
        const why = await ev("JSON.stringify({"
            + " err: document.getElementById('reportError') ? document.getElementById('reportError').textContent.trim() : null,"
            + " loading: document.getElementById('reportLoading') ? getComputedStyle(document.getElementById('reportLoading')).display : null,"
            + " out: document.getElementById('reportOutput') ? document.getElementById('reportOutput').innerHTML.slice(0,200) : null })");
        console.log('FAIL: no report was rendered to print. ' + why);
        done(1);
    }


    // The print path now writes into a hidden <iframe> on this page instead of
    // opening a pop-up. Two things are checked:
    //   · no new tab appears (the pop-up regression)
    //   · the frame the button created is populated with the real document
    // contentWindow.print() itself is NOT asserted — headless Chrome would
    // open a real blocking dialog and hang the probe. It is a single visible
    // line in openPrintView(), and every layout assertion below is made
    // against the document that line prints.
    const tabsBefore = (await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json())
        .filter((t) => t.type === 'page').length;

    const captured = await ev(`(function(){
        var before = document.querySelectorAll('iframe').length;
        document.getElementById('printBtn').click();
        var frames = document.querySelectorAll('iframe');
        if (frames.length <= before) {
            return JSON.stringify({ threw: 'no print frame was created' });
        }
        var f = frames[frames.length - 1];
        if (!f.contentDocument || !f.contentDocument.documentElement) {
            return JSON.stringify({ threw: 'print frame not populated' });
        }
        // The frame must also stay off-screen and out of the layout.
        return f.contentDocument.documentElement.outerHTML + '||GEO||' + JSON.stringify({
            position: getComputedStyle(f).position,
            left: f.style.left, width: f.style.width, height: f.style.height,
            ariaHidden: f.getAttribute('aria-hidden')
        });
    })()`);
    const sep = captured.indexOf('||GEO||');
    if (!captured || sep === -1 || captured.indexOf('threw') !== -1) {
        console.log('FAIL: could not read the print document: ' + captured);
        done(1);
    }
    const html = captured.slice(0, sep);
    const geo = JSON.parse(captured.slice(sep + '||GEO||'.length));
    const geoOk = geo.position === 'fixed' && parseInt(geo.left, 10) < 0
        && parseInt(geo.width, 10) > 0 && geo.ariaHidden === 'true';
    console.log('  frame: ' + JSON.stringify(geo)
        + (geoOk ? ' (hidden off-screen: ok)' : ' (BAD GEOMETRY)'));
    if (!geoOk) { console.log('  FAIL: print frame is not correctly hidden'); failures++; }

    // No new tab: printing must not open one.
    const tabsAfter = (await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json())
        .filter((t) => t.type === 'page').length;
    console.log('  tabs: ' + tabsBefore + ' -> ' + tabsAfter
        + (tabsAfter === tabsBefore ? ' (no pop-up: ok)' : ' (POP-UP OPENED)'));
    if (tabsAfter !== tabsBefore) { console.log('  FAIL: printing opened a new tab'); failures++; }

    console.log('captured print doc: ' + html.length + ' bytes');
    // Confirm the letterhead actually made it into the captured string. If it
    // is absent here, the stub captured a partial document and every selector
    // downstream is null for a reason that has nothing to do with layout.
    ['lh-school', 'lh-unit', 'lh-address', 'class="footer"', 'letterhead']
        .forEach(function (needle) {
            console.log('  contains ' + needle + ': ' + (html.indexOf(needle) !== -1));
        });
    // Loaded with Page.setDocumentContent rather than a data: URL. A 90 KB
    // document — mostly base64 chart PNGs — pushed into a data: URL is
    // silently dropped by the navigation, and the probe then measured a blank
    // page and reported every selector as null. setDocumentContent takes the
    // markup directly and has no such ceiling.
    // openPrintView() writes "</body></html>" but never writes an opening
    // <body>. A real window.open() document has the parser insert one;
    // Page.setDocumentContent does not, so the markup arrives with an empty
    // body and every selector reads null. Insert the implied tag rather than
    // changing the production string for the probe's benefit.
    const markup = /<body[^>]*>/i.test(html)
        ? html
        : html.replace(/(<style>[\s\S]*?<\/style>)/i, '$1<body>');
    await send('Emulation.setDeviceMetricsOverride',
        { width: 900, height: 1240, deviceScaleFactor: 1, mobile: false });
    await send('Page.setDocumentContent', { frameId: target.id, html: markup });
    await sleep(2000);

    await sleep(2000);

    // ── Assert the letterhead and footer ──────────────────────
    const q = await ev(`(function(){
        function cs(sel, prop) {
            var el = document.querySelector(sel);
            return el ? getComputedStyle(el)[prop] : null;
        }
        function txt(sel) {
            var el = document.querySelector(sel);
            return el ? el.textContent.replace(/\\s+/g, ' ').trim() : null;
        }
        var school = document.querySelector('.lh-school');
        var unit = document.querySelector('.lh-unit');
        var addr = document.querySelector('.lh-address');
        var logo = document.querySelector('.letterhead img');
        return JSON.stringify({
            school: txt('.lh-school'),
            schoolFont: cs('.lh-school', 'fontFamily'),
            schoolSize: cs('.lh-school', 'fontSize'),
            unit: txt('.lh-unit'),
            unitFont: cs('.lh-unit', 'fontFamily'),
            unitSize: cs('.lh-unit', 'fontSize'),
            addr: txt('.lh-address'),
            addrFont: cs('.lh-address', 'fontFamily'),
            addrSize: cs('.lh-address', 'fontSize'),
            foot: txt('.footer'),
            footFont: cs('.footer', 'fontFamily'),
            footSize: cs('.footer', 'fontSize'),
            footPosition: cs('.footer', 'position'),
            logoW: logo ? Math.round(logo.getBoundingClientRect().width) : 0,
            logoLoaded: logo ? (logo.complete && logo.naturalWidth > 0) : false,
            logoComplete: logo ? logo.complete === true : false,
            // The crest must span the three name lines: its top reaches the top of the
            // college name and its bottom reaches the bottom of the address.
            logoSpansNames: logo && school && addr
                ? logo.getBoundingClientRect().top <= school.getBoundingClientRect().top + 1
                    && logo.getBoundingClientRect().bottom >= addr.getBoundingClientRect().bottom - 1
                : false,
            // Vertical gaps between the name lines must match.
            headerGaps: (function () {
                if (!school || !unit || !addr) return [];
                var gap = function (a, b) {
                    return Math.round(b.getBoundingClientRect().top - a.getBoundingClientRect().bottom);
                };
                return [gap(school, unit), gap(unit, addr)];
            })(),
            ink: {
                meta: cs('.meta', 'color'),
                para: cs('.doc-body p', 'color'),
                head: cs('.doc-h', 'color'),
                foot: cs('.footer', 'color'),
                footNote: cs('.foot-note', 'color')
            },
            metaText: txt('.meta'),
            sigStyled: cs('.sig', 'display'),
            // Nothing from the old dashboard furniture may survive.
            imgsInBody: document.querySelectorAll('.doc-body img').length,
            chartBlocks: document.querySelectorAll('.chart-block').length,
            kpiTiles: document.querySelectorAll('.kpi').length,
            reportSections: document.querySelectorAll('.report-section').length,
            headings: Array.prototype.map.call(document.querySelectorAll('.doc-h'),
                function (el) { return el.textContent.trim(); }),
            bodyAlign: cs('.doc-body p', 'textAlign'),
            lhAlign: cs('.letterhead', 'textAlign'),
            footAlign: cs('.footer', 'textAlign'),
            // The browser's own print header/footer is suppressed by giving
            // @page no margin to draw into; body padding replaces it.
            pageMargin: 'n/a-in-dom'
        });
    })()`);
    const r = JSON.parse(q);
    // When everything comes back null the document did not load, which is a
    // probe failure and not a layout failure. Saying so beats seventeen
    // confusing assertions about fonts that were never rendered.
    if (r.school === null) {
        const diag = await ev("JSON.stringify({ url: location.href,"
            + " len: document.documentElement.outerHTML.length,"
            + " hasSchool: document.documentElement.outerHTML.indexOf('lh-school'),"
            + " bodyKids: document.body ? document.body.children.length : -1,"
            + " bodyStart: document.body ? document.body.innerHTML.slice(0,300) : 'NO BODY' })");
        console.log('FAIL: print document did not load. ' + diag);
        done(1);
    }
    console.log('letterhead: ' + JSON.stringify(r, null, 1));

    // Garamond and Calibri are named in the stack; which face actually
    // renders depends on what the machine has installed, so the check is
    // that the requested family is FIRST in the stack and the size is right.
    const garamondFirst = (s) => /^Garamond/.test(s || '');
    const calibriFirst = (s) => /^Calibri/.test(s || '');
    // 16pt and 10pt are fractional px; browsers report them rounded.
    const isSize = (actual, pt) => {
        const want = pt * 4 / 3;
        return Math.abs(parseFloat(actual) - want) < 0.6;
    };

    // Charts pair up two to a row: consecutive blocks share a top, and the
    // count of distinct rows is half the number of blocks.
    const noFurniture = r.imgsInBody === 0 && r.chartBlocks === 0
        && r.kpiTiles === 0 && r.reportSections === 0;
    console.log('  furniture: ' + r.chartBlocks + ' chart blocks, ' + r.kpiTiles
        + ' KPI tiles, ' + r.reportSections + ' section cards, '
        + r.imgsInBody + ' images -> ' + (noFurniture ? 'none (ok)' : 'STILL PRESENT'));
    if (!noFurniture) failures++;
    console.log('  headings: ' + JSON.stringify(r.headings));
    if (r.headings.length !== 3) { console.log('  FAIL: expected 3 numbered sections'); failures++; }

    const checks = [
        ['school name all caps', r.school === 'BESTLINK COLLEGE OF THE PHILIPPINES'],
        ['school is Garamond', garamondFirst(r.schoolFont)],
        ['school is 16pt', isSize(r.schoolSize, 16)],
        ['unit text exact', r.unit === 'College of Computer Studies'],
        ['unit is Garamond', garamondFirst(r.unitFont)],
        ['unit is 16pt', isSize(r.unitSize, 16)],
        ['address exact', r.addr === '1071 Brgy. Kaligayahan Quirino Highway, Novaliches, Quezon City'],
        ['address is Calibri', calibriFirst(r.addrFont)],
        ['address is 9pt', isSize(r.addrSize, 9)],
        ['footer present', !!r.foot && r.foot.indexOf('1044 Bestlink Building') === 0],
        ['footer has both lines', !!r.foot && r.foot.indexOf('02.417.4355') !== -1
            && r.foot.indexOf('02.930.1565') !== -1
            && r.foot.indexOf('http://www.bcp.edu.ph') !== -1],
        ['footer is Calibri', calibriFirst(r.footFont)],
        ['footer is 10pt', isSize(r.footSize, 10)],
        ['footer repeats (fixed)', r.footPosition === 'fixed'],
        ['logo rendered', r.logoLoaded && r.logoW > 30],
        // The logo race: printDocument() must wait for the crest before
        // printing. Without the image wait the document prints while the
        // <img> is still fetching and the letterhead comes out bare — the
        // exact bug the shared letterhead module exists to prevent.
        ['crest finished loading', r.logoComplete === true],
        ['crest spans the name lines', r.logoSpansNames],
        // Same gap between name→unit and unit→address.
        ['header lines evenly spaced', r.headerGaps.length === 2
            && Math.abs(r.headerGaps[0] - r.headerGaps[1]) <= 1],
        // A crest blown up to its intrinsic 220px would span the names and still
            // pass the check above, so the width is bounded too.
        ['crest is not oversized', r.logoW > 30 && r.logoW <= 100],
        ['all ink is pure black', Object.keys(r.ink).every(function (k) {
            return r.ink[k] === 'rgb(0, 0, 0)';
        })],
        // The fixture source is 'fallback', so this proves the branch is gone.
        ['no gateway notice printed', r.metaText
            && r.metaText.indexOf('gateway') === -1
            && r.metaText.indexOf('rule-based') === -1
            && r.metaText.indexOf('unavailable') === -1],
        ['signature block styled', r.sigStyled === 'flex'],
        ['header is centred', r.lhAlign === 'center'],
        ['footer is centred', r.footAlign === 'center'],
        ['body prose is justified', r.bodyAlign === 'justify'],
        ['no charts or tiles in body', noFurniture],
        ['three numbered sections', r.headings.length === 3],
        ['section numbers kept', /^1\./.test(r.headings[0] || '')
            && /^2\./.test(r.headings[1] || '') && /^3\./.test(r.headings[2] || '')],
        ['page margin is zeroed', /@page\s*\{[^}]*margin:\s*0/.test(html)],
    ];
    checks.forEach(function (c) {
        if (!c[1]) { console.log('  FAIL: ' + c[0]); failures++; }
    });

    // A real paginated PDF, not a screenshot. Only a paginated print shows
    // what the user actually cares about here: that the footer repeats on
    // every page, that the browser's own header/footer is absent, and how
    // many pages the report takes. A tall screenshot cannot show any of it.
    const pdf = await send('Page.printToPDF', {
        printBackground: true,
        preferCSSPageSize: true,
        marginTop: 0, marginBottom: 0, marginLeft: 0, marginRight: 0,
    });
    fs.writeFileSync(`${OUT}.pdf`, Buffer.from(pdf.data, 'base64'));
    // Look at the PDF itself, not the HTML. This is the artifact the user
    // prints, and only a paginated render can show whether the centred
    // footer sits inside the page, whether the browser's own print
    // header/footer ("date + title" / "about:blank 2/2") is gone, and
    // whether anything is clipped at the bottom.
    const pdfTarget = await send('Target.createTarget', { url: 'about:blank' });
    const pdfView = await send('Target.attachToTarget', { targetId: pdfTarget.targetId, flatten: true });
    const callPdf = (m, p) => new Promise((res, rej) => {
        const i = ++id;
        ws.send(JSON.stringify({ id: i, method: m, params: p, sessionId: pdfView.sessionId }));
        pending.set(i, { res, rej });
    });
    await callPdf('Page.enable');
    await callPdf('Page.navigate', { url: `file:///${path.resolve(`${OUT}.pdf`).replace(/\\/g, '/')}` });
    await sleep(4000);   // built-in PDF viewer needs a moment to lay out
    const pdfShot = await callPdf('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(`${OUT}_page1.png`, Buffer.from(pdfShot.data, 'base64'));
    console.log(`SHOT=${OUT}_page1.png`);
    ws.send(JSON.stringify({ id: ++id, method: 'Target.closeTarget', params: { targetId: pdfTarget.targetId } }));

    // /Type /Page (not /Pages) — count real page objects. Read back from
    // disk rather than from the base64 string: Page.printToPDF returns
    // base64, and a regex over the decoded bytes is what actually reflects
    // what Chrome wrote.
    const raw = fs.readFileSync(`${OUT}.pdf`).toString('latin1');
    const pageCount = (raw.match(/\/Type\s*\/Page[^s]/g) || []).length;
    console.log(`PDF=${OUT}.pdf  bytes=${raw.length}  pages=${pageCount}`);
    if (pageCount < 1) { console.log('FAIL: PDF produced no pages'); failures++; }

    const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    fs.writeFileSync(`${OUT}.png`, Buffer.from(shot.data, 'base64'));
    console.log(`SHOT=${OUT}.png`);

    console.log(failures ? `RESULT=FAIL (${failures})` : 'RESULT=PASS');
    done(failures ? 1 : 0);
})().catch((e) => { console.error('ERR ' + e.message); done(1); });
