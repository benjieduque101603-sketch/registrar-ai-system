// The student portal must show the same GWA as the registrar and the
// Transcript of Records, from the same helper.
//
// student/grades.php and student/academic-records.php each used to carry
// their own inline average over the legacy `grade` column, restricted to
// 1.0-3.0. The registrar form writes `final_rating` on a 1.0-5.0 scale
// and leaves `grade` NULL, so that loop found nothing and both student
// pages showed an em dash for a term whose real GWA was 1.77.
//
// This pins the two things that let that happen: that the pages call the
// shared helper, and that the legacy formula is gone.
//
//   node tests/student_gwa_parity.js
const fs = require('fs');
const path = require('path');

let pass = 0, fail = 0;
function check(name, got, want) {
    if (got === want) { pass++; console.log(`  ok   ${name}`); }
    else { fail++; console.log(`  FAIL ${name}\n       got:  ${got}\n       want: ${want}`); }
}

console.log('student GWA parity:');
for (const page of ['grades.php', 'academic-records.php']) {
    const src = fs.readFileSync(path.join(__dirname, '..', 'student', page), 'utf8');

    check(`${page} uses the shared helper`,
        /require_once[^;]*shared\/term_grades\.php/.test(src), true);
    check(`${page} computes GWA with careerGwa()`,
        /careerGwa\s*\(/.test(src), true);

    // The legacy formula, in either formatting.
    check(`${page} has no inline grade average`,
        /gradeValues/.test(src), false);
    check(`${page} does not hardcode a 1.0-3.0 range`,
        /\$gv\s*<=\s*3\.0/.test(src), false);

    // Reading the legacy `grade` column is allowed ONLY as a display
    // fallback behind final_rating - that is how a row entered before
    // the 1.0-5.0 scale still renders. What must never come back is a
    // computation off it, which is what produced the em dash.
    check(`${page} prefers final_rating over the legacy column`,
        (src.match(/final_rating/g) || []).length > 0, true);
    check(`${page} has no GWA computed from the legacy column`,
        /careerGwa\([\s\S]{0,200}?\[\s*['"]grade['"]/.test(src), false);
}

console.log('\nregistrar side (must keep using the helper):');
const ah = fs.readFileSync(path.join(__dirname, '..', 'registrar', 'academic-history.php'), 'utf8');
check('academic-history.php still uses shared/term_grades.php',
    /require_once[^;]*shared\/term_grades\.php/.test(ah), true);
check('academic-history.php still uses termGwa()',
    /termGwa\s*\(/.test(ah), true);

// ── Term picker ──────────────────────────────────────────────
// Both student pages let the student pick a school year and semester.
// The options must come from that student's own rows, and the resolution
// must be shared so the two pages cannot disagree about what exists.
console.log('\nstudent term picker:');
const tg = fs.readFileSync(path.join(__dirname, '..', 'shared', 'term_grades.php'), 'utf8');
check('helper builds the options', /function studentTermOptions\s*\(/.test(tg), true);
check('helper resolves the choice', /function resolveStudentTerm\s*\(/.test(tg), true);

for (const page of ['grades.php', 'academic-records.php']) {
    const src = fs.readFileSync(path.join(__dirname, '..', 'student', page), 'utf8');
    check(`${page} offers a school-year select`, /name="sy"/.test(src), true);
    check(`${page} offers a semester select`, /name="sem"/.test(src), true);
    check(`${page} uses the shared options`, /studentTermOptions\s*\(/.test(src), true);
    check(`${page} uses the shared resolver`, /resolveStudentTerm\s*\(/.test(src), true);
    // A GET form, so the choice survives a refresh and works without JS.
    check(`${page} picker is a GET form`, /<form[^>]*class="st-picker"[^>]*method="get"/.test(src), true);
    check(`${page} picker has a submit button`,
        /<button[^>]*type="submit"[^>]*st-picker-go/.test(src), true);
    // Career figures must not be recomputed from the filtered set.
    check(`${page} keeps a career figure`,
        /careerGwa\([\s\S]{0,200}?\$allTerms/.test(src), true);
}

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
