# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] — 2026-11-17

A hardening release on top of 1.0.0. No API shape changed except the new
`subdomain_unavailable` error (below); every fix has a regression test.

### Fixed

- **Token redemption was not atomic.** Verification and reset links were read and
  then marked used in two statements, so two concurrent requests could both
  succeed. Redemption is now one `UPDATE ... WHERE used_at IS NULL AND
  expires_at > now() RETURNING principal_id`, in the same transaction as the
  account change; a failed change rolls the consumption back.
- **The last-owner check could race.** Two Owners demoting or removing each other
  could leave an organization ownerless. Owner-sensitive member changes now take
  a per-organization advisory lock inside a transaction and re-read the roster
  before counting Owners.
- **Registration treated every unique violation as "address exists".** A losing
  subdomain race answered `201 verification_sent` for an account that was rolled
  back. Duplicate addresses still answer identically (enumeration safety), but a
  subdomain collision is now an explicit `409 subdomain_unavailable`, and the
  failed attempt leaves no principal, organization or token behind.
- **`doctrine:schema:validate` aborted on introspection.** PostgreSQL folded the
  uppercase index name DBAL generates for `invoices.customer_id`, so the index
  was seen twice. Migration `Version20261117180000` renames it to
  `idx_invoices_customer`; the check reports a diff (runtime-only objects such as
  `sessions` and GIN/partial indexes have no ORM mapping) instead of crashing, and
  `--skip-sync` — the CI gate — passes.
- **CI could not pass three jobs.** The static analysis read a dev container that
  CI never builds; the e2e database was named `keelson`, which the fixture guard
  correctly refuses; and the audit job ran `composer audit` without installed
  packages. The workflow now warms the test container and reads both XML paths,
  uses `keelson_e2e_test`, and runs `composer audit --locked` alongside both pnpm
  audits — all still blocking.

### Added

- **Cross-tab session synchronisation.** `BroadcastChannel` (with a `storage`
  fallback) tells the other tabs that the session changed; each tab re-reads
  `GET /api/session`, and a changed principal clears cached tenant reads. A tab
  revalidates when it becomes active again, and a `401` triggers a re-read rather
  than a guessed state.
- **Backup and restore scripts** (`backend/bin/db-backup.sh`,
  `backend/bin/db-restore.sh`) with archive verification, retention, an in-place
  restore guard and a single-transaction restore; both were exercised against a
  disposable PostgreSQL 16.
- **Regression suites:** token redemption (replay, expiry, rollback, two
  connections), last-owner protection (demotion, removal, admin boundary, lock),
  registration constraints (suffix allocation, exhausted subdomains), cross-tab
  session behaviour and the session signal codec.

### Changed

- **Same-origin only.** `VITE_API_BASE_URL` and the matching Docker build
  argument were removed: no CORS exists on the API, so advertising a cross-origin
  build produced requests the browser always refused (ADR 0006).
- **CI is stricter**: `composer validate --strict`, Doctrine mapping validation,
  a migration up-to-date check, a pre-flight API health check for the e2e job, and
  an audit job that fails if any of the three audits reports a problem.
- `composer.json` gained a name and description so `composer validate --strict`
  passes; no dependency changed.

## [1.0.0] — 2026-11-17

The first release. Everything below arrived across nine sprints; the list is
grouped by what a reader needs to know, not by the order the work happened.

### Added

- **First-party accounts** (sprint 2): registration with its first organization,
  mailed address verification, sign-in, sign-out, forgot/reset password, password
  change, hashed single-use tokens, sign-in rate limiting, and a server-side
  session stored in PostgreSQL.
- **Authentication screens** (sprint 3): sign-in, sign-up, verification, forgot and
  reset, with session-expiry handling, inline field errors and a live region for
  asynchronous failures.
- **Demo identities that can sign in** (sprint 4): an idempotent fixture loader with
  a target guard that refuses production, non-PostgreSQL, non-loopback and
  non-dev/test databases.
