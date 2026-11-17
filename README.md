# Keelson

A multi-tenant SaaS starter you can put in front of a real customer: organizations
with roles, invitations, a first-party email + password account flow, tenant-scoped
REST resources, an audit trail, notifications, three languages including
right-to-left Arabic, and light/dark themes. The demo product on top of the
plumbing is invoicing for small businesses — customers, invoices and their
lifecycle.

It is two applications and one browser suite:

- `backend/` — Symfony 7.4 API on PHP 8.2+ (Doctrine ORM 3, PostgreSQL 16).
- `frontend/` — React 19 + Vite 8 single-page dashboard in TypeScript.
- `e2e/` — Playwright journeys that drive the real stack, not mocks.

## What it does

**Accounts and access**

- Register with an address and a first organization; confirm the address through a
  mailed link before signing in.
- Sign in with a server-side session (`HttpOnly`, `SameSite=Lax`, `Secure` on
  HTTPS); passwords are hashed, sign-in is rate limited per address and per client.
- Forgot/reset password, change password, sign out.
- Invite a colleague by email; the invitation is previewed publicly, accepted with
  a name and password, and becomes a membership with a role.
- Four roles — Owner, Admin, Member, Viewer — enforced by voters on every request.
  A Viewer reads everything and writes nothing. Denials answer `404`, not `403`, so
  the API never confirms that another tenant's record exists.
- Switch organizations; the server re-authorizes the switch on every request.

**The invoicing product**

- Customers with optional billing address, searchable, cursor-paginated.
- Invoice drafts with line items, tax rate, discount and server-computed totals;
  draft → sent → paid, plus an automatic overdue transition.
- Every transition writes an audit event; the detail screen shows the trail.
- A dashboard of the numbers that matter in the current organization.
- A notification feed for members, invitations and invoice transitions.

**Platform behaviour**

- JSON error envelope (`error.code`, `error.message`, field errors) for every
  failure; request-shape and type errors are `422`, never `500`.
- CSRF and same-origin enforcement on every unsafe request.
- Cursor (keyset) pagination for every list — no page drift while rows are added.
- English, French and Arabic, with logical CSS properties so Arabic mirrors.
- Light, dark and system themes from one token file.
- A production configuration validator that refuses to boot on unsafe settings.

## Architecture

```mermaid
flowchart LR
    subgraph Browser
        SPA["React SPA<br/>Vite build, hash-named assets"]
    end

    subgraph Edge
        NGINX["nginx<br/>static assets + SPA fallback<br/>security headers + CSP"]
    end

    subgraph Application
        FPM["php-fpm / Symfony 7.4<br/>session auth, voters, JSON API"]
    end

    subgraph Data
        PG[("PostgreSQL 16<br/>tenants, invoices, sessions,<br/>audit events, notifications")]
    end

    MAIL["SMTP<br/>(Mailpit in development)"]

    SPA -->|"same-origin /api/*"| NGINX
    NGINX -->|"FastCGI"| FPM
    FPM -->|"Doctrine DBAL"| PG
    FPM -->|"verification, reset,<br/>invitation mail"| MAIL
    FPM -.->|"audit + notification rows"| PG
```

Tenant isolation is **application-layer**: every repository call and voter requires
an active membership for the organization in the route, and the organization id in
the URL is a selector, never authority. PostgreSQL row-level security is not
implemented; see [docs/adr/0003](docs/adr/0003-postgresql-row-level-security.md)
for the reasoning and the consequences.

## Quick start

Prerequisite: Docker Desktop, PHP 8.2+ with `ext-sodium`, Composer, Node 20.19+/22.12+
and pnpm 12.14.

```sh
# 1. PostgreSQL 16 (127.0.0.1:5433) and Mailpit (UI http://localhost:8026)
docker compose up -d

# 2. Backend: dependencies, local configuration, schema, server on :8000
cd backend && composer install && cp .env.example .env.local   # set APP_SECRET
php bin/console doctrine:migrations:migrate && php -S 127.0.0.1:8000 -t public public/index.php

# 3. Frontend: dependencies and Vite on :5173
cd ../frontend && pnpm install --frozen-lockfile && pnpm start

# 4. (optional) synthetic demo accounts and data
bash ../backend/bin/dev-reset.sh

# 5. Browser suite, once: install Chromium
pnpm --dir ../e2e exec playwright install chromium
```

