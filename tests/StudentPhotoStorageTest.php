<?php

use PHPUnit\Framework\TestCase;

/**
 * A student's photograph lives in Digital File Storage, and only there.
 *
 * The student list and the View modal were both reading `students.photo` alone.
 * That column is NULL for every student whose picture came through File Storage
 * - which is all of them - so both surfaces rendered a coloured "RT" for a
 * student whose photograph was sitting in the documents table the whole time.
 * Two pages got this wrong separately and identically, which is the argument
 * for the resolution living in shared/stored_file.php: a rule written in one
 * place cannot drift.
 *
 * Asserted here:
 *   · the one source of truth exists and orders candidates correctly;
 *   · nothing writes students.photo any more (the second way in is closed);
 *   · nothing reads it directly any more (every reader goes through the helper);
 *   · the disk check is real, so a database from another host yields initials
 *     rather than a 404.
 *
 * Deliberately NOT asserted: that `students.photo` stops existing. The column
 * is still read as a legacy first choice, so historical rows keep their photo.
 */
final class StudentPhotoStorageTest extends TestCase
{
    private const PAGE     = __DIR__ . '/../registrar/students.php';
    private const API      = __DIR__ . '/../api/students.php';
    private const RFID     = __DIR__ . '/../registrar/rfid-cards.php';
    private const STORED   = __DIR__ . '/../shared/stored_file.php';
    private const FUNCTIONS = __DIR__ . '/../shared/functions.php';
    private const FIELDS   = __DIR__ . '/../registrar/file-storage.php';

    private static function page(): string
    {
        return file_get_contents(self::PAGE);
    }

    /**
     * A file that is on this server resolves; one that is not does not.
     *
     * This is the load-bearing behaviour. uploads/ is gitignored, so a database
     * that arrived from a copied dump or a staging box promoted to production
     * carries rows naming files this host never received. Without the disk
     * check those rows produce a broken-image glyph and a 404 per student.
     */
    public function testPhotoResolvesOnlyWhenTheFileIsActuallyOnDisk(): void
    {
        require_once self::STORED;

        // A file that is on disk resolves; one that is not does not.
        //
        // The path handed in is the real filename from this checkout, not a
        // fixture, so the test asserts against an actual file rather than a
        // guess at where uploads/ will be on someone else's machine.
        $files = array_values(array_filter(
            glob(dirname(__DIR__) . '/uploads/students/*'),
            static fn($f) => is_file($f) && preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $f) === 1
        ));
        if (!$files) {
            self::markTestSkipped('No uploaded photographs on this checkout to test against.');
        }

        // glob() returns absolute paths. storedFileUrl() wants a path relative
        // to the app root - the same shape documents.file_path holds - so the
        // fixture is rebased onto the repo root rather than passed through.
        // Passing the absolute path through verbatim resolves to '' and the
        // test would fail while asserting nothing useful.
        $relative = 'uploads/students/' . basename($files[0]);
        $absolute = dirname(__DIR__) . '/' . $relative;

        // Sanity-check the fixture itself, so a failure below is about the
        // resolver rather than about a path that was never a file.
        self::assertFileExists($absolute, 'The chosen fixture is not a file on disk.');

        self::assertNotSame(
            '',
            studentPhotoUrl(['photo_path' => '../' . $relative]),
            'A file that is on disk must resolve to a URL.'
        );

