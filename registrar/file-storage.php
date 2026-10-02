<?php
// ============================================================
//  REGISTRAR/FILE-STORAGE.PHP
//  Digital File Storage — Upload / preview / download / delete
//  student documents. Per-student file view with missing-doc
//  detection, data quality report, and bulk notify.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

// Required doc types for completeness check.
// The list itself now lives in shared/stored_file.php, because the View modal
// needs the same answer and a second hand-maintained copy would be free to
// drift from this one.
require_once __DIR__ . '/../shared/stored_file.php';
$requiredTypes = requiredDocumentTypes();

// Students (non-archived) for upload dropdown
$students = $db->fetchAll(
    "SELECT id, student_number, first_name, last_name, status,
            CONCAT(first_name,' ',last_name) AS name
     FROM students WHERE status != 'archived'
     ORDER BY last_name, first_name"
);

// All documents with student info
$files = $db->fetchAll(
    "SELECT d.*, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.student_number
     FROM documents d LEFT JOIN students s ON d.student_id = s.id
     ORDER BY d.created_at DESC"
);

// Group files by student
$studentFiles = [];
foreach ($files as $f) {
    $sid = (int)$f['student_id'];
    if (!isset($studentFiles[$sid])) {
        $studentFiles[$sid] = [
            'id' => $sid, 'student_number' => $f['student_number'] ?? '',
            'student_name' => $f['student_name'] ?? '—',
            'files' => [], 'types_present' => [],
        ];
    }
    $studentFiles[$sid]['files'][] = $f;
    $studentFiles[$sid]['types_present'][] = $f['doc_type'];
}

// Per-student missing counts
$missingCount = 0;
$studentMissing = [];
foreach ($studentFiles as $sid => &$info) {
    $info['types_present'] = array_unique($info['types_present']);
    $missing = array_diff($requiredTypes, $info['types_present']);
    $info['missing_types'] = $missing;
    $info['missing_count'] = count($missing);
    $studentMissing[$sid] = $info['missing_count'];
    if ($info['missing_count'] > 0) $missingCount++;
}
unset($info);

$totalFiles  = count($files);
$sumBytes    = (int) $db->fetchColumn("SELECT COALESCE(SUM(file_size),0) FROM documents");

// ── Is each file actually on this server? ─────────────────────────
//
// Resolved here, up front, rather than inside the row markup, for two reasons:
// the banner at the top needs the total, and a page that discovers it is
// rendering 404s while it renders is too late to say anything useful about them.
//
// See shared/stored_file.php for the rule and why it checks the disk at all.
require_once __DIR__ . '/../shared/stored_file.php';

$fileUrlById  = [];   // id => loadable URL, or '' when the file is not here
$fileGoneById = [];   // id => true
foreach ($files as $f) {
    // F1: point at the authorising endpoint, not the raw uploads/ path.
    //
    // uploads/student_files/ and uploads/document_requirements/ are now
    // `Require all denied`, so a direct ../uploads/... link returns 403.
    // api/file-download.php checks the session, the role and ownership,
    // then streams the file with Content-Disposition: attachment.
    //
    // storedFileUrl() is still used to decide whether the file EXISTS on
    // this host (it checks the disk) — only the URL handed to the browser
    // changes.
    $onDisk = storedFileUrl($f['file_path'], '../');
    $id     = (int) $f['id'];
    if ($onDisk === '') {
        $fileUrlById[$id] = '';
        $fileGoneById[$id] = true;
    } else {
        $fileUrlById[$id] = '../api/file-download.php?id=' . $id;
    }
}
$missingFileCount = count($fileGoneById);

function fmtBytes($b) {
    if ($b >= 1073741824) return round($b / 1073741824, 1) . ' GB';
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024) return round($b / 1024, 1) . ' KB';
    return $b . ' B';
}
$isImage = function ($t) { return in_array(strtolower($t), ['jpg','jpeg','png','webp','gif']); };
$iconMap = ['pdf'=>'fa-file-pdf','doc'=>'fa-file-word','docx'=>'fa-file-word','xls'=>'fa-file-excel','xlsx'=>'fa-file-excel','txt'=>'fa-file-lines','zip'=>'fa-file-zipper','rar'=>'fa-file-zipper','odt'=>'fa-file-word','ods'=>'fa-file-excel','jpg'=>'fa-file-image','jpeg'=>'fa-file-image','png'=>'fa-file-image','webp'=>'fa-file-image'];
$colorMap = ['pdf'=>'#dc2626','doc'=>'#2563eb','docx'=>'#2563eb','xls'=>'#16a34a','xlsx'=>'#16a34a','txt'=>'#64748b','zip'=>'#7c3aed','rar'=>'#7c3aed','odt'=>'#2563eb','ods'=>'#16a34a','jpg'=>'#db2777','jpeg'=>'#db2777','png'=>'#db2777','webp'=>'#db2777'];

// Sort: missing-docs first, then alphabetical
$sortedStudents = $studentFiles;
usort($sortedStudents, function ($a, $b) use ($studentMissing) {
    $am = $studentMissing[$a['id']] ?? 0;
    $bm = $studentMissing[$b['id']] ?? 0;
    if ($am !== $bm) return $bm - $am;
    return strcasecmp($a['student_name'], $b['student_name']);
});

$page_title = 'File Storage';
$APP_ROOT   = '../';
$ACTIVE_NAV = 'filestorage';
include '../includes/header.php';
include '../includes/sidebar.php';


