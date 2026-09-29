---
tags: [table, core]
---

# ðŸ—„ï¸ `students`

Master student record. The central table every other student sub-record joins to.

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `student_number` | varchar(20) **UNIQUE** | e.g. `2026-0001` |
| `first_name` / `middle_name` / `last_name` | varchar | |
| `gender` | enum `Male/Female` | |
| `civil_status` | enum `Single/Married/Widowed/Separated` | |
| `birth_date` | date (NOT NULL) | |
| `place_of_birth` | varchar(100) | |
| `nationality` / `religion` | varchar | |
| `address` | text (NOT NULL) | |
| `contact_number` | varchar(15) | |
| `email` | varchar(100) | |
| `photo` | varchar(255) | path |
| `course` | varchar(100) | e.g. `BS Computer Science` |
| `major` | varchar(100) | |
| `year_level` | int | |
| `school_year` / `semester` | varchar | |
| `adviser_id` | int | â†’ users (not a FK constraint) |
| `section` | varchar(20) | |
| `status` | enum `enrolled/active/graduate/alumni/dropped` | default `enrolled` |
| `created_at` / `updated_at` | timestamp | auto |

### Phase 1 additions ([[registrar_upgrade.sql]])

`lrn` varchar(12), `name_suffix` varchar(10), `mother_name` varchar(100), `father_name` varchar(100), `birth_country` varchar(60) â€” DepEd/Form 137 fields.

### Five-value status model

Reduced to five values, defined once in `studentStatuses()` in [[functions.php]]: **Enrolled, Active, Graduate, Alumni, Dropped**. The pages, the CSS, the API allow-list, the insights pie and the schema dump all read that one list. See [[Glossary]] for the retired values and `migrations/student_status_five_values.sql` for the conversion.

## Indexes

`PK(id)`, `UNIQUE(student_number)`, `idx_student_number`, `idx_status`, `idx_course`.

## Children (FK â†’ `students.id`, all `ON DELETE CASCADE`)

[[guardians]] Â· [[emergency_contacts]] Â· [[academic_history]] Â· [[health_records]] Â· [[document_requests]] Â· [[documents]] Â· [[rfid_cards]] Â· [[student_ids]] Â· [[status_tracker]] Â· [[health_visits]]

## Seed data

3 demo students (Juan Dela Cruz, Maria Santos, Ana Reyes) â€” see [[registrar_ai.sql]].

## Related

- [[Student Management]] Â· [[status_tracker]] Â· [[Database MOC]] Â· [[registrar_ai.sql]]
