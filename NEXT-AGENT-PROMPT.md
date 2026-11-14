# Freebuff Prompt: Finalize the Keelson Local Demo

## Objective

Review and finish the current Keelson worktree so the mock frontend chapter is complete, the project is straightforward to run locally, and the documented checks reflect actual results. In addition to the account/notifications/settings work already in progress, make the top-bar search functional, show the Keelson logo in the browser tab, and configure local Docker services under the Compose project name `keelson`.

“Finalized” means the requested local demo experience is coherent and verified. Do **not** claim the full SaaS product is production-ready: backend/API integration, real OIDC sign-in, PostgreSQL RLS, billing, and most screens in the full UI specification remain outside the verified scope.

## Repository scan (2026-11-13; recapture before acting)

- Repository: `C:\Users\Anouer PC\Documents\Projects\Keelson`
- Branch: `feat/phase-7-invoicing`
- HEAD and upstream were synced at `dbfe11d9e33ca63a1cdcd14abbad5c9416a9ab9d`.
- The worktree is now **dirty** with current frontend implementation, backend transaction follow-up, documentation, and a Docker test helper. The status has changed since earlier prompts; capture `git status`, branch, HEAD, upstream, staged/unstaged diffs, and untracked paths before editing. Do not overwrite or discard any existing work.
- Tracked changes at the latest scan: `README.md`, `backend/README.md`, `backend/src/Controller/InvoiceApiController.php`, `backend/src/DataFixtures/SyntheticFixtureLoader.php`, `backend/tests/Integration/InvoiceDraftConcurrencyTest.php`, `frontend/README.md`, `frontend/src/App.css`, `frontend/src/App.tsx`, `frontend/src/index.css`, `frontend/src/main.tsx`, `frontend/src/mocks/dashboard.ts`, `frontend/src/mocks/organizations.ts`, and `how-to-run.md`.
- Untracked implementation paths included `backend/bin/test-db.sh`, `frontend/src/components/`, `frontend/src/i18n/`, `frontend/src/mocks/notifications.ts`, `frontend/src/notifications/`, `frontend/src/pages.ts`, `frontend/src/screens/`, and `frontend/src/settings/`. `NEXT-AGENT-PROMPT.md` is also an untracked handoff file. Confirm the current list; preserve all unrelated work and user assets.
- `.gitignore` was not modified at the scan. It ignores local backend environment files and allows only the intended shared defaults. Verify the ignore rule for `backend/.env.test.local` with `git check-ignore`; do not read or print any `.env*` contents, and do not stage secrets.
- The new frontend already contains account, notifications and settings screens, typed EN/FR/AR messages, local preferences, and RTL styling. Frontend build and lint passed during this scan. Backend Composer validation and the database-independent unit subset passed (29 tests, 72 assertions); touched PHP files passed syntax checks. `git diff --check` passed.
- Docker Compose currently defines PostgreSQL, Redis and Mailpit only; the frontend and Symfony app run on the host. Compose has a persistent `db_data` volume. No Compose services were running at the scan.
- `backend/bin/test-db.sh` is new and its documentation claims a full PostgreSQL run of 47 tests / 197 assertions. That full suite was not independently rerun during this scan. **Review and fix the helper's cleanup/ownership safety before using it.** Currently it removes a stopped container by name on `start`, stops/removes by name on `stop`, and deletes the whole marked `.env.test.local` even though `start` preserves unrelated existing keys. It must not delete or stop an unrelated container or erase a developer's pre-existing environment file.
- Current `frontend/index.html` points at `/favicon.svg`, which is still the purple Vite starter icon. The project's existing `frontend/public/Keelson.svg` is the intended logo asset; preserve it and use it for the browser tab.
- The top-bar search button is currently decorative/inert (`frontend/src/App.tsx`). Implement it; do not leave a visible control that does nothing.

## Required work

### 1. Finish and review the current UI work

