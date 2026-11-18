# Keelson

Multi-tenant SaaS starter: organizations, roles, invitations, notifications, an
audit trail and — the demo product on top — invoicing. Version 1.0.0.

## Architecture

- `backend/` — Symfony 7.4 API (PHP >=8.2) on Doctrine ORM 3 / DBAL 4 / Migrations
  and PostgreSQL 16, with a first-party email+password session (no external
  identity provider). Implemented: registration with its first organization, email
  verification, password reset, membership-based authorization voters, organization
  administration (create/rename/soft-delete, members, invitations), dashboard
  aggregates, notifications with read state, profile/password, and a tenant-scoped
  customer/invoice/audit HTTP API with `q` search and cursor pagination. 43 HTTP
  routes: `/api/auth/{register,login,verify-email,logout,forgot-password,reset-password,accept-invitation}`,
  `/healthz`, `/readyz`, `/api/session`, `/api/invitations/{token}`, `/api/me`,
  `/api/me/password`, `/api/me/organizations`, `/api/notifications…` and
  `/api/organizations/…` (settings, members, invitations, dashboard, customers,
  invoices, audit events).
- `frontend/` — React 19 + Vite 8 + TypeScript SPA on the router, every screen on
  real API data (`src/api/`, one module per resource). English, French and Arabic
  (RTL), light/dark/system themes from `src/theme/tokens.css`.
- `e2e/` — Playwright (chromium) journeys over the real stack: sign-up and
  verification, the invoice lifecycle, invitations, viewer read-only, sign-in
  throttling and the two translations.
- `docker-compose.yml` — dev-only PostgreSQL 16 and Mailpit. Mailpit is wired in
  through `MAILER_DSN=smtp://127.0.0.1:1026`; its UI on http://localhost:8026 is
  where verification and password-reset links are read locally.
- Production packaging — `backend/Dockerfile` (php-fpm, non-root, opcache),
  `frontend/Dockerfile` (nginx serving the build and forwarding `/api` over
  FastCGI), `docker-compose.prod.example.yml`, `docker/postgres/10-app-role.sh`.

Tenant isolation is application-layer only: repositories and controllers require an
active membership for the route organization, and a client-supplied organization ID
is a selector, never authority. PostgreSQL row-level security and Stripe webhooks
are **not implemented** — see `docs/adr/0003-postgresql-row-level-security.md`. Do
not assume either exists without inspecting schema, queries and code.

## Brand

Main color: `#0F766E`. Use it as the single primary color across the whole app (CSS
custom property `--color-primary` in `frontend/src/theme/tokens.css`; keep all colors
in that file). Plain CSS with logical properties — no CSS framework is installed.
Keep the UI clean, modern and accessible (WCAG AA contrast).

## Verified baseline (2026-11-17, v1.0.1)

- Frontend: React 19.3.1, Vite 8.3.4, TypeScript 6.0.3, ESLint 10.13.0 with
  `typescript-eslint` 8.71.2, Vitest 5.1.0, jsdom 27, Testing Library, knip 6.
  TypeScript is deliberately pinned below 6.1 because `typescript-eslint` 8.71.2
  supports `>=4.8.4 <6.1.0`; do not move it back to 7.x until that peer range
  widens. `tsc -b`, `pnpm run lint`, `pnpm test` (node and jsdom projects),
  `pnpm run build` and `pnpm run check:bundle` (entry 358.6 kB of a 420 kB budget)
  all pass. Test counts are printed by `bash bin/test-counts.sh`; do not copy a
  number into a document. `pnpm exec knip` and `node scripts/unused-i18n.mjs`
  report nothing.
- Backend dependencies: Symfony 7.4, Doctrine ORM/DBAL/Migrations plus the Doctrine
  bundles, `symfony/security-bundle`, `symfony/security-csrf`, `symfony/validator`,
  `symfony/rate-limiter`, `symfony/mailer`, `symfony/yaml`. No identity-provider
  bundle, no JWT library, no API Platform, no Stripe, no Messenger.
- Tests: `php bin/phpunit` against the disposable `backend/bin/test-db.sh`
  PostgreSQL 16 container (counts: `bash bin/test-counts.sh`, exit 0). Static
  analysis: `composer stan` — PHPStan level 8 with `shipmonk/dead-code-detector`
  and **no baseline** — reports no errors; `composer cs` is clean.
  `tests/Unit/Repository/TenantScopedRepositoryTest.php` and `tests/Integration/*`
  require that database and assert the PostgreSQL platform.
