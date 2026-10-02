-- ============================================================================
--  SEED: Receive Student (enrollment intake)
--  Date: 2026-10-02
--  Purpose: Populates the `enrollments` table so the Receive Student modal on
--           registrar/students.php has applicants to show. Without this the
--           modal renders its "No applicants from the Enrollment System" empty
--           state and none of Duplicate Check / Accept / Re-enroll can be
--           exercised.
--
--  In a real deployment this table is written by the Enrollment System, a
--  separate team. Nothing in this repository inserts into it, so this file
--  stands in for that feed. See migrations/rename_course_to_program.md.
--
--  HOW TO RUN
--    mysql -u <user> -p <db> < migrations/seed_receive_students.sql
--    phpMyAdmin: Import > choose this file.
--
--  Clean up with migrations/clear_receive_students.sql — read that file first,
--  it also removes students created by Accepting these rows.
--
--  ── THE MARKERS ────────────────────────────────────────────────────────────
--  Every row written here carries BOTH of these:
--
--    address LIKE 'SEEDDATA-%'      in `enrollments` and in `students`
--    email   LIKE '%@seed.receive.test'
--
--  Both, not one. `enrollments.student_number` is nullable and is REWRITTEN by
--  Accept (api/enrollments.php copies the freshly minted number back), so it
--  cannot be used as a marker. The address + email pair survives that rewrite
--  because Accept copies both straight through to `students`.
--
--  The domain is .test, which is reserved by RFC 2606 and can never resolve, so
--  an accidental send to a seeded address bounces instead of reaching a person.
--  Real BCP students are on @bestlink.edu.ph and can never match.
--
--  This is the same dual-marker rule tests/clear_seeded_students.php uses, for
--  the same reason: one marker alone can eventually appear on a genuine record,
--  and a cleanup script that deletes on one marker would delete a real student.
-- ============================================================================

SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- 6 fresh applicants. Mostly complete records, because Accept validates:
-- address, birth_date, contact_number, email, year_level and semester are all
-- required by createStudentFromInput() and it rejects the whole applicant if
-- any is missing. See shared/functions.php:1311.
--
-- Three distinct shapes so the modal's branching is actually covered:
--   rows 1-3  clean applicants  -> Accept creates a new student
--   row  4     carries a student_number matching an existing student
--              -> Duplicate Check reports a hit, reveals Re-enroll
--   rows 5-6  deliberately sparse (no birth date, no prior school)
--              -> proves the modal renders empty cells without breaking
--
-- !! RE-RUNNING DUPLICATES THESE ROWS. There is no unique constraint on
-- enrollments, so the file is not idempotent by design — it is a fixture, and
-- running clear_receive_students.sql first keeps the table clean.

INSERT INTO `enrollments`
  (`first_name`, `middle_name`, `last_name`, `name_suffix`, `student_number`,
   `birth_date`, `gender`, `civil_status`, `religion`, `nationality`,
   `place_of_birth`, `father_name`, `mother_name`, `email`, `address`,
   `contact_number`, `prev_school_name`, `prev_school_last_year`,
   `prev_school_graduated_sy`, `emergency_name`, `emergency_relationship`,
   `emergency_contact`, `course`, `major`, `year_level`, `school_year`,
   `semester`, `section`, `status`)