- Inspect the current diff and complete the existing profile menu/account, notifications, settings, and typed EN/FR/AR localization work. Preserve the current mock-only boundary: no backend calls, auth bypass, or dependency additions.
- Keep translations complete for all currently rendered text, including app navigation, dashboard, onboarding, notifications, settings, accessibility labels, status text, feedback, and search. Arabic must set `lang="ar"` and `dir="rtl"` and work on narrow screens as well as desktop.
- Keep Light / Dark / System working on every implemented screen and persist the existing user preferences honestly as local browser state.
- Audit local-storage reads/writes. Validate parsed values at runtime, handle unavailable/corrupt storage predictably, and avoid silently accepting invalid preference shapes.
- Fix `frontend/src/i18n/format.ts` formatter caching: `formatNumber` accepts `maximumFractionDigits`, but the cache is keyed only by locale, so later calls with another precision can reuse the wrong formatter. Cache by locale and precision (or use another correct, simple approach); verify both 0- and 1-fraction formatting.
- Review RTL CSS for remaining physical left/right declarations that affect layout. Prefer logical properties, retain left-to-right behavior for EN/FR, and verify directional icons and mobile navigation.
- Do not build every placeholder product screen; keep their current status clear in the UI and docs.

### 2. Make Search work

- Turn the top-bar search control into an accessible command/search dialog with a labeled input, clear close behavior (Escape and close button), keyboard operation, visible focus, and a useful empty state.
- Support the displayed shortcut using both `Ctrl+K` and `Meta+K`; prevent browser defaults only when opening the app search. Show an accurate, localized shortcut hint.
- Search only existing local mock data and known pages (for example organizations, customers, invoices, notifications, and navigation destinations). Results must be localized and navigate to a relevant existing screen. Do not imply backend/global search or create fake results.
- Keep the feature dependency-free and compatible with EN/FR/AR, RTL, and all theme modes.

### 3. Use the Keelson logo in the browser tab

- Update `frontend/index.html` so the favicon resolves to the existing `frontend/public/Keelson.svg` rather than the Vite starter icon. Preserve the existing logo file.
- Verify the favicon URL returns successfully from the dev server and the document title/metadata identify Keelson. Remove or retain the old Vite favicon only after confirming references and avoiding unrelated asset cleanup.

### 4. Set up Docker with the project name

- Make the local Compose project name explicit as **`keelson`** and document/use commands with `docker compose -p keelson ...`, so managed service containers are visibly named with the project prefix (for example, `keelson-db-1`). Keep the existing service architecture: Compose currently provides PostgreSQL, Redis and Mailpit; do not silently add frontend/backend containerization or new dependencies.
- Inspect `docker compose -p keelson ps` first. The owner has requested local Docker setup; if the Docker engine is available, bring up the intended local development services with `docker compose -p keelson up -d`, then verify the project name, service status and health. Report clearly that the Compose database uses persistent `db_data`.
- Never run `down -v`, delete/reset/replace the persistent volume, migrate an unknown database, or use the Compose PostgreSQL service for the full integration suite. Do not print credentials.
- Before using `backend/bin/test-db.sh`, fix its safety:
  - Use a unique container name or Docker labels to prove a container belongs to this test helper before reuse/stop/remove; fail on a name collision instead of `docker rm -f` on an unknown container.
  - Never stop/remove a container based only on its name.
  - Do not overwrite or delete a pre-existing `backend/.env.test.local`. Either refuse to start if one already exists, or preserve and restore it byte-for-byte; cleanup may remove only a file the helper itself created.
  - Ensure failed startup/migration paths leave a clear error and a safe cleanup path. Keep the test container volume-free and loopback-bound.
  - Confirm the generated env file is ignored by `.gitignore`; never display its values.
- Only run PostgreSQL tests against a positively identified throwaway container with no persistent volume, after the helper safety review. Record exact startup, migration, test and cleanup outcomes. If Docker cannot run safely, mark the full suite blocked rather than claiming the README's historical result as current verification.

### 5. Backend follow-up and fixture safety

- Review the current `InvoiceApiController` transaction-ownership changes and their integration tests; preserve the rule that a handler must not commit or roll back a caller-owned transaction. Keep row locking, authorization, tenant scope, and audit updates atomic.
- Keep synthetic fixture source in the exact path `C:\Users\Anouer PC\Documents\Projects\Keelson\backend\src\DataFixtures`. Do not move or regenerate it, and do not run fixtures against the Compose database.
- Review fixture changes for idempotency and expected status transitions. Do not modify migrations, auth, billing, API contracts, or tenant architecture outside directly necessary corrections.

### 6. Documentation and safe cleanup

