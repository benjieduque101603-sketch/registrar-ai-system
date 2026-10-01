---
tags: [subsystem]
---

# 📊 Masterlist Generation

Subsystem 10 (Phase 1) — auto-generated, cached student masterlists.

## What it does

- Generates program/year/term masterlists, split into lists of at most
  `MAX_STUDENTS_PER_SECTION` (default 50). A block past the cap starts a new
  list rather than overflowing one long table.
- **Assigns section codes.** `11001` = year 1, 1st semester, section 1;
  `11003` = year 1, summer, section 3. A code is scoped by course + year level
  + **term**, not by school year — the code has no S.Y. digit, so one
  program+year+term is one section space however many intakes sit in it.
- Auto-assign fills gaps in existing sections first and only opens a new code
  once every existing one is at the cap, so re-running it renumbers nobody.
- A student with **no year level cannot hold a section**: the code is derived
  from the year level. Auto-assign skips them and reports the count; a manual
  assign names them and refuses.
- Results cached per-user in [[masterlist_cache]] keyed by `query_hash` to cut DB load

## Tables

- `students.section` — the code itself, `varchar(20)`. There is no `sections`
  table: a section exists only as a code shared by the students holding it,
  which is why "creating" a section means computing a code and assigning
  students to it.
- [[masterlist_cache]] — cached result data (JSON) with expiry

## Pages & endpoints

- `api/masterlist.php` — roster (GET) plus the section writes: `assign_sections`
  (auto-assign), `next_section`, `list_sections`, `bulk_assign_section`,
  `edit_section`. Admin/registrar only; CSRF is enforced on include.
- `registrar/masterlist.php` — masterlist UI, including the Section tools group
  (Auto-assign, Create Section) and the three section modals
- `shared/section_code.php` — pure code derivation, no DB, unit tested on its own
- `shared/functions.php` — `autoAssignStudentSections()`, `nextSectionNumber()`,
  `sectionExists()`
- `tests/SectionCodeTest.php` — the code format
- `tests/section_e2e.php` — the whole feature over real HTTP (needs Apache +
  MySQL and the 150-student seed)

## Related

- [[Subsystems MOC]] · [[Student Management]] · [[masterlist_cache]] · [[config.php]]
- Ownership: see `DEPARTMENTS.md` — the Masterlist assigns sections; the
  Students roster does not, and advisers remain another department's.
