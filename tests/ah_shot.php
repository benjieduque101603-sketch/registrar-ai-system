<?php
// Write a static, self-contained copy of the page for screenshotting.
//
//   php tests/ah_shot.php [out.html]
//
// The page cannot be screenshotted over HTTP: session.use_strict_mode=1
// refuses a session id minted from the CLI, so every request lands on the
// login page. Serving the rendered HTML from file:// with the stylesheets
// inlined is the closest honest approximation - the same markup, the same
// CSS, the viewport differences and all - and it is how the layout can
// actually be looked at rather than assumed.
//
// Sample data is used rather than the live roster, because the live
// database holds one student and a single row cannot show a broken grid.

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/term_grades.php';

$student = Database::getInstance()->fetchOne(
    "SELECT id FROM students WHERE status != 'archived' ORDER BY id LIMIT 1"
);
$sid = $student ? (int) $student['id'] : 1;

// A roster with every state represented: complete, partial, and not
// started, plus a spread of GWAs across all three bands.
$sample = [
    ['Roldan Tiu',        '2026-0001', 'BSIT', '1', 'A', 1.75, 21.0, 'complete', 0,
     [['IT 101', 3, 1.5, 'passed'], ['IT 102', 3, 2.0, 'passed'], ['PE 1', 2, 1.75, 'passed']]],
    ['Angela Mendoza',    '2026-0002', 'BSIT', '1', 'A', 2.71, 18.0, 'partial', 2,
     [['IT 101', 3, 1.75, 'passed'], ['IT 102', 3, 2.5, 'passed'], ['IT 103', 3, null, 'passed'], ['PE 1', 2, null, 'passed']]],
    ['Joshua Alvarez',    '2026-0003', 'BSIT', '1', 'B', 3.24, 12.0, 'complete', 0,
     [['IT 101', 3, 3.5, 'passed'], ['IT 102', 3, 3.0, 'passed']]],
    ['Camille Santos',    '2026-0004', 'BSIT', '2', 'A', null, 0.0, 'none', 0, []],
    ['Diego Ramos',       '2026-0005', 'BSIT', '2', 'B', 2.30, 15.0, 'complete', 0,
     [['IT 201', 3, 2.0, 'passed'], ['IT 202', 3, 2.5, 'passed'], ['GE 5', 3, 2.4, 'passed']]],
];

$rows = [];
$termComplete = 0;
$termMissing  = 0;
$termUnits    = 0.0;
$rosterForAudit = [];

foreach ($sample as $i => $s) {
    [$name, $number, $prog, $level, $sec, $gwa, $units, $state, $missing, $subs] = $s;

    $subjects = [];
    foreach ($subs as $sub) {
        $subjects[] = [
            'subject' => $sub[0], 'subject_code' => '', 'units' => $sub[1],
            'final_rating' => $sub[2], 'grade' => '', 'remarks' => '',
            'grade_status' => $sub[3],
        ];
    }
    $computed = termGwa($subjects);
    // The unit count is summed from the subjects rather than hardcoded
    // beside them. The page's own roster sums it, so a sample that
    // carried a literal would put a figure on the screenshot that the
    // page would never produce for that subject list.
    $summedUnits = 0.0;
    foreach ($subjects as $sub) {
        $summedUnits += max(0.0, (float) $sub['units']);
    }
    $units = $summedUnits;
    $state === 'complete' ? $termComplete++ : $termMissing++;
    $termUnits += $units;

    $rows[] = [
        'id' => $sid + $i, 'number' => $number, 'name' => $name,
        'program' => 'BSIT', 'level' => $level, 'section' => $sec,
        'record' => 0, 'gwa' => $computed, 'stored' => $computed,
        'units' => $units, 'state' => $state, 'missing' => $missing,
        'subjects' => $subjects,
    ];
    $rosterForAudit[] = ['name' => $name, 'number' => $number, 'gwa' => $computed, 'subjects' => $subjects];
}

$audit = termAudit('2026-2028', '1st', $rosterForAudit);

