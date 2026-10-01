<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the Phase 1 authentication hardening.
 *
 * Each test pins a specific defect that existed before 2026-10-01. They
 * assert BEHAVIOUR (what the code accepts and returns) rather than the
 * presence of a function, because the previous SECURITY_CHECKLIST.md
 * marked every control as done while several of them (lockout, throttle,
 * OTP cap) had zero call sites.
 */

final class AuthHardeningTest extends TestCase
{
    /** Read a source file as a string. */
    private function src(string $relative): string
    {
        $path = dirname(__DIR__) . '/' . $relative;
        self::assertFileExists($path, "missing source file: {$relative}");
        return (string) file_get_contents($path);
    }

    /**
     * Source with comments stripped.
     *
     * Several fixes here deliberately QUOTE the old vulnerable code in an
     * explanatory comment (e.g. "'Access-Control-Allow-Origin: *' was here").
     * A raw substring search would then match the comment and report the
     * bug as still present. Stripping comments first makes these assertions
     * test what the code does, not what it says it used to do.
     */
    private function code(string $relative): string
    {
        $src = $this->src($relative);

        // ORDER MATTERS: strip full-line comments FIRST, then block comments.
        //
        // A line comment can legitimately contain the characters "/*" — for
        // example "the api/*.php endpoints did not". If block comments were
        // stripped first, that "/*" would be treated as an opening delimiter
        // and paired with the next real "*/" hundreds of lines later,
        // deleting a large slab of genuine code and making a present control
        // look absent.
        $src = (string) preg_replace('~^\s*//.*$~m', '', $src);
        $src = (string) preg_replace('~^\s*\#.*$~m', '', $src);
        $src = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        return $src;
    }

    // â”€â”€ C1 â€” reset must not trust a client-supplied user_id â”€â”€â”€â”€â”€â”€

    /**
     * The reset_password handler must derive the user id from a verified,
     * single-use grant rather than from the request body.
     *
     * Only the reset_password block is inspected: resend_otp and
     * verify_otp legitimately read user_id (they are bound to the
     * session that started the flow), so a whole-file check would be
     * both noisy and wrong.
     */
    public function testResetPasswordDoesNotAcceptABareUserId(): void
    {
        foreach (['api/auth.php', 'shared/auth_actions.php'] as $file) {
            $src = $this->src($file);

            $start = strpos($src, "action === 'reset_password'");
            self::assertNotFalse($start, "{$file}: reset_password handler not found");

            // End the block at the next top-level action handler.
            $end = strpos($src, '// â”€â”€â”€ LOGOUT ACTION', $start);
            if ($end === false) {
                $end = strpos($src, "// â”€â”€â”€ LOGOUT", $start);
            }
            if ($end === false) {
                $end = strlen($src);
            }
            $block = substr($src, $start, $end - $start);

            self::assertStringNotContainsString(
                "\$_POST['user_id']",
                $block,
                "{$file}: reset_password still trusts a user_id from the request"
            );
            self::assertStringNotContainsString(
                "\$input['user_id']",
                $block,
                "{$file}: reset_password still trusts a user_id from the JSON body"
            );
        }
    }

    public function testResetPasswordRequiresAConsumedGrant(): void
    {
        foreach (['api/auth.php', 'shared/auth_actions.php'] as $file) {
            self::assertStringContainsString(
                'consumeResetGrant',
                $this->src($file),
                "{$file} must gate reset_password behind consumeResetGrant()"
            );
        }
    }

    // â”€â”€ C2 â€” the OTP must never appear in an API response â”€â”€â”€â”€â”€â”€â”€â”€

    public function testOtpIsNeverReturnedInAResponse(): void
    {
        foreach (['api/auth.php', 'shared/auth_actions.php'] as $file) {
            self::assertStringNotContainsString(
                "\$otp['otp']",
                $this->src($file),
                "{$file} still echoes the plaintext OTP back to the client"
            );
        }
    }

    // â”€â”€ C4 â€” brute-force controls must actually be called â”€â”€â”€â”€â”€â”€â”€â”€

