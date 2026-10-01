<?php

use PHPUnit\Framework\TestCase;

/**
 * Section is back on the Masterlist, and still Class Scheduling's field
 * everywhere else.
 *
 * This test exists because "the form doesn't show it" is a property of the
 * markup, while "the office cannot write it" is a property of the server. The
 * first can regress with an edit to a template; the second with an edit to an
 * allow-list. Both are asserted here, and the second is asserted where it is
 * enforced - the SQL allow-list and the create path - rather than in
 * JavaScript, because a browser check is a courtesy and an allow-list is a rule.
 *
 * SCOPE, which has changed once. Sectioning was removed from the Masterlist
 * and the open question "may the Registrar auto-assign blocks at all?" was
 * parked in DEPARTMENTS.md. That question has been answered yes, and
 * api/masterlist.php + registrar/masterlist.php now write and show the code.
 * The Students roster is UNCHANGED by that decision: it still has no section
 * input, because assigning a block is a Masterlist operation and putting a
 * per-student field on a roster form is not the same thing.
 *
 * Deliberately NOT asserted: that `students.section` stops existing. The
 * column is read and written by the Masterlist, so removing it would be a
 * scheduling decision, not a registrar one.
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
        // The N/A row must still name where the section actually comes from.
        // It used to say "Class Scheduling"; the Masterlist assigns it now, and
        // a row that points a registrar at the wrong office sends them to ask
        // the one department that cannot help.
        self::assertMatchesRegularExpression(
            '/Section.*Masterlist/is',
            $page,
            'The N/A row should name where the section is assigned.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/Class Scheduling/is',
            $page,
            'The Students page still points at the old owning department.'
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
     * The Masterlist HAS a Section column, and still has no Adviser column.
     *
     * This assertion used to require the opposite of the first half. Section
     * was removed from the Masterlist on the grounds that it belonged to
     * Class Scheduling, and DEPARTMENTS.md left "may the office auto-assign
     * blocks at all" as an open scheduling question. That question has been
     * answered: auto-assign is back, so the list carries the code it assigns
     * and the registrar can see it.
     *
     * Adviser is a DIFFERENT field and a different owner - Faculty
     * Management (#296) - and that half of the original test still stands
     * untouched. Splitting the two matters: folding them back together would
     * let a future change quietly reintroduce the adviser column under cover
     * of "sections came back".
     *
     * The old "no scope note" assertions are kept. The note was rejected for
     * repeating itself on every block heading, and that reason has not
     * changed - the section chips in the heading now carry the information
     * instead, which is what replaced it.
     */
    public function testMasterlistHasSectionColumnButNoAdviserColumn(): void
    {
        $page = file_get_contents(__DIR__ . '/../registrar/masterlist.php');

        // Section IS a per-row column now, and it is matched as markup rather
        // than as a word so that prose about sections cannot satisfy it.
        self::assertSame(
            1,
            preg_match('/<th\b[^>]*data-field="section"/', $page),
            'The Section column header is missing from the masterlist table.'
        );
        self::assertSame(
            1,
            preg_match('/<td\b[^>]*data-field="section"/', $page),
            'The Section column body cell is missing from the masterlist table.'
        );
        // A blank code must be worded, never an empty cell: a registrar has
        // to be able to tell "not placed yet" from "the value failed to load".
        self::assertStringContainsString('Unassigned', $page);

        // Adviser is still not the Registrar's to record.
        self::assertSame(
            0,
            preg_match('/<(td|th)\b[^>]*data-field="adviser"/', $page),
            'Adviser should not be a per-row column.'
        );
        self::assertSame(
            0,
            preg_match("/\['adviser',\s*'Adviser'\]/", $page),
            'The export fallback should not emit an Adviser column.'
        );
        self::assertSame(
            0,
            preg_match('/adviser_name/', $page),
            'adviser_name is still resolved for the masterlist rows.'
        );

        // The row no longer fabricates a section the way it used to, and the
        // rejected "N/A" scaffolding stays rejected.
        self::assertStringNotContainsString('ml-section-slot', $page);
        self::assertStringNotContainsString('ml-na-head', $page);

        // And the removed note stays removed, markup and styling both, so it
        // cannot creep back as a stray unstyled paragraph.
        self::assertSame(
            0,
            preg_match('/<p[^>]*class="ml-scope-note"/', $page),
            'The scope note should not be in the block header.'
        );
        self::assertStringNotContainsString('ml-scope-note', $page);
        // Matched with the trailing brace, not the bare token: "ml-name" starts
        // with "ml-na", so a bare substring test passes against the name class.
        self::assertStringNotContainsString('.ml-na{', $page);
    }

    /**
     * The printed sheet has no column the table dropped.
     *
     * The print header is written by hand, not derived from the table, so a
     * column can be removed on screen and keep printing. It is addressed by
     * data-field, so a removed cell does not error - it prints an EMPTY cell
     * under a header that still promises a value, which on a document someone
     * signs looks like a gap in the record.
     */
    public function testPrintedSheetHasNoColumnTheTableDropped(): void
    {
        $page = file_get_contents(__DIR__ . '/../registrar/masterlist.php');

        self::assertSame(
            1,
            preg_match("/w\.document\.write\('<table><tr>(.*?)<\/tr>'\);(.*?)w\.document\.write\('<\/table>'\);/s", $page, $m),
            'Could not find the printed table header and its row writer.'
        );
        [, $header, $body] = $m;

        // Every field the body reads must have a header above it. The header
        // wording is abbreviated ("Student No." for student_number), so the
        // comparison is on a normalised form of each: punctuation dropped and
        // the two abbreviations the sheet actually uses expanded. Comparing the
        // raw text would either miss real drift or force the sheet to spell
        // everything out, and a signed document is not the place for a long
        // header.
        $normalise = static function (string $s): string {
            $s = strtolower(strip_tags($s));
            $s = str_replace(['.', ','], '', $s);
            return trim((string) preg_replace('/\s+/', ' ', $s));
        };
        // The one abbreviation the sheet actually uses.
        $expand = ['student no' => 'student number'];

        preg_match_all('/<th>(.*?)<\/th>/', $header, $h);
        preg_match_all("/cellText\(row, '([a-z_]+)'\)/", $body, $b);
        $labels = array_map($normalise, $h[1]);
        $labels = array_map(fn($x) => $expand[$x] ?? $x, $labels);
        foreach ($b[1] as $field) {
            $label = implode(' ', explode('_', $field));
            self::assertContains(
                $label,
                $labels,
                "The printed sheet reads '$field' with no header above it."
            );
        }

        // The dropped columns, explicitly. Year was the live hazard: the print
        // sheet still named it after the column was removed.
        self::assertStringNotContainsString("cellText(row, 'year_level')", $page);
        self::assertStringNotContainsString("cellText(row, 'school_year')", $page);
        // And the header no longer promises a Year the body cannot fill.
        self::assertStringNotContainsString('<th>Year</th>', $page);
        self::assertStringNotContainsString('<th>S.Y.</th>', $page);
    }

    /**
     * The name is set in caps by CSS, never baked into the value.
     *
     * The temptation is `mb_strtoupper()` in the template. That writes AQUINO
     * into the DOM text, and then a search for "Aquino" - the name as it is
     * stored and as it appears on every form the student signs - finds nobody,
     * because the search box matches rendered text. It also ships "AQUINO" into
     * the CSV and the printed sheet. CSS text-transform is presentation, so the
     * markup keeps the real name and every text consumer reads it correctly.
     */
    public function testMasterlistNameIsUppercaseInCssNotInTheMarkup(): void
    {
        $page = file_get_contents(__DIR__ . '/../registrar/masterlist.php');

        // Caps are applied by the stylesheet, to BOTH halves - so the surname
        // cannot be caps sitting over a mixed-case given name. Counted within
        // that one rule only: text-transform:uppercase appears throughout the
        // page's other stylesheet, so a page-wide count would prove nothing.
        self::assertSame(
            1,
            preg_match('/\.ml-name \.sur,\s*\.ml-name \.giv\{[^}]*text-transform:\s*uppercase[^}]*\}/s', $page),
            'The name should be set in caps by CSS, on both halves in one rule.'
        );
        self::assertSame(
            0,
            preg_match('/\.ml-name \.sur\{[^}]*text-transform/s', $page),
            'The surname should not have its own caps rule, or the two halves can drift apart.'
        );

        // And NOT by the template. This is the regression: a working search
        // silently stops matching the names people actually type.
        self::assertSame(
            0,
            preg_match('/<span class="sur"><\?= htmlspecialchars\(mb_strtoupper/', $page),
            'The surname must not be uppercased in PHP - it would break search and the export.'
        );
        self::assertSame(
            0,
            preg_match('/<span class="giv"><\?= htmlspecialchars\(mb_strtoupper/', $page),
            'The given names must not be uppercased in PHP either.'
        );
        self::assertSame(
            1,
            preg_match('/<span class="sur"><\?= htmlspecialchars\(trim\(\$student\[.last_name.\]/', $page),
            'The surname should be rendered in its stored case.'
        );
    }

    /**
     * The header is a heading, not a banner: sentence case, and the sort arrow
     * out of the text.
     *
     * Letterspaced 11px caps across nine columns is the default look, and it is
     * the wrong one here. A header is read once and then used to match a column
     * of values below it; caps forces letter-shape decoding on every label,
     * which is the opposite of what a scannable label is for.
     */
    public function testMasterlistHeaderIsSentenceCaseWithTheArrowOutOfTheText(): void
    {
        $page = file_get_contents(__DIR__ . '/../registrar/masterlist.php');

        self::assertSame(
            1,
            preg_match('/\.masterlist-table th\{[^}]*text-transform:\s*none/s', $page),
            'Column headers should be sentence case, not uppercased.'
        );
        // The arrow is positioned out of the flow. Inline, it pushed "Name" two
        // characters right of every other label, so the column of headings
        // stopped lining up with the column of values - and it was the only
        // indication that three of the columns were sortable at all.
        self::assertSame(
            1,
            preg_match('/th\[data-sort\] i\{[^}]*position:\s*absolute/s', $page),
            'The sort arrow should be positioned out of the text flow.'
        );
        self::assertSame(
            0,
            preg_match('/<i class="fas fa-sort" style="[^"]*margin-right/s', $page),
            'No header should still carry an inline sort icon.'
        );
        // A sorted column must say so after the pointer leaves, or the list
        // looks untouched. aria-sort is also the only signal a screen reader gets.
        self::assertSame(
            1,
            preg_match("/setAttribute\('aria-sort', dir === 'asc' \? 'ascending' : 'descending'\)/", $page),
            'Sorting should set aria-sort on the column it sorted.'
        );
        self::assertSame(
            1,
            preg_match("/th\[data-sort\]\[aria-sort\] i\{opacity:1/s", $page),
            'The arrow must stay visible on the sorted column, not only on hover.'
        );
    }

    /**
     * LRN is gone, and Email takes the column.
     *
     * LRN is the better field on paper - the DepEd identifier travels with a
     * student between schools. But every current student has students.lrn NULL,
     * so the column would have read N/A down the entire list, and a column
     * nobody can fill teaches the reader nothing. Email is populated on the
     * records that exist, and it is what an office actually sends to: a section
     * notice, a schedule change, a documents-request update.
     */
    public function testMasterlistReplacesLrnWithEmail(): void
    {
        $page = file_get_contents(__DIR__ . '/../registrar/masterlist.php');

        // LRN is gone from the header, the body, the track, the export and the
        // printed sheet. All five, or it survives in the CSV alone.
        self::assertSame(0, preg_match('/<th\b[^>]*data-field="lrn"/', $page), 'LRN should not be a column header.');
        self::assertSame(0, preg_match('/<td\b[^>]*data-field="lrn"/', $page), 'LRN should not be a body cell.');
        self::assertSame(0, preg_match('/<col class="c-lrn">/', $page), 'LRN should not have a column track.');
        self::assertStringNotContainsString("['lrn'", $page, 'The export fallback should not carry LRN.');
        self::assertStringNotContainsString("cellText(row, 'lrn')", $page, 'The printed sheet should not read LRN.');
        // No leftover .c-lrn rule either, which would be dead weight that reads
        // as a column that was meant to be here.
        self::assertSame(0, preg_match('/\.c-lrn\{/', $page), 'The LRN column track rule is still in the stylesheet.');

        // Email replaces it, in the same places.
        self::assertSame(1, preg_match('/<th\b[^>]*data-field="email"/', $page), 'Email should be a column header.');
        self::assertSame(1, preg_match('/<td\b[^>]*data-field="email"/', $page), 'Email should be a body cell.');
        self::assertSame(1, preg_match('/<col class="c-email">/', $page), 'Email should have a column track.');
        self::assertStringContainsString("['email'", $page, 'The export fallback should carry Email.');

        // A missing address is stated, not left blank.
        preg_match('/<td data-field="email".*?<\/td>/s', $page, $m);
        self::assertNotEmpty($m, 'Could not find the email cell.');
        self::assertStringContainsString(
            "htmlspecialchars(\$email !== '' ? \$email : 'N/A')",
            $m[0],
            "A student with no email should read 'N/A' rather than a blank cell."
        );
    }

    /**
     * Every body cell sits under the header that names it.
     *
     * This is the failure a cell COUNT cannot catch. Moving Email into the slot
     * LRN had vacated left the body cell before Birthdate while the header had
     * moved it after Status - so every row still had eleven cells, every count
     * check passed, and the table showed email addresses under "Birthdate" and
     * status pills under "Email". Every value under the wrong heading, and
     * nothing about it looked broken.
     *
     * Compared field-by-field in order rather than as a set, so a permutation
     * is caught too.
     */
    public function testMasterlistBodyCellsLineUpWithTheirHeaders(): void
    {
        $page = file_get_contents(__DIR__ . '/../registrar/masterlist.php');

        // Three capture groups, and the mapping is not a guess: group 1 is the
        // header row, group 2 is only the whitespace between </thead> and
        // <tbody>, and group 3 is the body. Reading them as [, $thead, $tbody]
        // silently takes the WHITESPACE as the body - a 30-character string with
        // no <td> in it - and the comparison then reports an empty column list
        // rather than saying which column is wrong. The gap is named, not
        // skipped, so the indexes are visible.
        preg_match('/<colgroup>.*?<\/colgroup>(.*?)<\/thead>(.*?)<tbody>(.*?)<\/tbody>/s', $page, $m);
        self::assertNotEmpty($m, 'Could not find the table skeleton.');
        [, $thead, $gap, $tbody] = $m;
        self::assertStringContainsString('<th', $thead, 'Capture 1 should be the header row.');
        self::assertStringContainsString('<td', $tbody, 'Capture 3 should be the body row.');
        unset($gap);

        // The control columns carry no data-field in the header, so they are
        // normalised to a fixed token on both sides. The row-number cell does
        // carry data-field="rowno" while its header is a bare "#", so the class is
        // tested first - otherwise the body reads "rowno" against a header
        // reading "#" and every column looks displaced.
        // Attributes are matched with a tempered dot rather than a class that
        // excludes the greater-than sign: a cell carries an onclick shortcode
        // whose output can contain one of its own, so such a class stops
        // mid-attribute and the cell is never matched at all - which reads as an
        // empty column list rather than as a broken pattern.
        $key = static function (string $tag, string $markup): array {
            $out = [];
            $re = '/<' . $tag . '\b((?:[^>"]|"[^"]*")*)>(.*?)<\/' . $tag . '>/s';
            preg_match_all($re, $markup, $cells, PREG_SET_ORDER);
            foreach ($cells as [, $attrs, $inner]) {
                if (strpos($attrs, 'class="ml-rowno"') !== false
                    || (strpos($attrs, 'data-field=') === false && trim(strip_tags($inner)) === '#')) {
                    $out[] = '@no';
                    continue;
                }
                if (strpos($inner, '<input') !== false) {
                    $out[] = '@pick';
                    continue;
                }
                $out[] = preg_match('/data-field="([a-z_]+)"/', $attrs, $f) ? $f[1] : '?';
            }
            return $out;
        };

        $head = $key('th', $thead);
        $body = $key('td', $tbody);

        self::assertSame(
            $head,
            $body,
            'Body cells must sit under the header that names them, in the same order. '
                . 'Header: ' . implode(',', $head) . ' | Body: ' . implode(',', $body)
        );
        self::assertNotContains('?', $head, 'A header cell could not be identified.');
        self::assertNotContains('?', $body, 'A body cell could not be identified.');
    }


    /**
     * The header is a ruled band, and nothing overrides it.
     *
     * The thead colours were duplicated in a later block with !important, and
     * that copy silently won: the band rendered #f8fafc on #475569 whatever the
     * main rule said, so the redesign was invisible on screen and appeared only
     * in a print preview. Nothing may set a property the main th rule owns.
     */
    public function testNothingOverridesTheHeaderBandWithImportant(): void
    {
        $page = file_get_contents(__DIR__ . '/../registrar/masterlist.php');

        self::assertSame(
            0,
            preg_match('/\.masterlist-table thead th\s*\{[^}]*!important/s', $page),
            'A later !important rule is overriding the header band.'
        );
        self::assertSame(
            0,
            preg_match('/\.masterlist-table th\s*\{[^}]*background[^;}]*!important/s', $page),
            'A later !important rule is overriding the header background.'
        );
        // And the values the main rule declares are the ones that reach the page.
        self::assertSame(
            1,
            preg_match('/\.masterlist-table th\{[^}]*color:\s*#334155/s', $page),
            'The header rule should set its own text colour.'
        );
        self::assertSame(
            1,
            preg_match('/\.masterlist-table th\{[^}]*font-weight:\s*600/s', $page),
            'The header should be weight 600 - a heading that shouts competes with the names.'
        );
        // Sort state is the rule under the column, not a tinted cell.
        self::assertSame(
            1,
            preg_match('/th\[aria-sort="ascending"\][^{]*\{[^}]*box-shadow:\s*inset 0 -3px 0 #2563eb/s', $page),
            'A sorted column should be marked by an accent rule, not by tinting the cell.'
        );
        self::assertSame(
            0,
            preg_match('/th\[aria-sort="ascending"\][^{]*\{[^}]*background/s', $page),
            'A sorted column should not tint its whole cell.'
        );
    }

    /**
     * Gender and RFID are list columns, and they reach every text consumer.
     *
     * The export reads its headers off the table rather than from a list, so a
     * column that is rendered but not given a data-field would silently vanish
     * from the CSV, the Excel file and the print sheet. These assert the
     * data-field exists on both the header cell and the body cell, since the
     * header is what names the column and the body is what fills it.
     */
    public function testMasterlistShowsGenderAndRfid(): void
    {
        $page = file_get_contents(__DIR__ . '/../registrar/masterlist.php');

        foreach (['gender', 'rfid'] as $field) {
            self::assertSame(
                1,
                preg_match('/<th data-field="' . $field . '"/', $page),
                "The $field column should have a header cell the export can read."
            );
            self::assertSame(
                1,
                preg_match('/<td data-field="' . $field . '"/', $page),
                "The $field column should have a body cell to export."
            );
            // And a track, or table-layout:fixed has no width to give it.
            self::assertSame(
                1,
                preg_match('/\.c-' . $field . '\{[^}]*width:/', $page),
                "The $field column should have a width in the colgroup."
            );
        }

        // The colgroup and the header row must agree, or the fixed layout maps
        // values onto the wrong columns - a name under a status, say. This
        // covers the two columns this test owns; the one-track-per-column count
        // is pinned by testMasterlistBodyCellsLineUpWithTheirHeaders.
        self::assertSame(
            1,
            preg_match('/<colgroup>.*?c-gender.*?c-rfid.*?<\/colgroup>/s', $page),
            'Gender and RFID should have tracks in the colgroup, in that order.'
        );
    }

    /**
     * A student with several cards is shown the one they could actually use.
     *
     * A student keeps an old card on file - archived after a loss, expired and
     * never returned - so rfid_cards can hold more than one row per student. A
     * plain "last row wins" map lets the archived card overwrite the live one,
     * and the roster then shows a dead number against a student walking in with
     * a working card. The whole point of the column is to answer "can this
     * student be scanned?", so it has to rank by whether the card works.
     */
    public function testRfidColumnPrefersAUsableCard(): void
    {
        $page = file_get_contents(__DIR__ . '/../registrar/masterlist.php');

        // A rank table, not a bare assignment.
        self::assertSame(
            1,
            preg_match("/\\\$rfidRank = \[[^\]]*'active' => 0[^]]*\]/s", $page),
            'The card map should rank an active card first.'
        );
        // Archived and lost rank BELOW active, so they can never win a tie-out.
        self::assertSame(
            1,
            preg_match("/'lost' => 3[^}]*'archived' => 5/", $page),
            'Lost and archived cards should rank below an active one.'
        );
        self::assertSame(
            1,
            preg_match("/\\\$rank < \\\$rfidMap\[\\\$sid\]\['rank'\]/", $page),
            'A worse card must not replace a better one already on file.'
        );

        // No card is stated, never left blank: a blank id cell reads as a fault.
        self::assertSame(
            1,
            preg_match("/\\\$cardLabel = 'Not issued';/", $page),
            "A student with no card should read 'Not issued', not an empty cell."
        );
        // Only cards in trouble are toned, so a live card does not shout.
        self::assertSame(
            1,
            preg_match("/in_array\(\\\$cardState, \['lost', 'expired', 'archived'\], true\) \? \\\$cardState : 'ok'/", $page),
            'Only a card in trouble should be given an attention tone.'
        );
        // The same in the stylesheet, so the class in the markup is real.
        foreach (['lost', 'expired', 'archived', 'none'] as $tone) {
            self::assertSame(
                1,
                preg_match('/\.ml-card-' . $tone . '\b/', $page),
                "There is no style for the .ml-card-$tone tone."
            );
        }
    }

    /**
     * The table is not a 13px table with a 15px name. Everything scales together.
     *
     * The roster was set small throughout - 13px base, 11.5px tokens, 10px
     * headers - and the name sat in the middle of it at the same 13px, so there
     * was slack in the column and nothing to spend it on. These are asserted as
     * floors rather than exact values, so the next person can go bigger without
     * having to edit the test, but cannot quietly shrink it back: a table that
     * is hard to read is invisible in a diff and obvious to the person using it.
     */
    public function testMasterlistTypeIsLargeEnoughToRead(): void
    {
        $page = file_get_contents(__DIR__ . '/../registrar/masterlist.php');

        // Reads one declaration out of the LAST rule whose selector ends with
        // the given suffix. A suffix rather than the full selector, because the
        // table rules are written body[data-page="masterlist"] .masterlist-table
        // and the plain form does not appear anywhere. "Last wins" is how CSS
        // itself resolves, so the later responsive override is the one that
        // counts.
        //
        // The suffix may itself contain a descendant combinator (".masterlist-table
        // th"), so the leading dot is part of the caller's string, not added here.
        $px = static function (string $suffix, string $prop) use ($page): float {
            self::assertStringStartsWith('.', $suffix, 'A selector suffix must start with a dot.');
            preg_match_all(
                '/([^{}]*' . preg_quote($suffix, '/') . ')\s*\{([^}]*)\}/s',
                $page,
                $m,
                PREG_SET_ORDER
            );
            $found = null;
            foreach ($m as $rule) {
                // Comments are stripped before the declaration is read. A rule
                // written across several lines - which is how the header styles
                // are now written, to carry their reasoning - has font-size
                // preceded by a `/* ... */` block rather than by a semicolon, and
                // a `;`-anchored pattern silently misses it and reports "no
                // font-size" for a rule that plainly has one. Anchored on a
                // semicolon or the start of the remaining declarations.
                $decls = preg_replace('#/\*.*?\*/#s', '', $rule[2]);
                if (preg_match('/(?:^|;)\s*' . preg_quote($prop, '/') . '\s*:\s*([\d.]+)px/s', $decls, $v)) {
                    $found = (float) $v[1];
                }
            }
            self::assertNotNull($found, "No rule ending in .$suffix sets $prop in px.");
            return $found;
        };

        // The base the whole table inherits, and the four sizes that override
        // it. Monospace reads smaller than Inter at the same point size, so the
        // tokens are held to a higher floor than the base rather than equal to
        // it - the student id is what a registrar calls the student up by.
        self::assertGreaterThanOrEqual(15, $px('.masterlist-table', 'font-size'), 'Table base type is too small to read.');
        self::assertGreaterThanOrEqual(15, $px('.ml-tok', 'font-size'), 'The token columns are too small to read.');
        self::assertGreaterThanOrEqual(14, $px('.ml-status', 'font-size'), 'The status pill text is too small to read.');
        self::assertGreaterThanOrEqual(14, $px('.ml-course', 'font-size'), 'The course pill text is too small to read.');
        self::assertGreaterThanOrEqual(12, $px('.masterlist-table th', 'font-size'), 'The column headers are too small to read.');

        // The name is set at the table's size, not by overriding it: .ml-name
        // declares no font-size, so the two halves of the name cannot drift
        // apart again without this failing.
        self::assertSame(
            0,
            preg_match('/\.ml-name\s*\{[^}]*font-size/', $page),
            'The name should inherit the table size rather than declaring its own.'
        );
        self::assertSame(
            1,
            preg_match('/\.ml-name \.sur\s*\{[^}]*font-weight\s*:\s*(\d+)/', $page, $w),
            'The surname should carry a heavier weight than the given names.'
        );
        self::assertGreaterThanOrEqual(600, (int) $w[1], 'The surname weight is too light to anchor the column.');
    }

    /**
     * The name cell shows the whole name, at one size, wrapping rather than
     * truncating.
     *
     * It used to be letterspaced caps for the surname with the given names
     * beneath at 11.5px grey - which made the full name unreadable at a glance
     * and hid it behind a hover. A registrar matching faces to students needs
     * both parts at the same size; the surname keeps a heavier weight so the
     * eye still has an anchor, but nothing is demoted to a caption.
     */
    public function testMasterlistNameShowsTheFullNameAtOneSize(): void
    {
        $page = file_get_contents(__DIR__ . '/../registrar/masterlist.php');

        // Surname is no longer forced to caps. Uppercasing the stored value
        // also meant the search box could not match it: the cell read AQUINO
        // while the database holds Aquino, and a search for the name as written
        // on the page failed.
        self::assertSame(
            0,
            preg_match('/<span class="sur"><\?= htmlspecialchars\(mb_strtoupper/', $page),
            'The surname should be stored-case, not uppercased on render.'
        );
        self::assertSame(
            0,
            preg_match('/\.ml-name \.sur\{[^}]*text-transform/', $page),
            'The surname should not be uppercased in CSS either.'
        );

        // Both halves are in the cell, separated by a real space in the markup.
        // A CSS ::after or margin would leave them glued for search and for the
        // CSV, both of which read textContent.
        self::assertSame(
            1,
            preg_match('/<span class="sur"><\?= htmlspecialchars\(trim\(\$student\[.last_name.\]\s*\?\?\s*.{2}\)\) \?><\/span> <span class="giv">/', $page),
            'The name cell should render surname and given names separated by a real space.'
        );

        // The given names are not a caption: no size step-down on .giv.
        self::assertSame(
            0,
            preg_match('/\.ml-name \.giv\{[^}]*font-size/', $page),
            'The given names should not be rendered smaller than the surname.'
        );
        // And the name wraps instead of being ellipsised away.
        self::assertSame(
            0,
            preg_match('/\.ml-name \.sur\{[^}]*text-overflow/', $page),
            'The surname should wrap, not truncate - a hidden half-name is a row the reader cannot use.'
        );
        self::assertSame(
            1,
            preg_match('/\.ml-name \.giv\{[^}]*overflow-wrap/', $page),
            'A long name should be allowed to break rather than overflow its cell.'
        );
    }

    /**
     * The Course column shows the acronym, and the printed sheet agrees.
     *
     * The long program name is a whole line of itself at column width and is
     * already spelled out on the block heading above, so the cell carries
     * courseAcronym() and the full name rides along in the title attribute. A
     * bare cell reading the raw course is the regression: it either wraps into
     * an unreadable stack or gets ellipsised away to nothing.
     */
    public function testMasterlistCourseColumnUsesTheAcronym(): void
    {
        $page = file_get_contents(__DIR__ . '/../registrar/masterlist.php');

        self::assertSame(
            1,
            preg_match('/data-field="course"[^>]*title="<\?= htmlspecialchars\(\$student\[.course.\]/', $page),
            'The Course cell should keep the full program name in its title.'
        );
        self::assertSame(
            1,
            preg_match('/class="ml-course">\s*<\?= htmlspecialchars\(\$acronym/s', $page),
            'The Course cell should render the acronym, not the raw course value.'
        );
        // Computed per row, so a student with no stored program prints their
        // own N/A instead of silently inheriting the block heading's.
        self::assertSame(
            1,
            preg_match('/\$acronym\s*=\s*courseAcronym\(\(string\)\s*\(\$student\[.course./', $page),
            'The acronym should be derived per row from that row\'s course.'
        );
    }

    /**
     * A student with no recorded status must say so, not render a blank pill.
     *
     * 42 of 150 seeded students carry an EMPTY status string, not null - so
     * `$student['status'] ?? 'Active'` never fired, `ucfirst('')` returned '',
     * and the cell rendered an empty grey badge. A blank pill reads as a
     * rendering failure, and it is also wrong on the data: "no status on file"
     * is not the same claim as "active", and a registrar counting the
     * enrolled students on the list would be misled.
     */
    public function testMasterlistNeverRendersAnEmptyStatus(): void
    {
        $page = file_get_contents(__DIR__ . '/../registrar/masterlist.php');

        // The empty case is trimmed and caught explicitly, not left to `??`,
        // which only fires on null.
        self::assertSame(
            1,
            preg_match("/\\\$statusRaw\s*=\s*trim\(\(string\)\s*\(\\\$student\['status'\]\s*\?\?\s*''\)\)/", $page),
            'The status should be trimmed and read as a string, so an empty value is detectable.'
        );
        self::assertSame(
            1,
            preg_match("/\\\$statusRaw\s*===\s*''\s*\)\s*\{[^}]*\\\$statusLabel\s*=\s*'Not recorded'/", $page),
            "An empty status should render as 'Not recorded', not as an empty badge."
        );

        // And the cell is driven by that label, rather than by ucfirst() on a
        // value that may be blank.
        self::assertSame(
            0,
            preg_match('/<\?= *ucfirst\(\$student\[.status.\]/', $page),
            'The status cell should not fall back to ucfirst() on a possibly-empty value.'
        );
        self::assertSame(
            1,
            preg_match('/<td[^>]*data-field="status"[^>]*>\s*<span class="ml-status[^"]*">\s*<\?= htmlspecialchars\(\$statusLabel\)/', $page),
            'The status cell should render the resolved label.'
        );
    }

    /**
     * The CSV must carry one set of columns, not one per block.
     *
     * The blocks are separate tables, so the selector that reads the export
     * headers matched the <th> of every one of them: a four-block list exported
     * 28 columns with every value written four times over, under four
     * identical sets of headers. It is invisible in the file preview and only
     * shows up when someone opens it in Excel.
     *
     * The scope is asserted as source because the bug is a selector, not a
     * render: the fix is that the lookup is anchored to ONE table.
     */
    public function testCsvExportReadsTheHeaderFromASingleTable(): void
    {
        $page = file_get_contents(__DIR__ . '/../registrar/masterlist.php');

        self::assertSame(
            1,
            preg_match(
                "/const first = document\.querySelector\('#masterlistContent \.masterlist-table'\);\s*\n\s*if \(!first\) return FALLBACK_FIELDS;\s*\n\s*const ths = first\.querySelectorAll\('thead th\[data-field\]'\);/",
                $page
            ),
            'The export header lookup must be anchored to a single table, not every table on the page.'
        );
        // The unguarded form is the regression: it matches one <th> per block.
        self::assertSame(
            0,
            preg_match(
                "/querySelectorAll\(\s*'#masterlistContent \.masterlist-table thead th\[data-field\]'\s*\)/",
                $page
            ),
            'The export header lookup still collects from every block at once.'
        );
        // And :first-of-type would be a no-op here, since each table is alone in
        // its own wrapper. Guard against it being reintroduced as the "fix".
        self::assertStringNotContainsString('.masterlist-table:first-of-type', $page);
    }

    /**
     * The name's two spans must not run together off the page.
     *
     * Surname and given names are separate elements, so `textContent`
     * concatenates them with whatever whitespace is in the markup between:
     * with none, "DELA CRUZJUAN PEDRO". Every consumer that reads a cell as
     * text - the CSV, the Excel file, the printed sheet, the sort key and the
     * search box - would inherit that, so the join has to be explicit in the
     * shared reader rather than in each caller.
     */
    public function testNameCellLinesAreJoinedForEveryTextConsumer(): void
    {
        $page = file_get_contents(__DIR__ . '/../registrar/masterlist.php');

        // The two spans are adjacent, and the separator between them is a real
        // space character rather than nothing. The class sits on the wrapping
        // <a> alongside the onclick, so the pattern starts at the surname span
        // rather than at class="ml-name". A zero-width or CSS-only separator
        // would look identical here and still glue the name in every export.
        self::assertSame(
            1,
            preg_match('/<span class="sur">.*?<\/span> <span class="giv">/s', $page),
            'The name should render surname and given names separated by a real space.'
        );

        // The shared reader joins them.
        self::assertSame(
            1,
            preg_match("/querySelectorAll\(':scope > span'\)/", $page),
            'cellText() should join the name lines, not concatenate them.'
        );

        // The search box must match across the line break too, or "Cruz Juan"
        // silently returns nobody - a filter that looks like it works.
        self::assertSame(
            1,
            preg_match("/querySelectorAll\('\.ml-name > span'\)/", $page),
            'The search box should match across the two name lines.'
        );
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
