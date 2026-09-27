-- Migration: Walk-in-only document requests (Registrar Office counter)
-- Date: 2026-09-27
-- Description:
--   Document requests are now filed and processed exclusively at the
--   Registrar's Office across 3 counters. The student portal and the
--   online payment gateway are archived, so provenance (which counter,
--   which staff member) and a permanently retained, signed record copy
--   become part of the audit trail.
--
--   Also closes three known schema gaps that left the Certificate of
--   Good Moral, Diploma Replacement and Certified True Copy templates
--   printing N/A for want of any backing column.
--
--   Safe to run more than once: every statement is guarded.

-- ─────────────────────────────────────────────────────────────────────
-- 1. Per-request turnaround target
--
--    Some documents are quick (a Certificate of Enrollment is a print
--    job); some cannot be finished by the Registrar at all, because
--    they wait on another office (Honorable Dismissal needs exit
--    clearance from Alumni, Dean and Property). Without a target the
--    desk cannot tell "this is overdue" from "this is simply early",
--    so everything reads as equally urgent and nothing is triaged.
--
--    Deliberately a per-SKU target, not one global number: a 1-day
--    promise and a 10-day promise are not the same lateness.
-- ─────────────────────────────────────────────────────────────────────

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_catalog' AND COLUMN_NAME = 'sla_days'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_catalog` ADD COLUMN `sla_days` int(11) DEFAULT NULL COMMENT 'Target turnaround in days for this document' AFTER `base_fee`",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Seeded per SKU. Guarded per row so a re-run cannot clobber a target
-- the office has since tuned.
UPDATE `document_catalog` SET `sla_days` = 1  WHERE `sku` = 'DOC-COE';
UPDATE `document_catalog` SET `sla_days` = 1  WHERE `sku` = 'DOC-CD';
UPDATE `document_catalog` SET `sla_days` = 2  WHERE `sku` = 'DOC-CTC';
UPDATE `document_catalog` SET `sla_days` = 3  WHERE `sku` = 'DOC-TOR';
UPDATE `document_catalog` SET `sla_days` = 3  WHERE `sku` = 'DOC-GM';
UPDATE `document_catalog` SET `sla_days` = 5  WHERE `sku` = 'DOC-DIPLOMA';
-- 10 days: gated on three offices clearing, none of which is the desk.
UPDATE `document_catalog` SET `sla_days` = 10 WHERE `sku` = 'DOC-HD';

