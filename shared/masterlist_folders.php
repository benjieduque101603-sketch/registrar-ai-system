<?php
// ============================================================
//  SHARED/MASTERLIST_FOLDERS.PHP
//  The folder shape of a masterlist: Program / Year / Section,
//  and the navigation that walks it one folder at a time.
//
//  Why folders at all
//  -----------------
//  A masterlist is not one list. It is a set of lists that
//  different offices need for different reasons, and until now
//  the only shape available was "filter the page and export",
//  which means every destination re-implements the same grouping
//  in a spreadsheet by hand.
//
//  A folder tree is the shape all three actually want: one folder
//  per program, one per year level inside it, one per section
//  inside that, and the roster inside the section folder. It is
//  also the shape a shared drive already has, so handing the same
//  tree to another system is the same operation as downloading it.
//
//  IT IS A BROWSER, NOT AN OUTLINE
//  -------------------------------
//  The page navigates ONE FOLDER AT A TIME, the way a shared
//  drive does: you are either at the root looking at program
//  folders, or inside BSIT looking at year folders, or inside
//  BSIT/Year 1 looking at section folders, or inside a section
//  looking at the masterlist that folder holds. Clicking a folder
//  goes INTO it.
//
//  The earlier version rendered every folder expanded on one long
//  page. That is the wrong shape for two reasons that are easy to
//  get wrong. It does not scale: a college with eight programs and
//  four years each produces hundreds of tables nobody will scroll
//  through. And it cannot answer the question the office actually
//  asks, which is "show me BSIT", not "show me everything, I will
//  find BSIT in the middle of it".
//
//  Four rules, and they are the whole design
//  ------------------------------------------
//  1. A folder is a GROUPING, never a copy. The folders are a
//     projection of `students`, not a second copy of it.
//     Creating a folder cannot invent students, and moving a
//     student between folders is the section assignment that
//     already exists on the Masterlist page.
//
//  2. The path is DERIVED from the student row, never stored.
//     A stored path is a second source of truth that disagrees
//     with `students.section` the first time somebody edits a
//     section, and a masterlist that files a student under a
//     folder they are not in is worse than no folder at all.
//
//  3. An unplaced student still appears, under an explicit
//     "Unassigned" folder, never silently dropped. The Masterlist
//     page hides unplaced rows because a signed section sheet
//     needs every row to be signable; a folder tree is an
//     inventory, not a signed sheet, and the registrar needs to
//     see the work that is left. Hiding them is how a cohort
//     goes missing.
//
//  4. The URL IS THE LOCATION. `?path=BSIT/Year 1/11001` is a
//     real address, so a folder can be bookmarked, linked into an
//     email to a department, and reached by Back. A tree that
//     only exists in a JS variable has none of those.
//
//  Pure functions. The database stays in the page and the API,
//  so this file is unit tested without one — the grouping rules
//  are the part worth pinning.
// ============================================================

if (defined('MASTERLIST_FOLDERS_LOADED')) {
    return;
}
define('MASTERLIST_FOLDERS_LOADED', true);

/** The folder name a student with no program of record lands in. */
if (!defined('MLF_NO_PROGRAM')) {
    define('MLF_NO_PROGRAM', 'Unassigned Program');
}
/** The folder name for a missing year level. */
if (!defined('MLF_NO_YEAR')) {
    define('MLF_NO_YEAR', 'Unassigned Year');
}
/** The folder name for a missing section code. */
if (!defined('MLF_NO_SECTION')) {
    define('MLF_NO_SECTION', 'Unassigned Section');
}
/**
 * Strip the characters that cannot survive a folder name.
 *
 * A program name is free text typed by a clerk ("BSIT - Computer
 * Science", "BSIT/Networking", "BSIT \\ Night"). Windows forbids
 * \ / : * ? " < > | and every platform agrees on the first six.
 * Left alone, one of those characters makes the whole archive
 * un-extractable on the machine that receives it, so they are
 * removed where the name is built.
 *
 * Unicode is KEPT. A program may be named in Filipino or another
 * non-Latin script, and transliterating it to "BSIT?" would be a
 * worse answer than passing the bytes through — the ZIP writer
 * sets the UTF-8 flag for exactly this.
 *
 * Collapsing to the fallback when nothing survives is deliberate:
 * an empty folder name is not a folder, and two different blank
 * names silently merging into one folder is how two cohorts get
 * merged on disk.
 */
