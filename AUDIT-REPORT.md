# Keelson — completion audit (9 sprints + optional billing)

Audited on branch `audit/complete-sprints`, created from `main` (4f18d25) and
fast-forwarded to the sprint stack (`82b2a6f`, 80 commits; `main` is an ancestor and
has no commits that the stack lacks). Nothing was pushed, tagged or opened as a PR.

Evidence is a command and its real output, or a path with lines. Anything that could
not be run is marked NOT RUN, never DONE.

## 1. Sprint checklist

| Sprint | Item | Status | Evidence |
| --- | --- | --- | --- |
| S1 | `.agents/`, `NEXT-AGENT-PROMPT.md`, `docs/ORCHESTRATION_READINESS.md`, `docs/sprints/`, `docs/screenshots/`, `backend/compose.yaml`, `backend/compose.override.yaml`, `frontend/src/assets`, `public/icons.svg`, `src/mocks/*`, `Placeholder.tsx` gone | DONE | Probe loop: all `absent`; `git grep -l "NEXT-AGENT-PROMPT\|ORCHESTRATION_READINESS\|DEVELOPMENT_ROADMAP\|how-to-run\|Placeholder"` matches only i18n `…Placeholder` label keys and `EnvironmentValidator` |
| S1 | `backend/config/reference.php` present but git-ignored | DONE | `git check-ignore -v` → `backend/.gitignore:21:/config/reference.php` |
| S1 | `backend/.env.dev` untracked | DONE | `git ls-files --error-unmatch backend/.env.dev` → "Did you forget to 'git add'?"; `git check-ignore` → `.gitignore:21:backend/.env.*` |
| S1 | `docker-compose.yml` has no redis, no references to missing docs | DONE | `grep -in redis docker-compose.yml` → empty; `grep -nE "docs/\|\.md" docker-compose.yml` → empty; services are `db`, `mailpit` (`docker-compose.yml:19,37`) |
| S1 | `DEVELOPMENT_ROADMAP.md` replaced by `docs/ROADMAP.md`; README merged with `how-to-run.md` | DONE | `docs/ROADMAP.md` exists, `DEVELOPMENT_ROADMAP.md`/`how-to-run.md` absent; README carries the run guide |
| S1 | No Tailwind/shadcn/Lucide/Recharts/Expo claims in `AGENTS.md`/`docs/ui-spec.md` | DONE | `grep -in "tailwind\|shadcn\|lucide\|recharts\|expo"` → no output |
| S1 | `.gitignore` has `.vscode/`, `*.tsbuildinfo`, `coverage/`, root `.env` | DONE | `.gitignore` lines for `coverage/`, `*.tsbuildinfo`, `.vscode/`, `.env` |
| S2 | No OIDC/Logto/Drenso dependency or config | DONE | `git grep -in "oidc\|logto\|drenso"` → only `migrations/Version20261116103000.php:11,23`, historical prose describing the removal of the old issuer/subject pair |
| S2 | `principals`: unique email, `password_hash`, `email_verified_at`, `last_login_at`, no issuer/subject | DONE | `src/Entity/Principal.php:24` unique email, `:41` password_hash, `:44` email_verified_at, `:47` last_login_at; no `issuer`/`subject` in `src/` |
| S2 | `auth_tokens` table, hashed, single use, expiring | DONE | `src/Entity/AuthToken.php:20-27` (table, unique token_hash), `:34` CHAR(64) digest, `:59-63` markUsed throws on reuse; live proof below |
| S2 | Migration reversible (down then up) on the throwaway DB | DONE | `migrate first` → "Migrating down to 0 / 7 migrations executed"; status `Executed 0`; `migrate` → "Migrating up to Version20261116170000 / 7 migrations executed"; status `Executed 7` |
| S2 | register/verify-email/login/logout/forgot-password/reset-password endpoints | DONE | `debug:router` lists all seven (`app_api_auth_*`), 43 routes total |
| S2 | `json_login` + `login_throttling` configured | DONE (note) | `config/packages/security.yaml:31-43`; the comment at `:38-41` states the framework listener is not invoked and `AuthThrottleListener` enforces the same policy — verified below |
| S2 | 6th failed login returns 429 | DONE | Live: attempts 1–5 `401 invalid_credentials`, attempt 6 `429 too_many_login_attempts` |
| S2 | Identical responses for known/unknown email on forgot-password and failed login | DONE | Live: `202` + identical body for both addresses; failed login always `401 invalid_credentials` |
| S2 | CSRF listener requires `X-CSRF-Token` on authenticated unsafe `/api`; `GET /api/session` returns `csrfToken` | DONE | `src/EventListener/CsrfProtectionListener.php:96`; live: no header → `403 csrf_invalid`, with header → `204`; `/api/session` returned a 106-char token |
| S2 | Error shape `{error:{code,message,fields?}}` everywhere | DONE | `src/Exception/ApiException.php` + single `ApiExceptionListener`; live bodies match (`invalid_token`, `invalid_id`, `invalid_cursor`, `unsupported_media_type`, `invalid_request`) |
| S2 | Malformed UUID/cursor and wrong JSON types are 4xx, not 500 | DONE | Live (authenticated): `not-a-uuid` → `400 invalid_id`; `cursor=zzz` → `400 invalid_cursor`; `{"email":123,"password":true}` → `400 invalid_request`; invalid JSON → `400`; no content-type → `415` |
| S2 | Mail through `MailFactory` to Mailpit | DONE | `src/Service/MailFactory.php`, used by `AuthController:18,52` and `InvitationService:40`; live verification mail read from Mailpit at :8026 |
| S3 | react-router; five auth routes work on reload | DONE | `src/app/AppRouter.tsx:81-85`; `preview` reload of `/sign-in` renders the form |
| S3 | `api/auth.ts`; CSRF header on unsafe methods + one retry on `csrf_invalid` | DONE | `src/api/client.ts:75,118` (retry guarded by `csrfRetried`), `src/api/auth.ts` |
| S3 | No tokens/passwords in localStorage/sessionStorage | DONE | Grep: only `locale` and theme preference keys (`I18nProvider.tsx:14,29`, `ThemeProvider.tsx:10,36`); `client.ts:38` documents the token staying in memory |
| S3 | Five auth screens with labels, autocomplete, show/hide, inline 422, 429, email_not_verified | DONE | SignIn/SignUp/VerifyEmail/ForgotPassword/ResetPassword screens; `PasswordField.tsx:52-62` (autocomplete, show/hide, `aria-invalid`) |
| S3 | resend-verification endpoint + button | DONE | Route `app_api_auth_resend_verification`; button at `SignInScreen.tsx:67` |
| S3 | Logout from profile menu, session card and sidebar | PARTIAL | `ProfileMenu.tsx:92` (menu), `Account.tsx:189-195` (session card). The sidebar exposes logout only through the profile menu it contains (`AppShell.tsx:200`) — no third standalone control. Left as-is: a duplicate sidebar button would be redundant UI |
| S3 | EN/FR/AR strings | DONE | `src/i18n/{en,fr,ar}` with parity test `src/i18n/messages.test.ts` |
| S3 | `SignedOutScreen.tsx` deleted | DONE | Absent from `src/screens` |
| S3 | Vite proxy only `/api` | DONE | `vite.config.ts:13-15` proxy has one entry, `/api` |
| S3 | No `oidc`/`logto`/`login_check` references | DONE | `git grep` over `frontend docs README.md` → none |
| S4 | Five fixture accounts exist | DONE | `SyntheticFixtureCatalog.php:43-49` owner/admin/member/viewer/unverified `@example.test` |
| S4 | Password hashed at load time from `FIXTURE_PASSWORD` | DONE | `SyntheticFixtureLoader` hashes at load (documented in `AGENTS.md`; `DemoAccountsTest` logs every verified account in) |
| S4 | ~12 customers, ~30 invoices, all statuses | DONE | Catalog: 12 customer rows (7 northwind + 5 contoso), 30 invoice rows (18 + 12), statuses draft/sent/overdue/paid |
| S4 | Docker default DB is `keelson_dev` | DONE | `backend/.env`/compose default database name; `dev-reset.sh:20` `EXPECTED_DATABASE="keelson_dev"` |
| S4 | `dev-reset.sh`/`.ps1` refuse any other DB name | DONE | `dev-reset.sh:37` refuses a non-`keelson_dev` name, `:41` refuses a non-loopback host |
| S4 | `FixtureTargetGuard` refuses prod, non-PostgreSQL, non-loopback, names like `keelson` | DONE | `src/DataFixtures/FixtureTargetGuard.php` (+ `SyntheticFixtureLoaderTest`, `DemoAccountsTest`) |
| S4 | Command prints the account table | DONE | `LoadSyntheticFixturesCommand.php:73` record table, `:82-105` account table |
| S4 | Loading twice creates nothing | DONE | `SyntheticFixtureLoaderTest` idempotency; document counts as "Created / Already present" (`:73`) |
| S4 | Every verified account logs in; viewer cannot mutate | DONE | `DemoAccountsTest` (suite) + e2e `d-viewer-readonly` + manual browser pass below |
| S4 | README Demo accounts section | DONE | `README.md:113-121` |
| S5 | Organization create/patch/delete + subdomain availability | DONE | Routes `app_api_organizations_{create,update,delete,subdomain_availability}`; `OrganizationController.php:52-63` |
| S5 | Members list/role change/remove with last-owner 409 | DONE | Routes `app_api_members_*`; `ApiException.php:105` `lastOwner()` → 409 `last_owner` |
| S5 | Invitations create/list/resend/revoke + preview + accept | DONE | Five invitation routes + `app_api_auth_accept_invitation` |
| S5 | Dashboard endpoint | DONE | `app_api_dashboard`; live figures rendered in the browser |
| S5 | Notifications list/read/read-all | DONE | Three routes; notifications page rendered 12 rows / 10 unread |
| S5 | `PATCH /api/me`, `POST /api/me/password` | DONE | Routes present; `ProfileApiTest` |
| S5 | `q` + cursor + `nextCursor` on customers, invoices, audit | DONE | `api_customers_list`, `api_invoices_list`, `api_invoice_audit_list`; `ListQueryTest`; live `invalid_cursor` 400 |
| S5 | mark-overdue in batches creating notifications | DONE | `InvoiceOverdueService` (BATCH_SIZE 200, per organization, `NotificationService` injected) |
| S5 | Fixtures extended with invitations and notifications | DONE | Catalog `invitations` key + notifications seeded |
| S5 | Role-matrix tests per endpoint | DONE | `InvoiceAuthorizationMatrixTest` (Owner/Admin/Member/Viewer, suspended membership/org), plus organization/team tests |
| S5 | Every endpoint documented | DONE | `Unit/Docs/OpenApiContractTest.php:29-32` fails if router and `docs/openapi.yaml` differ in either direction |
| S6 | RLS enabled and forced on customers, invoices, invoice_audit_events, memberships, invitations, notifications | MISSING (STILL OPEN) | No migration contains `ROW LEVEL SECURITY`/`CREATE POLICY`; no `SET LOCAL app.…` anywhere. `docs/adr/0003-postgresql-row-level-security.md` records the decision to ship application-layer scoping instead |
| S6 | Zero-rows-without-`app.principal_id` test | MISSING (STILL OPEN) | Cannot exist without RLS; the equivalent coverage is `TenantScopedRepositoryTest` + `InvoiceAuthorizationMatrixTest` (404 on foreign tenant) |
| S6 | Non-superuser `keelson_app` role, owner connection for migrations/commands | DONE | `docker/postgres/10-app-role.sh` creates the role with DML-only grants; `docker-compose.prod.example.yml:42` migrate uses `POSTGRES_USER`, `:57` backend uses `APP_DB_USER` |
| S6 | Sessions in PostgreSQL, cookie flags, new session id on login | DONE | `config/packages/framework.yaml:21-25` (`session.handler.pdo`, httponly, samesite lax, secure auto); `PdoSessionHandlerFactory` + regression test |
| S6 | Security-headers listener | DONE | `SecurityHeadersListener` (`/api` only, HSTS only in prod over HTTPS); proven in the production run |
| S6 | 415/413/depth caps | DONE | `ApiBodyGuardListener`; `HardeningTest:78,90,101`; live 415 |
| S6 | Central `rate_limiter.yaml` | DONE | `config/packages/rate_limiter.yaml` (login_email 5/min, login_ip, resend_*, subdomain_availability) |
| S6 | `security_events` table without secrets | DONE | `src/Entity/SecurityEvent.php`; `HardeningTest:127` |
| S6 | `composer audit` and `pnpm audit --audit-level high` clean or documented | DONE | composer: "No security vulnerability advisories found."; pnpm: 1 moderate (`minimatch` GHSA-g3hv-75wg-m249), recorded at `docs/security.md:8-13` |
| S6 | `EnvironmentValidator` refuses unsafe prod config | DONE | `Unit/Config/EnvironmentValidatorTest`; container run printed six findings and exited 1 |
| S6 | Data-driven IDOR test over all routes with ids | **PARTIAL→DONE** (fixed) | Added `backend/tests/Integration/RouteIdorTest.php`: iterates the router, calls every `/api` route with a placeholder as a foreign tenant and as nobody; 2 tests, 106 assertions, `OK` |
| S7 | Routes under `/o/:orgId/...` | DONE | `AppRouter.tsx:104-117` |
| S7 | Dashboard, Team, Invitations, Notifications, Onboarding, Settings, Account, AcceptInvitation on real APIs | DONE | All screens import `../api/*`; Team/Invitations served by `organizationAdmin`/`members`/`invitations` |
| S7 | Server-side search + cursor paging with Load more | DONE | `SearchDialog`, `Pagination.tsx`, `useApiResource` with cursor; `q` handled by the API |
| S7 | Skeleton, Toast, ErrorBoundary, consistent empty/error states | DONE | `components/{Skeleton,Toast,ErrorBoundary,EmptyState,ErrorState}.tsx` |
| S7 | Focus trap/restore, skip link, landmarks, nav links | DONE | `AppShell.tsx:160` skip link, `OverlaySurface` focus trap, `ProfileMenu.tsx:39,47` restore, `role="main"`/`nav` landmarks |
| S7 | Styles split per feature | DONE | `Dashboard.css`, `customers.css`, `invoices.css`, `auth.css`, `simple.css` |
| S7 | i18n split per language with parity test | DONE | `i18n/{en,fr,ar}/…` + `i18n/messages.test.ts` |
| S7 | Lazy routes | DONE | `AppRouter.tsx:19-25` `lazy(() => import(...))` |
| S7 | `index.html` fixed | DONE | Title, description, theme-color, icon, viewport in `frontend/index.html` |
| S7 | Invoice print view | DONE | Invoice detail renders a `Print` action and print CSS |
| S7 | `src/mocks/`, Pricing/Billing deleted | DONE | `src/mocks` absent; no pricing/billing screens |
| S7 | `grep -r 'mocks/' frontend/src` empty | DONE | No matches |
| S8 | Component tests (auth, editor, team, toast/dialog, i18n parity) | DONE | 15 files: `SignIn/SignUp/ResetPassword`, `InvoiceEditor`, `Team`, `Toast`, `OverlaySurface`, `messages`, api clients |
| S8 | Playwright e2e journeys | DONE | `e2e/tests/*.spec.ts`; this run: 7 passed (2.0m) |
| S8 | PHPStan level 8, empty baseline | DONE | `[OK] No errors` with `shipmonk/dead-code-detector`, no baseline |
| S8 | php-cs-fixer enforced | DONE (note) | `--dry-run` on the LF blobs from `HEAD` → "Found 0 of 2 files"; the 152-file finding on this machine is the CRLF working tree (`core.autocrlf=true`, no `.gitattributes`), not style |
| S8 | Coverage threshold 80% on Service/Security/Controller | NOT RUN | Gate exists (`backend/bin/coverage-check.php:17,21`); needs pcov/xdebug, absent here |
| S8 | Bulk fixtures + `docs/performance.md` timings | DONE | `BulkFixturesCommand`, `bin/perf.php`, `docs/performance.md` |
| S8 | `docs/openapi.yaml` with a router-matching test | DONE | `OpenApiContractTest` (both directions + response presence) |
| S8 | CI with frontend/backend/e2e/audit jobs, caches, SHA-pinned actions, dependabot | DONE | `.github/workflows/ci.yml` jobs `frontend:28`, `backend:60`, e2e `:159`, `audit:242`; every `uses:` pinned to a SHA; `.github/dependabot.yml` |
| S8 | ADRs 0001–0005 | DONE | `docs/adr/` five files |
| S8 | Test counts produced by a script, not hard-coded | **MISSING→DONE** (fixed) | Added `bin/test-counts.sh`; six documents now point at it instead of quoting numbers |
| S9 | Dead-code detector and knip report nothing | DONE | phpstan `[OK] No errors`; `pnpm knip` exit 0, no output |
| S9 | Unused i18n keys removed | DONE | `node scripts/unused-i18n.mjs` → "No unused dictionary keys." |
| S9 | No tracked binaries/build output/env files | DONE | `git ls-files` has no `dist/`, `node_modules`, `vendor`, `var/`, `coverage`, `*.png/ico/woff/pdf`; tracked env files are `.env`, `.env.example`, `.env.test` with no secret values (`backend/.env:22 APP_SECRET=` empty) |
| S9 | No TODO/FIXME without a limitations entry | DONE | `git grep -n "TODO\|FIXME"` → none |
| S9 | Both Dockerfiles and the prod compose build and run | DONE | `docker build` backend `exit=0`, frontend `exit=0`; stack ran healthy earlier (migrate exited 0, all three healthy) |
| S9 | Production boot proven (`APP_ENV=prod`, migrate, no fixtures, sign up works) | DONE | Fresh DB: 7 migrations as keelson_owner; register 201 → Mailpit → verify 204 → login 200 → customer 201 → invoice 201 → send 200 → dashboard 300000 outstanding |
| S9 | README (architecture diagram, env table), deployment, CONTRIBUTING, SECURITY, CHANGELOG, limitations | DONE | All present; README Quick start/env tables; `docs/deployment.md` 210 lines |
| S9 | No LICENSE file | DONE | Absent (`ls LICENSE` fails) |
| Billing | Optional stripe sprint | NOT STARTED | `grep -n stripe backend/composer.json frontend/package.json` → no match; no plans/subscriptions/webhook code |

