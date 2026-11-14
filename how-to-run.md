# How to Run Keelson Locally

This guide covers the currently implemented local development scope. The frontend is a mock-data dashboard shell; it does not call the backend API. The backend has organization-scoped customer and invoice endpoints, but billing, PostgreSQL RLS, and real Logto end-to-end verification are not implemented.

## Prerequisites

Versions verified on this machine on 2026-11-13:

- Node.js `v22.13.0`
- pnpm `12.14.0`
- PHP `8.5.12` (project requirement: `>=8.2`)
- Composer `2.10.0`
- Docker Compose `v5.1.4` for local PostgreSQL

Docker Desktop (engine 29.5.2) is available. The Compose project name is pinned to `keelson` in `docker-compose.yml` (and can also be passed explicitly with `-p keelson`), so containers appear as `keelson-db-1`, `keelson-redis-1` and `keelson-mailpit-1`. Compose runs infrastructure only — Symfony and the frontend run on the host. Do not assume the Compose database is disposable: it uses the persistent `keelson_db_data` volume.

## Run the frontend

From the repository root in PowerShell:

```powershell
Set-Location .\frontend
pnpm install --frozen-lockfile
npm start
```

`npm start` is the requested entry point and maps to `vite --host 127.0.0.1`; `pnpm dev --host 127.0.0.1` is equivalent. Install with pnpm because `pnpm-lock.yaml` is the committed lockfile — there is no `package-lock.json`, and `npm start` only runs the script, it does not install or rewrite a lockfile.

Open `http://127.0.0.1:5173`. Verified on 2026-11-14: `npm start` served Vite 8.3.4 on `127.0.0.1:5173` and returned HTTP 200. Vite's default (plain `pnpm dev`) binds only localhost/IPv6, so pass `--host` directly — the `pnpm run dev -- --host ...` form was observed to ignore the flag.

### Frontend ↔ API requests in development

The shell mirrors the real Symfony session for sign-out, so `vite.config.ts` proxies `/api`, `/login` and `/logout` to the backend. The default target is `http://127.0.0.1:8000`; override it when the API listens elsewhere:

```powershell
$env:KEELSON_BACKEND_ORIGIN = "http://127.0.0.1:8011"
npm start
```

The proxy must be same-origin so the browser sends and receives the session cookie. `GET /api/session` returns the caller's own authentication state and, when signed in, the CSRF token required by `POST /logout`; with no session it returns `{"authenticated":false,"user":null,"logout":null}`. Verified 2026-11-14 through the Vite origin: HTTP 200, `cache-control: no-store, private`.

Sign-out is not simulated. The **Log out** item in the account menu posts the CSRF token to `/logout` and only shows the signed-out screen after a follow-up `/api/session` read confirms the session ended; otherwise it reports that the server did not confirm. Without an OIDC session it reports that there is no active session. Real end-to-end sign-in/sign-out still requires a configured test Logto tenant (see `backend/README.md`).

The frontend runs independently using typed mock data; it does not require the backend or database. It supports English, French and Arabic (with Arabic right-to-left), the existing light/dark/system appearance, a profile menu with My account/Notifications/Settings, an inbox with All/Unread filters and mark-one/mark-all read actions, labelled notification preferences, and a search dialog (`Ctrl+K`/`Meta+K`) over local mock pages, organizations, invoices, customers and notifications. JSON in `localStorage` is validated on read: invalid values fall back to defaults rather than being trusted. Language, theme, read state and preferences persist under `keelson-locale`, `keelson-theme`, `keelson-notifications-read` and `keelson-notification-prefs`; nothing is sent to a server. The browser tab uses the existing `public/Keelson.svg` logo.

Frontend checks:

```powershell
pnpm run build
pnpm run lint
```

There is currently no frontend test script.

## Start local backend services

From the repository root:

```powershell
docker compose -p keelson ps
docker compose -p keelson up -d
docker compose -p keelson ps
```

The services are named `keelson-db-1`, `keelson-redis-1` and `keelson-mailpit-1`. PostgreSQL listens on `127.0.0.1:5433` (database/user `keelson`/`app`), Redis on `6380` and Mailpit on `1026`/`8026`. Redis and Mailpit are not used by the application code. The database data is persistent in `keelson_db_data`; never run `docker compose -p keelson down -v`, delete the volume, or use this database for integration tests.

## Configure and run the backend

From the repository root:

```powershell
Set-Location .\backend
composer install
```

Create `backend/.env.local` if it does not exist. Keep it local and untracked; never put real credentials in this guide. Configure:

