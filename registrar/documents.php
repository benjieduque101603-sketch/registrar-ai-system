<?php
// ============================================================
//  REGISTRAR/DOCUMENTS.PHP
//  Document requests + desk metrics (no Digital).
//  Filed, prepared and claimed in person at the registrar counter.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
requireRole('registrar');

require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/document_process.php';

$db = Database::getInstance();

// ── Requests ────────────────────────────────────────────────────
$requests = $db->fetchAll(
    "SELECT dr.*, c.name AS catalog_name, c.sku, c.fee_type, c.base_fee,
            c.requirement, c.sla_days,
            CONCAT(s.first_name, ' ', s.last_name) AS student_name,
            s.student_number
       FROM document_requests dr
       LEFT JOIN document_catalog c ON c.id = dr.catalog_id
       LEFT JOIN students s ON dr.student_id = s.id
      ORDER BY dr.id DESC"
);

// Balances, fetched in one pass rather than per row: the desk loads
// this on every refresh, and a query per request turns a seven-row
// table into twenty-one round trips.
$balanceByStudent = [];
foreach ($db->fetchAll('SELECT student_id, balance FROM finance') as $f) {
    $balanceByStudent[(int) $f['student_id']] = (float) $f['balance'];
}

// Attach the derived facts the desk renders from. Both are computed by
// the shared helpers so intake, the API and this page cannot disagree
// about what is holding a request up.
foreach ($requests as &$r) {
    $r['balance']  = $balanceByStudent[(int) ($r['student_id'] ?? 0)] ?? 0.0;
    $r['_blocker'] = doc_blocker($r);
    $r['_age']     = doc_age($r);
}
unset($r);

// Status events grouped by request.
$eventsByRequest = [];
if ($requests) {
    $ids = array_map('intval', array_column($requests, 'id'));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $events = $db->fetchAll(
        "SELECT * FROM document_request_events WHERE request_id IN ($ph) ORDER BY id ASC",
        $ids
    );
    foreach ($events as $ev) {
        $eventsByRequest[(int) $ev['request_id']][] = $ev;
    }
}

// ── Read retired event wording in the current language ─────────
//
// Event notes are an append-only record: they are written once, at the
// moment something happened, and must not be rewritten afterwards.
// Rewriting them would falsify history, so the desk translates on the
// way out instead.
//
// This matters because the status realignment retired a vocabulary
// that intake still used. A request filed before it carries
// "Request submitted (DOC-…) — awaiting payment" as its opening line,
// which describes a payment gate the walk-in flow no longer has. Left
// alone, the log contradicts the rail directly above it: the row says
// Preparing, the log below says the request is still waiting to pay.
//
// The stored row is left exactly as written. Only the displayed text
// changes, and only for the phrases that are now actively misleading.
function doc_readable_event_note(array $ev): string
{
    $note = (string) ($ev['note'] ?? '');

    // Phrase-level substitutions, longest first so the more specific
    // wording wins over the general one.
    $map = [
        'Request submitted (' => 'Request filed (',
        '— awaiting payment'  => '— fee due at filing',
        'awaiting payment'    => 'awaiting payment',
        'Awaiting_Payment'    => 'Filed',
    ];
    $out = strtr($note, $map);

    // A note that says nothing useful (the bare status label) is worse
    // than silence, so fall back to the status in plain words.
    if (trim($out) === '' || trim($out) === ($ev['status'] ?? '')) {
        $label = $statusLabel[(string) $ev['status']] ?? str_replace('_', ' ', (string) $ev['status']);
        $out = 'Status changed to ' . $label;
    }
    return $out;
}

// ── Metrics ─────────────────────────────────────────────────────
//
// Four numbers the desk acts on, chosen because each one changes what
// the clerk does next. "Regular vs Express requests" told them
// nothing actionable and is gone; its slot goes to what is actually
// late, which was previously invisible on this page. The same reasoning
// retired the Express series from the daily-volume chart below.
$blockedCount = 0;
$overdueCount = 0;
$needsAction  = 0;
$oldestDays   = 0.0;
// Held rows, split by what is holding them. Zeroed here so a page render
// that somehow skipped the loop below still cannot emit an undefined
// variable in the tile caption.
$manualHoldCount = 0;
$balanceHoldCount = 0;
foreach ($requests as $r) {
    if (!empty($r['_blocker'])) {
        $blockedCount++;
        // Split the held rows by kind. The tile's caption names the cause,
        // and a caption that asserts "a balance" above a hold a person set
        // sends the clerk after money that was never the problem.
        if (($r['blocked_source'] ?? null) === 'registrar') $manualHoldCount++;
        else $balanceHoldCount++;
    }
    if (!empty($r['_age']['overdue'])) $overdueCount++;
    if (in_array((string) $r['document_status'], ['Filed', 'Pending_Clearance', 'Processing'], true)) {
        $needsAction++;
    }
    $oldestDays = max($oldestDays, (float) $r['_age']['days']);
}

// A request held on a balance is work the desk must clear before it can
// progress. A missing requirement is NOT in this number - it is an ask
// (doc_requirement_note), and counting it here made every document that
// names one look stalled from the moment it was filed.

$tatHours = $db->fetchColumn(
    "SELECT AVG(TIMESTAMPDIFF(HOUR, paid_at, ready_at))
       FROM document_requests WHERE ready_at IS NOT NULL AND paid_at IS NOT NULL"
);
$tatHours = $tatHours !== null ? round((float) $tatHours, 1) : null;

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
$revenueRows = $db->fetchAll(
    "SELECT COALESCE(c.name, dr.document_type) AS doc_name,
            SUM(dr.fee_amount) AS revenue, COUNT(*) AS cnt
       FROM document_requests dr
       LEFT JOIN document_catalog c ON c.id = dr.catalog_id
      WHERE dr.document_status <> 'Rejected'
        AND COALESCE(dr.paid_at, dr.request_date) BETWEEN ? AND ?
      GROUP BY c.id, dr.document_type ORDER BY revenue DESC",
    [$from . ' 00:00:00', $to . ' 23:59:59']
);
$revenueTotal = array_sum(array_map(fn($r) => (float) $r['revenue'], $revenueRows));

// Daily volume, last 7 days — what came in against what is still open.
//
// This was "Express vs Regular". That split cannot draw anything now:
// Express was removed from the product and priority survives only as a
// hidden Regular value the API requires, so every row is Regular and the
// Express series is permanently zero. A legend promising two series with
// one always empty is worse than no legend — it reads as a rendering
// fault, and it spends the panel on a distinction the clerk cannot act on.
//
// So it now answers the question a counter desk asks every morning: did
// we keep up? Each day stacks the work that has been settled against the
// work still open, and the outstanding remainder is the backlog. One
// series carries meaning on every day, including the empty ones.
$volumeRows = $db->fetchAll(
    "SELECT DATE(request_date) AS d,
            SUM(CASE WHEN document_status IN ('Claimed','Rejected') THEN 1 ELSE 0 END) AS settled,
            SUM(CASE WHEN document_status IS NULL OR document_status NOT IN ('Claimed','Rejected') THEN 1 ELSE 0 END) AS outstanding
       FROM document_requests
      WHERE request_date >= ?
      GROUP BY DATE(request_date)",
    [date('Y-m-d', strtotime('-6 days')) . ' 00:00:00']
);
$volByDay = [];
foreach ($volumeRows as $v) {
    $volByDay[$v['d']] = [
        'settled'     => (int) $v['settled'],
        'outstanding' => (int) $v['outstanding'],
    ];
}
$days = $settledSeries = $outstandingSeries = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $days[] = date('M j', strtotime($d));
    $settledSeries[] = $volByDay[$d]['settled'] ?? 0;
    $outstandingSeries[] = $volByDay[$d]['outstanding'] ?? 0;
}

$catalog   = $db->fetchAll("SELECT * FROM document_catalog WHERE is_active = 1 ORDER BY id");
// The student picker must mirror the Student Management module, which lists
// every student row regardless of status. Filtering on `status = 'active'`
// hid anyone whose status is the column default ('enrolled'), plus
// probation / at-risk / loa, so the modal came up empty. Only soft-deleted
// rows are excluded so a request can't be filed for a removed student.
$students  = $db->fetchAll(
    "SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name
       FROM students
      WHERE status IS NULL OR status NOT IN ('archived')
      ORDER BY name"
);

$statusPill = [
    'Pending_Clearance' => ['pending-clearance','fa-triangle-exclamation'],
    'Filed'             => ['filed','fa-folder-open'],
    'Processing'        => ['processing','fa-gear'],
    'Ready'             => ['ready','fa-circle-check'],
    'Claimed'           => ['claimed','fa-box-check'],
    'Rejected'          => ['rejected','fa-xmark'],
];
$statusLabel = [
    'Pending_Clearance' => 'Pending Clearance',
    'Filed'             => 'Filed',
    'Processing'        => 'Being prepared',
    'Ready'             => 'Ready for collection',
    'Claimed'           => 'Claimed',
    'Rejected'          => 'Rejected',
];
$catIcon = [
    'DOC-TOR'     => ['linear-gradient(135deg,#2563eb,#1d4ed8)','fa-file-invoice'],
    'DOC-COE'     => ['linear-gradient(135deg,#16a34a,#15803d)','fa-certificate'],
    'DOC-GM'      => ['linear-gradient(135deg,#0d9488,#0f766e)','fa-handshake-angle'],
    'DOC-DIPLOMA' => ['linear-gradient(135deg,#7c3aed,#6d28d9)','fa-graduation-cap'],
    'DOC-CTC'     => ['linear-gradient(135deg,#4f46e5,#4338ca)','fa-copy'],
    'DOC-HD'      => ['linear-gradient(135deg,#ea580c,#c2410c)','fa-sign-out-alt'],
    'DOC-CD'      => ['linear-gradient(135deg,#db2777,#be185d)','fa-book-open'],
];

$page_title = 'Document Requests';
$page_description = 'Document requests, workflow actions, and performance metrics';
$body_page = 'documents';
$APP_ROOT = '../';
$ACTIVE_NAV = 'documents';
$extra_css = ['documents.css'];
$use_chart = true;
// Loads js/documents.js, which builds the revenue and daily-volume
// charts from the canvases' data-attributes.
//
// This was simply missing, and the failure was silent: Chart.js still
// loaded (so $use_chart did its job and nothing 404'd), the page had
// two correctly-populated <canvas> elements sitting in correctly-sized
// 230px wrappers, and the only symptom was two blank boxes. Chart.js is
// only a library — without this line nothing ever calls it, and the
// canvas keeps its default 300x150 backing store.
$page_scripts = ['documents.js'];
?>


<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<style>
/* Document requests — registrar-blue system, shared with the other
   registrar modules (Students, Queue, Status Tracker). */
