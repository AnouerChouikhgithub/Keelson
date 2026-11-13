# Keelson

Keelson is a multi-tenant SaaS starter with organization membership and an invoicing demonstration. It has a working local backend for organization-scoped customers, invoices and audit events, plus a frontend dashboard shell that renders **mock data only** and does not call the API.

Brand color: `#0F766E`.

## Status (verified 2026-11-13)

- **Frontend** — React 19.3.1, Vite 8.3.4, TypeScript 6.0.3. Dashboard, organization switcher, mock onboarding and light/dark/system theme. No API calls, no persistence, no auth.
- **Backend** — Symfony 7.4 (PHP >=8.2), Doctrine ORM 3 / DBAL 4 / Migrations, Drenso OIDC bundle. Implemented: OIDC session firewall with local principal provisioning, membership-based authorization voters, tenant-scoped organization/membership/invitation reads, and a customer/invoice/audit API (`draft → sent → paid`, derived overdue, TND millime arithmetic) whose draft edits, deletions and status transitions take a row lock so a concurrent send cannot be overwritten.
- **Fixtures** — `backend/src/DataFixtures/` plus `php bin/console app:fixtures:load --env=test --force` seed idempotent, entirely synthetic demo identities, two organizations with all four roles, customers and draft/sent/overdue/paid invoices. The command refuses `prod`, non-loopback hosts and non-test/dev database names, never deletes data, and creates no login credentials.
- **Sign-in redirect fixed (2026-11-13)** — `symfony/translation-contracts ^3.8` is now declared; its absence had made `GET /login` throw while building the PKCE challenge. `GET /login` now returns `302` to the authorization endpoint with `state`, `nonce`, `code_challenge` and `code_challenge_method=S256` (verified against a temporary local discovery stub, then the stub was removed). Real Logto sign-in/callback/logout is still unverified: it needs a configured test client and a reachable provider.
- **Verified** — the full backend suite ran against a disposable `postgres:16-alpine` container (no volume, dynamic loopback port, removed afterwards): 45 tests / 187 assertions, exit 0. The Compose `db_data` volume was never used.
- **Not implemented or not verified** — PostgreSQL RLS, real Logto sign-in/callback/logout, billing and usage enforcement (Phase 8 is a scope record only), email/invitation delivery, frontend↔API integration, CI, and most `docs/ui-spec.md` screens. Treat tenant isolation as application-layer scoping, not production hardening.

## Run locally

See **[how-to-run.md](how-to-run.md)** for prerequisites, environment setup, database isolation, migrations, start commands and test commands. It is the single detailed run guide; do not duplicate its steps here.

Companion docs:

- [frontend/README.md](frontend/README.md) — frontend scope, stack, commands, mock-data limits.
- [backend/README.md](backend/README.md) — OIDC configuration, API surface, database safety, isolated PostgreSQL test procedure.
- [DEVELOPMENT_ROADMAP.md](DEVELOPMENT_ROADMAP.md) — per-phase status and evidence.
- `docs/ui-spec.md` — UI specification; it does not prove backend behavior.
- `docs/ORCHESTRATION_READINESS.md` — superseded 2026-10-20 audit snapshot, kept for history.

Quick orientation: `frontend/` (Vite app), `backend/` (Symfony API), `docker-compose.yml` (PostgreSQL 16, Redis 7, Mailpit — Redis and Mailpit are not used by the code yet).

## Checks

| Check | Result (2026-11-13) |
| --- | --- |
| `cd frontend && pnpm install --frozen-lockfile`, `pnpm run build`, `pnpm run lint` | PASS |
| `composer validate`, PHP syntax (40 files), YAML lint (13 files), container lint, `debug:router --env=test` (15 routes) | PASS |
| Full `php bin/phpunit` against a throwaway PostgreSQL 16 container | PASS (45 tests, 187 assertions) |
| `app:fixtures:load` idempotency + unsafe-target refusal | PASS (second run creates 0; `prod`, non-loopback and non-test names refused) |
| `GET /login` (local discovery stub, placeholder client) | PASS — HTTP 302 with `state`, `nonce`, S256 `code_challenge` |

There is no frontend test script. Exact commands and the full results table are in [how-to-run.md](how-to-run.md).

## Security and limitations

Tenant scoping is enforced in repositories and controllers, not by PostgreSQL RLS. A client-supplied organization ID is only a selector; authorization comes from the authenticated principal's active membership. Never point migrations or tests at shared, production or customer data, and never commit `.env.local`, credentials or database dumps. Remaining gates: RLS policies and pool-context reset, provider end-to-end sign-in/logout, invitation delivery and rate limiting, billing/usage enforcement, CSRF conventions for future clients, CI, and the untested remainder of the UI spec.

## Contributing

Read [AGENTS.md](AGENTS.md), [docs/ui-spec.md](docs/ui-spec.md) and the roadmap before changes. Preserve existing tracked and untracked work, check `git status`/branch first, and keep tenant, auth, billing, migration and API-contract changes serialized behind one owner. Ask before adding dependencies, changing architecture, or running destructive database operations. Record exact commands and results; never report unrun or blocked checks as passing.
