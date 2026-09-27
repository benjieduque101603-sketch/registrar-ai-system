let qualityData = null;
let qualitySelectedId = null;
let qualityFilter = 'all';
let qualityReturnFocus = null;

function openQualityPanel(force) {
    const modal = document.getElementById('qualityModal');
    if (!modal) return;
    if (!modal.classList.contains('active')) qualityReturnFocus = document.activeElement;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    if (force || !qualityData) loadQualityData();
    else { renderQualityQueue(); renderQualityDetail(); }
}

function closeQualityPanel() {
    document.getElementById('qualityModal')?.classList.remove('active');
    document.body.style.overflow = '';
    if (qualityReturnFocus && typeof qualityReturnFocus.focus === 'function') qualityReturnFocus.focus();
}

async function loadQualityData() {
    const list = document.getElementById('qualityList');
    if (list) list.innerHTML = '<div class="quality-empty"><i class="fas fa-spinner fa-spin"></i>Checking student records…</div>';
    try {
        const response = await aiToolsPost('quality');
        if (!response.success || !response.data) throw new Error(response.message || 'Quality check failed.');
        qualityData = response.data;
        qualitySelectedId = null;
        renderQualityQueue();
        renderQualityDetail();
    } catch (error) {
        if (list) list.innerHTML = '<div class="quality-empty"><i class="fas fa-triangle-exclamation"></i>Could not check student records.<br>Please refresh and try again.</div>';
        showToast(error.message || 'Could not check student records.', 'error');
    }
}

function filteredQualityStudents() {
    const rows = (qualityData && qualityData.students) || [];
    const query = (document.getElementById('qualitySearch')?.value || '').trim().toLowerCase();
    return rows.filter(report => {
        const student = report.student || {};
        const matchesText = !query || [student.name, student.student_number, student.course].join(' ').toLowerCase().includes(query);
        const matchesCategory = qualityFilter === 'all'
            || (qualityFilter === 'duplicate' && (report.duplicates || []).length > 0)
            || (report.issues || []).some(issue => issue.category === qualityFilter);
        return matchesText && matchesCategory;
    });
}

function renderQualityQueue() {
    const list = document.getElementById('qualityList');
    const meta = document.getElementById('qualityQueueMeta');
    const rows = filteredQualityStudents();
    if (!qualityData) return;
    if (meta) meta.textContent = `${qualityData.needs_review} of ${qualityData.total_students} records need review`;
    if (!list) return;
    if (!rows.length) {
        list.innerHTML = '<div class="quality-empty"><i class="fas fa-circle-check"></i>No records match this view.</div>';
        return;
    }
    list.innerHTML = rows.map(report => {
        const s = report.student || {};
        return `<button type="button" class="quality-student ${Number(s.id) === Number(qualitySelectedId) ? 'active' : ''}" data-quality-id="${Number(s.id)}">
            <span class="quality-student-name">${esc(s.name || 'Unnamed student')}</span>
            <span class="quality-student-num">${esc(s.student_number || 'No student #')}</span>
            <span class="quality-student-count">${(report.issues || []).length + (report.duplicates || []).length}</span>
        </button>`;
    }).join('');
}