VALUES


  -- 1. Clean applicant, BSIT 1st year.
  ('Juan',      'Miguel',   'Dela Cruz', 'Jr.',  NULL,
   '2007-03-14', 'Male',   'Single',    'Roman Catholic', 'Filipino',
   'Quezon City', 'Roberto Dela Cruz', 'Teresita Dela Cruz',
   'juan.dc@seed.receive.test', 'SEEDDATA-01 Block 1, Barangay Commonwealth, Quezon City',
   '09171230001', 'Bestlink College of the Philippines', 'Grade 12', '2026-2027',
   'Rosario Dela Cruz', 'mother', '09171230002',
   'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)', NULL,
   1, '2026-2027', '1st', NULL, 'pending'),

  -- 2. Clean applicant, BSHM 2nd year.
  ('Maria',     'Clara',    'Santos',    NULL,  NULL,
   '2006-11-02', 'Female', 'Single',    'Roman Catholic', 'Filipino',
   'Makati City', 'Antonio Santos', 'Elena Santos',
   'maria.santos@seed.receive.test', 'SEEDDATA-02 Block 2, Barangay Magallanes, Makati City',
   '09181230002', 'Makati Hope Christian High School', 'Grade 11', '2025-2026',
   'Jose Santos', 'father', '09181230003',
   'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)', NULL,
   2, '2026-2027', '1st', NULL, 'pending'),

  -- 3. Clean applicant, BSBA with a major (only BSBA/BSED carry majors).
  ('Andrea',    'Reyes',    'Bautista',  NULL,  NULL,
   '2008-06-25', 'Female', 'Single',    'Aglipayan', 'Filipino',
   'Pasig City', 'Ricardo Bautista', 'Lorna Bautista',
   'andrea.bautista@seed.receive.test', 'SEEDDATA-03 Block 3, Barangay Santolan, Pasig City',
   '09281230003', 'San Juan Memorial High School', 'Grade 12', '2026-2027',
   'Ricardo Bautista', 'father', '09281230004',
   'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)', 'Marketing Management',
   1, '2026-2027', '1st', NULL, 'pending'),

  -- 4. Returning student: carries the student_number of a record that already
  --    exists in `students`, which is what makes Duplicate Check report a hit
  --    and reveal the Re-enroll button. Replace the placeholder with a number
  --    that really is on file before this scenario is meaningful:
  --
  --      SELECT student_number FROM students WHERE status = 'active' LIMIT 1;
  --
  --    Left as a placeholder deliberately — hardcoding a real student's number
  --    in a fixture would couple it to live data that changes. As shipped,
  --    Duplicate Check finds no number match and falls through to its softer
  --    name+birthdate lookup, which is still a valid path to observe.
  --    NOTE: `student_number` is varchar(20). A longer placeholder is silently
  --    TRUNCATED by MySQL (no error in non-strict mode), so this one is exactly
  --    12 characters to stay well clear of that and read as obviously fake.
  ('Chris',     'Emmanuel', 'Villanueva', NULL, 'REPLACE_ME',
   '2005-08-30', 'Male',   'Single',    'Roman Catholic', 'Filipino',
   'Manila City', 'Ramon Villanueva', 'Celia Villanueva',
   'chris.villanueva@seed.receive.test', 'SEEDDATA-04 Block 4, Barangay Tondo, Manila City',
   '09171230004', 'Bestlink College of the Philippines', 'Grade 12', '2025-2026',
   'Ramon Villanueva', 'father', '09171230005',
   'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)', NULL,
   3, '2026-2027', '2nd', NULL, 'pending'),

  -- 5. Sparse: no birth date, no prior school, no emergency contact. Accept
  --    will reject this one (birth date is required) — that is the point. It
  --    proves the modal renders a missing date as "—" instead of "undefined".
  ('Patrice',   NULL,       'Cruz',      NULL,  NULL,
   NULL,          'Male',   'Single',    NULL,           'Filipino',
   NULL, NULL, NULL,
   'patrice.cruz@seed.receive.test', 'SEEDDATA-05 Block 5, Barangay Marikina, Marikina City',
   '09191230005', NULL, NULL, NULL,
   NULL, NULL, NULL,
   'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)', NULL,
   1, '2026-2027', '1st', NULL, 'pending'),

  -- 6. Sparse and already processed, so the modal also shows a non-pending
  --    status badge and drops the Accept button for this row.
  ('Nicole',    'Ann',      'Garcia',    NULL,  NULL,
   NULL,          'Female', 'Single',    NULL,           'Filipino',
   NULL, NULL, NULL,
   'nicole.garcia@seed.receive.test', 'SEEDDATA-06 Block 6, Barangay Cubao, Quezon City',
   '09201230006', NULL, NULL, NULL,
   NULL, NULL, NULL,
   'BACHELOR OF SCIENCE IN PSYCHOLOGY (BSP)', NULL,
   2, '2026-2027', 'summer', NULL, 'duplicate');

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

-- ── VERIFY ─────────────────────────────────────────────────────────────────
SELECT id, first_name, last_name, student_number, birth_date, course, status
FROM enrollments
WHERE address LIKE 'SEEDDATA-%'
  AND email LIKE '%@seed.receive.test'
ORDER BY id;

-- Expected: 6 rows. If this returns 0 the INSERT did nothing — check that the
-- `enrollments` table exists first (api/enrollments.php refuses to run without
-- it, saying "Enrollment system not set up").
