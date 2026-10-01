# =============================================================================
#  Registrar AI System â€” deployment image
#
#  The PHP extensions are NOT compiled here. They come prebuilt in
#  ghcr.io/rekusissu/php-8.2-apache-ext (built by CI from
#  docker/php-extensions.Dockerfile). That keeps the platform build fast â€”
#  just COPY steps â€” which is essential on managed Docker hosts that kill
#  long builds at their timeout.
#
#  Build:
#    docker build -t ghcr.io/rekusissu/registrar-ai:latest .
# =============================================================================

# Tag of the prebuilt extension layer (bumped only when the extension set
# changes). Push ghcr.io/rekusissu/php-8.2-apache-ext:<tag> first (CI does).
ARG PHP_EXT_TAG=php-8.2
FROM ghcr.io/rekusissu/php-8.2-apache-ext:${PHP_EXT_TAG} AS vendor

# Composer needs the unzip binary to download dist archives.
# We only install the minimal tool â€” all PHP extensions (pdo_mysql, mysqli,
# mbstring, gd, curl, zip, opcache) are already compiled in the base image.
# Cache mounts keep apt lists and Composer downloads across rebuilds.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    apt-get update -o Acquire::Retries=5 -o Acquire::http::Timeout=30 \
    && apt-get install -y --no-install-recommends unzip

# Composer production dependencies. The vendor stage uses the SAME prebuilt
# runtime so Composer's platform checks match exactly.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /build
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader

# â”€â”€ Runtime (final image) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
FROM ghcr.io/rekusissu/php-8.2-apache-ext:${PHP_EXT_TAG}

# Extensions are pre-compiled in the base image â€” no apt-get or
# docker-php-ext-install needed here. This keeps the build to COPY-only.

ENV APP_ENV=production

# Production secrets.
#
# Declared as ARGs with a placeholder default so the image always BUILDS on any
# platform. The placeholder is never a usable secret: shared/config.php refuses to
# serve an HTTP request when APP_ENV=production and either of these is missing or
# still a placeholder, and docker/entrypoint.sh warns loudly at boot.
#
# A PREVIOUS VERSION failed the BUILD on a placeholder. That was wrong: managed
# platforms (Hostinger Cloud, most CI builders) inject environment variables at
# RUNTIME, not as build args, so the value does not exist yet during docker build.
# Failing there made the image unbuildable on exactly the platform that needed it,
# and pushed people toward committing a real secret back into this file - the very
# thing the placeholder exists to prevent.
#
# Supply real values at runtime (hosting panel env vars, or -e flags):
#     JWT_SECRET=$(openssl rand -hex 32)
#     KIOSK_ACCESS_TOKEN=$(openssl rand -hex 32)
ARG JWT_SECRET_ARG=REPLACE_ME_AT_BUILD_TIME
ARG KIOSK_ACCESS_TOKEN_ARG=REPLACE_ME_AT_BUILD_TIME
ENV JWT_SECRET=${JWT_SECRET:-${JWT_SECRET_ARG}}
ENV KIOSK_ACCESS_TOKEN=${KIOSK_ACCESS_TOKEN:-${KIOSK_ACCESS_TOKEN_ARG}}

# The runtime check lives in docker/entrypoint.sh, which runs after the platform
# has injected the environment.

# Apache vhost: serve the app from the web root and honor .htaccess
# (the app depends on mod_rewrite for /verify/<hash> pretty URLs).
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

# Guard templates re-seeded into (possibly empty) upload/log volumes at boot.
COPY docker/deny-php.htaccess /usr/local/share/registrar-templates/deny-php.htaccess
COPY docker/deny-all.htaccess /usr/local/share/registrar-templates/deny-all.htaccess
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Composer: NOT baked into the production image (~10 MB saved).
# Dev mode (RUN_COMPOSER_ON_BOOT=1) installs it on-the-fly in entrypoint.sh.

# Application source + ready-to-run vendor.
COPY --from=vendor /build/vendor /var/www/html/vendor
COPY . /var/www/html/

# The app writes to uploads/ and logs/ at runtime. Apache's master runs as
# root (binds :80) and its workers run as www-data, so make those paths
# www-data-writable. The entrypoint keeps them correct inside volumes.
# mkdir -p is required: .dockerignore excludes logs/ (and its subpaths of
# uploads/), so these dirs may not exist in the image until we create them.
RUN set -e \
    && mkdir -p \
        /var/www/html/uploads \
        /var/www/html/logs \
        /var/www/html/assets/uploads/students \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/logs /var/www/html/assets/uploads \
    && chmod -R u+rwX,g+rwX /var/www/html/uploads /var/www/html/logs /var/www/html/assets/uploads

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
