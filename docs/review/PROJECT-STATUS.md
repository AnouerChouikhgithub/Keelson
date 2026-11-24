# Keelson — independent project status audit

## 1. Scope, baseline and what could not be run

| | |
| --- | --- |
| Audited commit | `21e29552107b3ca9877fb29f23891193f8229f97` (`main`, "Merge pull request #38 …"), committed 2026-11-23 15:46:48 +0100 |
| Branch audited | `main`; audit written on `audit/project-status` (not merged) |
| Audit date | 2026-11-24 |
| Working tree at audit time | **Not clean.** Five tracked files modified (`SECURITY.md`, `backend/src/Summary/SummaryService.php`, `backend/tests/Ai/Eval/SummaryEvalTest.php`, `docs/ai.md`, `docs/deployment.md`) and three untracked paths (`backend/tests/Integration/Summary/SummaryBudgetPolicyTest.php`, `docs/runbooks/ai-key-rotation.md`, `docs/screenshots/`). Those edits were preserved and are **not** part of this commit. Where the tree and `HEAD` differ, both results are reported. |
| Environment | Windows 11, PHP 8.5.12 CLI (`pdo_pgsql`, `sodium`; **no `pcov`, no `xdebug`**), Composer 2.10.0, Node 22.23.3, pnpm 12.14.0, Docker 29.5.2. PostgreSQL 16 (`keelson-db-1`, 127.0.0.1:5433) and Mailpit (:1026, UI :8026) from the project's Compose file. |
| App under test | API `php -S 127.0.0.1:8010` and SPA `pnpm dev --host 127.0.0.1 --port 5174` (`KEELSON_BACKEND_ORIGIN=http://127.0.0.1:8010`), against a **disposable** database `keelson_audit_dev` created for this audit (13 migrations from empty, `app:fixtures:load`). The Compose `keelson_dev` database and its `db_data` volume were **not** migrated, reset or written to. |

Environment notes that cost time and may cost the next auditor the same:

- `php.ini` here sets `variables_order=GPCS`, so **exported environment variables are invisible to Symfony** (`$_SERVER` has no `DATABASE_URL`) and `.env.local` always wins. `php -d variables_order=EGPCS` was required to point the server at the audit database.
- A **pre-existing `php -S` process (PID 12816) already holds port 8000** and serves the stale `keelson_dev` schema; sign-in against it answers `500 internal_error` (`column t0.session_version does not exist`, migration `Version20261202120000` not applied). That process was left alone; the audit ran on 8010.
- The SPA's sign-in button did not fire its submit handler under the browser tool's synthetic clicks (the form `submit` event never fired); the flow was exercised with `form.requestSubmit()`. This is recorded as a tooling artefact, not a product defect — the same problem affected the AI settings checkbox, where `element.click()` worked immediately.

### What I could not run, and why

| Item | Status | Why |
| --- | --- | --- |
| Coverage (`backend/bin/coverage-check.php`, 80% gate) | NOT RUN | This machine has neither `pcov` nor `xdebug`. No percentage is claimed. |
| Playwright journeys (`pnpm --dir e2e test`) | NOT RUN | `e2e/playwright.config.ts:40-47` hardcodes `php -S 127.0.0.1:8000` and sets `reuseExistingServer: !CI`. Port 8000 is held by the stale server described above, so the run would have reused a broken instance and tested nothing about this commit. |
| axe / automated accessibility audit | NOT RUN | No axe dependency exists in the repo; none was added (read-only audit). Accessibility was inspected manually only. |
| `pnpm install` / `composer install` from a clean clone | NOT RUN | Only the existing `vendor/` and `node_modules/` were used; the online install path and registry availability were not exercised. |
| Docker image builds, `docker-compose.prod.example.yml` | NOT RUN | Out of scope for a read-only audit run; not attempted. |
| TLS, HSTS under a real proxy, real SMTP | NOT RUN | No TLS terminator and no real SMTP endpoint here. |
| N+1 query counting on live screens | NOT RUN | No query logger enabled; the reading is static (fetch-joins) plus one `EXPLAIN`. |
| AI eval against a real provider (`.github/workflows/ai-eval.yml`) | NOT RUN | `workflow_dispatch` only, needs `AI_API_KEY`; the run used `AI_PROVIDER=fake` deliberately so no owner credits were spent. |
| Answer quality of a live model | NOT RUN | Same reason. Only plumbing, retrieval and safety were exercised. |

---

## 2. Executive summary

- **Backend: PARTIAL.** `HEAD` is green (595 tests / 4427 assertions, PHPStan level 8 clean, style clean on an LF copy, migrations up to date). The untracked working-tree test is red, and the SPA never calls the dashboard endpoint.
- **Frontend: PARTIAL.** Every screen renders real API data in EN/FR/AR with RTL and a working theme switch, and the i18n key sets are exactly equal (608/608/608). Two visible defects: the auth screens lose their field layout, and money figures clip at 390 px. The dashboard's headline numbers are computed in the browser from at most 100 invoices.
- **AI: PARTIAL.** One gateway really is the only provider boundary, the budget reservation is one atomic conditional `UPDATE … RETURNING` with a two-connection test, and the AI tables are RLS-enforced. Grounding only proves *numeric* claims, not prose.
- **Security: DONE for the surface checked.** A1–A5 are fixed in code and covered by tests; CSRF, same-origin, cross-tenant `404` and cookie flags were re-verified over live HTTP. No Critical issue found. Residual risks are the committed-then-removed `.env.dev` in history and the documented absences (MFA, email change, identity-table RLS).
- **Ops: PARTIAL.** CI gates are real (hygiene, frontend, backend with coverage + migrations + schema, e2e, aggregated audit), images are non-root with healthchecks. Locally, `/readyz` answers `503` on the documented Compose stack by design.
- **Docs: PARTIAL.** Unusually extensive and mostly accurate, but the backend README contradicts the shipped code, `AGENTS.md` undercounts routes, and the roadmap asserts browser-suite results this audit could not reproduce.
- **Overall verdict: the product is real and operable, not a demo shell.** The highest-value fixes are not security work; they are the dashboard aggregate (wrong at scale) and two UI defects on the first screens a user meets.

