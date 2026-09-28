<?php

use PHPUnit\Framework\TestCase;

/**
 * The RFID Edit modal.
 *
 * These are source-level assertions on purpose. The failure this guards against
 * is silent: the page used to PUT `status_reason`, which is not in the API's
 * $allowedFields, so the server filtered it out and still returned success. The
 * registrar picked a reason, saw "Saved.", and nothing was ever written. A
 * behavioural test through the HTTP layer would need a database and an
 * authenticated session to catch that, so instead the payload keys are read out
 * of the page and checked against the allow-list read out of the API. If either
 * side drifts, this fails.
 */
final class RfidEditModalTest extends TestCase
{
    private const PAGE = __DIR__ . '/../registrar/rfid-cards.php';
    private const API  = __DIR__ . '/../api/rfid.php';
    private const SCHEMA = __DIR__ . '/../registrar_ai.sql';

    private static function page(): string
    {
        return file_get_contents(self::PAGE);
    }

    /** The fields the API will actually persist on a PUT. */
    private static function apiAllowedFields(): array
    {
        $api = file_get_contents(self::API);
        self::assertSame(
            1,
            preg_match('/\$allowedFields\s*=\s*\[(.*?)\]/s', $api, $m),
            'Could not find $allowedFields in api/rfid.php — the allow-list moved.'
        );
        preg_match_all("/'([a-z_]+)'/", $m[1], $f);

        return $f[1];
    }

    /** The keys in the Edit modal's PUT request body. */
    private static function editPutPayload(): array
    {
        $page = self::page();
        // Isolate the edit form's submit handler so we do not pick up the
        // register/assign/import handlers, which legitimately POST other keys.
        self::assertSame(
            1,
            preg_match("/addEventListener\('submit'.*?editCardId.*?JSON\.stringify\(\{(.*?)\}\)/s", $page, $m),
            'Could not find the Edit modal PUT payload in registrar/rfid-cards.php.'
        );

        preg_match_all('/^\s*([a-z_]+):/m', $m[1], $k);

        return $k[1];
    }

    public function testEditModalSendsTheReasonUnderTheNameTheApiStores(): void
    {
        self::assertContains(
            'archive_reason',
            self::editPutPayload(),
            'The Edit modal must PUT archive_reason — that is the column rfid_cards has.'
        );
    }

    public function testEditModalNeverSendsTheSilentlyDroppedStatusReasonKey(): void
    {
        self::assertNotContains(
            'status_reason',
            self::editPutPayload(),
            '`status_reason` is not in the API $allowedFields. The server drops it '
            . 'without error, so the chosen reason is never saved.'
        );
    }

    /**
     * The general guard: any key the page sends must be one the API persists.
     * This is the assertion that would have caught the original bug without
     * anyone having to know the column name.
     */
    public function testEveryKeyTheEditModalSendsIsAcceptedByTheApi(): void
    {
        $allowed = self::apiAllowedFields();
        $sent = self::editPutPayload();

        self::assertNotEmpty($sent, 'No payload keys parsed — the test is not looking at real code.');

        $rejected = array_values(array_diff($sent, $allowed));
        self::assertSame(
            [],
            $rejected,
            'The Edit modal sends keys the API will silently discard: ' . implode(', ', $rejected)
        );
    }

    public function testEditModalReadsBackTheStoredReasonColumn(): void
    {
        $page = self::page();
        // Scoped to openEditModal, so the View modal's own (separate, still
        // unfixed) status_reason read does not mask a regression here.
        self::assertSame(
            1,
            preg_match('/async function openEditModal.*?\n\}/s', $page, $m),
            'Could not find openEditModal.'
        );
        // Strip whole-line comments first: the handler explains the old
        // c.status_reason bug in a comment, and a naive substring check would
        // match its own explanation.
        $code = preg_replace('/^\s*\/\/.*$/m', '', $m[0]);

        self::assertStringContainsString(
            'c.archive_reason',
            $code,
            'openEditModal must read c.archive_reason to show the reason already on record.'
        );
        self::assertStringNotContainsString(
            'c.status_reason',
            $code,
            'c.status_reason does not exist on the API response, so it always read as undefined.'
        );
    }

    public function testStatusAndReasonAreNoLongerTwoIndependentControls(): void
    {
        $page = self::page();
        // The old modal had a Status select and a Reason select, which could
        // save status=active together with reason=graduated.
        self::assertStringNotContainsString('id="editStatus"', $page, 'The standalone Status select should be gone.');
        self::assertStringNotContainsString('id="editStatusReason"', $page, 'The standalone Reason select should be gone.');
        self::assertStringContainsString('name="editOutcome"', $page, 'Choices should drive status and reason together.');
    }

