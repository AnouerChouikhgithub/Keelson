# End-to-end journeys

Browser tests for the whole stack: Symfony + PostgreSQL + Mailpit + the Vite SPA.
They exist to catch what unit and component tests cannot — a broken cookie, a
proxy the SPA cannot reach, a mail that never arrives, a role the server refuses
when the button was hidden.

Chromium only, on purpose: the app is a single-browser product today, and a
second engine would double the runtime without testing anything new.

## Prerequisites

- PHP 8.2+ and the backend dependencies installed (`cd backend && composer install`)
- Node 22+ and pnpm, with the frontend dependencies installed (`cd frontend && pnpm install --frozen-lockfile`)
- Docker Desktop (PostgreSQL 16 and Mailpit from `docker-compose.yml`)
- The Playwright browser once: `pnpm --dir e2e exec playwright install chromium`

## Run it

```bash
bash e2e/prepare-stack.sh      # docker compose up -d db mailpit + rebuild keelson_dev
pnpm --dir e2e test            # Playwright starts the API and the SPA itself
```

Playwright's `webServer` block starts both halves, so nothing has to be running
beforehand:

| Process | Command | Health check |
| --- | --- | --- |
| API | `php -S 127.0.0.1:8000 -t public public/index.php` in `backend/` | `GET /healthz` |
| SPA | `pnpm dev --host 127.0.0.1` in `frontend/` | `GET /` |

`reuseExistingServer` is on locally, so an API you already have running is
reused; in CI both are started fresh.

Useful variants:

```bash
pnpm --dir e2e test -- --headed        # watch the journeys
pnpm --dir e2e test -- tests/b-*.ts    # one file
pnpm --dir e2e report                  # open the HTML report after a CI-style run
```

## What the journeys cover

| File | Journey |
| --- | --- |
| `tests/a-signup-verify.spec.ts` | sign up → verification link from Mailpit → sign in → create a customer → sign out |
| `tests/b-invoice-lifecycle.spec.ts` | owner creates a customer and an invoice, sends it, marks it paid, sees the notification |
| `tests/c-invitation.spec.ts` | owner invites an address; a second browser context accepts it and joins the workspace |
| `tests/d-viewer-readonly.spec.ts` | a Viewer sees no write actions, and forged API calls are refused (`403` without CSRF, `404` as a viewer) |
| `tests/e-sign-in-throttling.spec.ts` | wrong password message, then the `429` throttling message |
| `tests/f-locales.spec.ts` | French and Arabic sign-in, `lang`/`dir` attributes, Arabic copy |

## Accounts

The fixture loader seeds five accounts that all share the documented demo
password (`Keelson-Demo-2026!`, or `FIXTURE_PASSWORD`):

| Address | Role |
| --- | --- |
| `owner@example.test` | Owner of Northwind |
| `admin@example.test` | Admin of Northwind |
| `member@example.test` | Member of Northwind and Contoso |
| `viewer@example.test` | Viewer of Contoso |
| `unverified@example.test` | registered, address never confirmed |

Sign-up and invitation journeys create their own addresses, so repeated runs do
not collide.

## Conventions

- **No sleeps.** Every wait is a condition: a locator becoming visible, a URL, or
  a Mailpit message arriving (`expect.poll` inside `support/mailpit.ts`).
- **Selectors are roles and labels**, not CSS classes, so a restyle does not break
  the suite. The two locale tests address inputs by `type` because the point is
  that their labels changed language.
- **Assertions about refusal use the real status codes.** Tenant denials are
  `404`, not `403`: the voters deliberately hide whether a resource exists in
  another workspace. `403` is the CSRF/origin guard. Both are asserted in journey
  (d).
- **Traces on failure.** `trace: 'retain-on-failure'` plus a screenshot, uploaded
  by CI. `retries: 1` in CI only, so a genuine local failure stays visible.

## Environment

| Variable | Default | Purpose |
| --- | --- | --- |
| `E2E_BASE_URL` | `http://localhost:5173` | SPA origin the journeys run against |
| `MAILPIT_URL` | `http://127.0.0.1:8026` | Mailpit HTTP API used to read links |
| `FIXTURE_PASSWORD` | `Keelson-Demo-2026!` | password of the seeded accounts |
| `CI` | unset | enables retries, the HTML report and fresh servers |