---

## 3. Findings, sorted by severity

Severity: Critical (data leak / auth bypass / data loss) · High (feature broken or weakness exploitable by a logged-in user) · Medium (wrong behaviour users can hit, missing safeguard) · Low (polish, docs, hygiene).
Effort: S < 1 day · M 1–3 days · L > 3 days.

| ID | Sev. | Area | Finding | Evidence | Suggested fix | Effort |
| --- | --- | --- | --- | --- | --- | --- |
| F-01 | **High** | Frontend / data correctness | The dashboard never calls `GET /api/organizations/{id}/dashboard`. It loads `listInvoices(…, { limit: 100 })` and sums in the browser, so every headline figure (paid this month, outstanding, overdue, paid rate, revenue chart) silently ignores invoices 101+. The backend endpoint — documented as authoritative with four SQL statements — has **no frontend caller**. | `frontend/src/screens/Dashboard.tsx:58` (`limit: 100`), `:87-99` (client-side `filter`/`reduce`); `backend/src/Repository/InvoiceRepository.php:71` (`setMaxResults(max(1, min($limit, 100)) + 1)`); route `app_api_dashboard` in `debug:router`; `docs/api-contract.md:514`; backend test `tests/Integration/DashboardApiTest.php` exists but nothing exercises the SPA path. Observed on the running SPA: the dashboard issued `/invoices?limit=100`, never `/dashboard`. | Make Dashboard use the dashboard endpoint (add `frontend/src/api/dashboard.ts`), keep the chart if it needs per-month rows but feed it server aggregates, and add a component test asserting the extra call. | M |
| F-02 | Medium | Frontend / CSS | The auth screens use `class="field"` but never import the stylesheet that defines it, so labels and inputs collapse onto one line and the input falls back to its intrinsic 180 px width. Sign-in, sign-up, forgot- and reset-password share this. | `frontend/src/screens/auth/SignInScreen.tsx:73-88` (raw `<div className="field">`); `.field` is defined only in `frontend/src/components/Field.css:1` and imported only by `components/Field.tsx`; `screens/auth/AuthLayout.tsx` imports `auth.css` only. Live measurement at 1440 px: email input `180×29`, computed `display: inline-block`; screenshot shows the label overlapping the field. | Import `Field.css` from `AuthLayout` (or move `.field` rules into the global sheet loaded by `main.tsx`). | S |
| F-03 | Medium | Frontend / responsive | At 390 px the money figures in the dashboard stat cards are clipped: “Outstanding” needs 152 px and gets 129, “Overdue” needs 141 and gets 129 — the user reads `TND 11,95…`. | Live `getBoundingClientRect`/`scrollWidth` at `window.innerWidth = 390`: `TND 11,956.600` sw 152 / cw 129; `TND 6,922.200` sw 141 / cw 129. Screenshot at 390×844 shows the ellipsis. | Allow the value to shrink (smaller type step below 480 px), or reduce to one column so the amount has room; assert no `scrollWidth > clientWidth` on the value. | S |
| F-04 | Medium | AI / safety | `GroundingCheck` proves only **digit** claims. A summary that writes “five invoices are overdue” when the facts say three, or “almost half of the balance” with no number, passes the check untouched. `docs/ai.md` (“a summary that mentions a figure the request could not derive is replaced”) and `docs/privacy-data-flow.md` read stronger than the code. | `backend/src/Summary/GroundingCheck.php:64-70` — `if (0 === preg_match_all(NUMBER_PATTERN …)) return true;`; tests cover digits only (`tests/Unit/Summary/GroundingCheckTest.php:26-114`), no test for spelled-out numerals or qualitative claims. | Either narrow the documentation to “numeric claims”, or add a locale word-list (one…ten / un…dix / واحد…عشرة) to the same derivation pool, plus a test for each. | M |
| F-05 | Medium | AI / discoverability | AI surfaces disappear completely when the organization has not opted in, with no hint and no link to the place that fixes it. A freshly registered or freshly seeded organization has **no `organization_ai_settings` row** (schema default budget `0`), so `organizationEnabled=false` and the header button and summary card render nothing. The fixture loader creates no AI settings row either. | Captured live for the fixture Owner before opting in: `GET /api/ai/status` → `{"enabled":true,"organizationEnabled":false,"budgetRemainingTokens":0}` (`backend/src/Controller/AiStatusController.php:44-70`); `frontend/src/features/ai/AssistantLauncher.tsx:20` (`if (!canUseAi(status)) return null`); `useAiStatus.ts:41-43`. The Settings section *is* reachable and did enable it (§7). | When `enabled && !organizationEnabled`, show a discreet “AI is available — enable it in Settings” affordance for Owner/Admin instead of rendering nothing; seed a disabled settings row in fixtures so the empty state is exercised. | S |
| F-06 | Medium | Backend / working tree | The working tree does **not** pass its own suite: 599 tests, 1 failure, in the untracked `SummaryBudgetPolicyTest::testACacheHitIsRefusedWhenTheBudgetIsExhausted` (“The first request generates for real” — `fallback` was `true`). The implementation it tests (`SummaryService::assertBudgetAvailable`) is uncommitted too, so the cache-hit budget policy is shipped neither green nor merged. `HEAD` alone is green. | `php bin/phpunit` (tree) → `Tests: 599, Assertions: 4456, Failures: 1`, exit 1, 01:25; `php bin/phpunit --filter '/^(?!.*SummaryBudgetPolicyTest)/'` → `OK (595 tests, 4427 assertions)`, exit 0, 01:16. `git status` shows the test untracked and `SummaryService.php` modified. | Fix or finish the policy and its test before merging; until then the tree is red and CI would fail on the branch that carries it. | S |
| F-07 | Medium | Security / history | `backend/.env.dev` was committed and later deleted; the history is not rewritten (documented in `docs/limitations.md`). The file contained at least one non-empty secret-shaped assignment. Anyone with the repository can read it. | `git log --oneline -- backend/.env.dev` → `644ab68`, `1669777`; `git show 644ab68^:backend/.env.dev | grep -cE 'APP_SECRET=.+|DATABASE_URL=.+'` → `1`. Current `HEAD:backend/.env` is clean (`APP_SECRET=`, `AI_API_KEY=`, local-only DB URL). | Treat every value ever present in that file as public: rotate `APP_SECRET` and any credential that was reused. Keep the documented note, and add the scan to the hygiene job if not already covered. | S |
| F-08 | Low | Docs | `backend/README.md` describes a different project than the one that ships: PHPStan “level 5, phpstan-baseline.neon”, “170 tests / 932 assertions (2026-11-16)”, and “Not implemented: PostgreSQL row-level security, invitation issuance/acceptance, organization lifecycle operations…”. All of those are implemented and tested. | `backend/README.md` (the “Tests” and “Not implemented” sections); `composer stan` → `[OK] No errors` with `phpstan.neon.dist` (level 8 per `AGENTS.md`); `debug:router` shows invitations/RLS-era routes; RLS verified in §4 table. | Rewrite those two sections against the current tree; do not restate test counts manually (`bash bin/test-counts.sh` is the documented source). | S |
| F-09 | Low | Docs | `AGENTS.md` says “43 HTTP routes”; the router exposes **49**. | `php bin/console debug:router --env=test --format=json` → 49 entries (AI status/guide/summaries, `resend-verification`, `subdomain-availability` are beyond the documented list). | Update the count and the route list, or drop the number and point at `debug:router`. | S |
| F-10 | Low | Frontend / design system | The stated rule “keep all colours in `tokens.css`” is violated in two places: a dark-theme danger text colour and the print stylesheet. | `frontend/src/components/Button.css:48` (`#2A0A0A`); `frontend/src/screens/invoices.css:121-129` (`#FFFFFF`, `#000000`, `#333333`, `#555555`, `#CCCCCC`, `#FFFFFF` in a `@media print` block). | Move the button colour to a token; print colours are defensible but should be declared as such in a comment or a dedicated `print-tokens` block. | S |
| F-11 | Low | Security / config hygiene | `login_throttling` is declared in the firewall but its listener never runs (the code says so); the explicit `AuthThrottleListener` is what enforces the policy. Two mechanisms, one of which is inert, is a trap for the next reader. | `backend/config/packages/security.yaml:35`; `backend/src/Security/AuthAttemptLimiter.php:29-37` (comment: “its listener never runs in this setup”). | Remove the dead `login_throttling` block or wire it and delete the duplicate, and keep one test that proves which one answers `429`. | S |
| F-12 | Low | Ops | `/readyz` returns `503 {"rowLevelSecurity":"bypass"}` on the documented local Compose stack, because the Compose `app` role is the bootstrap superuser. Documented, but it makes the local readiness signal useless and hides a real regression if one appears. | Live: `GET http://127.0.0.1:8010/readyz` → 503, `database: ok`, `rowLevelSecurity: bypass`; `docs/limitations.md` states exactly this. | Give the dev Compose file the same owner/app split as production (`docker/postgres/10-app-role.sh` already exists), so `readyz` is `ok` locally too. | S |
| F-13 | Low | Frontend / a11y | In my run the assistant drawer (`role="dialog"`, `aria-modal="true"`) ended with focus on `<body>` after an answer arrived, i.e. outside the trap. The trap itself is implemented and re-focuses on open, so this may be an artefact of synthetic input — hence UNVERIFIED, not confirmed. | `frontend/src/features/ai/AssistantPanel.tsx` → `OverlaySurface`; `frontend/src/hooks/useFocusTrap.ts:33-36` (`initial.focus()` on mount); observed `document.activeElement === document.body` with the dialog open. | Reproduce with a real keyboard: open the panel, ask, confirm focus stays on the composer or the answer; if it does not, re-focus the close button after the answer state settles. | S |
| F-14 | Low | UI | The audit log renders raw event codes (`invoice.created`, `invoice.sent`, `invoice.paid`) and short hex ids as table content, in all three languages. Fine for a technical trail, but it looks unfinished next to the translated rest of the app. | Live `/audit` in French: rows “`invoice.created  111b2c54  363a151d  db7751f4  29 sept. 2026, 01:00`”. | Map the closed set of event types to localized labels, keep the raw code as a `title`/secondary line. | S |

