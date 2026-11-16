# Keelson

Multi-tenant SaaS starter: organizations, roles, invitations, subscriptions, usage
limits and an admin panel. Demo product on top: invoicing.

## Architecture (current branch)

- `backend/` — Symfony 7.4 API (PHP >=8.2) on Doctrine ORM 3 / DBAL 4 / Migrations
  and PostgreSQL 16, with a first-party email+password session (no external
  identity provider). Implemented: registration with its first organization,
  email verification, password reset, membership-based authorization voters,
  tenant-scoped organization/membership/invitation **repository reads** (no HTTP
  endpoints), and a tenant-scoped customer/invoice/audit HTTP API. The HTTP routes
  are `/api/auth/{register,login,verify-email,logout,forgot-password,reset-password}`,
  `/healthz`, `/readyz`, `/api/session`, `/api/me/organizations` and
  `/api/organizations/{organizationId}/…` customers, invoices and audit events.
- `frontend/` — React + Vite + TypeScript single-page dashboard shell. Session,
  organization/role, customers and invoices are **real API data** (`src/api/`);
  organization switching is a client-side selection the server re-authorizes on
  every request. Dashboard metrics, onboarding/invitations, team, billing, pricing
  and notifications are still labelled mock surfaces with no backend. Only
  preferences and notification read state persist in `localStorage`.
- `docker-compose.yml` — dev-only PostgreSQL 16 and Mailpit. Mailpit is wired in
  through `MAILER_DSN=smtp://127.0.0.1:1026`; its web UI on http://localhost:8026 is
  where verification and password-reset links are read locally.

Tenant isolation is application-layer only: repositories and controllers require an
active membership for the route organization, and a client-supplied organization ID
is a selector, never authority. PostgreSQL row-level security and Stripe webhooks
are intended but **not implemented** — do not assume either exists without
inspecting schema, queries and code.

## Brand

Main color: `#0F766E`. Use it as the single primary color across the whole app
(CSS custom property `--color-primary` in `frontend/src/theme/tokens.css`; keep all
colors in that file). Plain CSS with logical properties — no CSS framework is
installed. Keep the UI clean, modern and accessible (WCAG AA contrast).

## Verified baseline (2026-11-16)

- Frontend: React 19.3.1, Vite 8.3.4, TypeScript 6.0.3, ESLint 10.13.0 with
  `typescript-eslint` 8.71.2, Vitest 5.1.0. TypeScript is deliberately pinned below
  6.1 because `typescript-eslint` 8.71.2 supports `>=4.8.4 <6.1.0`; do not move it
  back to 7.x until that peer range widens. `pnpm run build`, `pnpm run lint` and
  `pnpm test` pass; there are 57 node-environment unit tests: session/logout
  parsers in `src/session/session.test.ts`, and the API layer (client, customers,
  invoices, organizations, error mapping) in `src/api/*.test.ts`. No component/DOM
  tests and no jsdom/RTL dependency (adding one needs approval).
- Backend dependencies: Symfony 7.4, Doctrine ORM/DBAL/Migrations plus the Doctrine
  bundles, `symfony/security-bundle`, `symfony/security-csrf`, `symfony/validator`,
  `symfony/rate-limiter`, `symfony/mailer`, `symfony/translation-contracts`. No
  identity-provider bundle and no JWT library. API Platform, Stripe and Messenger
  are **not** declared.
- Tests: `php bin/phpunit` against the disposable `backend/bin/test-db.sh`
  PostgreSQL 16 container gives **157 tests / 721 assertions, exit 0**. Coverage
  includes registration, verification, login (throttling, unverified-address
  ordering), logout, password reset, CSRF, request-shape errors and the token
  service, alongside the invoice API and concurrency suites.
  `tests/Unit/Repository/TenantScopedRepositoryTest.php` and `tests/Integration/*`
  require that database and assert the PostgreSQL platform. Static analysis:
  `./vendor/bin/phpstan analyse` (level 5, `phpstan-baseline.neon`) reports no
  errors. Database-independent subset: `php bin/phpunit tests/Unit/Entity
  tests/Unit/Security tests/Unit/Service tests/Unit/Util tests/Unit/EventListener`.
- Known limitation: `php bin/console doctrine:schema:validate --env=test` fails
  while introspecting the database with `An index with name "idx_6a2f2f95a5a280d"
  was already defined on table "invoices"`. DBAL 4.5 synthesizes that exact name for
  the `fk_invoices_customer` local columns and the migration declares the same name
  (`migrations/Version20261023170000.php`), so the hydration sees it twice. Mapping
  validation (`--skip-sync`) passes. Do not use `schema:update`/`migrations:diff`
  against this database until it is resolved.
- Environment: `ext-sodium`, declared by `lcobucci/jwt`, is enabled on this host
  through the machine-local CLI ini (`…/php/cli/php-phase7.ini`,
  `extension=php_sodium.dll`, libsodium 1.0.22). `composer install` and
  `composer check-platform-reqs` both exit 0 with no flags. That ini lives outside
  the repository, so a fresh machine must enable the extension before
  `composer install`. Docker Desktop is available; use a temporary
  `postgres:16-alpine` container (no volume) for database-backed tests, never the
  Compose `db_data` volume.
