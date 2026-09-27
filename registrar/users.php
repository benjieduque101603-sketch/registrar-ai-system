<?php
// ============================================================
//  REGISTRAR/USERS.PHP
//  User Management (admin-only) — create, edit, enable/disable,
//  reset passwords. Matches the students.php gen-2 design.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('admin');
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/password_policy.php';

$passwordMinLength = passwordPolicyMinimumLength();
$passwordRequirements = passwordPolicyRequirements();

$db = Database::getInstance();

$users = $db->fetchAll("
    SELECT u.id, u.email, u.full_name, u.role, u.is_active, u.created_at, u.updated_at
    FROM users u
    WHERE u.role <> 'student'
    ORDER BY u.id ASC
");

$totalUsers    = count($users);
$activeUsers   = count(array_filter($users, fn($u) => (int)$u['is_active'] === 1));
$disabledUsers = $totalUsers - $activeUsers;
$adminUsers    = count(array_filter($users, fn($u) => $u['role'] === 'admin'));

$page_title = 'Users';
$APP_ROOT = '../';
$ACTIVE_NAV = 'users';
$body_page = 'admin-users';           // scopes the admin-blue layer
$extra_css = ['admin.css'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main">
<div class="dashboard-container">

<header class="adm-head">
    <div>
        <div class="adm-kicker"><i class="fas fa-user-shield"></i> Accounts</div>
        <h1>Users</h1>
        <p>Staff accounts that can sign in to the registrar. Students are created from the student record and managed on the Students page.</p>
    </div>
    <div class="adm-head-actions">
        <span class="adm-chip <?= $activeUsers === $totalUsers ? 'ok' : 'warn' ?>">
            <i class="fas <?= $activeUsers === $totalUsers ? 'fa-circle-check' : 'fa-user-slash' ?>"></i>
            <b><?= $activeUsers ?></b> of <?= $totalUsers ?> active
        </span>
        <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-user-plus"></i> Add user</button>
    </div>
</header>

<!-- Stats -->
<div class="adm-stats" style="--adm-cols:4" aria-label="User summary">
    <div class="adm-stat"><div class="adm-stat-icon"><i class="fas fa-users"></i></div>
        <div><div class="adm-stat-value"><?= number_format($totalUsers) ?></div><div class="adm-stat-label">Total accounts</div></div></div>
    <div class="adm-stat tone-green"><div class="adm-stat-icon"><i class="fas fa-user-check"></i></div>
        <div><div class="adm-stat-value"><?= number_format($activeUsers) ?></div><div class="adm-stat-label">Active</div></div></div>
    <div class="adm-stat tone-amber"><div class="adm-stat-icon"><i class="fas fa-user-slash"></i></div>
        <div><div class="adm-stat-value"><?= number_format($disabledUsers) ?></div><div class="adm-stat-label">Disabled</div></div></div>
    <div class="adm-stat tone-purple"><div class="adm-stat-icon"><i class="fas fa-user-shield"></i></div>
        <div><div class="adm-stat-value"><?= number_format($adminUsers) ?></div><div class="adm-stat-label">Administrators</div></div></div>
</div>

<!-- Search + Table -->
<div class="adm-panel">
<div class="adm-panel-title"><i class="fas fa-list"></i> Staff accounts</div>
<div class="adm-filters">
    <div class="search-wrap">
        <i class="fas fa-search"></i>
        <input type="text" id="userSearch" placeholder="Search name or email...">
    </div>
    <label class="adm-filters-label" for="roleFilter">Role</label>
    <select id="roleFilter" class="form-control" onchange="performSearch()">
        <option value="">All roles</option>
        <option value="admin">Admin</option>
        <option value="registrar">Registrar</option>
        <option value="nurse">Nurse</option>
            <option value="staff">Staff</option>
        </select>
    </div>

    <div class="table-responsive" style="overflow-x:auto;">
    <table class="table">
        <thead>
        <tr><th>User</th><th>Role</th><th>Status</th><th>Created</th><th style="text-align:center;">Actions</th></tr>
        </thead>
        <tbody id="userBody">
        <?php if (empty($users)): ?>
        <tr><td colspan="5" class="empty-state"><i class="fas fa-user-slash"></i><p>No users found</p><span>Add your first user to get started</span></td></tr>
        <?php else:
        $avatarColors = ['blue','green','purple','orange','pink'];
        foreach ($users as $i => $u):
            $initials = strtoupper(substr($u['full_name'],0,1));
            if (strpos($u['full_name'],' ') !== false) {
                $parts = explode(' ', trim($u['full_name']));
                $initials = strtoupper(substr($parts[0],0,1).substr(end($parts),0,1));
            }
            $ac = $avatarColors[$i % count($avatarColors)];
        ?>
        <tr data-user='<?= htmlspecialchars(json_encode($u),ENT_QUOTES,'UTF-8') ?>' class="<?= (int)$u['is_active']===0?'archived':'' ?>">
            <td><div class="student-info"><div class="student-avatar <?= $ac ?>"><?= $initials ?: '?' ?></div><div><div class="student-name"><?= htmlspecialchars($u['full_name']) ?></div><div class="student-sub"><?= htmlspecialchars($u['email']) ?></div></div></div></td>
            <td><span class="pill <?= htmlspecialchars($u['role']) ?>"><i class="fas fa-<?= $u['role']==='admin'?'user-shield':($u['role']==='student'?'user-graduate':($u['role']==='registrar'?'user-tie':'user')) ?>"></i> <?= ucfirst($u['role']) ?></span></td>

            <td><span class="pill <?= (int)$u['is_active']===1?'active':'inactive' ?>"><span class="status-dot <?= (int)$u['is_active']===1?'active':'inactive' ?>"></span><?= (int)$u['is_active']===1?'Active':'Disabled' ?></span></td>
            <td style="font-size:12px;color:#64748b;"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
            <td><div class="action-group">
                <button class="action-btn edit" onclick="editUser(<?= (int)$u['id'] ?>)" title="Edit"><i class="fas fa-pen"></i></button>
                <button class="action-btn" style="color:#7c3aed;" onclick="resetPassword(<?= (int)$u['id'] ?>,'<?= htmlspecialchars($u['full_name'],ENT_QUOTES) ?>')" title="Reset Password"><i class="fas fa-key"></i></button>
                <?php if ((int)$u['is_active']===1): ?>
                <button class="action-btn delete" onclick="toggleUser(<?= (int)$u['id'] ?>,'<?= htmlspecialchars($u['full_name'],ENT_QUOTES) ?>','disable')" title="Disable"><i class="fas fa-user-slash"></i></button>
                <?php else: ?>
                <button class="action-btn restore" onclick="toggleUser(<?= (int)$u['id'] ?>,'<?= htmlspecialchars($u['full_name'],ENT_QUOTES) ?>','enable')" title="Enable"><i class="fas fa-user-check"></i></button>
                <?php endif; ?>
            </div></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>

    <div class="adm-panel-foot">
        <div>Showing <strong id="showingCount"><?= count($users) ?></strong> of <strong id="totalCount"><?= count($users) ?></strong> accounts</div>
    </div>
</div>

</div>
</main>

<!-- Add User Modal -->
<style>
.password-field-shell{position:relative}.password-field-shell input{padding-right:82px}.password-tools{position:absolute;right:8px;top:50%;transform:translateY(-50%);display:flex;gap:2px}.password-tool{border:0;background:transparent;color:#64748b;width:30px;height:30px;border-radius:6px;cursor:pointer}.password-tool:hover,.password-tool:focus-visible{background:#eef2ff;color:#2563eb;outline:none}.password-meter{margin-top:10px;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px}.password-meter-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}.password-meter-head span:first-child{font-size:11px;font-weight:700;letter-spacing:.04em;color:#475569}.password-score{font-size:12px;font-weight:700;color:#b45309}.password-track{height:5px;background:#e2e8f0;border-radius:99px;overflow:hidden}.password-track span{display:block;width:0;height:100%;background:#b45309;transition:width .18s ease,background-color .18s ease}.password-requirements{display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;margin-top:10px}.password-requirement{display:flex;align-items:center;gap:7px;font-size:12px;color:#64748b}.password-requirement i{width:16px;height:16px;display:grid;place-items:center;border-radius:50%;background:#e2e8f0;color:#64748b;font-size:9px}.password-requirement.met{color:#166534}.password-requirement.met i{background:#dcfce7;color:#15803d}.password-strength-complete .password-score{color:#15803d}.password-strength-complete .password-track span{background:#15803d}.password-error{color:#b91c1c;font-size:12px;margin-top:8px;min-height:18px}.password-error:empty{display:none}.password-identity{font-size:12px;color:#64748b;margin-top:8px}.password-confirm{margin-top:12px}@media(max-width:600px){.form-row{grid-template-columns:1fr!important}.password-requirements{grid-template-columns:1fr}}@media(prefers-reduced-motion:reduce){.password-track span{transition:none}}
</style>
<div class="modal-overlay" id="addModal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="addModalTitle"><div class="modal-content"><div class="modal-header"><h2 id="addModalTitle"><i class="fas fa-user-plus"></i> Add User</h2><button class="modal-close" type="button" onclick="closeModal('addModal')" aria-label="Close"><i class="fas fa-times"></i></button></div>
<form id="addForm"><div class="modal-body">
    <div class="form-group"><label for="addFullName">Full Name <span style="color:#dc2626;">*</span></label><input type="text" id="addFullName" class="form-control" autocomplete="name" required></div>
    <div class="form-group"><label for="addEmail">Email <span style="color:#dc2626;">*</span></label><input type="email" id="addEmail" class="form-control" autocomplete="email" required></div>
    <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label for="addRole">Role</label><select id="addRole" class="form-control"><option value="staff">Staff</option><option value="registrar">Registrar</option><option value="nurse">Nurse</option><option value="admin">Admin</option></select></div>
        <div class="form-group"><label for="addPassword">Password <span style="color:#dc2626;">*</span></label><div class="password-field-shell"><input type="password" id="addPassword" class="form-control" autocomplete="new-password" required minlength="<?= $passwordMinLength ?>" aria-describedby="addPasswordMeter addPasswordError"><div class="password-tools"><button type="button" class="password-tool" data-password-toggle="addPassword" aria-label="Show password"><i class="fas fa-eye"></i></button><button type="button" class="password-tool" data-password-generate="addPassword" aria-label="Generate strong password" title="Generate strong password"><i class="fas fa-wand-magic-sparkles"></i></button></div></div></div>
    </div>
    <div class="password-meter" id="addPasswordMeter" data-password-meter="addPassword" data-min-length="<?= $passwordMinLength ?>"><div class="password-meter-head"><span>Password strength</span><span class="password-score" aria-live="polite">Not started</span></div><div class="password-track" aria-hidden="true"><span></span></div><div class="password-requirements">
        <?php foreach ($passwordRequirements as $key => $label): ?><div class="password-requirement" data-requirement="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-times" aria-hidden="true"></i><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span></div><?php endforeach; ?>
    </div></div>
    <div class="form-group password-confirm"><label for="addPasswordConfirm">Confirm Password <span style="color:#dc2626;">*</span></label><div class="password-field-shell"><input type="password" id="addPasswordConfirm" class="form-control" autocomplete="new-password" required minlength="<?= $passwordMinLength ?>" aria-describedby="addPasswordError"><div class="password-tools"><button type="button" class="password-tool" data-password-toggle="addPasswordConfirm" aria-label="Show confirmation"><i class="fas fa-eye"></i></button></div></div></div>
    <p class="password-identity" id="addPasswordIdentity">Use a password not used for any other account.</p><div class="password-error" id="addPasswordError" role="alert"></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Create user</button></div></form></div></div>

<!-- Edit User Modal -->
<div class="modal-overlay" id="editModal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="editModalTitle"><div class="modal-content"><div class="modal-header"><h2 id="editModalTitle"><i class="fas fa-user-pen"></i> Edit User</h2><button class="modal-close" type="button" onclick="closeModal('editModal')" aria-label="Close"><i class="fas fa-times"></i></button></div>
<form id="editForm"><input type="hidden" id="editId" value=""><div class="modal-body">
    <div class="form-group"><label>Full Name <span style="color:#dc2626;">*</span></label><input type="text" id="editFullName" class="form-control" required></div>
    <div class="form-group"><label>Email</label><input type="email" id="editEmail" class="form-control" disabled style="background:#f8fafc;font-size:12px;"></div>
    <div class="form-group"><label>Role</label><select id="editRole" class="form-control"><option value="staff">Staff</option><option value="registrar">Registrar</option><option value="nurse">Nurse</option><option value="admin">Admin</option></select></div>

</div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div></form></div></div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="passwordModal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="passwordModalTitle"><div class="modal-content"><div class="modal-header"><h2 id="passwordModalTitle"><i class="fas fa-key"></i> Reset Password</h2><button class="modal-close" type="button" onclick="closeModal('passwordModal')" aria-label="Close"><i class="fas fa-times"></i></button></div>
<form id="passwordForm"><input type="hidden" id="passwordUserId" value=""><div class="modal-body">
    <p id="passwordTarget" style="font-size:13px;color:#64748b;margin-bottom:12px;"></p>
    <div class="form-group"><label for="passwordValue">New Password <span style="color:#dc2626;">*</span></label><div class="password-field-shell"><input type="password" id="passwordValue" class="form-control" autocomplete="new-password" required minlength="<?= $passwordMinLength ?>" aria-describedby="passwordMeter passwordError"><div class="password-tools"><button type="button" class="password-tool" data-password-toggle="passwordValue" aria-label="Show password"><i class="fas fa-eye"></i></button><button type="button" class="password-tool" data-password-generate="passwordValue" aria-label="Generate strong password"><i class="fas fa-wand-magic-sparkles"></i></button></div></div></div>
    <div class="password-meter" id="passwordMeter" data-password-meter="passwordValue" data-min-length="<?= $passwordMinLength ?>"><div class="password-meter-head"><span>Password strength</span><span class="password-score" aria-live="polite">Not started</span></div><div class="password-track" aria-hidden="true"><span></span></div><div class="password-requirements"><?php foreach ($passwordRequirements as $key => $label): ?><div class="password-requirement" data-requirement="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-times" aria-hidden="true"></i><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span></div><?php endforeach; ?></div></div>
    <div class="form-group password-confirm"><label for="passwordConfirm">Confirm Password <span style="color:#dc2626;">*</span></label><div class="password-field-shell"><input type="password" id="passwordConfirm" class="form-control" autocomplete="new-password" required minlength="<?= $passwordMinLength ?>" aria-describedby="passwordError"><div class="password-tools"><button type="button" class="password-tool" data-password-toggle="passwordConfirm" aria-label="Show confirmation"><i class="fas fa-eye"></i></button></div></div></div>
    <p class="password-identity">The user must use a unique password they do not use elsewhere.</p><div class="password-error" id="passwordError" role="alert"></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" onclick="closeModal('passwordModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Set password</button></div></form></div></div>

<script>
const allUsers = [];
document.querySelectorAll('#userBody tr[data-user]').forEach(row => {
    try { const d = JSON.parse(row.dataset.user); if (d) allUsers.push({ ...d, element: row }); } catch(e) {}
});
const showingCount = document.getElementById('showingCount');

function performSearch() {
    const q = document.getElementById('userSearch').value.trim().toLowerCase();
    const role = document.getElementById('roleFilter').value;
    let visible = 0;
    allUsers.forEach(u => {
        const matchQ = !q || (u.full_name||'').toLowerCase().includes(q) || (u.email||'').toLowerCase().includes(q);
        const matchRole = !role || u.role === role;
        const show = matchQ && matchRole;
        u.element.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    showingCount.textContent = visible;
}
document.getElementById('userSearch').addEventListener('input', performSearch);

let lastFocusedElement = null;
function openModal(id) {
    lastFocusedElement = document.activeElement;
    const modal = document.getElementById(id);
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    const first = modal.querySelector('input:not([type="hidden"]), select, button, [tabindex]:not([tabindex="-1"])');
    if (first) first.focus();
}
function closeModal(id) {
    const modal = document.getElementById(id);
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') lastFocusedElement.focus();
}
['addModal','editModal','passwordModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) { if (e.target === this) closeModal(id); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { ['addModal','editModal','passwordModal'].forEach(closeModal); return; }
    if (e.key !== 'Tab') return;
    const modal = document.querySelector('.modal-overlay.active');
    if (!modal) return;
    const focusable = [...modal.querySelectorAll('input:not([type="hidden"]):not([disabled]), select, button:not([disabled]), [tabindex]:not([tabindex="-1"])')];
    if (!focusable.length) return;
    const first = focusable[0], last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
});

function updatePasswordMeter(passwordId) {
    const input = document.getElementById(passwordId);
    const meter = document.querySelector(`[data-password-meter="${passwordId}"]`);
    if (!input || !meter) return true;
    const value = input.value;
    const min = Number(meter.dataset.minLength || 8);
    const normalized = value.toLowerCase().trim();
    const compact = normalized.replace(/[^a-z0-9]/g, '');
    const identities = passwordId === 'addPassword'
        ? [document.getElementById('addFullName').value, document.getElementById('addEmail').value]
        : (meter.dataset.identity ? JSON.parse(meter.dataset.identity) : []);
    const identityTokens = identities.flatMap(value => value.toLowerCase().split(/[^a-z0-9]+/).filter(token => token.length >= 4));
    const commonBases = ['password','qwerty','admin','administrator','registrar','bestlink','welcome','letmein','iloveyou','changeme','default','secret'];
    const checks = {
        length: Array.from(value).length >= min && new TextEncoder().encode(value).length <= 72,
        uppercase: /[A-Z]/.test(value),
        lowercase: /[a-z]/.test(value),
        number: /[0-9]/.test(value),
        symbol: /[^A-Za-z0-9\s]/.test(value),
        common: !commonBases.some(base => new RegExp(`^${base}[0-9!@#$%^&*._-]*$`).test(normalized)),
        identity: !identityTokens.some(token => compact.includes(token.replace(/[^a-z0-9]/g, '')))
    };
    const met = Object.values(checks).filter(Boolean).length;
    const total = Object.keys(checks).length;
    meter.querySelectorAll('[data-requirement]').forEach(item => {
        const pass = checks[item.dataset.requirement] === true;
        const label = item.querySelector('span').textContent;
        item.classList.toggle('met', pass);
        item.setAttribute('aria-label', label + (pass ? ', met' : ', not met'));
        item.querySelector('i').className = pass ? 'fas fa-check' : 'fas fa-times';
    });
    const labels = value ? ['', 'Weak', 'Needs work', 'Good', 'Strong'] : ['Not started', '', '', '', ''];
    const score = meter.querySelector('.password-score');
    const fill = meter.querySelector('.password-track span');
    score.textContent = labels[Math.max(1, Math.min(4, Math.ceil(met / total * 4)))];
    fill.style.width = `${(met / total) * 100}%`;
    meter.classList.toggle('password-strength-complete', met === total);
    return met === total;
}

function generatePassword() {
    const randomIndex = length => {
        const limit = Math.floor(256 / length) * length;
        const bytes = crypto.getRandomValues(new Uint8Array(128));
        return bytes.find(byte => byte < limit) % length;
    };
    const sets = ['abcdefghjkmnpqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@#$%&*?-_'];
    const all = sets.join('');
    const values = Array.from({ length: 16 }, () => all[randomIndex(all.length)]);
    sets.forEach((set, index) => { if (!values.some(v => set.includes(v))) values[index] = set[randomIndex(set.length)]; });
    for (let i = values.length - 1; i > 0; i--) {
        const j = randomIndex(i + 1);
        [values[i], values[j]] = [values[j], values[i]];
    }
    return values.join('');
}

document.querySelectorAll('[data-password-toggle]').forEach(button => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        button.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
    });
});
document.querySelectorAll('[data-password-generate]').forEach(button => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordGenerate);
        input.value = generatePassword();
        const confirmation = document.getElementById(input.id + 'Confirm');
        if (confirmation) confirmation.value = input.value;
        updatePasswordMeter(input.id);
        confirmation?.focus();
    });
});
document.querySelectorAll('[data-password-meter]').forEach(meter => {
    const input = document.getElementById(meter.dataset.passwordMeter);
    input.addEventListener('input', () => updatePasswordMeter(input.id));
});

