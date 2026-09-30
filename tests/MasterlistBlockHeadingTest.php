<?php

use PHPUnit\Framework\TestCase;

/**
 * The block heading names a cohort, and two separate consumers depend on it.
 *
 * The screen consumer is the registrar, who has to tell three BSIT blocks apart
 * at a glance. The print consumer is a popup window that reads
 * `.ml-block-head h2` to title every printed table - and when it finds no title
 * it falls back to the literal string "Unassigned program". So the heading is not
 * only a design surface, it is a lookup key: a rename that drops it from the
 * print path puts a wrong name on a sheet of 50 students.
 *
 * These are assertions about markup, so they are cheap to write and cheap to
 * fail. They are here because the heading has been restructured once already and
 * the print path quietly followed it down to a compact form.
 */
final class MasterlistBlockHeadingTest extends TestCase
{
    private const PAGE = __DIR__ . '/../registrar/masterlist.php';

    private static function page(): string
    {
        $src = file_get_contents(self::PAGE);
        self::assertIsString($src);
        return $src;
    }

    /** The four facts a cohort heading carries, in the order they are read. */
    public function testHeadingCarriesProgramCohortSchoolYearAndCount(): void
    {
        $src = self::page();

        self::assertStringContainsString('ml-block-acronym', $src, 'Heading must name the program.');
        self::assertStringContainsString('ml-block-cohort', $src, 'Heading must name the year level and term.');
        self::assertStringContainsString('ml-block-sy', $src, 'Heading must name the school year.');
        self::assertStringContainsString('ml-block-count', $src, 'Heading must state how many students are in the block.');
    }

    /**
     * The h2 is the print path's selector. If it is renamed or replaced by a div,
     * every printed table silently falls back to "Unassigned program" and nothing
     * on screen looks wrong.
     */
    public function testHeadingKeepsTheH2ThatThePrintPathQueries(): void
    {
        $src = self::page();

        self::assertStringContainsString(
            '.ml-block-head h2',
            $src,
            'The print path looks up `.ml-block-head h2`. If that selector stops matching, '
            . 'every printed table is titled "Unassigned program".'
        );
        self::assertMatchesRegularExpression(
            '/<h2[^>]*class="[^"]*ml-block-lead[^"]*"[^>]*\btitle=/',
            $src,
            'The h2 must carry both the class and the title attribute the print path relies on.'
        );
    }

    /**
     * The screen heading is compact on purpose, so the full program name lives in
     * `title` and print reads that. If print goes back to textContent, the sheet
     * says "BSIT Year 1 - 1st sem" with no course name on it.
     */
    public function testPrintPathPrefersTheFullTitleOverTheCompactScreenText(): void
    {
        $src = self::page();

        self::assertMatchesRegularExpression(
            '/blockTitleOf.*?getAttribute\(\s*.title.\s*\)/s',
            $src,
            'The print path must read the h2 title, which holds the full program name.'
        );
        self::assertStringContainsString(
            'Unassigned program',
            $src,
            'Asserted so this test knows what the fallback text is: the printed sheet prints '
            . 'this literal whenever a block title cannot be read.'
        );
    }

    /**
     * Type hierarchy is the point of the redesign and it is invisible to a
     * cell-count check. The lead must out-size the meta line, or the heading is
     * back to being one undifferentiated run of labels.
     */
    public function testHeadingTypeIsLargerThanTheMetaLine(): void
    {
        $src = self::page();

        preg_match('/\.ml-block-lead\{([^}]*)\}/', $src, $lead);
        preg_match('/\.ml-block-meta\{([^}]*)\}/', $src, $meta);
        self::assertNotEmpty($lead, 'No .ml-block-lead rule found.');
        self::assertNotEmpty($meta, 'No .ml-block-meta rule found.');

        preg_match('/font-size:\s*([\d.]+)px/', $lead[1], $leadPx);
        preg_match('/font-size:\s*([\d.]+)px/', $meta[1], $metaPx);
        self::assertNotEmpty($leadPx, '.ml-block-lead sets no font-size.');
        self::assertNotEmpty($metaPx, '.ml-block-meta sets no font-size.');

        self::assertGreaterThan(
            (float) $metaPx[1],
            (float) $leadPx[1],
            'The cohort name must be larger than the school year and count beneath it.'
        );
    }

    /**
     * The rule that tinted the block head was `>div:first-child`, which reached
     * past .ml-block-head and repainted the heading's background - the same class
     * of bug as the `!important` table header override. Nothing may reach in and
     * set a block head's background from the outside.
     */
    public function testNoExternalRuleRepaintsTheBlockHeading(): void
    {
        // Comments are stripped first: the rule was removed, but the note
        // explaining why still spells the selector out, and a test that cannot
        // tell a comment from a rule is not a test of anything.
        $src = self::page();
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $src);

        self::assertDoesNotMatchRegularExpression(
            '/masterlist-section-block\s*>\s*div:first-child/',
            $code,
            'A `:first-child` rule on the section block silently repaints .ml-block-head. '
            . 'Let the heading own its own background.'
        );
    }

    /**
     * The office rejected the vertical hairlines that divided the row into three
     * groups at Contact and Email: they read as a grid, and a registrar scanning
     * for a name saw cells first and a roster second. A vertical rule inside a
     * row is a cell boundary, not a separation of meaning.
     *
     * The .ml-col-start class is deliberately still on the markup - it is a hook,
     * not the visual. So this asserts on the RULE, not the class.
     */
    public function testTableHasNoVerticalCellBoundaries(): void
    {
        // Comments stripped for the same reason as above: the note explaining the
        // removal still names the selector, and it is not a rule.
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', self::page());

        // No border-left/border-right anywhere in the table's own styling.
        self::assertDoesNotMatchRegularExpression(
            '/\.masterlist-table[^{]*\{[^}]*border-(left|right)\s*:/',
            $code,
            'The table must not draw vertical rules. A vertical line inside a row is a '
            . 'cell boundary, which is what the office asked to remove.'
        );

        // And specifically: the group-divider rule must not come back.
        self::assertDoesNotMatchRegularExpression(
            '/th\.ml-col-start[^{]*\{[^}]*border-left/',
            $code,
            'The .ml-col-start hairlines at Contact and Email are removed.'
        );
    }

    /** The columns still group into three kinds of question. */
    public function testTableStillGroupsColumnsWhereItMatters(): void
    {
        $src = self::page();

        // The class stays in the markup so the grouping is still machine-readable
        // (and so the boundaries can come back without a markup change).
        //
        // Comments are stripped first, because the note explaining the removal
        // names the class twice and would be counted as markup.
        //
        // Four, not two: Contact and Email each carry it on their th AND their
        // td. A header-only class would leave the body's columns unmarked, and
        // that is the half that was drawing the line the reader could see.
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $src);
        $count = preg_match_all('/\bml-col-start\b/', $code);

        self::assertSame(
            4,
            $count,
            'Contact and Email each carry the group-start class on both their th and their td.'
        );
    }

    /** Amber means one thing now: a block that ran past the per-table cap. */
    public function testAmberIsReservedForACountPastTheCap(): void
    {
        $src = self::page();

        self::assertStringContainsString(
            'ml-block-count-over',
            $src,
            'A count past the cap should be flagged in the heading.'
        );
        self::assertStringNotContainsString(
            'badge-warning',
            $src,
            'The old green/amber count badge made a routine table split look like a problem. '
            . 'The count is plain type now, and only a past-cap count turns amber.'
        );
    }
}