function mlf_safe_segment(string $raw, string $fallback): string
{
    $s = str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], ' ', $raw);
    $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = trim($s);
    // A trailing dot or space is silently stripped by Windows,
    // which turns "BSIT." and "BSIT" into the same folder.
    $s = rtrim($s, ". ");

    if ($s === '') {
        return $fallback;
    }
    if (mb_strlen($s) > 80) {
        // Long enough for any real program name, short enough that
        // the deepest path stays inside the 260-character limit
        // Windows still applies to an extracted tree.
        $s = rtrim(mb_substr($s, 0, 80), ". ");
        if ($s === '') {
            return $fallback;
        }
    }
    return $s;
}

/**
 * The three folder segments for one student row, in order.
 *
 * @return array{0:string,1:string,2:string} program, year, section
 */
function mlf_path_segments(array $student): array
{
    $program = mlf_safe_segment(trim((string) ($student['course'] ?? '')), MLF_NO_PROGRAM);

    $year = trim((string) ($student['year_level'] ?? ''));
    $year = ($year !== '' && is_numeric($year) && (int) $year >= 1)
        ? 'Year ' . (int) $year
        : MLF_NO_YEAR;

    // The section code is the folder's identity and stays EXACTLY
    // as stored — 11001 must read "11001", never "Section 11001",
    // because this folder is named in emails to a department and
    // re-typed by a human into the receiving system.
    $section = trim((string) ($student['section'] ?? ''));
    $section = $section !== '' ? $section : MLF_NO_SECTION;

    return [$program, $year, $section];
}

/** The folder path of one student row, as "A/B/C". */
function mlf_path(array $student): string
{
    return implode('/', mlf_path_segments($student));
}

/**
 * Group student rows into the folder tree.
 *
 * @param array $students Rows from `students`, already filtered.
 * @return array<string, array> Program name => ['name', 'count',
 *         'years' => [yearName => ['name','count','sections' => [...]]]]
 *         Sections carry their own rows so a leaf can be rendered,
 *         exported or printed without a second query.
 */