function renderQualityDetail() {
    const detail = document.getElementById('qualityDetail');
    if (!detail) return;
    const report = ((qualityData && qualityData.students) || []).find(item => Number(item.student_id) === Number(qualitySelectedId));
    if (!report) {
        detail.innerHTML = '<div class="quality-detail-empty"><div><i class="fas fa-user-check"></i><p>Select a student to review their record.</p></div></div>';
        const openButton = document.getElementById('qualityOpenStudentBtn');
        const applyButton = document.getElementById('qualityApplyBtn');
        if (openButton) openButton.disabled = true;
        if (applyButton) applyButton.disabled = true;
        return;
    }
    const s = report.student || {};
    const repairs = report.safe_repairs || [];
    const issues = (report.issues || []).map(issue => {
        const values = `<div class="quality-value-grid"><div class="quality-value"><label>Current value</label><span>${esc(issue.current_value || 'Not provided')}</span></div>${issue.suggested_value ? `<div class="quality-value suggested"><label>Suggested value</label><span>${esc(issue.suggested_value)}</span></div>` : ''}</div>`;
        return `<article class="quality-issue ${esc(issue.severity || 'medium')} ${esc(issue.category || 'general')}"><div class="quality-issue-top"><strong>${esc(issue.label || issue.field)}</strong><span>${esc(issue.category || 'Review')}</span></div><p>${esc(issue.message || 'Needs review.')}</p>${values}</article>`;
    }).join('');
    const duplicates = (report.duplicates || []).map(item => `<div class="quality-duplicate"><strong>Possible duplicate:</strong> ${esc(item.name)} · ${esc(item.student_number || 'No student #')} · match ${Math.round(Number(item.score || 0) * 100)}%<br><span>Review both records before making a decision. This panel never merges students.</span></div>`).join('');
    const repairCards = repairs.map(repair => `<label class="quality-issue low" style="display:block;cursor:pointer"><div class="quality-issue-top"><strong>Apply safe correction</strong><span>${esc(repair.field)}</span></div><p>${esc(repair.reason || 'Verified formatting correction.')}</p><div class="quality-value-grid"><div class="quality-value"><label>Current</label><span>${esc(repair.current_value)}</span></div><div class="quality-value suggested"><label>Correction</label><span>${esc(repair.suggested_value)}</span></div></div><input type="checkbox" data-quality-repair="${esc(repair.field)}" style="margin-top:10px"></label>`).join('');
    detail.innerHTML = `<div class="quality-detail-inner">
        <div class="quality-person"><div><h2>${esc(s.name || 'Unnamed student')}</h2><p><span class="quality-student-num">${esc(s.student_number || 'No student #')}</span> · ${esc(s.course || 'No course')} · ${s.year_level ? `Year ${esc(s.year_level)}` : 'Year not set'}</p></div><div class="quality-score"><strong>${Number(report.score || 0)}</strong><span>Quality score</span></div></div>
        <div class="quality-ai"><div class="quality-ai-head"><strong><i class="fas fa-wand-magic-sparkles"></i> AI quality explanation</strong><span id="qualityAiSource">Not generated</span></div><p id="qualityAiText">Select “Explain this record” for an AI-assisted overview.</p><div class="quality-ai-actions"><button class="btn btn-secondary" id="qualityAiBtn" style="padding:6px 10px;font-size:11px" onclick="runQualityAiSummary()"><i class="fas fa-sparkles"></i> Explain this record</button></div></div>
        <div class="quality-section-title">Detected issues · ${(report.issues || []).length}</div>${issues || '<div class="quality-empty">No issues detected.</div>'}
        ${duplicates ? `<div class="quality-section-title">Duplicate review</div>${duplicates}` : ''}
        ${repairCards ? `<div class="quality-section-title">Verified safe corrections</div>${repairCards}<p id="qualityRepairHint" style="font-size:11px;color:#64748b;margin:8px 0 0"><i class="fas fa-circle-info"></i> Select one or more corrections to enable Apply selected.</p>` : ''}
        </div>`;
    const text = document.getElementById('qualityAiText');
    if (text) text.textContent = report.summary || 'Select “Explain this record” for an AI-assisted overview.';
    const openButton = document.getElementById('qualityOpenStudentBtn');
    const applyButton = document.getElementById('qualityApplyBtn');
    if (openButton) openButton.disabled = !s.id;
    if (applyButton) {
        applyButton.disabled = true;
        applyButton.innerHTML = '<i class="fas fa-check"></i> Apply selected';
    }
    updateQualityApplyButton();
}

