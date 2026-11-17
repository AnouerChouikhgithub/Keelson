# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
