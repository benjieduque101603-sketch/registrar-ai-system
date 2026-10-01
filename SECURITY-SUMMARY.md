# Security Summary - BCP Registrar System

> What protections this system has, why each exists, how it works, and which attack it
> stops. Written 2026-10-01 after a full audit and four remediation phases.
>
> Companions: `SECURITY-ROADMAP.md` (status of each fix), `SECURITY_CHECKLIST.md`
> (the original checklist - it was over-optimistic).

---

## The one-sentence version

**No anonymous visitor can change anything; no logged-in user can read data outside
their role; and every sensitive byte - passwords, files, records - passes through a
check before it is used.**

---

## 1. Authentication - "prove who you are"

### Password storage
**What:** passwords are never stored. They are hashed with bcrypt via `password_hash()`
and checked with `password_verify()`.
**Where:** `shared/auth_security.php`, `shared/functions.php`
**Why:** if the database is ever stolen (backup leak, SQL injection, a curious
registrar), the attacker gets hashes, not passwords. Bcrypt is deliberately slow, so
cracking a stolen dump costs real time per guess.
**Stops:** credential disclosure from a database breach.

### Password policy
**What:** minimum length (12 in production), requires uppercase, lowercase, a digit and a
symbol, rejects predictable passwords (`password1`, `admin123`) and any password
containing the user's own name or email.
**Where:** `shared/password_policy.php`
**Why:** length and unpredictability matter far more than symbol-count rules.
**Stops:** weak passwords being chosen.

### Multi-factor (OTP)
**What:** a 6-digit code from `random_int()`, stored as a **bcrypt hash**, expiring in 5
minutes, single-use, capped at 3 verification attempts.
**Where:** `shared/auth_security.php`
**Why:** it is a second factor, so a stolen password alone is not enough. Hashing it
means a database read does not reveal live codes.
**Stops:** takeover from a leaked password.

### Account lockout + IP throttle
**What:** 5 failed logins locks the account for 10 minutes. Separately, 5 failures from
one IP in 15 minutes blocks that IP for 15 minutes.
**Where:** `shared/auth_security.php` + `shared/login_throttle.php`
**Why:** makes online guessing impractical. Two layers, because a distributed attacker can
spread attempts across IPs, and lockout alone can be used to deny a real user access.
**Stops:** online brute force and credential stuffing.
> **Was broken, now fixed.** These functions existed but were **never called** - the
> original checklist claimed they worked. Brute force was completely unlimited.

### Password reset
**What:** a reset requires a **single-use grant**, created only after the emailed code is
verified. The grant is stored hashed, expires in 15 minutes, cannot be reused, and
changing the password invalidates every session issued before the change.
**Where:** `shared/auth_actions.php`, `shared/auth_security.php`, `shared/session_config.php`
**Why:** applies OWASP's "create a limited session from the verified code" guidance to
storage and session handling.
**Stops:** takeover through the reset flow.
> **This was the most serious bug found.** The endpoint accepted a bare `user_id` plus
> `new_password` with no proof, so `POST action=reset_password&user_id=1` would have taken
> over the admin account. It also accepted any 6-character password.

### No user enumeration
**What:** unknown username, wrong password and disabled account all return the **same**
message and take the **same time** (a dummy `password_verify()` removes the timing
oracle).
**Where:** `shared/auth_actions.php`, `api/auth.php`
**Why:** different replies let an attacker build a list of valid usernames.
**Stops:** harvesting valid accounts.

---

## 2. Sessions - "stay logged in safely"

