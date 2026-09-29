<?php
// ============================================================
//  STUDENT/GRADES.PHP
//  Student's own academic history — per term, subjects, grades.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/term_grades.php';

$page_title = 'Grades &amp; Academic History';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_grades';
$extra_css = ['student.css'];

require_once __DIR__ . '/_guard.php';

$db = Database::getInstance();

$terms = $db->fetchAll(
    "SELECT * FROM academic_history
     WHERE student_id = ?
     ORDER BY COALESCE(school_year, ''), COALESCE(semester, '')",
    [$student['id']]
);

// Fetch all grades for the student's terms in one pass
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

// Summary.
//
// The GWA comes from careerGwa() in shared/term_grades.php - the same
// function the registrar's audit, the Transcript of Records and the
// document templates use. This page used to compute its own average
// inline, over the legacy `grade` column, restricted to 1.0-3.0.
//
// That was not a cosmetic difference. The registrar form writes
// `final_rating` on a 1.0-5.0 scale and leaves `grade` NULL, so the
// inline loop found no usable value at all and the student was shown an
// em dash for a term that really had a GWA of 1.77. One definition of
// GWA now governs the whole system, and this page cannot drift from
// the transcript again.
$totalUnits = 0;
foreach ($gradeRows as $g) {
    $totalUnits += (float) ($g['units'] ?? 0);
}

$careerGwa = careerGwa(array_map(
    static fn($t) => ['subjects' => $gradesByTerm[$t['id']] ?? []],
    $terms
));
$avgGrade = $careerGwa !== null ? number_format($careerGwa, 2) : '—';
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <header class="header">
            <div class="title"><h1>Grades &amp; Academic History</h1><p>Your academic record as maintained by the Registrar.</p></div>
            <?php if (!empty($terms)): ?>
            <div class="header-actions">
                <button type="button" class="btn btn-primary" id="gradesAiBtn" onclick="openGradesAi()"><i class="fa-solid fa-wand-magic-sparkles"></i> Ask AI</button>
            </div>
            <?php endif; ?>
        </header>

        <?php if (empty($terms)): ?>
            <div class="panel">
                <div class="empty-state"><i class="fa-solid fa-book-open"></i><p>No academic history on file yet</p><span>Records will appear here once the Registrar publishes your grades.</span></div>
            </div>
        <?php else: ?>

        <div class="status-strip">
            <div class="s-item">
                <div class="s-icon blue"><i class="fa-solid fa-layers"></i></div>
                <div>
                    <div class="s-value"><?= count($terms) ?></div>
                    <div class="s-label">Terms / Semesters</div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon green"><i class="fa-solid fa-book"></i></div>
                <div>
                    <div class="s-value"><?= count($gradeRows) ?></div>
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
                            <tr><th>Subject</th><th>Units</th><th>Grade</th><th>Remarks</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gradesByTerm[$term['id']] as $g): ?>
                            <tr>
                                <td><div style="font-weight:600;color:#0f172a;"><?= htmlspecialchars($g['subject']) ?></div></td>
                                <td><?= htmlspecialchars((string)$g['units']) ?></td>
                                <td>
                                    <?php
                                    // final_rating is what the registrar form writes;
                                    // `grade` is the legacy column it leaves NULL. Reading
                                    // only the legacy one showed an em dash in every
                                    // subject row, the same way the summary GWA did.
                                    // Fall back, exactly as student/academic-records.php
                                    // already did, so a row entered either way renders.
                                    $shown = $g['final_rating'] ?? ($g['grade'] ?? '');
                                    $gm = strtolower((string) $shown);
                                    $pcls = in_array($gm, ['f', '5.0', '4.0', 'inc', 'ng', 'failed', 'dropped', 'incomplete']) ? 'inactive' : 'active';
                                    ?>
                                    <span class="pill <?= $pcls ?>"><?= htmlspecialchars($shown !== '' ? $shown : '—') ?></span>
                                </td>
                                <td style="font-size:13px;color:#64748b;"><?= htmlspecialchars($g['remarks'] ?? '—') ?></td>
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