- Reconcile `README.md`, `how-to-run.md`, `frontend/README.md`, `backend/README.md`, and the current status section of `DEVELOPMENT_ROADMAP.md` with the actual code and verification. Preserve historical audit details, but correct stale current-branch/HEAD/test claims. Distinguish current runs from historical test evidence and blocked checks.
- State clearly that the frontend remains mock-only and that Compose runs infrastructure services, not the web/API app containers.
- Inspect `.gitignore` as part of the final repository scan. Keep local secrets, generated test environment files, caches, build outputs, and dependencies ignored; do not broaden ignore patterns in a way that hides source/assets, and do not add or reveal environment values.
- Remove only clearly unused starter artifacts after checking all references. Preserve `frontend/public/Keelson.svg` and all existing user assets. No broad deletion or `git clean`.

## Verification and closeout criteria

1. `cd frontend && pnpm run build` and `pnpm run lint` pass; `git diff --check` passes.
2. Verify number formatting with both zero and one maximum fraction digit; verify stored locale and preference validation.
3. Browser-smoke-test Dashboard, Onboarding, Account, Notifications, Settings, search open/query/empty/result/navigation, and profile-menu keyboard behavior in EN, FR and AR, with Light/Dark/System and a narrow viewport. Confirm `lang`, `dir`, no console errors, reload persistence, and no clipped or reversed controls.
4. Verify `/Keelson.svg` (or its correctly chosen relative favicon URL) loads through Vite and is linked in the browser tab.
5. Check `docker compose -p keelson ps`, start the requested dev services if safe/available, and verify the resulting service names/status. Never remove the persistent volume.
6. Run `composer validate --no-check-publish`, PHP syntax checks for changed PHP, Symfony lint/container checks, and the database-independent PHPUnit subset. Run the full PHPUnit suite only after the isolated test helper's safety fixes and only against its disposable no-volume PostgreSQL instance; record actual test/assertion counts and stop only resources created by this run.
7. Review final `git status`, diff and staged paths. Preserve unrelated edits and user assets. The owner previously requested finalizing/pushing; after all gates pass, a focused Conventional Commit and a normal push to `feat/phase-7-invoicing` are authorized only if the remote has not diverged and every staged path is reviewed. Never force-push. Do not include this handoff prompt or unrelated/untracked files unless explicitly intended.

## Final report

List the exact reviewed/changed paths; report frontend build/lint, browser smoke, favicon, Docker project/container status, backend unit/full-suite and diff-check results; state any blockers without presenting them as passes; describe the test helper's safety behavior; and give the final branch/commit/push result if a push was safely completed. Clearly distinguish local demo completion from overall product/release readiness (real OIDC, billing, RLS, API integration, CI and unimplemented UI screens remain out of scope).

---

## Next prompt: Complete every roadmap phase and verify

This is the current authoritative task and supersedes earlier task-specific objectives in this file. Keep prior material only as historical context.

Finish all work in `DEVELOPMENT_ROADMAP.md`, Phases 0–9, and verify every applicable acceptance criterion with actual command/test output. The expected result is a complete, locally runnable project with every required screen and workflow working—not a plan, partial handoff, or list of future work. Preserve completed work, complete partial/not-started phases, run the full relevant checks, and update the roadmap and documentation to reflect current evidence. Do not call the project complete while any phase or required check is blocked.

### Roadmap status reviewed on 2026-11-14

- **Phase 0 — Environment/readiness:** previously verified; the 2026-11-14 roadmap evidence reports Docker services healthy and both frontend and Symfony startup responding. Recheck before relying on that environment.
- **Phase 1 — Requirements/domain/API contract:** verified.
- **Phase 2 — Backend domain model/migrations:** implemented and verified.
- **Phase 3 — Client shell/design system:** shell verified; the Invoices, invoice editor, Customers, Team, Billing, Organization settings, Platform admin, Public pricing and authentication screens remain unfinished.
- **Phase 4 — Organization onboarding:** the current interaction is present, but persistence, API integration, invitation delivery and authorization remain incomplete.
- **Phase 5 — Authentication/authorization:** local implementation/checks exist; real Logto login/callback/logout and request-level verification remain outstanding.
- **Phase 6 — Tenant-scoped data/migrations:** repository scoping exists; PostgreSQL RLS and complete tenant/API security gate remain outstanding. Reconcile this status with later Phase 7 API evidence rather than copying stale claims.
- **Phase 7 — Invoicing:** backend implementation and local PostgreSQL/API tests are reported verified; frontend integration, RLS, and real provider authentication remain limitations.
- **Phase 8 — Billing/usage:** scope is recorded, implementation has not started, and provider/pricing/limit decisions remain open. The owner's request authorizes implementing the roadmap's recorded **provider-neutral** scope; it does not select a payment provider or authorize live charges, checkout, or production webhooks.
- **Phase 9 — Hardening/CI/release readiness:** in progress; workflow, audit record and rollback/observability documentation exist, but frontend automated coverage, a hosted CI run and the full security regression suite remain open.

