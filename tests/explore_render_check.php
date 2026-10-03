<?php
// Render check for the FOLDER BROWSER (view=explore), over real HTTP.
//
//   php tests/explore_render_check.php
//
// The unit tests pin what mlf_resolve() returns. They cannot catch
// what this page then does with it - a breadcrumb printed twice, a
// tile whose link is wrong, a column count that does not match
// mlf_roster_columns(). Those are markup, and markup only fails when
// it is rendered, so this fetches the page and reads the HTML the
// registrar would actually get.
//
// Read-only: it issues GETs and writes nothing.

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/masterlist_folders.php';

$fail = 0; $ok = 0;
function check(string $label, bool $cond, string $detail = ''): void
{
    global $fail, $ok;
    if ($cond) { $ok++;  printf("  ok    %s\n", $label); }
    else       { $fail++; printf("  FAIL  %s%s\n", $label, $detail ? " ($detail)" : ''); }
}

$base = rtrim(($argv[1] ?? 'http://localhost/registrar-ai-system'), '/');
printf("folder browser render - %s\n", $base);

$db = Database::getInstance();
$user = $db->fetchOne("SELECT id FROM users WHERE role = 'registrar' ORDER BY id LIMIT 1");
if (!$user) {
    fwrite(STDERR, "  no registrar account to test with\n");
    exit(1);
}
$sid = (int) $user['id'];

// A session the page will accept. The page only reads
// $_SESSION['user_id'] / role, so a session file is written directly
// rather than driving the real login form: no password is needed and
// no credential is touched.
//
// The cookie name is the app's own (shared/session_config.php sets
// BCP_REGISTRAR_SESSION) - a check that sends PHPSESSID gets silently
// bounced to the login page, and then every assertion below fails for
// a reason that has nothing to do with the folders.
$sessDir  = ini_get('session.save_path') ?: sys_get_temp_dir();
$sessId   = bin2hex(random_bytes(16));
$sessPath = rtrim($sessDir, '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sessId;
file_put_contents($sessPath, 'user_id|i:' . $sid . ';role|s:9:"registrar";');
register_shutdown_function(static function () use ($sessPath) { @unlink($sessPath); });

function page(string $qs, string $sessId): string
{
    global $base;
    $ch = curl_init($base . '/registrar/masterlist.php?' . $qs);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIE        => 'BCP_REGISTRAR_SESSION=' . $sessId,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        fwrite(STDERR, "  request failed: $err\n");
        exit(1);
    }
    return (string) $body;
}

// Assertions below look for class names, and every class name also
// appears in this page's <style> and <script> blocks. Comparing
// against the raw HTML would match that CSS/JS and pass (or fail) for
// the wrong reason, so both are stripped first and what is left is the
// markup the registrar actually sees.
function markup(string $html): string
{
    return (string) preg_replace(
        '#<(style|script)\b[^>]*>.*?</\1>#is',
        '',
        $html
    );
}

// A real path to click into, read out of the tree so the check
// follows whatever this database actually holds.
$sample = $db->fetchOne(
    "SELECT TRIM(course) AS c, year_level AS y, TRIM(section) AS s
     FROM students
     WHERE TRIM(course) <> '' AND TRIM(section) <> '' AND year_level IS NOT NULL
     ORDER BY id LIMIT 1"
);
if (!$sample) {
    fwrite(STDERR, "  no placed student to browse to\n");
    exit(1);
}
$prog = (string) $sample['c'];
$year = 'Year ' . (int) $sample['y'];
$sec  = (string) $sample['s'];
printf("browsing to %s/%s/%s\n\n", $prog, $year, $sec);

echo "no view toggle\n";
$root = markup(page('view=explore', $sessId));
check('the browser renders', strpos($root, 'mlx-browser') !== false);

