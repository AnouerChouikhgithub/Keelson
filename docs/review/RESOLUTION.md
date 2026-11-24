# Resolution of the project-status audit

Source: `docs/review/PROJECT-STATUS.md` on `origin/audit/project-status`. That
branch is **not merged and that file is not edited here** — this is the answer to
it, on `fix/audit-findings`, based on `origin/main` at the commit it audited.

Every finding was treated as a claim, not a task: each was reproduced first. Where
the claim was wrong or already stale, the table says so instead of inventing a fix.
Where the fix would decide something that belongs to the product owner (a licence,
a host, a provider, a paid feature), it is left open and listed at the end.

Commits are one per finding, prefixed `fix(<area>): … (F-xx)`.

## Status

| ID | Status | Commit | Proof | Notes |
|----|--------|--------|-------|-------|
| F-01 | Fixed | `2fe79cf` | `backend/tests/Integration/DashboardApiTest.php` extended: paid figures aggregate by `paid_at`, issued/paid-rate by `issue_date`; `frontend/src/screens/Dashboard.test.tsx` renders the server's numbers and computes none. | The SPA no longer derives a headline from the page of recent invoices. |
| F-02 | Fixed | `8f28147` | `frontend/src/screens/auth/AuthFieldLayout.test.tsx`; `e2e/tests/g-layout.spec.ts` measures the real browser at 1440px and 390px in en/fr/ar — 24 cases, all green in the full run. | `AuthLayout` imports `components/Field.css`; the public screens no longer duplicate the rules. |
| F-03 | Fixed | `4b378d8` | `e2e/tests/h-money.spec.ts` writes a ten-million TND amount into every stat card at 390px and asserts `scrollWidth <= clientWidth` in en/fr/ar. | The regression was a fixed-width card, not the number format. |
| F-04 | Fixed | `76422b6` | `backend/tests/Unit/Summary/GroundingCheckTest.php`, `backend/tests/Ai/Eval/GroundingEvalTest.php`. Two parser bugs fixed: the identifier stripper now requires a digit (so `quatre-vingt-onze` survives) and the digit lookbehind is Latin-only (so `و203٫250` parses). | EN/FR/AR spelled-out numbers are read and vague quantifiers refused. Explicit limitations, written in the code: a French article counts as 1 only inside a composition; a percentage in words is checked as a plain value. |
| F-05 | Fixed | `23d8d80` | `frontend/src/features/ai/AiOptInHint.test.tsx`, `AiSettingsSection.test.tsx`; fixtures now ship the demo organization with AI **disabled**, so a fresh checkout shows the opt-in path rather than an empty section. | Reproduced on a seeded database: the section was hidden with no explanation when `AI_ENABLED=true` but the organization had not opted in. |
| F-06 | Fixed | `25ad21c` | `backend/tests/Integration/Summary/SummaryBudgetPolicyTest.php` — `OK` in the full suite. | The red test was caused by the fake provider's digest starting with eight digits (it looked like a number the grounding check had to find); the fixture was wrong, not the assertion, so `DIGEST_LETTERS` fixed the fixture. The cache-hit budget policy is now shipped green. |
| F-07 | Fixed | `9680e3f` | `bin/secret-scan.sh` (CI step) fails on a tracked file carrying a value; `docs/runbooks/secret-rotation.md` records the rotation; the tree is clean (`bin/secret-scan.sh` exits 0; the only allowed value is the documented `backend/.env.test` `APP_SECRET`). | Measured while writing the runbook: **rotating `APP_SECRET` does not sign sessions out** — sessions are database rows. `docs/deployment.md` and `docs/security.md` said otherwise and are corrected; the runbook's real mass sign-out is `DELETE FROM sessions`. |
| F-08 | Fixed | `d6552ca` | `backend/README.md`: the Tests section now lists the gates that run (level 8, no baseline) and points at `bash bin/test-counts.sh` instead of quoting a count; "Not implemented" no longer claims RLS, invitations or the SPA's sign-in are missing. | The claim "PHPStan level 5 with a baseline" is contradicted by `backend/phpstan.neon.dist:13` (`level: 8`) and by `composer stan` reporting `[OK] No errors` with no baseline file present. |
| F-09 | Fixed | `d6552ca` | `php bin/console debug:router --env=test --format=json` → 49 entries. `AGENTS.md` no longer quotes a count and names the groups the router actually has (`resend-verification`, `subdomain-availability`, `/api/ai/…`). | Together with F-08. `AUDIT-REPORT.md` still says "43 routes total", deliberately: it is the dated record of an earlier review, not living documentation. |
| F-10 | Fixed | `2446481` | `frontend/scripts/no-raw-colors.mjs` extended to the print stylesheet; new tokens in `frontend/src/theme/tokens.css` (`--color-danger-contrast` and the print set); `pnpm lint` clean. | Every colour now lives in the theme file. |
| F-11 | Fixed | `691de60` | `backend/tests/Unit/Config/SecurityThrottleConfigTest.php` asserts the firewall declares no `login_throttling` and that `AuthThrottleListener` is the only policy; `tests/Integration/LoginTest.php` keeps the 6th-attempt `429`. | The dead framework config was removed rather than left as a second, invisible policy. |
| F-12 | Fixed | `f321122` | `backend/bin/dev-reset.sh` re-applies per-database grants after the drop (via `dbal:run-sql`); `.gitattributes` pins `*.sh` and `docker/**` to LF. | Reproduced through the failure, not by reading: a reset left the app role without grants and the CRLF shebang broke the container init script on Windows. Throwaway compose project `keelson_audit_tmp` was used throughout; the developer's volumes were never touched. |
| F-13 | Fixed | `f984e80` | `e2e/tests/i-assistant-focus.spec.ts`, real keyboard: focus "Ask Keelson", Enter, type, Tab to submit, Enter, wait for the answer, then assert focus is inside the dialog, Tab four times inside it, Escape closes it and focus returns to the opener. **Reproduced first**: with the unrepaired component the journey fails at `focusInsideDialog → false`; with the fix it passes. | Cause: the composer and submit button were `disabled` while a request ran, so focus was handed to `<body>`. The textarea is `readOnly` now and a settled request returns focus to the composer if focus fell out of the dialog. |
| F-14 | Fixed | `f1d0bdb` | `backend/src/Audit/InvoiceAuditAction.php` (closed set, used by the controller, fixture loader and bulk command); `backend/tests/Integration/InvoiceAuditReferenceTest.php`; `frontend/src/screens/Audit.test.tsx`; `frontend/src/i18n/audit-labels.test.ts` reads the PHP file and fails if any constant lacks an en/fr/ar label **or** if a locale labels an action the backend cannot emit. | The server resolves `invoiceNumber` and `customerName` for the page in two queries, never one per row, and returns `null` when the row is gone. The raw code stays as a secondary line. `docs/openapi.yaml` and `docs/api-contract.md` updated. |
| Gap: e2e | Fixed | `ae7170d` | `e2e/playwright.config.ts` takes `E2E_API_PORT`, `E2E_WEB_PORT`, `E2E_API_ORIGIN`, `E2E_BASE_URL` (defaults 8000/5173) and moves the SPA's proxy target with the API port. Full suite on 8095/5199: **36 passed**, exit 0. | The first full run failed 9 of 36 — all in assertions this branch had written and never executed. Two were wrong assertions (see the commit), one was the new focus journey. |
| Gap: coverage | **Not run** | — | `php -m` has neither `pcov` nor `xdebug`; `backend/bin/coverage-check.php` needs one. | CI installs `pcov` and gates 80% on `src/Service`, `src/Security` and `src/Controller`. No percentage is claimed here. |

