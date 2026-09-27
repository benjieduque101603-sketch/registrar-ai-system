<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../shared/document_templates.php';

/**
 * The N/A convention.
 *
 * Every template prints its full structure and fills missing fields with
 * the literal "N/A". Nothing is hidden and there is no "no data" empty
 * state, so a registrar can tell a genuinely empty record apart from a
 * rendering failure. These tests pin that behaviour, because a template
 * that quietly drops a section is the failure mode that matters.
 */
final class DocumentTemplateTest extends TestCase
{
    private const SKUS = [
        'DOC-TOR', 'DOC-COE', 'DOC-GM', 'DOC-DIPLOMA',
        'DOC-CTC', 'DOC-HD', 'DOC-CD',
    ];

    /** A context with no academic or source data at all. */
    private function emptyContext(string $sku = 'DOC-TOR'): array
    {
        return [
            'request' => [
                'id' => 1, 'request_id' => 'DOC-2026-0001', 'counter' => 2,
                'purpose' => null, 'requirement_file_path' => null,
                'claimed_at' => null, 'release_date' => null,
            ],
            'student' => [
                'last_name' => 'DELA CRUZ', 'first_name' => 'JUAN',
                'middle_name' => 'S', 'name_suffix' => 'JR.',
                'student_number' => '2024-00137',
                'course' => null, 'year_level' => null, 'section' => null,
                'graduation_date' => null, 'previous_school' => null,
                'last_year_level_completed' => null,
            ],
            'catalog' => ['sku' => $sku, 'name' => $sku],
            'terms' => [], 'total_units' => null, 'career_gwa' => null,
            'tor_remarks' => null,
            'moral_checks' => [
                'Pending disciplinary cases' => null,
                'Pending academic deficiency' => null,
                'Outstanding financial obligation' => null,
                'Administrative hold on file' => 'No',
                'Exit clearance' => null,
            ],
            'clearances' => [], 'clearance_done' => null,
            'ctc' => [], 'course_desc' => [],
            'signatory' => 'R. Bautista', 'logo_url' => null,
            'meta' => ['walkin_at' => null, 'walkin_by' => '', 'released_at' => null,
                       'released_by' => '', 'tickets' => '', 'sha256' => '', 'qr_img' => ''],
        ];
    }

    public function testEverySkuRendersTheAuthenticationBlock(): void
    {
        foreach (self::SKUS as $sku) {
            $html = dt_render($this->emptyContext($sku));

            self::assertStringContainsString('dt-auth', $html, "$sku missing the auth block");
            self::assertStringContainsString('dt-verify', $html, "$sku missing the verification strip");
            // The rule reads "Registrar" in markup; the stylesheet applies
            // text-transform:uppercase for the printed output.
            self::assertStringContainsString('Registrar', $html, "$sku missing the signature rule");
            self::assertStringContainsString('dt-who', $html, "$sku missing the signature line");
            // The registrar stamps the real seal by hand, so no printed
            // placeholder circle is emitted.
            self::assertStringNotContainsString('dt-seal', $html, "$sku still prints a seal placeholder");
            self::assertStringContainsString(
                'DOC-2026-0001', $html, "$sku missing the request number"
            );
        }
    }

    public function testEmptyRecordsPrintNaRatherThanDroppingSections(): void
    {
        foreach (self::SKUS as $sku) {
            $html = dt_render($this->emptyContext($sku));

            self::assertGreaterThan(800, strlen($html), "$sku rendered almost nothing");
            self::assertStringContainsString(
                'N/A', $html, "$sku suppressed a missing value instead of printing N/A"
            );
        }
    }

    /**
     * The whole point of the convention: an absent syllabus must look
     * empty, not absent. A vanished section reads as a rendering bug.
     */
    public function testCourseDescriptionKeepsItsSyllabusBlockWhenEmpty(): void
    {
        $html = dt_render($this->emptyContext('DOC-CD'));

        self::assertStringContainsString('COURSE DESCRIPTION', $html);
        self::assertStringContainsString('dt-nabody', $html);
        self::assertStringContainsString('N/A', $html);
    }

    public function testTorPrintsAGradeTableEvenWithNoTerms(): void
    {
        $html = dt_render($this->emptyContext('DOC-TOR'));

        self::assertStringContainsString('ACADEMIC RECORD', $html);
        self::assertStringContainsString('dt-table', $html);
        self::assertStringContainsString('Total Units Earned', $html);
        self::assertStringContainsString('Career GWA', $html);
    }

