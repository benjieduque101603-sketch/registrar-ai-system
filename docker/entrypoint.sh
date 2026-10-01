#!/usr/bin/env sh
# =============================================================================
#  Registrar AI System — container entrypoint
#
#  Responsibilities (runs as root, then hands off to Apache as root so the
#  master can bind :80; workers drop to www-data automatically):
#    1. Create the writable runtime dirs (uploads/, logs/) and re-seed their
#       `.htaccess` "deny script execution" guards whenever they were hidden
#       behind a fresh, empty Docker volume.
#    2. Make those dirs writable by the www-data Apache worker.
#    3. Optional: run `composer install` on first boot (used by the DEV
#       bind-mount override where the host's gitignored vendor/ is absent).
#    4. exec Apache so it stays PID 1 and receives signals/stop cleanly.
# =============================================================================
set -e

WWW=/var/www/html
TEMPLATES=/usr/local/share/registrar-templates
DENY_PHP=.htaccess

# Directory layout relative to $WWW that must exist and be writable.
RUNTIME_DIRS="
uploads
uploads/students
uploads/ids
uploads/document_requirements
uploads/document_pdfs
uploads/ai_docs
assets/uploads/students
logs
"

echo "[entrypoint] preparing runtime directories…"

# -- Secret sanity check (runtime, not build time) ----------------
# Managed platforms inject environment variables at RUNTIME, so this is
# the first point at which the real values exist. It WARNS rather than
# exits: shared/config.php is the component that actually refuses to
# serve requests with a missing or placeholder secret, and hard-failing
# here would take down a dev container that has no secrets at all.
#
# Previously this check lived in the Dockerfile as a build-time RUN, which
# was wrong on two counts: the value does not exist yet during
# `docker build`, and failing there pushed people toward committing a real
# secret into the Dockerfile.
for pair in "JWT_SECRET:$JWT_SECRET" "KIOSK_ACCESS_TOKEN:$KIOSK_ACCESS_TOKEN"; do
  key="${pair%%:*}"
  val="${pair#*:}"
  case "$val" in
    ""|REPLACE_ME*|*placeholder*|change-me*|kiosk-tap*)
      echo "[entrypoint] WARNING: ${key} is unset or still a placeholder." >&2
      echo "[entrypoint]          The app will refuse to serve requests until it is set." >&2
      ;;
  esac
done

for d in $RUNTIME_DIRS; do
  target="$WWW/$d"
  mkdir -p "$target"
  # Re-seed the script-execution guard if the volume shadowed the image copy.
  if [ ! -f "$target/$DENY_PHP" ]; then
    cp "$TEMPLATES/deny-php.htaccess" "$target/$DENY_PHP"
  fi
  chown -R www-data:www-data "$target" 2>/dev/null || true
  chmod -R u+rwX,g+rwX "$target" 2>/dev/null || true
done

# The logs volume must never be served over the web.
if [ ! -f "$WWW/logs/$DENY_PHP" ]; then
  cp "$TEMPLATES/deny-all.htaccess" "$WWW/logs/$DENY_PHP"
fi
chown www-data:www-data "$WWW/logs/$DENY_PHP" 2>/dev/null || true

# Dev-only: install Composer deps when the source is bind-mounted and the
# host's vendor/ (which is gitignored) isn't present yet.
if [ "${RUN_COMPOSER_ON_BOOT:-0}" = "1" ] && [ ! -f "$WWW/vendor/autoload.php" ]; then
  # Composer is not baked into the production image (~10 MB saved).
  # Download it on-the-fly for dev mode only.
  if ! command -v composer >/dev/null 2>&1; then
    echo "[entrypoint] installing composer…"
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer 2>/dev/null \
      || echo "[entrypoint] warning: composer install failed, continuing anyway."
  fi
  if command -v composer >/dev/null 2>&1; then
    echo "[entrypoint] vendor/ missing — running composer install…"
    ( cd "$WWW" && composer install --no-interaction --no-progress --prefer-dist ) \
      || echo "[entrypoint] warning: composer install failed, continuing anyway."
  fi
fi

# ── PaaS port support ──────────────────────────────────────────────────────
# Managed platforms set $PORT and health-check that container port. Apache
# defaults to :80, so with $PORT set it was listening on the wrong port and
# the platform reported "not accepting connections on port <PORT>".
#
# Bind BOTH ports when $PORT is provided: the vhost accepts multiple
# addresses, so one <VirtualHost *:80 *:$PORT> serves either. That way the
# platform's health check works whether it probes $PORT or the mapped :80,
# and the docker-compose / local setup (no $PORT) is completely unchanged.
if [ -n "${PORT:-}" ] && [ "$PORT" != "80" ]; then
  echo "[entrypoint] \$PORT=$PORT detected — Apache will also listen on it"
  if ! grep -qE "^Listen ${PORT}\$" /etc/apache2/ports.conf; then
    printf 'Listen %s\n' "$PORT" >> /etc/apache2/ports.conf
  fi
  sed -i -E "s|<VirtualHost \*:80>|<VirtualHost *:80 *:${PORT}>|" \
      /etc/apache2/sites-available/000-default.conf
fi

# ── Database seed ────────────────────────────────────────────────────────────
# On a fresh database (no tables) import registrar_ai.sql so the app
# works out-of-the-box. Safe: checks for an existing table first.
echo "[entrypoint] checking database…"
if command -v php >/dev/null 2>&1; then
  php /var/www/html/seed.php 2>/dev/null && echo "[entrypoint] database seed complete." \
    || echo "[entrypoint] seed skipped (db may not be ready or already has tables)."
fi

echo "[entrypoint] starting Apache…"
exec apache2-foreground