function openAddModal() {
    document.getElementById('addForm').reset();
    document.getElementById('addPasswordError').textContent = '';
    updatePasswordMeter('addPassword');
    openModal('addModal');
}

function editUser(id) {
    const u = allUsers.find(x => String(x.id) === String(id));
    if (!u) return;
    document.getElementById('editId').value = u.id;
    document.getElementById('editFullName').value = u.full_name;
    document.getElementById('editEmail').value = u.email;
    document.getElementById('editRole').value = u.role;
    openModal('editModal');
}

function resetPassword(id, name) {
    document.getElementById('passwordForm').reset();
    document.getElementById('passwordUserId').value = id;
    document.getElementById('passwordTarget').textContent = 'Set a new password for ' + name + '.';
    document.getElementById('passwordMeter').dataset.identity = JSON.stringify([name]);
    document.getElementById('passwordError').textContent = '';
    updatePasswordMeter('passwordValue');
    openModal('passwordModal');
}

async function toggleUser(id, name, action) {
    if (!await confirmAction({
        title: action === 'disable' ? 'Disable user' : 'Enable user',
        body: (action === 'disable' ? 'Disable' : 'Enable') + ' <strong>' + escText(name) + '</strong>?',
        confirmLabel: action === 'disable' ? 'Disable' : 'Enable',
        tone: action === 'disable' ? 'danger' : 'primary'
    })) return;
    fetch('../api/users.php?id=' + id + (action === 'enable' ? '&action=enable' : ''), {
        method: 'PATCH', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action })
    }).then(r => r.json()).then(d => { if (d.success) window.location.reload(); else showToast(d.message || 'Failed.', 'error'); }).catch(() => showToast('Network error.', 'error'));
}

