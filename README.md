# Keelson

Keelson is a multi-tenant SaaS starter with organization membership and an invoicing demonstration. Its React frontend signs in through the Symfony session, reads the caller's organizations and role, and drives the organization-scoped customer and invoice API — with loading, empty, error, validation and conflict states. Dashboard metrics, onboarding/invitations, team, billing, pricing and notifications remain labelled mock surfaces because no backend exists for them. The verified API contract is [`docs/api-contract.md`](docs/api-contract.md).

Brand color: `#0F766E`.

## Status (verified 2026-11-15)

- **Frontend** — React 19.3.1, Vite 8.3.4, TypeScript 6.0.3. Real API integration for the session, the organization/role list, customers (CRUD, `409` on delete conflicts) and invoices (draft create/update, send, mark paid, delete draft, conflict refetch). Typed client in `frontend/src/api/`; organization switching is a client-side selection that the server re-authorizes on every request. Mock surfaces that remain are labelled and never mixed into API lists. EN/FR/AR with RTL and light/dark/system theme unchanged.
- **Backend** — Symfony 7.4 (PHP >=8.2), Doctrine ORM 3 / DBAL 4 / Migrations, Drenso OIDC bundle. Implemented: OIDC session firewall with local principal provisioning, membership-based authorization voters, tenant-scoped organization/membership/invitation reads, and a customer/invoice/audit API (`draft → sent → paid`, derived overdue, TND millime arithmetic) whose draft edits, deletions and status transitions take a row lock so a concurrent send cannot be overwritten.
- **Fixtures** — `backend/src/DataFixtures/` plus `php bin/console app:fixtures:load --env=test --force` seed idempotent, entirely synthetic demo identities, two organizations with all four roles, customers and draft/sent/overdue/paid invoices. The command refuses `prod`, non-loopback hosts and non-test/dev database names, never deletes data, and creates no login credentials.
- **Sign-in redirect fixed (2026-11-13)** — `symfony/translation-contracts ^3.8` is now declared; its absence had made `GET /login` throw while building the PKCE challenge. `GET /login` now returns `302` to the authorization endpoint with `state`, `nonce`, `code_challenge` and `code_challenge_method=S256` (verified against a temporary local discovery stub, then the stub was removed). Real Logto sign-in/callback/logout is still unverified: it needs a configured test client and a reachable provider.
- **Local startup (verified 2026-11-14)** — `npm start` in `frontend/` serves Vite on `127.0.0.1:5173` (HTTP 200); `symfony serve` in `backend/` serves the API, and the public `GET /healthz` probe now answers 200 without OIDC configuration because it has its own stateless firewall. Compose services start as `keelson-db-1`, `keelson-redis-1` and `keelson-mailpit-1` with the persistent `db_data` volume untouched.
- **Reliability pass (2026-11-15)** — sign-out is a real server logout: a CSRF-protected form POST invalidates the session and the application's own listener redirects to the provider's `end_session_endpoint` (`id_token_hint`, `post_logout_redirect_uri`), degrading to the local redirect if the provider is unreachable instead of returning HTTP 500. Audited mutations and their audit events share one transaction and one flush; `GET` invoice reads no longer write derived overdue state (a separate locked `app:invoices:mark-overdue` operation records it); customer deletion locks the row before checking references and maps only foreign-key violations to `409`; invoice numbers are allocated under a per-tenant/year advisory lock. Authorization is centralised in `InvoiceVoter`. Sprint 3 regression coverage now verifies the principal and active membership in customer single-row reads/writes, tenant-bound creation, cross-tenant ID/body/query tampering, viewer-denied invoice transitions, rollback on audit failure, and no duplicate transition events on retries.
- **Verified (current Sprint 3 run, 2026-11-15)** — the already-running helper-owned, volume-free PostgreSQL 16 container (tmpfs data, loopback-only `127.0.0.1:50682`, effective database `keelson_test`) ran the full suite: **97 tests / 527 assertions**, exit 0. `composer validate --no-check-publish`, `php bin/console lint:container --env=test` and `git diff --check` also pass. Frontend: `tsc -b`, `pnpm run build`, `pnpm run lint` (0 findings) and `pnpm test` (**56 Vitest tests**) passed in the Sprint 2 run on this worktree; no frontend source changed in Sprint 3. The Compose `keelson` project and persistent `keelson_db_data` volume were not used for tests. Full preflight and Sprint 3 evidence: [`docs/sprints/sprint-3-preflight.md`](docs/sprints/sprint-3-preflight.md).
- **Frontend↔API integration (2026-11-15)** — `docs/api-contract.md` documents the verified contract. One backend read was required and added (`GET /api/me/organizations`, membership-scoped, `no-store, private`, 4 tests) because nothing exposed the caller's tenant IDs. Live browser check: with the API returning `500 Environment variable not found: "OIDC_CLIENT_ID"` (no `backend/.env.local`), the shell shows a session-unavailable gate with retry rather than demo identity (`docs/screenshots/session-unavailable.png`). Authenticated screens are covered by backend functional tests and frontend API tests, but were **not** exercised in a browser: real Logto credentials are still absent.
- **CI fixture-loader failure fixed (2026-11-15)** — GitHub Actions failed because the fixture loader inspected the raw `DATABASE_URL` (`…/keelson`) while Doctrine's test environment appends `_test`, so it refused its own isolated CI database. `FixtureTargetGuard` now validates the effective DBAL connection parameters and keeps every refusal (prod, non-PostgreSQL, non-loopback, ambiguous names). The CI configuration is reproduced in `SyntheticFixtureLoaderTest`.
- **CI (updated)** — `.github/workflows/ci.yml` runs frontend install/build/lint/tests, backend validation plus the PostgreSQL PHPUnit suite, and blocking `composer audit` / high-severity `pnpm audit` with a report-only moderate step. The YAML is valid locally; a hosted run has not been observed for this change.
- **Not implemented or not verified** — PostgreSQL RLS, real Logto sign-in/callback/logout (no test client configured on this checkout), billing and usage enforcement (Phase 8 is a scope record only), email/invitation delivery, component/DOM frontend tests (needs `jsdom` + Testing Library approval), server-side customer/invoice search and cursor pagination in the UI (the first 100 rows are loaded, with a visible note), profile editing, organization switching at the backend, most `docs/ui-spec.md` screens, and `doctrine:schema:validate` (known DBAL index-name collision on `invoices`). Treat tenant isolation as application-layer scoping, not production hardening.

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

