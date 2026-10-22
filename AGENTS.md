# Keelson

Multi-tenant SaaS starter: organizations, roles, invitations, Stripe subscriptions, usage limits and an admin panel. Demo product on top: invoicing.

## Intended architecture (not yet verified as implemented)
PostgreSQL row-level security and Symfony Stripe webhooks are intended. Do not assume either exists or is effective without inspecting schema, queries, authorization code, webhook validation/idempotency, and tests. This is not a shared runtime for sibling projects.

## Brand
Main color: `#0F766E`. Use it as the single primary color across the whole app (Tailwind theme token `primary`). Keep the UI clean, modern and accessible (WCAG AA contrast).

## Layout
- `backend/` Symfony (PHP) API skeleton
- `frontend/` React + Vite + TypeScript web app (currently Vite starter)
- `docker-compose.yml` Postgres, Redis, Mailpit (dev services only)

## Intended stack (not verified as implemented)
- Backend target: Symfony, API Platform (OpenAPI), Doctrine ORM, PostgreSQL, LexikJWT auth, Symfony Messenger
- Frontend target: React, Vite, TypeScript, Tailwind CSS, shadcn/ui, TanStack Query, React Router
- Quality target: PHPUnit, PHPStan, PHP-CS-Fixer / Vitest, Playwright, ESLint, Prettier / GitHub Actions

## Verified current manifest baseline
- `frontend/package.json` / `pnpm-lock.yaml`: React 19.3.1, Vite 8.3.4, TypeScript 7.0.2; current source is the Vite starter. No frontend test script is defined.
- `backend/composer.json` / `composer.lock`: Symfony 7.4 skeleton, PHP >=8.2; Doctrine/API Platform/Postgres/security/Stripe/Messenger dependencies are not declared there.
- Read `docs/ORCHESTRATION_READINESS.md` for actual commands and current check results; do not infer tenant isolation or billing implementation from project descriptions or Compose services.

## Local setup
- Optional local services: inspect `docker-compose.yml` and `docker compose ps` first, then `docker compose up -d` only when explicitly needed. Compose defines persistent DB volume data; do not reset/remove volumes.
- Intended local database URL: `DATABASE_URL="postgresql://app:app@127.0.0.1:5433/keelson?serverVersion=16&charset=utf8"` (local dev only, `backend/.env.local`); tenant and billing data access is not currently implemented.
- Backend (once PHP/Composer and the API are set up): `cd backend`, `composer install`, inspect migration status and `DATABASE_URL`, then run migrations only against a confirmed disposable/local database before `symfony serve` (or `php -S 127.0.0.1:8000 -t public`). Never migrate an unknown/shared database.
- Frontend: `cd frontend`, `pnpm install`, `pnpm dev`
- Tests/checks: `cd backend && php bin/phpunit`; no frontend test script currently exists. Frontend commands: `pnpm run build`, `pnpm run lint`; consult the readiness report for current outcomes. Backend tests currently contain only the bootstrap file.
- Mail catcher UI: http://localhost:8026

## Rules
- TypeScript strict mode; no `any` unless justified in a comment.
- Generate the frontend API client from the backend OpenAPI spec (openapi-typescript or Orval). Never hand-write API types.
- Small, focused commits (Conventional Commits). Add or update tests with every feature.
- Never commit secrets; use `.env.local`. Keep `.env` as documented defaults only.
- Prefer simple, readable code over clever code. Explain non-obvious decisions in short comments.
- Ask before adding a new dependency or changing the architecture.
## UI spec
The full UI specification (design system, screens, states, light/dark theming) is in `docs/ui-spec.md`. Treat it as the source of truth for every screen. Build screens in the batches requested, with typed mock data in `src/mocks/`, and keep all colors in the central theme file.
## Docs and skills
- Before writing code that uses a library (React 19, Vite 8, TypeScript 7, Tailwind, shadcn/ui, Expo SDK 57, Expo Router, Symfony, API Platform, Doctrine), look up the CURRENT docs with the Context7 tools if available. Do not rely on memory for APIs that changed recently.
- API Platform docs index for agents: https://api-platform.com/docs/llms.txt
- Load skills with /skill:<name> when relevant. Web: shadcn, web-design-guidelines, vercel-react-best-practices, vercel-composition-patterns. Mobile: expo-overview, expo-router, expo-design-system, expo-native-ui, expo-animation, react-native-guidelines. Backend: the api-platform skills (api-resource, state-provider, state-processor, serialization-groups, pagination, securing-collections, api-test).
- Only use skills and tools listed here. Ask before installing any other skill, MCP server or dependency.

## Orchestration and verified readiness
- Read `docs/ORCHESTRATION_READINESS.md` before planning multi-file or delegated work. The current frontend build passes, but lint fails because the installed `typescript-eslint` rejects TypeScript 7.0.2; backend PHPUnit is blocked when PHP is unavailable. No frontend test script exists.
- Treat tenant isolation/RLS, Doctrine/API Platform data access, auth, Stripe billing/webhooks, and usage enforcement as unverified until current source/manifests prove them. Client-supplied organization IDs are never an authorization boundary.
- Tenant, auth, billing, webhook/idempotency, database migration, and API contract changes require one integration owner and serialize by default. Agree contracts before assigning frontend/backend tasks.
- Capture `git status`, branch, and HEAD before work. Preserve untracked assets (including `frontend/public/Keelson.svg`) and existing edits; do not stage, commit, or alter sibling repositories. No destructive migration, deployment, push, production data, or dependency/architecture change without approval.
- Record exact changed paths and verification commands/output. Do not report a failed, blocked, or unrun check as passing.