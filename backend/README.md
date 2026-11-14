# Backend setup

Symfony 7.4 API (PHP >=8.2) with Doctrine ORM 3 / DBAL 4 / Migrations and the Drenso Symfony OIDC bundle. Authentication is a server-managed session established by an OpenID Connect provider (Logto Cloud in the documented setup); local principals are provisioned after the OIDC library verifies the ID token.

Implemented API surface: tenant-scoped customer CRUD, invoice draft/list/read/update/send/mark-paid/delete, and a read-only invoice audit-event list, all under `/api/organizations/{organizationId}/...`. Tenant access is enforced by loading the organization membership server-side; an organization ID from the request is a selector, not authorization.

## Local setup

```sh
cd backend
composer install
```

Local values belong in `backend/.env.local`, which is ignored by Git. Do not commit secrets, and do not reuse production or customer credentials.

`ext-sodium` (declared by the locked `lcobucci/jwt`) is enabled on this host since 2026-11-13 through the machine-local CLI ini `C:\Users\Anouer PC\scoop\apps\php\current\cli\php-phase7.ini` (`extension=php_sodium.dll`, libsodium 1.0.22). `php -m` lists `sodium`, and `composer install` plus `composer check-platform-reqs` both exit 0 without flags. That ini is host configuration outside the repository, so a fresh machine must enable the extension first; `--ignore-platform-req=ext-sodium` remains a temporary workaround, not a passing platform check.

Required for the app to run:

```sh
APP_SECRET="<random-local-value>"
DATABASE_URL="postgresql://app:app@127.0.0.1:5433/keelson?serverVersion=16&charset=utf8"
```

`DATABASE_URL` above matches the root Compose PostgreSQL service (`127.0.0.1:5433`, database/user `keelson`/`app`). Start it from the repository root with `docker compose -p keelson up -d` if you want a local database, and never reset its persistent `keelson_db_data` volume.

Required for any HTTP request (see the Logto section below): `OIDC_ISSUER`, `OIDC_WELL_KNOWN_URL`, `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`. Without them `php bin/console` commands still work, but every request through `public/index.php` fails with HTTP 500 `Environment variable not found: "OIDC_CLIENT_ID"`, because the security firewall resolves the client on each request. There is currently no offline or password-based sign-in path; the HTTP tests avoid it by using `KernelBrowser::loginUser()`.

**Sign-in redirect works as of 2026-11-13.** `symfony/translation-contracts` (`^3.8`) is now declared in `composer.json`; without it the OIDC client's PKCE challenge generation threw `LogicException: You cannot use the "Symfony\Component\String\Slugger\AsciiSlugger" ...` from `SecurityController::login()`. After the install, `GET /login` returns HTTP 302 to the authorization endpoint with `client_id`, `response_type=code`, `redirect_uri=.../login_check`, `scope=openid profile email`, `state`, `nonce`, `code_challenge` and `code_challenge_method=S256`. That was verified with placeholder credentials and a temporary local discovery document; a real provider sign-in/callback/logout still needs a Logto client and has not been run.

Apply migrations only against a database you own locally:

```sh
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:migrate
```

Rollback is the reverse of the same migration set. Verify it on a disposable database (the `bin/test-db.sh` container, never the Compose volume):

```sh
php bin/console doctrine:migrations:migrate --env=test first --no-interaction
php bin/console doctrine:migrations:migrate --env=test --no-interaction
```

Verified 2026-11-14: down applied both migrations (18 SQL queries, leaving only `doctrine_migration_versions`) and up re-applied them (44 queries), exit 0. For a real environment, back up first and stop writes; never "roll back" by deleting a volume.

