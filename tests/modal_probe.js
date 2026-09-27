// Opens each desk modal the way a clerk does and screenshots it, so the
// copy can be read rather than assumed. Same CDP approach as shot_probe.js
// (no Playwright in this project).
//   node tests/modal_probe.js <port> <url> <cookie> <outPrefix> [w] [h]
'use strict';
const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');
const PORT = Number(process.argv[2] || 9444);
const PAGE = process.argv[3];
const COOKIE = process.argv[4] || '';
const OUT = process.argv[5] || 'modal';
const W = Number(process.argv[6] || 1300);
const H = Number(process.argv[7] || 1000);
const CANDIDATES = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
];
const browser = CANDIDATES.find((p) => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dq-modal-'));
const child = spawn(browser, ['--headless=new','--disable-gpu','--no-sandbox','--no-first-run',
    `--remote-debugging-port=${PORT}`,`--user-data-dir=${profile}`,`--window-size=${W},${H}`,'about:blank'],
    { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
function done(code){ try{child.kill();}catch(_){}; try{fs.rmSync(profile,{recursive:true,force:true});}catch(_){}; process.exit(code); }
(async () => {
    let target = null;
    for (let i=0;i<60 && !target;i++){
        await sleep(200);
        try { const l = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
              target = l.find(t=>t.type==='page'); } catch(_){}
    }
    if (!target) { console.error('RESULT=NO_TARGET'); done(1); }
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise(r => ws.onopen = r);
    let id=0; const pending=new Map();
    const send=(m,p)=>{const i=++id; ws.send(JSON.stringify({id:i,method:m,params:p}));
        return new Promise((res,rej)=>pending.set(i,{res,rej}));};
    ws.onmessage=(e)=>{const m=JSON.parse(e.data);
        if(m.id&&pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);
            m.error?p.rej(new Error(JSON.stringify(m.error))):p.res(m.result);}};
    await send('Emulation.setDeviceMetricsOverride',{width:W,height:H,deviceScaleFactor:1,mobile:false});
    await send('Page.enable'); await send('Network.enable');
    if (COOKIE){ const kv=COOKIE.split(';')[0].split('=');
        await send('Network.setCookie',{name:kv[0].trim(),value:kv[1],domain:'localhost',path:'/'}); }
    await send('Page.navigate',{url:PAGE});
    for (let i=0;i<40;i++){
        const st = await send('Runtime.evaluate',{expression:
            "document.querySelectorAll('.dq-station').length",returnByValue:true});
        if (Number(st.result.value)>0) break;
        await sleep(250);
    }
    const ev = async (expr) => (await send('Runtime.evaluate',{expression:expr,returnByValue:true,awaitPromise:true})).result.value;
    const shot = async (name) => {
        const s = await send('Page.captureScreenshot',{format:'png'});
        fs.writeFileSync(`${OUT}_${name}.png`, Buffer.from(s.data,'base64'));
        console.log(`SHOT=${OUT}_${name}.png`);
    };
    // 1. New Request: the fee ticket is the element under review.
    await ev("openModal('newRequestModal')");
    await sleep(500);
    await ev("document.getElementById('nrCatalog').selectedIndex = 1; document.getElementById('nrCatalog').dispatchEvent(new Event('change'))");
    await sleep(400);
    console.log('FEE CAP: ' + await ev("document.querySelector('.nq-fee-cap').textContent"));
    console.log('FEE NOTE: ' + await ev("document.querySelector('.nq-fee-note').textContent"));
    console.log('FEE AMOUNT: ' + await ev("document.getElementById('nrFeeAmount').textContent"));
    await shot('new');
    await ev("closeModal('newRequestModal')");
    // 2. Sign & mark ready
    await ev("openModal('approveReleaseModal')");
    await sleep(400);
    console.log('SIGN COPY: ' + await ev("(document.querySelector('#approveReleaseModal .modal-body p')||{}).textContent"));
    await shot('sign');
    await ev("closeModal('approveReleaseModal')");
    // 3. The real claim confirmation, opened by the row's Claim button.
    const clicked = await ev("(function(){var b=Array.from(document.querySelectorAll('button')).find(function(x){return /claim/i.test(x.textContent);}); if(b){b.click(); return 1;} return 0;})()");
    await sleep(600);
    if (Number(clicked)) {
        console.log('CLAIM TITLE: ' + await ev("(document.getElementById('confirmActionHeading')||{}).textContent"));
        console.log('CLAIM BODY: ' + await ev("(document.getElementById('confirmActionBody')||{}).textContent"));
        console.log('CLAIM BTN: ' + await ev("(document.getElementById('confirmActionOk')||{}).textContent"));
        await shot('claim');
    } else { console.log('CLAIM: no claim button on this row'); }
    // 4. The document preview. The claim confirmation above is still open at
    //    this point, so it must be dismissed first or it covers the sheet.
    await ev("(function(){var m=document.getElementById('confirmActionModal'); if(m) m.classList.remove('active'); document.body.style.overflow=''; return 1;})()");
    await sleep(200);
    // The preview itself, opened the way a clerk opens it.
    await ev("(function(){var b=Array.from(document.querySelectorAll('button')).find(function(x){return /preview/i.test(x.textContent);}); if(b){b.click(); return 1;} return 0;})()");
    await sleep(3000);
    console.log('PREVIEW TITLE: ' + await ev("(document.getElementById('docPreviewTitle')||{}).textContent"));
    console.log('PREVIEW FRAME: ' + await ev("(function(){var f=document.querySelector('.dq-preview-frame'); if(!f) return 'NO FRAME'; try{var d=f.contentDocument; return 'sheet='+!!(d&&d.querySelector('.dt-doc'))+' h2='+(d&&d.querySelector('h2')?d.querySelector('h2').textContent.trim():'-');}catch(e){return 'ERR '+e.message;}})()"));
    console.log('FOOTER BTNS: ' + await ev("Array.from(document.querySelectorAll('#docPreviewModal .modal-footer button')).map(function(b){return b.textContent.trim();}).join(' | ')"));
    await shot('preview');    // 5. The row Preview button: expand a row detail and report its colours.
    await ev("closeModal('docPreviewModal')");
    await sleep(300);
    await ev("(function(){var tr=document.querySelector('tr[data-doc]'); if(tr){ var d=tr.querySelector('.dq-detail'); if(d) d.classList.add('open'); tr.click(); } return 0;})()");
    await sleep(700);
    console.log('BTN BG: ' + await ev("(function(){var b=Array.from(document.querySelectorAll('.dq-detail-actions button')).find(function(x){return /preview/i.test(x.textContent);}); if(!b) return 'NO BUTTON'; var c=getComputedStyle(b); return 'bg='+c.backgroundImage.slice(0,60)+' / '+c.backgroundColor+' color='+c.color+' cls='+b.className;})()"));
    await shot('row');    // 5. Row Preview button, visually. Expand the first row and scroll to it.
    await ev("closeModal('docPreviewModal')");
    await sleep(300);
    await ev("(function(){var rows=document.querySelectorAll('tr[data-doc]'); if(!rows.length) return 'no rows'; rows[0].click(); return rows.length;})()");
    await sleep(800);
    await ev("(function(){var b=Array.from(document.querySelectorAll('.dq-detail-actions button')).find(function(x){return /preview/i.test(x.textContent);}); if(b) b.scrollIntoView({block:'center'}); return 0;})()");
    await sleep(500);
    console.log('BTN VISIBLE: ' + await ev("(function(){var b=Array.from(document.querySelectorAll('.dq-detail-actions button')).find(function(x){return /preview/i.test(x.textContent);}); if(!b) return 'NONE'; var r=b.getBoundingClientRect(); var c=getComputedStyle(b); return 'visible='+(r.width>0&&r.height>0)+' size='+Math.round(r.width)+'x'+Math.round(r.height)+' color='+c.color;})()"));
    await shot('button');    // 5. Row Preview button, visually. Drive the real View control.
    await ev("closeModal('docPreviewModal')");
    await sleep(300);
    await ev("(function(){var b=document.querySelector('.dq-view'); if(b) b.click(); return 0;})()");
    await sleep(900);
    console.log('BTN VISIBLE: ' + await ev("(function(){var b=Array.from(document.querySelectorAll('.dq-detail-actions button')).find(function(x){return /preview/i.test(x.textContent);}); if(!b) return 'NONE'; var r=b.getBoundingClientRect(); var c=getComputedStyle(b); return 'visible='+(r.width>0&&r.height>0)+' size='+Math.round(r.width)+'x'+Math.round(r.height)+' color='+c.color;})()"));
    await ev("(function(){var b=Array.from(document.querySelectorAll('.dq-detail-actions button')).find(function(x){return /preview/i.test(x.textContent);}); if(b) b.scrollIntoView({block:'center'}); return 0;})()");
    await sleep(600);
    await shot('button');    done(0);
})().catch(e=>{console.error('RESULT=ERR '+e.message); done(1);});