body[data-page="documents"]{background:#f5f7fb;color:#0f172a}
body[data-page="documents"] .dashboard-main{padding:24px clamp(18px,2.5vw,38px) 48px;background:linear-gradient(180deg,#eef4ff 0,#f8faff 300px,#f8faff 100%);min-height:auto}

/* ── Header ───────────────────────────────────────────── */
.dq-head{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap;margin:0 0 16px;padding:25px 27px;border:1px solid #c7d7fe;border-radius:19px;background:linear-gradient(120deg,#eff6ff,#fff 68%);box-shadow:0 10px 30px rgba(37,99,235,.08)}
.dq-kicker{display:flex;align-items:center;gap:7px;margin-bottom:7px;color:#1d4ed8;font-size:10.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.dq-head h1{margin:0 0 5px;font-size:28px;line-height:1.1;letter-spacing:-.03em;color:#172554}
.dq-head p{max-width:640px;margin:0;font-size:12.5px;line-height:1.5;color:#64748b}
.dq-head .header-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.dq-head .btn{min-height:36px;font-size:12px}

/* ── Metric strip ─────────────────────────────────────── */
.dq-strip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));margin:0 0 16px;background:#fff;border:1px solid #dbeafe;border-radius:16px;box-shadow:0 6px 22px rgba(15,23,42,.04);overflow:hidden}
.dq-metric{position:relative;padding:18px 20px;border-right:1px solid #e2e8f0}
.dq-metric:last-child{border-right:0}
.dq-metric::after{content:"";position:absolute;left:20px;right:20px;bottom:0;height:3px;background:#dbeafe}
.dq-metric.is-tat::after{background:#1d4ed8}
.dq-metric.is-rev::after{background:#16a34a}
.dq-metric.is-reg::after{background:#64748b}
.dq-metric.is-exp::after{background:#d97706}
.dq-metric .dq-label{font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#64748b}
.dq-metric .dq-value{margin-top:6px;font-size:28px;font-weight:800;line-height:1.1;color:#0f172a;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
.dq-metric .dq-value .dq-unit{font-size:15px;font-weight:700;color:#64748b}
.dq-metric.is-rev .dq-value{color:#15803d}

/* ── Panels ───────────────────────────────────────────── */
.dq-split{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:0 0 16px}
.dq-split>.panel{margin-bottom:0}
body[data-page="documents"] .panel{border:1px solid #dbeafe;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.045);margin-bottom:16px;overflow:hidden}
body[data-page="documents"] .panel-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:13px 18px;background:#f8faff;border-bottom:1px solid #e5e7eb}
body[data-page="documents"] .panel-title{display:flex;align-items:center;gap:8px;padding:0;font-size:13px;font-weight:700;letter-spacing:-.01em;color:#1e293b}
body[data-page="documents"] .panel-title i{color:#2563eb;font-size:12px}
body[data-page="documents"] .table-responsive{margin:0}
body[data-page="documents"] .table{margin:0;width:100%;border-collapse:collapse}
body[data-page="documents"] .table thead th{padding:11px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#475569;font-size:10px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;text-align:left;white-space:nowrap}
body[data-page="documents"] .table tbody td{padding:11px 14px;border-bottom:1px solid #f1f5f9;color:#1e293b;font-size:13px;vertical-align:middle}
body[data-page="documents"] .table tbody tr[data-doc]{cursor:pointer}
body[data-page="documents"] .table tbody tr[data-doc]:hover{background:#eff6ff}
body[data-page="documents"] .student-avatar{width:32px;height:32px;font-size:12px}
body[data-page="documents"] .empty-state td{padding:0}
body[data-page="documents"] .dq-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;min-height:220px;padding:34px 20px;text-align:center}
body[data-page="documents"] .dq-empty i{font-size:34px;color:#cbd5e1}
body[data-page="documents"] .dq-empty p{margin:0;font-size:14px;font-weight:600;color:#64748b}
body[data-page="documents"] .dq-empty span{font-size:12.5px;color:#94a3b8}

/* ── Row actions ────────────────────────────────────────────────
   A row has exactly ONE forward step and it is the thing the clerk
   came to do, so it is rendered at full strength, always. Nothing
   here is dimmed to signal "secondary" — that made the primary
   action on every row look disabled, and it only came back on
   :focus-visible, so a mouse user never saw it clearly at all.

   Hierarchy is carried by weight and fill instead:
     primary   filled blue, full opacity   — the next step
     danger    outline, muted red          — available, not inviting
   De-emphasis, where it is wanted, is applied to the SECONDARY
   control only (.btn-danger), never to the action itself. */
/* Three controls in this cell, and letting them wrap pushed View onto a
   second line where it read as a footnote to the row rather than a
   control. The column is sized explicitly instead: the forward step
   gets the width it needs, Reject takes what is left, and View spans
   the pair underneath — where a three-button row would have been too
   cramped to read. */
/* ── The process rail ────────────────────────────────────────────
   Where the request is standing in the walk-in run, drawn as a
   lollipop: a dot on each stage, a line running to the next.

   Three earlier revisions were wrong and it is worth recording how,
   because each time I treated this as something to STYLE rather than
   something to REDUCE. Square stamp pads (a costume, not a
   reference); circles on a rule (which is what this is again — but
   then carrying four words per row); a typographic strip; and a
   tally. The tally was closest to right and still wrong, because a
   count says how many and not which. A lollipop says both: the dot
   is the station, the line is the journey between them.

   Only the CURRENT stage is named. The other three are the same on
   every row, and the "Waiting on" column already carries the state
   in words, so repeating them twenty times cost a line of height to
   say nothing row-specific.

   COLOUR comes from the palette the app already ships in
   registrar.css (.pill.active / .pending / .at-risk / .inactive)
   rather than a private one. An earlier revision invented navy,
   brass and rust, which read as a different product sitting inside
   this one - the same reason those values are wrong is the reason
   they look wrong:
     passed   green  #16a34a   the app's "done" green
     in hand  amber  #b45309   .pill.pending
     held     red    #dc2626   .pill.at-risk
     ahead    grey   #cbd5e1   .pill.inactive, the 200 step
   Amber is reserved for the station actually being worked, so it is
   the only warm mark on the row and the eye lands on it first.

   MOTION: the line inks itself left to right on load, then the dot
   for the current station rises and takes a slow pulse. It runs
   once, never on every row hover - motion here answers "where did
   this start", and repeating it would be noise. Fully disabled
   under prefers-reduced-motion, and the dots are still in their
   final positions, so the rail is legible without the animation. */
.dq-fee{font-weight:700;font-variant-numeric:tabular-nums;color:#334155}
.dq-rail{display:flex;align-items:center;gap:0;margin-top:11px;list-style:none;padding:0;width:max-content;max-width:100%}
/* Each stage owns the line that LEADS to it, so the line and the dot
   that ends it are one element's business. */
.dq-station{display:flex;align-items:center;flex:0 0 auto;position:relative;padding-right:20px}
.dq-station:last-child{padding-right:0}

/* ── The line ──────────────────────────────────────────────────
   Sits behind the dots (z-index below), and stops short of the next
   dot so the two never touch. Dashed while the work is still ahead
   of it: an unfilled rule is what a not-yet-walked line looks like. */
.dq-station::before{
  content:"";position:absolute;left:11px;right:6px;top:50%;
  height:2px;margin-top:-1px;z-index:0;
  background:repeating-linear-gradient(90deg,#cbd5e1 0 4px,transparent 4px 8px);
  transform:scaleX(0);transform-origin:left center;
}
.dq-station:first-child::before{display:none}
/* Passed: the line is walked, so it is solid. */
.dq-station.is-done::before{background:#16a34a;transform:scaleX(1);animation:railInk .34s cubic-bezier(.4,0,.2,1) both;animation-delay:calc(var(--i,0) * 70ms)}
/* The line into the station in hand is half inked: it is the one
   still being travelled. */
.dq-station.is-now::before{background:repeating-linear-gradient(90deg,#b45309 0 4px,transparent 4px 8px);transform:scaleX(1);animation:railInk .34s cubic-bezier(.4,0,.2,1) both;animation-delay:calc(var(--i,0) * 70ms)}
/* Held: dashed but red. It is not progress, and must not look like
   progress that is merely slow. */
.dq-station.is-held::before{background:repeating-linear-gradient(90deg,#dc2626 0 4px,transparent 4px 8px);transform:scaleX(1);animation:railInk .34s cubic-bezier(.4,0,.2,1) both;animation-delay:calc(var(--i,0) * 70ms)}
/* Off the track: struck, not pale. */
.dq-station.is-stopped::before{background:repeating-linear-gradient(90deg,#dc2626 0 4px,transparent 4px 8px);transform:scaleX(1) rotate(-1deg)}

@keyframes railInk{from{transform:scaleX(0)}to{transform:scaleX(1)}}

/* ── The lollipop ─────────────────────────────────────────────
   A dot with a stem, so it reads as a lollipop on a wire rather
   than a plain circle: the stem is the short rise above the line
   the dot sits on. */
.dq-dot{
  position:relative;z-index:1;flex:0 0 auto;
  width:9px;height:9px;border-radius:50%;
  background:#fff;border:2px solid #cbd5e1;
}
.dq-station.is-done .dq-dot{background:#16a34a;border-color:#16a34a}
.dq-station.is-now .dq-dot{background:#b45309;border-color:#b45309;width:11px;height:11px;animation:railPop .3s cubic-bezier(.34,1.56,.64,1) both;animation-delay:calc(var(--i,0) * 70ms + 260ms)}
.dq-station.is-held .dq-dot{background:#dc2626;border-color:#dc2626;width:11px;height:11px;animation:railPop .3s cubic-bezier(.34,1.56,.64,1) both;animation-delay:calc(var(--i,0) * 70ms + 260ms)}
.dq-station.is-stopped .dq-dot{background:#fff;border-color:#dc2626}
/* The slow pulse on the one dot being worked. Infinite, but tiny
   and slow - a heartbeat, not a blink. This is the only looping
   motion on the page. */
.dq-station.is-now .dq-dot::after,.dq-station.is-held .dq-dot::after{
  content:"";position:absolute;inset:-2px;border-radius:50%;
  border:2px solid currentColor;color:#b45309;opacity:0;
  animation:railPulse 2.6s ease-out infinite;
}
.dq-station.is-held .dq-dot::after{color:#dc2626}
/* Struck through: the request never reached the end. */
.dq-station.is-stopped .dq-dot::before{
  content:"";position:absolute;left:50%;top:50%;width:17px;height:2px;
  background:#dc2626;border-radius:1px;transform:translate(-50%,-50%) rotate(-8deg);
}
@keyframes railPop{from{transform:scale(0)}to{transform:scale(1)}}
@keyframes railPulse{0%{opacity:.55;transform:scale(1)}70%{opacity:0;transform:scale(2.1)}100%{opacity:0;transform:scale(2.1)}}

/* The one word: the current station, and nothing else. */
.dq-stage-word{margin-left:10px;font-size:11.5px;font-weight:700;letter-spacing:.005em;color:#16a34a;white-space:nowrap}
.dq-stage-word.is-now{color:#b45309}
/* Green is the default colour, so a finished stage word needs no rule -
   it just must not be given is-now. */
.dq-stage-word.is-held{color:#dc2626}
.dq-stage-word.is-stopped{color:#dc2626;text-decoration:line-through;text-decoration-thickness:1px}

/* Screen-reader only: a dot is meaningless read linearly, so the
   full track is announced as a sentence instead. */
.dq-rail-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}

@media(max-width:760px){
  .dq-rail{display:none}
  .dq-rail-fallback{display:flex!important}
}
.dq-rail-fallback{display:none;align-items:center;gap:7px;margin-top:9px;font-size:11.5px;font-weight:700;color:#16a34a}
.dq-rail-fallback i{font-size:11px}
/* The state word, then the qualifier. Kept on one line: an earlier
   version let "left the track" wrap to three lines in a narrow
   column, which made the rejected row three times the height of
   every other row for no extra information. */
.dq-rail-fb-state{white-space:nowrap}
.dq-rail-fb-note{font-weight:600;font-size:10.5px;color:#64748b;white-space:nowrap}
.dq-rail-fb-note::before{content:"· ";color:#cbd5e1}
.dq-rail-fallback.is-held{color:#dc2626}
.dq-rail-fallback.is-stopped{color:#dc2626}
.dq-rail-fallback.is-stopped .dq-rail-fb-state{text-decoration:line-through;text-decoration-thickness:1px}

.row-actions{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px 8px;align-items:center;min-width:190px}
.row-actions>.btn{width:100%}
.row-actions .dq-view{grid-column:1 / -1;width:100%}
/* "Sign & mark ready" is the longest label in the set; without this it
   wraps inside its own button and the row grows a line. */
.row-actions .btn-primary{white-space:nowrap}
@media(max-width:900px){
  .row-actions{grid-template-columns:1fr}
  .row-actions .dq-view{grid-column:auto}
}
.row-actions .btn{opacity:1}
/* Reject is a real option but not the one the clerk came for. It
   reads as an outline rather than a filled red block, so the eye goes
   to the forward step first. Still fully legible and keyboard
   reachable — de-emphasised, not disabled. */
.row-actions .btn-danger{
  background:transparent;color:#b91c1c;border-color:#fecaca;
  font-weight:600;opacity:1;
}
.row-actions .btn-danger:hover{background:#fef2f2;border-color:#fca5a5;color:#991b1b}
.row-actions .btn-danger:focus-visible{outline:2px solid #dc2626;outline-offset:1px}
.row-actions .btn-primary:focus-visible{outline:2px solid #1d4ed8;outline-offset:2px}

/* View opens the request's detail. It is a peer of the forward step,
   not a leftover, so it gets the same quiet weight as Reject rather
   than the ghosted grey text it replaced — a control you cannot read
   is a control that does not look clickable. */
.row-actions .dq-view{
  background:transparent;color:#475569;border-color:#cbd5e1;
  font-weight:600;opacity:1;
}
.row-actions .dq-view:hover{background:#f1f5f9;color:#0f172a;border-color:#94a3b8}
.row-actions .dq-view:focus-visible{outline:2px solid #475569;outline-offset:1px}
.row-actions .dq-view[aria-expanded="true"]{background:#f1f5f9;color:#0f172a;border-color:#94a3b8}

/* ── Filter toolbar ───────────────────────────────────── */
.dq-filters{display:flex;align-items:center;gap:9px;flex-wrap:wrap;padding:13px 18px;border-bottom:1px solid #e5e7eb;background:#fff}
.dq-search{position:relative;flex:1 1 280px;min-width:200px}
.dq-search i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#64748b;font-size:13px;pointer-events:none}
.dq-search input{width:100%;height:38px;box-sizing:border-box;padding:0 12px 0 36px;border:1px solid #cbd5e1;border-radius:9px;background:#f8faff;color:#1e293b;font:13px Inter,sans-serif}
.dq-search input:focus{outline:0;border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.1)}
.dq-filters select{height:38px;padding:0 10px;border:1px solid #cbd5e1;border-radius:9px;background:#f8faff;color:#1e293b;font:13px Inter,sans-serif;cursor:pointer}
.dq-filters select:focus{outline:0;border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.1)}
.dq-count{font-size:12px;color:#64748b;white-space:nowrap}
.dq-count strong{color:#0f172a}

/* ── Revenue range picker ─────────────────────────────── */
.dq-range{display:flex;align-items:center;gap:7px}
.dq-range input[type="date"]{height:34px;width:150px;padding:0 9px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#1e293b;font:13px Inter,sans-serif;cursor:pointer}
.dq-range input[type="date"]:focus{outline:0;border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.1)}
.dq-range .dq-sep{color:#94a3b8;font-size:12px}
.dq-range .btn{min-height:34px;width:34px;padding:0;display:inline-flex;align-items:center;justify-content:center}

/* ── Expanded detail row ──────────────────────────────── */
.doc-detail{background:#f8faff}
.doc-detail .dq-fields{display:flex;flex-wrap:wrap;gap:8px 22px;font-size:12.5px;color:#475569}
.doc-detail .dq-fields strong{color:#0f172a;font-weight:700}
.doc-detail .dq-log{margin-top:14px;border-top:1px solid #e2e8f0;padding-top:12px}
.doc-detail .dq-log-head{font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#64748b;margin-bottom:8px}
.doc-detail .dq-log-row{display:flex;gap:10px;margin-bottom:5px;font-size:12px}
.doc-detail .dq-log-row time{color:#94a3b8;white-space:nowrap;font-variant-numeric:tabular-nums}

/* ── New Request modal ───────────────────────────────── */
/* registrar.css makes .modal-content the scroll box (max-height:90vh,
   overflow-y:auto). Here the dialog becomes a fixed-height flex column
   instead: header and footer pinned, only the body scrolls. */
#newRequestModal{align-items:flex-start}
#newRequestModal .modal-content{display:flex;flex-direction:column;max-width:600px;max-height:calc(100vh - 40px);padding:0;border-radius:18px;border:1px solid #dbeafe;box-shadow:0 24px 60px rgba(15,23,42,.22);overflow:hidden}
#newRequestModal .modal-header{flex:0 0 auto;display:flex;align-items:center;gap:13px;padding:18px 22px;background:linear-gradient(120deg,#eff6ff,#fff 70%);border-bottom:1px solid #dbeafe;margin-bottom:0}
#newRequestModal .modal-header .nq-mark{display:grid;place-items:center;width:38px;height:38px;flex:0 0 38px;border-radius:11px;background:linear-gradient(140deg,#2563eb,#1d4ed8);color:#fff;font-size:15px;box-shadow:0 6px 16px rgba(37,99,235,.28)}
#newRequestModal .modal-header h3{margin:0;font-size:17px;font-weight:700;letter-spacing:-.02em;color:#172554}
#newRequestModal .modal-header p{margin:2px 0 0;font-size:12px;color:#64748b}
#newRequestModal .modal-header .modal-close{margin-left:auto;background:transparent;border:1px solid #dbeafe;color:#64748b;width:32px;height:32px;border-radius:9px;display:grid;place-items:center;cursor:pointer;transition:background .15s ease,color .15s ease}
#newRequestModal .modal-header .modal-close:hover{background:#e0e7ff;color:#1d4ed8}
#newRequestModal .modal-body{flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;padding:20px 22px;margin-bottom:0;background:#fff}

/* ?? The steps ???????????????????????????????????????????????????
   Numbered, because filling this form IS a sequence: who, then what,
   then why. Four uppercase headings with rules under them said "here is
   a document" rather than "this is the order to work", and the rules
   added four more horizontal lines to a form that already had a boxed
   fee ticket below it.

   The numeral carries the order, the label carries the meaning, and a
   thin rail joins them so the eye reads down one column instead of
   hopping between left-edge numbers and right-edge labels. The rail
   fills in as the step below it becomes usable, which is the only
   motion on this dialog and it answers the clerk's next question:
   "what can I do next?". */
.nq-steps{list-style:none;margin:0 0 4px;padding:0;counter-reset:nq}
.nq-step{position:relative;display:grid;grid-template-columns:26px 1fr;gap:12px;padding-bottom:18px}
.nq-step:last-child{padding-bottom:4px}
/* The rail between steps. Drawn on the step, not as a separate element,
   so it cannot outnumber the steps and leave a dangling stub. */
.nq-step:not(:last-child)::after{
  content:"";position:absolute;left:13px;top:26px;bottom:2px;width:2px;
  background:#e2e8f0;border-radius:1px;
}
.nq-step.is-done::after{background:#2563eb}
.nq-step-mark{
  display:grid;place-items:center;width:26px;height:26px;border-radius:8px;
  background:#f1f5f9;color:#94a3b8;font:700 12px/1 "JetBrains Mono",ui-monospace,monospace;
  border:1px solid #e2e8f0;transition:background .16s ease,color .16s ease,border-color .16s ease;
}
.nq-step.is-active .nq-step-mark{background:#2563eb;border-color:#2563eb;color:#fff}
.nq-step.is-done .nq-step-mark{background:#dbeafe;border-color:#bfdbfe;color:#1d4ed8}
.nq-step-body{min-width:0}
.nq-step-label{margin-bottom:6px;font-size:12.5px;font-weight:650;color:#334155}
.nq-fields{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.nq-field>label{display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#64748b}
.nq-field>label .nq-req{color:#dc2626;margin-left:2px}
/* The one line that says what the optional field is for. Sits under the
   input rather than in the label, because it is a caution about filling
   the field in, not a description of its contents. */
.nq-hint{margin-top:6px;font-size:11px;line-height:1.45;color:#94a3b8}

/* Facts about the request, as opposed to fields on it. Kept together
   under the form because that is where a clerk looks for the two things
   that are true rather than typed: what it costs, and how it is
   handed over. */
.nq-facts{margin-top:2px;padding-top:16px;border-top:1px solid #e2e8f0}
.nq-fact{display:flex;align-items:center;gap:8px;margin:11px 0 0;font-size:12px;color:#64748b}
.nq-fact i{font-size:11px;color:#94a3b8}
#newRequestModal .form-control{width:100%;height:40px;box-sizing:border-box;padding:0 12px;border:1px solid #cbd5e1;border-radius:9px;background:#f8faff;color:#1e293b;font:13px Inter,sans-serif;transition:border-color .15s ease,box-shadow .15s ease}
#newRequestModal select.form-control{cursor:pointer;padding-right:30px}
#newRequestModal .form-control:focus{outline:0;border-color:#2563eb;background:#fff;box-shadow:0 0 0 4px rgba(37,99,235,.12)}
#newRequestModal .form-control::placeholder{color:#94a3b8}
#newRequestModal .form-control[readonly]{background:#f1f5f9;color:#64748b;cursor:default}
#newRequestModal .form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
#newRequestModal .req-hint{margin-top:8px}

/* The fee ticket — the one loud element. */
.nq-fee{position:relative;display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:0 0 2px;padding:15px 18px 14px;border:1px solid #bfdbfe;border-radius:14px;background:linear-gradient(140deg,#eff6ff,#fff 72%)}
.nq-fee::before{content:"";position:absolute;left:18px;right:18px;top:11px;height:2px;background:repeating-linear-gradient(90deg,#c7d7fe 0 7px,transparent 7px 14px)}
.nq-fee-cap{font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:#1d4ed8}
.nq-fee-note{margin-top:5px;font-size:12px;color:#64748b;max-width:38ch}
.nq-fee-amount{font:800 30px/1 JetBrains Mono,ui-monospace,monospace;letter-spacing:-.03em;color:#1d4ed8;font-variant-numeric:tabular-nums;white-space:nowrap}

#newRequestModal .modal-footer{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:9px;padding:14px 22px;background:#f8faff;border-top:1px solid #e2e8f0}
#newRequestModal .modal-footer .btn{min-height:40px;padding:0 20px;font-size:13px}
#newRequestModal .modal-footer .btn-primary{display:inline-flex;align-items:center;gap:7px}

/* ── Document preview ─────────────────────────────────────────────
   The preview is a full document, so the shell is sized like a sheet
   of paper and scrolls internally instead of the page scrolling. */
#docPreviewShell{max-width:960px;height:min(88vh,1180px);padding:0;border-radius:18px;border:1px solid #dbeafe;box-shadow:0 24px 60px rgba(15,23,42,.22);overflow:hidden;display:flex;flex-direction:column}
#docPreviewShell .modal-header{padding:16px 20px;flex:0 0 auto}
#docPreviewShell .modal-footer{padding:12px 20px;flex:0 0 auto}
#docPreviewBody{padding:0;flex:1 1 auto;overflow:auto;background:#e2e8f0;min-height:0}
/* The sheet is centred on a grey mat, not stretched to fill the dialog.
   Stretching is what made the preview read as disorganised: the document
   has its own fixed page width, so forcing it to the dialog width
   reflowed the field table and pulled the seal/QR block away from the
   signature line. */
.dq-preview-frame{width:100%;height:100%;min-height:560px;border:0;display:block;background:#e2e8f0}
.dq-preview-loading{padding:60px 20px;text-align:center;color:#64748b;font-size:13px}
.dq-preview-loading i{margin-right:6px;color:#1d4ed8}
.dq-detail-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid #e2e8f0}
@media(max-width:560px){#docPreviewShell{height:calc(100vh - 24px);max-width:100%}.dq-detail-actions .btn{flex:1 1 auto;justify-content:center}.dq-preview-frame{min-height:420px}}

@media(max-width:560px){#newRequestModal .modal-content{max-height:calc(100vh - 24px)}#newRequestModal .modal-body{padding:16px}#newRequestModal .form-row{grid-template-columns:1fr}#newRequestModal .modal-header{padding:15px 16px}#newRequestModal .modal-footer{padding:12px 16px}#newRequestModal .modal-footer .btn{flex:1 1 auto;justify-content:center}.nq-fee{flex-direction:column;align-items:flex-start;gap:8px}.nq-fee-amount{font-size:26px}.nq-fields{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){#newRequestModal .modal-header .modal-close{transition:none}}
.dq-metric.is-warn .dq-value{color:#b45309}
.dq-metric-note{font-size:10.5px;color:#94a3b8;margin-top:5px;line-height:1.3}
.dq-metric.is-warn .dq-metric-note{color:#b45309}

/* ── Waiting & lateness ─────────────────────────────────────────
   The one thing this page could not previously show: why a request
   is not moving. Two distinct ideas, kept visually distinct so a
   clerk never mistakes "someone else owes us" for "we are behind".

   .dq-wait   a blockage — amber rule down the row's left edge
   .dq-late   past the SKU's target — red, and red is reserved for
              lateness so the colour keeps its meaning
   Both carry an age, because "blocked" without "since when" is not
   actionable: a clearance pending four days is a chase, one pending
   four hours is not. */
/* The status pill and fee, sitting under the request name. */
.dq-facts{display:flex;align-items:center;gap:8px;margin-top:5px;padding-left:36px}
.dq-fee{font-size:12px;font-weight:700;color:#475569;font-variant-numeric:tabular-nums}

/* The request's own clock.

   A counter clerk does not think in dates, they think in "how much of
   my promise is used up". Every SKU carries a target in
   document_catalog.sla_days, so this draws that promise as a bar:
   filled for time consumed, a tick at the target.

   It is the one deliberately loud element on the page, and it earns
   that - it is the only mark that shows a request drifting toward
   late before it is actually late. */
.dq-clock{display:flex;flex-direction:column;gap:5px;min-width:104px}
/* The fill is always given a floor so a request filed an hour ago does
   not render as a bare track. A 3% sliver still reads as "just
   started" against a 1-day target while staying visible. */
.dq-clock-fill{position:absolute;left:0;top:0;bottom:0;min-width:5px;border-radius:999px;background:#475569;transition:width .2s ease}
.dq-clock-bar{position:relative;height:6px;border-radius:999px;background:#e8edf3;box-shadow:inset 0 0 0 1px #e2e8f0}
.dq-clock-tick{position:absolute;top:-3px;bottom:-3px;width:1px;background:#94a3b8}
.dq-clock-tick::after{content:"";position:absolute;left:-2px;top:-2px;width:5px;height:5px;border-radius:50%;background:#94a3b8}
.dq-clock-when{font-size:11.5px;color:#475569;font-variant-numeric:tabular-nums}
.dq-clock-when b{color:#0f172a;font-weight:700}
/* Past the target the bar turns red while the tick stays neutral, so
   "the promise ended" and "now" remain distinguishable. */
.dq-clock.is-late .dq-clock-fill{background:#dc2626}
.dq-clock.is-late .dq-clock-when{color:#b91c1c;font-weight:700}
.dq-clock.is-done .dq-clock-fill{background:#0f766e}
@media(prefers-reduced-motion:reduce){.dq-clock-fill{transition:none}}

/* Waiting.

   With exit clearance gone there is one kind of hold left: a balance,
   or a document the student must bring. Both are the student's to
   resolve, so this is always amber and never a lock - nothing here is
   a hard stop, and the desk can prepare the document regardless. */
.dq-wait{display:flex;flex-direction:column;gap:3px;max-width:34ch}
.dq-wait-line{display:flex;align-items:flex-start;gap:6px;font-size:11.5px;line-height:1.35;color:#92400e}
.dq-wait-line i{color:#d97706;margin-top:2px;flex:0 0 auto}
.dq-wait-since{font-size:10.5px;color:#b45309;padding-left:17px;font-variant-numeric:tabular-nums}
/* Lift a hold the registrar set. Deliberately the same weight as
   .dq-recheck so the two controls do not compete — but it is a real
   action, so it gets the interactive affordances Re-check has. */
.dq-lift{border:0;background:transparent;color:#94a3b8;cursor:pointer;font-size:11px;padding:2px 4px;border-radius:4px;display:inline-flex;align-items:center;gap:4px}
.dq-lift:hover{color:#b45309;background:#fffbeb}
.dq-lift:focus-visible{outline:2px solid #b45309;outline-offset:1px}
.dq-lift:disabled{opacity:.6;cursor:default}
tr.is-blocked>td:first-child{box-shadow:inset 3px 0 0 #f59e0b}

/* Clear — the only positive state in the process, and shown on most
   rows, so it is deliberately low-contrast rather than a green stamp
   per line. */
.dq-clear{font-size:11.5px;color:#94a3b8;display:flex;align-items:center;gap:5px}
/* The advisory ask. Visually a footnote to the status line, not a
   banner: same weight as "Nothing — start it", because that is exactly
   what it is. Amber and dashed would read as a warning, and a hold is
   what the desk already uses those for. */
.dq-bring{margin-top:6px;font-size:11px;color:#64748b;display:flex;align-items:flex-start;gap:5px;line-height:1.45}
.dq-bring i{margin-top:2px;font-size:10px;color:#94a3b8;flex:0 0 auto}
.dq-clear i{color:#22c55e}

/* Blocked rows recede slightly: the work still matters, but it is
   not the next thing to do. Contrast is never reduced below legible
   — these are the rows most likely to be scrolled past. */
tr.is-blocked{background:#fffdf7}
tr.is-blocked:hover{background:#fffbeb}

/* Row-level recheck, quiet until wanted. */
.dq-recheck{border:0;background:transparent;color:#94a3b8;cursor:pointer;font-size:11px;padding:2px 4px;border-radius:4px;display:inline-flex;align-items:center;gap:4px}
.dq-recheck:hover{color:#1d4ed8;background:#eff6ff}
.dq-recheck:focus-visible{outline:2px solid #1d4ed8;outline-offset:1px}

/* Row-level recheck, quiet until wanted. */
.dq-recheck{border:0;background:transparent;color:#94a3b8;cursor:pointer;font-size:11px;padding:2px 4px;border-radius:4px;display:inline-flex;align-items:center;gap:4px}
.dq-recheck:hover{color:#1d4ed8;background:#eff6ff}
.dq-recheck:focus-visible{outline:2px solid #1d4ed8;outline-offset:1px}

@media(prefers-reduced-motion:reduce){.dq-recheck{transition:none}}

/* ── Responsive ───────────────────────────────────────── */
@media(max-width:1000px){.dq-strip{grid-template-columns:repeat(2,minmax(0,1fr))}.dq-metric:nth-child(2){border-right:0}.dq-metric:nth-child(-n+2){border-bottom:1px solid #e2e8f0}.dq-metric::after{display:none}.dq-split{grid-template-columns:1fr}}
@media(max-width:900px){.dq-head{flex-direction:column;align-items:flex-start}.dq-head .header-actions{width:100%;justify-content:flex-start}}
@media(max-width:600px){.dq-head{padding:21px 18px}.dq-head h1{font-size:25px}.dq-head .header-actions{flex-direction:column;align-items:stretch}.dq-head .btn{justify-content:center}.dq-strip{grid-template-columns:1fr}.dq-metric{border-right:0;border-bottom:1px solid #e2e8f0}.dq-metric:last-child{border-bottom:0}.dq-search,.dq-filters select{width:100%}.dq-range{width:100%}.dq-range input[type="date"]{flex:1 1 0;width:auto}}
@media(prefers-reduced-motion:reduce){.row-actions .btn{transition:none}}
</style>
<main class="dashboard-main">
<div class="dashboard-container">

<header class="dq-head">
    <div>
        <div class="dq-kicker"><i class="fa-solid fa-file-circle-check"></i> Registrar desk</div>
        <h1>Document Requests</h1>
        <p>File, prepare and release documents at the counter. Track where each request stands.</p>
    </div>
    <div class="header-actions">
        <button class="btn btn-primary" onclick="openNewRequest()"><i class="fas fa-plus"></i> New Request</button>
    </div>
</header>

<!-- ── The four numbers that change what you do next ─────────── -->
<!-- Each tile answers a question a clerk asks on arrival, and each
     one has a different consequence:
       Needs action   work the desk owns right now
       Waiting        held by someone else — chase, do not start
       Overdue        past the target set on that document
       Longest open   how stale the oldest request has gone -->
<div class="dq-strip">
    <div class="dq-metric is-tat">
        <div class="dq-label">Needs action</div>
        <div class="dq-value"><?= (int) $needsAction ?></div>
        <div class="dq-metric-note"><?= $needsAction === 1 ? 'request' : 'requests' ?> filed or in progress</div>
    </div>
    <div class="dq-metric is-rev<?= $blockedCount > 0 ? ' is-warn' : '' ?>">
        <div class="dq-label">Needs something first</div>
        <div class="dq-value"><?= (int) $blockedCount ?></div>
        <div class="dq-metric-note">
            <?php if ($blockedCount > 0): ?>
                <?php // Was hardcoded to "a balance to settle". That is only
                      // true when the hold was derived, and it stopped being
                      // true the moment a person could set one: the tile said
                      // "a balance" above a row whose reason read "Dean's
                      // office has the affidavit", sending the clerk to look
                      // for money that was never the problem. It now says
                      // which kinds of hold are actually on the desk. ?>
                <?= $manualHoldCount > 0 && $balanceHoldCount > 0 ? 'a balance and your own holds'
                    : ($manualHoldCount > 0 ? 'held at your discretion' : 'a balance to settle') ?>
            <?php else: ?>
                nothing outstanding
            <?php endif; ?>
        </div>
    </div>
    <div class="dq-metric is-reg<?= $overdueCount > 0 ? ' is-warn' : '' ?>">
        <div class="dq-label">Past target</div>
        <div class="dq-value"><?= (int) $overdueCount ?></div>
        <div class="dq-metric-note"><?= $oldestDays >= 1 ? 'oldest open ' . doc_age_label(['days' => $oldestDays, 'hours' => 0]) : 'nothing overdue' ?></div>
    </div>
    <div class="dq-metric is-exp">
        <div class="dq-label">Collected this month</div>
        <div class="dq-value">&#8369;<?= number_format($revenueTotal, 2) ?></div>
        <div class="dq-metric-note"><?= $tatHours !== null ? 'avg ' . htmlspecialchars($tatHours) . 'h to prepare' : 'fees taken at the counter' ?></div>
    </div>
</div>

<!-- ── Charts ───────────────────────────────────────────────── -->
<div class="dq-split">
    <div class="panel">
        <div class="panel-toolbar">
            <div class="panel-title"><i class="fa-solid fa-chart-bar"></i> Revenue by Document Type</div>
            <div class="dq-range">
                <input type="date" id="revFrom" value="<?= htmlspecialchars($from) ?>" aria-label="Revenue from">
                <span class="dq-sep">&ndash;</span>
                <input type="date" id="revTo" value="<?= htmlspecialchars($to) ?>" aria-label="Revenue to">
                <button class="btn btn-sm btn-secondary" onclick="applyRevFilter()" title="Apply date range"><i class="fa-solid fa-filter"></i></button>
            </div>
        </div>
        <div style="padding:16px;">
            <div class="metric-canvas">
                <canvas id="revenueChart"
                    data-labels='<?= htmlspecialchars(json_encode(array_column($revenueRows, 'doc_name'))) ?>'
                    data-data='<?= htmlspecialchars(json_encode(array_map(fn($r) => (float) $r['revenue'], $revenueRows))) ?>'
                    data-total="<?= (float) $revenueTotal ?>"></canvas>
            </div>
        </div>
    </div>
    <div class="panel">
        <div class="panel-toolbar">
            <div class="panel-title"><i class="fa-solid fa-layer-group"></i> Daily Intake, Last 7 Days</div>
        </div>
        <div style="padding:16px;">
            <div class="metric-canvas">
                <canvas id="volumeChart"
                    data-labels='<?= htmlspecialchars(json_encode($days)) ?>'
                    data-settled='<?= htmlspecialchars(json_encode($settledSeries)) ?>'
                    data-outstanding='<?= htmlspecialchars(json_encode($outstandingSeries)) ?>'></canvas>
            </div>
            <?php // No backlog total is printed here on purpose. The amber
                  // cap already carries it and "Needs Action" one tile up
                  // counts the same rows, so a second copy of the number
                  // would just be the same figure twice within one screen.
                  // What this panel adds is the shape over the week — which
                  // days the work arrived and whether the cap is growing. ?>
        </div>
    </div>
</div>

<!-- ── Request Table ─────────────────────────────────────────── -->
<div class="panel">
    <div class="panel-toolbar">
        <div class="panel-title"><i class="fa-solid fa-list"></i> Document Requests</div>
    </div>
    <div class="dq-filters">
        <div class="dq-search">
            <i class="fas fa-search"></i>
            <input type="text" id="docSearch" placeholder="Search student, document, ID&hellip;" aria-label="Search requests">
        </div>
        <select id="typeFilter" onchange="applyFilters()" aria-label="Filter by request type">
            <?php // Every request is Regular now that Express is out of the
                  // product, so a two-option filter has one reachable
                  // choice. The select is kept (not removed) because the
                  // table is rendered client-side and the column is part
                  // of its state, but it no longer offers a dead option. ?>
            <option value="">All Requests</option>
            <option value="Regular">Regular</option>
        </select>
        <select id="statusFilter" onchange="applyFilters()" aria-label="Filter by status">
            <option value="">All Statuses</option>
            <option value="Filed">Filed</option>
            <option value="Pending_Clearance">Pending Clearance</option>
            <option value="Processing">Being prepared</option>
            <option value="Ready">Ready for collection</option>
            <option value="Claimed">Claimed</option>
            <option value="Rejected">Rejected</option>
        </select>
        <select id="waitFilter" onchange="applyFilters()" aria-label="Filter by what a request is waiting on">
            <option value="">Anything</option>
            <option value="actionable">Needs the desk</option>
            <option value="blocked">Needs something first</option>
            <option value="late">Past target</option>
        </select>
        <span class="dq-count">Showing <strong id="showingCount"><?= count($requests) ?></strong> of <?= count($requests) ?></span>
    </div>

    <div class="table-responsive" style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr><th>Student</th><th>Request</th><th>Waiting on</th><th>Turnaround</th><th style="text-align:right;">Action</th></tr>
        </thead>
        <tbody>
            <?php if (empty($requests)): ?>
                <tr><td colspan="5" class="empty-state"><div class="dq-empty"><i class="fas fa-file-lines"></i><p>No document requests found</p><span>Requests appear here once a student or the registrar submits one.</span></div></td></tr>
            <?php else: foreach ($requests as $r):
                $pill = $statusPill[$r['document_status']] ?? ['filed','fa-folder-open'];
                $label = $statusLabel[$r['document_status']] ?? str_replace('_', ' ', $r['document_status']);
                $ci = $catIcon[$r['sku']] ?? ['linear-gradient(135deg,#64748b,#475569)','fa-file-lines'];
                $st = (string) $r['document_status'];
                $reqEvents = $eventsByRequest[(int) $r['id']] ?? [];
                $blocker  = $r['_blocker'];
                // The ask, as distinct from the hold. Both derive from the
                // same columns but mean different things, so they must not
                // be rendered with the same weight: a hold says this cannot
                // proceed, an ask says mention it to the student.
                $needsNote = doc_requirement_note($r);
                $age      = $r['_age'];
                $next     = doc_next_step($r);
                // Blocked days = how long the CURRENT hold has stood, which
                // is the number worth chasing — not total age, which also
                // counts days the desk spent actually working.
                $heldDays = null;
                if ($blocker && !empty($blocker['since'])) {
                    $heldDays = round((time() - (strtotime($blocker['since']) ?: time())) / 86400, 1);
                }
                // How much of the target this request has used. The bar is
                // scaled to 1.5x the target so a request that has run
                // slightly over still reads as "over", not as pinned.
                $clockPct = 100.0;
                if ($age['target'] !== null && $age['target'] > 0) {
                    $clockPct = max(4.0, min(100.0, ($age['days'] / $age['target']) / 1.5 * 100));
                }
                $settled = in_array($st, ['Claimed', 'Rejected'], true);

                // ── The process rail ────────────────────────────────
                // Position and the drawn track come from one function, so
                // the rail cannot show a station the API would disagree
                // with. `railStory` is the same information in words: four
                // coloured squares mean nothing read out linearly.
                $track     = doc_stage_track();
                $pos       = doc_stage_position($st);
                // The trailing sentence says whether the request is closed,
                // because a screen-reader user has no line to read the colour
                // off, and "Stage 4 of 4" on its own sounds like still-arriving.
                $railStory = $pos['stopped']
                    ? 'Rejected. This request left the process track.'
                    : sprintf(
                        'Stage %d of %d: %s. %s%s',
                        $pos['index'] + 1,
                        count($track),
                        $track[$pos['index']]['label'],
                        $blocker
                            ? 'Held, waiting on ' . $blocker['reason'] . '.'
                            : 'Not waiting on anything.',
                        (!$pos['stopped']
                            && $pos['index'] === count($track) - 1
                            && !$blocker)
                            ? ' Complete - the request is closed.'
                            : ''
                    );
            ?>
                <tr data-doc="<?= (int) $r['id'] ?>" data-status="<?= htmlspecialchars($st) ?>"
                    data-reqtype="<?= htmlspecialchars((string) $r['request_type']) ?>"
                    data-counter="<?= (int) ($r['counter'] ?? 1) ?>"
                    data-blocked="<?= $blocker ? '1' : '0' ?>"
                    data-late="<?= !empty($age['overdue']) ? '1' : '0' ?>"
                    data-actionable="<?= in_array($st, ['Filed', 'Pending_Clearance', 'Processing'], true) ? '1' : '0' ?>"
                    data-label="<?= htmlspecialchars($r['catalog_name'] ?? ucwords(str_replace('_', ' ', $r['document_type'])), ENT_QUOTES) ?>"
                    class="<?= $blocker ? 'is-blocked' : '' ?>"
                    onclick="toggleDetail(<?= (int) $r['id'] ?>)">
                    <td><div class="student-info"><div class="student-avatar blue"><?= htmlspecialchars(strtoupper(substr((string) $r['student_name'], 0, 1))) ?></div><div><div class="student-name"><?= htmlspecialchars((string) $r['student_name']) ?></div><div class="student-sub"><?= htmlspecialchars((string) $r['student_number']) ?></div></div></div></td>
                    <?php // Status and fee folded in here. Both are facts ABOUT
                          // the request, and giving each its own column spent
                          // horizontal space on numbers a clerk reads once,
                          // while pushing the actionable columns off screen. ?>
                    <td>
                        <div class="student-info">
                            <div class="student-avatar" style="background:<?= $ci[0] ?>;"><i class="fa-solid <?= $ci[1] ?>"></i></div>
                            <div>
                                <div class="student-name"><?= htmlspecialchars($r['catalog_name'] ?? ucwords(str_replace('_', ' ', (string) $r['document_type']))) ?></div>
                                <div class="student-sub"><i class="fa-solid fa-hashtag"></i> <?= htmlspecialchars($r['request_id'] ?? '') ?> &middot; <span class="dq-fee">&#8369;<?= number_format((float) ($r['fee_amount'] ?? 0), 2) ?></span></div>
                            </div>
                        </div>
                        <?php // A lollipop per stage, a line to the next, and
                              // the current station named. The other three stage
                              // names are not rendered: they are identical on
                              // every row, and the "Waiting on" column beside this
                              // already states the condition in words. The full
                              // track is still announced, via the screen-reader
                              // sentence below.
                              //
                              // It REPLACES the status pill rather than sitting
                              // under it: the pill said "Being prepared" and the
                              // rail says the same thing, so keeping both meant
                              // two indicators for one fact and the clerk having
                              // to check they agreed. ?>
                        <ol class="dq-rail">
                            <li class="dq-rail-sr"><?= htmlspecialchars($railStory) ?></li>
                            <?php $tallyStop = $pos['stopped']; ?>
                            <?php foreach ($track as $i => $stage):
                                $cls = '';
                                if ($tallyStop) {
                                    // Off the track entirely: nothing was
                                    // reached, so no stage is "now".
                                    $cls = 'is-stopped';
                                } elseif ($i < $pos['index']) {
                                    $cls = 'is-done';
                                } elseif ($i === $pos['index']) {
                                    // Held and current are different facts.
                                    // A hold re-inks the dot red; it does not
                                    // move it, and does not become a state.
                                    $cls = $blocker ? 'is-now is-held' : 'is-now';
                                }
                                // A request on the LAST stage is not "on" it
                                // any more, it is through it. Painting it amber
                                // left a finished request ending on the one
                                // colour on the rail that means "still being
                                // worked", with a heartbeat still pulsing, so
                                // the single most settled thing in the column
                                // was the only thing that looked live. The
                                // last stage inks green like the rest and the
                                // rail ends solid.
                                //
                                // A hold is exempt: held is a present-tense
                                // fact about work outstanding, whatever stage
                                // it lands on, so it stays red.
                                if ($cls === 'is-now' && $i === count($track) - 1) {
                                    $cls = 'is-done';
                                }
                            ?>
                                <?php // --i staggers the line-draw and the pop, so
                                      // the rail inks left to right like a hand
                                      // moving along it rather than all at once.
                                      //
                                      // aria-current keys off the POSITION, not
                                      // off is-now: the last stage is painted
                                      // is-done above, but it is still where
                                      // this request stands, and a screen
                                      // reader announcing the rail should say
                                      // so rather than skipping it. ?>
                                <li class="dq-station <?= $cls ?>" style="--i:<?= (int) $i ?>"<?= !$tallyStop && $i === $pos['index'] ? ' aria-current="step"' : '' ?>>
                                    <span class="dq-dot" aria-hidden="true"></span>
                                </li>
                            <?php endforeach; ?>
                            <?php // The single word. A rejected request has no
                                  // station, so it names the departure instead of
                                  // implying one was reached.
                                  //
                                  // The word is green once the request is
                                  // through, for the same reason the last
                                  // dot is: it sits beside that dot, and an
                                  // amber word next to a green dot would
                                  // contradict it. ?>
                            <li class="dq-stage-word <?= $tallyStop ? 'is-stopped' : ($blocker ? 'is-held' : ($pos['index'] === count($track) - 1 ? 'is-done' : 'is-now')) ?>"><?= htmlspecialchars($tallyStop ? 'Rejected' : $track[$pos['index']]['label']) ?></li>
                        </ol>
                        <?php // Hidden on narrow screens, where four dots and a
                              // word cannot stay legible together. Falls back to
                              // the plain status rather than dropping the fact.
                              //
                              // It has to carry BOTH facts, because at this width
                              // the rail's only surviving signal - position - is
                              // gone. "Filed" alone is ambiguous: it is both the
                              // name of the second station and a request sitting
                              // HELD at the second station, and those two want
                              // opposite actions from the clerk. So a held request
                              // says it is held, and a rejected one says it left
                              // the track, each in the same colour the rail used. ?>
                        <div class="dq-rail-fallback <?= $tallyStop ? 'is-stopped' : ($blocker ? 'is-held' : '') ?>">
                            <i class="fa-solid <?= $pill[1] ?>" aria-hidden="true"></i>
                            <span class="dq-rail-fb-state"><?= htmlspecialchars($tallyStop ? 'Rejected' : $track[$pos['index']]['label']) ?></span>
                            <?php if ($tallyStop): ?>
                                <span class="dq-rail-fb-note">left the track</span>
                            <?php elseif ($blocker): ?>
                                <span class="dq-rail-fb-note">held</span>
                            <?php endif; ?>
                        </div>
                        </div>
                        </div>
                    </td>
                    <!-- What this request is waiting on, and since when. The
                         column that did not exist before, and the reason a
                         three-day request stopped looking identical to a
                         three-hour one. -->
                    <td>
                        <?php $byRegistrar = ($r['blocked_source'] ?? null) === 'registrar'; ?>
                        <?php if ($blocker): ?>
                            <div class="dq-wait">
                                <div class="dq-wait-line">
                                    <i class="fa-solid fa-hourglass-half"></i>
                                    <span><?= htmlspecialchars($blocker['reason']) ?></span>
                                </div>
                                <?php if ($heldDays !== null): ?>
                                    <span class="dq-wait-since">held <?= $heldDays < 1 ? 'since this morning' : 'for ' . doc_age_label(['days' => $heldDays, 'hours' => 0]) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php // Re-check re-derives the BALANCE. On a hold a
                                  // registrar set it cannot change the answer —
                                  // the office holding the affidavit is not
                                  // something a query can resolve — so offering
                                  // the button there is a control that provably
                                  // does nothing. Replaced by a plain note
                                  // saying the hold is theirs to lift. ?>
                            <?php if ($byRegistrar): ?>
                                <?php // A decision a person made has to be
                                      // reversible by a person. This button is
                                      // the reason that is true, and it is the
                                      // control the banner below points at. ?>
                                <button class="dq-lift" onclick="event.stopPropagation();liftHold(<?= (int) $r['id'] ?>)" title="Clear the hold you set and put this back on the queue">
                                    <i class="fa-solid fa-lock-open"></i> Lift hold
                                </button>
                            <?php else: ?>
                                <button class="dq-recheck" onclick="event.stopPropagation();recheckDoc(<?= (int) $r['id'] ?>)" title="Check again whether this is still held">
                                    <i class="fa-solid fa-rotate"></i> Re-check
                                </button>
                            <?php endif; ?>
                        <?php elseif ($st === 'Ready'): ?>
                            <div class="dq-clear"><i class="fa-solid fa-check"></i> With the student</div>
                        <?php elseif (in_array($st, ['Claimed', 'Rejected'], true)): ?>
                            <div class="dq-clear"><i class="fa-solid fa-check"></i> Settled</div>
                        <?php else: ?>
                            <div class="dq-clear"><i class="fa-solid fa-circle-check"></i> Nothing — start it</div>
                        <?php endif; ?>
                        <?php // A named requirement is an ask, not a hold. It
                              // used to render above with an hourglass and a
                              // "held since" date, which asserted a decision
                              // the registrar had not made. It now reads as
                              // what it is: something to mention to the
                              // student, with the request still fully
                              // actionable. ?>
                        <?php if ($needsNote): ?>
                            <div class="dq-bring"><i class="fa-solid fa-file-import" aria-hidden="true"></i> Bring: <?= htmlspecialchars($needsNote) ?></div>
                        <?php endif; ?>
                    </td>

                    <!-- Turnaround: the request's own promise, drawn. The bar
                         is filled for time used and the tick sits at the
                         target, so "nearly late" is visible before it is
                         late - the one thing a bare date cannot show. -->
                    <td>
                        <div class="dq-clock<?= !empty($age['overdue']) && !$settled ? ' is-late' : '' ?><?= $settled ? ' is-done' : '' ?>">
                            <div class="dq-clock-bar">
                                <div class="dq-clock-fill" style="width:<?= (float) $clockPct ?>%"></div>
                                <?php if ($age['target'] !== null): ?>
                                    <div class="dq-clock-tick" style="left:66.667%"></div>
                                <?php endif; ?>
                            </div>
                            <div class="dq-clock-when">
                                <?php if ($age['target'] === null): ?>
                                    <b><?= htmlspecialchars(doc_age_label($age)) ?></b> open
                                <?php elseif (!empty($age['overdue']) && !$settled): ?>
                                    <b><?= htmlspecialchars(doc_age_label($age)) ?></b> &middot; <?= (int) $age['target'] ?>d target
                                <?php elseif ($settled): ?>
                                    done in <b><?= htmlspecialchars(doc_age_label($age)) ?></b>
                                <?php else: ?>
                                    <b><?= htmlspecialchars(doc_age_label($age)) ?></b> of <?= (int) $age['target'] ?>d
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>

                    <td><div class="row-actions">
                        <?php // The forward step, offered in full strength.
                              // Every blocker on this desk is the clerk's
                              // own to clear, so there is no state where
                              // the step is unavailable. ?>
                        <?php if ($next !== null): ?>
                            <button class="btn btn-sm btn-primary" onclick="<?= htmlspecialchars($next['handler']) ?>(<?= (int) $r['id'] ?><?= ($next['wants'] ?? '') === 'btn' ? ', this' : '' ?>);event.stopPropagation()"><i class="fa-solid <?= $next['icon'] ?>"></i> <?= htmlspecialchars($next['label']) ?></button>
                            <?php if ($next['action'] !== 'claim'): ?>
                                <button class="btn btn-sm btn-danger" onclick="rejectDoc(<?= (int) $r['id'] ?>);event.stopPropagation()"><i class="fa-solid fa-xmark"></i> Reject</button>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php // View is available on EVERY row, not only the
                              // settled ones. It used to render only when there
                              // was no next step, so on a working desk — where
                              // nothing is collected yet — it never appeared at
                              // all, and the only way to see a request's detail
                              // was to guess that the row itself was clickable.
                              // A real <button>, so it is keyboard reachable
                              // and announces itself, where a bare span is
                              // neither. ?>
                        <button class="btn btn-sm btn-light dq-view" data-doc="<?= (int) $r['id'] ?>" onclick="toggleDetail(<?= (int) $r['id'] ?>);event.stopPropagation()" aria-expanded="false" aria-controls="detail-<?= (int) $r['id'] ?>">
                            <i class="fa-solid fa-chevron-down"></i> View
                        </button>
                    </div></td>
                </tr>

                <tr class="doc-detail-row" id="detail-<?= (int) $r['id'] ?>" style="display:none;">
                    <td colspan="5" style="padding:0;">
                        <div class="doc-detail" style="padding:18px 22px;border-bottom:1px solid #e2e8f0;">
                            <?php if ($st === 'Rejected'): ?>
                                <div class="block-banner" style="margin-bottom:12px;">
                                    <div class="banner-icon"><i class="fa-solid fa-xmark"></i></div>
                                    <div><div class="banner-title">Request rejected</div><div class="banner-text"><?= htmlspecialchars($r['rejection_reason'] ?? 'No reason provided.') ?></div></div>
                                </div>
                            <?php endif; ?>
                            <?php if ($blocker): ?>
                                <?php // Say whose call this is. A balance is
                                      // something the system worked out and
                                      // anyone can clear by paying; a note the
                                      // registrar typed is a decision, and
                                      // clicking Re-check will NOT remove it.
                                      // Without this line the two look identical
                                      // and the next clerk wastes a morning
                                      // re-checking a hold that was never going
                                      // to move. ?>
                                <div class="block-banner is-hold" style="margin-bottom:12px;">
                                    <div class="banner-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                                    <div>
                                        <div class="banner-title">Waiting on <?= htmlspecialchars($blocker['reason']) ?></div>
                                        <div class="banner-text">
                                            Held since <?= date('M d, Y', strtotime((string) $blocker['since'])) ?>.
                                            <?php if (($r['blocked_source'] ?? null) === 'registrar'): ?>
                                                Set by the registrar, so re-checking will not clear it. Use <b>Lift hold</b> on the row once the reason has passed.
                                            <?php else: ?>
                                                Derived from the student&rsquo;s balance. It clears itself once the balance is settled.
                                            <?php endif; ?>
                                        </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="dq-fields">
                                <div><strong>Purpose:</strong> <?= htmlspecialchars($r['purpose'] ?: '—') ?></div>
                                <?php // Recipient is gone from the desk's vocabulary:
                                      // a walk-in document is always collected by
                                      // the student it was filed for, so the field
                                      // had one possible answer. The column is
                                      // still in the schema because student intake
                                      // writes it; the desk no longer displays
                                      // or asks for it. ?>
                                <div><strong>Qty:</strong> <?= (int) ($r['quantity'] ?? 1) ?></div>
                                <div><strong>Payment:</strong> Paid at the counter</div>
                                <div><strong>Target:</strong> <?= $age['target'] !== null ? (int) $age['target'] . ' day' . ($age['target'] === 1 ? '' : 's') : '—' ?></div>
                                <div><strong>Requested:</strong> <?= date('M d, Y h:i A', strtotime($r['request_date'])) ?></div>
                                <?php // What to ask the student for. An ask, not a
                                      // hold: the registrar decides whether this
                                      // is worth chasing, and the request stays
                                      // workable either way. ?>
                                <?php if ($needsNote): ?><div><strong>Ask for:</strong> <?= htmlspecialchars($needsNote) ?></div><?php endif; ?>
                                <?php if (!empty($r['paid_at'])): ?><div><strong>Paid:</strong> <?= date('M d, Y h:i A', strtotime($r['paid_at'])) ?></div><?php endif; ?>
                                <?php if (!empty($r['ready_at'])): ?><div><strong>Ready:</strong> <?= date('M d, Y h:i A', strtotime($r['ready_at'])) ?></div><?php endif; ?>
                                <?php if (!empty($r['claimed_at'])): ?><div><strong>Claimed:</strong> <?= date('M d, Y h:i A', strtotime($r['claimed_at'])) ?><?= !empty($r['official_receipt']) ? ' &middot; Receipt ' . htmlspecialchars($r['official_receipt']) : '' ?></div><?php endif; ?>
                                <?php if (!empty($r['release_date'])): ?><div><strong>Release Date:</strong> <?= htmlspecialchars($r['release_date']) ?></div><?php endif; ?>
                            </div>
                            <?php // The document itself. This was missing
                                  // entirely: the detail panel described the
                                  // request, but nothing on the desk ever
                                  // linked to the rendered document, so a clerk
                                  // had no way to check what they were about
                                  // to sign off on. api/document-preview.php
                                  // already renders the real thing — it was
                                  // simply unreachable from the page. Rejected
                                  // rows are excluded: there is nothing to sign.
                                  //
                                  // One button, not two. Print used to sit
                                  // beside Preview and opened a separate
                                  // window, so the clerk approved one render
                                  // and printed a second — a different code
                                  // path, which is how the two drifted apart.
                                  // Printing from inside the preview prints the
                                  // sheet the clerk has already read.
                                  ?>
                            <?php if ($st !== 'Rejected'): ?>
                            <div class="dq-detail-actions">
                                <!-- Primary, not light. The row this sits in is
                                     mostly grey-on-white, so a white button was
                                     the least visible control in the panel and
                                     the one a clerk most needs: it is the only
                                     way to see what they are signing off on.
                                     Same blue as Print in the preview footer. -->
                                <button class="btn btn-sm btn-primary" onclick="openPreview(<?= (int) $r['id'] ?>);event.stopPropagation()">
                                    <i class="fa-solid fa-eye"></i> Preview &amp; print
                                </button>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($reqEvents)): ?>
                                <div class="dq-log">
                                    <div class="dq-log-head">Activity Log</div>
                                    <?php foreach ($reqEvents as $ev): ?>
                                        <div class="dq-log-row">
                                            <time datetime="<?= htmlspecialchars(date('c', strtotime($ev['created_at']))) ?>"><?= date('M d, h:i A', strtotime($ev['created_at'])) ?></time>
                                            <span><?= htmlspecialchars(doc_readable_event_note($ev)) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            <tr id="docNoMatch" style="display:none;"><td colspan="5" class="empty-state"><div class="dq-empty"><i class="fas fa-magnifying-glass"></i><p>No requests match your search</p><span>Try a different name, ID number, document, or clear the filters.</span></div></td></tr>
        </tbody>
    </table>
    </div>
</div>

</div>
</main>

<!-- ═══ NEW REQUEST MODAL ═══════════════════════════════════════ -->
<div class="modal-overlay" id="newRequestModal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="nq-mark"><i class="fa-solid fa-file-circle-plus"></i></div>
            <div>
                <h3>New Document Request</h3>
                <p>Raise a request on behalf of a student.</p>
            </div>
            <button class="modal-close" onclick="closeModal('newRequestModal')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form id="newRequestForm" onsubmit="submitNewRequest(event)">

                <!-- The form is a sequence, so it is numbered. The clerk
                     works top to bottom under a queue of other students, and
                     the numbers say which field comes next without making
                     them read four uppercase headings. -->
                <ol class="nq-steps">
                    <li class="nq-step">
                        <div class="nq-step-mark" aria-hidden="true">1</div>
                        <div class="nq-step-body">
                            <div class="nq-step-label" id="nrStudentLabel">Student</div>
                            <select name="student_id" id="nrStudent" class="form-control" data-searchable required aria-labelledby="nrStudentLabel">
                                <option value="">Search or select a student&hellip;</option>
                                <?php foreach ($students as $s): ?>
                                    <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['student_number']) ?> &mdash; <?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </li>

                    <li class="nq-step">
                        <div class="nq-step-mark" aria-hidden="true">2</div>
                        <div class="nq-step-body">
                            <div class="nq-step-label" id="nrDocLabel">Document</div>
                            <select name="catalog_id" id="nrCatalog" class="form-control" required onchange="updateNrFee()" aria-labelledby="nrDocLabel">
                                <option value="">Select a document&hellip;</option>
                                <?php foreach ($catalog as $c):
                                    // The enum already carries its own preposition
                                    // ("per_page"), so appending " per " to the
                                    // de-underscored value printed "per per page" in
                                    // the clerk's dropdown. Name the unit instead,
                                    // matching how the same fee is worded elsewhere
                                    // on this page.
                                    $unit = match ($c['fee_type']) {
                                        'per_page'     => 'page',
                                        'per_syllabus' => 'syllabus',
                                        default        => '',
                                    };
                                    $feeTxt = '&#8369;' . number_format((float) $c['base_fee'], 2)
                                        . ($unit !== '' ? ' per ' . $unit : ''); ?>
                                    <option value="<?= (int) $c['id'] ?>"
                                        data-fee="<?= (float) $c['base_fee'] ?>"
                                        data-fee-type="<?= htmlspecialchars($c['fee_type']) ?>"
                                        data-req="<?= htmlspecialchars((string) $c['requirement'], ENT_QUOTES) ?>">
                                        <?= htmlspecialchars($c['name']) ?> (<?= $feeTxt ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <!-- A requirement is a fact about the document, not
                                 an error. It appears under the field it belongs
                                 to, in the amber the rest of the app uses for
                                 "attention", and it never blocks the form. -->
                            <div id="nrHint" class="req-hint" role="status"></div>
                        </div>
                    </li>

                    <li class="nq-step">
                        <div class="nq-step-mark" aria-hidden="true">3</div>
                        <div class="nq-step-body">
                            <div class="nq-step-label" id="nrDetailLabel">Why it is needed</div>
                            <div class="nq-fields">
                                <div class="nq-field">
                                    <label for="nrPurpose">Purpose<span class="nq-req">*</span></label>
                                    <input type="text" name="purpose" id="nrPurpose" class="form-control" placeholder="Employment requirement" required>
                                </div>
                                <div class="nq-field">
                                    <?php // Recipient used to sit here. A walk-in
                                          // document is always collected by the
                                          // student it was filed for — the office
                                          // runs no courier — so the field asked a
                                          // question with exactly one possible
                                          // answer, and its presence implied a
                                          // delivery option that does not exist.
                                          // The column stays in the schema
                                          // (student intake still writes it); what
                                          // is gone is the desk asking.
                                          //
                                          // Its slot is taken by the one thing
                                          // this form could not record: what the
                                          // clerk knows that the system cannot
                                          // derive. Left empty the request is
                                          // simply actionable, which is the right
                                          // default. ?>
                                    <label for="nrWaitingOn">Waiting on</label>
                                    <input type="text" name="waiting_on" id="nrWaitingOn" class="form-control"
                                           list="nrWaitingOnPresets" maxlength="160"
                                           placeholder="Nothing &mdash; ready to start">
                                    <?php // Offered, not forced. These are reasons
                                          // this desk actually sees; the field stays
                                          // free text because the real ones are
                                          // specific ("Dean's office has the
                                          // affidavit") and a fixed list would
                                          // force an imprecise pick. ?>
                                    <datalist id="nrWaitingOnPresets">
                                        <option value="Dean&rsquo;s office approval"></option>
                                        <option value="Outstanding balance"></option>
                                        <option value="Student ID being reprinted"></option>
                                        <option value="Guidance Office signature"></option>
                                        <option value="Original document not yet returned"></option>
                                    </datalist>
                                    <p class="nq-hint">Optional. Fill this in only if the desk genuinely cannot start. The request still works &mdash; this records why, and you can change or clear it later.</p>
                                </div>
                            </div>
                        </div>
                    </li>
                </ol>

                <!-- Pickup was never a choice the clerk made - the field was
                     readonly and the API only accepts Pickup. It sat in its own
                     "Handling" section as a disabled-looking box, which read as
                     a setting that had gone wrong. It is now a single stated
                     fact, in the same position as the fee: things that are
                     true about this request rather than things to fill in. -->
                <div class="nq-facts">
                    <div class="nq-fee">
                        <div>
                            <div class="nq-fee-cap">Fee due at filing</div>
                            <div class="nq-fee-note" id="nrFeePreview">&#8369;0.00 &middot; take this when the student pays</div>
                        </div>
                        <div class="nq-fee-amount" id="nrFeeAmount">&#8369;0.00</div>
                    </div>
                    <p class="nq-fact"><i class="fa-solid fa-building-columns" aria-hidden="true"></i> Collected at the registrar counter</p>
                </div>

                <!-- Priority is sent as a fixed value rather than offered as a
                     choice. The API validates request_type, so the field has to
                     exist, but a single-option control invites the clerk to
                     wonder what the other option did. -->
                <input type="hidden" name="request_type" id="nrPriority" value="Regular">

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('newRequestModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="nrSubmitBtn"><i class="fa-solid fa-plus"></i> Add Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ SIGN & MARK READY MODAL ═════════════════════════════════ -->
<div class="modal-overlay" id="approveReleaseModal">
    <div class="modal-content" style="max-width:420px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-stamp"></i> Sign &amp; mark ready</h3>
            <button class="modal-close" onclick="closeModal('approveReleaseModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="arId">
            <p style="font-size:13px;color:#475569;margin-bottom:12px;">
                Sign the document and set it aside for the student to claim.
            </p>
            <div class="form-group">
                <label>Ready on <span style="color:#dc2626;">*</span></label>
                <input type="date" id="arReleaseDate" class="form-control" required>
            </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid #e2e8f0;">
            <button class="btn btn-secondary" onclick="closeModal('approveReleaseModal')">Cancel</button>
            <button class="btn btn-primary" id="arSubmitBtn" onclick="submitApproveRelease()"><i class="fa-solid fa-stamp"></i> Mark ready</button>
        </div>
    </div>
</div>

<!-- ═══ REJECT MODAL ═════════════════════════════════════════════ -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal-content" style="max-width:420px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-ban"></i> Reject Request</h3>
            <button class="modal-close" onclick="closeModal('rejectModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="rejectId">
            <p style="font-size:13px;color:#475569;margin-bottom:12px;">Rejecting: <strong id="rejectLabel"></strong></p>
            <div class="form-group">
                <label>Rejection Reason <span style="color:#dc2626;">*</span></label>
                <textarea id="rejectReason" class="form-control" rows="3" placeholder="Enter reason for rejection…"></textarea>
            </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid #e2e8f0;">
            <button class="btn btn-secondary" onclick="closeModal('rejectModal')">Cancel</button>
            <button class="btn btn-danger" id="rejectSubmit" onclick="submitReject()"><i class="fa-solid fa-ban"></i> Reject</button>
        </div>
    </div>
</div>

<!-- ═══ DOCUMENT PREVIEW ═════════════════════════════════════════ -->
<!-- The document as the student will receive it, rendered by
     api/document-preview.php from shared/document_templates.php — the
     same source the print window uses, so what is approved on screen is
     what comes off the printer. -->
<div class="modal-overlay" id="docPreviewModal">
    <div class="modal-content" id="docPreviewShell">
        <div class="modal-header">
            <h3><i class="fa-solid fa-file-lines"></i> <span id="docPreviewTitle">Document preview</span></h3>
            <button class="modal-close" onclick="closeModal('docPreviewModal')" aria-label="Close preview"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="docPreviewBody">
            <div class="dq-preview-loading"><i class="fa-solid fa-spinner fa-spin"></i> Rendering the document…</div>
        </div>
        <div class="modal-footer" style="border-top:1px solid #e2e8f0;">
            <button class="btn btn-secondary" onclick="closeModal('docPreviewModal')">Close</button>
            <button class="btn btn-primary" onclick="printDoc(docPreviewId)"><i class="fa-solid fa-print"></i> Print</button>
        </div>
    </div>
</div>

<script>
// The API base, resolved by the server. The desk lives at /registrar/,
// so a bare relative "api/..." resolves to /registrar/api/... and 404s —
// the same reason every other call here writes '../api/...'. Emitted by
// the server rather than hardcoded so the page keeps working wherever
// the app is deployed.
const DOC_API = <?= json_encode(app_url('/api')) ?>;
// ── Helpers ────────────────────────────────────────────────────

// The request currently open in the preview, so the footer's Print
// button knows what to print without re-reading the DOM.
let docPreviewId = 0;

function openModal(id) { const m = document.getElementById(id); if (m) { m.classList.add('active'); document.body.style.overflow = 'hidden'; } }
function closeModal(id) { const m = document.getElementById(id); if (m) { m.classList.remove('active'); document.body.style.overflow = ''; } }
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) { m.classList.remove('active'); document.body.style.overflow = ''; } });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.active').forEach(m => { m.classList.remove('active'); document.body.style.overflow = ''; });
});

function rowLabel(id) {
    const tr = document.querySelector('tr[data-doc="' + id + '"]');
    return tr ? (tr.dataset.label || 'Request #' + id) : 'Request #' + id;
}

async function putDoc(id, action, extra) {
    const body = Object.assign({ action: action }, extra || {});
    const res = await fetch('../api/documents.php?id=' + id, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
    return res.json();
}

// ── New Request Modal ──────────────────────────────────────────
// The three steps, in the order they are filled. Completion drives the
// numerals, so the clerk can see at a glance whether the request is
// finished or whether the tail of the form is still empty.
function paintNrSteps() {
    const filled = [
        !!document.getElementById('nrStudent').value,
        !!document.getElementById('nrCatalog').value,
        !!document.getElementById('nrPurpose').value.trim()
    ];
    const firstOpen = filled.indexOf(false);
    document.querySelectorAll('#newRequestModal .nq-step').forEach((s, i) => {
        s.classList.toggle('is-done', filled[i]);
        // "active" is the first step still empty, or the last one once
        // they are all answered - so the highlight never sits on a
        // field the clerk has already dealt with.
        s.classList.toggle('is-active', i === (firstOpen === -1 ? 2 : firstOpen));
    });
}

// The steps repaint as the clerk answers them, so the numerals are a
// progress read-out rather than decoration. Bound here rather than with
// inline oninput= so the markup stays free of behaviour.
['nrStudent', 'nrCatalog', 'nrPurpose'].forEach(function (id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', paintNrSteps);
    el.addEventListener('change', paintNrSteps);
});

function openNewRequest() {
    document.getElementById('newRequestForm').reset();
    document.getElementById('nrHint').textContent = '';
    document.getElementById('nrHint').classList.remove('visible');
    document.getElementById('nrFeeAmount').textContent = '\u20B10.00';
    document.getElementById('nrFeePreview').textContent = '\u20B10.00 \u00B7 take this when the student pays';
    paintNrSteps();
    openModal('newRequestModal');
}

function peso(n) {
    return '\u20B1' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function updateNrFee() {
    const opt = document.getElementById('nrCatalog').selectedOptions[0];
    // The amount and its note are separate elements now: the note carries
    // the unit ("per page"), which the old single field could not show
    // without pushing the figure out of the ticket.
    const note = document.getElementById('nrFeePreview');
    const amount = document.getElementById('nrFeeAmount');
    if (!opt || !opt.value) {
        amount.textContent = peso(0);
        note.textContent = 'Choose a document to see the fee';
        document.getElementById('nrHint').classList.remove('visible');
        paintNrSteps();
        return;
    }
    const fee = parseFloat(opt.dataset.fee || '0');
    const unit = opt.dataset.feeType === 'per_page' ? 'page'
               : opt.dataset.feeType === 'per_syllabus' ? 'syllabus' : '';
    amount.textContent = peso(fee);
    amount.dataset.unit = unit ? 'per ' + unit : '';
    note.textContent = unit
        ? 'per ' + unit + ' \u00B7 take this when the student pays'
        : 'take this when the student pays';
    const req = opt.dataset.req;
    if (req) {
        document.getElementById('nrHint').textContent = req;
        document.getElementById('nrHint').classList.add('visible');
    } else {
        document.getElementById('nrHint').classList.remove('visible');
    }
    paintNrSteps();
}

async function submitNewRequest(e) {
    e.preventDefault();
    const btn = document.getElementById('nrSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding\u2026';
    const data = {
        student_id: document.getElementById('nrStudent').value,
        catalog_id: document.getElementById('nrCatalog').value,
        request_type: document.getElementById('nrPriority').value,
        fulfillment_type: 'Pickup',
        purpose: document.getElementById('nrPurpose').value.trim(),
        // Empty means "nothing is holding this", which is the default and
        // the common case. Sent as an empty string rather than omitted so
        // the intent is explicit at the API.
        waiting_on: document.getElementById('nrWaitingOn').value.trim()
    };
    if (!data.student_id || !data.catalog_id || !data.purpose) {
        showToast('Please fill all required fields.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-plus"></i> Add Request';
        return;
    }
    try {
        const res = await fetch('../api/student-documents.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await res.json();
        if (result.success) {
            closeModal('newRequestModal');
            showToast(result.message || 'Document request created.', 'success');
            setTimeout(() => location.reload(), 600);
        } else {
            showToast(result.message || 'Failed to create request.', 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-plus"></i> Add Request';
    }
}

// ── Process ────────────────────────────────────────────────────
//
// The first step of every request, so it is the one that must never
// misfire. Two things were wrong with it before:
//
//  1. It called native confirm(). A browser dialog cannot be styled,
//     blocks the page, and reads as an error state — the clerk's
//     first instinct is that the button is broken. The other two
//     actions use proper modals, so this one was inconsistent too.
//  2. Nothing stopped a double click. The request is fetched, the
//     status moves, the row is stale until reload — and a second
//     click in that window sends a second transition, which the API
//     rejects with a message the clerk never sees because the page
//     reloads underneath them.
//
// So: the button disables itself and says what it is doing, and a
// failure puts the row back exactly as it was. No dialog at all —
// starting to prepare a document is reversible, unlike signing it
// or handing it over, and those two still ask.
async function processDoc(id, btn) {
    if (btn && btn.disabled) return;
    const label = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Starting…';
    }
    try {
        const d = await putDoc(id, 'process');
        if (d.success) {
            showToast('Set to being prepared.', 'success');
            location.reload();
            return;
        }
        showToast(d.message || 'Could not start this request.', 'error');
    } catch (e) {
        showToast('Network error. Nothing was changed.', 'error');
    }
    // Only reached on failure — on success the page is reloading, so
    // restoring the button would only be seen for a frame.
    if (btn) { btn.disabled = false; btn.innerHTML = label; }
}

// ── Approve & Release ─────────────────────────────────────────
function approveRelease(id) {
    document.getElementById('arId').value = id;
    document.getElementById('arReleaseDate').value = '';
    openModal('approveReleaseModal');
}

async function submitApproveRelease() {
    const id = document.getElementById('arId').value;
    const releaseDate = document.getElementById('arReleaseDate').value;
    if (!releaseDate) { showToast('Please select the date the document is ready.', 'error'); return; }
    const btn = document.getElementById('arSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving\u2026';
    try {
        const d = await putDoc(id, 'ready', { approval_reason: 'Signed by registrar', release_date: releaseDate });
        if (d.success) {
            closeModal('approveReleaseModal');
            showToast('Document signed and ready to claim.', 'success');
            setTimeout(() => location.reload(), 600);
        } else {
            showToast(d.message || 'Action failed.', 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-stamp"></i> Mark ready';
    }
}

// ── Claim ────────────────────────────────────────────────────
// This one must ask. Unlike "start preparing", handing a document
// over takes payment and cannot be undone from the desk, so the clerk
// needs a deliberate yes.
//
// It used to call native confirm(), which the browser draws itself:
// unstyled, titled "localhost says", blocking the whole tab. It read
// as a failure rather than a question, and the clerk's first
// instinct was that the button was broken.
async function claimDoc(id, btn) {
    if (btn && btn.disabled) return;
    const btnLabel = btn ? btn.innerHTML : '';
    const docName = rowLabel(id);
    const ok = await confirmAction({
        title: 'Claim document',
        body: 'Hand <strong>' + escText(docName) + '</strong> to the student? ' +
              'This closes the request and cannot be undone from the desk.',
        confirmLabel: 'Claim & close',
        tone: 'primary'
    });
    if (!ok) return;

    // Same double-click guard as processDoc: the status moves on the
    // server but the row stays stale until reload, so a second click
    // in that window would send a second transition.
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Claiming…';
    }
    putDoc(id, 'claim').then(d => {
        if (d.success) { showToast('Document claimed.', 'success'); location.reload(); return; }
        showToast(d.message || 'Action failed.', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = btnLabel; }
    }).catch(() => {
        showToast('Network error. Nothing was changed.', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = btnLabel; }
    });
}

/* Re-derive whether this request is still held.

   The clearance and balance gates used to be decided once, when the
   request was filed, and never looked at again — so a student who
   paid the next morning stayed blocked forever with nothing on screen
   to say so. This asks the question again on demand. */
async function recheckDoc(id) {
    const btn = document.querySelector(`tr[data-doc="${id}"] .dq-recheck`);
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking'; }
    try {
        const d = await putDoc(id, 'recheck');
        showToast(d.message || 'Checked.', d.success ? 'success' : 'error');
        // Reload either way: a release changes the status pill, the
        // waiting column and the action button all at once.
        setTimeout(() => location.reload(), 700);
    } catch (err) {
        showToast('Could not re-check. Is the server reachable?', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-rotate"></i> Re-check'; }
    }
}

    // Lift a hold the registrar set by hand.
    //
    // Confirms first, via the house confirmAction() modal rather than a
    // native confirm(). The desk's own Collect confirmation was the reason
    // that component exists: an OS-drawn dialog looks like an error state
    // and it was mistaken for a broken button. This is the same class of
    // mistake, so it uses the same fix.
    async function liftHold(id) {
        const ok = await confirmAction({
            title: 'Lift this hold?',
            body: 'This request goes straight back into your queue. The lift is ' +
                  'recorded in the log with your name.',
            confirmLabel: 'Lift hold'
        });
        if (!ok) return;
        const btn = document.querySelector(`tr[data-doc="${id}"] .dq-lift`);
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Lifting'; }
        try {
            const d = await putDoc(id, 'lift_hold');
            showToast(d.message || 'Hold lifted.', d.success ? 'success' : 'error');
            // Reload either way: lifting changes the hold, the amber rail,
            // the waiting column and the "Needs something first" count.
            setTimeout(() => location.reload(), 700);
        } catch (err) {
            showToast('Could not lift the hold. Is the server reachable?', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-lock-open"></i> Lift hold'; }
        }
    }

// ── Document preview ────────────────────────────────────────────
// The endpoint returns a full HTML document, so it is loaded into an
// iframe rather than injected. Injecting it would run the preview's own
// markup inside the desk, and the desk's CSP forbids framing anyway
// (frame-ancestors 'none'), so an iframe is the only shape that works
// under our own headers. Same-origin, and the endpoint checks the
// session itself — an iframe cannot be tricked into showing a document
// the clerk could not already fetch.
function openPreview(id) {
    docPreviewId = id;
    const shell  = document.getElementById('docPreviewShell');
    const body   = document.getElementById('docPreviewBody');
    const title  = document.getElementById('docPreviewTitle');
    const label  = rowLabel(id);
    if (title && label) title.textContent = label;
    openModal('docPreviewModal');
    if (shell) shell.classList.add('dq-preview-shell');
    if (body) body.innerHTML = '<div class="dq-preview-loading"><i class="fa-solid fa-spinner fa-spin"></i> Rendering the document…</div>';

    // Rebuild rather than reuse, so re-opening the same request always
    // re-fetches instead of flashing a cached render.
    const frame = document.createElement('iframe');
    frame.className = 'dq-preview-frame';
    frame.title = 'Document preview';
    frame.setAttribute('src', DOC_API + '/document-preview.php?id=' + encodeURIComponent(id));
    frame.addEventListener('load', () => {
        const d = frame.contentDocument;
        if (!d) return;
        // Centre the sheet on a mat. The template already caps the page
        // width for screen (@media screen .dt-doc), so nothing here may
        // restyle the document itself — the first version of this forced
        // width/background onto the sheet and fought the template, which
        // is what made the preview look disorganised. Only the body
        // around the sheet is touched.
        //
        // The mat is a SCREEN decoration and must never reach the paper.
        // It used to be assigned as inline styles on the iframe's body,
        // which printDoc() then faithfully printed: a grey background, body
        // padding and a drop shadow around the sheet, so the printed page
        // looked like a screenshot pasted onto paper rather than a document
        // the registrar had typed. A stylesheet rule cannot undo an inline
        // style without !important, so rather than escalate to !important
        // and fight specificity, the mat is injected as a @media screen
        // block. Print then has nothing to override and the sheet prints
        // bare, exactly as dt_standalone_html() renders it.
        const sheet = d.querySelector('.dt-doc');
        if (sheet) {
            const style = d.createElement('style');
            style.textContent =
                '@media screen{' +
                'html{background:#e2e8f0}' +
                'body{background:#e2e8f0;margin:0;display:flex;' +
                'justify-content:center;align-items:flex-start;' +
                'min-height:100%;padding:20px 16px 32px}' +
                '.dt-doc{box-shadow:0 6px 24px rgba(15,23,42,.16)}' +
                '}';
            d.head.appendChild(style);
        } else {
            // No sheet wrapper (an unexpected template): fall back to
            // filling the frame rather than leaving a blank mat.
            const fallback = d.createElement('style');
            fallback.textContent = '@media screen{html{height:100%}}';
            d.head.appendChild(fallback);
        }
    });
    if (body) { body.innerHTML = ''; body.appendChild(frame); }
}

// Print the document already loaded in the preview iframe.
//
// This used to call window.open(…'&print=1'), which opened a NEW TAB on
// every print. The document is already on screen, so that was pure
// friction: the clerk read it in one tab and then had to find and close
// another. Calling print() on the iframe's own window prints exactly
// the same document, in place.
//
// The iframe is same-origin (the endpoint checks the session itself), so
// reaching into its contentWindow is permitted. If it is ever not — a
// cross-origin response, a browser quirk — fall back to the standalone
// view rather than failing silently.
function printDoc(id) {
    const frame = document.querySelector('.dq-preview-frame');
    if (frame) {
        try {
            const w = frame.contentWindow;
            if (w && typeof w.print === 'function') {
                w.focus();
                w.print();
                return;
            }
        } catch (e) {
            // Fall through to the standalone window below.
        }
    }
    // No preview open (or it is unreachable): print it directly.
    if (!id) { showToast('Open a document first.', 'error'); return; }
    const w = window.open(DOC_API + '/document-preview.php?id=' + encodeURIComponent(id) + '&print=1', '_blank');
    if (!w) { showToast('Allow pop-ups to print this document.', 'error'); return; }
    w.addEventListener('load', () => {
        try { w.focus(); w.print(); } catch (e) { /* the user can still print manually */ }
    });
}

// ── Reject ─────────────────────────────────────────────────────
function rejectDoc(id) {
    document.getElementById('rejectId').value = id;
    document.getElementById('rejectLabel').textContent = rowLabel(id);
    document.getElementById('rejectReason').value = '';
    openModal('rejectModal');
}

async function submitReject() {
    const id = document.getElementById('rejectId').value;
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) { showToast('Please enter a rejection reason.', 'error'); return; }
    const btn = document.getElementById('rejectSubmit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Rejecting\u2026';
    try {
        const d = await putDoc(id, 'reject', { rejection_reason: reason });
        if (d.success) { showToast('Request rejected.', 'success'); location.reload(); }
        else showToast(d.message || 'Action failed.', 'error');
    } catch (err) {
        showToast('Network error.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-ban"></i> Reject';
    }
}

// ── Detail Row Toggle ──────────────────────────────────────────
// `open` here means "the row is currently open", so that aria-expanded
// and the chevron can report the pre-toggle state. It was previously
// the other way round: the detail row ships with display:none, so the
// check matched the closed state, the flag was then used as "should I
// close it", and every click closed the row that was already closed.
//
// The buttons in this cell MUST end their own handler with
// event.stopPropagation(). The row itself is clickable and calls this
// same function, so a click on View that also bubbles up fires the
// handler twice: the row opens and closes within the same tick and
// ends exactly where it started. A stopPropagation on a wrapper
// element is no defence — a wrapper is a DESCENDANT of the row, so it
// runs first and the row's handler still runs. Only stopping it at the
// button prevents the second call. tests/process_check.php clicks the
// live page and counts invocations, so this cannot come back quietly.
function toggleDetail(id) {
    const row = document.getElementById('detail-' + id);
    if (!row) return;
    const isOpen = row.style.display !== 'none';
    row.style.display = isOpen ? 'none' : '';
    // The button is the only control that reports whether the row is
    // open, and a screen-reader user has no row to click.
    document.querySelectorAll('.dq-view[data-doc="' + id + '"]').forEach(b => {
        b.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        const icon = b.querySelector('i');
        if (icon) icon.className = 'fa-solid ' + (isOpen ? 'fa-chevron-down' : 'fa-chevron-up');
    });
}

// ── Search + Filters ───────────────────────────────────────────
function applyFilters() {
    const q = (document.getElementById('docSearch').value || '').trim().toLowerCase();
    const st = document.getElementById('statusFilter').value;
    const rt = document.getElementById('typeFilter').value;
    const wf = document.getElementById('waitFilter').value;
    let visible = 0;
    document.querySelectorAll('table tbody tr[data-doc]').forEach(tr => {
        const text = tr.textContent.toLowerCase();
        const matchQ = !q || text.includes(q);
        const matchS = !st || tr.dataset.status === st;
        const matchR = !rt || tr.dataset.reqtype === rt;
        // The waiting filter is the one a clerk actually reaches for:
        // "show me only what is not moving" is a question the status
        // dropdown cannot answer, because blocking is not a status.
        const matchW = !wf
            || (wf === 'actionable' && tr.dataset.actionable === '1')
            || (wf === 'blocked'     && tr.dataset.blocked === '1')
            || (wf === 'late'        && tr.dataset.late === '1');
        const show = matchQ && matchS && matchR && matchW;
        tr.style.display = show ? '' : 'none';
        const detail = document.getElementById('detail-' + tr.dataset.doc);
        if (detail) detail.style.display = 'none';
        if (show) visible++;
    });
    // Say so plainly when nothing matched, instead of leaving a blank table.
    const noMatch = document.getElementById('docNoMatch');
    if (noMatch) noMatch.style.display = visible === 0 ? '' : 'none';
    document.getElementById('showingCount').textContent = visible;
}
document.getElementById('docSearch').addEventListener('input', applyFilters);
applyFilters();

// ── Revenue Date Filter ────────────────────────────────────────
function applyRevFilter() {
    const from = document.getElementById('revFrom').value;
    const to = document.getElementById('revTo').value;
    window.location.href = 'documents.php?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
}
</script>

<?php include '../includes/footer.php'; ?>








