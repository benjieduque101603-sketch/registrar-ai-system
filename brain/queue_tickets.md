---
tags: [table]
---

# 🗄️ `queue_tickets`

One row per queue number issued per day. The backbone of [[Queue Management]].

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `queue_date` | date NOT NULL | ticket's day (MySQL UTC-safe: written via PHP `date('Y-m-d')`) |
| `ticket_number` | int unsigned | sequential per day |
| `student_id` | int NULL | FK → [[students]].id `ON DELETE SET NULL` (walk-ins allowed) |
| `student_name` | varchar(191) NOT NULL | denormalized for the monitor/console |
| `student_number` | varchar(50) NULL | |
| `course` | varchar(100) NULL | |
| `status` | enum `waiting/serving/completed/no-show/removed/cancelled` | `cancelled` added Phase 5 |
| `counter` | int unsigned | default 1 — the window serving a `serving` ticket |
| `txn_type` | enum `service/claim` | default `service` — added with the four-window split |
| `priority_group` | enum `student/priority` | default `student` — same migration |
| `card_uid` | varchar(50) NULL | RFID/QR card that joined |
| `joined_at` | datetime | time joined |
| `called_at` | datetime NULL | when serving started |
| `served_at` | datetime NULL | completion / cancellation time |

`txn_type` + `priority_group` are the **lane**. The pair identifies exactly one
of the four windows (see [[Queue Management]]); `counter` is only written once
a ticket is called, so a waiting ticket has `counter = 0` and its lane is read
from these two columns instead.

Both are `NOT NULL DEFAULT`ed to the old single-lane behaviour
(`service` / `student`), so every row that predates the split is already valid
and needs no backfill.

## Status flow

`waiting → serving → completed | no-show | removed` · `waiting → cancelled` (student self-cancel, Phase 5)

`queue_date`/`joined_at`/`called_at`/`served_at` are **written from PHP** (`date('Y-m-d H:i:s')`) — never compared against MySQL `NOW()` (see [[mysql-timezone-skew]]).

## Related

- [[Database MOC]] · [[Queue Management]] · [[students]] · [[Student Portal]]