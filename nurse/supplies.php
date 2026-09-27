<?php
// ============================================================
//  NURSE/SUPPLIES.PHP
//  Medicine & Supply Inventory — NURSE PORTAL
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
requireRole('nurse');
require_once __DIR__ . '/../shared/database.php';

$page_title = 'Medicine & Supplies';
$APP_ROOT = '../';
$ACTIVE_NAV = 'nurse_supplies';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
.sp-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px}
@media(max-width:700px){.sp-stats{grid-template-columns:1fr}}
.sp-stat{background:#fff;border:1px solid #f1f5f9;border-radius:14px;padding:18px 20px;box-shadow:0 4px 16px rgba(15,23,42,.04)}
.sp-stat-top{display:flex;align-items:center;margin-bottom:10px}
.sp-stat-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:16px}
.sp-stat-icon.teal{background:#ccfbf1;color:#0d9488}
.sp-stat-icon.red{background:#fee2e2;color:#dc2626}
.sp-stat-icon.blue{background:#dbeafe;color:#2563eb}
.sp-stat-num{font-size:26px;font-weight:800;color:#0f172a;line-height:1}
.sp-stat-lbl{font-size:12px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px;margin-top:4px}
.sp-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px}
.sp-search{flex:1;min-width:200px;position:relative}
.sp-search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px;pointer-events:none}
.sp-search input{width:100%;padding:10px 14px 10px 40px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:14px;color:#0f172a;background:#f8fafc;outline:none;transition:border-color .15s,box-shadow .15s;box-sizing:border-box}
.sp-search input:focus{border-color:#0d9488;box-shadow:0 0 0 3px rgba(13,148,136,.12);background:#fff}
.btn-add{padding:10px 20px;border:none;border-radius:12px;background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;box-shadow:0 4px 12px rgba(13,148,136,.25)}
.btn-add:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(13,148,136,.35)}
.sp-panel{background:#fff;border:1px solid #f1f5f9;border-radius:16px;overflow:hidden;box-shadow:0 4px 16px rgba(15,23,42,.04)}
.sp-table{width:100%;border-collapse:collapse}
.sp-table th{background:#f8fafc;padding:12px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;border-bottom:2px solid #f1f5f9;white-space:nowrap}
.sp-table td{padding:12px 14px;font-size:13px;color:#334155;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.sp-table tbody tr:hover{background:#f0fdfa}
.sp-table-footer{padding:12px 18px;display:flex;align-items:center;justify-content:space-between}
.sp-info{font-size:12px;color:#94a3b8}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.badge-ok{background:#dcfce7;color:#16a34a}
.badge-low{background:#fee2e2;color:#dc2626}
.badge-warn{background:#fef3c7;color:#d97706}
.act-btn{padding:5px 10px;border:none;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .15s}
.act-btn.edit{background:#e0f2fe;color:#0369a1}.act-btn.edit:hover{background:#bae6fd}
.act-btn.dispense{background:#ccfbf1;color:#0d9488}.act-btn.dispense:hover{background:#99f6e4}
.act-btn.delete{background:#fee2e2;color:#dc2626}.act-btn.delete:hover{background:#fecaca}
.sp-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,23,42,.45);z-index:9999;align-items:center;justify-content:center;backdrop-filter:blur(3px)}
.sp-overlay.active{display:flex}
.sp-modal{background:#fff;border-radius:18px;width:100%;max-width:520px;max-height:88vh;overflow-y:auto;padding:26px 28px;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.sp-modal h3{margin:0 0 18px;font-size:18px;font-weight:700;color:#0f172a}
.sp-fg{margin-bottom:14px}
.sp-fg label{display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;text-transform:uppercase;letter-spacing:.3px}
.sp-fg input,.sp-fg select,.sp-fg textarea{width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;color:#0f172a;background:#f8fafc;outline:none;transition:border-color .15s,box-shadow .15s;box-sizing:border-box}
.sp-fg input:focus,.sp-fg select:focus,.sp-fg textarea:focus{border-color:#0d9488;box-shadow:0 0 0 3px rgba(13,148,136,.12);background:#fff}
.sp-fg textarea{resize:vertical;min-height:60px}
.sp-row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.sp-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px}
.btn-cancel{padding:10px 20px;border:1.5px solid #e2e8f0;border-radius:10px;background:#fff;color:#64748b;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s}
.btn-cancel:hover{background:#f8fafc;border-color:#cbd5e1}
.btn-save{padding:10px 22px;border:none;border-radius:10px;background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;font-size:13px;font-weight:700;cursor:pointer;transition:all .15s;box-shadow:0 4px 12px rgba(13,148,136,.25)}
.btn-save:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(13,148,136,.35)}
.btn-save:disabled{opacity:.6;cursor:not-allowed;transform:none}
.sp-toast{position:fixed;bottom:24px;right:24px;padding:14px 22px;border-radius:12px;font-size:13px;font-weight:600;color:#fff;z-index:99999;box-shadow:0 8px 24px rgba(0,0,0,.18);transition:opacity .3s;pointer-events:none}
.sp-toast.ok{background:#0d9488}.sp-toast.err{background:#dc2626}
@media(max-width:800px){.sp-table{font-size:12px}.sp-table th,.sp-table td{padding:8px 10px}}
</style>

<main class="dashboard-main">
<div class="dashboard-container">
<a href="dashboard.php" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#0d9488;margin-bottom:16px;text-decoration:none"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
<header class="header">
  <div class="title">
    <h1><i class="fas fa-pills" style="color:#0d9488"></i> Medicine &amp; Supplies</h1>
    <p>Manage clinic supply inventory</p>
  </div>
</header>
<div class="sp-stats">
  <div class="sp-stat"><div class="sp-stat-top"><div class="sp-stat-icon teal"><i class="fas fa-boxes-stacked"></i></div></div><div class="sp-stat-num" id="statTotal">0</div><div class="sp-stat-lbl">Total Items</div></div>
  <div class="sp-stat"><div class="sp-stat-top"><div class="sp-stat-icon red"><i class="fas fa-triangle-exclamation"></i></div></div><div class="sp-stat-num" id="statLow">0</div><div class="sp-stat-lbl">Low Stock</div></div>
  <div class="sp-stat"><div class="sp-stat-top"><div class="sp-stat-icon blue"><i class="fas fa-cubes"></i></div></div><div class="sp-stat-num" id="statQty">0</div><div class="sp-stat-lbl">Total Quantity</div></div>
</div>
<div class="sp-toolbar">
  <div class="sp-search"><i class="fas fa-search"></i><input type="text" id="spQ" placeholder="Search supplies by name, category…" autocomplete="off"></div>
  <button class="btn-add" onclick="addSupply()"><i class="fas fa-plus"></i> Add Supply</button>
</div>
<div class="sp-panel">
  <table class="sp-table"><thead><tr><th>Name</th><th>Category</th><th>Qty</th><th>Unit</th><th>Min Qty</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody id="spBody"><tr><td colspan="7" style="text-align:center;padding:28px;color:#94a3b8;">Loading…</td></tr></tbody></table>
  <div class="sp-table-footer"><span class="sp-info" id="spInfo">—</span></div>
</div>
</div>
</main>

<!-- Supply Modal -->
<div class="sp-overlay" id="supplyModal">
  <div class="sp-modal">
    <h3 id="supplyModalTitle">Add Supply</h3>
    <input type="hidden" id="fId">
    <div class="sp-fg"><label>Name *</label><input type="text" id="fName" placeholder="e.g. Paracetamol 500mg"></div>
    <div class="sp-row2">
      <div class="sp-fg"><label>Category</label><select id="fCategory"><option value="Medicine">Medicine</option><option value="First Aid">First Aid</option><option value="PPE">PPE</option><option value="Equipment">Equipment</option><option value="Other">Other</option></select></div>
      <div class="sp-fg"><label>Unit</label><select id="fUnit"><option value="pcs">pcs</option><option value="bottles">bottles</option><option value="strips">strips</option><option value="boxes">boxes</option><option value="packs">packs</option><option value="ml">ml</option><option value="mg">mg</option></select></div>
    </div>
    <div class="sp-row2">
      <div class="sp-fg"><label>Quantity</label><input type="number" id="fQty" min="0" value="0"></div>
      <div class="sp-fg"><label>Min Quantity</label><input type="number" id="fMinQty" min="0" value="5"></div>
    </div>
    <div class="sp-fg"><label>Description</label><textarea id="fDesc" placeholder="Optional notes…"></textarea></div>
    <div class="sp-actions">
      <button class="btn-cancel" onclick="closeSupplyModal()">Cancel</button>
      <button class="btn-save" id="supplySaveBtn" onclick="saveSupply()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<!-- Dispense Modal -->
<div class="sp-overlay" id="dispenseModal">
  <div class="sp-modal">
    <h3>Dispense: <span id="dispenseName" style="color:#0d9488"></span></h3>
    <input type="hidden" id="dId">
    <input type="hidden" id="dMaxQty">
    <div class="sp-fg"><label>Quantity to Dispense *</label><input type="number" id="dQty" min="1" value="1"></div>
    <div class="sp-fg"><label>Health Visit ID (optional)</label><input type="number" id="dVisitId" min="0" placeholder="Leave blank if not linked"></div>
    <div class="sp-fg"><label>Notes</label><textarea id="dNotes" placeholder="Optional dispensing notes…"></textarea></div>
    <div class="sp-actions">
      <button class="btn-cancel" onclick="closeDispenseModal()">Cancel</button>
      <button class="btn-save" id="dispenseSaveBtn" onclick="doDispense()"><i class="fas fa-hand-holding-medical"></i> Dispense</button>
    </div>
  </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
(function(){
    'use strict';
    var API='../api/clinic-supplies.php';
    var CSRF=document.querySelector('meta[name="csrf-token"]');
    CSRF=CSRF?CSRF.getAttribute('content'):'';
    var _rows=[];

    function $(id){return document.getElementById(id);}
    function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}

    window.loadSupplies=function(){
        var q=($('spQ').value||'').trim();
        var url=API+(q?'?q='+encodeURIComponent(q):'');
        fetch(url).then(function(r){return r.json();}).then(function(d){
            if(!d.success){$('spBody').innerHTML='<tr><td colspan="7" style="text-align:center;padding:28px;color:#dc2626;">'+esc(d.message||'Error')+'</td></tr>';return;}
            _rows=d.data||[];
            renderTable();
        }).catch(function(){
            $('spBody').innerHTML='<tr><td colspan="7" style="text-align:center;padding:28px;color:#94a3b8;">Network error.</td></tr>';
        });
    };

    function renderTable(){
        var rows=_rows, totalQty=0, lowCount=0;
        for(var i=0;i<rows.length;i++){
            var r=rows[i];
            totalQty+=parseInt(r.quantity)||0;
            if((parseInt(r.quantity)||0)<(parseInt(r.min_quantity)||0)) lowCount++;
        }
        $('statTotal').textContent=rows.length;
        $('statLow').textContent=lowCount;
        $('statQty').textContent=totalQty;
        if(rows.length===0){
            $('spBody').innerHTML='<tr><td colspan="7" style="text-align:center;padding:28px;color:#94a3b8;">No supplies found.</td></tr>';
            $('spInfo').textContent='Showing 0 items';
            return;
        }
        var html='';
        for(var i=0;i<rows.length;i++){
            var r=rows[i];
            var qty=parseInt(r.quantity)||0;
            var minQ=parseInt(r.min_quantity)||0;
            var isLow=qty<minQ;
            var badge=isLow?'<span class="badge badge-low"><i class="fas fa-circle-exclamation"></i> Low</span>':'<span class="badge badge-ok"><i class="fas fa-check-circle"></i> OK</span>';
            var rowStyle=isLow?'background:#fff5f5;':'';
            html+='<tr style="'+rowStyle+'">'
                +'<td><strong>'+esc(r.name)+'</strong>'+(r.description?'<br><span style="font-size:11px;color:#94a3b8">'+esc(r.description)+'</span>':'')+'</td>'
                +'<td><span class="badge badge-warn">'+esc(r.category||'—')+'</span></td>'
                +'<td><strong>'+qty+'</strong></td>'
                +'<td>'+esc(r.unit||'—')+'</td>'
                +'<td>'+minQ+'</td>'
                +'<td>'+badge+'</td>'
                +'<td style="white-space:nowrap">'
                +'<button class="act-btn edit" onclick="editSupply('+i+')" title="Edit"><i class="fas fa-pen"></i> Edit</button> '
                +'<button class="act-btn dispense" onclick="dispenseSupply('+r.id+',\''+esc(r.name).replace(/'/g,"\\'")+'\','+qty+')" title="Dispense"><i class="fas fa-hand-holding-medical"></i> Dispense</button> '
                +'<button class="act-btn delete" onclick="deleteSupply('+r.id+')" title="Delete"><i class="fas fa-trash"></i></button>'
                +'</td></tr>';
        }
        $('spBody').innerHTML=html;
        $('spInfo').textContent='Showing '+rows.length+' item'+(rows.length===1?'':'s');
    }

    /* ── Add ─────────────────────────────────── */
    window.addSupply=function(){
        $('supplyModalTitle').textContent='Add Supply';
        $('fId').value='';
        $('fName').value='';$('fCategory').value='Medicine';$('fUnit').value='pcs';
        $('fQty').value='0';$('fMinQty').value='5';$('fDesc').value='';
        $('supplyModal').classList.add('active');document.body.style.overflow='hidden';
        setTimeout(function(){$('fName').focus();},100);
    };

    /* ── Edit ────────────────────────────────── */
    window.editSupply=function(idx){
        var r=_rows[idx];if(!r)return;
        $('supplyModalTitle').textContent='Edit Supply';
        $('fId').value=r.id;$('fName').value=r.name||'';
        $('fCategory').value=r.category||'Medicine';$('fUnit').value=r.unit||'pcs';
        $('fQty').value=r.quantity||0;$('fMinQty').value=r.min_quantity||5;
        $('fDesc').value=r.description||'';
        $('supplyModal').classList.add('active');document.body.style.overflow='hidden';
        setTimeout(function(){$('fName').focus();},100);
    };

    window.closeSupplyModal=function(){
        $('supplyModal').classList.remove('active');document.body.style.overflow='';
    };

    /* ── Save ────────────────────────────────── */
    window.saveSupply=function(){
        var name=$('fName').value.trim();
        if(!name){toast('Name required.',false);$('fName').focus();return;}
        var btn=$('supplySaveBtn');btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';
        var payload={name:name,category:$('fCategory').value,quantity:parseInt($('fQty').value)||0,unit:$('fUnit').value,min_quantity:parseInt($('fMinQty').value)||5,description:$('fDesc').value.trim()};
        var id=$('fId').value;if(id) payload.id=parseInt(id);
        fetch(API,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify(payload)})
        .then(function(r){return r.json();}).then(function(d){
            if(d.success){toast(d.message||'Saved.',true);closeSupplyModal();loadSupplies();}
            else{toast(d.message||'Save failed.',false);}
        }).catch(function(){toast('Network error.',false);})
        .finally(function(){btn.disabled=false;btn.innerHTML='<i class="fas fa-save"></i> Save';});
    };

    /* ── Dispense ────────────────────────────── */
    window.dispenseSupply=function(id,name,qty){
        $('dId').value=id;$('dMaxQty').value=qty;
        $('dispenseName').textContent=name;$('dQty').value=1;$('dQty').max=qty;
        $('dVisitId').value='';$('dNotes').value='';
        $('dispenseModal').classList.add('active');document.body.style.overflow='hidden';
        setTimeout(function(){$('dQty').focus();},100);
    };
    window.closeDispenseModal=function(){
        $('dispenseModal').classList.remove('active');document.body.style.overflow='';
    };
    window.doDispense=function(){
        var qty=parseInt($('dQty').value)||0;
        var maxQ=parseInt($('dMaxQty').value)||0;
        if(qty<=0){toast('Quantity must be positive.',false);return;}
        if(qty>maxQ){toast('Cannot dispense more than available ('+maxQ+').',false);return;}
        var btn=$('dispenseSaveBtn');btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Dispensing…';
        var payload={supply_id:parseInt($('dId').value),quantity_used:qty,notes:$('dNotes').value.trim()};
        var vid=parseInt($('dVisitId').value)||0;
        if(vid>0) payload.health_visit_id=vid;
        fetch(API+'?action=log-usage',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify(payload)})
        .then(function(r){return r.json();}).then(function(d){
            if(d.success){toast(d.message||'Dispensed.',true);closeDispenseModal();loadSupplies();}
            else{toast(d.message||'Dispense failed.',false);}
        }).catch(function(){toast('Network error.',false);})
        .finally(function(){btn.disabled=false;btn.innerHTML='<i class="fas fa-hand-holding-medical"></i> Dispense';});
    };

    /* ── Delete ──────────────────────────────── */
    window.deleteSupply=async function(id){
        if(!await confirmAction({
            title:'Delete supply',
            body:'Delete this supply? <strong>This action cannot be undone.</strong>',
            confirmLabel:'Delete',
            tone:'danger'
        })) return;
        fetch(API+'?id='+id,{method:'DELETE',headers:{'X-CSRF-Token':CSRF}})
        .then(function(r){return r.json();}).then(function(d){
            if(d.success){toast('Deleted.',true);loadSupplies();}
            else{toast(d.message||'Delete failed.',false);}
        }).catch(function(){toast('Network error.',false);});
    };

    /* ── Toast (consistent with global CSS) ─────── */
    function toast(msg,ok){
        var c=document.querySelector('.toast-container');
        if(!c){c=document.createElement('div');c.className='toast-container';document.body.appendChild(c);}
        var t=document.createElement('div');
        t.className='toast '+(ok?'success':'error');
        t.innerHTML='<i class="fas '+(ok?'fa-circle-check':'fa-circle-xmark')+' toast-icon"></i><div class="toast-content"><div class="toast-message">'+esc(msg)+'</div></div><button class="toast-close" onclick="this.closest(\'.toast\').remove()"><i class="fas fa-times"></i></button>';
        c.appendChild(t);
        setTimeout(function(){t.classList.add('hiding');setTimeout(function(){t.remove();},300);},4000);
    }
    function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;');}

    /* ── Events ──────────────────────────────── */
    $('spQ').addEventListener('keyup',function(e){if(e.key==='Enter')loadSupplies();});
    $('supplyModal').addEventListener('click',function(e){if(e.target===this)closeSupplyModal();});
    $('dispenseModal').addEventListener('click',function(e){if(e.target===this)closeDispenseModal();});
    document.addEventListener('keydown',function(e){
        if(e.key==='Escape'){
            if($('dispenseModal').classList.contains('active')) closeDispenseModal();
            else if($('supplyModal').classList.contains('active')) closeSupplyModal();
        }
    });

    loadSupplies();
})();
</script>
