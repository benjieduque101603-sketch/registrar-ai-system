-- Migration: Restore the online document-request lifecycle
-- Date: 2026-10-02
-- Description:
--   Reverses the online-retiring half of migrations/document_walkin_only.sql.
--   Students file online again, pay online, and a request can be couriered.
--
--   It ALSO used to reverse migrations/document_status_realign.sql, which
--   narrowed document_status by dropping Awaiting_Payment and Shipped. That file
--   has been DELETED, not applied: the live enum still carries all eight values,
--   this file's WIDEN step restores exactly that eight, and five modules still
--   write both retired values - api/documents.php, api/student-documents.php,
--   api/analytics.php, api/paymongo_client.php and api/student-ai-chat.php. A
--   migration that narrows an enum out from under five writers is not a
--   rollback, it is data loss.
--
--   This is an ADDITIVE migration. The retired model it partially reverses is
--   left untouched as the historical record of when the office moved to
--   walk-in only; a rollback is re-running that file, not editing it.
--
--   Only the ENUM widths come back. Every column the retired model used was
--   deliberately left in place rather than dropped (shipped_at, payment_ref,
--   lalamove_order_ref, delivery_address, source, the mock gateway tables),
--   so restoring the lifecycle is a widening and a rewiring — no data has
--   to be reconstructed and nothing is lost.
--
--   Safe to run more than once: every statement is guarded, and the WIDEN
--   steps are no-ops when the value is already a member.

-- ─────────────────────────────────────────────────────────────────────
-- 1. Widen BEFORE writing, or MySQL destroys the values.
--
--    MySQL validates a value written to an ENUM against the column's
--    CURRENT definition. Writing 'Awaiting_Payment' while it is not yet a
--    member coerces to '' (non-strict) or aborts (strict). So the order is
--    widen, move, and only then narrow if ever needed — never the reverse.
-- ─────────────────────────────────────────────────────────────────────

-- 1a. document_status regains the two retired stages.
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

-- 1b. `source` is how the desk tells an online filing from a counter
--     filing. The walk-in migration narrowed it to enum('walk_in'), which
--     left an ONLINE request with no honest value to store — the exact
--     provenance gap this restore closes.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'source'
);
SET @s := IF(@has = 1,
  "ALTER TABLE `document_requests`
     MODIFY COLUMN `source` enum('walk_in','online') NOT NULL DEFAULT 'walk_in'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 1c. Courier comes back as a third fulfilment mode. 'Digital' is kept.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'fulfillment_type'
);
SET @s := IF(@has = 1,
  "ALTER TABLE `document_requests`
     MODIFY COLUMN `fulfillment_type`
       enum('Pickup','Delivery','Digital') NOT NULL DEFAULT 'Pickup'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 2. Re-file the requests the walk-in migration mislabelled.
--
--    The realign stamped paid_at on EVERY request at filing time, because
--    fees were taken at the counter. So paid_at alone cannot distinguish
--    "really paid at the counter" from "stamped by the old submit path".
--
--    walkin_at is the reliable discriminator: a genuine counter filing has
--    one, an online filing never did. Only rows with a NULL walkin_at are
--    rewound, so no real counter revenue record is touched.
-- ─────────────────────────────────────────────────────────────────────

UPDATE `document_requests`
   SET `document_status` = 'Awaiting_Payment',
       `paid_at`         = NULL,
       `payment_ref`     = NULL,
       `source`          = 'online'
 WHERE `walkin_at` IS NULL
   AND `paid_at` IS NOT NULL
   AND `document_status` = 'Filed';

-- A Shipped request that the realign folded back to Ready was in transit.
-- With the courier restored there is no way to know whether it arrived, so
-- it stays Ready and the desk re-dispatches deliberately rather than
-- guessing a delivery the system never tracked.