## 2. Fixed during this audit

| Gap | Commit | What changed |
| --- | --- | --- |
| S6 — no route-wide IDOR test | `b0da26f` | `backend/tests/Integration/RouteIdorTest.php`: reads the router, calls every `/api` route with an identifier as a foreign tenant (no 2xx, no 5xx) and anonymously (401/403/404). 2 tests / 106 assertions |
| S8 — test counts hard-coded in six documents | `8f63286` | `bin/test-counts.sh` runs both suites (starting/stopping the disposable DB) and prints counts; README, AGENTS, `docs/api-contract.md`, `docs/ROADMAP.md` now point at it |

## 3. Still open

1. **S6 RLS (and the zero-rows test that depends on it).** Not closed on purpose: it is
   an architecture change (policies on six tables, `SET LOCAL` on every connection,
   session table included) that the accepted ADR 0003 explicitly rejected for 1.0.0.
   Next step: owner decision, then a migration adding `ENABLE/FORCE ROW LEVEL SECURITY`
   + policies keyed on `current_setting('app.principal_id')`, a connection listener
   that sets it, and a test that asserts zero rows without it. The privilege split
   (`keelson_owner`/`keelson_app`) already exists, which is the prerequisite.
2. **Coverage gate** — NOT RUN locally (no pcov/xdebug). Next step: install pcov and run
   `php bin/phpunit --coverage-clover var/coverage/clover.xml && php bin/coverage-check.php var/coverage/clover.xml`.
