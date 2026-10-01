# Pre-Deployment Checklist

> Do these in order. Steps 1-5 are **required**; without them the app either fails to
> boot or silently loses every security control added in the four remediation phases.
> Written 2026-10-01.

---

## The three things that will silently break your deploy

Each of these fails **quietly** — the site loads, but without the protection you think
you deployed.

### 1. No PHP code reads a `.env` file

Verified: nothing in `shared/*.php` references `.env`. `env()` in
`shared/config.php:309` checks only `getenv()`, `$_ENV`, `$_SERVER`, `apache_getenv()`.

A `.env` file in the webroot is **inert**. `docker-compose` reads it and injects the
values as container env vars — but on shared hosting there is no compose step, so a
`.env` file does nothing.

> **If you created a `.env` file in your hosting panel and nothing changed, this is
> why.** Use the panel's *Environment Variables* feature, or `shared/secrets.local`.

### 2. Two secrets have NO file fallback

| Secret | File fallback | If missing |
|---|---|---|
| `JWT_SECRET`, `KIOSK_ACCESS_TOKEN` | `shared/secrets.local` | Site aborts with a clear message (good) |
| Mail (`BREVO_API_KEY`, `SMTP_*`, `MAIL_FROM`) | `shared/email_secret.local` | `EMAIL_CONFIGURED=false`, **every email silently fails** |
| `DB_*`, `APP_ENV`, `SESSION_IDLE_TIMEOUT` | **none** | Falls back to `localhost` / `root` / **empty password** |

That last row is why a DB guard was added: previously a missing `DB_PASSWORD` was
invisible until the first query, and could connect to the **wrong database**.

### 3. The schema dump does NOT contain the new tables

`registrar_ai.sql` has **none** of: `password_reset_grants`, `users.password_changed_at`,
`otp_codes.verify_attempts`, `email_bounced_at`.

If you seed a fresh database from that dump, **run the migration afterwards** or the
reset flow and session invalidation will not work.

---

## Step 1 — Apply the database migration (REQUIRED)

Take a backup first, then apply `migrations/security_hardening_phase1.sql`.

**phpMyAdmin** → select your database → **Import** → choose the file → Go

**or** via CLI:
```bash
mysql -u YOUR_DB_USER -p YOUR_DB_NAME < migrations/security_hardening_phase1.sql
```

**Verify** — each should return a result, not an error:
```sql
SHOW TABLES LIKE 'password_reset_grants';
SHOW COLUMNS FROM users LIKE 'password_changed_at';
SHOW COLUMNS FROM otp_codes LIKE 'verify_attempts';
SHOW COLUMNS FROM students LIKE 'email_bounced_at';
```

> Check too whether `add_previous_school_fields.sql` and the `rename_course_to_program`
> change are applied — they are also absent from the dump.

---

## Step 2 — Set the required environment variables

In your hosting panel (cPanel / Hostinger → Environment Variables):

| Variable | Required | Value |
|---|---|---|
| `APP_ENV` | **Yes** | `production` |
| `DB_HOST` | **Yes** | `localhost`, or the add-on hostname |
| `DB_NAME` | **Yes** | your database name |
| `DB_USER` | **Yes** | a **non-root** user you create |
| `DB_PASSWORD` | **Yes** | a strong password |
| `DB_PORT` | If non-standard | e.g. `3307` (Hostinger MySQL often is) |
| `JWT_SECRET` | **Yes** | `openssl rand -hex 32` |
| `KIOSK_ACCESS_TOKEN` | **Yes** | `openssl rand -hex 32` |
| `SESSION_IDLE_TIMEOUT` | No | `1200` |
| `BREVO_API_KEY` | For email | your Brevo key |
| `MAIL_FROM` | For email | a sender **verified in Brevo** |
| `MAIL_FROM_NAME` | No | `BCP Registrar System` |
| `MAIL_BOUNCE_TOKEN` | Optional | `openssl rand -hex 32` — enables the bounce endpoint |
| `NINEROUTER_URL`, `AI_API_KEY` | Optional | your AI gateway |
| `PAYMONGO_*` | Optional | only for real GCash |

