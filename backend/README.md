# Backend setup

Symfony 7.4 API (PHP >=8.2) with Doctrine ORM 3 / DBAL 4 / Migrations and the Drenso Symfony OIDC bundle. Authentication is a server-managed session established by an OpenID Connect provider (Logto Cloud in the documented setup); local principals are provisioned after the OIDC library verifies the ID token.

Implemented API surface: tenant-scoped customer CRUD, invoice draft/list/read/update/send/mark-paid/delete, and a read-only invoice audit-event list, all under `/api/organizations/{organizationId}/...`. Tenant access is enforced by loading the organization membership server-side; an organization ID from the request is a selector, not authorization.

## Local setup

```sh
cd backend
composer install
```

Local values belong in `backend/.env.local`, which is ignored by Git. Do not commit secrets, and do not reuse production or customer credentials.

Environment note: this machine's PHP has no `ext-sodium`, while the locked `lcobucci/jwt` declares it. `composer install` therefore exits non-zero unless the extension is enabled or Composer is run with `--ignore-platform-req=ext-sodium`; the dependency install recorded for this project used that flag. Runtime sign-in redirect generation does not need sodium, but enabling it is the cleaner fix.

Required for the app to run:

```sh
APP_SECRET="<random-local-value>"
DATABASE_URL="postgresql://app:app@127.0.0.1:5433/keelson?serverVersion=16&charset=utf8"
```

`DATABASE_URL` above matches the root Compose PostgreSQL service (`127.0.0.1:5433`, database/user `keelson`/`app`). Start it from the repository root with `docker compose up -d` if you want a local database, and never reset its persistent `db_data` volume.

Required for any HTTP request (see the Logto section below): `OIDC_ISSUER`, `OIDC_WELL_KNOWN_URL`, `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`. Without them `php bin/console` commands still work, but every request through `public/index.php` fails with HTTP 500 `Environment variable not found: "OIDC_CLIENT_ID"`, because the security firewall resolves the client on each request. There is currently no offline or password-based sign-in path; the HTTP tests avoid it by using `KernelBrowser::loginUser()`.

**Sign-in redirect works as of 2026-11-13.** `symfony/translation-contracts` (`^3.8`) is now declared in `composer.json`; without it the OIDC client's PKCE challenge generation threw `LogicException: You cannot use the "Symfony\Component\String\Slugger\AsciiSlugger" ...` from `SecurityController::login()`. After the install, `GET /login` returns HTTP 302 to the authorization endpoint with `client_id`, `response_type=code`, `redirect_uri=.../login_check`, `scope=openid profile email`, `state`, `nonce`, `code_challenge` and `code_challenge_method=S256`. That was verified with placeholder credentials and a temporary local discovery document; a real provider sign-in/callback/logout still needs a Logto client and has not been run.

Apply migrations only against a database you own locally:

```sh
php bin/console doctrine:migrations:migrate
php bin/console doctrine:migrations:status
```

