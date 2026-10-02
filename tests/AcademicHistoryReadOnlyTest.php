<?php

// ============================================================
//  TESTS/AcademicHistoryReadOnlyTest.php
//  Grades are Faculty-owned since 2026-10-02; the Registrar reads them.
//
//  These tests guard a BOUNDARY, not a behaviour. A read-only page that
//  quietly regains a save path is the failure this file exists to catch, and
//  nothing else in the suite would notice: the editor worked perfectly
//  before, so re-adding it would not break a single existing test.
//
//  Static source assertions are the right tool here. A browser probe
//  (tests/academic_readonly_probe.js) proves the rendered page is read-only
//  too, but it needs a live server; these run everywhere.
// ============================================================

use PHPUnit\Framework\TestCase;

final class AcademicHistoryReadOnlyTest extends TestCase
{
    private const PAGE   = __DIR__ . '/../registrar/academic-history.php';
    private const API    = __DIR__ . '/../api/students.php';
    private const LETTER = __DIR__ . '/../js/bcp-letterhead.js';

    private static function source(string $file): string
    {
        self::assertFileExists($file);
        return (string) file_get_contents($file);
    }

    // ── The page ─────────────────────────────────────────────────

    /**
     * The editor's JavaScript must be GONE, not merely unused.
     *
     * Checked as "function no longer declared" rather than "never called",
     * because a dormant save function is a live save function the day
     * someone wires a button back to it.
     */
    public function testGradeEditingFunctionsAreRemoved(): void
    {
        $js = self::source(self::PAGE);

        foreach ([
            'saveGrades', 'addGradeRow', 'removeGradeRow',
            'readGrid', 'updatePreview', 'previewGwa',
        ] as $fn) {
            self::assertStringNotContainsString(
                "function {$fn}(",
                $js,
                "{$fn}() still exists on the Academic History page. Grades are "
                . 'Faculty-owned; the Registrar must not be able to write them.'
            );
        }
    }

    /** The save path that actually reaches the server. */
    public function testPageNoLongerPostsToTheSaveEndpoint(): void
    {
        $html = preg_replace('#/\*.*?\*/|//[^\n]*|<!--.*?-->#s', '', self::source(self::PAGE));
        self::assertStringNotContainsString(
            'save-academic',
            $html,
            'The page still references the save-academic endpoint.'
        );
    }

    /**
     * No form controls in the grade grid.
     *
     * data-f is the marker every editable cell carried; its absence is the
     * machine-checkable version of "the grid is not a form".
     *
     * The whole FILE is not checked for these names: the header comment
     * deliberately names the functions that were removed, so that a reader
     * arriving in two years knows what used to be here. Comments are prose;
     * only live markup is the boundary.
     */
    public function testGradeGridHasNoEditorMarkup(): void
    {
        // Markup only — strip the comment blocks first.
        $html = preg_replace('#/\*.*?\*/|//[^\n]*|<!--.*?-->#s', '', self::source(self::PAGE));

        self::assertStringNotContainsString('data-f=', $html,
            'An editor cell is still present in the grade grid.');
        self::assertStringNotContainsString('id="btnSaveGrades"', $html,
            'The Save term button is still in the markup.');
        self::assertStringNotContainsString('addGradeRow()', $html);
        self::assertStringNotContainsString('removeGradeRow(', $html);
    }

    /**
     * No form controls in the ledger or the record.
     *
     * This used to assert against #gradeModal. The redesign removed the
     * dialog entirely — the record now expands in place under the row that
     * was clicked — so there is no dialog left to scope the check to. The
     * assertion gets STRONGER in exchange: it covers the whole ledger and
     * the record markup, rather than one container.
     *
     * The page keeps inputs, and must: the term box and the find box are how
     * a registrar reaches the student they want. Those live outside the
     * ledger, so scoping to the table is correct rather than a convenience.
     */
    public function testLedgerAndRecordContainNoFormControls(): void
    {
        $html = self::source(self::PAGE);

        // Isolate the roster table.
        //
        // It used to be found as <table class="reg-ledger">, which is unique
        // because that class existed only here. It is now the house .table,
        // shared with every panel on every registrar page — and the page has
        // two of them, because the empty-state branch renders its own table.
        // Scoping by the header row instead finds the roster in both branches
        // and cannot drift when the styling is changed.
        self::assertSame(
            1,
            preg_match_all(
                '/<thead>\s*<tr>\s*<th scope="col">Student<\/th>.*?<\/table>/s',
                $html,
                $m
            ),
            'Could not isolate the roster markup to check it.'
        );
        $ledger = $m[0][0];

        self::assertStringContainsString('class="ah-record"', $html,
            'The inline record container is missing from the page.');

        foreach (['<input', '<select', '<textarea', 'data-f='] as $tag) {
            self::assertStringNotContainsString($tag, $ledger,
                "The ledger still contains a {$tag} control. It is a read-only "
                . 'view of a Faculty-owned record.');
        }

        // The record is rendered by recordHtml() in JS, so the same rule
        // has to hold there.
        $js = self::source(self::PAGE);
        $start = strpos($js, 'function recordHtml(');
        $end = strpos($js, 'function toggleRecord(');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $record = substr($js, $start, $end - $start);
        foreach (['<input', '<select', '<textarea', 'data-f='] as $tag) {
            self::assertStringNotContainsString($tag, $record,
                "recordHtml() renders a {$tag} control into the printed record.");
        }
    }

