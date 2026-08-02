# Rozhanitsy

Vulnerability intelligence service. Ingests the NVD CVE feed, normalises it into
version ranges, and answers batched "is this component inventory vulnerable"
queries over HTTP.


## Documentation

Read more on the github [wiki](https://github.com/TadeasDitte/Rozhanitsy/wiki) for this repo

## Components

- Laravel 13 / PHP 8.5, Postgres 17.
- Inertia + Vue 3 web UI for account, token, and admin management.
- `nvd:sync` scheduled hourly; populates `vulnerabilities` and `vulnerability_ranges`.
- `vendors`/`products` are a curated catalog, not derived from NVD. Seeded with a
  starter set and extended via `/admin/products`; `nvd:sync` only links CVEs to
  products that already exist, it never creates them.
- `POST /api/vulns/check` authenticated with Sanctum bearer tokens bound to a scan host.

## Request flow

```
scanner ──POST /api/vulns/check──▶ cpe_map lookup ──▶ vulnerability_ranges join
                                        │                      │
                                   unmatched[]            VersionComparator
                                        │                      │
                                unmatched_lookups         vulnerable[]
```

Two database reads per request regardless of payload size. Version comparison is
in-memory. Fuzzy CPE resolution happens only during ingest, never on the request
path.


## Notice

This product uses the NVD API but is not endorsed or certified by the NVD.
