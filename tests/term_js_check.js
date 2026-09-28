// Extract the <script> block from the rendered page and syntax-check it.
//   node tests/term_js_check.js < html
//
// The page's behaviour lives almost entirely in this one block, and PHP
// will happily render a script that does not parse. A single comma in
// the wrong place would leave the page rendering perfectly and doing
// nothing, so the block is checked on its own terms.

const fs = require('fs');
const vm = require('vm');

const html = fs.readFileSync(process.argv[2] || 'ah_render.html', 'utf8');
const blocks = [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)]
    .map(m => m[1]);

if (blocks.length === 0) {
    console.error('  FAIL  no inline script block found');
    process.exit(1);
}

let fail = 0;
const inline = blocks[blocks.length - 1];

try {
    new vm.Script(inline, {filename: 'academic-history-inline.js'});
    console.log(`  ok    the inline script parses (${inline.length} bytes)`);
} catch (e) {
    fail++;
    console.log(`  FAIL  the inline script does not parse: ${e.message}`);
}

// The functions the page's markup calls by name must actually exist, or
// every button silently does nothing.
const required = [
    'openGrades', 'closeGrades', 'addGradeRow', 'removeGradeRow',
    'updatePreview', 'saveGrades', 'openAudit', 'closeAudit',
    'previewGwa', 'readGrid', 'gradeRowHtml', 'findingHtml', 'esc', 'band',
];
for (const fn of required) {
    if (new RegExp(`function\\s+${fn}\\s*\\(`).test(inline)) {
        console.log(`  ok    ${fn} is defined`);
    } else {
        fail++;
        console.log(`  FAIL  ${fn} is called but not defined`);
    }
}

// The CSRF token has to reach the request, or every save is refused with
// a 419 and the reason is not obvious from the UI.
if (/X-CSRF-Token/.test(inline)) {
    console.log('  ok    the save sends a CSRF token');
} else {
    fail++;
    console.log('  FAIL  the save sends no CSRF token');
}

// The audit must not reach a write endpoint. It is a reporting tool; a
// fetch in that path would be a finding that changes a record.
const auditFn = inline.slice(inline.indexOf('function openAudit'));
if (auditFn && !/fetch\(/.test(auditFn)) {
    console.log('  ok    the audit makes no request');
} else {
    fail++;
    console.log('  FAIL  the audit appears to make a request');
}

console.log(`\n  ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
