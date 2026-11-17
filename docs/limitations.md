# Limitations at 1.0.0

Everything here is known, not accidental. Each item says what is missing and what
it means for whoever deploys Keelson.

## Security and tenancy

- **No row-level security.** Isolation is enforced by repositories and voters, not
  by PostgreSQL. A query added later that forgets the tenant filter is a data leak;
  the protection is review plus the cross-tenant tests in
  `tests/Unit/Repository/TenantScopedRepositoryTest.php`. See
  [adr/0003](adr/0003-postgresql-row-level-security.md).
- **A denial is a `404`.** Members of another organization get "not found", never
  "forbidden". That is deliberate (no tenant enumeration), but it means logs and
  users cannot distinguish "does not exist" from "not yours".
- **No multi-factor authentication**, no social login and no enterprise SSO. Adding
  one means adding an identity provider beside the local accounts.
- **No email-change flow.** An address is fixed at registration; only the display
  name and password can change.
- **No session-management screen.** A password change invalidates other sessions
  through the stored hash, but there is no "signed-in devices" list and no
  "sign out everywhere" button.
- **Rate limits are per process and per minute** (sign-in by address and by client
  address). They use Symfony's rate limiter with the configured cache; a
  multi-instance deployment shares them only if that cache is shared.

## Product

- **No billing.** Subscriptions, plans, usage limits and Stripe are not implemented:
  no code, no tables, no webhooks. Any "billing" surface you remember was a mock.
- **One organization per invoice list.** Batch operations across organizations, an
  owner console across tenants and cross-tenant reporting do not exist.
- **Invoice emailing is manual.** "Send" records the transition and the audit event;
  it does not email the invoice to the customer. The PDF/print view is the browser's
  print dialog.
- **Numbers are computed on the server** but there is no invoice numbering policy
  screen: the sequence is per organization and assigned at creation.
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

- **Local latency is not production latency.** In this checkout the API's own HTTP
  floor (`/healthz`) measured p50 285.7 ms / p95 475.2 ms against the PHP built-in
  server, so the 100 ms p95 target for list endpoints is **not** met locally. The
  database query times are in [performance.md](performance.md); the HTTP numbers
  need a real web server and a warm opcache before they mean anything.
- **No metrics or tracing exporter.** Structured JSON logs and `/healthz`/`/readyz`
  are what exists; there is no Prometheus endpoint and no OpenTelemetry.
- **No queue worker.** Everything is synchronous per request; the overdue job is a
  cron/console command, not a message consumer.
- **Single database.** No read replicas, no connection pooling beyond php-fpm's.
- **`doctrine:schema:validate --env=test` fails** while introspecting the live test
  database: `An index with name "idx_6a2f2f95a5a280d" was already defined on table
  "invoices"`. DBAL 4.5 synthesises that name for the `fk_invoices_customer` local
  columns and the migration declares the same name, so introspection sees it twice.
  Mapping validation (`--skip-sync`) passes. Do not run `schema:update` or
  `migrations:diff` against that database until this is resolved.

## Repository

- **No licence file.** The terms of use are undecided; see the release notes.
- **`backend/.env.dev` and `backend/.env.phase7` exist in the history** with local
  development values (an `APP_SECRET` and a `postgresql://app:app@127.0.0.1`
  development URL). They were removed from the tree and are git-ignored now; the
  history was not rewritten. If those values were ever reused anywhere real,
  rotate them — the scan is recorded in the release notes.
- **No CI for Windows.** The workflows run on Ubuntu; the local scripts
  (`backend/bin/dev-reset.ps1`, `bin/test-db.sh`) cover Windows, but nothing
  verifies them automatically.

## Verification

What the release checks did and did not cover, so nobody reads more into them
than they show:

- **Coverage was not measured locally.** PHPUnit needs `pcov` or `xdebug` and this
  checkout has neither, so `bin/coverage-check.php` and the 80% per-directory rule
  in `phpunit.dist.xml` were **not run**. CI measures them on the `pcov` job.
- **The GitHub workflows were never executed.** The repository has not been
  pushed, so CI has not run once; `.github/workflows/ci.yml` is syntax-checked by
  reading it, nothing more.
- **No visual review.** The Playwright journeys drive the screens and assert on
  behaviour, but no screenshots were taken or reviewed in this release: how the
  light and dark themes and the 360 px and 1280 px layouts actually look is
  unverified.
- **The production proof ran on Docker Desktop for Windows**, on a fresh database
  with `APP_ENV=prod APP_DEBUG=0`, not on the eventual host. It covered
  registration, the verification mail through Mailpit, sign-in, a customer, an
  invoice, the send transition and the dashboard — but not TLS itself: HSTS was
  checked by sending `X-Forwarded-Proto: https` from a trusted proxy, since the
  stack has no certificate.
- **The 100 ms p95 target is unmet locally** (see Operations above) and no
  comparison against a production-sized dataset was made.
