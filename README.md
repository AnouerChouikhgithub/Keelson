# Keelson

**Keelson is intended to be a multi-tenant SaaS starter** with organization membership and an invoicing demonstration. The repository is currently a starter baseline: tenancy, billing, roles, invitations, admin, and invoice workflows described in project guidance are not implemented or verified in the tracked application source.

Brand color: `#0F766E`.

## Current status

- **Verified:** frontend is a React 19.3.1 + Vite 8.3.4 + TypeScript 7.0.2 starter; the current `App.tsx` is the Vite demo.
- **Verified:** backend is a Symfony 7.4 skeleton (PHP >=8.2). `composer.json` does not declare Doctrine, API Platform, DBAL/Postgres, security/JWT, Messenger, or Stripe integration; no tenant/domain entities, API controllers, or backend test cases were found in tracked source.
- **Verified:** Compose defines local Postgres, Redis, and Mailpit services. Syntax was validated; services and database were not started.
- **Planned / not verified:** organization isolation/RLS, membership/roles/invitations, auth, subscription lifecycle, Stripe webhooks, usage enforcement, administration, and invoicing behavior.

## Architecture and repository layout

- `frontend/` — Vite application entry `src/main.tsx`; current screen `src/App.tsx` remains starter content.
- `backend/` — Symfony entry `public/index.php`, Kernel, framework/routing config. Domain/API/persistence code is not present in the audited tracked tree.
- `docker-compose.yml` — local PostgreSQL 16, Redis, Mailpit, and persistent DB volume.
- `docs/ui-spec.md` — planned mock-first UI specification; it does not prove backend behavior.
- `AGENTS.md` — project conventions and intended tenant/billing requirements. Its RLS/webhook descriptions require implementation and tests before claiming security or payment functionality.

## Prerequisites and local setup

Audit host had Node 24.13.0, pnpm 12.14.0, Docker Compose; PHP and Composer were unavailable. Backend manifest requires PHP >=8.2.

1. Inspect Compose services and existing volumes before optionally starting local development infrastructure:
   ```sh
   docker compose up -d
   ```
   This defines a persistent Postgres volume. Do not reset/remove it casually.
2. Frontend with the existing pnpm lockfile:
   ```sh
   cd frontend
   pnpm install
   pnpm dev
   ```
3. Backend installation is **not verified in the audit host**. Once PHP/Composer are available, inspect the local database configuration and confirm a disposable/local target before any migration. No application DB schema/migrations are currently evidenced.

Keep credentials in ignored local configuration; do not place secrets in tracked `.env` files or documentation.

## Development checks and quality

From `frontend/`:

- `pnpm run build` — **PASS** on 2026-10-20.
- `pnpm run lint` — **FAIL** on that date: installed `typescript-eslint@8.71.2` does not support TypeScript 7.0.2.
- No frontend test script is defined.

From `backend/`:

- `php bin/phpunit` is configured but **BLOCKED** because PHP is unavailable. Only `tests/bootstrap.php` is present; no backend application test cases were found.
- No CI workflow was found.

`docker compose -f docker-compose.yml config --quiet` passed; this checks Compose syntax only, not live DB, tenant policy, application integration, or persistence.

## Security, limitations, and technical debt

Tenant isolation is a critical planned requirement, not a verified control. Do not trust client-supplied organization IDs as authorization. Before billing, verify server-side role checks, organization-scoped queries, database connection/tenant context, webhook signatures and idempotency, and usage-limit race behavior. No auth, tenancy, invoice, or payment implementation was verified in tracked source. Use only test-mode billing fixtures after a provider integration is deliberately approved; never include credentials or production data in docs/tests.

## Roadmap

See [DEVELOPMENT_ROADMAP.md](DEVELOPMENT_ROADMAP.md) for phases, tenant/security gates, prioritized tasks, acceptance criteria, and blockers.

## Contributing and AI-agent workflow

Read [AGENTS.md](AGENTS.md), [docs/ui-spec.md](docs/ui-spec.md), and the roadmap before changes. Preserve existing tracked/untracked work; inspect Git status, branch, and diffs before editing. Keep this repository independent. Tenant/auth/billing/schema/API contracts require one coordinated owner before frontend/backend work is split. Ask before dependencies, architecture, migrations, or security changes; never use production data or perform destructive DB, deploy, push, or commit operations without approval. Record exact commands/output and do not report unrun or blocked checks as passing.

Update README and roadmap when implemented capabilities, architecture, setup, dependencies, or verification evidence changes.
