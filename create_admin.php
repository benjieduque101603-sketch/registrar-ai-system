<?php
/**
 * create_admin.php -- first-run bootstrap.
 *
 *   php create_admin.php                     create the first admin account
 *   php create_admin.php --catalog           also load the default document catalog
 *   php create_admin.php --list              show what is missing
 *
 * registrar_ai.sql deliberately ships no rows. That is right for an install
 * dump -- a school decides its own fees and its own staff accounts, and a
 * file full of someone else's logins and prices is not something to import
 * into production. But it leaves a fresh install with an empty users table,
 * and the application has no first-run wizard: login.php resolves against
 * the users table and api/users.php requires an existing admin session, so
 * an empty table cannot be recovered from through the browser. It needs one
 * command run from the shell.
 *
 * The same is true, less severely, of document_catalog: without rows the
 * document desk lists nothing and no request can be filed. Those defaults
 * are offered by --catalog but never applied silently.
 *
 * This is CLI only. It creates an account that can then log in normally,
 * change its own password, and create every other account from the Users
 * screen; it is not a permanent back door.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/database.php';
require_once __DIR__ . '/shared/password_policy.php';

$db = Database::getInstance();
$NL = PHP_EOL;

/**
 * Ask for a value without echoing it, so it never reaches the screen.
 *
 * fgets() keeps the line ending, and trim() alone would leave a stray
 * carriage return welded onto the end of a password. That password then
 * fails to verify at the login form with nothing to explain why, so strip
 * the terminator explicitly instead of trimming whitespace -- which a
 * password is perfectly entitled to end with.
 */
function askSecret(string $prompt): string
{
    echo $prompt;
    if (DIRECTORY_SEPARATOR === '\\') {
        // Windows has no portable hidden-input call without the readline
        // extension, so fall back to visible input there.
        $v = rtrim((string) fgets(STDIN), "\r\n");
        echo PHP_EOL;
        return $v;
    }
    shell_exec('stty -echo 2>/dev/null');
    $v = rtrim((string) fgets(STDIN), "\r\n");
    shell_exec('stty echo 2>/dev/null');
    echo PHP_EOL;
    return $v;
}

function ask(string $prompt, string $default = ''): string
{
    echo $default !== '' ? $prompt . " [$default]: " : $prompt . ': ';
    $v = trim((string) fgets(STDIN));
    return $v === '' ? $default : $v;
}

/**
 * The document types the desk offers out of the box.
 *
 * These are a starting point, not an answer. Fees and turnaround targets
 * are local policy, and a school that charges differently should edit them
 * or delete the rows it does not offer. sla_days is what the desk calls
 * overdue, so a wrong value turns every request red or no request red.
 */
function defaultCatalog(): array
{
    return [
        ['DOC-COE',     'Certificate of Enrollment',  'Proof of current enrollment',      '100.00',  'flat',         null,                              1],
        ['DOC-TOR',     'Transcript of Records',     'Complete academic record (TOR)',   '250.00',  'per_page',     'Scanned copy of valid ID',          3],
        ['DOC-GM',      'Certificate of Good Moral', 'Good moral character certificate', '150.00',  'flat',         'No pending disciplinary cases',    3],
        ['DOC-DIPLOMA', 'Diploma Replacement',       'Replacement of lost diploma',      '1000.00', 'flat',         'Notarized Affidavit of Loss',       5],
        ['DOC-CTC',     'Certified True Copy',       'Certified true copy of a record',  '50.00',   'per_page',     null,                              2],
        ['DOC-HD',      'Honorable Dismissal',       'Transfer / honorable dismissal',   '300.00',  'flat',         null,                             10],
        ['DOC-CD',      'Course Description',        'Subject syllabus / course description', '100.00', 'per_syllabus', null,                        1],
    ];
}

$users     = (int) $db->fetchColumn('SELECT COUNT(*) FROM users');
$catalog   = (int) $db->fetchColumn('SELECT COUNT(*) FROM document_catalog');
$admins    = (int) $db->fetchColumn("SELECT COUNT(*) FROM users WHERE role = 'admin'");

