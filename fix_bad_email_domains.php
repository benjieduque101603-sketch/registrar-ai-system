<?php
// ============================================================
//  fix_bad_email_domains.php
//  DEPRECATED / DISABLED - performs no change.
//
//  This script rewrote @bestlink.edu.ph addresses to @gmail.com. It was
//  written so auto-generated addresses would "land on a domain that actually
//  accepts mail" - but those addresses were never real mailboxes. Rewriting
//  the domain only disguised the fabrication: the mail still had nowhere to
//  go, and Gmail answered every message with
//      550 5.1.1 The email account that you tried to reach does not exist
//  which surfaced in the registrar's inbox as a failed delivery.
//
//  The real fix is in shared/functions.php: the system no longer invents
//  mailbox names. An account with no real address gets a non-deliverable
//  sentinel and no mail is attempted.
//
//  To clean up the damage this script caused, use instead:
//      php scripts/purge_fake_emails.php          # preview
//      php scripts/purge_fake_emails.php --run    # apply
// ============================================================

echo "fix_bad_email_domains.php is DEPRECATED and no longer performs any change.\n\n";
echo "It rewrote addresses to @gmail.com to disguise synthetic mailboxes that\n";
echo "could never receive mail, producing permanent 550 5.1.1 NoSuchUser bounces.\n\n";
echo "Address fabrication is fixed in shared/functions.php.\n";
echo "To clean up previously fabricated addresses:\n";
echo "    php scripts/purge_fake_emails.php          # preview\n";
echo "    php scripts/purge_fake_emails.php --run    # apply\n";
exit(0);

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/database.php';

$targetDomain = 'bestlink.edu.ph';

// Parse --domain=X override
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--domain=')) {
        $targetDomain = trim(substr($arg, strlen('--domain=')));
    }
}

$dryRun = !in_array('--run', $argv, true);
$mode   = $dryRun ? 'DRY RUN' : 'LIVE';

// Derive replacement domain from MAIL_FROM
$replacementDomain = (defined('MAIL_FROM') && MAIL_FROM !== '')
    ? substr(MAIL_FROM, strrpos(MAIL_FROM, '@') + 1)
    : '';

if ($replacementDomain === '' || $replacementDomain === $targetDomain) {
    echo "Cannot fix: replacement domain '$replacementDomain' is empty or same as target.\n";
    echo "Set MAIL_FROM in email_secret.local or as an env var.\n";
    exit(1);
}

$db = Database::getInstance();
$affected = 0;

echo "$mode — Replacing @$targetDomain → @$replacementDomain\n";
echo str_repeat('-', 60) . "\n";

// 1. Students table
$students = $db->fetchAll(
    "SELECT id, student_number, email FROM students WHERE email LIKE ?",
    ['%@' . $targetDomain]
);
echo "students table: " . count($students) . " record(s) with @$targetDomain\n";

foreach ($students as $s) {
    $oldEmail = $s['email'];
    $newEmail = str_replace('@' . $targetDomain, '@' . $replacementDomain, $oldEmail);
    printf("  #%d %-12s  %s → %s\n", $s['id'], $s['student_number'] ?? '', $oldEmail, $newEmail);
    if (!$dryRun) {
        $db->update('students', ['email' => $newEmail], 'id = ?', [(int) $s['id']]);
    }
    $affected++;
}

// 2. Users table (portal accounts)
$users = $db->fetchAll(
    "SELECT id, username, email FROM users WHERE email LIKE ?",
    ['%@' . $targetDomain]
);
echo "users table: " . count($users) . " record(s) with @$targetDomain\n";

foreach ($users as $u) {
    $oldEmail = $u['email'];
    $newEmail = str_replace('@' . $targetDomain, '@' . $replacementDomain, $oldEmail);
    printf("  #%d user=%-16s  %s → %s\n", $u['id'], $u['username'], $oldEmail, $newEmail);
    if (!$dryRun) {
        $db->update('users', ['email' => $newEmail], 'id = ?', [(int) $u['id']]);
    }
    $affected++;
}

echo str_repeat('-', 60) . "\n";
echo "Total affected: $affected record(s)\n";

if ($dryRun) {
    echo "\nRe-run with --run to apply.\n";
} else {
    echo "\nDone. All @$targetDomain emails replaced with @$replacementDomain.\n";
}
?>