    public function testRealGradesRenderAlongsideTheNaFillerRow(): void
    {
        $ctx = $this->emptyContext('DOC-TOR');
        $ctx['terms'] = [[
            'school_year' => '2024-2025', 'semester' => '1st',
            'credits' => '6', 'gwa' => '1.75', 'remarks' => null,
            'subjects' => [
                ['subject_code' => 'CS101', 'subject' => 'Programming 1',
                 'units' => '3', 'final_rating' => '88.00',
                 'grade' => '1.75', 'remarks' => 'PASS'],
                ['subject_code' => null, 'subject' => null, 'units' => null,
                 'final_rating' => null, 'grade' => null, 'remarks' => null],
            ],
        ]];
        $ctx['career_gwa'] = 1.75;
        $ctx['total_units'] = '6';

        $html = dt_render($ctx);

        self::assertStringContainsString('CS101', $html);
        self::assertStringContainsString('88.00', $html);
        self::assertStringContainsString('1.75', $html);
        // The N/A row still prints beside the populated one.
        self::assertStringContainsString('dt-na', $html);
    }

    /**
     * A request must always yield a printable record copy, even for a
     * catalog row the renderer has never seen.
     */

    /**
     * A certified copy attests to the copy, never to the authenticity
     * of the original. Omitting the limiting clause makes the document
     * legally wrong, so the wording is asserted here.
     */
    public function testCertifiedTrueCopyCarriesTheLimitingClause(): void
    {
        $html = dt_render($this->emptyContext('DOC-CTC'));

        self::assertStringContainsString('true and correct', $html);
        self::assertStringContainsString('does not certify', $html);
        self::assertStringContainsString('Original SHA-256', $html);
    }

    /**
     * A deferred body must NOT print a "produced by department X" notice.
     *
     * The Certificate of Enrollment and the Course Description carry a
     * signature from the Office of the Registrar. A disclaimer saying the
     * Registrar does not produce the document contradicts the signature
     * block directly beneath it, and internal system boundaries mean
     * nothing to whoever receives the certificate. The ownership lives in
     * DEPARTMENTS.md; the document itself just shows N/A fields.
     */
    public function testDeferredBodiesCarryNoDepartmentDisclaimer(): void
    {
        foreach (['DOC-COE' => 'Enrollment Management System',
                  'DOC-CD'  => 'Curriculum'] as $sku => $needle) {
            $html = dt_render($this->emptyContext($sku));

            self::assertStringNotContainsString(
                'This document is produced by', $html, "$sku still prints a producer notice"
            );
            self::assertStringNotContainsString(
                'not reproduced by the Office', $html, "$sku still prints a disclaimer"
            );
            self::assertStringNotContainsString(
                $needle, $html, "$sku still names the owning department"
            );
            self::assertStringNotContainsString('dt-defer', $html, "$sku still uses the deferral block");

            // The fields themselves are still present, printing N/A.
            self::assertStringContainsString('N/A', $html);
        }
    }

    public function testNameFormattingIsLastCommaFirst(): void
    {
        self::assertSame('DELA CRUZ, JUAN S JR.', dt_cert_name([
            'last_name' => 'DELA CRUZ', 'first_name' => 'JUAN',
            'middle_name' => 'S', 'name_suffix' => 'JR.',
        ]));
    }

    public function testEmptyNameIsNotAnA(): void
    {
        self::assertSame(DT_NA, dt_cert_name([]));
    }

    public function testValueHelpersFallBackToNa(): void
    {
        self::assertSame('N/A', dt_val(null));
        self::assertSame('N/A', dt_val(''));
        self::assertSame('N/A', dt_val('   '));
        self::assertSame('N/A', dt_money(null));
        // A zero balance is a real figure, not missing data.
        self::assertSame('₱0.00', dt_money(0));
    }

    /**
     * A request must always yield a printable record copy, even for a
     * catalog row the renderer has never seen.
     */
    public function testUnknownSkuStillProducesACompleteDocument(): void
    {
        $html = dt_render($this->emptyContext('DOC-UNKNOWN'));

        self::assertStringContainsString('dt-auth', $html);
        self::assertStringContainsString('dt-verify', $html);
        self::assertStringContainsString('N/A', $html);
    }

