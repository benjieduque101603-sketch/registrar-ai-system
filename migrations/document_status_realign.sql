-- Migration: Realign document request statuses to the walk-in counter
-- Date: 2026-09-27
-- Description:
--   Document requests are now filed and settled entirely at the Registrar's
--   Office across three counters. The status vocabulary still described the
--   retired online model, so every new walk-in landed in Awaiting_Payment
--   and waited on a payment step that can never complete — at the time of
--   writing, all live rows were stuck there.
--
--   Before: Pending_Clearance | Awaiting_Payment | Processing | Ready
--           | Shipped | Claimed | Rejected
--   After:  Filed | Pending_Clearance | Processing | Ready | Claimed
--           | Rejected
--
--     Awaiting_Payment — dropped. Payment happens at the counter on
--                         collection, so a request awaiting payment is
--                         simply Filed.
--     Shipped           — dropped. Courier was retired with the online
--                         gateway; nothing is dispatched.
--
--   Re-runnable: every statement is guarded.

-- ─────────────────────────────────────────────────────────────────────
-- 1. Migrate existing rows BEFORE narrowing the enum.
--    Order matters: MySQL would coerce an unknown value to '' under a
--    strict mode, silently destroying the lifecycle stage.
-- ─────────────────────────────────────────────────────────────────────

-- ─────────────────────────────────────────────────────────────────────
-- 1. WIDEN the enum to include every value used in the next step.
--
--    This MUST happen before the UPDATE. MySQL validates a value written
--    to an enum column against the column's CURRENT definition: setting
--    document_status = 'Filed' while 'Filed' is not yet a member coerces
--    the value to '' (non-strict) or aborts the statement (strict). Either
--    way the lifecycle stage is destroyed. Order: add, then move, then
--    remove.
-- ─────────────────────────────────────────────────────────────────────

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'document_status'
);
SET @s := IF(@has = 1,
  "ALTER TABLE `document_requests`
     MODIFY COLUMN `document_status`
       enum('Filed','Pending_Clearance','Awaiting_Payment','Processing',
            'Ready','Shipped','Claimed','Rejected')
       NOT NULL DEFAULT 'Filed'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 2. Migrate the rows while both old and new values are valid members.
-- ─────────────────────────────────────────────────────────────────────

-- An Awaiting_Payment request was, in the online model, a filed request
-- whose fee had not been settled. At the counter that is simply Filed.
UPDATE `document_requests`
   SET `document_status` = 'Filed'
 WHERE `document_status` = 'Awaiting_Payment';

-- A Shipped request was in transit. With no courier, it is sitting at the
-- counter awaiting collection.
UPDATE `document_requests`
   SET `document_status` = 'Ready'
 WHERE `document_status` = 'Shipped';

-- The legacy `status` column mirrors the v2 lifecycle. Requests still
-- sitting on the old default never advanced because their gateway
-- payment never arrived; treat them as filed.
UPDATE `document_requests`
   SET `status` = 'pending'
 WHERE `status` IS NULL OR `status` = '';

-- A request released before the realignment kept `status = 'released'`
-- and `document_status = 'Claimed'`. Bring the legacy mirror back into
-- step with the new vocabulary so the archive page and the student
-- history agree with the queue.
UPDATE `document_requests`
   SET `status` = 'completed'
 WHERE `document_status` = 'Claimed' AND `status` = 'released';

-- ─────────────────────────────────────────────────────────────────────
-- 3. NARROW the enum, now that nothing in it is dead. The new values lead
--    in lifecycle order so SHOW CREATE TABLE reads as the process rather
--    than as an alphabetical accident.
--
--    Deliberately unguarded: MODIFY is idempotent, so always running it
--    is safer than a "has the new enum" check, which would also skip the
--    step if an earlier attempt failed partway through.
-- ─────────────────────────────────────────────────────────────────────

SET @s := "ALTER TABLE `document_requests`
     MODIFY COLUMN `document_status`
       enum('Filed','Pending_Clearance','Processing','Ready','Claimed','Rejected')
       NOT NULL DEFAULT 'Filed'";
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 3. Payment is now recorded on collection, not on submission.
--    Clear any stale timestamp from the retired gateway so "paid" is
--    only ever true of a counter settlement.
-- ─────────────────────────────────────────────────────────────────────

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'paid_at'
);
SET @s := IF(@has = 1,
  "UPDATE `document_requests`
      SET `paid_at` = NULL, `payment_ref` = NULL
    WHERE `paid_at` IS NOT NULL
      AND `document_status` <> 'Claimed'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
