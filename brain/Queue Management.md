---
tags: [subsystem]
---

# 🎫 Queue Management

Daily queue numbers for the registrar counter — kiosk join, live monitor, serving console, and student self-cancel. Backed by [[queue_tickets]].

## The four windows

The queue is **not** one line. Four windows, each owning one lane:

| Window | Lane | Serves |
|---|---|---|
| 1 | Service · Priority | PWD, senior citizen, pregnant, parent |
| 2 | Service · Student | everyone else |
| 3 | Claim · Priority | same four categories, collecting a document |
| 4 | Claim · Student | everyone else |

A ticket's lane is `queue_tickets.txn_type` + `priority_group`. The window
map lives in `shared/queue_helpers.php::queueWindows()` and is read by **both**
the kiosk (to label the choice) and the console (to filter what it calls), so
the two cannot drift apart.

`call_next` and the skip auto-advance both filter on the window's own lane.
Before this, `call_next` took the globally-oldest waiting ticket, so Window 2
could call a priority student who belonged at Window 1 — the split existed on
paper and did nothing.

## How it works

1. **Kiosk join** — the student taps their card, then answers two questions in
   order: *what do you need* (Service / Claim) and *are you a priority client*
   (Student / Priority, labelled PWD · Senior Citizen · Pregnant · Parent).
   `api/queue-public.php?action=join` validates the lane, the opening hours,
   the card (2 s anti-bounce, active), the live-ticket guard, and the daily tap
   cap, then appends from the back.
2. **Live monitor / board** — `api/queue-public.php?action=board` feeds four
   labelled window slots plus per-lane waiting lists and the open/closed state.
3. **Serving console** — `registrar/queue.php` (registrar/admin) picks a window,
   then calls next / completes / skips within that window's lane.
4. **Student view** — `student/queue.php` + `api/student-queue.php` show my
   ticket, position within my lane, and now-serving; a waiting student can
   **self-cancel** (`action=cancel`, Phase 5).

## Opening hours, cut-off, and tap caps

`queue_day_settings` — one row per `queue_date`, created lazily. Defaults are
**08:00–17:00** with **no** tap cap (`0` = unlimited), so behaviour is unchanged
until a registrar saves something.

Three closed states, and the kiosk says a *different* thing for each:

| State | Kiosk says |
|---|---|
| before open | "The queue opens at 8:00 AM." |
| after close | "The queue closed at 5:00 PM." |
| cut off (`forced`) | "The queue was closed at 4:30 PM. Numbers already issued are still being served." |

- **CUT OFF** (`set_cutoff`) closes it immediately and stamps who did it.
- **REOPEN** (`clear_cutoff`) returns the day to the **clock rule** only — it
  deliberately does not extend past `closes_time`.
- Saving hours never clears a forced cut-off; that is a separate action.
- A closed queue refuses joins **before** the card lookup, so a student is told
  about the hours rather than about their card.
- Numbers issued before the cut-off are still served normally. Only *new*
  numbers are refused.

The kiosk polls `board` every 15 s, so the closed sign appears on its own when a
registrar cuts the line — nobody has to tap to find out.

## Statuses

`waiting → serving → completed | no-show | removed` · `waiting → cancelled` (Phase 5 self-cancel)

## API endpoints

- [[api/queue-public.php]] — public join/board/my_ticket (no login)
- `api/queue.php` — console state (`&window=`, `&date=`) + actions, incl. `save_day_settings`, `set_cutoff`, `clear_cutoff`
- [[api/student-queue.php]] — portal view + self-cancel (student-gated)

## Timezone note

`queue_date` and all `*_at` columns are written from PHP (`date('Y-m-d H:i:s')`) and compared against PHP-written strings — never MySQL `NOW()` (see [[mysql-timezone-skew]]). The same rule governs the open/close comparison.

## Tests

`tests/QueueWindowingTest.php` — pins the window count, the lane map, the
lane-scoped `call_next`/skip, the closed-state reasons, the tap cap's position
inside the join transaction, and the history date filter. It reads source text
deliberately, so **bumping `QUEUE_MAX_WINDOWS` requires editing the test.**

Related: [[Home]] · [[queue_tickets]] · [[RFID Access]] · [[Student Portal]]