Counts: **Critical 0 · High 1 · Medium 6 · Low 7 = 14 findings.**

---

## 4. Feature inventory

Status vocabulary: DONE (code + a run I performed) · PARTIAL (works, gaps listed) · MISSING · BROKEN · UNVERIFIED · NOT RUN.

| Feature | Frontend route / component | Backend endpoint(s) | DB tables | Automated test(s) | Status | Evidence | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Registration + first organization | `/sign-up` `SignUpScreen` | `POST /api/auth/register` | `principals`, `organizations`, `memberships`, `auth_tokens` | `RegistrationFlowTest`, `RegistrationConstraintTest`, `SignUpScreen.test.tsx` (3) | DONE | live: account created, verification queued through Mailpit | enumeration-safe by design |
| Email verification | `/verify-email` | `POST /api/auth/verify-email`, `POST /api/auth/resend-verification` | `auth_tokens`, `principals` | `RegistrationFlowTest`, `AuthTokenRedemptionConcurrencyTest`, `ResendVerificationTest`, `DeferredMailTest` | DONE | code: `AuthController.php:91-105` sets the password at verification (A1 fix) | pre-hijacking closed |
| Sign-in / sign-out | `/sign-in`, profile menu | `POST /api/auth/login`, `POST /api/auth/logout` | `sessions`, `principals`, `security_events` | `LoginTest`, `SessionLogoutTest`, `AuthThrottlingTest`, `AuthThrottleListener` | DONE | live: `login 200`, `Set-Cookie: PHPSESSID=…; httponly; samesite=lax`; logout → `/sign-in` | e2e throttling journey NOT RUN |
| Password reset | `/forgot-password`, `/reset-password` | `POST /api/auth/forgot-password`, `POST /api/auth/reset-password` | `auth_tokens`, `principals` | `PasswordResetTest`, `SessionRevocationTest` | DONE | code: reset invalidates sessions (`AuthController.php:166-175`) | — |
| Session handling | `/api/session` polling, `BroadcastChannel` sync | `GET /api/session` | `sessions` | `SessionControllerTest`, `SessionRevocationTest`, `SessionVersionListener` | DONE | live cookie flags; `session_version` column present after migration | `Secure` only over HTTPS (`cookie_secure: auto`) |
| Organization create / switch | `/onboarding`, header switcher | `POST /api/organizations`, `GET /api/me/organizations`, `GET /api/organizations/subdomain-availability` | `organizations`, `memberships` | `OrganizationApiTest`, `MeOrganizationsTest`, `organizations.test.ts` (10) | DONE | live: switcher lists memberships with role | — |
| Team members and roles | `/team` | `GET/PATCH/DELETE /api/organizations/{id}/members[/{id}]` | `memberships`, `principals` | `Team.test.tsx` (3), `InvoiceAuthorizationMatrixTest`, `LastOwnerProtectionTest` | DONE | live: 5 members listed; viewer sees no edit controls | advisory lock for the last owner |
| Invitations (create/resend/revoke/accept) | `/team`, `/accept-invitation` | `POST/GET …/invitations`, `POST …/invitations/{id}/resend`, `DELETE …/invitations/{id}`, `GET /api/invitations/{token}`, `POST /api/auth/accept-invitation` | `invitations`, `principals`, `memberships` | `TeamInvitationTest`, `InvitationAcceptanceConcurrencyTest` | DONE | `InvitationService.php:120-140` row lock, `:168-173` unique-violation → 409 | — |
| Customers CRUD + search + pagination | `/customers` `Customers` | `GET/POST/PATCH/DELETE /api/organizations/{id}/customers[/{id}]` | `customers` | `CustomerDeletionConcurrencyTest`, `ListQueryTest`, `customers.test.ts` (7) | DONE | live: list renders, 7 rows for the tenant, cursor pagination in code | `ILIKE` + trigram indexes |
| Invoices: create/edit/status/search/pagination | `/invoices`, `/invoices/:id`, `InvoiceEditor` | `…/invoices` list/create/read/patch, `/send`, `/mark-paid`, `DELETE` (draft) | `invoices` (line items in JSON), `invoice_audit_events` | `InvoiceApiTest`, `InvoiceHttpApiTest`, `InvoiceNumberAllocationTest`, `InvoiceDraftConcurrencyTest`, `InvoiceOverdueTest`, `invoices.test.ts` (9) | DONE | live: 18 rows; `587,600 TND` (3 decimals, FR thousands separator) | money is integer millimes server-side |
| Invoice totals / money arithmetic | `InvoiceCalculator` | same | — | `InvoiceCalculator` tests via `InvoiceApiTest` | DONE | `backend/src/Service/InvoiceCalculator.php` — integer `intdiv`/`roundDivide`, “No floats are used”; overflow guards | — |
| Dashboard cards and chart | `/dashboard` `Dashboard` | **`GET …/dashboard` never called** | computes from `invoices` | `DashboardApiTest` (backend only), no frontend test | **PARTIAL** | §3 F-01; live reconciliation | figures correct while ≤100 invoices |
| Notifications + read state | `/notifications` | `GET /api/notifications`, `POST /api/notifications/{id}/read`, `POST /api/notifications/read-all` | `notifications` | `NotificationApiTest`, `notificationApi` unit tests | DONE | live: 2 unread badge, list with read timestamps | per-recipient RLS exception |
| Audit log | `/audit` | `GET …/invoice-audit-events` | `invoice_audit_events` | `AuditAtomicityTest` | DONE | live: 25 rows + “charger plus” | raw event codes (F-14) |
| Organization settings | `/settings` | `PATCH/DELETE /api/organizations/{id}` | `organizations` | `OrganizationApiTest` | DONE | live: name/subdomain form, danger zone owner-only | — |
| AI settings (opt-in + budget) | `/settings` → “AI assistance” | `GET/PUT …/ai-settings` | `organization_ai_settings`, `security_events` | `AiSettingsApiTest` | DONE | live: enabled it, `ai_enabled` security event written, status flipped to `true` | new orgs start disabled with budget 0 |
| Assistant / Help panel | header “Ask Keelson” | `POST /api/ai/guide`, `GET /api/ai/status` | `organization_ai_settings`, `ai_requests` | `GuideApiTest`, `GuideEvalTest`, `PromptInjectionTest` | DONE (when opted in) | live: answer + 3 sources; absent before opt-in (F-05) | fake provider used |
| Workspace summaries | dashboard “Workspace summary” | `POST …/ai/summaries/{kind}` | `ai_requests`, `organization_ai_settings` | `SummaryApiTest`, `SummaryServiceTest`, `SummaryBudgetPolicyTest` (untracked, red), eval sets | DONE | live: “7 customers, 18 invoices, 3 overdue and 11,956.600 TND outstanding” — matches SQL | grounding is numeric-only (F-04) |
| Onboarding checklist | dashboard card | `GET …/onboarding` | `customers`, `invoices`, `invitations`, `memberships` | `OnboardingApiTest`, `OnboardingChecklist.test.tsx` (4) | DONE | live: “5 of 5 done”, progressbar | — |
| Global search (Ctrl K) | `SearchDialog` | none (client-side) | — | no dedicated test found | DONE | live: `Control+k` opened a labelled dialog, focus inside, “1 résultat — Factures / Page” | static route search only |
| Theme switch | `ThemeControl` | none | — | not covered by tests | DONE | live: Light/Dark/System with `aria-pressed`; dark rendering observed | tokens in `theme/tokens.css` |
| Language switch + RTL | Settings + auth select | none | — | `messages.test.ts` (7), `f-locales.spec.ts` (NOT RUN) | DONE | live: `lang="ar" dir="rtl"`, translated nav/title; FR invoice list | 608/608/608 keys |
| Profile + password change | `/account` | `PATCH /api/me`, `POST /api/me/password` | `principals`, `security_events` | `ProfileApiTest`, `SessionRevocationTest` | DONE | endpoints present, password change ends other sessions | no email change (documented) |

