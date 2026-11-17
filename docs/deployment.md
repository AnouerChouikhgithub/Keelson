# Deployment

Two artifacts, one origin:

- the **API** — `backend/Dockerfile`, php-fpm on port 9000, non-root, opcache on;
- the **SPA** — `frontend/Dockerfile`, a static build served by nginx on port 80,
  which also forwards `/api`, `/healthz` and `/readyz` to php-fpm over FastCGI.

`docker-compose.prod.example.yml` wires the whole stack (PostgreSQL, a migrate job,
the API, the frontend) with every secret read from the environment. Copy it to
`docker-compose.prod.yml`, write a `.env` next to it, and use it as-is or as the
reference for your own orchestrator.

## 1. Build

```sh
docker build -t keelson-api ./backend
docker build -t keelson-web ./frontend
```

Both are multi-stage builds: the backend image carries only the production
autoloader, a warmed `prod` cache and the `pdo_pgsql`/`intl`/`opcache` extensions;
the frontend image carries only `dist/` and the nginx configuration. Expected
sizes: ~180 MB for the API, ~25 MB for the web image.

The SPA talks to its own origin by default. Only set a build argument when the API
lives elsewhere:

```sh
docker build -t keelson-web --build-arg VITE_API_BASE_URL=https://api.example.com ./frontend
```

## 2. Environment

Nothing is defaulted that should not be. The compose example uses `${VAR:?message}`
for every secret, so a missing value stops the deployment instead of shipping a
known one.

| Variable | Example | Notes |
| --- | --- | --- |
| `APP_ENV` | `prod` | anything else turns on debug behaviour |
| `APP_DEBUG` | `0` | must be `0` in production; the validator refuses otherwise |
| `APP_SECRET` | 32+ random bytes | per environment, never reused; rotating it signs everyone out |
| `DATABASE_URL` | `postgresql://keelson_app:***@db:5432/keelson?serverVersion=16&charset=utf8` | the **runtime** role |
| `DEFAULT_URI` | `https://app.example.com` | absolute links from CLI commands |
| `FRONTEND_URL` | `https://app.example.com` | links in verification/reset mail; must be `https://` in production |
| `MAILER_DSN` | `smtp://user:pass@smtp.example.com:587` | `null://` is refused in production |
| `REQUIRE_VERIFIED_EMAIL` | `true` | leave it on |
| `TRUSTED_HOSTS` | `app.example.com` | mandatory in production |
| `TRUSTED_PROXIES` | `10.0.0.0/8` | only behind a proxy; pair with `TRUSTED_HEADERS` |
| `TRUSTED_HEADERS` | `x-forwarded-for,x-forwarded-proto` | required for `Secure` cookies and HSTS behind TLS |
| `LOG_LEVEL` | `warning` | temporary override while investigating |

Check the configuration before serving traffic:

```sh
docker run --rm --env-file .env keelson-api php bin/console app:config:check
```

It prints findings, never values, and exits non-zero on anything fatal (a missing
`APP_SECRET`, a `null` mailer in production, an `http://` frontend URL, debug
enabled, no trusted hosts). It is the same validator that runs during boot, so a
container that passes this will not refuse to start.

## 3. Migrate

Two roles, on purpose:

- `keelson_owner` owns the schema and runs migrations;
- `keelson_app` is what the API runs as: `SELECT, INSERT, UPDATE, DELETE` on rows,
  no DDL. The init script in `docker/postgres/10-app-role.sh` creates it and sets
  default privileges so tables created later by the owner are granted to it.

```sh
# as the owner, from the migrate job or a one-off container
DATABASE_URL=postgresql://keelson_owner:***@db:5432/keelson \
  php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
```

Then confirm both roles behave:

```sh
php bin/console app:db:target          # database@host, no credentials
php bin/console doctrine:migrations:status
```

One migration creates the `pg_trgm` extension, so the owner role needs the right to
`CREATE EXTENSION` (the bootstrap superuser in the official image has it). If your
provider forbids that, enable `pg_trgm` out of band and mark the migration as
migrated with `--no-interaction` after checking it ran the indexes.

## 4. Serve

