# Keelson

Multi-tenant SaaS starter: organizations, roles, invitations, Stripe subscriptions, usage limits and an admin panel. Demo product on top: invoicing.

## Architecture (current branch)

- `backend/` — Symfony 7.4 API (PHP >=8.2) on Doctrine ORM 3 / DBAL 4 / Migrations and PostgreSQL 16, with a Drenso OIDC session firewall. Implemented: local principal provisioning after ID-token verification, membership-based authorization voters, tenant-scoped organization/membership/invitation reads, and a tenant-scoped customer/invoice/audit API.
- `frontend/` — React + Vite + TypeScript single-page dashboard shell. It renders typed mock data, does **not** call the API, and stores nothing.
- `docker-compose.yml` — dev-only PostgreSQL 16, Redis 7 and Mailpit. Redis and Mailpit are not used by the code.

Tenant isolation is application-layer only: repositories and controllers require an active membership for the route organization, and a client-supplied organization ID is a selector, never authority. PostgreSQL row-level security and Stripe webhooks are intended but **not implemented** — do not assume either exists without inspecting schema, queries and code.

## Brand

Main color: `#0F766E`. Use it as the single primary color across the whole app (Tailwind theme token `primary`). Keep the UI clean, modern and accessible (WCAG AA contrast).

## Verified baseline (2026-11-13)

- Frontend: React 19.3.1, Vite 8.3.4, TypeScript 6.0.3, ESLint 10.13.0 with `typescript-eslint` 8.71.2. TypeScript is deliberately pinned below 6.1 because `typescript-eslint` 8.71.2 supports `>=4.8.4 <6.1.0`; do not move it back to 7.x until that peer range widens. `pnpm run build` and `pnpm run lint` pass. No frontend test script is defined.
- Backend dependencies: Symfony 7.4, Doctrine ORM/DBAL/Migrations plus the Doctrine bundles, `drenso/symfony-oidc-bundle`, `symfony/security-bundle`, `symfony/security-csrf`, `symfony/translation-contracts`. API Platform, Stripe, Messenger and LexikJWT are **not** declared.
- Tests: database-independent PHPUnit suites pass (29 tests, 72 assertions). `tests/Unit/Repository/TenantScopedRepositoryTest.php` and `tests/Integration/*` require a disposable PostgreSQL 16 database and assert the PostgreSQL platform.
- Environment: `composer check-platform-reqs` exits 2 because `lcobucci/jwt` declares `ext-sodium` and this PHP build does not load it. Enable the extension (or pass `--ignore-platform-req=ext-sodium` to Composer) rather than treating the check as passing. The Docker Desktop Linux engine was unreachable on this host, so database-backed suites were not re-run.
- Documentation: `how-to-run.md` is the run guide, `backend/README.md` covers OIDC/API/database safety, `frontend/README.md` covers the frontend scope, and `DEVELOPMENT_ROADMAP.md` holds per-phase status and evidence. `docs/ORCHESTRATION_READINESS.md` is a superseded 2026-10-20 snapshot kept for history — do not treat it as current.

## Local setup

- Frontend: `cd frontend && pnpm install --frozen-lockfile`, then `pnpm dev --host 127.0.0.1` (plain `pnpm dev` binds only the IPv6 loopback; `pnpm run dev -- --host ...` does not forward the flag). Checks: `pnpm run build`, `pnpm run lint`.
- Backend: `cd backend && composer install` (see the `ext-sodium` note above). Keep local values in the ignored `backend/.env.local`; never commit secrets or reuse customer/production credentials.
- Backend checks that need no database: `composer validate --no-check-publish`, `php bin/console lint:yaml config --env=test`, `php bin/console lint:container --env=test`, `php bin/console debug:router --env=test`, `php bin/phpunit tests/Unit/Entity tests/Unit/Security tests/Unit/Service tests/Unit/Util`.
- Backend HTTP needs `OIDC_ISSUER`, `OIDC_WELL_KNOWN_URL`, `OIDC_CLIENT_ID` and `OIDC_CLIENT_SECRET`; there is no offline or password login, and console commands work without them. Serve with `php -S 127.0.0.1:8000 -t public public/index.php` (the front controller must be explicit, otherwise PHP's built-in server 404s on `/api/...`).
- Migrations: inspect `DATABASE_URL` and `doctrine:migrations:status` first, then migrate only a confirmed local or disposable database. The Compose database uses the persistent `db_data` volume: never reset it, never run `down -v`, and never point tests at it.
- Database-backed tests need a separate throwaway PostgreSQL 16 instance (the `test` environment appends `_test` to the configured database name); follow `backend/README.md`.
- Mail catcher UI: http://localhost:8026

## Rules

- TypeScript strict mode; no `any` unless justified in a comment.
- Generate the frontend API client from the backend OpenAPI spec once one exists (openapi-typescript or Orval). Never hand-write API types. No spec exists yet, so the frontend stays mock-only.
- Small, focused commits (Conventional Commits). Add or update tests with every feature.
- Never commit secrets; use `.env.local`. Machine-local env snapshots (`backend/.env.phase7`) and generated tool output (`graphify-out/`) are git-ignored — keep them that way.
- Prefer simple, readable code over clever code. Explain non-obvious decisions in short comments.
- Ask before adding a new dependency or changing the architecture.

## UI spec

The full UI specification (design system, screens, states, light/dark theming) is in `docs/ui-spec.md`. Treat it as the source of truth for every screen. Build screens in the batches requested, with typed mock data in `src/mocks/`, and keep all colors in the central theme file.

## Docs and skills

- Before writing code that uses a library (React 19, Vite 8, TypeScript, Tailwind, shadcn/ui, Expo SDK 57, Expo Router, Symfony, API Platform, Doctrine), look up the CURRENT docs with the Context7 tools if available. Do not rely on memory for APIs that changed recently.
- API Platform docs index for agents: https://api-platform.com/docs/llms.txt
- Load skills with /skill:<name> when relevant. Web: shadcn, web-design-guidelines, vercel-react-best-practices, vercel-composition-patterns. Mobile: expo-overview, expo-router, expo-design-system, expo-native-ui, expo-animation, react-native-guidelines. Backend: the api-platform skills (api-resource, state-provider, state-processor, serialization-groups, pagination, securing-collections, api-test).
- Only use skills and tools listed here. Ask before installing any other skill, MCP server or dependency.

## Orchestration and verified readiness

- Read `DEVELOPMENT_ROADMAP.md`, `how-to-run.md` and `backend/README.md` before planning multi-file or delegated work. `docs/ORCHESTRATION_READINESS.md` is historical only.
- Treat PostgreSQL RLS, Stripe billing/webhooks, usage enforcement, invitation delivery, and real Logto sign-in/callback/logout as unimplemented or unverified until current source, tests and provider runs prove them. Application-layer scoping is not defense-in-depth on its own.
- Tenant, auth, billing, webhook/idempotency, database migration and API contract changes require one integration owner and serialize by default. Agree contracts before assigning frontend/backend tasks.
- Capture `git status`, branch and HEAD before work. Preserve untracked assets (including `frontend/public/Keelson.svg`) and existing edits; do not stage, commit or alter sibling repositories. No destructive migration, deployment, push, production data, or dependency/architecture change without approval.
- Record exact changed paths and verification commands/output. Do not report a failed, blocked or unrun check as passing.
