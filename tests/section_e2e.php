<?php
// ============================================================
//  SECTION E2E CHECK
//  Drives the revived sectioning over real HTTP with a real
//  session, so what is proven is the page and the API as a
//  browser reaches them - not the functions in isolation.
//
//  Requires Apache + MySQL running and students to exist.
//  Everything it writes is confined to the T9xxxxxx seeded rows,
//  which tests/clear_seeded_students.php removes.
//
//    php tests/section_e2e.php
// ============================================================
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
// csrf_guard.php pulls in session_config.php, which starts the session.
// That is why this script must not call session_start() itself: a second
// start is refused, and the id we asked for would be quietly discarded -
// the request would then land on the login page while the check passed.
require_once __DIR__ . '/../shared/csrf_guard.php';

$BASE = 'http://localhost/registrar-ai-system';
$db   = Database::getInstance();

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  OK   $label\n"; }
    else    { $fail++; echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "== SETUP ==\n";
$u = $db->fetchOne("SELECT id, full_name, role FROM users WHERE role IN ('admin','registrar') AND is_active = 1 ORDER BY id LIMIT 1");
if (!$u) { fwrite(STDERR, "No active registrar/admin user.\n"); exit(1); }
$seeded = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE student_number LIKE 'T9%'");
if ($seeded === 0) { fwrite(STDERR, "No seeded students. Run: php tests/seed_masterlist_150.php --execute\n"); exit(1); }
echo "  user #{$u['id']} ({$u['role']}), $seeded seeded students\n";

// Start from a known state: no sections anywhere.
$db->query("UPDATE students SET section = NULL WHERE student_number LIKE 'T9%'");

// A real session file, with a CSRF token the API will accept.
// session_config.php has already opened one; reuse it rather than
// starting a second, which PHP refuses and which would leave us
// holding an id no HTTP request will ever present.
$token = csrfToken();
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['user_id']       = (int) $u['id'];
    $_SESSION['role']          = (string) $u['role'];
    $_SESSION['full_name']     = (string) $u['full_name'];
    $_SESSION['email']         = '';
    $_SESSION['last_activity'] = time();
    $id = session_id();
    session_write_close();
} else {
    $id = 'sectione2e' . substr(bin2hex(random_bytes(8)), 0, 15);
    ini_set('session.use_strict_mode', '0');
    ini_set('session.use_cookies', '0');
    session_id($id);
    session_start();
    $_SESSION['user_id']       = (int) $u['id'];
    $_SESSION['role']          = (string) $u['role'];
    $_SESSION['full_name']     = (string) $u['full_name'];
    $_SESSION['email']         = '';
    $_SESSION['last_activity'] = time();
    session_write_close();
}
echo "  session $id\n";

$jar = tempnam(sys_get_temp_dir(), 'mlcookie');
// Seed the jar with OUR session id. Without this the server mints a
// fresh empty session on the first request, the CSRF token does not
// match, and every POST comes back 419 - which looks exactly like a
// broken CSRF guard rather than a harness that never logged in.
file_put_contents($jar, "# Netscape HTTP Cookie File\n"
    . implode("\t", ['.' . parse_url($BASE, PHP_URL_HOST), 'FALSE', '/', 'FALSE', '0', 'BCP_REGISTRAR_SESSION', $id]) . "\n");

