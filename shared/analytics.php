<?php
// ============================================================
//  SHARED/ANALYTICS.PHP
//  Intelligent Analytics and Reports — data layer.
//
//  Single source of truth for:
//    * the 4 KPI cards        (aiInsightBuild()['cards'])
//    * the 4 charts           (aiInsightBuild()['charts'])
//    * the AI fact sheet      (aiInsightBuild()['facts'])
//
//  The dashboard (ai/insights.php), the data endpoint
//  (api/ai-insights-data.php) and the AI report endpoint
//  (api/ai-insights-report.php) all read from here, so the charts
//  and the AI narrative can never disagree.
//
//  Aggregates only — no student name or student number ever leaves
//  this file, which is what keeps the AI prompt privacy-safe.
// ============================================================

require_once __DIR__ . '/database.php';
// This file calls studentStatuses() / studentStatusLabel() from
// shared/functions.php (the status buckets below), and it is included directly
// by three callers - ai/insights.php, api/ai-insights-data.php and
// api/ai-insights-report.php - two of which do not load functions.php
// themselves. Requiring it here rather than trusting every caller to have done
// it is what stops the whole insights page dying with "Call to undefined
// function studentStatuses()" the moment someone adds a fourth caller and
// forgets.
require_once __DIR__ . '/functions.php';

if (defined('ANALYTICS_LOADED')) {
    return;
}
define('ANALYTICS_LOADED', true);

// ─── Access ──────────────────────────────────────────────────
/**
 * Roles allowed to see registrar analytics. Mirrors the sidebar
 * ("AI Tools" group is hidden for student / nurse accounts).
 */
function aiInsightRoles(): array {
    return ['admin', 'registrar', 'staff'];
}

// ─── Reporting period ────────────────────────────────────────
/**
 * Resolve a month/year pair into concrete date windows plus the
 * previous period, which powers every "vs last month" comparison.
 *
 * @return array{month:int,year:int,label:string,short_label:string,month_name:string,
 *               start:string,end:string,prev_start:string,prev_end:string,prev_label:string,prev_short:string}
 */
function aiInsightPeriod(int $month, int $year): array {
    if ($month < 1 || $month > 12) $month = (int) date('n');
    if ($year < 2000 || $year > 2100) $year = (int) date('Y');

    $start     = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $end       = date('Y-m-01 00:00:00', strtotime($start . ' +1 month'));
    $prevStart = date('Y-m-01 00:00:00', strtotime($start . ' -1 month'));

    return [
        'month'       => $month,
        'year'        => $year,
        'label'       => date('F Y', strtotime($start)),
        'short_label' => date('M Y', strtotime($start)),
        'month_name'  => date('F', strtotime($start)),
        'start'       => $start,
        'end'         => $end,
        'prev_start'  => $prevStart,
        'prev_end'    => $start,
        'prev_label'  => date('F Y', strtotime($prevStart)),
        'prev_short'  => date('M Y', strtotime($prevStart)),
    ];
}

/** Read ?month=&year= from the current request (defaults to this month). */
function aiInsightPeriodFromRequest(): array {
    $month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
    $year  = isset($_GET['year'])  ? (int) $_GET['year']  : (int) date('Y');
    return aiInsightPeriod($month, $year);
}

// ─── Sign-off pending: document taxonomy ─────────────────────
// The 8 document types requested by the registrar are not all
// present in document_requests.document_type (the enum holds only
// form137, good_moral, transcript, certificate, clearance) and 4 of
// the 14 live rows carry an empty type because
// api/student-documents.php writes values that enum rejects.
//
// Classification precedence is therefore:
//     catalog SKU  →  catalog name keyword  →  document_type  →  Others
// SKU is authoritative because catalog_id is always populated.
//
// SIGN-OFF D1d: DOC-HD ("Honorable Dismissal") is treated as
//               Withdrawal Form.
// SIGN-OFF D1e: DOC-COE ("Certificate of Enrollment") is treated as
//               COR (4 of 11 August requests land in this bucket —
//               the single biggest swing in the document chart).
// Edit only this map once the registrar rules on those two buckets.
function aiInsightDocTaxonomy(): array {
    return [
        // Display order of the bars (the trailing "Others" is appended by the chart builder).
        'buckets' => ['Form 137', 'Form 138', 'Good Moral', 'Withdrawal Form', 'COR', 'COG', 'TOR', 'Diploma'],

        // catalogue SKU → bucket
        'sku' => [
            'DOC-F137'    => 'Form 137',
            'DOC-F138'    => 'Form 138',
            'DOC-GM'      => 'Good Moral',
            'DOC-HD'      => 'Withdrawal Form',   // SIGN-OFF D1d
            'DOC-COE'     => 'COR',               // SIGN-OFF D1e
            'DOC-COG'     => 'COG',
            'DOC-TOR'     => 'TOR',
            'DOC-DIPLOMA' => 'Diploma',
            'DOC-CTC'     => 'Others',            // Certified True Copy — not one of the requested 8
            'DOC-CD'      => 'Others',            // Course Description
        ],

        // catalogue name keywords, checked in order (case-insensitive)
        'keywords' => [
            ['Form 137',        '137'],
            ['Form 138',        '138'],
            ['Withdrawal Form', 'dismissal'],
            ['Withdrawal Form', 'withdrawal'],
            ['COR',             'registration'],
            ['COR',             'enrollment'],
            ['COG',             'grades'],
            ['TOR',             'transcript'],
            ['Good Moral',      'good moral'],
            ['Diploma',         'diploma'],
        ],

        // legacy document_type enum → bucket
        'type' => [
            'form137'    => 'Form 137',
            'form138'    => 'Form 138',
            'good_moral' => 'Good Moral',
            'withdrawal' => 'Withdrawal Form',
            'cor'        => 'COR',
            'cog'        => 'COG',
            'tor'        => 'TOR',
            'transcript' => 'TOR',
            'diploma'    => 'Diploma',
            // 'certificate' and 'clearance' intentionally fall through to Others
        ],
    ];
}

