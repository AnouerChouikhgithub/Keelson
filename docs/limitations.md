# Limitations at 1.0.x

Everything here is known, not accidental. Each item says what is missing and what
it means for whoever deploys Keelson.

## Security and tenancy

- **Row-level security covers the tenant tables, not the identity tables.**
  `customers`, `invoices`, `invitations` and `notifications` are `ENABLE` **and**
  `FORCE ROW LEVEL SECURITY` with a policy on the transaction-local
  `app.organization_id`; `principals`, `memberships`, `auth_tokens` and `sessions`
  deliberately are not, because they are read before a tenant is known. Isolation
  for those stays application-layer. See
  [adr/0003](adr/0003-postgresql-row-level-security.md).
- **An unscoped query returns no rows, not all rows.** That is the point, but it
  means a console command or a fixture that touches a tenant table must use
  `App\Tenancy\TenantContext::run()`; without it the write is rejected and the read
  is empty.
- **`/readyz` fails when the connecting role bypasses the policies.** A superuser or
  a role with `BYPASSRLS` is exempt from every policy, so the probe answers `503`
  with `checks.rowLevelSecurity = bypass`. The dev Compose database's `app` role is
  the bootstrap **superuser**, so a plain local stack answers `503` there; the
  production split in [deployment.md](deployment.md) is what makes it `ok`.
- **The database suite still runs as the schema owner in CI.** That role is a
  superuser, so it bypasses the policies and the suite does not exercise them
  globally; the RLS behaviour is asserted by
  `tests/Integration/RowLevelSecurityTest.php` on a dedicated non-`BYPASSRLS` probe
  connection. Running the whole suite as `keelson_app` needs every suite that seeds
  tenant rows to wrap those writes in `TenantContext` — a follow-up, not a claim.
  `invoice_lines` has no table today (line items live in `invoices.line_items`); the
  migration applies the policy if one is ever introduced.
- **A denial is a `404`.** Members of another organization get "not found", never
  "forbidden". That is deliberate (no tenant enumeration), but it means logs and
  users cannot distinguish "does not exist" from "not yours".
- **No multi-factor authentication**, no social login and no enterprise SSO.
- **No email-change flow.** An address is fixed at registration; only the display
  name and password can change.
- **No session-management screen.** A password change invalidates other sessions
  through the stored hash, but there is no "signed-in devices" list and no "sign
  out everywhere" button.
- **Rate limits are per process and per minute** (sign-in by address and by client
  address). They use Symfony's rate limiter with the configured cache; a
  multi-instance deployment shares them only if that cache is shared.
- **Same origin only.** A SPA served from a different host than the API is not
  supported: there is no CORS configuration and the client has no API base URL
  ([adr/0006](adr/0006-same-origin-only.md)).

## Product

- **No billing.** Subscriptions, plans, usage limits and Stripe are not implemented:
  no code, no tables, no webhooks.
- **One organization per list.** Batch operations across organizations, an owner
  console across tenants and cross-tenant reporting do not exist.
- **Invoice emailing is manual.** "Send" records the transition and the audit event;
  it does not email the invoice to the customer. The PDF/print view is the browser's
  print dialog.
- **Tax is a single rate per invoice** with three-decimal TND arithmetic and no
  jurisdiction rules. Treat rates, rounding and retention as product decisions,
  not tax advice.
- **Numbers are computed on the server** but there is no invoice numbering policy
  screen: the sequence is per organization and year, assigned at creation.
- **Notification delivery is in-app only** — no digest email, no webhook.
- **Search is substring-based** (`ILIKE` with trigram indexes), not full-text: no
  ranking, no stemming, no multilingual tokenisation.

## Frontend

- **No server-side rendering.** The SPA is a static build; the first paint waits for
  JavaScript. There is no SEO story because there is no public marketing surface.
- **One locale per page load.** Switching language re-renders, but the backend
  emails are English-only.
- **Bundle budget, not code splitting per route.** Screens are lazy where it paid
  off (invoices, team); the entry chunk still carries the shell and the dictionaries
  for all three languages.
- **No offline mode and no PWA.** No service worker is registered.

## Operations

- **Local latency is not production latency.** The API's own HTTP floor measured
  against the PHP built-in server does not meet the 100 ms p95 target; database
  query times are in [performance.md](performance.md) and the HTTP numbers need a
  real web server with a warm opcache before they mean anything.
- **No metrics or tracing exporter.** Structured JSON logs and `/healthz`/`/readyz`
  are what exists; there is no Prometheus endpoint and no OpenTelemetry.
- **No queue worker.** Everything is synchronous per request; the overdue job is a
  cron/console command, not a message consumer.
- **Single database.** No read replicas, no connection pooling beyond php-fpm's.
- **No zero-downtime migrations.** A migration that rewrites a large table locks
  it; run such changes in a window or split them into expand/contract steps.
- **`doctrine:schema:validate --skip-sync` is the mapped-schema gate; the full
  check reports a diff by design.** The schema deliberately contains objects the
  ORM cannot express: the framework-managed `sessions` table, GIN `pg_trgm` indexes
  and partial indexes. The former hard failure
  (`An index with name "idx_6a2f2f95a5a280d" was already defined on table
  "invoices"`) was a real bug: PostgreSQL had folded the uppercase name DBAL
  generates for the foreign key, so introspection added it twice. Migration
  `Version20261117180000` renames it to `idx_invoices_customer`; the check now
  reports a diff instead of aborting, and `--skip-sync` passes. Do not treat
  `migrations:diff` output as an instruction — the hand-written indexes and
  triggers are intentional.
- **Backups are a script and a documented procedure, not a managed service.**
  `backend/bin/db-backup.sh` and `backend/bin/db-restore.sh` were exercised
  against a disposable PostgreSQL 16 in this environment (see
  [deployment.md](deployment.md)); off-host retention and encryption are the
  operator's responsibility.

## Repository

- **No licence file.** The terms of use are undecided; see the release notes.
- **`backend/.env.dev` and `backend/.env.phase7` exist in the history** with local
  development values. They were removed from the tree and are git-ignored now; the
  history was not rewritten. If those values were ever reused anywhere real,
  rotate them.
- **No CI for Windows.** The workflows run on Ubuntu; the local scripts
  (`backend/bin/dev-reset.ps1`, `bin/test-db.sh`) cover Windows, but nothing
  verifies them automatically.
- **`minimatch` (moderate, build-time only)** is pinned below its patched release by
  the ESLint line; lifting it needs a major ESLint/`typescript-eslint` upgrade.

## Verification status

What the release checks did and did not cover, so nobody reads more into them
than they show:

- **Coverage is measured in CI** (`pcov` + `backend/bin/coverage-check.php`, 80%
  for Service/Security/Controller). It is not measured locally on a machine
  without `pcov`.
- **Email delivery to a real provider was not verified.** Mailpit caught every
  message locally; `MAILER_DSN` to a real SMTP endpoint is untested here.
- **TLS was not terminated in this environment.** HSTS and `Secure` cookies were
  checked by sending `X-Forwarded-Proto: https` from a trusted proxy.
- **No external identity provider exists**, so "missing identity-provider
  configuration" cannot break sign-in: the flow is first-party email + password.
- **No visual review of every screen at every breakpoint.** The Playwright
  journeys assert behaviour and the layouts are responsive by construction; the
  release report records what was actually rendered and checked.
- **The production stack is proven with Compose locally**, not on the eventual
  host; the real host decides `TRUSTED_PROXIES`/`TRUSTED_HEADERS` and where TLS
  terminates.
