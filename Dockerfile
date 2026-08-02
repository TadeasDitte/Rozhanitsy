# syntax=docker/dockerfile:1

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.5-alpine

# ---------------------------------------------------------------------------
# Build stage
#
# PHP dependencies and front-end assets are built together on purpose: the
# Wayfinder Vite plugin shells out to `php artisan wayfinder:generate` during
# `vite build`, so the asset build needs a bootable app with vendor/ present.
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS build

RUN install-php-extensions pdo_pgsql intl zip pcntl opcache

RUN apk add --no-cache nodejs npm git \
    && npm install --global pnpm@10

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    # Artisan boots during the build; a throwaway key keeps the encrypter happy
    # and is never carried into the runtime image.
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=

# Dependency layers first so application edits do not invalidate them.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY package.json pnpm-lock.yaml pnpm-workspace.yaml .npmrc ./
RUN pnpm install --frozen-lockfile

COPY . .

# .dockerignore strips the contents of these directories, and Docker does not
# create a directory that ends up with no files in it. Artisan needs them to
# exist before it will boot.
RUN mkdir -p bootstrap/cache storage/framework/views storage/logs

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover \
    && pnpm run build \
    && rm -rf node_modules

# ---------------------------------------------------------------------------
# Runtime stage
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS runtime

RUN install-php-extensions pdo_pgsql intl zip pcntl opcache

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/opcache.ini "$PHP_INI_DIR/conf.d/zz-opcache.ini"

WORKDIR /app

RUN addgroup -g 1000 -S app \
    && adduser -u 1000 -S -G app -h /home/app app

COPY --from=build --chown=app:app /app /app

# Plain COPY plus an explicit chmod, rather than COPY --chmod, so the image
# also builds on daemons still using the legacy (non-BuildKit) builder.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod 0755 /usr/local/bin/entrypoint \
    && mkdir -p \
        storage/app/private \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
        /home/app/.local/share/caddy \
        /home/app/.config/caddy \
    && chown -R app:app storage bootstrap/cache /home/app

USER app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SERVER_NAME=":8080" \
    SERVER_ROOT="public/" \
    XDG_DATA_HOME=/home/app/.local/share \
    XDG_CONFIG_HOME=/home/app/.config

EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=5s --start-period=90s --retries=5 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
