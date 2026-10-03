<?php
// ============================================================
//  API/MASTERLIST-FOLDERS.PHP
//  The folder tree of the masterlist, as JSON or as an archive.
//
//    GET ?action=tree               → the whole tree, as JSON
//    GET ?action=browse&path=A/B/C  → one folder's contents
//    GET ?action=export&path=…      → a .zip of that folder
//    GET ?action=export             → a .zip of the whole tree
//
//  WHY A SEPARATE ENDPOINT, RATHER THAN A MODE ON api/masterlist.php
//  ------------------------------------------------------------------
//  Two reasons, and the second is the one that matters.
//
//  First, the responses are not the same shape: the roster
//  endpoint answers JSON, and this one answers a file download for
//  two of its three actions. Mixing them means the Content-Type is
//  decided twice on different paths and one branch eventually
//  forgets.
//
//  Second, and more important: THE FOLDER TREE LISTS UNPLACED
//  STUDENTS. The roster on api/masterlist.php deliberately does
//  not — it is the list that gets printed, signed and handed off,
//  and a row with no section has no place on it. A folder tree is
//  an inventory, not a signed sheet, and a registrar looking at a
//  folder called "Unassigned Section" needs to see the 14 students
//  in it in order to go and place them.
//
//  Putting both in one file with a filter flag would have made
//  that difference a parameter, and a parameter is a thing that
//  gets set wrong by whoever writes the next caller. Two endpoints
//  make it structural.
//
//  Reads only. Assigning a section is still api/masterlist.php's
//  job, and a folder path is not accepted as a write target: it is
//  derived from `students`, so there is nothing to write to.
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/masterlist_folders.php';
require_once __DIR__ . '/../shared/zip_writer.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
if (!in_array(getCurrentUserRole(), ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

$action = (string) ($_GET['action'] ?? 'tree');

/**
 * Students for the folder tree, honouring the optional filters.
 *
 * @return array<int, array<string,mixed>>
 */
function mlf_load_students(array $filters): array
{
    $db = Database::getInstance();

    $sql = "SELECT id, student_number, first_name, middle_name, last_name,
                   name_suffix, course, year_level, section, school_year,
                   semester, gender, status, contact_number, email
            FROM students";
    $where  = [];
    $params = [];

    if (($filters['program'] ?? '') !== '') {
        // TRIM() on both sides because the stored value and the
        // value in the URL are both human-typed, and "BSIT" must
        // match " BSIT ". The Masterlist page compares the same way.
        $where[]  = 'TRIM(course) = ?';
        $params[] = $filters['program'];
    }
    if (($filters['year'] ?? '') !== '' && is_numeric($filters['year'])) {
        $where[]  = 'year_level = ?';
        $params[] = (int) $filters['year'];
    }
    if (($filters['school_year'] ?? '') !== '') {
        $where[]  = 'school_year = ?';
        $params[] = $filters['school_year'];
    }
    if (($filters['semester'] ?? '') !== '') {
        $where[]  = 'semester = ?';
        $params[] = $filters['semester'];
    }
    if (($filters['section'] ?? '') !== '') {
        $where[]  = 'TRIM(section) = ?';
        $params[] = $filters['section'];
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    // Same ordering as the Masterlist page: program, then year,
    // then section. The tree re-sorts within a section by name
    // (mlf_build_tree), which is the only place the two orders
    // differ, and it is the order the folder names imply.
    $sql .= ' ORDER BY TRIM(course) ASC, COALESCE(year_level, 0) ASC, section ASC, last_name ASC, first_name ASC';

    return $db->fetchAll($sql, $params);
}

/** The filters as they arrived, for echoing back and for the manifest. */
function mlf_request_filters(): array
{
    return [
        'program'     => isset($_GET['program']) ? trim((string) $_GET['program']) : '',
        'year'        => isset($_GET['year_level']) ? trim((string) $_GET['year_level']) : '',
        'school_year' => isset($_GET['school_year']) ? trim((string) $_GET['school_year']) : '',
        'semester'    => isset($_GET['semester']) ? trim((string) $_GET['semester']) : '',
        'section'     => isset($_GET['section']) ? trim((string) $_GET['section']) : '',
    ];
}try {
    $filters  = mlf_request_filters();
    $students = mlf_load_students($filters);
    $tree     = mlf_build_tree($students);
    $path     = isset($_GET['path']) ? trim((string) $_GET['path']) : '';

    // ─── TREE ─────────────────────────────────────────────
    if ($action === 'tree') {
        echo json_encode([
            'success'    => true,
            'filters'    => $filters,
            'tree'       => $tree,
            'dimensions' => mlf_known_dimensions($students),
            'total'      => count($students),
        ]);
        exit;
    }

    // ─── BROWSE ONE FOLDER ────────────────────────────────
    //
    // The same resolver the page uses, so a folder means the same
    // thing in JSON as it does on screen. A path that names no
    // folder reports found:false rather than inventing one.
    if ($action === 'browse') {
        $node = mlf_resolve($tree, $path);

        if (!$node['exists'] && $path !== '') {
            echo json_encode([
                'success' => true,
                'path'    => $path,
                'found'   => false,
                'message' => 'No such folder in the current filter.',
            ]);
            exit;
        }

        echo json_encode(['success' => true] + $node);
        exit;
    }

    // ─── EXPORT ───────────────────────────────────────────
    if ($action === 'export') {
        // Resolved by SUBTREE, not by "the one level being
        // viewed". Downloading BSIT must give the whole of BSIT:
        // an archive that silently omitted Year 2 because the
        // reader was standing in Year 1 would be worse than none.
        $scope = mlf_sections_under($tree, $path);

        if (!$scope) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'That folder holds no students. Nothing to export.',
            ]);
            exit;
        }

        $now  = time();
        $meta = [
            'school_year' => $filters['school_year'],
            'semester'    => $filters['semester'],
            'prepared_by' => (string) ($_SESSION['full_name'] ?? getCurrentUserName()),
            'folder'      => $path,
        ];

        $zip = new MlfZipWriter();
        $zip->addFile('README.txt', mlf_manifest_text($scope, $meta, $now), $now);

        foreach ($scope as $folder) {
            $zip->addFile(
                $folder['path'] . '/' . mlf_section_filename($folder['section']),
                mlf_roster_csv($folder['students']),
                $now
            );
        }

        $archive = $zip->finish($now);

        // The download name follows the FOLDER, not the page, so a
        // saved "BSIT-Year-1.zip" says what is inside it even when
        // it turns up in a downloads folder full of
        // "masterlist(3).zip".
        $label    = $path === '' ? 'masterlist-all' : str_replace('/', '-', $path);
        $filename = mlf_zip_filename($label, $now);

        logActivity(
            $_SESSION['user_id'] ?? 0,
            'masterlist_folder_export',
            json_encode([
                'path'     => $path === '' ? '(entire tree)' : $path,
                'folders'  => count($scope),
                'students' => array_sum(array_column($scope, 'count')),
            ]),
            'students'
        );

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($archive));
        header('X-Content-Type-Options: nosniff');
        echo $archive;
        exit;
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Unknown action. Use tree, browse or export.',
    ]);
} catch (Throwable $e) {
    json_error($e, 'Could not build the folder tree.');
}