- Coverage: `backend/bin/coverage-check.php` enforces 80% for `src/Service`,
  `src/Security` and `src/Controller`. It needs `pcov` or `xdebug`; CI installs
  `pcov`. **This checkout has neither, so coverage was not measured locally** — do
  not claim a percentage you did not produce.
- Browser suite: `pnpm --dir e2e test` — 7 journeys, one worker, traces on failure.
- Schema checks: mapping validation (`doctrine:schema:validate --skip-sync`)
  passes and is what CI gates on. The full sync check **reports a diff by design**:
  the `sessions` table, the GIN `pg_trgm` indexes and the partial indexes have no
  ORM mapping. It no longer aborts — the introspection crash (`An index with name
  "idx_6a2f2f95a5a280d" was already defined`) was caused by PostgreSQL folding the
  uppercase name DBAL generates for `invoices.customer_id`; migration
  `Version20261117180000` renames the index to `idx_invoices_customer`. Still do
  not treat `migrations:diff`/`schema:update` output as an instruction.
  `doctrine:migrations:up-to-date` passes and is also gated in CI.
- Environment: `ext-sodium` is enabled on this host through the machine-local CLI ini
  (`…/php/cli/php-phase7.ini`, `extension=php_sodium.dll`). `composer install` and
  `composer check-platform-reqs` exit 0. That ini lives outside the repository, so a
  fresh machine must enable the extension before `composer install`. Docker Desktop
  is available; use `bin/test-db.sh` or a temporary `postgres:16-alpine` container
  (no volume) for database-backed tests, never the Compose `db_data` volume.
- Documentation: `README.md` is the run guide; `docs/deployment.md` covers build,
  environment, migrations, backup/restore, rollback and scheduled work;
  `CONTRIBUTING.md`, `SECURITY.md`, `CHANGELOG.md` and `docs/limitations.md` complete
  the set; `docs/openapi.yaml` is the API contract, kept in step with the router by
  `tests/Unit/Docs/OpenApiContractTest.php`; `docs/adr/` holds six decisions;
  `docs/release/FINAL-RELEASE-READINESS-REPORT.md` records the release decision
  and its evidence.

## Local setup

- Frontend: `cd frontend && pnpm install --frozen-lockfile`, then
  `pnpm dev --host 127.0.0.1` (plain `pnpm dev` binds only the IPv6 loopback;
  `pnpm run dev -- --host …` does not forward the flag). Checks: `pnpm run build`,
  `pnpm run lint`, `pnpm test`.
- Backend: `cd backend && composer install`. Keep local values in the ignored
  `backend/.env.local`; never commit secrets or reuse customer/production
  credentials.
- Backend checks that need no database: `composer validate --strict`,
  `php bin/console lint:yaml config --env=test`,
  `php bin/console lint:container --env=test`,
  `php bin/console debug:router --env=test`, `composer audit --locked`,
  `doctrine:schema:validate --skip-sync`, `doctrine:migrations:up-to-date` (the last
  two need the test database), `composer stan`, `composer cs`, and
  `php bin/console app:config:check`.
- `composer cs` on a **CRLF** checkout reports every file as fixable because the
  fixer rewrites line endings; that is a Windows worktree artifact, not a style
  result. On Linux (CI) it is clean. To check locally, copy `src tests migrations
  bin` somewhere, strip `\r`, and run the fixer on the copy.
- Full suite: `bash bin/test-db.sh start && php bin/phpunit && bash bin/test-db.sh stop`
  (from `backend/`). The helper refuses to start while `.env.test.local` exists;
  `stop` removes it.
