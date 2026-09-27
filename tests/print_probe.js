// PRINT CHECK: loads the preview, switches the iframe to print media, and
// reports the computed styles the printer would receive.
//   node tests/print_probe.js <port> <deskUrl> <cookie> <outPrefix> [w] [h]
'use strict';
const { spawn } = require('node:child_process');
const os = require('node:os'); const path = require('node:path'); const fs = require('node:fs');
const PORT = Number(process.argv[2] || 9460);
const PAGE = process.argv[3];
const COOKIE = process.argv[4] || '';
const OUT = process.argv[5] || 'print';
const W = Number(process.argv[6] || 1300), H = Number(process.argv[7] || 1000);
const CANDIDATES = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Google/Chrome/Application/chrome.exe'];
const browser = CANDIDATES.find(p => fs.existsSync(p));
if (!browser) { console.error('RESULT=NO_BROWSER'); process.exit(1); }
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dq-print-'));
const child = spawn(browser, ['--headless=new','--disable-gpu','--no-sandbox','--no-first-run',
    `--remote-debugging-port=${PORT}`,`--user-data-dir=${profile}`,`--window-size=${W},${H}`,'about:blank'], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));
function done(code){ try{child.kill();}catch(_){}; try{fs.rmSync(profile,{recursive:true,force:true});}catch(_){}; process.exit(code); }
(async () => {
    let target = null;
    for (let i=0;i<60 && !target;i++){ await sleep(200);
        try { const l = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
              target = l.find(t=>t.type==='page'); } catch(_){} }
    if (!target) { console.error('RESULT=NO_TARGET'); done(1); }
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise(r => ws.onopen = r);
    let id=0; const pending=new Map();
    const send=(m,p,sid)=>{const i=++id; ws.send(JSON.stringify({id:i,method:m,params:p,sessionId:sid}));
        return new Promise((res,rej)=>pending.set(i,{res,rej}));};
    ws.onmessage=(e)=>{const m=JSON.parse(e.data);
        if(m.id&&pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);
            m.error?p.rej(new Error(JSON.stringify(m.error))):p.res(m.result);}};
    await send('Emulation.setDeviceMetricsOverride',{width:W,height:H,deviceScaleFactor:1,mobile:false});
    await send('Page.enable'); await send('Network.enable');
    if (COOKIE){ const kv=COOKIE.split(';')[0].split('=');
        await send('Network.setCookie',{name:kv[0].trim(),value:kv[1],domain:'localhost',path:'/'}); }
    await send('Page.navigate',{url:PAGE});
    const ev = async (expr) => (await send('Runtime.evaluate',{expression:expr,returnByValue:true,awaitPromise:true})).result.value;
    for (let i=0;i<40;i++){ if (Number(await ev("document.querySelectorAll('.dq-station').length"))>0) break; await sleep(250); }
    // Open the preview the way a clerk does.
    await ev("(function(){var b=Array.from(document.querySelectorAll('button')).find(function(x){return /preview/i.test(x.textContent);}); if(b) b.click(); return 0;})()");
    await sleep(3500);
    console.log('SCREEN: ' + await ev("(function(){var f=document.querySelector('.dq-preview-frame'); if(!f) return 'NO FRAME'; var d=f.contentDocument, b=getComputedStyle(d.body), s=d.querySelector('.dt-doc'); return 'bodyBg='+b.backgroundColor+' pad='+b.padding+' shadow='+(s?getComputedStyle(s).boxShadow:'-');})()"));
    // Switch the whole page (and its frames) to print media, then re-read.
    await send('Emulation.setEmulatedMedia',{media:'print'});
    await sleep(600);
    console.log('PRINT : ' + await ev("(function(){var f=document.querySelector('.dq-preview-frame'); if(!f) return 'NO FRAME'; var d=f.contentDocument, b=getComputedStyle(d.body), s=d.querySelector('.dt-doc'); return 'bodyBg='+b.backgroundColor+' pad='+b.padding+' shadow='+(s?getComputedStyle(s).boxShadow:'-');})()"));
    const s = await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
    fs.writeFileSync(`${OUT}_printview.png`, Buffer.from(s.data,'base64'));
    console.log(`SHOT=${OUT}_printview.png`);
    done(0);
})().catch(e=>{console.error('RESULT=ERR '+e.message); done(1);});
