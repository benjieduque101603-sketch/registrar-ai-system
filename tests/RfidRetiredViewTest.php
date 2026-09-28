<?php

use PHPUnit\Framework\TestCase;

/**
 * The Live / Retired split on the RFID cards page.
 *
 * Retiring a card through the Edit modal sets its status, and the page then
 * shows it in a separate group behind a toggle rather than among the live
 * cards. The grouping is defined twice - once in PHP, once in JavaScript - and
 * the JavaScript side is what actually decides which rows a registrar sees. If
 * those two lists drift, cards silently appear in the wrong table.
 */
final class RfidRetiredViewTest extends TestCase
{
    private const PAGE = __DIR__ . '/../registrar/rfid-cards.php';
    private const SCHEMA = __DIR__ . '/../registrar_ai.sql';

    private static function page(): string
    {
        return file_get_contents(self::PAGE);
    }

    /** Statuses the PHP split puts in the retired group. */
    private static function phpRetiredStatuses(): array
    {
        $page = self::page();
        self::assertSame(
            1,
            preg_match('/\$RETIRED_STATUSES\s*=\s*\[(.*?)\]/s', $page, $m),
            'Could not find $RETIRED_STATUSES.'
        );
        preg_match_all("/'([a-z]+)'/", $m[1], $s);

        return $s[1];
    }

    /** Statuses the JavaScript view logic puts in the retired group. */
    private static function jsRetiredStatuses(): array
    {
        $page = self::page();
        self::assertSame(
            1,
            preg_match('/RFID_RETIRED_STATUSES\s*=\s*\[(.*?)\]/s', $page, $m),
            'Could not find RFID_RETIRED_STATUSES.'
        );
        preg_match_all("/'([a-z]+)'/", $m[1], $s);

        return $s[1];
    }

    /**
     * The load-bearing assertion. PHP decides which rows get data-retired="1";
     * JavaScript decides which of those rows are visible. If the two disagree,
     * either a retired card is invisible or a live one is hidden.
     */
    public function testPhpAndJavaScriptAgreeOnWhichStatusesAreRetired(): void
    {
        self::assertSame(
            self::phpRetiredStatuses(),
            self::jsRetiredStatuses(),
            'The PHP split and the JS view filter list different statuses, so cards '
            . 'will land in the wrong table.'
        );
    }

    public function testRetiredGroupExcludesLiveStatuses(): void
    {
        $retired = self::phpRetiredStatuses();
        self::assertNotEmpty($retired);
        // active must never be retired, or every live card would vanish.
        self::assertNotContains('active', $retired);
        // available is the unassigned pool, not a retirement.
        self::assertNotContains('available', $retired);
    }

    /** Retiring a card must not invent a status the column will reject. */
    public function testEveryRetiredStatusExistsInTheStatusEnum(): void
    {
        $sql = file_get_contents(self::SCHEMA);
        self::assertSame(
            1,
            preg_match('/CREATE TABLE `rfid_cards`.*?`status`\s+enum\(([^)]*)\)/s', $sql, $m),
            'Could not find the rfid_cards.status enum.'
        );
        preg_match_all("/'([^']+)'/", $m[1], $v);

        foreach (self::phpRetiredStatuses() as $s) {
            self::assertContains($s, $v[1], "'$s' is treated as retired but is not a valid rfid_cards.status.");
        }
    }

    /** The status filter must be able to reach the retired group on its own. */
    public function testStatusFilterOffersEveryRetiredStatus(): void
    {
        $page = self::page();
        foreach (self::phpRetiredStatuses() as $s) {
            self::assertMatchesRegularExpression(
                '/<option value="' . preg_quote($s, '/') . '"/',
                $page,
                "The status filter has no '$s' option, so a retired card could not be filtered for."
            );
        }
    }


