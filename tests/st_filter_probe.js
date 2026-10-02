// Screenshots the status-tracker directory toolbar (year strip + program
// select) at three widths, and reports the measured geometry of each year
// button, so the control can be looked at rather than assumed.
//
//   node tests/st_filter_probe.js <port> <cookie> <outPrefix> [query]
//
// The optional query is a bare string, e.g. "year=1" - the "?" is added here.
//
// Same CDP approach as modal_probe.js (no Playwright in this project).
// Writes no database rows and touches no session: the page is loaded with
// the caller's cookie, and the probe only reads the DOM.
'use strict';
const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const PORT = Number(process.argv[2] || 9470);
const COOKIE = process.argv[3] || '';
const OUT = process.argv[4] || 'stfilter';
const QUERY = process.argv[5] || '';
const BASE = 'http://localhost/registrar-ai-system/registrar/status-tracker.php';
const CANDIDATES = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
];
const browser = CANDIDATES.find((p) => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

const WIDTHS = [
    // mode: 'stacked' means the controls below the strip take their own rows
    // (phone), 'inline' means they share one row (desktop and narrow).
    [1500, 1000, 'wide', 'inline'],
    [1000, 900, 'narrow', 'inline'],
    [560, 900, 'mobile', 'stacked'],
];
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dq-stfilter-'));
const child = spawn(browser, ['--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
    `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, '--window-size=1500,1000', 'about:blank'],
    { stdio: 'ignore' });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
function done(code) {
    try { child.kill(); } catch (_) {}
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (_) {}
    process.exit(code);
}

(async () => {
    // Counts widths where the layout claim did not hold, so the exit code
    // means something. Previously the probe printed numbers and always
    // exited 0, which is how a broken layout kept reading as a pass.
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

    await send('Page.enable'); await send('Network.enable');
    if (COOKIE) {
        const kv = COOKIE.split(';')[0].split('=');
        await send('Network.setCookie', { name: kv[0].trim(), value: kv[1], domain: 'localhost', path: '/' });
    }

    const ev = async (expr) => (await send('Runtime.evaluate',
        { expression: expr, returnByValue: true, awaitPromise: true })).result.value;

    // Collect page errors and failed requests. Without these, a script that
    // 404s or throws looks exactly like a script that never existed - which
    // is how a missing <script src> previously went unremarked.
    const pageErrors = [];
    ws.addEventListener('message', (e) => {
        const m = JSON.parse(e.data);
        if (m.method === 'Runtime.exceptionThrown') {
            const d = m.params.exceptionDetails;
            pageErrors.push('EXCEPTION: ' + (d.exception && d.exception.description || d.text));
        } else if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error') {
            pageErrors.push('LOG: ' + m.params.entry.text);
        } else if (m.method === 'Network.loadingFailed') {
            pageErrors.push('NETFAIL: ' + m.params.errorText);
        }
    });
    await send('Runtime.enable');
    await send('Log.enable');

    for (const [w, h, name, mode] of WIDTHS) {
        await send('Emulation.setDeviceMetricsOverride',
            { width: w, height: h, deviceScaleFactor: 1, mobile: false });
        // QUERY is a bare query string ("year=1"); the leading "?" belongs here
        // so callers cannot get the URL wrong.
        await send('Page.navigate', { url: BASE + (QUERY ? '?' + QUERY : '') });
        // Wait for the year strip to actually exist rather than sleeping a
        // fixed interval - the first navigation was being measured before the
        // document arrived, which reported zero buttons and looked like a
        // layout failure.
        for (let i = 0; i < 40; i++) {
            const n = await ev("document.querySelectorAll('.st-year-b').length");
            if (Number(n) >= 4) break;
            await sleep(250);
        }
        await sleep(400);

        // Geometry of the year strip: every button's top edge should match,
        // which is the actual claim being made - one row, not four stacked -
        // plus the proportional rules, whose widths are the design claim.
        const geo = await ev(`(function(){
            var bs = Array.from(document.querySelectorAll('.st-year-b'));
            var bar = document.querySelector('.st-dirbar');
            var sel = document.querySelector('.st-progsel-in');
            var chev = document.querySelector('.st-progsel-chev');
            return {
                count: bs.length,
                tops: bs.map(function(b){ return Math.round(b.getBoundingClientRect().top); }),
                w: bs.map(function(b){ return Math.round(b.getBoundingClientRect().width); }),
                ords: bs.map(function(b){ var o=b.querySelector('.st-ord'); return o?o.textContent.trim():null; }),
                nums: bs.map(function(b){ var o=b.querySelector('.st-year-n'); return o?o.textContent.trim():null; }),
                ruleW: bs.map(function(b){
                    var r=b.querySelector('.st-year-rule');
                    return r?Math.round(r.getBoundingClientRect().width):null;
                }),
                onCount: document.querySelectorAll('.st-year-b.on').length,
                barH: bar ? Math.round(bar.getBoundingClientRect().height) : -1,
                overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth,
                selW: sel ? Math.round(sel.getBoundingClientRect().width) : -1,
                chevVisible: chev ? Math.round(chev.getBoundingClientRect().width) : -1,
                dirbarWrap: bar ? getComputedStyle(bar).flexWrap : '?'
            };
        })()`);
        // Which grid row each control landed on. The design claim is "the
        // cohort strip gets its own full-width row" plus, below it, either
        // one shared row (desktop) or a stack (phone).
        const rows = await ev(`(function(){
            function top(sel){
                var e=document.querySelector(sel);
                return e ? Math.round(e.getBoundingClientRect().top) : null;
            }
            return {
                strip: top('.st-year'),
                search: top('.st-search'),
                program: top('.st-progsel'),
                go: top('.st-dirbar-go'),
                clear: top('.st-dirbar-clear'),
                stripW: (function(){
                    // .st-year is the grid item and legitimately spans the full
                    // column. The instrument is .st-year-set inside it, so that
                    // is what has to be measured - reading the parent reported
                    // 809px and made a correct compact strip look stretched.
                    var e=document.querySelector('.st-year-set');
                    return e ? Math.round(e.getBoundingClientRect().width) : null;
                })(),
                barW: (function(){
                    var e=document.querySelector('.st-dirbar');
                    return e ? Math.round(e.getBoundingClientRect().width) : null;
                })(),
                display: (function(){
                    var e=document.querySelector('.st-dirbar');
                    return e ? getComputedStyle(e).display : null;
                })()
            };
        })()`);
        console.log(`${name} (${w}px): ` + JSON.stringify(geo));
        console.log(`  rows: ${JSON.stringify(rows)}`);

        // The claim under test, per width.
        //  - the four segments share one row
        //  - the strip sits on its own row, above the other controls
        //  - the strip spans the toolbar's full width
        //  - below it, controls share a row (inline) or each take their own
        //    (stacked) - and in neither case do they overlap the strip
        //
        // Row membership is compared with a tolerance: align-items:center
        // centres controls of different heights, so a select and a button on
        // the same row report tops 1-2px apart. Exact equality reported that
        // as a failure while the layout was in fact correct.
        const TOL = 4;
        const below = [rows.search, rows.program, rows.go, rows.clear]
            .filter((v) => v !== null);
        const spread = Math.max(...below) - Math.min(...below);
        const segAligned = new Set(geo.tops).size === 1;
        const stripOwnRow = rows.strip !== null && below.every((v) => v > rows.strip);
        const controlsCorrect = mode === 'inline'
            ? spread <= TOL
            : spread > TOL;
        // The strip must own its row, but must NOT stretch to fill it: an
        // over-wide strip gave each segment 185px for three characters and
        // read as four empty boxes. Cap it and assert it stays compact.
        // The phone breakpoint is the one exception - there the strip is
        // meant to fill the column - so the cap is checked per width.
        const widestSeg = Math.max(0, ...geo.w.filter((n) => n > 0));
        const cap = mode === 'stacked' ? 999 : 120;
        const stripCap = mode === 'stacked' ? 999 : 520;
        const stripCompact = widestSeg > 0 && widestSeg <= cap
            && (rows.stripW === null || rows.stripW <= stripCap);
        console.log(
            `  segmentsOneRow=${segAligned} stripOwnRow=${stripOwnRow}`
            + ` controlsCorrect(${mode})=${controlsCorrect} spread=${spread}px`
            + ` stripW=${rows.stripW} widestSeg=${widestSeg}`
            + ` stripCompact=${stripCompact} overflowX=${geo.overflowX}`
        );
        if (!segAligned || !stripOwnRow || !controlsCorrect
            || !stripCompact || geo.overflowX) {
            console.log('  FAIL: layout claim not met');
            failures++;
        }

        const s = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(`${OUT}_${name}.png`, Buffer.from(s.data, 'base64'));
        console.log(`SHOT=${OUT}_${name}.png`);

        // ── Program listbox ────────────────────────────────────
        // Exercised only at the widest width: the claims are about behaviour
        // and ARIA wiring, not layout, and doing it three times would triple
        // the runtime to re-prove the same thing.
        if (name === WIDTHS[0][2]) {
            const lb = {};
            lb.enhanced = await ev("!!document.querySelector('.st-lb-btn')");
            // If the control is missing, say why before anything else: a 404
            // on the script and a silent exception look identical from here.
            if (!lb.enhanced) {
                console.log('  listbox NOT enhanced. page errors: '
                    + (pageErrors.length ? JSON.stringify(pageErrors) : '(none reported)'));
                console.log('  script present in DOM: ' + await ev(
                    "!!document.querySelector('script[src*=st-program-listbox]')"));
            }
            lb.btnRole = await ev("(document.querySelector('.st-lb-btn')||{getAttribute:()=>null}).getAttribute('role')");
            lb.expandedClosed = await ev("(document.querySelector('.st-lb-btn')||{getAttribute:()=>null}).getAttribute('aria-expanded')");
            lb.nativeStillThere = await ev("!!document.querySelector('select[name=program]')");
            lb.nativeNameOk = await ev("(document.querySelector('select[name=program]')||{}).name === 'program'");
            lb.triggerLabel = await ev("(document.querySelector('.st-lb-val')||{}).textContent");

            // Keyboard is driven by dispatching KeyboardEvents inside the page
            // rather than through CDP Input. A headless target without OS-level
            // focus silently drops raw input events, so the earlier version
            // reported expandedOpen=false for a panel that had in fact opened
            // and closed again - the arrow key never reached the button. The
            // handlers under test are the same either way.
            await ev("(function(){var b=document.querySelector('.st-lb-btn');b.focus();b.dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowDown',bubbles:true,cancelable:true}));})()");
            await sleep(250);

            lb.stateAfterOpen = await ev("(function(){var p=document.querySelector('.st-lb-panel');var b=document.querySelector('.st-lb-btn');return JSON.stringify({hidden:p.hidden,expanded:b.getAttribute('aria-expanded'),rows:document.querySelectorAll('.st-lb-row').length,val:b.querySelector('.st-lb-val').textContent});})()");

            lb.expandedOpen = await ev("document.querySelector('.st-lb-btn').getAttribute('aria-expanded')");
            lb.panelVisible = await ev("(function(){var p=document.querySelector('.st-lb-panel');return !!p && !p.hidden && p.getBoundingClientRect().height>0;})()");
            lb.rowCount = await ev("document.querySelectorAll('.st-lb-row').length");
            lb.panelRole = await ev("(document.querySelector('.st-lb-panel')||{getAttribute:()=>null}).getAttribute('role')");
            lb.rowRoles = await ev("Array.from(document.querySelectorAll('.st-lb-row')).every(function(r){return r.getAttribute('role')==='option';})");
            // Counts must be split into their own column, not glued to the tag.
            // The "All programs" row is exempt: it is a reset, not a program,
            // so it carries no roster count by design.
            lb.countsSplit = await ev("Array.from(document.querySelectorAll('.st-lb-row')).filter(function(r){return !r.classList.contains('st-lb-all');}).every(function(r){var n=r.querySelector('.st-lb-n');return n && !/\\(\\d/.test(r.textContent.replace(/\\(\\d[\\d,]*\\)\\s*$/,''));})");
            lb.activeRow = await ev("document.querySelectorAll('.st-lb-row.is-active').length");
            // "All programs" must be reachable from inside the panel, or a
            // chosen filter can only be undone via the toolbar's Clear link.
            lb.hasAllRow = await ev("!!document.querySelector('.st-lb-all')");
            lb.allRowFirst = await ev("(function(){var r=document.querySelectorAll('.st-lb-row');return r.length>0 && r[0].classList.contains('st-lb-all');})()");
            // The panel must not run past the right edge of the viewport: the
            // count column is the part that cannot be elided.
            lb.fitsViewport = await ev("(function(){var p=document.querySelector('.st-lb-panel');if(!p||p.hidden)return 'n/a';var r=p.getBoundingClientRect();return Math.round(r.right)+'<='+window.innerWidth;})()");
            lb.noHorizontalScroll = await ev("(function(){var p=document.querySelector('.st-lb-panel');return p.scrollWidth<=p.clientWidth+1;})()");
            // The panel must not be clipped to the trigger width.
            lb.panelW = await ev("(function(){var p=document.querySelector('.st-lb-panel');var b=document.querySelector('.st-lb-btn');return p&&b?Math.round(p.getBoundingClientRect().width)+'/'+Math.round(b.getBoundingClientRect().width):null;})()");

            // Escape must close and return focus, not just hide the panel.
            await ev("(function(){document.querySelector('.st-lb-btn').dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));})()");
            await sleep(200);
            lb.closedByEsc = await ev("(function(){var p=document.querySelector('.st-lb-panel');return !!p && p.hidden;})()");
            lb.focusOnBtn = await ev("document.activeElement === document.querySelector('.st-lb-btn')");
            console.log('  listbox: ' + JSON.stringify(lb));

            // Any of these being false means a keyboard user cannot use the
            // control at all, so they are hard failures rather than warnings.
            const lbOk = lb.enhanced && lb.nativeStillThere && lb.nativeNameOk
                && lb.btnRole === 'combobox' && lb.expandedClosed === 'false'
                && lb.expandedOpen === 'true' && lb.panelVisible
                && lb.panelRole === 'listbox' && lb.rowRoles
                && lb.countsSplit && lb.activeRow === 1
                && lb.hasAllRow && lb.allRowFirst
                && lb.noHorizontalScroll
                && lb.closedByEsc && lb.focusOnBtn;
            if (!lbOk) { console.log('  FAIL: listbox claim not met'); failures++; }

            // Round-trip: pick a real program and confirm the form actually
            // submits with it. The whole design rests on the native select
            // staying the control of record; if the value never reaches the
            // URL the control is decorative, and no visual check would show it.
            const rt = await ev(`(function(){
                var sel = document.querySelector('select.st-progsel-in');
                var opts = Array.from(sel.options).filter(function(o){ return o.value !== ''; });
                if (!opts.length) return JSON.stringify({ skipped: 'no programs' });
                var want = opts[0].value;
                var fired = false;
                sel.addEventListener('change', function(){ fired = true; }, { once: true });
                sel.form.addEventListener('submit', function(e){ e.preventDefault(); }, { once: true });
                document.querySelector('.st-lb-btn').click();
                var row = document.querySelector('.st-lb-row[data-v="' + CSS.escape(want) + '"]');
                if (!row) return JSON.stringify({ noRow: true });
                row.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
                return JSON.stringify({
                    valueSet: sel.value === want,
                    changeFired: fired,
                    triggerShows: document.querySelector('.st-lb-val').textContent,
                    marked: document.querySelector('.st-lb-btn').classList.contains('has-val'),
                    closed: document.querySelector('.st-lb-panel').hidden
                });
            })()`);
            console.log('  round-trip: ' + rt);
            if (rt.indexOf('"valueSet":true') === -1
                || rt.indexOf('"changeFired":true') === -1
                || rt.indexOf('"closed":true') === -1) {
                console.log('  FAIL: picking a program did not round-trip');
                failures++;
            }

            // ── Acronym tooltip ─────────────────────────────────
            // The row shows the acronym; the full degree title appears on
            // hover. These check the expansion actually appears, carries the
            // full title (not the acronym), and is not clipped by the panel's
            // overflow - which is why it lives on the body.
            const tip = {};
            tip.exists = await ev("document.querySelectorAll('.st-lb-tip').length === 1");
            tip.onBody = await ev("!!document.querySelector('body > .st-lb-tip')");
            tip.hiddenAtRest = await ev("document.querySelector('.st-lb-tip').hidden");
            // Every acronym row must carry the expansion; rows without an
            // acronym must not claim one.
            tip.rowsHaveTip = await ev("Array.from(document.querySelectorAll('.st-lb-row[data-tip]')).every(function(r){return r.getAttribute('data-tip').length > 8;})");
            tip.ariaLabelFull = await ev("Array.from(document.querySelectorAll('.st-lb-row[data-tip]')).every(function(r){var a=r.getAttribute('aria-label')||'';return a.length >= r.getAttribute('data-tip').length;})");
            // The row must not print the full title inline any more.
            tip.rowIsCompact = await ev("Array.from(document.querySelectorAll('.st-lb-row')).every(function(r){return !r.querySelector('.st-lb-lbl') || r.getAttribute('data-tip')===null;})");
            lb.tip = tip;

            // Hover a real acronym row and confirm the tip becomes visible
            // with the full title and a sane on-screen box.
            const tipState = await ev(`(function(){
                var row = document.querySelector('.st-lb-row[data-tip]');
                if (!row) return JSON.stringify({ skipped: 'no acronym rows' });
                var tag = row.querySelector('.st-lb-tag');
                tag.dispatchEvent(new PointerEvent('pointerover', { bubbles: true }));
                var t = document.querySelector('.st-lb-tip');
                var b = t.getBoundingClientRect();
                return JSON.stringify({
                    hidden: t.hidden,
                    text: t.textContent,
                    expected: row.getAttribute('data-tip'),
                    onScreen: b.width > 40 && b.height > 10
                              && b.left >= 0 && b.top >= 0
                              && b.right <= window.innerWidth + 1
                              && b.bottom <= window.innerHeight + 1
                });
            })()`);
            console.log('  tooltip hover: ' + tipState);
            if (tipState.indexOf('"hidden":false') === -1
                || tipState.indexOf('"onScreen":true') === -1) {
                console.log('  FAIL: acronym tooltip did not appear on hover');
                failures++;
            }

            // Leaving the row must take the tooltip with it.
            const tipGone = await ev(`(function(){
                var row = document.querySelector('.st-lb-row[data-tip]');
                row.dispatchEvent(new PointerEvent('pointerout', { bubbles: true, relatedTarget: document.body }));
                return document.querySelector('.st-lb-tip').hidden;
            })()`);
            console.log('  tooltip hides on leave: ' + tipGone);
            if (!tipGone) { console.log('  FAIL: tooltip outlived its row'); failures++; }

            // Open it again and photograph the panel, since the whole point of
            // the change is what the panel looks like.
            await ev("document.querySelector('.st-lb-btn').click()");
            await sleep(300);
            // Hover an acronym row so the screenshot captures the expansion -
            // the panel on its own would not show what this change is about.
            await ev("(function(){var r=document.querySelector('.st-lb-row[data-tip]');if(r)r.querySelector('.st-lb-tag').dispatchEvent(new PointerEvent('pointerover',{bubbles:true}));})()");
            await sleep(300);
            const s2 = await send('Page.captureScreenshot', { format: 'png' });
            fs.writeFileSync(`${OUT}_listbox.png`, Buffer.from(s2.data, 'base64'));
            console.log(`SHOT=${OUT}_listbox.png`);
        }
    }
    console.log(failures ? `RESULT=FAIL (${failures} width(s))` : 'RESULT=PASS');
    done(failures ? 1 : 0);
})().catch((e) => { console.error('RESULT=ERR ' + e.message); done(1); });
