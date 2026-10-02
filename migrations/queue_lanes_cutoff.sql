-- ============================================================
--  Four-lane queue: service/claim x student/priority, and the
--  daily open/close window with per-day tap caps.
--
--  WHAT CHANGES
--
--  1. queue_tickets gains txn_type and priority_group. These are
--     DEFAULTed to the old single-lane behaviour, so every existing
--     row stays valid and needs no backfill: a ticket written before
--     this migration is exactly a "service / student" ticket.
--
--  2. queue_day_settings is new: ONE row per queue_date, created
--     lazily on first write. It is per-day rather than a global
--     settings table because the caps are explicitly "per day", and
--     because who cut the line off, and when, is an audit fact the
--     registrar will want to see afterwards.
--
--  The reading side (is the queue open? how many taps has this
--  student used?) must tolerate the table not existing yet, so
--  shared/queue_helpers.php treats a missing row as "defaults" and
--  never writes one just to read it. Only a registrar pressing
--  CUT OFF / REOPEN / Save creates the row.
--
--  Re-runnable: the CREATE is IF NOT EXISTS and every ALTER is
--  guarded by information_schema, so a partial earlier run
--  converges instead of erroring.
-- ============================================================

-- ── 1. Lanes on the ticket ────────────────────────────────────
SET @has_txn := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'queue_tickets'
    AND COLUMN_NAME = 'txn_type'
);
SET @sql := IF(@has_txn = 0,
  'ALTER TABLE `queue_tickets`
     ADD COLUMN `txn_type` enum(''service'',''claim'') NOT NULL DEFAULT ''service'' AFTER `counter`,
     ADD COLUMN `priority_group` enum(''student'',''priority'') NOT NULL DEFAULT ''student'' AFTER `txn_type`,
     ADD KEY `idx_queue_lane` (`queue_date`,`status`,`txn_type`,`priority_group`)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2. Per-day settings ───────────────────────────────────────
-- opens/closes default to the agreed office hours (08:00-17:00).
-- max_taps_* of 0 means UNLIMITED, so a fresh install behaves
-- exactly as it did before: open all day, no cap, until someone
-- saves a value.
CREATE TABLE IF NOT EXISTS `queue_day_settings` (
  `queue_date`       date         NOT NULL,
  `opens_time`       time         NOT NULL DEFAULT '08:00:00',
  `closes_time`      time         NOT NULL DEFAULT '17:00:00',
  `cutoff_enabled`   tinyint(1)   NOT NULL DEFAULT 1,
  `cutoff_forced_at` datetime     DEFAULT NULL,
  `cutoff_forced_by` int unsigned DEFAULT NULL,
  `max_taps_student` int unsigned NOT NULL DEFAULT 0,
  `max_taps_priority` int unsigned NOT NULL DEFAULT 0,
  `updated_at`       timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by`       int unsigned DEFAULT NULL,
  PRIMARY KEY (`queue_date`),
  KEY `idx_qds_forced_by` (`cutoff_forced_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;