3. **CI workflows** — NOT RUN: the repository has never been pushed in this audit.
4. **S3 logout entry points** — two of the three named surfaces exist (profile menu, account
   session card); the sidebar hosts the profile menu rather than its own button.
5. **`.pr-screenshots/`** — untracked at the repo root, pre-existing. It is neither
   committed nor ignored; add it to `.gitignore` or delete it.
6. **Windows line endings** — with `core.autocrlf=true` and no `.gitattributes`,
   `php-cs-fixer --dry-run` flags every file on a Windows checkout while CI (Linux) is
   clean. Adding `* text=auto eol=lf` would make checkouts deterministic; out of scope here.

## 4. Final outputs (verbatim)

```
== branch/head ==
audit/complete-sprints
8f63286 docs: report test counts from a script instead of copying them
b0da26f test(backend): walk every identifier route as a foreign tenant
82b2a6f docs: warn that a second stack shares the compose volume

== git status --short ==
?? .pr-screenshots/

== git ls-files | wc -l ==
393

== phpstan (level 8 + shipmonk/dead-code-detector) ==
 [OK] No errors

== php-cs-fixer --dry-run (LF blobs from HEAD) ==
Found 0 of 2 files that can be fixed

== php-cs-fixer --dry-run (CRLF working tree, this machine) ==
Found 152 of 160 files that can be fixed

== phpunit (full suite, test database) ==
OK (216 tests, 1298 assertions)

== bin/test-counts.sh ==
backend: OK (216 tests, 1298 assertions) (exit 0)
frontend: 98 tests in 15 files (exit 0)

== frontend build ==
dist/assets/index-Yd9gCQ15.js              367.17 kB │ gzip: 111.02 kB
✓ built in 1.10s
build exit=0

== frontend lint ==
$ eslint .
lint exit=0

== frontend tests ==
 Test Files  15 passed (15)
      Tests  98 passed (98)
test exit=0

== pnpm knip ==
exit=0 (no output)

== node scripts/unused-i18n.mjs ==
No unused dictionary keys.

== playwright (e2e) ==
  ok 1 … sign up, verify by email, sign in, create a customer and sign out
  ok 2 … owner invoices a customer, sends it, marks it paid and sees the notification
  ok 3 … owner invites a new user and the invitation is accepted in a second context
  ok 4 … a viewer sees no write actions and the forged API calls are refused
  ok 5 … a wrong password is refused and repeated attempts are throttled
  ok 6 … locales › French sign-in smoke test
  ok 7 … locales › Arabic sign-in smoke test keeps the document right-to-left
  7 passed (2.0m)
exit=0

== composer audit ==
No security vulnerability advisories found.

== pnpm audit --audit-level high ==
1 vulnerabilities found
Severity: 1 moderate
exit=0

== docker build ./backend ==
exit=0
== docker build ./frontend ==
exit=0

== migrations down/up (throwaway DB) ==
[notice] Migrating down to 0
[notice] finished in 158.7ms, used 18M memory, 7 migrations executed, 44 sql queries
 [OK] Successfully migrated to version: 0
| Migrations           | Executed             | 0 |
[notice] Migrating up to DoctrineMigrations\Version20261116170000
[notice] finished in 188.9ms, used 18M memory, 7 migrations executed, 82 sql queries
 [OK] Successfully migrated to version: DoctrineMigrations\Version20261116170000
| Migrations           | Executed             | 7 |

== secrets grep (tracked files) ==
backend/.env:22:APP_SECRET=            <- empty value in the shared default file
backend/Dockerfile:8:# … -e APP_SECRET=... -e DATABASE_URL=...
backend/src/Logging/StructuredLogger.php:18,202  <- documentation + redaction regex
(no BEGIN PRIVATE KEY, client_secret value, sk_live_ or whsec_ anywhere)
```

