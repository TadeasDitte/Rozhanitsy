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
| `vulnerability_ranges` | `id, vulnerability_id, product_id, match_confidence, version_start, version_start_incl, version_end, version_end_incl, raw_cpe` |
| `scan_hosts` | `id, user_id, hostname, is_active, last_seen_at` |
| `scan_logs` | `id, scan_host_id, tenant_id, component_count, vulnerable_count, unmatched_count, scanned_at` |
| `unmatched_lookups` | `id, cpe_vendor, cpe_product, hit_count, first_seen_at, last_seen_at` |

### sources

One row per upstream feed. `driver` names the code that parses it and is unique;
`nvd:sync` finds its row by `driver = 'nvd'`. Everything else about the feed —
endpoint, page size, request delays — is data on the row rather than constants
in the command. A row with a null `driver` is recorded but not syncable.

### cpe_map

Bridges NVD's CPE vendor/product naming to local products. Unique on
`(cpe_vendor, cpe_product)`, which also serves as the index for the live lookup.
`match_type` records how the mapping was established, `exact` or `fuzzy`.

### vulnerability_ranges

One row per `cpeMatch` entry in a CVE. `match_confidence` is `exact`, `fuzzy`, or
`unmatched`; the live check ignores `unmatched` rows. `raw_cpe` keeps the
original CPE string.

A CVE that names a concrete version in the CPE with no range keys is stored as
an inclusive point range (`version_start = version_end`), not as an unbounded
one.

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
2. Delete existing `vulnerability_ranges` for that CVE.
3. For each vulnerable `cpeMatch`, resolve the CPE and insert a range.

Deleting and rebuilding makes a re-sync idempotent rather than accumulating
stale rows.

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

## Matching

`POST /api/vulns/check` performs exactly two reads:

1. `cpe_map` filtered by the request's vendors and products, keyed in memory on
   `vendor|product`.
2. `vulnerability_ranges` joined to `vulnerabilities`, filtered to the matched
   product ids, excluding `unmatched`, grouped by product.

Comparison is then pure in-memory `VersionComparator`.

The first query uses two `whereIn` clauses rather than exact pair matching. That
over-fetches in principle, but the exact pairing is re-established by the in
memory key, and the alternative — 2000 OR'd `(vendor AND product)` groups — is
expensive for Postgres to plan.

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
