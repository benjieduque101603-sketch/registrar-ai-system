-- ============================================================
--  Staff notification read cursor.
--
--  The staff bell is fed from audit_logs, which is a shared,
--  append-only trail. Read state therefore cannot live on the
--  trail itself: "read" is a fact about one user looking at one
--  feed, not about the row. This table stores, per user, the
--  highest audit_logs.id they have already seen. Anything newer
--  is unread.
--
--  Deliberately NOT foreign-keyed to users.id. A session's user_id can
--  outlive its row in users (re-seeding, account deletion, a restored
--  backup), and a hard FK then turned every "mark all as read" into a
--  500 — the cursor is a per-user preference, so an orphaned user id
--  is harmless and simply ignored.
--
--  Note on re-runs: the CREATE below is IF NOT EXISTS, so on a database
--  that already has an older copy of this table the CREATE is a no-op
--  and the ALTERs after it are what make the script converge on the
--  intended shape either way.
-- ============================================================

CREATE TABLE IF NOT EXISTS `staff_notification_reads` (
  `user_id`      int(11) NOT NULL,
  `last_read_id` int(11) NOT NULL DEFAULT 0,
  `updated_at`   timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Drop the foreign key if an older copy of this table has one. The
-- ON DELETE CASCADE it provided is no longer wanted: deleting a user
-- should not have to reach into notification state.
SET @drop_fk := (
  SELECT IF(COUNT(*) > 0,
    'ALTER TABLE `staff_notification_reads` DROP FOREIGN KEY `fk_snr_user`',
    'DO 0')
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME      = 'staff_notification_reads'
    AND CONSTRAINT_NAME = 'fk_snr_user'
);
PREPARE stmt FROM @drop_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
