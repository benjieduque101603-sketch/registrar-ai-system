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
ALTER TABLE `users` ADD COLUMN `password_changed_at` datetime DEFAULT NULL;

-- ── 3. OTP brute-force cap ──────────────────────────────────────────────────
-- OTP_MAX_VERIFY_ATTEMPTS was defined but never consulted. A 6-digit code
-- with no cap and no rate limit is brute-forceable.
ALTER TABLE `otp_codes` ADD COLUMN `verify_attempts` int(11) NOT NULL DEFAULT 0;

-- ── 4. Bounce tracking ──────────────────────────────────────────────────────
-- The registrar kept mailing addresses that do not exist, producing
-- "550 5.1.1 NoSuchUser" forever. api/mail-bounce.php writes these so the
-- app stops retrying and staff can see why a message did not arrive.
ALTER TABLE `students`
  ADD COLUMN `email_bounced_at` datetime DEFAULT NULL,
  ADD COLUMN `email_bounce_reason` varchar(255) DEFAULT NULL;

ALTER TABLE `users`
  ADD COLUMN `email_bounced_at` datetime DEFAULT NULL,
  ADD COLUMN `email_bounce_reason` varchar(255) DEFAULT NULL;

-- ── 5. Address quality ──────────────────────────────────────────────────────
-- Distinguishes a real, deliverable address from one we had to invent.
ALTER TABLE `students`
  ADD COLUMN `email_is_placeholder` tinyint(1) NOT NULL DEFAULT 0;

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
ALTER TABLE `users` DROP INDEX `email`;

-- NULL means "no usable address on file" (replaces the
-- no-email-<id>@invalid.example sentinel).
ALTER TABLE `users`
  MODIFY COLUMN `email` varchar(190) NULL DEFAULT NULL;

-- Recovery index for the login/reset lookups that used the unique key.
CREATE INDEX `idx_users_email` ON `users` (`email`);

-- Full identity: length 190 keeps the index inside InnoDB's 767-byte
-- limit under utf8mb4 (190 * 4 = 760).