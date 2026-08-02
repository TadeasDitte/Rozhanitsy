# CLI

All commands are Artisan commands. Under Docker, prefix with
`docker compose exec app`.

## nvd:sync

```
php artisan nvd:sync [--full]
```

Pulls CVE and CPE data from NVD and upserts `vulnerabilities` and
`vulnerability_ranges`. Scheduled hourly by `schedule:work` in the scheduler
container, with `withoutOverlapping` at a 6 hour expiry.

Incremental by default: requests only CVEs modified since
`sync_states.last_synced_at`, using that value as `lastModStartDate` and the run
start time as `lastModEndDate`. `--full` ignores the watermark and re-requests
everything.

The watermark is captured before the first request and written only after the
whole run succeeds. A failed request returns a non-zero exit code and leaves
`last_synced_at` untouched, so the next run re-requests the same window rather
than stepping over CVEs it never received.

Endpoint, page size, and request delays are read from the `sources` row whose
`driver` is `nvd`; only that driver string lives in the command. The command
fails if the row is missing or any of `url`, `page_size`, `request_delay_ms`,
`unauthenticated_request_delay_ms` is null.

Rate limiting follows the seeded values: 600 ms between requests with an API
key, 6000 ms without. Set `NVD_API_KEY` to use the faster tier.

```bash
php artisan nvd:sync
php artisan nvd:sync --full
```

The first run backfills the entire catalogue and takes hours. Ranges for a CVE
are deleted and rebuilt on every sync, so re-running is idempotent.

Exit codes: 0 success, 1 missing/misconfigured source or a failed request.

## nvd:unmatched

```
php artisan nvd:unmatched [--limit=50] [--min-hits=1]
```

Prints vendor/product pairs that the live check could not resolve against
`cpe_map`, ordered by hit count. This is a coverage worklist, not an error log:
each row is a mapping worth adding.

```bash
php artisan nvd:unmatched
php artisan nvd:unmatched --limit=20 --min-hits=10
```

```
+------------+-------------+------+---------------------+---------------------+
| CPE Vendor | CPE Product | Hits | First Seen          | Last Seen           |
+------------+-------------+------+---------------------+---------------------+
| acme       | widget      | 3    | 2026-08-02 11:28:14 | 2026-08-02 11:28:19 |
+------------+-------------+------+---------------------+---------------------+

Showing 1 of 1 unmatched pairs.
```

## nvd:relink

```
php artisan nvd:relink
```

Re-resolves `vulnerability_ranges` rows stuck at `match_confidence = unmatched`
against the current `products` table, using each row's stored `raw_cpe`. Pure
database work, no NVD request.

Exists because `nvd:sync` only resolves a CPE at ingest time — a product added
to the catalog afterwards does not retroactively fix ranges that were already
stored as unmatched. Re-running `nvd:sync --full` would eventually fix them
too, but re-downloads the entire catalogue to do it. `nvd:relink` reaches the
same result against data already on disk, in seconds rather than the hour-plus
`nvd:sync --full` takes (see [operations.md](operations.md)).

Run it once after seeding or adding products, not on a schedule — it has
nothing to do until the catalog changes.

```bash
php artisan nvd:relink
```

```
Relinked 41823 vulnerability ranges.
```

A successful fuzzy match also writes the `cpe_map` row, exactly as `nvd:sync`
would, so the live `/api/vulns/check` path benefits immediately without
waiting on the next sync.

## nvd:rebuild-ranges

```
php artisan nvd:rebuild-ranges
```

Rebuilds `vulnerability_ranges` for every `Vulnerability`, from its own stored
`raw_data` — the full CVE JSON kept at ingest time — via the same
`VulnerabilityRangeBuilder` `nvd:sync` uses. Pure database work, no NVD
request, and it re-resolves CPEs against the current catalog as a side effect,
so it supersedes `nvd:relink`: running both after the same event is redundant,
run this one.

This exists for schema/parsing changes, not routine use — a change to how a
CVE's `configurations` gets turned into ranges (e.g. the `group_index`/
`clause_index` AND-grouping added in 2026-08) only affects rows created after
the change until existing rows are rebuilt. `raw_data` makes that a local
operation instead of a multi-hour `nvd:sync --full` against the live API. See
schema.md's [vulnerability_ranges](schema.md#vulnerability_ranges).

Run it once, after a deploy that changes range-building logic. Not scheduled,
not run by the entrypoint — rewrites the whole table, which is wasted work on
every container restart when nothing changed.

```bash
php artisan nvd:rebuild-ranges
```

```
Rebuilt 2000 vulnerabilities.
Rebuilt 4000 vulnerabilities.
...
Done. Rebuilt ranges for 372520 vulnerabilities.
```

## scan-host:create

```
php artisan scan-host:create <hostname> [--rotate]
```

Registers a scan host and prints a bearer token. Use for hosts provisioned
outside the web UI; such hosts have no owning user and will not appear on any
account's token page, though admins still see them.

Without `--rotate`, a host that already has a token is left alone. With
`--rotate`, existing tokens are deleted, a new one is issued, and an inactive
host is reactivated.

```bash
php artisan scan-host:create scanner-01.example.com
```

```
Registered scan host "scanner-01.example.com".

SCAN_TOKEN=1|PBqK1mpMyJxHUqmY3AJjf4t9HI1ddTS6vPuo3vaGdb13b6a8

Copy this now — it cannot be shown again.
```

```bash
php artisan scan-host:create scanner-01.example.com --rotate
```

## user:admin

```
php artisan user:admin <email> [--revoke]
```

Grants or revokes administrator access. The recovery path when nobody can reach
the admin UI.

The first registered account becomes an administrator automatically. Subsequent
ones do not.

```bash
php artisan user:admin owner@example.com
php artisan user:admin colleague@example.com --revoke
```

Revoking refuses when the target is the only remaining administrator:

```
Refusing to remove the last administrator.
```

Exit codes: 0 success, 1 unknown email or last-administrator refusal.

## Operational

```bash
php artisan migrate --force
php artisan db:seed --class=SourceSeeder --force
php artisan schedule:list
php artisan sanctum:prune-expired --hours=24
```

The Docker entrypoint runs migrations, the source seeder, `VendorSeeder`,
`ProductSeeder`, and the config, route, view, and event caches on start-up.
None of these need running by hand for a normal deployment — `SourceSeeder`,
`VendorSeeder`, and `ProductSeeder` all use `firstOrCreate`/`updateOrCreate`,
so re-running on every restart is safe and does not duplicate rows.

`VendorSeeder`/`ProductSeeder` only cover a small starter catalog (see
[deployment.md](deployment.md)). Growing it beyond that is manual, either
through `/admin/products` or by hand:

```bash
php artisan db:seed --class=VendorSeeder --force
php artisan db:seed --class=ProductSeeder --force
php artisan nvd:relink
```

`nvd:relink` matters on an install that has already run `nvd:sync`: a product
added after the fact does not retroactively resolve ranges already stored as
`unmatched`. See schema.md's
[Vendors and products](schema.md#vendors-and-products).