$payload = json_encode([
    'sy' => '2026-2028', 'sem' => '1st', 'csrf' => str_repeat('a', 64),
    'initials' => array_combine(
        array_column($rows, 'id'),
        array_map(static function ($r) {
            $parts = preg_split('/\s+/', trim($r['name']), -1, PREG_SPLIT_NO_EMPTY);
            return mb_strtoupper(mb_substr($parts[0], 0, 1)) .
                   (isset($parts[1]) ? mb_strtoupper(mb_substr(end($parts), 0, 1)) : '');
        }, $rows)
    ),
    'rows' => array_map(static fn($r) => [
        'id' => $r['id'], 'name' => $r['name'], 'number' => $r['number'],
        'program' => 'BSIT', 'level' => $r['level'], 'section' => $r['section'],
        'record' => 0, 'subjects' => $r['subjects'],
    ], $rows),
], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

echo "sample roster built: " . count($rows) . " students, "
     . count($audit['blocking']) . " blocking, " . count($audit['advisory']) . " advisory\n";

// ── Render the real page with this sample standing in for the roster ──
// The page is included rather than reimplemented, so the screenshot shows
// the markup that actually ships. Sample rows are injected by pre-seeding
// the same variables the page builds, and the page's own audit call is
// reused, so what is pictured is what a real term looks like.
ini_set('session.use_strict_mode', '0');
session_name('BCP_REGISTRAR_SESSION');
require_once __DIR__ . '/../shared/config.php';
$u = Database::getInstance()->fetchOne(
    "SELECT id, full_name, role FROM users
     WHERE role IN ('admin','registrar') AND is_active = 1 ORDER BY id LIMIT 1"
);
@session_start();
$_SESSION['user_id']       = $u ? (int) $u['id'] : 1;
$_SESSION['role']          = $u ? (string) $u['role'] : 'registrar';
$_SESSION['full_name']     = $u ? (string) $u['full_name'] : 'Test';
$_SESSION['last_activity'] = time();

$cwd = getcwd();
chdir(__DIR__ . '/../registrar');
// AH_SHOT_ROWS lets the page's own roster query be replaced with the
// sample set, so the markup under test is the page's and not a copy of it.
// A single real student cannot show a broken grid, and a hand-written
// copy of the markup would drift from the file it is meant to be testing.
define('AH_SHOT_ROWS', json_encode($rows, JSON_UNESCAPED_UNICODE));
ob_start();
include __DIR__ . '/../registrar/academic-history.php';
$html = ob_get_clean();
chdir($cwd);

// Rewrite the inline stylesheet references to absolute file paths so the
// document renders standalone from file://, and drop the page's own
// relative links, which cannot resolve there.
$root = dirname(__DIR__);
$html = str_replace('../css/', $root . '/css/', $html);
$html = preg_replace('#<script src=\x27[^\x27]*\x27></script>#', '', $html);
$html = str_replace('../js/csrf.js', '', $html);
// The loader script hides the page until it thinks it is loaded; there is
// no loader in a static file, so strip anything that would hide content.
$html = preg_replace('#<script[^>]*page-loader[^>]*>.*?</script>#s', '', $html);
// Rendering the page from the CLI trips session warnings, because a
// session cannot be started after output has begun. They are an artefact
// of the harness, not the page, and they cover the top of the page in the
// screenshot, so they are stripped here rather than left to obscure the
// layout being inspected.
$html = preg_replace('#<b>(Warning|Notice|Deprecated)</b>:\s*[^<]*<br\s*/?>\s*<br\s*/?>#i', '', $html);
$html = str_replace('<div id="page-loader">', '<div id="page-loader" style="display:none">', $html);

$out = __DIR__ . '/../ah_shot.html';

// A layout probe, plus an optional dialog to open. The dialogs are the
// densest part of this page and were the least seen: a screenshot of the
// roster alone says nothing about whether the grade grid or the audit
// drawer actually fit inside their shell.
$openDialog = $argv[1] ?? '';   // '' | 'grades' | 'audit'

// Measures the boxes in the browser rather than inferring them from the
// CSS, and prints the result into the document, so the screenshot shows
// the diagnosis and not only the symptom.
$probe = <<<JS
<script>
window.addEventListener('load', function () {
  setTimeout(function () {
    var open = '$openDialog';
    if (open) {
      try {
        if (open === 'grades') { openGrades(AH.rows[0].id); }
        if (open === 'view')   { openView(AH.rows[0].id); }
        if (open === 'audit')  { openAudit(); }
      } catch (e) {
        var err = document.createElement('pre');
        err.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;'
          + 'background:#900;color:#fff;font:12px monospace;padding:8px';
        err.textContent = 'DIALOG FAILED: ' + e.message;
        document.body.appendChild(err);
      }
    }
    var out = [];
    // The dialogs claimed aria-modal="true" while moving no focus at all,
    // so this is measured rather than read from the CSS: open from a real
    // button, check where focus landed, close, check it came back. A
    // headless DOM assertion is not available here - no jsdom in the
    // project - so the browser itself is the instrument.
    function focusReport() {
      var lines = [];
      var opener = document.createElement('button');
      opener.id = 'focus-probe-opener';
      opener.textContent = 'probe';
      document.body.appendChild(opener);
      opener.focus();
      var before = document.activeElement === opener;
      openAudit();
      var inDialog = document.getElementById('auditModal').contains(document.activeElement);
      var landed = document.activeElement.id || document.activeElement.tagName;
      closeAudit();
      var restored = document.activeElement === opener;
      opener.remove();
      lines.push('  focus before open: ' + (before ? 'on opener' : 'NOT on opener'));
      lines.push('  focus after open : ' + landed + (inDialog ? ' (inside dialog)' : ' ESCAPED'));
      lines.push('  focus after close: ' + (restored ? 'returned to opener' : 'NOT restored'));
      return lines;
    }
    try { out = out.concat(focusReport()); } catch (e) {
      out.push('  FOCUS PROBE FAILED: ' + e.message);
    }

    // Search and the unreadable-term warning, driven for real. The term
    // box is free text, so the two things worth proving are that a
    // query narrows the roster and that a term the page cannot read
    // says so instead of quietly showing a different one.
    function searchReport() {
      var lines = [];
      var box = document.getElementById('rosterSearch');
      var clear = document.getElementById('ahSearchClear');
      var rows = document.querySelectorAll('tbody tr[data-ah-search]');
      var readout = document.getElementById('ahCountReadout');
      if (!box || !rows.length) return ['  SEARCH PROBE: no search box or rows'];

      lines.push('  clear button hidden when empty: ' + (clear && clear.hidden));
      lines.push('  readout at rest: "' + (readout ? readout.textContent.trim() : '?') + '"');

      box.value = 'mendoza';
      box.dispatchEvent(new Event('input', { bubbles: true }));
      var shown = Array.prototype.filter.call(rows, function (r) { return !r.hidden; }).length;
      lines.push('  "mendoza" -> ' + shown + ' of ' + rows.length + ' rows, readout "'
        + (readout ? readout.textContent.trim() : '?') + '"');
      lines.push('  clear button appears: ' + (clear && !clear.hidden));

      box.value = 'zzzz';
      box.dispatchEvent(new Event('input', { bubbles: true }));
      var empty = document.getElementById('ahSearchEmpty');
      lines.push('  "zzzz" -> 0 rows, empty state shown: ' + (empty && !empty.hidden));
      if (empty && !empty.hidden) {
        lines.push('  empty state says: "' + empty.textContent.replace(/\s+/g, ' ').trim() + '"');
      }

      clear.click();
      lines.push('  after clear -> ' +
        Array.prototype.filter.call(rows, function (r) { return !r.hidden; }).length +
        ' of ' + rows.length + ' rows');
      return lines;
    }
    try { out = out.concat(searchReport()); } catch (e) {
      out.push('  SEARCH PROBE FAILED: ' + e.message);
    }
    function box(sel) {
      var el = document.querySelector(sel);
      if (!el) { out.push(sel + ': MISSING'); return; }
      var r = el.getBoundingClientRect();
      var c = getComputedStyle(el);
      out.push(sel + ' -> x=' + Math.round(r.x) + ' w=' + Math.round(r.width)
        + ' | ml=' + c.marginLeft + ' pad=' + c.paddingLeft
        + ' disp=' + c.display
        + ' | --sidebar-width='
        + getComputedStyle(document.documentElement).getPropertyValue('--sidebar-width'));
    }
    box('.dashboard-main');
    box('.ah-header');
    box('.ah-filters');
    box('.ah-stats');
    box('.ah-panel');
    box('.ah-table-wrap');
    box('.modal-overlay.ah-dialog');
    var card = document.querySelector('.modal-overlay.active .modal-content');
    if (card) {
      var cr = card.getBoundingClientRect();
      out.push('ACTIVE DIALOG CARD -> x=' + Math.round(cr.x) + ' w=' + Math.round(cr.width)
        + ' h=' + Math.round(cr.height) + ' (viewport h=' + window.innerHeight + ')');
    } else {
      out.push('ACTIVE DIALOG CARD: none (overlay did not open)');
    }
    var grid = document.querySelector('.ah-grid');
    if (grid) {
      out.push('GRID -> h=' + Math.round(grid.getBoundingClientRect().height)
        + ' rows=' + document.querySelectorAll('#gradeRows tr').length);
    }
    var d = document.createElement('pre');
    d.id = 'layout-probe';
    d.textContent = out.join(String.fromCharCode(10));
    d.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:99999;'
      + 'background:#000;color:#0f0;font:11px monospace;padding:8px;white-space:pre-wrap';
    document.body.appendChild(d);

    // Anything wider than the viewport is a layout bug. Report the
    // worst offenders rather than guessing which one it is.
    var vw = document.documentElement.clientWidth;
    var wide = [];
    document.querySelectorAll('body *').forEach(function (el) {
      var r = el.getBoundingClientRect();
      if (r.width > vw + 1 && r.height > 0) {
        var cs = getComputedStyle(el);
        if (cs.position === 'fixed' && cs.display === 'none') { return; }
        wide.push({ el: el, w: Math.round(r.width), ox: cs.overflowX });
      }
    });
    wide.sort(function (a, b) { return b.w - a.w; });
    var seen = {};
    var lines = ['viewport=' + vw + '  scrollW=' + document.documentElement.scrollWidth];
    wide.slice(0, 12).forEach(function (x) {
      var t = x.el.tagName.toLowerCase()
        + (x.el.className && typeof x.el.className === 'string'
           ? '.' + x.el.className.trim().split(/\s+/).join('.') : '');
      if (seen[t]) { return; }
      seen[t] = 1;
      lines.push('  WIDE ' + x.w + '  ' + t + '  overflowX=' + x.ox);
    });
    var w2 = document.createElement('pre');
    w2.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;'
      + 'background:#300;color:#fd6;font:11px monospace;padding:8px;white-space:pre-wrap';
    w2.textContent = lines.join(String.fromCharCode(10));
    document.body.appendChild(w2);
  }, 400);
});
</script>
JS;

file_put_contents($out, str_replace('</body>', $probe . "\n</body>", $html));
printf("wrote %d bytes to %s\n", strlen($html), basename($out));