// The header has NO view buttons at all - Browse, Table and List were
// all removed. So this asserts the toggle is ABSENT, which is the
// exact inverse of the check that used to live here.
//
// Asserting absence is the only honest form of this test. "Browse is
// present" would have passed just as happily with the old three-button
// toggle still in place, so it never actually tested the removal.
//
// Scoped to the <header> rather than the whole document, because
// `?view=folders` and `?view=list` legitimately appear elsewhere - in
// the HTML comment explaining the views still exist, and in the notes
// under each folder tree. Failing on those would fail for the wrong
// reason.
preg_match('#<header.*?</header>#s', $root, $hdr);
$header = $hdr[0] ?? '';
check('the header is present', $header !== '');
check('no view buttons remain in the header',
    preg_match_all('/<a class="mlv-btn/', $header, $none) === 0,
    'found: ' . count($none[0] ?? []));
check('the toggle group itself is gone',
    strpos($header, 'masterlist-views') === false);
// Comments are stripped first: the header carries a long explanatory
// comment that names ?view=folders and ?view=list in prose, which is
// documentation, not a link. Checking the raw text fails on the very
// comment that explains the removal.
$headerNoComment = (string) preg_replace('#<!--.*?-->#s', '', $header);
check('no link in the header switches view',
    preg_match('/\?view=(list|folders|explore)/', $headerNoComment) !== 1,
    'a view-switching link is still in the header');
// The CSS for the removed toggle went with it. A rule left behind is
// dead weight that the next button added will inherit.
check('the toggle CSS was removed too',
    strpos($root, '.mlv-btn') === false && strpos($root, '.masterlist-views') === false);
check('an unrecognised ?view= falls back to the list, not a blank page',
    strpos(page('view=bogus', $sessId), 'masterlist-table') !== false);

echo "\nthe root\n";
check('programs are tiles, not rows inside a table',
    strpos($root, 'mlx-tile') !== false && strpos($root, 'mlf-row-folder') === false);
check('no roster is shown at the root', strpos($root, 'mlx-roster') === false);
$progLink = 'view=explore&amp;path=' . rawurlencode($prog);
check('the program tile links into the program',
    strpos($root, $progLink) !== false, 'looking for ' . $progLink);

// ── PROGRAMS PRINT AS ACRONYMS ─────────────────────────────────
// A tile, a breadcrumb and a dropdown all read "BSIT", not 55
// characters of degree title. The full name has to survive as the
// link target and the tooltip, though - this is a display change and
// must not touch what the URL or the `course` column mean.
echo "\nprogram names are acronyms\n";
$short = courseDisplay($prog);
check('the sample program has a short form', $short !== $prog && $short !== '',
    'courseDisplay returned: ' . $short);

// Every tile at the root must lead with the acronym of its own
// program, and carry the full name in its title.
//
// The program each tile points at is read out of its OWN href - the
// path segment holds the real, unabbreviated course string, so this
// compares the printed label against the acronym derived from the
// link. That way the check works for every program in the database,
// not just the one this file sampled.
preg_match_all('#<a class="mlx-tile[^"]*"[^>]*href="([^"]*)"[^>]*>(.*?)</a>#s', $root, $tileMatch, PREG_SET_ORDER);
$badLabel = [];
$noTitle  = [];
foreach ($tileMatch as $tile) {
    preg_match('#mlx-tile-name">(.*?)</span>#s', $tile[2], $nm);
    preg_match('#title="([^"]*)"#', $tile[0], $ti);
    $label = trim((string) ($nm[1] ?? ''));
    // The program this tile leads to, taken from its href.
    $hrefFull = html_entity_decode((string) $tile[1], ENT_QUOTES, 'UTF-8');
    $want     = courseDisplay((string) (preg_match('#path=([^&]+)#', $hrefFull, $pm) ? rawurldecode($pm[1]) : ''));
    // The tile is wrong if it prints something other than the acronym
    // of the program it links to.
    if ($label === '' || $label !== $want) {
        $badLabel[] = $label . ' (wanted ' . $want . ')';
    }
    // If that program WAS abbreviated, the expansion must be reachable.
    $program = (string) (preg_match('#path=([^&]+)#', $hrefFull, $pm2) ? rawurldecode($pm2[1]) : '');
    if (courseDisplayTitle($program) !== '' && !isset($ti[1])) {
        $noTitle[] = $label;
    }
}
check('no program tile prints its full name', $badLabel === [],
    'wrong labels: ' . implode(' | ', array_slice($badLabel, 0, 3)));
