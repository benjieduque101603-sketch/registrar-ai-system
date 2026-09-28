<?php
// ============================================================
//  STUDENT/PROFILE.PHP
//  Student's own record: personal info, parents/guardians,
//  emergency contacts, and status timeline.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';

$page_title = 'My Profile';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_profile';
$extra_css = ['student.css'];

require_once __DIR__ . '/_guard.php';

$db = Database::getInstance();

$guardians = $db->fetchAll(
    "SELECT * FROM guardians WHERE student_id = ? ORDER BY is_primary DESC, id ASC",
    [$student['id']]
);
$emergency = $db->fetchAll(
    "SELECT * FROM emergency_contacts WHERE student_id = ? ORDER BY is_primary DESC, id ASC",
    [$student['id']]
);
$statusLog = $db->fetchAll(
    "SELECT st.*, u.full_name AS changed_by_name
     FROM status_tracker st
     LEFT JOIN users u ON u.id = st.changed_by
     WHERE st.student_id = ?
     ORDER BY st.created_at DESC LIMIT 6",
    [$student['id']]
);

$fullName = trim(($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '') . ' ' . ($student['last_name'] ?? '') . ($student['name_suffix'] ?? ''));
$firstLast = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
if (empty($firstLast)) $firstLast = $fullName;
$initial  = strtoupper(substr(trim($firstLast), 0, 1));
$photo    = $student['photo'] ?? '';
$photoUrl = $photo ? $APP_ROOT . ltrim($photo, './') : '';
$statusPillCls = $statusPillDot = 'active';
$statusNow = strtolower($student['status'] ?? 'active');
foreach (['enrolled','probation','at-risk','graduated','loa','transferred','dropped','inactive'] as $st) {
    if ($statusNow === $st) { $statusPillCls = $statusPillDot = $st; break; }
}
?>