Serve the API (pass the front controller explicitly so PHP's built-in server does not 404 on `/api/...` paths):

```sh
php -S 127.0.0.1:8000 -t public public/index.php
# or: symfony server:start
```

Every route except `/login` and `/logout` is behind the session firewall, so requests without a session are redirected to the provider log-in rather than returning data.

## Tests

Configuration lives in `phpunit.dist.xml` (`APP_ENV=test`, bootstrap `tests/bootstrap.php`).

Database-independent checks:

```sh
composer validate --no-check-publish
php bin/console lint:yaml config --env=test
php bin/console lint:container --env=test
php bin/console debug:router --env=test
php bin/phpunit tests/Unit/Entity tests/Unit/Security tests/Unit/Service tests/Unit/Util
```

The full suite additionally contains `tests/Unit/Repository/TenantScopedRepositoryTest.php` and `tests/Integration/InvoiceApiTest.php` / `tests/Integration/InvoiceHttpApiTest.php`. Those tests assert a PostgreSQL platform, insert rows, and delete them in teardown (temporarily disabling the append-only audit trigger for their own random-suffixed rows). They must never run against a shared, production or customer database.

### Isolated test database

In the `test` environment, `config/packages/doctrine.yaml` appends `_test` (plus `TEST_TOKEN` when set) to the configured database name. A `DATABASE_URL` of `.../keelson_test_local` therefore uses the database `keelson_test_local_test`.

Use a throwaway PostgreSQL 16 container that is unrelated to the Compose `db_data` volume:

```sh
docker run --rm -d --name keelson-test-db \
  -e POSTGRES_USER=app -e POSTGRES_PASSWORD=app -e POSTGRES_DB=keelson_test_local \
  -p 127.0.0.1:55437:5432 postgres:16-alpine
```

Create the ignored `backend/.env.test.local` (never commit it):

```sh
DATABASE_URL="postgresql://app:app@127.0.0.1:55437/keelson_test_local?serverVersion=16&charset=utf8"
OIDC_ISSUER="https://issuer.example.test/oidc"
OIDC_WELL_KNOWN_URL="https://issuer.example.test/oidc/.well-known/openid-configuration"
OIDC_CLIENT_ID="test-client"
OIDC_CLIENT_SECRET="test-secret"
```

All OIDC values may be dummies for these tests. The firewall resolves the client service on every request (so the four variables must exist), while the HTTP tests sign in with `KernelBrowser::loginUser()` and never contact the provider; `OIDC_ISSUER` is also used to build the test `Principal` rows. Placeholder values were confirmed on 2026-11-13 to let an unauthenticated API request reach the firewall, which denies it with `AccessDeniedException` rather than failing on a missing environment variable.

Then create and migrate the test database and run the suite:

```sh
php bin/console doctrine:database:create --env=test --if-not-exists
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/phpunit
docker stop keelson-test-db
```

Do not substitute the Compose `keelson` database for this: Doctrine would target `keelson_test` on the persistent volume, which is not guaranteed disposable.

## Configure Logto Cloud

1. In the Logto Console, open **Applications → Create application → Third-party app → Traditional Web**. This is a confidential server-side client; keep its client secret on the server only.
2. Choose an application name and create it. In the application settings, set the sign-in redirect URI to the exact URL you will use for local development plus `/login_check` (for example, `http://localhost:8000/login_check` if you run `symfony server:start --allow-http`). Add the corresponding post-sign-out redirect URI with `/` (for example, `http://localhost:8000/`). Do not use a wildcard callback.
3. In the Logto Console, enable the `openid`, `profile`, and `email` scopes; the application requests `openid profile email`. New local principals are created only after the OIDC library verifies the ID-token signature, issuer, audience, expiry, nonce and the callback's state; the configured issuer is paired with the verified ID-token subject. Email is stored as contact/profile data only and never used as an authorization key. No organization membership or role is granted at first login.
4. Copy the tenant's **OIDC issuer** and **OpenID Provider Configuration URL** from the Logto application integration guide. Do not assume URL paths: enter the exact issuer and discovery URL Logto displays. The provider must advertise S256 PKCE support and an end-session endpoint for the corresponding features.
5. Copy `backend/.env.example` to the ignored `backend/.env.local`, and replace its four example values with the issuer, discovery URL, client ID, and client secret from Logto. Never commit `.env.local`, paste the secret into chat, put it in frontend code, or share it in logs. No production or real user data is needed for local verification.
6. Start a locally reachable Symfony server, then try `GET /login`. Confirm it redirects to Logto with `response_type=code`, the `openid profile email` scopes, state, nonce, and S256 PKCE. Complete sign-in with a test account; confirm Symfony returns to the exact `/login_check` URI, creates/loads a `Principal` using the configured issuer + the verified ID-token `sub`, and redirects to `/`. The callback is an authenticator check path and is not a separately listed route. Test a callback with missing/wrong state and confirm it does not authenticate. Submit a POST to `/logout` with the session's Symfony CSRF token; confirm local session invalidation and the provider logout redirect.

The app does not grant organization membership or a tenant role at sign-in. Every authenticated route is protected by default. Controllers must use a server-loaded organization ID with an active membership check for organization-specific actions; an ID from a request is a selector only. The voters check active membership and grant view access to all four organization roles, administration to Owner/Admin, and destructive customer/draft-invoice operations to Owner/Admin. Keep this policy in sync with the Phase 1 contract before introducing further protected workflows.

## Before deployment

The Drenso OIDC bundle declares Symfony 7 support and implements authorization code flow, state, nonce and S256 PKCE, but Logto is not named in its tested-provider list. A real sign-in/callback/sign-out against the configured Logto tenant remains an explicit provider compatibility check; as of 2026-11-13 it has not been run.

Still not implemented: PostgreSQL row-level security, invitation issuance/acceptance flows, organization lifecycle operations beyond reads, billing/subscription/usage enforcement, notification delivery, refresh token rotation, password recovery and user self-service account linking. No Logto account, client credentials, or production configuration is included in the repository.
