// ============================================================
//  JS/INSIGHTS.JS
//  Intelligent Analytics and Reports
//    · Chart.js rendering for the 4 registrar charts
//    · Reporting Period switching (cards + charts refetch)
//    · Generate AI Insight → 3-section AI ANALYSIS REPORT
//    · Print + Export (PDF / CSV / TXT)
//
//  Reads the first paint from
//  <script type="application/json" id="insightsData">.
//  Chart styling mirrors js/dashboard.js (same typography, dark
//  tooltip, doughnut centre readout) so both pages read as one
//  product.
// ============================================================
(function () {
    'use strict';

    var payloadEl = document.getElementById('insightsData');
    var payload = null;
    try { payload = payloadEl ? JSON.parse(payloadEl.textContent) : null; } catch (e) { payload = null; }
    if (!payload) return;

    var ENDPOINTS = payload.endpoints || {
        data:   '../api/ai-insights-data.php',
        report: '../api/ai-insights-report.php'
    };

    var state = {
        period: payload.period,
        cards:  payload.cards,
        charts: payload.charts,
        report: null,
        source: null,
        facts:  null,
        stale:  false
    };

    var chartInstances = {};   // canvas id → Chart

    var el = {
        month:      document.getElementById('reportMonth'),
        year:       document.getElementById('reportYear'),
        periodBox:  document.getElementById('periodControl'),
        generate:   document.getElementById('generateBtn'),
        cardGrid:   document.getElementById('statCards'),
        statusBadge: document.getElementById('statusBadge'),
        programBadge: document.getElementById('programBadge'),
        programCanvas: document.getElementById('programChart'),
        programEmptyNote: document.getElementById('programEmptyNote'),
        docBadge:   document.getElementById('docBadge'),
        rfidBadge:  document.getElementById('rfidBadge'),
        rfidSubtitle: document.getElementById('rfidSubtitle'),
        rfidTrendLabel: document.getElementById('rfidTrendLabel'),
        rfidEmptyNote: document.getElementById('rfidEmptyNote'),
        reportOutput: document.getElementById('reportOutput'),
        reportEmpty:  document.getElementById('reportEmpty'),
        reportLoading: document.getElementById('reportLoading'),
        reportError:  document.getElementById('reportError'),
        reportMeta:   document.getElementById('reportMeta'),
        reportSourceBadge: document.getElementById('reportSourceBadge'),
        reportMetaText: document.getElementById('reportMetaText'),
        reportFooter: document.getElementById('reportFooter'),
        printBtn:   document.getElementById('printBtn'),
        exportBtn:  document.getElementById('exportBtn'),
        exportMenu: document.getElementById('exportMenu'),
        exportPdf:  document.getElementById('exportPdf'),
        exportCsv:  document.getElementById('exportCsv'),
        exportTxt:  document.getElementById('exportTxt')
    };

    // ─── Chart.js global look (identical to the dashboard) ──────
    // Neutral chrome is aligned to the registrar-blue tokens used by
    // css/registrar*.css. Series colours are NOT touched: they come
    // from shared/analytics.php and are shared with dashboard.php.
    var T = {
        ink:    '#0f172a',
        muted:  '#64748b',
        faint:  '#94a3b8',
        grid:   '#f1f5f9',
        axis:   '#e2e8f0',
        brand:  '#2563eb',
        deep:   '#1d4ed8',
        pale:   '#93c5fd'
    };

    if (window.Chart) {
        Chart.defaults.font.family = "'Inter', 'Segoe UI', -apple-system, sans-serif";
        Chart.defaults.font.weight = '500';
        Chart.defaults.color = T.muted;
    }

    var tooltipStyle = {
        backgroundColor: 'rgba(13, 27, 46, 0.94)',
        titleColor: '#fff',
        titleFont: { size: 12, weight: '700' },
        bodyColor: 'rgba(255,255,255,0.82)',
        bodyFont: { size: 12, weight: '500' },
        borderWidth: 0,
        cornerRadius: 9,
        padding: { top: 9, bottom: 9, left: 12, right: 12 },
        displayColors: false,
        caretSize: 5
    };

    var legendStyle = {
        position: 'bottom',
        labels: {
            padding: 14,
            boxWidth: 7,
            boxHeight: 7,
            usePointStyle: true,
            pointStyle: 'circle',
            font: { size: 11, weight: '600' },
            color: '#64748b'
        }
    };

    // Centre readout inside a doughnut (total + label), as on the dashboard.
    var doughnutCentre = {
        id: 'doughnutCentre',
        afterDraw: function (chart) {
            var meta = chart.getDatasetMeta(0);
            if (!meta || !meta.data.length) return;

            var total = chart.data.datasets[0].data.reduce(function (a, b) { return a + b; }, 0);
            var arc = meta.data[0];
            var ctx = chart.ctx;
            var inner = arc.innerRadius || 40;
            var big = Math.max(14, Math.min(26, inner * 0.5));
            var small = Math.max(8, big * 0.36);

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = '700 ' + big + "px 'Inter', sans-serif";
            ctx.fillStyle = T.ink;
            ctx.fillText(String(chart.$centreValue == null ? total : chart.$centreValue), arc.x, arc.y - small * 0.4);
            ctx.font = '600 ' + small + "px 'Inter', sans-serif";
            ctx.fillStyle = T.faint;
            ctx.fillText(String(chart.$centreLabel || 'Total'), arc.x, arc.y + small * 1.1);
            ctx.restore();
        }
    };

    // Lollipop terminals. The datasets below draw only a thin stem; this
    // draws the dot that the eye actually lands on, plus the value beside
    // it, so a 4px stem never has to do the work of a full bar.
    var lollipopEnds = {
        id: 'lollipopEnds',
        afterDatasetsDraw: function (chart) {
            var totals = chart.$barTotals;
            if (!totals || !totals.length) return;

            // Stacked stems each report their own right edge; the furthest
            // one per row is the true bar end.
            var metas = chart.data.datasets.map(function (_, di) {
                return chart.getDatasetMeta(di);
            });
            var radius = chart.$lollipopRadius || 5.5;

            var ctx = chart.ctx;
            ctx.save();

            totals.forEach(function (total, i) {
                var right = null, y = null;
                for (var m = 0; m < metas.length; m++) {
                    var bar = metas[m] && metas[m].data && metas[m].data[i];
                    if (!bar) continue;
                    if (right === null || bar.x > right) right = bar.x;
                    if (y === null) y = bar.y;
                }
                if (right === null || y === null) return;

                // No dot for an empty row — it would read as a real value.
                if (total > 0) {
                    ctx.beginPath();
                    ctx.arc(right, y, radius, 0, Math.PI * 2);
                    ctx.fillStyle = T.deep;
                    ctx.fill();
                    // White halo separates the dot from the stem it caps.
                    ctx.lineWidth = 2;
                    ctx.strokeStyle = '#fff';
                    ctx.stroke();
                }

                ctx.font = "700 11px 'Inter', 'Segoe UI', -apple-system, sans-serif";
                ctx.textAlign = 'left';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = T.ink;
                ctx.fillText(String(total), right + radius + 8, y);
            });
            ctx.restore();
        }
    };

    // ─── Helpers ────────────────────────────────────────────────
    function hasData(arr) {
        return Array.isArray(arr) && arr.some(function (v) { return v > 0; });
    }

    function truncateLabel(text, max) {
        var s = String(text == null ? '' : text);
        return s.length > max ? s.slice(0, max - 1) + '…' : s;
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function mount(id) {
        var canvas = document.getElementById(id);
        return canvas ? canvas.getContext('2d') : null;
    }

    function destroyChart(id) {
        if (chartInstances[id]) {
            chartInstances[id].destroy();
            delete chartInstances[id];
        }
    }

    function num(value) {
        return Number(value || 0).toLocaleString();
    }


    // ─── Charts ─────────────────────────────────────────────────
    function renderCharts(data) {
        var charts = data.charts || {};
        var periodLabel = (data.period && data.period.label) || state.period.label;

        // ── 1 · Student status distribution (doughnut) ──
        var statusCtx = mount('statusChart');
        if (statusCtx) {
            destroyChart('statusChart');
            var s = charts.status || { labels: [], values: [], colors: [] };
            var sHas = hasData(s.values);
            chartInstances.statusChart = new Chart(statusCtx, {
                type: 'doughnut',
                plugins: [doughnutCentre],
                data: {
                    labels: sHas ? s.labels : ['No data'],
                    datasets: [{
                        data: sHas ? s.values : [1],
                        backgroundColor: sHas ? s.colors : ['#eef2f7'],
                        borderWidth: 0,
                        borderRadius: sHas ? 6 : 0,
                        spacing: sHas ? 3 : 0,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '76%',
                    animation: { duration: 800, easing: 'easeOutQuart' },
                    plugins: {
                        legend: legendStyle,
                        tooltip: Object.assign({}, tooltipStyle, {
                            callbacks: {
                                label: function (ctx) {
                                    if (!sHas) return 'No students on record';
                                    var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                    var pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : '0.0';
                                    return ctx.parsed + ' students · ' + pct + '%';
                                }
                            }
                        })
                    }
                }
            });
            chartInstances.statusChart.$centreValue = sHas ? num(s.total != null ? s.total : s.values.reduce(function (a, b) { return a + b; }, 0)) : '—';
            chartInstances.statusChart.$centreLabel = sHas ? 'Students' : 'No data';
            chartInstances.statusChart.update('none');
        }

        // ── 2 · Student program distribution (ranked horizontal bars) ──
        //
        // Orientation: this chart was always authored for a horizontal
        // layout — scales.x carries beginAtZero/grid/precision and scales.y
        // turns its grid off, which is the value/category split of a
        // horizontal bar. Without indexAxis:'y' Chart.js put the categories
        // on x, so program names rendered as 40°-rotated ticks, the gridlines
        // landed on the wrong axis, and the tooltip read ctx.parsed.x (the
        // category index) instead of the enrolment count.
        //
        // Encoding: bars used to be coloured per program while the axis
        // label already named the program — eight competing hues carrying no
        // information, under a legend whose swatches matched none of them.
        // Colour now means one thing only: the existing cohort vs. what
        // arrived this period, with the count printed at each bar's end.
        var programCtx = mount('programChart');
        if (programCtx) {
            destroyChart('programChart');
            var p = charts.program || { labels: [], values: [], new: [] };
            var pHas = hasData(p.values);

            if (el.programEmptyNote) el.programEmptyNote.classList.toggle('is-empty', !pHas);
            if (el.programCanvas) el.programCanvas.style.display = pHas ? '' : 'none';
            if (el.programBadge) el.programBadge.textContent = pHas ? p.labels.length + ' programs' : 'No data';

            if (pHas) {
                // Rank by enrolment so position carries the ranking.
                var order = p.values
                    .map(function (_, i) { return i; })
                    .sort(function (a, b) { return p.values[b] - p.values[a]; });

                var names = order.map(function (i) { return String(p.labels[i] == null ? '' : p.labels[i]); });
                var totals = order.map(function (i) { return Number(p.values[i]) || 0; });
                // Guard against a new count exceeding the total it belongs to.
                var fresh = order.map(function (i) {
                    return Math.max(0, Math.min(Number((p.new || [])[i]) || 0, Number(p.values[i]) || 0));
                });
                var earlier = totals.map(function (t, i) { return Math.max(0, t - fresh[i]); });

                var programChart = new Chart(programCtx, {
                    type: 'bar',
                    plugins: [lollipopEnds],
                    data: {
                        labels: names.map(function (l) { return truncateLabel(l, 30); }),
                        datasets: [
                            {
                                label: 'Earlier students',
                                data: earlier,
                                backgroundColor: '#c7dcfd',
                                borderRadius: 2,
                                borderSkipped: 'start',
                                maxBarThickness: 4,
                                stack: 'enrolment'
                            },
                            {
                                label: 'New this period',
                                data: fresh,
                                backgroundColor: T.deep,
                                borderRadius: 2,
                                borderSkipped: 'start',
                                maxBarThickness: 4,
                                stack: 'enrolment'
                            }
                        ]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        // A 4px stem is a poor hover target, so the whole
                        // row band is made interactive instead.
                        interaction: { mode: 'index', intersect: false },
                        layout: { padding: { right: 48, top: 2 } },
                        animation: { duration: 800, easing: 'easeOutQuart' },
                        plugins: {
                            legend: legendStyle,
                            tooltip: Object.assign({}, tooltipStyle, {
                                displayColors: true,
                                callbacks: {
                                    title: function (items) {
                                        return names[items[0].dataIndex] || items[0].label;
                                    },
                                    label: function (ctx) {
                                        return ctx.dataset.label + ': ' + num(ctx.parsed.x);
                                    },
                                    footer: function (items) {
                                        if (!items.length) return '';
                                        var total = totals[items[0].dataIndex];
                                        return 'Total: ' + num(total) + ' student' + (total === 1 ? '' : 's');
                                    }
                                }
                            })
                        },
                        scales: {
                            x: {
                                stacked: true,
                                beginAtZero: true,
                                grid: { color: T.grid },
                                border: { display: false },
                                ticks: {
                                    font: { size: 11, weight: '500' },
                                    color: T.faint,
                                    padding: 6,
                                    precision: 0,
                                    maxTicksLimit: 6
                                }
                            },
                            y: {
                                stacked: true,
                                grid: { display: false },
                                border: { display: false },
                                ticks: {
                                    font: { size: 11.5, weight: '600' },
                                    color: T.ink,
                                    padding: 10,
                                    autoSkip: false,
                                    crossAlign: 'far'
                                }
                            }
                        }
                    }
                });
                programChart.$barTotals = totals;
                chartInstances.programChart = programChart;
            }
        }

        // ── 3 · Document transaction overview (stacked bar) ──
        var docCtx = mount('docChart');
        if (docCtx) {
            destroyChart('docChart');
            var d = charts.doc || { labels: [], series: [], totals: [], total: 0 };
            var dHas = hasData(d.totals);
            var docDatasets = (d.series || []).map(function (series) {
                return {
                    label: series.label,
                    data: series.values,
                    backgroundColor: series.color,
                    borderRadius: 4,
                    maxBarThickness: 46,
                    stack: 'requests'
                };
            });
            if (!docDatasets.length) {
                docDatasets = [{
                    label: 'Requests',
                    data: (d.labels || []).map(function () { return 0; }),
                    backgroundColor: '#eef2f7',
                    borderRadius: 4
                }];
            }
            chartInstances.docChart = new Chart(docCtx, {
                type: 'bar',
                data: { labels: d.labels || [], datasets: docDatasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 800, easing: 'easeOutQuart' },
                    plugins: {
                        legend: legendStyle,
                        tooltip: Object.assign({}, tooltipStyle, {
                            displayColors: true,
                            callbacks: {
                                label: function (ctx) {
                                    if (!dHas) return 'No requests in this period';
                                    return ctx.dataset.label + ': ' + ctx.parsed.y;
                                },
                                footer: function (items) {
                                    if (!items.length) return '';
                                    var bucket = items[0].dataIndex;
                                    var total = (d.totals && d.totals[bucket] != null) ? d.totals[bucket] : 0;
                                    return 'Total: ' + total + ' request' + (total === 1 ? '' : 's');
                                }
                            }
                        })
                    },
                    scales: {
                        x: {
                            stacked: true,
                            grid: { display: false },
                            border: { display: false },
                            ticks: { font: { size: 11, weight: '600' }, color: '#64748b', padding: 6, autoSkip: false, maxRotation: 40, minRotation: 0 }
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            grid: { color: T.grid },
                            border: { display: false },
                            ticks: { font: { size: 11, weight: '500' }, color: T.muted, padding: 8, precision: 0 }
                        }
                    }
                }
            });
        }

        // ── 4 · RFID overview (status doughnut + activity trend) ──
        var r = charts.rfid || { labels: [], values: [], colors: [], trend_labels: [], trend_values: [], trend_source: 'none', highlight: 0 };

        var rfidCtx = mount('rfidChart');
        if (rfidCtx) {
            destroyChart('rfidChart');
            var rHas = hasData(r.values);
            if (el.rfidEmptyNote) el.rfidEmptyNote.style.display = rHas ? 'none' : 'block';
            chartInstances.rfidChart = new Chart(rfidCtx, {
                type: 'doughnut',
                plugins: [doughnutCentre],
                data: {
                    labels: rHas ? r.labels : ['No cards'],
                    datasets: [{
                        data: rHas ? r.values : [1],
                        backgroundColor: rHas ? r.colors : ['#eef2f7'],
                        borderWidth: 0,
                        borderRadius: rHas ? 6 : 0,
                        spacing: rHas ? 3 : 0,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '74%',
                    animation: { duration: 800, easing: 'easeOutQuart' },
                    plugins: {
                        legend: rHas ? legendStyle : { display: false },
                        tooltip: Object.assign({}, tooltipStyle, {
                            callbacks: {
                                label: function (ctx) {
                                    if (!rHas) return 'No RFID cards issued yet';
                                    return ctx.parsed + ' cards';
                                }
                            }
                        })
                    }
                }
            });
            chartInstances.rfidChart.$centreValue = rHas ? num(r.total) : '0';
            chartInstances.rfidChart.$centreLabel = rHas ? 'Cards' : 'None yet';
            chartInstances.rfidChart.update('none');
        }

        var trendCtx = mount('rfidTrendChart');
        if (trendCtx) {
            destroyChart('rfidTrendChart');
            var trendValues = r.trend_values || [];
            var highlight = Number(r.highlight != null ? r.highlight : trendValues.length - 1);
            chartInstances.rfidTrendChart = new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: r.trend_labels || [],
                    datasets: [{
                        label: r.trend_source === 'cards' ? 'Cards issued' : 'Kiosk taps',
                        data: trendValues,
                        borderColor: T.brand,
                        backgroundColor: 'rgba(37, 99, 235, 0.12)',
                        borderWidth: 2.5,
                        tension: 0.35,
                        fill: true,
                        pointRadius: trendValues.map(function (v, i) { return i === highlight ? 6 : 3; }),
                        pointBackgroundColor: trendValues.map(function (v, i) { return i === highlight ? T.deep : T.pale; }),
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 800, easing: 'easeOutQuart' },
                    plugins: {
                        legend: { display: false },
                        tooltip: Object.assign({}, tooltipStyle, {
                            callbacks: {
                                title: function (items) {
                                    return items[0].label + (items[0].dataIndex === highlight ? ' · reporting period' : '');
                                },
                                label: function (ctx) {
                                    return ctx.parsed.y + (r.trend_source === 'cards' ? ' cards issued' : ' kiosk taps');
                                }
                            }
                        })
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            border: { display: false },
                            ticks: { font: { size: 10, weight: '600' }, color: T.muted, maxRotation: 0, autoSkipPadding: 8 }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: T.grid },
                            border: { display: false },
                            ticks: { font: { size: 10, weight: '500' }, color: T.muted, padding: 6, precision: 0, maxTicksLimit: 4 }
                        }
                    }
                }
            });
        }

        // ── Badges and labels tied to the reporting period ──
        if (el.statusBadge) el.statusBadge.textContent = periodLabel;
        if (el.docBadge)    el.docBadge.textContent    = periodLabel;
        if (el.rfidBadge)   el.rfidBadge.textContent   = periodLabel;
        if (el.rfidTrendLabel) {
            el.rfidTrendLabel.textContent = r.trend_source === 'cards'
                ? 'Cards issued — last 12 months'
                : (r.trend_source === 'scans' ? 'Kiosk taps — last 12 months' : 'Activity — last 12 months');
        }
        if (el.rfidSubtitle) {
            el.rfidSubtitle.textContent = r.trend_source === 'scans'
                ? 'No cards registered yet — showing kiosk tap activity'
                : 'Card lifecycle and issuance activity';
        }
    }

    // ─── KPI cards ──────────────────────────────────────────────
    var trendGlyph = { up: 'fa-arrow-up', down: 'fa-arrow-down', flat: 'fa-minus' };

    function renderCards(cards) {
        if (!el.cardGrid || !Array.isArray(cards)) return;
        cards.forEach(function (card) {
            var box = el.cardGrid.querySelector('[data-card="' + card.key + '"]');
            if (!box) return;

            var valueEl = box.querySelector('.stat-value');
            if (valueEl) valueEl.textContent = num(card.value);

            var labelEl = box.querySelector('.stat-label');
            if (labelEl) labelEl.textContent = card.label;

            var trendEl = box.querySelector('.stat-trend');
            if (trendEl) {
                trendEl.className = 'stat-trend ' + card.badge.dir;
                trendEl.title = 'Compared with ' + (state.period.prev_label || 'the previous period');
                var icon = trendEl.querySelector('i');
                if (icon) icon.className = 'fas ' + (trendGlyph[card.badge.dir] || 'fa-minus');
                var textEl = trendEl.querySelector('.stat-trend-text');
                if (textEl) textEl.textContent = card.badge.text;
            }

            var footerEl = box.querySelector('.stat-footer');
            if (footerEl) {
                var dot = footerEl.querySelector('.dot');
                if (dot) dot.className = 'dot ' + card.footer.dot;
                var footerText = footerEl.querySelector('.stat-footer-text');
                if (footerText) footerText.textContent = card.footer.text;
            }
        });
    }

    // ─── Reporting period ───────────────────────────────────────
    function markReportStale(isStale) {
        state.stale = !!isStale;
        if (!state.report) return;
        if (isStale) {
            if (el.reportMetaText) {
                el.reportMetaText.textContent = 'Data refreshed for ' + (state.period.label || 'a new period')
                    + ' — select Generate AI Insight to update the analysis.';
            }
            if (el.reportSourceBadge) {
                el.reportSourceBadge.textContent = 'Out of date';
                el.reportSourceBadge.classList.add('is-fallback');
            }
        }
    }

    function loadPeriod() {
        var month = parseInt(el.month.value, 10);
        var year  = parseInt(el.year.value, 10);

        if (el.periodBox) el.periodBox.classList.add('is-busy');
        showReportError('');

        fetch(ENDPOINTS.data + '?month=' + encodeURIComponent(month) + '&year=' + encodeURIComponent(year), {
            headers: { 'Accept': 'application/json' }
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json || !json.success || !json.data) {
                    throw new Error((json && json.message) || 'Unable to load analytics for that period.');
                }
                state.period = Object.assign({}, state.period, json.data.period);
                state.cards  = json.data.cards;
                state.charts = json.data.charts;

                renderCards(state.cards);
                renderCharts({ period: state.period, charts: state.charts });
                markReportStale(true);
            })
            .catch(function (err) {
                showReportError('Could not refresh the dashboard: ' + err.message);
            })
            .then(function () {
                if (el.periodBox) el.periodBox.classList.remove('is-busy');
            });
    }

    // ─── Report states ──────────────────────────────────────────
    function showReportError(message) {
        if (!el.reportError) return;
        if (!message) {
            el.reportError.style.display = 'none';
            el.reportError.textContent = '';
            return;
        }
        el.reportError.textContent = message;
        el.reportError.style.display = 'block';
    }



    // ─── AI ANALYSIS REPORT rendering ───────────────────────────
    function inlineFormat(str) {
        return str.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    }

    /** Markdown → HTML for the three-section report shape. */
    function markdownToHtml(report) {
        var lines = String(report).split('\n');
        var html = '';
        var current = null;   // { num, title }
        var body = '';
        var inList = false;

        function flush() {
            if (!current) return;
            if (inList) { body += '</ul>'; inList = false; }
            var badge = current.num ? '<span class="report-section-num">' + current.num + '</span>' : '';
            html += '<div class="report-section report-section-' + (current.num || 'plain') + '">'
                + '<div class="report-section-head">' + badge + '<h2>' + inlineFormat(escapeHtml(current.title)) + '</h2></div>'
                + body
                + '</div>';
            body = '';
        }

        lines.forEach(function (line) {
            var numbered = /^##\s+(\d+)\.\s*(.*)$/.exec(line);
            var plain    = /^##\s+(.+)$/.exec(line);
            if (numbered) {
                flush();
                current = { num: parseInt(numbered[1], 10), title: numbered[2].trim() };
            } else if (plain) {
                flush();
                current = { num: 0, title: plain[1].trim() };
            } else if (/^\s*[-*]\s+/.test(line)) {
                if (!inList) { body += '<ul>'; inList = true; }
                body += '<li>' + inlineFormat(escapeHtml(line.replace(/^\s*[-*]\s+/, ''))) + '</li>';
            } else if (line.trim() === '') {
                if (inList) { body += '</ul>'; inList = false; }
            } else {
                if (inList) { body += '</ul>'; inList = false; }
                body += '<p>' + inlineFormat(escapeHtml(line)) + '</p>';
            }
        });
        flush();
        return html;
    }

    /**
     * The printable rendering of the report: plain headings, paragraphs and
     * bullets, with no card, badge or border wrappers.
     *
     * markdownToHtml() cannot be reused here. It is shared with the on-screen
     * view, where the boxed .report-section cards are wanted, and its output
     * is <div>-wrapped. Stripping the wrappers by regex here would be brittle
     * — it would depend on the exact class strings the other function emits,
     * so an unrelated restyle of the screen view could quietly corrupt the
     * printout. Parsing the same small Markdown subset directly keeps the two
     * renderings independent.
     */
    function reportBodyHtml(report) {
        var lines = String(report).split('\n');
        var html = '';
        var inList = false;

        function closeList() {
            if (inList) { html += '</ul>'; inList = false; }
        }

        lines.forEach(function (line) {
            // "## 1. Title" keeps its number: the model contract guarantees
            // three numbered sections, and a formal report cites them by
            // number. "## Title" (no number) is used as-is.
            var numbered = /^##\s+(\d+)\.\s*(.+)$/.exec(line);
            var heading  = /^##\s+(.+)$/.exec(line);
            var bullet   = /^\s*[-*]\s+(.+)$/.exec(line);

            if (numbered) {
                closeList();
                html += '<h2 class="doc-h">' + numbered[1] + '. '
                    + inlineFormat(escapeHtml(numbered[2].trim())) + '</h2>';
            } else if (heading) {
                closeList();
                html += '<h2 class="doc-h">' + inlineFormat(escapeHtml(heading[1].trim())) + '</h2>';
            } else if (bullet) {
                if (!inList) { html += '<ul>'; inList = true; }
                html += '<li>' + inlineFormat(escapeHtml(bullet[1])) + '</li>';
            } else if (line.trim() === '') {
                closeList();
            } else {
                closeList();
                html += '<p>' + inlineFormat(escapeHtml(line.trim())) + '</p>';
            }
        });
        closeList();
        return html;
    }

    function showLoading() {
        el.reportEmpty.style.display = 'none';
        el.reportOutput.style.display = 'none';
        el.reportMeta.style.display = 'none';
        el.reportFooter.style.display = 'none';
        showReportError('');
        el.reportLoading.style.display = 'block';
        el.generate.disabled = true;
        el.generate.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating…';
    }

    function hideLoading() {
        el.reportLoading.style.display = 'none';
        el.generate.disabled = false;
        // A report already exists for this period → the next click
        // regenerates it with a forced fresh model call.
        el.generate.innerHTML = state.report
            ? '<i class="fas fa-rotate"></i> Regenerate AI Insight'
            : '<i class="fas fa-wand-magic-sparkles"></i> Generate AI Insight';
    }

    function renderReport(report, meta) {
        state.report = report;
        state.source = meta.source || 'ai';
        if (meta.facts)  state.facts  = meta.facts;
        if (meta.cards)  state.cards  = meta.cards;
        state.stale = false;

        el.reportOutput.innerHTML = '<div class="ai-report-title"><i class="fas fa-chart-line"></i> AI Analysis Report</div>'
            + markdownToHtml(report);
        el.reportOutput.style.display = 'block';
        el.reportFooter.style.display = 'block';

        // Source badge + meta line keep the report honest about who wrote it.
        if (el.reportSourceBadge) {
            if (state.source === 'ai') {
                el.reportSourceBadge.textContent = 'AI-generated' + (meta.model ? ' · ' + meta.model : '');
                el.reportSourceBadge.classList.remove('is-fallback');
            } else {
                el.reportSourceBadge.textContent = 'Rule-based summary';
                el.reportSourceBadge.classList.add('is-fallback');
            }
        }
        if (el.reportMetaText) {
            var text = (meta.period && meta.period.label) ? meta.period.label : state.period.label;
            text += ' · generated ' + new Date().toLocaleString();
            if (state.source === 'fallback' && meta.ai_error) {
                text += ' · AI gateway unavailable: ' + meta.ai_error;
            }
            el.reportMetaText.textContent = text;
        }
        el.reportMeta.style.display = 'flex';

        el.printBtn.style.display  = 'inline-flex';
        el.exportBtn.style.display = 'inline-flex';
    }

    function generateReport(force) {
        showLoading();
        var started = Date.now();
        var slowTimer = window.setInterval(function () {
            if (Date.now() - started > 20000) {
                var t = document.getElementById('reportLoadingText');
                if (t) t.textContent = 'Contacting the AI model — this can take a moment…';
                window.clearInterval(slowTimer);
            }
        }, 1000);

        fetch(ENDPOINTS.report, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                filters: {
                    month: parseInt(el.month.value, 10),
                    year:  parseInt(el.year.value, 10)
                },
                force: !!force
            })
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                window.clearInterval(slowTimer);
                hideLoading();
                var t = document.getElementById('reportLoadingText');
                if (t) t.textContent = 'Analysing registrar data…';

                if (json && json.success && json.data && json.data.report) {
                    renderReport(json.data.report, json.data);
                } else {
                    showReportError((json && json.message) || 'Failed to generate the analysis.');
                    el.reportEmpty.style.display = 'block';
                }
            })
            .catch(function (err) {
                window.clearInterval(slowTimer);
                hideLoading();
                showReportError('Network error: ' + err.message);
                el.reportEmpty.style.display = 'block';
            });
    }




    // ─── Printable executive report ─────────────────────────────
    /**
     * Prints the report: BCP letterhead, the AI analysis as plain prose,
     * and the signature block. "Export PDF" reuses this (print → save as PDF).
     *
     * The report is written into a hidden <iframe> rather than a pop-up
     * window. window.open() is the convention elsewhere in this project
     * (registrar/masterlist.php), but it spawns a new tab the user has to
     * dismiss, which is jarring when the only thing wanted is a sheet of
     * paper. An iframe prints from the current tab and the page the user is
     * already on is never disturbed.
     *
     * The charts and KPI tiles were removed on purpose: this is a formal
     * document, and the analysis is the content. The figures behind the
     * analysis stay available in the data endpoint and on screen.
     */
    function openPrintView() {
        if (!state.report) return;

        var logo = new URL('../assets/images/BCP_LOGO.png', window.location.href).href;
        var periodLabel = state.period.label || '';
        var generated = new Date().toLocaleString();

        // The gateway note is deliberately NOT printed. "rule-based summary
        // (AI gateway unavailable)" is an operational detail for whoever is
        // operating the screen, and it made the printout look like an error
        // on an official document. The source badge on screen still reports
        // it, so nothing is hidden from the user who needs to know.
        var body = ''
            + BCPPrint.headerHtml({
                logoUrl: logo,
                title: 'AI INSIGHT REPORT — ' + String(periodLabel).toUpperCase()
            })
            + '<div class="meta">Reporting period: ' + escapeHtml(periodLabel)
            + ' (compared with ' + escapeHtml(state.period.prev_label || '—')
            + ') · Generated: ' + escapeHtml(generated)
            + '</div>'
            // The report is the document. No KPI tiles and no chart
            // snapshots — those are a screen view; the printout is the
            // written analysis.
            + '<div class="doc-body">' + reportBodyHtml(state.report) + '</div>'
            + '<div class="sig"><div class="box"><div class="line">Prepared by:<br>Registrar</div></div>'
            + '<div class="box"><div class="line">Noted by:<br>School Head / President</div></div></div>'
            + '<div class="foot-note">Generated by: Registrar Information System<br>'
            + 'AI-generated information is provided for administrative reference.</div>';

        if (!BCPPrint.printDocument({ title: periodLabel, body: body })) {
            showReportError('Unable to prepare the report for printing.');
        }
    }

    // ─── Export ───────────────────────────────────────────────────────────────────────────────────────────────
    function periodSlug() {
        return el.year.value + '-' + ('0' + el.month.value).slice(-2);
    }

    function downloadBlob(content, filename, mime) {
        var blob = new Blob([content], { type: mime });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    function exportTxt() {
        if (!state.report) return;
        var text = 'AI ANALYSIS REPORT\n';
        text += 'Bestlink College of the Philippines — Office of the Registrar\n';
        text += 'Reporting period: ' + (state.period.label || '') + ' (compared with ' + (state.period.prev_label || '—') + ')\n';
        text += 'Generated: ' + new Date().toLocaleString() + '\n';
        text += 'Source: ' + (state.source === 'ai' ? 'AI-generated' : 'Rule-based summary (AI gateway unavailable)') + '\n';
        text += Array(64).join('=') + '\n\n';
        text += state.report + '\n\n';
        text += Array(64).join('-') + '\n';
        text += 'Generated by: Registrar Information System\n';
        text += 'AI-generated information is provided for administrative reference.\n';

        downloadBlob(text, 'ai-insights-' + periodSlug() + '.txt', 'text/plain;charset=utf-8');
    }

    /** Every figure that fed the analysis, as a flat CSV (auditable). */
    function exportCsv() {
        if (!state.facts) return;
        var f = state.facts;
        var rows = [['Metric', 'Value']];
        function add(metric, value) { rows.push([metric, value]); }

        add('Reporting period', f.period.label);
        add('Period start', f.period.start);
        add('Period end', f.period.end);
        add('Comparison period', f.period.prev_label);
        add('Report source', state.source === 'ai' ? 'AI-generated' : 'Rule-based summary');

        add('Total students', f.students.total);
        add('Active or enrolled', f.students.active_enrolled);
        add('New registrations in period', f.students.new_in_period);
        add('New registrations previous period', f.students.new_in_previous);
        Object.keys(f.students.by_status || {}).forEach(function (k) {
            add('Students — ' + k, f.students.by_status[k]);
        });

        add('Document requests in period', f.documents.total_in_period);
        add('Document requests previous period', f.documents.total_previous);
        add('Documents pending', f.documents.pending);
        add('Documents completed', f.documents.completed);
        add('Documents rejected', f.documents.rejected);
        Object.keys(f.documents.by_type || {}).forEach(function (k) {
            add('Document type — ' + k, f.documents.by_type[k]);
        });
        Object.keys(f.documents.by_stage || {}).forEach(function (k) {
            add('Workflow stage — ' + k, f.documents.by_stage[k]);
        });

        add('RFID cards total', f.rfid.total_cards);
        add('RFID cards active', f.rfid.active);
        add('RFID cards expired', f.rfid.expired);
        add('RFID cards lost', f.rfid.lost);
        add('RFID cards inactive', f.rfid.inactive);
        add('RFID issued in period', f.rfid.issued_in_period);
        add('RFID activity source', f.rfid.activity_source);
        add('Kiosk taps (12 months)', f.rfid.kiosk_taps_total);

        add('Queue students in period', f.queue.students_in_period);
        add('Queue students previous period', f.queue.students_previous);
        add('Queue tickets in period', f.queue.tickets_in_period);
        add('Queue tickets previous period', f.queue.tickets_previous);
        add('Queue completed', f.queue.served);
        add('Queue cancelled / no-show', f.queue.cancelled_or_no_show);
        add('Queue walk-ins without student record', f.queue.walk_ins);
        add('Queue tickets today', f.queue.tickets_today);
        add('Busiest queue day', f.queue.busiest_day || '');
        add('Busiest day tickets', f.queue.busiest_day_tickets);

        (f.programs.top || []).forEach(function (p) {
            add('Program — ' + p.course, p.students + ' (' + p.new_in_period + ' new)');
        });

        var csv = rows.map(function (row) {
            return row.map(function (cell) {
                return '"' + String(cell == null ? '' : cell).replace(/"/g, '""') + '"';
            }).join(',');
        }).join('\r\n');

        downloadBlob('\uFEFF' + csv, 'ai-insights-' + periodSlug() + '.csv', 'text/csv;charset=utf-8;');
    }

    // ─── Wiring ─────────────────────────────────────────────────
    function closeExportMenu() {
        if (el.exportMenu) el.exportMenu.classList.remove('is-open');
    }

    if (el.generate) {
        el.generate.addEventListener('click', function () {
            // Second run for the same period forces a fresh model call.
            generateReport(!!state.report);
        });
    }

    [el.month, el.year].forEach(function (select) {
        if (!select) return;
        select.addEventListener('change', loadPeriod);
    });

    if (el.printBtn)  el.printBtn.addEventListener('click', openPrintView);
    if (el.exportPdf) el.exportPdf.addEventListener('click', function (e) { e.preventDefault(); closeExportMenu(); openPrintView(); });
    if (el.exportCsv) el.exportCsv.addEventListener('click', function (e) { e.preventDefault(); closeExportMenu(); exportCsv(); });
    if (el.exportTxt) el.exportTxt.addEventListener('click', function (e) { e.preventDefault(); closeExportMenu(); exportTxt(); });

    if (el.exportBtn) {
        el.exportBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (el.exportMenu) el.exportMenu.classList.toggle('is-open');
        });
    }
    document.addEventListener('click', closeExportMenu);

    // ─── First paint (server-rendered payload) ──────────────────
    renderCards(state.cards);
    renderCharts({ period: state.period, charts: state.charts });
})();