## Test counts, before and after

| | Before (`origin/main`) | After (this branch) |
|---|---|---|
| Backend (`php bin/phpunit`) | 595 tests / 4427 assertions | **634 tests / 4589 assertions**, exit 0 |
| Frontend (`pnpm test`) | 162 tests / 24 files | **185 tests / 29 files** |
| Playwright | never run | **36 passed** (1.6 min) |

The "before" numbers are the audit's own measurement of `HEAD`
(`PROJECT-STATUS.md` §3 and the gate table); they were not re-derived here.

## Checks actually run

Every line is a command whose output is quoted, on the final tree.

Backend, `cd backend`:

| Gate | Result |
|---|---|
| `composer validate --strict` | `./composer.json is valid` |
| `composer stan` | `[OK] No errors` (level 8, `shipmonk/dead-code-detector`, no baseline) |
| `composer cs` on an LF copy of `src tests migrations bin config public` | `Found 0 of 263 files that can be fixed`, exit 0. The CRLF working copy reports 251 files, which is the documented Windows artifact, not a style result. |
| `php bin/phpunit` | `OK (634 tests, 4589 assertions)` |
| `php bin/console doctrine:schema:validate --skip-sync --env=test` | `[OK] The mapping files are correct.` / `[SKIPPED] The database was not checked for synchronicity.` |
| `php bin/console doctrine:migrations:up-to-date --env=test` | `[OK] Up-to-date! No migrations to execute.` |
| `php bin/console lint:yaml config --env=test` | `[OK] All 17 YAML files contain valid syntax.` |
| `php bin/console lint:container --env=test` | `[OK] The container was linted successfully` |
| `composer audit --locked` | `No security vulnerability advisories found.` |

