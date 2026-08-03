# Deployment

## Quick start

```bash
docker compose up -d
docker compose exec app php artisan scan-host:create scanner-01.example.com
```

The first command is sufficient on a clean host. The entrypoint generates and
persists an `APP_KEY`, waits for Postgres, runs migrations, seeds the NVD source
row and a starter `products`/`vendors` catalog, and warms the config, route,
view, and event caches. The application is on `http://localhost:8000`.

The starter catalog (`VendorSeeder`/`ProductSeeder`) covers common software —
WordPress, Joomla, Drupal, PHP, OpenSSL, nginx, Apache, MySQL, phpMyAdmin and a
handful of plugins each. It is not exhaustive. Extend it from
`/admin/products` as scans report gaps; see
[schema.md](schema.md#vendors-and-products) for why nothing does this
automatically. After adding a product to an install that has already run
`nvd:sync`, follow with `php artisan nvd:relink` — the new product does not
retroactively resolve CVEs already stored as unmatched.

Register the first account through the web UI; it becomes the administrator.

## Services

| Service | Role |
| --- | --- |
| `app` | FrankenPHP serving the application on `:8080`, published as `8000` |
| `scheduler` | `php artisan schedule:work`, runs `nvd:sync` hourly |
| `db` | Postgres 17, not published to the host |

`scheduler` shares the image with `app` and is distinguished by
`CONTAINER_ROLE=scheduler`, which skips migrations and the seeder. Its
healthcheck is disabled because it runs no HTTP server.

## Environment

Compose reads `./.env` for interpolation. A stale development `.env` in the
deployment directory will silently supply values such as `APP_KEY` and the
database credentials.

| Variable | Default | Notes |
| --- | --- | --- |
| `APP_PORT` | `8000` | Host port for the app container |
| `APP_URL` | `http://localhost:8000` | Used for generated URLs |
| `APP_KEY` | generated | Persisted to the storage volume if unset |
| `POSTGRES_DB` | `rozhanitsy` | |
| `POSTGRES_USER` | `rozhanitsy` | |
| `POSTGRES_PASSWORD` | `rozhanitsy` | Change before exposing anything |
| `NVD_API_KEY` | empty | Raises the sync from 5 to 50 requests / 30s |
| `TRUSTED_PROXIES` | `*` | See below |
| `LOG_LEVEL` | `info` | Logs go to stderr |

Sessions, cache, and queue all use the database. There is no Redis dependency
and no queue worker, because nothing dispatches jobs.

Those ten are the only variables that do anything. Compose reads `.env` to
interpolate `${...}` in `compose.yaml`; a variable not referenced there never
reaches a container. The file is never copied into the image — `.dockerignore`
excludes it — so on a server it serves only Compose, not Laravel.

### .env template

Place next to `compose.yaml`:

```ini
APP_URL=https://rozhanitsy.example.com
APP_PORT=8000

POSTGRES_DB=rozhanitsy
POSTGRES_USER=rozhanitsy
POSTGRES_PASSWORD=generate-a-long-random-one

NVD_API_KEY=
TRUSTED_PROXIES=*
LOG_LEVEL=info

# Optional. Omit and the entrypoint generates one onto the storage volume.
# Set it here and the storage volume becomes disposable for backup purposes. 
# APP_KEY=base64:...
```

```bash
# generate APP_KEY
echo "base64:$(openssl rand -base64 32)"
```

```bash
chmod 600 .env
```

Already covered by `.gitignore`. Values are taken literally: a password
containing `$`, `!`, or spaces needs no escaping, and quotes are stripped.
Inline `#` starts a comment, so avoid it inside values.

### Changing POSTGRES_PASSWORD later

`POSTGRES_PASSWORD` is read by Postgres only when it initialises an empty data
directory. Editing it in `.env` afterwards changes what the app connects with
but not what the database expects, and the app container goes unhealthy with
`FATAL: password authentication failed`.

Change it in both places:

```bash
docker compose exec db psql -U rozhanitsy -c "ALTER USER rozhanitsy WITH PASSWORD 'new';"
# edit .env to match
docker compose up -d
```

Or, on a fresh install with nothing to lose, `docker compose down -v` and start
again.

### APP_KEY

If `APP_KEY` is unset, the entrypoint generates one and writes it to
`/app/storage/app/private/app_key` on the `storage` volume, reusing it on
subsequent starts. A key that changed between restarts would invalidate sessions
and make already-encrypted columns unreadable.

Supplying `APP_KEY` explicitly takes precedence and the file is not consulted.

## TLS and reverse proxy

The app serves plain HTTP and expects TLS to terminate at a proxy in front of
it.

`TRUSTED_PROXIES` defaults to `*`, which is correct when the container is only
reachable through your proxy. It must be set: the scanner rate limiter falls
back to the client IP for requests without a `tenant_id`, and untrusted proxy
headers make every such install appear as the proxy's own address, sharing one
bucket.

Narrow it to specific addresses if the container is reachable directly:

```
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12
```

Example nginx front end:

```nginx
server {
    listen 443 ssl http2;
    server_name rozhanitsy.example.com;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Set `APP_URL=https://rozhanitsy.example.com` to match.

FrankenPHP can terminate TLS itself instead, by setting `SERVER_NAME` to the
domain and mapping ports 80 and 443. That requires the container to run as root
or hold `CAP_NET_BIND_SERVICE`; it currently runs as uid 1000.

## Health

`GET /up` returns 200 when the application boots and reaches the database. The
app container healthcheck polls it every 15 s after a 90 s grace period.

```bash
curl -fsS http://localhost:8000/up
docker compose ps
docker compose logs -f app
```

## Image

Multi-stage build on `dunglas/frankenphp:1-php8.5-alpine`. Composer
dependencies and front-end assets are built in the same stage because the
Wayfinder Vite plugin invokes `php artisan` during `vite build`. The runtime
stage runs as a non-root user with opcache validation disabled. Roughly 420 MB.

The image builds with both BuildKit and the legacy builder.

## Upgrades

```bash
docker compose build
docker compose up -d
```

Migrations run on start-up. The `app` container is the only one that migrates;
run a single replica, or run migrations out of band before scaling.

## Backups

State lives in two volumes: `db` (Postgres) and `storage` (logs and the
generated `APP_KEY`). Back up both — restoring the database without the key
leaves encrypted columns unreadable.

```bash
docker compose exec db pg_dump -U rozhanitsy rozhanitsy > backup.sql
```
