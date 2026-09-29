<?php

use PHPUnit\Framework\TestCase;

/**
 * The RFID ID card: a missing photo and a missing QR file.
 *
 * Both failures reported from production are the same failure wearing two
 * costumes. uploads/ is gitignored - photos and QR SVGs are runtime artifacts
 * written by the host that issues the card - so a database whose rows were
 * created on one server names files that were never deployed to the next. The
 * page then asks the browser for files that cannot exist:
 *
 *     /uploads/ids/id_1_1790314776.svg            404   (QR)
 *     uploads/students/..._1790314810.jpg         404   (photo)
 *
 * and the photo one had a second-order effect that made the QR look guilty.
 *
 * Part 1 - the crash. The card photo read:
 *
 *     <img id="cardPhoto" src="" onerror="showIdInitialsFallback()">
 *
 * An empty src is not "no source". The browser resolves it to the document
 * URL, fails to decode the response as an image, and fires error WHILE
 * PARSING - hundreds of lines before the <script> that defines
 * showIdInitialsFallback has run. So the handler threw:
 *
 *     Uncaught ReferenceError: showIdInitialsFallback is not defined
 *
 * and the initials fallback it existed to draw never appeared. display:none
 * does not help: a hidden image is still fetched. The markup must carry no src
 * at all, and the handler must be attached in viewIdCard() where the src is
 * assigned.
 *
 * Part 2 - the 404s. Branching on the raw qr_code_path column chose the
 * <img src> branch for a file that was never deployed. resolveStudentQrUrl()
 * now returns '' for a file that is not on disk, so the pages must branch on
 * the resolved URL and fall through to the in-browser generator they already
 * ship for cards that never had a file.
 *
 * Source-level, like the other RFID tests: the defect is a name in a query
 * and an attribute in a template, and the interesting assertion ("this
 * filename is never emitted") needs a database whose rows point at files that
 * do not exist - precisely the state a dev machine cannot reproduce by
 * setting itself up properly.
 */
final class RfidQrFallbackTest extends TestCase
{
    private static function read(string $rel): string
    {
        $p = dirname(__DIR__) . '/' . $rel;
        self::assertFileExists($p);
        return (string) file_get_contents($p);
    }

    private static function page(): string
    {
        return self::read('registrar/rfid-cards.php');
    }

    /**
     * The page with comment-only lines removed.
     *
     * This file documents the markup it replaced, and that documentation quotes
     * the old <img> verbatim:
     *
     *     //     <img id="cardPhoto" src="" onerror="showIdInitialsFallback()">
     *
     * A regex over the raw source therefore "finds" the very markup the fix
     * removed and the test fails against correct code - and worse, a future
     * edit that shortened the comment would turn the test green again. The
     * assertions are about what is served, so they read what is served.
     *
     * Only whole-line comments are dropped. Blanking an inline // would eat the
     * https:// in a script src, which is precisely the kind of clever this file
     * does not need.
     */
    private static function markup(): string
    {
        $out = [];
        foreach (explode("\n", self::page()) as $line) {
            $t = ltrim($line);
            if ($t === '' || $t[0] === '/' || $t[0] === '*') {
                continue;
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    // -- 1. The photo: no empty src, no inline onerror --------------------

    public function testCardPhotoImgHasNoSrcAttributeAtAll(): void
    {
        // An <img> with no src does not fire error. One with src="" always does.
        self::assertSame(
            1,
            preg_match('/<img[^>]*\bid="cardPhoto"[^>]*>/', self::markup(), $m),
            'Could not find the cardPhoto <img> - the ID card markup moved.'
        );
        self::assertStringNotContainsString(
            'src=',
            $m[0],
            'cardPhoto must carry no src attribute. An empty src fires error during parsing.'
        );
    }

    public function testCardPhotoHasNoInlineOnerrorHandler(): void
    {
        self::assertSame(
            1,
            preg_match('/<img[^>]*\bid="cardPhoto"[^>]*>/', self::markup(), $m)
        );
        self::assertStringNotContainsString(
            'onerror',
            $m[0],
            'An inline onerror runs before this page\'s <script> defines what it calls.'
        );
    }

    public function testTheFallbackIsAttachedWhereTheSrcIsAssigned(): void
    {
        $page = self::page();
        self::assertStringContainsString('img.onerror = function () {', $page);
        self::assertStringContainsString('showIdInitialsFallback();', $page);

        // ...and inside viewIdCard(), not somewhere it can never run.
        self::assertSame(
            1,
            preg_match('/function viewIdCard\([^)]*\)\s*\{(.*?)\n\}/s', $page, $m),
            'viewIdCard() moved.'
        );
        self::assertStringContainsString('img.onerror = function () {', $m[1]);
        self::assertStringContainsString('showIdInitialsFallback();', $m[1]);
    }

    public function testTheFallbackFunctionStillExists(): void
    {
        // The handler was never missing from the code - it was missing at the
        // moment it was called. Both facts have to hold.
        self::assertStringContainsString('function showIdInitialsFallback()', self::page());
    }

    // -- 2. The QR: branch on the resolved URL, never on the column -------

    public function testQrResolverRefusesToPointAtAFileThatIsNotThere(): void
    {
        self::assertStringContainsString(
            'is_file(',
            self::read('shared/qr_generator.php'),
            'resolveStudentQrUrl() must check the file exists before handing back a URL.'
        );
    }

    /** @dataProvider qrPages */
    public function testQrBranchUsesTheResolvedUrlNotTheRawColumn(string $rel): void
    {
        $src = self::read($rel);

        // A guard that reads the column directly is the bug: it picks <img src>
        // for a filename this host does not have.
        self::assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*!?empty\s*\(\s*\$(?:card|i)\[.qr_code_path.\]\s*\)\s*\)\s*\)\s*\??\s*:/',
            $src,
            $rel . ' still branches on the raw qr_code_path column.'
        );
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$qrUrl\s*!==\s*[\'\"]{2}\s*\)\s*:/',
            $src,
            $rel . ' must branch on the resolved $qrUrl.'
        );
    }

    /** @dataProvider qrPages */
    public function testBothQrSurfacesAreResolved(string $rel): void
    {
        $src = self::read($rel);
        // The thumbnail AND the card-view data attribute. A raw value in either
        // one sends the browser to a file that 404s.
        self::assertGreaterThanOrEqual(
            2,
            substr_count($src, 'resolveStudentQrUrl('),
            $rel . ' must resolve the QR for both the thumbnail and the card view.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/data-qr="<\?=\s*htmlspecialchars\(\s*\$[ci]\[.qr_code_path/',
            $src,
            $rel . ' emits a raw qr_code_path into data-qr.'
        );
    }

    /** @return array<string, array{0:string}> */
    public function qrPages(): array
    {
        return [
            'RFID cards'       => ['registrar/rfid-cards.php'],
            'student ID cards' => ['registrar/student-ids.php'],
        ];
    }

    // -- 3. The generator both pages fall back on must still be loaded ----

    /** @dataProvider qrPages */
    public function testPageLoadsTheQrLibraryItsFallbackNeeds(string $rel): void
    {
        $src = self::read($rel);
        self::assertStringContainsString(
            'qrcode-generator',
            $src,
            $rel . ' falls back to generateQrDataUrl() but never loads the library behind it.'
        );
        self::assertStringContainsString('function generateQrDataUrl(', $src);
        self::assertStringContainsString('qr-auto', $src);
    }
}