Frontend, `cd frontend`:

| Gate | Result |
|---|---|
| `pnpm exec tsc -b` | exit 0, no output |
| `pnpm lint` | exit 0 |
| `pnpm test` | `Test Files 29 passed (29)`, `Tests 185 passed (185)` |
| `pnpm run build` | `✓ built in 439ms`, entry chunk `386.01 kB` (gzip 116.34 kB) |
| `pnpm run check:bundle` | `entry: 377.0 kB (budget 420.0 kB)`, `Bundle is within budget.` |
| `pnpm exec knip` | exit 0, no output |
| `node scripts/unused-i18n.mjs` | `No unused dictionary keys.` |
| `pnpm audit` | `No known vulnerabilities found` |

Browser, `cd e2e`: `pnpm exec playwright test` on ports 8095/5199 against a
throwaway database (`keelson_audit_dev`) and a throwaway Mailpit container →
`36 passed (1.6m)`, exit 0. No test sleeps on a timer.

## What I could not verify

- **Coverage: NOT RUN.** Neither `pcov` nor `xdebug` is installed on this host, and
  installing a PHP extension is not a project change. CI installs `pcov` and runs
  `backend/bin/coverage-check.php` (80% for `src/Service`, `src/Security`,
  `src/Controller`); that number is unmeasured here.
- **Accessibility scan: NOT RUN.** An axe pass needs `@axe-core/playwright`, a new
  dev dependency; adding one needs approval, so the keyboard journeys (F-02, F-03,
  F-13) stand in for it: real focus, real layout boxes, real Tab and Escape.
- **The browser journeys ran as the schema owner**, not `keelson_app`, so forced
  row-level security was not exercised in the browser and `/readyz` answers `503`
  in that configuration (documented: it fails when the connecting role bypasses the
  policies). RLS itself is exercised by the integration suite, which asserts the
  platform and refuses to run without it.
- **Windows-only paths.** `composer cs` cannot be judged on the CRLF working copy;
  the LF-copy result above is the evidence. `bin/test-db.sh` was used for the
  integration database; the Compose `db_data` volume was never touched.
- **Performance and Lighthouse** were not re-measured; nothing here changes the hot
  path except F-14's two extra queries per audit page, which are bounded by the
  page size.

## Owner decisions left open

Not decided here, because they are product or commercial choices:

1. The licence (there is still no `LICENSE` file).
2. Hosting, TLS and the release target.
3. The AI provider and its key (the deployment switch and the `fake` provider are
   in place; no credential is committed).
4. Row-level security on the **identity** tables (`principals`, `memberships`,
   sessions). The six tenant tables are forced; the identity tables remain
   application-scoped, which is the remaining tenancy gap.
5. Billing, subscriptions, usage enforcement and Stripe webhooks.
6. The frontend upgrade past TypeScript 6.0.x (blocked by `typescript-eslint`'s
   peer range, not by the code).
7. The scope of the 80% coverage gate (which directories, and whether it becomes a
   merge blocker).

## New findings

Found while closing the list; none is a regression from this branch.

- **N-01 — `php -S` ignores exported environment variables on this host.** Its
  `php.ini` sets `variables_order=GPCS`, so `$_ENV` is empty and Symfony's Dotenv
  does not see an exported `DATABASE_URL`; `backend/.env.local` silently wins. The
  local instructions say "serve with `php -S …`" without the flag. Starting the
  server as `php -d variables_order=EGPCS -S …` fixes it. Worth a line in
  `backend/README.md`; not added here because it is host-specific.
- **N-02 — the assistant has no Enter-to-send.** Enter in the composer inserts a
  newline, so a keyboard user must Tab to the button. That is defensible (a long
  question needs newlines) but should be a deliberate choice; the focus journey
  documents the Tab path.
- **N-03 — e2e mutates the seeded organization's AI settings.** The fixture ships AI
  disabled (F-05) and the focus journey enables it through the UI, so a second run
  sees the checkbox already checked. The journey tolerates that, but the fixture
  state is no longer pristine after a suite run. A dedicated throwaway account would
  be cleaner.
- **N-04 — `AUDIT-REPORT.md` is stale by design** ("43 routes total") and could be
  mistaken for current. Annotating it as a dated record would remove the ambiguity.
