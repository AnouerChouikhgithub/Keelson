# Final release readiness — Keelson 1.0.1

Date: 2026-11-17
Branch: `release/final-stabilization`
Code head: `9fee60e` (this document is committed on top of it)
Base: `main` at `954e474`
Pull request: https://github.com/AnouerChouikhgithub/Keelson/pull/27

## Decision

**READY WITH DOCUMENTED EXTERNAL DEPENDENCIES.**

The application code, its tests, its CI pipeline, its packaging and its
backup/restore procedure are verified. What remains is not code: TLS termination,
a real SMTP provider, a host with the documented environment, and one accepted
dependency advisory with no upstream fix. Every one of those is listed in
[Remaining external dependencies](#remaining-external-dependencies), and none of
them is a defect in this repository.

Severity legend used below: **P0** = data loss, auth bypass or a corrupt account;
**P1** = wrong behaviour a user or operator can hit, or a release gate that cannot
pass; **P2** = misleading behaviour, documentation that contradicts the code, or
missing operational tooling.

## Resolved defects

| # | Severity | Defect | Resolution |
| --- | --- | --- | --- |
| B1 | P0 | Verification and password-reset links could be redeemed twice: the token was read, then marked used in a second statement. A replay raced a legitimate request, and two concurrent requests could both change the account. | `AuthTokenRepository::consume()` performs one conditional `UPDATE ... WHERE used_at IS NULL AND expires_at > now() RETURNING principal_id` inside the caller's transaction; `AuthTokenService::redeem()` replaced the read-then-write pair. A rolled-back account change releases the token. Commit `d7006f4`. |
| B2 | P0 | The last-owner check read the roster and then wrote, so two Owners acting at once (demote + remove) could both pass and leave an organization with no Owner — an organization nobody can administer. | `MemberController` opens a transaction, takes `pg_advisory_xact_lock(hashtext('organization-owners:<id>'))`, and re-reads requester, target and Owner count before deciding. `MembershipRepository::acquireOwnerGuard()`. Commit `61a8328`. |
| B4 | P1 | Every unique violation during registration was reported as "address already registered". A registration that lost the subdomain race answered `201 verification_sent` even though its principal and organization had been rolled back — the user was told to verify an account that did not exist. | After rollback the address is re-read on the raw connection: a duplicate address still answers identically (no enumeration), a subdomain collision answers an explicit `409 subdomain_unavailable`, and nothing is created. Commit `3975571`. |
| — | P1 | `doctrine:schema:validate` aborted while introspecting: `An index with name "idx_6a2f2f95a5a280d" was already defined on table "invoices"`. PostgreSQL folds the uppercase name DBAL synthesises for `invoices.customer_id`, so the index was seen twice and no schema check could run. | Migration `Version20261117180000` renames it to `idx_invoices_customer`. The check now reports a diff (runtime-only objects have no ORM mapping) and `--skip-sync` — the CI gate — passes. Commit `2965c0e`. |
| B3 | P2 | Tabs disagreed: signing in or out in one left another showing stale tenant data, and an expired session surfaced as an arbitrary error instead of a signed-out state. | `BroadcastChannel` with a `storage` fallback announces a session change; each tab re-reads `GET /api/session`, a changed principal clears cached tenant reads, an active tab revalidates (throttled), and a `401` re-reads instead of guessing. Commit `e72d8f7`. |
| B5 | P2 | `VITE_API_BASE_URL` advertised a cross-origin SPA build. The API has no CORS and the CSRF listener requires a same-origin `Origin`, so such a build produced requests the browser always refused — a configuration that looked supported and never worked. | The variable and the Docker build argument are removed; the SPA calls its own origin. ADR 0006 records the decision. Commit `e72d8f7`. |
| F1 | P1 | Static analysis could not pass in CI: the dead-code detector read the dev compiled container, which CI never built. | CI warms both kernels; `phpstan.neon.dist` lists both paths. Commits `b332e68`, `47bebe8`, `f72286a`. |
| F2 | P1 | The e2e job pointed at a database named `keelson`, which the fixture guard correctly refuses for a non-local-looking name. | Uses `keelson_e2e_test`, migrations and fixtures run against it, and the API gets a reachable `DATABASE_URL` through `backend/.env.local` (the git-ignored per-environment file). Commits `b332e68`, `823d577`. |
| F3 | P1 | The audit job ran `composer audit` with no installed packages, and a frontend audit failure was swallowed by an earlier non-blocking step. | `composer audit --locked`, both pnpm audits with `if: always()`, and a final step that fails the job if any of the three reported a problem. Commit `b332e68`. |
| F4 | P1 | No CI gate covered the schema mapping or pending migrations, so a migration that was written but never applied could merge. | `composer validate --strict`, `doctrine:schema:validate --skip-sync` and `doctrine:migrations:up-to-date` are blocking steps. Commit `b332e68`. |
| F5 | P1 | The backend job's tests ran as the application role, which cannot install the trigger some integration tests need; 68 tests errored with `SQLSTATE[42501] insufficient privilege` the first time CI reached them. | PHPUnit runs as the owner role; production still runs as the application role. Commit `50c0886`. |
| F6 | P1 | The e2e boot probe left its API on port 8000, and Playwright refuses to start a server on a port that already answers, so the journeys never ran. | The probe stops the server it started. Commit `50c0886`. |
| F7 | P1 | The signup journey matched two elements in strict mode (`Add customer` in the header and in the empty state; the customer name in the row and in the toast), failing on timing. | Locators made unambiguous with a comment on why. Commits `22b13cf`, `9fee60e`. |
| — | P2 | No executable backup or restore procedure existed; the runbook described one in prose. | `backend/bin/db-backup.sh` and `db-restore.sh`: compressed custom-format dump, archive verification, SHA-256, retention, an in-place restore guard, `--single-transaction --exit-on-error`. Commit `e2a5eaa`. |
| — | P2 | `pnpm audit` failed on a high-severity advisory with no published fix, which would have kept the audit job permanently red. | The single GHSA (`GHSA-fc5q-39xc-vjpf`, `picomatch`, dev-only) is listed in `frontend/pnpm-workspace.yaml`; the audit prints `1 ignored: 1 high` and every other advisory still fails the job. Commit `47bebe8`. |
| — | P2 | `.pr-screenshots/` was untracked and unignored. | Added to `.gitignore`. |

## Regression tests added

- `backend/tests/Integration/AuthTokenRedemptionConcurrencyTest.php` — replay of a
  consumed token, expiry, rollback releasing the token, two independent connections.
- `backend/tests/Integration/LastOwnerProtectionTest.php` — demotion, removal, the
  admin boundary and the advisory lock (8 tests, 39 assertions).
- `backend/tests/Integration/RegistrationConstraintTest.php` — suffix allocation and
  an exhausted subdomain namespace.
- `backend/tests/Integration/AuthTokenServiceTest.php` — updated to the `redeem()` contract.
- `frontend/src/session/sync.test.ts`, `frontend/src/session/AuthProvider.test.tsx` —
  signal codec, cross-tab application, principal change clearing cached data.

## Verification actually run

Local, on this machine (Windows, PHP 8.5.12, Node 22.13, pnpm 12.14.0, Docker 29.5.2):

| Check | Command | Result |
| --- | --- | --- |
| Backend suite | `php bin/phpunit` | `OK (232 tests, 1382 assertions)` |
| Static analysis | `composer stan` | `[OK] No errors` (PHPStan level 8, no baseline, dead-code detector) |
| Style | `php-cs-fixer --dry-run` on LF copies of `src tests migrations bin` | `Found 0 of 162 files that can be fixed` |
| Composer metadata | `composer validate --strict` | `./composer.json is valid` |
| PHP advisories | `composer audit --locked` | `No security vulnerability advisories found.` |
| Config lint | `lint:yaml` (16 files), `lint:container`, `debug:router` | all `[OK]` |
| Mapping | `doctrine:schema:validate --skip-sync --env=test` | mapping correct, sync skipped |
| Migrations | `doctrine:migrations:up-to-date --env=test` | `Up-to-date! No migrations to execute.` |
| Frontend types/lint | `pnpm exec tsc -b`, `pnpm run lint` | clean |
| Frontend tests | `pnpm test` | `115 passed (115)`, 17 files |
| Frontend build | `pnpm run build`, `pnpm run check:bundle` | entry 358.6 kB of 420 kB, total 464.1 kB of 800 kB |
| Frontend dead code | `pnpm exec knip`, `node scripts/unused-i18n.mjs` | nothing reported |
| Frontend advisories | `pnpm audit --audit-level high` | exit 0, `1 ignored: 1 high`, 1 moderate |
| E2E advisories | `pnpm audit --audit-level high` (e2e) | no known vulnerabilities |
| Browser journeys | Playwright, 7 journeys, one worker, throwaway database on free ports | `7 passed (45.0s)` |
| Production compose | `docker compose -f docker-compose.prod.example.yml config` | renders `db`, `migrate`, `backend`, `frontend` |

CI, GitHub Actions run [44143196870](https://github.com/AnouerChouikhgithub/Keelson/actions/runs/44143196870)
on `9fee60e` — all four required jobs:

| Job | Result |
| --- | --- |
| Backend (static analysis, style, tests, coverage gate) | pass (49 s) — `OK (232 tests, 1382 assertions)`, coverage gate met |
| Frontend (types, lint, tests, build, bundle budget) | pass (38 s) |
| End-to-end journeys (chromium) | pass (1 m 31 s) — 7 journeys against PostgreSQL and Mailpit service containers |
| Dependency audit (blocking) | pass (33 s) |

Coverage is measured only in CI (`pcov`): the local machine has neither `pcov` nor
`xdebug`, so no local percentage is claimed. The 80% gate over
`src/Service`, `src/Security` and `src/Controller` is a blocking CI step and it
passed on this run.

## Backup and restore, verified

`backend/bin/db-backup.sh` and `backend/bin/db-restore.sh` were exercised against a
disposable PostgreSQL 16 instance:

- backup: custom-format dump, **35 846 bytes**, archive verified with
  `pg_restore --list`, SHA-256 printed;
- restore guard: the script refused the live database (`keelson_test_test`) as a target;
- restore: into `keelson_restore_proof_test` with `--single-transaction --exit-on-error`,
  reproducing **12 tables, 48 indexes, 8 migration rows**, and a real `principals`
  row with its display name;
- the migration checksum in the restored database matched the source.

Not exercised: off-host retention, encryption at rest, and a restore onto the
eventual production host.

## Remaining external dependencies

1. **TLS termination and host configuration.** The images speak HTTP; HSTS and
   `Secure` cookies depend on `TRUSTED_PROXIES`/`TRUSTED_HEADERS` being set by the
   host that terminates TLS. Verified only with a trusted-proxy header locally.
2. **A real SMTP provider.** Every message was caught by Mailpit. `MAILER_DSN` to a
   real provider was not exercised; delivery, bounce handling and sender reputation
   are unverified.
3. **The deployment host.** The production stack is proven with Compose on this
   machine, not on the target host or orchestrator.
4. **`picomatch` advisory `GHSA-fc5q-39xc-vjpf`.** Dev-only, unreachable from the
   shipped bundle, already at the newest release (4.0.7), and the advisory lists no
   patched version. Accepted in `frontend/pnpm-workspace.yaml` and re-checked when a
   fix ships.
5. **A licence decision.** No `LICENSE` file exists; the terms are the owner's call.

## Known limitations, accepted (not defects)

- No PostgreSQL row-level security: isolation is enforced by repositories and
  voters. ADR 0003 rejects RLS for 1.0.x; the privilege split that a future RLS
  migration needs is already in place. Cross-tenant behaviour is covered by
  `RouteIdorTest` and `TenantScopedRepositoryTest`.
- No billing, no MFA/SSO, no email change, no session-management screen, no metrics
  exporter, no queue worker — see [../limitations.md](../limitations.md).
- The full `doctrine:schema:validate` sync check reports a diff by design: the
  `sessions` table and the GIN/partial indexes have no ORM mapping.
- `composer cs` on a CRLF Windows checkout flags every file; it is clean on LF and in
  CI. Documented in `AGENTS.md`.
- Coverage was not measured locally (no `pcov`); CI measures and gates it.

Nothing in this release is reported as passing that was not executed. The one
deliberate exception is a dependency advisory with no available fix, which is
visible in the audit output rather than hidden.