```sh
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml ps        # migrate must be "exited (0)"
```

The frontend listens on `${HTTP_PORT:-8080}`. Terminate TLS in front of it (a load
balancer, Caddy, Traefik or another nginx) and pass `X-Forwarded-Proto`; then set
`TRUSTED_PROXIES` and `TRUSTED_HEADERS` so Symfony honours it.

### Response headers

The API sets these itself (`App\EventListener\SecurityHeadersListener`) on every
`/api` response; do not duplicate or override them:

| Header | API value |
| --- | --- |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=()` |
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` — **only when the request is HTTPS**, which is why trusted proxies matter |
| `Cache-Control` | `no-store, private` on authenticated responses |

The SPA's nginx block sets the document policy
(`default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; …`),
`X-Frame-Options: DENY` and the same referrer/permissions headers. `unsafe-inline`
for styles is required by inline `style` attributes in the dashboard; there is no
nonce for attributes. HSTS is deliberately **not** set by the container: it speaks
HTTP, and only the TLS terminator knows the request arrived over HTTPS. Add it
there:

```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

## 5. Back up and restore

Back up the database, not the volume. A logical dump is portable across PostgreSQL
versions and is what a restore is tested against.

```sh
# nightly, keep 14 days
docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" db \
  pg_dump --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --format=custom \
  > "/backups/keelson-$(date +%F).dump"
find /backups -name 'keelson-*.dump' -mtime +14 -delete
```

Restore into a fresh database, then point the API at it:

```sh
docker exec -i -e PGPASSWORD="$POSTGRES_PASSWORD" db \
  pg_restore --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --clean --if-exists --no-owner \
  < /backups/keelson-2026-11-17.dump
```

`--no-owner` matters: the restore may run as a different role than the dump did, and
ownership is not what makes the application work — grants are. Re-run the migration
command afterwards: it is idempotent and re-applies anything the dump predates.

Sessions live in the same database, so a restore signs everyone out. That is the
safe direction.

## 6. Roll back

1. **Application only** (bad deploy, schema unchanged):
   ```sh
   docker compose -f docker-compose.prod.yml up -d --no-deps --build backend frontend
   ```
   with the previous image tags, or `docker tag` the last good pair back.
2. **Schema changed too**: restore the dump taken before the deploy, then start the
   previous image. Migrations in this repository are forward-only; a `down()` that
   drops data is not a rollback plan.
3. Keep `APP_SECRET` stable across a rollback: changing it invalidates every
   session and every CSRF token in flight.

## 7. Scheduled work

`app:invoices:mark-overdue` records sent invoices past their due date as overdue,
with an audit event and notifications for the organization's Owners and Admins. It
is idempotent and batched (200 per transaction), so running it twice does nothing
the second time.

**Linux (cron, 02:15 nightly):**

```cron
15 2 * * * cd /srv/keelson && docker compose -f docker-compose.prod.yml exec -T backend \
  php bin/console app:invoices:mark-overdue --env=prod >> /var/log/keelson-overdue.log 2>&1
```

**Windows (Task Scheduler):**

```powershell
$a = New-ScheduledTaskAction -Execute "php" `
     -Argument "bin\console app:invoices:mark-overdue --env=prod" `
     -WorkingDirectory "C:\path\to\keelson\backend"
$t = New-ScheduledTaskTrigger -Daily -At 2:15am
Register-ScheduledTask -TaskName "Keelson mark overdue" -Action $a -Trigger $t `
     -Description "Marks past-due invoices overdue"
```

## 8. Health and readiness

| Endpoint | Meaning |
| --- | --- |
| `GET /healthz` | the kernel booted; no dependencies touched. Used by the container healthcheck |
| `GET /readyz` | the database answers; returns `503` when it does not. Point your load balancer here |

Both are public and exempt from CSRF (they are `GET`). They return JSON.

## 9. What is not covered

- No TLS termination in the images: put a proxy in front.
- No log shipping, metrics or tracing exporter (see [limitations.md](limitations.md)).
- No zero-downtime migration strategy: a migration that rewrites a large table
  locks it. Run such migrations in a window, or split them into expand/contract
  steps.
