// Exercise the read-only grade view against the real inline script.
//   node tests/view_render_check.js < ah_render.html
//
// The view is the one dialog on this page that cannot be checked by
// reading its markup, because everything it shows is built in JS. A
// typo in viewRowHtml would leave the dialog silently empty, and a
// regression that dropped the escaping would put whatever a subject is
// called straight into innerHTML.
//
// So the functions are run here against the script the page actually
// ships, not against a copy that can drift from it.

const fs = require('fs');
const vm = require('vm');

const html = fs.readFileSync(process.argv[2] || 'ah_render.html', 'utf8');
const mine = html.match(/<script[^>]*>((?:(?!<\/script>)[\s\S])*?const AH =[\s\S]*?)<\/script>/);
if (!mine) {
    console.error('  FAIL  this page\'s script block was not found');
    process.exit(1);
}

let fail = 0;
const ok = m => console.log(`  ok    ${m}`);
const bad = m => { fail++; console.log(`  FAIL  ${m}`); };

// A DOM just large enough for the view to render into. Anything the
// view reaches for that is not here is a bug, not a test gap.
let sink = '';
const stub = id => ({
    _id: id,
    set textContent(v) { sink += v + '\n'; },
    get textContent() { return ''; },
    set innerHTML(v) { sink = v; },
    get innerHTML() { return sink; },
    insertAdjacentHTML(_pos, h) { sink += h; },
    addEventListener() {},
    querySelector() { return null; },
    querySelectorAll() { return []; },
    classList: { add() {}, remove() {} },
});
const els = {};
const sandbox = {
    document: {
        getElementById: id => (els[id] = els[id] || stub(id)),
        querySelector: () => null,
        addEventListener() {},
        contains: () => true,
        activeElement: null,
        body: { style: {} },
    },
    window: {},
    console,
    isFinite, Number, Math, String, JSON,
    fetch: () => Promise.resolve({ ok: true, json: () => ({}) }),
};
sandbox.globalThis = sandbox;
vm.createContext(sandbox);

// The page's own esc() and the view functions, in one context, so the
// escaping under test is the escaping that ships.
const body = mine[1]
    .replace(/^\s*'use strict';/, '')
    .replace(/^const AH = [\s\S]*?JSON_UNESCAPED_UNICODE\);?$/m,
        'const AH = {"initials":{},"rows":[],"sy":"2026-2028","sem":"1st","csrf":"x"};');

let api;
try {
    // AH comes back out with the functions: it is a const inside the
    // block, and the tests below need to put a roster in it and see
    // what the view makes of it.
    api = new vm.Script(
        `(function(){ ${body} ; return {openView, viewRowHtml, ratingBand, AH}; })()`,
        { filename: 'ah-view.js' }
    ).runInContext(sandbox);
} catch (e) {
    bad(`the view functions could not be loaded: ${e.message}`);
    process.exit(1);
}
const { openView, viewRowHtml, ratingBand, AH } = api;