check('every abbreviated tile keeps the full name on hover', $noTitle === [],
    'missing title on: ' . implode(', ', array_slice($noTitle, 0, 3)));

// The abbreviation is DISPLAY ONLY. The href must still carry the
// full course string, or the link resolves to nothing.
check('the tile link still carries the FULL program name',
    strpos($root, 'path=' . rawurlencode($prog)) !== false,
    'abbreviation leaked into the link');
check('and the acronym never appears in a link target',
    strpos($root, 'path=' . rawurlencode($short)) === false
    || $short === $prog);

// The filter dropdown: acronym in the label, full string as the
// value. Abbreviating the value would make the filter match nothing.
preg_match_all('#<option value="([^"]*)"[^>]*>([^<]*)</option>#', $root, $optMatch, PREG_SET_ORDER);
$badOpt = [];
foreach ($optMatch as $o) {
    $value = html_entity_decode($o[1], ENT_QUOTES, 'UTF-8');
    $label = trim(html_entity_decode($o[2], ENT_QUOTES, 'UTF-8'));
    // Only the Course select matters here; Year/Semester options have
    // short values already and are not programs.
    if ($value === $prog || $value === $short) {
        if ($label !== $short) {
            $badOpt[] = $value . ' -> ' . $label;
        }
    }
}
check('the course dropdown lists acronyms', $badOpt === [],
    'unabbreviated: ' . implode(' | ', array_slice($badOpt, 0, 3)));
check('the course dropdown keeps full values',
    preg_match('#<option value="' . preg_quote(htmlspecialchars($prog), '#') . '"#', $root) === 1,
    'the full course string is not an option value');

// Years and sections must NOT be abbreviated: "Year 1" has no
// shorter true form, and a section code IS its own name. Fetched here
// rather than reusing $inProg, which is only defined further down.
$inProgEarly = markup(page('view=explore&path=' . rawurlencode($prog), $sessId));
check('year folders keep their real name', strpos($inProgEarly, 'Year 1') !== false);
check('unassigned work folders are never abbreviated',
    courseDisplay('Unassigned Program') === 'Unassigned Program',
    'got: ' . courseDisplay('Unassigned Program'));

// ── THE LEGEND ─────────────────────────────────────────────────
// Abbreviating every program is only half a decision. The legend is
// the other half: it is where a reader looks up what an acronym
// MEANS. Without it the abbreviation is a barrier rather than a
// compression, so its absence is a real defect and not a cosmetic one.
echo "\nthe program legend\n";
check('the legend is rendered', strpos($root, 'mlx-legend') !== false);
preg_match('#<section class="mlx-legend.*?</section>#s', $root, $lg);
$legend = $lg[0] ?? '';
check('the legend has a body', $legend !== '');

// Every program that appears as a tile must appear in the legend.
// Compared by acronym, because that is what both surfaces print -
// deriving one from the other is what makes the two provably agree.
preg_match_all('#mlx-legend-tag">(.*?)</span>#s', $legend, $legendTags);
$legendShorts = array_map('trim', $legendTags[1]);
$missing = [];
foreach ($tileMatch as $tile) {
    preg_match('#mlx-tile-name">(.*?)</span>#s', $tile[2], $nm);
    $short = trim((string) ($nm[1] ?? ''));
    if ($short !== '' && !in_array($short, $legendShorts, true)) {
        $missing[] = $short;
    }
}
check('every program on the page is in the legend', $missing === [],
    'not in the legend: ' . implode(', ', array_slice($missing, 0, 3)));