/** Classify one request into a canonical document bucket. */
function aiInsightDocBucket(?string $sku, ?string $name, ?string $type): string {
    $tax  = aiInsightDocTaxonomy();
    $sku  = strtoupper(trim((string) $sku));
    $name = strtolower(trim((string) $name));
    $type = strtolower(trim((string) $type));

    if ($sku !== '' && isset($tax['sku'][$sku])) {
        return $tax['sku'][$sku];
    }
    if ($name !== '') {
        foreach ($tax['keywords'] as $pair) {
            if (strpos($name, $pair[1]) !== false) {
                return $pair[0];
            }
        }
    }
    if ($type !== '' && isset($tax['type'][$type])) {
        return $tax['type'][$type];
    }
    return 'Others';
}

/** Workflow statuses collapsed into the stacked series of the document chart. */
function aiInsightDocStatusGroups(): array {
    return [
        'Pending'    => ['Filed', 'Pending_Clearance'],
        'Processing' => ['Processing'],
        'Ready'      => ['Ready'],
        'Claimed'    => ['Claimed'],
        'Rejected'   => ['Rejected'],
    ];
}

/** Which stacked series a document_status belongs to. */
function aiInsightDocStatusGroup(?string $status): string {
    $status = (string) $status;
    foreach (aiInsightDocStatusGroups() as $group => $statuses) {
        if (in_array($status, $statuses, true)) {
            return $group;
        }
    }
    return 'Processing';
}

// ─── Student status buckets (the pie) ────────────────────────
// Read from studentStatuses() so the pie cannot offer a slice the column would
// reject.
//
// This list used to be hand-written and had already drifted twice: it folded
// probation / at-risk / loa into "Active" (values that no longer exist), and it
// drew an "Alumni" slice from a value the ENUM did not accept, so the wedge was
// always empty. A pie that promises a category it can never fill is worse than
// a pie that does not mention it.
//
// graduate and alumni stay separate here. "Just finished" and "left years ago"
// are different facts about a cohort, and merging them loses the distinction
// this chart exists to show.
function aiInsightStatusBuckets(): array {
    $colors = [
        'enrolled' => '#2563eb',
        'active'   => '#16a34a',
        'graduate' => '#7c3aed',
        'alumni'   => '#0891b2',
        'dropped'  => '#64748b',
    ];
    $out = [];
    foreach (studentStatuses() as $st) {
        $out[studentStatusLabel($st)] = ['statuses' => [$st], 'color' => $colors[$st] ?? '#64748b'];
    }
    return $out;
}

/** Program distribution: top programs plus a "new in period" second series. */
function aiInsightProgramMetrics(array $period, int $topLimit = 8): array {
    $db = Database::getInstance();

    $rows = $db->fetchAll(
        "SELECT COALESCE(NULLIF(TRIM(course), ''), 'Unassigned') AS course,
                COUNT(*) AS total,
                SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS new_in_period
           FROM students
          GROUP BY course
          ORDER BY total DESC, course ASC",
        [$period['start'], $period['end']]
    );

    $items  = [];
    $others = ['course' => 'Others', 'total' => 0, 'new_in_period' => 0];
    $i = 0;
    foreach ($rows as $r) {
        $row = [
            'course'         => (string) $r['course'],
            'total'          => (int) $r['total'],
            'new_in_period'  => (int) $r['new_in_period'],
        ];
        if ($i < $topLimit) {
            $items[] = $row;
        } else {
            $others['total']        += $row['total'];
            $others['new_in_period'] += $row['new_in_period'];
        }
        $i++;
    }
    if ($i > $topLimit) {
        $items[] = $others;
    }

    return ['items' => $items, 'program_count' => count($rows)];
}