function mlf_build_tree(array $students): array
{
    $tree = [];

    foreach ($students as $student) {
        [$program, $year, $section] = mlf_path_segments((array) $student);

        if (!isset($tree[$program])) {
            $tree[$program] = ['name' => $program, 'count' => 0, 'years' => []];
        }
        $tree[$program]['count']++;

        if (!isset($tree[$program]['years'][$year])) {
            $tree[$program]['years'][$year] = ['name' => $year, 'count' => 0, 'sections' => []];
        }
        $tree[$program]['years'][$year]['count']++;

        if (!isset($tree[$program]['years'][$year]['sections'][$section])) {
            $tree[$program]['years'][$year]['sections'][$section] = [
                'name'     => $section,
                'path'     => $program . '/' . $year . '/' . $section,
                'count'    => 0,
                'students' => [],
            ];
        }
        $tree[$program]['years'][$year]['sections'][$section]['count']++;
        $tree[$program]['years'][$year]['sections'][$section]['students'][] = $student;
    }

    // Sort every level so the tree reads the same way twice. The
    // "Unassigned" folders sort last regardless of their names:
    // they are the work queue, not part of the cohort order, and
    // "Unassigned Section" sitting between 11001 and 11002 is
    // noise. Sorting by name alone cannot express that, so the key
    // is partitioned first and only the real folders are sorted
    // among themselves.
    $orderKeys = static function (array $keys): array {
        $isMissing = static fn($k) => strpos((string) $k, 'Unassigned') === 0;
        $real = array_values(array_filter($keys, static fn($k) => !$isMissing($k)));
        $rest = array_values(array_filter($keys, $isMissing));
        return array_merge($real, $rest);
    };
    $reorder = static function (array $map) use ($orderKeys): array {
        $out = [];
        foreach ($orderKeys(array_keys($map)) as $k) {
            $out[$k] = $map[$k];
        }
        return $out;
    };

    foreach ($tree as &$programNode) {
        ksort($programNode['years']);
        foreach ($programNode['years'] as &$yearNode) {
            ksort($yearNode['sections']);
            // Sections need the same Unassigned-last treatment as
            // the levels above them, not just a name sort: a plain
            // ksort puts "Unassigned Section" between 11001 and
            // 11002, where it reads as a section that exists.
            $yearNode['sections'] = $reorder($yearNode['sections']);
            foreach ($yearNode['sections'] as &$sectionNode) {
                // Inside a section, one student's rows must land
                // together — the same rule the Masterlist page
                // sorts by, so both surfaces print one order.
                usort($sectionNode['students'], static function ($a, $b) {
                    $an = trim((string) ($a['last_name'] ?? '')) . ', ' . trim((string) ($a['first_name'] ?? ''));
                    $bn = trim((string) ($b['last_name'] ?? '')) . ', ' . trim((string) ($b['first_name'] ?? ''));
                    return strcasecmp($an, $bn);
                });
            }
            unset($sectionNode);
        }
        $programNode['years'] = $reorder($programNode['years']);
        unset($yearNode);
    }
    unset($programNode);

    ksort($tree);
    return $reorder($tree);
}
    /**
 * Walk to one folder and describe what is inside it.
 *
 * This is the whole of the navigation. The page is a browser, not
 * an outline: it is always sitting at exactly one folder, and this
 * returns that folder's breadcrumbs, its sub-folders, and — if it
 * is a leaf — the roster it holds.
 *
 * $path is '' for the root. It is MATCHED against folders that
 * already exist, never used to build a query: a crafted path
 * cannot reach rows the caller could not already see, because no
 * SQL is built from it. A path that names no folder falls back to
 * the root rather than erroring, so a stale bookmark lands the
 * reader somewhere real instead of a blank page.
 *
 * @return array{path,name,level,exists,breadcrumbs,folders,students,count,parent}
 */