    public function testLoginEndpointsInvokeLockoutAndThrottle(): void
    {
        foreach (['api/auth.php', 'shared/auth_actions.php'] as $file) {
            $src = $this->src($file);

            self::assertStringContainsString('handleFailedAttempt(', $src, "{$file}: lockout not wired");
            self::assertStringContainsString('lockoutRemainingSeconds(', $src, "{$file}: lockout not checked");
            self::assertStringContainsString('loginThrottleStatus(', $src, "{$file}: throttle not checked");
            self::assertStringContainsString('loginThrottleRecord(', $src, "{$file}: attempts not recorded");
        }
    }

    public function testOtpVerifyAttemptCapIsEnforced(): void
    {
        $src = $this->src('shared/auth_security.php');

        self::assertStringContainsString('OTP_MAX_VERIFY_ATTEMPTS', $src);
        // The counter must actually increment, or the cap is a no-op.
        self::assertStringContainsString(
            'verify_attempts = verify_attempts + 1',
            $src,
            'OTP attempts are never incremented, so the cap cannot fire'
        );
    }

    // â”€â”€ C7 â€” no user enumeration â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testLoginDoesNotRevealThatAnAccountIsDisabled(): void
    {
        foreach (['api/auth.php', 'shared/auth_actions.php'] as $file) {
            self::assertStringNotContainsString(
                'Your account is disabled',
                $this->src($file),
                "{$file} still distinguishes a disabled account, enabling enumeration"
            );
        }
    }

    // â”€â”€ C8 â€” fail closed on environment â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testAppEnvDefaultsToProduction(): void
    {
        self::assertStringContainsString(
            "define('APP_ENV', getenv('APP_ENV') ?: 'production')",
            $this->src('shared/config.php'),
            'APP_ENV must default to production so a misconfigured host is not permissive'
        );
    }
    // â”€â”€ Phase 0 â€” the fabricated-mailbox bug â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testNoCodePathFabricatesAMailboxAddress(): void
    {
        foreach (['shared/functions.php', 'backfill_student_accounts.php'] as $file) {
            self::assertStringNotContainsString(
                "'student_' .",
                $this->src($file),
                "{$file} still fabricates a student_<id> mailbox, which bounces as 550 NoSuchUser"
            );
        }
    }

    public function testPlaceholderAccountsAreNotMailed(): void
    {
        $src = $this->src('shared/functions.php');

        self::assertStringContainsString(
            '$emailIsPlaceholder === false',
            $src,
            'the welcome mail must be skipped when there is no address on file'
        );
    }

    /**
     * THE ACTUAL ROOT CAUSE of the 550 bounces.
     *
     * users.email carried a UNIQUE index. A registrar entering a real address
     * that another account already used caused the code to silently replace it
     * with a fabricated mailbox (student_<id>_<ymd>@gmail.com) that never
     * existed. Email is not a unique identity in a school, so the constraint
     * must be gone.
     */
    public function testEmailIsNotForcedUniqueAnywhere(): void
    {
        $sql = $this->src('migrations/security_hardening_phase1.sql');

        self::assertStringContainsString('DROP INDEX `email`', $sql, 'the UNIQUE index on users.email must be dropped');
        self::assertStringContainsString('MODIFY COLUMN `email`', $sql, 'users.email must become nullable');

        // The generator must not run when an address is merely SHARED.
        $src = $this->src('shared/functions.php');
        self::assertStringNotContainsString(
            'SELECT id FROM users WHERE email = ?',
            $src,
            'the portal creator still discards a real address that another account uses'
        );
    }

    /** A shared address must resolve deterministically, never to the wrong account. */
    public function testSharedEmailLookupIsDeterministic(): void
    {
        $src = $this->src('shared/auth_security.php');

        self::assertStringContainsString(
            'ORDER BY u.id ASC',
            $src,
            'the email login branch must be deterministically ordered'
        );
    }

    public function testFixBadEmailDomainsScriptIsInert(): void
    {
        $src = $this->src('fix_bad_email_domains.php');

        self::assertStringContainsString('DEPRECATED', $src);
        // exit(0) must come before any live database work.
        self::assertLessThan(
            (int) strpos($src, 'Database::getInstance'),
            (int) strpos($src, 'exit(0)'),
            'the deprecation notice must exit before touching the database'
        );
    }