Open <http://localhost:5173>: a signed-out visitor lands on `/sign-in`, and
"Create an account" registers the address with its first organization. The
confirmation mail appears in Mailpit at <http://localhost:8026>.

## Demo accounts

`bash backend/bin/dev-reset.sh` rebuilds `keelson_dev` (drop, create, migrate, seed)
and prints the accounts. Every account shares the password **`Keelson-Demo-2026!`**
(`FIXTURE_PASSWORD` overrides it; at least 10 characters).

| Email | Role | Organization |
| --- | --- | --- |
| `owner@example.test` | Owner | Northwind Demo Co. (fixture) |
| `admin@example.test` | Admin | Northwind Demo Co. (fixture) |
| `member@example.test` | Member | Northwind **and** Contoso (fixture) |
| `viewer@example.test` | Viewer | Contoso Demo Co. (fixture) |
| `unverified@example.test` | — | none; sign-in answers `403 email_not_verified` |

The loader refuses `APP_ENV=prod`, non-PostgreSQL drivers, non-loopback hosts and
database names without a `test`/`dev`/`fixture` segment, so these accounts cannot
reach a real deployment.

`php bin/console app:fixtures:bulk` adds 1,000 customers and 10,000 invoices for
performance work; see [docs/performance.md](docs/performance.md).

## Command-line tools

| Command | What it does |
| --- | --- |
| `php bin/console app:config:check` | Validates the environment without printing secrets; exits non-zero on a fatal finding |
| `php bin/console app:db:target` | Prints `database@host` for the effective connection, credentials excluded |
| `php bin/console app:fixtures:load --force` | Seeds the demo accounts and data into a local dev/test database |
| `php bin/console app:fixtures:bulk` | Bulk performance data (1,000 customers / 10,000 invoices) |
| `php bin/console app:invoices:mark-overdue` | Moves sent invoices past their due date to overdue, with audit and notifications |

## Environment variables

Backend (read from the process environment, then `backend/.env`, then
`.env.local` — which is git-ignored and the only place credentials belong):

| Variable | Required | Purpose |
| --- | --- | --- |
| `APP_ENV` | yes | `dev`, `test` or `prod` |
| `APP_DEBUG` | prod: `0` | Debug toolbar and verbose errors; must be `0` in production |
| `APP_SECRET` | yes | Signs CSRF tokens and the session; a long random value per environment |
| `APP_SHARE_DIR` | no | Shared writable directory (`var/share` by default) |
| `DATABASE_URL` | yes | `postgresql://user:pass@host:5432/db?serverVersion=16&charset=utf8` |
| `DEFAULT_URI` | yes | Absolute URL for CLI-generated links and absolute redirects |
| `FRONTEND_URL` | yes | Origin of the SPA; verification, reset and invitation links point here (must be `https://` in production) |
| `MAILER_DSN` | yes | SMTP endpoint; `smtp://127.0.0.1:1026` with Mailpit locally, never `null://` in production |
| `REQUIRE_VERIFIED_EMAIL` | no | Defaults to `true`; refuses to sign in an unconfirmed address |
| `TRUSTED_HOSTS` | prod: yes | Comma-separated hosts or one regex; mandatory in production |
| `TRUSTED_PROXIES` | behind a proxy | CIDRs of the reverse proxy; must be paired with `TRUSTED_HEADERS` |
| `TRUSTED_HEADERS` | behind a proxy | e.g. `x-forwarded-for,x-forwarded-proto,x-forwarded-host,x-forwarded-port` |
| `LOG_LEVEL` | no | Structured-log threshold override for an investigation |
| `FIXTURE_PASSWORD` | no | Shared password for the seeded demo accounts (local dev/test only) |
| `TEST_TOKEN` | test only | Suffixes the test database name so parallel runs do not collide |

Frontend:

| Variable | Required | Purpose |
| --- | --- | --- |
| `VITE_API_BASE_URL` | no | Only when the API is served from another origin; empty means same-origin `/api` |

Browser suite: `E2E_BASE_URL`, `MAILPIT_URL`, `FIXTURE_PASSWORD` and `CI` — see
[e2e/README.md](e2e/README.md).

## Checks

Everything below is what CI runs ([.github/workflows/ci.yml](.github/workflows/ci.yml));
each command is also runnable locally.

