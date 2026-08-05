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
| `vulnerabilities` | `id, cve_id, cvss_score, cvss_vector, cvss_version, cvss_severity, description, published_at, last_modified_at, source_id, raw_data, ghsa_checked_at, ghsa_ecosystem_mismatch` |
| `vulnerability_ranges` | `id, vulnerability_id, product_id, match_confidence, group_index, clause_index, version_start, version_start_incl, version_end, version_end_incl, raw_cpe, version_start_missing_since` |
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
scheduled job, nothing triggered by a sync. They come from `VendorSeeder` /
`ProductSeeder` (a small starter catalog, run by the Docker entrypoint on every
start — see [deployment.md](deployment.md)), from `/admin/products` (see
[web.md](web.md#products)), and from `nvd:promote-unmatched` (see below) — and
from nowhere else.

This is deliberate, not an oversight to route around. A `Product` carries a
`type` (`core`, `plugin`, `theme`, `extension`, `package`, `library`) that a
raw CPE string does not encode, and NVD's CPE dictionary has an entry for
essentially every piece of software ever assigned a CVE — auto-importing it
would flood the catalog with entries no tenant will ever report. `NvdCpeResolver`
only *links* CVEs to products that already exist; it has no path to invent one,
and never will — CVE volume is not a signal that any given tenant actually
runs the software in question.

Scan traffic is a different signal: a vendor/product pair reported by real,
authenticated scan hosts, repeatedly, is unambiguous evidence some tenant
actually runs it. `nvd:promote-unmatched` (see [cli.md](cli.md)) acts on that
signal alone, promoting `unmatched_lookups` pairs seen at least `--min-hits`
times into `Vendor`/`Product`/`cpe_map` rows, defaulting new products to type
`plugin`. It has no opinion on whether NVD has ever published a CVE for the
pair — it can't, `unmatched_lookups` carries no CVE data — so a promoted
product may sit with zero ranges until a future `nvd:sync` or the immediate
`nvd:relink` it runs afterward resolves something against it.

The consequence for everything else outside these three paths: a product with
no `Vendor`/`Product` row can never resolve, no matter how much CVE data is
synced. `nvd:sync --full` will not fix it, `nvd:sync` running hourly will not
fix it — the fuzzy matcher in [CPE resolution](#cpe-resolution) has nothing to
score against. The starter catalog covers common software so a fresh install
isn't matching against an empty table, but it is not exhaustive; anything
outside it reports `unmatched` until added by hand or promoted.

Plugins each get their own vendor slug (`elementor`/`elementor`,
`automattic`/`akismet`, `woocommerce`/`woocommerce`, `yoast`/`yoast_seo`,
`rocklobster`/`contact_form_7`) rather than being nested under a shared
platform vendor like `wordpress`. NVD assigns CPE identities per-plugin, not
per-platform, and `NvdCpeResolver`'s fuzzy match is vendor-weighted (0.4) —
a plugin seeded under the wrong vendor scores 0 on that half of the formula
regardless of how well the product name matches, and can never be rescued by
fuzzy matching. `nvd:promote-unmatched` follows the same convention for
whatever it creates, since it names the vendor directly from the scanner's
own `cpe_vendor` string.

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
2. Hand off to `VulnerabilityRangeBuilder::build()`, which rebuilds
   `vulnerability_ranges` for that CVE from `configurations`, resolving each
   `cpeMatch` and assigning `group_index`/`clause_index` per the rules in
   [vulnerability_ranges](#vulnerability_ranges).

Rebuilding is an upsert by `(group_index, clause_index, raw_cpe)`, not a blind
delete-then-recreate: a row whose identity still appears in the new
`configurations` is updated in place, and only rows whose identity no longer
appears are deleted. This makes a re-sync idempotent the same way delete-and-
recreate would, but it also lets `created_at` and
[`version_start_missing_since`](#stability-guard) survive an unchanged range
across resyncs — both would reset to "now" on every sync under a delete-and-
recreate strategy, which would defeat the stability guard entirely (every
sync would look like the shape was just observed for the first time).

`VulnerabilityRangeBuilder` is the only thing that parses `configurations` —
`nvd:sync` and `nvd:rebuild-ranges` (see [cli.md](cli.md)) both call it, one
from a live NVD response, the other from a `vulnerabilities.raw_data` already
on disk. They cannot drift apart because there's only one implementation.

### Stability guard

A `cpeMatch` with `versionEndExcluding`/`versionEndIncluding` but no start
bound at all is ambiguous: it can genuinely mean "vulnerable since release",
or it can mean NVD has not finished scoping the CVE yet and will add a floor
later. Trusting it immediately produces false positives against very old,
unaffected installs (see the Joomla CVEs this guard was built for — NVD
published several 2026 entries with only an end bound, floors added days
later).

`VulnerabilityRangeBuilder` does not guess a cutoff from NVD's own
`published`/`lastModified` metadata — a CVE can regress into this half-finished
shape at any point in its life, not just shortly after publication. Instead it
tracks how long *this installation* has observed the exact shape (`no start
bound`, `same end bound`) stably across its own resyncs, in
`version_start_missing_since`, and only trusts the range once that has held
for 14 days (`VulnerabilityRangeBuilder::GRACE_PERIOD_DAYS`). Until then,
`match_confidence` is forced to `unmatched` regardless of what
`NvdCpeResolver` returned — see `nvd:pending-review` in
[cli.md](cli.md) to see what's currently held back and why.

The shape resetting is the self-healing part: the moment NVD fills in a real
start bound, or the end bound changes, `version_start_missing_since` resets to
`now()` on the next sync (see the upsert identity above — same
`raw_cpe`/`group_index`/`clause_index`, different `version_start`/`version_end`,
counts as a shape change). A genuinely floor-less range keeps re-confirming
the same shape every sync and graduates permanently once 14 days pass; a
transient one corrects itself as soon as NVD does.

### GHSA cross-check

NVD's CPE match can point a library CVE at the wrong product when the library
shares a vendor slug with a platform it's associated with. CVE-2025-25226 is
the case this exists for: NVD's CPE match is
`cpe:2.3:a:joomla:joomla\!:*` — the Joomla CMS itself — but the CVE is
actually about the standalone `joomla/database` Composer package, which GHSA
correctly lists under the `composer` ecosystem. Left alone, that CVE
attributes to every scanned Joomla CMS install regardless of whether it uses
that library at all.

`nvd:cross-check-core` (see [cli.md](cli.md)) checks every `Vulnerability`
with at least one range resolved to a `type = core` product against GitHub's
Security Advisory API. If GHSA independently tags the CVE under a package
ecosystem, that's strong evidence the CPE match is wrong, and the verdict is
stored on `vulnerabilities.ghsa_ecosystem_mismatch` — permanently, not just for
the ranges that exist at check time. `ghsa_checked_at` records when the check
last ran so a plain `nvd:cross-check-core` skips CVEs already checked;
`--force` re-checks them.

`VulnerabilityRangeBuilder` consults `ghsa_ecosystem_mismatch` on every
rebuild: if set, any range that resolves to a `core` product is forced to
`match_confidence = unmatched`, the same as an unresolved one. Without this,
the next `nvd:sync` or `nvd:rebuild-ranges` would recompute confidence from
`NvdCpeResolver` alone and silently undo the downgrade — the flag exists
specifically so the fix survives ingestion, not just the run that found it.

A GHSA check that fails (rate limited, network error, no record at all beyond
a plain 404) leaves `ghsa_checked_at` untouched rather than recording a false
"clean" verdict, so it's retried on the next run instead of being silently
skipped forever.

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
