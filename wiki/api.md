# Scanner API

One endpoint. Everything else in the application is UI or ingest.

```
POST /api/vulns/check
```

## Authentication

Sanctum bearer token, issued per scan host.

```
Authorization: Bearer 1|O77LnzVevd7pQ...
```

Tokens are created in the web UI (`/tokens`) or via `scan-host:create`. The
plain-text value is shown once and is not recoverable afterwards.

The `sanctum` guard is bound to the `scan_hosts` provider and `sanctum.guard` is
empty, so a browser session or a token belonging to any other model will not
authenticate this endpoint. A token whose scan host has `is_active = false` is
rejected.

Failure modes:

| Condition | Status |
| --- | --- |
| Missing or malformed token | 401 |
| Token revoked, or host deactivated | 401 |
| Validation failure | 422 |
| Rate limit exceeded | 429 |

## Request

```json
{
  "tenant_id": "p1234",
  "min_cvss_score": 7,
  "severity": ["high", "critical"],
  "confidence": "bounded",
  "components": [
    {
      "vendor": "automattic",
      "product": "akismet",
      "version": "5.3.1",
      "local_id": "wp-content/plugins/akismet"
    }
  ]
}
```

| Field | Rules |
| --- | --- |
| `tenant_id` | nullable, string, max 64 |
| `min_cvss_score` | nullable, numeric, 0–10 |
| `severity` | nullable, array of `low`\|`medium`\|`high`\|`critical` (case-insensitive) |
| `confidence` | nullable, `bounded`\|`all` (case-insensitive), default `all` |
| `components` | required, array, 1–2000 entries |
| `components[].vendor` | required, string, max 191 |
| `components[].product` | required, string, max 191 |
| `components[].version` | required, string, max 64 |
| `components[].local_id` | nullable, string, max 191 |

`tenant_id` identifies a tenant directory on a hosting panel. It is free-form —
there is no `pXXXX` format requirement — and omitting it is the correct
behaviour for a standalone install.

`min_cvss_score` and `severity` filter the `vulnerable` array before it's
returned; they never affect `unmatched`. Both apply to the CVE as a whole, not
to an individual match, and combine as an AND when both are given. A CVE with
no CVSS score at all (common for pre-2015 NVD entries) never satisfies either
filter and is excluded whenever one is active — it's a known-severity filter,
not an "assume the worst" one.

`confidence: "bounded"` drops findings whose `confidence` would otherwise come
back `unbounded` (see the `confidence` field below), keeping only matches
against a real, structured version range. Use it to cut the pre-2015-NVD noise
out of a report entirely instead of filtering it client-side. The default,
`"all"`, is today's behaviour — both tiers are reported.

`local_id` is opaque passthrough. The server never reads or matches on it; it is
echoed back on every result so the caller can correlate findings with its own
inventory.

`vendor` and `product` are matched case-insensitively. CPE names are lowercase by
spec, and both sides are folded before lookup.

## Response

```json
{
  "tenant_id": "p1234",
  "vulnerable": [
    {
      "vendor": "automattic",
      "product": "akismet",
      "local_id": "wp-content/plugins/akismet",
      "installed_version": "5.3.1",
      "cve_id": "CVE-2026-0001",
      "cvss_score": 9.8,
      "cvss_vector": "CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H",
      "cvss_severity": "CRITICAL",
      "confidence": "bounded"
    }
  ],
  "unmatched": [
    {
      "vendor": "acme",
      "product": "widget",
      "local_id": "plugins/widget"
    }
  ],
  "checked_at": "2026-08-02T11:28:14+00:00"
}
```

`tenant_id` is echoed as sent, including `null`.

`vulnerable` contains one entry per component/CVE pair. A CVE that matches a
component through several CPE ranges is reported once for that component.

Some CVEs only apply when two components are both present — e.g. a plugin
that requires a specific CMS as its platform. For those, `vulnerable` reports
the component that's actually vulnerable (the one with a real version range,
or at minimum an ambiguous but genuine "any version" claim), not the
platform-requirement component, and only once every such requirement is
satisfied somewhere in the submitted inventory. If nothing in a satisfied
group is more than a platform requirement, nothing is reported for it — a
component is never named as vulnerable on the strength of a CPE entry that
NVD itself marked "not applicable". See schema.md's
[Matching](schema.md#matching).

`confidence` is `bounded` (the CVE's CPE entry names a real version range) or
`unbounded` (no version range at all — NVD's CPE data doesn't scope which
versions are affected, so this is reported against every installed version of
the product as a precaution). `unbounded` skews toward CVEs older than NVD's
structured version-range fields (roughly pre-2015) where the real fix version,
if any, only exists in the free-text description — treat these as worth a
manual look rather than an automatic finding. A third internal tier,
`platform`, is never returned here; see schema.md's
[Matching](schema.md#matching) for what it means and why it's suppressed
instead of reported.

`unmatched` lists components with no `cpe_map` entry. It is not an error — it
means no mapping exists yet. Vendor and product are echoed with the caller's
original casing. Each distinct pair is also recorded in `unmatched_lookups` with
an incrementing hit count, which is the coverage worklist surfaced by
`nvd:unmatched` and on the admin dashboard.

A component that resolves to a product with no matching CVE range appears in
neither array.

## Rate limits

30 requests per minute, keyed by `tenant_id` when present and by client IP
otherwise.

Keying on the tenant rather than the token is deliberate: a fleet may share one
scanner token across many tenants, and keying on the token would put every
tenant into a single budget. One tenant exhausting its budget does not affect
another on the same token.

Because the key comes from the request body, a client can widen its own
throughput by varying `tenant_id`. Authentication is the gate; the limiter
provides per-tenant fairness, not abuse prevention.

Behind a reverse proxy, `TRUSTED_PROXIES` must be set or the IP fallback
collapses every standalone install into one bucket. See
[deployment.md](deployment.md).

## Examples

Panel host, one tenant directory:

```bash
curl -X POST https://rozhanitsy.example.com/api/vulns/check \
  -H "Authorization: Bearer $SCAN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "tenant_id": "p1234",
    "components": [
      { "vendor": "automattic", "product": "akismet",     "version": "5.3.1", "local_id": "plugins/akismet" },
      { "vendor": "wordpress",  "product": "wordpress",   "version": "6.4.2", "local_id": "core" }
    ]
  }'
```

Standalone install, no tenant:

```bash
curl -X POST https://rozhanitsy.example.com/api/vulns/check \
  -H "Authorization: Bearer $SCAN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"components":[{"vendor":"nginx","product":"nginx","version":"1.24.0"}]}'
```

Only critical and high severity findings:

```bash
curl -X POST https://rozhanitsy.example.com/api/vulns/check \
  -H "Authorization: Bearer $SCAN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "severity": ["high", "critical"],
    "components": [{ "vendor": "nginx", "product": "nginx", "version": "1.24.0" }]
  }'
```

Validation error:

```json
{
  "message": "The components field is required.",
  "errors": { "components": ["The components field is required."] }
}
```

## Batching

Send one request per tenant directory with the whole inventory, not one request
per component. The 2000-entry cap is the intended working size; matching cost
does not scale with the number of components.