### Route coverage and callers

- **Backend route with no frontend caller:** `GET /api/organizations/{organizationId}/dashboard` (49 routes total; `debug:router --format=json`). `/healthz` and `/readyz` are ops endpoints and correctly have no SPA caller.
- **Frontend API call with no backend route:** none found. Every literal path in `frontend/src/api/*.ts` maps to a routed operation (`/api/me`, `/api/me/organizations`, `/api/notifications…`, `/api/organizations…`, `/api/invitations/{token}`, `/api/auth/…`, `/api/ai/…`).
- **Contract parity:** `tests/Unit/Docs/OpenApiContractTest::testSpecCoversExactlyTheRoutedApiSurface` keeps `docs/openapi.yaml` equal to the router, and it passed as part of the 595-test run.

---

## 5. Command results

| Command (from `backend/` unless stated) | Result | Notes |
| --- | --- | --- |
| `composer validate --strict` | **pass** (exit 0) | `./composer.json is valid` |
| `php bin/console lint:yaml config --env=test` | **pass** | `All 17 YAML files contain valid syntax.` |
| `php bin/console lint:container --env=test` | **pass** | container linted successfully |
| `php bin/console debug:router --env=test` | **pass** | **49** routes |
| `php bin/console app:config:check` | **pass** | “Required configuration for this environment is present.” |
| `composer audit --locked` | **pass** | `No security vulnerability advisories found.` |
| `composer stan` | **pass** (exit 0) | `[OK] No errors`, `phpstan.neon.dist` |
| `composer cs` | **fail on this checkout** (exit 8) | `Found 251 of 260 files that can be fixed` — the documented CRLF artefact |
| `composer cs` on an LF copy (`/tmp/cs`, 257 files) | **pass** (exit 0) | `Found 0 of 257 files that can be fixed` → style is genuinely clean |
| `php bin/console doctrine:schema:validate --skip-sync --env=test` | **pass** | mapping correct; sync check skipped by design |
| `php bin/console doctrine:migrations:up-to-date --env=test` | **pass** | `Up-to-date! No migrations to execute.` |
| `php bin/phpunit` (working tree) | **fail** (exit 1) | `Tests: 599, Assertions: 4456, Failures: 1` — untracked `SummaryBudgetPolicyTest` (F-06); time 01:25.081 |
| `php bin/phpunit` excluding that untracked file (= committed baseline) | **pass** (exit 0) | `OK (595 tests, 4427 assertions)`, time 01:16.488 |
| Skipped tests | none reported | `--display-skipped --display-warnings --display-deprecations` printed no skip and no warning |
| `bash bin/test-counts.sh` | **exit 1** | Re-runs the suite; inherits F-06. It did print `frontend: 162 tests in 24 files (exit 0)` |
| `pnpm exec tsc -b` (from `frontend/`) | **pass** (exit 0) | no diagnostics |
| `pnpm run lint` | **pass** (exit 0) | `eslint .` silent |
| `pnpm test` | **pass** (exit 0) | `Test Files 24 passed (24)`, `Tests 162 passed (162)`, 40.86 s; no skips |
| `pnpm run build` | **pass** | built in 1.27 s; entry `index-LWNgnB9T.js` 384.75 kB |
| `pnpm run check:bundle` | **pass** | entry **375.7 kB** of 420.0 kB; total 492.6 of 800.0 kB |
| `pnpm exec knip` | **pass** (exit 0) | no output (nothing unused) |
| `node scripts/unused-i18n.mjs` | **pass** | `No unused dictionary keys.` |
| `pnpm audit` | **pass** | `No known vulnerabilities found` |
| `pnpm outdated` | informational | `knip 6.47.0 → 6.48.0`, `typescript 6.0.3 → 7.0.2` (the latter is a deliberate pin; see `AGENTS.md`) |
| i18n key-set comparison (script in `/tmp`) | **pass** | `en 608 · fr 608 · ar 608`, no missing/extra keys in any direction |
| `php bin/phpunit --group ai-eval` | included in the suite | `tests/Ai/Eval/*` ran inside the 595-test run with the fake provider |
| Coverage (`backend/bin/coverage-check.php`) | **NOT RUN** | no `pcov`/`xdebug` on this machine |
| `pnpm --dir e2e test` | **NOT RUN** | port 8000 occupied by a pre-existing server (§1) |

