# Department Scope — Registrar Module Map

Reference for the Registrar's Office module. Determines which data the
Registrar owns versus which belongs to another department, and therefore
which document templates may populate real data versus print `N/A`.

## The Registrar — Group #292 · Student Information System (SIS)

| Capability | Status |
|---|---|
| Personal Info Database | ✅ In scope — `students` (except **section** — see below) |
| Guardian & Emergency Contact | ✅ In scope — `guardians`, `emergency_contacts` |
| Academic History (records) | 🔄 **Faculty-owned** — `academic_history`, `academic_grades`. See below. |
| Academic History (templates) | ✅ In scope — the printable grade record the Registrar issues |
| Health Record Log | ✅ In scope — `health_records`, `health_visits` |
| RFID/QR Code Integration | ✅ In scope — `rfid_cards`, `rfid_scan_logs` |
| Student ID Generation | ✅ In scope — `student_ids` |
| Document Requests | ✅ In scope — see template table below |
| Student Status Tracker | ✅ In scope — `status_tracker` |
| Digital File Storage | ✅ In scope — `documents` |
| Student Masterlist Generator | ✅ In scope — `registrar/masterlist.php` |

### Academic History — CHANGED 2026-10-02: Faculty owns the grades

**Faculty Management #296 now owns the grade record. The Registrar reads it.**

This supersedes the earlier "✅ In scope — `academic_history`". The tables
have not moved and nothing was dropped; what changed is which office may
**write** them.

| | Owner | Registrar's role |
|---|---|---|
| `academic_grades` (subjects, units, ratings, status) | Faculty #296 | Read-only |
| `academic_history` (term, semester, school year) | Faculty #296 | Read-only |
| Computed GWA | Registrar | **Computed here, from the recorded ratings** |
| Printable grade record / template | Registrar | Produced here |

Concretely, in this repository:

- `registrar/academic-history.php` has no grade editor. `addGradeRow()`,
  `removeGradeRow()`, `readGrid()`, `saveGrades()` and the Add-subject and
  Remove controls are gone, not disabled.
- `api/students.php?action=save-academic` and `…=delete-academic` now return
  **409** with the reason, rather than 404. A 404 would read as an unknown
  action; a 409 says the endpoint exists, the boundary moved, and where the
  data comes from now.
- `js/bcp-letterhead.js` is the shared letterhead used by both the AI Insight
  report and the printable grade record.

**Why computed GWA stays ours.** Once Faculty owns the grades, a number typed
in two places is a number that can disagree. `academic_history` therefore
carries `gwa_reported` (Faculty's) and `gwa_computed` (ours, from
`shared/term_grades.php`) **side by side**, and the printable record prints the
computed figure. A disagreement is *printed*, never silently resolved — a
document that quietly picks one of two conflicting numbers is worse than one
that admits there are two.

**Not yet decided:** where the Faculty data actually comes from. The local
read path is source-agnostic (`source_system` / `source_ref` / `faculty_id` /
`received_at`, see `migrations/grades_faculty_source.sql`) so that an API sync
or a file import can be attached later without changing the pages above.

### Note on "Section" — RESOLVED: the Masterlist assigns sections

**Settled.** The office **may** auto-assign sections, and the Masterlist is
where that happens. This supersedes the earlier note below, which parked the
question as an open scheduling decision.

The reasoning: Masterlist generation is Registrar-owned, and a masterlist is
the artefact a block is *cut from*. Handing a department a list of students
with no blocks on it asks that department to do the registrar's grouping.
The Registrar still does not decide the block structure — the code encodes
only year level and term, both of which the Registrar already owns — and it
does not assign **advisers**, which remain Faculty Management's.

So the split is by surface, not by field:

| Surface | Treatment |
|---|---|
| **Masterlist** | ✅ **In scope.** Auto-assign, Create Section, a Section column, a section filter, section chips on each block heading, and Section on the printed sheet and both exports. |
| Add Student modal | No section field. A dashed row reads *"Section — assigned from the Masterlist"* and prints `N/A`. |
| Student list | No section column, no section filter. |
| Quality score | `section` stays out of the weights; its 5 points went to `course`. |