function mlf_resolve(array $tree, string $path): array
{
    $path = trim(str_replace('\\', '/', $path), '/');

    $segments = $path === '' ? [] : explode('/', $path);

    $root = [
        'path'        => '',
        'name'        => 'Masterlist Folders',
        'level'       => 'root',
        'exists'      => true,
        'breadcrumbs' => [],
        'folders'     => [],
        'students'    => [],
        'count'       => 0,
        'parent'      => '',
    ];

    // Too deep to exist: the tree is exactly three levels. The
    // reader still lands on the root, but `exists` stays FALSE so
    // the page can say "no such folder" — a URL that named
    // something specific and silently showed the top level would
    // look exactly like the link having worked.
    if (count($segments) > 3) {
        $segments = [];
        $root['exists'] = false;
        return $root;
    }

    // ── ROOT: the program folders ────────────────────────
    if (count($segments) === 0) {
        foreach ($tree as $program => $node) {
            $root['folders'][] = [
                'name'   => (string) $program,
                'path'   => (string) $program,
                'count'  => (int) $node['count'],
                'kind'   => 'program',
                // How many year folders sit inside — what a drive
                // shows next to a folder, and what tells the reader
                // whether opening it is worth the click.
                'subfolders' => count($node['years']),
                'unassigned' => strpos((string) $program, 'Unassigned') === 0,
            ];
        }
        return $root;
    }

    $program = $segments[0];
    if (!isset($tree[$program])) {
        $root['exists'] = false;
        return $root;
    }
    $programNode = $tree[$program];

    // ── A PROGRAM: the year folders inside it ─────────────
    if (count($segments) === 1) {
        $node = [
            'path'        => $program,
            'name'        => (string) $program,
            'level'       => 'program',
            'exists'      => true,
            'breadcrumbs' => [['name' => (string) $program, 'path' => $program]],
            'folders'     => [],
            'students'    => [],
            'count'       => (int) $programNode['count'],
            'parent'      => '',
        ];
        foreach ($programNode['years'] as $year => $yearNode) {
            $node['folders'][] = [
                'name'       => (string) $year,
                'path'       => $program . '/' . $year,
                'count'      => (int) $yearNode['count'],
                'kind'       => 'year',
                'subfolders' => count($yearNode['sections']),
                'unassigned' => strpos((string) $year, 'Unassigned') === 0,
            ];
        }
        return $node;
    }

    $year = $segments[1];
    if (!isset($programNode['years'][$year])) {
        $root['exists'] = false;
        return $root;
    }
    $yearNode = $programNode['years'][$year];

    // ── A YEAR: the section folders inside it ─────────────
    if (count($segments) === 2) {
        $node = [
            'path'        => $program . '/' . $year,
            'name'        => (string) $year,
            'level'       => 'year',
            'exists'      => true,
            'breadcrumbs' => [
                ['name' => (string) $program, 'path' => $program],
                ['name' => (string) $year, 'path' => $program . '/' . $year],
            ],
            'folders'     => [],
            'students'    => [],
            'count'       => (int) $yearNode['count'],
            'parent'      => $program,
        ];
        foreach ($yearNode['sections'] as $section => $sectionNode) {
            $node['folders'][] = [
                'name'       => (string) $section,
                'path'       => $program . '/' . $year . '/' . $section,
                'count'      => (int) $sectionNode['count'],
                'kind'       => 'section',
                // A section is a leaf: what it holds is the
                // masterlist, not more folders.
                'subfolders' => 0,
                'unassigned' => strpos((string) $section, 'Unassigned') === 0,
            ];
        }
        return $node;
    }

    // ── A SECTION: the roster this folder holds ───────────
    $section = $segments[2];
    if (!isset($yearNode['sections'][$section])) {
        $root['exists'] = false;
        return $root;
    }
    $sectionNode = $yearNode['sections'][$section];

    return [
        'path'        => $program . '/' . $year . '/' . $section,
        'name'        => (string) $section,
        'level'       => 'section',
        'exists'      => true,
        'breadcrumbs' => [
            ['name' => (string) $program, 'path' => $program],
            ['name' => (string) $year, 'path' => $program . '/' . $year],
            ['name' => (string) $section, 'path' => $program . '/' . $year . '/' . $section],
        ],
        'folders'     => [],
        'students'    => $sectionNode['students'],
        'count'       => (int) $sectionNode['count'],
        'parent'      => $program . '/' . $year,
        'program'     => (string) $program,
        'year'        => (string) $year,
        'section'     => (string) $section,
    ];
}

/**
 * The section folders beneath a path, for the ZIP writer.
 *
 * The export of "the BSIT folder" must contain BSIT's WHOLE
 * subtree, not the one level the reader happens to be standing in.
 * A download that silently omitted Year 2 because the reader was
 * looking at Year 1 would be worse than no download at all, so the
 * export is resolved by prefix rather than by "whatever is on
 * screen".
 *
 * @return array<int, array{path:string,program:string,year:string,
 *         section:string,count:int,students:array}>
 */
function mlf_sections_under(array $tree, string $path): array
{
    $path  = trim(str_replace('\\', '/', $path), '/');
    $out   = [];
    $parts = $path === '' ? [] : explode('/', $path);

    foreach ($tree as $program => $programNode) {
        if ($parts && (string) $program !== $parts[0]) {
            continue;
        }
        foreach ($programNode['years'] as $year => $yearNode) {
            if (count($parts) > 1 && (string) $year !== $parts[1]) {
                continue;
            }
            foreach ($yearNode['sections'] as $section => $sectionNode) {
                if (count($parts) > 2 && (string) $section !== $parts[2]) {
                    continue;
                }
                $out[] = [
                    'path'     => $program . '/' . $year . '/' . $section,
                    'program'  => (string) $program,
                    'year'     => (string) $year,
                    'section'  => (string) $section,
                    'count'    => (int) $sectionNode['count'],
                    'students' => $sectionNode['students'],
                ];
            }
        }
    }

    return $out;
}

