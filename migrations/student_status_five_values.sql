-- -------------------------------------------------------------------------
--  STUDENT STATUS ? 5 VALUES
--  enrolled · active · graduate · alumni · dropped
-- -------------------------------------------------------------------------
--
--  Run this BEFORE deploying the code that writes the new values.
--
--  Why this needs a migration at all
--  --------------------------------
--  The column is an ENUM with 8 values, and four separate places in the app
--  each carried their own list of what a status could be. They had already
--  drifted apart in two ways that mattered:
--
--    · `alumni` was drawn as a slice of the AI insights pie while not being a
--      value the column accepted.
--    · `archived` was written by the delete path (api/students.php, DELETE)
--      while not being in the ENUM at all. MySQL did not raise an error - it
--      silently coerced the value to '' - so "deactivating" a student left
--      them with an empty status: still in every list, matching no status
--      filter, and with a bogus entry in status_tracker.
--
--  Narrowing an ENUM does not convert the data in it. Without the UPDATE
--  below, every legacy row would be truncated to '' by the ALTER, which is
--  exactly the corruption the delete path already caused.
--
--  The mapping, and why
--  -------------------
--    enrolled    ? enrolled     unchanged
--    active      ? active       unchanged
--    graduated   ? graduate     renamed, so the stored value names a state
--                                rather than a past-tense verb, and matches
--                                the label the office uses.
--    transferred ? alumni       the closest honest home. A transferred
--                                student left the school, but the school did
--                                not award them a qualification. `dropped`
--                                would assert a withdrawal they did not
--                                choose. Former-student is the fact actually
--                                recorded.
--    dropped     ? dropped      unchanged
--    probation   ? active       a passing grade held back. There is no longer
--                                a probation state, and `active` is the
--                                honest reading of a student still here.
--    at-risk     ? active       an AI advisory flag, not an enrolment state.
--                                It now lives in the data-quality signal
--                                (shared/student_quality.php), which is where
--                                an advisory belongs.
--    loa         ? active       a student on leave is still enrolled and
--                                still active. The window itself is retained
--                                in status_tracker.effective_date/end_date,
--                                so no history is lost.
--    '' / NULL   ? enrolled     rows corrupted by the `archived` bug above,
--                                plus any pre-Phase-5 row. `enrolled` is the
--                                column default and the least destructive
--                                assumption: it puts the record back in front
--                                of a clerk instead of hiding it.
--
--  Verify before committing: the final SELECT must return no rows. If it does,
--  there is a status this file does not know about and the ALTER is not safe.
-- -------------------------------------------------------------------------

SELECT '-- current students.status distribution --' AS '';
SELECT status, COUNT(*) AS n
  FROM students
 GROUP BY status
 ORDER BY n DESC;

-- 1. Move the data first. The WHERE lists are exhaustive on purpose: an
--    unlisted status is left alone rather than guessed at, so the verification
--    SELECT below can catch it.
UPDATE students SET status = 'graduate' WHERE status = 'graduated';
UPDATE students SET status = 'alumni'   WHERE status = 'transferred';
UPDATE students SET status = 'active'   WHERE status IN ('probation', 'at-risk', 'loa');
UPDATE students SET status = 'enrolled' WHERE status IS NULL OR status = '';

-- 2. Same for the audit trail. status_tracker is a journal: the values are
--    translated so the Status Tracker timeline keeps rendering, but the history
--    is not rewritten. Rewriting the past is not what a journal is for.
UPDATE status_tracker SET previous_status = 'graduate' WHERE previous_status = 'graduated';
UPDATE status_tracker SET previous_status = 'alumni'   WHERE previous_status = 'transferred';
UPDATE status_tracker SET previous_status = 'active'   WHERE previous_status IN ('probation', 'at-risk', 'loa');
UPDATE status_tracker SET current_status  = 'graduate' WHERE current_status  = 'graduated';
UPDATE status_tracker SET current_status  = 'alumni'   WHERE current_status  = 'transferred';
UPDATE status_tracker SET current_status  = 'active'   WHERE current_status IN ('probation', 'at-risk', 'loa');

-- 3. Now narrow the column. Safe only because step 1 emptied it of the old
--    values; MySQL would map any leftover to '' rather than failing loudly.
ALTER TABLE students
  MODIFY COLUMN `status` enum('enrolled','active','graduate','alumni','dropped')
  NOT NULL DEFAULT 'enrolled';

-- 4. Drop the cached masterlist, which holds a status distribution that is now
--    stale. See api/masterlist.php.
DELETE FROM masterlist_cache;

-- 5. VERIFY: must return zero rows. If it does not, stop - do not ship.
SELECT status, COUNT(*) AS still_illegal
  FROM students
 WHERE status IS NULL
    OR status NOT IN ('enrolled','active','graduate','alumni','dropped')
 GROUP BY status;
