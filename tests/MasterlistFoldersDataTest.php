<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../shared/masterlist_folders.php';

/**
 * The data-safety rules behind the folders.
 *
 * Kept beside MasterlistFoldersTest because they are the same
 * module's rules; a separate file only so neither grows past the
 * point where a reviewer skims it.
 */
final class MasterlistFoldersDataTest extends TestCase
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

    /**
     * The reason the "Unassigned" folders exist. A student with no
     * section is a real enrolment, and the tree has to show it
     * under an explicit folder rather than lose it — a cohort that
     * quietly vanishes from a folder tree is one that gets handed
     * to a department as if it did not exist.
     */
    public function testUnplacedStudentsStillAppearInAnExplicitFolder(): void
    {
        $path = mlf_path($this->student([
            'course' => '', 'year_level' => null, 'section' => '',
        ]));

        self::assertSame(MLF_NO_PROGRAM . '/' . MLF_NO_YEAR . '/' . MLF_NO_SECTION, $path);

        $sections = mlf_sections_under(
            mlf_build_tree([$this->student(['section' => ''])]),
            ''
        );

        self::assertCount(1, $sections);
        self::assertSame(MLF_NO_SECTION, $sections[0]['section']);
        self::assertSame(1, $sections[0]['count']);
    }

    /** A section saved as a space is as unplaced as one saved as NULL. */
    public function testWhitespaceOnlySectionCountsAsUnassigned(): void
    {
        self::assertSame(MLF_NO_SECTION, mlf_path_segments($this->student(['section' => '   ']))[2]);
    }

    /**
     * The tile for an unassigned folder must be marked, or an empty
     * section and a cohort nobody has placed yet look alike.
     */
    public function testTheUnassignedFolderIsFlaggedOnItsTile(): void
    {
        // Not on the program's tile at the root — the program IS
        // recorded, it is the section that is missing. The flag
        // belongs on the tile the reader actually sees it on.
        $tree = mlf_build_tree([$this->student(['section' => ''])]);

        self::assertFalse(
            mlf_resolve($tree, 'BSIT')['folders'][0]['unassigned'],
            'the program tile must not be flagged when only the section is missing'
        );

        $sections = mlf_resolve($tree, 'BSIT/Year 1')['folders'];
        self::assertTrue($sections[0]['unassigned']);
    }

    /**
     * A program name is free text typed by a clerk, and the
     * forbidden characters are the ones that make an archive
     * un-extractable on the receiving machine.
     */
    public function testIllegalFolderCharactersAreStripped(): void
    {
        $segments = mlf_path_segments($this->student(['course' => 'BSIT: Computer/Engineering?']));

        foreach (['\\', '/', ':', '*', '?', '"', '<', '>', '|'] as $bad) {
            self::assertStringNotContainsString(
                $bad, $segments[0], 'folder name still holds an illegal character'
            );
        }
        self::assertStringContainsString('BSIT', $segments[0]);
    }

    /**
     * Windows strips a trailing dot, which would make "BSIT." and
     * "BSIT" the same folder on disk while they are two rows in the
     * database.
     */
    public function testTrailingDotsAndSpacesAreRemoved(): void
    {
        self::assertSame('BSIT', mlf_safe_segment('BSIT. ', 'fallback'));
    }

    /** A name made only of forbidden characters must not become an empty folder. */
    public function testFullyStrippedNameFallsBack(): void
    {
        self::assertSame(MLF_NO_PROGRAM, mlf_safe_segment('///', MLF_NO_PROGRAM));
    }

    /** Non-Latin program names are kept, not transliterated away. */
    public function testUnicodeProgramNamesSurvive(): void
    {
        self::assertSame('Akasyon', mlf_safe_segment('Akasyon', MLF_NO_PROGRAM));
    }

    /**
     * "Unassigned" is the work queue, not part of the cohort
     * order — sorted by name it would land between real section
     * codes and read as a section called "Unassigned".
     */
    public function testUnassignedFoldersSortLastAtEveryLevel(): void
    {
        $tree = mlf_build_tree([
            $this->student(['id' => 1, 'course' => 'BSIT', 'year_level' => 2, 'section' => '21001']),
            $this->student(['id' => 2, 'course' => 'BSIT', 'year_level' => 1, 'section' => '']),
            $this->student(['id' => 3, 'course' => 'BSIT', 'year_level' => 1, 'section' => '11002']),
            $this->student(['id' => 4, 'course' => '', 'year_level' => 1, 'section' => '11001']),
        ]);

        self::assertSame(['BSIT', MLF_NO_PROGRAM], array_keys($tree));
        self::assertSame(['Year 1', 'Year 2'], array_keys($tree['BSIT']['years']));

        // Read back through the public API rather than the raw array
        // keys: PHP coerces a numeric key like '11002' into an int,
        // so the tree's internals are ints while mlf_resolve() hands
        // callers the strings they actually use.
        $names = array_column(mlf_resolve($tree, 'BSIT/Year 1')['folders'], 'name');

        self::assertSame(['11002', MLF_NO_SECTION], $names);
    }

    /** Counts are the point of the tile: a registrar reads them to spot a missing cohort. */
    public function testCountsRollUpThroughEveryLevel(): void
    {
        $tree = mlf_build_tree([
            $this->student(['id' => 1, 'section' => '11001']),
            $this->student(['id' => 2, 'section' => '11001']),
            $this->student(['id' => 3, 'section' => '11002']),
        ]);

        self::assertSame(3, $tree['BSIT']['count']);
        self::assertSame(3, $tree['BSIT']['years']['Year 1']['count']);
        self::assertSame(2, $tree['BSIT']['years']['Year 1']['sections']['11001']['count']);
        self::assertSame(1, $tree['BSIT']['years']['Year 1']['sections']['11002']['count']);
    }

    /** One section's students stay together, in the order the Masterlist page prints. */
    public function testStudentsInsideASectionAreSortedByName(): void
    {
        $tree = mlf_build_tree([
            $this->student(['id' => 1, 'last_name' => 'Zulu']),
            $this->student(['id' => 2, 'last_name' => 'Alvarez']),
            $this->student(['id' => 3, 'last_name' => 'Mendoza']),
        ]);

        $names = array_map(
            static fn($s) => $s['last_name'],
            $tree['BSIT']['years']['Year 1']['sections']['11001']['students']
        );

        self::assertSame(['Alvarez', 'Mendoza', 'Zulu'], $names);
    }

    /**
     * The folder is a projection of the student row, so a changed
     * section MOVES the row on the next build. This is the property
     * that would break if a path were ever stored.
     */
    public function testFolderFollowsTheStudentsCurrentSection(): void
    {
        $student = $this->student(['section' => '11001']);
        self::assertStringEndsWith('/11001', mlf_path($student));

        $student['section'] = '11002';
        self::assertStringEndsWith('/11002', mlf_path($student));
    }

    public function testEmptyInputProducesAnEmptyTree(): void
    {
        self::assertSame([], mlf_build_tree([]));
        self::assertSame([], mlf_sections_under([], ''));
        self::assertSame([], mlf_resolve([], '')['folders']);
    }
}