/**
 * Flatten the tree into the ordered rows one table needs.
 *
 * The page renders a SINGLE table in which a folder is a row you
 * expand, rather than a grid of tiles you navigate between. That
 * shape needs every node in document order with its parent named,
 * including the students sitting inside a section — so this is the
 * one place that decision is made, and it is pure, so it is tested
 * without a browser.
 *
 * Each row is:
 *   type      'folder' | 'student'
 *   level     0|1|2 for a program|year|section, 'section' for a student
 *   name      the folder name, or the student's name
 *   path      the folder path (students carry their section's path)
 *   parent    the folder path this row sits inside ('' at the root)
 *   count     students directly in this folder
 *   subfolders how many child folders
 *   unassigned whether this is one of the "Unassigned" work-queue folders
 *   student   the row itself, for a student line
 *
 * $focusPath is the folder to open on arrival. Its ANCESTORS are
 * marked open too, because a deep link to a section that renders a
 * collapsed tree has shown the reader nothing at all — the link
 * they were sent names a folder, so the folder must be visible.
 *
 * @return array<int, array<string,mixed>>
 */
function mlf_rows(array $tree, string $focusPath = ''): array
{
    $focus  = trim(str_replace('\\', '/', $focusPath), '/');
    $open   = [];
    if ($focus !== '') {
        // Every ancestor of the focus, plus the focus itself.
        $parts = explode('/', $focus);
        $walk  = '';
        foreach ($parts as $part) {
            $walk = $walk === '' ? $part : $walk . '/' . $part;
            $open[$walk] = true;
        }
    }

    $rows   = [];
    $isOpen = static fn(string $path): bool => isset($open[$path]);

    foreach ($tree as $program => $programNode) {
        $programPath = (string) $program;

        $rows[] = [
            'type' => 'folder', 'level' => 0, 'name' => $programPath,
            'path' => $programPath, 'parent' => '',
            'count' => (int) $programNode['count'],
            'subfolders' => count($programNode['years']),
            'unassigned' => strpos($programPath, 'Unassigned') === 0,
        ];

        foreach ($programNode['years'] as $year => $yearNode) {
            $yearPath = $programPath . '/' . $year;

            $rows[] = [
                'type' => 'folder', 'level' => 1, 'name' => (string) $year,
                'path' => $yearPath, 'parent' => $programPath,
                'count' => (int) $yearNode['count'],
                'subfolders' => count($yearNode['sections']),
                'unassigned' => strpos((string) $year, 'Unassigned') === 0,
            ];

            foreach ($yearNode['sections'] as $section => $sectionNode) {
                $sectionPath = $yearPath . '/' . $section;

                $rows[] = [
                    'type' => 'folder', 'level' => 2, 'name' => (string) $section,
                    'path' => $sectionPath, 'parent' => $yearPath,
                    'count' => (int) $sectionNode['count'],
                    'subfolders' => 0,
                    'unassigned' => strpos((string) $section, 'Unassigned') === 0,
                ];

                foreach ($sectionNode['students'] as $student) {
                    $s = (array) $student;
                    $rows[] = [
                        'type' => 'student', 'level' => 'section',
                        'name' => trim((string) ($s['last_name'] ?? '')) . ', '
                               . trim((string) ($s['first_name'] ?? '')) . ' '
                               . trim((string) ($s['middle_name'] ?? '')),
                        'path' => $sectionPath, 'parent' => $sectionPath,
                        'count' => 0, 'subfolders' => 0, 'unassigned' => false,
                        'student' => $student,
                    ];
                }
            }
        }
    }

    return $rows;
}

/**
 * Whether a row is visible given the folders currently open.
 *
 * A row is visible when every folder between it and the root is
 * open. A program row (level 0) has no parent, so it is always
 * visible; a student row needs its own section AND that section's
 * year AND that year's program to be open.
 *
 * @param array $rows  Rows from mlf_rows().
 * @param array $open  Paths of the open folders.
 */