Three-decimal money spot check (live SPA, FR): invoice `2026-000002` renders `587,600 TND`; dashboard renders `TND 11,956.600`. Database totals for the same tenant reconcile exactly: sent 5 034.400 + overdue 6 922.200 = 11 956.600; paid 8 invoices / 14 828.850, chart by issue month Aug 7 450.000 · Sep 6 121.600 · Oct 1 257.250 (matches SQL).

---

## 6. Earlier-review recheck

| Ref | Claim to verify | Verdict | Proof |
| --- | --- | --- | --- |
| A1 | Pre-hijacking via unverified registration: verify-email must set the password | **FIXED** | `backend/src/Controller/AuthController.php:91-105` — the verify callback calls `setPasswordHash($this->passwordHasher->hashPassword($principal, $input->password))` then `markEmailVerified()` inside the token transaction; `VerifyEmailInput` requires the password; covered by `RegistrationFlowTest` / `AuthTokenRedemptionConcurrencyTest` |
| A2 | Sign-in lockout DoS: limiter must not key the short bucket on the address alone | **FIXED** | `backend/src/Security/AuthAttemptLimiter.php:66-72` — 5/min bucket is keyed `account|client`; the address-only ceiling is 50/h; `resetFor()` clears only the pair bucket after success |
| A3 | Invitation accept race and unique-violation handling | **FIXED** | `backend/src/Service/InvitationService.php:120-140` `refresh($invitation, LockMode::PESSIMISTIC_WRITE)` + re-check; `:168-173` `UniqueConstraintViolationException` → `409 invitation_unavailable`; `InvitationAcceptanceConcurrencyTest` |
| A4 | Limiter keys use `Principal::normalizeEmail` | **FIXED** | `AuthAttemptLimiter::emailKey()` (`:117-137`) calls `Principal::normalizeEmail()` with a lower-case fallback for invalid input; `RateLimitListener` reuses it |
| A5 | Timing difference between known and unknown addresses | **FIXED** | `AuthController` queues mail on `DeferredMailer`, flushed at `kernel.terminate` (`:44-58` comment, `:130-137`); `DeferredMailTest` |
| B1 | Tracked junk (`.pr-screenshots`, `AUDIT-REPORT.md`) | **FIXED** | `git ls-files` lists neither; `.gitignore` ignores both with a written reason |
| B2 | `LICENSE` present | **NOT FIXED** | No `LICENSE` anywhere in the tracked tree; only `docs/release/LICENSE-TODO.md`. `docs/limitations.md` says so openly |
| B3 | RLS state matches ADR 0003 | **MATCHES** | Live query on the migrated audit DB: `ai_requests`, `customers`, `invitations`, `invoices`, `notifications`, `organization_ai_settings` → `rowsecurity=t`, `forcerowsecurity=t`, 1 policy each; `principals`, `memberships`, `sessions` → `f`, 0 policies (the documented identity-table exception) |

