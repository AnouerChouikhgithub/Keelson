# Keelson

Keelson is a multi-tenant SaaS starter with organization membership and an invoicing demonstration. It has a working local backend for organization-scoped customers, invoices and audit events, plus a frontend dashboard shell that renders **mock data only** and does not call the API.

Brand color: `#0F766E`.

## Status (verified 2026-11-14)

- **Frontend** — React 19.3.1, Vite 8.3.4, TypeScript 6.0.3. Dashboard, organization switcher, mock onboarding, account screen, notifications, settings, a local-only search dialog and EN/FR/AR localization with RTL, plus light/dark/system theme. Browser preferences only; no API calls, no server persistence, no auth.
- **Backend** — Symfony 7.4 (PHP >=8.2), Doctrine ORM 3 / DBAL 4 / Migrations, Drenso OIDC bundle. Implemented: OIDC session firewall with local principal provisioning, membership-based authorization voters, tenant-scoped organization/membership/invitation reads, and a customer/invoice/audit API (`draft → sent → paid`, derived overdue, TND millime arithmetic) whose draft edits, deletions and status transitions take a row lock so a concurrent send cannot be overwritten.
- **Fixtures** — `backend/src/DataFixtures/` plus `php bin/console app:fixtures:load --env=test --force` seed idempotent, entirely synthetic demo identities, two organizations with all four roles, customers and draft/sent/overdue/paid invoices. The command refuses `prod`, non-loopback hosts and non-test/dev database names, never deletes data, and creates no login credentials.
- **Sign-in redirect fixed (2026-11-13)** — `symfony/translation-contracts ^3.8` is now declared; its absence had made `GET /login` throw while building the PKCE challenge. `GET /login` now returns `302` to the authorization endpoint with `state`, `nonce`, `code_challenge` and `code_challenge_method=S256` (verified against a temporary local discovery stub, then the stub was removed). Real Logto sign-in/callback/logout is still unverified: it needs a configured test client and a reachable provider.
- **Local startup (verified 2026-11-14)** — `npm start` in `frontend/` serves Vite on `127.0.0.1:5173` (HTTP 200); `symfony serve` in `backend/` serves the API, and the public `GET /healthz` probe now answers 200 without OIDC configuration because it has its own stateless firewall. Compose services start as `keelson-db-1`, `keelson-redis-1` and `keelson-mailpit-1` with the persistent `db_data` volume untouched.
- **Verified (current run, 2026-11-14)** — `backend/bin/test-db.sh` started a newly labelled, volume-free `postgres:16-alpine` container (PostgreSQL 16.15, tmpfs data, ephemeral loopback port). The full suite passed: **52 tests / 229 assertions**, exit 0. This includes the CSRF-protected logout/session invalidation test. The helper-owned container and generated ignored env file were removed afterwards. The Compose `keelson` project and persistent `keelson_db_data` volume were not used for tests.
- **CI (added 2026-11-14)** — `.github/workflows/ci.yml` runs frontend build/lint, backend validation plus the PostgreSQL PHPUnit suite, and report-only dependency audits. The YAML was validated locally; the workflow has not yet run on GitHub-hosted runners.
- **Not implemented or not verified** — PostgreSQL RLS, real Logto sign-in/callback/logout, billing and usage enforcement (Phase 8 is a scope record only), email/invitation delivery, frontend↔API integration, automated frontend tests, and most `docs/ui-spec.md` screens. Treat tenant isolation as application-layer scoping, not production hardening.

## Run locally

See **[how-to-run.md](how-to-run.md)** for prerequisites, environment setup, database isolation, migrations, start commands and test commands. It is the single detailed run guide; do not duplicate its steps here.

Companion docs:

- [frontend/README.md](frontend/README.md) — frontend scope, stack, commands, mock-data limits.
- [backend/README.md](backend/README.md) — OIDC configuration, API surface, database safety, isolated PostgreSQL test procedure.
- [DEVELOPMENT_ROADMAP.md](DEVELOPMENT_ROADMAP.md) — per-phase status and evidence.
- `docs/ui-spec.md` — UI specification; it does not prove backend behavior.
- `docs/ORCHESTRATION_READINESS.md` — superseded 2026-10-20 audit snapshot, kept for history.

Quick orientation: `frontend/` (Vite app), `backend/` (Symfony API), `docker-compose.yml` (Compose project `keelson`: PostgreSQL 16, Redis 7, Mailpit — Redis and Mailpit are not used by the code yet). Symfony and the frontend run on the host, not in Compose.

## Checks

| Check | Result (2026-11-14) |
| --- | --- |
| `cd frontend && pnpm install --frozen-lockfile`, `pnpm run build`, `pnpm run lint` | PASS |
| `composer validate`, PHP syntax (40 files), YAML lint (13 files), container lint, `debug:router --env=test` (15 routes) | PASS |
| Full `php bin/phpunit` against a throwaway PostgreSQL 16 container (`backend/bin/test-db.sh`) | PASS (52 tests, 229 assertions; 2026-11-14) |
| `app:fixtures:load` idempotency + unsafe-target refusal | PASS (second run creates 0; `prod`, non-loopback and non-test names refused) |
| `GET /login` (local discovery stub, placeholder client) | PASS — HTTP 302 with `state`, `nonce`, S256 `code_challenge` |
| `npm start` (frontend) and `GET /healthz` through `symfony serve` (backend) | PASS — HTTP 200 each |
| `docker compose -p keelson up -d` + `ps`, PostgreSQL/Redis/Mailpit probes | PASS — all three running; persistent volume untouched |
| Migration down to 0 and back up on the disposable test database | PASS — 2 migrations, exit 0 both directions |
| `.github/workflows/ci.yml` parsed with Symfony YAML | PASS — 3 jobs (workflow not yet run on GitHub) |
| `composer audit` / `pnpm audit --audit-level moderate` | 13 advisories / 9 packages; 1 moderate — recorded, not remediated |

There is no frontend test script. Exact commands and the full results table are in [how-to-run.md](how-to-run.md).

## Security and limitations

Tenant scoping is enforced in repositories and controllers, not by PostgreSQL RLS. A client-supplied organization ID is only a selector; authorization comes from the authenticated principal's active membership. Never point migrations or tests at shared, production or customer data, and never commit `.env.local`, credentials or database dumps. Remaining gates: RLS policies and pool-context reset, provider end-to-end sign-in/logout, invitation delivery and rate limiting, billing/usage enforcement, CSRF conventions for future clients, a first CI run on GitHub, and the untested remainder of the UI spec.

## Contributing

Read [AGENTS.md](AGENTS.md), [docs/ui-spec.md](docs/ui-spec.md) and the roadmap before changes. Preserve existing tracked and untracked work, check `git status`/branch first, and keep tenant, auth, billing, migration and API-contract changes serialized behind one owner. Ask before adding dependencies, changing architecture, or running destructive database operations. Record exact commands and results; never report unrun or blocked checks as passing.
