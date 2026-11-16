# Backend

Symfony 7.4 API (PHP >=8.2) on Doctrine ORM 3 / DBAL 4 / Migrations and
PostgreSQL 16. Authentication is a server-managed session established by an OpenID
Connect provider (Logto Cloud in the documented setup); local principals are
provisioned after the OIDC library verifies the ID token. [../docs/ROADMAP.md](../docs/ROADMAP.md)
schedules its replacement with first-party authentication.

Implemented API surface: tenant-scoped customer CRUD, invoice
draft/list/read/update/send/mark-paid/delete, and a read-only invoice audit-event
list, all under `/api/organizations/{organizationId}/…`, plus `/login`, `/logout`,
`/healthz`, `/api/session` and `/api/me/organizations`. Tenant access is enforced
by loading the organization membership server-side; an organization ID from the
request is a selector, not authorization.

## Local setup

```sh
cd backend
composer install
```

Local values belong in `backend/.env.local`, which is ignored by Git. Never commit
secrets or reuse production or customer credentials. The tracked `backend/.env`
deliberately leaves `APP_SECRET` empty: any value committed to a public repository
is public, so supply a random one in `.env.local`.

`ext-sodium` (declared by the locked `lcobucci/jwt`) is enabled on this host
through a machine-local CLI ini outside the repository, so a fresh machine must
enable it before `composer install`. `--ignore-platform-req=ext-sodium` hides the
missing extension and is not a passing platform check.

Required to run:

```dotenv
APP_SECRET="<random-local-value>"
DATABASE_URL="postgresql://app:app@127.0.0.1:5433/keelson?serverVersion=16&charset=utf8"
```

`DATABASE_URL` matches the root Compose PostgreSQL service; start it from the
repository root with `docker compose up -d` and never reset its persistent
`db_data` volume. Required for any HTTP request: `OIDC_ISSUER`,
`OIDC_WELL_KNOWN_URL`, `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`. Without them
console commands still work, but every request through the front controller fails
with `Environment variable not found: "OIDC_CLIENT_ID"` because the security
firewall resolves the client per request. There is no offline or password sign-in;
the HTTP tests use `KernelBrowser::loginUser()`.

Migrate only against a database you own locally:

```sh
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:migrate

# rollback, verified on a disposable database only
php bin/console doctrine:migrations:migrate --env=test first --no-interaction
php bin/console doctrine:migrations:migrate --env=test --no-interaction
```

Serve the API, passing the front controller explicitly so PHP's built-in server
does not 404 on `/api/...`:

```sh
symfony serve --no-tls --port=8000
php -S 127.0.0.1:8000 -t public public/index.php
```

`GET /healthz` is a public, database-free probe with its own stateless
`security: false` firewall (ahead of `main`), which is why it answers before OIDC
is configured. `GET /api/session` is `PUBLIC_ACCESS`, queries no database, and
returns only the caller's own identity plus the `logout` CSRF token, always with
`Cache-Control: no-store, private`. `debug:router --env=test` lists 20 routes.
Request/response shapes are in [../docs/api-contract.md](../docs/api-contract.md).

## Tests

Configuration lives in `phpunit.dist.xml` (`APP_ENV=test`, bootstrap
`tests/bootstrap.php`).

```sh
composer validate --no-check-publish
php bin/console lint:yaml config --env=test
php bin/console lint:container --env=test
php bin/console debug:router --env=test
./vendor/bin/phpstan analyse      # level 5, phpstan-baseline.neon
composer audit
php bin/phpunit tests/Unit/Entity tests/Unit/Security tests/Unit/Service tests/Unit/Util tests/Unit/EventListener
```

The last command needs no database. The full suite adds
`tests/Unit/Repository/TenantScopedRepositoryTest.php` and `tests/Integration/*`
(invoice API and HTTP contract, draft-edit and customer-delete lock races,
invoice-number allocation, overdue derivation, audit atomicity, the authorization
matrix, logout, and the fixture loader). Those assert a PostgreSQL platform,
insert rows and delete them in teardown, so they must never run against a shared,
production or customer database.

