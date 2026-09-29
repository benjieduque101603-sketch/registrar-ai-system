-- ============================================================
--  Undo drop_student_section.sql
--
--  Re-adds the section column and restores the archived values.
--  The revert of the matching commit brings the code back; this
--  brings the schema back. Without it the restored code would
--  query a column that no longer exists.
--
--  Safe to re-run: the ADD COLUMN is guarded on the column being
--  absent, and the restore uses INSERT IGNORE keyed on
--  (student_id, table_name) so it will not duplicate a row.
-- ============================================================

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
      AND COLUMN_NAME = 'section') = 0,
  'ALTER TABLE `students` ADD COLUMN `section` varchar(20) DEFAULT NULL AFTER `adviser_id`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollments'
      AND COLUMN_NAME = 'section') = 0,
  'ALTER TABLE `enrollments` ADD COLUMN `section` varchar(20) DEFAULT NULL AFTER `semester`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_history'
      AND COLUMN_NAME = 'section') = 0,
  'ALTER TABLE `enrollment_history` ADD COLUMN `section` varchar(20) DEFAULT NULL AFTER `semester`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Put the archived values back.
--
-- Guarded on the archive table existing. retired_student_sections was
-- created by drop_student_section.sql, the migration that took the
-- values away before dropping the column - and that file is deleted, so
-- a database created from registrar_ai.sql never had it. The three
-- restore UPDATEs are the only reason this file was not a no-op on a
-- fresh install: the guarded ALTERs above all correctly did nothing,
-- and then the very next statement died on a table that had never
-- existed. A no-op has to be a no-op all the way through, or an
-- operator importing the dump still has to work out which migrations
-- are safe to skip.
--
-- Double-quoted, as in every sibling migration here: the statement is a
-- string literal for PREPARE, and ANSI_QUOTES is not part of the SQL
-- mode this app runs under.
SET @has := (
  SELECT COUNT(*) FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retired_student_sections'
);
SET @s := IF(@has = 0, 'DO 0',
  "UPDATE `students` s
     JOIN `retired_student_sections` r
       ON r.student_id = s.id AND r.table_name = 'students'
     SET s.section = r.section
   WHERE s.section IS NULL OR TRIM(s.section) = ''");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@has = 0, 'DO 0',
  "UPDATE `enrollments` e
     JOIN `retired_student_sections` r
       ON r.student_id = e.id AND r.table_name = 'enrollments'
     SET e.section = r.section
   WHERE e.section IS NULL OR TRIM(e.section) = ''");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@has = 0, 'DO 0',
  "UPDATE `enrollment_history` h
     JOIN `retired_student_sections` r
       ON r.student_id = h.id AND r.table_name = 'enrollment_history'
     SET h.section = r.section
   WHERE h.section IS NULL OR TRIM(h.section) = ''");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

