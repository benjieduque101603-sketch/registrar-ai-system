# Security Roadmap — BCP Registrar System

> Living status document. Every control is marked with what is **actually enforced in
> code**, verified by reading the source — not what a checklist claims.
> Last reviewed: 2026-10-01

## Why this file exists

`SECURITY_CHECKLIST.md` claimed *"Status: Production Ready ✅"*. That was inaccurate:
several controls it documented as `[x]` were **written but never called**. This file
replaces optimism with verified state.

Status key: **DONE** · **PARTIAL** (works on some paths) · **WIRED-NOT-USED** (dead code) ·
**TODO** (not started)

---

## Phase 0 — Email deliverability

The registrar was seeing `550 5.1.1 NoSuchUser` because the system **fabricated mailbox
names** it then tried to mail.

- [DONE] **S1 — Stop fabricating email addresses.** `shared/functions.php` and
  `backfill_student_accounts.php` no longer mint `student_<id>@<domain>`. An account with
  no valid email no longer triggers a welcome mail; credentials are surfaced in the UI
  for manual hand-off.
- [DONE] **S2 — Enforce email server-side on student intake.** `registrar/students.php`
  marked the field `required` in HTML only. `createStudentFromInput()` now rejects a
  missing/invalid address.
- [DONE] **S3 — Brevo is the intended transport.** `sendEmail()` already prefers
  Brevo over SMTP. Setting `BREVO_API_KEY` + a Brevo-verified `MAIL_FROM` moves mail off
  `smtp.gmail.com`. **Operator action — see below.**
- [DONE] **S4 — Bounce feedback loop.** New `api/mail-bounce.php` accepts a Brevo
  hard-bounce webhook and marks the address undeliverable so the app stops retrying it.
- [DONE] **S5 — Retire `fix_bad_email_domains.php`.** It rewrote `@bestlink.edu.ph` →
  `@gmail.com`, which is what made synthetic addresses look real. Now dry-run only.
- [DONE] **S6 — Cleanup script** `scripts/purge_fake_emails.php` (dry-run by default,
  `--run` to apply) clears the already-fabricated addresses from the DB.

## Phase 1 — Critical authentication

- [DONE] **C1 — Password reset requires a server-side grant.** `reset_password` used to
  accept a bare `user_id` + `new_password`: unauthenticated takeover of any account.
  Now gated on a single-use, expiring, hashed grant (`password_reset_grants`).
- [DONE] **C2 — OTP is never returned in an API response.** Four response sites leaked
  the plaintext code whenever mail delivery failed.
- [DONE] **C3 — `resend_otp` requires a pending grant / valid session** instead of
  accepting an arbitrary `user_id` + `purpose` and minting codes.
- [DONE] **C4 — Brute-force controls actually wired.** `handleFailedAttempt()`,
  `lockoutRemainingSeconds()`, `loginThrottleStatus()`/`Record()` and
  `OTP_MAX_VERIFY_ATTEMPTS` existed with **zero call sites**. All are now invoked.
- [DONE] **C5 — Password policy enforced on every set path** (was `strlen >= 6` on reset).
- [DONE] **C6 — Session invalidation on password change** via `users.password_changed_at`
  compared against `$_SESSION['login_time']`.
- [DONE] **C7 — No user enumeration.** Identical response for unknown user, wrong
  password and disabled account; a dummy `password_verify()` closes the timing oracle.
- [DONE] **C8 — `APP_ENV` defaults to `production`** (fail closed). The JWT/KIOSK
  secret guard now exempts CLI runs only, so maintenance scripts and the test
  suite still work while any HTTP-serving process refuses to boot on an
  insecure default.
- [DONE] **C9 — `registrar/smtp-debug.php` deleted.** It printed every SMTP secret in
  cleartext to any unauthenticated visitor.
- [DONE] **C10 — Secrets removed from `Dockerfile`** and from `docker-compose.yml`
  defaults. The app now refuses to boot on placeholder secrets.
- [DONE] **C11 — Live credential scrubbed** from `gmail-oauth-setup.php`.

## Phase 2 — Authorization (next)

- [TODO] **A1** — `api/ai-tools.php`: `case_brief`, `student_risks`, `profile` accept any
  `student_id`. A `student` session can read any other student's record. **IDOR.**
- [TODO] **A2** — `api/ai-assist.php` `findDuplicateStudents()` discloses cross-student PII.
- [TODO] **A3** — CSRF guard missing in `api/clinic-incidents.php`, `api/clinic-supplies.php`,
  `api/mock/payment.php`, `api/mock/lalamove.php`.
- [TODO] **A4** — `delete-guardian` in `api/students.php` lacks an ownership predicate.

## Phase 3 — Files & exports

- [TODO] **F1** — Uploaded files are served **directly by Apache from the webroot** with no
---

## What is already genuinely good

Do not regress these. They were verified as working:

| Control | Where | Note |
|---|---|---|
| SQL injection defence | `shared/database.php` | Prepared statements, `ATTR_EMULATE_PREPARES => false`. No injectable sink found. |
| Password hashing | `auth_security.php`, `functions.php` | `password_hash` / `password_verify`. |
| OTP storage | `auth_security.php` | `random_int()`, bcrypt at rest, TTL, single-use. |
| Session hardening | `session_config.php` | `use_strict_mode=1`, `session_regenerate_id(true)`, 20-min idle timeout. |
| CSRF mechanism | `csrf_guard.php` | Per-session `random_bytes(32)`, `hash_equals()`, no GET-param source. |
| Command injection | `document_reader.php`, `student_template.php` | All `escapeshellarg()`; tool names from hardcoded lists. |
| Upload script execution | `uploads/**/.htaccess`, `docker/deny-php.htaccess` | `Require all denied` + `php_flag engine off`. |
| CSP | `security_headers.php` | `default-src 'self'`, `frame-ancestors 'none'`. |
| Audit trail | `functions.php` `logActivity()` | Login, logout, OTP, resets, writes. |

