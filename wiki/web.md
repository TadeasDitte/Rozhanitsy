# Web UI

Inertia and Vue 3. Sessions are database-backed.

## Routes

| Method | Path | Access |
| --- | --- | --- |
| GET | `/` | public |
| GET | `/dashboard` | authenticated |
| GET | `/tokens` | authenticated |
| POST | `/tokens` | authenticated |
| POST | `/tokens/{scanHost}/regenerate` | owner |
| DELETE | `/tokens/{scanHost}` | owner |
| GET | `/admin` | administrator |
| GET | `/admin/users` | administrator |
| POST | `/admin/users/{user}/admin` | administrator |
| DELETE | `/admin/users/{user}/admin` | administrator |
| DELETE | `/admin/scan-hosts/{scanHost}/token` | administrator |
| GET | `/admin/products` | administrator |
| POST | `/admin/products` | administrator |
| DELETE | `/admin/products/{product}` | administrator |
| GET | `/settings/profile` | authenticated |
| GET | `/settings/security` | authenticated |

Ownership on the `/tokens` routes is enforced against `scan_hosts.user_id`;
another user's host, or a host created via the CLI with no owner, returns 403.

## Accounts

Registration is open. The first account created becomes an administrator; later
ones do not. Use `user:admin` or the users tab to grant access to others.

Email verification is not enforced. Fortify's `emailVerification` feature is
listed in `config/fortify.php`, but `User` does not implement `MustVerifyEmail`,
so no verification mail is sent and `email_verified_at` stays null. The
`verified` middleware on the routes above is therefore a no-op. Enabling it
means implementing the interface and configuring a mail transport; until then
treat all accounts as unverified.

Two-factor authentication and passkeys are available under `/settings/security`.

## Tokens

`/tokens` lists the signed-in user's scan hosts and issues bearer tokens.

Generating a token registers a scan host with the given hostname, which must be
unique across the installation and match `^[A-Za-z0-9][A-Za-z0-9._-]*$`. The
plain-text token is shown once, on the redirect after creation, and is not
recoverable.

Regenerate deletes existing tokens, issues a new one, and reactivates the host.
Revoke deletes the tokens and sets `is_active = false`, keeping the host row and
its scan history. A revoked host can be brought back with Regenerate.

## Dashboards

`/dashboard` covers the signed-in user's own hosts over the last 30 days: host
and active counts, scan count, components checked, vulnerable and unmatched
totals, plus the ten most recent scans.

`/admin` is system-wide: user and administrator counts, hosts, tracked vendor
and product counts, CVEs and matched ranges, 30 day scan volume, the last
`nvd:sync` watermark, the ten most requested coverage gaps from
`unmatched_lookups`, and recent scans across all hosts.

The coverage gap table is the same data as `nvd:unmatched`. Each row links to
`/admin/products` with the vendor and product pre-filled, so closing a gap is
one click from the row that reported it.

## Administration

`/admin/users` lists every account with the scan hosts it owns.

Make admin and Remove admin toggle `users.is_admin`. Two constraints are
enforced server-side, not just hidden in the UI:

- the last remaining administrator cannot be demoted;
- an administrator cannot demote themselves.

Both return a validation error rendered above the user list. Losing all
administrator access therefore requires deliberate action; if it happens anyway,
`php artisan user:admin <email>` restores it.

Administrators can revoke any scan host's token, including hosts created via the
CLI that belong to no account.

### Products

`/admin/products` lists tracked vendors with their products, and is the only
way to grow the catalog beyond `VendorSeeder`/`ProductSeeder` without shelling
in. See schema.md's [Vendors and products](schema.md#vendors-and-products) for
why this table isn't populated automatically.

Adding a product takes a vendor name, a product name, and a `type`
(`core`/`plugin`/`theme`/`extension`/`package`/`library`). The vendor is
matched by slug and reused if it already exists, so adding a second WordPress
plugin does not create a second WordPress vendor. Duplicating an existing
vendor/product pair is a validation error, not a silent no-op.

Deleting a product cascades its `cpe_map` rows and nulls `product_id` on any
`vulnerability_ranges` that pointed at it — those ranges fall back to
unresolved rather than disappearing.

Adding a product does not retroactively fix CVEs already stored as unmatched;
run `php artisan nvd:relink` afterwards (see [cli.md](cli.md)).

## Theme

Light and dark share one palette: white or near-black backgrounds, grey muted
text, and a blood red accent (`#af1d1d` light, `#c52020` dark) used for primary
actions, active states, and non-zero vulnerability counts. Defined as CSS custom
properties in `resources/css/app.css`; components consume the tokens, so
retheming is a variable change. The appearance toggle under
`/settings/appearance` switches between light, dark, and system.
