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

The registrar was seeing `550 5.1.1 NoSuchUser` for `student_1660_260929@gmail.com`.
The root cause was **not** a missing email field — it was a **UNIQUE collision**:

- Student #1660 (Cathy Tenco) was enrolled with the real address
  `roldantiu89@gmail.com`, which was saved correctly on `students.email`.
- User #3 (`ADM-002`, an admin) already held that same address, and
  `users.email` carried a `UNIQUE` index.
- When the portal account was auto-created, the uniqueness check failed and the
  code **silently replaced the real address** with a fabricated one. The `_260929`
  suffix is `date('ymd')` — the collision-retry marker.
- The fabricated mailbox never existed, so every welcome email bounced, and the
  bounce came back to the sender because `MAIL_FROM` is that same address.

The defect is that **email is not a unique identity in a school**: families
share addresses, and a student may later become staff.

- [DONE] **S0 — `users.email` is no longer UNIQUE** and is now nullable.
  Step 6 of the migration drops the index and widens the column to `varchar(190)`
  (190 × 4 = 760 bytes, inside InnoDB's 767-byte utf8mb4 limit).
- [DONE] **S0a — A shared address is kept as-is.** The uniqueness pre-check that
  discarded it is removed from `shared/functions.php`.
- [DONE] **S0b — NULL means "no usable address on file"** and replaces the
  `no-email-<id>@invalid.example` sentinel. NULL is honest; a fake domain is not.
- [DONE] **S0c — Shared-address lookups are deterministic.** `resolveLoginUser()`
  now resolves username/student-number first and orders the email branch by
  `id ASC`, so a shared address can never resolve to the wrong (e.g. admin)
  account. Same for the password-reset lookup.
- [DONE] **S1 — Stop fabricating addresses when none is on file.** Accounts with
  no real address no longer trigger a welcome mail; credentials are surfaced in
  the UI for manual hand-off.
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

## Phase 2 — Authorization

- [DONE] **A1 — `api/ai-tools.php` IDOR closed (CWE-639).** The endpoint is registrar
  analytics and previously accepted any authenticated session, role-checking only 3 of
  11 actions. A logged-in **student** could read any other student's status evidence,
  GWA history and profile. Now an allow-list (`admin/registrar/staff`) gates the whole
  file, so a future action cannot default to open. Allow-listing roles rather than
  deny-listing actions is deliberate: it fails closed as the file grows.
- [DONE] **A2 — `check_duplicate` gated.** It returned real rows (name, student number,
  birth date) for fuzzy name matches, disclosing cross-student PII to any session.
  Now staff-tier only.
- [DONE] **A3 — CSRF guard added** to `api/clinic-incidents.php`,
  `api/clinic-supplies.php`, `api/mock/payment.php` and `api/mock/lalamove.php` — all
  accept POST/DELETE and previously loaded no guard. `security_headers.php` added to
  match.
- [DONE] **A3b — CORS wildcards removed** from both `api/mock/*` endpoints in favour of
  the existing `corsSameOrigin()`. Include order fixed so `config.php` loads first.
- [DONE] **A4 — `delete-guardian` scoped by `student_id`**, matching the update path a
  few lines above it. It previously deleted by `id` alone, so any guardian row in the
  table could be removed (CWE-639).

## Phase 3 — Files & exports

- [DONE] **F1 — Uploaded files no longer readable over the web.**
  `uploads/student_files/` and `uploads/document_requirements/` are now
  `Require all denied`; the old `.htaccess` blocked script *execution* but never
  *reading*, so a PSA birth certificate was fetchable by anyone who guessed
  `<student_id>_<unixtime>_<name>`. New `api/file-download.php` authorises then streams:
  session required, students restricted to their own records, forced
  `Content-Disposition: attachment`, `nosniff`, and a neutral `Content-Type` so a stored
  `.txt`/`.svg` cannot execute in the app origin. Avatars (`assets/uploads/students`) and
  ID QR codes (`uploads/ids`) are deliberately **not** blocked — they render inline.
- [DONE] **F2 — Upload signature validation.** `validateUploadSignature()` checks real
  magic bytes with `finfo` and refuses content that contradicts its extension, with an
  explicit branch for executable payloads. Wired into `api/documents.php` and
  `api/student-documents.php`. Container formats (doc/xls/zip) stay extension-validated,
  since finfo reports them generically and strict checking would reject real documents.
- [DONE] **F3 — CSV formula injection fixed** in `registrar/students.php`. The export
  built CSV by raw concatenation with no quoting and no `= + - @` guard, unlike
  `masterlist.php` which already had `csvCell()`. Now RFC 4180 quoted with the same
  guard, plus a BOM so Excel reads UTF-8 names correctly.
- [DONE] **F4 — Document delete no longer builds paths by hand.** It used
  `__DIR__ . '/../' . ltrim($file_path, './')`, which does no traversal normalisation.
  Now resolves through `storedFileDiskPath()` with a `realpath` boundary re-asserted
  immediately before `unlink()`.

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

## How to verify locally (all phases)

One command runs every layer:

```powershell
C:\xampp\php\php.exe scripts\test_security.php
```

Apache and MySQL must be running. It resets the login counters, then runs:
`verify_phase01.php` → `tests/AuthHardeningTest.php` → `security_attack_sim.php`,
and prints a single verdict. Exit code 0 means everything passed.

### Step 0 — one-time: set local secrets + apply the migration

`APP_ENV` now defaults to `production`, so a missing secret stops the app with:

```
Server configuration error: JWT_SECRET is not set. Set it before going live.
```

That is the fail-closed guard doing its job. On local XAMPP, set the secrets in
`shared/secrets.local` (already gitignored via the `*.local` rule):

```
JWT_SECRET=<64 hex chars>
KIOSK_ACCESS_TOKEN=<64 hex chars>
MAIL_BOUNCE_TOKEN=<64 hex chars>
```

Generate a value with:

```powershell
C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

`shared/config.php` resolves secrets in this order:

1. a real environment variable (cPanel / Docker `.env`)
2. `shared/secrets.local`
3. **fail closed** — any HTTP request in production mode aborts

On your real host, prefer the hosting panel's environment variables and leave
`secrets.local` alone. Never commit either.

Then apply the migration:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root registrar_ai < migrations\security_hardening_phase1.sql
```

Without it the reset flow cannot work and the verifier reports FAILs.

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

Expected: `OK (27 tests, 136 assertions)`.

### Step 2b — live attack simulation (recommended)

Unit tests prove the logic. This proves the **running server** refuses the
attacks, by actually attempting them over HTTP:

```powershell
C:\xampp\php\php.exe scripts\security_attack_sim.php
```

Apache and MySQL must be running. Expected: `BLOCKED: 14   VULNERABLE: 0`.

It is read-only — no account is taken over and no password is changed. It
attempts, and expects to fail:

| # | Attack | Result it must get |
|---|---|---|
| 1 | Take over any account via `reset_password` + bare `user_id` | refused — no grant |
| 1b | Same, with a forged 64-char `reset_token` | refused — invalid grant |
| 2 | Read the OTP out of the `forgot` response | no `otp` field |
| 3 | Mint/mail-bomb codes via `resend_otp` | refused — session-bound |
| 4 | 8 wrong passwords | throttled at attempt 6 |
| 4b | Compare a real vs fake account message | identical |
| 5 | Cross-site POST with no CSRF token | refused |
| 6 | Fetch `registrar/smtp-debug.php` | HTTP 404 |
| 7 | Set a 6-character password via reset | policy refuses it |

Because it deliberately trips the login throttle, reset the counters before
re-running:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root registrar_ai -e "UPDATE users SET login_attempts=0, locked_until=NULL; DELETE FROM login_attempts;"
```

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