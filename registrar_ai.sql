-- registrar_ai.sql -- full schema and seed data for a fresh install.
--
-- Importing THIS file is sufficient. Everything the application reads is
-- defined here, including the walk-in document-request work:
--
--   document_catalog.sla_days          per-SKU turnaround target
--   document_requests.blocked_reason    what a request is waiting on
--   document_requests.blocked_since     when the hold started
--   document_requests.blocked_source    balance | registrar | NULL
--   document_requests.source/counter/   walk-in provenance
--   walkin_at/walkin_by/...
--
-- The migrations in migrations/ are for databases that ALREADY exist.
-- They are guarded and idempotent, so running one against a database
-- created from this file is harmless but unnecessary.
--
-- ═══════════════════════════════════════════════════════════════════════════
--  THIS FILE DESTROYS DATA. BACK UP FIRST. READ THIS BEFORE IMPORTING.
-- ═══════════════════════════════════════════════════════════════════════════
--
-- Every table below is preceded by DROP TABLE IF EXISTS. Importing this file
-- into a database that already has data DELETES that data. This is now true
-- where it was not before: an earlier revision only ever did CREATE TABLE, so
-- importing it into a populated database stopped at the first "table already
-- exists" and left a partial import. That was safe but useless for the case
-- that actually comes up - importing onto a hosting database that already has
-- the old tables - so the DROPs were added and the risk came with them.
--
--   mysqldump -u USER -p --routines --triggers registrar_ai > backup.sql
--
-- TAKE THAT DUMP FIRST, EVERY TIME. There is no undo in SQL and no prompt.
--
-- What you get afterwards: the correct schema, and three staff logins - one
-- admin, one registrar, one nurse - whose bcrypt hashes are in this repository
-- and therefore public. Change every password immediately after importing:
--
--   php create_admin.php
--
-- What you lose: every student, document request, queue ticket, grade and
-- audit row that was in those tables. The uploads/ files on disk are NOT
-- touched by this import - rows referencing them will survive the schema but
-- have nothing to point at until the files are deployed alongside.
--
-- To KEEP existing data instead, do not import this file. Import the
-- migrations, which are guarded and idempotent - safe in any order, repeatable,
-- and a no-op on a database already up to date:
--
--   mysql -u USER -p registrar_ai < migrations/document_walkin_only.sql
--
-- That is the right command for a live database with records in it.
-- ───────────────────────────────────────────────────────────────────────────
--
-- Safe to import into an EMPTY database, or one whose contents you are
-- deliberately discarding:
--
--   CREATE DATABASE registrar_ai CHARACTER SET utf8mb4;
--   mysql -u USER -p registrar_ai < registrar_ai.sql
--
-- Safe to import MORE THAN ONCE. Every statement is DROP-then-CREATE, so a
-- second import reproduces the same schema instead of erroring. It also
-- re-inserts the three staff rows, which have fixed ids and so do not
-- duplicate. tests/dump_freshness.php imports the file twice and asserts this.
--
-- Generated from the live schema, so it cannot drift from the code the way
-- a hand-edited dump does. tests/dump_freshness.php proves it imports clean,
-- that every migration is a no-op against it, and that nothing but staff
-- logins is seeded. Run it after any schema change.
--
-- The freshness check has three parts, and the third is the one that matters
-- most for a fresh install:
--
--   1. every table in the LIVE database is defined here
--   2. every column the code reads exists in both
--   3. every table the CODE reads is defined here
--
-- (3) exists because (1) cannot catch a table that is missing from both. That
-- is how `card_readers` survived here: five files read it unguarded and it
-- existed neither in this dump nor in the live database, so a fresh install
-- would have shipped a dead RFID Kiosk, a dead Readers page, and a
-- card-readers endpoint answering "table not found" - from a file whose header
-- called the schema complete.
--
-- Three tables were REMOVED as dead, on 2026-10-03:
--
--   announcements      the bulletin feed. student/announcements.php and
--                      api/announcements.php were deleted, and nothing has
--                      referenced the table since.
--   authorized_cards   "staff cards authorized to operate stations" from the
--                      retired RFID station model. rfid_cards, rfid_scan_logs
--                      and card_readers all remain and are all in use; only
--                      this side table went with the stations.
--   masterlist_cache   a query cache for the retired masterlist generator.
--                      api/masterlist.php regenerates on demand and never
--                      reads it; the only surviving mention was a cache purge
--                      inside a migration.
--
-- Before removing any of the three: zero references in any PHP file, zero rows
-- in the live database, zero inbound foreign keys, and zero audit_logs rows
-- naming them. tools/unused_tables.php reports the current state.
--
-- The blind spot that nearly caused a disaster, recorded so the next person
-- does not repeat it: shared/database.php takes the table name as a STRING -
-- $db->insert('enrollment_history', ...). A scanner that strips quoted
-- literals reports enrollment_history, contact_change_requests and
-- mock_lalamove_orders as unused. All three are load-bearing. Do not write a
-- deletion tool that strips quotes.
--
-- Student status: enrolled / active / graduate / alumni / dropped, defined once
-- in studentStatuses() (shared/functions.php) and mirrored here. The dump
-- already declares the narrow enum, so migrations/student_status_five_values.sql
-- is a silent no-op against it - it exists for databases that ALREADY exist.
--
-- Folded in from migrations/ so a fresh install needs this file alone:
--
--   queue_lanes_cutoff.sql    queue_tickets.txn_type / priority_group /
--                             idx_queue_lane, and the queue_day_settings table
--   grades_faculty_source.sql academic_grades provenance columns + uq_ag_source
--                             / idx_ag_faculty, and academic_history's
--                             gwa_reported / gwa_computed pair
--
-- Both were previously migration-only, so a fresh install shipped a queue with
-- no lanes and no opening hours, and a records page reading six columns that
-- did not exist. migrations/queue_lanes_cutoff.sql and
-- migrations/grades_faculty_source.sql remain for databases that ALREADY exist
-- and are no-ops against this file.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `academic_grades`;
CREATE TABLE `academic_grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_history_id` int(11) NOT NULL,
  `subject` varchar(120) NOT NULL,
  `units` decimal(4,2) DEFAULT NULL,
  `grade` varchar(10) DEFAULT NULL,
  `remarks` varchar(40) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `subject_code` varchar(30) DEFAULT NULL,
  `subject_type` varchar(30) DEFAULT NULL,
  `prerequisite` varchar(120) DEFAULT NULL,
  `instructor` varchar(100) DEFAULT NULL,
  `schedule` varchar(120) DEFAULT NULL,
  `room` varchar(50) DEFAULT NULL,
  `semester_taken` varchar(20) DEFAULT NULL,
  `midterm_grade` varchar(10) DEFAULT NULL,
  `final_grade` varchar(10) DEFAULT NULL,
  `final_rating` varchar(10) DEFAULT NULL,
  `grade_status` enum('passed','failed','incomplete','dropped') DEFAULT NULL,
  -- ── Provenance (migrations/grades_faculty_source.sql) ───────────
  -- Where the row came from, so no page has to care. 'faculty' is the
  -- default and the intended producer (Faculty Management #296 owns the
  -- grade record); 'import' and 'manual' exist so a backfill or a hand-keyed
  -- correction can be identified later instead of silently blending in.
  -- shared/term_grades.php reads every one of these unguarded.
  `source_system` varchar(32) NOT NULL DEFAULT 'faculty',
  `source_ref`    varchar(64) DEFAULT NULL,
  `faculty_id`    int(11)     DEFAULT NULL,
  `received_at`   timestamp   NULL DEFAULT NULL,
  `term_status`   varchar(20) DEFAULT NULL,
  -- 0 = the free-text instructor has NOT been checked against a faculty
  -- record. Kept rather than dropped: a name can arrive from Faculty that we
  -- have not reconciled, and "unverified" has to stay distinguishable from
  -- "confirmed" once the reference lands.
  `instructor_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_academic_history_id` (`academic_history_id`),
  -- Re-syncing must not duplicate: the same subject arrives again on every
  -- fetch, and without this a second sync of an unchanged term doubles every
  -- row. NULL source_ref rows are exempt because UNIQUE treats NULLs as
  -- distinct - deliberate, since a row with no source key yet is not claimed
  -- by any single source row.
  UNIQUE KEY `uq_ag_source` (`academic_history_id`,`source_system`,`source_ref`),
  KEY `idx_ag_faculty` (`faculty_id`),
  CONSTRAINT `fk_grade_academy` FOREIGN KEY (`academic_history_id`) REFERENCES `academic_history` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `academic_history`;