```dotenv
APP_SECRET=<random-local-value>
DATABASE_URL=postgresql://app:app@127.0.0.1:5433/keelson?serverVersion=16&charset=utf8
OIDC_ISSUER=<issuer-from-your-test-Logto-application>
OIDC_WELL_KNOWN_URL=<discovery-url-from-your-test-Logto-application>
OIDC_CLIENT_ID=<test-client-id>
OIDC_CLIENT_SECRET=<test-client-secret>
```

The OIDC values must come from a test Logto application. No offline/password login is configured. Without the OIDC values, Symfony console checks can work, but HTTP requests fail while resolving the security firewall (currently with a missing `OIDC_CLIENT_ID` error). Do not expose the client secret or use production credentials.

**Sign-in redirect verified on 2026-11-13.** `symfony/translation-contracts` (`^3.8`) was missing, which made `GET /login` throw `LogicException: ... AsciiSlugger ... symfony/translation-contracts ... is not installed` while building the PKCE challenge. The package is now declared, and `GET /login` returns HTTP 302 with `state`, `nonce`, `code_challenge` and `code_challenge_method=S256` (checked with placeholder credentials plus a temporary local discovery document). Completing a real sign-in still requires a Logto client.

Environment note: the locked `lcobucci/jwt` declares `ext-sodium`. On this machine it is now enabled (2026-11-13) in the host's PHP CLI ini `…\php\cli\php-phase7.ini` via `extension=php_sodium.dll`, so `composer install` and `composer check-platform-reqs` pass with no flags. Other machines must enable the extension before installing; `--ignore-platform-req=ext-sodium` only hides the missing extension and should not be treated as a passing check.

Before migrating, confirm `DATABASE_URL` identifies a local database you own. Then:

```powershell
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:migrate --no-interaction
```

Do not run these commands against a shared, production, or unknown database.

### Rollback procedure (disposable database only)

Migrations are reversible. Verify both directions on a database you positively identified as disposable (for example the `bin/test-db.sh` container, never the Compose `keelson` database):

```powershell
php bin/console doctrine:migrations:status --env=test
php bin/console doctrine:migrations:migrate --env=test first --no-interaction   # down to version 0
php bin/console doctrine:migrations:migrate --env=test --no-interaction         # back up to latest
```

Verified 2026-11-14 on the helper's tmpfs container: down applied 2 migrations / 18 SQL queries and left only `doctrine_migration_versions`; up re-applied 2 migrations / 44 queries, both exit 0. For a rollback on a real environment: take a backup first, stop writes, run the specific `migrate '<version>'` target, and re-run the health probe. Never roll back by deleting volumes.

### Serve the API

```powershell
symfony serve --no-tls --port=8000
# or, with PHP's built-in server (pass the front controller explicitly):
php -S 127.0.0.1:8000 -t public public/index.php
```

`GET http://127.0.0.1:8000/healthz` is a public liveness probe with its own stateless firewall, so it returns `{"status":"ok"}` even before OIDC values are configured — verified 2026-11-14 with `symfony serve` (HTTP 200). `GET /api/session` is also reachable while signed out, because the shell must learn it is anonymous without being bounced through OIDC; it performs no database query and exposes only the caller's own identity. Every other route requires a configured OIDC provider and an authenticated session. Passing `public/index.php` explicitly is necessary for Symfony to receive `/api/...` paths when using PHP's built-in server.

## Run backend checks

From `backend/`:

```powershell
composer validate --no-check-publish
php bin/console lint:yaml config --env=test
php bin/console lint:container --env=test
php bin/console debug:router --env=test
php bin/phpunit tests/Unit/Entity tests/Unit/Security tests/Unit/Service tests/Unit/Util
```

The unit-test command above was verified at 29 tests / 72 assertions. It avoids the PostgreSQL-dependent repository and integration suites.

The full PHPUnit suite requires a separate, verified disposable PostgreSQL database. The test environment appends `_test` to the configured database name, and the database-backed tests write and delete rows. From `backend/`:

```powershell
bash bin/test-db.sh start   # throwaway postgres:16-alpine, wires .env.test.local, migrates
php bin/phpunit
bash bin/test-db.sh stop    # removes the container and the generated env file
```

The helper publishes an ephemeral loopback port and reads it back from Docker, so no port has to be guessed. Its container is labelled per checkout and stores data in tmpfs (no volume); it refuses to reuse, stop or remove any container it did not create. `start` refuses if `.env.test.local` already exists, even if marked as a previous helper file; it never overwrites a developer's file. `stop` removes the generated file only after the owned container is removed. A failed start or migration removes only resources created by that run. See [backend/README.md](backend/README.md) for details; never point the test URL at the Compose `keelson` database or any database with data to preserve.

## CI and dependency audit

`.github/workflows/ci.yml` runs on pushes to `main`, pull requests and manual dispatch:

- frontend: `pnpm install --frozen-lockfile`, `pnpm run build`, `pnpm run lint` (Node 24, pnpm 12.14.0);
- backend: Composer validation and platform requirements, YAML/container/route lint, migrations and the full PHPUnit suite against a `postgres:16-alpine` service container seeded with the `keelson_test` database and dummy (non-secret) OIDC values;
- audit: `composer audit` and `pnpm audit --audit-level moderate`, both `continue-on-error` so findings are reported without blocking or auto-remediating.

The workflow file uses no repository secrets and no production data. It was validated locally on 2026-11-14 by parsing it with Symfony YAML (3 jobs) but has not been executed on GitHub-hosted runners.

Dependency audit findings recorded 2026-11-14 (not remediated; remediation needs separate approval):

- `composer audit`: 13 advisories across 9 packages (`symfony/http-foundation` 4, `symfony/security-http` 2, `phpseclib/phpseclib`, `symfony/config`, `symfony/filesystem`, `symfony/framework-bundle`, `symfony/http-kernel`, `symfony/routing`, `symfony/yaml` 1 each). Exit 1.
- `pnpm audit --audit-level moderate`: 1 moderate advisory, `minimatch <11.0.1` reached through ESLint (37 paths). Exit 1.

## Current verification status

**2026-11-14 run (current):** Docker Desktop was started and the Compose engine reported 29.5.2. `docker compose -p keelson up -d` started `keelson-db-1`, `keelson-redis-1` and `keelson-mailpit-1`; PostgreSQL accepts connections, Redis answers `PONG`, Mailpit returns HTTP 200, and the persistent `keelson_db_data` volume was not reset, migrated or queried. A separate `bash bin/test-db.sh start` provisioned the labelled tmpfs PostgreSQL 16.15 container. `php bin/phpunit` passed **52 tests / 229 assertions**, exit 0; the helper-owned container and generated ignored env file were removed afterwards. The Compose database was not used. `npm start` served the frontend on `127.0.0.1:5173` (HTTP 200); `symfony serve` served the backend and `GET /healthz` returned 200. `pnpm run build` and `pnpm run lint` passed; `composer validate`, YAML lint (13 files), container lint and the router listing (including `app_healthz`) passed. Migration down/up was verified on the disposable database. Full detail is in `DEVELOPMENT_ROADMAP.md`.

**2026-11-13 (historical):** frontend frozen install, build, and lint passed. Composer validation, PHP syntax checks, and Symfony YAML/container/route checks passed. The frontend dev server returned HTTP 200 in a local smoke check.

The **full backend PHPUnit suite passes against the helper's disposable PostgreSQL 16 container** — the 2026-11-14 run reported 52 tests / 229 assertions with exit 0. The container was labelled for this checkout, used tmpfs instead of a volume and an ephemeral loopback port, and was removed with its generated ignored env file after the run. The Compose `keelson_db_data` volume was never used for tests. The suite includes the draft edit/delete race regression tests, which hold a real `FOR UPDATE` lock on a second connection and assert the request blocks, two transaction-ownership regressions (a rejected edit no longer rolls back, and an idempotent send no longer commits, a transaction the handler did not open), plus fixture-loader idempotency and target-guard tests.

The frontend smoke test covered all five screens (Dashboard, Onboarding, Account, Notifications, Settings) in EN/FR/AR for light, dark and system appearance (45 combinations; system resolved to dark on this machine), plus search open/query/no-result/result/navigation, profile-menu keyboard behavior, Arabic RTL and a 430 px viewport. `lang`/`dir`, theme, notification read state and preferences survive a reload, the favicon resolves to `/Keelson.svg`, and no console errors were observed. No automated frontend tests exist.

Synthetic demo data can be seeded explicitly (never automatically):

```powershell
php bin/console app:fixtures:load --env=test --force
```

The command refuses `prod`, non-loopback hosts and database names that do not contain `test`, `dev` or `fixture`; it is idempotent and never deletes data. Fixture principals have no login credentials.

Real Logto sign-in/callback/logout remains unverified, but the `/login` redirect itself passes: `symfony/translation-contracts` is installed and `GET /login` returned HTTP 302 with `state`, `nonce` and an S256 `code_challenge`. With placeholder OIDC values, an unauthenticated API request reached the firewall and was denied with `AccessDeniedException` instead of a missing-environment error.

See [DEVELOPMENT_ROADMAP.md](DEVELOPMENT_ROADMAP.md) for phase status, [README.md](README.md) for the project overview, [backend/README.md](backend/README.md) for OIDC and isolated-database detail, and [frontend/README.md](frontend/README.md) for the frontend scope.