// ─── Metric blocks (one per data domain) ─────────────────────
/**
 * "+8 requests vs Jul 2026" / "-2 vs Jul 2026" / "±0 vs Jul 2026".
 *
 * @param string $unit optional unit word shown before "vs" ("new", "issued", …)
 */
function aiInsightDelta(int $current, int $previous, string $prevLabel, string $unit = ''): array {
    $d    = $current - $previous;
    $dir  = $d > 0 ? 'up' : ($d < 0 ? 'down' : 'flat');
    $sign = $d > 0 ? '+' : ($d < 0 ? '-' : '±');
    $text = $sign . abs($d) . ($unit !== '' ? ' ' . $unit : '') . ' vs ' . $prevLabel;
    return ['dir' => $dir, 'value' => $d, 'text' => $text];
}

/** Population snapshot + new registrations in the period. */
function aiInsightStudentMetrics(array $period): array {
    $db = Database::getInstance();
    $s  = $period['start'];      $e  = $period['end'];
    $ps = $period['prev_start']; $pe = $period['prev_end'];

    $rows = $db->fetchAll(
        "SELECT status, COUNT(*) AS c FROM students
          WHERE status IS NOT NULL AND status <> '' GROUP BY status"
    );
    $byStatus = [];
    foreach ($rows as $r) {
        $byStatus[strtolower((string) $r['status'])] = (int) $r['c'];
    }

    $total       = (int) $db->fetchColumn("SELECT COUNT(*) FROM students");
    $newPeriod   = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE created_at >= ? AND created_at < ?", [$s, $e]);
    $newPrevious = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE created_at >= ? AND created_at < ?", [$ps, $pe]);
    $engaged     = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status IN ('active','enrolled')");

    return [
        'total'        => $total,
        'by_status'    => $byStatus,
        'engaged'      => $engaged,
        'new_period'   => $newPeriod,
        'new_previous' => $newPrevious,
    ];
}

/** RFID card lifecycle + issuance/scan trend. */
function aiInsightRfidMetrics(array $period): array {
    $db = Database::getInstance();
    $s  = $period['start'];      $e  = $period['end'];
    $ps = $period['prev_start']; $pe = $period['prev_end'];

    $rows = $db->fetchAll("SELECT status, COUNT(*) AS c FROM rfid_cards GROUP BY status");
    $byStatus = [];
    foreach ($rows as $r) {
        $byStatus[strtolower((string) $r['status'])] = (int) $r['c'];
    }

    $total       = (int) $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards");
    $issued      = (int) $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE issued_at >= ? AND issued_at < ?", [$s, $e]);
    $issuedPrev  = (int) $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE issued_at >= ? AND issued_at < ?", [$ps, $pe]);

    // 12-month window ending on the selected period.
    $windowStart = date('Y-m-01 00:00:00', strtotime($s . ' -11 months'));
    $issuedRows  = $db->fetchAll(
        "SELECT DATE_FORMAT(COALESCE(issued_at, issued_date), '%Y-%m') AS ym, COUNT(*) AS c
           FROM rfid_cards
          WHERE COALESCE(issued_at, issued_date) >= ?
          GROUP BY ym",
        [$windowStart]
    );
    $scanRows = $db->fetchAll(
        "SELECT DATE_FORMAT(scanned_at, '%Y-%m') AS ym, COUNT(*) AS c
           FROM rfid_scan_logs
          WHERE scanned_at >= ?
          GROUP BY ym",
        [$windowStart]
    );

    $issuedMonths = [];
    foreach ($issuedRows as $r) $issuedMonths[(string) $r['ym']] = (int) $r['c'];
    $scanMonths = [];
    foreach ($scanRows as $r) $scanMonths[(string) $r['ym']] = (int) $r['c'];

    return [
        'total'           => $total,
        'by_status'       => $byStatus,
        'active'          => $byStatus['active']   ?? 0,
        'expired'         => $byStatus['expired']  ?? 0,
        'lost'            => $byStatus['lost']     ?? 0,
        'inactive'        => $byStatus['inactive'] ?? 0,
        'issued_period'   => $issued,
        'issued_previous' => $issuedPrev,
        'issued_months'   => $issuedMonths,
        'scan_months'     => $scanMonths,
        'scan_total'      => array_sum($scanMonths),
    ];
}