### Fresh security pass

- **IDOR, every identifier route.** `tests/Integration/RouteIdorTest` enumerates the router and walks each route containing `{` as a foreign tenant (asserting `>15` checked routes) and anonymously; the run passed. Each tenant controller resolves the organization through `findActiveForPrincipalInOrganization`/voter and answers `404`. Live spot check: Viewer of Contoso requesting Northwind's `/customers` → `404`.
- **CSRF and Origin on unsafe routes, including AI.** `CsrfProtectionListener` exempts only `/api/auth/{login,register,verify-email,forgot-password,reset-password}` and applies the origin rule to every unsafe method before the firewall. Live: `POST /api/ai/guide` without a token → `403 csrf_invalid`; `PUT …/ai-settings` without a token → `403 csrf_invalid`; `Origin: http://evil.example` → `403`.
- **Mass assignment.** Controllers hydrate a closed input class per endpoint (`InputHydrator` + `App\Input\*`); no entity is populated from the request body directly (grep of controllers shows `hydrate(` calls only).
- **SQL built by string concatenation.** No concatenated SQL with request data; the only raw SQL is `RESERVE_SQL`/settle/release with bound parameters, and `q` search uses `addcslashes` + a bound `:q` parameter (`InvoiceRepository.php:74-78`).
- **Secrets in tracked files.** `HEAD:backend/.env` has empty `APP_SECRET`/`AI_API_KEY` and a local-only DB URL. History still contains the deleted `.env.dev` (F-07).
- **Security headers.** `nosniff`, `strict-origin-when-cross-origin`, `camera=(), microphone=(), geolocation=()`, `default-src 'none'; frame-ancestors 'none'`, HSTS only over HTTPS, `no-store, private` on authenticated API responses (`SecurityHeadersListener.php:51-63`); observed live on `/api/ai/status`.
- **Cookie flags.** Server-side session in PostgreSQL; `cookie_httponly: true`, `cookie_samesite: lax`, `cookie_secure: auto`, `cookie_lifetime: 0` (`config/packages/framework.yaml:21-25`); live `Set-Cookie: PHPSESSID=…; path=/; httponly; samesite=lax` over plain HTTP (no `Secure` — correct for the scheme).
- **Session fixation / invalidation on password change.** Login goes through Symfony's firewall (session id regeneration); password change and reset bump `principals.session_version` and `SessionVersionListener` invalidates a stale session body (`:55-63`), tested by `SessionRevocationTest`.
- **Rate limits on unauthenticated and costly routes.** `limiter.login_email`, `login_ip`, `login_account`, `resend_email`, `resend_ip`, `register_ip`, `forgot_password_email`, `forgot_password_ip`, `accept_invitation_ip`, `change_password_principal`, `invitation_resend`, `subdomain_availability`, plus AI `ai_principal` (20/5 min) and `ai_organization` (200/5 min).
- **Error responses leaking internals.** `ApiExceptionListener` maps everything to the `{error:{code,message}}` envelope; the live `500` I produced against the stale server returned `internal_error` with no trace, while the trace stayed in the server log. Good.
- **Logging of PII/prompts/answers.** `AiGateway` logs `organizationId`, `feature`, `tokens`, `latencyMs` only (`:139-146`); `ai_requests` has no prompt/answer column; failed sign-ins store a 16-hex fingerprint.