<main class="dashboard-main">
    <div class="dashboard-container" style="max-width: 900px; margin: 0 auto;">

        <!-- Resume Header Section -->
        <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 40px; margin-bottom: 32px; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);">

            <!-- Header Flex: Photo + Name/Details -->
            <div style="display: flex; gap: 32px; align-items: flex-start; margin-bottom: 32px; padding-bottom: 32px; border-bottom: 2px solid #e2e8f0;">

                <!-- Photo on Left -->
                <div style="flex-shrink: 0;">
                    <div class="student-avatar profile-avatar-lg blue" style="position: relative; cursor: pointer; width: 140px; height: 140px; font-size: 56px; border: 3px solid #2563eb;" id="avatarContainer" title="Click to upload photo">
                        <?php if ($photoUrl): ?>
                            <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Student photo" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" id="avatarImage">
                        <?php else: ?>
                            <div id="avatarInitial" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;"><?= htmlspecialchars($initial) ?></div>
                        <?php endif; ?>
                        <div style="position: absolute; bottom: -6px; right: -6px; width: 44px; height: 44px; border-radius: 50%; background: linear-gradient(135deg, #10b981, #059669); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); border: 3px solid #fff; cursor: pointer;">
                            <i class="fa-solid fa-camera"></i>
                        </div>
                    </div>
                    <input type="file" id="photoInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">
                </div>

                <!-- Name + Meta -->
                <div style="flex: 1; padding-top: 4px;">
                    <h1 style="font-size: 32px; font-weight: 800; color: #0d1b2e; margin: 0 0 8px; letter-spacing: -0.5px; line-height: 1.2;"><?= htmlspecialchars($fullName) ?></h1>
                    <div style="display: flex; gap: 20px; margin-bottom: 16px; flex-wrap: wrap;">
                        <div>
                            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 3px;">Student ID</div>
                            <div style="font-size: 15px; font-weight: 700; color: #0d1b2e;"><?= htmlspecialchars($student['student_number'] ?? '—') ?></div>
                        </div>
                        <div>
                            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 3px;">Status</div>
                            <div style="font-size: 15px; font-weight: 700; color: #0d1b2e;"><?= ucfirst(htmlspecialchars($student['status'] ?? 'Active')) ?></div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                        <span style="background: linear-gradient(135deg, rgba(37, 99, 235, 0.1) 0%, rgba(59, 130, 246, 0.05) 100%); border: 1px solid #2563eb; color: #2563eb; padding: 5px 12px; border-radius: 6px; font-size: 13px; font-weight: 700;"><?= htmlspecialchars($student['course'] ?? '—') ?></span>
                        <span style="background: linear-gradient(135deg, rgba(124, 58, 237, 0.1) 0%, rgba(168, 85, 247, 0.05) 100%); border: 1px solid #7c3aed; color: #7c3aed; padding: 5px 12px; border-radius: 6px; font-size: 13px; font-weight: 700;">Year <?= htmlspecialchars((string)($student['year_level'] ?? '—')) ?></span>
                        <?php if (!empty($student['section'])): ?>
                        <span style="background: linear-gradient(135deg, rgba(6, 182, 212, 0.1) 0%, rgba(8, 145, 178, 0.05) 100%); border: 1px solid #06b6d4; color: #06b6d4; padding: 5px 12px; border-radius: 6px; font-size: 13px; font-weight: 700;">Section <?= htmlspecialchars($student['section']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Contact Information -->
            <div style="margin-bottom: 32px; padding-bottom: 32px; border-bottom: 2px solid #e2e8f0;">
                <h2 style="font-size: 13px; text-transform: uppercase; letter-spacing: 1px; color: #0d1b2e; font-weight: 800; margin: 0 0 16px;">Contact Information</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">Email</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e; word-break: break-all;"><?= htmlspecialchars($student['email'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">Phone</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($student['contact_number'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">Address</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($student['address'] ?? '—') ?></div>
                    </div>
                </div>
            </div>

            <!-- Personal Information -->
            <div style="margin-bottom: 32px; padding-bottom: 32px; border-bottom: 2px solid #e2e8f0;">
                <h2 style="font-size: 13px; text-transform: uppercase; letter-spacing: 1px; color: #0d1b2e; font-weight: 800; margin: 0 0 16px;">Personal Information</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">LRN</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($student['lrn'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">Gender</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($student['gender'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">Civil Status</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($student['civil_status'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">Birth Date</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($student['birth_date'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">Place of Birth</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($student['place_of_birth'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">Nationality</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($student['nationality'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">Religion</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($student['religion'] ?? '—') ?></div>
                    </div>
                </div>
            </div>

            <!-- Academic Information -->
            <div style="margin-bottom: 32px; padding-bottom: 32px; border-bottom: 2px solid #e2e8f0;">
                <h2 style="font-size: 13px; text-transform: uppercase; letter-spacing: 1px; color: #0d1b2e; font-weight: 800; margin: 0 0 16px;">Academic Information</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">Program / Course</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($student['course'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">Year Level</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;">Year <?= htmlspecialchars((string)($student['year_level'] ?? '—')) ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">Section</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($student['section'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">School Year</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($student['school_year'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">Semester</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($student['semester'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">Enrollment Type</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;"><?= !empty($student['is_transferee']) ? 'Transferee' : 'Regular' ?></div>
                    </div>
                </div>
            </div>

            <!-- Family Information -->
            <div style="margin-bottom: 32px; padding-bottom: 32px; border-bottom: 2px solid #e2e8f0;">
                <h2 style="font-size: 13px; text-transform: uppercase; letter-spacing: 1px; color: #0d1b2e; font-weight: 800; margin: 0 0 16px;">Family Information</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">Mother</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($student['mother_name'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 4px;">Father</div>
                        <div style="font-size: 14px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($student['father_name'] ?? '—') ?></div>
                    </div>
                </div>
            </div>

            <!-- Guardians -->
            <?php if (!empty($guardians)): ?>
            <div style="margin-bottom: 32px; padding-bottom: 32px; border-bottom: 2px solid #e2e8f0;">
                <h2 style="font-size: 13px; text-transform: uppercase; letter-spacing: 1px; color: #0d1b2e; font-weight: 800; margin: 0 0 16px;">Guardians</h2>
                <div style="display: grid; gap: 14px;">
                    <?php foreach ($guardians as $g): ?>
                    <div style="padding: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px;">
                            <div>
                                <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 3px;">Name</div>
                                <div style="font-size: 13px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($g['name'] ?? '—') ?></div>
                            </div>
                            <div>
                                <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 3px;">Relationship</div>
                                <div style="font-size: 13px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($g['relationship'] ?? '—') ?></div>
                            </div>
                            <div>
                                <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 3px;">Phone</div>
                                <div style="font-size: 13px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($g['phone'] ?? '—') ?></div>
                            </div>
                            <div>
                                <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 3px;">Status</div>
                                <div style="font-size: 13px; font-weight: 600; color: #0d1b2e;"><?= !empty($g['is_primary']) ? 'Primary' : 'Secondary' ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Emergency Contacts -->
            <?php if (!empty($emergency)): ?>
            <div style="margin-bottom: 32px; padding-bottom: 32px; border-bottom: 2px solid #e2e8f0;">
                <h2 style="font-size: 13px; text-transform: uppercase; letter-spacing: 1px; color: #0d1b2e; font-weight: 800; margin: 0 0 16px;">Emergency Contacts</h2>
                <div style="display: grid; gap: 14px;">
                    <?php foreach ($emergency as $e): ?>
                    <div style="padding: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px;">
                            <div>
                                <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 3px;">Name</div>
                                <div style="font-size: 13px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($e['name'] ?? '—') ?></div>
                            </div>
                            <div>
                                <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 3px;">Relationship</div>
                                <div style="font-size: 13px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($e['relationship'] ?? '—') ?></div>
                            </div>
                            <div>
                                <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 3px;">Phone</div>
                                <div style="font-size: 13px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($e['phone'] ?? '—') ?></div>
                            </div>
                            <?php if (!empty($e['address'])): ?>
                            <div>
                                <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 800; margin-bottom: 3px;">Address</div>
                                <div style="font-size: 13px; font-weight: 600; color: #0d1b2e;"><?= htmlspecialchars($e['address']) ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Status History -->
            <div>
                <h2 style="font-size: 13px; text-transform: uppercase; letter-spacing: 1px; color: #0d1b2e; font-weight: 800; margin: 0 0 16px;">Status History</h2>
                <?php if (empty($statusLog)): ?>
                    <div style="padding: 24px; text-align: center; color: #94a3b8;">
                        <i class="fa-solid fa-arrows-rotate" style="font-size: 24px; margin-bottom: 12px; display: block; color: #cbd5e1;"></i>
                        <p style="margin: 0; font-size: 14px; font-weight: 600;">No status changes recorded</p>
                        <span style="font-size: 12px;">Changes to your student status will appear here.</span>
                    </div>
                <?php else: ?>
                    <div style="display: grid; gap: 12px;">
                        <?php foreach ($statusLog as $s): ?>
                        <div style="padding: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;">
                                <div style="flex: 1;">
                                    <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 6px; flex-wrap: wrap;">
                                        <span style="background: linear-gradient(135deg, rgba(229, 231, 235, 1) 0%, rgba(209, 213, 219, 1) 100%); color: #4b5563; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 700;"><?= htmlspecialchars(ucfirst($s['previous_status'] ?? '—')) ?></span>
                                        <i class="fa-solid fa-arrow-right" style="color: #cbd5e1; font-size: 12px;"></i>
                                        <span style="background: linear-gradient(135deg, rgba(37, 99, 235, 0.1) 0%, rgba(59, 130, 246, 0.05) 100%); color: #2563eb; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 700;"><?= htmlspecialchars(ucfirst($s['current_status'])) ?></span>
                                    </div>
                                    <?php if (!empty($s['remarks'])): ?>
                                    <div style="font-size: 13px; color: #475569; font-weight: 500;"><?= htmlspecialchars($s['remarks']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div style="flex-shrink: 0; text-align: right; font-size: 12px; color: #94a3b8; font-weight: 600;">
                                    <div><?= date('M d, Y', strtotime($s['created_at'])) ?></div>
                                    <?php if (!empty($s['changed_by_name'])): ?>
                                    <div style="font-size: 11px; margin-top: 2px;"><?= htmlspecialchars($s['changed_by_name']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </div>
</main>

<script>
(function() {
    'use strict';

    const avatarContainer = document.getElementById('avatarContainer');
    const photoInput = document.getElementById('photoInput');
    const avatarImage = document.getElementById('avatarImage');
    const avatarInitial = document.getElementById('avatarInitial');

    if (!avatarContainer || !photoInput) return;

    function showToast(message, type = 'success') {
        var c = document.querySelector('.toast-container');
        if (!c) { c = document.createElement('div'); c.className = 'toast-container'; document.body.appendChild(c); }
        var t = document.createElement('div');
        t.className = 'toast ' + (type || 'success');
        t.innerHTML = '<i class="fas ' + (type === 'success' ? 'fa-circle-check' : 'fa-circle-xmark') + ' toast-icon"></i>' +
            '<div class="toast-content"><div class="toast-message">' + message + '</div></div>' +
            '<button class="toast-close" onclick="this.closest(\'.toast\').remove()"><i class="fas fa-times"></i></button>';
        c.appendChild(t);
        setTimeout(() => { t.classList.add('hiding'); setTimeout(() => t.remove(), 300); }, 4000);
    }

    // Open file picker on avatar click
    avatarContainer.addEventListener('click', function() {
        photoInput.click();
    });

    // Handle file selection
    photoInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;

        // Validate file size
        if (file.size > 5 * 1024 * 1024) {
            showToast('File size exceeds 5MB limit', 'error');
            return;
        }

        // Show loading state
        const originalContent = avatarContainer.innerHTML;
        avatarContainer.innerHTML = '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-spinner fa-spin" style="font-size:32px;color:#2563eb;"></i></div>';

        // Create FormData
        const formData = new FormData();
        formData.append('photo', file);

        // Upload file
        fetch('../api/student-upload-photo.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            console.log('Upload response:', data);
            if (data.success) {
                showToast('Photo updated successfully!', 'success');
                // Reload page after 500ms to show updated photo
                setTimeout(() => {
                    window.location.reload();
                }, 500);
            } else {
                console.log('Upload failed - data:', data);
                showToast('Upload failed: ' + (data.message || 'Unknown error'), 'error');
                avatarContainer.innerHTML = originalContent;
            }
        })
        .catch(err => {
            console.error('Upload error:', err);
            showToast('Upload failed: Network error', 'error');
            avatarContainer.innerHTML = originalContent;
        });

        // Reset input
        photoInput.value = '';
    });
})();
</script>

<?php include '../includes/footer.php'; ?>