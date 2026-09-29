---
tags: [subsystem]
---

# ðŸ”„ Status Tracker

Subsystem 8 (Phase 1) â€” journal of student status changes.

## What it does

- Records every status transition: `previous_status â†’ current_status` with a reason and who changed it
- Statuses: the five from `studentStatuses()` in [[functions.php]] - `enrolled`, `active`, `graduate`, `alumni`, `dropped`. The page reads that list rather than declaring its own, so it cannot offer a status the column would reject.
- Phase 1 added `effective_date` and `end_date` for LOA / transfer windows. No current status is time-boxed, so the form does not offer those dates (`WINDOWED` is empty)
- Phase 5 adds **`enrolled`** to the enum ([[security_upgrade.sql]]); the portal shows the 5 canonical labels via `getStudentStatusLabel()`

## Tables

- [[status_tracker]] â€” one row per change (FK `student_id`, `changed_by` â†’ [[users]])

## Pages

- `registrar/status-tracker.php` â€” AI command console with Command Bar, collapsible output panel, status distribution, student table + timeline
- `css/status-tracker.css` â€” extracted styles (193 lines)

## Related

- [[Subsystems MOC]] Â· [[Student Management]] Â· [[status_tracker]]
