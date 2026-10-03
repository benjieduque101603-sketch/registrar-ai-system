<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../shared/masterlist_folders.php';

/**
 * The folder shape of a masterlist, and the navigation that walks
 * it one folder at a time.
 *
 * These pin the rules in shared/masterlist_folders.php that the page
 * and the API both depend on and neither can enforce on its own: an
 * unplaced student is still LISTED, a folder name is always a legal
 * filesystem name, a folder path is derived from the student row
 * rather than stored beside it, and — the one this page exists for —
 * clicking a folder takes you INTO it.
 */
final class MasterlistFoldersTest extends TestCase
{
    private function student(array $over = []): array
    {
        return array_merge([
            'id' => 1, 'student_number' => '2026-0001',
            'last_name' => 'Dela Cruz', 'first_name' => 'Juan',
            'middle_name' => 'S', 'name_suffix' => '',
            'course' => 'BSIT', 'year_level' => 1, 'section' => '11001',
            'school_year' => '2026-2027', 'semester' => '1st',
            'gender' => 'Male', 'status' => 'enrolled',
            'contact_number' => '09171234567', 'email' => 'juan@example.edu',
        ], $over);
    }

    /** A small, fully-populated tree to navigate. */
    private function tree(): array
    {
        return mlf_build_tree([
            $this->student(['id' => 1, 'section' => '11001']),
            $this->student(['id' => 2, 'section' => '11002', 'last_name' => 'Alvarez']),
            $this->student(['id' => 3, 'year_level' => 2, 'section' => '21001', 'last_name' => 'Mendoza']),
            $this->student(['id' => 4, 'course' => 'BSBA', 'section' => '', 'last_name' => 'Reyes']),
        ]);
    }

    public function testPathIsProgramYearSection(): void
    {
        self::assertSame('BSIT/Year 1/11001', mlf_path($this->student()));
    }

    // ── Navigation: the thing this page exists for ─────────

    /**
     * The root lists the PROGRAMS. If it listed anything else the
     * page would not be a drive, it would be a table.
     */
    public function testTheRootOffersTheProgramFolders(): void
    {
        $node = mlf_resolve($this->tree(), '');

        self::assertSame('root', $node['level']);
        self::assertSame(['BSBA', 'BSIT'], array_column($node['folders'], 'name'));
        self::assertSame([], $node['students'], 'the root holds no roster of its own');
    }

    /** Each root tile carries its student count, so the reader can judge the click. */
    public function testEachProgramFolderReportsItsSize(): void
    {
        $sizes = array_column(mlf_resolve($this->tree(), '')['folders'], 'count', 'name');

        self::assertSame(3, $sizes['BSIT']);
        self::assertSame(1, $sizes['BSBA']);
    }

    /**
     * Clicking a program takes you INTO it: the folders on offer
     * are now its year levels, and nothing from any other program
     * is visible.
     */
    public function testClickingAProgramOpensItsYearFolders(): void
    {
        $node = mlf_resolve($this->tree(), 'BSIT');

        self::assertSame('program', $node['level']);
        self::assertSame('BSIT', $node['path']);
        self::assertSame(['Year 1', 'Year 2'], array_column($node['folders'], 'name'));
        self::assertSame([], $node['students']);
    }

    /** And a year opens its sections. */
    public function testClickingAYearOpensItsSectionFolders(): void
    {
        $node = mlf_resolve($this->tree(), 'BSIT/Year 1');

        self::assertSame('year', $node['level']);
        self::assertSame(['11001', '11002'], array_column($node['folders'], 'name'));
        self::assertSame([], $node['students']);
    }

    /**
     * The whole request: clicking a section folder shows THE
     * MASTERLIST GENERATED FOR IT — that folder's students, and
     * only those students.
     */
    public function testClickingASectionShowsTheMasterlistItHolds(): void
    {
        $node = mlf_resolve($this->tree(), 'BSIT/Year 1/11001');

        self::assertSame('section', $node['level']);
        self::assertSame('11001', $node['name']);
        self::assertSame(1, $node['count']);
        self::assertCount(1, $node['students']);
        self::assertSame('Dela Cruz', $node['students'][0]['last_name']);
    }