async function runQualityAiSummary() {
    if (!qualitySelectedId) return;
    const text = document.getElementById('qualityAiText');
    const source = document.getElementById('qualityAiSource');
    const button = document.getElementById('qualityAiBtn');
    if (text) text.textContent = 'Generating a concise explanation of the detected issues…';
    if (source) source.textContent = 'Loading…';
    if (button) { button.disabled = true; button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Explaining…'; }
    try {
        const response = await aiToolsPost('quality_summary', { student_id: Number(qualitySelectedId) });
        if (!response.success) throw new Error(response.message || 'AI summary unavailable.');
        if (text) text.textContent = response.data.summary;
        if (source) source.textContent = response.data.source === 'ai' ? 'AI explanation' : 'Rule-based fallback';
    } catch (error) {
        const report = ((qualityData && qualityData.students) || []).find(item => Number(item.student_id) === Number(qualitySelectedId));
        if (text) text.textContent = report?.summary || 'AI summary is unavailable. Review the detected issues below.';
        if (source) source.textContent = 'Rule-based fallback';
    } finally {
        if (button) { button.disabled = false; button.innerHTML = '<i class="fas fa-sparkles"></i> Explain this record'; }
    }
}

function updateQualityApplyButton() {
    const button = document.getElementById('qualityApplyBtn');
    const selected = document.querySelectorAll('[data-quality-repair]:checked').length;
    const hint = document.getElementById('qualityRepairHint');
    if (button) {
        button.disabled = selected === 0;
        button.innerHTML = selected > 0
            ? `<i class="fas fa-check"></i> Apply ${selected} selected`
            : '<i class="fas fa-check"></i> Apply selected';
    }
    if (hint) {
        hint.innerHTML = selected > 0
            ? `<i class="fas fa-check-circle" style="color:#15803d"></i> ${selected} correction${selected === 1 ? '' : 's'} selected.`
            : '<i class="fas fa-circle-info"></i> Select one or more corrections to enable Apply selected.';
    }
}

async function applySelectedQualityRepairs() {
    if (!qualitySelectedId) return;
    const report = ((qualityData && qualityData.students) || []).find(item => Number(item.student_id) === Number(qualitySelectedId));
    const selected = [...document.querySelectorAll('[data-quality-repair]:checked')].map(input => input.dataset.qualityRepair);
    if (!report || !selected.length) { showToast('Select at least one verified correction.', 'warning'); return; }
    const repairs = selected.map(field => {
        const repair = (report.safe_repairs || []).find(item => item.field === field);
        return repair ? { field: repair.field, expected_value: repair.current_value, suggested_value: repair.suggested_value } : null;
    }).filter(Boolean);
    const ok = await window.confirmAction({
        title: 'Apply corrections',
        body: `Apply ${repairs.length} verified correction${repairs.length === 1 ? '' : 's'}?`,
        confirmLabel: 'Apply'
    });
    if (!ok) return;
    const button = document.getElementById('qualityApplyBtn');
    if (button) { button.disabled = true; button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Applying…'; }
    try {
        const response = await aiToolsPost('apply_safe_repairs', { student_id: Number(qualitySelectedId), repairs });
        if (!response.success) throw new Error(response.message || 'Corrections could not be applied.');
        closeQualityPanel();
        qualityData = null;
        window.location.reload();
    } catch (error) {
        showToast(error.message || 'Corrections could not be applied.', 'error');
        if (button) { button.disabled = false; button.innerHTML = '<i class="fas fa-check"></i> Apply selected'; }
    }
}

function openQualityStudent() {
    if (!qualitySelectedId) return;
    closeQualityPanel();
    window.setTimeout(() => viewStudent(qualitySelectedId), 180);
}

function initStudentDataQuality() {
    const modal = document.getElementById('qualityModal');
    if (!modal) return;
    modal.addEventListener('click', event => { if (event.target === modal) closeQualityPanel(); });
    document.getElementById('qualitySearch')?.addEventListener('input', renderQualityQueue);
    document.getElementById('qualityRefreshBtn')?.addEventListener('click', () => openQualityPanel(true));
    document.getElementById('qualityList')?.addEventListener('click', event => {
        const row = event.target.closest('[data-quality-id]');
        if (!row) return;
        qualitySelectedId = Number(row.dataset.qualityId);
        renderQualityQueue();
        renderQualityDetail();
    });
    document.getElementById('qualityDetail')?.addEventListener('change', event => {
        if (event.target.matches('[data-quality-repair]')) updateQualityApplyButton();
    });
    document.getElementById('qualityFilters')?.addEventListener('click', event => {
        const button = event.target.closest('[data-filter]');
        if (!button) return;
        qualityFilter = button.dataset.filter || 'all';
        document.querySelectorAll('#qualityFilters .quality-filter').forEach(item => item.classList.toggle('active', item === button));
        renderQualityQueue();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && modal.classList.contains('active')) closeQualityPanel();
    });
}

document.addEventListener('DOMContentLoaded', initStudentDataQuality);

