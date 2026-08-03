# Data model

## Tables

```
sources ──┬── sync_states
          └── vulnerabilities ── vulnerability_ranges ──┐
                                                        │
vendors ── products ──┬── cpe_map                       │
                      └───────────────────────────────── product_id

users ── scan_hosts ── scan_logs
unmatched_lookups (standalone)
```

| Table | Columns |
| --- | --- |
| `sources` | `id, slug, name, url, driver, page_size, request_delay_ms, unauthenticated_request_delay_ms` |
| `sync_states` | `id, source_id, last_synced_at` |
| `vendors` | `id, name, slug` |
| `products` | `id, vendor_id, name, slug, type` |
| `cpe_map` | `id, cpe_vendor, cpe_product, product_id, match_type` |
| `vulnerabilities` | `id, cve_id, cvss_score, cvss_vector, cvss_version, cvss_severity, description, published_at, last_modified_at, source_id, raw_data` |
| `vulnerability_ranges` | `id, vulnerability_id, product_id, match_confidence, group_index, clause_index, version_start, version_start_incl, version_end, version_end_incl, raw_cpe` |
| `scan_hosts` | `id, user_id, hostname, is_active, last_seen_at` |
| `scan_logs` | `id, scan_host_id, tenant_id, component_count, vulnerable_count, unmatched_count, scanned_at` |
| `unmatched_lookups` | `id, cpe_vendor, cpe_product, hit_count, first_seen_at, last_seen_at` |

### sources

One row per upstream feed. `driver` names the code that parses it and is unique;
`nvd:sync` finds its row by `driver = 'nvd'`. Everything else about the feed —
endpoint, page size, request delays — is data on the row rather than constants
in the command. A row with a null `driver` is recorded but not syncable.

### Vendors and products

