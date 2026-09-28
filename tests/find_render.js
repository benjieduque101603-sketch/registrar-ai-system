// Render the drawer's finding cards without a browser, and assert their
// structure.
//
//   node tests/find_render.js
//
// The drawer is built entirely in JavaScript, so a PHP render of the page
// proves nothing about it: the markup only exists once renderMissedCards
// has run against a real response. This exercises that function with a
// stubbed DOM and checks the structure a human is meant to read - a named
// student, a status, each contradiction, and the question attached to it.
// A missing wrapper or a dropped class is invisible to every other check
// in the suite, and invisible is exactly how a drawer ends up rendering
// as an unstyled wall of text.
//
// The function below is a copy of the one in registrar/status-tracker.php.
// It is duplicated rather than imported because the page has no build
// step and no module loader; the copy is what makes the test runnable at
// all. If the two drift, the page is the source of truth.

const STATUS_META = {
  active:   { color: '#16a34a', bg: '#f0fdf4', icon: 'fas fa-user-check' },
  enrolled: { color: '#2563eb', bg: '#eff6ff', icon: 'fas fa-user-plus' },
  'at-risk':{ color: '#ef4444', bg: '#fef2f2', icon: 'fas fa-shield-halved' },
  loa:      { color: '#6366f1', bg: '#eef2ff', icon: 'fas fa-pause-circle' },
  inactive: { color: '#94a3b8', bg: '#f1f5f9', icon: 'fas fa-user-slash' },
};

function escapeHTML(str) {
  return String(str).replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

function renderMissedCards(data) {
  const d = (data && data.data) || data || {};
  const list = d.findings || [];
  const count = list.length;
  let h = '';
  if (d.partial) {
    h += '<div class="st-find-warn"><i class="fas fa-triangle-exclamation"></i> Some records could not be read, so this list is incomplete.</div>';
  }
  if (!count) {
    return { html: h + '<div class="st-find-clear"><i class="fas fa-circle-check"></i><b>Nothing contradicts itself</b><span>Every record on the roster agrees with itself. New findings land here as soon as one does not.</span></div>', count: 0 };
  }
  list.forEach((f, i) => {
    const meta = STATUS_META[f.current_status] || STATUS_META.inactive;
    const parts = String(f.student_name || '?').trim().split(' ');
    const initials = (parts[0] ? parts[0][0] : '') + (parts.length > 1 ? parts[parts.length - 1][0] : '');
    const issues = f.issues || [];
    h += '<article class="st-find" id="missed-' + i + '">';
    h += '<header class="st-find-head">';
    h += '<span class="st-find-av" style="background:' + meta.bg + ';color:' + meta.color + '">' + escapeHTML(initials.toUpperCase()) + '</span>';
    h += '<span class="st-find-who"><b>' + escapeHTML(f.student_name) + '</b><span>' + escapeHTML(f.student_number || 'No ID') + '</span></span>';
    h += '<span class="st-find-badge" style="background:' + meta.bg + ';color:' + meta.color + '">' + escapeHTML(String(f.current_status || 'unset').replace(/-/g, ' ')) + '</span>';
    h += '</header>';
    if (issues.length > 1) h += '<p class="st-find-n">' + issues.length + ' contradictions in this record</p>';
    h += '<ul class="st-find-list">';
    issues.forEach(iss => {
      h += '<li class="st-find-iss">';
      h += '<span class="st-find-ico"><i class="fas fa-triangle-exclamation"></i></span>';
      h += '<div class="st-find-issbody">';
      h += '<b>' + escapeHTML(iss.title) + '</b>';
      h += '<p>' + escapeHTML(iss.detail) + '</p>';
      h += '<p class="st-find-q"><i class="fas fa-circle-question"></i><span>' + escapeHTML(iss.question) + '</span></p>';
      h += '</div></li>';
    });
    h += '</ul>';
    h += '<footer class="st-find-foot">';
    h += '<button class="st-btn-apply" onclick="openFromCard(\'missed-' + i + '\',' + parseInt(f.student_id) + ')">Read the case</button>';
    h += '<button class="st-btn-dismiss" onclick="dismissRec(\'missed-' + i + '\')">Dismiss</button>';
    h += '</footer></article>';
  });
  return { html: h, count: count };
}


// â”€â”€ Checks â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
let fail = 0, pass = 0;
const check = (label, cond) => {
  if (cond) { pass++; console.log('  ok    ' + label); }
  else { fail++; console.log('  FAIL  ' + label); }
};

console.log('findings drawer - rendered card structure');

const one = renderMissedCards({
  findings: [{
    student_id: 7, student_name: 'Dela Cruz', student_number: '2024-0117',
    current_status: 'active',
    issues: [{ title: 'Unresolved disciplinary case', detail: '1 pending (open 30 days).', question: 'Resolve or dismiss before reviewing.' }],
  }],
});
const a = one.html;
check('returns one finding', one.count === 1);
check('card is an article', /<article class="st-find"/.test(a));
check('names the student', a.includes('Dela Cruz'));
check('shows the student number', a.includes('2024-0117'));
check('shows the status', /st-find-badge[^>]*>active</.test(a));
check('initials come from first and last name', a.includes('>DC<'));
check('carries the issue title', a.includes('Unresolved disciplinary case'));
check('carries the issue detail', a.includes('1 pending (open 30 days).'));
check('carries the question', a.includes('Resolve or dismiss before reviewing.'));
check('question is set apart from the record', a.includes('st-find-q'));
check('links into the case', /openFromCard\('missed-0',7\)/.test(a));
check('offers dismiss', /dismissRec\('missed-0'\)/.test(a));
check('no summary line on a single issue', !a.includes('st-find-n'));

const two = renderMissedCards({
  findings: [{
    student_id: 9, student_name: 'Lim', student_number: '2024-0132',
    current_status: 'loa',
    issues: [
      { title: 'Leave window has closed', detail: 'Ended 3 Mar 2026.', question: 'Return or extend?' },
      { title: 'Outstanding balance', detail: 'PHP 1,200.00', question: 'Does it block this status?' },
    ],
  }],
});
const b = two.html;
check('two issues on one student', (b.match(/st-find-iss"/g) || []).length === 2);
check('states how many contradictions', b.includes('2 contradictions in this record'));
check('both questions render', b.includes('Return or extend?') && b.includes('Does it block this status?'));

const odd = renderMissedCards({
  findings: [{ student_id: 3, student_name: 'Solo Name', student_number: '', current_status: 'transferred',
    issues: [{ title: 'T', detail: 'D', question: 'Q' }] }],
});
check('unknown status falls back, no throw', odd.count === 1 && odd.html.includes('>transferred<'));
check('missing student number handled', odd.html.includes('No ID'));

const none = renderMissedCards({ findings: [] });
check('empty result is not an error', none.count === 0);
check('empty result explains itself', none.html.includes('Nothing contradicts itself'));

const partial = renderMissedCards({ findings: [], partial: true });
check('partial read is announced', partial.html.includes('st-find-warn'));

const nasty = renderMissedCards({
  findings: [{ student_id: 1, student_name: '<img src=x onerror=alert(1)>', student_number: '1',
    current_status: 'active', issues: [{ title: 'T', detail: 'D', question: 'Q' }] }],
});
check('student name is escaped', !nasty.html.includes('<img src=x'));

// process.exitCode rather than process.exit(). Calling process.exit()
// terminates before buffered stdout has drained when the output is a pipe,
// so the whole report was silently discarded and the run looked as though
// it had printed nothing at all.
console.log(`\n  ${pass} passed, ${fail} failed`);
process.exitCode = fail === 0 ? 0 : 1;
