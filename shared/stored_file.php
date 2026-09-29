<?php
// ============================================================
//  SHARED/STORED_FILE.PHP
//  What does a documents.file_path mean, and is it still there?
//
//  Two consumers must agree exactly, and they used not to:
//
//    · registrar/file-storage.php  - renders a thumbnail, a preview and a
//                                    Download link from the stored string
//    · api/documents.php           - decides whether a re-upload may replace
//                                    an existing record
//
//  If they disagree about whether a file exists, the page offers to "replace"
//  a record the API then refuses, or the API deletes a file the page believes
//  is safe to overwrite. So the rule lives here once.
//
//  The stored form is a path relative to /registrar/, e.g.
//      ../uploads/student_files/12/12_1790268412_download.jpg
//
//  Why that matters at all: uploads/ is gitignored. The files are runtime
//  artifacts of whichever host accepted the upload, so a database that arrived
//  from elsewhere - a copied dump, a migrated server, a staging box promoted
//  to production - carries rows naming files that were never deployed here.
//  Rendering those produces a 404, a broken-image glyph, an empty preview and
//  a Download button that fails, with nothing to tell a clerk what to do.
//
//  Two deliberate choices:
//
//  · It checks the disk. Returning a URL for a file that is not there is what
//    produced the original bug; a path is only useful if something can load it.
//
//  · It does NOT regenerate or re-upload anything. A page render is the wrong
//    place to write files, and a file that is genuinely gone has to be supplied
//    by a person.
// ============================================================

if (defined('STORED_FILE_LOADED')) {
    return;
}
define('STORED_FILE_LOADED', true);

/**
 * The stored path reduced to a safe path relative to the APP ROOT.
 *
 * '../' and './' prefixes are stripped because the stored string is written
 * relative to /registrar/ and consumed from the app root.
 *
 * Then the result must sit under one of the known upload roots:
 *
 *      uploads/…              file storage, student photos via api/students.php
 *      assets/uploads/…       student photos via api/student-upload-photo.php
 *
 * That allow-list is not decoration. Stripping '../' on its own is not a
 * containment check: '../shared/config.php' reduces to 'shared/config.php',
 * which is a file that really exists, so a crafted or simply wrong file_path
 * would resolve and the Download link would hand out application source. The
 * same argument is why '..' is refused outright afterwards - after the prefix
 * strip there is no legitimate way for one to remain.
 *
 * @return string  '' when the stored value is empty, remote, unsafe, or outside
 *                 the upload roots.
 */
function storedFileRel(?string $stored): string
{
    $stored = trim((string) $stored);
    if ($stored === '') return '';
    // Absolute, protocol-relative and in-memory URLs are not ours to resolve;
    // a caller that gets '' for these must not treat them as "missing".
    if (preg_match('#^(https?:)?//|^data:|^blob:#i', $stored)) return '';

    $rel = str_replace('\\', '/', $stored);
    $rel = ltrim($rel, '/');
    while (strpos($rel, '../') === 0) {
        $rel = substr($rel, 3);
    }
    while (strpos($rel, './') === 0) {
        $rel = substr($rel, 2);
    }
    if ($rel === '' || strpos($rel, '..') !== false) return '';

    $allowed = ['uploads/', 'assets/uploads/'];
    foreach ($allowed as $prefix) {
        if (strncmp($rel, $prefix, strlen($prefix)) === 0) {
            return $rel;
        }
    }
    return '';
}

/**
 * Absolute path of a stored file on THIS server, or null when it is not here.
 *
 * @param string|null $stored  documents.file_path
 * @param string      $appRoot App root on disk (defaults to this file's parent)
 * @return string|null
 */
function storedFileDiskPath(?string $stored, ?string $appRoot = null): ?string
{
    $rel = storedFileRel($stored);
    if ($rel === '') return null;

    $root = rtrim(str_replace('\\', '/', $appRoot ?? dirname(__DIR__)), '/');
    $abs  = $root . '/' . $rel;
    return is_file($abs) ? $abs : null;
}

/**
 * A URL for a stored file, or '' when there is nothing to point at.
 *
 * Returning '' rather than a plausible-looking URL is the whole point: the
 * caller can then branch on it, and a row whose file is gone renders an honest
 * "not on this server" state instead of a broken image and a console 404.
 *
 * @param string|null $stored  documents.file_path
 * @param string      $appRoot URL prefix for the page rendering it, e.g. '../'
 */
function storedFileUrl(?string $stored, string $appRoot = '../'): string
{
    $rel = storedFileRel($stored);
    if ($rel === '') return '';

    $root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    if (!is_file($root . '/' . $rel)) return '';

    // rawurlencode per segment, so spaces and unicode survive while the
    // separators stay separators.
    $encoded = implode('/', array_map('rawurlencode', explode('/', $rel)));
    return rtrim($appRoot, '/') . '/' . $encoded;
}

// ============================================================
//  DOCUMENT COMPLETENESS
// ============================================================
//
//  Which document types make a student's file set complete.
//
//  This lived as a bare array literal in registrar/file-storage.php. The View
//  modal needs exactly the same answer - "which of the five are still missing
//  for this student" - and copying the list would mean two pages that could
//  quietly disagree about what a complete file set is. A clerk told a student
//  "you still owe a clearance" on one page while the other page said they were
//  complete is a credibility problem, not a display bug.
//
//  The list is the office's, not the schema's. documents.doc_type also carries
//  'form_137' and 'psa', which are CTC-specific and are NOT part of the intake
//  set - so the enum and this list are deliberately different, and this function
//  is the only place the distinction is made.