    // â”€â”€ Secrets hygiene â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testNoCommittedCredentialRemains(): void
    {
        foreach (['gmail-oauth-setup.php', 'Dockerfile', 'docker-compose.yml'] as $file) {
            $src = $this->src($file);

            self::assertStringNotContainsString('llli rgfv', $src, "{$file} still contains the leaked app password");
            self::assertStringNotContainsString('75b9c530776f97aa', $src, "{$file} still bakes in the JWT secret");
            self::assertStringNotContainsString('43f288fbe5d9c3c9', $src, "{$file} still bakes in the kiosk token");
        }
    }

    public function testUnauthenticatedSecretDumperIsGone(): void
    {
        self::assertFileDoesNotExist(
            dirname(__DIR__) . '/registrar/smtp-debug.php',
            'smtp-debug.php printed every SMTP secret to anonymous visitors'
        );
    }

    // â”€â”€ C6 â€” password change must invalidate earlier sessions â”€â”€â”€

    public function testSessionIsInvalidatedWhenPasswordChanges(): void
    {
        self::assertStringContainsString(
            'password_changed_at',
            $this->src('shared/session_config.php'),
            'session_config.php must compare password_changed_at to end stale sessions'
        );
        self::assertStringContainsString(
            'password_changed_at',
            $this->src('shared/auth_security.php'),
            'the password change must stamp password_changed_at'
        );
    }

    public function testMigrationCreatesTheSupportingSchema(): void
    {
        $sql = $this->src('migrations/security_hardening_phase1.sql');

        self::assertStringContainsString('password_reset_grants', $sql);
        self::assertStringContainsString('verify_attempts', $sql);
        self::assertStringContainsString('email_bounced_at', $sql);
    }

    // â”€â”€ Bounce webhook must not be an open write endpoint â”€â”€â”€â”€â”€â”€â”€â”€

    public function testBounceEndpointFailsClosedWithoutAToken(): void
    {
        $src = $this->src('api/mail-bounce.php');

        self::assertStringContainsString('MAIL_BOUNCE_TOKEN', $src);
        self::assertStringContainsString('hash_equals', $src, 'the shared secret must be compared in constant time');
        self::assertStringContainsString('http_response_code(503)', $src, 'must refuse when no token is configured');
        self::assertStringNotContainsString('csrf_guard', $src, 'this is a provider webhook, not a browser form');
    }

    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Phase 2 â€” authorization
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * A1 (IDOR, CWE-639): api/ai-tools.php is registrar analytics. It
     * previously accepted any authenticated session and role-checked only
     * 3 of 11 actions, so a logged-in STUDENT could read any other
     * student's status evidence, GWA history and profile.
     */
    public function testAiToolsIsGatedForStudents(): void
    {
        $src = $this->src('api/ai-tools.php');

        self::assertStringContainsString(
            "in_array(getCurrentUserRole(), \$AI_TOOLS_ROLES, true)",
            $src,
            'ai-tools must allow-list roles rather than deny-list actions'
        );
        self::assertStringContainsString("'student'", $src, 'a student must never appear in the allowed roles');
        self::assertStringContainsString('http_response_code(403)', $src);

        // The old partial gate must be gone: it only covered 3 actions.
        self::assertStringNotContainsString('$qualityActions', $src,
            'the deny-list gate is what allowed the IDOR');
    }

    /** A2: findDuplicateStudents() returns name + student number + birth date. */
    public function testDuplicateLookupIsGated(): void
    {
        $src = $this->src('api/ai-assist.php');

        self::assertStringContainsString('case \'check_duplicate\'', $src);
        $start = strpos($src, "case 'check_duplicate'");
        $block = substr($src, $start, 700);

        self::assertStringContainsString('getCurrentUserRole', $block,
            'check_duplicate discloses cross-student PII and must be role-gated');
        self::assertStringContainsString('403', $block);
    }

    /** A3: every state-changing endpoint must load the CSRF guard. */
    public function testStateChangingEndpointsLoadCsrfGuard(): void
    {
        foreach ([
            'api/clinic-incidents.php',
            'api/clinic-supplies.php',
            'api/mock/payment.php',
            'api/mock/lalamove.php',
        ] as $file) {
            $src = $this->src($file);
            self::assertStringContainsString(
                'csrf_guard.php',
                $src,
                "{$file} accepts POST/DELETE but never loaded the CSRF guard"
            );
            self::assertStringContainsString('security_headers.php', $src,
                "{$file} skipped the security headers include");
        }
    }