    /**
     * A record copy must print on ONE page. These assert the print
     * contract: a single @page rule, and the compact type scale that
     * keeps every template inside it.
     */
    public function testPrintSheetIsConfiguredForOnePage(): void
    {
        $html = dt_standalone_html($this->emptyContext());

        // Exactly one @page RULE. Matching the declaration, since prose in
        // the stylesheet also says "@page".
        self::assertSame(1, preg_match_all('/@page\s*\{/', $html));

        // Paper size is left to the printer so a counter machine loaded with
        // Letter or Legal prints correctly rather than being forced to A4.
        self::assertStringContainsString('size: auto', $html);
        self::assertStringNotContainsString('size: A4', $html);

        // The fit-to-one-page pass is present in the print window.
        self::assertStringContainsString('dt-doc', $html);
        self::assertStringContainsString('scale(', $html);
        self::assertStringContainsString('scrollHeight', $html);

        // Blocks must not split across a page boundary.
        self::assertStringContainsString('break-inside: avoid', $html);
    }

    /**
     * The print stylesheet is the shared source for the modal and the
     * print window, so a compact scale here is what keeps both to one
     * page. Guards against a regression to the roomy defaults.
     */
    public function testStylesheetIsCompactEnoughForOnePage(): void
    {
        $css = dt_stylesheet();

        // @page is 0 so the browser has no room to draw its own header
        // and footer (the date/title/URL Chrome otherwise stamps on the
        // printout). The sheet carries the margin as padding instead.
        self::assertStringContainsString('margin: 0', $css);
        self::assertStringContainsString('padding: 12mm 12mm', $css);

        // The BODY type must be the compact scale, not the old roomy
        // default. The college name stays larger on purpose, so this
        // matches the .dt-doc rule specifically.
        self::assertStringContainsString('font-size: 9.5pt', $css);
        self::assertMatchesRegularExpression('/\.dt-doc\s*\{[^}]*font-size:\s*9\.5pt/', $css);

        // The dry-seal placeholder is gone; only the signature rule is
        // printed, so there must be no fixed-height seal box reserving
        // space on the sheet.
        self::assertStringNotContainsString('.dt-seal', $css);
    }

    /**
     * The sheet is the only element in the print window, so nothing
     * can follow it onto a second page.
     */
    public function testPrintWindowHasNoTrailingContent(): void
    {
        $html = dt_standalone_html($this->emptyContext());

        // Body contains exactly the sheet div, then the fit script.
        self::assertSame(1, substr_count($html, '<body>'));
        self::assertSame(1, substr_count($html, 'class="dt-doc"'));
        // Script comes last, inside body.
        self::assertLessThan(strrpos($html, '</body>'), strrpos($html, '<script>'));
    }

    /**
     * The sheet prints bare — no mat, no shadow.
     *
     * The registrar's preview modal draws a grey mat and a drop shadow so
     * the document reads as a sheet on a desk. That decoration used to be
     * assigned as INLINE styles on the preview iframe's body, which
     * printDoc() then faithfully printed, so the hard copy came out as a
     * grey rectangle with a drop shadow around it — a screenshot pasted
     * onto paper rather than a document the office issued. A stylesheet
     * rule cannot undo an inline style without !important, so the desk now
     * injects its mat inside @media screen and these resets are
     * belt-and-braces. They are asserted here because the failure is
     * invisible in the preview and only shows up on paper.
     */
    public function testPrintMediaStripsTheSheetDecoration(): void
    {
        $css = dt_stylesheet();

        // Pull out the @media print block, then assert within it.
        self::assertSame(
            1,
            preg_match('/@media\s+print\s*\{(.*?)\n\}/s', $css, $m),
            'The stylesheet must still declare an @media print block.'
        );
        $print = $m[1];

        // The resets must be !important, or they lose to any inline
        // style a viewer sets on the body or the sheet.
        self::assertMatchesRegularExpression(
            '/html\s*,\s*body\s*\{[^}]*background:\s*#fff\s*!important/',
            $print,
            'Print must force a white page background.'
        );
        self::assertMatchesRegularExpression(
            '/\.dt-doc\s*\{[^}]*box-shadow:\s*none\s*!important/',
            $print,
            'The preview drop shadow must not print.'
        );

        // The screen-only width cap must stay screen-only: if the sheet
        // were capped in print it would sit in a column of dead margin.
        self::assertMatchesRegularExpression(
            '/@media\s+screen\s*\{\s*\.dt-doc\s*\{\s*max-width:\s*190mm/',
            $css,
            'The 190mm width cap is a screen affordance only.'
        );
    }