async function submitJson(url, method, body) {
    const res = await fetch(url, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    return await res.json();
}

document.getElementById('addForm').addEventListener('input', function(e) {
    if (e.target.id === 'addFullName' || e.target.id === 'addEmail') updatePasswordMeter('addPassword');
});
document.getElementById('addForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const password = document.getElementById('addPassword').value;
    const confirmation = document.getElementById('addPasswordConfirm').value;
    const error = document.getElementById('addPasswordError');
    if (!updatePasswordMeter('addPassword')) { error.textContent = 'Complete every password requirement before continuing.'; document.getElementById('addPassword').focus(); return; }
    if (password !== confirmation) { error.textContent = 'Password confirmation does not match.'; document.getElementById('addPasswordConfirm').focus(); return; }
    error.textContent = '';
    const btn = this.querySelector('button[type="submit"]');
    const label = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
    try {
        const d = await submitJson('../api/users.php', 'POST', {
            full_name: document.getElementById('addFullName').value,
            email: document.getElementById('addEmail').value,
            role: document.getElementById('addRole').value,
            password
        });
        if (d.success) { showToast('User created successfully.', 'success'); window.location.reload(); }
        else { error.textContent = d.message || 'Failed to create user.'; showToast(d.message || 'Failed to create user.', 'error'); btn.disabled = false; btn.innerHTML = label; }
    } catch(e) { const message = 'Network error. Check your connection and try again.'; error.textContent = message; showToast(message, 'error'); btn.disabled = false; btn.innerHTML = label; }
});