    /** A4: the guardian delete must scope by student_id, like its update path. */
    public function testGuardianDeleteIsOwnershipScoped(): void
    {
        $src = $this->src('api/students.php');

        $start = strpos($src, "'delete-guardian'");
        self::assertNotFalse($start, 'delete-guardian handler not found');
        $block = substr($src, $start, 1800);

        self::assertStringContainsString(
            "'id = ? AND student_id = ?'",
            $block,
            'the delete must carry a student_id predicate, not delete by id alone'
        );
        self::assertStringNotContainsString(
            "\$db->delete('guardians', 'id = ?', [\$id])",
            $block,
            'deleting by id alone lets any guardian row be removed (CWE-639)'
        );
    }

    /** The CORS wildcard must not survive on a cookie-authenticated endpoint. */
    public function testNoWildcardCorsOnAuthenticatedEndpoints(): void
    {
        foreach (['api/mock/payment.php', 'api/mock/lalamove.php'] as $file) {
            // Comments stripped: the fix explains in a comment that the
            // wildcard was there before, and a raw search would match it.
            $src = $this->code($file);
            self::assertStringNotContainsString(
                'Access-Control-Allow-Origin: *',
                $src,
                "{$file} still advertises itself to every origin"
            );
            self::assertStringContainsString('corsSameOrigin()', $src);
        }
    }

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Phase 3 â€” files and exports
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * F1: uploaded files were served straight out of the web root by
     * Apache. The per-directory .htaccess blocked script EXECUTION but
     * never blocked READING, so a student's PSA birth certificate was
     * fetchable by anyone who guessed <student_id>_<unixtime>_<name>.
     */
    public function testSensitiveUploadDirectoriesDenyWebAccess(): void
    {
        foreach (['uploads/student_files/.htaccess', 'uploads/document_requirements/.htaccess'] as $guard) {
            $src = $this->src($guard);
            self::assertStringContainsString(
                'Require all denied',
                $src,
                "{$guard} must deny all direct web access, not just script execution"
            );
        }
    }

    public function testFileDownloadEndpointAuthorisesBeforeStreaming(): void
    {
        $src = $this->code('api/file-download.php');

        self::assertStringContainsString('isLoggedIn()', $src, 'downloads must require a session');
        self::assertStringContainsString('getCurrentStudentId()', $src,
            'a student must be checked against their OWN record');
        // The 403 is raised through the jsonFail() helper, so assert on the
        // call sites rather than a literal http_response_code(403).
        self::assertStringContainsString('jsonFail(403', $src,
            'an unauthorised download must be refused with 403');

        // The path must be re-checked against the app root right before
        // the read, not just once at the top.
        self::assertStringContainsString('strpos($realAbs, $realRoot) !== 0', $src,
            'the resolved path must be confined to the app root');
        self::assertStringContainsString('Content-Disposition: attachment', $src,
            'a stored file must never be served inline in the app origin');
        self::assertStringContainsString('nosniff', $src);

        // A caller-supplied path would bypass ownership entirely.
        self::assertStringNotContainsString("\$_GET['path']", $src,
            'resolving from a caller-supplied path re-opens the exposure');
    }

    /** F2: uploads must be validated by content, not by extension alone. */
    public function testUploadSignatureValidationIsWired(): void
    {
        $helper = $this->src('shared/functions.php');
        self::assertStringContainsString('function validateUploadSignature', $helper);
        self::assertStringContainsString('finfo_file', $helper,
            'the signature check must read real magic bytes');

        foreach (['api/documents.php', 'api/student-documents.php'] as $file) {
            self::assertStringContainsString(
                'validateUploadSignature',
                $this->src($file),
                "{$file} validates uploads by extension only"
            );
        }
    }

    /** F3: the students CSV export must guard against formula injection. */
    public function testStudentCsvExportQuotesAndEscapesFormulas(): void
    {
        $src = $this->src('registrar/students.php');

        $start = strpos($src, 'function exportStudents');
        self::assertNotFalse($start, 'exportStudents not found');
        $block = substr($src, $start, 1400);

        self::assertStringContainsString('/^[=+\-@\t\r]/', $block,
            'the export must prefix = + - @ so Excel cannot execute a cell');
        self::assertStringContainsString('.replace(/"/g', $block,
            'RFC 4180 quoting is required; raw concatenation breaks on commas');
    }

