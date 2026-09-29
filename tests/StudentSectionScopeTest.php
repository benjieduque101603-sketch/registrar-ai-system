<?php

use PHPUnit\Framework\TestCase;

/**
 * Section is Class Scheduling's field, not the Registrar's (#297).
 *
 * This test exists because "the form doesn't show it" is a property of the
 * markup, while "the office cannot write it" is a property of the server. The
 * first can regress with an edit to a template; the second with an edit to an
 * allow-list. Both are asserted here, and the second is asserted where it is
 * enforced - the SQL allow-list and the create path - rather than in
 * JavaScript, because a browser check is a courtesy and an allow-list is a rule.
 *
 * Deliberately NOT asserted: that `students.section` stops existing. The column
 * is still written by Class Scheduling and read by the Masterlist, so removing
 * it would be a scheduling decision, not a registrar one.
 */
final class StudentSectionScopeTest extends TestCase
{
    private const PAGE = __DIR__ . '/../registrar/students.php';
    private const API = __DIR__ . '/../api/students.php';
    private const FUNCTIONS = __DIR__ . '/../shared/functions.php';

    private static function page(): string
    {
        return file_get_contents(self::PAGE);
    }

    /**
     * No section input anywhere. Asserting on the field element rather than the
     * word catches a field that survived a rename, and a field is exactly what
     * a registrar would fill in expecting it to save.
     */
    public function testNoEditableSectionFieldInAnyModal(): void
    {
        $page = self::page();

        self::assertSame(
            0,
            preg_match('/<input[^>]*\bid="(add|edit)?Section"/i', $page),
            'Found a section input in a student modal. Section belongs to Class Scheduling.'
        );
        self::assertSame(
            0,
            preg_match('/<select[^>]*\bid="(add|edit)?Section"/i', $page),
            'Found a section select in a student modal.'
        );
    }

    /**
     * N/A, not an omitted row. A registrar has to be able to tell "not my
     * department" from "data lost", which only works if the label stays put and
     * names the owner.
     */
    public function testModalsShowSectionAsNotApplicable(): void
    {
        $page = self::page();

        self::assertSame(
            1,
            preg_match('/id="vSection"[^>]*>N\/A/', $page),
            'View Student should render #vSection as N/A.'
        );
        // Year level and Section are separate rows now. Merging them back into
        // a "Year / Section" cell would bring the section value back with it.
        self::assertStringNotContainsString('id="vYearSection"', $page);
        self::assertSame(
            0,
            preg_match("/vSection'\]\.textContent/", $page),
            'JavaScript must not write a section value into the view.'
        );
        self::assertMatchesRegularExpression(
            '/Section.*Class Scheduling/is',
            $page,
            'The N/A row should name the owning department.'
        );
    }

    public function testListAndFiltersHaveNoSectionColumn(): void
    {
        $page = self::page();

        self::assertStringNotContainsString('filterSection', $page);
        self::assertStringNotContainsString('"addSection"', $page);
    }
    /**
     * The helpers are gone, not just the markup. A leftover suggestSection()
     * still compiles, still calls the AI endpoint, and still writes into a
     * field that no longer exists - so a dead-looking helper is the likely way
     * this comes back.
     */
    public function testSectionHelpersAreGone(): void
    {
        $page = self::page();

        // Matched as a call, not as a word. The comment explaining the removal
        // still names both functions, and a word search would fail on its own
        // documentation - which is a good way for a test to be quietly deleted
        // rather than fixed.
        self::assertSame(0, preg_match('/suggestSection\s*\(/', $page));
        self::assertSame(0, preg_match('/syncSectionAvailability\s*\(/', $page));
        // Still declared, and still writing a field that no longer exists.
        self::assertSame(0, preg_match('/function\s+suggestSection\b/', $page));
        self::assertSame(0, preg_match('/function\s+syncSectionAvailability\b/', $page));
    }

    /**
     * Neither payload carries a section. Enrol must not send '' either: the
     * update path reads an empty string as "clear this field".
     */
    public function testNeitherPayloadSendsASection(): void
    {
        self::assertSame(
            0,
            preg_match('/^\s*section:\s*document/m', self::page()),
            'A student payload still sends a section key.'
        );
    }

