# syntax=docker/dockerfile:1

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.5-alpine

FROM ${PHP_IMAGE} AS build

RUN install-php-extensions pdo_pgsql intl zip pcntl opcache

RUN apk add --no-cache nodejs npm git \
    && npm install --global pnpm@10

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY package.json pnpm-lock.yaml pnpm-workspace.yaml .npmrc ./
RUN pnpm install --frozen-lockfile

COPY . .

RUN mkdir -p bootstrap/cache storage/framework/views storage/logs

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover \
    && pnpm run build \
    && rm -rf node_modules

FROM ${PHP_IMAGE} AS runtime

RUN install-php-extensions pdo_pgsql intl zip pcntl opcache

# For `docker compose exec` sessions: the base image ships only busybox ash.
# nohup and setsid are already busybox applets, so detaching a long-running
# maintenance command needs nothing beyond this.
RUN apk add --no-cache bash

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/opcache.ini "$PHP_INI_DIR/conf.d/zz-opcache.ini"

WORKDIR /app

RUN addgroup -g 1000 -S app \
    && adduser -u 1000 -S -G app -h /home/app app

COPY --from=build --chown=app:app /app /app

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