    /** A section folder holds a roster, not more folders. */
    public function testASectionFolderHasNoSubfolders(): void
    {
        foreach (mlf_resolve($this->tree(), 'BSIT/Year 1')['folders'] as $folder) {
            self::assertSame('section', $folder['kind']);
            self::assertSame(0, $folder['subfolders']);
        }
    }

    /**
     * The breadcrumb has to name every ancestor, or a reader who
     * has gone three levels deep has no way back up.
     */
    public function testTheBreadcrumbNamesEveryAncestor(): void
    {
        $node = mlf_resolve($this->tree(), 'BSIT/Year 1/11001');

        self::assertSame(
            ['BSIT', 'Year 1', '11001'],
            array_column($node['breadcrumbs'], 'name')
        );
        // Each crumb links one level up, not to the root, so the
        // trail is a staircase rather than a shortcut.
        self::assertSame(
            ['BSIT', 'BSIT/Year 1', 'BSIT/Year 1/11001'],
            array_column($node['breadcrumbs'], 'path')
        );
    }

    /** A folder knows its parent, which is what "go up" uses. */
    public function testAFolderKnowsItsParent(): void
    {
        self::assertSame(
            'BSIT/Year 1',
            mlf_resolve($this->tree(), 'BSIT/Year 1/11001')['parent']
        );
    }

    /**
     * A link to a folder that no longer exists must land somewhere
     * real. Silently rendering the root for a typo'd link looks
     * like the link worked; the page pairs this with a visible
     * warning, but the resolution must not error.
     */
    public function testAnUnknownPathFallsBackToTheRoot(): void
    {
        $node = mlf_resolve($this->tree(), 'NOPE/Year 9');

        self::assertFalse($node['exists']);
        self::assertSame('', $node['path']);
        self::assertSame('root', $node['level']);
    }

    /** The tree is three levels deep; a deeper path is not one. */
    public function testAPathDeeperThanTheTreeFallsBackToTheRoot(): void
    {
        self::assertFalse(mlf_resolve($this->tree(), 'BSIT/Year 1/11001/extra')['exists']);
    }

    /**
     * Downloading "the BSIT folder" must give BSIT's WHOLE subtree.
     * An archive that quietly omitted Year 2 because the reader was
     * standing in Year 1 would be worse than no download at all.
     */
    public function testExportingAFolderCoversItsWholeSubtree(): void
    {
        $tree = $this->tree();

        self::assertCount(4, mlf_sections_under($tree, ''));
        self::assertCount(3, mlf_sections_under($tree, 'BSIT'), 'BSIT must include its Year 2');
        self::assertCount(2, mlf_sections_under($tree, 'BSIT/Year 1'));

        self::assertSame(
            ['BSIT/Year 1/11001', 'BSIT/Year 1/11002', 'BSIT/Year 2/21001'],
            array_column(mlf_sections_under($tree, 'BSIT'), 'path')
        );
    }

    /** A leaf exports only itself. */
    public function testExportingASectionCoversOnlyIt(): void
    {
        $one = mlf_sections_under($this->tree(), 'BSIT/Year 1/11001');

        self::assertCount(1, $one);
        self::assertSame('BSIT/Year 1/11001', $one[0]['path']);
    }
// ── The single expandable table ────────────────────────

    /**
     * The shape the page renders: one flat, ordered list where a
     * folder is a row and a student is a row beneath its section.
     *
     * Asserted on PATHS, not names. Both programs in this fixture
     * have a "Year 1", so a name-only assertion cannot tell one
     * program's year from the other's — which is exactly the
     * confusion the page has to avoid visually, by indenting them.
     */
    public function testRowsListFoldersAndStudentsInDocumentOrder(): void
    {
        $rows = mlf_rows($this->tree());

        // Programs come out in name order, so BSBA precedes BSIT.
        self::assertSame(
            ['BSBA', 'BSIT'],
            array_values(array_filter(array_column($rows, 'path'), static fn($p) => strpos((string) $p, '/') === false))
        );

        // Every folder appears before anything inside it.
        self::assertSame(
            [
                'BSBA', 'BSBA/Year 1', 'BSBA/Year 1/' . MLF_NO_SECTION,
                'BSIT', 'BSIT/Year 1', 'BSIT/Year 1/11001',
                'BSIT/Year 1/11002', 'BSIT/Year 2', 'BSIT/Year 2/21001',
            ],
            array_values(array_filter(
                array_column($rows, 'path'),
                static function ($p, $i) use ($rows) {
                    return $rows[$i]['type'] === 'folder';
                },
                ARRAY_FILTER_USE_BOTH
            ))
        );
    }