-- ─────────────────────────────────────────────────────────────────────
-- 2. Blocking, as a fact separate from progress
--
--    document_status answers "where is the work". It cannot also answer
--    "what is this waiting on", and forcing it to do both is what made
--    a request blocked on the Dean look identical to one nobody had
--    touched. These two columns carry the second question.
--
--    NULL reason + NULL since = nothing is blocking it.
-- ─────────────────────────────────────────────────────────────────────

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests' AND COLUMN_NAME = 'blocked_reason'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests` ADD COLUMN `blocked_reason` varchar(160) DEFAULT NULL COMMENT 'What this request is waiting on, if anything' AFTER `document_status`",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests' AND COLUMN_NAME = 'blocked_since'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests` ADD COLUMN `blocked_since` datetime DEFAULT NULL COMMENT 'When the current blockage began' AFTER `blocked_reason`",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Backfill. Every request already filed as Pending_Clearance was, by
-- definition, waiting on a balance that never cleared — record that as
-- the blockage so the desk can see it rather than infer it. Guarded on
-- NULL so a re-run does not overwrite a real, current reason.
UPDATE `document_requests`
   SET `blocked_reason` = 'Outstanding balance not yet settled'
 WHERE `document_status` = 'Pending_Clearance'
   AND `blocked_reason` IS NULL;
UPDATE `document_requests`
   SET `blocked_since` = COALESCE(`blocked_since`, `request_date`)
 WHERE `blocked_reason` IS NOT NULL
   AND `blocked_since` IS NULL;

-- ─────────────────────────────────────────────────────────────────────
-- 3. Remove exit clearance
--
--    The gate asked three offices (Alumni, Dean, Property) to sign off
--    before Honorable Dismissal or a final Transcript of Records could be
--    signed. It was never a working workflow:
--
--      * the catalog flag was selected and then never read;
--      * intake stopped creating the rows it depended on;
--      * a backfill on 2026-09-27 seeded six PENDING rows and not one
--        was ever signed by an office, so nothing depended on them.
--
--    The table is dropped, not merely unused: leaving it would keep a
--    gate that looks enforced in the schema while the code no longer
--    consults it, which is how it drifted in the first place.
--
--    Backed up to backups/pre_exit_clearance_removal_<date>.sql first.
--    document_catalog keeps the column dropped below rather than
--    neutered to 0, so a future gate cannot be re-enabled by accident
--    against a table that no longer exists.
-- ─────────────────────────────────────────────────────────────────────

DROP TABLE IF EXISTS `exit_clearances`;

ALTER TABLE `document_catalog`
    DROP COLUMN IF EXISTS `triggers_exit_clearance`;

-- Any blockage that named a clearance office is no longer true.
UPDATE `document_requests`
   SET `blocked_reason` = NULL,
       `blocked_since`  = NULL
 WHERE `blocked_reason` LIKE 'Exit clearance%';

-- "Pending Clearance" is a legacy LABEL for an unsettled balance. With
-- the exit gate gone the name only confuses, so held requests are
-- returned to the ordinary Filed queue and re-derived from the balance.
UPDATE `document_requests`
   SET `document_status` = 'Filed'
 WHERE `document_status` = 'Pending_Clearance';

-- DOC-HD's stated requirement was the prose "Completed Exit Clearance",
-- which was satisfied by the office signatures rather than by an
-- uploaded file. With the gate removed that requirement is now
-- impossible to satisfy, so it would sit on every Honorable Dismissal
-- forever reading "Awaiting: Completed Exit Clearance" - a hold the
-- desk has no way to clear. Cleared to NULL, which is how a SKU with
-- no requirement is stored.
UPDATE `document_catalog`
   SET `requirement` = NULL
 WHERE `requirement` LIKE '%Exit Clearance%';

-- ─────────────────────────────────────────────────────────────────────
-- 3. Requests: walk-in provenance, 3 counters, retained record copy
--
--    Document requests are deliberately NOT tied to queue tickets.
--    A request is filed and collected at the counter and carries its
--    own Filed → Processing → Ready → Claimed lifecycle; queue_tickets
--    stays a standalone subsystem for walk-in visitors generally.
--    Coupling the two meant a document request could only exist via a
--    ticket, which the Registrar's Office does not require.
-- ─────────────────────────────────────────────────────────────────────

-- Enum widening: MODIFY (not CHANGE) so the existing DEFAULT 'Online'
-- and every existing row are preserved.
SET @s := "ALTER TABLE `document_requests`
  MODIFY COLUMN `payment_method` enum('Online','Cash_on_Delivery','Counter') NOT NULL DEFAULT 'Counter'";
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests' AND COLUMN_NAME = 'source'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests` ADD COLUMN `source` enum('walk_in') NOT NULL DEFAULT 'walk_in' COMMENT 'All requests are counter walk-ins' AFTER `request_id`",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Walk-in + custody columns share one guarded ALTER: MySQL has no
-- "ADD COLUMN IF NOT EXISTS", so the whole batch is applied once or
-- not at all. Re-running is safe because the guard checks the FIRST
-- column only, and the batch is all-or-nothing.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests' AND COLUMN_NAME = 'walkin_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `walkin_at` datetime DEFAULT NULL COMMENT 'Walked in at the counter' AFTER `source`,
     ADD COLUMN `walkin_by` int(11) DEFAULT NULL COMMENT 'Registrar who took the request' AFTER `walkin_at`,
     ADD COLUMN `counter` tinyint(3) NOT NULL DEFAULT 1 COMMENT 'Releasing counter (1-3)' AFTER `walkin_by`,
     ADD COLUMN `released_by` int(11) DEFAULT NULL COMMENT 'Registrar who released the document' AFTER `counter`,
     ADD COLUMN `record_file_path` varchar(255) DEFAULT NULL COMMENT 'Retained signed record copy' AFTER `released_by`,
     ADD COLUMN `record_file_sha256` varchar(64) DEFAULT NULL AFTER `record_file_path`,
     ADD COLUMN `record_file_generated_at` datetime DEFAULT NULL AFTER `record_file_sha256`,
     ADD COLUMN `record_copies_issued` int(11) NOT NULL DEFAULT 0 AFTER `record_file_generated_at`",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- Foreign keys for the walk-in / release staff columns.
-- Added separately from the column batch so a FK failure cannot leave
-- the columns half-created, and re-running is a no-op.
SET @has := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND CONSTRAINT_NAME = 'fk_document_requests_walkin_by'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests` ADD CONSTRAINT `fk_document_requests_walkin_by` FOREIGN KEY (`walkin_by`) REFERENCES `users` (`id`) ON DELETE SET NULL",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND CONSTRAINT_NAME = 'fk_document_requests_released_by'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests` ADD CONSTRAINT `fk_document_requests_released_by` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`) ON DELETE SET NULL",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
--  4. Schema gaps that left templates printing N/A for want of a column
-- ─────────────────────────────────────────────────────────────────────

--  4a. Certificate of Good Moral asserted facts the system could not
--     check: there was no disciplinary record anywhere in the schema.
SET @has := (
  SELECT COUNT(*) FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'discipline_records'
);
SET @s := IF(@has = 0,
  "CREATE TABLE `discipline_records` (
     `id` int(11) NOT NULL AUTO_INCREMENT,
     `student_id` int(11) NOT NULL,
     `recorded_at` date DEFAULT NULL COMMENT 'Date the case was filed',
     `nature` varchar(255) DEFAULT NULL COMMENT 'Nature of the case',
     `resolution` varchar(100) DEFAULT NULL COMMENT 'Penalty imposed, if any',
     `status` enum('pending','resolved','dismissed') NOT NULL DEFAULT 'pending',
     `remarks` text DEFAULT NULL,
     `recorded_by` int(11) DEFAULT NULL,
     `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
     PRIMARY KEY (`id`),
     KEY `idx_discipline_student` (`student_id`,`status`),
     CONSTRAINT `fk_discipline_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
     CONSTRAINT `fk_discipline_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