---

## 7. The missing Help button: diagnosis

**Symptom.** `AI_ENABLED=true` is set in `backend/.env.local` (verified: `AI_ENABLED=true`, `AI_PROVIDER=openai`, `AI_MODEL=openai/gpt-oss-20b`, base URL set), yet no assistant button appears.

**Captured response** — real `GET /api/ai/status` as the fixture Owner (`owner@example.test`), against a fully migrated database with fresh fixtures, before any opt-in:

```
HTTP 200
{"enabled":true,"organizationEnabled":false,"budgetRemainingTokens":0}
```

After opting the organization in through the UI (`Settings → AI assistance`: checkbox + budget `50000`, Save):

```
HTTP 200
{"enabled":true,"organizationEnabled":true,"budgetRemainingTokens":50000}
```

and the header immediately rendered `<button>Ask Keelson</button>`, the dashboard gained the “Workspace summary” card and the “Set up your workspace” checklist (5 of 5 done). Database after the save: `organization_ai_settings(enabled=t, monthly_token_budget=50000, period=2026-11)`, plus a `security_events` row `ai_enabled`.

**Which condition failed.** `enabled` (the deployment switch) was **true**; `organizationEnabled` was **false**. `useAiStatus.ts:41-43` (`canUseAi` = `ready && enabled && organizationEnabled`) and `AssistantLauncher.tsx:20` correctly render nothing. The cause is that the organization had **no `organization_ai_settings` row at all**, and `AiStatusController.php:62-66` treats “no row” as “not enabled, 0 tokens”. New organizations are created with no settings row on purpose (schema default budget `0`), and the fixture loader does not create one either — so a freshly seeded or freshly registered workspace always starts without the button.

**Is it a code bug or configuration?** Configuration/defaults, not a defect in the status endpoint: given a stored opt-in the endpoint and the UI behave exactly as documented. The plumbing was verified end to end — the failure was neither the env loading order (`.env` placeholders < `.env.local` < real environment), nor the cache, nor the organization the SPA is viewing. The one caveat worth knowing: the endpoint falls back to the caller's **oldest** membership when `organizationId` is omitted, while the SPA always passes the workspace in the route, so the UI's answer is for the workspace on screen (the fallback only affects API clients).

**Discoverability: partially solved.** The Settings page *does* show the section to Owner/Admin whenever the deployment switch is on (`AiSettingsSection.tsx:24-30` gated on `canConfigureAi`, not on the org flag), with a consent paragraph and the budget field. But nothing on the empty state says where to go: a first-time user sees no button, no hint, and no link. That is the real product gap (F-05).

**Exact steps that make the button appear**

1. `backend/.env.local`: `AI_ENABLED=true` (already true here). Leave `AI_PROVIDER=fake` for a local run if you do not want to spend credits; `openai` needs `AI_API_KEY` + `AI_BASE_URL`.
2. Sign in as an **Owner or Admin** of the workspace you are looking at.
3. Open **Settings → AI assistance**.
4. Tick **“Enable AI for this workspace”** and set **Monthly token budget** to a positive integer (e.g. `50000`; `0` blocks every call).
5. Save. `GET /api/ai/status?organizationId=…` must then return `organizationEnabled: true` and a non-zero `budgetRemainingTokens`; the header shows **Ask Keelson**.
6. Repeat per workspace — the opt-in is per organization, and switching workspace changes which tenant's settings apply.

---

## 8. Promised vs. present