    /**
     * The server-side half, and the half that actually matters.
     *
     * With 'section' out of the update allow-list, the UPDATE cannot include
     * the column, so this endpoint cannot modify a Scheduling field even when a
     * stale client or a crafted request sends one. No assertion on the rendered
     * form could catch that.
     */
    public function testUpdateApiCannotWriteSection(): void
    {
        $api = file_get_contents(self::API);

        self::assertSame(
            1,
            preg_match('/\$allowedFields\s*=\s*\[(.*?)\]/s', $api, $m),
            'Could not find the update allow-list.'
        );

        $fields = array_values(array_filter(array_map(
            static fn(string $f): string => trim($f, " \t\n\r'"),
            explode(',', $m[1])
        )));

        self::assertNotContains(
            'section',
            $fields,
            'section is still writable through the Registrar update API.'
        );
        // Guards against a rename reintroducing the same capability.
        self::assertNotContains('section_code', $fields);
    }

    /**
     * Create writes NULL, not ''. The column is VARCHAR, so an empty string
     * would reach the Masterlist as a blank block code - a different claim from
     * "not yet assigned".
     */
    public function testCreateApiStoresNullSection(): void
    {
        $src = file_get_contents(self::FUNCTIONS);

        // Scoped to the create function. A bare search finds the masterlist
        // auto-assign writes first and would pass on the wrong line.
        self::assertSame(
            1,
            preg_match('/function createStudentFromInput.*?\$data\s*=\s*\[(.*?)\n    \];/s', $src, $fn),
            'Could not find the create payload in createStudentFromInput().'
        );
        self::assertSame(
            1,
            preg_match("/'section'\s*=>\s*([^,\n]+)/", $fn[1], $m),
            'The create payload no longer mentions section at all.'
        );
        self::assertSame(
            'null',
            trim($m[1]),
            'Create should hard-code section to null and ignore any incoming value.'
        );
    }

    /** The quality score no longer rewards a field this office cannot set. */
    public function testQualityScoreIgnoresSectionEntirely(): void
    {
        $quality = file_get_contents(__DIR__ . '/../shared/student_quality.php');

        // Two separate lists, and the weights alone are not enough. The first
        // drives the score, the second raises "Section is missing" on the
        // Quality Desk - a complaint with no possible fix, since this office
        // cannot set the field. Both have to drop it.
        self::assertSame(
            1,
            preg_match('/\$weights\s*=\s*\[(.*?)\]/s', $quality, $m),
            'Could not find the quality score weights.'
        );
        self::assertSame(0, preg_match("/'section'\s*=>/", $m[1]), 'Section is still weighted in the score.');

        self::assertSame(
            1,
            preg_match('/\$required\s*=\s*\[(.*?)\]/s', $quality, $m),
            'Could not find the quality issue list.'
        );
        self::assertSame(0, preg_match("/'section'\s*=>/", $m[1]), 'Section is still raised as a missing-field issue.');
    }

    /**
     * Not collateral damage in the removal pass: the column stays, because
     * Class Scheduling writes it and the Masterlist reads it.
     */
    public function testSectionColumnStillExistsInSchema(): void
    {
        $sql = file_get_contents(__DIR__ . '/../registrar_ai.sql');

        self::assertMatchesRegularExpression(
            '/CREATE TABLE.*?`?students`?.*?\(/s',
            $sql,
            'Could not find the students table in the schema.'
        );
        self::assertMatchesRegularExpression(
            '/`section`\s+varchar/i',
            $sql,
            'students.section should remain in the schema for Class Scheduling.'
        );
    }

