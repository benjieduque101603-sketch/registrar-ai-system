-- =============================================================================
--  migrations/security_hardening_phase1.sql
--  Phase 0 + Phase 1 support tables/columns.
--
--  Safe to run more than once.
--
--  Apply with:
--      mysql -u <user> -p <db> < migrations/security_hardening_phase1.sql
--  or paste into phpMyAdmin.
-- =============================================================================

-- ── 1. Password reset grants ────────────────────────────────────────────────
-- Before this, api/auth.php?action=reset_password accepted a bare user_id +
-- new_password with no proof of anything, so anyone could take over any
-- account. A grant is now minted only after the OTP is verified, is stored
-- hashed, expires in 15 minutes, and is single-use.
CREATE TABLE IF NOT EXISTS `password_reset_grants` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `user_id`    int(11) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at`    datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_grant_user` (`user_id`,`expires_at`),
  CONSTRAINT `fk_grant_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 2. Session invalidation ──────────────────────────────────────────────────
-- Password reset did not kill existing sessions, so a stolen cookie survived
-- the victim changing their password. session_config.php compares this value
-- against $_SESSION['login_time'] and re-authenticates when they disagree.
--
-- GUARDED, like sections 1 and 7. These ALTERs were originally bare, which
-- meant this migration aborted at the first statement on any database that
-- already had the column — including a FRESH install built from
-- registrar_ai.sql, because the seed now declares these columns. The
-- migration's own header promises the migrations are "guarded and
-- idempotent, so they can be run in any order, repeatedly, and against a
-- database already up to date", and every statement below section 1 broke
-- that promise. tests/dump_freshness.php checks exactly this: it imports
-- the seed and replays every migration expecting each to be a no-op.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
     AND COLUMN_NAME = 'password_changed_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `users` ADD COLUMN `password_changed_at` datetime DEFAULT NULL",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 3. OTP brute-force cap ──────────────────────────────────────────────────
-- OTP_MAX_VERIFY_ATTEMPTS was defined but never consulted. A 6-digit code
-- with no cap and no rate limit is brute-forceable.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_codes'
     AND COLUMN_NAME = 'verify_attempts'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `otp_codes` ADD COLUMN `verify_attempts` int(11) NOT NULL DEFAULT 0",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 4. Bounce tracking ──────────────────────────────────────────────────────
-- The registrar kept mailing addresses that do not exist, producing
-- "550 5.1.1 NoSuchUser" forever. api/mail-bounce.php writes these so the
-- app stops retrying and staff can see why a message did not arrive.
--
-- EACH COLUMN IS GUARDED SEPARATELY, deliberately. An earlier version of
-- this guarded on email_bounced_at and then added email_bounced_at AND
-- email_bounce_reason in one statement. That is only safe when neither
-- exists. On a database that has one and not the other — which a half-run
-- migration, a manual fix, or an interrupted deploy produces — the ALTER
-- aborts on the duplicate second column, and because the mysql client
-- stops at the first error, every statement after it never runs. The
-- migration then fails to install the very thing it exists to install.
-- tests/dump_freshness.php section 7 reproduces exactly that state and
-- caught it.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
     AND COLUMN_NAME = 'email_bounced_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `students` ADD COLUMN `email_bounced_at` datetime DEFAULT NULL",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
     AND COLUMN_NAME = 'email_bounce_reason'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `students` ADD COLUMN `email_bounce_reason` varchar(255) DEFAULT NULL",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
     AND COLUMN_NAME = 'email_bounced_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `users` ADD COLUMN `email_bounced_at` datetime DEFAULT NULL",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
     AND COLUMN_NAME = 'email_bounce_reason'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `users` ADD COLUMN `email_bounce_reason` varchar(255) DEFAULT NULL",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 5. Address quality ──────────────────────────────────────────────────────
-- Distinguishes a real, deliverable address from one we had to invent.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
     AND COLUMN_NAME = 'email_is_placeholder'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `students` ADD COLUMN `email_is_placeholder` tinyint(1) NOT NULL DEFAULT 0",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 6. Email must NOT be unique ─────────────────────────────────────────────
-- THE ACTUAL ROOT CAUSE of the "550 5.1.1 NoSuchUser" bounces.
--
-- Observed case: student #1660 (Cathy Tenco) was enrolled with the real
-- address roldantiu89@gmail.com. That address was saved correctly on the
-- students row. But user #3 (ADM-002, an admin) already held the same
-- address, and users.email carried a UNIQUE index. When the portal account
-- was auto-created, the uniqueness check failed and the code SILENTLY
-- REPLACED the real address with a fabricated one:
--
--     student_1660_260929@gmail.com      <- _260929 = date('ymd') collision marker
--
-- That mailbox never existed, so every welcome email bounced, and the
-- bounce went back to the sender (MAIL_FROM = the same address).
--
-- Email is not a unique identity in a school: families share addresses, a
-- student may later become staff, a teacher may tutor several students. So
-- users.email becomes a normal lookup index instead of a uniqueness
-- constraint, and a nullable "no address on file" state is allowed.
--
-- Login is unaffected: the primary identifier is username / student ID.
-- Email remains valid for password reset, and the reset flow now picks the
-- most recently created active account when an address is shared.
-- GUARDED individually. This section was three bare statements, and all
-- three abort on a database where the work is already done:
--   DROP INDEX `email`        -> ERROR 1091 once the index is gone
--   CREATE INDEX idx_users_email -> ERROR 1061 once it exists
--   MODIFY COLUMN `email`     -> harmless, but included here for tidiness
--
-- The seed already declares the end state (non-unique idx_users_email,
-- nullable email), so a fresh install reaches this point with the work
-- done — and section 6 used to be the statement that broke it. Since the
-- client stops at the first error, anything after it in the file would
-- also be skipped.
--
-- information_schema.STATISTICS is the right source for index existence;
-- checking for a COLUMN named `email` would not distinguish the unique
-- constraint from the plain index.
SET @has := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
     AND INDEX_NAME = 'email'
);
SET @s := IF(@has > 0,
  'ALTER TABLE `users` DROP INDEX `email`',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- NULL means "no usable address on file" (replaces the
-- no-email-<id>@invalid.example sentinel).
ALTER TABLE `users`
  MODIFY COLUMN `email` varchar(190) NULL DEFAULT NULL;

-- Recovery index for the login/reset lookups that used the unique key.
SET @has := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
     AND INDEX_NAME = 'idx_users_email'
);
SET @s := IF(@has = 0,
  'CREATE INDEX `idx_users_email` ON `users` (`email`)',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Full identity: length 190 keeps the index inside InnoDB's 767-byte
-- limit under utf8mb4 (190 * 4 = 760).