document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const id = document.getElementById('editId').value;
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    try {
        const d = await submitJson('../api/users.php?id=' + id, 'PUT', {
            full_name: document.getElementById('editFullName').value,
            role: document.getElementById('editRole').value
        });
        if (d.success) { showToast('User updated.', 'success'); window.location.reload(); }
        else { showToast(d.message || 'Failed to update.', 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save'; }
    } catch(e) { showToast('Network error.', 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save'; }
});

document.getElementById('passwordForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const id = document.getElementById('passwordUserId').value;
    const password = document.getElementById('passwordValue').value;
    const confirmation = document.getElementById('passwordConfirm').value;
    const error = document.getElementById('passwordError');
    if (!updatePasswordMeter('passwordValue')) { error.textContent = 'Complete every password requirement before continuing.'; document.getElementById('passwordValue').focus(); return; }
    if (password !== confirmation) { error.textContent = 'Password confirmation does not match.'; document.getElementById('passwordConfirm').focus(); return; }
    error.textContent = '';
    const btn = this.querySelector('button[type="submit"]');
    const label = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    try {
        const d = await submitJson('../api/users.php?id=' + id, 'PATCH', { action: 'password', password });
        if (d.success) { showToast('Password updated.', 'success'); window.location.reload(); }
        else { error.textContent = d.message || 'Failed to update password.'; showToast(d.message || 'Failed to update password.', 'error'); btn.disabled = false; btn.innerHTML = label; }
    } catch(e) { const message = 'Network error. Check your connection and try again.'; error.textContent = message; showToast(message, 'error'); btn.disabled = false; btn.innerHTML = label; }
});
</script>

<?php include '../includes/footer.php'; ?>
