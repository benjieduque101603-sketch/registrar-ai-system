// The client-side GWA preview must match the server's, exactly.
//
//   node tests/term_gwa_parity.js
//
// previewGwa() in registrar/academic-history.php re-implements the
// weighting in JavaScript so it can run on every keystroke, and the server
// computes the real one in shared/term_grades.php. Two implementations of
// one number is the exact problem this rework set out to remove, so the
// two are pinned against the same cases here. If they drift, a registrar
// sees 2.10 in the bar, saves, and the stored figure is something else.

const fs = require('fs');
const path = require('path');

// The cases come from the PHP side so there is one list, not two.
const cases = JSON.parse(fs.readFileSync(path.join(__dirname, 'term_gwa_cases.json'), 'utf8'));
const expected = JSON.parse(fs.readFileSync(path.join(__dirname, 'term_gwa_expected.json'), 'utf8'));

// Pull previewGwa straight out of the rendered page, so this tests the
// shipped code rather than a copy of it.
const html = fs.readFileSync(path.join(__dirname, '..', 'ah_render.html'), 'utf8');
const inline = [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)]
    .map(m => m[1]).pop();

const previewSrc = inline.match(/function previewGwa[\s\S]*?\n}/);
if (!previewSrc) {
    console.error('  FAIL  previewGwa is not in the rendered page');
    process.exit(1);
}
const previewGwa = new Function(`${previewSrc[0]}; return previewGwa;`)();

let fail = 0;
cases.forEach((subjects, i) => {
    const got = previewGwa(subjects);
    const want = expected[i];
    const same = (got === null && want === null) || (got !== null && Math.abs(got - want) < 1e-9);
    if (same) {
        console.log(`  ok    ${JSON.stringify(subjects).slice(0, 58)} -> ${got}`);
    } else {
        fail++;
        console.log(`  FAIL  ${JSON.stringify(subjects).slice(0, 58)} -> client ${got}, server ${want}`);
    }
});

console.log(`\n  ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