### Owner-reported failures from the latest manual test

- **Logout is completely nonfunctional.** The owner states that logout is not working at all. Treat this as the first release-blocking defect, not as a minor menu issue. Trace the full click-to-server flow (menu action, request method/URL, CSRF token, cookies/session, response/redirect, and protected-route behavior), identify and fix the root cause, and verify that the server invalidates the session. Do not show a success state or claim logout unless the server confirms it.
- **Invoice creation also failed.** The owner reports that new invoice creation did not work in the latest test. Reproduce the full browser/API flow with fixture-backed records, inspect browser/network and backend results, fix the root cause, and verify the invoice persists and appears in the list after reload.
- These are owner-reported failures and have not yet been independently reproduced in this task. Keep them marked unresolved until the fixes and repeat tests pass; a visible control, route, or API endpoint alone is not proof.

Treat these as the roadmap's recorded statuses, not proof of current behavior. Inspect the complete phase requirements and the current repository. The roadmap contains historical evidence and some older statements superseded by later phases; preserve history, resolve contradictions in current status, and mark a criterion complete only after verifying the current implementation.

### Required outcomes

1. **Scan and finalize the whole repository.** Before editing, inspect the complete current repository and every tracked, modified, staged, and untracked path relevant to the product: application source, APIs, entities, migrations, fixtures, security/configuration, dependencies/lockfiles, tests, CI, Docker, and all project documentation. Build a current inventory of implemented, broken, placeholder, and unverified behavior; preserve existing user work and assets. Then immediately fix the logout failure below before broad UI work, and continue implementing and verifying the remaining work—do not stop after an audit or plan.
2. **Complete all phases 0–9.** Preserve sound existing work; implement every unfinished or partial repository-local deliverable and acceptance criterion. Complete the product screens, backend/tenant security, provider-neutral Phase 8 behavior, and Phase 9 CI, regression checks, audit findings, rollback and observability. Keep phases integrated and consistent with their recorded contracts. Iterate on failures until all locally verifiable checks pass.
3. **Finish the whole UI.** Implement and connect every screen in `docs/ui-spec.md`: sign-in/sign-up/forgot password, organization onboarding, dashboard, invoice list, invoice editor, customers, team and roles, billing, organization settings, platform admin, and public pricing. In particular, finish usable Invoices, Customers, Team, and Billing screens; remove their coming-soon placeholders. Do not stop at visual shells: forms, lists, filters, actions, validation, loading/empty/error/success states, navigation, responsive behavior, accessibility, localization and theme support must work.
4. **Account menu and pages.** Beside the account owner's name, the three-dot menu must provide working destinations for **My Account** and **Account Settings**, plus a **Log out** action. Implement My Account and Account Settings as distinct, usable screens and connect their supported changes to persistent account/API state. Log out must call the backend's CSRF-protected OIDC logout, invalidate the server session, and return the user to the signed-out state; do not simulate logout by only clearing frontend state. Keep keyboard navigation, focus handling and localized accessible labels.
5. **Finish invoice creation end-to-end.** Implement a complete new-invoice flow backed by the existing tenant-scoped API: select a customer, set dates, add/edit/remove line items, calculate totals using the approved TND/millime policy, validate input, save a draft, preview it, and send it through the existing lifecycle where authorized. Handle API errors, loading and success feedback; verify the created invoice persists and appears in the list after reload. Add or adjust backend endpoints only when the UI requires them and preserve the Phase 1/7 contract, tenant isolation, audit, and transaction rules.
6. **Use persisted fixture-backed data.** Add/extend safe, idempotent development fixtures for organizations, memberships/roles, customers, invoices and any required billing examples. Connect screens to actual backend API responses and persist user actions through the API; do not use in-memory-only screen datasets or hardcoded records as a substitute for application data. Keep fixture identities synthetic, seed only an explicitly confirmed local development database, never bypass authentication/authorization, and preserve the existing fixture loader's production and database safety guards.
7. **Local startup:** make the frontend start with `npm start` from `frontend/`, Symfony start with `symfony serve` from `backend/`, and PostgreSQL run through the existing Compose setup. `frontend/package.json` now has a start script; `pnpm-lock.yaml` remains the existing lockfile and there is no `package-lock.json`. Verify the current install/start workflow and document it without changing dependency versions or silently replacing/desynchronizing lockfiles. Do not add dependencies without approval.
8. **Security and data safety:** implement and test the roadmap's PostgreSQL RLS defense-in-depth where required; preserve application authorization and tenant scoping. Use only positively identified disposable, no-volume PostgreSQL containers for destructive/database test suites. Never reset/delete the persistent Compose `db_data` volume, use it for integration tests, or migrate an unknown database. Inspect the effective target and migration status before migrations.
9. **Authentication/provider gates:** use only a test Logto tenant and credentials configured privately outside tracked files. Never read or print secrets, ask the owner to paste secrets into chat, or claim real OIDC verification without a completed test login/callback/logout and negative cases. If credentials or operator setup are unavailable, finish independent work and report this gate as blocked; do not mark Phase 5 fully verified.
10. **Billing gates:** implement only the provider-neutral behavior and policies recorded in Phase 8, including required configuration with no invented price/limit defaults, test-only Pro activation, atomic usage enforcement, role boundaries, and signed/idempotent test events. Do not choose a vendor, add a provider SDK, implement live payment collection/checkout, or accept production webhook traffic without separate provider/merchant approval and verified eligibility.
11. **Phase 9:** finish CI for frontend build/lint/tests and backend validation/tests; add frontend automated tests; run dependency/security audits and record findings without unapproved remediation; document rollback/observability. Use no production data or credentials in CI and obtain a real CI run where repository access permits.
12. **Docs/status:** after implementation and verification, update the root `README.md` and `DEVELOPMENT_ROADMAP.md` with the finished screens and workflows, fixture/setup instructions, exact current check results and any remaining blockers. Keep historical records but distinguish historical from current evidence; update `how-to-run.md`, `frontend/README.md` and `backend/README.md` where needed. Do not mark any phase VERIFIED based on plans, prior runs, static claims, or an unrun check.
13. **Worktree:** first capture `git status`, branch, HEAD, staged/unstaged diffs and untracked paths. Preserve the current dirty worktree, all user assets, and unrelated changes. Do not stage, commit, push, or perform destructive cleanup.

