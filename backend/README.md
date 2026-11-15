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
2. Choose an application name and create it. Register the exact URLs below (**Redirect URIs** and **Post sign-out redirect URIs** in the application's settings); do not use a wildcard callback and do not add a trailing slash to the callback.

   | Logto setting | Value for the verified local setup |
   | --- | --- |
   | Redirect URI | `http://127.0.0.1:5173/login_check` |
   | Post sign-out redirect URI | `http://127.0.0.1:5173/` |

   Both URLs use the SPA origin because the browser only ever talks to `http://127.0.0.1:5173`, which proxies `/api`, `/login`, `/login_check` and `/logout` to Symfony on `http://127.0.0.1:8000`. Use one host name everywhere: session cookies are scoped by host name and ignore the port, so `127.0.0.1` and `localhost` are different cookie hosts. If the console rejects the `127.0.0.1` literal, register the same two URLs under `localhost` (`http://localhost:5173/login_check` and `http://localhost:5173/`) and browse the app at `http://localhost:5173`; never mix the two host names.

   `redirect_uri` is built from the `Host` header of the current request, and the Vite proxy forwards `Host: 127.0.0.1:5173` unchanged (`changeOrigin: false`), so a sign-in started at `http://127.0.0.1:5173/login` requests exactly the callback registered above, and Vite now proxies `/login_check` to Symfony's authenticator instead of answering with the SPA's HTML. The test database must be running and migrated, because the callback provisions the `Principal` row on first sign-in.

   Sign-out is a top-level form POST to `/logout` carrying the session's `_csrf_token` (`frontend/src/session/session.ts` builds and submits the form). Symfony invalidates its session and answers with a redirect to Logto's `end_session_endpoint` (`id_token_hint` plus `post_logout_redirect_uri=http://127.0.0.1:5173/`); because the browser performs a real navigation, it follows that redirect and Logto ends its SSO session. This deliberately is not a `fetch()`: with `redirect: 'manual'` the browser never navigates, and a `fetch()` cannot follow the cross-origin, credentialed redirect either, so the provider session would survive and the next sign-in would silently succeed without prompting.
3. In the Logto Console, enable the `openid`, `profile`, and `email` scopes; the application requests `openid profile email`. New local principals are created only after the OIDC library verifies the ID-token signature, issuer, audience, expiry, nonce and the callback's state; the configured issuer is paired with the verified ID-token subject. Email is stored as contact/profile data only and never used as an authorization key. No organization membership or role is granted at first login.
4. Copy the tenant's **OIDC issuer** and **OpenID Provider Configuration URL** from the application's **Endpoints & Credentials** panel, or read them from Settings → Domains. For a Logto Cloud tenant they have the fixed shape below (`<tenant>` is the tenant endpoint Logto shows, e.g. `abc123.logto.app`); do not assume a different path, and use the values the console displays.

   | Value | Shape | Source |
   | --- | --- | --- |
   | Issuer | `https://<tenant>/oidc` | application endpoints, or the discovery document's `issuer` |
   | Discovery URL | `https://<tenant>/oidc/.well-known/openid-configuration` | application endpoints |

   The discovery document must advertise `code_challenge_methods_supported` containing `S256` and an `end_session_endpoint`; the bundle refuses `/login` with a PKCE exception when `S256` is absent, and logout cannot redirect to the provider without the end-session endpoint. Confirm both before starting a browser run.

5. Create the ignored private config at `backend/.env.local` (`cp backend/.env.example backend/.env.local`) and fill in exactly these names. Symfony loads `.env.local` in every environment; do not use `.env.test.local` for a browser run, because the test helper owns and deletes that file.

   | Variable | Value |
   | --- | --- |
   | `APP_SECRET` | any random local value |
   | `DATABASE_URL` | the local PostgreSQL URL, e.g. `postgresql://app:app@127.0.0.1:5433/keelson?serverVersion=16&charset=utf8` |
   | `OIDC_ISSUER` | `https://<tenant>/oidc` |
   | `OIDC_WELL_KNOWN_URL` | `https://<tenant>/oidc/.well-known/openid-configuration` |
   | `OIDC_CLIENT_ID` | the application's **Application ID** |
   | `OIDC_CLIENT_SECRET` | the application's **App Secret** (confidential Traditional web apps only) |

   `backend/.env.local` is ignored by Git and is the only place these values belong. Never commit it, paste the secret into chat or an issue, put it in frontend code, or let it appear in logs. No production or real user data is needed; use a dedicated test tenant and a test user account.
6. Run both servers (`php -S 127.0.0.1:8000 -t public public/index.php` from `backend/`, and `pnpm dev --host 127.0.0.1` from `frontend/`), then open `http://127.0.0.1:5173` and use the **Sign in** link, which navigates to the proxied `/login`. Confirm the redirect to Logto carries `response_type=code`, the `openid profile email` scopes, state, nonce, S256 PKCE, and `redirect_uri` exactly `http://127.0.0.1:5173/login_check`. Complete sign-in with a test account; confirm the browser returns to that exact callback and that it is answered by Symfony through the proxy rather than by the SPA's HTML fallback, that a `Principal` is created/loaded from the configured issuer + the verified ID-token `sub`, and that the redirect to `/` lands back on the shell, where `/api/session` reports the authenticated principal. Also test a callback with a missing or wrong `state` and confirm it does not authenticate.

   Then sign out from the account menu or Settings. The browser must navigate to Logto's `end_session_endpoint` (the address bar leaves the app), return to `http://127.0.0.1:5173/`, show the signed-out screen, report `{"authenticated":false,...}` from `/api/session`, and — the part that proves the provider session ended — make a fresh `GET /login` prompt for credentials instead of signing straight back in.

The app does not grant organization membership or a tenant role at sign-in. Every authenticated route is protected by default. Controllers must use a server-loaded organization ID with an active membership check for organization-specific actions; an ID from a request is a selector only. The voters check active membership and grant view access to all four organization roles, administration to Owner/Admin, and destructive customer/draft-invoice operations to Owner/Admin. Keep this policy in sync with the Phase 1 contract before introducing further protected workflows.

## Before deployment

The Drenso OIDC bundle declares Symfony 7 support and implements authorization code flow, state, nonce and S256 PKCE, but Logto is not named in its tested-provider list. A real sign-in/callback/sign-out against the configured Logto tenant remains an explicit provider compatibility check. As of 2026-11-14 it has still not been run: no test tenant or client credentials are configured on this checkout (`backend/.env.local` and the OIDC variables are absent). The two blockers found on that date are now fixed in source — Vite proxies `/login_check`, and sign-out is a top-level form POST that lets the browser follow the provider's end-session redirect — but neither has been exercised against a real provider yet.

CI and dependency audit: `.github/workflows/ci.yml` runs Composer validation, YAML/container/route lint, migrations and this PHPUnit suite against a `postgres:16-alpine` service using dummy OIDC values, plus report-only `composer audit`/`pnpm audit`. The workflow was YAML-validated locally but has not run on GitHub-hosted runners. `composer audit` on 2026-11-14 reported 13 advisories across 9 packages; none were remediated because dependency changes need approval.

Still not implemented: PostgreSQL row-level security, invitation issuance/acceptance flows, organization lifecycle operations beyond reads, billing/subscription/usage enforcement, notification delivery, refresh token rotation, password recovery and user self-service account linking. No Logto account, client credentials, or production configuration is included in the repository.
