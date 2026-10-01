<?php
// ============================================================
//  scripts/purge_fake_emails.php
//  One-time cleanup for addresses the system invented.
//
//  Historical bug: shared/functions.php and backfill_student_accounts.php
//  fabricated mailbox names like student_1660_260929@gmail.com when a
//  student had no email on file. Those addresses do not exist, so every
//  send produced a permanent Gmail bounce:
//
//      550 5.1.1 The email account that you tried to reach does not exist
//
//  This script finds those rows and clears the fabricated address so the
//  app stops mailing them. It NEVER invents a replacement address.
//
//  Usage:
//      php scripts/purge_fake_emails.php          # dry run (default)
//      php scripts/purge_fake_emails.php --run    # apply
//      php scripts/purge_fake_emails.php --students  # students table only
//      php scripts/purge_fake_emails.php --users     # users table only
//
//  Dry run is the default, matching fix_bad_email_domains.php's original
//  convention. It changes nothing until --run is passed.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

$apply   = in_array('--run', $argv, true);
$onlyStudents = in_array('--students', $argv, true);
$onlyUsers    = in_array('--users', $argv, true);
$doStudents = $onlyStudents || !$onlyUsers;
$doUsers    = $onlyUsers || !$onlyStudents;

echo ($apply ? 'LIVE' : 'DRY RUN') . " — purge fabricated email addresses\n";
echo str_repeat('=', 66) . "\n";
if ($apply) {
    echo "LIVE: this WILL change the database. Take a backup first.\n\n";
} else {
    echo "Dry run: prints what would change and writes nothing.\n";
    echo "Re-run with --run to apply.\n\n";
}

// The two shapes that were ever generated:
//   student_<student_number>@<domain>
//   student_<student_number>_<ymd>[@_<n>]@<domain>   (uniqueness suffix)
// Matched on the local part so any domain is caught, including the
// @bestlink.edu.ph originals and the @gmail.com they were rewritten to.
function looksFabricated(string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '' || strpos($email, '@') === false) {
        return false;
    }
    [$local] = explode('@', $email, 2);
    // no-email-<id>@invalid.example is the sentinel the fixed code writes.
    if (strpos($local, 'no-email-') === 0) {
        return true;
    }
    return (bool) preg_match('/^student_[0-9]{2,}(_[0-9]{6}(_[0-9]+)?)?$/', $local);
}

$db  = Database::getInstance();
$total = 0;

// ── users (portal accounts) ──────────────────────────────────────
if ($doUsers) {
    echo "--- users (portal accounts) ---\n";
    $rows = $db->fetchAll("SELECT id, username, email, student_id FROM users");
    $hits = 0;
    foreach ($rows as $u) {
        if (!looksFabricated((string) $u['email'])) {
            continue;
        }
        $hits++;
        $total++;
        $newEmail = 'no-email-' . (int) $u['id'] . '@invalid.example';
        printf("  #%-5d %-22s %s\n", $u['id'], (string) $u['username'], $u['email']);
        if ($apply) {
            // users.email is UNIQUE + NOT NULL, so the sentinel keeps the
            // constraint satisfied while making the row unmailable.
            $db->update('users', [
                'email'               => $newEmail,
                'email_bounced_at'    => date('Y-m-d H:i:s'),
                'email_bounce_reason' => 'Fabricated address removed by purge_fake_emails.php',
            ], 'id = ?', [(int) $u['id']]);
            printf("      → %s\n", $newEmail);
        }
    }
    if ($hits === 0) {
        echo "  (none)\n";
    }
    echo "\n";
}

// ── students ─────────────────────────────────────────────────────
if ($doStudents) {
    echo "--- students ---\n";
    $rows = $db->fetchAll("SELECT id, student_number, email FROM students");
    $hits = 0;
    foreach ($rows as $s) {
        if (!looksFabricated((string) $s['email'])) {
            continue;
        }
        $hits++;
        $total++;
        printf("  #%-5d %-14s %s\n", $s['id'], (string) $s['student_number'], $s['email']);
        if ($apply) {
            $db->update('students', [
                // Empty, not a sentinel: students.email is nullable, and an
                // empty value makes the UI prompt the registrar for a real one.
                'email'                 => '',
                'email_is_placeholder'  => 1,
                'email_bounced_at'      => date('Y-m-d H:i:s'),
                'email_bounce_reason'   => 'Fabricated address removed by purge_fake_emails.php',
            ], 'id = ?', [(int) $s['id']]);
            printf("      → (cleared; add a real address)\n");
        }
    }
    if ($hits === 0) {
        echo "  (none)\n";
    }
    echo "\n";
}

echo str_repeat('=', 66) . "\n";
echo "Total fabricated addresses: $total\n";
if ($apply) {
    echo "\nDone. Review students with no email and collect real addresses.\n";
    echo "The welcome email will not be attempted until one is recorded.\n";
} else {
    echo "\nDry run only — nothing was written. Re-run with --run to apply.\n";
}