        self::assertSame(
            '',
            studentPhotoUrl(['photo_path' => '../uploads/students/9999_not_here.jpg']),
            'A path naming a file this host lacks must resolve to nothing.'
        );
    }

    /**
     * The legacy column is a first choice, not ignored. Rows that predate File
     * Storage still have their picture, and closing the second upload path must
     * not also blank out the photographs it wrote.
     */
    public function testLegacyPhotoColumnIsStillHonoured(): void
    {
        require_once self::STORED;

        self::assertSame('', studentPhotoUrl(['photo' => '']), 'No column and no document means nothing.');

        self::assertMatchesRegularExpression(
            '/\[\s*\$get\(.photo.\),\s*\$get\(.photo_path.\)\s*\]/',
            file_get_contents(self::STORED),
            'students.photo must stay the first candidate; historical rows depend on it.'
        );
    }

    /**
     * The Document Reader subquery has to be the same everywhere.
     *
     * It was written out inline in two pages. It now has one definition, and
     * both pages interpolate it rather than retyping it.
     */
    public function testPhotoSubqueryIsDefinedOnceAndShared(): void
    {
        require_once self::STORED;

        $fragment = studentPhotoSelectSql();

        // The allow-list is the part that actually matters: without it a .pdf
        // or .docx labelled "photo" would be offered as a face.
        self::assertStringContainsString("d.doc_type = 'photo'", $fragment);
        self::assertStringContainsString("'jpg','jpeg','png','webp','gif'", $fragment);
        // Newest wins, so a corrected photograph supersedes the original.
        self::assertStringContainsString('ORDER BY d.created_at DESC', $fragment);

        // No page may hand-maintain its own copy any more.
        foreach (['page' => self::PAGE, 'api' => self::API, 'rfid' => self::RFID] as $label => $file) {
            $src = file_get_contents($file);
            self::assertSame(
                0,
                preg_match("/d\.doc_type\s*=\s*'photo'/", $src),
                "$label retypes the photo subquery inline. Call studentPhotoSelectSql() instead."
            );
            self::assertStringContainsString(
                'studentPhotoSelectSql()',
                $src,
                "$label must select the stored photo through the shared fragment."
            );
        }
    }

    // ── One way in ─────────────────────────────────────────────────

    /**
     * The upload endpoint is gone, not just unlinked.
     *
     * It wrote students.photo while File Storage wrote a documents row. Two
     * locations for one face is precisely what made every reader guess
     * differently, and it re-uploaded under a timestamped name so replacing a
     * face orphaned the previous file instead.
     */
    public function testNoSecondPhotoWritePathRemains(): void
    {
        $api = file_get_contents(self::API);

        self::assertStringNotContainsString(
            "action'] === 'upload-photo'",
            $api,
            'The parallel students.photo upload endpoint is back.'
        );
        self::assertSame(
            0,
            preg_match("/db->update\(\s*'students'\s*,\s*\[\s*'photo'/", $api),
            'Something writes students.photo again. File Storage is the only way in.'
        );

        // The removed control must not survive in the page as dead markup.
        $page = self::page();
        self::assertStringNotContainsString('id="photoInput"', $page, 'The photo file input is back.');
        self::assertStringNotContainsString('uploadPhoto(', $page, 'The photo upload handler is back.');

        // Assert on the control, not the phrase. "Change Photo" survives in
        // the comments explaining why the button was removed, and a test that
        // greps for a phrase it has itself put in the file fails for ever
        // after. The button is an element with an onclick and a camera icon.
        self::assertSame(
            0,
            preg_match('/<button[^>]*>\s*<i class="fas fa-camera"/i', $page),
            'The camera button is back. Photographs are managed in File Storage.'
        );
    }

    // ── Both surfaces render it ────────────────────────────────────

    /**
     * The list and the modal both consult the stored photo, and both keep
     * initials as an honest fallback rather than an empty box.
     */
    public function testListAndModalBothReadTheStoredPhoto(): void
    {
        $page = self::page();

        self::assertStringContainsString(
            'studentPhotoUrl($s, $APP_ROOT)',
            $page,
            'The student list must resolve photographs through the shared helper.'
        );
        self::assertStringContainsString(
            's.photo_url',
            $page,
            'The View modal must read the resolved URL the API returns.'
        );

        // A photo that fails to load uncovers the initials rather than leaving
        // a broken-image icon in a list a clerk reads all day.
        //
        // The patterns use [\s\S]{0,200}? rather than [^>]*: the img tag
        // interpolates a PHP short echo, so its attributes contain a literal
        // short-echo close token. A `>`-excluding character class stops dead
        // at the end of that echo, so a pattern written that way cannot see
        // the tag it is looking for and fails for a reason that has nothing to
        // do with the behaviour under test.
        self::assertMatchesRegularExpression(
            '/<img[^>]*class="student-avatar"[\s\S]{0,200}?onerror=/',
            $page,
            'A list avatar whose image fails must fall back to the initials.'
        );
        self::assertMatchesRegularExpression(
            '/id="vAvatarImg"[\s\S]{0,200}?onerror=/',
            $page,
            'The modal portrait must fall back to the initials on a failed load.'
        );
    }

    /**
     * The counter desk's request table shows the face too.
     *
     * registrar/documents.php rendered a hardcoded `blue` circle holding
     * strtoupper(substr($student_name, 0, 1)) - the first letter of the
     * CONCATENATED name. So Roldan Tiu, Rosa Tiu and Rey Tiu were all the same
     * blue "R", and a student with a photograph on file looked identical to one
     * without. On a counter where several people are waiting, the one column
     * that could carry a photo for free was the one that did not.
     */
    public function testCounterDeskRequestTableShowsTheStoredPhoto(): void
    {
        $root = dirname(__DIR__);
        $page = file_get_contents($root . '/registrar/documents.php');

        self::assertStringContainsString(
            "studentPhotoUrl(\$r, '../')",
            $page,
            'The request table must resolve the student photo through the shared helper.'
        );
        self::assertStringContainsString(
            'studentInitials(',
            $page,
            'The request table must fall back to first+last initials, not one letter.'
        );

        // The one-letter avatar is the bug. Asserted on the exact old
        // expression, so a re-introduction is unambiguous.
        self::assertStringNotContainsString(
            "substr((string) \$r['student_name'], 0, 1)",
            $page,
            'The request table is back to a single initial from the concatenated name.'
        );

        // The query must actually select the photo, or the helper is fed
        // nothing and quietly returns '' for every row.
        self::assertStringContainsString(
            'studentPhotoSelectSql()',
            $page,
            'The request table query must select the stored photo.'
        );
        self::assertStringContainsString(
            "require_once __DIR__ . '/../shared/stored_file.php'",
            $page,
            'registrar/documents.php must load shared/stored_file.php before using the helper.'
        );

        // Without object-fit the photograph is stretched to a square and a
        // portrait comes out squashed inside the circle.
        self::assertMatchesRegularExpression(
            '/img\.student-avatar\s*\{[^}]*object-fit:\s*cover/',
            file_get_contents($root . '/css/registrar.css'),
            'The avatar <img> needs object-fit:cover or the photograph is stretched.'
        );
    }

    /**
     * The Status Tracker directory shows the face too.
     *
     * Its avatar was two letters assembled with mb_substr() and tinted from the
     * status colour - and the directory query already selected `s.photo` for
     * nothing at all. So a student with a photograph on file was
     * indistinguishable from one without, on the one screen whose entire job
     * is telling two records apart.
     *
     * The status tint is deliberately KEPT for the initials fallback, where it
     * still carries meaning: it is the fastest read in the column.
     */
    public function testStatusTrackerDirectoryShowsTheStoredPhoto(): void
    {
        $root = dirname(__DIR__);
        $page = file_get_contents($root . '/registrar/status-tracker.php');

        self::assertStringContainsString(
            "studentPhotoUrl(\$s, '../')",
            $page,
            'The Status Tracker directory must resolve the photo through the shared helper.'
        );
        self::assertStringContainsString(
            'studentInitials(',
            $page,
            'The directory avatar must fall back to the shared initials helper.'
        );
        self::assertStringContainsString(
            'studentPhotoSelectSql()',
            $page,
            'The directory query must select the stored photo.'
        );
        self::assertStringContainsString(
            "require_once __DIR__ . '/../shared/stored_file.php'",
            $page,
            'registrar/status-tracker.php must load shared/stored_file.php before using the helper.'
        );

        // The hand-rolled initials are the bug: mb_substr() duplicated what
        // studentInitials() already does, and would silently differ from the
        // Students page the first time one of them handled a name differently.
        self::assertStringNotContainsString(
            "mb_substr((string) \$s['first_name'], 0, 1)",
            $page,
            'The directory avatar is building its own initials again.'
        );

        // The status tint must survive on the fallback.
        self::assertStringContainsString(
            "<span class=\"st-avatar\" style=\"background:<?= \$meta['color'] ?>22",
            $page,
            'The initials fallback must keep its status tint.'
        );

        // A photo that fails to load must uncover the initials.
        self::assertMatchesRegularExpression(
            '/<img[^>]*class="st-avatar"[\s\S]{0,200}?onerror=/',
            $page,
            'A directory avatar whose image fails must fall back to the initials.'
        );

        // CSS: without object-fit the photograph is stretched to a square.
        self::assertMatchesRegularExpression(
            '/img\.st-avatar\s*\{[^}]*object-fit:\s*cover/',
            file_get_contents($root . '/css/status-tracker.css'),
            'The directory avatar <img> needs object-fit:cover.'
        );
    }

    /**
     * The modal must not paint the portrait as a CSS background.
     *
     * Nothing ever cleared the inline background-image, so the first student's
     * face stayed painted over the second student's initials - and a student
     * with no photo inherited whoever was viewed before them. A real <img> that
     * is hidden cannot leak into the next render.
     */
    public function testModalPortraitIsNotAnInlineBackgroundImage(): void
    {
        $page = self::page();

        self::assertSame(
            0,
            preg_match('/vAvatar[\s\S]{0,200}backgroundImage/', $page),
            'The portrait is set as a background image again, which leaks between students.'
        );
        self::assertStringContainsString('id="vAvatarImg"', $page);
    }

    /**
     * The photo has to actually be PAINTED, not merely fetched.
     *
     * This is the bug that made the whole change look like it had done nothing.
     * The API returned the right URL, the img loaded with a real naturalWidth,
     * no console error appeared - and the modal still showed initials, because
     * `.vs-id-face` was position:absolute;inset:0 while the img sat in normal
     * flow. A positioned element paints above an unpositioned one, so the
     * initials were drawn straight over a photograph that had downloaded
     * perfectly. Every layer of the stack above this one said the work was
     * done, because as far as each of them was concerned it was.
     *
     * Confirmed with document.elementFromPoint() at the centre of the portrait:
     * the topmost node was the initials div. After positioning both layers the
     * topmost node is the img, and with no photo it is the initials span.
     *
     * Asserted on the CSS rather than on a screenshot, so the failure names the
     * property that has to change. A pixel-diff test would fail here too, but
     * it would fail with a rectangle and no explanation.
     */
    public function testModalPortraitLayersAboveTheInitials(): void
    {
        $page = self::page();

        // Both layers must be positioned, or paint order falls back to which
        // element happens to be in normal flow - which is the bug.
        self::assertMatchesRegularExpression(
            '/\.vs-id-portrait img\s*\{[^}]*position:absolute/',
            $page,
            'The portrait img must be absolutely positioned, or the initials paint over it.'
        );
        self::assertMatchesRegularExpression(
            '/\.vs-id-face\s*\{[^}]*position:absolute/',
            $page,
            'The initials block must be absolutely positioned to share the portrait box.'
        );

        // And the image must be the one on top.
        $imgLayer = self::layerValue($page, '/\.vs-id-portrait img\s*\{([^}]*)\}/');
        $faceLayer = self::layerValue($page, '/\.vs-id-face\s*\{([^}]*)\}/');

        self::assertGreaterThan(
            (int) $faceLayer,
            (int) $imgLayer,
            'The photo must stack above the initials. Otherwise it downloads and is never seen.'
        );
    }

    /**
     * JS toggles the image only; the initials block is never hidden.
     *
     * Hiding both layers from JS is how they came to disagree in the first
     * place - the image and the initials each had a display state written
     * independently. Now the image is display:none until a photo exists and
     * CSS resolves the overlap, so there is one source of truth.
     *
     * Also asserted: clearing src, not just hiding. An <img> left holding the
     * previous student's src is a pending request that can still resolve and
     * paint over the current student's initials.
     */
    public function testOnlyTheImageLayerIsToggledFromJs(): void
    {
        $page = self::page();

        $view = substr($page, (int) strpos($page, 'function viewStudent(id)'));
        $view = substr($view, 0, 3000);

        self::assertStringContainsString(
            "portrait.removeAttribute('src')",
            $view,
            'Clearing src prevents the previous student\'s photo being requested for the next one.'
        );
        self::assertStringNotContainsString(
            "avatarEl.style.display",
            $view,
            'JS must not hide the initials layer. CSS stacking owns that decision.'
        );
    }

    /** Read a z-index out of a CSS rule, defaulting to 0 when absent. */
    private static function layerValue(string $css, string $rule): int
    {
        self::assertSame(1, preg_match($rule, $css, $m), "Could not find the rule for $rule.");
        return preg_match('/z-index\s*:\s*(\d+)/', $m[1], $z) === 1 ? (int) $z[1] : 0;
    }

    /**
     * The endpoint hands over a resolved URL, and drops the raw column.
     *
     * Leaving `photo` in the response invites the old mistake of assigning it
     * straight to a background-image - it is a path, and it may name a file
     * this host does not have.
     */
    public function testApiReturnsResolvedUrlAndDropsRawColumn(): void
    {
        $api = file_get_contents(self::API);

        self::assertStringContainsString("\$student['photo_url'] = studentPhotoUrl(\$student, '../');", $api);
        self::assertStringContainsString("unset(\$student['photo']);", $api);

        // The helper must be loaded before the query calls it, or the endpoint
        // fatals on a call to an undefined function.
        //
        // Anchored to the real call site - the fragment concatenated into the
        // SQL - rather than the bare function name. The name also appears in
        // the require's own comment, and matching that would compare the
        // comment's offset against the require's and fail for ever.
        self::assertLessThan(
            strpos($api, 'studentPhotoSelectSql() . " AS photo_path'),
            strpos($api, "require_once __DIR__ . '/../shared/stored_file.php'"),
            'shared/stored_file.php must be required before the photo query runs.'
        );
    }

    // ── The program column ─────────────────────────────────────────

    /**
     * The table shows the acronym; the full name is still reachable.
     *
     * "BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)" is 51 characters
     * in a column a fifth of the table wide, which pushed the status badge and
     * the quality marker off to the right where they stopped being scannable.
     * courseAcronym() compresses it - and never returns an empty string, so a
     * program is never silently lost. The full name stays in the title
     * attribute and in full in the View modal, so this is a compression of the
     * record and never a replacement for it.
     */
    public function testProgramColumnShowsAcronymWithFullNameOnHover(): void
    {
        $page = self::page();

        self::assertMatchesRegularExpression(
            '/<td class="course-cell"\s+title="<\?= htmlspecialchars\(\$s\[.course.\]/',
            $page,
            'The program cell must keep the full course name in its title attribute.'
        );
        self::assertStringContainsString(
            "courseAcronym(\$s['course'] ?? '')",
            $page,
            'The program cell must render the acronym, not the full program name.'
        );
    }

    /**
     * Initials are first-and-last, with an honest placeholder.
     *
     * A middle initial is noise in a small square, and an empty box reads as a
     * failed load rather than as a student with no name yet.
     */
    public function testInitialsAreFirstAndLastOnly(): void
    {
        require_once self::STORED;

        self::assertSame('RT', studentInitials('Roldan', 'Tenco'));
        self::assertSame('JR', studentInitials('Juan Miguel', 'Reyes'), 'A middle name is not an initial.');
        self::assertSame('R', studentInitials('Roldan', ''), 'One name still yields an initial.');
        self::assertSame('?', studentInitials('', ''), 'No name is a placeholder, not an empty box.');
        // A leading space must not become the first letter.
        self::assertSame('RT', studentInitials('  Roldan ', '  Tenco  '));
    }

    // ── Guardians ─────────────────────────────────────────────────

    /**
     * A guardian is a LIST, and the View modal was showing one of them.
     *
     * It called action=guardian (singular), which is
     * "ORDER BY is_primary DESC LIMIT 1". The guardians table holds several per
     * student - the Edit modal alone saves father, mother and a named guardian
     * - so a second guardian or a co-parent was silently dropped from the
     * record view. A clerk deciding whether they can reach someone had no way
     * to know the person existed.
     *
     * The Edit modal keeps the singular endpoint on purpose: it edits one
     * guardian into fixed fields, so the primary is the right one there. Only
     * the View modal needs all of them.
     */
    public function testViewModalListsEveryGuardianNotJustThePrimary(): void
    {
        $page = self::page();
        $view = $this->viewStudentBody();

        self::assertStringContainsString(
            'action=guardians&student_id=',
            $view,
            'The View modal must fetch every guardian, not the single primary one.'
        );
        self::assertStringNotContainsString(
            "action=guardian&student_id=",
            $view,
            'The View modal is back on the singular guardian endpoint, which drops the rest.'
        );

        // The Edit modal keeps the singular endpoint on purpose - it edits one
        // guardian into fixed fields. Asserted so "switch to the plural
        // endpoint" does not get applied there as a side effect and break it.
        self::assertStringContainsString(
            "action=guardian&student_id='+id",
            $page,
            'The Edit modal should still load the single primary guardian into its fields.'
        );
    }

    /**
     * Guardian values are escaped.
     *
     * The old renderer concatenated full_name and relationship straight into
     * innerHTML, so a guardian recorded as "Ana <b>Santos" injected markup into
     * the record view. Verified in a browser: a name containing <b> is stored as
     * literal text and no <b> element appears in the output.
     */
    public function testGuardianFieldsAreEscaped(): void
    {
        $view = $this->viewStudentBody();

        // Every interpolated guardian value must go through esc(). Asserted on
        // the interpolations themselves, not merely on esc() existing somewhere
        // in the file - a helper that is defined but not used is the same bug.
        //
        // The pattern allows for a fallback inside the call
        // (esc(g.full_name||'-')), which is what the renderer actually does, so
        // a tightened pattern here would fail on correct code.
        foreach (['g.full_name', 'g.relationship', 'g.contact_number', 'g.email'] as $field) {
            self::assertMatchesRegularExpression(
                '/esc\(' . preg_quote($field, '/') . '[\s\S]{0,12}?\)/',
                $view,
                "$field is interpolated into innerHTML without esc()."
            );
        }
    }

    /**
     * The guardian block is a real container in the sheet's rhythm.
     *
     * It was a bare div with inline styles after the grid: its own 10px
     * micro-label, no card behind it, and a name and phone number run together
     * in one text node with a <br>. So it did not line up with the Father and
     * Mother cells above it, which is what "no proper alignment and container"
     * was describing.
     *
     * Measured in a browser after the fix: the guardian card's left edge and
     * width are identical to a .view-item's (delta 0px on both).
     */
    public function testGuardianBlockIsAContainerInTheSheetRhythm(): void
    {
        $page = self::page();

        self::assertStringContainsString('class="vs-guardians"', $page);
        self::assertStringContainsString('class="vs-guardians-list"', $page);
        self::assertMatchesRegularExpression(
            '/\.vs-g-card\s*\{[^}]*background:[^}]*border[^}]*border-radius/',
            $page,
            'A guardian must render as a card, matching the field cards beside it.'
        );
        // One card per guardian, not one blob of text.
        self::assertStringContainsString('class="vs-g-card"', $page);

        // The inline-styled one-off is gone.
        self::assertSame(
            0,
            preg_match('/id="vGuardianInfo"\s+style="/', $page),
            'The guardian container is inline-styled again instead of using the sheet rhythm.'
        );
    }

    /** The body of viewStudent(), up to the next top-level function. */
    private function viewStudentBody(): string
    {
        $page = self::page();
        $start = (int) strpos($page, 'function viewStudent(id)');
        self::assertGreaterThan(0, $start, 'viewStudent() not found.');

        $slice = substr($page, $start, 12000);
        // CRLF-safe: the file has \r\n line endings, so a "\nfunction " needle
        // does not match and the slice silently runs to its length cap - which
        // then matches assertions belonging to functions further down the file.
        $end = preg_match('/\r?\nfunction /', $slice, $m, PREG_OFFSET_CAPTURE) === 1
            ? $m[0][1]
            : null;
        return $end === null ? $slice : substr($slice, 0, $end);
    }

    // ── Documents ──────────────────────────────────────────────────

    /**
     * The Documents tab must read the file store, not just the request log.
     *
     * It queried document_requests and nothing else. That table is the
     * counter's walk-in log, not a file store, and the two vocabularies barely
     * overlap - document_requests.document_type is
     * (form137, good_moral, transcript, certificate, clearance) while stored
     * files are documents.doc_type =
     * (enrollment, transcript, health, photo, clearance, other, form_137, psa).
     * Only transcript and clearance appear in both.
     *
     * So the tab described request history while the student's actual uploaded
     * files - in the same documents table the photo is read from - were never
     * queried. The empty state read "No document requests.", which for a
     * student with a photo and a Form 137 on file looks like having nothing.
     */
    public function testDocumentsTabReadsTheFileStoreNotJustRequests(): void
    {
        $api  = file_get_contents(self::API);
        $page = self::page();

        self::assertStringContainsString('FROM documents', $api, 'The endpoint must read the documents table.');
        self::assertStringContainsString('FROM document_requests', $api, 'Counter requests are real history.');

        foreach (["'files'", "'requests'", "'missing'", "'complete'"] as $key) {
            self::assertStringContainsString($key, $api, "The documents payload lost $key.");
        }

        $js = substr($page, (int) strpos($page, 'function loadDocuments(sid)'));
        $end = preg_match('/\r?\nfunction /', $js, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : null;
        $js = $end === null ? $js : substr($js, 0, $end);
        self::assertStringContainsString('d.data.files', $js, 'The tab must render stored files.');
        self::assertStringContainsString('d.data.missing', $js, 'The tab must render what is missing.');

        self::assertStringNotContainsString(
            'No document requests.',
            $page,
            'The old empty state is back - it hides a student who has files on file.'
        );
    }

    /**
     * A file that is not on this server is reported, not silently dropped.
     *
     * uploads/ is gitignored, so a database promoted from another host carries
     * rows naming files this one never received. Counting those toward
     * completeness would tell a clerk a student is clear to enrol when the
     * paperwork is not actually here.
     */
    public function testCompletenessCountsOnlyFilesThatAreReallyPresent(): void
    {
        $api = file_get_contents(self::API);

        self::assertStringContainsString("'on_disk'", $api, 'Each file must report whether it is on disk.');
        self::assertStringContainsString("'gone_count'", $api, 'The count of unreachable files must be reported.');
        self::assertMatchesRegularExpression(
            '/if \(\$url !== \'\'\) \{\s*\$typesPresent\[\]/',
            $api,
            'Only a file that is really on disk may count toward completeness.'
        );
    }

    /**
     * The required-types list has one definition.
     *
     * It was a bare array in registrar/file-storage.php, and the View modal
     * needs the same answer. Two copies would mean a clerk told a student they
     * still owe a clearance on one page while the other called them complete.
     */
    public function testRequiredDocumentListIsDefinedOnce(): void
    {
        require_once self::STORED;

        self::assertSame(
            ['enrollment', 'transcript', 'health', 'photo', 'clearance'],
            requiredDocumentTypes()
        );

        $storage = file_get_contents(self::FIELDS);
        self::assertStringContainsString(
            'requiredDocumentTypes()',
            $storage,
            'File Storage must use the shared list rather than its own copy.'
        );
        self::assertSame(
            0,
            preg_match('/\$requiredTypes\s*=\s*\[/', $storage),
            'File Storage is holding its own literal list of required types again.'
        );
    }

    /**
     * The two doc_type vocabularies stay distinct.
     *
     * shared/functions.php already has documentTypeLabel() for
     * document_requests.document_type. Adding a second function of the same name
     * for documents.doc_type is a fatal redeclare, and quietly reusing the
     * wrong one is how a photo ends up labelled "Good Moral Certificate".
     * The stored-file helpers are named for their table so the two cannot be
     * swapped by accident.
     */
    public function testStoredDocTypeLabelsDoNotCollideWithTheRequestLabels(): void
    {
        require_once self::STORED;
        require_once self::FUNCTIONS;

        self::assertTrue(function_exists('storedDocTypeLabel'));
        self::assertTrue(function_exists('documentTypeLabel'), 'The request-side label helper should still exist.');

        // Same word, different tables, different answers. This is exactly the
        // distinction the whole bug turned on.
        self::assertSame('Form 137', storedDocTypeLabel('form_137'));
        self::assertSame('', documentTypeLabel('form_137'), 'form_137 is not a request type.');

        self::assertSame('Good Moral Certificate', documentTypeLabel('good_moral'));
        self::assertNotSame('', storedDocTypeLabel('good_moral'), 'An unmapped stored type still gets a name.');

        // An unknown type is never blank - a visible gap beats a silent one.
        self::assertSame('Something new', storedDocTypeLabel('something_new'));
        self::assertSame('Document', storedDocTypeLabel(''));
    }

    /**
     * Completeness is judged by type, not by counting files.
     *
     * Three clearance scans must not make a student three-fifths of the way
     * through, and the number must not move when a duplicate is uploaded.
     */
    public function testCompletenessIsByTypeNotByFileCount(): void
    {
        require_once self::STORED;

        $one = documentCompleteness(['photo', 'clearance']);
        self::assertSame(['enrollment', 'transcript', 'health'], $one['missing']);
        self::assertFalse($one['complete']);

        $dupe = documentCompleteness(['photo', 'clearance', 'clearance', 'photo']);
        self::assertSame($one['missing'], $dupe['missing'], 'Duplicate files changed the missing set.');
        self::assertFalse($dupe['complete']);

        $all = documentCompleteness(requiredDocumentTypes());
        self::assertSame([], $all['missing']);
        self::assertTrue($all['complete']);

        $none = documentCompleteness([]);
        self::assertCount(5, $none['missing']);
        self::assertFalse($none['complete']);
    }
}