```sh
# Backend, from backend/
composer validate --no-check-publish
php bin/console lint:yaml config --env=test
php bin/console lint:container --env=test
php bin/console debug:router --env=test
composer stan                    # PHPStan level 8 + dead-code detector, no baseline
composer cs                      # php-cs-fixer, dry run
bash bin/test-db.sh start        # throwaway PostgreSQL 16, ephemeral port
php bin/phpunit                  # backend counts: bash bin/test-counts.sh
php bin/coverage-check.php var/coverage/clover.xml   # 80% for Service, Security, Controller
bash bin/test-db.sh stop
composer audit

# Frontend, from frontend/
pnpm install --frozen-lockfile
pnpm exec tsc -b
pnpm run lint
pnpm test                        # node + jsdom projects (same script reports counts)
pnpm run build
pnpm run check:bundle            # entry and total JavaScript budgets
pnpm exec knip                   # unused files, exports, types, dependencies
node scripts/unused-i18n.mjs     # dictionary keys no screen references
pnpm audit --audit-level high

# Browser journeys, from e2e/
bash prepare-stack.sh            # docker compose up db mailpit + rebuild keelson_dev
pnpm test                        # 7 journeys, chromium, one worker
```

The backend suite needs a disposable PostgreSQL: `bin/test-db.sh start` creates a
volume-free container on an ephemeral loopback port and writes a git-ignored
`.env.test.local`. It never touches the Compose `db_data` volume. Coverage needs
`pcov` (or `xdebug`); CI installs `pcov` and enforces the threshold through
`backend/bin/coverage-check.php`, because PHPUnit has no per-directory threshold.

## Project structure

```
backend/                 Symfony API
  src/                   Controller, Service, Security (voters), Entity, Repository,
                         Listener, Command, Config (EnvironmentValidator)
  config/                YAML configuration, security firewall, services
  migrations/            versioned schema, including the pg_trgm search indexes
  tests/                 Unit tests (no database) and Integration tests (real PostgreSQL)
  bin/                   dev-reset, test-db, perf, coverage-check
  docker/                php.ini and php-fpm pool for the image
  Dockerfile             multi-stage production image (php-fpm, non-root, opcache)
frontend/                React SPA
  src/api/               typed API layer, one module per resource
  src/screens/           one file (and stylesheet) per screen
  src/components/        Field, DataTable, OverlaySurface, Toast, …
  src/i18n/{en,fr,ar}/   dictionaries; `en` is the shape the others are typed against
  src/theme/tokens.css   every colour, radius and shadow
  nginx.conf             static serving, SPA fallback, /api to php-fpm, CSP
e2e/                     Playwright journeys and their support code
docs/                    ADRs, API contract, OpenAPI, deployment, performance
docker/postgres/         init script that creates the least-privilege runtime role
```

## Production

[docs/deployment.md](docs/deployment.md) covers the image build, environment,
migrations as the owner role, backups with `pg_dump`, rollback, the reverse proxy
headers and the scheduled overdue job. `docker-compose.prod.example.yml` is a
working shape of the whole stack — PostgreSQL, a migrate job, the API and the
static frontend — with every secret taken from the environment and no defaults
for any of them.

## Limits

[docs/limitations.md](docs/limitations.md) is the honest list: no row-level
security, no billing, no email-change flow, no multi-factor authentication, single
locale per build of the email templates, and the measured local latency figures.
Read it before promising a feature to a customer.

## Documentation

- [AGENTS.md](AGENTS.md) — repository rules, architecture and verified baseline.
- [docs/deployment.md](docs/deployment.md) — build, configure, migrate, back up, roll back.
- [CONTRIBUTING.md](CONTRIBUTING.md) — branch, commit and check conventions.
- [SECURITY.md](SECURITY.md) — how to report a vulnerability and what is supported.
- [CHANGELOG.md](CHANGELOG.md) — the 1.0.0 history, sprint by sprint.
- [docs/ROADMAP.md](docs/ROADMAP.md) — the nine sprints and what each delivered.
- [docs/adr/](docs/adr/) — the five decisions worth knowing before changing things.
- [docs/openapi.yaml](docs/openapi.yaml) — the API contract, kept in step with the router by a test.
- [docs/api-contract.md](docs/api-contract.md) — prose annotations the OpenAPI document cannot carry.
- [docs/performance.md](docs/performance.md) — measured list-endpoint timings and indexes.
- [docs/ui-spec.md](docs/ui-spec.md) — the screen specification.
- [e2e/README.md](e2e/README.md) — the browser journeys and how to run them.

## Licence

There is no `LICENSE` file: the terms under which this code may be used are an
open question for the owner (see the release notes). Until that is decided, treat
the repository as all rights reserved.
