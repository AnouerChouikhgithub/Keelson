# Security notes

## Dependency audit (2026-11-16)

| Ecosystem | Command | Result |
| --- | --- | --- |
| PHP | `composer audit` | No security vulnerability advisories found. |
| JavaScript | `pnpm audit` | 1 moderate: `minimatch` (GHSA-g3hv-75wg-m249), patched in `>=11.0.1`. |

`minimatch` is transitive, pulled in by ESLint (`eslint`,
`@eslint/config-array`, `eslint-plugin-react-hooks`) across 37 paths. It is a
build-time dependency only — it never ships to a browser — and the advisory is
ReDoS in pattern compilation. `pnpm update minimatch` reports "Already up to
date": the installed ESLint line pins a range below the patched release, so
lifting it would require a major ESLint upgrade (and a matching
`typescript-eslint`), which needs approval and is out of scope for this sprint.

## Headers and cookies

- API responses send `nosniff`, `strict-origin-when-cross-origin`,
  `camera=(), microphone=(), geolocation=()`, `default-src 'none'; frame-ancestors 'none'`,
  `no-store, private` when authenticated, and HSTS only in production over HTTPS.
- Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` whenever the
  request is HTTPS (`cookie_secure: auto`). Sessions are stored in PostgreSQL
  (`sessions` table), so a stolen file or a new worker cannot resurrect them.
- The frontend's CSP (which necessarily allows scripts) is added by the reverse
  proxy; see `docs/deployment.md`.

## Input handling

- Unsafe `/api` requests must declare `Content-Type: application/json` when they
  declare anything at all; an explicit form or text type is `415`.
- Bodies over 1 MiB are `413`, JSON nested deeper than 16 levels is `400`.
- Validation errors use the shared `{error:{code,message,fields}}` envelope.

## Security trail

`security_events` records `login_success`, `login_failure`,
`password_changed` and `invitation_accepted` with IP, truncated user agent,
request id and a small data map. A failed sign-in stores
`emailFingerprint` (16 hex characters of SHA-256), never the address; passwords
and tokens are never passed to the recorder, and a test scans the table for both.

## Not implemented in this sprint

- PostgreSQL row-level security and the `keelson_owner` / `keelson_app` role
  split were specified but are **not** in place; tenant isolation remains
  application-layer. The blocker and the plan are described in the sprint 6
  pull request: every existing integration suite seeds two tenants through one
  connection, and the anonymous invitation preview reads `invitations`, so
  enabling `FORCE ROW LEVEL SECURITY` first requires seeding as the owner role
  and a `SECURITY DEFINER` projection for the preview. Shipping the policies
  without that refactor would have broken the suite and the invitation link.
- The data-driven IDOR sweep over every `{organizationId}`/`{customerId}`/… route
  is not included; the per-endpoint cross-tenant tests from Sprint 5 remain the
  coverage for those routes.