**Where:** `shared/session_config.php`
Cookie `BCP_REGISTRAR_SESSION`, `HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS,
`use_strict_mode` on, ID regenerated on login, 20-minute idle timeout.

| Flag | What it stops |
|---|---|
| `HttpOnly` | JavaScript reading the cookie, so an XSS bug cannot steal the session |
---

## 3. Authorization - "only touch what is yours"

**What:** role guards on every page and endpoint (`requireLogin()`, `requireRole()`,
`requireStudent()`) plus ownership checks, so a student reaches only their own records.
**Where:** `shared/session_config.php` and per-endpoint checks

**The pattern that matters:** the id a student acts on comes from the **session**, never
from the request. If the code takes `student_id` from the URL, changing the URL reads
someone else's record.

> **Two real IDORs found and fixed:**
> - `api/ai-tools.php` accepted any logged-in session and role-checked only 3 of 11
>   actions, so a **student could read any other student's** status evidence, grades and
>   profile. Now an allow-list gates the whole file.
> - `delete-guardian` deleted by `id` alone, while its own update path correctly scoped
>   by `student_id`.

**Allow-list, not deny-list:** the AI tools gate lists roles that *may* access, so a newly
added feature is closed by default rather than open by default.

---

## 4. Database - "make injection impossible"

**What:** every query uses PDO prepared statements with `?` placeholders, and
`ATTR_EMULATE_PREPARES` is **off** so parameterisation happens in MySQL, not in PHP.

```php
// Never:
$db->query("SELECT * FROM users WHERE email = '" . $_GET['email'] . "'");
// Always:
$db->fetchOne("SELECT * FROM users WHERE email = ?", [$email]);
```

**Why:** the placeholder is sent to MySQL separately from the SQL text, so input is
**data** and can never become **code**. No amount of quote-escaping achieves that.
**Stops:** SQL injection, otherwise the most common web vulnerability.

**Also:** `insert/update/delete` validate table and column names against a strict
pattern. PDO cannot bind identifiers, so those are interpolated; the whitelist turns a
future mistake into an immediate exception instead of silent injection.

---

## 5. Output escaping - "don't let data become code"

**What:** a shared `e()` helper using `ENT_QUOTES`.
**Where:** `shared/functions.php`
**Why:** a student record is attacker-influenced data. A name of
`<img src=x onerror=alert(1)>` renders as **live script** for every staff member who opens
the page - stored XSS. Escaping turns it back into text.
**Why `ENT_QUOTES`:** the default `htmlspecialchars` flags do **not** escape single
quotes, which is exactly what protects a single-quoted attribute.
**Stops:** stored and reflected XSS.

---

## 6. CSRF - "prove the request came from your page"

**What:** a per-session token (`random_bytes(32)`) that every state-changing request must
send, compared with `hash_equals()` in constant time. Tokens are **not** accepted from the
URL, because URLs leak through browser history, referrer headers and server logs.
**Where:** `shared/csrf_guard.php`

**Why:** without it, any site the registrar visits could silently POST to the system -
changing a status, deleting a record - using their session cookie.
**Stops:** cross-site request forgery.
> **Four endpoints accepted POST/DELETE with no CSRF guard at all** and now have one.

---

## 7. Files and uploads - "a file is data, never code"

| Control | How | Stops |
|---|---|---|
| **No direct web access** | `uploads/student_files/` and `document_requirements/` are `Require all denied` | Anyone guessing a filename reading a PSA birth certificate |
| **Authorised download** | New `api/file-download.php`: checks session, role, ownership, then streams | A student downloading another student's file |
| **Forced attachment** | `Content-Disposition: attachment` + `nosniff` + neutral `Content-Type` | A stored `.txt` or `.svg` executing in your domain |
| **Extension allowlist** | Only known-safe types | Uploading a `.php` |
| **Signature check** | `finfo` reads real magic bytes | A `.png` that is really PHP (a polyglot) |

**Why the signature check:** the file *name* is chosen by the attacker. Only the *content*
is evidence.

> The old `.htaccess` blocked script **execution** but never **reading** - a student file
> was fetchable by anyone who guessed `<student_id>_<unixtime>_<name>`, both halves of
> which are guessable.

---

## 8. Transport and headers - "secure the browser's context"

**Where:** `shared/security_headers.php`

| Header | Stops |
|---|---|
| `Content-Security-Policy` (`default-src 'self'`, `frame-ancestors 'none'`) | Injected scripts and clickjacking |
| `X-Frame-Options: DENY` | The app being framed to trap clicks |
| `X-Content-Type-Options: nosniff` | A browser re-interpreting a file as script |
| `Strict-Transport-Security` | Downgrade to HTTP on a public network |
| `Referrer-Policy` | Leaking student URLs to third parties |
| `Permissions-Policy` | Unused browser features (camera, mic, geolocation) |
| `corsSameOrigin()` | Any website reading authenticated API responses |

---

## 9. Secrets - "keep credentials out of the code"

**What:** secrets come from environment variables, falling back to a gitignored
`shared/secrets.local`. `JWT_SECRET` and `KIOSK_ACCESS_TOKEN` **fail closed** - an HTTP
request with them unset or placeholder aborts rather than running insecurely.
**Where:** `shared/config.php`
**Stops:** a deployment silently running with a publicly-known token.

> Removed: a committed Gmail App Password, two secrets baked into `Dockerfile` and
> `docker-compose.yml`, and `registrar/smtp-debug.php` - which printed every SMTP password
> in cleartext to any anonymous visitor.

---

## 10. Email integrity - "don't send to addresses that don't exist"

**What:** the system never invents a mailbox. No email on file means no mail attempted,
and the credentials are shown for manual hand-off. `users.email` is **no longer unique**,
because email is not a unique identity in a school. A Brevo webhook records hard bounces
so dead addresses stop being retried.
**Where:** `shared/functions.php`, `api/mail-bounce.php`, `scripts/purge_fake_emails.php`

> **This was your actual bug.** Student 1660's real Gmail was silently **discarded**
> because your own admin account already used it (`users.email` was `UNIQUE`), then
> replaced with `student_1660_260929@gmail.com`, which never existed - hence
> `550 5.1.1 NoSuchUser`.

---

## 11. Command execution - "no shell injection"

**What:** every `exec()` and `shell_exec()` wraps arguments in `escapeshellarg()`, and tool
names come from hardcoded candidate lists, never from input.
**Where:** `shared/document_reader.php`, `shared/student_template.php`
**Stops:** an uploaded filename being executed as a shell command.

---

## 12. Auditing - "know what happened"

**What:** `logActivity()` records logins, logout, OTP issue and verify, password resets,
status changes and record edits with the acting user id.
**Where:** `shared/functions.php`
**Why:** without it you cannot investigate an incident or prove who changed a record.
**Stops:** nothing directly - it is what makes the other controls accountable.

---

## How these map to OWASP Top 10 (2025)

| Risk | Your defences |
|---|---|
| A01 Broken Access Control | Role guards, ownership checks, `Require all denied`, authorised downloads |
| A02 Security Misconfiguration | Fail-closed secrets, cookie flags, deleted debug page, `.htaccess` guards |
| A03 Software Supply Chain Failures | `composer.lock` pinned; **repo is public - make it private** |
| A04 Cryptographic Failures | bcrypt, hashed tokens and grants, secrets from environment |
| A05 Injection | Prepared statements, `e()`, `escapeshellarg`, CSV formula guard, signature checks |
| A06 Insecure Design | Reset grants, lockout, bounce tracking, IDOR fixes |
| A07 Authentication Failures | OTP, lockout, throttle, password policy, no enumeration, session invalidation |
| A09 Logging Failures | `logActivity()` audit trail |
| A10 Mishandling of Exceptions | Generic client messages; real detail only in `logs/php_errors.log` |

---

## Verifying it yourself

```powershell
C:\xampp\php\php.exe scripts\test_security.php
```

Three layers, one command, exit code 0 means all passed:

1. **`verify_phase01.php`** - 19 checks on the database schema and helpers
2. **`AuthHardeningTest`** - 31 tests / 161 assertions pinning each defect
3. **`security_attack_sim.php`** - 14 real attacks over HTTP; expects every one to fail

Current status: **14 blocked, 0 vulnerable.**

---

## Remaining risks - read this before deploying

| # | Risk | Action |
|---|---|---|
| 1 | **Repo is public** on GitHub | Make it **private**. It exposes your schema, admin usernames and file layout even with secrets scrubbed. |
| 2 | **Two-student IDOR untested end-to-end** | Log in as two students and try to read each other's records. The code is gated; the end-to-end path is unverified. |
| 3 | **Docs may be stale** | `SECURITY_CHECKLIST.md` still claims "Production Ready". Treat `SECURITY-ROADMAP.md` as the truth. |
| 4 | **HTTPS assumed on the host** | `Secure` cookies and HSTS only engage over HTTPS. Confirm your vhost or proxy terminates TLS. |
| 5 | **No rate limit on the queue kiosk or RFID readers** | Public-facing endpoints with no throttle. Low risk on a campus network, worth reviewing. |
| 6 | **`DocumentTemplateTest` has 4 pre-existing errors** | `undefined function app_url()`. Unrelated to security, but it hides real failures in that file. |

**On microservices: still not recommended.** About 139 files, one database, one team. The
correct next step is a modular monolith (single bootstrap, `api/<module>/` namespaces, a
`shared/contracts/` facade, no cross-module includes). The only genuine extraction seam
is AI inference - and that should come last, not first.

**Where:** `shared/security_headers.php`

| Header | Stops |
|---|---|
| `Content-Security-Policy` (`default-src 'self'`, `frame-ancestors 'none'`) | Injected scripts and clickjacking |
| `X-Frame-Options: DENY` | The app being framed to trap clicks |
| `X-Content-Type-Options: nosniff` | A browser re-interpreting a file as script |
| `Strict-Transport-Security` | Downgrade to HTTP on a public network |
| `Referrer-Policy` | Leaking student URLs to third parties |
| `Permissions-Policy` | Unused browser features (camera, mic, geolocation) |
| `corsSameOrigin()` | Any website reading authenticated API responses |
| `SameSite=Lax` | Another site making your browser POST as you (CSRF) |
| `Secure` | The cookie being sent over plain HTTP where it can be sniffed |
| `use_strict_mode` | PHP accepting attacker-chosen session IDs |
| regenerate on login | Session fixation - attacker plants an ID, victim logs in, attacker inherits it |
| idle timeout | Damage from a session left open on an unattended PC |

**Also:** `password_changed_at` is compared against the session's `login_time` on every
request, so a password change kills every older session immediately.
**Stops:** session hijacking, fixation, and stolen cookies surviving a reset.

> **Recently fixed:** the cookie flags lived only in `security_headers.php`, so the **32
> API endpoints** that never included it issued cookies with no `HttpOnly` and no
> `SameSite`. They now live in `session_config.php` itself.