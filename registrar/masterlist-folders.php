<?php
// ============================================================
//  REGISTRAR/MASTERLIST-FOLDERS.PHP
//  The masterlist as a drive: one folder per program, one per
//  year level, one per section — and clicking a folder goes
//  INTO it.
//
//  WHY IT IS A BROWSER AND NOT AN OUTLINE
//  --------------------------------------
//  The first version of this page rendered every folder expanded
//  on one long page. It was wrong in a way that is easy to miss
//  until you try to use it: it answers "show me everything" and
//  the office never asks that. They ask "show me BSIT".
//
//  So this is a folder browser. You are always standing in exactly
//  one folder. The root lists the programs. Clicking BSIT takes
//  you inside it, where you see its year folders. Clicking Year 1
//  shows its section folders. Clicking a section shows THE
//  MASTERLIST THAT FOLDER HOLDS — the generated list of its
//  students — which is what you came for.
//
//  Server-rendered links, no JavaScript navigation. That is a
//  deliberate choice: every folder is a real URL, so a folder can
//  be bookmarked, pasted into an email to a department, walked
//  back with the browser's Back button, and printed. A tree that
//  only lives in a JS variable has none of those, and "send me
//  the BSIT Year 1 11001 list" is a request this office makes
//  constantly.
//
//  The current folder is `?path=`, resolved by
//  shared/masterlist_folders.php against folders that actually
//  exist. See that file for the grouping rules and, importantly,
//  why unplaced students are listed rather than hidden.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
requireRole('registrar');

require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/masterlist_folders.php';

$db = Database::getInstance();

// ─── FILTERS ───────────────────────────────────────────────
// The same dimensions, and the same GET parameter names, as the
// flat Masterlist page — so a link copied off either works on
// the other.
$filters = [
    'program'     => isset($_GET['program']) ? trim((string) $_GET['program']) : '',
    'year'        => isset($_GET['year_level']) ? trim((string) $_GET['year_level']) : '',
    'school_year' => isset($_GET['school_year']) ? trim((string) $_GET['school_year']) : '',
    'semester'    => isset($_GET['semester']) ? trim((string) $_GET['semester']) : '',
    'section'     => isset($_GET['section']) ? trim((string) $_GET['section']) : '',
];
$anyFilterActive = in_array(true, array_map(static fn($v) => $v !== '', $filters), true);

// ─── WHERE ARE WE? ─────────────────────────────────────────
// The one thing this page is about. Empty = the root.
$path = isset($_GET['path']) ? trim((string) $_GET['path']) : '';

// ─── LOAD ──────────────────────────────────────────────────
// The WHOLE student table, not the "must have a section" roster
// the flat Masterlist page uses.
//
// This is the difference between the two pages and it is
// deliberate. The flat list is a signed section sheet: a row with
// no section has no place on it. A folder tree is an inventory,
// and a student nobody has placed yet has to be visible as work
// outstanding — in an Unassigned folder, not silently dropped.
$sql = "SELECT id, student_number, first_name, middle_name, last_name,
               name_suffix, course, year_level, section, school_year,
               semester, gender, status, contact_number, email
        FROM students";
$where  = [];
$params = [];