/**
 * The document types every student is expected to have on file.
 *
 * @return string[] doc_type values
 */
function requiredDocumentTypes(): array
{
    return ['enrollment', 'transcript', 'health', 'photo', 'clearance'];
}

/**
 * Human labels for a documents.doc_type, for display.
 *
 * NOT the same vocabulary as documentTypeLabel() in shared/functions.php, which
 * labels document_requests.document_type (form137, good_moral, certificate) -
 * the counter's request types, a different enum with different meanings. Only
 * "transcript" and "clearance" appear in both, which is exactly the confusion
 * that let the View modal describe a student's file store using the request
 * table. These are named for the table they label so the two cannot be swapped.
 *
 * The column stores 'form_137' and 'enrollment' as machine tokens; neither
 * reads well next to a student's name.
 *
 * @return array<string,string> doc_type => label
 */
function storedDocTypeLabels(): array
{
    return [
        'enrollment' => 'Enrollment form',
        'transcript' => 'Transcript',
        'health'     => 'Health record',
        'photo'      => 'Photo',
        'clearance'  => 'Clearance',
        'form_137'   => 'Form 137',
        'psa'        => 'PSA',
        'other'      => 'Other',
    ];
}

/**
 * A display label for one documents.doc_type.
 *
 * Falls back to a title-cased version of the token rather than to an empty
 * string: an unfamiliar type is a gap in this map, and showing "Other" or "—"
 * for a document the clerk can plainly see is worse than showing its name.
 */
function storedDocTypeLabel(string $type): string
{
    $type = trim($type);
    $map  = storedDocTypeLabels();
    if (isset($map[$type])) return $map[$type];

    $spaced = str_replace('_', ' ', $type);
    return $spaced === '' ? 'Document' : ucfirst(strtolower($spaced));
}

/**
 * Split a student's stored types into what is present and what is still owed.
 *
 * The completeness test is by TYPE, not by file. A student with three
 * clearance scans is not twice as complete as one with a single scan, and
 * counting files would make the number move for no reason a clerk could act on.
 *
 * @param string[] $typesPresent  doc_type values the student already has
 * @return array{present: string[], missing: string[], complete: bool}
 */
function documentCompleteness(array $typesPresent): array
{
    $present = array_values(array_unique(array_filter(array_map('trim', $typesPresent), 'strlen')));
    $missing = array_values(array_diff(requiredDocumentTypes(), $present));

    return ['present' => $present, 'missing' => $missing, 'complete' => $missing === []];
}


// ============================================================
//  STUDENT PHOTOS
// ============================================================
//
//  A student's photograph is a *document*, held in Digital File Storage under
//  doc_type = 'photo'. The `students.photo` column is the older location and is
//  NULL for every student whose picture came through File Storage.
//
//  Both pages that show a face had this wrong in the same way, independently:
//  each read students.photo, found nothing, and rendered initials for a student
//  whose photograph was sitting in the database the whole time. So the
//  resolution order lives here, once, rather than being re-derived per page.
//
//  Both candidates go through storedFileUrl(), which checks the disk. uploads/
//  is gitignored, so a database that arrived from a copied dump or a promoted
//  staging box carries rows naming files this host never received. A missing
//  photograph must produce initials, never a broken-image glyph and a 404 - so
//  the "is it actually here" test is the whole point, not a nicety.
//
//  Returns '' when neither candidate is a readable file on this server. The
//  caller falls back to initials, which is always correct.

/**
 * SQL fragment selecting a student's stored photo, if they have one.
 *
 * Attached as `photo_path` on any students query. Kept as one string so the
 * subquery - including the file-type allow-list - cannot drift between pages.
 *
 * @return string  a SELECT list fragment, no FROM clause
 */
function studentPhotoSelectSql(): string
{
    return "(SELECT d.file_path
               FROM documents d
              WHERE d.student_id = s.id
                AND d.doc_type = 'photo'
                AND LOWER(d.file_type) IN ('jpg','jpeg','png','webp','gif')
              ORDER BY d.created_at DESC, d.id DESC
              LIMIT 1)";
}

/**
 * The best available URL for a student's photograph, or '' if there is none
 * that this server can actually serve.
 *
 * Order of preference: the `students.photo` column first (a photo placed there
 * directly is the more explicit signal), then the newest File Storage document.
 * Both are disk-checked.
 *
 * @param array|object $student  a row carrying `photo` and/or `photo_path`
 * @param string       $appRoot  URL prefix for the page rendering it
 */
function studentPhotoUrl($student, string $appRoot = '../'): string
{
    $get = static function (string $key) use ($student) {
        if (is_array($student)) return $student[$key] ?? '';
        if (is_object($student)) return $student->$key ?? '';
        return '';
    };

    foreach ([$get('photo'), $get('photo_path')] as $candidate) {
        $url = storedFileUrl($candidate, $appRoot);
        if ($url !== '') return $url;
    }
    return '';
}

/**
 * Initials for an avatar fallback: first letter of first and last name.
 *
 * Middle names are deliberately skipped - "Juan Miguel Reyes" is JR, not JMR.
 * A middle initial is noise in a 32px circle.
 */
function studentInitials(string $first, string $last): string
{
    $first = trim($first);
    $last  = trim($last);
    $a = $first !== '' ? strtoupper(substr($first, 0, 1)) : '';
    $b = $last  !== '' ? strtoupper(substr($last, 0, 1))  : '';
    $out = trim($a . $b);
    return $out !== '' ? $out : '?';
}