CREATE TABLE `academic_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `school_name` varchar(100) NOT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `grade_level` varchar(20) DEFAULT NULL,
  `gwa` decimal(5,2) DEFAULT NULL,
  `subjects_completed` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `semester` varchar(20) DEFAULT NULL,
  `credits` decimal(6,2) DEFAULT NULL,
  -- Both GWA figures, side by side, from migrations/grades_faculty_source.sql.
  -- gwa_reported is Faculty's number; gwa_computed is ours from
  -- shared/term_grades.php. Once Faculty owns the grades, a number typed in two
  -- places can disagree, so the disagreement is printed rather than silently
  -- resolved. `gwa` above is left in place and keeps holding the computed value,
  -- so every existing reader (the TOR, the student grade views,
  -- gwa_agreement_check.php) keeps working untouched.
  `gwa_reported` decimal(5,2) DEFAULT NULL,
  `gwa_computed` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  CONSTRAINT `academic_history_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `ai_cache`;
CREATE TABLE `ai_cache` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `prompt_hash` varchar(64) NOT NULL,
  `prompt` text NOT NULL,
  `response` text NOT NULL,
  `model` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prompt_hash` (`prompt_hash`),
  KEY `idx_prompt_hash` (`prompt_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  -- Collation is utf8mb4_general_ci here, matching the live table, and the
  -- seed previously said utf8mb4_bin. That difference is inert: these are
  -- write-only JSON payloads, never compared in SQL (grepped to confirm),
  -- and json_valid() is a parse check rather than a comparison. The seed is
  -- aligned to the database that actually runs rather than the reverse.
  -- Change it here and you would be changing what a rebuild produces for no
  -- behavioural gain.
  `old_values` longtext DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=362 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `staff_notification_reads`;