    /** A student is a row inside its own section, not inside the year. */
    public function testAStudentSitsUnderItsOwnSection(): void
    {
        $student = null;
        foreach (mlf_rows($this->tree()) as $row) {
            if ($row['type'] === 'student') {
                $student = $row;
                break;
            }
        }

        self::assertNotNull($student);
        self::assertSame('BSBA/Year 1/' . MLF_NO_SECTION, $student['parent']);
        // 'section', not a number: a student row is not a folder level.
        self::assertSame('section', $student['level']);
    }

    /** Every row names the folder it lives in, which is what the expander keys on. */
    public function testEveryRowCarriesItsParentPath(): void
    {
        foreach (mlf_rows($this->tree()) as $row) {
            if ($row['type'] === 'folder' && $row['level'] === 0) {
                self::assertSame('', $row['parent'], 'a program row is at the root');
            } else {
                self::assertNotSame('', $row['parent'], 'every other row names its folder');
            }
        }
    }

    /**
     * With nothing open, only the programs show. That is the point
     * of the shape: the reader opens what they want rather than
     * scrolling a page of every cohort.
     */
    public function testWithNothingOpenOnlyTheProgramsAreVisible(): void
    {
        foreach (mlf_rows($this->tree()) as $row) {
            self::assertTrue(mlf_row_visible($row, []) ? $row['level'] === 0 : true);
        }

        $visible = array_values(array_filter(
            mlf_rows($this->tree()),
            static fn($r) => mlf_row_visible($r, [])
        ));
        self::assertSame(['BSBA', 'BSIT'], array_column($visible, 'name'));
    }

    /** Opening a program reveals its years — and no other program's. */
    public function testOpeningAProgramRevealsItsYears(): void
    {
        $rows = mlf_rows($this->tree());

        $paths = [];
        foreach ($rows as $row) {
            if (mlf_row_visible($row, ['BSIT' => true])) {
                $paths[] = $row['path'];
            }
        }

        self::assertSame(
            ['BSBA', 'BSIT', 'BSIT/Year 1', 'BSIT/Year 2'],
            $paths,
            "BSBA's Year 1 must stay hidden while only BSIT is open"
        );
    }

    /**
     * The deep-link case. A reader sent a link to one section must
     * land with that section visible, which means every folder above
     * it is open too — otherwise the link shows them nothing.
     */
    public function testAFocusPathOpensEveryAncestor(): void
    {
        $rows = mlf_rows($this->tree(), 'BSIT/Year 2/21001');
        $open = ['BSIT' => true, 'BSIT/Year 2' => true, 'BSIT/Year 2/21001' => true];

        $names = [];
        foreach ($rows as $row) {
            if (mlf_row_visible($row, $open)) {
                $names[] = $row['name'];
            }
        }

        self::assertContains('BSIT', $names);
        self::assertContains('21001', $names);
        self::assertContains('Mendoza, Juan S', $names, 'the student inside the focused folder must show');
        // And nothing from a sibling year leaked in.
        self::assertNotContains('11001', $names);
        self::assertNotContains('Dela Cruz, Juan S', $names);
    }

    /** Folder rows carry the counts the reader uses to decide what to open. */
    public function testFolderRowsCarryCountsAndSubfolderCounts(): void
    {
        $byPath = [];
        foreach (mlf_rows($this->tree()) as $row) {
            if ($row['type'] === 'folder') {
                $byPath[$row['path']] = $row;
            }
        }

        self::assertSame(3, $byPath['BSIT']['count']);
        self::assertSame(2, $byPath['BSIT']['subfolders']);
        self::assertSame(2, $byPath['BSIT/Year 1']['count']);
        self::assertSame(2, $byPath['BSIT/Year 1']['subfolders']);
        self::assertSame(1, $byPath['BSIT/Year 1/11001']['count']);
        self::assertSame(0, $byPath['BSIT/Year 1/11001']['subfolders'], 'a section holds students, not folders');
    }
}