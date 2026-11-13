# How to Run Keelson Locally

This guide covers the currently implemented local development scope. The frontend is a mock-data dashboard shell; it does not call the backend API. The backend has organization-scoped customer and invoice endpoints, but billing, PostgreSQL RLS, and real Logto end-to-end verification are not implemented.

## Prerequisites

Versions verified on this machine on 2026-11-13:

- Node.js `v22.13.0`
- pnpm `12.14.0`
- PHP `8.5.12` (project requirement: `>=8.2`)
- Composer `2.10.0`
- Docker Compose `v5.1.4` for local PostgreSQL

The Docker Desktop Linux engine was unavailable during the latest check. Start Docker Desktop yourself before using Compose. Do not assume the Compose database is disposable: Compose uses the persistent `db_data` volume.

## Run the frontend

From the repository root in PowerShell:

```powershell
Set-Location .\frontend
pnpm install --frozen-lockfile
pnpm dev --host 127.0.0.1
```

Open `http://127.0.0.1:5173`. Verified on 2026-11-13: this command binds `127.0.0.1:5173` and returns HTTP 200. Vite's default (plain `pnpm dev`) binds only localhost/IPv6, so pass `--host` directly — the `pnpm run dev -- --host ...` form was observed to ignore the flag.

The frontend runs independently using typed mock data; it does not require the backend or database.

Frontend checks:

```powershell
pnpm run build
pnpm run lint
```

There is currently no frontend test script.

## Start local backend services

From the repository root:

```powershell
docker compose -f docker-compose.yml ps
docker compose -f docker-compose.yml up -d db
docker compose -f docker-compose.yml ps
```

The PostgreSQL service listens on `127.0.0.1:5433`, with local database/user defaults `keelson`/`app`. Compose also defines Redis and Mailpit, but the application does not currently use them. The database data is persistent. Never run `docker compose down -v`, delete the volume, or use this database for integration tests.

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

Another environment note: this machine's PHP lacks `ext-sodium`, which the locked `lcobucci/jwt` declares. `composer install` fails its platform check unless the extension is enabled or Composer is called with `--ignore-platform-req=ext-sodium`; the install used here did the latter.

Before migrating, confirm `DATABASE_URL` identifies a local database you own. Then:

```powershell
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:migrate --no-interaction
```

Do not run these commands against a shared, production, or unknown database. To serve the API using PHP's built-in server:

```powershell
php -S 127.0.0.1:8000 -t public public/index.php
```

Passing `public/index.php` explicitly is necessary for Symfony to receive `/api/...` paths. The API requires a configured OIDC provider and authenticated session.

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

The full PHPUnit suite requires a separate, verified disposable PostgreSQL database. The test environment appends `_test` to the configured database name, and the database-backed tests write and delete rows. Follow the isolated-container procedure in [backend/README.md](backend/README.md); never point the test URL at the Compose `keelson` database or any database with data to preserve.

## Current verification status

As of 2026-11-13, frontend frozen install, build, and lint passed. Composer validation, PHP syntax checks, Symfony YAML/container/route checks, and the database-independent backend unit tests passed. The frontend dev server returned HTTP 200 in a local smoke check.

The full PostgreSQL-backed test suite was not run because Docker was unavailable and no disposable database was selected. Real Logto sign-in/callback/logout was not verified, but the `/login` redirect itself now passes: the previously missing `symfony/translation-contracts` package is installed and `GET /login` returned HTTP 302 with `state`, `nonce` and an S256 `code_challenge`. With placeholder OIDC values, an unauthenticated API request was confirmed to reach the firewall and be denied with `AccessDeniedException` instead of a missing-environment error. These are explicit remaining checks, not passing results.

See [DEVELOPMENT_ROADMAP.md](DEVELOPMENT_ROADMAP.md) for phase status, [README.md](README.md) for the project overview, [backend/README.md](backend/README.md) for OIDC and isolated-database detail, and [frontend/README.md](frontend/README.md) for the frontend scope.
