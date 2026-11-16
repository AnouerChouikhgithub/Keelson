# Backend

Symfony 7.4 API (PHP >=8.2) on Doctrine ORM 3 / DBAL 4 / Migrations and
PostgreSQL 16. Authentication is first-party: an email address and a password
(Symfony's password hasher, `json_login`) establishing a server-managed session.
Email verification and password reset use single-use, hashed tokens mailed to the
address; there is no external identity provider any more.

Implemented API surface: tenant-scoped customer CRUD, invoice
draft/list/read/update/send/mark-paid/delete, and a read-only invoice audit-event
list, all under `/api/organizations/{organizationId}/…`, plus
`/api/auth/{register,login,verify-email,logout,forgot-password,reset-password}`,
`/api/session`, `/api/me/organizations`, `/healthz` and `/readyz`. Tenant access is
enforced by loading the organization membership server-side; an organization ID
from the request is a selector, not authorization.

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
`db_data` volume.

Sign-in also needs these (see `backend/.env.example`):

```dotenv
MAILER_DSN="smtp://127.0.0.1:1026"     # Mailpit from docker-compose, UI on :8026
FRONTEND_URL="http://localhost:5173"   # verification/reset links point here
REQUIRE_VERIFIED_EMAIL=true            # false only where mail delivery does not exist
```

`MAILER_DSN=null://null` in `.env.test` keeps the tests off the network. With
`REQUIRE_VERIFIED_EMAIL=false` an unconfirmed address can sign in, which is only
sensible on a local checkout without Mailpit.

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

`GET /healthz` and `GET /readyz` are `PUBLIC_ACCESS` in `access_control`, so they
answer without a session and no longer need their own firewall. `GET /api/session`
is `PUBLIC_ACCESS` too, queries no database, and returns only the caller's own
identity plus the CSRF token every unsafe request must echo, always with
`Cache-Control: no-store, private`. Request/response shapes, including the six
authentication routes, are in [../docs/api-contract.md](../docs/api-contract.md).

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

Last full run (2026-11-16): **157 tests / 721 assertions, exit 0**. The suite now
covers registration, email verification, login (including throttling and the
unverified-address order), logout, password reset, CSRF, request-shape errors and
the token service, in addition to the invoice API and concurrency suites.

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

- Data is entirely synthetic: `example.test` emails, four principals, two
  organizations, all four roles, four customers and six invoices covering draft,
  sent, overdue and paid.
- It matches by natural key and never creates duplicates; it deletes, truncates and
  updates nothing.
- `FixtureTargetGuard` validates the **effective DBAL parameters**
  (`Connection::getParams()`, where Doctrine has already applied the test
  `dbname_suffix`), not the raw `DATABASE_URL`. It refuses `APP_ENV=prod`,
  non-PostgreSQL drivers, non-loopback hosts, empty names and names without a
  `test`/`dev`/`fixture` segment, and its messages never include credentials.
- Every fixture principal is created with the documented development password
  `Keelson-dev-2026` (`SyntheticFixtureCatalog::DEV_PASSWORD`) and a confirmed
  address, so the seeded account is usable locally. Sprint 4 finalises the fixture
  flow; the target guard keeps this off any real database. The frontend never reads
  fixture records directly.

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

The generated `.env.test.local` carries only `DATABASE_URL`; `MAILER_DSN` is
`null://null` from `.env.test`, and the HTTP tests either use
`KernelBrowser::loginUser()` or drive the real JSON login flow.

## Sign-in flows

All of them are JSON under `/api/auth` and none of them needs a session.

| Flow | Request | Result |
| --- | --- | --- |
| Register | `POST /api/auth/register` `{email, password, organizationName, displayName?}` | `201 {"status":"verification_sent"}`; creates principal + organization + `Owner` membership and mails a link |
| Verify | `POST /api/auth/verify-email` `{token}` | `204`, or `400 invalid_token` |
| Sign in | `POST /api/auth/login` `{email, password}` | `200` session payload, `401 invalid_credentials`, `403 email_not_verified`, `429` when throttled |
| Sign out | `POST /api/auth/logout` with `X-CSRF-Token` | `204`, session invalidated |
| Forgot | `POST /api/auth/forgot-password` `{email}` | always `202`; mails a 1 h link only if the account exists |
| Reset | `POST /api/auth/reset-password` `{token, password}` | `204`, password replaced and address marked verified |

- Passwords are 10…72 characters (bcrypt truncates past 72, so a longer password
  would silently lose entropy) and only ever stored as a hash.
- Tokens are 32 random bytes, base64url-encoded, and only their SHA-256 digest is
  stored (`auth_tokens.token_hash`, `CHAR(64)`). Verification links live 24 hours,
  reset links 1 hour, and each is single-use. Issuing a new token of the same type
  retires the previous one.
- Registration and `forgot-password` are enumeration-safe: the response is identical
  whether or not the address exists, and no mail is sent for an existing registration.
- Five failed attempts per address per minute are refused with `429` and
  `Retry-After` before the password is even checked
  (`App\EventListener\LoginThrottleListener`, `config/packages/rate_limiter.yaml`).
  The firewall's own `login_throttling` is declared too, but its listener is not
  invoked in this setup, so the explicit guard is what enforces the policy.
- Emails are plain text built in `App\Service\MailFactory` (no Twig):
  `FRONTEND_URL/verify-email?token=…` and `FRONTEND_URL/reset-password?token=…`.
  Tokens and passwords are never logged.

To try it locally, run Mailpit (`docker compose up -d`) and the API, register, then
open http://localhost:8026 and follow the link. Seeded fixtures already have a
confirmed address and the password `Keelson-dev-2026`.

## Not implemented

PostgreSQL row-level security, invitation issuance/acceptance, organization
lifecycle operations beyond reads, billing/subscription/usage enforcement,
notification delivery, two-factor authentication, account linking and the SPA's
sign-in screens (Sprint 3). Tenant isolation is application-layer scoping, not
production hardening. No credentials or production configuration live in the
repository.