- Backend HTTP sign-in needs `MAILER_DSN` (Mailpit locally), `FRONTEND_URL` and
  `REQUIRE_VERIFIED_EMAIL`; console commands need `DEFAULT_URI`. Serve with
  `php -S 127.0.0.1:8000 -t public public/index.php` (the front controller must be
  explicit, otherwise PHP's built-in server 404s on `/api/...`).
- Sessions are database rows written through `PdoSessionHandler` on Doctrine's own
  PDO connection, with **advisory** locking. Do not switch it back to Symfony's
  default transactional locking: that opens a transaction DBAL cannot see and the
  first write of every request fails with "There is already an active transaction"
  (regression test: `tests/Unit/Session/PdoSessionHandlerFactoryTest.php`).
- Migrations: inspect `DATABASE_URL` and `doctrine:migrations:status` first, then
  migrate only a confirmed local or disposable database. The Compose database uses
  the persistent `db_data` volume: never reset it, never run `down -v`, and never
  point tests at it. In production the owner role migrates and the application role
  runs the API; see `docs/deployment.md`.
- Database-backed tests need a separate throwaway PostgreSQL 16 instance (the `test`
  environment appends `_test`, plus `TEST_TOKEN` when set, to the configured
  database name); follow `backend/README.md`. On Windows, bind
  `-p 127.0.0.1::5432` and read the assigned port back — fixed ports can fall in
  reserved ranges.
- Synthetic demo data lives only in `backend/src/DataFixtures/` and is seeded
  explicitly with `php bin/console app:fixtures:load --env=dev --force` (or
  `bash backend/bin/dev-reset.sh` for a full rebuild of `keelson_dev`). It is
  idempotent, never deletes, refuses `prod`/non-PostgreSQL/non-loopback/ambiguous
  targets; every fixture account is created with the documented demo password
  (`FIXTURE_PASSWORD`, default `Keelson-Demo-2026!`), hashed at load time, and only
  `unverified@example.test` keeps an unconfirmed address. `app:fixtures:bulk` adds
  the 1,000-customer / 10,000-invoice set used by `docs/performance.md`.
  `FixtureTargetGuard` validates the **effective** DBAL connection parameters
  (`Connection::getParams()`, including Doctrine's test `dbname_suffix`), not the raw
  `DATABASE_URL`.
- Mail catcher UI: http://localhost:8026

## Rules

- TypeScript strict mode; no `any` unless justified in a comment.
- The typed frontend API layer is `frontend/src/api/`; `docs/openapi.yaml` is the
  contract and a backend test keeps it equal to the router. Change the controller,
  the OpenAPI document and the frontend client in the same commit. Never invent an
  endpoint or a CSRF header: every unsafe request sends a same-origin `Origin` and
  `X-CSRF-Token` taken from the `csrfToken` field of `GET /api/session`.
- Small, focused commits (Conventional Commits). Add or update tests with every
  feature.
- Never commit secrets; use `.env.local`. Machine-local snapshots
  (`backend/.env.*`, except the tracked `.env`, `.env.example` and `.env.test`) and
  generated tool output (`graphify-out/`, `backend/config/reference.php`) are
  git-ignored — keep them that way.
- Prefer simple, readable code over clever code. Explain non-obvious decisions in
  short comments.
- Ask before adding a new dependency or changing the architecture.

## UI spec

The full UI specification (design system, screens, states, light/dark theming) is in
`docs/ui-spec.md`. Treat it as the source of truth for every screen, keep all colors
in the central theme file, and keep every user-visible string in
`frontend/src/i18n/{en,fr,ar}`. `node scripts/unused-i18n.mjs` must stay clean; a key
built at runtime belongs in its allowlist with a reason.

## Docs and skills

- Before writing code that uses a library (React 19, Vite 8, TypeScript, Symfony,
  Doctrine), look up the CURRENT docs with the Context7 tools if available. Do not
  rely on memory for APIs that changed recently.
- No skills are installed in this repository (there is no `.agents/skills`). Ask
  before adding one, an MCP server or a dependency.

## Orchestration and verified readiness

- Read `README.md`, `docs/ROADMAP.md`, `CONTRIBUTING.md` and `backend/README.md`
  before planning multi-file or delegated work.
- Treat PostgreSQL RLS, Stripe billing/webhooks, usage enforcement and email-change
  as unimplemented until current source and tests prove them. Application-layer
  scoping is not defense-in-depth on its own.
- Tenant, auth, billing, webhook/idempotency, database migration and API contract
  changes require one integration owner and serialize by default. Agree contracts
  before assigning frontend/backend tasks.
- Capture `git status`, branch and HEAD before work. Preserve untracked assets
  (including `frontend/public/Keelson.svg`) and existing edits; do not stage, commit
  or alter sibling repositories. No destructive migration, deployment, push,
  production data, or dependency/architecture change without approval.
- Record exact changed paths and verification commands/output. Do not report a
  failed, blocked or unrun check as passing.