/** Document transactions in the period, classified into the 8 canonical buckets. */
function aiInsightDocumentMetrics(array $period): array {
    $db = Database::getInstance();
    $s  = $period['start'];      $e  = $period['end'];
    $ps = $period['prev_start']; $pe = $period['prev_end'];

    $rows = $db->fetchAll(
        "SELECT COALESCE(c.sku, '')        AS sku,
                COALESCE(c.name, '')       AS cat_name,
                dr.document_type           AS doc_type,
                dr.document_status         AS doc_status,
                COUNT(*)                   AS c
           FROM document_requests dr
           LEFT JOIN document_catalog c ON c.id = dr.catalog_id
          WHERE dr.request_date >= ? AND dr.request_date < ?
          GROUP BY c.sku, c.name, dr.document_type, dr.document_status",
        [$s, $e]
    );

    $tax     = aiInsightDocTaxonomy();
    $buckets = array_merge($tax['buckets'], ['Others']);
    $groups  = array_keys(aiInsightDocStatusGroups());

    // matrix[bucket][series] = count
    $matrix = [];
    foreach ($buckets as $b) {
        $matrix[$b] = array_fill_keys($groups, 0);
    }
    foreach ($rows as $r) {
        $bucket = aiInsightDocBucket($r['sku'], $r['cat_name'], $r['doc_type']);
        if (!isset($matrix[$bucket])) {
            $matrix[$bucket] = array_fill_keys($groups, 0);
        }
        $matrix[$bucket][aiInsightDocStatusGroup($r['doc_status'])] += (int) $r['c'];
    }

    $total       = (int) $db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE request_date >= ? AND request_date < ?", [$s, $e]);
    $previous    = (int) $db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE request_date >= ? AND request_date < ?", [$ps, $pe]);
    $pending     = (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM document_requests
          WHERE request_date >= ? AND request_date < ?
            AND document_status IN ('Pending_Clearance','Awaiting_Payment','Processing')",
        [$s, $e]
    );
    $completed   = (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM document_requests
          WHERE request_date >= ? AND request_date < ?
            AND document_status IN ('Ready','Shipped','Claimed')",
        [$s, $e]
    );
    $rejected    = (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM document_requests
          WHERE request_date >= ? AND request_date < ? AND document_status = 'Rejected'",
        [$s, $e]
    );

    $bucketTotals = [];
    foreach ($buckets as $b) {
        $bucketTotals[$b] = array_sum($matrix[$b]);
    }

    return [
        'total'         => $total,
        'previous'      => $previous,
        'pending'       => $pending,
        'completed'     => $completed,
        'rejected'      => $rejected,
        'buckets'       => $buckets,
        'series'        => $groups,
        'matrix'        => $matrix,
        'bucket_totals' => $bucketTotals,
    ];
}