    /**
     * The QR must encode the PUBLIC verification URL, never a localhost
     * or preview URL — the code is scanned months later, by a third
     * party, on a different network.
     */
    public function testVerificationQrPointsAtThePublicDomain(): void
    {
        self::assertSame('https://registrar.bcpsms2.com', dt_public_base_url());

        $url = dt_verify_url('abc123');
        self::assertStringStartsWith('https://registrar.bcpsms2.com/', $url);
        self::assertStringContainsString('verify.php?qr=abc123', $url);
        self::assertStringNotContainsString('localhost', $url);

        // The host and the app path must be joined with a separator —
        // app_url() is root-relative, so naive concatenation produces
        // "https://registrar.bcpsms2.comtests/verify.php".
        self::assertStringNotContainsString('.comtests', $url);
        self::assertMatchesRegularExpression('#^https://registrar\.bcpsms2\.com/[a-z0-9/_-]*verify\.php\?qr=#i', $url);

        // No hash means nothing to verify, so no code is drawn.
        self::assertSame('', dt_verify_url(null));
        self::assertSame('', dt_verify_url('   '));
    }

    /** The logo must be absolute, or it 404s in the print window. */
    public function testLogoUrlIsAbsolute(): void
    {
        self::assertStringStartsWith(
            'https://registrar.bcpsms2.com/',
            dt_public_url('assets/images/BCP_LOGO.png')
        );
        self::assertStringNotContainsString('.comassets', dt_public_url('assets/images/BCP_LOGO.png'));
    }

    /** The QR renders as inline SVG so it always prints. */
    public function testQrRendersInlineSvg(): void
    {
        $svg = dt_qr_svg(dt_verify_url('deadbeef'));
        if ($svg === '') {
            self::markTestSkipped('chillerlan/php-qrcode not installed.');
        }
        self::assertStringContainsString('<svg', $svg);
        // Fixed width/height attributes would override the print CSS.
        self::assertDoesNotMatchRegularExpression('/<svg[^>]*\swidth=/i', $svg);
    }

    /**
     * A QR painted solid black cannot be scanned, which defeats the
     * point of printing a verification code.
     *
     * chillerlan emits two sets of paths: .dark modules and .light
     * modules. Recolouring `svg *` black fills BOTH sets and produces a
     * solid square. The stylesheet must target only the dark set, and
     * both sets must survive into the output.
     */
    public function testQrKeepsLightModulesWhite(): void
    {
        $css = dt_stylesheet();

        // Only the dark modules may be forced black. Match the SELECTOR,
        // not the prose: the comment above it explains the `svg *` mistake.
        self::assertStringContainsString('svg path.dark { fill: #000', $css);
        self::assertStringContainsString('svg path.light { fill: #fff', $css);
        self::assertDoesNotMatchRegularExpression('/svg\s+\*\s*\{/', $css);

        $svg = dt_qr_svg(dt_verify_url('deadbeef'));
        if ($svg === '') {
            self::markTestSkipped('chillerlan/php-qrcode not installed.');
        }
        // Both module sets must be present, or the code is unreadable.
        self::assertStringContainsString('dark qrcode', $svg);
        self::assertStringContainsString('light qrcode', $svg);
    }

    /**
     * The logo is referenced relatively so it resolves in BOTH the
     * preview modal and the print window, and does not depend on the
     * production domain being reachable from the registrar's machine.
     */
    public function testLogoIsRelativeSoItResolvesLocallyAndInPrint(): void
    {
        // The stub context passes logo_url => null, so the <img> is
        // omitted. Build one WITH a logo to assert on the markup.
        $ctx = $this->emptyContext();
        $ctx['logo_url'] = '../assets/images/BCP_LOGO.png';
        $html = dt_render($ctx);

        self::assertStringContainsString('../assets/images/BCP_LOGO.png', $html);
        self::assertStringNotContainsString(
            'https://registrar.bcpsms2.com/assets', $html
        );
    }

    /**
     * The signature block must be centred, not stretched edge to edge.
     * It was sized as a flex child beside the (now removed) seal circle.
     */
    public function testSignatureBlockIsCentred(): void
    {
        $css = dt_stylesheet();
        self::assertStringContainsString('.dt-signrow { display: flex; justify-content: center', $css);
        self::assertStringContainsString('.dt-sign { flex: 0 0 70mm', $css);

        $html = dt_render($this->emptyContext());
        // The date line shares the signature's width, so they read as
        // one centred block rather than two unrelated rules.
        self::assertStringContainsString('dt-signrow-date', $html);
        self::assertStringNotContainsString('dt-signdate', $html);
    }