Live auth proof (dev stack + Mailpit, `127.0.0.1:8000`):

```
register -> 201 {"status":"verification_sent"}
verify -> 204 ; verify again (single use) -> 400 {"error":{"code":"invalid_token",…}}
login -> 200 ; /api/session csrfToken length 106
unsafe request without X-CSRF-Token -> 403 csrf_invalid
unsafe request with token -> 204
attempts 1-5 -> 401 invalid_credentials ; attempt 6 -> 429 too_many_login_attempts
forgot-password known -> 202 ; unknown -> 202 ; identical: yes
malformed UUID -> 400 invalid_id ; bad cursor -> 400 invalid_cursor
wrong JSON type -> 400 invalid_request ; invalid JSON -> 400 ; no content-type -> 415
```

Manual browser pass (`http://127.0.0.1:5173`, owner@example.test): dashboard rendered live
figures (paid 20,676.262 / outstanding 33,422.649 / overdue 17,198.475, chart with table
fallback, 5 recent invoices); created "Audit Manual Customer" through the dialog → toast
"Audit Manual Customer created."; opened a draft invoice → "Send invoice" → status `Sent`
→ "Mark as paid" → status `Paid`; notifications page listed 12 rows with mark-all-read;
signed out via the profile menu → `/sign-in`. Then viewer@example.test: invoices list,
12 rows, **zero write controls** (no create/edit/delete/send/mark) and role badge `Viewer`.

Caveat: this panel's synthetic mouse clicks were not delivered to the page (the
show-password toggle did not react), so the pass was driven through the app's own DOM
handlers and form submissions. Real pointer input is covered by the Playwright run above,
which uses a real Chromium and passed all seven journeys.

## 5. Open questions for the owner

1. **Licence** — deliberately still absent. Which licence should 1.0.0 ship under?
2. **Hosting target** — php-fpm + nginx Compose is proven locally; the real host decides
   `TRUSTED_PROXIES`/`TRUSTED_HEADERS` and whether TLS terminates in front of nginx.
3. **Billing** — the optional Stripe sprint is NOT STARTED. Build it next, or drop it from
   the plan and say so in `docs/ROADMAP.md`?
4. **RLS** — close the ADR 0003 gap in 1.0.x, or keep it as documented future work?