/** Queue load: how many students joined, how many tickets, how busy today. */
function aiInsightQueueMetrics(array $period): array {
    $db = Database::getInstance();
    $s  = $period['start'];      $e  = $period['end'];
    $ps = $period['prev_start']; $pe = $period['prev_end'];

    $students      = (int) $db->fetchColumn(
        "SELECT COUNT(DISTINCT student_id) FROM queue_tickets
          WHERE joined_at >= ? AND joined_at < ? AND student_id IS NOT NULL",
        [$s, $e]
    );
    $studentsPrev  = (int) $db->fetchColumn(
        "SELECT COUNT(DISTINCT student_id) FROM queue_tickets
          WHERE joined_at >= ? AND joined_at < ? AND student_id IS NOT NULL",
        [$ps, $pe]
    );
    $tickets       = (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE joined_at >= ? AND joined_at < ?", [$s, $e]);
    $ticketsPrev   = (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE joined_at >= ? AND joined_at < ?", [$ps, $pe]);
    $today         = (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE queue_date = CURDATE()");
    $walkIns       = (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE joined_at >= ? AND joined_at < ? AND student_id IS NULL", [$s, $e]);
    $served        = (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE joined_at >= ? AND joined_at < ? AND status = 'completed'", [$s, $e]);
    $cancelled     = (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE joined_at >= ? AND joined_at < ? AND status IN ('cancelled','no-show','removed')", [$s, $e]);

    // Peak day inside the period — gives the AI something concrete to observe.
    $peak = $db->fetchOne(
        "SELECT queue_date, COUNT(*) AS c FROM queue_tickets
          WHERE joined_at >= ? AND joined_at < ?
          GROUP BY queue_date ORDER BY c DESC LIMIT 1",
        [$s, $e]
    );

    return [
        'students'         => $students,
        'students_previous'=> $studentsPrev,
        'tickets'          => $tickets,
        'tickets_previous' => $ticketsPrev,
        'today'            => $today,
        'walk_ins'         => $walkIns,
        'served'           => $served,
        'cancelled'        => $cancelled,
        'peak_date'        => $peak['queue_date'] ?? null,
        'peak_count'       => (int) ($peak['c'] ?? 0),
    ];
}

// ─── Main builder ────────────────────────────────────────────
/**
 * Everything the dashboard, the data endpoint and the AI report
 * need for one reporting period. Runs each aggregate query once.
 *
 * @return array{period:array,cards:array,charts:array,facts:array}
 */
function aiInsightBuild(array $period): array {
    $students = aiInsightStudentMetrics($period);
    $rfid     = aiInsightRfidMetrics($period);
    $docs     = aiInsightDocumentMetrics($period);
    $queue    = aiInsightQueueMetrics($period);
    $programs = aiInsightProgramMetrics($period);

    $prev = $period['prev_short'];

    // ─── KPI cards (headline = snapshot, badge = vs previous period) ───
    $cards = [
        [
            'key'    => 'students',
            'label'  => 'Total Students',
            'icon'   => 'fa-user-graduate',
            'tone'   => 'blue',
            'value'  => $students['total'],
            'badge'  => aiInsightDelta($students['new_period'], $students['new_previous'], $prev, 'new'),
            'footer' => [
                'dot'  => 'green',
                'text' => $students['engaged'] . ' active / enrolled',
            ],
        ],
        [
            'key'    => 'rfid',
            'label'  => 'RFID Cards',
            'icon'   => 'fa-id-card',
            'tone'   => 'green',
            'value'  => $rfid['total'],
            'badge'  => aiInsightDelta($rfid['issued_period'], $rfid['issued_previous'], $prev, 'issued'),
            'footer' => [
                'dot'  => 'purple',
                'text' => $rfid['active'] . ' active · ' . $rfid['expired'] . ' expired',
            ],
        ],
        [
            'key'    => 'documents',
            'label'  => 'Document Transactions',
            'icon'   => 'fa-file-lines',
            'tone'   => 'yellow',
            'value'  => $docs['total'],
            'badge'  => aiInsightDelta($docs['total'], $docs['previous'], $prev, 'requests'),
            'footer' => [
                'dot'  => 'blue',
                'text' => $docs['pending'] . ' pending · ' . $docs['completed'] . ' completed',
            ],
        ],
        [
            'key'    => 'queue',
            'label'  => 'Queue Total Students',
            'icon'   => 'fa-ticket',
            'tone'   => 'purple',
            'value'  => $queue['students'],
            'badge'  => aiInsightDelta($queue['students'], $queue['students_previous'], $prev, 'students'),
            'footer' => [
                'dot'  => 'purple',
                'text' => $queue['tickets'] . ' tickets · ' . $queue['today'] . ' today',
            ],
        ],
    ];

    // ─── Chart 1 · Student status distribution (pie) ───
    $statusLabels = []; $statusValues = []; $statusColors = [];
    $claimed = [];
    foreach (aiInsightStatusBuckets() as $label => $meta) {
        $count = 0;
        foreach ($meta['statuses'] as $st) {
            $count += $students['by_status'][$st] ?? 0;
            $claimed[] = $st;
        }
        if ($count > 0) {
            $statusLabels[] = $label;
            $statusValues[] = $count;
            $statusColors[] = $meta['color'];
        }
    }
    // A status value the buckets don't know about (added later to the enum)
    // still surfaces instead of vanishing from the pie.
    $unclassified = 0;
    foreach ($students['by_status'] as $st => $count) {
        if (!in_array($st, $claimed, true)) $unclassified += $count;
    }
    if ($unclassified > 0) {
        $statusLabels[] = 'Unclassified';
        $statusValues[] = $unclassified;
        $statusColors[] = '#94a3b8';
    }

    // ─── Chart 2 · Student program distribution (bar) ───
    $programLabels = []; $programTotals = []; $programNew = []; $programColors = [];
    $palette = ['#2563eb', '#7c3aed', '#0ea5e9', '#b45309', '#db2777', '#16a34a', '#0891b2', '#ea580c', '#64748b'];
    foreach ($programs['items'] as $i => $row) {
        $programLabels[] = $row['course'];
        $programTotals[] = $row['total'];
        $programNew[]    = $row['new_in_period'];
        $programColors[] = $palette[$i % count($palette)];
    }

    // ─── Chart 3 · Document transaction overview (stacked bar) ───
    $seriesColors = [
        'Pending'    => '#b45309',
        'Processing' => '#2563eb',
        'Ready'      => '#7c3aed',
        'Claimed'    => '#16a34a',
        'Rejected'   => '#dc2626',
    ];
    $docSeries = [];
    foreach ($docs['series'] as $group) {
        $values = [];
        foreach ($docs['buckets'] as $b) {
            $values[] = (int) $docs['matrix'][$b][$group];
        }
        $docSeries[] = [
            'label'  => $group,
            'values' => $values,
            'color'  => $seriesColors[$group] ?? '#94a3b8',
        ];
    }

    // ─── Chart 4 · RFID overview (doughnut + 12-month trend) ───
    $rfidLabels = []; $rfidValues = []; $rfidColors = [];
    $rfidMeta = [
        'active'   => ['Active',   '#16a34a'],
        'inactive' => ['Inactive', '#94a3b8'],
        'lost'     => ['Lost',     '#dc2626'],
        'expired'  => ['Expired',  '#b45309'],
    ];
    foreach ($rfidMeta as $key => $meta) {
        $count = (int) ($rfid['by_status'][$key] ?? 0);
        if ($count > 0) {
            $rfidLabels[] = $meta[0];
            $rfidValues[] = $count;
            $rfidColors[] = $meta[1];
        }
    }

    // Trend falls back to kiosk taps while no card has been issued yet,
    // so the card still says something true instead of drawing empty axes.
    $trendSource = $rfid['total'] > 0 ? 'cards' : ($rfid['scan_total'] > 0 ? 'scans' : 'none');
    $trendLabels = []; $trendValues = [];
    for ($i = 11; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime($period['start'] . ' -' . $i . ' months'));
        $trendLabels[] = date('M y', strtotime($ym . '-01'));
        $trendValues[] = $trendSource === 'cards'
            ? (int) ($rfid['issued_months'][$ym] ?? 0)
            : (int) ($rfid['scan_months'][$ym] ?? 0);
    }

    $charts = [
        'status' => [
            'labels' => $statusLabels,
            'values' => $statusValues,
            'colors' => $statusColors,
            'total'  => array_sum($statusValues),
        ],
        'program' => [
            'labels' => $programLabels,
            'values' => $programTotals,
            'new'    => $programNew,
            'colors' => $programColors,
        ],
        'doc' => [
            'labels' => $docs['buckets'],
            'series' => $docSeries,
            'totals' => array_values($docs['bucket_totals']),
            'total'  => $docs['total'],
        ],
        'rfid' => [
            'labels'        => $rfidLabels,
            'values'        => $rfidValues,
            'colors'        => $rfidColors,
            'total'         => $rfid['total'],
            'trend_labels'  => $trendLabels,
            'trend_values'  => $trendValues,
            'trend_source'  => $trendSource,
            'highlight'     => count($trendLabels) - 1,
        ],
    ];

    // ─── Fact sheet (aggregates only — feeds the prompt and the fallback) ───
    $statusMap = [];
    foreach ($statusLabels as $i => $label) {
        $statusMap[$label] = (int) $statusValues[$i];
    }
    $docByBucket = [];
    foreach ($docs['buckets'] as $b) {
        $docByBucket[$b] = (int) $docs['bucket_totals'][$b];
    }
    $docByGroup = [];
    foreach ($docs['series'] as $group) {
        $docByGroup[$group] = 0;
    }
    foreach ($docs['matrix'] as $bucketRow) {
        foreach ($bucketRow as $group => $count) {
            $docByGroup[$group] = (int) ($docByGroup[$group] ?? 0) + (int) $count;
        }
    }

    $topPrograms = [];
    foreach ($programs['items'] as $row) {
        $topPrograms[] = [
            'course'         => $row['course'],
            'students'       => $row['total'],
            'new_in_period'  => $row['new_in_period'],
        ];
    }

    $facts = [
        'period' => [
            'label'      => $period['label'],
            'start'      => substr($period['start'], 0, 10),
            'end'        => date('Y-m-d', strtotime($period['end'] . ' -1 day')),
            'prev_label' => $period['prev_label'],
        ],
        'students' => [
            'total'           => $students['total'],
            'active_enrolled' => $students['engaged'],
            'new_in_period'   => $students['new_period'],
            'new_in_previous' => $students['new_previous'],
            'by_status'       => $statusMap,
        ],
        'rfid' => [
            'total_cards'        => $rfid['total'],
            'active'             => $rfid['active'],
            'expired'            => $rfid['expired'],
            'lost'               => $rfid['lost'],
            'inactive'           => $rfid['inactive'],
            'issued_in_period'   => $rfid['issued_period'],
            'issued_in_previous' => $rfid['issued_previous'],
            'activity_source'    => $trendSource === 'cards' ? 'cards_issued' : ($trendSource === 'scans' ? 'kiosk_taps' : 'none'),
            'kiosk_taps_total'   => $rfid['scan_total'],
        ],
        'documents' => [
            'total_in_period'  => $docs['total'],
            'total_previous'   => $docs['previous'],
            'pending'          => $docs['pending'],
            'completed'        => $docs['completed'],
            'rejected'         => $docs['rejected'],
            'by_type'          => $docByBucket,
            'by_stage'         => $docByGroup,
        ],
        'queue' => [
            'students_in_period'   => $queue['students'],
            'students_previous'    => $queue['students_previous'],
            'tickets_in_period'    => $queue['tickets'],
            'tickets_previous'     => $queue['tickets_previous'],
            'served'               => $queue['served'],
            'cancelled_or_no_show' => $queue['cancelled'],
            'walk_ins'             => $queue['walk_ins'],
            'tickets_today'        => $queue['today'],
            'busiest_day'          => $queue['peak_date'],
            'busiest_day_tickets'  => $queue['peak_count'],
        ],
        'programs' => [
            'program_count' => $programs['program_count'],
            'top'           => $topPrograms,
        ],
    ];

    return [
        'period' => $period,
        'cards'  => $cards,
        'charts' => $charts,
        'facts'  => $facts,
    ];
}

// ─── Text builders ───────────────────────────────────────────
/** Safe percentage helper — never divides by zero. */
function aiInsightPct($part, $whole, int $decimals = 1): string {
    $whole = (float) $whole;
    if ($whole <= 0) return 'n/a';
    return number_format(((float) $part / $whole) * 100, $decimals) . '%';
}

/** Join label => count pairs into one readable line. */
function aiInsightPairs(array $map): string {
    $out = [];
    foreach ($map as $label => $value) {
        $out[] = $label . ': ' . $value;
    }
    return $out ? implode(', ', $out) : 'none';
}

/**
 * Deterministic fact sheet handed to the model. Every figure the AI
 * is allowed to cite appears here, once, in a fixed order, so the
 * narrative can never drift from the charts.
 */
function aiInsightFactSheetText(array $facts): string {
    $p  = $facts['period'];
    $st = $facts['students'];
    $rf = $facts['rfid'];
    $dc = $facts['documents'];
    $q  = $facts['queue'];

    // Pre-assigned so PHP 7/8 string interpolation never trips over "??"
    // inside "{...}" (which older parsers reject).
    $newInPeriod    = (int) ($st['new_in_period']   ?? 0);
    $newInPrevious  = (int) ($st['new_in_previous'] ?? 0);
    $rfLost         = (int) ($rf['lost'] ?? 0);
    $served             = (int) ($q['served'] ?? 0);
    $cancelledNoShow    = (int) ($q['cancelled_or_no_show'] ?? 0);
    $walkIns            = (int) ($q['walk_ins'] ?? 0);

    $t = "Reporting period: {$p['label']} ({$p['start']} to {$p['end']})\n";
    $t .= "Comparison period: {$p['prev_label']}\n\n";

    $t .= "STUDENT POPULATION\n";
    $t .= "- Total students on record: {$st['total']}\n";
    $t .= "- Active or enrolled: {$st['active_enrolled']} (" . aiInsightPct($st['active_enrolled'], $st['total'], 0) . " of the population)\n";
    $t .= "- New registrations this period: {$newInPeriod} (previous period: {$newInPrevious})\n";
    $t .= "- Status distribution: " . aiInsightPairs($st['by_status']) . "\n";
    $t .= "- Programs: {$facts['programs']['program_count']}\n";
    foreach ($facts['programs']['top'] as $prog) {
        $t .= "  * {$prog['course']}: {$prog['students']} students ({$prog['new_in_period']} new this period)\n";
    }

    $t .= "\nDOCUMENT TRANSACTIONS\n";
    $t .= "- Requests this period: {$dc['total_in_period']} (previous period: {$dc['total_previous']})\n";
    $t .= "- Not yet ready (pending / awaiting payment / processing): {$dc['pending']}\n";
    $t .= "- Ready, shipped or claimed: {$dc['completed']}\n";
    $t .= "- Rejected: {$dc['rejected']}\n";
    $t .= "- Workflow stages: " . aiInsightPairs($dc['by_stage']) . "\n";
    $t .= "- By document type: " . aiInsightPairs($dc['by_type']) . "\n";

    $t .= "\nRFID CARDS\n";
    $t .= "- Total cards on record: {$rf['total_cards']}\n";
    $t .= "- Cards active: {$rf['active']} (" . aiInsightPct($rf['active'], $rf['total_cards'], 0) . " of all cards)\n";
    $t .= "- Cards expired: {$rf['expired']}; lost: {$rfLost}; inactive: {$rf['inactive']}\n";
    $t .= "- Issued this period: {$rf['issued_in_period']} (previous period: {$rf['issued_in_previous']})\n";
    $t .= "- Activity data source: {$rf['activity_source']}\n";
    $t .= "- Kiosk taps recorded in the last 12 months: {$rf['kiosk_taps_total']}\n";

    $t .= "\nQUEUE\n";
    $t .= "- Students who joined the queue this period: {$q['students_in_period']} (previous period: {$q['students_previous']})\n";
    $t .= "- Tickets issued: {$q['tickets_in_period']} (previous period: {$q['tickets_previous']})\n";
    $t .= "- Completed: {$served}; cancelled, no-show or removed: {$cancelledNoShow}\n";
    $t .= "- Walk-in tickets with no student record: {$walkIns}\n";
    if (!empty($q['busiest_day'])) {
        $t .= "- Busiest day: {$q['busiest_day']} with {$q['busiest_day_tickets']} tickets\n";
    }
    $t .= "- Tickets today: {$q['tickets_today']}\n";

    return $t;
}

/**
 * Rule-based three-section report, rendered whenever the AI gateway
 * is unreachable. Deliberately identical in shape to the model's
 * output so the report card never appears empty or broken.
 */
function aiInsightFallbackReport(array $facts): string {
    $p  = $facts['period'];
    $st = $facts['students'];
    $rf = $facts['rfid'];
    $dc = $facts['documents'];
    $q  = $facts['queue'];

    // Pre-assigned so PHP 7/8 string interpolation never trips over "??"
    // inside "{...}" in the templates below.
    $rfLost                  = (int) ($rf['lost'] ?? 0);
    $served                  = (int) ($q['served'] ?? 0);
    $cancelledOrNoShow       = (int) ($q['cancelled_or_no_show'] ?? 0);
    $walkIns                 = (int) ($q['walk_ins'] ?? 0);
    $newInPeriod             = (int) ($st['new_in_period'] ?? 0);
    $newInPrevious           = (int) ($st['new_in_previous'] ?? 0);

    $docDelta  = (int) $dc['total_in_period'] - (int) $dc['total_previous'];
    $deltaWord = $docDelta > 0 ? "rose by {$docDelta}" : ($docDelta < 0 ? 'fell by ' . abs($docDelta) : 'held steady');
    $pendingShare = aiInsightPct($dc['pending'], $dc['total_in_period'], 0);

    // Most-requested document type in the period.
    $types = $dc['by_type'];
    arsort($types);
    $topType = null; $topCount = 0;
    foreach ($types as $label => $count) {
        if ((int) $count > 0) { $topType = $label; $topCount = (int) $count; break; }
    }

    // ── 1. AI Registrar Summary ──
    $summary = "{$p['label']} recorded {$dc['total_in_period']} document transactions and "
        . "{$q['tickets_in_period']} queue tickets from {$q['students_in_period']} students, against a population of "
        . "{$st['total']} students ({$st['active_enrolled']} active or enrolled). "
        . "Document request volume {$deltaWord} compared with {$p['prev_label']}.";
    $summary .= (int) $rf['total_cards'] === 0
        ? ' No RFID cards are on record yet.'
        : " {$rf['active']} of {$rf['total_cards']} RFID cards are active.";

    // ── 2. Detected Trends ──
    $trends   = [];
    $trends[] = "- **Students:** {$st['total']} students on record, {$st['active_enrolled']} active or enrolled; "
        . "{$newInPeriod} new registrations this period against {$newInPrevious} in {$p['prev_label']}.";
    $trends[] = "- **Document Transactions:** {$dc['total_in_period']} requests this period versus {$dc['total_previous']} "
        . "in {$p['prev_label']}; {$dc['pending']} are still in progress and {$dc['completed']} are ready, shipped or claimed."
        . ($topType ? " {$topType} is the most requested type with {$topCount}." : '');
    $trends[] = (int) $rf['total_cards'] === 0
        ? "- **RFID:** no cards have been issued, so card coverage cannot be measured; {$rf['kiosk_taps_total']} kiosk taps were logged in the last 12 months."
        : "- **RFID:** {$rf['issued_in_period']} cards issued this period versus {$rf['issued_in_previous']} in {$p['prev_label']}; {$rf['active']} active, {$rf['expired']} expired, {$rfLost} lost.";
    $trends[] = "- **Queue:** {$q['students_in_period']} students took {$q['tickets_in_period']} tickets this period "
        . "(versus {$q['students_previous']} students in {$p['prev_label']}); {$served} were completed and "
        . "{$cancelledOrNoShow} were cancelled or no-show.";

    // ── 3. Patterns Observed ──
    $observations = [];
    if ((int) $dc['total_in_period'] > 0) {
        if ($topType) {
            $observations[] = "- {$topType} accounts for {$topCount} of {$dc['total_in_period']} requests ("
                . aiInsightPct($topCount, $dc['total_in_period'], 0) . '), the largest single demand this period.';
        }
        $observations[] = "- Work still in progress represents {$pendingShare} of the period's requests, "
            . 'making turnaround the main operational focus.';
    } else {
        $observations[] = '- No document requests were logged in this period, so document demand is flat.';
    }
    if (!empty($q['busiest_day']) && (int) $q['busiest_day_tickets'] > 0) {
        $observations[] = "- Queue demand is unevenly spread: {$q['busiest_day']} carried {$q['busiest_day_tickets']} of "
            . "{$q['tickets_in_period']} tickets, so counter staffing should follow the daily peaks.";
    }
    if ($walkIns > 0) {
        $observations[] = "- {$walkIns} queue tickets had no matching student record, which limits per-student reporting.";
    }
    if ((int) $rf['expired'] > 0) {
        $observations[] = "- {$rf['expired']} RFID cards have expired, which reduces active card coverage.";
    }
    if ($newInPeriod === 0 && $newInPrevious === 0) {
        $observations[] = '- No new students were registered in the selected period or the period before it.';
    }
    if (count($observations) === 0) {
        $observations[] = '- Registrar activity is within its normal operating range for this period.';
    }

    return "## 1. AI Registrar Summary\n"
        . trim($summary) . "\n\n"
        . "## 2. Detected Trends\n"
        . implode("\n", $trends) . "\n\n"
        . "## 3. Patterns Observed\n"
        . implode("\n", $observations);
}