| Check | Result (2026-11-15) |
| --- | --- |
| `cd frontend && tsc -b`, `pnpm run build`, `pnpm run lint`, `pnpm test` | PASS — build 370.64 kB / 108.62 kB gzip, lint 0 findings, 56 Vitest tests |
| `composer validate --no-check-publish`, `composer check-platform-reqs`, PHP syntax, YAML lint, container lint, `debug:router --env=test` (18 routes) | PASS |
| Full `php bin/phpunit` against the helper's disposable PostgreSQL 16 container | PASS (97 tests, 527 assertions; Sprint 3 run) |
| `app:fixtures:load` idempotency + effective-target refusal (incl. the CI `keelson` + `_test` case) | PASS |
| OIDC sign-out (`OidcSessionLogoutTest`, offline discovery stub) | PASS — 302 to `end_session_endpoint` with `id_token_hint`; session anonymous afterwards |
| Audit atomicity, GET purity, overdue derivation, customer-delete FK race, invoice-number lock, role matrix | PASS (integration suites) |
| `git diff --check` | PASS |
| `doctrine:schema:validate --env=test` | FAIL — known DBAL index-name collision on `invoices`; mapping validation passes |
| `.github/workflows/ci.yml` | YAML valid; no hosted run observed for this change |
| `composer audit` / `pnpm audit` | Composer: no advisories. pnpm: 1 moderate dev-only `minimatch` finding, documented |

Exact commands and the full results table are in [how-to-run.md](how-to-run.md).

## Security and limitations

Tenant scoping is enforced in repositories and controllers, not by PostgreSQL RLS. A client-supplied organization ID is only a selector; authorization comes from the authenticated principal's active membership. Never point migrations or tests at shared, production or customer data, and never commit `.env.local`, credentials or database dumps. Remaining gates: RLS policies and pool-context reset, provider end-to-end sign-in/logout, invitation delivery and rate limiting, billing/usage enforcement, CSRF conventions for future clients, a first CI run on GitHub, and the untested remainder of the UI spec.

## Contributing

Read [AGENTS.md](AGENTS.md), [docs/ui-spec.md](docs/ui-spec.md) and the roadmap before changes. Preserve existing tracked and untracked work, check `git status`/branch first, and keep tenant, auth, billing, migration and API-contract changes serialized behind one owner. Ask before adding dependencies, changing architecture, or running destructive database operations. Record exact commands and results; never report unrun or blocked checks as passing.
