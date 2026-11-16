# Keelson

Multi-tenant SaaS starter: organizations, roles, invitations, subscriptions, usage
limits and an admin panel. The demo product on top is invoicing for small
businesses.

Two applications:

- `backend/` — Symfony 7.4 API (PHP >=8.2), Doctrine ORM 3 / DBAL 4 / Migrations,
  PostgreSQL 16. Session-based authentication (an OIDC session today; see
  [docs/ROADMAP.md](docs/ROADMAP.md) for the replacement sprints). Tenant-scoped
  customer, invoice and invoice-audit HTTP API under
  `/api/organizations/{organizationId}/…`, plus `/api/session`,
  `/api/me/organizations`, `/login`, `/logout` and `/healthz`.
- `frontend/` — React 19 + Vite 8 + TypeScript dashboard shell. Session,
  organization/role, customers and invoices are real API data; dashboard metrics,
  onboarding/invitations, team, billing, pricing and notifications are labelled
  mock surfaces with no backend yet.

Brand color: `#0F766E` (`--color-primary` in `frontend/src/theme/tokens.css`).

Tenant isolation is application-layer only: repositories and controllers require
an active membership for the route organization, and a client-supplied
organization ID is a selector, never authority. PostgreSQL row-level security is
not implemented. Never assume otherwise without reading the schema, queries and
code.

## Quick start

Prerequisites: PHP 8.2+ with `ext-sodium` (declared by `lcobucci/jwt`), Composer,
Node 20.19+/22.12+, pnpm 12.14, Docker Desktop.

```sh
# 1. PostgreSQL 16 (127.0.0.1:5433, db/user keelson/app) and Mailpit (UI :8026)
docker compose up -d

# 2. Backend dependencies and local configuration
cd backend
composer install
cp .env.example .env.local     # then fill APP_SECRET, DATABASE_URL, OIDC_*
php bin/console doctrine:migrations:migrate
symfony serve --no-tls --port=8000
# or: php -S 127.0.0.1:8000 -t public public/index.php

# 3. Frontend
cd ../frontend
pnpm install --frozen-lockfile
pnpm start                     # Vite on http://127.0.0.1:5173
```

`backend/.env.local` is git-ignored and the only place credentials belong. It
needs `APP_SECRET`, `DATABASE_URL` (the Compose URL is
`postgresql://app:app@127.0.0.1:5433/keelson?serverVersion=16&charset=utf8`) and,
for any request through the firewall, `OIDC_ISSUER`, `OIDC_WELL_KNOWN_URL`,
`OIDC_CLIENT_ID` and `OIDC_CLIENT_SECRET`. Console commands work without the OIDC
values; every HTTP request fails with
`Environment variable not found: "OIDC_CLIENT_ID"` until they are set. There is
no offline or password login today.

`GET /healthz` is a public, database-free probe, so it answers before OIDC is
configured. The Compose database uses the persistent `db_data` volume: never
reset it, never run `docker compose down -v`, and never point migrations or tests
at it.

## Checks

Frontend, from `frontend/`:

```sh
pnpm install --frozen-lockfile
pnpm run build                 # tsc -b && vite build
pnpm run lint                  # eslint
pnpm test                      # vitest, Node environment
```

Backend, from `backend/`:

```sh
composer validate --no-check-publish
php bin/console lint:yaml config --env=test
php bin/console lint:container --env=test
php bin/console debug:router --env=test
./vendor/bin/phpstan analyse    # level 5, baseline in phpstan-baseline.neon
composer audit

# Full suite: needs a disposable PostgreSQL 16, never the Compose volume
bash bin/test-db.sh start       # tmpfs container, ephemeral port, migrates
php bin/phpunit
bash bin/test-db.sh stop        # removes the container and .env.test.local
```

Database-independent subset (no PostgreSQL needed):

```sh
php bin/phpunit tests/Unit/Entity tests/Unit/Security tests/Unit/Service tests/Unit/Util tests/Unit/EventListener
```

Last run in this repository (2026-11-16, Sprint 1):

| Check | Result |
| --- | --- |
| `pnpm install --frozen-lockfile` | PASS |
| `pnpm run build` | PASS — `dist/assets/index-*.js` 371.20 kB / 108.85 kB gzip |
| `pnpm run lint` | PASS — 0 findings |
| `pnpm test` | PASS — 57 tests in 5 files |
| `composer install` | PASS |
| `composer validate --no-check-publish` | PASS |
| `php bin/console lint:container --env=test` | PASS |
| `php bin/console debug:router --env=test` | PASS — 20 routes |
| `php bin/phpstan analyse` | PASS — no errors |
| `php bin/phpunit` (disposable container) | **FAIL — 115 tests, 585 assertions, 4 failures** |
| `doctrine:schema:validate --env=test` | **FAIL** — see limitations |

The four PHPUnit failures are older than Sprint 1 and unchanged by it: anonymous
`/api/...` requests answer `401` JSON from `ApiAuthenticationEntryPoint`, while
`InvoiceHttpApiTest`, `MeOrganizationsTest`, `SessionLogoutTest` and
`OidcSessionLogoutTest` still expect a redirect to `/login`. Mapping validation
(`doctrine:schema:validate --skip-sync`) passes and PHPStan reports no errors.

## Limitations

- Tenant isolation is enforced in repositories and controllers, not by PostgreSQL
  RLS. There is no defence in depth behind the application layer.
- Real provider sign-in/callback/sign-out has never been run: no test Logto client
  is configured on this checkout. Only the `/login` redirect has been checked.
- Billing, subscriptions, usage enforcement and invitation delivery are not
  implemented. In the frontend, dashboard metrics, onboarding/invitations, team,
  billing, pricing and notifications are mock surfaces.
- `php bin/console doctrine:schema:validate --env=test` fails while introspecting
  the database: `An index with name "idx_6a2f2f95a5a280d" was already defined on
  table "invoices"`. DBAL 4.5 synthesizes that name for the `fk_invoices_customer`
  columns and the migration declares it too, so introspection sees it twice. Do
  not run `schema:update` or `migrations:diff` against that database until it is
  resolved.
- No component/DOM frontend tests (`jsdom` + Testing Library would be a new
  dependency). No OpenAPI document; `frontend/src/api/` is hand-written and
  `docs/api-contract.md` records the verified shapes.
- There is no CSRF token exposed to API clients: the mutation guard accepts a
  same-origin `Origin` header for `invoice_api`.

## Documentation

- [AGENTS.md](AGENTS.md) — repository rules, architecture and verified baseline.
- [docs/ROADMAP.md](docs/ROADMAP.md) — the nine sprints and their status.
- [docs/api-contract.md](docs/api-contract.md) — verified request/response shapes.
- [docs/ui-spec.md](docs/ui-spec.md) — the screen specification; it does not prove
  backend behaviour.
- [backend/README.md](backend/README.md) — OIDC, API surface, fixtures, isolated
  test database.
- [frontend/README.md](frontend/README.md) — frontend scope, stack, API layer.

## Rules

Read [AGENTS.md](AGENTS.md) before changes. Keep tenant, auth, billing, migration
and API-contract changes behind one owner; ask before adding dependencies or
changing architecture; never commit secrets; record exact commands and results
and never report an unrun or failed check as passing.
