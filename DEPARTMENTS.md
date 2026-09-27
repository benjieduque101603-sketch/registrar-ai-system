# Department Scope — Registrar Module Map

Reference for the Registrar's Office module. Determines which data the
Registrar owns versus which belongs to another department, and therefore
which document templates may populate real data versus print `N/A`.

## The Registrar — Group #292 · Student Information System (SIS)

| Capability | Status |
|---|---|
| Personal Info Database | ✅ In scope — `students` |
| Guardian & Emergency Contact | ✅ In scope — `guardians`, `emergency_contacts` |
| Academic History | ✅ In scope — `academic_history` |
| Health Record Log | ✅ In scope — `health_records`, `health_visits` |
| RFID/QR Code Integration | ✅ In scope — `rfid_cards`, `rfid_scan_logs` |
| Student ID Generation | ✅ In scope — `student_ids` |
| Document Requests | ✅ In scope — see template table below |
| Student Status Tracker | ✅ In scope — `status_tracker` |
| Digital File Storage | ✅ In scope — `documents` |
| Student Masterlist Generator | ✅ In scope — `registrar/masterlist.php` |

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
| Class Scheduling | #297 | None |
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