    /**
     * The only button in a row is the disclosure, plus print in the record.
     *
     * "Remove all the buttons" was a design instruction, but deleting the
     * <button> element outright would break keyboard and screen-reader
     * users. The rule enforced here is that a row carries no button CHROME:
     * no .btn class, no icon font, no bordered control.
     */
    public function testRowsCarryNoButtonChrome(): void
    {
        $html = self::source(self::PAGE);

        // No bootstrap button classes inside the roster. preg_match_all
        // returns the number of MATCHES, so 1 means "found exactly the one
        // roster" — asserting 0 here (as an earlier draft did) fails on a
        // perfectly healthy page, which is a test that teaches the reader
        // to ignore it.
        //
        // Scoped by the header row rather than by a bespoke class: the roster
        // now wears the house .table, so the class no longer identifies it.
        self::assertSame(
            1,
            preg_match_all(
                '/<thead>\s*<tr>\s*<th scope="col">Student<\/th>.*?<\/table>/s',
                $html,
                $m
            ),
            'Could not isolate the roster markup to check it.'
        );
        foreach (['btn btn-light', 'btn btn-primary', 'fa-eye', 'fa-pen',
                  'fa-print', 'fa-floppy-disk'] as $chrome) {
            self::assertStringNotContainsString($chrome, $m[0][0],
                "The roster still carries button chrome ('{$chrome}').");
        }

        // The disclosure is a plain button, styled by .ah-open with no
        // border, background or padding of its own.
        self::assertStringContainsString('class="ah-open"', $html);
    }

    /** The replacement action is present, so the page is still useful. */
    public function testPrintableTemplateActionExists(): void
    {
        $html = self::source(self::PAGE);
        self::assertStringContainsString('function printGradeTemplate(', $html);
        // It must go through the shared letterhead, not a private copy.
        self::assertStringContainsString('BCPPrint.printDocument(', $html);
        self::assertStringContainsString('BCPPrint.headerHtml(', $html);
    }
    // ── The API ──────────────────────────────────────────────────

    /**
     * The write endpoints must refuse, and must say why.
     *
     * 404 is the wrong answer here: it reads as "unknown action" and looks
     * like a bug in whatever called it. 409 plus a reason tells an
     * integrator the endpoint exists, that the boundary moved, and where the
     * data comes from now.
     */
    public function testWriteEndpointsAreRefusedWithAReason(): void
    {
        $api = self::source(self::API);

        self::assertStringContainsString("'save-academic'", $api);
        self::assertStringContainsString("'delete-academic'", $api);
        self::assertStringContainsString('http_response_code(409)', $api,
            'Grade writes must be refused with 409, not left to run.');
        self::assertStringContainsString('Faculty Management', $api,
            'The refusal must name the owning office.');
    }

    /** Nothing may still insert into or delete from the grade tables. */
    public function testApiNoLongerWritesToGradeTables(): void
    {
        $api = self::source(self::API);

        self::assertDoesNotMatchRegularExpression(
            '/insert\(\s*[\'"]academic_grades[\'"]/i',
            $api,
            'The API still inserts into academic_grades.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/delete\(\s*[\'"]academic_(grades|history)[\'"]/i',
            $api,
            'The API still deletes academic grade records.'
        );
    }

    /** The read path must survive the boundary change. */
    public function testApiStillServesGrades(): void
    {
        self::assertStringContainsString(
            "=== 'grades'",
            self::source(self::API),
            'The read endpoint for grades was removed; the page cannot refresh.'
        );
    }

    // ── The shared letterhead ─────────────────────────────────────

    /**
     * printDocument() must wait for the crest before printing.
     *
     * This is the AI Insight logo regression, pinned. The original code
     * called d.close(); w.print() back to back; the browser printed before
     * the <img> had fetched and the letterhead came out with no logo. An
     * <img> is fetched asynchronously and nothing in document.write waits
     * for it, so the wait has to be explicit — and it lives with the
     * letterhead because the letterhead is the only thing carrying an image.
     */
    public function testPrintWaitsForImagesToLoad(): void
    {
        $js = self::source(self::LETTER);

        self::assertStringContainsString('whenImagesReady', $js,
            'printDocument() must wait for images before printing.');
        self::assertStringContainsString("addEventListener('load'", $js);
        self::assertStringContainsString('!img.complete', $js,
            'An already-complete image must be detected, or the wait hangs on '
            . 'a cached crest until the timeout.');

        // The deadline is a safety net, not the mechanism: a broken image
        // must never stop the document printing.
        self::assertStringContainsString('setTimeout(go, 1500)', $js);
    }

    /** Both consumers must share the one letterhead. */
    public function testLetterheadIsSharedNotDuplicated(): void
    {
        $insights = self::source(__DIR__ . '/../js/insights.js');

        self::assertStringContainsString('BCPPrint.printDocument(', $insights,
            'The AI Insight report no longer uses the shared letterhead.');
        self::assertStringContainsString('BCPPrint.headerHtml(', $insights);

        // Neither file may carry its own copy of the rules.
        foreach (['.lh-school {', '.letterhead {'] as $rule) {
            self::assertStringNotContainsString(
                $rule,
                $insights,
                "The report has its own copy of '{$rule}' — the letterhead has "
                . 'been re-inlined and can drift again.'
            );
        }
    }

    /** The letterhead prints on A4 with no browser furniture. */
    public function testPrintStylingSuppressesBrowserFurniture(): void
    {
        $js = self::source(self::LETTER);
        // Browsers draw their own date/URL/page-number header only into the
        // page margin, so @page margin:0 leaves them nowhere to go.
        self::assertStringContainsString(
            '@page { size: A4 portrait; margin: 0; }', $js);
        self::assertStringContainsString('position:fixed', $js,
            'The footer must be position:fixed to repeat on every page.');
    }
}