<!-- AI Academic Summary Modal -->
<div id="gradesAiModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.45);backdrop-filter:blur(3px);z-index:99999;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:18px;padding:26px 28px;max-width:560px;width:100%;max-height:88vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,0.2);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="margin:0;font-size:17px;font-weight:700;color:#0f172a;"><i class="fa-solid fa-wand-magic-sparkles" style="color:#2563eb;"></i> AI Academic Summary</h3>
            <button type="button" onclick="closeGradesAi()" style="width:34px;height:34px;border:none;background:#f1f5f9;border-radius:50%;cursor:pointer;font-size:15px;color:#94a3b8;">&times;</button>
        </div>
        <div id="gradesAiContent" style="min-height:120px;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:14px;">
            <i class="fa-solid fa-spinner fa-spin"></i> Generating your academic summary...
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var modal = document.getElementById('gradesAiModal');
    var content = document.getElementById('gradesAiContent');

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function chip(label, value, color, bg) {
        return '<span style="display:inline-block;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:600;margin:0 6px 6px 0;color:' + color + ';background:' + bg + ';">'
            + esc(label) + ': <strong>' + esc(value) + '</strong></span>';
    }

    window.openGradesAi = function () {
        if (!modal || !content) return;
        modal.style.display = 'flex';
        content.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating your academic summary...';
        fetch('../api/student-grades-ai.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'summary' })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) throw new Error(d.message || 'Failed to generate summary.');
            render(d.data || {});
        })
        .catch(function (e) {
            content.innerHTML = '<div style="color:#dc2626;font-size:13px;">' + esc(e.message) + '</div>';
        });
    };

    window.closeGradesAi = function () { if (modal) modal.style.display = 'none'; };

    function render(data) {
        var html = '';

        html += '<div style="font-size:14px;color:#1e293b;line-height:1.6;padding:14px 16px;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0;">'
             + esc(data.summary || '') + '</div>';

        var chips = '';
        if (data.computed_gwa !== null && data.computed_gwa !== undefined) {
            chips += chip('Computed GWA', data.computed_gwa, '#2563eb', '#eef4ff');
        }
        chips += chip('Terms', data.terms || 0, '#475569', '#f1f5f9');
        chips += chip('Subjects', data.subjects || 0, '#475569', '#f1f5f9');
        if (data.trend) {
            var trendMap = { improving: ['Improving', '#16a34a', '#f0fdf4'], steady: ['Steady', '#475569', '#f1f5f9'], declining: ['Needs attention', '#dc2626', '#fef2f2'] };
            var t = trendMap[data.trend] || [data.trend, '#475569', '#f1f5f9'];
            chips += chip('Trend', t[0], t[1], t[2]);
        }
        if (data.honors_hint) {
            chips += chip('Standing', data.honors_hint, '#b45309', '#fffbeb');
        }
        if (chips) {
            html += '<div style="margin-top:14px;">' + chips + '</div>';
        }

        if (data.strengths && data.strengths.length) {
            html += '<div style="margin-top:14px;"><div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#16a34a;margin-bottom:6px;"><i class="fa-solid fa-circle-check"></i> Strengths</div><div style="display:flex;flex-wrap:wrap;gap:6px;">';
            data.strengths.forEach(function (s) {
                html += '<span style="display:inline-block;padding:4px 10px;border-radius:8px;font-size:12px;font-weight:600;color:#166534;background:#f0fdf4;">' + esc(s) + '</span>';
            });
            html += '</div></div>';
        }

        if (data.attention && data.attention.length) {
            html += '<div style="margin-top:14px;"><div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#dc2626;margin-bottom:6px;"><i class="fa-solid fa-triangle-exclamation"></i> Subjects to watch</div><div style="display:flex;flex-wrap:wrap;gap:6px;">';
            data.attention.forEach(function (s) {
                html += '<span style="display:inline-block;padding:4px 10px;border-radius:8px;font-size:12px;font-weight:600;color:#991b1b;background:#fef2f2;">' + esc(s) + '</span>';
            });
            html += '</div></div>';
        }

        if (data.advice) {
            html += '<div style="margin-top:14px;font-size:13px;color:#334155;line-height:1.5;padding:12px 14px;background:#eef4ff;border-radius:10px;border:1px solid #dbeafe;"><i class="fa-solid fa-lightbulb" style="color:#2563eb;"></i> ' + esc(data.advice) + '</div>';
        }

        content.innerHTML = html || '<p style="color:#94a3b8;">No summary available.</p>';
    }

    if (modal) {
        modal.addEventListener('click', function (e) { if (e.target === modal) modal.style.display = 'none'; });
    }
})();
</script>

<?php include '../includes/footer.php'; ?>
