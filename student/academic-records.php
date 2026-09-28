<?php
// ============================================================
//  STUDENT/ACADEMIC-RECORDS.PHP
//  Student's own academic records: basic academic information,
//  per-term subject records, and grades.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';

$page_title = 'Academic Records';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_academic';
$extra_css = ['student.css'];

require_once __DIR__ . '/_guard.php';

$db = Database::getInstance();

// Per-term academic history (reflects the new subject_records schema).
$terms = $db->fetchAll(
    "SELECT * FROM academic_history
     WHERE student_id = ?
     ORDER BY COALESCE(school_year, ''), COALESCE(semester, '')",
    [$student['id']]
);

// Fetch all grades for the student's terms in one pass.
$gradeRows = [];
if ($terms) {
    $termIds = array_column($terms, 'id');
    $placeholders = implode(',', array_fill(0, count($termIds), '?'));
    $gradeRows = $db->fetchAll(
        "SELECT * FROM academic_grades WHERE academic_history_id IN ($placeholders) ORDER BY id ASC",
        $termIds
    );
}
$gradesByTerm = [];
foreach ($gradeRows as $g) {
    $gradesByTerm[$g['academic_history_id']][] = $g;
}

// Summary
$totalUnits = 0;
$totalSubjects = count($gradeRows);
$gradeValues = [];
foreach ($gradeRows as $g) {
    $totalUnits += (float)($g['units'] ?? 0);
    $gv = is_numeric($g['grade'] ?? null) ? (float)$g['grade'] : null;
    if ($gv !== null && $gv <= 3.0 && $gv >= 1.0) {
        $gradeValues[] = $gv;
    }
}
$avgGrade = count($gradeValues) ? number_format(array_sum($gradeValues) / count($gradeValues), 2) : '—';
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <header class="header">
            <div class="title"><h1>Academic Records</h1><p>Your basic academic information, subject records, and grades on file with the Registrar.</p></div>
        </header>

        <!-- Basic academic information -->
        <div class="panel" style="margin-bottom:24px;">
            <div class="panel-toolbar">
                <div class="panel-title"><i class="fa-solid fa-book-open" style="color:#2563eb;"></i> Basic Academic Information</div>
            </div>
            <div style="padding:16px 20px;">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;">
                    <div class="profile-field">
                        <div class="profile-field-icon"><i class="fa-solid fa-fingerprint"></i></div>
                        <div class="profile-field-content"><span class="label">Student ID</span><span class="value"><?= htmlspecialchars($student['student_number'] ?? '—') ?></span></div>
                    </div>
                    <div class="profile-field">
                        <div class="profile-field-icon"><i class="fa-solid fa-graduation-cap"></i></div>
                        <div class="profile-field-content"><span class="label">Program / Course</span><span class="value"><?= htmlspecialchars($student['course'] ?? '—') ?></span></div>
                    </div>
                    <div class="profile-field">
                        <div class="profile-field-icon"><i class="fa-solid fa-star"></i></div>
                        <div class="profile-field-content"><span class="label">Major / Specialization</span><span class="value"><?= htmlspecialchars($student['major'] ?? '—') ?></span></div>
                    </div>
                    <div class="profile-field">
                        <div class="profile-field-icon"><i class="fa-solid fa-layer-group"></i></div>
                        <div class="profile-field-content"><span class="label">Year Level</span><span class="value">Year <?= htmlspecialchars((string)($student['year_level'] ?? '—')) ?></span></div>
                    </div>
                    <div class="profile-field">
                        <div class="profile-field-icon"><i class="fa-solid fa-people-group"></i></div>
                        <div class="profile-field-content"><span class="label">Section</span><span class="value"><?= htmlspecialchars($student['section'] ?? '—') ?></span></div>
                    </div>
                    <div class="profile-field">
                        <div class="profile-field-icon"><i class="fa-solid fa-calendar-days"></i></div>
                        <div class="profile-field-content"><span class="label">Academic Year</span><span class="value"><?= htmlspecialchars($student['school_year'] ?? '—') ?></span></div>
                    </div>
                    <div class="profile-field">
                        <div class="profile-field-icon"><i class="fa-solid fa-book"></i></div>
                        <div class="profile-field-content"><span class="label">Semester</span><span class="value"><?= htmlspecialchars($student['semester'] ?? '—') ?></span></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($terms)): ?>
            <div class="panel">
                <div class="empty-state"><i class="fa-solid fa-book-open"></i><p>No academic history on file yet</p><span>Subject records and grades will appear here once the Registrar publishes them.</span></div>
            </div>
        <?php else: ?>

        <!-- Summary strip -->
        <div class="status-strip" style="margin-top:24px;margin-bottom:24px;">
            <div class="s-item">
                <div class="s-icon blue"><i class="fa-solid fa-layer-group"></i></div>
                <div>
                    <div class="s-value"><?= count($terms) ?></div>
                    <div class="s-label">Terms / Semesters</div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon green"><i class="fa-solid fa-book"></i></div>
                <div>
                    <div class="s-value"><?= $totalSubjects ?></div>
                    <div class="s-label">Subjects Recorded</div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon purple"><i class="fa-solid fa-calculator"></i></div>
                <div>
                    <div class="s-value"><?= htmlspecialchars($avgGrade) ?></div>
                    <div class="s-label">Average Grade</div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon yellow"><i class="fa-solid fa-scale-balanced"></i></div>
                <div>
                    <div class="s-value"><?= number_format($totalUnits, 1) ?></div>
                    <div class="s-label">Total Units</div>
                </div>
            </div>
        </div>

        <!-- Per-term subject records + grades -->
        <?php foreach ($terms as $term): ?>
        <div class="panel" style="margin-bottom:20px;">
            <div class="panel-toolbar">
                <div class="panel-title">
                    <i class="fa-solid fa-school" style="color:#2563eb;"></i>
                    <?= htmlspecialchars($term['school_name'] ?? 'School') ?>
                </div>
                <div class="panel-actions">
                    <?php if ($term['school_year']): ?><span class="chip blue"><i class="fa-solid fa-calendar-days"></i> <?= htmlspecialchars($term['school_year']) ?></span><?php endif; ?>
                    <?php if ($term['semester']): ?><span class="chip gray"><?= htmlspecialchars($term['semester']) ?></span><?php endif; ?>
                    <?php if ($term['grade_level']): ?><span class="chip gray"><i class="fa-solid fa-layer-group"></i> <?= htmlspecialchars($term['grade_level']) ?></span><?php endif; ?>
                    <?php if ($term['gwa'] !== null): ?><span class="chip green"><i class="fa-solid fa-star"></i> GWA <?= htmlspecialchars($term['gwa']) ?></span><?php endif; ?>
                </div>
            </div>

            <?php if (empty($gradesByTerm[$term['id']] ?? [])): ?>
                <div class="empty-state"><i class="fa-solid fa-book-open"></i><p>No subject grades recorded for this term</p></div>
            <?php else: ?>
                <div class="table-responsive" style="overflow-x:auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject</th>
                                <th>Units</th>
                                <th>Subject Type</th>
                                <th>Instructor</th>
                                <th>Schedule</th>
                                <th>Room</th>
                                <th>Semester Taken</th>
                                <th>Midterm</th>
                                <th>Final</th>
                                <th>Rating</th>
                                <th>Status</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gradesByTerm[$term['id']] as $g): ?>
                            <tr>
                                <td><?= htmlspecialchars($g['subject_code'] ?? '—') ?></td>
                                <td><div style="font-weight:600;color:#0f172a;"><?= htmlspecialchars($g['subject']) ?></div></td>
                                <td><?= htmlspecialchars((string)($g['units'] ?? '')) ?></td>
                                <td style="font-size:12px;"><?= htmlspecialchars($g['subject_type'] ?? '—') ?></td>
                                <td style="font-size:13px;"><?= htmlspecialchars($g['instructor'] ?? '—') ?></td>
                                <td style="font-size:12px;"><?= htmlspecialchars($g['schedule'] ?? '—') ?></td>
                                <td style="font-size:12px;"><?= htmlspecialchars($g['room'] ?? '—') ?></td>
                                <td style="font-size:12px;"><?= htmlspecialchars($g['semester_taken'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($g['midterm_grade'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($g['final_grade'] ?? '—') ?></td>
                                <td>
                                    <?php
                                    $rr = strtolower((string)($g['final_rating'] ?? ($g['grade'] ?? '')));
                                    $pill = in_array($rr, ['f', '5.0', '4.0', 'inc', 'ng', 'failed', 'dropped', 'incomplete']) ? 'inactive' : 'active';
                                    ?>
                                    <span class="pill <?= $pill ?>"><?= htmlspecialchars($g['final_rating'] ?? ($g['grade'] ?? '—')) ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($g['grade_status'])): ?>
                                        <span class="chip <?= $g['grade_status'] === 'passed' ? 'green' : 'gray' ?>"><?= htmlspecialchars(ucfirst($g['grade_status'])) ?></span>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td style="font-size:12px;color:#64748b;"><?= htmlspecialchars($g['remarks'] ?? '—') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($term['credits']) || !empty($term['subjects_completed']) || !empty($term['remarks'])): ?>
            <div style="padding:12px 20px;background:#fafcfe;border-top:1px solid #e8edf4;font-size:13px;color:#64748b;">
                <?php if (!empty($term['credits'])): ?><span style="margin-right:16px;"><i class="fa-solid fa-coins" style="color:#b45309;"></i> Credits: <strong style="color:#1e293b;"><?= htmlspecialchars($term['credits']) ?></strong></span><?php endif; ?>
                <?php if (!empty($term['subjects_completed'])): ?><span><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Subjects completed: <strong style="color:#1e293b;"><?= htmlspecialchars($term['subjects_completed']) ?></strong></span><?php endif; ?>
                <?php if (!empty($term['remarks'])): ?><div style="margin-top:6px;"><i class="fa-solid fa-note-sticky" style="color:#7c3aed;"></i> <?= nl2br(htmlspecialchars($term['remarks'])) ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>

    </div>
</main>

<?php include '../includes/footer.php'; ?>