// ── Ratings are written to a fixed precision ─────────────────────
// A ledger column with 1.5 next to 1.75 reads as two precisions
// rather than two grades.
{
    const out = [1.5, 2, 1.75, 3].map(v => viewRowHtml({ subject: 'S', units: 3, final_rating: v })
        .match(/ah-view-rating"[^>]*>([^<]*)</)[1]);
    const want = ['1.50', '2.00', '1.75', '3.00'];
    if (JSON.stringify(out) === JSON.stringify(want)) ok(`ratings print to two decimals (${out.join(', ')})`);
    else bad(`ratings are not uniform: got ${JSON.stringify(out)}, want ${JSON.stringify(want)}`);
}

// ── An unrated subject says so in words ──────────────────────────
// null, '' and undefined all have to land on the same word.
{
    const out = [null, '', undefined].map(v =>
        viewRowHtml({ subject: 'S', units: 3, final_rating: v })
            .match(/ah-view-rating"[^>]*>([^<]*)</)[1]);
    if (out.every(v => v === 'not rated')) ok('an unrated subject reads "not rated" for null, empty and undefined');
    else bad(`unrated subjects render as ${JSON.stringify(out)}, want "not rated" three times`);
}

// ── Bands match the roster's ─────────────────────────────────────
// GWA_AT_RISK is 3.00 and the good band stops half a point under it.
{
    const want = { '1.00': 'good', '2.49': 'good', '2.5': 'warn', '2.99': 'warn', '3': 'poor', '5': 'poor' };
    const out = {};
    for (const k of Object.keys(want)) out[k] = ratingBand(Number(k));
    if (JSON.stringify(out) === JSON.stringify(want)) ok('rating bands match the server thresholds');
    else bad(`bands drifted: got ${JSON.stringify(out)}, want ${JSON.stringify(want)}`);
    for (const v of [null, '', undefined, 'abc']) {
        if (ratingBand(v) === 'none') ok(`no rating bands as "none" (${JSON.stringify(v)})`);
        else bad(`${JSON.stringify(v)} banded as "${ratingBand(v)}", want "none"`);
    }
}

// ── A subject name is never trusted ──────────────────────────────
// Subject names are typed by staff, and this one lands in innerHTML.
{
    const h = viewRowHtml({
        subject: '<img src=x onerror=alert(1)>', units: 3,
        final_rating: 1, grade_status: '<b>dropped</b>',
    });
    if (h.includes('&lt;img') && !h.includes('<img')) ok('a subject name is escaped before it reaches innerHTML');
    else bad('the subject name is not escaped: ' + h.slice(0, 90));
    if (h.includes('&lt;b&gt;')) ok('a result value is escaped too');
    else bad('the result value is not escaped: ' + h.slice(0, 90));
}

// ── The record ends in a total ───────────────────────────────────
// A ledger that does not total is a list, and the total has to be the
// figure the roster shows, not a second sum computed here.
{
    AH.rows = [{
        id: 1, name: 'A B', number: '1', program: 'BSIT', level: '1', section: 'A',
        subjects: [
            { subject: 'IT 101', units: 3, final_rating: 1.5, grade_status: 'passed' },
            { subject: 'IT 102', units: 3, final_rating: 1.75, grade_status: 'passed' },
        ],
        gwa: 1.65, units: 6, state: 'complete', missing: 0,
    }];
    AH.initials = { 1: 'AB' };
    openView(1);
    const row = sink.match(/<tr class="ah-view-total">[\s\S]*?<\/tr>/);
    if (!row) bad('the total row was not rendered');
    else {
        if (row[0].includes('2 subjects')) ok('the total counts the subjects');
        else bad('the total does not count the subjects: ' + row[0]);
        if (row[0].includes('6 units')) ok('the total carries the stored unit count');
        else bad('the total lost the unit count: ' + row[0]);
        if (row[0].includes('1.65')) ok('the total shows the stored GWA');
        else bad('the total does not show the stored GWA: ' + row[0]);
    }
}

// ── A term with no subjects says so ─────────────────────────────
{
    AH.rows = [{ id: 2, name: 'C D', number: '2', program: '', level: '', section: '', subjects: [], gwa: null, units: 0, state: 'none', missing: 0 }];
    openView(2);
    if (/No subjects recorded/.test(sink)) ok('a term with no subjects says so in words');
    else bad('a term with no subjects renders an unexplained empty table');
    if (!/ah-view-total/.test(sink)) ok('an empty record has no total row, because there is nothing to total');
    else bad('an empty record shows a total row');
}

// ── The view cannot edit ─────────────────────────────────────────
// The whole point of this dialog. Enforced by the markup, so this
// checks the markup that ships rather than the JS that fills it.
{
    const m = html.match(/<div[^>]*id="viewModal"[\s\S]*?<\/div>\s*<\/div>/);
    if (!m) bad('the view dialog was not found in the markup');
    else {
        if (!/<input|<select|<textarea/.test(m[0])) ok('the view dialog contains no form control');
        else bad('the view dialog contains a form control, so it is not read-only');
        if (/saveGrades|addGradeRow|removeGradeRow/.test(m[0])) bad('the view dialog offers a way to change something');
        else ok('the view dialog offers no way to change something');
        if (/aria-modal="true"/.test(m[0]) && /aria-labelledby="viewModalTitle"/.test(m[0])) ok('the view dialog is a labelled modal');
        else bad('the view dialog is missing its modal semantics');
    }
}

// The roster must be able to open it.
if (/openView\(/.test(html)) ok('a roster row can open the view');
else bad('nothing on the page calls openView');

console.log(`  ${fail} failed`);
process.exit(fail ? 1 : 0);