    /** outcome key => [status, reason] */
    private static function outcomes(): array
    {
        $page = self::page();
        self::assertSame(
            1,
            preg_match('/const EDIT_OUTCOMES = \{(.*?)\n\};/s', $page, $m),
            'Could not find EDIT_OUTCOMES.'
        );
        preg_match_all("/(\w+):\s*\{\s*status:\s*'([^']+)',\s*reason:\s*'([^']+)'/", $m[1], $o, PREG_SET_ORDER);

        $out = [];
        foreach ($o as $row) {
            $out[$row[1]] = ['status' => $row[2], 'reason' => $row[3]];
        }
        return $out;
    }

    /** The live rfid_cards.status enum, read from the schema dump. */
    private static function statusEnum(): array
    {
        $sql = file_get_contents(self::SCHEMA);
        self::assertSame(
            1,
            preg_match('/CREATE TABLE `rfid_cards`.*?`status`\s+enum\(([^)]*)\)/s', $sql, $m),
            'Could not find the rfid_cards.status enum in registrar_ai.sql.'
        );
        preg_match_all("/'([^']+)'/", $m[1], $v);

        return $v[1];
    }

    /**
     * MySQL in strict mode rejects a value outside the enum. A wrong status here
     * means the whole update throws and the registrar's edit is lost.
     */
    public function testEveryOutcomeUsesAStatusTheColumnAccepts(): void
    {
        $enum = self::statusEnum();
        foreach (self::outcomes() as $key => $o) {
            self::assertContains(
                $o['status'],
                $enum,
                "Outcome '$key' writes status '{$o['status']}', which is not in the rfid_cards.status enum."
            );
        }
    }

    /**
     * Two tiles writing the same reason would make a saved card ambiguous to
     * read back, since matchEditOutcome() can only preselect one of them.
     */
    public function testNoTwoOutcomesShareARetirementReason(): void
    {
        $reasons = array_column(self::outcomes(), 'reason');
        self::assertSame(
            array_values(array_unique($reasons)),
            array_values($reasons),
            'Two outcomes write the same archive_reason, so a saved card cannot be read back unambiguously.'
        );
    }

    /** Every reason the modal can write must have a label for the history line. */
    public function testEveryOutcomeReasonHasAReadableLabel(): void
    {
        $page = self::page();
        self::assertSame(
            1,
            preg_match('/const EDIT_REASON_LABELS = \{(.*?)\n\};/s', $page, $m),
            'Could not find EDIT_REASON_LABELS.'
        );
        foreach (self::outcomes() as $key => $o) {
            self::assertMatchesRegularExpression(
                "/'" . preg_quote($o['reason'], '/') . "'\s*:/",
                $m[1],
                "Outcome '$key' writes reason '{$o['reason']}', which has no label — the "
                . '"Already recorded" line would show the raw token.'
            );
        }
    }

    /**
     * applyEditOutcome() builds the effect line's class as 'is-' + o.effect, so
     * every effect value must have a matching `.rc-effect.is-*` rule. This
     * shipped once as 'retire'/'warn' against CSS written for
     * 'retiring'/'warning', which silently left the consequence line in its
     * default blue for every retirement.
     */
    public function testEveryEffectToneHasAMatchingCssRule(): void
    {
        $page = self::page();

        // preg_match_all returns a count, not a boolean, so assert it found some.
        self::assertGreaterThan(
            0,
            preg_match_all("/(\w+):\s*\{\s*status:\s*'[^']+',\s*reason:\s*'[^']+',[^\n]*effect:\s*'(\w+)'/", $page, $m, PREG_SET_ORDER),
            'Could not parse effect tones out of EDIT_OUTCOMES.'
        );

        $tones = array_unique(array_column($m, 2));
        self::assertNotEmpty($tones);

        foreach ($tones as $tone) {
            if ($tone === 'keep') {
                // The default style, deliberately unclassed.
                continue;
            }
            self::assertMatchesRegularExpression(
                '/#editModal \.rc-effect\.is-' . preg_quote($tone, '/') . '\s*\{/',
                $page,
                "Effect tone '$tone' produces the class is-$tone, but no .rc-effect.is-$tone "
                . 'CSS rule exists, so the consequence line will not be styled.'
            );
        }
    }

    /** The tile markup and the effect tones must use the same vocabulary. */
    public function testTileToneClassesMatchTheEffectTones(): void
    {
        $page = self::page();
        preg_match_all('/class="rc-choice ([a-z-]+)"/', $page, $tiles);
        // The markup carries the full class, e.g. "is-retiring"; EDIT_OUTCOMES
        // stores the bare tone, e.g. "retiring".
        $tileTones = array_values(array_unique(array_map(
            static fn (string $c): string => preg_replace('/^is-/', '', $c),
            $tiles[1]
        )));

        preg_match_all("/effect:\s*'(\w+)'/", $page, $e);
        $tones = array_unique($e[1]);
        // 'keep' has no tile tone: "Still in use" is the neutral, unstyled tile.
        $expected = array_values(array_diff($tones, ['keep']));

        sort($tileTones);
        sort($expected);
        self::assertSame(
            $expected,
            $tileTones,
            'The tone classes on the tiles and the effect tones in EDIT_OUTCOMES have drifted apart.'
        );
    }
}
