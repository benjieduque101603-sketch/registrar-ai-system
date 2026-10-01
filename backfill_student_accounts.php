<?php
// ============================================================
//  BACKFILL_STUDENT_ACCOUNTS.PHP
//  One-time maintenance script: create student portal accounts
//  for EXISTING students that don't have one yet.
//
//  Uses the SAME credential scheme as the auto-create on enroll:
//    username = first letter of first name + 9-digit student id
//              e.g. Juan / 100000001  ->  j100000001
//    password = '#' + first 2 letters of first name + birth year
//              e.g. Juan / 2005        ->  #ju2005
//
//  Usage (CLI):
//    php backfill_student_accounts.php            # dry run (preview only)
//    php backfill_student_accounts.php --run      # actually create accounts
//    php backfill_student_accounts.php --run --email   # also send welcome emails
//
//  Safe: skips students already linked to an account, and skips
//  students without a valid birth date. Never updates existing rows.
// ============================================================

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/database.php';
require_once __DIR__ . '/shared/functions.php';

$dryRun  = !in_array('--run', $argv, true);
$doEmail = in_array('--email', $argv, true);

// ── Credential derivation (must match api/students.php) ────────
function backfillDeriveCreds(string $firstName, string $studentNumber, string $birthDateRaw): array {
    $idDigits = preg_replace('/[^0-9]/', '', $studentNumber);
    $id9      = substr($idDigits, -9);
    $first    = mb_strtolower(mb_substr($firstName, 0, 1));
    $username = $first . $id9;
    $firstTwo = mb_strtolower(mb_substr($firstName, 0, 2));
    $password = '#' . $firstTwo . (int) substr($birthDateRaw, 0, 4);
    return [$username, $password];
}

function backfillIsValidBirthDate(?string $d): bool {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d !== '0000-00-00';
}

$db = Database::getInstance();

$students = $db->fetchAll(
    "SELECT s.id, s.student_number, s.first_name, s.last_name, s.birth_date, s.email
       FROM students s
      WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.student_id = s.id)
      ORDER BY s.id ASC"
);

echo ($dryRun ? "DRY RUN" : "LIVE") . " — " . count($students) . " student(s) found without a portal account.\n";
echo str_repeat('-', 70) . "\n";

$created    = 0;
$skippedBd  = 0;
$skippedDup = 0;
$skippedNoEmail = 0;
$emailed    = 0;

foreach ($students as $s) {
    // Skip missing / invalid birth date (password depends on birth year)
    if (!backfillIsValidBirthDate($s['birth_date'] ?? null)) {
        printf("  SKIP  #%d %-25s (no valid birth date)\n", $s['id'], $s['first_name'] . ' ' . $s['last_name']);
        $skippedBd++;
        continue;
    }

    [$username, $password] = backfillDeriveCreds((string)$s['first_name'], (string)$s['student_number'], (string)$s['birth_date']);

    // Email: only ever use a real, valid address.
    //
    // This previously fell back to 'student_<number>@<MAIL_FROM domain>',
    // inventing a mailbox that cannot receive mail. Sending to those produced
    // a permanent "550 5.1.1 NoSuchUser" bounce from Gmail on every run.
    // A backfill must never fabricate a deliverable-to-address.
    $email = trim((string)($s['email'] ?? ''));
    if (!isValidEmail($email)) {
        printf("  SKIP  #%d %-25s (no valid email - add one, then re-run)\n", $s['id'], $s['first_name'] . ' ' . $s['last_name']);
        $skippedNoEmail++;
        continue;
    }
    $email = strtolower($email);

    // Collision guard: users.email is UNIQUE. Reuse is not an option here -
    // two portal accounts cannot share an address, and silently rewriting it
    // to a synthetic variant is the exact bug this script previously caused.
    if ($db->fetchOne("SELECT id FROM users WHERE email = ?", [$email]) !== false) {
        printf("  SKIP  #%d %-25s (%s already has a portal account)\n", $s['id'], $s['first_name'] . ' ' . $s['last_name'], $email);
        $skippedDup++;
        continue;
    }

    $fullName = trim($s['first_name'] . ' ' . $s['last_name']);

    if ($dryRun) {
        printf("  WOULD  #%d %-16s  user=%s  pass=%s  email=%s\n",
            $s['id'], $fullName, $username, $password, $email);
        continue;
    }

    // ── LIVE: insert the account ─────────────────────────────
    try {
        $db->insert('users', [
            'username'      => $username,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'full_name'     => $fullName,
            'role'          => 'student',
            'student_id'    => (int) $s['id'],
            'is_active'     => 1,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $created++;
        printf("  OK    #%d %-16s  user=%s  pass=%s  email=%s\n",
            $s['id'], $fullName, $username, $password, $email);

        $account = ['username' => $username, 'email' => $email, 'password' => $password, 'full_name' => $fullName];
        if ($doEmail) {
            $autoload = __DIR__ . '/vendor/autoload.php';
            if (is_file($autoload)) {
                try {
                    require_once __DIR__ . '/shared/mail_client.php';
                    if (function_exists('sendStudentWelcomeEmail') && emailConfigured()) {
                        $res = sendStudentWelcomeEmail(
                            ['id' => (int) $s['id'], 'student_number' => $s['student_number']],
                            $account,
                            null
                        );
                        if (!empty($res['sent'])) { $emailed++; echo "         (welcome email sent)\n"; }
                        else { echo "         (welcome email skipped: " . ($res['message'] ?? 'unknown') . ")\n"; }
                    } else {
                        echo "         (welcome email skipped: SMTP not configured)\n";
                    }
                } catch (Throwable $e) {
                    echo "         (welcome email error: " . $e->getMessage() . ")\n";
                }
            } else {
                echo "         (welcome email skipped: vendor/autoload.php missing)\n";
            }
        }
    } catch (Exception $e) {
        printf("  ERROR #%d %-25s  %s\n", $s['id'], $fullName, $e->getMessage());
    }
}

echo str_repeat('-', 70) . "\n";
echo "Done.\n";
echo "  Created:       $created\n";
echo "  Skipped (no birth date): $skippedBd\n";
echo "  Skipped (dup username):  $skippedDup\n";
echo "  Skipped (no valid email): $skippedNoEmail\n";
if ($doEmail) echo "  Emails sent:   $emailed\n";
if ($dryRun) echo "\nRe-run with --run to actually create the accounts (add --email to send welcome emails).\n";
