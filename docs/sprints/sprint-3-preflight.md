# Sprint 3 Preflight — Sprint 2 Verification

**Run date:** 2026-11-15  
**Repository:** `AnouerChouikhgithub/Keelson`  
**Branch:** `feat/phase-7-invoicing`  
**HEAD at inspection:** `000618b5b6a815e8fc4eb5d3bdce53429acd0c2d`

## Worktree preservation

The worktree was already dirty before this sprint: 24 modified tracked paths and 7 untracked paths were recorded in the handoff state. The current source, docs, frontend, fixture, auth and helper edits were retained; no branch switch, stage, reset, clean, commit, push, merge, production database, or production credential was used. The database-backed checks ran against the labelled, volume-free, tmpfs PostgreSQL 16 helper at loopback only (`keelson-test-db`, `127.0.0.1:50682`, effective test database `keelson_test_test`). Its ownership labels and empty mount list were inspected before use.

The repository instructions and current documentation were inspected: `AGENTS.md`, `README.md`, `DEVELOPMENT_ROADMAP.md`, `NEXT-AGENT-PROMPT.md`, `how-to-run.md`, `backend/README.md`, `frontend/README.md`, `docs/api-contract.md`, and `docs/ui-spec.md`. The existing HTTP surface is session/OIDC plus the caller's organization list and tenant-scoped customer, invoice and invoice-audit routes; invitations have repositories but no HTTP workflow. There is no configured background processing subsystem.

## Sprint 2 acceptance review

| Requirement | Status | Evidence and limits |
|---|---|---|
| Frontend uses the real backend API for customers and invoices, not mock records in those lists | **VERIFIED** | `frontend/src/api/{client,customers,invoices,organizations}.ts` and the customer/invoice screens use the Symfony API; API-layer tests assert paths, methods, payloads and response handling. The documentation distinguishes other mock-only surfaces. |
| Frontend session is obtained from the backend | **PARTIALLY VERIFIED** | `GET /api/session` supplies the authenticated principal and logout CSRF token; logout integration tests assert the session becomes anonymous and protected routes redirect. Real OIDC sign-in/callback against Logto is blocked by missing test-provider configuration, so no live authenticated browser session was exercised. |
| Organization and membership context comes from the backend | **VERIFIED** | `GET /api/me/organizations` returns only the caller's active memberships on active organizations and includes the caller's role. `MeOrganizationsTest` and frontend organization API tests cover projection and behavior. Tenant selection in the SPA is only a request selector; the server reauthorizes each request. |
| Customer operations use actual API endpoints | **VERIFIED** | Customer list/create/read/update/delete routes are implemented and exercised by PostgreSQL HTTP tests. The frontend API tests cover request shape, success, validation and conflict responses. |
| Invoice operations use actual API endpoints | **VERIFIED** | Invoice list/create/read/update/send/mark-paid/delete routes are implemented and exercised by PostgreSQL HTTP tests. Frontend API tests cover the route and payload contract. |
| Loading, validation, error, conflict and empty states are handled | **PARTIALLY VERIFIED** | Source contains explicit resource loading/error states and form validation/conflict/empty UI; frontend API-layer and backend validation tests pass. There are no DOM/component tests, and authenticated screens could not be browser-exercised without OIDC credentials. |
| Client-supplied tenant IDs do not grant authorization | **VERIFIED** | `InvoiceVoter` resolves the server-authenticated `Principal` and active membership. Organization IDs are route selectors only. Tenant-scoped repositories bind the principal and organization; backend HTTP regressions cover foreign tenant IDs, query/body tampering, nested foreign customer/invoice IDs, missing membership and anonymous access. |
| Integration coverage exists for the frontend/API boundary | **VERIFIED** | Frontend Vitest API/session tests plus PostgreSQL backend HTTP, voter/role, tenant-scope, audit-atomicity and lifecycle tests exercise the boundary. This is not equivalent to a live-provider authenticated UI test. |

### Gate decision

**PASS — continue Sprint 3.** The API/session integration and server-side tenant authorization exist and have local automated coverage. No critical Sprint 2 defect was found that makes Sprint 3 unsafe or impossible. The missing real Logto test credentials and authenticated browser run remain an explicit external verification limitation, not a reason to invent a local authentication bypass.

## Sprint 3 audit findings and verified behavior

### Tenant boundary and roles

