// Student modal probe: does Add open, does View open, does Edit open, and
// what does the console say when each is clicked? Run against the RENDERED
// page (tests/_students_render_probe.php output, copied to index.html
// alongside this repo root) - the three symptoms are all "nothing happens",
// and nothing in a static read of the source tells a dead handler apart from
// a missing one.
//
//   node tests/student_modal_probe.js <url> [outPrefix]
'use strict';

const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const PORT = 9351;
const PAGE = process.argv[2];
const OUT = process.argv[3] || path.join(os.tmpdir(), 'student-modal');
const W = 1500, H = 1000;

const CANDIDATES = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
];
const browser = CANDIDATES.find((p) => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }

const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'sm-'));
const child = spawn(browser, [
    '--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
    // Without this, a browser extension's background page can be the target
    // picked up from /json/list, and the probe then reports every lookup on
    // the real page as "undefined" - which reads as a dead script block.
    '--disable-extensions', '--disable-extensions-except=',
    '--remote-debugging-port=' + PORT, '--user-data-dir=' + profile,
    '--window-size=' + W + ',' + H, 'about:blank',
], { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
function done(code) {
    try { child.kill(); } catch (_) {}
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (_) {}
    process.exit(code);
}

(async () => {
    const http = require('node:http');
    let wsUrl;
    for (let i = 0; i < 60; i++) {
        try {
            const list = await new Promise((res, rej) => {
                http.get('http://127.0.0.1:' + PORT + '/json/list', (r) => {
                    let b = ''; r.on('data', (d) => (b += d)); r.on('end', () => res(JSON.parse(b)));
                }).on('error', rej);
            });
            // Pick a real page target, not whichever came back first. An
            // extension background page (uBlock Origin and friends) is also a
            // CDP target, and attaching to one means every later lookup runs
            // against an empty document.
            const page = list.find((t) => t.type === 'page' && !/^chrome-extension:/.test(t.url || ''));
            if (page) { wsUrl = page.webSocketDebuggerUrl; break; }
        } catch (_) {}
        await sleep(200);
    }
    if (!wsUrl) { console.error('RESULT=NO_CDP'); done(1); }

    const sock = new (require('ws'))(wsUrl);
    let id = 0; const pending = new Map(); const errors = [];
    const send = (m, p) => {
        const i = ++id;
        sock.send(JSON.stringify({ id: i, method: m, params: p }));
        return new Promise((res, rej) => pending.set(i, { res, rej }));
    };
    await new Promise((r) => { sock.on('open', r); });
    sock.on('message', (raw) => {
        const m = JSON.parse(raw);
        if (m.id && pending.has(m.id)) {
            const p = pending.get(m.id); pending.delete(m.id);
            m.error ? p.rej(new Error(JSON.stringify(m.error))) : p.res(m.result);
            return;
        }
        if (m.method === 'Runtime.exceptionThrown') {
            const d = m.params.exceptionDetails;
            errors.push((d.exception && (d.exception.description || d.exception.value)) || d.text);
        }
        if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') {
            errors.push(m.params.args.map((a) => a.value || a.description || '').join(' '));
        }
    });

    await send('Runtime.enable');
    await send('Log.enable');
    await send('Page.enable');
    await send('Emulation.setDeviceMetricsOverride', { width: W, height: H, deviceScaleFactor: 1, mobile: false });
    // The session cookie is REQUIRED, not optional. Edit and View both open
    // from a fetch() to api/students.php, and that API answers
    // {"success":false,"message":"Unauthorized."} without one. The handlers
    // then bail on `if (!d.success) return;` and the modals never open - which
    // looks precisely like a broken button. The probe render is a static file
    // with no session of its own, so the cookie has to be supplied by hand
    // from the session file the render probe wrote.
    const cookie = process.argv[4] || '';
    if (cookie) {
        const kv = cookie.split('=');
        await send('Network.setCookie', {
            name: kv[0].trim(), value: kv.slice(1).join('='), domain: 'localhost', path: '/',
        });
        console.log('COOKIE SET: ' + kv[0].trim());
    } else {
        console.log('WARNING: no cookie arg - Edit and View will report Unauthorized.');
    }
    await send('Page.navigate', { url: PAGE });
    await sleep(2500);

    const ev = async (expr) => {
        const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
        if (r.exceptionDetails) return 'EVAL_ERR: ' + JSON.stringify(r.exceptionDetails.text || '');
        return r.result.value;
    };
    const shot = async (name) => {
        const s = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(OUT + '_' + name + '.png', Buffer.from(s.data, 'base64'));
        console.log('SHOT=' + OUT + '_' + name + '.png');
    };

    // If the page under test never loaded, every later line reports
    // "undefined" and the probe looks like it found a JavaScript bug. So the
    // document itself is identified FIRST, before anything is asked of it.
    console.log('=== PAGE ===');
    console.log('  href:   ' + await ev('location.href'));
    console.log('  title:  ' + await ev('document.title'));
    console.log('  ready:  ' + await ev('document.readyState'));
    console.log('  bodyLen:' + await ev('document.body ? document.body.innerHTML.length : -1'));
    console.log('  hasAdd: ' + await ev("!!document.getElementById('addModal')"));
    // If the stylesheets 404, every modal renders at zero height and the
    // buttons are 0x0 - which looks exactly like "the button does nothing"
    // when the real cause is that the page is unstyled. Checked explicitly so
    // the two are never confused.
    console.log('  sheets: ' + await ev("Array.from(document.styleSheets).map(function(s){return (s.href||'inline').split('/').pop();}).join(', ')"));
    console.log('  sheetErrors: ' + await ev("document.querySelectorAll('link[rel=stylesheet]').length + ' links, ' + Array.from(document.querySelectorAll('link[rel=stylesheet]')).filter(function(l){return !l.sheet;}).length + ' failed'"));
    console.log('  overlayDisplay: ' + await ev("(function(){var o=document.getElementById('addModal');var s=getComputedStyle(o);return s.display+' / pos='+s.position+' / z='+s.zIndex;})()"));
    // Which rule actually wins? getComputedStyle says display:none while the
    // element plainly has .active, so a later stylesheet is overriding
    // registrar.css. Every matching rule is dumped with its source order, and
    // the one with the winning cascade is the bug.
    console.log('  modalClass(idle): ' + await ev("document.getElementById('addModal').className"));
    // Layout is only meaningful AFTER the modal is open. Measured while it is
    // still closed, .modal-content is legitimately 0x0 - display:none gives a
    // box no size at all - and reporting that as the fault sends the hunt in
    // entirely the wrong direction.


    console.log('=== DEFINITIONS ===');
    for (const fn of ['openAddModal', 'viewStudent', 'openEditModal', 'updateAddLedger', 'escText', 'showToast']) {
        console.log('  ' + fn + ': ' + await ev('typeof ' + fn));
    }
    console.log('ROWS: ' + await ev("document.querySelectorAll('tr[data-student]').length"));
    // ── ADD ──────────────────────────────────────────────
    console.log('=== ADD ===');
    await ev('openAddModal()');
    await sleep(500);
    console.log('modal active: ' + await ev("document.getElementById('addModal').classList.contains('active')"));
    // The overlay is display:flex with .active applied, yet the card inside is
    // 0x0. That is not a cascade problem - a visible flex child always gets a
    // box. It means the element is not where the CSS thinks it is, so the
    // actual parent chain is dumped rather than guessed at.
    console.log('=== ADD DOM SHAPE ===');
    // The card is a child of <body> inside #addModal, which means some
    // container opened before #addModal is still open at that point. So the
    // chain is walked from the DOCUMENT outward, not from the modal outward -
    // walking up from #addModal only re-reports the symptom.
    console.log('=== WHO CONTAINS addModal ===');
    console.log(await ev(
        "(function(){var o=document.getElementById('addModal');var chain=[];var n=o;while(n){chain.unshift(n.tagName.toLowerCase()+(n.id?'#'+n.id:'')+(typeof n.className==='string'&&n.className?'.'+n.className.trim().split(/\\s+/).slice(0,2).join('.'):''));n=n.parentElement;}" +
        "return chain.join(' > ');})()"
    ));
    // And which container is it? Anything between the page wrapper and
    // addModal that is still open at addModal's position.
    console.log('=== SIBLINGS AFTER addModal ===');
    console.log(await ev(
        "(function(){var o=document.getElementById('addModal');var p=o.parentElement;return p.tagName.toLowerCase()+(p.id?'#'+p.id:'')+' has '+p.children.length+' children: '+Array.from(p.children).map(function(c){return (c.id||c.tagName.toLowerCase());}).join(',');})()"
    ));
    console.log('ledger text: ' + await ev("(document.getElementById('addLedgerTxt')||{}).textContent"));
    console.log('submit disabled: ' + await ev("document.getElementById('addSubmit').disabled"));
    // A button that is present and enabled can still be unclickable: covered by
    // an overlay, or off-screen because the modal body scrolled past it. Hit
    // testing is the only way to tell, which is why it is asked here.
    console.log('submit box: ' + await ev("(function(){var b=document.getElementById('addSubmit');var r=b.getBoundingClientRect();return Math.round(r.width)+'x'+Math.round(r.height)+' top='+Math.round(r.top);})()"));
    console.log('hit target: ' + await ev("(function(){var b=document.getElementById('addSubmit');var r=b.getBoundingClientRect();var e=document.elementFromPoint(r.left+r.width/2,r.top+r.height/2);return e?(e.id||e.tagName):'null';})()"));
    console.log('modal overflow: ' + await ev("(function(){var m=document.querySelector('#addModal .modal-content');if(!m)return 'no modal-content';var s=getComputedStyle(m);return s.overflow+' / body '+getComputedStyle(document.querySelector('#addModal .modal-body')||m).overflow+' / scrollH='+m.scrollHeight+' clientH='+m.clientHeight;})()"));
    await shot('add');

    // Not in CSS either. So the glyph is a genuine text node inside the h2,
    // and its siblings are printed: an <i> that renders as an icon cannot be
    // leaving a ">" behind unless the tag itself was malformed.
    console.log(await ev(
        "(function(){var h=document.querySelector('#addModal .modal-header h2');var out=[];" +
        "var w=h.childNodes;for(var i=0;i<w.length;i++){var n=w[i];" +
        "out.push(n.nodeType===3?('TEXT '+JSON.stringify(n.nodeValue)):(n.nodeType===1?('EL <'+n.tagName+'> '+JSON.stringify((n.textContent||'').slice(0,20))):('type'+n.nodeType)));}" +
        "return out.join(' | ');})()"
    ));

    // Submit an empty form: does the handler run at all, or is it dead?
    await ev("document.getElementById('addForm').requestSubmit()");
    await sleep(800);
    console.log('after submit, still open: ' + await ev("document.getElementById('addModal').classList.contains('active')"));
    await shot('add_submit');
    await ev('closeAddModal()');
    await sleep(300);

    // Row buttons are matched by their real handler name. Hunting for the word
    // "edit" in a button's text finds nothing here - the row actions are icon
    // buttons whose handlers are editStudent()/viewStudent() - and a miss
    // reads as a dead button.
    console.log('=== EDIT ===');
    console.log('edit trigger: ' + await ev("(function(){var r=document.querySelector('tr[data-student]');if(!r)return 'NO_ROW';var b=r.querySelector('button[onclick*=editStudent]');if(!b)return 'NO_EDIT_BTN';b.click();return 'clicked';})()"));
    await sleep(1200);
    console.log('edit modal active: ' + await ev("document.getElementById('editModal').classList.contains('active')"));
    console.log('edit id: ' + await ev("(document.getElementById('editId')||{}).value"));
    console.log('edit name: ' + await ev("(document.getElementById('editFirstName')||{}).value"));
    await shot('edit');
    // Submit the edit form unchanged: the Save button has to work, and it is
    // one of the two the user reported as dead.
    console.log('edit save box: ' + await ev("(function(){var f=document.getElementById('editForm');var b=f.querySelector('button[type=submit]');if(!b)return 'NO_SUBMIT';var r=b.getBoundingClientRect();return Math.round(r.width)+'x'+Math.round(r.height)+' top='+Math.round(r.top);})()"));
    await ev("document.getElementById('editForm').requestSubmit()");
    await sleep(1600);
    await shot('edit_saved');
    await ev('closeEditModal && closeEditModal()');
    await sleep(400);

    // ── VIEW ─────────────────────────────────────────────
    console.log('=== VIEW ===');
    console.log('view trigger: ' + await ev("(function(){var r=document.querySelector('tr[data-student]');if(!r)return 'NO_ROW';var b=r.querySelector('button[onclick*=viewStudent]');if(!b)return 'NO_VIEW_BTN';b.click();return 'clicked';})()"));
    await sleep(1600);
    console.log('view modal active: ' + await ev("document.getElementById('viewModal').classList.contains('active')"));
    console.log('vName: ' + await ev("(document.getElementById('vName')||{}).textContent"));
    console.log('vCourse: ' + await ev("(document.getElementById('vCourse')||{}).textContent"));
    console.log('vSection: ' + await ev("(document.getElementById('vSection')||{}).textContent"));
    console.log('view card box: ' + await ev("(function(){var m=document.querySelector('#viewModal .modal-content');var r=m.getBoundingClientRect();return Math.round(r.width)+'x'+Math.round(r.height);})()"));

    // ── API RESPONSES ─────────────────────────────────────
    // Edit and View both open from a fetch(). If the fetch is redirected to the
    // login page - which it is whenever the session is missing, exactly as it
    // is for a probe - the handler's `if (!d.success) return;` swallows it and
    // the modal never opens. The symptom is identical to a broken handler, so
    // the response is inspected directly rather than inferred.
    console.log('=== API ===');
    for (const ep of ['id=1', 'action=documents&student_id=1']) {
        const r = await send('Runtime.evaluate', {
            expression: "fetch('../api/students.php?" + ep + "').then(function(r){return r.text().then(function(t){return r.status+' '+(r.redirected?'REDIRECTED->'+r.url:'')+' len='+t.length+' '+t.slice(0,90);});})",
            returnByValue: true, awaitPromise: true,
        });
        console.log('  ' + ep + ' => ' + (r.result.value || JSON.stringify(r.exceptionDetails || {})));
    }
    await shot('view');

    console.log('=== CONSOLE ERRORS (' + errors.length + ') ===');
    errors.slice(0, 12).forEach((e) => console.log('  ! ' + String(e).split('\n')[0]));
    done(0);
})().catch((e) => { console.error('PROBE_ERROR: ' + e.message); done(1); });
