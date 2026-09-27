# BCP Registrar System — Complete System Flow

> **Version:** 1.0.0 · **Last Updated:** Sep 24, 2026
> **Stack:** PHP 8.x · MariaDB 10.4 · Vanilla JS · PDO · PHPMailer · PayMongo · OpenAI-compatible AI Gateway

---

## Table of Contents

1. [System Architecture](#1-system-architecture)
2. [User Roles & Access Matrix](#2-user-roles--access-matrix)
3. [Authentication & Session Flow](#3-authentication--session-flow)
4. [Student Management Flow](#4-student-management-flow)
5. [Document Request Flow](#5-document-request-flow)
6. [RFID Card & Queue System](#6-rfid-card--queue-system)
7. [Health / Clinic Flow](#7-health--clinic-flow)
8. [AI-Assisted Features](#8-ai-assisted-features)
9. [Emergency & Contacts Flow](#9-emergency--contacts-flow)
10. [Notifications & Email Flow](#10-notifications--email-flow)
11. [Payment Flow (PayMongo)](#11-payment-flow-paymongo)
12. [Database Schema Map](#12-database-schema-map)
13. [File Structure Reference](#13-file-structure-reference)

---


## 1. System Architecture


### High-Level Architecture
```
+-----------------------------------------------------------------------------+
|                              BROWSER (Client)                               |
|                                                                             |
|  +----------+  +----------+  +----------+  +----------+  +--------------+  |
|  |  Login    |  | Dashboard|  | Student  |  | Registrar|  |   Nurse      |  |
|  |  Page     |  |          |  | Portal   |  | Pages    |  |   Portal     |  |
|  +----+-----+  +----+-----+  +----+-----+  +----+-----+  +------+-------+  |
|       |              |              |              |               |          |
|  +----+--------------+--------------+--------------+---------------+------+   |
|  |                    JavaScript Layer (auth.js, queue.js, etc.)          |   |
|  |              showToast()  ·  CSRF tokens  ·  Fetch API calls          |   |
|  +----------------------------------+------------------------------------+   |
+-------------------------------------+----------------------------------------+
                                      | HTTP/HTTPS (AJAX fetch)
                                      v
+-------------------------------------+----------------------------------------+
|                         APACHE / PHP 8.x (Server)                           |
|                                                                             |
|  +------------------------------------------------------------------------+ |
|  |                      Shared Layer (shared/)                            | |
|  |  config.php -> database.php -> session_config.php -> csrf_guard.php    | |
|  |  functions.php · normalize.php · auth_security.php · security_headers  | |
|  +------------------------------------------------------------------------+ |
|                                                                             |
|  +------------------+  +------------------+  +--------------------------+   |
|  |  auth_actions.php |  |   api/*.php      |  |   AI Client (ai_client) |   |
|  |  (Login/OTP/Reset)|  |   (REST endpoints|  |   PayMongo Client       |   |
|  |                   |  |    for all CRUD) |  |   Mail Client           |   |
|  +--------+---------+  +--------+---------+  +----------+---------------+   |
|           |                      |                        |                  |
|           v                      v                        v                  |
|  +------------------------------------------------------------------------+ |
|  |                       MariaDB 10.4  (registrar_ai)                     | |
|  |                    39 tables · utf8mb4 · InnoDB                        | |
|  +------------------------------------------------------------------------+ |
|           |                      |                        |                  |
|           v                      v                        v                  |
|  +------------------------------------------------------------------------+ |
|  |  External Services                                                     | |
|  |  +----------+ +----------+ +----------+ +----------+ +-------------+  | |
|  |  | PayMongo | | AI       | | Brevo/   | | Gmail    | | Lalamove    |  | |
|  |  | (GCash)  | | Gateway  | | SMTP     | | OAuth2   | | (Mock)      |  | |
|  |  +----------+ +----------+ +----------+ +----------+ +-------------+  | |
|  +------------------------------------------------------------------------+ |
+-----------------------------------------------------------------------------+
```

### Request Lifecycle

```
Browser Request
      |
      v
+-------------+    +------------------+    +-----------------+
| security_   |-->| session_config   |-->| csrf_guard      |
| headers.php |    | (start session,  |    | (validate token |
| (X-Frame,   |    |  idle timeout)   |    |  on POST/PUT)   |
|  HSTS, etc.)|    |                  |    |                 |
+-------------+    +------------------+    +--------+--------+
                                                     |
                    +--------------------------------+
                    v
          +------------------+
          |  Route / Action  |
          |  Dispatch        |
          +--------+---------+
                   |
        +----------+----------+--------------+
        v          v          v              v
   +---------+ +-------+ +--------+  +----------+
   |  Auth   | | CRUD  | |  AI    |  | Payment  |
   |  Logic  | | Logic | |  Call  |  | Webhook  |
   +----+----+ +---+---+ +---+----+  +----+-----+
        |          |         |             |
        v          v         v             v
   +-------------------------------------------+
   |          Database (PDO)                   |
   |  Prepared statements · Transactions       |
   +-------------------------------------------+
                    |
                    v
          +------------------+
          |  JSON Response   |
          |  + HTTP Status   |
          +------------------+
```


---

## 2. User Roles & Access Matrix

### Roles

| Role        | Description                            | Dashboard              |
|-------------|----------------------------------------|------------------------|
| `admin`     | Full system access, bypasses all role checks | `dashboard.php`   |
| `registrar` | Student mgmt, documents, RFID, queue, contacts | `dashboard.php` |
| `nurse`     | Health records, clinic visits, supplies | `nurse/dashboard.php`  |
| `student`   | Self-service portal (read-only for most) | `student/dashboard.php` |

### Page Access Matrix

```
+------------------------+-------+-----------+-------+---------+
| Feature                | admin | registrar | nurse | student |
+------------------------+-------+-----------+-------+---------+
| Dashboard              |   Y   |     Y     |   Y   |    Y    |
| Student CRUD           |   Y   |     Y     |   N   |    N    |
| Enrollment Pipeline    |   Y   |     Y     |   N   |    N    |
| Academic History       |   Y   |     Y     |   N   |    Y*   |
| Document Requests      |   Y   |     Y     |   N   |    Y*   |
| RFID Cards             |   Y   |     Y     |   N   |    N    |
| Queue Console          |   Y   |     Y     |   N   |    N    |
| Queue Kiosk            |   Y   |     Y     |   Y   |    Y    |
| Health/Clinic          |   Y   |     Y     |   Y   |    Y*   |
| Emergency Contacts     |   Y   |     Y     |   N   |    Y*   |
| Student IDs            |   Y   |     Y     |   N   |    Y*   |
| Audit Logs             |   Y   |     Y     |   N   |    N    |
| Users Management       |   Y   |     N     |   N   |    N    |
| AI Features            |   Y   |     Y     |   N   |    Y    |
| Payments (PayMongo)    |   Y   |     Y     |   N   |    Y*   |
+------------------------+-------+-----------+-------+---------+
  * = Read-only / self-service only
```

### Role Enforcement Mechanism

```
shared/session_config.php
  ├── requireLogin()          → checks $_SESSION['user_id']
  ├── requireRole($role)      → admin OR specified role
  ├── requireStudent()        → admin OR student only
  └── getCurrentUserRole()    → returns $_SESSION['role']

API endpoints enforce via:
  if (!in_array(getCurrentUserRole(), ['admin', 'registrar'], true)) {
      echo json_encode(['success' => false, 'message' => 'Forbidden.']);
      exit;
  }
```


---

## 3. Authentication & Session Flow

### Login Flow

```
+----------+         +--------------+        +--------------+        +----------+
|  User    |         | login.php    |        | auth_actions |        | MariaDB  |
| (Browser)|         | (Frontend)   |        | .php (API)   |        |          |
+----+-----+         +------+-------+        +------+-------+        +----+-----+
     |  1. Visit login.php  |                       |                     |
     |--------------------->|                       |                     |
     |  2. Render form      |                       |                     |
     |<---------------------|                       |                     |
     |  3. Enter credentials|                       |                     |
     |  + CSRF token        |                       |                     |
     |--------------------->|  POST action=login    |                     |
     |                      |  username + password  |                     |
     |                      |---------------------->|                     |
     |                      |                       |  4. Resolve user    |
     |                      |                       |  (by username,      |
     |                      |                       |   email, or         |
     |                      |                       |   student_number)   |
     |                      |                       |-------------------->|
     |                      |                       |  5. Return user row |
     |                      |                       |<--------------------|
     |                      |                       |  6. Verify password |
     |                      |                       |  password_verify()  |
     |                      |                       |  7. Check lockout   |
     |                      |                       |  (login_attempts    |
     |                      |                       |   + locked_until)   |
     |                      |                       |-------------------->|
     |                      |                       |  8. Create session  |
     |                      |                       |  (user_id, role,    |
     |                      |                       |   full_name)        |
     |                      |  9. JSON response     |                     |
     |                      |  { success, redirect} |                     |
     |                      |<----------------------|                     |
     |  10. JS redirects    |                       |                     |
     |  based on role       |                       |                     |
     |<---------------------|                       |                     |
```

### Login Decision Tree

```
Credential + Password submitted
      |
      v
+------------------+
| Empty fields?    |---YES-->> "Please enter your ID/username and password"
+--------+---------+
         | NO
         v
+------------------+
| User exists?     |---NO--->> "Invalid ID/username or password."
+--------+---------+          (generic — no account enumeration)
         | YES
         v
+------------------+
| Account active?  |---NO--->> "Your account is disabled."
+--------+---------+
         | YES
         v
+------------------+
| Password valid?  |---NO--->> Increment login_attempts
+--------+---------+          |
         | YES                v
         |           +------------------+
         |           | 5+ attempts?     |---YES-->> Lock account 10 min
         |           | in 10 min window |          "Too many attempts."
         |           +------------------+          |
         |                    | NO                  v
         |                    v            "Invalid ID/username or password."
         |
         v
+------------------+
| Create session   |
| Set redirects    |
|                  |
| admin/registrar  |--> /dashboard.php
| nurse            |--> /nurse/dashboard.php
| student          |--> /student/dashboard.php
+------------------+
```


### Forgot Password Flow

```
User clicks "Forgot Password?"
      |
      v
+-----------------+     +----------------+     +----------------+
| Enter email     |---->| Find user by   |     | Generate OTP   |
|                 |     | email in DB    |     | (6-digit,      |
|                 |     |                |     |  5-min expiry)  |
|                 |     | NOT found?     |     |                |
|                 |     | Generic reply: |     | Store hash in  |
|                 |     | "If registered |     | otp_codes table|
|                 |     |  code sent"    |     +-------+--------+
|                 |     +----------------+             |
|  <<-- "Code sent to j***@gmail.com" ----------------+
|                                                       |
| Enter OTP code                                        |
|      |                                                |
|      v                                                |
| Verify OTP hash ------>> Invalid? --> "Invalid code"  |
|      | Valid                                          |
|      v                                                |
| Set new password form                                 |
|      |                                                |
|      v                                                |
| password_hash() + update users table                  |
| Reset login_attempts + locked_until                   |
|      |                                                |
|      v                                                |
| "Password reset. Sign in."                            |
+-------------------------------------------------------+
```

### Session Management

```
+---------------------------------------------------------+
|                   Session Lifecycle                      |
|                                                          |
|  session_config.php runs on EVERY page load:             |
|                                                          |
|  1. Start session (name: BCP_REGISTRAR_SESSION)         |
|     strict_mode = 1                                      |
|                                                          |
|  2. Check idle timeout:                                  |
|     if (time() - $_SESSION['last_activity'] > 1200s)    |
|       → destroy session                                  |
|       → API routes: JSON 401 response                    |
|       → HTML pages: redirect login.php?timeout=1         |
|                                                          |
|  3. Touch last_activity = time()                         |
|     (keeps 20-min window sliding on every request)       |
|                                                          |
|  Session Data:                                           |
|    $_SESSION['user_id']       — users.id                 |
|    $_SESSION['email']         — users.email              |
|    $_SESSION['full_name']     — users.full_name          |
|    $_SESSION['role']          — admin|registrar|nurse|   |
|                                 student                  |
|    $_SESSION['last_activity'] — time()                   |
|                                                          |
|  Client-side: js/session-warning.js                      |
|    → Warns user 60s before timeout                       |
|    → Shows countdown modal                               |
|    → "Stay signed in" extends the window                 |
|    → Auto-logout when countdown hits 0                   |
+---------------------------------------------------------+
```


---

## 4. Student Management Flow

### Enrollment Pipeline

```
+--------------+    +---------------+    +--------------+    +--------------+
| External     |    | Registrar     |    | Registrar    |    | MariaDB      |
| Enrollment   |    | Masterlist    |    | Accepts      |    |              |
| System       |    | Page          |    | Student      |    |              |
+------+-------+    +------+--------+    +------+-------+    +------+-------+
       |  1. Applicant     |                    |                    |
       |  submits online   |                    |                    |
       |  enrollment       |                    |                    |
       |------------------>|                    |                    |
       |  2. Registrar     |                    |                    |
       |  sees pending     |                    |                    |
       |  GET ?action=list |                    |                    |
       |                   |--------------------------------------->|
       |                   |  3. Display list   |                    |
       |<------------------|                    |                    |
       |  4. Click "Accept"|                    |                    |
       |  POST             |                    |                    |
       |  ?action=duplicate|                    |                    |
       |  -check           |                    |                    |
       |                   |--------------------------------------->|
       |                   |  Check students table for dupes       |
       |                   |  (name+birthdate OR student_no)       |
       |                   |  5a. DUPLICATE → show warning          |
       |                   |  5b. NEW → proceed                     |
       |                   |                    |                    |
       |                   |  POST              |                    |
       |                   |  ?action=accept    |                    |
       |                   |--------------------------------------->|
       |                   |                    |  6. Create/Update |
       |                   |                    |  students +       |
       |                   |                    |  guardians +      |
       |                   |                    |  enrollment_      |
       |                   |                    |  history +        |
       |                   |                    |  status_tracker   |
```


### Student CRUD Flow

```
+----------+     +---------------+     +------------------+     +----------+
| Registrar|     | students.php  |     | api/students.php |     |   DB     |
| Page     |     | (HTML form)   |     | (JSON API)       |     |          |
+----+-----+     +------+--------+     +--------+---------+     +----+-----+
     |  ADD STUDENT     |                       |                     |
     |  Fill form       |  submit               |                     |
     |----------------->|---------------------->|  validate fields    |
     |                  |                       |  normalize names    |
     |                  |                       |  validate phone     |
     |                  |                       |  insert students    |
     |                  |                       |-------------------->|
     |                  |                       |  insert guardians   |
     |                  |                       |-------------------->|
     |                  |                       |  log status_tracker |
     |                  |                       |-------------------->|
     |                  |  {success: true}      |                     |
     |  Toast: "Saved"  |<----------------------|                     |
     |<-----------------|                       |                     |
     |                  |                       |                     |
     |  EDIT STUDENT    |  GET ?id=N            |                     |
     |  Load form       |---------------------->|  SELECT * FROM     |
     |  with data       |<----------------------|  students WHERE id  |
     |  Modify + submit |  PUT/PATCH ?id=N      |                     |
     |----------------->|---------------------->|  UPDATE students   |
     |                  |<----------------------|                     |
     |                  |                       |                     |
     |  AI ASSIST       |  POST                 |                     |
     |  Paste text from │  action=paste_fill    |                     |
     |  enrollment slip |---------------------->|  -> ai-assist.php  |
     |  AI extracts:    |  {extracted fields}   |  -> ai_client.php  |
     |  name, course    |<----------------------|  -> OpenAI/Gemini  |
     |  Auto-fill form  |                       |                     |
```


### Student Data Model

```
+--------------------------------------------------------------+
|                      students table                          |
|  id · student_number · first_name · middle_name · last_name |
|  name_suffix · lrn · gender · civil_status · birth_date     |
|  place_of_birth · nationality · religion · email             |
|  contact_number · address · course · major · year_level     |
|  section · school_year · semester · status · photo           |
|  mother_name · father_name · adviser_id · created_at         |
+----------------------+-------------------+-------------------+
       |                  |                    |
  +----v------+    +------v------+    +-------v-----------+
  | guardians |    | academic_   |    | student_ids       |
  |           |    | history     |    |                   |
  | student_id|    | student_id  |    | student_id        |
  | full_name |    | school_year |    | id_number         |
  | relation  |    | semester    |    | id_type           |
  | contact   |    | department  |    | issued_date       |
  | email     |    | program     |    | expiry_date       |
  | is_primary|    | status      |    | qr_path           |
  +-----------+    +------+------+    +-------------------+
                    +-----v------+
                    | academic_  |
                    | grades     |
                    | subject · units · midterm_grade        |
                    | final_grade · remarks                  |
                    +-------------+
```


---

## 5. Document Request Flow

Requests are **walk-in only**. Everything below happens at one of three
counters in the Registrar's Office: the student is present, the fee is
settled in cash at the same counter, and the document is handed over
before the student leaves. There is no payment gateway and no courier.

### Full Document Request Lifecycle

```
+-----------+   +------------------+   +------------------+
|  Student  |   |  Counter desk    |   |  document_       |
|  at the   |-->|  registrar/      |   |  requests        |
|  counter  |   |  documents.php   |   |                  |
+-----+-----+   +--------+---------+   +--------+---------+
      |                    |                      |
      | 1. Registrar       |                      |
      |    files the       |                      |
      |    request and     |                      |
      |    quotes the fee  |                      |
      |                    |--------------------->|
      |                    | 2. Row created as    |
      |                    |    Filed, stamped with|
      |                    |    counter, queue    |
      |                    |    ticket, walk-in   |
      |                    |    time and staff    |
      |                    |<---------------------|
      |                    |                      |
      | 3. Returns when   |                      |
      |    told it is      |                      |
      |    ready           |                      |
      |<------------------>|                      |
      |                    |                      |
      | 4. Registrar      |--------------------->|
      |    signs, stamps, | 4. Processing ->     |
      |    previews the   |    Ready, record copy |
      |    record copy    |    retained          |
      |                    |<---------------------|
      |                    |                      |
      | 5. Fee settled    |                      |
      |    at the counter |                      |
      |    + collected    |--------------------->|
      |<------------------>| 5. Claimed: paid_at, |
      |                    |    receipt no, time  |
      |                    |    and staff recorded|
```

### Document Status Pipeline

`document_status` records **where the work is**. It is deliberately not the
place to record **what the request is waiting on** — those are two
independent facts, and collapsing them is what previously made multi-day
requests invisible (see [Why progress and blockage are separate](#why-progress-and-blockage-are-separate)).

```
  +--------+    +------------------+    +------------+
  | Filed  |--->| Being prepared  |--->|   Ready    |
  +--------+    |   (Processing)  |    | (signed,   |
       |        +------------------+    |  sealed)   |
       |                                  +-----+------+
       |                                        |
  fee paid at                                fee settled at
  the counter                                the counter
       |                                        |
       v                                        v
  +------------------+                    +-------------+
  | Held (balance or |                    |  Claimed    |
  | a missing doc)   |---> back to Filed  +-------------+
  +------------------+
```
* **Filed** — taken at the counter. The Registrar's next job.
* **Held** — not a status but a derived blockage, shown in its own column: an
  outstanding balance, or a requirement the student has not brought. The
  authoritative values are `blocked_reason` and `blocked_since`, re-derived
  whenever the request is viewed, re-checked, or the balance is settled — so
  paying actually releases the request instead of stranding it. (It used to be
  decided once, at intake, and never revisited: a student who paid the next
  morning stayed blocked forever.)
* **Processing** — being prepared.
* **Ready** — signed and sealed, waiting for the student to return.
  Nothing for the Registrar to do; the fee is still owed.
* **Claimed** — collected. `paid_at` and `official_receipt` are written
  here, because this is where money actually changes hands.
* **Rejected** — terminal, with `rejection_reason`.

Two former stages were removed: `Awaiting_Payment` (there is no online
payment to wait for — it blocked every request indefinitely) and `Shipped`
(courier retired with the gateway).

### Why progress and blockage are separate

A single status column cannot answer both "where is this?" and "why is it
stuck?". A request waiting on a balance and a request the clerk had simply
not started both read as **Filed**, so nothing on the desk could tell *held
up* from *not started* — and the request aged quietly either way.

So blockage lives in its own fields, `blocked_reason` and `blocked_since`,
derived from data rather than typed in:

| Blocker | Derived from | Release when |
|---|---|---|
| Outstanding balance | `finance.balance > 0` | the balance is settled |
| Awaiting a requirement | catalog `requirement` with no uploaded file | the file is supplied |

Both are the student's to resolve, and neither is a hard stop: the desk can
prepare the document while either is outstanding, and the fee is settled at
collection regardless. A request can be at Filed **and** blocked at the same
time; the two are independent. The blockage clock starts when the *first*
blocker appears, so a request held for four days reports four days even if the
last obstacle only surfaced today.

### How the desk shows the process

The request row draws the walk-in track rather than naming the current status,
because a status name has to be remembered against a mental model of the
pipeline. Four stations — **Filed → Preparing → Ready → Collected** — are drawn
in order, with the one in hand picked out and the ones already passed filled in:

| Station meaning | Rail state | CSS |
|---|---|---|
| Already done | filled ink square | `.is-done` |
| The station in hand | filled brass square, ringed | `.is-now` |
| The station in hand **and** held | outlined rust square | `.is-now.is-held` |
| Not yet reached | hollow grey square | `.is-future` |
| Off the track (rejected) | rust square, struck through | `.is-stopped` |

Two consequences worth stating, because both were deliberate:

* **A hold is not a station.** `is-held` is layered onto the station the request
  is already sitting on; it never moves the request along the track and never
  becomes a lifecycle state of its own. That is the same split as
  `document_status` vs `blocked_reason`, carried through to the pixels.
* **Rejected leaves the track.** It is drawn as *off* the rail with every
  station struck through, not as a request that reached the end — a rejection is
  not progress.

Both the track and the position come from `doc_stage_track()` and
`doc_stage_position()` in `shared/document_process.php`, and the forward action
comes from `doc_next_step()`. All three read the same lifecycle, so the drawn
rail and the offered button cannot drift into disagreeing about where a request
is. The rail is accompanied by a visually-hidden sentence ("Stage 2 of 4:
Preparing. Held, waiting on …"), since four coloured squares mean nothing read
out linearly, and it collapses to the status word on narrow screens rather than
truncating its labels.

The **Action** column therefore names the verb that moves the request to the
next station, and the rail shows the destination.

`shared/document_process.php` is the single source of truth for all of this
(`doc_blocker`, `doc_age`, `doc_next_step`, `doc_refresh_blocker`). Intake, the
desk and the API all read from it, so the button on the page and the guard on
the endpoint cannot disagree about what may happen next.

### Exit clearance has been removed

There was once a gate here: `document_catalog.triggers_exit_clearance` marked
Honorable Dismissal and a final Transcript of Records as unsignable until
Alumni, Dean and Property each signed off in `exit_clearances`.

**It never worked, and it was removed on 2026-09-27.** Three separate things
were wrong with it:

* the catalog flag was selected and then never read by any code path;
* intake had stopped creating the clearance rows the gate depended on;
* a backfill seeded six `PENDING` rows and not one was ever signed by an
  office, so nothing in the school depended on the answer.

It also read as more authoritative than it was. The desk replaced the action
button with a hard **Awaiting clearance** lock, and the AI assistant told
students their final TOR would be held until three offices approved. A gate
that never fires, presented as a stop, is worse than no gate.

Removal was therefore a simplification, not a loss: the `exit_clearances`
table is dropped, `triggers_exit_clearance` is dropped rather than left at
`0` (so a future gate cannot be re-enabled against a table that is gone), and
DOC-HD's requirement — the prose "Completed Exit Clearance", satisfied by
signatures rather than an uploaded file — is cleared to `NULL`. Left in place
it would have sat on every Honorable Dismissal forever as a hold the desk
could not clear.

Backed up to `backups/pre_exit_clearance_removal_20260927.sql` first. If the
school does need a three-office sign-off for these two documents, it should be
reintroduced as a working feature with a page that actually records
signatures — not as a flag and an empty table.

### Turnaround targets

`document_catalog.sla_days` sets a per-SKU target, seeded as:

| SKU | Target | Why |
|---|---|---|
| Certificate of Enrollment, Course Description | 1 day | a print job |
| Certified True Copy | 2 days | certifies a stored file |
| Transcript, Certificate of Good Moral | 3 days | verification, signature |
| Diploma Replacement | 5 days | notarized affidavit to verify |
| Honorable Dismissal | 10 days | a full student exit, longest of the set |

A target is per-SKU rather than global because a 1-day promise and a 10-day
promise are not the same lateness. The age clock runs from filing to
**collection**, not to signature — a document prepared in an hour but left
three days on the shelf still cost the student three days. A collected
request is never flagged overdue: it arrived, and marking it late would
punish the desk for work the student never came to collect.

A SKU with no target set is never overdue — absence of a promise is not a
broken promise.

### The counter desk

`registrar/documents.php` is the standard registrar request table: the metric
strip, the two charts, then a single table of every request. It follows the
same house design system as the rest of the app — the registrar-blue panels,
`.table`, `.pill` and `.btn` primitives — so it sits alongside Students,
Queue and the other modules rather than reading as a separate tool.

What changed is the process, not the chrome.

The four metric tiles each answer a question that changes what a clerk does
next, which is why they are these four: **Needs action** (work the desk owns),
**Waiting on others** (held by another office — chase, do not start),
**Past target** (over the SKU's target), and revenue for the selected range.
The former *Regular vs Express* split was dropped: it was not actionable.

In the table:

* **The process rail** — four stations, Filed → Preparing → Ready → Collected,
  drawn in the request cell — replaces the status pill rather than sitting
  under it. A pill says "Being prepared" and the clerk has to remember where
  that sits in the run; the rail shows the whole walk-in journey with one
  station lit, so position is read rather than recalled.

  It is drawn as stamped impressions on a paper docket, not as a UI progress
  bar: squared pads, a tick through each completed one, and the ruled line
  between them dashed while the work is still ahead. Circles on a line were
  rejected — that shape means nothing in a registrar's office, and a rubber
  stamp is square. Ink navy for passed, brass for the station in hand (the
  only pad with a cast shadow, so the eye lands there first), rust for a
  hold, and a struck-through rust rule for a request that came off the track.
  Labels are sentence case at 10.5px, not 9.5px all-caps.

  Rejected requests are drawn as **off the track** — every station struck,
  none of them current — because a request that was refused never reached the
  end, and drawing it at the last station would say the opposite.
* **Waiting on** states the blocker and how long it has stood — `held 4 days`
  is a chase, `held 4 hours` is not. Rows held carry an amber edge; a request
  waiting on nothing says so plainly, so the absence of a blockage is visible
  too.
* **Turnaround** draws the SKU's promise as a bar: filled for time used, with
  a tick at the target. It is the only mark on the page that shows a request
  *drifting* late before it is late, which a bare date cannot. It turns red
  past the target and teal once collected.
* **Action** offers only the next legitimate step: *Start preparing* →
  *Sign & mark ready* → *Collect*, with Reject alongside until the document is
  signed. Status and fee sit under the request name rather than in columns of
  their own — they are facts *about* the request, and each was costing a
  column of horizontal space the actionable cells needed.
* A **Re-check** control re-derives the blockage on demand, for the case
  where a balance was settled outside this page.
* **View** opens a detail row holding purpose, recipient, quantity, payment,
  target and the activity log — and the two controls that make the document
  itself reachable: **Preview document** and **Print**. Both read
  `api/document-preview.php`, which renders from the same
  `shared/document_templates.php` as the printed page, so what is approved on
  screen is what comes off the printer.

#### Two controls that rendered and did nothing

Both of these shipped wired to a real handler, passed every markup check, and
were completely inert. They are recorded because "it renders, it is wired, so
it works" is not a test, and neither was caught by one.

**View.** The row ships `display:none`, and the handler tested
`row.style.display` to decide whether the row was open, then used that same
value to decide whether to *close* it — so every click closed a row that was
already closed. Once that was fixed, View was still inert for a second reason:
the row is itself clickable and calls the same function, so a click on the
button also bubbled up and called `toggleDetail` twice. The row opened and
closed in the same tick and ended exactly where it started. The
`event.stopPropagation()` that should have prevented this was on a wrapper
`<div>` — a *descendant* of the row, so it ran first and could never stop the
row's own handler. Only stopping propagation at the button prevents the second
call. `tests/view_toggle_probe.js` clicks the live page and counts handler
invocations, so neither can return quietly.

**Preview.** `api/document-preview.php` worked correctly all along (verified
200, full document) and *nothing on the page linked to it*. The first attempt
at fixing it reproduced the same class of bug in a new place: a page-relative
`api/document-preview.php` resolves from `/registrar/` to `/registrar/api/`
and 404s, so the button rendered a working-looking dialog containing an Apache
error page. The API base is now emitted by the server as `DOC_API`, and
`tests/process_check.php` fetches the URL that base produces.

The document renders in a same-origin iframe inside the dialog rather than
being injected, both because the endpoint returns a whole document and because
the desk's own CSP sets `frame-ancestors 'none'`. Only the `body` around the
sheet is styled — the template already caps its page width for screen
(`@media screen .dt-doc`), and an earlier version that forced width and
background onto the sheet fought that rule, which is what made the preview read
as disorganised.

**Print** printed the document by opening `…&print=1` in a new tab, even
though the document was already on screen, so every print left a tab to find
and close. It now calls `print()` on the preview iframe's own window and
prints in place; the standalone window remains only as a fallback for when no
preview is open. The probe counts `window.open` calls, because a headless
browser *blocks* popups — "no new tab appeared" proves nothing on its own, and
the first version of this test passed against the tab-opening code.

* The status filter is joined by a **waiting filter** — *Needs the desk*,
  *Needs something first*, *Past target* — because
  "show me only what is not moving" is a question a status dropdown cannot
  answer, since blocking is not a status.
* Payment is no longer a precondition for starting. A Filed request can be
  prepared immediately; the fee is taken when the student collects, and
  that is where `paid_at` and `official_receipt` are written.

#### The log line that contradicted the rail

`document_request_events` is append-only: a note is written once, when
something happened, and rewriting it afterwards would falsify the record. So
notes written before the status realignment still read
*"Request submitted (DOC-…) — awaiting payment"* — describing a payment gate
the walk-in flow no longer has, directly beneath a rail saying *Preparing*.
`doc_readable_event_note()` translates retired wording on the way out. The
stored rows are untouched; only the displayed text changes.

### Starting from an empty desk

Three scripts sit in `tests/` for exercising the desk from a known state.
All three default to being safe: the destructive one is a dry run unless
told otherwise.

| Script | Does |
| --- | --- |
| `doc_request_impact.php` | Read-only. Lists every table holding request data, what references it by foreign key, and the rows that would go. Run this first. |
| `wipe_doc_requests.php` | Clears `document_requests` and `document_request_events`. Dry run by default; `--execute` to apply. Children are deleted first inside a transaction, and the auto-increment is reset so the next request is `DOC-2026-0001` again. |
| `seed_doc_request.php` | Files one request through `api/student-documents.php` over HTTP, with a real session and the real CSRF token. |

`document_catalog` is never touched. The desk's New Request form, the
preview and the whole render path are built from those 7 SKUs, so
emptying it would break testing rather than enable it. Students, users,
finance and queue tickets are likewise left alone.

Seeding goes over HTTP rather than inserting a row directly, on purpose: a
direct INSERT would prove the page renders but not that **intake** still
works, and intake is exactly what needs checking after a wipe. Three
things about that endpoint are easy to get wrong, and all three report a
misleading error:

* The body must be posted from a file (`-d @file`). JSON passed inline
  through the shell gets mangled, and the endpoint then replies *"No
  student account is linked to this session"* — which reads like an auth
  problem but is a broken request body.
* `request_type` must be `Express` or `Regular`, and `fulfillment_type`
  `Pickup` or `Digital`. Anything else is rejected as *"Invalid request
  type"*.
* The CSRF token is in `<meta name=csrf-token>` rendered with **single**
  quotes. A regex expecting double quotes finds nothing and reports
  *"No CSRF token in the desk page"*.

`wipe_doc_requests.php` should not be run without a dump first:

```
mysqldump -u root --single-transaction registrar_ai \
  document_requests document_request_events > tests/backup.sql
```

`tests/*.sql` is gitignored — those dumps are local safety nets containing
real rows, not source.

### Not tied to the queue

Document requests are independent of `queue_tickets`. An earlier revision
added a `purpose` column and a `document_request_id` foreign key to
`queue_tickets`, on the reasoning that one request might span two visits
(file it, collect it later). Nothing ever read those columns, and the
constraint was wrong in practice: it meant a request could only exist if
somebody first issued a queue ticket, which the Registrar's Office does not
require. Both columns, the index and the foreign key have been dropped, and
the queue subsystem is untouched.

### Document Types

Seven catalog SKUs render from `shared/document_templates.php`, each with an
inline QR pointing at the public verification page:

| SKU | Document | Form |
|-----|----------|------|
| `DOC-TOR` | Transcript of Records | 137/138 |
| `DOC-COE` | Certificate of Enrollment | COE |
| `DOC-GM` | Certificate of Good Moral | GM |
| `DOC-CTC` | Certified True Copy | CTC |
| `DOC-DIPLOMA` | Diploma Replacement | Diploma |
| `DOC-HD` | Honorable Dismissal | HD |
| `DOC-CD` | Course Description | CD |

`DOC-137` is intentionally absent — Form 137 is not a college-issued
document.


---

## 6. RFID Card & Queue System

### RFID Card Lifecycle

```
+------------------------------------------------------------+
|                     RFID CARD LIFECYCLE                     |
|                                                            |
|  +---------+     +---------+     +---------+     +---------+ |
|  | Registry|---->| Active  |---->| Expired |     |  Lost   | |
|  | (new)   |     |         |     |         |     |         | |
|  | assigned|     |  Tap at |     | Date    |     | Reported| |
|  | to      |     |  kiosk  |     | passed  |     | stolen  | |
|  | student |     |  works  |     | auto-   |     |         | |
|  +---------+     +----+----+     | expired |     | Block   | |
|                       | Lost?    +---------+     | + Reissue| |
|                       v                          +---------+ |
|                  +---------+                                   |
|                  |  Lost   |                                   |
|                  |  Replaced|                                  |
|                  +---------+                                   |
|                                                                |
|  autoExpireCards():                                            |
|  UPDATE rfid_cards SET status='expired'                       |
|  WHERE expiry_date < CURDATE() AND status='active'            |
|  (runs on every scan and kiosk tap)                            |
+------------------------------------------------------------+
```


### Queue System Flow

```
+---------------------------------------------------------------------+
|                      QUEUE SYSTEM                                    |
|                                                                      |
|  +------------------+                                              |
|  |  QUEUE KIOSK     |  queue/kiosk.php (student-facing)            |
|  |  (Public Display) |                                              |
|  |                  |  Student taps RFID card on reader            |
|  |  +------------+  |         |                                     |
|  |  | TAP SCREEN |  |         v                                     |
|  |  | [RFID Tap] |  |  POST to api/queue-public.php                 |
|  |  +-----+------+  |  { card_uid: "ABC123" }                      |
|  |        v         |         |                                     |
|  |  +------------+  |         v                                     |
|  |  |  RESULT    |  |  1. lookupCardByUid()                         |
|  |  |  Ticket #  |  |  2. Check if already in queue today          |
|  |  |  Awaiting  |  |  3. Insert queue_tickets                      |
|  |  +------------+  |     display_number: 001, status: waiting     |
|  |                  |  4. Log to rfid_scan_logs                      |
|  |  +------------+  |                                               |
|  |  |  BOARD     |  |  Shows: Now serving: 003, Waiting: 5         |
|  |  |  (live     |  |  Skipped: 1, Completed: 42                   |
|  |  |  polling)  |  |  Polls every 3s                               |
|  |  +------------+  |                                               |
|  +------------------+                                               |
|                                                                      |
|  +------------------+                                               |
|  | REGISTRAR        |  registrar/queue.php + api/queue.php           |
|  | CONSOLE          |                                               |
|  |  Window select   |  ACTIONS:                                      |
|  |  (1, 2, or 3)   |  > Call Next -- auto-complete current,        |
|  |                  |              serve oldest waiting              |
|  |  NOW SERVING     |  > Skip -- mark as no-show, advance           |
|  |  #003            |  > Complete -- mark finished                   |
|  |                  |  > Remove -- delete stuck ticket               |
|  |  WAITING         |                                               |
|  |  #004 #005 #006  |  Window routing: each ticket gets a          |
|  +------------------+  window (1-3) for multi-window service       |
+---------------------------------------------------------------------+
```

### Queue Ticket States

```
  +---------+     +---------+     +------------+
  | waiting |---->| serving |---->| completed  |
  +---------+     +----+----+     +------------+
                       |
                       | Skip
                       v
                  +---------+
                  | skipped |---->> (optionally -> removed)
                  +---------+
```


---

## 7. Health / Clinic Flow

### Nurse Clinic Workflow

```
+--------------+     +--------------+     +--------------+     +----------+
|  Nurse       |     | Nurse        |     | api/clinic   |     |   DB     |
|  (Browser)   |     | Dashboard    |     | .php         |     |          |
+------+-------+     +------+-------+     +------+-------+     +----+-----+
       |  1. Student taps   |                    |                   |
       |  RFID at nurse     |  POST action=      |                   |
       |  kiosk             |  identify {card_uid}                   |
       |------------------->|------------------->|  Resolve card ->  |
       |                    |                    |  student         |
       |                    |  Return student    |------------------>|
       |                    |  info + photo      |                   |
       |  2. View student   |<-------------------|                   |
       |  profile + history |                    |                   |
       |<-------------------|                    |                   |
       |  3. Log visit      |  POST action=      |                   |
       |  (complaint,       |  save-visit        |                   |
       |   diagnosis,       |------------------->|  Insert into     |
       |   vitals,          |                    |  health_visits   |
       |   treatment,       |-------------------------------------->|
       |   medication)      |                    |                   |
       |  Toast: "Saved"    |  {success: true}   |                   |
       |<-------------------|<-------------------|                   |
```

### Health Module Pages

```
NURSE PORTAL:
  nurse/dashboard.php   — Stats + recent visits
  nurse/records.php     — Searchable visit history
  nurse/student-lookup.php — RFID identify
  nurse/incidents.php   — Log health incidents
  nurse/supplies.php    — Clinic supply tracking
  nurse/statistics.php  — Health analytics

STUDENT PORTAL:
  student/health-records.php — View own visits

REGISTRAR:
  registrar/health-records.php — View-only log
  api/clinic.php · api/clinic-incidents.php · api/clinic-supplies.php
  api/clinic-ai-recommend.php — AI recommendations
```


---

## 8. AI-Assisted Features

### AI Integration Architecture

```
+------------------------------------------------------------------+
|                      AI FEATURES                                  |
|                                                                   |
|  shared/ai_client.php                                            |
|    aiGenerate(systemPrompt, userPrompt, opts)                    |
|      -> Check ai_cache (prompt_hash -> response)                  |
|      -> Cache miss? Call API:                                     |
|          POST NINEROUTER_URL/v1/chat/completions                 |
|          (OpenAI-compatible format)                               |
|      -> Or Gemini generateContent endpoint                       |
|      -> Store in ai_cache with TTL                                |
|      -> Return text                                               |
|                                                                   |
|  Model fallback chain (AI_MODELS array):                         |
|    Try model_1 -> HTTP error? -> Try model_2 -> ... -> Return '' |
|                                                                   |
|  +----------------------+------------------------------------+   |
|  | INPUT                | AI USE CASE                        |   |
|  +----------------------+------------------------------------+   |
|  | Paste text           | Extract student fields            |   |
|  | (enrollment slip)    | api/ai-assist.php (paste_fill)    |   |
|  +----------------------+------------------------------------+   |
|  | Document image       | OCR extraction (vision model)     |   |
|  | (PDF/scan/photo)     | api/ai-assist.php (extract_doc)   |   |
|  +----------------------+------------------------------------+   |
|  | Single field value   | Suggest/correct one field         |   |
|  |                      | api/ai-assist.php (suggest_field) |   |
|  +----------------------+------------------------------------+   |
|  | Masterlist query     | Natural language -> SQL search     |   |
|  | "BSIS probation"     | api/masterlist-ai-search.php      |   |
|  +----------------------+------------------------------------+   |
|  | Student question     | Student AI chat assistant          |   |
|  | "What's my GPA?"     | api/student-ai-chat.php           |   |
|  +----------------------+------------------------------------+   |
|  | Uploaded document    | AI document analysis               |   |
|  |                      | api/documents-ai.php               |   |
|  +----------------------+------------------------------------+   |
|  | Student grades       | Grade analysis & insights          |   |
|  |                      | api/student-grades-ai.php          |   |
|  +----------------------+------------------------------------+   |
|  | Clinic data          | AI health recommendations          |   |
|  |                      | api/clinic-ai-recommend.php        |   |
|  +----------------------+------------------------------------+   |
+------------------------------------------------------------------+
```


### AI Cache Flow

```
+--------------+     +----------------+     +----------+
| AI Request   |     | ai_cache table |     | AI API   |
|              |     |                |     | Gateway  |
+------+-------+     +-------+--------+     +----+-----+
       | 1. Hash prompt      |                    |
       | (SHA-256)           |                    |
       | 2. SELECT WHERE     |                    |
       | prompt_hash = ?     |                    |
       |--------------------->|                    |
       |    3a. HIT          |                    |
       |<--------------------|  Return cached     |
       |                     |                    |
       |    3b. MISS         |                    |
       |    +----------------|------------------->|
       |    |                |  4. POST /v1/      |
       |    |                |  chat/completions  |
       |    |  5. Return     |<-------------------|
       |    |     text       |                    |
       |    |  6. INSERT     |                    |
       |    |  INTO ai_cache |                    |
       |    +--------------->|                    |
```


---

## 9. Emergency & Contacts Flow

### Contact Management

```
+------------------------------------------------------------------+
|                    EMERGENCY & CONTACTS MODULE                    |
|                                                                   |
|  Source of truth: Registrar (not student)                         |
|                                                                   |
|  REGISTRAR (Staff):                                              |
|    1. Create contacts -> contact_recipients table                |
|       Fields: email, full_name, relationship,                    |
|               send_billing, send_emergency, verified              |
|    2. Send email -> shared/mail_client.php                       |
|       Priority: Brevo API > Gmail API > SMTP                     |
|    3. Emergency blast -> sendEmergencyBlast()                    |
|       Sends to every contact WHERE send_emergency=1              |
|    4. Auto-forward invoices -> contactAutoForwardInvoice()       |
|       Triggered when a request is filed at the counter         |
|    5. Test email -> verify SMTP working                          |
|                                                                   |
|  STUDENT (Self-service):                                         |
|    View contacts (read-only)                                     |
|    REQUEST changes:                                              |
|      POST action=request_change                                  |
|      { type: "add|update|remove", contact_data: {...} }         |
|    Registrar reviews + approves/rejects:                         |
|      POST action=approve_change / reject_change                  |
+------------------------------------------------------------------+
```


---

## 10. Notifications & Email Flow

### Email Transport Priority

```
+------------------------------------------------------------------+
|  shared/mail_client.php -> sendEmail(to, subject, html, attach)  |
|                                                                   |
|  1. BREVO_API_KEY configured?                                    |
|     YES -> sendViaBrevo (HTTP API)                               |
|     Brevo failed? -> try next                                    |
|                                                                   |
|  2. GMAIL_API configured?                                        |
|     YES -> sendViaGmailApi (OAuth2 + refresh token)              |
|     Gmail failed? -> try next                                    |
|                                                                   |
|  3. SMTP FALLBACK (PHPMailer, STARTTLS :587)                     |
|                                                                   |
|  Not configured at all? -> Log warning, return false             |
|  App works normally without email.                                |
+------------------------------------------------------------------+
```

### In-App Notifications

```
  Staff notifications:
    api/notifications.php -> reads from announcements table
    Sidebar bell icon -> notification modal

  Student notifications:
    api/student-notifications.php -> student_notifications table
    Triggered by: document status changes
    Auto-created when registrar processes a request

  Communication log:
    Every email logged to communication_log table
    (recipient, subject, status, sent_by, timestamp)
```


---

## 11. Payment Flow (PayMongo)

### Payment Integration

```
+----------+     +--------------+     +--------------+     +----------+
| Student  |     | api/student- |     | PayMongo     |     | Webhook  |
| (Browser)|     | documents.php|     | API          |     | Receiver |
+----+-----+     +------+-------+     +------+-------+     +----+-----+
     |  1. Request doc  |                    |                   |
     |  (online payment)|                    |                   |
     |----------------->|                    |                   |
     |                  | 2. PayMongo        |                   |
     |                  | configured?        |                   |
     |                  |                    |                   |
     |                  | YES: Create Source |                   |
     |                  | (gcash, hosted)    |                   |
     |                  |------------------->|                   |
     |                  |                    |                   |
     |                  | NO: Auto-complete  |                   |
     |                  | (mock mode)        |                   |
     |                  |                    |                   |
     |  3. Redirect to  |                    |                   |
     |  GCash checkout  |                    |                   |
     |<-----------------|                    |                   |
     |  4. User pays    |                    |                   |
     |  in GCash app    |                    |                   |
     |----------------------------------------------->|
     |                  |                    |  5. Webhook POST  |
     |                  |                    |  paymongo-webhook |
     |                  |                    |                   |
     |                  |                    |  source.chargeable|
     |                  |                    |  -> capture payment|
     |                  |                    |                   |
     |                  |                    |  payment.paid     |
     |                  |                    |  -> confirmGateway|
     |                  |                    |  -> Processing    |
     |                  |                    |                   |
     |  6. Poll status  |<-------------------|                   |
     |  -> "Processing" |                    |                   |
```


---

## 12. Database Schema Map

### Entity Relationship Overview (39 tables)

```
+-----------------------------------------------------------------+
|                     CORE ENTITIES                                |
|                                                                  |
|  +----------+     +----------------+     +------------------+  |
|  |  users   |---->|  students      |<----|  enrollments     |  |
|  |          |     |                |     |  (external)      |  |
|  | id       |     | id             |     |                  |  |
|  | email    |     | student_number |     | enrollment_id    |  |
|  | role     |     | first_name     |     | first_name       |  |
|  | password |     | last_name      |     | last_name        |  |
|  | _hash    |     | course         |     | course           |  |
|  | student  |     | status         |     | status           |  |
|  | _id (FK) |     +-------+--------+     +------------------+  |
|  +----------+             |                                     |
|                           |                                     |
|  RELATED TABLES (via students.id FK):                          |
|                                                                  |
|  guardians · rfid_cards · student_ids · contact_recipients      |
|  academic_history · status_tracker · health_visits              |
|  health_records · documents · student_notifications             |
|  enrollment_history · status_tracker                             |
|                                                                  |
|  academic_history ---> academic_grades (subject, grade, units)  |
|                                                                  |
|  DOCUMENT REQUEST SYSTEM:                                        |
|  document_requests ---> document_request_events (audit trail)   |
|                     ---> mock_payment_transactions (PayMongo)    |
|                     ---> mock_lalamove_orders (delivery)          |
|  document_catalog (fee schedule)                                |
|  documents (digital file storage)                                |
|  document_ai_audit (AI analysis log)                            |
|                                                                  |
|  QUEUE SYSTEM:                                                   |
|  queue_tickets · card_readers · rfid_scan_logs                  |
|                                                                  |
|  HEALTH / CLINIC:                                                |
|  health_visits · health_records · clinic_incidents              |
|  clinic_supplies · medical_certificates                         |
|                                                                  |
|  SECURITY / CACHE / LOGGING:                                     |
|  otp_codes · login_attempts · ai_cache · masterlist_cache       |
|  audit_logs · announcements · student_notifications             |
|  communication_log (via mail_client)                             |
+-----------------------------------------------------------------+
```


---

## 13. File Structure Reference

### Directory Map

```
registrar-ai-system/
│
├── index.php                  Root redirect -> login.php
├── login.php                  Auth page (creds -> OTP -> session)
├── logout.php                 Session destroy + redirect
├── dashboard.php              Admin/Registrar dashboard
├── settings.php               System settings
├── terms-and-conditions.php   T&C page
├── verify.php                 Public verification endpoint
├── verify-student.php         QR code -> student verification
├── seed.php                   Database seeder
│
├── shared/                    -- Shared PHP layer --
│   ├── config.php             DB constants, app config, env()
│   ├── database.php           PDO singleton (Database class)
│   ├── session_config.php     Session start, idle timeout, auth helpers
│   ├── csrf_guard.php         CSRF token generation + enforcement
│   ├── auth_actions.php       Login / OTP / Reset / Logout API
│   ├── auth_security.php      Account lockout, OTP issue/verify
│   ├── security_headers.php   X-Frame, CSP, HSTS headers
│   ├── login_throttle.php     Per-email/IP rate limiting
│   ├── functions.php          General helpers + student CRUD
│   ├── normalize.php          Name/phone/course normalization
│   ├── ai_client.php          OpenAI/Gemini client + cache
│   ├── mail_client.php        Email transport (Brevo > Gmail > SMTP)
│   ├── paymongo_client.php    PayMongo GCash integration
│   ├── qr_generator.php       QR code SVG generation
│   ├── rfid_helpers.php       RFID card lookup + reader resolution
│   ├── document_pdf.php       PDF generation (Form 137, transcript)
│   ├── document_reader.php    PDF/DOCX/TXT text extraction
│   └── student_template.php   Student ID card template
```


```
├── api/                       -- JSON API endpoints --
│   ├── students.php           Student CRUD + guardians
│   ├── enrollments.php        Enrollment pipeline (intake)
│   ├── documents.php          Document request CRUD + file storage
│   ├── student-documents.php  Student-facing document operations
│   ├── generate-document-pdf.php  PDF generation endpoint
│   ├── documents-ai.php       AI document analysis
│   ├── rfid.php               RFID card CRUD
│   ├── rfid-scan.php          RFID tap processing
│   ├── card-readers.php       Reader CRUD
│   ├── queue.php              Queue state + actions (auth)
│   ├── queue-public.php       Queue public endpoints (kiosk)
│   ├── masterlist.php         Masterlist data
│   ├── masterlist-ai-search.php  AI-powered masterlist search
│   ├── contacts.php           Emergency contacts CRUD
│   ├── notifications.php      Staff notifications
│   ├── student-notifications.php  Student notifications
│   ├── clinic.php             Clinic visit API
│   ├── clinic-incidents.php   Health incidents
│   ├── clinic-supplies.php    Clinic supply tracking
│   ├── clinic-ai-recommend.php  AI health recommendations
│   ├── ai-assist.php          AI data extraction
│   ├── student-ai-chat.php    Student AI chat
│   ├── rfid-ai-search.php     RFID AI search
│   ├── student-grades-ai.php  Grade AI analysis
│   ├── student-ids.php        Student ID management
│   ├── users.php              User management
│   ├── audit-logs.php         Audit log viewer
│   ├── paymongo-webhook.php   PayMongo webhook receiver
│   └── keepalive.php          Session keepalive
│
├── registrar/                 -- Registrar staff pages --
│   ├── students.php           Student list + forms
│   ├── masterlist.php         Masterlist + AI search
│   ├── documents.php          Document request queue
│   ├── rfid-cards.php         RFID card management
│   ├── rfid-readers.php       Reader management
│   ├── rfid-scan-logs.php     Scan log viewer
│   ├── queue.php              Queue console
│   ├── status-tracker.php     Student status tracking
│   ├── academic-history.php   Academic records
│   ├── guardians.php          Guardian management
│   ├── health-records.php     Health record viewer
│   ├── student-ids.php        Student ID management
│   └── users.php              User management
│
├── student/                   -- Student self-service portal --
│   ├── _guard.php             Student role guard
│   ├── dashboard.php          Student dashboard
│   ├── profile.php            My profile
│   ├── grades.php             My grades
│   ├── academic-records.php   My academic history
│   ├── documents.php          My document requests
│   ├── contacts.php           My contacts (read-only)
│   ├── health-records.php     My health records
│   └── ids.php                My student IDs
│
├── nurse/                     -- Nurse portal --
│   ├── dashboard.php          Clinic dashboard
│   ├── records.php            Visit history
│   ├── student-lookup.php     RFID student identification
│   ├── incidents.php          Incident logging
│   ├── supplies.php           Supply tracking
│   └── statistics.php         Health analytics
│
├── queue/                     -- Queue system pages --
│   ├── kiosk.php              Public queue kiosk (student-facing)
│   └── monitor.php            Queue monitor display
│
├── ai/                        -- AI pages --
│   └── insights.php           AI analytics dashboard
│
├── includes/                  -- Reusable page parts --
│   ├── header.php             <head>, CSS, CSRF meta, page loader
│   ├── sidebar.php            Navigation sidebar (role-based)
│   ├── footer.php             Scripts, toast container, chat widget
│   └── student-chat.php       Student AI chat widget
│
├── js/                        -- Client-side JavaScript --
│   ├── auth.js                Auth logic + global showToast()
│   ├── queue.js               Queue system (kiosk/monitor/console)
│   ├── dashboard.js           Dashboard charts + live queue widget
│   ├── documents.js           Document request UI
│   ├── insights.js            AI insights charts
│   ├── sidebar.js             Sidebar collapse/expand
│   ├── session-warning.js     Idle timeout warning + countdown
│   ├── logout.js              Logout button handler
│   ├── csrf.js                CSRF token fetcher for AJAX
│   ├── searchable-select.js   Searchable dropdown component
│   └── page-loader.js         Page transition loader
│
├── css/                       -- Stylesheets --
│   ├── auth.css · components.css · sidebar.css
│   ├── registrar.css · registrar-premium.css
│   ├── dashboard.css · documents.css · student.css
│   ├── queue.css · status-tracker.css · page-loader.css
│
├── uploads/                   -- User uploads --
│   ├── students/ · ids/ · student_files/
│   ├── document_pdfs/ · document_requirements/ · ai_docs/
│
├── assets/images/             Static images (logos, icons)
├── registrar_ai.sql           Full DB schema + seed (39 tables)
└── composer.json              PHP dependencies
```


### Key Include Chains

```
Every authenticated page includes:
  shared/security_headers.php
  shared/session_config.php -> shared/config.php
  shared/database.php -> shared/config.php
  shared/csrf_guard.php

Every HTML page (via includes/header.php):
  -> CSRF meta tag
  -> CSS: page-loader, components, sidebar, registrar, dashboard
  -> JS: csrf.js, page-loader.js

Every HTML page (via includes/footer.php):
  -> Toast container (#toastContainer)
  -> JS: sidebar.js, auth.js (global showToast), logout.js,
         searchable-select.js, session-warning.js
  -> Optional: Chart.js, page-specific scripts
  -> Student chat widget (student role only)

API endpoints include:
  shared/config.php + database.php + session_config.php + csrf_guard.php
  + shared/functions.php (when needed)
```


---

## Data Flow Summary Table

| Module | Input | Process | Output | Tables |
|--------|-------|---------|--------|--------|
| **Login** | Username + password | Password verify -> session | Session + redirect | `users`, `login_attempts`, `otp_codes` |
| **Enrollment** | Applicant data | Duplicate check -> create | New student record | `enrollments`, `students`, `guardians`, `status_tracker` |
| **Document Request** | Doc type selection | File at counter -> prepare -> sign -> collect | PDF + email | `document_requests`, `mock_payment_transactions` |
| **RFID Scan** | Card UID | Lookup card -> student | Queue ticket # | `rfid_cards`, `rfid_scan_logs`, `queue_tickets` |
| **Queue** | RFID tap | Create -> waiting -> serving -> complete | Display updates | `queue_tickets`, `rfid_scan_logs` |
| **Clinic Visit** | RFID tap -> nurse logs | Identify -> record vitals | Visit record | `health_visits`, `health_records` |
| **AI Assist** | Text / image / query | LLM extraction -> cache | Fields / answer | `ai_cache`, `masterlist_cache` |
| **Contact Email** | Registrar sends | Brevo -> Gmail -> SMTP | Email + log | `contact_recipients`, `communication_log` |
| **Payment** | Student pays GCash | PayMongo -> webhook -> confirm | Confirmed -> processing | `mock_payment_transactions`, `document_requests` |

---

> **Generated from codebase analysis.** Covers 47+ PHP files, 39 DB tables, 11 JS files, 4 user roles, and 6 external service integrations.