?>
<style>
.storage-header{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:18px;padding:25px 27px;border:1px solid #c7d7fe;border-radius:19px;background:linear-gradient(120deg,#eff6ff,#fff 68%);box-shadow:0 10px 30px rgba(37,99,235,.08)}
.storage-kicker{display:flex;align-items:center;gap:7px;margin-bottom:7px;color:#1d4ed8;font-size:10.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.storage-header h1{margin:0 0 5px;font-size:28px;line-height:1.1;letter-spacing:-.03em;color:#172554}.storage-header p{max-width:660px;margin:0;font-size:12.5px;line-height:1.5;color:#64748b}.storage-header .header-actions{display:flex;align-items:center;gap:9px}.storage-header .btn{min-height:38px}
.storage-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0;margin:0 0 18px;overflow:hidden;border:1px solid #bfdbfe;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.05)}
.storage-stat{display:flex;align-items:center;gap:14px;min-width:0;padding:22px;border-right:1px solid #e2e8f0;background:#fff}.storage-stat:last-child{border-right:0}/* Accent system.
   Each card carries ONE --accent token; the icon chip and the bottom
   rule both read from it, so they can never drift apart. The old rules
   hardcoded the same blue on .blue/.teal/.red, which silently overrode
   the teal and red hooks to identical blue and flattened the strip. */
.storage-stat{--accent:#1d4ed8;--accent-soft:#dbeafe;--accent-bar:linear-gradient(90deg,#2563eb,#60a5fa)}
.storage-stat:nth-child(1){--accent:#1d4ed8;--accent-soft:#dbeafe;--accent-bar:linear-gradient(90deg,#2563eb,#60a5fa)}
.storage-stat:nth-child(2){--accent:#0f766e;--accent-soft:#ccfbf1;--accent-bar:linear-gradient(90deg,#0d9488,#5eead4)}
.storage-stat:nth-child(3){--accent:#b91c1c;--accent-soft:#fee2e2;--accent-bar:linear-gradient(90deg,#dc2626,#fca5a5)}
.storage-stat-icon{width:40px;height:40px;display:grid;place-items:center;flex:0 0 auto;border-radius:11px;font-size:17px;background:var(--accent-soft)!important;color:var(--accent)!important}.storage-stat strong{display:block;font-size:28px;line-height:1;font-weight:800;color:#172554}.storage-stat span{display:block;margin-top:8px;color:#64748b;font-size:11px;font-weight:750;letter-spacing:.04em;text-transform:uppercase}
@media(max-width:900px){.storage-header{align-items:flex-start;flex-direction:column}.storage-header .header-actions{width:100%;justify-content:flex-end}}

/* Match the Status Tracker KPI strip: unified surface with bottom accent bars. */
.storage-stats{position:relative;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:0!important;margin:0 0 20px!important;overflow:hidden!important;border:1px solid #dce7e6!important;border-radius:18px!important;background:#fff!important;box-shadow:0 8px 28px rgba(15,23,42,.045)!important}
.storage-stat{position:relative!important;display:flex!important;align-items:flex-start!important;gap:14px!important;min-width:0!important;padding:20px 22px!important;border:0!important;border-right:1px solid #e5eceb!important;border-radius:0!important;background:#fff!important;box-shadow:none!important}
.storage-stat:last-child{border-right:0!important}
.storage-stat::after{content:"";position:absolute;left:22px;right:22px;bottom:0;height:3px;background:var(--accent-bar)}
.storage-stat-icon{width:42px!important;height:42px!important;display:flex!important;align-items:center!important;justify-content:center!important;flex:0 0 auto!important;border-radius:10px!important;font-size:17px!important}
.storage-stat strong{display:block!important;margin-top:0!important;font-size:26px!important;line-height:1.1!important;font-weight:800!important;letter-spacing:-.5px!important;color:#172554!important}
.storage-stat span{display:block!important;margin-top:2px!important;color:#64748b!important;font-size:13px!important;font-weight:500!important;letter-spacing:0!important;text-transform:none!important}
@media(max-width:640px){.storage-stats{grid-template-columns:1fr!important}.storage-stat{border-right:0!important;border-bottom:1px solid #e5eceb!important;padding:18px!important}.storage-stat:last-child{border-bottom:0!important}}

@media(max-width:640px){.storage-header{padding:21px 18px}.storage-header h1{font-size:25px}.storage-header .header-actions{flex-direction:column}.storage-header .btn{justify-content:center}.storage-stats{grid-template-columns:1fr}.storage-stat{border-right:0;border-bottom:1px solid #e2e8f0;padding:17px 18px}.storage-stat:last-child{border-bottom:0}}
.file-icon { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
.file-state { display:flex; align-items:center; gap:10px; }
.fname { font-weight:600; color:#0f172a; font-size:13px; word-break:break-all; }
.fmeta { font-size:11px; color:#94a3b8; }
.thumb { width:44px; height:44px; object-fit:cover; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; }
.dropzone { border:2px dashed #cbd5e1; border-radius:12px; padding:22px 16px; text-align:center; color:#64748b; background:#f8fafc; cursor:pointer; transition:all .15s; }
.dropzone:hover, .dropzone.over { border-color:#2563eb; background:#eef4ff; }
.file-expand { display:none; padding:10px 14px; background:#f8fafc; border-radius:10px; margin-top:6px; }
.file-expand.open { display:block; }
.file-expand-row { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #eef1f6; }
.file-expand-row:last-child { border-bottom:none; }
.q-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; }
.q-item { display:flex; align-items:center; gap:10px; padding:12px; background:#f8fafc; border-radius:10px; }
.q-icon { width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; }
.q-item .q-pct { font-size:20px; font-weight:700; color:#0f172a; }
.q-item .q-label { font-size:12px; color:#64748b; }

/* ── Toolbar search / filter bar ──────────────────────── */
.fs-toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;row-gap:10px}
.fs-toolbar-left{display:flex;align-items:center;gap:10px;flex-shrink:0}
.fs-toolbar-left .count-badge{font-size:12px;font-weight:600;color:#64748b;background:#f1f5f9;padding:3px 10px;border-radius:9999px;white-space:nowrap;line-height:1.4}
.fs-toolbar-right{display:flex;align-items:center;gap:10px;margin-left:auto;flex-wrap:wrap;row-gap:8px}
.fs-search{position:relative;display:flex;align-items:center;height:38px;min-width:200px;flex:1 1 240px;max-width:320px}
.fs-search i.fa-magnifying-glass{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px;pointer-events:none}
.fs-search input{width:100%;height:100%;padding:0 32px 0 36px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-family:inherit;outline:none;background:#fff;color:#1e293b;box-sizing:border-box;transition:border-color .15s,box-shadow .15s}
.fs-search input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.08)}
.fs-search input::placeholder{color:#94a3b8}
.fs-search .clear-btn{position:absolute;right:6px;top:50%;transform:translateY(-50%);width:22px;height:22px;border:none;background:none;color:#94a3b8;border-radius:50%;cursor:pointer;display:none;align-items:center;justify-content:center;font-size:13px;line-height:1;transition:background .12s,color .12s}
.fs-search .clear-btn:hover{background:#f1f5f9;color:#475569}
.fs-search .clear-btn.show{display:flex}
.fs-filter{height:38px;min-width:150px;max-width:180px;padding:0 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-family:inherit;color:#1e293b;background:#fff;cursor:pointer;outline:none;appearance:auto;transition:border-color .15s}
.fs-filter:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.08)}
.fs-divider{width:1px;height:24px;background:#e2e8f0;flex-shrink:0}
.fs-count{font-size:12px;font-weight:600;color:#94a3b8;white-space:nowrap}

/* ── A file recorded in the database but not on this server ──
   Deliberately loud and deliberately not amber. Amber is already "waiting on
   a student" everywhere else in this app, and a missing file is not something
   anyone is waiting on - it is a broken record that needs a person to act. */
.fs-alert{display:flex;gap:13px;align-items:flex-start;margin:0 0 18px;padding:15px 18px;border:1px solid #fecaca;background:#fef2f2;border-radius:14px;color:#7f1d1d;font-size:12.5px;line-height:1.6}
.fs-alert i{font-size:17px;margin-top:1px;flex:0 0 auto}
.fs-alert b{color:#991b1b}
.fs-alert code{background:#fee2e2;padding:1px 5px;border-radius:4px;font-size:11.5px}
.file-expand-row.is-missing{background:#fef2f2;box-shadow:inset 3px 0 0 #ef4444}
.file-expand-row.is-missing .fname{color:#7f1d1d}
.fs-gone{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:6px;font-size:11.5px;color:#991b1b;line-height:1.5}
.fs-gone i{color:#dc2626}
.fs-gone-btn{border:1px solid #fca5a5;background:#fff;color:#b91c1c;font:inherit;font-size:11.5px;font-weight:600;padding:3px 9px;border-radius:7px;cursor:pointer}
.fs-gone-btn:hover{background:#fee2e2;border-color:#ef4444;color:#7f1d1d}
.fs-gone-btn:focus-visible{outline:2px solid #dc2626;outline-offset:1px}
</style>

<main class="dashboard-main">
    <div class="dashboard-container">
        <header class="storage-header">
            <div>
                <div class="storage-kicker"><i class="fas fa-folder-open"></i> Registrar document center</div>
                <h1>Digital File Storage</h1>
                <p>Store, preview and manage student documents — transcripts, clearances, health records</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-secondary" onclick="openModal('qualityModal')"><i class="fas fa-chart-pie"></i> Data Quality</button>
                <button class="btn btn-primary" onclick="openUpload()"><i class="fas fa-cloud-arrow-up"></i> Upload Files</button>
            </div>
        </header>

        <section class="storage-stats" aria-label="File storage summary">
            <div class="storage-stat"><div class="storage-stat-icon blue"><i class="fas fa-file-lines"></i></div><div><strong><?= $totalFiles ?></strong><span>Total files</span></div></div>
            <div class="storage-stat"><div class="storage-stat-icon teal"><i class="fas fa-hard-drive"></i></div><div><strong><?= fmtBytes($sumBytes) ?></strong><span>Total storage</span></div></div>
            <div class="storage-stat"><div class="storage-stat-icon red"><i class="fas fa-triangle-exclamation"></i></div><div><strong><?= $missingCount ?></strong><span>Missing document sets</span></div></div>
        </section>

        <?php if ($missingFileCount > 0): ?>
            <?php // Say it once, at the top, with the count. Without this the only
                  // evidence of the problem is a red icon in a table and a 404 in
                  // a console nobody opens - and "images not loading" reads as a
                  // broken page rather than as a set of broken records. ?>
            <div class="fs-alert" role="alert">
                <i class="fas fa-triangle-exclamation"></i>
                <div>
                    <b><?= (int) $missingFileCount ?> of <?= (int) $totalFiles ?> stored <?= $totalFiles === 1 ? 'file is' : 'files are' ?> not on this server.</b>
                    The database has the <?= $missingFileCount === 1 ? 'record' : 'records' ?>, but the files themselves were never deployed to this host &mdash;
                    they are uploaded at runtime and <code>uploads/</code> is not carried between servers. Nothing is lost in the database; the
                    <?= $missingFileCount === 1 ? 'file' : 'files' ?> just needs uploading again. Those rows are marked in red below.
                </div>
            </div>
        <?php endif; ?>

        <div class="panel" style="margin-bottom:18px;">
            <div class="panel-toolbar" style="flex-direction:column;align-items:stretch;border-bottom:none;padding-bottom:0;margin-bottom:0;">
                <div class="fs-toolbar">
                    <!-- Left: title + count -->
                    <div class="fs-toolbar-left">
                        <div class="panel-title"><i class="fas fa-users" style="color:#2563eb;"></i> Student Files</div>
                        <span class="count-badge" id="shownCount"><?= count($sortedStudents) ?> shown</span>
                    </div>
                    <!-- Right: search, filter, notify -->
                    <div class="fs-toolbar-right">
                        <div class="fs-search">
                            <i class="fas fa-magnifying-glass"></i>
                            <input type="text" id="stuSearch" placeholder="Search name, student ID…" autocomplete="off">
                            <button type="button" class="clear-btn" id="stuSearchClear"><i class="fas fa-xmark"></i></button>
                        </div>
                        <select id="docTypeFilter" class="fs-filter">
                            <option value="">All types</option>
                            <option value="enrollment">Enrollment</option>
                            <option value="transcript">Transcript</option>
                            <option value="health">Health</option>
                            <option value="photo">Photo</option>
                            <option value="clearance">Clearance</option>
                            <option value="other">Other</option>
                        </select>
                        <div class="fs-divider"></div>
                        <button class="btn btn-secondary btn-sm" onclick="notifyMissing()" id="notifyBtn"><i class="fas fa-bell"></i> Notify Students</button>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
            <table class="table">
                <thead><tr>
                    <th>Student ID</th><th>Student Name</th><th>Files</th><th>Status</th><th>Actions</th>
                </tr></thead>
                <tbody id="studentTableBody">
                <?php if (empty($sortedStudents)): ?>
                    <tr><td colspan="5" style="text-align:center;padding:32px;color:#94a3b8;">No student files yet.</td></tr>
                <?php else: foreach ($sortedStudents as $stu):
                    $mc = $stu['missing_count'];
                    $searchStr = strtolower($stu['student_name'] . ' ' . $stu['student_number']);
                    $docTypes = implode(' ', $stu['types_present']);
                ?>
                    <tr data-search="<?= htmlspecialchars($searchStr) ?>" data-doctypes="<?= htmlspecialchars(strtolower($docTypes)) ?>" data-student-id="<?= $stu['id'] ?>">
                        <td><span style="font-weight:600;font-size:13px;"><?= htmlspecialchars($stu['student_number']) ?></span></td>
                        <td><div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($stu['student_name']) ?></div></td>
                        <td><span style="font-weight:600;"><?= count($stu['files']) ?></span></td>
                        <td>
                            <?php if ($mc === 0): ?>
                                <span class="pill approved"><i class="fas fa-check" style="font-size:10px;"></i> Complete</span>
                            <?php else: ?>
                                <span class="pill denied"><i class="fas fa-times" style="font-size:10px;"></i> Missing (<?= $mc ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td><div class="action-group">
                            <button class="action-btn view" onclick="toggleFiles(<?= $stu['id'] ?>)" title="View Files"><i class="fas fa-folder-open"></i></button>
                            <button class="action-btn download" onclick="quickUpload(<?= $stu['id'] ?>)" title="Upload to student"><i class="fas fa-upload"></i></button>
                        </div></td>
                    </tr>
                    <tr class="file-expand-tr" id="files-<?= $stu['id'] ?>" style="display:none;">
                        <td colspan="5" style="padding:0;">
                            <div class="file-expand open">
                            <?php if ($mc > 0): ?>
                                <div style="padding:6px 0 10px;">
                                    <span style="font-size:11px;font-weight:600;color:#dc2626;"><i class="fas fa-triangle-exclamation"></i> Missing:</span>
                                    <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:4px;">
                                        <?php foreach ($stu['missing_types'] as $mt): ?>
                                            <span class="pill at-risk"><?= ucfirst(htmlspecialchars($mt)) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php foreach ($stu['files'] as $f):
                                $ext = strtolower(pathinfo($f['filename'], PATHINFO_EXTENSION));
                                $ic = $iconMap[$ext] ?? 'fa-file';
                                $cl = $colorMap[$ext] ?? '#94a3b8';
                                // Resolved up front (see $fileUrlById above), so a file
                                // that is not on this server produces no src, no href and
                                // no preview: the browser is never asked for something
                                // that will 404.
                                $fileUrl = $fileUrlById[(int) $f['id']] ?? '';
                                $fileGone = isset($fileGoneById[(int) $f['id']]);
                            ?>
                                <div class="file-expand-row<?= $fileGone ? ' is-missing' : '' ?>" data-id="<?= (int)$f['id'] ?>" data-name="<?= htmlspecialchars($f['filename']) ?>" data-path="<?= htmlspecialchars($fileGone ? '' : $fileUrl) ?>" data-missing="<?= $fileGone ? '1' : '0' ?>" data-type="<?= htmlspecialchars($ext) ?>" data-student="<?= htmlspecialchars($stu['student_name']) ?>" data-desc="<?= htmlspecialchars($f['description'] ?? '') ?>" data-ai-valid="<?= (int)($f['ai_valid'] ?? -1) ?>">
                                    <?php if ($fileGone): ?>
                                        <?php // An <img> here would be a 404 and a broken-image
                                              // glyph, and the Download link would 404 too: two
                                              // console errors and nothing a clerk can act on. ?>
                                        <div class="file-icon" style="background:#fef2f2;color:#dc2626;" title="Recorded in the database, but the file is not on this server"><i class="fas fa-file-circle-xmark"></i></div>
                                    <?php elseif ($isImage($ext)): ?>
                                        <img src="<?= htmlspecialchars($fileUrl) ?>" alt="" class="thumb" loading="lazy">
                                    <?php else: ?>
                                        <div class="file-icon" style="background:<?= $cl ?>14;color:<?= $cl ?>;"><i class="fas <?= $ic ?>"></i></div>
                                    <?php endif; ?>
                                    <div style="flex:1;min-width:0;">
                                        <div class="fname"><?= htmlspecialchars($f['filename']) ?></div>
                                        <div class="fmeta">
                                            <span class="chip blue" style="font-size:10px;"><?= htmlspecialchars(ucfirst($f['doc_type'])) ?></span>
                                            &middot; <?= fmtBytes((int)$f['file_size']) ?>
                                            &middot; <?= date('M d, Y', strtotime($f['created_at'])) ?>
                                            <?php if ($f['description']): ?> &middot; <?= htmlspecialchars($f['description']) ?><?php endif; ?>
                                        </div>
                                        <?php if ($fileGone): ?>
                                            <?php // Name the fault, and offer the one action that
                                                  // fixes it. "Missing document" already means
                                                  // something else on this page - a required
                                                  // document a student has not supplied - so
                                                  // this says precisely what is wrong: the file
                                                  // itself is not on this server. ?>
                                            <div class="fs-gone">
                                                <i class="fas fa-triangle-exclamation"></i>
                                                Not on this server &mdash; the record is here, the file was never deployed to this host.
                                                <button type="button" class="fs-gone-btn" onclick="reuploadMissing(<?= (int)$f['student_id'] ?>, <?= (int)$f['id'] ?>)">Upload it again</button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="action-group">
                                        <?php $av = (int)($f['ai_valid'] ?? -1); ?>
                                        <?php if ($av === 1): ?>
                                            <span class="action-btn" style="color:#16a34a;cursor:default;" title="AI Valid: <?= htmlspecialchars($f['ai_validation_note'] ?? 'Verified') ?>"><i class="fas fa-circle-check"></i></span>
                                        <?php elseif ($av === 0): ?>
                                            <span class="action-btn" style="color:#dc2626;cursor:default;background:#fef2f2;" title="AI Flagged: <?= htmlspecialchars($f['ai_validation_note'] ?? 'Invalid document') ?>"><i class="fas fa-triangle-exclamation"></i></span>
                                        <?php else: ?>
                                            <button class="action-btn" onclick="aiValidate(this)" data-id="<?= (int)$f['id'] ?>" title="AI Validate this document" style="color:#8b5cf6;"><i class="fas fa-robot"></i></button>
                                        <?php endif; ?>
                                        <?php if ($fileGone): ?>
                                            <span class="action-btn" style="color:#94a3b8;cursor:default;" title="Preview unavailable &mdash; the file is not on this server"><i class="fas fa-eye-slash"></i></span>
                                            <span class="action-btn" style="color:#94a3b8;cursor:default;" title="Download unavailable &mdash; the file is not on this server"><i class="fas fa-download"></i></span>
                                        <?php else: ?>
                                            <button class="action-btn view" onclick="previewFile(this.closest('.file-expand-row'))" title="Preview"><i class="fas fa-eye"></i></button>
                                            <a class="action-btn download" href="<?= htmlspecialchars($fileUrl) ?>" title="Download" download><i class="fas fa-download"></i></a>
                                        <?php endif; ?>
                                        <button class="action-btn delete" onclick="deleteFile(this.closest('.file-expand-row'))" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
            <div class="table-footer">
                <div class="info-text">Showing <strong id="storageTotalCount"><?= count($sortedStudents) ?></strong> of <strong><?= count($sortedStudents) ?></strong> students</div>
            </div>
        </div>
    </div>
</main>

<!-- Upload Modal -->
<div class="modal-overlay" id="uploadModal"><div class="modal-content wide">
    <div class="modal-header"><h2><i class="fas fa-upload" style="color:#2563eb;"></i> Upload Document</h2><button class="modal-close" onclick="closeModal('uploadModal')"><i class="fas fa-times"></i></button></div>
    <form id="uploadForm">
        <div id="upReplaceNotice" hidden
             style="margin:0 0 14px;padding:11px 13px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:10px;font-size:12px;line-height:1.5;color:#1e40af"></div>
    <div class="modal-body">
        <div class="form-group"><label>Student <span style="color:#dc2626;">*</span></label>
            <select id="upStudent" class="form-control" data-searchable required>
                <option value="">Select a student</option>
                <?php foreach ($students as $st): ?>
                    <option value="<?= (int)$st['id'] ?>"><?= htmlspecialchars($st['student_number'] . ' — ' . $st['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group"><label>Document Type</label>
                <select id="upType" class="form-control">
                    <option value="enrollment">Enrollment</option>
                    <option value="transcript">Transcript / Records</option>
                    <option value="health">Health</option>
                    <option value="photo">Photo</option>
                    <option value="clearance">Clearance</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="form-group"><label>Category (optional)</label><input type="text" id="upCategory" class="form-control" placeholder="e.g. Form 137, NCAE…"></div>
        </div>
        <div id="upDropzone" class="dropzone">
            <i class="fas fa-cloud-arrow-up" style="font-size:26px;display:block;margin-bottom:8px;color:#94a3b8;"></i>
            <div style="font-size:13px;"><strong>Drag &amp; drop a file</strong> or <span style="color:#2563eb;text-decoration:underline;">click to browse</span></div>
            <div style="font-size:12px;color:#94a3b8;margin-top:4px;">PDF, Word, Excel, images, text, zip · up to 25 MB</div>
            <input type="file" id="upFile" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,.txt,.odt,.ods,.zip,.rar" style="display:none;">
        </div>
        <div id="upFileName" style="font-size:12px;color:#16a34a;margin-top:8px;"></div>
        <div class="form-group" style="margin-top:12px;"><label>Description</label><textarea id="upDesc" class="form-control" rows="2" placeholder="What this document is…"></textarea></div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('uploadModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="upSubmit"><i class="fas fa-upload"></i> Upload</button>
    </div>
    </form>
</div></div>

<!-- Preview Modal -->
<div class="modal-overlay" id="previewModal"><div class="modal-content wide">
    <div class="modal-header"><h2><i class="fas fa-file-lines" style="color:#2563eb;"></i> <span id="pvFileName">File</span></h2><button class="modal-close" onclick="closeModal('previewModal')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body">
        <div id="pvMeta" style="font-size:12px;color:#64748b;margin-bottom:12px;"></div>
        <div id="pvContent" style="text-align:center;padding:10px;background:#f8fafc;border-radius:12px;min-height:120px;"></div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('previewModal')">Close</button>
        <a id="pvDownload" class="btn btn-primary" download><i class="fas fa-download"></i> Download</a>
    </div>
</div></div>

<!-- Data Quality Modal -->
<div class="modal-overlay" id="qualityModal"><div class="modal-content wide">
    <div class="modal-header"><h2><i class="fas fa-chart-pie" style="color:#2563eb;"></i> Data Quality Report</h2><button class="modal-close" onclick="closeModal('qualityModal')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body">
        <div class="q-grid">
        <?php
        $totalStudents = count($students);
        foreach ($requiredTypes as $qt):
            $have = (int) $db->fetchColumn("SELECT COUNT(DISTINCT student_id) FROM documents WHERE doc_type = ?", [$qt]);
            $pct = $totalStudents > 0 ? round(($have / $totalStudents) * 100) : 0;
            $barColor = $pct >= 80 ? '#16a34a' : ($pct >= 50 ? '#b45309' : '#dc2626');
            $qtIcon = $qt === 'enrollment' ? 'fa-clipboard-list' : ($qt === 'transcript' ? 'fa-graduation-cap' : ($qt === 'health' ? 'fa-heart-pulse' : ($qt === 'photo' ? 'fa-camera' : 'fa-shield-halved')));
        ?>
            <div class="q-item">
                <div class="q-icon" style="background:<?= $barColor ?>14;color:<?= $barColor ?>;"><i class="fas <?= $qtIcon ?>"></i></div>
                <div>
                    <div class="q-pct"><?= $pct ?>%</div>
                    <div class="q-label"><?= ucfirst($qt) ?> (<?= $have ?>/<?= $totalStudents ?>)</div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <div style="margin-top:16px;padding:14px;background:#f8fafc;border-radius:10px;">
            <div style="font-weight:600;font-size:13px;color:#0f172a;margin-bottom:8px;"><i class="fas fa-chart-bar" style="color:#2563eb;"></i> Summary</div>
            <div style="font-size:13px;color:#475569;line-height:1.8;">
                <strong><?= $totalStudents ?></strong> active students &middot;
                <strong><?= $totalFiles ?></strong> total files &middot;
                <strong><?= fmtBytes($sumBytes) ?></strong> total storage<br>
                <span style="color:#16a34a;font-weight:600;"><?= $totalStudents - $missingCount ?></span> fully documented &middot;
                <span style="color:#dc2626;font-weight:600;"><?= $missingCount ?></span> with missing docs
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('qualityModal')">Close</button>
    </div>
</div></div>

<script>
// ─── MODAL HELPERS ────────────────────────────────────────
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}
function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}
['uploadModal','previewModal','qualityModal'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('click', function(e) { if (e.target === el) closeModal(id); });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        ['uploadModal','previewModal','qualityModal'].forEach(closeModal);
    }
});

// ─── EXPAND / COLLAPSE FILES ──────────────────────────────
function toggleFiles(studentId) {
    var row = document.getElementById('files-' + studentId);
    if (!row) return;
    row.style.display = row.style.display === 'none' ? '' : 'none';
}

// ─── QUICK UPLOAD (pre-select student) ────────────────────
function quickUpload(studentId) {
    openUpload();
    var sel = document.getElementById('upStudent');
    if (sel) sel.value = studentId;
}


// UPLOAD MODAL (open + dropzone)
var upDropzone  = document.getElementById('upDropzone');
var upFileInput = document.getElementById('upFile');
var upFileName  = document.getElementById('upFileName');
function showSelectedFile() {
    if (!upFileName || !upFileInput) return;
    upFileName.textContent = upFileInput.files.length ? 'Selected: ' + upFileInput.files[0].name : '';
}
function openUpload() {
    document.getElementById('upStudent').value = '';
    document.getElementById('upType').value = 'enrollment';
    document.getElementById('upCategory').value = '';
    document.getElementById('upDesc').value = '';
    if (upFileInput) upFileInput.value = '';
    // A plain Upload is never a replace. Left set, the next unrelated upload would
    // silently overwrite a record the clerk never named.
    replaceDocId = null;
    var notice = document.getElementById('upReplaceNotice');
    if (notice) { notice.hidden = true; notice.textContent = ''; }
    showSelectedFile();
    openModal('uploadModal');
}
if (upDropzone && upFileInput) {
    // Exactly ONE click handler, and that is load-bearing.
    //
    // This block used to be followed by a second, near-identical IIFE wiring
    // the same two elements, which meant one click on the dropzone ran
    // upFileInput.click() twice. Chrome treats the second as a fresh request to
    // show a file chooser with no user activation left, and reports:
    //
    //     File chooser dialog can only be shown with a user activation.
    //
    // The symptom was "uploading a file does nothing" with that error sitting
    // in the console - the chooser fought itself.
    //
    // The second copy also declared `function openUpload() { openModal(...) }`.
    // Function declarations are hoisted and the last one wins, so that stub -
    // which does NOT reset the form - silently replaced the real one above.
    // Opening Upload a second time therefore kept the previous student, type,
    // category, description and selected file, which reads as "upload is
    // broken" all over again: the clerk submits and gets the last file stored
    // twice, or a duplicate rejected.
    //
    // One wiring, one definition. If the dropzone needs new behaviour, add it
    // here.
    upDropzone.addEventListener('click', function() { upFileInput.click(); });
    upDropzone.addEventListener('dragover', function(e) { e.preventDefault(); upDropzone.classList.add('over'); });
    upDropzone.addEventListener('dragleave', function(e) { upDropzone.classList.remove('over'); });
    upDropzone.addEventListener('drop', function(e) {
        e.preventDefault();
        upDropzone.classList.remove('over');
        if (e.dataTransfer && e.dataTransfer.files.length) upFileInput.files = e.dataTransfer.files;
        showSelectedFile();
    });
    upFileInput.addEventListener('change', showSelectedFile);
}

document.getElementById('uploadForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var btn = document.getElementById('upSubmit');
    var studentId = document.getElementById('upStudent').value;
    var file = document.getElementById('upFile').files[0];
    if (!studentId) { showToast('Select a student.', 'warning'); return; }
    if (!file) { showToast('Select a file.', 'warning'); return; }
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading…';
    var fd = new FormData();
    fd.append('student_id', studentId);
    fd.append('doc_type', document.getElementById('upType').value);
    fd.append('category', document.getElementById('upCategory').value);
    fd.append('description', document.getElementById('upDesc').value);
    if (replaceDocId) fd.append('replace_id', replaceDocId);
    fd.append('file', file);
    try {
        var res = await fetch('../api/documents.php?section=files', { method: 'POST', body: fd });
        var d = await res.json();
        if (d.success) {
            showToast('Document stored. Validating…', 'success');
            // Auto-validate the uploaded document via AI
            if (d.data && d.data.id) {
                validateDocument(d.data.id, function(vr) {
                    if (vr && vr.valid === false) {
                        showToast('AI flagged this document: ' + (vr.reasoning || 'Does not match declared type.'), 'error');
                    }
                    setTimeout(function() { window.location.reload(); }, vr && vr.valid === false ? 1500 : 700);
                });
            } else {
                setTimeout(function() { window.location.reload(); }, 700);
            }
        } else {
            showToast(d.message || 'Upload failed.', 'error');
        }
    } catch(err) { showToast('Network error.', 'error'); }
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-upload"></i> Upload';
});

// ─── OPEN UPLOAD MODAL ─────────────────────────────────
// (the canonical openUpload, form reset included, is defined above with the
// dropzone wiring - see the note there)

// ─── RE-UPLOAD A FILE THAT IS NOT ON THIS SERVER ─────────────
//
// The row offers "Upload it again". That opens the normal upload modal with the
// student pre-selected and remembers which record it is replacing, so the
// re-upload updates the row instead of adding a second copy beside it.
//
// Without the replaceId the clerk would end up with two rows for one document:
// the dead one still on file, and a new one. The page would look no better.
var replaceDocId = null;

function reuploadMissing(studentId, docId) {
    replaceDocId = docId || null;
    openUpload();
    var sel = document.getElementById('upStudent');
    if (sel && studentId) sel.value = studentId;
    var notice = document.getElementById('upReplaceNotice');
    if (notice) {
        notice.hidden = !replaceDocId;
        if (replaceDocId) {
            notice.textContent = 'This will replace the existing record #' + replaceDocId
                + ' — the file it points at is missing, so nothing is overwritten.';
        }
    }
}

// ─── SEARCH / FILTER ─────────────────────────────────────
var searchInput    = document.getElementById('stuSearch');
var searchClearBtn = document.getElementById('stuSearchClear');
var docTypeFilter  = document.getElementById('docTypeFilter');
var shownCountEl   = document.getElementById('shownCount');
// The table footer carries its own id. It used to be 'shownCount' too,
// which made getElementById return the toolbar badge first and left the
// footer permanently stale.
var footerCountEl  = document.getElementById('storageTotalCount');
var totalStudents  = document.querySelectorAll('#studentTableBody tr[data-search]').length;

function applyFilter() {
    var q = searchInput.value.trim().toLowerCase();
    var t = docTypeFilter.value;
    var visible = 0;
    document.querySelectorAll('#studentTableBody tr[data-search]').forEach(function(row) {
        var s = row.dataset.search;
        var types = row.dataset.doctypes || '';
        var okQ = !q || s.indexOf(q) !== -1;
        var okT = !t || types.indexOf(t) !== -1;
        row.style.display = okQ && okT ? '' : 'none';
        var next = row.nextElementSibling;
        if (next && next.classList.contains('file-expand-tr')) {
            next.style.display = 'none';
        }
        if (okQ && okT) visible++;
    });
    shownCountEl.textContent = visible + ' shown';
    if (footerCountEl) footerCountEl.textContent = visible;
    searchClearBtn.classList.toggle('show', searchInput.value.length > 0);
}
searchInput.addEventListener('input', applyFilter);
docTypeFilter.addEventListener('change', applyFilter);
searchClearBtn.addEventListener('click', function() {
    searchInput.value = '';
    applyFilter();
    searchInput.focus();
});

// ─── PREVIEW ─────────────────────────────────────────────
var IMG_EXTS = ['jpg','jpeg','png','webp','gif'];
var _mammothLoaded = false;
function _loadMammoth(cb) {
    if (_mammothLoaded) { cb(); return; }
    var s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js';
    s.onload = function() { _mammothLoaded = true; cb(); };
    s.onerror = function() { cb(); };
    document.head.appendChild(s);
}
function previewFile(el) {
    document.getElementById('pvFileName').textContent = el.dataset.name;
    document.getElementById('pvMeta').innerHTML = '<b>' + (el.dataset.student || '') + '</b> · ' + el.dataset.type.toUpperCase() + ' · ' + (el.dataset.desc || 'No description');

    // A row whose file is not on this server carries no path. It has no Preview
    // button any more, but the function is also reachable from the console and
    // from a stale click target, and an <img src=""> here resolves to the page
    // URL and fails - the exact empty-src trap the RFID card photo had.
    var path = el.dataset.path || '';
    if (!path) {
        var dl = document.getElementById('pvDownload');
        if (dl) dl.removeAttribute('href');
        document.getElementById('pvContent').innerHTML =
            '<div style="padding:36px 20px;text-align:center;color:#b91c1c;">'
            + '<i class="fas fa-triangle-exclamation" style="font-size:26px;display:block;margin-bottom:10px;"></i>'
            + 'This file is recorded in the database but is not on this server.<br>'
            + '<span style="font-size:12px;color:#64748b;">Close this and use &ldquo;Upload it again&rdquo; on the row.</span></div>';
        return;
    }

    document.getElementById('pvDownload').href = path;
    var ext = el.dataset.type.toLowerCase();
    var c = document.getElementById('pvContent');
    if (IMG_EXTS.indexOf(ext) !== -1) {
        c.innerHTML = '<img src="' + path + '" alt="preview" style="max-width:100%;max-height:420px;border-radius:8px;">';
    } else if (ext === 'pdf') {
        c.innerHTML = '<iframe src="' + path + '" style="width:100%;height:480px;border:none;border-radius:8px;background:#fff;"></iframe>';
    } else if (ext === 'txt') {
        fetch(path).then(function(r) { return r.text(); }).then(function(t) {
            c.innerHTML = '<pre style="text-align:left;font-size:12px;white-space:pre-wrap;max-height:420px;overflow:auto;">' + t.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</pre>';
        }).catch(function() { c.innerHTML = '<p style="color:#64748b;">Preview not available.</p>'; });
    } else if (ext === 'docx') {
        c.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><p style="margin-top:8px;">Loading document preview…</p></div>';
        openModal('previewModal');
        _loadMammoth(function() {
            if (typeof mammoth === 'undefined') { c.innerHTML = '<p style="color:#64748b;">Preview library failed to load. Download the file to view.</p>'; return; }
            fetch(el.dataset.path).then(function(r) { return r.arrayBuffer(); }).then(function(buf) {
                return mammoth.convertToHtml({ arrayBuffer: buf });
            }).then(function(res) {
                var html = res.value;
                if (!html || !html.trim()) { html = '<p style="color:#64748b;">Document appears empty.</p>'; }
                c.innerHTML = '<div style="max-height:480px;overflow:auto;padding:20px;background:#fff;border-radius:8px;font-size:13px;line-height:1.7;">' + html + '</div>';
            }).catch(function() { c.innerHTML = '<p style="color:#64748b;">Could not preview this document. Download to view.</p>'; });
        });
        return;
    } else {
        c.innerHTML = '<span style="font-size:32px;color:#94a3b8;"><i class="fas fa-file"></i></span><p style="color:#64748b;margin-top:8px;">No inline preview for .' + ext + ' — download to view.</p>';
    }
    openModal('previewModal');
}

// ─── DELETE ──────────────────────────────────────────────
async function deleteFile(el) {
    if (!await confirmAction({
        title: 'Delete file',
        body: 'Delete <strong>' + escText(el.dataset.name) + '</strong> for ' + escText(el.dataset.student) +
              '? This cannot be undone.',
        confirmLabel: 'Delete file',
        tone: 'danger'
    })) return;
    fetch('../api/documents.php?section=files&action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: el.dataset.id })
    }).then(function(r) { return r.json(); }).then(function(res) {
        if (res.success) { showToast('File removed.', 'success'); setTimeout(function() { window.location.reload(); }, 500); }
        else showToast(res.message || 'Delete failed.', 'error');
    }).catch(function() { showToast('Network error.', 'error'); });
}

// ─── NOTIFY MISSING ─────────────────────────────────────
function notifyMissing() {
    var btn = document.getElementById('notifyBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
    fetch('../api/documents.php?section=files&action=notify_missing', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
    }).then(function(r) { return r.json(); }).then(function(res) {
        if (res.success) {
            showToast(res.message || 'Students with missing documents notified.', 'success');
        } else {
            showToast(res.message || 'Failed to send notifications.', 'error');
        }
    }).catch(function() {
        showToast('Network error.', 'error');
    }).finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-bell"></i> Notify Students';
    });
}

// ─── AI VALIDATE ────────────────────────────────────────
function validateDocument(docId, cb) {
    var fd = new FormData();
    fd.append('document_id', docId);
    fetch('../api/documents-ai.php?action=validate', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) { if (cb) cb(d); })
        .catch(function() { if (cb) cb(null); });
}

// Validate a single file row
function aiValidate(el) {
    var docId = el.dataset.id;
    var row = el.closest('.file-expand-row');
    el.disabled = true;
    el.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    validateDocument(docId, function(d) {
        el.disabled = false;
        if (!d || !d.success) {
            el.innerHTML = '<i class="fas fa-triangle-exclamation"></i>';
            el.title = 'Validation failed';
            return;
        }
        if (d.valid) {
            el.innerHTML = '<i class="fas fa-circle-check"></i>';
            el.className = 'action-btn valid-badge';
            el.title = 'Valid: ' + (d.reasoning || '');
            el.style.color = '#16a34a';
        } else {
            el.innerHTML = '<i class="fas fa-triangle-exclamation"></i>';
            el.className = 'action-btn invalid-badge';
            el.title = 'Invalid: ' + (d.reasoning || '');
            el.style.color = '#dc2626';
            // Highlight row
            row.style.background = '#fef2f2';
        }
        // Remove validate button, show result
    });
}
</script>

<?php include '../includes/footer.php'; ?>