### Verification and completion gate

- Run every phase's applicable acceptance checks and record exact commands, exit codes, test/assertion counts, and outcomes in the roadmap. At minimum include frontend install/build/lint, automated tests and browser smoke of all 11 screens; backend Composer/platform validation, PHP syntax, Symfony YAML/container/router checks, full PHPUnit against a positively verified throwaway PostgreSQL instance; tenant/RLS positive and negative tests; fixture idempotency/safety tests; billing limits/role/signature/idempotency tests; migration up/down on a disposable database; CI workflow validation/run; dependency audit; and `git diff --check`.
- Browser/API-test the full invoice creation path with fixture-backed records: create an invoice, verify its persisted detail and list entry after reload, and exercise validation, unauthorized/cross-tenant rejection, save-draft and send behavior.
- Explicitly reproduce and resolve the user's reported invoice-creation and logout failures. Retest clicking the invoice-create and logout controls in the browser, then verify persistence/list visibility and server-side session invalidation respectively; capture actual browser/network and backend evidence.
- Test the account owner's three-dot menu, My Account, Account Settings and logout, including keyboard access, CSRF protection, session invalidation, and denial of protected API access after logout.
- Verify `npm start` and `symfony serve` respond locally. Verify PostgreSQL Compose service health/status and `DATABASE_URL` connectivity without exposing credentials. Never claim Docker startup passed if the engine is unavailable.
- A phase is complete only when its required implementation and current checks pass. Record external requirements as explicit blockers with evidence and the exact operator action needed; do not convert blocked checks into passes or claim all phases verified while any remain blocked.
- Use `docs/ui-spec.md` for visual and interaction requirements, and the Phase 1 contract and Phase 8 policy for behavior/security. The latest owner instruction supersedes the older data-source text in the UI spec: screens must use backend APIs and persisted database fixtures. No shortcuts that weaken security or change product policy merely to make tests pass.
- Report each phase status, changed paths, all verification outcomes, blockers, and whether the entire roadmap is genuinely complete. Do not commit or push.
