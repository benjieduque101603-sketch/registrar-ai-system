-- ============================================================================
--  CLEANUP: Receive Student seed data
--  Date: 2026-10-02
--  Removes everything migrations/seed_receive_students.sql wrote, and
--  everything the UI then created on top of it by pressing Accept.
--
--  HOW TO RUN
--    Step 1 (optional, safe): run the whole file and read the reports. It
--             deletes nothing on this pass — the DELETEs sit behind the
--             @execute flag, which is 0 by default.
--    Step 2:            set @execute to 1 near the top and run it again.
--
--    mysql -u <user> -p <db> < migrations/clear_receive_students.sql
--
--  ── WHAT GETS REMOVED ──────────────────────────────────────────────────────
--  1. `students` rows carrying both seed markers. These are the ones Accept
--     created, plus any academic_history / emergency_contacts / guardians it
--     wrote — those tables cascade from students (see registrar_ai.sql), so one
--     DELETE clears them.
--  2. `users` rows pointing at those students. The FK is ON DELETE SET NULL,
--     not CASCADE, so a portal account would SURVIVE as an orphan and still be
--     able to log in. It is deleted explicitly here, matched on the seeded
--     email domain — a portal login with no student row is broken anyway.
--  3. `enrollments` rows carrying both seed markers.
--
--  ── WHY TWO MARKERS ────────────────────────────────────────────────────────
--  Matches only rows where BOTH hold:
--      address LIKE 'SEEDDATA-%'  AND  email LIKE '%@seed.receive.test'
--  One marker is not enough. A real applicant could plausibly carry an address
--  beginning "SEEDDATA-" after a typo, and a real student's email domain is
--  @bestlink.edu.ph so it cannot match the .test one — requiring both means a
--  genuine record has to be wrong in two independent ways to be deleted.
--
--  `student_number` is deliberately NOT used as a marker: Accept overwrites it
--  with a real minted number, so it cannot identify the seed afterwards.
--
--  ⚠ TAKE A BACKUP FIRST. This deletes real rows from `students`.
-- ============================================================================

SET @execute = 0;   -- change to 1 to actually delete

-- ── DRY RUN REPORT ─────────────────────────────────────────────────────────
SELECT 'enrollments (seed rows)'  AS what, COUNT(*) AS would_delete
FROM enrollments
WHERE address LIKE 'SEEDDATA-%' AND email LIKE '%@seed.receive.test'
UNION ALL
SELECT 'students (created by Accept)', COUNT(*)
FROM students
WHERE address LIKE 'SEEDDATA-%' AND email LIKE '%@seed.receive.test'
UNION ALL
SELECT 'portal accounts orphaned by them', COUNT(*)
FROM users u
JOIN students s ON s.id = u.student_id
WHERE s.address LIKE 'SEEDDATA-%' AND s.email LIKE '%@seed.receive.test';

-- Show what is about to go, so it can be eyeballed before step 2.
SELECT id, student_number, first_name, last_name, email, status
FROM students
WHERE address LIKE 'SEEDDATA-%' AND email LIKE '%@seed.receive.test'
ORDER BY id;

-- ── SAFETY CHECK: refuse to run on an unexplained row ──────────────────────
-- A row carrying one marker but not the other was not written by this seed.
-- Delete nothing and make the operator look, exactly as
-- tests/clear_seeded_students.php does.
SELECT id, student_number, address, email
FROM students
WHERE (address LIKE 'SEEDDATA-%') <> (email LIKE '%@seed.receive.test')
   OR (address IS NULL) <> (email IS NULL)
LIMIT 20;

-- !! READ THE OUTPUT ABOVE BEFORE PROCEEDING. If that query returned rows,
-- !! they are not seed rows. Investigate before running with @execute = 1.

-- ⚠ DO NOT DISABLE FOREIGN_KEY_CHECKS HERE.
-- Setting FOREIGN_KEY_CHECKS = 0 also switches OFF ON DELETE CASCADE, which
-- silently orphans every child row instead of removing it. Verified: with the
-- checks disabled, deleting a seeded student left its guardians and
-- academic_history rows behind pointing at a student_id that no longer exists.
-- The cascade is exactly what we want here — `students` is the parent of ~19
-- tables in registrar_ai.sql and they all cascade, so one DELETE clears them.

-- ── 1. Portal accounts first: the FK here is ON DELETE SET NULL, so the delete
--       of the student below would detach these rather than remove them, and
--       an account with no student row can still log in. ─────────────────────
DELETE u FROM users u
JOIN students s ON s.id = u.student_id
WHERE s.address LIKE 'SEEDDATA-%' AND s.email LIKE '%@seed.receive.test'
  AND @execute = 1;

-- ── 2. Students created by Accepting the seed. CASCADE removes guardians,
--       academic_history, emergency_contacts, documents, rfid_cards,
--       status_tracker, student_ids and the rest. ────────────────────────────
DELETE FROM students
WHERE address LIKE 'SEEDDATA-%' AND email LIKE '%@seed.receive.test'
  AND @execute = 1;

-- ── 3. The applicants themselves. No FK points at this table. ──────────────
DELETE FROM enrollments
WHERE address LIKE 'SEEDDATA-%' AND email LIKE '%@seed.receive.test'
  AND @execute = 1;

-- ── CONFIRM ────────────────────────────────────────────────────────────────
SELECT 'enrollments left' AS check_name, COUNT(*) AS remaining
FROM enrollments
WHERE address LIKE 'SEEDDATA-%' AND email LIKE '%@seed.receive.test'
UNION ALL
SELECT 'students left', COUNT(*)
FROM students
WHERE address LIKE 'SEEDDATA-%' AND email LIKE '%@seed.receive.test';

-- Both should read 0. If students is 0 but you ran an Accept that created
-- portal accounts, check `users` for the seeded email domain directly — the
-- delete above keys on the join, so an account whose student row was already
-- removed some other way will not be caught:
--
--   SELECT id, username, email FROM users WHERE email LIKE '%@seed.receive.test';
--
-- Orphan check. Must return 0 rows. Anything listed here is a child row whose
-- parent student is gone — the cascade did not fire, which means the seed was
-- cleaned up by a script that disabled FOREIGN_KEY_CHECKS. Delete these by
-- hand; they are invisible everywhere in the UI but still occupy the tables.
SELECT 'guardians' AS orphaned_in, COUNT(*) AS rows_left FROM guardians
  WHERE student_id NOT IN (SELECT id FROM students)
UNION ALL SELECT 'academic_history', COUNT(*) FROM academic_history
  WHERE student_id NOT IN (SELECT id FROM students)
UNION ALL SELECT 'emergency_contacts', COUNT(*) FROM emergency_contacts
  WHERE student_id NOT IN (SELECT id FROM students);