function mlf_row_visible(array $row, array $open): bool
{
    if ($row['type'] === 'folder' && $row['level'] === 0) {
        return true;
    }
    $parent = (string) $row['parent'];
    if ($parent === '') {
        return true;
    }
    return isset($open[$parent]);
}

/**
 * Every distinct program, year level and section on file — the
 * filter pickers.
 *
 * A tree built from students can only show folders that hold
 * students. The picker needs the opposite: everything ever
 * recorded, so a registrar looking for the cohort they know exists
 * finds it EMPTY rather than finds it absent.
 *
 * @return array{programs:array,years:array,sections:array}
 */
function mlf_known_dimensions(array $students): array
{
    $programs = [];
    $years    = [];
    $sections = [];

    foreach ($students as $student) {
        $s = (array) $student;
        $programs[mlf_safe_segment(trim((string) ($s['course'] ?? '')), MLF_NO_PROGRAM)] = true;

        $y = trim((string) ($s['year_level'] ?? ''));
        if ($y !== '' && is_numeric($y) && (int) $y >= 1) {
            $years[(int) $y] = true;
        }

        $sec = trim((string) ($s['section'] ?? ''));
        if ($sec !== '') {
            $sections[$sec] = true;
        }
    }

    // PHP silently turns a numeric array key into an int, so
    // $sections['11001'] comes back as int 11001. That is fine for
    // de-duplicating but not for a <select> value or a JSON
    // payload, where "11001" and 11001 are different to the reader,
    // so the lists are cast back to strings here once rather than
    // at every call site.
    $programs = array_map('strval', array_keys($programs));
    sort($programs, SORT_NATURAL | SORT_FLAG_CASE);
    $years = array_map('strval', array_keys($years));
    sort($years, SORT_NUMERIC);
    $sections = array_map('strval', array_keys($sections));
    sort($sections, SORT_NATURAL);

    return ['programs' => $programs, 'years' => $years, 'sections' => $sections];
}

/**
 * The columns a roster file carries.
 *
 * Chosen to be the set another system can key on AND a human can
 * read off a printed sheet. `Section` is here because it is the
 * folder's own name: a roster that arrives without the code
 * cannot be filed back anywhere.
 *
 * Deliberately absent: the adviser (Faculty names it, per
 * DEPARTMENTS.md) and the RFID card UID (a registrar-system
 * internal, meaningless to a receiving system).
 */
function mlf_roster_columns(): array
{
    return [
        'Student No.',
        'Last Name',
        'First Name',
        'Middle Name',
        'Suffix',
        'Program',
        'Year Level',
        'Section',
        'School Year',
        'Semester',
        'Gender',
        'Status',
        'Contact No.',
        'Email',
    ];
}

/** One roster row, in mlf_roster_columns() order. */
function mlf_roster_row(array $student): array
{
    $s = (array) $student;
    return [
        trim((string) ($s['student_number'] ?? '')),
        trim((string) ($s['last_name'] ?? '')),
        trim((string) ($s['first_name'] ?? '')),
        trim((string) ($s['middle_name'] ?? '')),
        trim((string) ($s['name_suffix'] ?? '')),
        trim((string) ($s['course'] ?? '')),
        trim((string) ($s['year_level'] ?? '')),
        trim((string) ($s['section'] ?? '')),
        trim((string) ($s['school_year'] ?? '')),
        trim((string) ($s['semester'] ?? '')),
        trim((string) ($s['gender'] ?? '')),
        trim((string) ($s['status'] ?? '')),
        trim((string) ($s['contact_number'] ?? '')),
        trim((string) ($s['email'] ?? '')),
    ];
}

/** The roster filename inside a section folder. */
function mlf_section_filename(string $section): string
{
    // The code is the name, and a code is already filename-safe by
    // construction (digits only). Sanitising anyway costs nothing
    // and covers the one case the code format does not: a section
    // typed by hand into the Edit Section modal.
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($section)) ?? '';
    $safe = trim($safe, '-.');
    return ($safe !== '' ? $safe : MLF_NO_SECTION) . '-roster.csv';
}

