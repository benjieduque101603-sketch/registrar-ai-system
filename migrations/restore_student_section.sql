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
UPDATE `students` s
  JOIN `retired_student_sections` r
    ON r.student_id = s.id AND r.table_name = 'students'
  SET s.section = r.section
  WHERE s.section IS NULL OR TRIM(s.section) = '';

UPDATE `enrollments` e
  JOIN `retired_student_sections` r
    ON r.student_id = e.id AND r.table_name = 'enrollments'
  SET e.section = r.section
  WHERE e.section IS NULL OR TRIM(e.section) = '';

UPDATE `enrollment_history` h
  JOIN `retired_student_sections` r
    ON r.student_id = h.id AND r.table_name = 'enrollment_history'
  SET h.section = r.section
  WHERE h.section IS NULL OR TRIM(h.section) = '';
