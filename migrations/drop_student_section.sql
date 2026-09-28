-- ============================================================
--  Drop sectioning. It belongs to another department.
--
--  The registrar's masterlist is a plain list of enrolled students
--  grouped by course and year level. The section code format
--  ([year][semester][###], e.g. 11001), auto-assign, create/edit
--  section, and the per-section cap all go away with the column.
--
--  Before running, take a backup: this is destructive and the
--  column cannot be recovered from the database afterwards.
--
--  Back up first:
--    mysqldump -u root registrar_ai students enrollments enrollment_history > section_backup.sql
-- ============================================================

-- Preserve the old values in a side table first, so a section
-- assignment can still be recovered by hand if it is ever needed.
CREATE TABLE IF NOT EXISTS `retired_student_sections` (
  `student_id`    int(11) NOT NULL,
  `table_name`    varchar(50) NOT NULL,
  `section`       varchar(20) DEFAULT NULL,
  `archived_at`   timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`student_id`, `table_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Copy the current values across before dropping anything. Each copy is
-- guarded on the source column still existing: once the column is gone
-- a plain SELECT would fail with "Unknown column 'section'", so a
-- re-run has to skip the copy rather than error. INSERT IGNORE also
-- keeps a second run from overwriting the archived value.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
      AND COLUMN_NAME = 'section') > 0,
  'INSERT IGNORE INTO `retired_student_sections` (student_id, table_name, section)
     SELECT id, ''students'', section FROM `students`
     WHERE section IS NOT NULL AND TRIM(section) <> ''''',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollments'
      AND COLUMN_NAME = 'section') > 0,
  'INSERT IGNORE INTO `retired_student_sections` (student_id, table_name, section)
     SELECT id, ''enrollments'', section FROM `enrollments`
     WHERE section IS NOT NULL AND TRIM(section) <> ''''',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_history'
      AND COLUMN_NAME = 'section') > 0,
  'INSERT IGNORE INTO `retired_student_sections` (student_id, table_name, section)
     SELECT id, ''enrollment_history'', section FROM `enrollment_history`
     WHERE section IS NOT NULL AND TRIM(section) <> ''''',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Drop the column from each table, guarded so a re-run is a no-op
-- rather than an error (MySQL and MariaDB both reject a missing
-- column in ALTER TABLE ... DROP COLUMN).
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
      AND COLUMN_NAME = 'section') > 0,
  'ALTER TABLE `students` DROP COLUMN `section`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollments'
      AND COLUMN_NAME = 'section') > 0,
  'ALTER TABLE `enrollments` DROP COLUMN `section`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_history'
      AND COLUMN_NAME = 'section') > 0,
  'ALTER TABLE `enrollment_history` DROP COLUMN `section`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