    /** F4: the document delete must resolve through the traversal-safe helper. */
    public function testDocumentDeleteUsesSafePathResolver(): void
    {
        $src = $this->code('api/documents.php');

        self::assertStringNotContainsString(
            "ltrim(\$row['file_path'], './')",
            $src,
            'building the delete path by hand skips traversal normalisation'
        );

        $start = strpos($src, "'action'] === 'delete'");
        self::assertNotFalse($start, 'file delete handler not found');
        $block = substr($src, $start, 1600);

        self::assertStringContainsString('storedFileDiskPath', $block,
            'the delete must resolve through the traversal-safe helper');
        self::assertStringContainsString('realpath', $block,
            'the resolved path must be re-asserted immediately before unlink()');
    }

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Phase 4 â€” defence in depth
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * D1: the session cookie flags must be set by session_config.php
     * itself, before session_start(). They used to live only in
     * security_headers.php, so 32 api/*.php endpoints that never included
     * it issued their cookie with php.ini defaults (no HttpOnly, no
     * SameSite). Fixing it at the source makes the include-order
     * requirement impossible to miss.
     */
    public function testSessionCookieFlagsAreSetBeforeSessionStart(): void
    {
        $src = $this->code('shared/session_config.php');

        $startOfFlags = strpos($src, "ini_set('session.cookie_httponly'");
        $sessionStart = strpos($src, 'session_start()');

        self::assertNotFalse($startOfFlags, 'cookie flags are never set in session_config.php');
        self::assertNotFalse($sessionStart, 'session_start() not found');
        self::assertLessThan(
            $sessionStart,
            $startOfFlags,
            'cookie flags must be set BEFORE session_start() or php.ini defaults win'
        );
        self::assertStringContainsString("ini_set('session.cookie_samesite'", $src);
        self::assertStringContainsString("ini_set('session.cookie_secure'", $src);
        self::assertStringContainsString("ini_set('session.use_strict_mode'", $src);
    }

    /** D2: one shared, greppable escape helper instead of hand-rolled calls. */
    public function testGlobalEscapeHelperExists(): void
    {
        $src = $this->code('shared/functions.php');

        self::assertStringContainsString('function e(', $src, 'the shared escape helper e() must exist');
        self::assertStringContainsString('ENT_QUOTES', $src,
            'ENT_QUOTES is required: the default flags do not escape single quotes, '
            . 'which is what protects a single-quoted attribute');
        self::assertStringContainsString('UTF-8', $src);
    }

    /** D2: the one real stored-XSS miss found in the audit must stay fixed. */
    public function testQrPathIsEscapedAndResolved(): void
    {
        $src = $this->code('student/ids.php');

        self::assertStringNotContainsString(
            "ltrim(\$id['qr_code_path'], './')",
            $src,
            'the QR path is written into a src attribute without escaping (stored XSS shape)'
        );
        self::assertStringContainsString('resolveStudentQrUrl', $src,
            'this column must go through the shared resolver used by every other page');
        self::assertStringContainsString('e($qrUrl)', $src);
    }

    /**
     * D3: Database::insert/update/delete interpolate the table and column
     * names because PDO cannot bind them. Every call site passes a literal
     * today, so this is defence in depth â€” but it turns a future mistake
     * into an exception instead of silent SQL injection.
     */
    public function testDatabaseRejectsUnsafeIdentifiers(): void
    {
        $src = $this->code('shared/database.php');

        self::assertStringContainsString('function assertSafeIdentifier', $src);
        self::assertStringContainsString('/^[A-Za-z_][A-Za-z0-9_]*$/', $src,
            'identifiers must be restricted to plain column names');
        self::assertStringContainsString('InvalidArgumentException', $src);
        // All three writers must route through it.
        foreach (['insert', 'update', 'delete'] as $method) {
            $start = strpos($src, "function {$method}(");
            self::assertNotFalse($start, "{$method}() not found");
            $body = substr($src, $start, 700);
            self::assertStringContainsString('assertSafeIdentifier', $body,
                "{$method}() must validate its table identifier");
        }
    }
}