    /**
     * The standalone archive path is gone. It wrote free text ("Graduated",
     * "Card Lost") into archive_reason while the Edit modal writes tokens
     * ("graduated", "lost_card"), so two vocabularies could land in one column.
     */
    public function testTheStandaloneArchivePathIsRemoved(): void
    {
        $page = self::page();
        $gone = ['openArchiveModal', 'archiveReason', 'archiveConfirm', 'archiveCancel', 'archiveMessage', 'id="archiveModal"'];
        foreach ($gone as $needle) {
            self::assertStringNotContainsString($needle, $page, "'$needle' should have been removed with the archive modal.");
        }
    }

    public function testCardRowsNoLongerOfferArchiveOrDelete(): void
    {
        $page = self::page();
        // The card table row renders inside the $tableCards/$retiredCards loop.
        $start = strpos($page, '<?php foreach (array_merge($tableCards, $retiredCards)');
        $end = strpos($page, '<?php endforeach; ?>', $start);
        self::assertNotFalse($start, 'Could not find the card row loop.');
        $row = substr($page, $start, $end - $start);

        self::assertStringNotContainsString('openArchiveModal', $row, 'Card rows must not offer Archive.');
        self::assertStringNotContainsString('confirmDelete', $row, 'Card rows must not offer Delete.');
        // The Edit button is the one way to change a card now.
        self::assertStringContainsString('openEditModal', $row);
    }

    /** Hard delete is still needed on the unassigned pool - clearing bad stock. */
    public function testTheUnassignedPoolStillHasItsClearButton(): void
    {
        self::assertStringContainsString(
            'confirmDelete',
            self::page(),
            'The pool still needs a way to remove an unassigned card; only the card table lost Delete.'
        );
    }

    /** Every row must carry the flag the view filter keys off. */
    public function testEveryRenderedCardRowCarriesTheRetiredFlag(): void
    {

        $page = self::page();
        self::assertStringContainsString(
            'data-retired="<?= $isRetired ? \'1\' : \'0\' ?>"',
            $page,
            'Card rows must carry data-retired, or the view filter cannot tell them apart.'
        );
        self::assertStringContainsString(
            "\$isRetired = in_array(\$card['status'], \$RETIRED_STATUSES, true);",
            $page,
            'The row flag must be derived from $RETIRED_STATUSES, not hardcoded.'
        );
    }


    /**
     * The view control is a two-segment switch, not a button that relabels
     * itself. A single control has to read "Show retired" and then "Show live",
     * so the reader re-reads it on every click and the retired count stays
     * hidden until they go looking for it. Both segments must therefore be
     * present at once, each carrying its own count.
     */
    public function testTheViewControlIsATwoSegmentSwitchNotARelabellingButton(): void
    {
        $page = self::page();

        self::assertStringContainsString('class="rfid-view-switch"', $page, 'The view control should be a segmented switch.');
        self::assertStringNotContainsString('rfid-view-toggle', $page, 'The old single relabelling button should be gone.');

        foreach (['active' => 'Live', 'retired' => 'Retired'] as $view => $label) {
            self::assertMatchesRegularExpression(
                '/data-view="' . $view . '"[^>]*>.*?' . $label . '/s',
                $page,
                "The switch is missing its '$view' segment."
            );
        }

        // Both counts are rendered server-side, so neither group is a blind spot.
        self::assertStringContainsString('id="rfidCountActive"', $page);
        self::assertStringContainsString('id="rfidCountRetired"', $page);
    }

    /**
     * The header is a plain block by default, so a sibling control drops onto
     * its own line underneath the heading. It has to be a flex row, or the
     * switch ends up stacked and looks like an orphan.
     */
    public function testTheTableHeaderLaysOutAsARowSoTheSwitchSitsOppositeTheHeading(): void
    {
        $page = self::page();
        self::assertMatchesRegularExpression(
            '/body\[data-page="rfid"\] \.rfid-table-header\{[^}]*display:flex[^}]*justify-content:space-between/s',
            $page,
            'The table header must be a flex row with the switch pushed to the right, '
            . 'otherwise the switch stacks under the heading.'
        );
    }
}