| Promise | Source | Reality |
| --- | --- | --- |
| "43 HTTP routes" | `AGENTS.md` | 49 routed operations (F-09). |
| "PHPStan level 5, baseline", "170 tests", "RLS/invitations not implemented" | `backend/README.md` | Level 8 clean with no baseline, 595 tests on `HEAD`, RLS and invitations shipped (F-08). |
| Dashboard aggregates are server-side, four SQL statements | `docs/api-contract.md:514-540`, `docs/openapi.yaml:1183` | Endpoint exists and is tested, but the SPA computes the same cards client-side from ≤100 invoices (F-01). |
| "A summary that mentions a figure the request could not derive is replaced" | `docs/ai.md`, `docs/privacy-data-flow.md` | True for digit figures only; spelled-out numbers and qualitative claims pass (F-04). |
| "Seven Playwright journeys pass locally" | `docs/ROADMAP.md`, release report | Specs exist (`e2e/tests/*.spec.ts`, 7 files); **not re-run here** (§1). |
| "Coverage gate 80% runs in CI" | `AGENTS.md`, `docs/limitations.md` | Gate exists in `.github/workflows/ci.yml:190-192`; **not measured locally** (no pcov). |
| "A newer release of the frontend lockfile is safe" | Dependabot merges #37/#38/#39 | `pnpm test`, `build`, `audit`, `tsc`, `lint`, `knip` all pass on the merged tree — the dependency bumps are sound. |
| Billing / Stripe | `docs/limitations.md` | Correctly absent: no tables, routes or code. |
| Licence decided | `docs/release/LICENSE-TODO.md` | Still undecided; no `LICENSE` file. |

---

## 9. Things that are good and should not be touched

1. The tenant boundary: forced RLS on the six tenant tables plus application `404`s, verified live and by `RowLevelSecurityTest` — do not weaken the `404`-not-`403` rule.
2. The AI reservation is a single conditional `UPDATE … RETURNING` and is proven by a two-connection drill; keep it as one statement.
3. Money is integer millimes end to end (`InvoiceCalculator` has no float), and the client renders three decimals correctly in EN and FR.
4. Auth token redemption is one conditional `UPDATE`, single-use under concurrency, and verification now sets the password.
5. `CsrfProtectionListener` is global rather than per-controller; new endpoints inherit it. Do not add per-route exemptions.
6. i18n is genuinely complete: 608 keys × EN/FR/AR with no drift, RTL verified live.
7. The CI workflow actually gates merges (hygiene, types, tests, coverage, migrations, schema, e2e, aggregated audit).
8. `composer stan` at level 8 with the dead-code detector and no baseline is a real asset — keep it that way.

---

## 10. Open questions for the owner

1. **Licence** — MIT, Apache-2.0, proprietary, or "all rights reserved"? This blocks any public distribution and is the only `NOT FIXED` item from the earlier review.
2. **Hosting and TLS** — where does this run? `TRUSTED_PROXIES`/`TRUSTED_HEADERS` and HSTS depend on it, and the same-origin rule (ADR 0006) means the SPA and API must share a host.
3. **AI provider, region and retention** — which endpoint, which data-processing terms, and is zero-retention available? Also whether up to five customer display names may leave the process (summary feature) or the feature should stay off.
4. **RLS on identity tables** — keep the deliberate exception (`principals`, `memberships`, `auth_tokens`, `sessions`) or invest in a session-aware policy?
5. **Billing** — is Stripe still in scope? Nothing exists; the schema and the docs are consistent about that today.
6. **Frontend upgrade path** — stay on `typescript` 6.0.3 / ESLint 10 until `typescript-eslint` widens its peer range, or take the upgrade window?
7. **Coverage policy** — keep the 80% gate on `Service`/`Security`/`Controller` only, or extend it to `Ai`/`Summary` (the newest and least covered code)?

---

## 11. Proposed next sprint (proposal for discussion)

Ordered by value and risk; each item names the finding it closes.

| # | Item | Closes | Effort |
| --- | --- | --- | --- |
| 1 | Move the dashboard onto `GET …/dashboard`; keep the chart but feed it server aggregates; add a component test that asserts the call and a test with >100 invoices. | F-01 | M |
| 2 | Fix the auth-screen field layout (import `Field.css` from `AuthLayout`) and add a visual regression check for the four public screens. | F-02 | S |
| 3 | Make stat-card money responsive at ≤480 px; assert `scrollWidth <= clientWidth` in a component test at 390 px. | F-03 | S |
| 4 | Finish or revert the uncommitted cache-hit budget policy so the tree is green again (`SummaryBudgetPolicyTest`). | F-06 | S |
| 5 | Ground spelled-out numerals, or narrow `docs/ai.md` + `docs/privacy-data-flow.md` to "numeric claims" and add the corresponding test. | F-04 | M |
| 6 | Add an “AI is available — enable it in Settings” affordance for Owner/Admin; seed a disabled `organization_ai_settings` row in fixtures. | F-05 | S |
| 7 | Rotate `APP_SECRET` (and anything reused) for every value that ever lived in the removed `backend/.env.dev`; add the history scan to the hygiene job. | F-07 | S |
| 8 | Rewrite `backend/README.md` and the route count in `AGENTS.md` against the current tree; point at `bin/test-counts.sh` instead of quoting numbers. | F-08, F-09 | S |
| 9 | Give the dev Compose stack the owner/app role split so `/readyz` is meaningful locally. | F-12 | S |
| 10 | Remove the inert `login_throttling` block (or wire it and delete the duplicate). | F-11 | S |
| 11 | Localize the audit-log event types; move the remaining hard-coded colours into tokens. | F-14, F-10 | S |
| 12 | Run the Playwright suite in a clean environment (ports free, `pcov` installed for coverage) and record both results in the release report. | — (§1) | M |

Not proposed: new features, new dependencies, architecture changes. Everything above is repair or verification of what this repository already claims.
