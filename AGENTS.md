# Keelson

Multi-tenant SaaS starter: organizations, roles, invitations, Stripe subscriptions, usage limits and an admin panel. Demo product on top: invoicing.

## Notes
Tenant isolation via Postgres row-level security. Stripe webhooks handled in Symfony. Reusable auth/billing base for the other projects.

## Brand
Main color: `#0F766E`. Use it as the single primary color across the whole app (Tailwind theme token `primary`). Keep the UI clean, modern and accessible (WCAG AA contrast).

## Layout
- `backend/`  Symfony (PHP) REST API
- `frontend/` React + Vite + TypeScript web app
- `docker-compose.yml` Postgres, Redis, Mailpit (dev services only)

## Stack
- Backend: Symfony, API Platform (OpenAPI), Doctrine ORM, PostgreSQL, LexikJWT auth, Symfony Messenger
- Frontend: React, Vite, TypeScript, Tailwind CSS, shadcn/ui, TanStack Query, React Router
- Quality: PHPUnit, PHPStan, PHP-CS-Fixer / Vitest, Playwright, ESLint, Prettier / GitHub Actions

## Local setup
- Start services: `docker compose up -d`
- Database URL: `DATABASE_URL="postgresql://app:app@127.0.0.1:5433/keelson?serverVersion=16&charset=utf8"` (put in `backend/.env.local`)
- Backend: `cd backend`, `composer install`, `php bin/console doctrine:migrations:migrate`, `symfony serve` (or `php -S 127.0.0.1:8000 -t public`)
- Frontend: `cd frontend`, `pnpm install`, `pnpm dev`
- Tests: `cd backend && php bin/phpunit` / `cd frontend && pnpm test`
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
- Load skills with /skill:<name> when relevant. Web: shadcn, web-design-guidelines, react-best-practices, composition-patterns. Mobile: expo-overview, expo-router, expo-design-system, expo-native-ui, expo-animation, react-native-guidelines. Backend: the api-platform skills (api-resource, state-provider, state-processor, serialization-groups, pagination, securing-collections, api-test).
- Only use skills and tools listed here. Ask before installing any other skill, MCP server or dependency.