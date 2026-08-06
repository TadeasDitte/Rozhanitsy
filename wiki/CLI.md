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
are rebuilt on every sync, upserted by NVD's own `matchCriteriaId` for each
`cpeMatch`, so re-running is idempotent.

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

## nvd:promote-unmatched

```
php artisan nvd:promote-unmatched [--min-hits=5] [--limit=100] [--type=plugin] [--dry-run]
```

Promotes `unmatched_lookups` pairs seen at least `--min-hits` times into real
`Vendor`/`Product`/`cpe_map` rows, up to `--limit` pairs per run, ordered by
hit count. Scheduled daily by `schedule:work` in the scheduler container, with
`withoutOverlapping` at a 6 hour expiry — see
[schema.md](schema.md#vendors-and-products) for why this is the one automated
path that's allowed to create catalog entries.

A vendor/product pair reported by real, authenticated scan traffic repeatedly
is treated as sufficient evidence some tenant runs it; the command has no
opinion on whether NVD has ever published a CVE against it. Promoted products
default to `type = plugin` — the safe default, since it excludes them from
`nvd:cross-check-core`'s `core`-only scope — and the promoted `cpe_map` row
uses the pair's raw strings exactly as reported, since `/api/vulns/check`
matches against them literally (case-insensitively).

A pair that fails to promote (a name collision with an existing product, most
likely) is skipped with a warning rather than aborting the run; it stays in
`unmatched_lookups` and is retried on the next run.

```bash
php artisan nvd:promote-unmatched
php artisan nvd:promote-unmatched --min-hits=10 --type=library
php artisan nvd:promote-unmatched --dry-run
```

```
+------------+-------------+------+
| CPE Vendor | CPE Product | Hits |
+------------+-------------+------+
| elementor  | elementor   | 12   |
+------------+-------------+------+

Promoted 1 pair(s).
Relinked 3 vulnerability ranges.
```

If anything was promoted, the command finishes by running `nvd:relink` so any
CVE data already on disk for the newly catalogued products resolves
immediately, rather than waiting on the next `nvd:sync`.

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

Rebuilds `vulnerability_ranges` for every `Vulnerability`, and re-derives its
CVSS columns and description, from its own stored `raw_data` — the full CVE
JSON kept at ingest time — via the same `VulnerabilityRangeBuilder` and
`NvdCveMapper` `nvd:sync` uses. Pure database work, no NVD request, and it
re-resolves CPEs against the current catalog as a side effect, so it supersedes
`nvd:relink`: running both after the same event is redundant, run this one.

Everything except `raw_data` is derived from it, which is what makes this the
repair path for any defect in how a CVE is read — a range keyed wrongly, a CPE
mapped wrongly, a CVSS metric chosen wrongly — without a multi-hour resync.

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
Done. Rebuilt ranges and scores for 372520 vulnerabilities.
```

## nvd:cross-check-core

```
php artisan nvd:cross-check-core [--limit=50] [--force]
```

Cross-checks CVEs resolved to a `type = core` product against GitHub's
Security Advisory API, catching the case where NVD's CPE match points a
library CVE at the platform it shares a vendor slug with — see
[schema.md](schema.md#ghsa-cross-check) for the mechanism and the
CVE-2025-25226 case it exists for. Scheduled daily by `schedule:work` in the
scheduler container, with `withoutOverlapping` at a 6 hour expiry.

Without `--force`, CVEs with a non-null `ghsa_checked_at` are skipped —
routine runs only look at CVEs newly resolved to a core product since the
last check. `--force` re-checks everything in scope, useful after a GHSA
record changes.

A GHSA request that fails (rate limit, network error) leaves
`ghsa_checked_at` untouched rather than recording a false "clean" verdict, so
it's retried on the next run.

```bash
php artisan nvd:cross-check-core
php artisan nvd:cross-check-core --limit=200 --force
```

```
CVE-2025-25226: GHSA tags this under a package ecosystem but it resolved to a core product here — downgraded 1 range(s).
Checked 12 CVE(s), flagged 1 as ecosystem mismatches.
```

Set `GITHUB_TOKEN` to raise GHSA's unauthenticated rate limit (60/hour) if
`--limit`/`--min-hits` on `nvd:promote-unmatched` grow the core catalog enough
to matter — see [deployment.md](deployment.md#environment).

## nvd:pending-review

```
php artisan nvd:pending-review [--limit=50]
```

Lists `vulnerability_ranges` currently held back from live matching despite
having a resolved `product_id` — either the stability guard hasn't cleared
them yet, or `nvd:cross-check-core` flagged the CVE as a GHSA ecosystem
mismatch. This is a visibility tool, not an error log: every row here is
`match_confidence = unmatched` on purpose, per
[schema.md](schema.md#stability-guard).

```bash
php artisan nvd:pending-review
php artisan nvd:pending-review --limit=20
```

```
+----------------+---------+----------------------+--------------+---------------------------------+
| CVE            | Product | Reason               | Version End  | Raw CPE                         |
+----------------+---------+----------------------+--------------+---------------------------------+
| CVE-2026-40383 | Joomla  | Missing lower bound  | 6.1.1        | cpe:2.3:a:joomla:joomla\!:*:*... |
| CVE-2025-25226 | Joomla  | GHSA: library, not   | 2.2.0        | cpe:2.3:a:joomla:joomla\!:*:*... |
|                |         | core product         |              |                                  |
+----------------+---------+----------------------+--------------+---------------------------------+

Missing-floor rows self-heal once NVD fills in the bound or the stability grace period elapses (nvd:sync / nvd:rebuild-ranges). GHSA-mismatch rows stay held back permanently unless the mismatch is cleared with nvd:cross-check-core --force.
Showing 2 of 2.
```

## cpe:collisions

```
php artisan cpe:collisions
```

Lists `cpe_vendor` values whose distinct `cpe_product` entries in `cpe_map`
resolve to the same `product_id` — the `GROUP BY cpe_vendor, product_id
HAVING COUNT(DISTINCT cpe_product) > 1` audit query. A manual-review tool, not
a bug detector: some collisions are legitimate name variants (see the `joomla`
vendor's `joomla`/`joomla\!` pair in [schema.md](schema.md#cpe_map)), others
are a genuine conflation worth splitting apart.

```bash
php artisan cpe:collisions
```

```
+--------+---------+-------------+------------+
| Vendor | Product | CPE Product | Match Type |
+--------+---------+-------------+------------+
| joomla | Joomla  | joomla      | exact      |
| joomla | Joomla  | joomla!     | exact      |
+--------+---------+-------------+------------+

Not every row above is wrong — some are legitimately-merged name variants. Verify each product_id genuinely represents one independently-versioned thing before treating it as a bug.
```

## cpe:variants

```
php artisan cpe:variants [--limit=25]
```

The opposite audit to `cpe:collisions`. That one finds CPE names wrongly
collapsed onto one product; this one finds CVEs stranded under a CPE name no
product claims, which is invisible from the outside — the product simply
reports clean.

Scans unresolved `vulnerability_ranges` and reports any whose CPE shares a
vendor with something catalogued, or whose name is a catalogued product's name
give or take a qualifying word, ranked by how many CVEs a mapping would unlock.

Both failures were live on Elementor at once: CVEs for the paid edition were
being attributed to the free plugin, while the free plugin's own 41 CVEs sat
unread under `elementor:website_builder`.

```bash
php artisan cpe:variants
php artisan cpe:variants --limit=50
```

```
+------------+-----------------+------+-------------------------+
| CPE Vendor | CPE Product     | CVEs | Nearest Catalog Product |
+------------+-----------------+------+-------------------------+
| elementor  | website_builder | 41   | Elementor / Elementor   |
| elementor  | elementor_pro   | 7    | Elementor / Elementor   |
+------------+-----------------+------+-------------------------+
```

A row is a candidate for `CpeMapSeeder`, not an error. Verify it against NVD
first: `website_builder` is the free plugin's own CVE feed and belongs there,
`elementor_pro` is a separately sold product and must stay on this list
forever. Mapping it would attribute Pro's vulnerabilities to every free
install.

## cpe:prune-variants

```
php artisan cpe:prune-variants [--dry-run]
```

Deletes `cpe_map` rows with `match_type = fuzzy` that the resolver would no
longer learn today. Rows with `match_type = exact` — seeded pairs and human
decisions — are never touched.

This exists because fuzzy matching writes its guesses back into `cpe_map`, and
an exact lookup on that table beats fuzzy matching on every later resolve. A
mapping learned under a looser policy therefore keeps being served long after
the policy is tightened, and no amount of syncing dislodges it.

```bash
php artisan cpe:prune-variants --dry-run
php artisan cpe:prune-variants
```

```
+------------+---------------+---------------+-----------------+
| CPE Vendor | CPE Product   | Was Mapped To | Would Now Match |
+------------+---------------+---------------+-----------------+
| elementor  | elementor_pro | Elementor     | no match        |
| wordpress  | wordpress_mu  | WordPress     | no match        |
+------------+---------------+---------------+-----------------+

Removed 2 learned mapping(s).

Stored ranges still point at the old products. Run nvd:rebuild-ranges to re-resolve them.
```

Deleting the mapping is only half the repair: existing `vulnerability_ranges`
still carry the `product_id` it produced, and the live check reads those, not
`cpe_map`. `nvd:relink` will not help — it only touches rows that are already
unmatched. Follow with `nvd:rebuild-ranges`.

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