CREATE TABLE `staff_notification_reads` (
  `user_id` int(11) NOT NULL,
  `last_read_id` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `clinic_incidents`;
CREATE TABLE `clinic_incidents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) DEFAULT NULL,
  `incident_type` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `incident_date` date DEFAULT NULL,
  `incident_time` time DEFAULT NULL,
  `severity` enum('low','medium','high','critical') DEFAULT 'low',
  `action_taken` text DEFAULT NULL,
  `follow_up` text DEFAULT NULL,
  `status` enum('open','in_progress','resolved','closed') DEFAULT 'open',
  `reported_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ci_student` (`student_id`),
  KEY `idx_ci_status` (`status`),
  KEY `idx_ci_date` (`incident_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `clinic_supplies`;
CREATE TABLE `clinic_supplies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `category` varchar(80) DEFAULT NULL,
  `quantity` int(11) DEFAULT 0,
  `unit` varchar(30) DEFAULT 'pcs',
  `min_quantity` int(11) DEFAULT 5,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cs_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `clinic_supply_usage`;
CREATE TABLE `clinic_supply_usage` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supply_id` int(11) NOT NULL,
  `quantity_used` int(11) NOT NULL DEFAULT 1,
  `health_visit_id` int(11) DEFAULT NULL,
  `used_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `used_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_csu_supply` (`supply_id`),
  KEY `idx_csu_visit` (`health_visit_id`),
  CONSTRAINT `fk_csu_supply` FOREIGN KEY (`supply_id`) REFERENCES `clinic_supplies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `communication_log`;
CREATE TABLE `communication_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `contact_id` int(11) DEFAULT NULL,
  `recipient_email` varchar(190) NOT NULL,
  `recipient_name` varchar(100) DEFAULT NULL,
  `message_type` varchar(20) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'sent',
  `ref` varchar(100) DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `sent_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_type_status` (`message_type`,`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `contact_change_requests`;
CREATE TABLE `contact_change_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `contact_type` varchar(10) NOT NULL,
  `request_type` varchar(10) NOT NULL,
  `target_id` int(11) DEFAULT NULL,
  `payload` text NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'pending',
  `review_note` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_ccr_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `contact_recipients`;
CREATE TABLE `contact_recipients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `relationship` varchar(40) NOT NULL DEFAULT 'parent',
  `email` varchar(190) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `send_billing` tinyint(1) NOT NULL DEFAULT 0,
  `send_grades` tinyint(1) NOT NULL DEFAULT 0,
  `send_emergency` tinyint(1) NOT NULL DEFAULT 0,
  `auth_token` varchar(64) DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `last_emailed` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contact_email` (`student_id`,`email`),
  KEY `idx_student` (`student_id`),
  CONSTRAINT `fk_contact_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `discipline_records`;
CREATE TABLE `discipline_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `recorded_at` date DEFAULT NULL COMMENT 'Date the case was filed',
  `nature` varchar(255) DEFAULT NULL COMMENT 'Nature of the case',
  `resolution` varchar(100) DEFAULT NULL COMMENT 'Penalty imposed, if any',
  `status` enum('pending','resolved','dismissed') NOT NULL DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_discipline_student` (`student_id`,`status`),
  KEY `fk_discipline_by` (`recorded_by`),
  CONSTRAINT `fk_discipline_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_discipline_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `document_ai_audit`;
CREATE TABLE `document_ai_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `input_summary` text DEFAULT NULL,
  `result` text DEFAULT NULL,
  `confidence` decimal(3,2) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_daa_document` (`document_id`),
  KEY `idx_daa_student` (`student_id`),
  KEY `idx_daa_action` (`action`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `document_catalog`;
CREATE TABLE `document_catalog` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sku` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `base_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sla_days` int(11) DEFAULT NULL COMMENT 'Target turnaround in days for this document',
  `fee_type` enum('flat','per_page','per_syllabus') NOT NULL DEFAULT 'flat',
  `requirement` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sku` (`sku`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `document_request_events`;
CREATE TABLE `document_request_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `status` varchar(40) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_events_request` (`request_id`),
  CONSTRAINT `fk_events_request` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=102 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `document_requests`;
CREATE TABLE `document_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` varchar(24) DEFAULT NULL,
  -- 'online' was added by migrations/document_online_lifecycle.sql. The seed
  -- kept enum('walk_in') with the comment "All requests are counter
  -- walk-ins", which is what makes this dangerous: rebuilding from the seed
  -- produces a table that rejects every online request with a silent
  -- truncation to '' in strict mode, and an outright error otherwise.
  `source` enum('walk_in','online') NOT NULL DEFAULT 'walk_in' COMMENT 'walk_in = counter, online = filed by the student',
  `walkin_at` datetime DEFAULT NULL COMMENT 'Walked in at the counter',
  `walkin_by` int(11) DEFAULT NULL COMMENT 'Registrar who took the request',
  `counter` tinyint(3) NOT NULL DEFAULT 1 COMMENT 'Releasing counter (1-3)',
  `released_by` int(11) DEFAULT NULL COMMENT 'Registrar who released the document',
  `record_file_path` varchar(255) DEFAULT NULL COMMENT 'Retained signed record copy',
  `record_file_sha256` varchar(64) DEFAULT NULL,
  `record_file_generated_at` datetime DEFAULT NULL,
  `record_copies_issued` int(11) NOT NULL DEFAULT 0,
  `student_id` int(11) NOT NULL,
  `document_type` enum('form137','good_moral','transcript','certificate','clearance') NOT NULL,
  `catalog_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `request_type` enum('Express','Regular') NOT NULL DEFAULT 'Regular',
  `fulfillment_type` enum('Pickup','Delivery','Digital') NOT NULL DEFAULT 'Pickup',
  `delivery_address` text DEFAULT NULL,
  `payment_method` enum('Online','Cash_on_Delivery','Counter') NOT NULL DEFAULT 'Counter',
  `purpose` varchar(255) DEFAULT NULL,
  `recipient` varchar(255) DEFAULT NULL,
  `status` enum('pending','processing','approved','denied','completed','released') DEFAULT 'pending',
  -- NOTE: this enum must stay byte-identical to the live table. Two values
  -- were added by migrations and never made it back into the seed:
  --   Awaiting_Payment — the online-payment hold
  --   Shipped          — courier hand-off
  -- A MODIFY COLUMN written from this (wrong) list REPLACES the enum
  -- rather than extending it, so applying it drops both values and
  -- rewrites every affected row to ''. That is silent and it only shows
  -- up once payments stop flowing.
  -- Verify with: php tests/document_receipt_migration_check.php
  `document_status` enum('Filed','Pending_Clearance','Awaiting_Payment','Processing','Ready','Shipped','Claimed','Rejected') NOT NULL DEFAULT 'Filed',
  `blocked_reason` varchar(160) DEFAULT NULL COMMENT 'What this request is waiting on, if anything',
  `blocked_since` datetime DEFAULT NULL COMMENT 'When the current blockage began',
  `blocked_source` varchar(16) DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `approval_reason` varchar(255) DEFAULT NULL,
  `qr_hash` varchar(64) DEFAULT NULL,
  `requirement_file_path` varchar(255) DEFAULT NULL,
  `payment_ref` varchar(40) DEFAULT NULL,
  `lalamove_order_ref` varchar(40) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `ready_at` datetime DEFAULT NULL,
  `shipped_at` datetime DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `pdf_fingerprint` varchar(64) DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `denial_reason` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `request_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_date` datetime DEFAULT NULL,
  `completed_date` datetime DEFAULT NULL,
  `fee_amount` decimal(10,2) DEFAULT 0.00,
  `delivery_fee` decimal(10,2) DEFAULT NULL,
  `official_receipt` varchar(40) DEFAULT NULL,
  `release_date` datetime DEFAULT NULL,
  -- ── GCash payment receipt ────────────────────────────────────
  -- Added by migrations/document_receipt_upload.sql. Previously these
  -- existed only in that migration, so the seed described a table the
  -- application cannot use: a database built from this file rejected
  -- every receipt upload and every receipt sign-off.
  --
  -- The migration is guarded (it checks information_schema first), so
  -- applying it on top of this seed is a no-op rather than a duplicate
  -- column error. The seed and the migration are both correct here.
  `payment_receipt_path` varchar(255) DEFAULT NULL COMMENT 'Relative path to the uploaded GCash receipt image/PDF',
  `payment_receipt_sha256` char(64) DEFAULT NULL COMMENT 'SHA-256 of the receipt at upload; proves the file is unaltered',
  `payment_receipt_filename` varchar(180) DEFAULT NULL COMMENT 'Original filename as uploaded, for display only',
  `payment_receipt_uploaded_at` datetime DEFAULT NULL COMMENT 'When the student attached the receipt',
  `payment_receipt_ref` varchar(40) DEFAULT NULL COMMENT 'GCash reference number as printed on the receipt',
  `payment_receipt_verified_at` datetime DEFAULT NULL COMMENT 'When staff confirmed the receipt matches the request',
  `payment_receipt_verified_by` int(11) DEFAULT NULL COMMENT 'users.id of the staff member who verified the receipt',
  `payment_receipt_waived_at` datetime DEFAULT NULL COMMENT 'When staff accepted the request WITHOUT a receipt',
  `payment_receipt_waived_by` int(11) DEFAULT NULL COMMENT 'users.id of the staff member who waived the receipt requirement',
  `payment_receipt_waived_reason` varchar(255) DEFAULT NULL COMMENT 'Why the receipt requirement was waived — required, never blank',
  -- ── Pickup notice ────────────────────────────────────────────
  -- Sent when the request goes to Ready. Recorded so a registrar can
  -- see afterwards whether the student was actually told — a Ready
  -- document nobody was notified about is the failure that gets
  -- discovered by the student standing at the counter.
  `pickup_notified_at` datetime DEFAULT NULL COMMENT 'When the pickup/availability email was successfully sent',
  `pickup_notified_to` varchar(255) DEFAULT NULL COMMENT 'Recipients the pickup email actually reached',
  `pickup_notify_error` varchar(255) DEFAULT NULL COMMENT 'Why the pickup email could not be sent; NULL when it sent',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_request_id` (`request_id`),
  UNIQUE KEY `uq_qr_hash` (`qr_hash`),
  KEY `processed_by` (`processed_by`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_status` (`status`),
  KEY `idx_document_type` (`document_type`),
  KEY `idx_catalog_id` (`catalog_id`),
  KEY `idx_document_status` (`document_status`),
  KEY `idx_request_type` (`request_type`),
  KEY `idx_fulfillment_type` (`fulfillment_type`),
  KEY `fk_document_requests_walkin_by` (`walkin_by`),
  KEY `fk_document_requests_released_by` (`released_by`),
  CONSTRAINT `document_requests_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_requests_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_document_requests_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `document_catalog` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_document_requests_released_by` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_document_requests_walkin_by` FOREIGN KEY (`walkin_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `documents`;
CREATE TABLE `documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) DEFAULT NULL,
  `enroll_no` varchar(20) DEFAULT NULL,
  `enroll_status` varchar(20) DEFAULT NULL,
  `doc_type` enum('enrollment','transcript','health','photo','clearance','other','form_137','psa') NOT NULL,
  `filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `file_sha256` varchar(64) DEFAULT NULL COMMENT 'SHA-256 of the stored file, printed on a CTC',
  `description` text DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `category` varchar(40) DEFAULT NULL,
  `is_locked` tinyint(1) DEFAULT 0,
  `file_hash` varchar(64) DEFAULT NULL,
  `content_text` text DEFAULT NULL,
  `ai_classified` tinyint(1) DEFAULT 0,
  `ai_confidence` decimal(3,2) DEFAULT NULL,
  `ai_valid` tinyint(1) DEFAULT NULL,
  `ai_validation_note` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `uploaded_by` (`uploaded_by`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_doc_type` (`doc_type`),
  KEY `idx_documents_enroll_no` (`enroll_no`),
  KEY `idx_documents_file_hash` (`file_hash`),
  CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `documents_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `emergency_contacts`;
CREATE TABLE `emergency_contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `relationship` varchar(50) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  CONSTRAINT `fk_emergency_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `enrollment_history`;
CREATE TABLE `enrollment_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `student_number` varchar(20) DEFAULT NULL,
  `course` varchar(100) DEFAULT NULL,
  `year_level` int(11) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `semester` varchar(20) DEFAULT NULL,
  `section` varchar(20) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'enrolled',
  `enrolled_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_enrollment_history_student` (`student_id`),
  CONSTRAINT `fk_eh_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `enrollments`;
CREATE TABLE `enrollments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `name_suffix` varchar(10) DEFAULT NULL,
  `student_number` varchar(20) DEFAULT NULL COMMENT 'Optional during application; assigned at accept',
  `birth_date` date DEFAULT NULL,
  `gender` enum('Male','Female') DEFAULT NULL,
  `civil_status` varchar(20) DEFAULT NULL,
  `religion` varchar(50) DEFAULT NULL,
  `nationality` varchar(50) DEFAULT NULL,
  `place_of_birth` varchar(100) DEFAULT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `mother_name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `prev_school_name` varchar(100) DEFAULT NULL,
  `prev_school_last_year` varchar(20) DEFAULT NULL,
  `prev_school_graduated_sy` varchar(20) DEFAULT NULL,
  `emergency_name` varchar(100) DEFAULT NULL,
  `emergency_relationship` varchar(50) DEFAULT NULL,
  `emergency_contact` varchar(20) DEFAULT NULL,
  `course` varchar(100) DEFAULT NULL,
  `major` varchar(100) DEFAULT NULL,
  `year_level` int(11) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `semester` varchar(20) DEFAULT NULL,
  `section` varchar(20) DEFAULT NULL,
  `status` enum('pending','received','re-enrolled','duplicate') NOT NULL DEFAULT 'pending',
  `received_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_enrollments_status` (`status`),
  KEY `idx_enrollments_student_number` (`student_number`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `finance`;
CREATE TABLE `finance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_finance_student` (`student_id`),
  CONSTRAINT `fk_finance_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `guardians`;
CREATE TABLE `guardians` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `relationship` enum('father','mother','guardian','spouse','sibling') NOT NULL,
  `contact_number` varchar(15) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `is_emergency` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_contact` (`contact_number`),
  CONSTRAINT `guardians_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `health_records`;
CREATE TABLE `health_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `blood_type` varchar(5) DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `pre_existing_conditions` text DEFAULT NULL,
  `immunization_records` text DEFAULT NULL,
  `height` decimal(5,2) DEFAULT NULL,
  `weight` decimal(5,2) DEFAULT NULL,
  `clinic_visits` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `blood_pressure` varchar(12) DEFAULT NULL,
  `dietary_restrictions` text DEFAULT NULL,
  `medical_history` text DEFAULT NULL,
  `surgical_history` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  CONSTRAINT `health_records_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `health_visits`;
CREATE TABLE `health_visits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `visit_date` date DEFAULT NULL,
  `complaint` varchar(255) DEFAULT NULL,
  `diagnosis` varchar(255) DEFAULT NULL,
  `temperature` decimal(4,1) DEFAULT NULL,
  `blood_pressure` varchar(12) DEFAULT NULL,
  `treatment` varchar(255) DEFAULT NULL,
  `medication` text DEFAULT NULL,
  `physician` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_time` datetime DEFAULT NULL,
  `reason_for_visit` varchar(255) DEFAULT NULL,
  `assessment` varchar(255) DEFAULT NULL,
  `action_taken` varchar(255) DEFAULT NULL,
  `nurse_notes` text DEFAULT NULL,
  `record_status` enum('Recorded','Draft','Pending','Cancelled') NOT NULL DEFAULT 'Recorded',
  `recorded_by` int(11) DEFAULT NULL,
  `blood_type` varchar(5) DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `height` decimal(5,2) DEFAULT NULL,
  `weight` decimal(5,2) DEFAULT NULL,
  `pre_existing_conditions` text DEFAULT NULL,
  `immunization_records` text DEFAULT NULL,
  `visit_type` varchar(32) DEFAULT NULL,
  `onset_at` datetime DEFAULT NULL,
  `incident_details` text DEFAULT NULL,
  `symptoms` text DEFAULT NULL,
  `pain_score` tinyint(3) DEFAULT NULL,
  `red_flags` text DEFAULT NULL,
  `pulse` smallint(6) DEFAULT NULL,
  `respiratory_rate` smallint(6) DEFAULT NULL,
  `oxygen_saturation` decimal(5,2) DEFAULT NULL,
  `current_medications` text DEFAULT NULL,
  `disposition` varchar(64) DEFAULT NULL,
  `return_precautions` text DEFAULT NULL,
  `follow_up_plan` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_recorded_by` (`recorded_by`),
  CONSTRAINT `fk_visit_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(191) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email_ip_time` (`email`,`ip_address`,`attempted_at`),
  KEY `idx_ip_time` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `mock_lalamove_orders`;
CREATE TABLE `mock_lalamove_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` varchar(40) NOT NULL,
  `quotation_id` varchar(40) DEFAULT NULL,
  `request_id` int(11) DEFAULT NULL,
  `pickup` varchar(255) DEFAULT NULL,
  `dropoff` varchar(255) DEFAULT NULL,
  `item` varchar(100) DEFAULT NULL,
  `total_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `distance_km` decimal(6,2) DEFAULT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `driver_phone` varchar(20) DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'ASSIGNING_RIDER',
  `tracking_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lala_order_id` (`order_id`),
  KEY `idx_lala_request` (`request_id`),
  CONSTRAINT `fk_lala_request` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `mock_payment_transactions`;
CREATE TABLE `mock_payment_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` varchar(40) NOT NULL,
  `request_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(8) NOT NULL DEFAULT 'PHP',
  `status` enum('pending','completed','failed') NOT NULL DEFAULT 'pending',
  `method` varchar(20) NOT NULL DEFAULT 'Online',
  `due_on` enum('now','delivery') NOT NULL DEFAULT 'now',
  `gateway` varchar(20) NOT NULL DEFAULT 'mock',
  `paymongo_intent_id` varchar(40) DEFAULT NULL,
  `paymongo_payment_id` varchar(40) DEFAULT NULL,
  `payment_url` varchar(255) DEFAULT NULL,
  `callback_url` varchar(255) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `raw_request` text DEFAULT NULL,
  `raw_response` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_txn_id` (`transaction_id`),
  UNIQUE KEY `uq_txn_paymongo_intent` (`paymongo_intent_id`),
  KEY `idx_txn_request` (`request_id`),
  KEY `idx_txn_gateway` (`gateway`),
  KEY `idx_txn_paymongo_payment` (`paymongo_payment_id`),
  CONSTRAINT `fk_txn_request` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `otp_codes`;
CREATE TABLE `otp_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `purpose` enum('login','reset') NOT NULL DEFAULT 'login',
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  -- Added by migrations/security_hardening_phase1.sql. NOT NULL DEFAULT 0,
  -- so the seed has to declare it: without it a seeded database has an
  -- OTP table with no attempt counter, and the brute-force lockout in
  -- auth_security.php silently never triggers.
  `verify_attempts` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_otp_user` (`user_id`),
  KEY `idx_otp_purpose` (`user_id`,`purpose`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
-- Also created by migrations/security_hardening_phase1.sql; it was
-- missing from the SEED only. That is still a real gap: the seed is what
-- a fresh install is built from, so a seeded database had no
-- password_reset_grants at all and every "forgot password" request would
-- fail on a missing-table error unless that migration happened to be run
-- afterwards. auth_security.php reads and writes it (11 references), so
-- the feature looks implemented and simply cannot run.
--
-- Declared IF NOT EXISTS in the migration, so the two agree and running
-- the migration over this seed is a no-op rather than a duplicate error.
--
-- Only the HASH of the reset token is stored, never the token itself: a
-- read-only compromise of this table must not yield usable reset links.
DROP TABLE IF EXISTS `password_reset_grants`;
CREATE TABLE `password_reset_grants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_grant_user` (`user_id`,`expires_at`),
  CONSTRAINT `fk_grant_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `queue_tickets`;
CREATE TABLE `queue_tickets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `queue_date` date NOT NULL,
  `ticket_number` int(10) unsigned NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `student_name` varchar(191) NOT NULL,
  `student_number` varchar(50) DEFAULT NULL,
  `course` varchar(100) DEFAULT NULL,
  `status` enum('waiting','serving','completed','no-show','removed','cancelled') NOT NULL DEFAULT 'waiting',
  `counter` int(10) unsigned NOT NULL DEFAULT 1,
  -- Four lanes: txn_type (service | claim) x priority_group (student | priority).
  -- Both DEFAULT to the old single-lane behaviour, so a ticket written before
  -- the cut-over is exactly a "service / student" ticket and no backfill is needed.
  -- FROM migrations/queue_lanes_cutoff.sql; api/queue-public.php filters on both.
  `txn_type` enum('service','claim') NOT NULL DEFAULT 'service',
  `priority_group` enum('student','priority') NOT NULL DEFAULT 'student',
  `purpose` enum('general','document_request','payment','enrollment') NOT NULL DEFAULT 'general',
  `document_request_id` int(11) DEFAULT NULL COMMENT 'Document request this visit relates to',
  `card_uid` varchar(50) DEFAULT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  `called_at` datetime DEFAULT NULL,
  `served_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_day` (`queue_date`,`ticket_number`),
  KEY `idx_queue_date_status` (`queue_date`,`status`),
  KEY `idx_student` (`student_id`),
  KEY `idx_joined_at` (`joined_at`),
  KEY `idx_queue_purpose` (`queue_date`,`purpose`),
  KEY `idx_queue_lane` (`queue_date`,`status`,`txn_type`,`priority_group`),
  KEY `fk_queue_document_request` (`document_request_id`),
  CONSTRAINT `fk_queue_document_request` FOREIGN KEY (`document_request_id`) REFERENCES `document_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_queue_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- QUEUE DAY SETTINGS
--
-- One row per queue_date, created lazily the first time a registrar saves
-- something. Per-day rather than a global settings table because the caps are
-- explicitly "per day", and because who cut the line off, and when, is an audit
-- fact the registrar wants to see afterwards.
--
-- max_taps_* of 0 means UNLIMITED, so a fresh install behaves exactly as it
-- did before: open all day, no cap, until someone saves a value.
-- shared/queue_helpers.php tolerates the table being absent, so nothing here
-- is a hard dependency of the queue.
--
-- FROM migrations/queue_lanes_cutoff.sql. Without it here, api/queue.php's
-- save / cut-off / reopen endpoints and shared/queue_helpers.php's read all
-- named a table no fresh install had.
DROP TABLE IF EXISTS `queue_day_settings`;
CREATE TABLE `queue_day_settings` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
-- RFID READER DEVICES
--
-- The physical readers that tap or scan a card, and the name of the place they
-- sit. A scan records a reader_id (see rfid_scan_logs.scanner_id) so the office
-- can tell a tap at the entrance from one at the registrar's window.
--
-- Added to the dump because five files read this table unguarded -
-- api/card-readers.php, registrar/rfid-readers.php, registrar/rfid-kiosk.php,
-- api/rfid-scan.php and shared/rfid_helpers.php - and none of them check
-- whether it exists first. It was absent from both this dump and the live
-- database, so the RFID Kiosk, the Readers page and the whole card-readers
-- endpoint were all one "table not found" away from being dead on a fresh
-- install, on a database the header calls complete.
--
-- reader_type is what a scan MEANS, not what the hardware is:
--   entrance  the reader is a door, a tap records an entry
--   exit      the reader is a door, a tap records an exit
--   both      one reader covers both directions (a desktop reader)
-- api/rfid-scan.php branches on exactly these three values.
DROP TABLE IF EXISTS `card_readers`;
CREATE TABLE `card_readers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  -- Widths match the live table. The seed carried 120/160/64 and every
  -- column NOT NULL, while production has 100/100/50 with name and
  -- status still NOT NULL but location and reader_code nullable. That
  -- combination is not cosmetic: seeding NOT NULL columns means a
  -- reader cannot be created without a location and a code, so the
  -- "add reader before enrolling anyone" step fails on a fresh install.
  -- Added by migrations/security_hardening_phase1.sql.
  `name` varchar(100) NOT NULL,
  `location` varchar(100) DEFAULT NULL,
  `reader_type` enum('entrance','exit','both') NOT NULL DEFAULT 'both',
  `reader_code` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reader_code` (`reader_code`),
  KEY `idx_status` (`status`),
  KEY `idx_reader_type` (`reader_type`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `rfid_cards`;
CREATE TABLE `rfid_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) DEFAULT NULL,
  `card_uid` varchar(50) NOT NULL,
  `card_type` enum('rfid','qrcode') DEFAULT 'rfid',
  `status` enum('active','inactive','lost','expired','available','archived') DEFAULT 'available',
  `issued_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `archive_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `registered_at` timestamp NULL DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `qr_code_path` varchar(255) DEFAULT NULL,
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `card_uid` (`card_uid`),
  KEY `student_id` (`student_id`),
  KEY `idx_card_uid` (`card_uid`),
  KEY `idx_status` (`status`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_available` (`status`,`student_id`),
  CONSTRAINT `rfid_cards_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `rfid_scan_logs`;
CREATE TABLE `rfid_scan_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `card_uid` varchar(50) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `location` varchar(100) DEFAULT 'Main Gate',
  `event_type` enum('entry','exit','library','cafeteria','clinic','other','queue_join','queue_call','queue_serving','queue_completed','queue_no_show','queue_cancelled') DEFAULT 'entry',
  `status` enum('success','denied','unknown') DEFAULT 'success',
  `scanner_id` varchar(50) DEFAULT 'scanner-01',
  `scanned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_card_uid` (`card_uid`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_scanned_at` (`scanned_at`),
  KEY `idx_location` (`location`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `status_tracker`;
CREATE TABLE `status_tracker` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `previous_status` varchar(50) DEFAULT NULL,
  `current_status` varchar(50) NOT NULL,
  `reason` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `effective_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `changed_by` (`changed_by`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_current_status` (`current_status`),
  CONSTRAINT `status_tracker_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `status_tracker_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `student_ids`;
CREATE TABLE `student_ids` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `id_number` varchar(20) DEFAULT '' COMMENT 'Enrollment department ID - assigned later',
  `id_type` enum('school_id','library','cafeteria') DEFAULT 'school_id',
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `status` enum('active','inactive','lost') DEFAULT 'active',
  `photo_path` varchar(255) DEFAULT NULL,
  `qr_code_path` varchar(255) DEFAULT NULL,
  `rfid_card_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `qr_payload` varchar(255) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `card_color` varchar(20) DEFAULT 'blue',
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `idx_id_number` (`id_number`),
  KEY `idx_status` (`status`),
  KEY `idx_student_ids_rfid_card_id` (`rfid_card_id`),
  CONSTRAINT `fk_student_ids_rfid_card` FOREIGN KEY (`rfid_card_id`) REFERENCES `rfid_cards` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_ids_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `student_notifications`;
CREATE TABLE `student_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `related_doc_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sn_student` (`student_id`),
  KEY `idx_sn_read` (`is_read`),
  CONSTRAINT `fk_sn_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_number` varchar(20) DEFAULT '' COMMENT 'Enrollment department ID -- assigned later',
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `gender` enum('Male','Female') DEFAULT NULL,
  `civil_status` enum('Single','Married','Widowed','Separated') DEFAULT NULL,
  `birth_date` date NOT NULL,
  `place_of_birth` varchar(100) DEFAULT NULL,
  `nationality` varchar(50) DEFAULT NULL,
  `religion` varchar(50) DEFAULT NULL,
  `address` text NOT NULL,
  `contact_number` varchar(15) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `course` varchar(100) DEFAULT NULL,
  `major` varchar(100) DEFAULT NULL,
  `year_level` int(11) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `semester` varchar(20) DEFAULT NULL,
  `adviser_id` int(11) DEFAULT NULL,
  `section` varchar(20) DEFAULT NULL,
  `status` enum('enrolled','active','graduate','alumni','dropped') NOT NULL DEFAULT 'enrolled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `lrn` varchar(12) DEFAULT NULL COMMENT 'LRN - Learner Reference Number',
  `name_suffix` varchar(10) DEFAULT NULL COMMENT 'e.g. Jr., III, Sr.',
  `mother_name` varchar(100) DEFAULT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `birth_country` varchar(60) DEFAULT NULL,
  `graduation_date` date DEFAULT NULL COMMENT 'Date the degree was conferred',
  -- Transfer / prior-school history. Added by
  -- migrations/add_previous_school_fields.sql. Without these in the seed a
  -- fresh install fails the enrolment form's previous-school block on an
  -- unknown column.
  `previous_school` varchar(150) DEFAULT NULL COMMENT 'School last attended before this one',
  `school_year_graduated` varchar(20) DEFAULT NULL,
  `last_year_level_completed` varchar(30) DEFAULT NULL,
  -- Bounce tracking + address quality, from
  -- migrations/security_hardening_phase1.sql.
  -- email_is_placeholder is the important one: without it a seeded database
  -- has no way to tell a real address from the synthetic one enrolment
  -- invents, so the pickup-notice sender would try to email placeholders
  -- and every send would bounce.
  `email_bounced_at` datetime DEFAULT NULL,
  `email_bounce_reason` varchar(255) DEFAULT NULL,
  `email_is_placeholder` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_student_number` (`student_number`),
  KEY `idx_status` (`status`),
  KEY `idx_course` (`course`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('admin','registrar','staff','teacher','student','nurse') NOT NULL DEFAULT 'staff',
  `rfid_uid` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `student_id` int(11) DEFAULT NULL,
  `username` varchar(60) DEFAULT NULL,
  `login_attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  -- Session invalidation + bounce tracking, from
  -- migrations/security_hardening_phase1.sql.
  --
  -- password_changed_at is load-bearing, not bookkeeping: session_config.php
  -- compares it against $_SESSION['login_time'] and forces a re-login when
  -- they disagree. That is what makes a password reset actually terminate
  -- existing sessions. On a seeded database without the column, a stolen
  -- cookie survives the victim changing their password.
  `password_changed_at` datetime DEFAULT NULL,
  `email_bounced_at` datetime DEFAULT NULL,
  `email_bounce_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_email` (`email`),
  KEY `idx_student_id` (`student_id`),
  CONSTRAINT `fk_users_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
-- ---------------------------------------------------------------------------
-- Staff accounts.
--
-- The three staff logins are seeded so a fresh install can be signed into:
-- ONE per role - admin, registrar, nurse. Change their passwords before the
-- host goes live, because the hashes below are in this repository and
-- therefore known to anyone who has read it:
--
--   php create_admin.php
--
-- THREE, not four. A second admin (roldantiu89@gmail.com, ADM-002) used to
-- be seeded here and was removed. Two admins sharing ONE password hash are
-- two copies of a single credential, and that address had already caused a
-- real incident - it was a student's own address, so the UNIQUE index on
-- users.email made that student's portal-account creation fail and silently
-- discard their address (see SECURITY-ROADMAP.md). One admin cannot collide
-- with anyone, because you are about to change its password anyway.
--
-- Create any additional staff from inside the application once you are in and
-- can set a password nobody else has seen.
--
-- No student accounts and no document catalog are seeded. A student login
-- is personal data and a fresh install has no students, and the catalog is
-- per-school business policy -- fees and turnaround targets differ. Add
-- both from inside the application once you are in:
--
--   php create_admin.php --catalog    document types the desk will offer
--
-- Students are enrolled through the registrar's own screens, and each one
-- gets its portal account automatically (shared/functions.php creates the
-- matching users row with role='student').
--
-- The student_id column is NULL for every row above, so this INSERT does
-- not depend on any student existing.
-- ---------------------------------------------------------------------------
INSERT INTO `users` (`id`, `email`, `password_hash`, `full_name`, `role`, `rfid_uid`, `is_active`, `created_at`, `updated_at`, `student_id`, `username`, `login_attempts`, `locked_until`) VALUES
(1,'admin@gmail.com','$2y$10$f9PmndF92hBFI/jeJAWxC.Pua3Osob3.zkWHn9GRSTQXSyPX8x0dK','System Administrator','admin',NULL,1,'2026-07-07 06:42:45','2026-09-23 12:57:09',NULL,'ADM-001',0,NULL),
(2,'registrar@gmail.com','$2y$10$zj33OjRB93RcPZWd2/f4VudcEqzDCfZdLAajEcZQ7LABuuEKeqFyu','Registrar Staff','registrar',NULL,1,'2026-07-07 06:42:45','2026-09-23 12:57:10',NULL,'RGS-001',0,NULL),
(7,'norse@gmail.com','$2y$10$mg/TmAFfYjwZNW34o6IGHedMnnZ04hUmYgm5iGy7OvGAxtDEoGWee','norse','nurse',NULL,1,'2026-09-02 18:16:15','2026-09-02 18:17:05',NULL,NULL,0,NULL);

-- Explicit COMMIT. Every statement above commits on its own under the
-- default autocommit, so this changes nothing in the normal case - but an
-- import is run by people who do not know that, and someone with
-- autocommit off (a hosting panel's import tool, a phpMyAdmin box with
-- the setting changed) would otherwise be left with a half-built schema
-- that vanishes on disconnect.
COMMIT;

SET FOREIGN_KEY_CHECKS = 1;

-- End of registrar_ai.sql