- Documentation: `README.md` is the single run guide and overview,
  `docs/ROADMAP.md` holds the nine sprints and their status,
  `backend/README.md` covers authentication/API/database safety and
  `frontend/README.md` covers the frontend scope.

## Local setup

- Frontend: `cd frontend && pnpm install --frozen-lockfile`, then
  `pnpm dev --host 127.0.0.1` (plain `pnpm dev` binds only the IPv6 loopback;
  `pnpm run dev -- --host ...` does not forward the flag). Checks:
  `pnpm run build`, `pnpm run lint`.
- Backend: `cd backend && composer install`. Keep local values in the ignored
  `backend/.env.local`; never commit secrets or reuse customer/production
  credentials.
- Backend checks that need no database: `composer validate --no-check-publish`,
  `php bin/console lint:yaml config --env=test`,
  `php bin/console lint:container --env=test`,
  `php bin/console debug:router --env=test` (20 routes),
  `composer audit`, `./vendor/bin/phpstan analyse`.
- Full suite: `bash bin/test-db.sh start && php bin/phpunit && bash bin/test-db.sh stop`
  (from `backend/`).
- Backend HTTP sign-in needs `MAILER_DSN` (Mailpit locally), `FRONTEND_URL` and
  `REQUIRE_VERIFIED_EMAIL`; console commands work without them. Serve with
  `php -S 127.0.0.1:8000 -t public public/index.php` (the front controller must be
  explicit, otherwise PHP's built-in server 404s on `/api/...`).
- Migrations: inspect `DATABASE_URL` and `doctrine:migrations:status` first, then
  migrate only a confirmed local or disposable database. The Compose database uses
  the persistent `db_data` volume: never reset it, never run `down -v`, and never
  point tests at it.
- Database-backed tests need a separate throwaway PostgreSQL 16 instance (the `test`
  environment appends `_test`, plus `TEST_TOKEN` when set, to the configured
  database name); follow `backend/README.md`. On Windows, bind
  `-p 127.0.0.1::5432` and read the assigned port back — fixed ports can fall in
  reserved ranges.
- Synthetic demo data lives only in `backend/src/DataFixtures/` and is seeded
  explicitly with `php bin/console app:fixtures:load --env=test --force`. It is
  idempotent, never deletes, refuses
  `prod`/non-PostgreSQL/non-loopback/ambiguous targets; every fixture principal
  gets the documented development password `Keelson-dev-2026` and a confirmed
  address. The frontend never reads it. `FixtureTargetGuard` validates the
  **effective** DBAL connection parameters (`Connection::getParams()`, including
  Doctrine's test `dbname_suffix`), not the raw `DATABASE_URL`; validating the URL
  was what made CI refuse its own isolated `keelson_test` database.
- Mail catcher UI: http://localhost:8026

## Rules

- TypeScript strict mode; no `any` unless justified in a comment.
- The typed frontend API layer is `frontend/src/api/` (hand-written because no
  OpenAPI spec exists yet; `docs/api-contract.md` records the verified shapes).
  Generate it from an OpenAPI spec once one exists (openapi-typescript or Orval)
  and keep the contract document in step with the controllers. Never invent an
  endpoint or a CSRF header: every unsafe request sends a same-origin `Origin` and
  `X-CSRF-Token` taken from the `csrfToken` field of `GET /api/session`.
- Small, focused commits (Conventional Commits). Add or update tests with every
  feature.
- Never commit secrets; use `.env.local`. Machine-local snapshots
  (`backend/.env.*`, except the tracked `.env`, `.env.example` and `.env.test`) and
  generated tool output (`graphify-out/`) are git-ignored — keep them that way.
- Prefer simple, readable code over clever code. Explain non-obvious decisions in
  short comments.
- Ask before adding a new dependency or changing the architecture.

## UI spec

The full UI specification (design system, screens, states, light/dark theming) is in
`docs/ui-spec.md`. Treat it as the source of truth for every screen. Build screens
in the batches requested, keep all colors in the central theme file, and keep
`src/mocks/` only for surfaces with no backend — never mix mock records into an
API-backed list.

## Docs and skills

- Before writing code that uses a library (React 19, Vite 8, TypeScript, Symfony,
  Doctrine), look up the CURRENT docs with the Context7 tools if available. Do not
  rely on memory for APIs that changed recently.
- No skills are installed in this repository (there is no `.agents/skills`). Ask
  before adding one, an MCP server or a dependency.

## Orchestration and verified readiness

- Read `README.md`, `docs/ROADMAP.md` and `backend/README.md` before planning
  multi-file or delegated work.
- Treat PostgreSQL RLS, Stripe billing/webhooks, usage enforcement, invitation
  delivery and the SPA's sign-in screens (Sprint 3) as unimplemented or unverified
  until current source and tests prove them. Application-layer scoping is not
  defense-in-depth on its own.
- Tenant, auth, billing, webhook/idempotency, database migration and API contract
  changes require one integration owner and serialize by default. Agree contracts
  before assigning frontend/backend tasks.
- Capture `git status`, branch and HEAD before work. Preserve untracked assets
  (including `frontend/public/Keelson.svg`) and existing edits; do not stage, commit
  or alter sibling repositories. No destructive migration, deployment, push,
  production data, or dependency/architecture change without approval.
- Record exact changed paths and verification commands/output. Do not report a
  failed, blocked or unrun check as passing.