if ($filters['program'] !== '') {
    $where[]  = 'TRIM(course) = ?';
    $params[] = $filters['program'];
}
if ($filters['year'] !== '' && is_numeric($filters['year'])) {
    $where[]  = 'year_level = ?';
    $params[] = (int) $filters['year'];
}
if ($filters['school_year'] !== '') {
    $where[]  = 'school_year = ?';
    $params[] = $filters['school_year'];
}
if ($filters['semester'] !== '') {
    $where[]  = 'semester = ?';
    $params[] = $filters['semester'];
}
if ($filters['section'] !== '') {
    $where[]  = 'TRIM(section) = ?';
    $params[] = $filters['section'];
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY TRIM(course) ASC, COALESCE(year_level, 0) ASC, section ASC, last_name ASC, first_name ASC';

$students = $db->fetchAll($sql, $params);

// ─── THE FOLDER WE ARE STANDING IN ──────────────────────────
$tree    = mlf_build_tree($students);
$current = mlf_resolve($tree, $path);

// A path that names no folder falls back to the root rather than
// erroring. Said out loud, because silently showing the root for a
// typo'd link looks like the link worked.
$pathMissing = !$current['exists'] && $path !== '';

// Everything under the current folder, for the export. Resolved by
// SUBTREE rather than by "what is on screen", so downloading BSIT
// gives the whole of BSIT and not just the level being viewed —
// a download that silently omitted Year 2 would be worse than none.
$scopeSections = mlf_sections_under($tree, $current['path']);
$scopeCount    = array_sum(array_column($scopeSections, 'count'));

$dims = mlf_known_dimensions($students);

// How many students are not yet in a section. Said plainly on the
// root rather than left for the reader to infer from a folder name.
$unplaced = 0;
foreach ($scopeSections as $folder) {
    if ($folder['section'] === MLF_NO_SECTION) {
        $unplaced += (int) $folder['count'];
    }
}

// Link helper: a URL to a folder that carries the current filters,
// so navigating never silently discards them.
$linkTo = static function (string $target) use ($filters): string {
    $q = $filters;
    if ($target !== '') {
        $q['path'] = $target;
    } else {
        unset($q['path']);
    }
    return 'masterlist-folders.php' . ($q ? '?' . http_build_query($q) : '');
};

$exportBase  = '../api/masterlist-folders.php?action=export';
$exportQuery = $filters;
if ($current['path'] !== '') {
    $exportQuery['path'] = $current['path'];
}

$page_title = 'Masterlist Folders';
$ACTIVE_NAV = 'masterlist';
$APP_ROOT   = '../';
include '../includes/header.php';
?><main class="main">
    <header class="mlf-header">
        <div>
            <div class="mlf-kicker"><i class="fas fa-folder-tree"></i> Registrar directory</div>
            <h1>Masterlist Folders</h1>
            <p>
                One folder per program, one per year level, one per section. Open a folder
                to see what it holds &mdash; a section folder shows the masterlist
                generated for it. Section codes follow the format
                <strong>11001</strong> (year 1, 1st semester, section 1).
            </p>
        </div>

        <!-- The same two-view toggle the flat Masterlist carries, so
             the switch is in the same place on both and neither page
             has to be found through the nav. -->
        <div class="mlf-views" role="group" aria-label="Choose how the masterlist is displayed">
            <a class="mlv-btn" href="masterlist.php"
               title="One flat, printable list">
                <i class="fas fa-table-list"></i> List
            </a>
            <a class="mlv-btn is-active" href="masterlist-folders.php"
               title="Browse the records as folders (this view)">
                <i class="fas fa-folder-tree"></i> Folders
            </a>
        </div>

        <div class="mlf-header-actions">
            <?php if ($scopeSections): ?>
                <a class="btn btn-primary" href="<?= htmlspecialchars($exportBase . '&' . http_build_query($exportQuery)) ?>">
                    <i class="fas fa-file-zipper"></i>
                    Download <?= $current['path'] === '' ? 'everything' : htmlspecialchars($current['name']) ?>
                </a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($pathMissing): ?>
        <div class="mlf-banner mlf-banner-warn" role="status">
            <i class="fas fa-folder-minus"></i>
            <div>
                <strong>No folder called &ldquo;<?= htmlspecialchars($path) ?>&rdquo; exists.</strong>
                It may have been renamed, or its students moved to another section.
                Showing the top level instead.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($unplaced > 0 && $current['level'] === 'root'): ?>
        <!-- Said plainly here rather than only as a folder name. An
             exported tree handed to a department omits nobody, but
             these students sit in an "Unassigned Section" folder, and
             a receiving office reading the tree at face value will
             treat them as a separate cohort. -->
        <div class="mlf-banner mlf-banner-warn" role="status">
            <i class="fas fa-user-clock"></i>
            <div>
                <strong><?= (int) $unplaced ?> student<?= $unplaced === 1 ? '' : 's' ?> not yet in a section.</strong>
                They are filed under <em><?= MLF_NO_SECTION ?></em> rather than hidden &mdash;
                place them from the <a href="masterlist.php">flat masterlist</a> and the
                folders rebuild themselves.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($anyFilterActive): ?>
        <div class="mlf-banner mlf-banner-info">
            <i class="fas fa-filter"></i>
            <div>
                Filtered
                <?php if ($filters['program'] !== ''): ?>to <strong><?= htmlspecialchars($filters['program']) ?></strong><?php endif; ?>
                <?php if ($filters['year'] !== ''): ?>, year <?= (int) $filters['year'] ?><?php endif; ?>
                <?php if ($filters['semester'] !== ''): ?>, <?= htmlspecialchars($filters['semester']) ?> semester<?php endif; ?>.
                <a href="<?= htmlspecialchars($linkTo('')) ?>">Clear filters</a>.
            </div>
        </div>
    <?php endif; ?><form class="mlf-filters" method="get" action="masterlist-folders.php">
        <?php if ($current['path'] !== ''): ?>
            <!-- Navigating must not discard the filters, so the
                 folder is carried through the form as a hidden field
                 rather than being dropped on submit. -->
            <input type="hidden" name="path" value="<?= htmlspecialchars($current['path']) ?>">
        <?php endif; ?>
        <label class="mlf-field">
            <span>Program</span>
            <select name="program" class="form-control">
                <option value="">All programs</option>
                <?php foreach ($dims['programs'] as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= $filters['program'] === $p ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="mlf-field">
            <span>Year level</span>
            <select name="year_level" class="form-control">
                <option value="">All years</option>
                <?php foreach ($dims['years'] as $y): ?>
                    <option value="<?= htmlspecialchars($y) ?>" <?= (string) $filters['year'] === $y ? 'selected' : '' ?>>
                        Year <?= htmlspecialchars($y) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="mlf-field">
            <span>Semester</span>
            <select name="semester" class="form-control">
                <option value="">All semesters</option>
                <?php foreach (['1st', '2nd', 'summer'] as $sm): ?>
                    <option value="<?= $sm ?>" <?= $filters['semester'] === $sm ? 'selected' : '' ?>><?= $sm ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="mlf-field">
            <span>Section</span>
            <select name="section" class="form-control">
                <option value="">All sections</option>
                <?php foreach ($dims['sections'] as $sec): ?>
                    <option value="<?= htmlspecialchars($sec) ?>" <?= $filters['section'] === $sec ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sec) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn btn-primary mlf-apply"><i class="fas fa-check"></i> Apply</button>
    </form>

    <!-- ── THE BREADCRUMB BAR ──────────────────────────────────
         The one piece of chrome that makes this read as a drive
         rather than a page. It shows where you are, and every
         ancestor is a link back up, which is what lets a reader
         climb out of a section without reaching for Back. -->
    <nav class="mlf-crumbs" aria-label="Folder path">
        <a class="mlf-crumb<?= $current['level'] === 'root' ? ' is-here' : '' ?>"
           href="<?= htmlspecialchars($linkTo('')) ?>">
            <i class="fas fa-hard-drive"></i> Masterlist Folders
        </a>
        <?php foreach ($current['breadcrumbs'] as $i => $crumb): ?>
            <?php $isLast = ($i === count($current['breadcrumbs']) - 1); ?>
            <span class="mlf-crumb-sep" aria-hidden="true">/</span>
            <?php if ($isLast): ?>
                <span class="mlf-crumb is-here" aria-current="page">
                    <?= htmlspecialchars($crumb['name']) ?>
                </span>
            <?php else: ?>
                <a class="mlf-crumb" href="<?= htmlspecialchars($linkTo($crumb['path'])) ?>">
                    <?= htmlspecialchars($crumb['name']) ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <?php if (!$students): ?>
        <div class="mlf-empty">
            <i class="fas fa-folder-open"></i>
            <p class="mlf-empty-title">
                <?= $anyFilterActive ? 'No folders match these filters' : 'There are no students on file yet' ?>
            </p>
            <p>
                <?php if ($anyFilterActive): ?>
                    <a href="<?= htmlspecialchars($linkTo('')) ?>">Clear the filters</a> to see the whole drive.
                <?php else: ?>
                    Folders appear as soon as students are added to the
                    <a href="students.php">student roster</a>.
                <?php endif; ?>
            </p>
        </div>
    <?php elseif ($current['level'] === 'section'): ?>
        <?php // ── A LEAF: the masterlist this folder holds ── ?><section class="mlf-leaf">
            <div class="mlf-leaf-head">
                <h2>
                    <i class="fas fa-users"></i>
                    Masterlist
                    <span class="mlf-leaf-meta">
                        <?= (int) $current['count'] ?> student<?= (int) $current['count'] === 1 ? '' : 's' ?>
                        &middot; <?= htmlspecialchars((string) ($current['program'] ?? '')) ?>
                        &middot; <?= htmlspecialchars((string) ($current['year'] ?? '')) ?>
                    </span>
                </h2>
                <div class="mlf-leaf-actions">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <a class="btn btn-secondary btn-sm"
                       href="masterlist.php?section=<?= rawurlencode((string) ($current['section'] ?? '')) ?>">
                        <i class="fas fa-table-list"></i> Open in flat masterlist
                    </a>
                </div>
            </div>

            <table class="mlf-roster">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student No.</th>
                        <th>Name</th>
                        <th>Program</th>
                        <th>Year</th>
                        <th>Section</th>
                        <th>School Year</th>
                        <th>Semester</th>
                        <th>Status</th>
                        <th>Contact</th>
                        <th>Email</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($current['students'] as $i => $row): ?>
                        <?php
                        // N/A, never blank, for every missing value —
                        // the same convention the printed masterlist
                        // and every document template uses, so a cell
                        // reads "no data on file" rather than "we
                        // forgot".
                        $full = trim((string) ($row['last_name'] ?? '')) . ', '
                              . trim((string) ($row['first_name'] ?? '')) . ' '
                              . trim((string) ($row['middle_name'] ?? ''));
                        $cell = static fn($v): string => htmlspecialchars(trim((string) ($v ?? '')) ?: 'N/A');
                        ?>
                        <tr>
                            <td class="mlf-num"><?= $i + 1 ?></td>
                            <td class="mlf-tok"><?= $cell($row['student_number'] ?? '') ?></td>
                            <td><?= htmlspecialchars(trim($full) !== '' ? trim($full) : 'N/A') ?></td>
                            <td><?= $cell($row['course'] ?? '') ?></td>
                            <td class="mlf-num"><?= $cell($row['year_level'] ?? '') ?></td>
                            <td class="mlf-tok"><?= $cell($row['section'] ?? '') ?></td>
                            <td class="mlf-tok"><?= $cell($row['school_year'] ?? '') ?></td>
                            <td><?= $cell($row['semester'] ?? '') ?></td>
                            <td><?= $cell($row['status'] ?? '') ?></td>
                            <td class="mlf-tok"><?= $cell($row['contact_number'] ?? '') ?></td>
                            <td class="mlf-email"><?= $cell($row['email'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section><?php elseif (!$current['folders']): ?>
        <div class="mlf-empty">
            <i class="fas fa-folder-open"></i>
            <p class="mlf-empty-title">This folder is empty</p>
            <p>No student currently files under it.
               <a href="<?= htmlspecialchars($linkTo('')) ?>">Back to the top level</a>.</p>
        </div>
    <?php else: ?>
        <?php // ── A FOLDER: the sub-folders inside it ─────────── ?>
        <p class="mlf-summary">
            <?php if ($current['level'] === 'root'): ?>
                <strong><?= count($current['folders']) ?></strong> program folder<?= count($current['folders']) === 1 ? '' : 's' ?>,
                holding <strong><?= (int) $scopeCount ?></strong> student<?= (int) $scopeCount === 1 ? '' : 's' ?>.
                Open one to see its year folders.
            <?php else: ?>
                <strong><?= count($current['folders']) ?></strong> section folder<?= count($current['folders']) === 1 ? '' : 's' ?>
                in <?= htmlspecialchars($current['name']) ?>, holding
                <strong><?= (int) $scopeCount ?></strong> student<?= (int) $scopeCount === 1 ? '' : 's' ?>.
                Open one to see the masterlist it holds.
            <?php endif; ?>
        </p>

        <div class="mlf-grid">
            <?php foreach ($current['folders'] as $folder): ?>
                <?php
                // The one place the "unplaced" state changes how a
                // folder looks. Amber, and the wording says why — an
                // empty section and a cohort nobody has placed yet
                // must never look alike.
                $icon = $folder['kind'] === 'program' ? 'fa-building-columns'
                      : ($folder['kind'] === 'year' ? 'fa-calendar' : 'fa-users');
                ?>
                <a class="mlf-tile<?= $folder['unassigned'] ? ' is-unassigned' : '' ?>"
                   href="<?= htmlspecialchars($linkTo($folder['path'])) ?>">
                    <i class="fas <?= $icon ?> mlf-tile-icon"></i>
                    <span class="mlf-tile-name"><?= htmlspecialchars($folder['name']) ?></span>
                    <span class="mlf-tile-meta">
                        <?= (int) $folder['count'] ?> student<?= (int) $folder['count'] === 1 ? '' : 's' ?>
                        <?php if ($folder['subfolders'] > 0): ?>
                            &middot; <?= (int) $folder['subfolders'] ?> subfolder<?= $folder['subfolders'] === 1 ? '' : 's' ?>
                        <?php endif; ?>
                    </span>
                    <?php if ($folder['unassigned']): ?>
                        <span class="mlf-tile-tag">not yet placed</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main><style>
/* The drive look. Two things earn their keep here: the breadcrumb
   bar, which is what makes "where am I" answerable at a glance, and
   the folder tiles, which are the click targets. Everything else is
   deliberately quiet so the folders read first. */
.mlf-header { display:flex; justify-content:space-between; align-items:flex-start; gap:20px; flex-wrap:wrap; margin-bottom:18px; }
.mlf-kicker { font-size:11px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#64748b; margin-bottom:6px; }
.mlf-header h1 { font-size:28px; font-weight:800; color:#0f172a; margin:0 0 6px; letter-spacing:-.02em; }
.mlf-header p { margin:0; font-size:14px; color:#475569; max-width:70ch; line-height:1.6; }
.mlf-header-actions { display:flex; gap:8px; flex-wrap:wrap; }

/* ── The List / Folders view toggle ─────────────────────────
     Defined here and repeated on the flat Masterlist page, because
     each page carries its own stylesheet and a toggle that looked
     different on the two views would read as two different
     features rather than two ways of looking at one.

     It is a pair of links, not tabs with hidden panels: both views
     are real pages with their own URLs, so Back works and a view
     can be linked to. */
.mlv-btn, .masterlist-views .mlv-btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:8px 14px; border:1px solid #e2e8f0; border-radius:9px;
    background:#fff; color:#475569; font-size:13px; font-weight:600;
    text-decoration:none; white-space:nowrap;
}
.mlv-btn:hover { border-color:#93c5fd; color:#1d4ed8; background:#eff6ff; }
.mlv-btn.is-active { background:#1a3a8c; border-color:#1a3a8c; color:#fff; }
.mlv-btn.is-active:hover { background:#1a3a8c; color:#fff; }
.mlf-views { display:flex; gap:6px; align-items:center; }

.mlf-banner { display:flex; gap:12px; align-items:flex-start; padding:13px 16px; border-radius:12px; margin-bottom:14px; font-size:13.5px; line-height:1.6; border:1px solid; }
.mlf-banner i { margin-top:2px; }
.mlf-banner a { font-weight:600; }
.mlf-banner-warn { background:#fffbeb; border-color:#fde68a; color:#78350f; }
.mlf-banner-info { background:#eff6ff; border-color:#bfdbfe; color:#1e3a8a; }

.mlf-filters { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; padding:14px 16px; background:#fff; border:1px solid #e2e8f0; border-radius:12px; margin-bottom:14px; }
.mlf-field { display:flex; flex-direction:column; gap:5px; min-width:140px; }
.mlf-field > span { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#64748b; }
.mlf-apply { margin-left:auto; }

/* Breadcrumbs. The root is always present and always clickable, so
   there is no depth at which a reader is stuck. */
.mlf-crumbs { display:flex; align-items:center; gap:6px; flex-wrap:wrap; padding:9px 14px; background:#fff; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:16px; font-size:13.5px; }
.mlf-crumb { color:#2563eb; text-decoration:none; font-weight:600; padding:2px 4px; border-radius:5px; }
.mlf-crumb:hover { background:#eff6ff; }
.mlf-crumb.is-here { color:#0f172a; font-weight:700; cursor:default; }
.mlf-crumb.is-here:hover { background:none; }
.mlf-crumb-sep { color:#cbd5e1; font-weight:700; }

.mlf-summary { font-size:13.5px; color:#475569; margin:0 0 14px; }
.mlf-summary strong { color:#0f172a; }

/* Folder tiles — the click targets. */
.mlf-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:12px; }
.mlf-tile { display:flex; flex-direction:column; gap:5px; padding:16px; background:#fff; border:1px solid #e2e8f0; border-radius:12px; text-decoration:none; transition:border-color .12s, box-shadow .12s, transform .12s; }
.mlf-tile:hover { border-color:#93c5fd; box-shadow:0 4px 14px rgba(37,99,235,.10); transform:translateY(-1px); }
.mlf-tile-icon { font-size:24px; color:#2563eb; margin-bottom:2px; }
.mlf-tile-name { font-size:14.5px; font-weight:700; color:#1e293b; line-height:1.35; word-break:break-word; }
.mlf-tile-meta { font-size:12px; color:#64748b; font-variant-numeric:tabular-nums; }
.mlf-tile-tag { align-self:flex-start; font-size:11px; font-weight:700; color:#92400e; background:#fef3c7; border-radius:99px; padding:2px 9px; }
.mlf-tile.is-unassigned { border-color:#fcd34d; background:#fffdf5; }
.mlf-tile.is-unassigned .mlf-tile-icon { color:#d97706; }

/* A section leaf: the generated masterlist. */
.mlf-leaf { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; box-shadow:0 1px 2px rgba(15,23,42,.05); }
.mlf-leaf-head { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; padding:14px 18px; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
.mlf-leaf-head h2 { font-size:15px; font-weight:700; color:#0f172a; margin:0; display:flex; align-items:center; gap:8px; }
.mlf-leaf-meta { font-size:12px; font-weight:600; color:#64748b; }
.mlf-leaf-actions { display:flex; gap:8px; }

.mlf-roster { width:100%; border-collapse:collapse; font-size:13px; }
.mlf-roster th { background:#fbfcfe; color:#475569; font-size:11px; text-transform:uppercase; letter-spacing:.05em; text-align:left; padding:8px 14px; border-bottom:1px solid #e2e8f0; font-weight:700; white-space:nowrap; }
.mlf-roster td { padding:8px 14px; border-bottom:1px solid #f1f5f9; color:#334155; }
.mlf-roster tr:last-child td { border-bottom:none; }
.mlf-tok { font-variant-numeric:tabular-nums; white-space:nowrap; }
.mlf-num { text-align:right; font-variant-numeric:tabular-nums; color:#94a3b8; }
.mlf-email { color:#475569; word-break:break-word; }

.mlf-empty { text-align:center; padding:52px 24px; background:#fff; border:1px solid #e2e8f0; border-radius:14px; color:#64748b; }
.mlf-empty i { font-size:38px; color:#e2e8f0; display:block; margin-bottom:12px; }
.mlf-empty-title { font-size:15px; font-weight:700; color:#334155; margin:0 0 6px; }

@media(max-width:720px){
  .mlf-header{flex-direction:column}
  .mlf-field{min-width:100%}
  .mlf-apply{margin-left:0;width:100%;justify-content:center}
  .mlf-grid{grid-template-columns:1fr 1fr}
  .mlf-roster{font-size:12px}
  .mlf-roster th,.mlf-roster td{padding:6px 10px}
}
@media print{
  /* Printing a folder should print its masterlist, not the
     navigation that got you there. */
  .mlf-filters,.mlf-header-actions,.mlf-crumbs,.mlf-leaf-actions,.mlf-banner{display:none!important}
  .mlf-leaf{box-shadow:none;border:none}
  .mlf-roster tr{break-inside:avoid;page-break-inside:avoid}
}
</style>

<?php include '../includes/footer.php'; ?>