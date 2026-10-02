-- Migration: Add previous school fields to students table
-- Date: 2026-09-23
-- Description: Adds columns for previous school information to support the Personal Info Viewing feature
--
-- GUARDED on existence rather than bare ALTERs. registrar_ai.sql now declares
-- these three columns, so on a fresh install built from the seed this bare
-- statement aborted with ERROR 1060 (Duplicate column name) — which meant the
-- seed and this migration could not both be right, and one of them had to
-- give. Guarding means both are: the migration is a no-op where the columns
-- already exist and still adds them to an older database that lacks them.
--
-- tests/dump_freshness.php imports the seed into an empty database and then
-- replays every migration expecting each to change nothing. That check is what
-- caught the collision.
--
-- EACH COLUMN IS GUARDED SEPARATELY. One guard covering three ADD COLUMNs
-- is only safe when all three are absent together: on a database holding one
-- or two of them — a half-run migration, a manual fix, an interrupted deploy
-- — the single ALTER aborts on the first duplicate and the client stops, so
-- the remaining columns never get added. The client stops at the first error,
-- which means everything after the failure in this file is skipped too.
-- tests/dump_freshness.php section 7 reproduces that state and checks each
-- column comes back.
--
-- Column order is deliberately NOT pinned with AFTER: on a seeded table the
-- columns are already in place and in a different position, and re-ordering
-- them would mean the migration was not a no-op. Order carries no meaning here.
--
-- EACH COLUMN GETS ITS OWN GUARD. One guard covering three ADD COLUMNs is only
-- safe when all three are absent together. On a database holding one or two of
-- them — a half-run migration, a manual fix, an interrupted deploy — the single
-- ALTER aborts on the first duplicate, and because the mysql client stops at
-- the first error, every statement after the failure in this file is skipped
-- too. The migration would then fail to install the very columns it exists to
-- install. tests/dump_freshness.php section 7 reproduces that state and checks
-- each column comes back.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
     AND COLUMN_NAME = 'previous_school'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `students` ADD COLUMN `previous_school` varchar(150) DEFAULT NULL COMMENT 'Name of previous school'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
     AND COLUMN_NAME = 'school_year_graduated'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `students` ADD COLUMN `school_year_graduated` varchar(20) DEFAULT NULL COMMENT 'School year graduated from previous school'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
     AND COLUMN_NAME = 'last_year_level_completed'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `students` ADD COLUMN `last_year_level_completed` varchar(30) DEFAULT NULL COMMENT 'Last year level completed in previous school'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