Generate a secret in PowerShell:
```powershell
C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

**Do NOT set** `DB_ALLOW_INSECURE_DEFAULTS=true` on the server — local-only.

**Verify they reach PHP.** Temporarily create this probe, open it, then **delete it**:

```php
<?php // _probe.php  -- DELETE ME
foreach (['APP_ENV','DB_HOST','DB_NAME','DB_USER','DB_PASSWORD','JWT_SECRET','BREVO_API_KEY'] as $k) {
    $v = getenv($k) ?: ($_SERVER[$k] ?? ($_ENV[$k] ?? ''));
    echo $k, ' = ', ($v === '' ? 'NOT SET' : 'set (len ' . strlen($v) . ')'), PHP_EOL;
}
```

Anything showing `NOT SET` is not reaching PHP — fix it before continuing.
---

## Step 3 — Create `shared/secrets.local` on the server

If your panel's env vars don't work (common on shared hosting where `getenv()` is
disabled), create `shared/secrets.local`:

```
JWT_SECRET=<64 hex chars>
KIOSK_ACCESS_TOKEN=<64 hex chars>
```

**Do not include `DB_ALLOW_INSECURE_DEFAULTS`** on the server.

Set permissions to `600`, then confirm it is not web-readable:
`https://yourdomain/shared/secrets.local` must return **403/404**, not the contents.

---

## Step 4 — Confirm HTTPS is actually on

`Secure` session cookies and HSTS only engage over HTTPS. On plain HTTP the app works,
but your session cookie travels in cleartext.

- `https://yourdomain/` must load
- The browser padlock must be present
- `registrar/smtp-status.php` (admin only) confirms your mail config

---

## Step 5 — Files, permissions, and the deny rules

These are dotfiles and are easily lost in a partial upload. Confirm all exist:

```
uploads/.htaccess
uploads/student_files/.htaccess         <- must now be "Require all denied"
uploads/document_requirements/.htaccess  <- must now be "Require all denied"
uploads/ids/.htaccess
uploads/document_pdfs/.htaccess
uploads/ai_docs/.htaccess
uploads/students/.htaccess
assets/uploads/students/.htaccess
logs/.htaccess                           <- "Require all denied"
```

**`AllowOverride` must be enabled**, or these are silently ignored. Test it:
```
https://yourdomain/uploads/student_files/1/<any real filename>
```
must return **403**. If it returns 200, your students' files are publicly readable —
enable `AllowOverride` or block the directory at the host level.

Permissions: `uploads/` and `logs/` writable by the PHP user; everything else read-only;
`shared/secrets.local` is `600`.

---

## Step 6 — Post-deploy smoke test

```powershell
# 1. App boots (NOT the fail-closed message)
curl -I https://yourdomain/login.php

# 2. Session cookie carries protection
curl -sI https://yourdomain/login.php | findstr /I "set-cookie"
#    expect: HttpOnly; SameSite=Lax; Secure

# 3. Uploaded files are NOT publicly readable
curl -I https://yourdomain/uploads/student_files/1/1_1790268412_download.jpg
#    expect: 403

# 4. Secret dumper is gone
curl -I https://yourdomain/registrar/smtp-debug.php
#    expect: 404

# 5. Download endpoint requires a session
curl -I "https://yourdomain/api/file-download.php?id=1"
#    expect: 401
```

Then in the browser:

1. Log in as **admin**, then as a **student**
2. Forgot password → request a code → **confirm it arrives** (proves Brevo works)
3. Reset with the real code → should succeed
4. Re-submit the same reset → must be **refused** (the grant is single-use)
5. As a student, open **Documents** → download your own file → must work
6. Try to open or edit another student's record → must be **refused**
7. Leave a session idle for 20 minutes → auto-logout

---

## Step 7 — Housekeeping

- [ ] **Make the GitHub repo private** — it is currently public
- [ ] Confirm no synthetic addresses remain (`php scripts/purge_fake_emails.php`)
- [ ] Delete `_probe.php` (Step 2)
- [ ] Optionally delete `registrar/smtp-status.php` from the deploy (admin-only, but it
      confirms mail configuration)
- [ ] Backups scheduled **and** a restore tested
- [ ] `logs/php_errors.log` is not web-readable

---

## If something fails

| Symptom | Cause | Fix |
|---|---|---|
| `Server configuration error: JWT_SECRET is not set` | env vars not reaching PHP | Step 2 probe, then Step 3 |
| `database credentials are not configured` | `DB_PASSWORD` empty, or user still `root` | set real `DB_*` env vars |
| `Access denied for user ...` | wrong password, or wrong port | Hostinger MySQL is often port **3307** |
| Login works, no email arrives | Brevo key unset, or sender unverified | `registrar/smtp-status.php` (admin) |
| Emails bounce `550 NoSuchUser` | stale synthetic address in the DB | `php scripts/purge_fake_emails.php --run` |
| An uploaded file returns **200** anonymously | `AllowOverride` off, so `.htaccess` ignored | Step 5 — block at host level |
| Login cookie has no `HttpOnly` | stale deploy or opcache | Step 6 check 2; restart PHP-FPM |
| Reset says "invalid or expired" | migration not applied | Step 1 |