    /**
     * The fit-to-one-page pass must not push the sheet's right edge past
     * the printable width.
     *
     * It previously set width to 100/scale% to "fill" the sheet after
     * scaling. A percentage width is a RENDERED width, so the element
     * became wider than the page and the right-hand side — including the
     * QR and the last table column — was clipped off in print.
     */
    public function testAutofitNeverWidensTheSheet(): void
    {
        $js = dt_autofit_script();

        // The offending computation must be gone.
        self::assertStringNotContainsString('100 / scale', $js);
        self::assertStringContainsString("doc.style.width = '100%'", $js);

        // Fit on whichever axis is worse, measured from the viewport
        // rather than an assumed A4 height.
        self::assertStringContainsString('scrollWidth', $js);
        self::assertStringContainsString('window.innerWidth', $js);
        self::assertStringNotContainsString('PAGE_H', $js);
    }

    /**
     * The sheet must not be wider than the page.
     *
     * `.dt-doc` has `width: 100%` plus `padding: 12mm` on each side. In
     * the default content-box that measured 100% + 24mm, so 24mm ran off
     * the right edge and the QR and the last table column were cropped.
     * The sheet itself must therefore be border-box, not just its
     * children.
     */
    public function testSheetItselfIsBorderBox(): void
    {
        $css = dt_stylesheet();
        self::assertStringContainsString(
            '.dt-doc, .dt-doc * { box-sizing: border-box; }', $css
        );
    }

    /**
     * `overflow: hidden` must not be used to paper over width overflow.
     * It does not prevent overflow, it CROPS it — which is precisely how
     * 24mm of the right edge went missing instead of being scaled to fit.
     */
    public function testPrintCssDoesNotHideOverflow(): void
    {
        // Strip CSS comments first: the block documenting this rule
        // necessarily NAMES overflow:hidden while explaining why it is
        // not used, which a naive substring or regex match would trip on.
        $css = (string) preg_replace('#/\*.*?\*/#s', '', dt_stylesheet());
        self::assertDoesNotMatchRegularExpression('/overflow\s*:\s*hidden/', $css);

        // The real guards are present instead.
        self::assertStringContainsString('table-layout: fixed', $css);
        self::assertStringContainsString('overflow-wrap: break-word', $css);
    }

    /**
     * The masthead carries only the document name. The request number,
     * counter, SKU and owning-department line were removed from the title
     * block; the request number must still appear in the verification
     * strip so the copy stays traceable.
     */
    public function testTitleBlockIsCleanButStillTraceable(): void
    {
        $html = dt_render($this->emptyContext());

        self::assertStringNotContainsString('dt-sub', $html);
        self::assertStringNotContainsString('dt-owner', $html);
        self::assertStringNotContainsString('Caypombo', $html);

        // Traceability survives in the verification strip.
        self::assertStringContainsString('dt-verify', $html);
        self::assertStringContainsString('DOC-2026-0001', $html);
        self::assertStringContainsString('Counter:', $html);
    }

    // ── Certificate of Good Moral: redesign ───────────────────

    /**
     * The standing ledger must print all five checks, and each must be
     * classified as either a VERIFIED finding or an unverified N/A.
     * A row that silently disappears is a certificate quietly
     * narrowing what it attests to.
     */
    public function testGoodMoralLedgerPrintsAllFiveChecks(): void
    {
        $ctx = $this->emptyContext('DOC-GM');
        $html = dt_render($ctx);

        self::assertStringContainsString('dt-standing', $html);
        self::assertStringContainsString('Standing at the time of issuance', $html);

        foreach (['Pending disciplinary cases', 'Pending academic deficiency',
                  'Outstanding financial obligation', 'Administrative hold on file',
                  'Exit clearance'] as $label) {
            self::assertStringContainsString($label, $html, "missing check: $label");
        }

        // Extract the ledger's table and confirm the rows balance.
        preg_match('#<div class="dt-standing">.*(<table>.*?</table>)#s', $html, $m);
        $ledger = $m[1] ?? '';
        self::assertSame(5, substr_count($ledger, '<tr>'));
        self::assertSame(
            5,
            substr_count($ledger, 'class="dt-v"') + substr_count($ledger, 'class="dt-unverified"')
        );
    }