echo $NL . "  First-run bootstrap" . $NL;
echo "  -------------------" . $NL;
printf("  accounts:   %d (%d admin)%s", $users, $admins, $NL);
printf("  catalog:    %d document type(s)%s", $catalog, $NL);
echo $NL;

if (in_array('--list', $argv, true)) {
    echo "  Nothing was changed." . $NL;
    exit($users > 0 && $admins > 0 ? 0 : 1);
}

$created = false;

// ── First account ────────────────────────────────────────────────────────
// Only offered when there is genuinely no way in. Adding a second one is
// what the Users screen is for, and encouraging it here would make this a
// way to plant extra admins.
if ($users === 0) {
    echo "  No accounts exist, so there is no way to sign in. Creating one." . $NL . $NL;

    $name  = ask('  Full name');
    $email = ask('  Email');
    $user  = ask('  Login ID (username)');
    $pass  = askSecret('  Password');

    if ($name === '' || $email === '' || $user === '' || $pass === '') {
        echo $NL . "  Cancelled: every field is required." . $NL;
        exit(1);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo $NL . "  That is not a valid email address." . $NL;
        exit(1);
    }

    // Reuse the application's own policy rather than a second, weaker set of
    // rules. A bootstrap that lets you set a password the login form would
    // then reject is worse than one that refuses.
    $check = checkPasswordPolicy($pass, [$name, $email, $user]);
    if (!$check['valid']) {
        echo $NL . "  Password rejected:" . $NL;
        foreach ($check['failed'] as $f) echo "    - $f" . $NL;
        echo $NL . "  Rules:" . $NL;
        foreach (passwordPolicyRequirements() as $r) echo "    - $r" . $NL;
        exit(1);
    }

    $id = $db->insert('users', [
        'email'         => $email,
        'username'      => $user,
        'full_name'     => $name,
        'role'          => 'admin',
        'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
        'is_active'     => 1,
    ]);
    printf("  Created admin #%d (%s). Sign in with the login ID above.%s", $id, $user, $NL . $NL);
    $created = true;
} elseif ($admins === 0) {
    // Accounts exist but none can administer them, so nobody can create
    // more. Worth naming, because it is a quiet dead end otherwise.
    echo "  Accounts exist but none has the admin role, so no one can manage" . $NL;
    echo "  accounts. Promote one with:" . $NL . $NL;
    echo "    UPDATE users SET role = 'admin' WHERE email = '<the address>';" . $NL . $NL;
}

// ── Document catalog ─────────────────────────────────────────────────────
// Never applied automatically. Which documents a school issues, and at what
// price, is a business decision that does not belong in a default.
if ($catalog === 0 && in_array('--catalog', $argv, true)) {
    $add = ask('  Load the 7 default document types? (y/N)', 'N');
    if (strtolower($add) === 'y' || strtolower($add) === 'yes') {
        foreach (defaultCatalog() as $r) {
            list($sku, $name, $desc, $fee, $feeType, $req, $sla) = $r;
            $db->insert('document_catalog', [
                'sku'         => $sku,
                'name'        => $name,
                'description' => $desc,
                'base_fee'    => $fee,
                'fee_type'    => $feeType,
                'requirement' => $req,
                'sla_days'    => $sla,
                'is_active'   => 1,
            ]);
            echo "    + $sku" . $NL;
        }
        echo "  Loaded. Fees and turnaround targets are starting values --" . $NL;
        echo "  edit them to match local policy before the desk goes live." . $NL . $NL;
        $created = true;
    }
} elseif ($catalog === 0) {
    echo "  No document types exist, so the desk will list nothing. When you" . $NL;
    echo "  are ready:  php create_admin.php --catalog" . $NL . $NL;
}

if (!$created) {
    echo "  Nothing was changed." . $NL;
    exit(0);
}
echo "  Done. Delete this file if the host serves the project directory." . $NL;
exit(0);