- **Team and invitations** (sprint 5): memberships with four roles, role changes,
  member removal, invitations issued/resent/revoked/accepted, a public invitation
  preview, and notifications with read state.
- **Dashboard and customer/invoice API** (sprint 5): tenant-scoped customers,
  invoices with line items, tax and discount, the draft → sent → paid lifecycle, an
  overdue transition, an audit trail per invoice, cursor pagination, and `q` search.
- **Security hardening** (sprint 6): membership-based voters on every tenant route,
  `404` for cross-tenant access, CSRF + same-origin enforcement on unsafe requests,
  security headers, structured logs with redaction, and the production
  configuration validator (`app:config:check`).
- **Frontend completion** (sprint 7): every screen in `docs/ui-spec.md` on real API
  data, English/French/Arabic with right-to-left layout, light/dark/system themes,
  keyboard-navigable dialogs and drawers, and a search dialog.
- **Quality gates** (sprint 8): jsdom component tests, Playwright journeys over the
  real stack, PHPStan level 8 with no baseline, php-cs-fixer, a per-namespace
  coverage gate at 80%, an OpenAPI 3.1 document kept in step with the router by a
  test, a bundle-size budget, and a CI workflow that runs all of it with actions
  pinned to commit SHAs.
- **Performance work** (sprint 8): `app:fixtures:bulk` (1,000 customers, 10,000
  invoices), `bin/perf.php`, measured p50/p95 per list endpoint, and the `pg_trgm`
  indexes behind substring search.
- **Release packaging** (sprint 9): multi-stage images for the API (php-fpm,
  non-root, opcache) and the SPA (nginx with SPA fallback, CSP and long-lived
  hashed assets), `docker-compose.prod.example.yml`, a least-privilege database role
  created by an init script, `.dockerignore` files, and the docs in this repository:
  README, deployment, contributing, security, limitations and five ADRs.
- **Dead-code tooling** (sprint 9): `shipmonk/dead-code-detector` as a PHPStan
  extension and `knip` for the frontend, plus `scripts/unused-i18n.mjs` for the
  dictionaries.

### Fixed

- **Session locking on a shared connection** (sprint 9): the session handler borrowed
  Doctrine's PDO and Symfony's default transactional locking opened a transaction
  DBAL could not see, so the first write of any request over HTTP died with
  "There is already an active transaction". Fixed by using advisory locking, which
  keeps the one-request-per-session guarantee without a transaction. Found by the
  browser journey for sign-up.
- **Invoice status after marking paid** (sprint 9): the detail screen remounted
  itself instead of reloading, so the badge stayed on "Sent" and the new audit row
  never appeared. Found by the invoice lifecycle journey.
- **Focus after a rejected sign-in** (sprint 8): auth inputs were disabled while a
  request was in flight, so the focus-first-invalid-field behaviour could never
  focus the rejected field.

### Changed

- **Deletions** (sprint 9): 35 unused backend members, an unused frontend hook, four
  unused exports, four unused type aliases, 32 unused dictionary keys in three
  locales, the CSS of the removed backend-gate screen, and two dev dependencies
  nothing imported (`symfony/maker-bundle`, `symfony/css-selector`).
- **Schema** (sprint 8): three GIN trigram indexes on the search columns, applied by
  a versioned migration.
- **`AGENTS.md`, `README.md` and the roadmap** now describe the shipped code rather
  than the state of sprint 1.

### Known limitations

See [docs/limitations.md](docs/limitations.md). The important ones: tenancy is
enforced in the application and not by row-level security; there is no billing, no
multi-factor authentication and no email-change flow; the measured HTTP latency in
this checkout does not meet the 100 ms p95 target because it was measured against
the PHP built-in server.

### Notes for operators

- `backend/.env.dev` and `backend/.env.phase7` existed in the git history with local
  development values. They are gone from the tree and ignored; the history was not
  rewritten. If either value was ever reused outside a local container, rotate it.
- There is no `LICENSE` file: the licence is an open decision for the owner.

[1.0.0]: https://example.invalid/keelson/releases/tag/v1.0.0