    /**
     * A verified finding and an unattested one are different statements
     * and must not look alike. "0 cases" is the office standing behind
     * the line; N/A means it cannot attest to it.
     */
    public function testGoodMoralDistinguishesVerifiedFromUnverified(): void
    {
        $ctx = $this->emptyContext('DOC-GM');
        $ctx['moral_checks'] = [
            'Pending disciplinary cases'       => '0',
            'Pending academic deficiency'      => null,
            'Outstanding financial obligation' => '₱0.00',
            'Administrative hold on file'      => 'No',
            'Exit clearance'                   => null,
        ];
        $html = dt_render($ctx);

        preg_match('#<div class="dt-standing">.*(<table>.*?</table>)#s', $html, $m);
        $ledger = $m[1] ?? '';

        self::assertSame(3, substr_count($ledger, 'class="dt-v"'), 'verified findings');
        self::assertSame(2, substr_count($ledger, 'class="dt-unverified"'), 'unverified findings');
        self::assertStringContainsString('>0<', $ledger);
        self::assertStringContainsString('>N/A<', $ledger);

        // An unattested line must NOT use the red data-defect styling.
        self::assertStringNotContainsString('dt-missing', $ledger);
    }

    /**
     * The student's name is the display element and must appear exactly
     * once. Repeating it in the identity strip as well reads as a layout
     * fault rather than as emphasis.
     */
    public function testGoodMoralNameIsTheHeroAndNotDuplicated(): void
    {
        $ctx = $this->emptyContext('DOC-GM');
        $html = dt_render($ctx);

        self::assertStringContainsString('dt-attest', $html);
        self::assertStringContainsString('dt-name', $html);
        self::assertSame(
            1,
            substr_count($html, 'DELA CRUZ, JUAN S JR.'),
            'the name should be printed exactly once'
        );

        // The lead-in and the claim read as one sentence.
        self::assertStringContainsString('This is to certify that', $html);
        self::assertStringContainsString('is a bona fide student', $html);
    }

    /** The redesign is scoped to the good moral template. */
    public function testGoodMoralStylesAreScoped(): void
    {
        $css = dt_stylesheet();
        // Every good-moral rule is nested under .dt-gm, so the other six
        // documents cannot inherit the redesign.
        self::assertStringContainsString('.dt-gm .dt-attest', $css);
        self::assertStringContainsString('.dt-gm .dt-standing', $css);
        self::assertDoesNotMatchRegularExpression('/(?<!\.dt-gm )\.dt-attest\s*\{/', $css);

        // Another document must not pick up the wrapper.
        $tor = dt_render($this->emptyContext('DOC-TOR'));
        self::assertStringNotContainsString('class="dt-gm"', $tor);
    }

    /**
     * No document may print a literal HTML entity.
     *
     * "&amp;amp;" in the output means a pre-escaped string was escaped a
     * second time, so the printout shows "&amp;" to the reader instead of
     * "&". A correct single "&amp;" is fine — that is how a plain "&" is
     * carried in HTML — so the check decodes the markup and looks for an
     * entity that SURVIVES as visible text.
     */
    public function testNoDocumentPrintsALiteralHtmlEntity(): void
    {
        foreach (self::SKUS as $sku) {
            $html = dt_render($this->emptyContext($sku));

            self::assertStringNotContainsString(
                '&amp;amp;', $html, "$sku double-escapes an entity"
            );

            $asText = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
            self::assertStringNotContainsString(
                '&amp;', $asText, "$sku leaves a literal entity in the printed text"
            );
        }
    }

    /**
     * An ampersand in any generated text must encode exactly once.
     *
     * "Curriculum & Subject Management" was the case that double-escaped,
     * printing a literal "&amp;" to the reader. The notice that carried
     * the name is gone, but the rule still holds for any department name
     * that reaches a document in future.
     */
    public function testAmpersandInGeneratedTextEncodesOnce(): void
    {
        $ctx = $this->emptyContext('DOC-CD');
        // A purpose line carrying an ampersand, which a student may type.
        $ctx['request']['purpose'] = 'CHED & PRC requirement';

        $html = dt_render($ctx);

        self::assertStringNotContainsString('&amp;amp;', $html);
        $text = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        self::assertStringContainsString('CHED & PRC requirement', $text);
        self::assertStringNotContainsString('&amp;', $text);
    }

    public function testStandaloneHtmlIsSelfContained(): void
    {
        $html = dt_standalone_html($this->emptyContext());

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('@page', $html);
        self::assertStringContainsString('</html>', $html);
    }
}
