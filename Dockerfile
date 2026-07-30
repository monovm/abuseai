# syntax=docker/dockerfile:1

# =====================================================================
# Abuse AI — production container image
#
# Multi-stage build:
#   1. assets  — compiles the Vite/Tailwind frontend bundle
#   2. base    — PHP runtime (FrankenPHP) with all required extensions
#   3. vendor  — installs Composer dependencies against the real runtime
#   4. app     — final slim image (no Composer, no Node, no build tools)
#
# The same image runs three roles via the CONTAINER_ROLE env var:
#   app (web server), horizon (queue workers), scheduler (cron loop).
# =====================================================================

# ---- Stage 1: frontend assets ---------------------------------------
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
RUN npm run build

# ---- Stage 2: PHP runtime base --------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS base
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*
# intl is REQUIRED — do not remove it to speed up the build.
# Illuminate\Support\Number guards every method with extension_loaded('intl')
# and throws outright, so no polyfill can satisfy it (symfony/polyfill-intl-*
# covers idn/grapheme/normalizer, not NumberFormatter). `artisan db:show` calls
# Number::format() once the database has tables, which the entrypoint's
# readiness probe depends on — without intl the horizon and scheduler roles
# never start. Compiling it is slow but it is built on a native runner.
RUN install-php-extensions \
    pcntl \
    pdo_pgsql \
    pdo_mysql \
    redis \
    intl \
    zip \
    bcmath \
    exif \
    opcache
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-abuseai.ini"
WORKDIR /app

# ---- Stage 3: Composer dependencies ---------------------------------
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev --no-scripts --no-autoloader \
    --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize

# ---- Stage 4: final runtime image -----------------------------------
FROM base AS app
ENV APP_ENV=production \
    APP_DEBUG=false \
    CONTAINER_ROLE=app \
    SERVER_NAME=:80

COPY --from=vendor /app /app
COPY --from=assets /app/public/build ./public/build
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

# Writable for any UID, not just root: Heroku's container runtime starts dynos
# as an unprivileged user of its own choosing, so group-only permissions leave
# Laravel unable to write logs or the config cache.
RUN chmod +x /usr/local/bin/entrypoint \
    && chmod -R a+rwX storage bootstrap/cache

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://localhost/up || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
