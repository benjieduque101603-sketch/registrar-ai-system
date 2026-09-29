---
tags: [reference]
---

# 🗂️ Glossary

Domain terminology used across the system.

## Student statuses (`students.status`)

Five values, defined once in `studentStatuses()` (shared/functions.php). Every
page, the CSS, the API allow-list, the insights pie and the schema dump read
that one list — there is no second copy to drift.

| Status | Meaning | Portal label |
|---|---|---|
| `enrolled` | taken on, not yet confirmed as attending *(default)* | **Enrolled** |
| `active` | attending | **Active** |
| `graduate` | completed the programme, diploma awarded | **Graduate** |
| `alumni` | graduate who has left / former student | **Alumni** |
| `dropped` | withdrawn before completing | **Dropped** |

`graduate` and `alumni` are deliberately separate: the insights pie needs to
tell "just finished" from "left years ago".

**Retired:** `probation`, `at-risk`, `loa`, `transferred`, `graduated`,
`archived`. The first five were advisory or event states rather than enrolment
states — `at-risk` in particular is now a *data-quality signal*
(shared/student_quality.php), not a column value. `archived` was never a valid
value at all: the delete path wrote it, MySQL truncated it to `''`, and the
student was left with an empty status. Archival needs a `deleted_at` column,
which this schema does not have.

Apply `migrations/student_status_five_values.sql` before deploying code that
writes the new values — it converts the data first, then narrows the ENUM, then
verifies. The order matters: narrowing an ENUM does not convert what is inside
it, so skipping the UPDATEs would truncate every legacy row to `''`.

## Document types (`document_requests.document_type`)

`form137` · `good_moral` · `transcript` · `certificate` · `clearance`

## Request statuses

`pending → processing → approved / denied → completed → released`

## File doc types (`documents.doc_type`)

`enrollment` · `transcript` · `health` · `photo` · `clearance` · `other`

Note this is a *different* vocabulary from document_requests above — only
`transcript` and `clearance` appear in both. Labelled by
`storedDocTypeLabel()` in shared/stored_file.php, deliberately named apart from
the request-side `documentTypeLabel()` so the two cannot be swapped.

## Other status columns (not student statuses)

- `rfid_cards.status` — `available` · `active` · `expired` · `lost` · `archived` · `inactive`
- `document_requests.status` — `pending` · `processing` · `approved` · `denied` · `completed` · `released`


## Roles

- **`users.role`:** `admin` · `registrar` · `staff` · `student` *(Phase 5)*
- **`authorized_cards.role`:** `admin` · `registrar` · `superadmin`

## Queue statuses (`queue_tickets.status`)

`waiting` · `serving` · `completed` · `no-show` · `removed` · `cancelled` *(cancelled = Phase 5 student self-cancel)*

## OTP (`otp_codes.purpose`)

`login` · `reset` — 6-digit, 5-min TTL, single-use; delivered via email or on-screen dev fallback.

## RFID

- **Card status:** `active` · `inactive` · `lost` · `expired`
- **Event types:** `entry` · `exit` · `library` · `cafeteria` · `other`
- **Scan status:** `success` · `denied` · `unknown`

## Relationships (guardians)

`father` · `mother` · `guardian` · `spouse` · `sibling`

## ID types (`student_ids.id_type`)

`school_id` · `library` · `cafeteria`

## Section code format

`[year][semester][number]` — e.g. `11001` = year 1, sem 1, section 1. Max `MAX_STUDENTS_PER_SECTION` = 50.

## Acronyms

| Acronym | Meaning |
|---|---|
| BCP | Bestlink College of the Philippines |
| LRN | Learner Reference Number (DepEd) |
| GWA | General Weighted Average |
| LOA | Leave of Absence |
| Form 137 | Permanent school record (DepEd) |
| UID | card unique identifier |

Related: [[Home]] · [[Reference MOC]]
