#!/bin/sh
set -e

role="${CONTAINER_ROLE:-app}"

wait_for_database() {
    [ "${DB_CONNECTION:-pgsql}" = "pgsql" ] || return 0

    attempt=0
    while true; do
        php -r '
            $host = getenv("DB_HOST") ?: "127.0.0.1";
            $port = getenv("DB_PORT") ?: "5432";
            $db   = getenv("DB_DATABASE") ?: "";
            $user = getenv("DB_USERNAME") ?: "";
            $pass = getenv("DB_PASSWORD") ?: "";

            try {
                new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass, [PDO::ATTR_TIMEOUT => 2]);
                exit(0);
            } catch (PDOException $e) {
                fwrite(STDERR, $e->getMessage() . PHP_EOL);
                exit(str_contains($e->getMessage(), "password authentication failed") ? 2 : 1);
            }
        '
        status=$?

        [ "$status" -eq 0 ] && return 0

        if [ "$status" -eq 2 ]; then
            echo "entrypoint: password authentication failed for DB_USERNAME=${DB_USERNAME} at ${DB_HOST}:${DB_PORT} -- credentials do not match the database. Postgres only applies POSTGRES_PASSWORD when it initializes a fresh data volume, so a rotated password will not take effect on an existing volume; update DB_PASSWORD to match, or reset the role's password inside the database." >&2
            exit 1
        fi

        attempt=$((attempt + 1))
        if [ "$attempt" -ge 60 ]; then
            echo "entrypoint: database at ${DB_HOST}:${DB_PORT} unreachable after 60s" >&2
            exit 1
        fi
        sleep 1
    done
}

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
    php artisan db:seed --class=VendorSeeder --force --no-interaction
    php artisan db:seed --class=ProductSeeder --force --no-interaction
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec docker-php-entrypoint "$@"