---

## How to verify Phase 0 + Phase 1 locally

### Step 0 — one-time: apply the migration

```powershell
cd C:\xampp\htdocs\registrar-ai-system
C:\xampp\mysql\bin\mysql.exe -u root registrar_ai < migrations\security_hardening_phase1.sql
```

Without this the reset flow cannot work and the verifier reports FAILs.

### Step 1 — automated checks (30 seconds)

```powershell
C:\xampp\php\php.exe scripts\verify_phase01.php
```

Read-only. It checks the schema, proves the fabricated mailboxes are gone,
exercises the real reset-grant and lockout helpers inside a rolled-back
transaction, and reports which mail transport is actually live.
Expected: `PASS: 19   FAIL: 0`.

### Step 2 — unit tests

```powershell
C:\xampp\php\php.exe vendor\phpunit\phpunit\phpunit --no-coverage tests\AuthHardeningTest.php
```

Expected: `OK (15 tests, 71 assertions)`.

### Step 3 — manual browser tests

1. **No code leaks to the screen.** Click *Forgot password*, enter your email,
   then open DevTools → Network → find the `forgot` call. The response JSON
   must contain `masked_email` and `delivered` but **no `otp` field**.
2. **Reset actually works.** Complete the reset with a real code. The new
   password must satisfy the policy (12+ chars, mixed case, digit, symbol).
3. **Replay is refused.** Immediately resubmit the *same* reset request. It must
   fail — the grant is single-use.
4. **Session dies with the password.** Stay logged in on one browser (Student
   Portal). Change the password in a second (private window). The first session
   must be bounced to login on its next click.
5. **Lockout works.** Sign in with a wrong password 5 times. The 6th attempt —
   even with the *correct* password — must be refused for ~10 minutes.
6. **No bounce for the fixed account.** Open a student with no real email and
   resend the welcome email. It must not attempt delivery (nothing arrives, and
   no bounce is generated either).
7. **Secret dumper is gone.** Visit
   `http://localhost/registrar-ai-system/registrar/smtp-debug.php` → 404.

### Step 4 — clean up the old fabricated address

```powershell
C:\xampp\php\php.exe scripts\purge_fake_emails.php          # preview
C:\xampp\php\php.exe scripts\purge_fake_emails.php --run    # apply
```

These must be done in the hosting control panel:

1. **Rotate every mail credential.** A Gmail App Password was committed in
   `gmail-oauth-setup.php` and `smtp-debug.php` printed secrets to anonymous visitors.
   Treat SMTP / Gmail / Brevo keys as compromised.
2. **Set the Brevo variables** so mail leaves via Brevo instead of `smtp.gmail.com`:
   `BREVO_API_KEY`, and `MAIL_FROM` set to an address **verified in Brevo**.
3. **Apply the migration:** `migrations/security_hardening_phase1.sql`.
4. **Set strong secrets** — `JWT_SECRET`, `KIOSK_ACCESS_TOKEN`
   (`openssl rand -hex 32`). The placeholders are now rejected at boot.
5. **Confirm `APP_ENV=production`** in the hosting environment.

## Architecture decision — microservices: **NO**

~139 PHP files, one MariaDB, one team, one deploy. Splitting now would distribute an
unfixed auth bug across seven network boundaries instead of removing it. The correct next
step is a **modular monolith**: one bootstrap, `api/<module>/` namespaces, a
`shared/contracts/` facade layer, and no cross-module `include`s. The only genuine
extraction seam is AI inference (`shared/ai_client.php`) — extract that last, behind a
queue. Revisit when a measurable bottleneck or a second team appears.
  authorisation; names are `<id>_<unixtime>_<name>`, both halves guessable.
- [TODO] **F2** — No `finfo` signature validation on upload (extension-only).
- [TODO] **F3** — CSV formula injection missing in `registrar/students.php:2465`
  (`csvCell()` already exists in `masterlist.php` and should be reused).
- [TODO] **F4** — `api/documents.php` delete path bypasses `storedFileDiskPath()`.

## Phase 4 — Defence in depth

- [TODO] **D1** — 36 of 46 `api/*.php` include `session_config.php` without
  `security_headers.php`, so cookie flags silently fall back to php.ini defaults.
- [TODO] **D2** — Global `e()` output helper; escaping is currently opt-in per call site.
- [TODO] **D3** — Whitelist identifiers inside `Database::insert/update/delete`
  (raw interpolation is safe today but fragile).
- [DONE] **D4** — Security regression tests: `tests/AuthHardeningTest.php`
  (15 tests, 71 assertions) pins every Phase 0/1 defect above.
  Existing suite verified unchanged against a stashed baseline.
- [KNOWN-PRE-EXISTING] `tests/DocumentTemplateTest.php` has 4 errors
  (`Call to undefined function app_url()` from `shared/document_templates.php:551`).
  Unrelated to security work; present before these changes.