// The legend's whole purpose is decoding, so the full name has to be
// PRINTED in it - not hidden behind a hover the way the tiles are.
$fullMissing = [];
foreach ($legendShorts as $short) {
    // Resolve the tile href back to the real course name and require
    // that exact string to appear somewhere in the legend markup.
    $found = false;
    foreach ($tileMatch as $tile) {
        preg_match('#mlx-tile-name">(.*?)</span>#s', $tile[2], $nm);
        if (trim((string) ($nm[1] ?? '')) !== $short) {
            continue;
        }
        $href = html_entity_decode((string) $tile[1], ENT_QUOTES, 'UTF-8');
        $real = (string) (preg_match('#path=([^&]+)#', $href, $pm) ? rawurldecode($pm[1]) : '');
        if ($real !== '' && strpos($legend, htmlspecialchars($real)) !== false) {
            $found = true;
        }
    }
    if (!$found) {
        $fullMissing[] = $short;
    }
}
check('each acronym is decoded to its full name', $fullMissing === [],
    'no full name for: ' . implode(', ', array_slice($fullMissing, 0, 3)));

// It is a reference, so it must not print on a sheet that gets signed.
// Checked against the RAW page, not markup(): the rule lives in the
// <style> block, and markup() strips that before anything else, so
// asserting on it there would pass whatever the CSS said.
$rootRaw = page('view=explore', $sessId);
check('the legend is hidden when printing',
    preg_match('/@media print\s*\{(?:[^{}]|\{[^{}]*\})*?\.mlx-legend\s*\{[^}]*display\s*:\s*none/s', $rootRaw) === 1);

echo "\ninside a program\n";
$inProg = markup(page('view=explore&path=' . rawurlencode($prog), $sessId));
check('the program is the heading', strpos($inProg, htmlspecialchars($prog)) !== false);
check('its year folders are listed', strpos($inProg, htmlspecialchars($year)) !== false);
check('still no roster one level down', strpos($inProg, 'mlx-roster') === false);
check('"Up one level" is offered', strpos($inProg, 'Up one level') !== false);
check('the current folder appears in the breadcrumb exactly once',
    substr_count($inProg, 'mlx-crumb-here') === 1,
    'count: ' . substr_count($inProg, 'mlx-crumb-here'));

echo "\ninside a year\n";
$inYear = markup(page('view=explore&path=' . rawurlencode($prog . '/' . $year), $sessId));
check('its section folders are listed', strpos($inYear, htmlspecialchars($sec)) !== false);
check('still no roster at year level', strpos($inYear, 'mlx-roster') === false);

echo "\ninside a section - the details table\n";
$inSec = markup(page('view=explore&path=' . rawurlencode($prog . '/' . $year . '/' . $sec), $sessId));
// .mlx-table, not .masterlist-table: the roster is its own table and
// must not inherit the printable List's fixed tracks, its 1390px floor,
// or its CSV/sort handlers.
check('the roster table is rendered', strpos($inSec, 'mlx-table') !== false);
check('it does NOT borrow the List ledger class',
    strpos($inSec, 'class="masterlist-table') === false);
check('a leaf offers no folders to click', strpos($inSec, 'mlx-folders') === false);
$missingCols = [];
foreach (mlf_roster_columns() as $col) {
    if (strpos($inSec, '>' . htmlspecialchars($col) . '<') === false) {
        $missingCols[] = $col;
    }
}
check('every roster column is headed', $missingCols === [],
    'missing: ' . implode(', ', $missingCols));
check('the roster has a body with rows',
    preg_match('/mlx-roster.*?<tbody>(.*?)<\/tbody>/s', $inSec, $m) === 1
    && substr_count($m[1] ?? '', '<tr>') >= 1);
check('the section\'s own students are the rows shown',
    strpos($inSec, htmlspecialchars($sec)) !== false);