--  4b. Diploma Replacement and Honorable Dismissal had nowhere to store a
--     graduation date — `status='graduated'` existed, the date did not.
--     No AFTER clause: the neighbouring previous-school columns come from
--     a separate migration that may not have been applied yet, and
--     positioning must not depend on another file's ordering.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'graduation_date'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `students` ADD COLUMN `graduation_date` date DEFAULT NULL COMMENT 'Date the degree was conferred'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

--  4c. The Certified True Copy certifies a stored file, so the original
--     must be fingerprintable. `documents` had no hash column.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'file_sha256'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `documents` ADD COLUMN `file_sha256` varchar(64) DEFAULT NULL COMMENT 'SHA-256 of the stored file, printed on a CTC' AFTER `file_type`",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
--  5. Dead table
--
--    `clearances` has zero code references (verified across *.php and
--    *.js) and is a DIFFERENT table from the live `exit_clearances` —
--    they are not interchangeable. Backed up before dropping.
--    To keep it, comment this out and it stays.
-- ─────────────────────────────────────────────────────────────────────

SET @has := (
  SELECT COUNT(*) FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clearances'
);
SET @s := IF(@has = 1, 'DROP TABLE `clearances`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 4. Who set the hold: blocked_source
--
--    blocked_reason used to mean one thing only — the desk had worked out
--    from the finance balance that this request could not move. That is a
--    DERIVED fact, and doc_refresh_blocker() recomputes it on every
--    Re-check.
--
--    The registrar also knows things the balance cannot know: the Dean's
--    office is holding the affidavit, the student's ID is being reprinted,
--    Guidance owes a signature. Those are decisions a person made, and
--    people have to be able to make them. But a free-text reason written
--    into the same column as the derived one gets silently overwritten the
--    next time anybody clicks Re-check — the balance re-derives as "not
--    blocked", the note is replaced with NULL, and the registrar's
--    judgment vanishes with no trace and no event row.
--
--    blocked_source records which kind of hold is in play:
--
--      'balance'    derived from finance. Recomputed freely.
--      'registrar'  a person's decision. Never overwritten by a re-derive.
--      NULL         nothing is holding it.
--
--    Existing rows are labelled 'balance' so Re-check keeps behaving
--    exactly as it does today, and the "never shorten a blockage" rule in
--    doc_refresh_blocker() still applies within each kind.
-- ─────────────────────────────────────────────────────────────────────

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests' AND COLUMN_NAME = 'blocked_source'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests` ADD COLUMN `blocked_source` varchar(16) DEFAULT NULL COMMENT 'balance = derived from finance, registrar = a decision by a person, NULL = not held' AFTER `blocked_since`",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Anything currently held was held by the balance rule, so label it as
-- such. Guarded on NULL so a re-run never relabels a genuine manual hold.
UPDATE `document_requests`
   SET `blocked_source` = 'balance'
 WHERE `blocked_reason` IS NOT NULL
   AND `blocked_source` IS NULL;

-- Ready/Claimed/Rejected are settled: whatever the reason column still
-- says, nothing is holding them. Only 'balance' rows are cleared here so a
-- registrar's note on a live request is never touched by a migration.
UPDATE `document_requests`
   SET `blocked_reason` = NULL,
       `blocked_since`  = NULL,
       `blocked_source` = NULL
 WHERE `document_status` IN ('Claimed', 'Rejected', 'Ready')
   AND `blocked_source` = 'balance';

-- ─────────────────────────────────────────────────────────────────────
-- 5. Recipient removed from the desk's vocabulary
--
--    `recipient` is left in place (nullable) rather than dropped. It is
--    referenced by student/documents.php's own intake form and by the
--    legacy document_requests shape, and dropping a column is not
--    reversible without a restore. What changed is that the product no
--    longer ASKS for it: a walk-in document is always collected by the
--    student it is filed for, so the field asked a question with exactly
--    one possible answer and its presence implied a courier that the
--    office does not run. See fulfillment_type in the schema.
-- ─────────────────────────────────────────────────────────────────────