The **Students roster is deliberately untouched.** Assigning a block is a
Masterlist operation, and adding a per-student section field to a roster form
is a different thing: it would invite a clerk to set a code one student at a
time, which is the exact manual work auto-assign exists to remove.

The field is still **shown as `N/A`, never hidden**, on the surfaces that do
not own it. The print convention below already requires that — "No section is
hidden and no 'no data' message is shown, so a registrar can distinguish a
genuinely empty record from a rendering failure" — and a modal that silently
dropped a column would read as data loss rather than as a boundary.

**How the code works.** `[year][sem][###]`, five digits: `11001` is year 1,
1st semester, section 1. Summer is the third semester digit, so `11003` is
year 1, summer, section 3. Built by `shared/section_code.php`, which is
free of database dependencies and unit tested in isolation.

**Two scoping rules that are easy to get wrong**, both enforced in
`api/masterlist.php`:

- A code is scoped by **course + year level + term**, *not* by school year.
  The code carries no S.Y. digit, so one program+year+term is one section
  space however many intakes sit in it. Keying on S.Y. would number the same
  space twice and hand one code to two different sets of students.
- A student with **no year level cannot hold a section**, because the code is
  derived from the year level. Auto-assign skips them and reports the count;
  a manual assign names them and refuses. Defaulting a missing year to 1
  would stamp a Year-1 code onto a student who has no year level at all.

Auto-assign is **idempotent by design**: it fills the gaps in existing
sections first and only opens a new code once every existing one is at the
cap, so re-running it does not renumber anybody. Verified by
`tests/section_e2e.php`.

### Note on "Document Requests (Form 137, Good Moral)"

Good Moral is Registrar-issued and correct. **Form 137 is not.**

Per DepEd Order No. 54, s. 2016 and DepEd Memorandum No. 42, s. 2017,
Form 137 (Permanent Record of the Learner, now SF10 per DepEd Order No.
32, s. 2022) may be issued **only by the school head of the last
DepEd-accredited school attended** — Grades 1 to 12. A college registrar
has no authority to issue it, and a college-issued Form 137 is invalid
for official purposes.

Form 138 is the **Report Card** (academic performance per grading
period), not a certificate of good moral. Both forms are DepEd
basic-education documents; neither is a college document.

Colleges issue the **Transcript of Records** where basic education
issues the Form 137. A graduating student typically needs both: college
TOR for the degree, plus Form 137 from their Grade 12 school.
`DOC-137` is therefore deliberately absent from `document_catalog`.

A future "Request from Previous School" helper would let the registrar
request a student's Form 137 from their last school on their behalf.
That is a records-request feature, not a document-issuance one, and is
out of scope for this build.

## Document templates — registrar ownership

| SKU | Document | Body | Rationale |
|---|---|---|---|
| `DOC-TOR` | Transcript of Records | ✅ **Build** | Registrar issues the TOR. Grade *data* is Curriculum #293's to populate; prints `N/A` until it does. |
| `DOC-COE` | Certificate of Enrollment | ▫ N/A body | Enrollment Management #291 owns enrollment records. |
| `DOC-GM` | Certificate of Good Moral | ✅ **Build** | Explicitly Registrar #292. |
| `DOC-DIPLOMA` | Diploma Replacement | ▫ N/A body | Not assigned to any group. |
| `DOC-CTC` | Certified True Copy | ✅ **Build** | Certifies a file in Registrar's Digital File Storage. |
| `DOC-HD` | Honorable Dismissal | ▫ N/A body | Not assigned to any group. |
| `DOC-CD` | Course Description | ▫ N/A body | Curriculum & Subject Management #293. |

An N/A body does **not** remove the SKU. The request still exists and
follows the full walk-in process — fee, counter, record copy, retention.
Only the department-owned *content* is deferred, and it prints as `N/A`.

The deferred documents carry **no on-document "produced by department X"
notice**. A Certificate of Enrollment that says the Office of the
Registrar does not produce it contradicts the signature block directly
beneath it, and an internal system boundary means nothing to a student
or employer holding the certificate. Ownership is recorded here, in
`DEPARTMENTS.md`, rather than on the document.

## Other departments (context only — not Registrar)