echo "\nlinks\n";
preg_match_all('/masterlist\.php\?view=explore[^"\']*/', $inSec, $links);
check('navigation is plain links to this view', ($links[0] ?? []) !== []);
check('no folder link carries an HTML-escaping bug',
    strpos($inSec, 'path=&amp;amp;') === false);

echo "\nstale links\n";
$bad = markup(page('view=explore&path=' . rawurlencode('NoSuchProgram/X'), $sessId));
check('a missing folder says so', strpos($bad, 'That folder is not here') !== false);
check('and still shows the programs rather than an empty screen',
    strpos($bad, 'mlx-tile') !== false);

echo "\nno errors leaked\n";
foreach (['root' => $root, 'program' => $inProg, 'year' => $inYear,
          'section' => $inSec, 'stale' => $bad] as $where => $html) {
    check("$where renders clean",
        strpos($html, 'Warning:') === false
        && strpos($html, 'Fatal error') === false
        && strpos($html, 'Undefined ') === false);
}

echo "\nthe bare URL\n";
// Browse is the landing view, so the bare URL must open it. A stale
// ?view= must not dead-end either.
$bare = markup(page('', $sessId));
check('the bare URL opens the folder browser', strpos($bare, 'mlx-browser') !== false);
// There is no button left to mark active, so the page must still be
// readable on its own terms: a bare URL is now the ONLY way to reach
// Browse, which is exactly why it has to work.
check('and it needs no button to be marked active',
    strpos($bare, 'mlv-btn') === false && strpos($bare, 'masterlist-views') === false);
check('an unrecognised ?view= lands on the default, not a blank page',
    strpos(markup(page('view=bogus', $sessId)), 'mlx-browser') !== false);

// The three view buttons were all removed from the header, but the
// VIEWS were not removed. These prove that: both unlinked views still
// render in full when asked for by URL. Without this, "delete the
// buttons" could quietly become "delete the features", and nothing
// else here would notice - a header with no buttons looks right
// either way.
echo "\nthe unlinked views still work by URL\n";
$table = markup(page('view=folders', $sessId));
check('the folder table still renders its rows',
    strpos($table, 'mlf-row-folder') !== false);
check('the folder table did not grow a second browser',
    strpos($table, 'mlx-browser') === false);
check('the folder table\'s expand/collapse controls are present',
    strpos($table, 'mlfExpandAll') !== false);
check('and it grew no header toggle of its own',
    strpos($table, 'masterlist-views') === false);

$list = markup(page('view=list', $sessId));
check('the printable list still renders', strpos($list, 'masterlist-table') !== false);
check('the printable list is not the browser', strpos($list, 'mlx-browser') === false);
// The bulk bar acts on the List's rows. If the List survives, so does
// its selection and export - a List you cannot hand off is half a view.
check('the printable list keeps its bulk select and export',
    strpos($list, 'selectAllPage') !== false
    && strpos($list, 'exportExcel') !== false);
check('and it grew no header toggle of its own',
    strpos($list, 'masterlist-views') === false);

// Filters must carry onto the BROWSER'S OWN folder links. The Browse
// button that used to carry them is gone, so $carryFilters() now has
// exactly one job - and if it broke, a reader who filtered to BSIT
// would click into a section and silently get every program back.
$filtered = markup(page('view=explore&course=' . rawurlencode($prog), $sessId));
// http_build_query() writes a space as "+", not "%20", so this looks
// for the key rather than the exact encoded value.
check('a filter survives onto the folder links',
    strpos($filtered, 'course=') !== false,
    'no course= anywhere on the filtered page');
// And on a drill-down, the filter has to still be there once you are
// inside the folder that carries the link.
$deep = markup(page('view=explore&course=' . rawurlencode($prog)
    . '&path=' . rawurlencode($prog), $sessId));
check('and it survives the click into the folder',
    strpos($deep, 'course=') !== false,
    'no course= on the links inside the folder');

echo "\n$ok checks passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
