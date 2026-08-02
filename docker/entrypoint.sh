#!/bin/sh
# Container start-up. Everything here is idempotent so `docker compose up`
# needs no follow-up commands on a fresh host or an existing one.
set -e

role="${CONTAINER_ROLE:-app}"

wait_for_database() {
    [ "${DB_CONNECTION:-pgsql}" = "pgsql" ] || return 0

    attempt=0
    until php -r 'exit(@fsockopen(getenv("DB_HOST") ?: "127.0.0.1", (int) (getenv("DB_PORT") ?: 5432), $e, $s, 2) ? 0 : 1);'; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 60 ]; then
            echo "entrypoint: database at ${DB_HOST}:${DB_PORT} unreachable after 60s" >&2
            exit 1
        fi
        sleep 1
    done
}

# An APP_KEY that changes between restarts would silently invalidate sessions
# and make already-encrypted columns unreadable, so a generated one is kept on
# the storage volume rather than regenerated each boot.
ensure_app_key() {
    [ -z "${APP_KEY:-}" ] || return 0

    key_file="/app/storage/app/private/app_key"

    if [ ! -s "$key_file" ]; then
        php artisan key:generate --show > "$key_file"
        echo "entrypoint: generated a new APP_KEY and stored it at $key_file"
    fi

    APP_KEY="$(cat "$key_file")"
    export APP_KEY
}

ensure_app_key
wait_for_database

if [ "$role" = "app" ]; then
    php artisan migrate --force --no-interaction
    php artisan db:seed --class=SourceSeeder --force --no-interaction
fi

# Caches are built at start-up, not at build time, so they capture the real
# runtime environment rather than whatever was set while the image was built.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec docker-php-entrypoint "$@"