| Department | Group | Registrar interaction |
|---|---|---|
| Enrollment Management System | #291 | Source of enrollment data for `DOC-COE` |
| Curriculum & Subject Management | #293 | Source of subject/grade data for `DOC-TOR`, `DOC-CD` |
| Accreditation Management | #294 | None |
| Payment Management | #295 | Counter payment replaces the online gateway |
| Faculty Management | #296 | None |
| Class Scheduling | #297 | Decides block **structure**; the Masterlist records the code it cuts from the list |
| Co-curricular & Club Management | #298 | None |
| Online Learning & LMS | #299 | None |
| CRAD | #300 | None |

## Not in any department

Diploma Replacement and Honorable Dismissal appear in no group. Their
templates print N/A pending assignment.

## Field sources

| Field group | Source table |
|---|---|
| Identity, program, year, section | `students` |
| Terms, SY, GWA, credits | `academic_history` |
| Subjects, units, grades, instructor, room | `academic_grades` |
| Request no., purpose, walk-in, release | `document_requests` |
| Doc name, fee, SKU | `document_catalog` |
| Status timeline | `document_request_events` |
| Stored files (CTC source) | `documents` |
| Outstanding balance | `finance` |
| Registrar name | `users` |
| Counter, clerk, receipt | `document_requests` |

## Schema gaps closed by `migrations/document_walkin_only.sql`

| Gap | Affects | Fix |
|---|---|---|
| No `discipline_records` table | `DOC-GM` | New table |
| No `students.graduation_date` | `DOC-DIPLOMA`, `DOC-HD` | New column |
| No `documents.file_sha256` | `DOC-CTC` | New column |