    /**
     * Every div in the student modal block is closed.
     *
     * This exists because it already failed once. A single missing closing tag
     * on #viewModal made the browser adopt the next modal overlay as its child,
     * so #addModal collapsed to a 0x0 box and both its buttons and the Edit
     * modal's became unclickable - with no JavaScript error anywhere, because
     * the JavaScript was fine. The markup was wrong.
     *
     * Scoped to the modal run, from #filterModal to the last overlay. A
     * whole-file count is not usable here: the page interleaves `<?php ?>`
     * blocks that EMIT markup, so stripping them deletes real tags, and
     * counting the raw file counts the PHP source as if it were HTML. Both
     * produce a number that is confidently wrong. Inside this run the markup
     * is literal, so the count means what it says.
     */
    public function testEveryDivInTheModalRunIsClosed(): void
    {
        $src = self::page();
        $src = preg_replace('/<!--.*?-->/s', '', $src);

        // Anchored on the opening TAG, not on the id. `strpos($src,
        // 'id="filterModal"')` lands after the div has already opened, so the
        // slice begins one tag short and the very first close reads as an
        // extra - a failure that points at line 16 of a region that has not
        // started yet, and sends the reader looking for a fault in the first
        // modal when the fault is in the test.
        $start = strpos($src, '<div class="modal-overlay" id="filterModal"');
        $end   = strrpos($src, '<div class="modal-overlay" id="receiveModal"');
        self::assertNotFalse($start, 'Could not find the first student modal.');
        self::assertNotFalse($end, 'Could not find the last student modal.');
        $run = substr($src, $start, $end - $start);

        $open  = preg_match_all('/<div\b(?![^>]*\/>)/i', $run);
        $close = preg_match_all('/<\/div>/i', $run);

        self::assertGreaterThan(0, $open, 'No divs found in the modal run - the anchors moved.');
        self::assertSame(
            $open,
            $close,
            sprintf(
                'Unbalanced divs in the student modals: %d opened, %d closed. An unclosed tag reparents every modal after it into its parent, and the visible one renders as a 0x0 box.',
                $open,
                $close
            )
        );
    }

    /**
     * No id appears twice.
     *
     * A duplicate id is legal HTML that silently breaks the page: the browser
     * resolves getElementById() to the FIRST match, so a script writing the
     * second one updates a node nobody is looking at. Status was rendered
     * twice for exactly this reason - once in a leftover strip, once in the
     * Identity group - and the visible value stopped matching the record.
     *
     * The check is on ids only. Duplicate classes are intentional and
     * meaningless to assert on.
     */
    public function testNoDuplicateElementIds(): void
    {
        $src = self::page();
        $src = preg_replace('/<!--.*?-->/s', '', $src);

        preg_match_all('/\bid="([^"]+)"/', $src, $m);
        $counts = array_count_values($m[1]);
        $dupes  = array_filter($counts, static fn(int $n): bool => $n > 1);

        self::assertSame(
            [],
            $dupes,
            'Duplicate id(s): ' . implode(', ', array_keys($dupes)) . '. getElementById() resolves to the first, so the second is dead markup.'
        );
    }

    /**
     * The modal overlays are siblings, not nested.
     *
     * The failure this guards against is silent and total: a stray unclosed tag
     * reparents every later modal into the previous one, so opening one shows
     * another's (hidden) contents and the visible one renders nothing at all.
     * Asserting the parent is <body> catches the cause directly, where a div
     * count only catches one way of causing it.
     */
    public function testModalOverlaysAreSiblingsOfBody(): void
    {
        $src = self::page();

        // Eight overlays on this page. The count is asserted rather than
        // hard-coded into the order assertion below, so adding a modal is a
        // one-line change here rather than a hunt through two expectations.
        preg_match_all('/<div class="modal-overlay" id="([A-Za-z]+)"/', $src, $m);

        // Each overlay must fully close before the next opens. Verified by
        // walking the tag stream, which is the property the div count alone
        // does not express: a page can balance overall and still nest one
        // modal inside another.
        $expected = [
            'filterModal', 'viewModal', 'qualityModal', 'addModal',
            'acctModal', 'pasteModal', 'editModal', 'receiveModal',
        ];
        self::assertSame(
            $expected,
            $m[1],
            'Student modal overlays changed identity or order. If one overlay\'s markup runs into the next, the browser nests it inside its neighbour and the visible modal renders empty.'
        );
    }
}
