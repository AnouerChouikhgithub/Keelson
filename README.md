# Keelson

Multi-tenant SaaS starter: organizations, roles, invitations, subscriptions, usage
limits and an admin panel. The demo product on top is invoicing for small
businesses.

Two applications:

- `backend/` — Symfony 7.4 API (PHP >=8.2), Doctrine ORM 3 / DBAL 4 / Migrations,
  PostgreSQL 16. First-party email + password authentication with a server-side
  session, mailed verification and reset links (see
  [docs/ROADMAP.md](docs/ROADMAP.md)). Tenant-scoped customer, invoice and
  invoice-audit HTTP API under `/api/organizations/{organizationId}/…`, plus
  `/api/auth/{register,login,verify-email,logout,forgot-password,reset-password}`,
  `/api/session`, `/api/me/organizations`, `/healthz` and `/readyz`.
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
cp .env.example .env.local     # then fill APP_SECRET and DATABASE_URL
php bin/console doctrine:migrations:migrate
symfony serve --no-tls --port=8000
# or: php -S 127.0.0.1:8000 -t public public/index.php

# 3. Frontend
cd ../frontend
pnpm install --frozen-lockfile
pnpm start                     # Vite on http://127.0.0.1:5173
```

Then open http://localhost:5173. A signed-out visitor lands on `/sign-in`;
"Create an account" registers the address together with its first organization.
The confirmation mail is caught by Mailpit at http://localhost:8026 — open the
link it contains and sign in. Nothing is pre-seeded, and the language selector on
the auth screens switches the whole flow between English, French and Arabic.

`backend/.env.local` is git-ignored and the only place credentials belong. It
needs `APP_SECRET` and `DATABASE_URL` (the committed development URL is
`postgresql://app:app@127.0.0.1:5433/keelson_dev?serverVersion=16&charset=utf8`).
Sign-in additionally reads `MAILER_DSN` (Mailpit at `smtp://127.0.0.1:1026`),
`FRONTEND_URL` (where verification and reset links point) and
`REQUIRE_VERIFIED_EMAIL`; without them console commands still work but new
accounts cannot confirm their address. Read the verification mail at
http://localhost:8026 after registering.

`GET /healthz` and `GET /readyz` are `PUBLIC_ACCESS` and need no session. The
Compose database uses the persistent `db_data` volume: never reset it, never run
`docker compose down -v`, and never point migrations or tests at it.

## Demo accounts

`backend/bin/dev-reset.sh` (or `backend\bin\dev-reset.ps1` on Windows) rebuilds
`keelson_dev` — drop, create, migrate, seed — and prints the accounts it created.
The seed is idempotent: running it twice creates nothing new and changes nothing.

| Email | Role(s) | Organization(s) |
| --- | --- | --- |
| `owner@example.test` | Owner | Northwind Demo Co. (fixture) |
| `admin@example.test` | Admin | Northwind Demo Co. (fixture) |
| `member@example.test` | Member | Northwind Demo Co. **and** Contoso Demo Co. (fixture) |
| `viewer@example.test` | Viewer | Contoso Demo Co. (fixture) |
| `unverified@example.test` | — (no membership) | — — sign-in answers `403 email_not_verified` |

Every account shares the password **`Keelson-Demo-2026!`**. Set `FIXTURE_PASSWORD`
(at least 10 characters) before seeding to use another one; the loader hashes it at
load time and never stores or prints a hash. The command's table shows the password
column only outside production, which the target guard refuses anyway.

```sh
bash backend/bin/dev-reset.sh                          # reset keelson_dev and seed
cd backend && php bin/console app:fixtures:load --force --env=dev   # seed only
```

These accounts exist **only in a local development or test database**: the loader
refuses `APP_ENV=prod`, non-PostgreSQL drivers, non-loopback hosts and any database
name without a `test`/`dev`/`fixture` segment. The seeded data is 2 organizations,
12 customers and 30 invoices (draft, sent, overdue and paid, with dates relative to
today) plus the invoice audit events the API would have written.

### An existing Docker volume

`POSTGRES_DB` only applies when the `db_data` volume is first initialised. A volume
created before this sprint still contains a database named `keelson`; create the
development one once and re-seed:

```sh
cd backend && php bin/console doctrine:database:create --env=dev
bash bin/dev-reset.sh          # now drops/creates/migrates/seeds keelson_dev
```

Never run that against a database named `keelson`, and never against the volume you
care about.

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
| `pnpm run build` | PASS — `dist/assets/index-*.js` 437.05 kB / 128.33 kB gzip |
| `pnpm run lint` | PASS — 0 findings |
| `pnpm test` | PASS — 61 tests in 6 files |
| `composer install` | PASS |
| `composer validate --no-check-publish` | PASS |
| `php bin/console lint:container --env=test` | PASS |
| `php bin/console debug:router --env=test` | PASS — 24 routes |
| `php bin/phpstan analyse` | PASS — no errors |
| `php bin/phpunit` (disposable container) | PASS — 170 tests, 932 assertions |
| `doctrine:schema:validate --env=test` | **FAIL** — see limitations |

The former anonymous-request failures are gone: an unauthenticated API call now
answers `401` JSON from `ApiAuthenticationEntryPoint`, which is what those tests
assert. Mapping validation (`doctrine:schema:validate --skip-sync`) passes and
PHPStan reports no errors.

## Limitations

- Tenant isolation is enforced in repositories and controllers, not by PostgreSQL
  RLS. There is no defence in depth behind the application layer.
- The shell's own navigation is still client-side state inside `App.tsx`; only
  the five auth screens are routes today (Sprint 7 moves the rest onto the router).
- No component/DOM tests: the auth screens are verified by a manual browser run
  and the API/session parsers by the node-environment suite (Sprint 8 adds DOM
  tests, which needs a `jsdom` dependency).
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
- CSRF tokens are exposed to the SPA through `GET /api/session` (`csrfToken`);
  the current frontend does not send them yet, which Sprint 3 fixes together with
  the sign-in screens.

## Documentation

- [AGENTS.md](AGENTS.md) — repository rules, architecture and verified baseline.
- [docs/ROADMAP.md](docs/ROADMAP.md) — the nine sprints and their status.
- [docs/api-contract.md](docs/api-contract.md) — verified request/response shapes.
- [docs/ui-spec.md](docs/ui-spec.md) — the screen specification; it does not prove
  backend behaviour.
- [backend/README.md](backend/README.md) — authentication, API surface, fixtures,
  isolated test database.
- [frontend/README.md](frontend/README.md) — frontend scope, stack, API layer.

## Rules

Read [AGENTS.md](AGENTS.md) before changes. Keep tenant, auth, billing, migration
and API-contract changes behind one owner; ask before adding dependencies or
changing architecture; never commit secrets; record exact commands and results
and never report an unrun or failed check as passing.