Serve the API (pass the front controller explicitly so PHP's built-in server does not 404 on `/api/...` paths):

```sh
symfony serve --no-tls --port=8000
# or, with PHP's built-in server:
php -S 127.0.0.1:8000 -t public public/index.php
```

`GET /healthz` is a public, database-free liveness probe. It has its own stateless `security: false` firewall (before `main` in `config/packages/security.yaml`) precisely because the `main` firewall resolves the OIDC client on every request; without that split the probe failed with `Environment variable not found: "OIDC_CLIENT_ID"`. Verified 2026-11-14 with `symfony serve`: HTTP 200 `{"status":"ok","service":"keelson-api"}` and `Cache-Control: no-store`.

Every route except `/login`, `/logout`, `/healthz` and `/api/session` is behind the session firewall, so requests without a session are redirected to the provider log-in rather than returning data.

`GET /api/session` is the shell's session probe. It is `PUBLIC_ACCESS` so the browser shell can render the signed-out state without an OIDC round trip, performs no database query, and returns only the caller's own identity: `{"authenticated":false,"user":null,"logout":null}` when anonymous, or the principal plus the CSRF token required by `POST /logout` (`csrf_token_id` `logout`, Symfony's default) when signed in. Responses are `Cache-Control: no-store, private`. Verified 2026-11-14 against a locally served kernel with placeholder OIDC values: HTTP 200 and the anonymous payload; an anonymous `POST /logout` redirects to `/login`.

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

The full suite additionally contains `tests/Unit/Repository/TenantScopedRepositoryTest.php`, `tests/Integration/InvoiceApiTest.php`, `tests/Integration/InvoiceHttpApiTest.php`, `tests/Integration/InvoiceDraftConcurrencyTest.php` and `tests/Integration/SyntheticFixtureLoaderTest.php`. Those tests assert a PostgreSQL platform, insert rows, and delete them in teardown (temporarily disabling the append-only audit trigger for their own random-suffixed rows). They must never run against a shared, production or customer database.

Re-verified 2026-11-14 against a fresh throwaway `postgres:16-alpine` container started by `bin/test-db.sh`: `php bin/phpunit` — PASS (**52 tests, 229 assertions**), exit 0, including the CSRF-protected logout/session invalidation test. The Compose database was not used; the helper-owned volume-free container and generated `.env.test.local` were removed after the run. That run includes the draft edit/delete race regression tests (`InvoiceDraftConcurrencyTest` holds a real `FOR UPDATE` lock on a second connection and asserts the request blocks), two transaction-ownership regressions (a rejected edit must not roll back, and an idempotent send must not commit, a transaction the handler did not open) and the fixture loader tests.

## Synthetic fixtures

`backend/src/DataFixtures/` holds the only fixture source: `SyntheticFixtureCatalog` (data definitions), `SyntheticFixtureLoader` (idempotent seeding plus target guard) and the `app:fixtures:load` entry point in `backend/src/Command/`.

```sh
php bin/console app:fixtures:load --env=test --force
```

- Data is entirely synthetic: fixture-only issuer `https://fixtures.example.test/oidc`, `example.test` emails, four principals, two organizations, Owner/Admin/Member/Viewer memberships, one principal in both organizations, four customers and six invoices covering draft, sent, overdue and paid.
- The loader matches records by natural key (issuer+subject, subdomain, organization+principal, organization+name, organization+number). Re-running creates nothing new and deletes, truncates or updates nothing.
- It refuses `APP_ENV=prod`, non-PostgreSQL URLs, non-loopback hosts, and database names without `test`, `dev` or `fixture`. Seeding the Compose `keelson` database therefore fails unless the owner explicitly changes that target policy.
- Without `--force` it asks for confirmation and aborts in non-interactive shells. Nothing seeds automatically on boot, migration or test run.
- Fixture principals have **no login credentials**. A manual OIDC sign-in only maps to one if the configured test provider returns the same issuer and subject; automated tests use `KernelBrowser::loginUser()`.
- The frontend does not read these records — it is still mock-data-only with no API client.

### Isolated test database

In the `test` environment, `config/packages/doctrine.yaml` appends `_test` (plus `TEST_TOKEN` when set) to the configured database name. A `DATABASE_URL` of `.../keelson_test_local` therefore uses the database `keelson_test_local_test`.

Use the helper, which starts a throwaway container unrelated to the Compose `db_data` volume, wires the ignored `backend/.env.test.local`, creates the test database and runs migrations:

```sh
bin/test-db.sh start     # start or reuse the container; write .env.test.local; migrate
php bin/phpunit
bin/test-db.sh stop      # remove the container and the generated env file
bin/test-db.sh status    # show the container and its current port
```

- `start` runs `postgres:16-alpine` with **tmpfs** data (no volume at all) and publishes an ephemeral loopback port (`-p 127.0.0.1::5432`), then reads the real port back with `docker port`, so no fixed host port has to be guessed. Fixed ports can fall inside reserved Windows ranges (`bind: An attempt was made to access a socket in a way forbidden by its access permissions`).
- The container is labelled with `keelson.test-db=1` and a repository-specific id. The helper refuses to reuse, stop or remove any container lacking those labels, and fails on a name collision rather than deleting an unknown container.
- It writes `DATABASE_URL` plus the dummy OIDC values below only when `.env.test.local` is absent. `start` refuses any pre-existing file, including a previous helper-generated file, rather than overwriting it. `stop` deletes the marker file only after removing the matching helper-owned container.
- A failed start or migration removes only the container created in that run and deletes only the marker file it generated; it exits non-zero with the underlying console error.
- The container name, image and credentials can be overridden with `KEELSON_TEST_DB_*` environment variables. All data disappears with the container, and `stop` cleans up only helper-owned resources.

The generated `.env.test.local` contains:

```sh
DATABASE_URL="postgresql://keelson:keelson@127.0.0.1:<assigned-port>/keelson_test?serverVersion=16&charset=utf8"
OIDC_ISSUER="https://issuer.example.test/oidc"
OIDC_WELL_KNOWN_URL="https://issuer.example.test/oidc/.well-known/openid-configuration"
OIDC_CLIENT_ID="test-client"
OIDC_CLIENT_SECRET="test-secret"
```

All OIDC values may be dummies for these tests. The firewall resolves the client service on every request (so the four variables must exist), while the HTTP tests sign in with `KernelBrowser::loginUser()` and never contact the provider; `OIDC_ISSUER` is also used to build the test `Principal` rows. Placeholder values were confirmed on 2026-11-13 to let an unauthenticated API request reach the firewall, which denies it with `AccessDeniedException` rather than failing on a missing environment variable.

Do not substitute the Compose `keelson` database for this: Doctrine would target `keelson_test` on the persistent volume, which is not guaranteed disposable. Never touch the Compose `db_data` volume.

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

CI and dependency audit: `.github/workflows/ci.yml` runs Composer validation, YAML/container/route lint, migrations and this PHPUnit suite against a `postgres:16-alpine` service using dummy OIDC values, plus report-only `composer audit`/`pnpm audit`. The workflow was YAML-validated locally but has not run on GitHub-hosted runners. `composer audit` on 2026-11-14 reported 13 advisories across 9 packages; none were remediated because dependency changes need approval.

Still not implemented: PostgreSQL row-level security, invitation issuance/acceptance flows, organization lifecycle operations beyond reads, billing/subscription/usage enforcement, notification delivery, refresh token rotation, password recovery and user self-service account linking. No Logto account, client credentials, or production configuration is included in the repository.