`vendors` and `products` are a curated catalog, not a mirror of NVD's CPE
dictionary. Nothing creates them from CVE data: no code path in ingest, no
scheduled job, nothing triggered by a scan. They come from `VendorSeeder` /
`ProductSeeder` (a small starter catalog, run by the Docker entrypoint on every
start — see [deployment.md](deployment.md)) and from `/admin/products` (see
[web.md](web.md#products)), and from nowhere else.

This is deliberate, not an oversight to route around. A `Product` carries a
`type` (`core`, `plugin`, `theme`, `extension`, `package`, `library`) that a
raw CPE string does not encode, and NVD's CPE dictionary has an entry for
essentially every piece of software ever assigned a CVE — auto-importing it
would flood the catalog with entries no tenant will ever report. `NvdCpeResolver`
only *links* CVEs to products that already exist; it has no path to invent one.

The consequence: a product with no `Vendor`/`Product` row can never resolve,
no matter how much CVE data is synced. `nvd:sync --full` will not fix it,
`nvd:sync` running hourly will not fix it — the fuzzy matcher in
[CPE resolution](#cpe-resolution) has nothing to score against. The starter
catalog covers common software so a fresh install isn't matching against an
empty table, but it is not exhaustive; anything outside it reports `unmatched`
until added by hand.

### cpe_map

Bridges NVD's CPE vendor/product naming to local products. Unique on
`(cpe_vendor, cpe_product)`, which also serves as the index for the live lookup.
`match_type` records how the mapping was established, `exact` or `fuzzy`.

### vulnerability_ranges

One row per `cpeMatch` entry in a CVE. `match_confidence` is `exact`, `fuzzy`, or
`unmatched`. `raw_cpe` keeps the original CPE string.

A CVE that names a concrete version in the CPE with no range keys is stored as
an inclusive point range (`version_start = version_end`), not as an unbounded
one.

`group_index`/`clause_index` encode the CVE's own `configurations` structure.
NVD's `configurations` array is a list of independently-OR'd applicability
scenarios (`group_index`); within one, `nodes` combined with `operator: "AND"`
each become their own `clause_index`, and every clause must be satisfied —
possibly by a different product — for the group to apply. Rows sharing both
indexes are plain OR alternatives: today's only shape until a CVE actually
uses `AND`, and still the shape for the overwhelming majority of CVEs
(`group_index = clause_index = 0` for everything). See
[CPE resolution](#cpe-resolution) and [Matching](#matching) for why this
exists and how it's enforced. `node.negate === true` is skipped entirely — not
stored, not treated as a positive requirement — since the matcher has no way
to express "vulnerable when this is *absent*".

### scan_logs

One row per API request. `tenant_id` is nullable for standalone installs.
`scan_host_id` cascades on delete, which is why revoking a token deactivates the
host rather than deleting it — deleting would take the scan history with it.

### unmatched_lookups

Vendor/product pairs the live check could not resolve, stored lowercased and
unique on the pair. Written with a single batched upsert that increments
`hit_count` on conflict.

## Ingest

`nvd:sync` per CVE, inside a transaction:

1. Upsert `vulnerabilities` by `cve_id`, storing the whole CVE in `raw_data`.
2. Hand off to `VulnerabilityRangeBuilder::build()`, which deletes existing
   `vulnerability_ranges` for that CVE and rebuilds them from
   `configurations`, resolving each `cpeMatch` and assigning
   `group_index`/`clause_index` per the rules in [vulnerability_ranges](#vulnerability_ranges).

Deleting and rebuilding makes a re-sync idempotent rather than accumulating
stale rows.

`VulnerabilityRangeBuilder` is the only thing that parses `configurations` —
`nvd:sync` and `nvd:rebuild-ranges` (see [cli.md](cli.md)) both call it, one
from a live NVD response, the other from a `vulnerabilities.raw_data` already
on disk. They cannot drift apart because there's only one implementation.

## CPE resolution

`NvdCpeResolver`, ingest-time only:

1. Parse vendor and product from the CPE string, lowercase them.
2. Exact lookup in `cpe_map`. On hit, return the stored `match_type`.
3. On miss, score every product with `similar_text` — vendor weighted 0.4,
   product 0.6 — and take the best above 0.87.
4. Write a fuzzy match back to `cpe_map` so later lookups are exact.
5. No match: `product_id = null`, `match_confidence = unmatched`.

A pair learned by fuzzy matching keeps `match_type = fuzzy` permanently, so a
re-sync cannot promote a guess to a certainty.

The threshold is not conservative. An identical vendor needs only about 78%
product similarity to cross it, so `elementor-pro` scores 0.891 against
`elementor` and `wordpress-mu` scores 0.914 against `wordpress`. Both are
distinct products with distinct CVE sets. Learned mappings are persisted and not
re-evaluated, so a wrong match stays until deleted from `cpe_map` by hand.

Resolution only runs from `nvd:sync` (or `nvd:rebuild-ranges`), on the CPE
strings in whatever CVEs that run touches. Adding a product after a range was
already stored as `unmatched` does not retry it — that range keeps
`product_id = null` until something resolves it again. `nvd:relink` does that
against `raw_cpe` already on disk, for every `unmatched` row at once, without
re-hitting NVD. `nvd:rebuild-ranges` does the same as a side effect of
rebuilding `group_index`/`clause_index`, so running both after the same event
is redundant. See [cli.md](cli.md).

## Matching

`POST /api/vulns/check` performs exactly two reads:

1. `cpe_map` filtered by the request's vendors and products, keyed in memory on
   `vendor|product`.
2. `vulnerability_ranges` joined to `vulnerabilities`, restricted by a
   sub-`whereIn` to vulnerabilities that have at least one *resolved* range
   touching a matched product id — but, unlike step 1, **not** filtered to
   `unmatched` on the outer fetch. An `unmatched` row still has to come back
   if its vulnerability was selected, so an AND-group clause with no
   resolvable product is visible as a clause that exists and correctly never
   satisfied, rather than silently absent and letting the group look complete
   by omission. Both conditions compile to one SQL statement (a nested
   `IN (SELECT ...)`), so this is still one query, one row in
   `DB::getQueryLog()` — the "two reads" guarantee is about round trips to
   Postgres, not how many conditions one query expresses.

The rows are grouped in PHP by `vulnerability_id` → `group_index` →
`clause_index`. A group applies only once every clause it contains has at
least one scanned component whose version satisfies some range in that
clause — clauses can reference different products, so this checks across the
whole request's inventory, not just one product's ranges. Comparison itself is
pure in-memory `VersionComparator`.

Attribution ranks matches in three tiers, computed by `attributionTier()`:

1. **`bounded`** — a real version range (`version_start`/`version_end` set).
2. **`unbounded`** — no structured range and the CPE's version field is a bare
   `*` wildcard. Formally "any version" per the CPE 2.3 spec, which is
   ambiguous in practice: could genuinely mean every version is affected, or
   could just mean NVD never went back to scope it (common for CVEs older
   than NVD's structured version-range fields, roughly pre-2015).
3. **`platform`** — no structured range and the CPE's version field is
   literally `-`, CPE 2.3's "not applicable" marker. Read off `raw_cpe` at
   match time (position 5 of the colon-delimited string), not stored
   separately. Almost always a co-requirement like "plugin X requires
   WordPress installed", never itself a claim that the platform is the
   vulnerable thing — a `-` marker never wins attribution, even as a
   fallback. If every match in a satisfied group is `platform`-tier, nothing
   is reported for that group.

The highest tier present wins; `platform` matches still count toward
satisfying an AND-group clause (the presence check), they just never appear
in the response as the reported component. This is a matching-time
reinterpretation only — no schema change, no ingest change, no
`nvd:rebuild-ranges` needed; it takes effect on the next request.

The first query (`cpe_map`) uses two `whereIn` clauses rather than exact pair
matching. That over-fetches in principle, but the exact pairing is
re-established by the in-memory key, and the alternative — 2000 OR'd
`(vendor AND product)` groups — is expensive for Postgres to plan.

## Version comparison

`VersionComparator::isAffected` normalises both sides before `version_compare`:

- Strips a leading `v`.
- Drops SemVer build metadata from `+` onward.
- Pads the numeric core to three segments.
- Keeps any prerelease suffix.

Padding matters. `version_compare` ranks a missing segment below a present one,
so without it `1.0` falls outside `[1.0.0, 2.0.0)` and `2.0` falls inside it —
a missed CVE and a false positive respectively. Two-segment versions are normal
for plugins.

| Installed | Range | Affected |
| --- | --- | --- |
| `1.0` | `[1.0.0, 2.0.0)` | yes |
| `2.0` | `[1.0.0, 2.0.0)` | no |
| `1.2.0-beta1` | `(, 1.2.0)` | yes |
| `v1.2.0+build99` | `[1.2.0, 1.2.0]` | yes |
| `2.14.1` | `[2.14.1, 2.14.1]` | yes |

A range with neither bound matches every version, which is the correct reading
of a CVE whose CPE names no version.

## Postgres

`unmatched_lookups` is written with raw `INSERT ... ON CONFLICT ... DO UPDATE`
because `hit_count = hit_count + 1` is not expressible through the query
builder's `upsert`. That syntax is supported by Postgres and SQLite; it would
need rewriting for MySQL.

The test suite runs on SQLite. Verify schema and raw SQL changes against
Postgres before shipping.
