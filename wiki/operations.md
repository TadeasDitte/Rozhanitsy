# Operations

Measured on 2026-08-02 against Postgres 17 in Docker. Re-measure on your own
hardware; the method is at the end of each section.

## First sync

NVD currently holds 372,505 CVEs. Ingest rate measured at 175 CVEs/sec, 766
ranges/sec, 64 MB peak process memory.

| Phase | With `NVD_API_KEY` | Without |
| --- | --- | --- |
| Processing (372,505 CVEs) | ~36 min | ~36 min |
| Throttle (187 pages) | ~2 min | ~19 min |
| Download (1.72 GB) | 3–30 min | 3–30 min |
| Total | 40–70 min | 60–85 min |

Download dominates the spread. NVD is frequently slow; at 1 MB/s the transfer
alone is ~30 min. A modest VPS will also process slower than the figure above —
assume 2–4 hours end to end on a small box and treat anything faster as a bonus.

Get an API key at https://nvd.nist.gov/developers/request-an-api-key. It cuts
the inter-request delay from 6000 ms to 600 ms, which is the difference between
19 and 2 minutes of pure waiting.

Run the first sync in the foreground so you can watch it, rather than waiting on
the scheduler:

```bash
docker compose exec app php artisan nvd:sync
```

```
Processed 2000 / 372505
Processed 4000 / 372505
...
NVD sync complete (372505 CVEs).
```

It is safe to interrupt. `last_synced_at` is only written after a full pass, so
a killed run re-requests the same window next time. Nothing is corrupted;
ranges are deleted and rebuilt per CVE.

Steady state: 127 CVEs were modified in the last 24 hours, so hourly incremental
runs finish in seconds and transfer almost nothing.

To measure on your own host, time a bounded slice:

```bash
docker compose exec app php artisan tinker --execute \
  'App\Models\Source::query()->update(["page_size" => 200]);'
time docker compose exec app php artisan nvd:sync --full
```

Interrupt after a few pages, divide CVEs processed by elapsed seconds, then set
`page_size` back to 2000.

## Disk

Measured 4,424 bytes per CVE including indexes and TOAST.

| Table | Projected at full catalogue |
| --- | --- |
| `vulnerabilities` | 1.18 GB |
| `vulnerability_ranges` | 0.36 GB |
| Total database | ~1.6 GB |

Most of that is `vulnerabilities.raw_data`, which stores the entire CVE JSON.
It buys the ability to rebuild derived tables without re-downloading from NVD.
If disk is tight, that column is the thing to trim — but do it deliberately,
since a re-sync would then be the only way to recover the data.

Growth is roughly 25–30 MB per 1000 new CVEs. NVD adds on the order of 30,000
per year, so budget about 150 MB/year plus headroom. Provision 10 GB and you
will not think about it again for years.

Scan logs grow with traffic: one row per API request, a few hundred bytes.
100 hosts scanning hourly is ~876,000 rows/year, well under 1 GB. Prune if it
matters:

```sql
DELETE FROM scan_logs WHERE scanned_at < now() - interval '1 year';
```

Check actual sizes:

```bash
docker compose exec db psql -U rozhanitsy -d rozhanitsy -c "
SELECT relname, pg_size_pretty(pg_total_relation_size(c.oid)) AS size
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind = 'r'
ORDER BY pg_total_relation_size(c.oid) DESC LIMIT 10;"
```

## Backups

Two volumes hold all state: `rozhanitsy_db` and `rozhanitsy_storage`. The
storage volume contains the generated `APP_KEY`. A database restored without
that key leaves encrypted columns — two-factor secrets, recovery codes —
permanently unreadable. Back up both, or set `APP_KEY` explicitly in `.env` and
keep it in your password manager, which makes the storage volume disposable.

### Routine dump

```bash
docker compose exec -T db pg_dump -U rozhanitsy -Fc rozhanitsy > rozhanitsy-$(date +%F).dump
docker compose exec -T app cat /app/storage/app/private/app_key > app_key-$(date +%F).txt
```

`-Fc` is the custom format: compressed, and restorable selectively with
`pg_restore`. A full dump of the catalogue compresses to a few hundred MB.

### Restore

```bash
docker compose down
docker volume rm rozhanitsy_db
docker compose up -d db
docker compose exec -T db psql -U rozhanitsy -d rozhanitsy -c \
  'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'
docker compose exec -T db pg_restore -U rozhanitsy -d rozhanitsy --no-owner < rozhanitsy-2026-08-02.dump
docker compose up -d
```

### Nightly cron

```bash
0 3 * * * cd /srv/rozhanitsy && docker compose exec -T db pg_dump -U rozhanitsy -Fc rozhanitsy \
  > /var/backups/rozhanitsy/$(date +\%F).dump 2>>/var/log/rozhanitsy-backup.log \
  && find /var/backups/rozhanitsy -name '*.dump' -mtime +14 -delete
```

Restore into a scratch database periodically. An untested backup is not a
backup:

```bash
docker compose exec -T db createdb -U rozhanitsy restoretest
docker compose exec -T db pg_restore -U rozhanitsy -d restoretest --no-owner < latest.dump
docker compose exec -T db psql -U rozhanitsy -d restoretest -c 'SELECT count(*) FROM vulnerabilities;'
docker compose exec -T db dropdb -U rozhanitsy restoretest
```

## Migrations

The entrypoint runs `migrate --force` on every `app` container start. A deploy
that includes a migration applies it automatically. Consequences worth knowing:

- Only the `app` container migrates; `scheduler` skips it via `CONTAINER_ROLE`.
  Run one `app` replica, or migrate out of band before scaling.
- A failing migration aborts the entrypoint, so the container never becomes
  healthy and the old one keeps serving if you deploy with a rolling update.

### Safe procedure

```bash
cd /srv/rozhanitsy
docker compose exec -T db pg_dump -U rozhanitsy -Fc rozhanitsy > pre-deploy.dump
git pull
docker compose build
docker compose exec app php artisan migrate --pretend    # review the SQL first
docker compose up -d
docker compose logs -f app
```

`--pretend` prints the statements without running them. Read them before a
deploy that touches a large table; an `ALTER TABLE` on `vulnerabilities` at 1.2 GB
takes a rewrite lock.

### Rollback

Application code rolls back by rebuilding the previous commit. Schema does not,
reliably — `migrate:rollback` runs `down()`, and any `down()` that drops a column
destroys data. For anything beyond a trivial additive change, restore the
pre-deploy dump instead.

Never run `migrate:fresh` or `migrate:refresh` against production. Both drop
every table. `DB::prohibitDestructiveCommands` is enabled when
`APP_ENV=production`, which blocks them, but do not rely on that as your only
guard.

### Editing an existing migration

Migrations that have run in production are immutable. Editing one changes
nothing on a deployed database — the migration is already in the `migrations`
table and will not re-run — while silently diverging fresh installs from
existing ones. Add a new migration instead.

The exception was pre-release, when no database existed anywhere. That period is
over once you deploy.

## Monitoring

```bash
curl -fsS https://your-host/up          # 200 when the app boots and reaches the DB
docker compose ps                        # app and db healthy, scheduler running
docker compose logs --tail=100 app
docker compose logs --tail=100 scheduler
```

Worth alerting on:

- `/up` non-200.
- `last_synced_at` falling behind. If it is more than a few hours old the
  scheduler or the sync is broken:

```bash
docker compose exec app php artisan tinker --execute \
  'echo App\Models\SyncState::max("last_synced_at");'
```

- Disk on the volume mount.
- `unmatched_lookups` growth, which is coverage rather than failure — see
  `php artisan nvd:unmatched`.

Logs go to stderr and are captured by the Docker log driver. Cap them or a busy
instance will fill the disk:

```json
{ "log-driver": "json-file", "log-opts": { "max-size": "50m", "max-file": "5" } }
```

## Routine tasks

```bash
docker compose exec app php artisan nvd:unmatched --min-hits=5   # coverage gaps worth mapping
docker compose exec app php artisan sanctum:prune-expired --hours=24
docker compose exec app php artisan user:admin you@example.com   # regain admin access
```

Postgres autovacuum handles the churn from `nvd:sync` deleting and reinserting
ranges. After the first full sync, one manual pass is worthwhile:

```bash
docker compose exec db vacuumdb -U rozhanitsy -d rozhanitsy --analyze
```

## Secrets

`APP_KEY`, `POSTGRES_PASSWORD`, and `NVD_API_KEY` live in `.env` next to
`compose.yaml`. Keep it out of version control, `chmod 600`, and back it up
separately from the database dump.

Rotating `POSTGRES_PASSWORD` requires changing it inside Postgres as well; the
compose variable only sets it at first initialisation:

```bash
docker compose exec db psql -U rozhanitsy -c "ALTER USER rozhanitsy WITH PASSWORD 'new';"
# then update .env and: docker compose up -d
```

`APP_KEY` cannot be rotated without invalidating every encrypted column.

## Failure modes

| Symptom | Cause | Action |
| --- | --- | --- |
| `/up` 200, `vulnerable` always empty | Sync never completed | Check `last_synced_at`, run `nvd:sync` manually |
| Every component `unmatched` | `cpe_map` empty | Expected until products and mappings exist |
| Sync exits 1 immediately | Source row missing or incomplete | `db:seed --class=SourceSeeder --force` |
| Scanners get 401 | Token revoked, or host `is_active = false` | Regenerate in the UI or `scan-host:create --rotate` |
| Scanners get 429 | 30 req/min per tenant | Batch per tenant, do not send per component |
| All standalone scanners throttled together | `TRUSTED_PROXIES` unset behind a proxy | Set it; see deployment.md |
| App container unhealthy after deploy | Migration failed | `docker compose logs app`, restore pre-deploy dump |

## Host sizing

- 2 vCPU, 2 GB RAM, 20 GB disk is comfortable. The sync is the peak load and
  peaks at 64 MB of PHP memory.
- 1 vCPU / 1 GB works, but the first sync will take several hours.
- Postgres and the app share a host by default. Split them out only when the
  scan API load justifies it; the endpoint is two indexed reads per request.
