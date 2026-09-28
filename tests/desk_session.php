<?php
// A genuine PHP session file, so the LIVE page at
// registrar/documents.php can be loaded over HTTP as a real browser
// request. The __shot.html rig proves the page works; it cannot prove
// the live page does, and the two are served differently — the live
// one runs its includes and its own <script> blocks.
//
// Writes a session file into the configured session directory under a
// known id, so a request carrying that id cookie is authenticated.
// A registrar account is used; no credentials and no user records are
// modified, and the file is removed by the caller.
//
// Usage: php tests/desk_session.php
// Prints: SESSION=<id>
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();
$u  = $db->fetchOne(
    "SELECT id, full_name, role FROM users WHERE role IN ('admin','registrar') AND is_active = 1 ORDER BY id LIMIT 1"
);
if (!$u) {
    fwrite(STDERR, "No active registrar/admin user to simulate.\n");
    exit(1);
}

// The id must be session.sid_length characters (26 by default) or
// session.use_strict_mode=1 makes PHP reject it and silently mint a
// different one, so the request lands on the login page and the check
// passes while testing nothing. 'deskcheck' + 17 hex = 26.
$id = 'deskcheck' . substr(bin2hex(random_bytes(9)), 0, 17);

// The session id and name must be set BEFORE anything reaches output.
// PHP refuses both once headers are sent, and it fails quietly in the
// sense that matters: the calls warn, session_start() then adopts a
// generated id instead of ours, and the "session" written is one an
// HTTP request will never present. That is exactly how the first
// version of this script appeared to work while actually testing the
// login page.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '0');
    ini_set('session.use_cookies', '0');
    session_id($id);
    session_name('BCP_REGISTRAR_SESSION');
    session_start();
} else {
    fwrite(STDERR, "A session is already open; cannot set a known id.\n");
    exit(1);
}

$_SESSION['user_id']       = (int) $u['id'];
$_SESSION['role']          = (string) $u['role'];
$_SESSION['full_name']     = (string) $u['full_name'];
$_SESSION['email']         = '';
$_SESSION['last_activity'] = time();

// Never report success unless the id we asked for is the id in force.
if (session_id() !== $id) {
    fwrite(STDERR, "PHP refused the requested session id (got '" . session_id() . "').\n");
    exit(1);
}
session_write_close();

echo 'SESSION=' . $id . PHP_EOL;
echo 'COOKIE=BCP_REGISTRAR_SESSION=' . $id . PHP_EOL;