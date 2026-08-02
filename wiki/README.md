# Rozhanitsy

Vulnerability intelligence service. Ingests the NVD CVE feed, normalises it into
version ranges, and answers batched "is this component inventory vulnerable"
queries over HTTP.

| Document | Contents |
| --- | --- |
| [api.md](api.md) | Scanner API: auth, request, response, errors, rate limits |
| [cli.md](cli.md) | Artisan commands |
| [deployment.md](deployment.md) | Docker, environment variables, reverse proxy |
| [operations.md](operations.md) | Sync timings, disk, backups, migrations, monitoring |
| [schema.md](schema.md) | Tables, matching pipeline, CPE resolution |
| [web.md](web.md) | Web UI, accounts, admin |

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
