// Checks for the notification fixes that need no database: the escaping
// applied before notification text reaches innerHTML, and the read-cursor
// arithmetic that decides what counts as unread.
// Run: node tests/notification_check.js
'use strict';

const fs = require('fs');
const path = require('path');

let pass = 0, fail = 0;
function check(name, got, want) {
    if (got === want) { pass++; console.log('  ok   ' + name); }
    else { fail++; console.log('  FAIL ' + name + '\n       got:  ' + got + '\n       want: ' + want); }
}

// Pull esc() straight out of the sidebar so this tests the shipped code
// rather than a copy that can drift away from it.
const sidebar = fs.readFileSync(
    path.join(__dirname, '..', 'includes', 'sidebar.php'), 'utf8');
const m = sidebar.match(/function esc\(str\)\s*\{[\s\S]*?\n    \}/);
if (!m) {
    console.error('  FAIL could not find esc() in includes/sidebar.php');
    process.exit(1);
}
const esc = eval('(' + m[0].replace(/^function esc/, 'function') + ')');

console.log('escaping (XSS):');
check('strips script tag', esc('<script>alert(1)</script>'), '&lt;script&gt;alert(1)&lt;/script&gt;');
check('escapes attribute breakout', esc('" onmouseover="x'), '&quot; onmouseover=&quot;x');
check('escapes ampersand', esc('a & b'), 'a &amp; b');
check('null becomes empty', esc(null), '');
check('img onerror neutralised', esc('<img src=x onerror=alert(1)>'),
    '&lt;img src=x onerror=alert(1)&gt;');

// The escaping above is worthless unless the render path routes every
// interpolated field through it. These assertions are what catch a
// regression where someone pastes a raw field back into the template.
console.log('\nrender path uses esc():');
const renderFn = sidebar.slice(
    sidebar.indexOf('function renderNotifications'),
    sidebar.indexOf('function openNotifModal'));
['n.id', 'n.icon', 'n.title', 'n.message', 'n.time'].forEach(f => {
    // Matches both esc(n.title) and the guarded esc(n.icon || 'fallback').
    check('escapes ' + f, new RegExp('esc\\(' + f + '\\b').test(renderFn), true);
});
// Nothing from the notification object may reach innerHTML unescaped.
const rawFields = renderFn.match(/' \+ n\.\w+ \+ '/g) || [];
check('no unescaped field interpolation', rawFields.length, 0);

// Endpoints must be resolved from $APP_ROOT, never hardcoded to '../api/...'
// which 404s on root-level pages. Comments are stripped first so the
// explanatory comment quoting the old path doesn't count as a hit.
const sidebarCode = sidebar
    .split('\n')
    .filter(l => !/^\s*(\/\/|\*|\/\*)/.test(l))
    .join('\n');

console.log('\nendpoint paths:');
check('no hardcoded ../api/ in sidebar code', /['"]\.\.\/api\//.test(sidebarCode), false);
check('uses $APP_ROOT for api path', /\$\{?APP_ROOT\}?\s*\?>\s*api\//.test(sidebar), true);
check('falls back to a root APP_ROOT', /\$APP_ROOT\s*=\s*\$APP_ROOT\s*\?\?\s*'\.\.\/'/.test(sidebar), true);

// Mark-all-read must persist for every role, not just students.
console.log('\nmark all read:');
const markAll = sidebar.slice(sidebar.indexOf('function markAllRead'));
check('posts for all roles (no student-only branch)',
    !/function markAllRead\(\)[\s\S]{0,400}if \(USER_ROLE === 'student'\)/.test(markAll), true);
check('posts action=read_all', markAll.includes('action=read_all'), true);

// Read state lives in a per-user cursor table.
console.log('\nread state:');
const notifApi = fs.readFileSync(path.join(__dirname, '..', 'api', 'notifications.php'), 'utf8');
check('uses a read cursor', notifApi.includes('staff_notification_reads'), true);
check('no longer hardcodes unread=true', notifApi.includes("'unread'  => true"), false);
check('rejects students', /getCurrentUserRole\(\) === 'student'/.test(notifApi), true);
check('cursor advances monotonically', notifApi.includes('GREATEST(last_read_id'), true);

console.log('\nschema:');
const migration = fs.readFileSync(
    path.join(__dirname, '..', 'migrations', 'add_staff_notification_reads.sql'), 'utf8');
check('migration creates the cursor table', migration.includes('CREATE TABLE IF NOT EXISTS `staff_notification_reads`'), true);

const sql = fs.readFileSync(path.join(__dirname, '..', 'registrar_ai.sql'), 'utf8');
check('install file creates the cursor table', sql.includes('CREATE TABLE `staff_notification_reads`'), true);

// Student unread count must span the whole table, not the 50-row window.
// The staff feed has the same window-vs-total hazard the student feed had.
// The feed returns 20 rows but must report unread across the whole table,
// otherwise the badge caps at 20 and disagrees with the ?unread=1 poll.
console.log('\nstaff feed unread count:');
check('counts unread with its own query',
    notifApi.includes('SELECT COUNT(*) FROM audit_logs WHERE id > ?'), true);
check('no longer counts unread inside the feed window',
    /if \(\$isUnread\) \$unread\+\+;/.test(notifApi), false);
check('both unread paths use the same predicate',
    (notifApi.match(/SELECT COUNT\(\*\) FROM audit_logs WHERE id > \?/g) || []).length >= 2, true);

console.log('\nstudent feed:');
const stuApi = fs.readFileSync(path.join(__dirname, '..', 'api', 'student-notifications.php'), 'utf8');
check('counts unread with its own query', stuApi.includes('SELECT COUNT(*) FROM student_notifications WHERE student_id = ? AND is_read = 0'), true);
check('no longer counts inside the window', /foreach \(\$notifs as \$n\) \{\s*if \(!\$n\[.is_read.\]\) \$unread\+\+;/.test(stuApi), false);

console.log('\ndocument status notifications:');
const docs = fs.readFileSync(path.join(__dirname, '..', 'api', 'documents.php'), 'utf8');
check('notifies the student on status change', docs.includes('notifyStudent('), true);
const fns = fs.readFileSync(path.join(__dirname, '..', 'shared', 'functions.php'), 'utf8');
check('documentTypeLabel helper exists', fns.includes('function documentTypeLabel'), true);

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail === 0 ? 0 : 1);