Still open: no syllabus-text column, so `DOC-CD`'s description is
permanently N/A (owned by Curriculum #293 anyway).

## Masterlist Folders — views inside the Masterlist

The masterlist reads three ways:

> **There is no view toggle in the header any more.** All three buttons
> (`Browse` / `Table` / `List`) were removed, along with the
> `.masterlist-views` / `.mlv-btn` CSS that styled them. The views
> themselves were **not** removed — `?view=folders` and `?view=list`
> still render in full, with their ZIP downloads, bulk select and
> CSV/Excel export, reachable only by URL. Browse is the default and
> what the page now simply *is*.
>
> What was given up: the header no longer says which view you are in.
> That is visible from the page itself instead — folder tiles, or a
> ledger. Read the rest of this section as describing what each view
> *is*, not what the header offers.

- **List** — the flat blocks that get printed, signed and handed off.
- **Table** (`?view=folders`) — **one table** in which a folder is a row you
  expand: a program row, its year rows inside it, its section rows inside
  those, and the student rows inside a section. A caret opens and closes what
  is under a folder; `Expand all` / `Collapse all` are there for when
  someone wants the whole tree.

  Its columns lead with **Program · Section · Year Level · Semester**,
  because those four are what say *which cohort* a row is. The other
  columns (name, type, counts, status, contact, email, download) follow.

  Three of those four are **derived from the row's folder path**
  (`PROGRAM/Year 1/11001`) rather than stored again — a second copy of
  the same fact is a second thing to fall out of step. **Semester is the
  exception**: a section code encodes year and term, but the tree does
  not keep the term, so it comes from the student data on a student row
  and is a dash on a folder row that has no students to ask.

  A program or year row leaves Section and Semester blank rather than
  repeating its own name — it *contains* sections, it is not one.

  The header and the cells are written in two separate blocks, which is
  a trap worth naming: adding a column to one and not the other raises
  no error and no warning. It silently shifts every cell after the gap
  one place left, and a registrar reads a status as a phone number.
  `tests/explore_render_check.php` therefore asserts **one cell per
  column on every row**, not just that the columns exist.
- **Browse** (`?view=explore`) — a **folder browser**, the shape a shared
  drive has. It is not the table with folders removed; it stands at exactly
  one folder at a time. Click **BSIT** and you go *into* BSIT and see its
  year folders; click a year and you see its sections; click a section and
  you get the roster that folder holds, as a table of every detail
  (`mlf_roster_columns()` — the same columns the ZIP export writes).
  A breadcrumb bar and `Up one level` move back out.

### Programs print as acronyms, everywhere they are listed

`BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)` is **55
characters**. Wherever programs are *listed*, they print as `BSIT`:
the browser's program tiles, the breadcrumb, the program heading, the
section sub-line, the folder table's program rows, the Masterlist
filter dropdown, and the Edit Section course dropdown. The status
tracker's program listbox already worked this way; this makes the
Masterlist agree with it.

The rule is enforced in one place, `courseDisplay()` /
`courseDisplayTitle()` (`shared/functions.php`), not by truncating at
each call site. Two things it will not do:

1. **It is display-only.** The `course` column, the folder paths, the
   ZIP structure and every `<option value>` keep the **full** name. A
   dropdown whose *value* was abbreviated would match no row and filter
   silently to nothing — the label is free to be short, the value is
   not. `tests/explore_render_check.php` asserts exactly this: the tile
   reads `BSIT` while its `href` still carries the whole string.
2. **It never abbreviates the work-queue folders.** `Unassigned
   Program` is not a program, and `courseAcronym()` would reduce it to
   `UP` — which is a real degree abbreviation and would be a lie on the
   page. Years and sections are left alone too: "Year 1" has no shorter
   true form, and a section code *is* its own name.

The full name is never discarded. Wherever a name is shortened, it
becomes the `title` (hover) and the `aria-label` (screen readers), so
the acronym is a compression rather than a replacement. That tooltip
is now the **only** way back to the full name: a legend table decoding
every acronym was built and then removed, on the grounds that the
acronyms are the office's own vocabulary and the browser already
answers "what is this folder" from the tiles themselves.
`tests/explore_render_check.php` pins the tooltip down, so the
abbreviations can never become undecodable by accident.

The one surface that needed its own handling is the **section roster**
(`mlx-table`), whose fourteen cells are written in one generic loop
over `mlf_roster_row()`. The Program cell is special-cased by locating
its index with `array_search('Program', mlf_roster_columns())` rather
than counting to it, so inserting a column later cannot silently shift
the abbreviation onto the wrong field.

That loop is also what the ZIP export is built from — but the export
calls `mlf_roster_row()` in `shared/`, not this markup, so the export
still writes the **full** program name. A handoff must carry what was
filed; only the screen is abbreviated.

Why both folder views exist: the Table answers "show me everything, I will
find BSIT in the middle of it", and it does not scale — a college with eight
programs and four years each is hundreds of rows nobody will scroll. The
Browse answers "show me BSIT", which is the question the office actually
asks. Neither replaces the other.

Both read the **same** tree (`mlf_build_tree` on one query), so they cannot
disagree about who is filed where. Only the presentation differs: the table
flattens the tree with `mlf_rows()`, the browser stands inside it with
`mlf_resolve()`.

There is **no separate page** and **no second sidebar entry**. They are
views of the Masterlist module, not modules beside it.

`?view=folders` and `?view=explore&path=...` are real URLs, so a view
survives Back and can be linked to: `?open=BSIT/Year 1/11001` lands on the
table with that section expanded, and `?view=explore&path=BSIT/Year 1/11001`
lands inside that folder. Navigation in the browser is plain links, not JS.
A stale path says so ("That folder is not here") and shows the root rather
than a blank screen.

Checked by `tests/explore_render_check.php`, which fetches the page over
real HTTP at every level of the tree and reads the rendered HTML — the
breadcrumbs, the tile links, and every roster column.

Three rules, recorded here because they are the ones a later change
could quietly undo:

1. **A folder is a projection, never a copy.** The rows are derived
   from `students` on every load (`shared/masterlist_folders.php`);
   nothing stores a path. Assigning a student to a section is still
   the Masterlist page's job, and the tree rebuilds itself.
2. **The views list DIFFERENT rows, on purpose.** The List view is
   the signable roster and drops students with no section. Both folder
   views are inventories and keep them, under `Unassigned Section`. An
   unplaced student who vanished from the table would be precisely the
   work the folders exist to show.
3. **Folder names are filesystem-safe.** Program names are free text,
   and a `:` or `/` in one makes the whole archive un-extractable on
   the receiving machine.
4. **The three views are mutually exclusive, and each one gates on
   `$view`.** This one was broken and is worth stating plainly. The
   printable List sat at the `else` of `if ($view === 'folders')`, but
   the browser is an **independent `if` above it**, not the other arm of
   that chain. So `?view=explore` matched neither and fell straight into
   the `else`: every folder page rendered the browser *and* the entire
   printable ledger underneath it — four `.masterlist-table` blocks
   below the roster the registrar actually asked for. Nothing looked
   broken; the page was simply twice as long, with a second complete
   rendering of the same students under the first.

   The lesson is the shape, not the typo. An `else` only names "the
   other two views" while there are two. The moment a third view arrives
   as its own branch, the `else` silently becomes "every view nobody
   claimed" — which is exactly what a new branch looks like. So each
   view now tests `$view` explicitly and nothing falls through by
   default. `tests/explore_render_check.php` asserts the ledger class is
   **absent** from a browser page, not merely that the roster is present:
   a presence check passes just as happily with two rosters on the page
   as with one.

Downloading any folder gives that folder's **whole subtree** as a `.zip`
that unpacks to the same structure (`api/masterlist-folders.php`), so a
department's cohort list is the same operation as filing it on a shared
drive.

## Test residue — not the same thing as seed data

`migrations/seed_receive_students.sql` is the only seed in the repo that
writes `students`, and it is **not applied** on this host: its markers
(`address LIKE 'SEEDDATA-%'` + `email LIKE '%@seed.receive.test'`) match
zero rows. So a green marker check there proves nothing about whether the
database is clean.

What *was* dirty was e2e residue: the scripts under `tests/` create real
students, users, guardians and document requests, then delete them again
in a `cleanup()` call. When a run dies before cleanup, those rows survive.
This database had **57 students, 25 users and 49 child rows** of that
kind, which is why the Masterlist had content nobody had entered.

Removed with:

```
php tests/clear_e2e_residue.php             # dry run, deletes nothing
php tests/clear_e2e_residue.php --execute   # after reading the dry run
```

Two things that script has to get right, both of which bit during the
first run:

1. **The child tables are discovered from `information_schema`, not
   listed.** A hardcoded list missed `audit_logs.user_id` and the DELETE
   was refused by the FK. Reading the schema means a table added later is
   cleaned up without editing the script.
2. **There are two markers, because one account has no email at all.**
   `tests/document_pickup_email.php` inserts a desk account with
   `username = 'pickup_staff_<hash>'` and a null email, so an
   email-only filter leaves it behind permanently. It survived the first
   pass and was still in the table afterwards.

A backup of everything removed is written to `backups/` (gitignored)
before the transaction commits. It was verified by replaying the dump
through the `mysql` client into scratch copies of the tables: all 57
students, 24 users, 12 guardians, 7 document requests and 29 audit rows
restored cleanly.

Note that `users.student_id` is `ON DELETE SET NULL`, so deleting a
student does **not** remove the portal login that pointed at it. The
script NULLs the link explicitly before removing the user, because a
login with no student row is broken but still able to authenticate.

## Removed tables

`exit_clearances` — the three-office (Alumni / Dean / Property) sign-off for
Honorable Dismissal and a final Transcript of Records. Removed 2026-09-27
because it never worked: the flag enabling it was read by no code path, intake
had stopped creating the rows, and a backfill's six seeded rows were never
signed by an office. `document_catalog.triggers_exit_clearance` went with it.
See `SYSTEM_FLOW.md` §5 and
`backups/pre_exit_clearance_removal_20260927.sql`.

`clearances` — zero code references, and a **different table** from the
now-also-removed `exit_clearances`. Dropped by an earlier migration. Backed up
first.

## Print convention

Every template renders its full structure. Any field with no data prints
`N/A`. No section is hidden and no "no data" message is shown, so a
registrar can distinguish a genuinely empty record from a rendering
failure.

`N/A` applies to **academic and source-document data only**. Request
provenance — request number, walk-in timestamp, counter, releasing
officer, ticket numbers, signature and seal — is recorded at intake and
is always present. A missing value there is a data defect and is logged,
never formatted as `N/A`.

Documents do not print HTML entities as visible text. Generated text
carries a plain `&` and is escaped once at render; a pre-escaped value
passed into an escaping helper would print a literal `&amp;` to the
reader.

## Related

- `brain/Document Requests.md` · `brain/Queue Management.md`
- `brain/document_requests.md` · `brain/queue_tickets.md`