/** One correctly-quoted CSV record, terminator included. */
function mlf_csv_line(array $fields): string
{
    $out = [];
    foreach ($fields as $field) {
        $f = (string) $field;
        // Quote when the value holds a delimiter, a quote or a
        // newline — and always quote the EMPTY string, because an
        // unquoted empty field is indistinguishable from a missing
        // one in every reader, and "no section on file" is a fact
        // this export must not blur into "section omitted".
        if ($f === '' || preg_match('/[",\r\n]/', $f)) {
            $f = '"' . str_replace('"', '""', $f) . '"';
        }
        $out[] = $f;
    }
    return implode(',', $out) . "\r\n";
}

/**
 * Render roster rows as CSV, with a header line.
 *
 * Written by hand rather than with fputcsv() for one reason: the
 * byte-order mark. Every other export in this system writes one,
 * because the recipient is always Excel and Excel reads a UTF-8
 * CSV without a BOM as Latin-1 — which turns every accented
 * Filipino name in the list into mojibake on the other end. A
 * folder that gets sent to another system needs its files to
 * survive that, so it gets one too.
 */
function mlf_roster_csv(array $students): string
{
    $out = "\xEF\xBB\xBF";   // BOM
    $out .= mlf_csv_line(mlf_roster_columns());
    foreach ($students as $student) {
        $out .= mlf_csv_line(mlf_roster_row((array) $student));
    }
    return $out;
}

/**
 * The README that goes at the root of an exported tree.
 *
 * Present because these archives are handed to offices outside the
 * Registrar. A recipient who opens "BSIT/Year 1/11001/…" and finds
 * a roster with no idea where it came from, when it was cut, or
 * how many students the folder was supposed to hold has no way to
 * tell a complete folder from a truncated export. The counts are
 * the point: they are what a person checks against.
 */
function mlf_manifest_text(array $sections, array $meta = [], int $generatedAt = 0): string
{
    $generatedAt = $generatedAt > 0 ? $generatedAt : time();
    $lines = [];
    $lines[] = 'BCP REGISTRAR - MASTERLIST FOLDER EXPORT';
    $lines[] = str_repeat('=', 38);
    $lines[] = '';
    $lines[] = 'Generated: ' . date('F d, Y g:i A', $generatedAt);
    if (!empty($meta['school_year'])) {
        $lines[] = 'School Year: ' . $meta['school_year'];
    }
    if (!empty($meta['semester'])) {
        $lines[] = 'Semester: ' . $meta['semester'];
    }
    if (!empty($meta['prepared_by'])) {
        $lines[] = 'Prepared by: ' . $meta['prepared_by'];
    }
    if (!empty($meta['folder'])) {
        $lines[] = 'Folder: ' . $meta['folder'] . '  (this archive holds that folder and everything under it)';
    }
    $lines[] = '';
    $lines[] = 'Layout: Program / Year Level / Section';
    $lines[] = 'Each section folder holds one CSV roster, one row per student.';
    $lines[] = '';
    $lines[] = str_repeat('-', 38);
    $lines[] = 'CONTENTS';
    $lines[] = str_repeat('-', 38);

    $total = 0;
    foreach ($sections as $folder) {
        $total += (int) $folder['count'];
        $lines[] = sprintf('%-34s %4d student(s)', $folder['path'], (int) $folder['count']);
    }
    $lines[] = '';
    $lines[] = sprintf('Total: %d student(s) across %d section folder(s).', $total, count($sections));
    $lines[] = '';

    $unplaced = 0;
    foreach ($sections as $folder) {
        if ($folder['section'] === MLF_NO_SECTION) {
            $unplaced += (int) $folder['count'];
        }
    }
    if ($unplaced > 0) {
        $lines[] = 'NOTE: ' . $unplaced . ' student(s) are in the ' . MLF_NO_SECTION
            . ' folder because they have not been placed in a';
        $lines[] = '      section yet. They are listed, not dropped - assign them from the';
        $lines[] = '      Masterlist page and re-export before submitting this tree to';
        $lines[] = '      another system.';
        $lines[] = '';
    }

    return implode("\r\n", $lines);
}