- The authenticated server-side OIDC principal is authoritative. Its issuer/subject is checked by the principal provider; email is profile data, not an authorization key.
- Every customer/invoice/audit endpoint applies `InvoiceVoter`. Active membership and active organization status are required. Inaccessible and unknown tenant resources return the existing non-enumerating `404` policy; same-origin/CSRF failures return `403`.
- Verified permissions from `InvoiceVoter`: `Owner`, `Admin`, `Member`, and `Viewer` can read; `Owner`, `Admin`, and `Member` can write; only `Owner` and `Admin` can delete customers/draft invoices. `InvoiceAuthorizationMatrixTest` exercises this policy, including viewer invoice read, denied edit/send/mark-paid and unchanged persisted state.
- Customer and invoice reads and writes scope both the route organization and authenticated principal. Customer single-row and locked lookups now carry the principal in the repository query; customer/invoice creation resolves an active organization membership server-side. Nested invoice customer references are resolved within the same organization. Request query/body organization values do not widen scope.
- Organization, membership and invitation repository reads are membership-scoped; invitations have no exposed HTTP controller/route in this repository. No asynchronous/background tenant worker is configured.

### Mutations, audit and lifecycle

- Customer/invoice handlers use `TransactionBoundary`; business mutations and required audit rows are persisted in the same unit of work and flush. Owned transactions roll back on failure. An active outer transaction is joined, not committed or rolled back by the handler.
- `AuditAtomicityTest` injects a database constraint failure for customer create/update/delete and invoice create/update/send/paid/delete; each test checks that neither half of the operation remains committed. The test failures intentionally produce critical database log entries while the suite passes.
- Invoice update/delete/send/mark-paid load the invoice under a PostgreSQL `FOR UPDATE` lock and re-check state while locked. GET reads do not write overdue state. Number allocation requires an active transaction and takes a per-organization/year PostgreSQL advisory transaction lock; the unique constraint remains the final guard. Lifecycle retries are no-ops and do not duplicate transition audit events.
- Customer deletion locks before checking invoice references. The foreign key remains a final defense and only FK violations are mapped to the existing `409` response.

### Authentication, logout and fixture safety

- Session cookies are configured HttpOnly, SameSite=Lax, Secure=auto, session-lifetime, with a one-hour garbage-collection lifetime. OIDC uses S256 PKCE and nonce verification in the configured bundle. Real provider behavior remains **BLOCKED** on a private test Logto client and completed callback flow.
- Logout requires an authenticated session and valid Symfony CSRF token; the integration test confirms a forged token leaves the session authenticated. Successful OIDC logout invalidates the local session and redirects through the discovered provider end-session endpoint; provider failure leaves the already-configured local `/` redirect. The request-supplied `_target_path` is explicitly tested not to alter the provider post-logout target. Provider exception messages/payloads are not logged.
- Fixture target validation uses effective DBAL connection parameters, including Doctrine's test database suffix. Regression coverage reproduces CI's base `keelson` URL plus `_test` suffix and checks refusal of production, non-PostgreSQL, non-loopback and ambiguous targets. The loader is idempotent and never deletes fixture data.

## Checks run for this preflight and Sprint 3 work

- `git status --short --branch`, `git log -1 --format=%H`, `git diff --check` — inspected before implementation; branch/HEAD recorded above; no whitespace errors.
- `bash bin/test-db.sh status` and `docker inspect` — confirmed the helper-owned PostgreSQL container, loopback-only port and no mounts; `.env.test.local` is ignored.
- Focused tenant/audit/authorization tests: `php bin/phpunit tests/Integration/InvoiceHttpApiTest.php tests/Integration/AuditAtomicityTest.php tests/Integration/InvoiceAuthorizationMatrixTest.php` — **PASS, 22 tests / 124 assertions**.
- Focused concurrency/lifecycle/logout/fixture tests: `php bin/phpunit tests/Integration/InvoiceNumberAllocationTest.php tests/Integration/InvoiceDraftConcurrencyTest.php tests/Integration/InvoiceAuthorizationMatrixTest.php tests/Integration/OidcSessionLogoutTest.php tests/Integration/SyntheticFixtureLoaderTest.php tests/Unit/EventListener/OidcEndSessionLogoutListenerTest.php` — **PASS, 26 tests / 179 assertions**.
- Full backend suite: `php bin/phpunit` — **PASS, 97 tests / 527 assertions**, exit 0, against the inspected disposable PostgreSQL helper.
- `composer validate --no-check-publish` and `php bin/console lint:container --env=test` — **PASS**.
- Frontend `tsc -b`, lint and 56-test Vitest suite passed in the Sprint 2 run on this worktree (recorded in `README.md` and `DEVELOPMENT_ROADMAP.md`); no frontend files were changed as part of this backend Sprint 3 verification.
- `doctrine:schema:validate --env=test` remains a **known failure**, documented before this run: DBAL 4.5 introspection reports a duplicate generated index name on `invoices`. Mapping validation passes; no migration/schema rewrite was attempted.
- Real Logto authorization-code sign-in/callback and an authenticated browser walkthrough remain **BLOCKED**: this checkout has no configured private OIDC test client. No secrets were read or reported.

The Sprint 3 prompt available in this task ended during Task B at “Ensure rollback”; this report records only the visible requirements and repository evidence, and does not invent unprovided acceptance criteria.