Last full run (2026-11-16): **115 tests / 585 assertions, 4 failures**. The four
failures pre-date Sprint 1: anonymous `/api/...` requests answer `401` JSON from
`ApiAuthenticationEntryPoint` while those tests still expect a redirect to
`/login`. They are listed in [../README.md](../README.md#checks).

Known limitation: `php bin/console doctrine:schema:validate --env=test` exits
non-zero with `An index with name "idx_6a2f2f95a5a280d" was already defined on
table "invoices"`. DBAL 4.5 synthesizes that identifier for `fk_invoices_customer`
and `migrations/Version20261023170000.php` declares the same name, so PostgreSQL
introspection adds it twice. `doctrine:schema:validate --skip-sync` passes. Do not
run `doctrine:schema:update` or `migrations:diff` until the index is renamed.

## Synthetic fixtures

`src/DataFixtures/` holds the only fixture source. Seed explicitly — nothing runs
on boot, migration or test:

```sh
php bin/console app:fixtures:load --env=test --force
```

- Data is entirely synthetic: fixture-only issuer `https://fixtures.example.test/oidc`,
  `example.test` emails, four principals, two organizations, all four roles, four
  customers and six invoices covering draft, sent, overdue and paid.
- It matches by natural key and never creates duplicates; it deletes, truncates and
  updates nothing.
- `FixtureTargetGuard` validates the **effective DBAL parameters**
  (`Connection::getParams()`, where Doctrine has already applied the test
  `dbname_suffix`), not the raw `DATABASE_URL`. It refuses `APP_ENV=prod`,
  non-PostgreSQL drivers, non-loopback hosts, empty names and names without a
  `test`/`dev`/`fixture` segment, and its messages never include credentials.
- Fixture principals have **no login credentials**; automated tests use
  `KernelBrowser::loginUser()`. The frontend never reads fixture records directly.

### Isolated test database

The `test` environment appends `_test` (plus `TEST_TOKEN` when set) to the
configured database name. Use the helper, which starts a throwaway container
unrelated to the Compose volume:

```sh
bash bin/test-db.sh start     # tmpfs container, ephemeral loopback port, migrate
php bin/phpunit
bash bin/test-db.sh stop      # remove the container and .env.test.local
bash bin/test-db.sh status
```

- `postgres:16-alpine` with **tmpfs** data and an ephemeral loopback port
  (`-p 127.0.0.1::5432`) read back with `docker port`; fixed ports can fall inside
  reserved Windows ranges.
- The container is labelled per checkout. The helper refuses to reuse, stop or
  remove anything it did not create, and refuses to overwrite an existing
  `.env.test.local` — including a file left by a previous helper run, which
  `bin/test-db.sh stop` cleans up.
- A failed start or migration removes only what that run created. All data
  disappears with the container.

The generated `.env.test.local` carries `DATABASE_URL` plus dummy
`OIDC_ISSUER`/`OIDC_WELL_KNOWN_URL`/`OIDC_CLIENT_ID`/`OIDC_CLIENT_SECRET`. The
firewall resolves the client on every request, so the four names must exist; the
HTTP tests sign in with `loginUser()` and never contact a provider.

## Configure Logto Cloud

1. **Applications → Create application → Third-party app → Traditional Web**: a
   confidential server-side client. Keep the secret on the server.
2. Register, with no wildcard and no trailing slash on the callback:

   | Logto setting | Value |
   | --- | --- |
   | Redirect URI | `http://127.0.0.1:5173/login_check` |
   | Post sign-out redirect URI | `http://127.0.0.1:5173/` |

   Both use the SPA origin because the browser only talks to the Vite proxy. Use
   one host name everywhere — cookies ignore the port, so `127.0.0.1` and
   `localhost` are different hosts. If the console rejects the literal, register
   the same two URLs under `localhost` and browse there; never mix the two.

3. Enable the `openid`, `profile` and `email` scopes. A principal is created only
   after the library verifies the ID-token signature, issuer, audience, expiry,
   nonce and callback state. Email is profile data, never an authorization key, and
   no membership or role is granted at first sign-in.
4. Copy the **issuer** (`https://<tenant>/oidc`) and the **discovery URL**
   (`https://<tenant>/oidc/.well-known/openid-configuration`). The document must
   advertise `code_challenge_methods_supported` containing `S256` and an
   `end_session_endpoint`, or `/login` and logout cannot redirect.
5. Put `APP_SECRET`, `DATABASE_URL` and the four `OIDC_*` values in
   `backend/.env.local`. Never commit it, paste the secret anywhere, or use
   `.env.test.local` for a browser run — the test helper owns and deletes it.
6. Run both servers and sign in through the proxy. Sign-out is a CSRF-protected
   top-level form POST to `/logout`; Symfony invalidates the session and redirects
   to the provider's `end_session_endpoint` (`id_token_hint`,
   `post_logout_redirect_uri`). `App\EventListener\OidcEndSessionLogoutListener`
   (priority `-10`, after session invalidation) performs that redirect through
   `App\Service\ProviderEndSessionRedirector`, and the bundle's own listener is
   disabled so a discovery outage degrades to the local redirect instead of
   HTTP 500. It is deliberately not a `fetch()`: the browser must navigate for the
   provider session to end.

Real Logto sign-in/callback/sign-out has **not** been run on this checkout (no
test tenant configured). Only the `/login` authorization redirect is verified.

## Not implemented

PostgreSQL row-level security, invitation issuance/acceptance, organization
lifecycle operations beyond reads, billing/subscription/usage enforcement,
notification delivery, refresh-token rotation, password recovery and account
linking. Tenant isolation is application-layer scoping, not production hardening.
No provider credentials or production configuration live in the repository.