function req(string $method, string $url, ?array $payload = null) {
    global $jar, $token;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_HEADER         => true,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-CSRF-Token: ' . $token,
        ]);
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = substr((string) $raw, $hs);
    $json = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'json' => is_array($json) ? $json : null];
}
function counts(): array {
    global $db;
    return [
        'assigned' => (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE student_number LIKE 'T9%' AND section IS NOT NULL AND TRIM(section) <> ''"),
        'sections' => (int) $db->fetchColumn("SELECT COUNT(DISTINCT section) FROM students WHERE student_number LIKE 'T9%' AND section IS NOT NULL AND TRIM(section) <> ''"),
        'over_cap' => (int) $db->fetchColumn("SELECT COUNT(*) FROM (SELECT section FROM students WHERE student_number LIKE 'T9%' AND section IS NOT NULL AND TRIM(section) <> '' GROUP BY section HAVING COUNT(*) > 50) x"),
        'total'    => (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE student_number LIKE 'T9%'"),
    ];
}
$cap = (int) (defined('MAX_STUDENTS_PER_SECTION') ? MAX_STUDENTS_PER_SECTION : 50);
echo "  cap = $cap\n";

echo "\n== 1. AUTO-ASSIGN ==\n";
$r = req('POST', "$BASE/api/masterlist.php", ['action' => 'assign_sections', 'max_per_section' => $cap]);
check('assign_sections returns 200', $r['status'] === 200, 'got ' . $r['status']);
check('assign_sections succeeds', ($r['json']['success'] ?? false) === true, $r['body']);
check('reports the number it updated', isset($r['json']['updated']), $r['body']);

$c = counts();
check("every seeded student now has a section ({$c['assigned']}/{$c['total']})", $c['assigned'] === $c['total'], "{$c['assigned']} of {$c['total']}");
check('no section exceeds the cap', $c['over_cap'] === 0, "{$c['over_cap']} over");
check('created more than one section', $c['sections'] > 1, (string) $c['sections']);

// 150 students over 3 year levels: 90 / 40 / 20. The 90 must split 50+40.
$rows = $db->fetchAll("SELECT year_level, section, COUNT(*) c FROM students WHERE student_number LIKE 'T9%' AND section IS NOT NULL GROUP BY year_level, section ORDER BY year_level, section");
echo "  layout:\n";
foreach ($rows as $row) { echo "    Year {$row['year_level']}  {$row['section']}  {$row['c']}\n"; }

$y1 = array_values(array_filter($rows, fn($r) => (int) $r['year_level'] === 1));
check('Year 1 (90 students) split into 50 + 40', count($y1) === 2
    && (int) $y1[0]['c'] === 50 && (int) $y1[1]['c'] === 40,
    json_encode(array_map(fn($r) => $r['section'] . ':' . $r['c'], $y1)));
check('Year 1 codes are 11xxx (year 1, 1st sem)',
    !empty($y1) && str_starts_with((string) $y1[0]['section'], '11'), $y1 ? $y1[0]['section'] : 'none');

$y2 = array_values(array_filter($rows, fn($r) => (int) $r['year_level'] === 2));
check('Year 2 (40 students) fits one section', count($y2) === 1 && (int) $y2[0]['c'] === 40, json_encode($y2));
check('Year 2 codes are 21xxx (year 2, 1st sem)',
    !empty($y2) && str_starts_with((string) $y2[0]['section'], '21'), $y2 ? $y2[0]['section'] : 'none');
// Everything below reads $y1[0]; without a section there is nothing
// sensible to continue into, and the run would die on a null offset
// rather than reporting which check actually failed.
if (empty($y1)) {
    echo "\n  Auto-assign produced no Year-1 section - stopping here.\n";
    echo "  $pass passed, $fail failed\n";
    exit(1);
}

echo "\n== 2. RE-RUN IS IDEMPOTENT ==\n";
$before = $db->fetchAll("SELECT id, section FROM students WHERE student_number LIKE 'T9%' ORDER BY id");
$r = req('POST', "$BASE/api/masterlist.php", ['action' => 'assign_sections', 'max_per_section' => $cap]);
check('re-run succeeds', ($r['json']['success'] ?? false) === true, $r['body']);
check('re-run assigns nobody', (int) ($r['json']['updated'] ?? -1) === 0, 'updated=' . ($r['json']['updated'] ?? '?'));
$after = $db->fetchAll("SELECT id, section FROM students WHERE student_number LIKE 'T9%' ORDER BY id");
$moved = 0;
foreach ($before as $i => $row) { if (($row['section'] ?? null) !== ($after[$i]['section'] ?? null)) $moved++; }
check('re-run moved nobody', $moved === 0, "$moved changed");

echo "\n== 3. A NEW ARRIVAL JOINS AN EXISTING SECTION ==\n";
$target = (string) $y1[0]['section'];   // the Year-1 section holding 40, ten short
$course1 = (string) $db->fetchColumn("SELECT TRIM(course) FROM students WHERE section = '$target' LIMIT 1");
$newId = (int) $db->fetchColumn("SELECT id FROM students WHERE student_number LIKE 'T9%' AND section = '$target' ORDER BY id LIMIT 1");
$db->update('students', ['section' => null], 'id = ?', [$newId]);
$r = req('POST', "$BASE/api/masterlist.php", ['action' => 'assign_sections', 'max_per_section' => $cap]);
check('the newcomer was placed', (int) ($r['json']['updated'] ?? 0) === 1, 'updated=' . ($r['json']['updated'] ?? '?'));
$back = trim((string) $db->fetchColumn("SELECT section FROM students WHERE id = $newId"));
check("and landed back in $target (an existing section, not a new one)", $back === $target, "got '$back'");
$stillTwo = (int) $db->fetchColumn("SELECT COUNT(DISTINCT section) FROM students WHERE student_number LIKE 'T9%' AND year_level = 1");
check('no new Year-1 section was opened', $stillTwo === 2, (string) $stillTwo);

echo "\n== 4. NEXT SECTION CODE ==\n";
$r = req('POST', "$BASE/api/masterlist.php", ['action' => 'next_section', 'course' => $course1, 'year_level' => 1, 'semester' => '1st']);
check('next_section succeeds', ($r['json']['success'] ?? false) === true, $r['body']);
$next = (string) ($r['json']['code'] ?? '');
check("next code is a fresh 11xxx (got '$next')", (bool) preg_match('/^11\d{3}$/', $next), $next);
check('and is not a code already in use',
    (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE section = '$next'") === 0, $next);

echo "\n== 5. LIST SECTIONS ==\n";
$r = req('POST', "$BASE/api/masterlist.php", ['action' => 'list_sections']);
check('list_sections succeeds', ($r['json']['success'] ?? false) === true, $r['body']);
$sections = $r['json']['sections'] ?? [];
check('returns the sections', !empty($sections), $r['body']);
check('every row carries a count', !empty($sections) && isset($sections[0]['count']), json_encode($sections[0] ?? null));

echo "\n== 6. BULK ASSIGN ==\n";
$ids = array_map('intval', array_column($db->fetchAll(
    "SELECT id FROM students WHERE student_number LIKE 'T9%' AND section = '$target' ORDER BY id LIMIT 3"
), 'id'));
// An empty IN () is a syntax error, not a match-all. The ids are
// interpolated because the count needs a literal list, so the guard
// has to be here rather than assumed.
if (empty($ids)) {
    check('bulk_assign_section: found students to move', false, "no students in $target");
} else {
$dest = $next;   // the fresh code from step 4
$r = req('POST', "$BASE/api/masterlist.php", [
    'action' => 'bulk_assign_section', 'ids' => $ids, 'section' => $dest,
    'course' => $course1, 'year_level' => 1, 'semester' => '1st',
]);
check('bulk_assign_section succeeds', ($r['json']['success'] ?? false) === true, $r['body']);
$movedN = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE id IN (" . implode(',', $ids) . ") AND section = '$dest'");
check("all " . count($ids) . " moved to $dest", $movedN === count($ids), "moved $movedN");
}

echo "\n== 7. A YEAR-LESS STUDENT IS REFUSED ==\n";
$yless = (int) $db->fetchColumn("SELECT id FROM students WHERE student_number LIKE 'T9%' ORDER BY id LIMIT 1");
$savedYear = $db->fetchOne("SELECT year_level, semester, course, school_year FROM students WHERE id = $yless");
$db->update('students', ['year_level' => null], 'id = ?', [$yless]);
$r = req('POST', "$BASE/api/masterlist.php", [
    'action' => 'bulk_assign_section', 'ids' => [$yless], 'section' => '11999',
]);
check('refuses a student with no year level', ($r['json']['success'] ?? false) === false, $r['body']);
check('and says why', str_contains((string) ($r['json']['message'] ?? ''), 'no year level'), (string) ($r['json']['message'] ?? ''));
check('nothing was written', (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE id = $yless AND section = '11999'") === 0);
// Auto-assign must skip them too, and count them rather than place them.
$r = req('POST', "$BASE/api/masterlist.php", ['action' => 'assign_sections']);
check('auto-assign reports them as skipped', (int) ($r['json']['skipped'] ?? 0) >= 1, 'skipped=' . ($r['json']['skipped'] ?? '?'));
$db->update('students', [
    'year_level' => $savedYear['year_level'], 'semester' => $savedYear['semester'],
    'course' => $savedYear['course'], 'school_year' => $savedYear['school_year'],
], 'id = ?', [$yless]);

echo "\n== 8. EDIT SECTION (RENAME) ==\n";
$from = $dest;
$to   = '11099';
$members = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE section = '$from'");
$fromCourse = (string) $db->fetchColumn("SELECT TRIM(course) FROM students WHERE section = '$from' LIMIT 1");
$r = req('POST', "$BASE/api/masterlist.php", [
    'action' => 'edit_section',
    'old_course' => $fromCourse, 'old_year_level' => 1, 'old_semester' => '1st', 'old_section' => $from,
    'course' => $fromCourse, 'year_level' => 1, 'semester' => '1st', 'section' => $to,
]);
check('edit_section succeeds', ($r['json']['success'] ?? false) === true, $r['body']);
check("all $members member(s) moved to $to",
    (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE section = '$to'") === $members,
    'now: ' . $db->fetchColumn("SELECT COUNT(*) FROM students WHERE section = '$to'"));
check('nothing left on the old code',
    (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE section = '$from'") === 0);

echo "\n== 9. EDIT SECTION REFUSES A COLLISION ==\n";
$toCourse = (string) $db->fetchColumn("SELECT TRIM(course) FROM students WHERE section = '$to' LIMIT 1");
$r = req('POST', "$BASE/api/masterlist.php", [
    'action' => 'edit_section',
    'old_course' => $toCourse, 'old_year_level' => 1, 'old_semester' => '1st', 'old_section' => $to,
    'course' => $toCourse, 'year_level' => 1, 'semester' => '1st', 'section' => $target,
]);
check('refuses a rename onto a code already in use', ($r['json']['success'] ?? false) === false, $r['body']);
check('and left the section untouched',
    (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE section = '$to'") === $members);

echo "\n== 10. CAP GUARD ==\n";
foreach ([0, -5, 99999] as $bad) {
    $r = req('POST', "$BASE/api/masterlist.php", ['action' => 'assign_sections', 'max_per_section' => $bad]);
    check("a cap of $bad falls back to $cap", (int) ($r['json']['max_per_section'] ?? 0) === $cap,
        'got ' . ($r['json']['max_per_section'] ?? '?'));
}

echo "\n== 11. THE PAGE RENDERS ==\n";
$page = req('GET', "$BASE/registrar/masterlist.php");
check('masterlist.php returns 200', $page['status'] === 200, 'got ' . $page['status']);
$b = $page['body'];
check('no PHP error on the page',
    !str_contains($b, 'Fatal error') && !str_contains($b, 'Warning:') && !str_contains($b, 'Notice:'),
    substr($b, 0, 400));
// Auto-assign was removed from the page. Checked on the button AND the handler,
// because deleting the markup alone leaves working JS with nothing to fire
// it, and the page still renders clean - the handler only fails on click.
check('the Auto-assign button is gone', !str_contains($b, 'id="btnAutoAssign"'));
check('and no JS still wires it up', !str_contains($b, 'btnAutoAssign')
    && !str_contains($b, "'assign_sections'"));
// AI search was removed from the page. Checked on the button, the handler,
// the banner it reported into, and the fetch itself - deleting only the
// button leaves a live function plus a fetch to an endpoint nothing calls.
check('the AI search button is gone', !str_contains($b, 'id="aiSearchBtn"'));
check('and no JS still wires it up', !str_contains($b, 'runAiSearch')
    && !str_contains($b, 'aiSearchBtn')
    && !str_contains($b, 'aiInterpretation')
    && !str_contains($b, 'aiExplanation'));
check('the page no longer calls the AI search endpoint',
    !str_contains($b, 'masterlist-ai-search.php'));
check('urlParamSafe went with it', !str_contains($b, 'function urlParamSafe'));
// Create Section is gone entirely - the button, the modal, and the handlers.
// Checked on the JS too because deleting only the markup leaves functions
// wired to element ids that no longer exist, which throws on page load.
check('the Create Section button is gone', !str_contains($b, 'id="btnCreateSection"'));
check('the Create Section modal is gone', !str_contains($b, 'id="createSectionModal"'));
check('no JS still wires Create Section up', !str_contains($b, 'openCreateSectionModal')
    && !str_contains($b, 'closeCreateSectionModal')
    && !str_contains($b, 'createSectionAndOpen')
    && !str_contains($b, 'function refreshSectionCode'));
// The create modal was the only caller of the next_section code lookup.
check('the page no longer asks for a next section code',
    !str_contains($b, "'next_section'"));
// Deleting a function the Escape handler still called throws a ReferenceError
// on keypress and strands every modal after it in the chain.
check('the Escape handler has no dead calls',
    preg_match("/if \(e\.key === 'Escape'\) \{([^}]*)\}/", $b, $m) === 1
    && !str_contains($m[1], 'closeCreateSectionModal'));
// Sections that already exist are still manageable - the chips, the edit
// modal and the workspace must survive, or there is no way left to place a
// student at all once both creation paths are gone.
check('existing sections are still editable', str_contains($b, 'class="ml-section-chip')
    && str_contains($b, 'id="editSectionModal"')
    && str_contains($b, 'id="sectionWorkspaceModal"')
    && str_contains($b, 'onclick="manageSectionStudents()"'));
// The toolbar is now just the search box and Filter.
check('the toolbar no longer holds a section button',
    !str_contains($b, 'masterlist-create-section'));
// The plain search box must still filter on its own, and it is now the only
// way to search - its input listener is what does it.
check('the search box still filters on input',
    str_contains($b, 'id="masterlistSearch"')
    && preg_match('/getElementById\(.masterlistSearch.\)/', $b) === 1);
// The hand-off row is Prepare Full List -> Send List -> Export, in that order.
check('the output group is prepare, send, export, in order',
    preg_match('/id="btnPrepareList".*?onclick="sendList\(\)".*?id="exportBtn"/s', $b) === 1);
check('the status line still reports unassigned students',
    str_contains($b, 'id="sectionStatus"'));
check('has a Section column', str_contains($b, 'data-field="section"'));
check('section chips are rendered', str_contains($b, 'class="ml-section-chip'));
check('the status line is rendered', str_contains($b, 'id="sectionStatus"'));
check('the workspace modal is present', str_contains($b, 'id="sectionWorkspaceModal"'));
check('the edit modal is present', str_contains($b, 'id="editSectionModal"'));
// The Generate button was removed as a duplicate of Filter - Filter has all
// five of its fields plus Section, and both submit the same query params.
// The check matters because the two halves can drift: leave the button's
// onclick in place after deleting openGenerateModal() and the page still
// renders fine, and only fails when someone clicks it.
check('the Generate button is gone', !str_contains($b, 'openGenerateModal()'));
check('the Generate modal is gone', !str_contains($b, 'id="generateModal"'));
check('and no JS still calls it', !str_contains($b, 'function openGenerateModal')
    && !str_contains($b, 'function closeGenerateModal')
    && !str_contains($b, 'function applyGenerate'));
// The toolbar Print button was removed as a duplicate of Export PDF, which
// runs the same window.print(). The bulk-bar Print is a DIFFERENT feature -
// printRows() builds its own letterhead, logo and signature block - so it
// stays. These checks pin that split: a future edit that strips the toolbar
// button but takes the bulk one with it would otherwise look like a tidy-up
// rather than a regression.
//
// Matched on the whole opening tag. Asserting on 'fa-print' alone would also
// match the bulk bar, and asserting on the absence of window.print() would
// fail while Export PDF still uses it.
check('the toolbar Print button is gone',
    !str_contains($b, '<button class="btn btn-secondary" onclick="window.print()">'));
check('Export PDF still prints', str_contains($b, 'Export PDF')
    && str_contains($b, 'onclick="window.print()"'));
check('the bulk-bar Print is still there', str_contains($b, 'onclick="printSelected()"')
    && str_contains($b, 'function printRows('));
// Scoped to a PHP open tag. A bare closing tag would also match the XML
// declaration in the Excel exporter, which is a legitimate string in the
// page, not a leaked template marker. (Neither tag is spelled out here:
// a closing tag inside a line comment silently ends PHP mode, and this
// file would stop parsing at that line.)
check('no leaked PHP open tag', !str_contains($b, '<?php') && !str_contains($b, '<?='));
// The copy that used to claim sections belonged to another department.
check('no stale copy claiming sections belong elsewhere',
    !str_contains($b, 'prepared for section assignment')
    && !str_contains($b, 'is read-only')
    && !str_contains($b, 'belong to other departments'));

echo "\n== 12. SECTION FILTER ==\n";
$f = req('GET', "$BASE/registrar/masterlist.php?section=" . urlencode($target));
check('filtering by section returns 200', $f['status'] === 200, 'got ' . $f['status']);
check('the filtered page shows the code', str_contains($f['body'], $target), $target);
// Counted from the rendered table only. The filter dropdown lists every
// code by design, so searching the whole page for another code would
// always "leak" and prove nothing about what the table shows.
preg_match_all('/<td data-field="section"[^>]*>.*?<span class="ml-section[^"]*">([^<]*)<\/span>/s', $f['body'], $cells);
$shown = array_values(array_unique(array_map('trim', $cells[1] ?? [])));
echo "  section cells on the filtered page: " . json_encode($shown) . "\n";
check('every row shows the filtered code', $shown !== [] && array_diff($shown, [$target, 'Unassigned']) === [],
    json_encode($shown));
// Counted as <td>, not as the bare attribute: the column header is a
// <th data-field="section"> too, and counting it inflates the total by
// one per table on the page.
$rowsShown = substr_count($f['body'], '<td data-field="section"');
$members = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE section = '$target'");
check("all $members member(s) of $target are listed", $rowsShown === $members, "listed $rowsShown, section has $members");

echo "\n== 13. NEIGHBOURING PAGES UNAFFECTED ==\n";
// Only pages that exist. registrar/dashboard.php does not - the
// dashboard is at the site root - and a 404 for it says nothing
// about this change.
foreach (['registrar/students.php', 'registrar/student-ids.php', 'dashboard.php', 'student/profile.php'] as $p) {
    $x = req('GET', "$BASE/$p");
    check("$p still renders (200)", $x['status'] === 200, 'got ' . $x['status']);
    check("$p has no PHP error", !str_contains($x['body'], 'Fatal error') && !str_contains($x['body'], 'Warning:'));
}

echo "\n== 14. CSRF IS ENFORCED ==\n";
$assignedBefore = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE section IS NOT NULL AND TRIM(section) <> ''");
$ch = curl_init("$BASE/api/masterlist.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['action' => 'assign_sections']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
]);
$noTokenBody = (string) curl_exec($ch);
$noTokenStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
// Asserted on the refusal, not on the status code. shared/csrf_guard.php
// sends 419, but Apache 2.4 answers an unrecognised status with 500 -
// every CSRF-guarded endpoint on this server behaves that way, so
// demanding 419 here would be testing Apache, not this code. The
// message and the absence of a write are the real contract.
check('a POST with no CSRF token is refused', str_contains($noTokenBody, 'CSRF token'), $noTokenBody);
check('and writes nothing',
    (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE section IS NOT NULL AND TRIM(section) <> ''") === $assignedBefore);
echo "  (status $noTokenStatus — Apache maps the guard's 419 to 500)\n";

@unlink($jar);

echo "\n" . str_repeat('=', 52) . "\n";
echo "  $pass passed, $fail failed\n";
echo "  Sections now: " . json_encode(counts()) . "\n";
echo "  Clean up with: php tests/clear_seeded_students.php --execute\n";
echo str_repeat('=', 52) . "\n";
exit($fail === 0 ? 0 : 1);
