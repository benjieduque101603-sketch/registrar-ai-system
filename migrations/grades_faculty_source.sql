-- ============================================================
--  MIGRATIONS/GRADES_FACULTY_SOURCE.SQL
--  Academic history: grades become Faculty-owned, Registrar reads.
--
--  Boundary change approved 2026-10-02 (see DEPARTMENTS.md):
--  Faculty Management #296 owns the grade record; the Registrar holds
--  terms and subjects for reporting and prints the grade template.
--
--  Everything here is ADDITIVE. No live data is dropped — the only
--  candidates would be the never-populated columns
--  (schedule/room/prerequisite/semester_taken/subject_type), which are
--  where #293's data lands later, so removing them would be churn.
--
--  Idempotent: safe to re-run.
--
--  GUARDED, not bare ALTERs. registrar_ai.sql now declares these columns and
--  indexes, so a fresh install built from the dump made this file abort with
--  ERROR 1060 (Duplicate column name) / 1061 (Duplicate key name) - meaning the
--  dump and this migration could not both be right. Guarding means both are: a
--  no-op where the objects already exist, still additive on an older database
--  that lacks them. tests/dump_freshness.php section 6 caught it.
--
--  EACH COLUMN AND KEY GETS ITS OWN GUARD, because the mysql client stops at
--  the first error: one guard over six ADD COLUMNs is only safe when all six
--  are absent together, and on a half-run migration the single ALTER aborts on
--  the first duplicate and every statement after it is skipped.
--
--  Column order is deliberately NOT pinned with AFTER: on a seeded table the
--  columns are already present in a different position, and re-ordering them
--  would mean the migration was not a no-op. Order carries no meaning here.
-- ============================================================

-- ── 1. Provenance ────────────────────────────────────────────────
-- Where a row came from, so nothing in the UI has to care. 'faculty' is
-- the default and the intended producer; 'import' and 'manual' exist so a
-- backfill or a hand-keyed correction can be identified later rather than
-- silently blending into Faculty-sourced rows.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades' AND COLUMN_NAME = 'source_system');
SET @s := IF(@has = 0,
  "ALTER TABLE `academic_grades` ADD COLUMN `source_system` varchar(32) NOT NULL DEFAULT 'faculty'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades' AND COLUMN_NAME = 'source_ref');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_grades` ADD COLUMN `source_ref` varchar(64) DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades' AND COLUMN_NAME = 'faculty_id');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_grades` ADD COLUMN `faculty_id` int(11) DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades' AND COLUMN_NAME = 'received_at');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_grades` ADD COLUMN `received_at` timestamp NULL DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades' AND COLUMN_NAME = 'term_status');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_grades` ADD COLUMN `term_status` varchar(20) DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 0 = the free-text instructor has NOT been checked against a faculty
-- record. Kept rather than dropped: a name can arrive from Faculty that
-- we have not reconciled, and "unverified" has to be distinguishable
-- from "confirmed" once the reference lands.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades' AND COLUMN_NAME = 'instructor_confirmed');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_grades` ADD COLUMN `instructor_confirmed` tinyint(1) NOT NULL DEFAULT 0',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2. Re-syncing must not duplicate ────────────────────────────
-- The same subject arrives again on every fetch. Without this, a second
-- sync of an unchanged term doubles every row.
--
-- NULL source_ref rows are exempt because UNIQUE treats NULLs as
-- distinct — that is deliberate: a row with no source key yet (a
-- hand-entered or legacy row) is not claimed by any single source row.
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades' AND INDEX_NAME = 'uq_ag_source');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_grades`
    ADD UNIQUE KEY `uq_ag_source` (`academic_history_id`,`source_system`,`source_ref`)',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades' AND INDEX_NAME = 'idx_ag_faculty');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_grades` ADD KEY `idx_ag_faculty` (`faculty_id`)',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 3. Term-level GWA, both figures ──────────────────────────────
-- gwa_reported is Faculty's number; gwa_computed is ours from
-- shared/term_grades.php. They are kept side by side on purpose: once
-- Faculty owns the grades, a local recompute that disagrees with the
-- official figure must be VISIBLE, not silently overwrite it.
--
-- `gwa` (the pre-existing column) is left in place and continues to hold
-- the computed value, so every existing reader — the TOR, the student
-- grade views, gwa_agreement_check.php — keeps working untouched. These
-- two are the new, explicit pair.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_history' AND COLUMN_NAME = 'gwa_reported');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_history` ADD COLUMN `gwa_reported` decimal(5,2) DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_history' AND COLUMN_NAME = 'gwa_computed');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_history` ADD COLUMN `gwa_computed` decimal(5,2) DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 4. Seed the computed figure from the existing `gwa` ──────────
-- The computed value already exists; it was just stored in a column
-- named after the concept rather than the source. This makes the two
-- explicit columns meaningful from day one, so the disagreement check
-- has something to compare on rows that predate the migration.
UPDATE `academic_history`
   SET `gwa_computed` = `gwa`
 WHERE `gwa` IS NOT NULL AND `gwa_computed` IS NULL;

-- ── 5. Backfill received_at for legacy rows ──────────────────────
-- Everything existing predates any source system, so it is recorded as
-- having arrived when the row was created rather than left NULL, which
-- would make "never synced" indistinguishable from "not yet tracked".
UPDATE `academic_grades`
   SET `received_at` = `created_at`
 WHERE `received_at` IS NULL;