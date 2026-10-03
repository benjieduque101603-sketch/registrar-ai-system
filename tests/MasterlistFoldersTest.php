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
    }}
