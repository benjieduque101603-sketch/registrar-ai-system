-- Migration: GCash payment receipt for online document requests
-- Date: 2026-10-02
-- Description:
--   Adds the receipt half of online payment. The gateway confirmation says
--   money moved; the receipt says what it was for. Two independent signals
--   agreeing is worth much more than one, and when they DISAGREE that is
--   precisely the case a registrar needs to look at â€” the gateway knows a
--   transaction id, the receipt carries the GCash reference number finance
--   reconciles on. Neither alone can tell a mislabelled transfer from a
--   wrong amount.
--
--   Design decision (agreed with the requester): the receipt is REQUIRED to
--   leave Awaiting_Payment for Processing, but staff may waive it with a
--   typed reason. Not "optional" â€” optional means nobody uploads, and the
--   people who don't upload are exactly the ones you need to see the
--   receipt for. Not a hard block either â€” that builds a queue of students
--   who genuinely paid and lost the screenshot, which gets resolved
--   informally anyway, leaving you nominally-mandatory-but-actually-not and
--   no way to tell which is which.
--
--   So the record always answers: was the receipt seen, or was it waived,
--   and by whom.
--
--   Safe to run more than once: every statement is guarded by an
--   information_schema check and is a no-op once the column exists.
--
--   NOTE ON document_status: this migration does NOT touch that column.
--   MODIFY COLUMN REPLACES an enum rather than adding to it, and the live
--   table has gained 'Awaiting_Payment' and 'Shipped' since the seed file
--   was written (registrar_ai.sql still lists the older six-value enum).
--   Anyone rebuilding the enum from the seed instead of from SHOW COLUMNS
--   would silently drop both values and rewrite every Awaiting_Payment row
--   to ''. The gate implemented alongside this reads the enum as it stands;
--   it does not redefine it.

-- â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
-- 1. The receipt file itself.
--
--    Stored as a path relative to the repo root, matching
--    requirement_file_path, and kept in its own directory so the .htaccess
--    that blocks script execution applies to it (see uploads/.htaccess).
--
--    The SHA-256 is recorded for the same reason document_requests already
--    keeps record_file_sha256: to answer "is the file the registrar is
--    looking at the file that was uploaded?" after the fact. A receipt is
--    evidence, and evidence that can be quietly swapped is not evidence.
-- â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_path'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_path` varchar(255) NULL
     COMMENT 'Relative path to the uploaded GCash receipt image/PDF'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_sha256'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_sha256` char(64) NULL
     COMMENT 'SHA-256 of the receipt at upload; proves the file is unaltered'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- The name the student UPLOADED under, kept for display only.
--
-- The stored file is renamed (generateFilename()), so payment_receipt_path
-- reads as an opaque hash. A registrar verifying a receipt needs to see
-- "gcash-receipt-2026.jpg", not the hash, and finance matching it against
-- a phone screenshot needs the name they sent it from. It is never used to
-- build a path -- only payment_receipt_path is -- so a hostile filename
-- cannot reach the filesystem through this column.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_filename'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_filename` varchar(180) NULL
     COMMENT 'Original filename as uploaded, for display only'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_uploaded_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_uploaded_at` datetime NULL
     COMMENT 'When the student attached the receipt'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
-- â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
-- 2. The GCash reference number, typed by the student off the screenshot.
--
--    Separate from payment_ref, which holds the GATEWAY's transaction id.
--    Keeping them apart is the point: when finance reconciles the school
--    account against GCash's settlement report, they match on the GCash
--    reference. Folding one into the other would make a mismatch
--    indistinguishable from a missing value.
--
--    Deliberately NOT an enum and NOT normalised: finance reconciles on the
--    exact string as printed, and a '0'-stripping normaliser would quietly
--    turn a valid 13-digit reference into an unmatchable one.
-- â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_ref'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_ref` varchar(40) NULL
     COMMENT 'GCash reference number as printed on the receipt'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
-- 3. Verification / waiver audit trail.
--
--    Two mutually exclusive outcomes, both leaving a trail:
--      verified_* â€” a registrar looked at the receipt and accepted it.
--      waived_*   â€” they accepted it WITHOUT one, with a typed reason.
--
--    Both also write to document_request_events, so the reason appears in
--    the same timeline as every other status change rather than only in
--    these columns.
-- â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_verified_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_verified_at` datetime NULL
     COMMENT 'When staff confirmed the receipt matches the request'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_verified_by'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_verified_by` int(11) NULL
     COMMENT 'users.id of the staff member who verified the receipt'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_waived_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_waived_at` datetime NULL
     COMMENT 'When staff accepted the request WITHOUT a receipt'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_waived_by'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_waived_by` int(11) NULL
     COMMENT 'users.id of the staff member who waived the receipt requirement'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_waived_reason'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_waived_reason` varchar(255) NULL
     COMMENT 'Why the receipt requirement was waived — required, never blank'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 4. Pickup email audit.
--
--    The user asked for an email when the document is ready. A notification
--    the office cannot prove it sent is one that quietly stops being sent,
--    so the timestamp, the recipients that actually received it, and the
--    failure reason are all recorded here.
-- ─────────────────────────────────────────────────────────────────────

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'pickup_notified_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `pickup_notified_at` datetime NULL
     COMMENT 'When the pickup/availability email was successfully sent'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'pickup_notified_to'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `pickup_notified_to` varchar(255) NULL
     COMMENT 'Recipients the pickup email actually reached'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'pickup_notify_error'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `pickup_notify_error` varchar(255) NULL
     COMMENT 'Why the pickup email could not be sent; NULL when it sent'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 5. Backfill: requests already paid get their receipt marked as waived,
--    with the reason saying so honestly.
--
--    Without this, every request paid online BEFORE this migration would
--    look like a student who refused to upload, and the desk would chase
--    people who have already paid. The backfilled reason says 'paid before
--    receipts were required', which is the true reason.
--
--    Restricted to ONLINE requests that actually got paid, because those
--    are the only ones the new gate would ever hold up.
--
--    The upload directory itself is NOT created here — it is made lazily by
--    the upload handler, exactly as uploads/document_requirements/ already
--    is. A SQL migration has no business knowing where the repo lives on
--    disk, and the table is the part that genuinely needs a schema.
-- ─────────────────────────────────────────────────────────────────────

UPDATE `document_requests`
   SET `payment_receipt_waived_at`     = COALESCE(`paid_at`, NOW()),
       `payment_receipt_waived_reason` = 'Waived: paid online before receipts were required.'
 WHERE `payment_method` = 'Online'
   AND `paid_at` IS NOT NULL
   AND `payment_receipt_path` IS NULL
   AND `payment_receipt_waived_at` IS NULL;