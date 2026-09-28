-- Migration: LOA and transfer windows
-- Date: 2026-09-28
-- Description:
--   status_tracker already carried effective_date and end_date, described in
--   the project notes as the support for "LOA / transfer windows". Nothing
--   ever wrote them: trackStatusChange() inserted six columns and stopped.
--   So a leave of absence had no expiry. A student granted LOA in March was
--   still on LOA in December, and no review could tell whether the window had
--   closed, because no window had ever been recorded.
--
--   This migration only ensures the columns exist and makes them queryable.
--   It writes no data and changes no existing status: a status is a
--   registrar's decision, and a migration does not get to make one.
--
--   Safe to run more than once: every statement is guarded.

-- ─────────────────────────────────────────────────────────────────────
-- 1. The window columns
--
--    effective_date is when the status actually takes hold. It is not the
--    same as created_at: a status entered on the 28th for a leave starting
--    on the 1st of next month was not effective that day.
--
--    end_date is when the status lapses on its own. Only meaningful for
--    statuses that are time-boxed by nature — leave of absence, a transfer
--    out, a probationary period. Left NULL for statuses that do not expire
--    (active, at-risk, dropped, graduated).
-- ─────────────────────────────────────────────────────────────────────

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'status_tracker' AND COLUMN_NAME = 'effective_date'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `status_tracker` ADD COLUMN `effective_date` date DEFAULT NULL COMMENT 'When this status actually takes effect'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'status_tracker' AND COLUMN_NAME = 'end_date'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `status_tracker` ADD COLUMN `end_date` date DEFAULT NULL COMMENT 'When a time-boxed status lapses (LOA, transfer, probation)'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 2. Make "who is past their window" a cheap question
--
--    The check is a range scan on end_date alone, and the page asks it on
--    every load. Without this index the sweep reads the whole journal and
--    sorts it, which is exactly the cost a review page cannot afford.
-- ─────────────────────────────────────────────────────────────────────

SET @has := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'status_tracker' AND INDEX_NAME = 'idx_student_end_date'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `status_tracker` ADD INDEX `idx_student_end_date` (`student_id`, `end_date`)",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
