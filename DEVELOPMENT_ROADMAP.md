# Keelson Development Roadmap

This roadmap scopes the multi-tenant SaaS starter domain only. It does not claim verified tenant isolation or billing functionality. Phase statuses are scoped to the evidence described below; partial UI scope, mock-only workflows, and provider-integration gates are stated explicitly.

## Intro

Keelson is a multi-tenant SaaS starter project. The frontend is a Vite/React TypeScript app and the backend is Symfony 7.4. The current branch contains implemented organization/principal authentication and authorization, tenant-scoped data access, and a backend-only customer/invoicing demonstration. Billing, PostgreSQL RLS, real identity-provider E2E, and production readiness are not established; roadmap statuses and evidence below distinguish implemented local behavior from remaining gates.

## Project scope summary

### In scope:
- Tenant isolation and organization membership model (server-side RLS or application-layer scoping)
- Authentication and session management (JWT or session-based)
- Role-based access control and invitation lifecycle
- Invoice domain model and state lifecycle (as documented in ui-spec.md)
- API contract and migration foundation for tenant-scoped data
- Frontend design system integration (Tailwind, shadcn/ui concepts)
- Docker-based local development infrastructure

### Out of scope unless explicitly approved:
- Live Stripe payment processing or production billing
- Database row-level security policies without explicit implementation and tests
- Client-supplied organization IDs as authorization boundaries
- Any code or configuration that assumes PHP/Composer availability in the audit host
- Features depending on unverified API Platform, Doctrine ORM, or Messenger integration

## Roadmap overview

| Phase | Status | Focus |
|-------|--------|-------|
| 0: Environment and repository readiness | VERIFIED - PR #2 merged | Frontend build and Compose syntax verified; environment recorded; lint incompatibility deferred; ephemeral PostgreSQL target documented |
| 1: Requirements, domain model and API contract | VERIFIED - PR #5 merged | Record owner-approved tenant/principal model, customer CRUD, invoice lifecycle, and API boundaries |
| 2: Backend domain model and migrations | IMPLEMENTED AND VERIFIED | Doctrine entities, repositories, migration, and isolated PostgreSQL up/down evidence |
| 3: Client app shell and design system | VERIFIED — app-shell foundation; full UI-spec batch remains partial | React/Vite dashboard shell, semantic light/dark/system theme, and organization-aware navigation; visual-scope gaps remain documented; frontend lint passes as of 2026-11-13 |
| 4: Client workflows — organization onboarding | VERIFIED — accepted mock-only frontend scope; no backend/API | Mock organization creation/switching and invitation interactions are built and smoke-tested; auth, persistence, token delivery, and API remain unimplemented |
| 5: Authentication and authorization | IMPLEMENTED — local checks pass and the `/login` authorization redirect was verified against a stub provider; Logto end-to-end not verified | Symfony session firewall, Logto OIDC authorization-code flow with state/nonce/S256 PKCE, local Principal provisioning and membership-based authorization voter; external provider credentials and callback/logout verification remain required |
| 6: Tenant-scoped data and migrations | IMPLEMENTED — PostgreSQL repository scope tests pass; API/RLS gate remains | Active membership scopes organization, membership, and invitation reads; Phase 7 adds customer/invoice APIs; PostgreSQL RLS remains unimplemented |
| 7: Invoicing demonstration | IMPLEMENTED AND VERIFIED — local PostgreSQL/API tests pass; provider E2E and RLS remain unverified | Backend-only customer CRUD and draft → sent → paid lifecycle with derived overdue status, TND calculation policy, tenant scoping, and audit trail; no frontend or payment processing |
| 8: Billing and usage enforcement | SCOPE DECISIONS RECORDED — implementation not started | Tunisia-based seller; TND customer charges required; provider unresolved; Free + test-only Pro tier, unset configurable prices/limits, UTC invoice/active-seat caps, defined lifecycle/roles, and signed durable mock events; implementation approval still required |
| 9: Hardening, CI and release readiness | NOT STARTED | CI pipeline for build/lint/tests; security regression suite; documented rollback and observability |

Status evidence (updated 2026-11-13):
- **2026-11-13 local run/test readiness (current branch `feat/phase-7-invoicing`, HEAD `323c5bd3166f208463397f8ca05c07e8bd70269b`, all pre-existing work preserved, nothing staged or committed):**
  - Frontend toolchain: TypeScript was aligned from `7.0.2` to `6.0.3` (still `typescript-eslint@8.71.2`, whose published peer range is `>=4.8.4 <6.1.0`). No lint rule was suppressed and no dependency was added. `cd frontend && pnpm install --frozen-lockfile` — PASS (lockfile up to date).
  - `cd frontend && pnpm run build` — PASS (21 modules; exit 0). `cd frontend && pnpm run lint` — PASS (exit 0, no findings) after moving the exported theme context/hook out of `ThemeProvider.tsx` into `src/theme/ThemeContext.ts`, which resolved the previously reported `react-refresh/only-export-components` error; component behavior is unchanged.
  - `cd backend && composer validate --no-check-publish` — PASS. PHP syntax check across `backend/src`, `backend/tests`, `backend/migrations` — PASS (40 files). `php bin/console lint:yaml config --env=test` — PASS (13 files). `php bin/console lint:container --env=test` — PASS. `php bin/console debug:router` in `dev` and `--env=test` — PASS (15 routes, including the seven customer/invoice/audit API routes). The backend boots in `dev` without OIDC credentials; `/login` still needs real provider values.
  - `cd backend && php bin/phpunit tests/Unit/Entity tests/Unit/Security tests/Unit/Service tests/Unit/Util` — PASS (29 tests, 72 assertions). The PostgreSQL-dependent `tests/Unit/Repository/TenantScopedRepositoryTest.php` and the two `tests/Integration` suites were deliberately excluded from this run.
  - `docker compose -f docker-compose.yml ps` — BLOCKED (exit 1): `failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine`. Docker Desktop was not started, no container or volume was created or removed, and no database was selected, migrated, or queried.
  - Full `php bin/phpunit` — NOT RUN. It needs a running PostgreSQL 16 instance whose test database is disposable; the default test URL derives from the Compose `keelson` database (plus Doctrine's `_test` suffix), so it would target the persistent Compose volume. This remains BLOCKED until an isolated test database is provided; Phase 7's own 2026-10-24 PostgreSQL evidence is unchanged and is not re-verified by this run.
  - Local serving check: `php -S 127.0.0.1:<port> -t public public/index.php` reaches Symfony, but with no OIDC values a request to `/login` or `/api/...` returns HTTP 500 `Environment variable not found: "OIDC_CLIENT_ID"`. This is a configuration prerequisite, not a code defect, and there is no offline sign-in path; the docs now state it. The front controller must be passed explicitly, otherwise PHP's built-in server 404s on `/api/...`.
  - **OIDC sign-in redirect fixed (owner-approved dependency):** `symfony/translation-contracts:^3.8` was added to `backend/composer.json` (installed `v3.8.1`) because `GET /login` previously threw `LogicException: You cannot use the "Symfony\Component\String\Slugger\AsciiSlugger" as the "symfony/translation-contracts" package is not installed` from `Drenso\OidcBundle\OidcClient::generateCodeChallenge()`. Verification with placeholder credentials and a temporary local discovery document (subsequently deleted) gave `GET /login` — HTTP 302, `Location` containing `client_id`, `response_type=code`, `redirect_uri=.../login_check`, `scope=openid+profile+email`, `state`, `nonce`, `code_challenge`, `code_challenge_method=S256`. `composer validate --no-check-publish` — PASS; `AsciiSlugger` now loads. This does not verify a real Logto authorization-code callback, ID-token validation, session creation or logout; those still require a configured provider client and remain unverified.
  - Environment note: `composer require` initially failed because the locked `lcobucci/jwt` declares `ext-sodium`, which this PHP build did not load; that install used `--ignore-platform-req=ext-sodium` and no jwt version changed. On 2026-11-13 the owner approved enabling the extension, and `extension=php_sodium.dll` was added to the host's CLI ini `…/php/cli/php-phase7.ini` (libsodium 1.0.22). `php -m` now lists `sodium`, `composer install --no-interaction` exits 0 without flags, and `composer check-platform-reqs` exits 0 (previously 2). Backend checks re-run after the change: `composer validate` — PASS; PHP syntax (40 files) — PASS; YAML lint, container lint, 15 routes — PASS; unit subset — PASS (29 tests, 72 assertions). The ini is host configuration outside the repository, so a fresh machine still needs the extension enabled. `composer` also reported 13 security advisories across 9 packages (`composer audit`); none were addressed in this task.
  - Setup/test instructions were reconciled in `README.md` and `backend/README.md`; `docs/ORCHESTRATION_READINESS.md` is now marked as a superseded 2026-10-20 snapshot rather than current status.
- Status evidence recorded 2026-10-24:
- Phase 0 PR #2 is merged into `main` (merge commit `911b16ff667c9173ca74fb0972e750fc7fc67164`); its recorded environment evidence and blockers are now part of `main`.
- Phase 1 contract PR #3 is merged into `main` (merge commit `4225679eb3a7daccaffacf65bd521636b6cc78ab`); owner-approved customer CRUD and invoice lifecycle follow-up PR #5 is merged at `02278a6ceaca021889bcd2bb61bff86e46a127a3`. This is direct owner approval, not independent third-party review. Phase 1 is VERIFIED as a documented planning contract; no runtime security verification is claimed.
- Phase 3/4 frontend work merged in PR #8 (merge commit `120e8c5b6f9487cae95ad6f2872660cabe8d53d8`). `cd frontend && pnpm run build` — PASS; `git diff --check` — PASS. Browser smoke tests exercised dashboard/theme, organization creation with valid and duplicate subdomains, organization switching, and invitation add/resend/revoke; the final browser console had no runtime errors. This verifies the app-shell foundation and accepted mock-only onboarding UI scope—not every screen in `docs/ui-spec.md`, nor any API, auth, persistence, email/token delivery, or authorization behavior.
- `cd Keelson/frontend && pnpm install` — PASS: `Already up to date`; pnpm 12.14.0.
- `cd Keelson/frontend && pnpm run build` — PASS: Vite 8.3.4 build completed successfully (20 modules; exit 0).
- `cd Keelson/frontend && pnpm run lint` — FAIL, exit 2: `typescript-eslint@8.71.2` reports `typescript-eslint does not support TS 7.0.` No dependency/config workaround applied; decision: defer and retain as a blocker for a later scoped phase.
- `cd Keelson && docker compose -f docker-compose.yml config --quiet` — PASS, exit 0 with no output. Compose syntax validated only; no services started and no DB/volume touched.
- `cd Keelson && php -v` — PHP 8.5.12; `composer -V` — Composer 2.10.0; `pnpm -v` — 12.14.0; `node -v` — v24.13.0; `docker version --format "{{.Server.Version}}"` — 29.5.2.
- Disposable migration database decision: use isolated ephemeral PostgreSQL only; this phase did not provision/start it or connect to any database.
- Initial audit state before these follow-up changes: `feat/phase-4-mock-onboarding` at `origin/feat/phase-4-mock-onboarding`, with `DEVELOPMENT_ROADMAP.md` modified and `docs/ORCHESTRATION_READINESS.md` plus `graphify-out/` untracked. The latter two pre-existing untracked paths were left untouched.
- `cd Keelson && git rev-parse HEAD` before phase changes — `27bf422f8097e564027927f5e08c40215d2bc7b0`; branch was based on up-to-date `origin/main`.
- `git diff --stat` before phase changes — empty.
- Phase 2 evidence is from its isolated migration verification recorded below. The current Phase 5 checks use disposable PHP containers and an in-memory SQLite URL; no database service, migration, or persistent volume was started or modified for Phase 5.
- Phase 5: `composer validate --no-check-publish` — PASS; Symfony YAML lint (12 files) — PASS; `lint:container` — PASS; `debug:router` resolves `/login` and the generated logout route — PASS; `debug:firewall main --events` confirms a stateful firewall, OIDC authenticator, session fixation listener and provider logout listener — PASS; PHPUnit — PASS (19 tests, 36 assertions); PHP syntax checks across `backend/src`, `backend/tests`, and `backend/migrations` — PASS; `Principal` session serialize/unserialize smoke test — PASS. Symfony cache compilation was verified with dummy non-secret env values. A subsequent repeated `cache:clear` hit a Windows-mounted Docker cache directory cleanup race (`Directory not empty`); the earlier compile and container lint passed, and no cache files were manually removed. OIDC provider callback/sign-in/logout against a real Logto client was not run because no Logto client or credentials have been configured. The bundle's callback check path is intercepted by the firewall and is not a router-visible route.
- Phase 5 does not establish actual tenant isolation across API routes, PostgreSQL RLS, database-backed session storage, email delivery, or production readiness. Phase 6 implements application-layer scoped organization/membership/invitation repository reads and tests those queries against a disposable PostgreSQL database. Phase 7 adds tenant-scoped customer/invoice entities, repositories, APIs and tests; PostgreSQL RLS remains unimplemented.
- Phase 6 verification (2026-10-23): disposable PostgreSQL 16 test database `keelson_phase6_test` on loopback port 55433, separate from the retained Phase 2 database and Compose volumes; initial domain migration applied to the isolated test database; Doctrine mapping/schema validation PASS; PHPUnit PASS (22 tests, 54 assertions), including 3 PostgreSQL tenant-isolation integration tests; container lint PASS. No schema migration was required.
- 2026-10-24 verification run: `cd frontend && pnpm run build` — PASS. `cd frontend && pnpm run lint` — FAIL, exit 2: installed `typescript-eslint@8.71.2` does not support TypeScript `7.0.2` (historical; resolved 2026-11-13 by aligning TypeScript to 6.0.3). `docker compose -f docker-compose.yml config --quiet` — PASS; no services were started.
- No frontend test script is defined; no frontend test command was run.
- No CI workflow was found in the baseline repository inspection.

## Phase 0: Environment and repository readiness

### Status: VERIFIED - merged in PR #2
**Reason:** Required Phase 0 environment checks and blockers were reviewed in PR #2, which is merged into `main` at `911b16ff667c9173ca74fb0972e750fc7fc67164`. The lint incompatibility recorded below is historical: it was resolved on 2026-11-13 by aligning TypeScript to `6.0.3`, so `pnpm run build` and `pnpm run lint` both pass now. Verification does not mean every check passed.

### Goal and scope
Verify that the development environment is functional and the repository state is sound before implementing any features. Confirm frontend build, Compose syntax, and identify blockers that must be resolved first.

### Prerequisites and dependencies
- Node 24.13.0, pnpm 12.14.0 available on audit host
- Docker Compose available; `docker compose -f docker-compose.yml config --quiet` passes
- PHP 8.5.12 and Composer 2.10.0 are available on the current host; backend work still requires scoped verification and approved DB target
- Frontend: `pnpm install` and `pnpm run build` must pass without errors
- Backend: PHP >=8.2 and Composer must be available for any Symfony operations; current versions: PHP 8.5.12 and Composer 2.10.0

### Expected deliverables and files
- Verified `pnpm run build` passes (or documented failure reasons)
- Verified `docker compose config --quiet` passes
- Documented list of blocking issues (TypeScript 7 / typescript-eslint 8.71.2 lint incompatibility); PHP/Composer availability recorded
- Repository state recorded: `git status`, `git diff --stat`, `git rev-parse HEAD`

### Coding-agent prompt
```
Focus: Verify and document the development environment for Keelon.

1. Run `cd frontend && pnpm install && pnpm run build` and capture exit status and any errors.
2. Run `cd ../.. && docker compose -f docker-compose.yml config --quiet` and capture output.
3. Check `which php` and `which composer`; document presence/absence.
4. Run `git status --porcelain --ignored` and `git rev-parse HEAD` from the project root.
5. Run `pnpm run lint` and capture results.

Do not attempt any backend operations or database migrations. Do not install PHP or Composer. Do not modify any tracked files or commit changes. Do not assume any dependencies are available.

Record all command outputs exactly as they appear. Mark each check as passing or failing based on actual output, not intention.

Test cases:
- `pnpm run build` exits with code 0
- `docker compose config --quiet` produces no error output
- `which php` returns a path or "not found"
- `which composer` returns a path or "not found"
- `git status --porcelain --ignored` lists only expected untracked/modified files
- `pnpm run lint` exits with code 0 or documented failures

Expected results for every test case:
- Build either passes or failures are documented with exact error messages
- Docker Compose config either passes or syntax issues are listed
- PHP and Composer status is recorded (present/absent)
- Git status reflects only the quarantine moves and DEVELOPMENT_ROADMAP.md changes
- Lint results are documented (incompatibility or passing)

Acceptance criteria:
- All four environment checks are recorded with exact command output
- Blockers are documented and cannot be dismissed without evidence
- No phase in this roadmap is marked VERIFIED without repository evidence

Exit criteria and definition of done:
- Environment check results are persisted in the roadmap
- All blockers are listed with exact command output
- The roadmap acknowledges which phases depend on resolved environment issues

Unresolved business decisions:
- Frontend toolchain compatibility (TS 7 vs typescript-eslint 8): decision for this phase is to record/defer; no dependency change.
- Disposable DB target: decision is an isolated ephemeral PostgreSQL database for later migration checks; no database is started or used in Phase 0.
- PHP/Composer version selection remains open for project-wide support policy; host currently provides PHP 8.5.12 and Composer 2.10.0.

Verified evidence (phase 0): See `Status evidence` above. PR #2 is merged and the recorded Phase 0 verification is part of `main`.
```

## Phase 1: Requirements, domain model and API contract

### Status: VERIFIED - PR #5 merged
**Reason:** The owner-approved contract and customer/invoice scope are merged into `main` in PR #5 at `02278a6ceaca021889bcd2bb61bff86e46a127a3`. This verifies Phase 1's planning/documentation exit criteria only; it is direct owner approval, not an independent third-party security review, audit, or runtime security verification.

### Goal and scope
Define the tenant, principal, and organization model as the secure foundation for all subsequent work. Establish API contract boundaries before any implementation begins.

### Prerequisites and dependencies
- Phase 0 PR #2 merged into `main`; environment evidence and the lint blocker are recorded above.
- Product/security owner approval of the contract and customer/invoice scope was explicitly given by the user and is recorded below and in merged PR #5. No independent review or runtime security verification is claimed.
- UI invariants below are transcribed from `docs/ui-spec.md` as intended product requirements, not verified implementation.
- No database schema, authentication code, routes, or API implementations are assumed to exist.

### Expected deliverables and files
- This roadmap contains the tenant/principal model, API contract, owner-approved choices, customer CRUD and invoice lifecycle boundaries, and negative access-control test concepts.
- The user's approval as product and security owner is recorded below; no independent third-party review is claimed.

### Phase 1 owner-approved contract (implementation planning boundary)

#### Scope and evidence boundary
This is an owner-approved planning contract only. It defines intended responsibilities and wire-level shapes for subsequent implementation; it does not assert that any endpoint, identity provider, RLS policy, UI, or domain behavior currently exists. No SQL, migration, implementation code, credentials, or provider-specific OIDC settings are defined here. Customer and invoice API boundaries are included at the user's request. Billing, payment collection, and subscription APIs and fields remain excluded; the UI spec alone is not authorization to define billing APIs or persistence.

#### Screen-level invariants extracted from `docs/ui-spec.md`
1. **Sign in / sign up / forgot password:** provide the three authentication entry screens. Provider-driven recovery and exact session UX remain unspecified.
2. **Create organization onboarding:** collect organization name, subdomain with live advisory availability feedback, teammate invitations, and a plan choice. Availability is not a reservation or authorization guarantee.
3. **Dashboard:** left navigation for Dashboard, Invoices, Customers, Team, Billing, and Settings; organization switcher; TND revenue/outstanding/overdue/paid-rate KPIs, trend sparklines, revenue chart, recent invoice table, and Create invoice action.
4. **Invoices:** searchable table, status tabs (All, Draft, Sent, Paid, Overdue), sorting, row actions, badges, pagination, bulk selection, loading/empty states, and appropriate table density/hover behavior.
5. **Invoice editor:** customer and dates, line items add/remove, tax, discount, live PDF-style preview, Save draft and Send actions. Invoice totals, rounding, tax law, numbering, and state-transition semantics are not established by this visual spec.
6. **Customers:** customer table and customer detail drawer.
7. **Team and roles:** members table for Owner/Admin/Member/Viewer, role control, pending invitations with resend/revoke, and invite dialog.
8. **Billing:** Free/Pro/Business plan cards, current plan, invoice/seat usage meters, payment method, invoice history, and upgrade modal. This phase does not define billing, payment, plan, or entitlement APIs or data.
9. **Organization settings:** name, subdomain, logo upload, organization deletion with typed confirmation, and appearance toggle. Deletion retention/cascade is not defined here.
10. **Platform admin:** super-admin-only tenant table with plan/MRR/seats/status, MRR chart, impersonate and suspend actions. Authority, audit, impersonation, and suspension policies need separate approval; no such endpoint is defined.
11. **Public pricing:** Free/Pro/Business comparison and monthly/yearly toggle; no pricing values are specified in the UI spec.

**Shared presentation requirements:** every screen supports light and dark themes through central semantic tokens; desktop-first responsive layout remains usable on mobile; applicable data screens provide loading skeleton, empty, error/retry, and success feedback; controls are labeled and keyboard/focus accessible; mock data only until backend integration is approved. These are intended requirements, not claims of shipped behavior.

#### Tenant, principal, and authorization model (approved baseline)
- **Principal/user:** the authenticated human identity established by the OIDC identity provider. Contract-level identity reference is an opaque, stable `subject` (`sub`) plus display name and email for presentation/contact only. Email is not an authorization key. Provider issuer and subject together identify the external identity; account linking is a later implementation concern and may not merge identities by email alone.
- **Organization/tenant:** a tenant boundary with an opaque organization ID, display name, unique normalized subdomain, lifecycle status, and creation/update timestamps. Tenant membership is explicit; a user's existence or invitation does not grant membership.
- **Ownership:** each active organization has exactly one active Owner membership. Ownership transfer is not included in this contract and requires an explicit reviewed operation before implementation. Organization deletion is an explicit, separately authorized lifecycle action; cascade/retention behavior is unresolved and must be decided before migrations.
- **Membership:** association of a principal with one organization, a role (`Owner`, `Admin`, `Member`, or `Viewer`), status (`active` or `suspended`), and timestamps. Role grants are organization-scoped. A member may belong to multiple organizations; switching changes the selected organization context only and never grants access. Each active organization must retain at least one active Owner; member role changes and removals may not violate that invariant.
- **Invitation:** organization-scoped invitation with normalized recipient email, intended role (not `Owner`), issuer principal, random single-use token whose verifier is stored hashed, expiry 72 hours after issuance, lifecycle status (`pending`, `accepted`, `revoked`, or `expired`), and timestamps. A raw token is generated only on issuance/resend, dispatched through the approved secure delivery path, and never exposed by API responses or logs. Accepting consumes it atomically; revoke/accept/expiry are terminal for that token. Resend issues a new token and invalidates the previous one. Acceptance requires a signed-in identity with a verified email matching the invitation recipient; the OIDC provider must supply a trustworthy verified-email claim. Rate limits and delivery implementation are security requirements.
- **Roles:** Owner may administer organization membership/invitations and organization settings; Admin may administer members/invitations and ordinary settings but cannot transfer ownership or delete the organization; Member may use ordinary organization workflows; Viewer has read-only organization access. Role names alone do not grant permissions beyond the approved organization/member/invitation boundaries; each endpoint still requires server-side authorization.
- **Super-admin:** a separate platform-level authority, not a tenant role or organization membership. No self-service endpoint can grant it. Its identity source, assignment process, auditing, and impersonation boundary are unresolved; platform administration remains unavailable until those controls are separately specified and reviewed.
- **Tenant enforcement decision (owner-approved):** application-layer authorization and organization scoping on every request, plus PostgreSQL Row-Level Security as defense in depth. The rationale is to enforce business roles and tenant context in the application while retaining a database boundary against a missed or faulty application filter; neither layer alone is treated as sufficient. The server derives the active organization from authenticated membership/context, verifies membership and role for every operation, and establishes database tenant context transaction-locally. This is a target design only: database support/policies, connection-pool context reset, background jobs, and bypass roles must be designed and tested in Phase 2/5 before any security claim.
- **Identity/session decision (owner-approved):** OIDC sign-in with a server-managed session. Provider selection, OIDC client configuration, callback/CSRF details, session store, cookie attributes/lifetime, sign-up/account-linking behavior, and recovery flow are implementation details; configure them securely and test them before enabling login. No JWT bearer contract is introduced.
- **Invitation-token decision (owner-approved):** cryptographically random single-use token, store only a hash/verifier, 72-hour expiry; acceptance/revocation invalidates it and resend rotates it. Token format and entropy parameters must meet cryptographic randomness requirements; implement throttling and secure delivery before enabling invitations.

#### API contract boundaries (owner-approved; JSON over HTTPS)
All authenticated endpoints use the server-managed session. Request organization IDs are selectors, never proof of authority: every operation verifies authenticated principal, active membership, role, and tenant scope. Responses must not disclose another tenant's existence or data; inaccessible and nonexistent organization resources should use a consistent non-enumerating denial policy. Mutating requests require CSRF/origin protection appropriate to the eventual session implementation. Error schemas, pagination and exact per-resource role enforcement are implementation details to specify before coding; Owner/Admin/Member/Viewer boundaries below are the approved domain baseline.

1. **Create organization** — `POST /api/organizations`; authenticated principal only. Request: `{ "name": string, "subdomain": string }`. Response `201`: `{ "organization": { "id": string, "name": string, "subdomain": string, "status": "active" }, "membership": { "role": "Owner", "status": "active" } }`. Server normalizes/validates subdomain uniqueness atomically and creates exactly one Owner membership for the caller. No plan, payment, or billing property is accepted.
2. **Check subdomain availability** — `GET /api/organization-subdomains/{subdomain}/availability`; public, advisory response `{ "available": boolean }`. Must not reserve the value or replace uniqueness validation on creation. Rate limiting is required; availability is advisory and must not reserve a subdomain or disclose tenant-private data.
3. **List organizations / member switch** — `GET /api/me/organizations` returns only the caller's memberships, with `{ "items": [{ "organization": { "id": string, "name": string, "subdomain": string }, "role": Role, "membershipStatus": string }] }`. `POST /api/session/organization` request `{ "organizationId": string }`; response `{ "activeOrganization": { "id": string, "name": string }, "role": Role }`. Server verifies active membership before changing session context; unknown and unauthorized IDs do not reveal tenant data.
4. **List members** — `GET /api/organizations/{organizationId}/members`; response `{ "items": [{ "principal": { "subject": string, "displayName": string, "email": string }, "role": Role, "status": string, "joinedAt": string }] }`. Available only to an authenticated, active member of the same tenant; never perform cross-tenant lookup.
5. **Change member role** — `PATCH /api/organizations/{organizationId}/members/{subject}`; request `{ "role": "Admin" | "Member" | "Viewer" }`; response `{ "membership": { "subject": string, "role": Role, "status": "active" } }`. Owner/Admin-only; cannot assign Owner, change the acting user's own role, or remove/demote the last Owner. Server-side authorization is required; updates must preserve at least one active Owner.
6. **Remove member** — `DELETE /api/organizations/{organizationId}/members/{subject}`; response `204`; Owner/Admin-only. Cannot remove the last active Owner; self-removal/owner departure behavior must preserve the last-active-Owner invariant; historical-data retention is outside this API contract. No operation can change another tenant's membership.
7. **Issue invitation** — `POST /api/organizations/{organizationId}/invitations`; request `{ "email": string, "role": "Admin" | "Member" | "Viewer" }`; response `201` includes invitation ID, email, role, `status: "pending"`, and `expiresAt`, but never the stored token verifier or raw token. The raw token is generated and dispatched only through a separately approved channel, never returned by the API; delivery is outside the endpoint response and must be implemented via the approved secure delivery path. Owner/Admin only; cannot invite Owner or create duplicate active membership/invitation and duplicate active memberships are rejected.
8. **List invitations** — `GET /api/organizations/{organizationId}/invitations`; response `{ "items": [{ "id": string, "email": string, "role": Role, "status": string, "expiresAt": string, "createdAt": string }] }`. Never returns a raw token or hash/verifier. Accessible only to Owner/Admin members with invitation-management permission.
9. **Resend invitation** — `POST /api/organizations/{organizationId}/invitations/{invitationId}/resend`; no token in request; response returns pending invitation metadata with refreshed `expiresAt`. Owner/Admin only; invalidates any previous token and issues a new single-use token. Secure delivery and resend throttling are mandatory implementation controls.
10. **Revoke invitation** — `DELETE /api/organizations/{organizationId}/invitations/{invitationId}`; response `204`; Owner/Admin only. Revocation is idempotent; revoked tokens cannot be accepted.
11. **Accept invitation** — `POST /api/invitations/accept`; request `{ "token": string }` under an authenticated session; response `{ "organization": { "id": string, "name": string }, "membership": { "role": Role, "status": "active" } }`. Validate hash, pending status, expiry, and verified email match; atomically consume token and create membership, preventing replay and duplicate membership. Invalid, expired, revoked, used, and non-matching-email cases return a consistent non-enumerating denial.

12. **List customers** — `GET /api/organizations/{organizationId}/customers`; response `200`: `{ "items": [CustomerSummary], "nextCursor": string | null }`. `CustomerSummary`: `{ "id": string, "name": string, "email": string | null, "phone": string | null }`. Only authorized members of that organization receive data; pagination must not cross tenant boundaries.
13. **Create customer** — `POST /api/organizations/{organizationId}/customers`; request `{ "name": string, "email": string | null, "phone": string | null, "billingAddress": Address | null }`; response `201`: `{ "customer": Customer }`. `Address`: `{ "street": string, "city": string, "postalCode": string, "countryCode": string }`; `Customer` includes `CustomerSummary` plus `billingAddress`. `Address` follows the shape above; validation/normalization and duplicate rules are implementation details to define before coding.
14. **Read customer** — `GET /api/organizations/{organizationId}/customers/{customerId}`; response `200`: `{ "customer": Customer }`. Enforce organization scope and return non-enumerating denial for inaccessible or absent IDs.
15. **Update customer** — `PATCH /api/organizations/{organizationId}/customers/{customerId}`; request accepts mutable fields from Create customer; response `200`: `{ "customer": Customer }`. Verify route organization and customer ownership; invoice snapshots must remain historically consistent as specified below.
16. **Delete customer** — `DELETE /api/organizations/{organizationId}/customers/{customerId}`; response `204` when no invoice references exist; return a documented conflict while referenced unless a later approved archival rule supersedes this. Enforce tenant scope.
17. **List invoices** — `GET /api/organizations/{organizationId}/invoices`; optional status filter. Response `200`: `{ "items": [InvoiceSummary], "nextCursor": string | null }`. Only authorized members of that organization receive data; `InvoiceSummary`: `{ "id": string, "customerId": string, "status": "draft" | "sent" | "paid" | "overdue", "issueDate": "YYYY-MM-DD", "dueDate": "YYYY-MM-DD", "currency": string, "total": string }`.
18. **Create invoice draft** — `POST /api/organizations/{organizationId}/invoices`; request `{ "customerId": string, "issueDate": "YYYY-MM-DD", "dueDate": "YYYY-MM-DD", "currency": string, "lineItems": [{ "description": string, "quantity": number, "unitPrice": string }], "taxRate": string | null, "discountAmount": string | null }`. Response `201`: `{ "invoice": Invoice }` with `status: "draft"`. Request is tenant-scoped and verifies that the customer belongs to the organization. Amounts are represented as decimal strings; currency must be a valid supported ISO 4217 code. Tax and discount inputs are explicit decimal rates/amounts, and the server computes totals using decimal arithmetic. Before implementation, choose and document supported currencies, tax jurisdiction/rules, decimal precision and rounding, then cover those decisions with tests.
19. **Read invoice** — `GET /api/organizations/{organizationId}/invoices/{invoiceId}`; response `200`: `{ "invoice": Invoice }`; enforce organization scoping on both invoice and customer references.
20. **Update draft** — `PATCH /api/organizations/{organizationId}/invoices/{invoiceId}`; accepts the editable fields from the create-draft request only while the invoice remains `draft`; response returns `{ "invoice": Invoice }`. Sent/paid/overdue state changes are not permitted through this operation.
21. **Send invoice** — `POST /api/organizations/{organizationId}/invoices/{invoiceId}/send`; response returns `{ "invoice": Invoice }` with `status: "sent"`. The transition is atomic, idempotent, and restricted to an authorized organization member; any notification side effect must not duplicate on retry.

Invoice response representation: `{ "id": string, "customerId": string, "status": "draft" | "sent" | "paid" | "overdue", "issueDate": "YYYY-MM-DD", "dueDate": "YYYY-MM-DD", "currency": string, "lineItems": [{ "description": string, "quantity": number, "unitPrice": string }], "taxRate": string | null, "discountAmount": string | null, "subtotal": string, "taxAmount": string, "total": string }`. Amounts are decimal strings to avoid JSON binary floating point.
22. **Mark invoice paid** — `POST /api/organizations/{organizationId}/invoices/{invoiceId}/mark-paid`; request has no client-set timestamp; response returns the invoice with `status: "paid"` and server-recorded `paidAt`. This is an idempotent bookkeeping/demo state transition, not payment collection or evidence of funds received; tenant membership and an authorized role are required.
23. **Delete invoice draft** — `DELETE /api/organizations/{organizationId}/invoices/{invoiceId}`; response `204` for a draft. Sent/paid/overdue invoices cannot be deleted through this endpoint. Voiding/cancellation and corrections to sent invoices are outside this contract and cannot be implemented without a separately approved state transition.

Approved lifecycle: `draft -> sent -> paid`, with `sent -> overdue` derived when the due date has passed and `overdue -> paid` allowed. `overdue` is not set directly by clients; paid is terminal in this contract. No transition to draft or resend/reopen is defined. Sending and marking paid must be atomic and safe against duplicate requests; exact delivery behavior, operation permissions and snapshot immutability are implementation requirements. Currency scale, quantity precision, tax/discount formula and rounding remain domain implementation details to settle before coding.

**Not defined in this contract:** payment collection, billing/subscription/Stripe endpoints, organization deletion, ownership transfer, logo upload, password reset (OIDC-provider-dependent), platform admin/impersonation, or provider-specific OIDC settings. These are excluded from this approved contract; do not infer approval from UI copy.

#### Negative access-control cases to carry into planned tests
- Unauthenticated principal cannot create organizations, list private memberships, switch context, list members, or manage/accept invitations.
- Authenticated user cannot select an organization with no active membership, nor infer its existence from a different response shape/status.
- A user who belongs to organizations A and B cannot read or mutate A's members, invitations, customers, or invoices while B is active by substituting A's ID; every referenced customer/invoice must share the route tenant.
- Viewer and Member cannot issue, resend, or revoke invitations; Admin/Owner permissions must follow the approved role baseline and be enforced per operation.
- Admin cannot grant Owner, transfer ownership, delete an organization, or access platform-super-admin operations.
- No organization member can grant platform-super-admin authority; tenant roles cannot cross the platform boundary.
- Expired, revoked, already accepted, replayed, malformed, and wrong-verified-email invitation tokens cannot create membership; concurrent acceptance creates at most one membership.
- A token is never returned by invitation API responses, persisted in plaintext, or written to logs; resend invalidates the old token.
- Customer deletion cannot bypass tenant boundaries or remove a customer referenced by invoices.
- Invoice creation cannot reference a customer from another organization; sent/paid/overdue invoice state cannot be changed by editing a draft or direct client state assignment; deleting an invoice is limited to drafts.
- Duplicate send/mark-paid requests do not duplicate side effects or alter timestamps unexpectedly; paid is terminal, and overdue is derived rather than client-controlled.
- RLS must deny cross-tenant rows when application scoping is omitted or misapplied; application checks must deny when RLS is disabled/misconfigured. Connection reuse/background execution must not inherit another tenant's context.
- Changing the session active organization cannot elevate role; simultaneous requests/session rotation must not leak prior tenant context.

#### Approval record and implementation constraints
The user explicitly approved the Phase 1 contract as both product owner and security owner in this conversation, including the customer CRUD and invoice lifecycle scope recorded here. This is a direct owner approval record in this follow-up; it is not an independent third-party review, audit, penetration test, or runtime security verification.

Approved contract decisions:
- Tenant isolation: application authorization/scoping plus PostgreSQL RLS defense in depth.
- Identity/session: OIDC sign-in with server-managed sessions.
- Invitation token: random single-use token stored hashed, 72-hour expiry, rotate on resend.
- Customer API scope: list, create, read, update, delete; referenced customers cannot be deleted; any archival alternative requires a separate contract.
- Invoice API scope: list, create draft, read, update draft, send, delete draft, and mark paid; overdue is derived from sent status and due date; paid is terminal.
- No billing/payment-provider APIs are authorized in Phase 1.

Implementation constraints to apply in later scoped work: configure the selected OIDC provider and server-managed session safely; implement and test endpoint authorization and role checks; handle organization deletion/ownership transfer only under a separate approved contract; apply invitation rate limiting and secure delivery; enforce CSRF/origin checks; scope PostgreSQL RLS context transactionally and reset it on pooled connections; validate customer addresses and invoice/customer tenant references; use fixed-precision decimal arithmetic and currency minor units; preserve sent-invoice snapshots; make invoice sending and payment-state recording atomic/idempotent; and do not introduce void/cancellation transitions or payment collection without a separate approved contract.

### Coding-agent prompt
```
Focus: Record and review the owner-approved Phase 1 tenant, domain, and API contract for Keelson.

1. Preserve the approved choices in this roadmap: application authorization/scoping plus PostgreSQL RLS defense in depth; OIDC with server-managed sessions; random single-use hashed invitation tokens with a 72-hour expiry and rotation on resend.
2. Include the approved boundaries for organization, membership, invitation lifecycle, complete customer CRUD, and invoice draft/send/mark-paid lifecycle; do not add billing/payment APIs.
3. Keep implementation details explicit for the later phase that implements them; do not treat owner approval as proof of deployed or tested security controls.

Do not implement any code or migrations. Do not assume any database schema or that an endpoint, identity provider, RLS policy, UI, or behavior exists. Do not claim a design is independently security-reviewed until that review is recorded.

Scope limits:
- Define/review the model and API contract only; no code generation or implementation.
- No billing/payment/Stripe endpoints or fields are included without separate approval.
- Do not assume PostgreSQL RLS is already available or configured.

Do-not rules:
- Do not write migration files or SQL.
- Do not add auth middleware, JWT generation, dependencies, or provider configuration.
- Do not modify package manifests or `docker-compose.yml`.
- Do not create API routes or controllers.

Test cases:
- Tenant model lists user, organization, membership, role, and invitation fields.
- API contract specifies organization creation/switching, membership and invitation lifecycle, customer CRUD, invoice draft/send/mark-paid transitions and draft deletion, with request/response shapes.
- Owner-approved application checks plus RLS choice is recorded with rationale and explicit implementation caveats.
- Negative access-control cases cover tenants, roles, invitations, customers, invoices, and session context.

Expected results for every test case:
- Model document exists and covers all required fields.
- API contract includes org creation, member switching, invitation lifecycle, membership management, complete customer CRUD and invoice lifecycle, plus negative access cases.
- Tenant enforcement choice and rationale are explicitly owner-approved; implementation and security tests remain required before any runtime security claim.
- Negative access cases are identified and documented.

Acceptance criteria:
- Tenant model and API contract are approved by the user acting as product and security owner; this direct approval is recorded and is not represented as independent review.
- API contract is sufficient to begin Phase 2 planning after this follow-up PR merges; implementation-specific controls and tests are mandatory before the relevant endpoints ship.
- The owner-approved RLS plus application-checks decision and rationale are documented; implementation and security tests must verify it before any security claim.

Exit criteria and definition of done:
- Model and API contract documents are persisted.
- Owner's product/security approval record is persisted; implementation and runtime security verification remain outside Phase 1.
- Phase 2 is ready to begin after this contract update is merged; implementation-specific details and tests remain phase gates.

Owner approval recorded in this conversation:
- User approved as both product owner and security owner. This is not independent review or runtime security verification.
- Application authorization/scoping plus PostgreSQL RLS defense in depth.
- OIDC with server-managed sessions.
- Random, single-use, hashed invitation token; 72-hour expiry and rotate on resend.
- Complete customer CRUD and invoice draft/send/mark-paid lifecycle, including the draft-only deletion boundary; no payment collection or billing APIs.

Implementation gates for later phases:
- Select OIDC provider and settle account linking, verified-email and recovery behavior.
- Define exact role/action enforcement, ownership transfer, organization deletion/retention, invitation delivery/rate limits/duplicate handling, API error/pagination/CSRF conventions, and RLS transaction/context lifecycle.
- Specify customer address validation/duplicate handling and invoice currency/quantity precision, tax/discount calculation, rounding, snapshots, send side effects and void/cancellation before implementing those rules.

Verified evidence (phase 1): Original contract merged in PR #3 at `4225679eb3a7daccaffacf65bd521636b6cc78ab`; owner-approved customer/invoice API boundaries and approval record merged in PR #5 at `02278a6ceaca021889bcd2bb61bff86e46a127a3`. This verifies the planning contract only; no independent review or runtime security verification is claimed.
```

## Phase 2: Backend domain model and migrations

### Status: IMPLEMENTED AND VERIFIED
**Reason:** Doctrine ORM + Migrations, four tenant-domain entities with their repository mappings, four repositories and the initial migration are implemented. The migration was tested down and up in the isolated PostgreSQL 16 test database. Doctrine confirms the database schema is synchronized with current entity metadata; all ten PHPUnit tests pass. The disposable database container and anonymous volume are retained; no resets or data removal were performed.

### Goal and scope
Use the approved Doctrine ORM + Migrations strategy for the initial organization/principal/membership/invitation tenant schema. Test migrations up and down only in an isolated ephemeral PostgreSQL database before any production consideration.

### Persistence strategy decision
- **Selected by user:** Doctrine ORM + Doctrine Migrations, integrated with Symfony 7.4.
- **Database:** PostgreSQL 16; tenant data access must follow the Phase 1 application-scoping plus RLS decision. This phase creates the initial organization/principal/membership/invitation schema, not authentication middleware, API routes, business workflows, customer/invoice tables, or billing/payment schema.
- **Disposable database:** `keelson-phase2-db` runs the local `postgres:16-alpine` image, publishes only to `127.0.0.1:55432`, and uses an anonymous Docker volume rather than the persistent Compose `db_data` volume. The inspected database and role are both `keelson_phase2`; the migration history records only `DoctrineMigrations\Version20261022164016`. This is a local disposable test target, not production and not the root Compose service.
- **Dependencies:** Doctrine ORM 3, DBAL 4, DoctrineBundle 2.19 and MigrationsBundle 3.7 are declared/locked. Composer dependency resolution/install had completed in the preceding work; current Symfony boots with the installed vendor tree.
- **Migration:** `Version20261022164016` creates organizations, principals, memberships and invitations with indexes, uniqueness and foreign-key constraints. It has `up()` and `down()` implementations. Migration down and up both succeeded against the isolated test DB using a disposable PHP 8.5 CLI container with PostgreSQL support.

### Prerequisites and dependencies
- Phase 1 owner-approved domain model/API contract is merged into `main` (PR #5); use the user-approved application-scoping plus PostgreSQL RLS decision.
- PHP 8.5.12 and Composer 2.10.0 are available on the host. Since the host lacks OpenSSL and `pdo_pgsql`, database/migration checks were run in `php:8.5-cli-bookworm` with `pdo_pgsql` built inside the disposable container. PHPUnit 11.5 supports PHP 8.2+, and Doctrine dependencies were resolved with Composer's `platform.php=8.2.0` setting. The host runtime was not changed.
- The inspected disposable PostgreSQL 16 container uses an anonymous volume and the separate `keelson_phase2` DB/user; it is isolated from the root Compose service's named `db_data` volume. Its data and volume remain intact.
- Persistence dependencies are locked, mappings validate, and Doctrine reports the database schema synchronized.

### Expected deliverables and files
- Updated `composer.json` with approved persistence dependencies (or documented alternative)
- Migration files for tenant schema (tested against disposable DB)
- Repository pattern for tenant-separated reads/writes
- DATABASE_URL configuration for local development (no credentials in tracked files)

### Coding-agent prompt
```
Focus: Set up the backend domain model and migrations for Keelon.

1. Ensure PHP >=8.2 with OpenSSL and `pdo_pgsql`, and Composer are available; use the approved Doctrine ORM + Migrations strategy.
2. Add Symfony ORM pack, compatible Doctrine ORM/DBAL/Bundles, and PostgreSQL support to `composer.json`; resolve and verify the lockfile.
3. Run `composer install` and capture any errors or warnings.
4. Generate or write migration files for tenant/organization schema using `symfony console doctrine:migrations:diff` or equivalent.
5. Test migrations against a fresh, disposable PostgreSQL database (not the Compose production volume).
6. Record `DATABASE_URL` format: `postgresql://app:app@127.0.0.1:5433/keelson?serverVersion=16&charset=utf8` (local dev only).

Do not use production database data. Do not migrate any shared or production database. Do not assume Doctrine ORM is the only option; document the chosen approach. Do not create any API routes or controllers yet.

Scope limits:
- Only set up the persistence layer and migrations; no business logic yet.
- Do not add Stripe, payment, or invoice fields unless explicitly approved in Phase 1.
- Do not configure any authentication middleware.

Do-not rules:
- Do not migrate an unknown/shared database.
- Do not add production credentials to `.env` or tracked files.
- Do not assume PostgreSQL RLS is configured or effective.
- Do not create any API controllers or routes.

Test cases:
- `composer install` completes without fatal errors (or documented issues).
- Migration `diff` command runs and produces a migration file.
- Migration can be executed against a fresh disposable DB and rolled back.
- `DATABASE_URL` format is recorded and does not reference production credentials.

Expected results for every test case:
- Composer install succeeds or failures are documented with output.
- Migration file is generated and contains at least up() and down() blocks.
- Disposable DB migration up/down completes without data loss.
- DATABASE_URL is in the recorded format without production secrets.

Acceptance criteria:
- Approved persistence dependency is in composer.json.
- At least one migration file exists and is tested against a disposable DB.
- DATABASE_URL is configured for local development without production data.

Exit criteria and definition of done:
- Persistence dependency and migrations are documented and tested.
- Ephemeral PostgreSQL is provisioned and independently verified as isolated from the persistent Compose volume.
- Phase 3 can begin with frontend work starting independently.

Unresolved business decisions:
- Migration naming and versioning strategy
- DATABASE_URL management (local vs CI secrets)

Verified evidence (phase 2): `composer.json` and `composer.lock` include Doctrine ORM 3, DBAL 4, DoctrineBundle 2.19 and MigrationsBundle 3.7. Four entities and four tenant-filtered repositories exist; all entities explicitly map their repositories. `Version20261022164016` was successfully applied, rolled back (`--down`), and reapplied (`--up`) against the verified PostgreSQL 16.15 disposable database. `doctrine:migrations:status` reports one executed migration and no new versions; `doctrine:schema:validate` reports correct mappings and a schema in sync, and `doctrine:schema:update --dump-sql` reports nothing to update. PHPUnit passes: 10 tests, 11 assertions. All PHP files under `src`, `tests` and `migrations` pass syntax checks; `git diff --check` passes. The isolated container uses its anonymous volume (not `db_data`), `keelson_phase2` DB/user and host port 55432; it was retained without resetting or removing data.
```

## Phase 3: Client app shell and design system

### Status: VERIFIED — app-shell foundation only (full UI-spec screen batch remains partial)
**Reason:** The Phase 3 app-shell deliverable is implemented and verified: React/Vite dashboard, semantic light/dark/system theme, organization-aware navigation, and responsive shell. This status does not claim completion of the full `docs/ui-spec.md` screen batch: remaining named product screens are placeholders or absent, the lint incompatibility remains unresolved, and mobile page-level horizontal overflow is a known follow-up. Do not describe the whole visual screen batch as delivered.

### Goal and scope
Set up the React + Vite + Tailwind environment with shadcn/ui component patterns. Implement the Home screen and organization-aware screens as the client app shell.

### Prerequisites and dependencies
- Phase 0 environment verified (Node, pnpm, Docker Compose passing)
- Phase 1 domain model and API contract documented (may be preliminary)
- Tailwind/shadcn use or a documented equivalent design-system decision (the current shell uses authored semantic CSS tokens)

### Expected deliverables and files
- Vite project configured with Tailwind CSS, or a documented equivalent decision
- shadcn/ui components installed or equivalent design tokens defined
- Home screen component replacing the Vite starter `App.tsx`
- Organization-aware layout with navigation between screens
- TypeScript strict mode configuration verified (no `any` unless justified)
- Completion of every named visual screen remains tracked against `docs/ui-spec.md`; existing placeholder navigation alone does not complete those screens

### Coding-agent prompt
```
Focus: Set up the Keelon frontend app shell and design system.

1. Ensure `cd frontend && pnpm install` completes (document any errors).
2. Configure semantic design tokens; document a decision not to adopt Tailwind/shadcn rather than adding unapproved dependencies.
3. Replace `src/App.tsx` with a Home screen that matches the ui-spec.md mock (use mock data only, no backend calls).
4. Add organization-aware navigation between screens (mock presentation only; no auth or API).
5. Verify `pnpm run build` passes with TypeScript strict mode.
6. Verify `pnpm run lint` results are documented (known incompatibility or passing).

Do not implement any authentication guards or API calls. Do not assume any backend endpoints are available. Do not modify `composer.json`, `docker-compose.yml`, or any backend files. Do not claim any feature is "connected" without verified API evidence.

Scope limits:
- Only set up the app shell and design system components.
- Organization switching may be demonstrated with local mock data only; it must not be treated as authorization.
- Do not add any auth state or context providers.

Do-not rules:
- Do not implement JWT auth or session management in the frontend.
- Do not call any API routes or fetch backend data.
- Do not modify backend `composer.json`, `docker-compose.yml`, or Symfony config.
- Do not remove or alter the Vite starter configuration without documentation.

Test cases:
- `pnpm run build` passes with no TypeScript strict errors.
- `pnpm run lint` exits with code 0 or documented known issues.
- Home screen component renders without errors.
- Navigation links are present and point to expected routes.

Expected results for every test case:
- Build passes or lint failures are exactly documented.
- Home screen renders with placeholder content.
- Navigation contains at least organization-related links.

Acceptance criteria:
- App shell renders without errors.
- Design system is configured with semantic tokens or the component-library decision is documented.
- Any incomplete screens and verification blockers are stated explicitly.
- Home screen matches ui-spec.md intent (mock data accepted).

Exit criteria and definition of done:
- Frontend app shell is operational and buildable.
- Design system decisions and remaining UI gaps are recorded.
- Phase 4 UI can proceed as a mock-only prototype; real onboarding still depends on the authenticated, authorized API foundation in Phase 5.

Implementation decision and remaining scope:
- Current frontend uses React/Vite with authored semantic CSS design tokens; Tailwind and shadcn/ui were not added. No new dependency or component-library decision is asserted.
- Current dashboard, switcher, and onboarding demo use typed in-memory mock data. No API-driven state management or persistence is present.
- Current navigation uses local page state, not URL routes; route naming and routing library remain undecided.
- Dashboard and organization switcher are implemented. The other UI-spec screens remain placeholders or are not present; this is a partial shell, not completion of the full 11-screen visual delivery.
- Organization creation, invitation submission, and switching are local demo interactions only. They are not authenticated, persisted, or authorization boundaries.

Verified evidence (phase 3, 2026-10-22):
- `cd frontend && pnpm run build` — PASS; TypeScript project build and Vite production build completed (20 modules).
- `git diff --check` — PASS for the working tree at verification time.
- `cd frontend && pnpm run lint` — BLOCKED/FAIL, exit 2: `typescript-eslint@8.71.2` rejects TypeScript `7.0.2` (`typescript-eslint does not support TS 7.0`). No dependency workaround was applied.
- Browser smoke test at `http://localhost:5173/`: dashboard rendered with 4 metric cards and 5 recent invoices; onboarding navigation rendered its forms; organization switch to Carthage Studio updated the active label and breadcrumb; light/dark buttons updated the root theme and dark token background (`rgb(11, 15, 20)`). At 1440px no document overflow was observed. At 390px the invoice table has 576px of content in a 319px horizontally scrollable wrapper; the document also reported a 564px `scrollWidth` for a 390px viewport, so mobile page-level horizontal overflow remains a known issue to investigate.
- Browser console after a clean reload had no runtime errors; earlier HMR syntax errors during in-progress edits are not treated as final-state errors.
- No frontend test script exists. Lint and automated UI tests remain outstanding.
```

## Phase 4: Client workflows — organization onboarding

### Status: VERIFIED — accepted mock-only frontend scope (PR #8 merged)
**Reason:** The user-approved Phase 4 deliverable was the mock-only frontend workflow. PR #8 implements and browser-smoke-tests organization creation with local subdomain validation/duplicate checks, organization switching, and organization-scoped invitation add/resend/revoke interactions. This status is deliberately limited to that frontend scope; API route placeholders, invitation token issuance/delivery, persistence, authentication, server-enforced roles, and cross-tenant denial tests were not delivered and are not verified. The mock milestone is complete; backend onboarding/security work remains for the authenticated API phases.

### Goal and scope
Implement organization creation, switching, and member invitation flows (frontend + API). All API calls should enforce tenant isolation per the model defined in Phase 1.

### Prerequisites and dependencies
- Phase 3 app shell and design system operational
- Phase 2 backend persistence and migrations operational
- Authentication mechanism (from Phase 5, carried forward as placeholder)
- API base URL and client configuration

### Expected deliverables and files
- Organization creation form (frontend) + API endpoint (backend placeholder or minimal)
- Organization member invitation form with token generation
- Organization switching selector in the UI
- Invitation issuance, expiry, and revocation flows (server-enforced roles)
- Cross-tenant negative tests verified (attempting access as non-member is denied)

### Coding-agent prompt
```
Focus: Implement organization onboarding workflows for Keelon.

1. Implement an organization creation form in the React frontend (use mock data only).
2. Add an API route placeholder or minimal implementation for org creation (no auth guard yet).
3. Implement an organization member invitation form with token fields.
4. Add invitation issuance endpoint placeholder (server-enforced role logic documented only).
5. Implement organization switching UI component with placeholder API call.
6. Add cross-tenant negative test concepts: mock a non-member access attempt and verify denial is explicit.

Do not implement any real authentication checks. Do not connect to a real database. Do not assume any existing API routes beyond placeholders. Do not modify backend persistence or migration files.

Scope limits:
- Only implement the frontend forms and placeholder API routes.
- Do not implement member role assignment logic beyond documentation.
- Do not implement invitation expiry or revocation timers.

Do-not rules:
- Do not implement JWT validation or session checks in the API routes.
- Do not add any database queries or migration changes.
- Do not modify the frontend design system established in Phase 3.
- Do not claim any endpoint is "secure" without Phase 5 auth implementation.

Test cases:
- Org creation form submits without frontend validation errors.
- Org switching selector renders and contains options.
- Invitation form fields are present and labeled.
- Cross-tenant access attempt is explicitly documented as denied.

Expected results for every test case:
- Forms render and submit (to placeholder endpoints).
- Selector contains at least one option.
- Invitation form has token and email fields.
- Denial of cross-tenant access is documented in test notes.

Acceptance criteria:
- Organization onboarding forms are present and functional (placeholder mode).
- API route placeholders are documented.
- Cross-tenant denial is explicitly noted.

Exit criteria and definition of done:
- Onboarding forms and selector are implemented.
- API placeholders are documented.
- Phase 5 can begin with auth implementation using the established onboarding flows.

Unresolved business decisions:
- Invitation token generation strategy (server vs client)
- Org switching UI placement and behavior
- Role hierarchy (admin vs member vs super-admin) definition

Verified evidence (phase 4, 2026-10-22): PR #8 is merged at `120e8c5b6f9487cae95ad6f2872660cabe8d53d8`. `cd frontend && pnpm run build` — PASS; `git diff --check` — PASS. Browser smoke tests exercised valid and duplicate subdomains, local organization creation and switching, and invitation add/resend/revoke; no final browser console errors. Verified scope is frontend demo behavior only. No backend route, database persistence, authentication/authorization, email delivery, invitation token generation, or cross-tenant security test is evidenced.
```

## Phase 5: Authentication and authorization

### Status: IMPLEMENTED — local checks verified; Logto end-to-end pending
**Reason:** The backend now has a stateful Symfony security firewall using the Drenso OIDC bundle, Logto-compatible authorization-code configuration, provider-side state/nonce and S256 PKCE, session fixation migration, CSRF-protected local logout with optional provider end-session redirect, first-login Principal provisioning keyed by configured issuer + ID-token `sub`, and a membership/role voter. Unit tests and local Symfony configuration checks pass. The selected Logto Cloud application has not been configured and a real callback, login, provider logout, or request-level authorization flow has not been exercised; therefore provider compatibility and complete Phase 5 acceptance remain unverified. This is not yet a gate-cleared Phase 6 foundation.

### Goal and scope
Implement the Phase 1-approved OIDC sign-in with a server-managed session, then enforce tenant isolation on all API routes and apply role-based access controls per the approved model. This change establishes the authentication/session and reusable organization-voter foundation; it does not implement tenant-scoped API routes or PostgreSQL RLS.

### Prerequisites and dependencies
- Phase 2 backend persistence and migrations operational
- Phase 1 tenant model and API contract documented
- PHP >=8.2 and Composer available (or approved auth approach documented)
- API route structure from Phase 3 and 4

### Expected deliverables and files
- OIDC authentication integration and server-managed session lifecycle (no JWT bearer contract)
- Reusable membership-based organization voter for view, administration and owner-only deletion decisions; routes must invoke it with a server-loaded organization selector
- Authentication required for all routes other than the public OIDC entry point
- Request-scoped tenant isolation across every API route and database context remains unimplemented; deliver it before claiming tenant authorization complete
- Sign-out and session expiration handling
- Cross-tenant access denial verified in test concepts

### Coding-agent prompt
```
Focus: Implement authentication and authorization for Keelon backend.

1. Use the approved Logto OIDC authorization-code integration with Symfony server-managed sessions; provision local principals by issuer + ID-token subject without granting organization membership.
2. Protect application routes by default; permit the login initiation endpoint and let the OIDC firewall authenticator receive its `/login_check` callback.
3. Add an organization voter that loads active membership server-side and distinguishes view, Owner/Admin administration, and Owner-only deletion.
4. Add local sign-out with CSRF protection and session invalidation; enable provider end-session redirect only where the OIDC discovery document supports it.
5. Test first-login provisioning, mismatched subject rejection, unknown memberships, inactive membership, viewer/admin/owner boundaries, callback state/nonce, and cross-tenant access denial.
6. Verify the selected Logto Cloud client with an actual test login/callback/logout before calling provider compatibility complete.

Do not use production credentials or configure any real OAuth provider. Do not assume PostgreSQL RLS is configured; document the chosen isolation approach. Do not modify frontend components without corresponding auth state changes.

Scope limits:
- Only implement auth middleware and tenant isolation logic.
- Do not add any Stripe, payment, or invoice authentication.
- Do not implement refresh token rotation or password reset flows.

Do-not rules:
- Do not use production database data for any auth tests.
- Do not assume PostgreSQL RLS is effective without explicit migration and test.
- Do not add any password reset, recovery, or OAuth flows.
- Do not modify frontend design system or component structure.

Test cases:
- Auth middleware is present in Symfony kernel config.
- Organization voter uses authenticated principal + database membership and differentiates role permissions.
- Unauthenticated application requests cannot reach protected routes.
- Local CSRF-protected sign-out invalidates the server-managed session.
- Logto callback verifies issuer, audience, expiry, signature, state, nonce and configured S256 PKCE.

Expected results for every test case:
- Middleware file exists and is referenced in kernel config.
- No request-provided organization id can substitute for an active membership returned by the repository.
- Viewer and Member cannot administer; Admin cannot delete; only an active Owner may delete an organization.
- Symfony logout invalidates the local session; provider logout is attempted only through discovered OIDC end-session metadata.
- Callback failures do not authenticate.

Acceptance criteria:
- Symfony stateful OIDC firewall and server-managed session configuration compile and pass local checks.
- Authenticated access is the default; role voter unit tests pass.
- CSRF-protected sign-out invalidates local state.
- End-to-end Logto login/callback/logout and negative authorization tests pass against a configured test tenant before Phase 5 can be treated as fully verified.

Exit criteria and definition of done:
- Stateful authentication, local Principal identity and a reusable membership/role voter are implemented and documented.
- Unit/configuration checks pass; external IdP behavior and API/RLS authorization are separate gates.
- Phase 6 may begin only after a Logto test login/callback/logout is demonstrated and its tenant-data scope is explicitly separated from later RLS work; the current code is a local authentication foundation, not a tenant-safe API.

Unresolved business decisions:
- Exact Logto issuer, discovery URL, client id/secret, callback URI and post-logout URI (operator setup; secrets must remain local/private)
- Provider end-to-end verification, including verified email claim behavior, state/nonce, S256 PKCE and logout endpoint support
- Tenant context derivation and request-scoped PostgreSQL RLS lifecycle
- Full role/action matrix for every route, including owner retention and organization deletion rules

Verified evidence (phase 5, 2026-10-23): `backend/composer.json`/`composer.lock` include Drenso Symfony OIDC bundle v4.5.0 and Symfony Security/CSRF components 7.4; the bundle uses Symfony RequestStack sessions and implements code flow with state, nonce, issuer/audience/signature/time validation and configured S256 PKCE. `config/packages/security.yaml` uses a stateful firewall with session-fixation migration, protects all application routes except login initiation/callback handling, and enables CSRF-protected logout/session invalidation plus the bundle end-session listener. `OidcPrincipalProvider` provisions/reloads local `Principal` entities by the configured issuer and verified ID-token `sub`, without granting organization membership; `PrincipalVoter` requires an active repository membership and limits administration/deletion by role. PHPUnit passes: 19 tests, 36 assertions (including subject mismatch, unknown principal, no membership, inactive membership, Member/Viewer/Admin/Owner permission boundaries). Composer validation, 12-file YAML lint, Symfony container lint/cache compilation, and PHP source syntax checks pass in PHP 8.5 containers with dummy non-secret env values. `debug:router` confirms `/login`; `/login_check` is an authenticator check path, not a listed route. **Not verified:** a Logto tenant/client is not configured, so no real redirect/callback/session/logout, provider claim shape, or end-to-end cross-tenant API authorization has been tested. Consequently Phase 5 is implemented as a local foundation but not fully verified or sufficient by itself to declare Phase 6 security readiness.
```

## Phase 6: Tenant-scoped data and migrations

### Status: IMPLEMENTED — PostgreSQL repository scope tests pass; API/RLS gate remains
**Reason:** Organization lookup by principal, membership reads gated by active same-organization membership, and invitation reads gated to active Owner/Admin membership are implemented and verified against a disposable PostgreSQL database. This is application-layer query scoping only: no organization API routes, customer/invoice repositories, PostgreSQL RLS policies, or actual Logto-authenticated request flow are implemented. Phase 5's provider E2E remains explicitly deferred at the user's request.

### Goal and scope
Implement repository pattern for tenant-separated reads/writes. Verify cross-tenant access is denied at the data access layer. All queries must scope by authenticated principal membership.

### Prerequisites and dependencies
- Phase 5 auth and authorization operational (even if placeholder)
- Phase 2 migrations and persistence dependency operational
- Agreed tenant scoping approach from Phase 1 decision
- Repository pattern established or documented

### Expected deliverables and files
- Repository methods that scope organization, membership and invitation reads by an authenticated principal's active membership
- PostgreSQL integration tests for cross-tenant denial and role-gated invitation access
- Tenant scoping required for all future API queries; no customer/invoice routes exist yet
- Migration updates if tenant schema changes (none required for this repository-only scope)

### Coding-agent prompt
```
Focus: Implement tenant-scoped data access for Keelon.

1. Implement a repository pattern class for organization/member/invoice data that scopes all queries by tenant ID.
2. Ensure every data access method accepts a tenant principal parameter and adds a scope condition.
3. Document cross-tenant access denial: any query without valid tenant scope should fail or return empty.
4. Update migration files if the tenant schema has changed (re-run diff against disposable DB).
5. Document test concepts for negative access: mock a request without tenant context and verify denial.

Do not implement any business logic beyond data scoping. Do not assume PostgreSQL RLS is configured; document the approach. Do not modify API route handlers beyond adding tenant scope to queries.

Scope limits:
- Only implement the repository pattern and query scoping.
- Do not add any business logic or service layer changes beyond tenant filtering.
- Do not modify the frontend design system.

Do-not rules:
- Do not assume RLS is effective without explicit migration and test evidence.
- Do not modify API route return types or business logic.
- Do not change the database schema beyond tenant scoping conditions.

Test cases:
- Repository method accepts tenant parameter and adds scope condition.
- Cross-tenant negative test concept is documented (access without tenant context denied).
- Migration file is updated or generation is re-run against disposable DB.

Expected results for every test case:
- Repository class/method exists with tenant scoping logic.
- Scope condition is added to at least one query.
- Negative access concept is documented.

Acceptance criteria:
- Repository pattern is in place with tenant scoping.
- Cross-tenant denial is explicitly documented.
- Phase 7 can begin with invoicing implementation.

Unresolved business decisions:
- Repository pattern approach (Symfony Doctrine vs custom vs raw SQL)
- Tenant ID field name and consistency across entities
- Scope condition placement (WHERE clause vs JOIN)

Verified evidence (phase 6, 2026-10-23): `OrganizationRepository::findForPrincipal` returns only active organizations with an active membership for that principal; `MembershipRepository` scopes membership lookups and lists to an active organization and membership; `InvitationRepository` requires an active Owner/Admin membership in the requested active organization for both individual and list reads. PHPUnit on isolated PostgreSQL 16 passes (22 tests, 54 assertions, including 3 repository integration tests), Doctrine schema validation passes, and Symfony container lint passes. The disposable `keelson_phase6_test` DB was created only on a new loopback-bound temporary PostgreSQL container on port 55433; the existing Phase 2 DB and shared Compose database were not used. No schema changes were needed. **Still not implemented or verified:** PostgreSQL RLS, tenant-scoped customer/invoice entities or APIs, controller authorization flows, or actual Logto-authenticated HTTP requests. Phase 5 provider E2E is intentionally deferred per user instruction; do not call tenant isolation complete or Phase 6 security-ready on this evidence alone.
```

## Phase 7: Invoicing demonstration

### Status: IMPLEMENTED AND VERIFIED — local PostgreSQL/API checks pass
**Reason:** The owner-approved backend API scope is implemented on `feat/phase-7-invoicing`; unit, repository integration, authenticated HTTP, migration, schema, and container checks pass against a disposable PostgreSQL test database. This does not claim production readiness, PostgreSQL RLS, or real identity-provider authentication.

### Goal and scope
Implement the owner-approved backend customer/invoice API contract from Phase 1. This phase is backend-only (the user selected Backend API + data); the prior mock-UI coding prompt is superseded. State lifecycle follows the approved contract: `draft → sent → paid`, with `sent → overdue` derived from due date, `overdue → paid`, paid terminal, and no `void` transition. All customer, invoice, and audit access requires active membership in the route organization; request organization IDs are selectors, not authority. No frontend integration or payment processing is included.

### Prerequisites and dependencies
- Phase 6 tenant-scoped data and migrations operational
- Phase 1 domain model and API contract documented
- Phase 5 authentication and authorization operational for provider-backed sessions; local role policy is exercised by voter and HTTP tests, while real OIDC provider flow remains unverified
- Product owner selected an explicit minimal calculation demo policy: TND only, amounts in millimes (3 fractional digits), quantity up to 3 fractional digits, tax rate up to 4 decimal places of percentage, tax on subtotal less fixed discount, half-up rounding to the nearest millime; no country-specific legal tax assumptions or live collection
- No invoice numbering scheme had been approved: use a per-organization yearly `YYYY-NNNNNN` demo sequence guarded by a unique database constraint; concurrent collisions fail with 409 and may be retried
- The approved contract's immutable customer snapshot is captured on invoice create/update; sent invoice edits are rejected
- Audit events are transactionally recorded where applicable and the database trigger prevents their update/delete. Customer and draft-invoice deletion events are appended after entity deletion within the same database transaction.

### Expected deliverables and files
- Tenant-scoped Customer, Invoice, and append-only InvoiceAuditEvent entities, repositories, and reversible PostgreSQL migration
- Customer list/create/read/update/delete API; referenced customer deletion conflicts
- Invoice list/create-draft/read/update-draft/send/mark-paid/delete-draft API with status filtering and cursors
- Role-aware membership authorization, same-origin/CSRF mutation checks, non-enumerating cross-tenant denials, and composite database customer/organization FK
- Exact decimal calculator and documented TND demo policy; customer snapshot preserved on sent invoices
- Audit events for customer/invoice create/update/delete and invoice send/pay/overdue transitions
- Unit tests and isolated PostgreSQL plus authenticated HTTP functional tests; no frontend changes or live payment provider

### Coding-agent prompt
```
Focus: Implement invoicing demonstration for Keelon guided by ui-spec.md.

1. Review `docs/ui-spec.md` and record all invoice-related screen invariants (do not assume backend implementation).
2. Define customer and invoice models that are tenant-scoped (include organization ID field).
3. Define invoice state machine with explicit transitions (draft → sent → paid → void) and validation rules for each.
4. Add validation for totals, tax, and currency — define rules explicitly instead of treating mock data as business behavior.
5. Add audit trail logging for invoice creation, state changes, and deletions (include tenant context).
6. Implement frontend invoice form using mock data only; ensure tenant scoping is explicit in the UI.

Do not implement any live payment processing. Do not assume any Stripe integration unless explicitly approved. Do not claim any state transition is "valid" without explicit rules documented. Do not modify the frontend design system established in Phase 3.

Scope limits:
- Backend API and persistence only; do not add or change frontend screens or the design system.
- Do not implement Stripe, payment collection, notification delivery, or external provider integrations.
- Do not claim any invoice state is "verified" without acceptance criteria and tests.
- Do not implement PostgreSQL RLS in this phase; its absence remains an explicit defense-in-depth limitation for later hardening.

Do-not rules:
- Do not add any live payment processing or Stripe SDK.
- Do not modify the database schema beyond what is needed for customer/invoice tenant fields.
- Do not implement any email sending beyond documented placeholders.
- Do not claim any invoice behavior is "verified" without acceptance criteria and tests.

Test cases:
- Customer model has organization ID and required fields.
- Invoice state machine has at least 4 states with documented transitions.
- Validation rules for totals, tax, and currency are explicitly documented.
- Frontend invoice form renders with mock data and tenant scoping.

Expected results for every test case:
- Model definitions exist with tenant ID fields.
- State machine has documented transitions and validation per state.
- Validation rules are written as comments or config, not inferred from mock data.
- Frontend form renders without errors using mock data.

Acceptance criteria:
- Customer and invoice models are tenant-scoped.
- State machine and validation rules are documented.
- Frontend form renders with mock data and tenant scoping.

Exit criteria and definition of done:
- Invoice models, state machine, and validation rules are documented.
- Frontend form is operational with mock data.
- Phase 8 can begin with billing and usage enforcement.

Approved/selected demo decisions:
- Currency: TND only; values are decimal strings with exactly 3 fractional digits (millimes).
- Quantity: non-negative decimal string with at most 3 fractional digits; must be positive per line.
- Tax: optional percentage rate with up to 4 fractional digits; calculated against `max(0, subtotal - fixed discount)` and rounded half-up per invoice to the nearest millime. This is a demo formula, not a legal tax configuration.
- Discount: optional non-negative fixed TND millime amount; cannot exceed subtotal.
- Unit prices and quantities use no binary floating point; line extensions and tax use integer arithmetic. Reject excess precision, exponent notation, negative values, and totals beyond NUMERIC capacity.
- Invoice numbers: per-organization annual `YYYY-NNNNNN` demo sequence with unique tenant constraint; uniqueness conflict is surfaced as retryable 409.
- Audit: organization/actor/action/entity IDs and timestamp; no sensitive free-text or customer PII in audit metadata; append-only DB trigger.
- Statuses/transitions: `draft -> sent -> paid`; `sent -> overdue` when due date precedes current day, and `overdue -> paid`. Mark-paid records a bookkeeping timestamp, not proof of receipt. No `void`, reopen, or payment collection.
- Customer/invoice list use bounded page sizes; customer cursor is UUID ID ordering; invoice cursor is base64url JSON (`id`, `createdAt`) and remains tenant-filtered by query predicates.

Verified evidence (phase 7, 2026-10-24):
- Implementation files added/changed on branch `feat/phase-7-invoicing`; no merge or PR is claimed.
- PHP syntax checks across `backend/src`, `backend/tests`, and `backend/migrations` — PASS; final syntax verification was rerun after formatting.
- Focused invoice PHPUnit suite — PASS (16 tests, 70 assertions); full backend PHPUnit suite — PASS (38 tests, 124 assertions). HTTP coverage includes authenticated customer create/list, cross-tenant denials, validation, and rejection of invoice creation using a foreign-tenant customer.
- `composer validate --no-check-publish` — PASS; Symfony `lint:container --env=test` — PASS; Doctrine mapping/schema validation with `--skip-sync` — PASS. PHP used a temporary ini outside the repository enabling extensions; duplicate-module warnings remain but did not affect checks.
- Disposable PostgreSQL test database `keelson_phase7_test_test` at loopback `127.0.0.1:55436`; migration status confirms `Version20261022164016` and Phase 7 `Version20261023170000` executed and no migrations pending. Schema synchronicity validation was explicitly skipped; migrations and PostgreSQL repository/API tests exercised the schema. No prior Phase 2 or unrelated database was targeted.
- `git diff --check` — PASS after removing a trailing blank line. PHP-CS-Fixer was applied to Phase 7 backend source/tests; its final dry-run reported 0 fixable files. HTTP test sessions use Symfony `KernelBrowser::loginUser()` and test-only framework session configuration; fixture cleanup temporarily disables/re-enables the append-only audit trigger only while deleting random-suffixed test rows.
- Real Logto OIDC login, external browser auth, and PostgreSQL RLS remain unverified/not implemented; application-layer tenant scoping alone is not complete defense-in-depth.
- 2026-11-13 re-run on the same branch: database-independent checks still pass (`composer validate`, PHP syntax on 40 files, YAML/container lint, 29 unit tests / 72 assertions), but the PostgreSQL-backed repository and HTTP suites were **not re-run** because no disposable database was available (Docker daemon unreachable). The 2026-10-24 results above are therefore historical evidence, not today's confirmation.
```

## Phase 8: Billing and usage enforcement

### Status: SCOPE DECISIONS RECORDED — implementation not started
**Reason:** The owner has selected a provider-neutral, no-integration scope and recorded the plan catalog shape, subscription lifecycle, usage counters, authorization boundary, and test-event contract below. This is approval to document the scope only—not to add a provider SDK, create checkout or live webhook endpoints, or process payments. Any Phase 8 implementation still requires separate approval.

### Goal and scope
Define a provider-neutral monthly organization subscription with a Free tier and one Pro tier, backed by configurable limits. The seller's legal business is based in Tunisia, and customer charges must be denominated/presented in TND; merchant settlement currency and payout terms are separate and remain unconfirmed. No provider is selected until Tunisia merchant eligibility, TND charging, and recurring-card behavior are verified. In the absence of a provider or checkout, Free is the only live-assignable tier; Pro may be activated only by local/test fixtures until provider selection and implementation are separately approved. Keep prices and numeric limits unset (required configuration; no defaults); trial policy remains undecided. Measure monthly invoice drafts and active memberships, and enforce hard caps atomically. Record access behavior for cancellation/delinquency/downgrade and the security/test-event boundaries without implementing billing in this scope-definition step. No SDK, checkout, payment processing, or production credentials are in scope. Keep platform-admin support capabilities separate from tenant-admin authority.

### Prerequisites and dependencies
- Phase 7 local PostgreSQL/API verification complete; real provider OIDC authentication and PostgreSQL RLS remain limitations and should be accounted for before production use.
- Phase 1 domain model and API contract documented.
- Phase 5 authentication and authorization operational for local application policy; real OIDC provider flow remains unverified.
- Initial scope decisions are recorded below. A separate implementation approval is required before changing application behavior, schema, or provider integrations; remaining decisions are listed below.
- Any provider credentials must be provided/configured outside tracked files and must never be recorded in this roadmap.

### Expected deliverables and files
- Scope decision record: provider deferred; seller business country Tunisia; TND is mandatory for customer charges; no provider integration or live payment processing; no credentials in tracked files
- Subscription and entitlement policy: organization-owned monthly subscription with Free and one test-only Pro tier; prices and numeric limits are required configuration with no defaults; trial policy remains undecided. No automatic upgrade; immediate cancellation, no grace period, and immediate Free fallback for canceled/past-due subscriptions. Without an approved provider/checkout, only Free may be assigned in live use; Pro activation is limited to local/test fixtures.
- Provider eligibility gate: require evidence of Tunisia merchant onboarding, TND customer presentment, recurring off-session card charges (if required for paid renewals), signed webhook behavior, and settlement/payout terms before selection. Current docs make Flouci the first vendor to ask, not an approved choice: its [payment API](https://docs.flouci.com/api-reference/generate-transaction) documents TND/millime payments and recurring-payment docs exist, but its [error dictionary](https://docs.flouci.com/essentials/error-dictionary) includes card recurring not enabled/not implemented cases, so card autopay must be confirmed for this merchant. [Konnect's payment endpoint](https://docs.konnect.network/docs/en/api-integration/endpoints/initiate-payment) accepts TND but reviewed docs did not establish reusable-card recurring. [Paymee's API guide](https://www.paymee.tn/paymee-integration-without-redirection/) documents an individual payment flow; recurring/card-on-file support and explicit currency/settlement terms were not verified. Settlement currency must not be inferred from customer charge currency.
- Usage policy: count successfully created invoice drafts during the UTC calendar month (deletion does not decrement); active accepted memberships count as seats (pending invitations do not); atomically deny cap-exceeding writes with a structured HTTP 409; downgrade takes effect immediately and blocks cap-increasing writes while usage is above the new cap.
- Admin boundary: tenant admins can view their organization's plan/usage, update billing-profile details, and cancel; they cannot activate Paid. Platform admins may inspect only plan/status and aggregate usage counters across tenants, read-only. Each support read must be audited with actor, organization, timestamp, and support action under the project audit-retention policy; if none exists, retention duration remains open for privacy review. No invoice/customer/member-level details.
- Test-event signing key: load from secret configuration, separate per environment, never from tracked files or production credentials; support rotation without bypassing verification.
- Provider-neutral mock/test event contract: timestamped HMAC-SHA256 signature with a 5-minute replay window, plus a durable unique event ID. Validate the signature, then apply the event state change and persist the ID atomically; retain IDs indefinitely. Reject invalid or replayed signatures; acknowledge duplicate IDs without applying them twice. No live provider endpoint or production events in the scope-definition step.

### Coding-agent prompt
```
Focus: Record the owner-approved Phase 8 billing and usage scope for Keelson. This is a planning/documentation task, not authorization to implement it.

Approved scope decisions:
1. Defer selecting a billing provider; the seller's legal business is based in Tunisia and customer charges must be in TND. Do not add a provider SDK, checkout, live integration, or payment processing until merchant eligibility, TND charging, recurring-card capability, and payout terms are verified and separately approved.
2. Use a Free tier and one test-only Pro tier for organization-owned monthly subscriptions. Free is the only live-assignable tier until provider/checkout selection and implementation are separately approved; Pro activation is limited to local/test fixtures. Prices and numeric limits are required configuration with no defaults; trial policy remains undecided. Do not automatically upgrade plans.
3. Count each successfully created invoice draft in the UTC calendar month; deleting a draft does not decrement usage. Count active accepted memberships as seats; pending invitations do not reserve seats. Enforce caps atomically and return a structured HTTP 409 when an operation would exceed them. A downgrade applies immediately; if usage is above the new caps, allow reads and reductions but deny writes that increase an over-cap counter.
4. Tenant admins may view their organization's plan/usage, update billing-profile details, and cancel; they cannot activate Paid. Platform admins have a separate role and may inspect only cross-tenant plan/status and aggregate counters, read-only; no invoice/customer/member-level detail and no plan, entitlement, or membership changes. Audit every support read with actor, organization, timestamp, and action; follow the project audit-retention policy, or leave retention duration open for privacy review if no policy exists.
5. Specify mock/test events using timestamped HMAC-SHA256 signatures with a 5-minute replay window and a durable unique event ID. Load signing keys from environment-specific secret configuration (never tracked files or production credentials) and support rotation without bypassing verification. Validate the signature and timestamp, then apply the state change and persist the event ID atomically; retain IDs indefinitely. Reject invalid signatures and timestamps outside the replay window; acknowledge a valid duplicate event ID without applying it twice. No live provider webhook endpoint or production events in this scope-definition step.
6. A database-managed subscription is authoritative before provider selection. Cancellation takes effect immediately, has no grace period, and falls back to Free entitlements. A past-due subscription likewise loses paid entitlements immediately and falls back to Free; Free caps continue to apply. Free is the only live-assignable tier until a provider/checkout is separately approved; Paid activation is test-fixture-only in this scope.

Scope limits:
- Record policy and remaining decisions only; do not implement billing, subscriptions, usage enforcement, or schema changes in this step.
- Do not add a provider dependency, checkout flow, live webhook handler, production charge, or credentials.
- Do not modify the frontend design system or Phase 7 invoice models.
- Any later implementation requires separate approval and must include tests for tenant isolation, atomic limits, role boundaries, signature rejection, and duplicate-event idempotency.

Acceptance criteria:
- The recorded policy reflects all six approved decisions above: seller country is Tunisia; TND customer charges are required; tier labels are Free and Pro; provider, prices, numeric limits, and trial policy are not selected; prices/limits have no defaults and must be explicitly configured.
- Pro activation is explicitly limited to local/test fixtures until separate provider/checkout approval; tenant-admin controls, platform support's aggregate-only audited visibility and retention rule, environment-specific signing-key source, and provider-eligibility gate are stated; event ID persistence is atomic and indefinite.
- Remaining commercial, lifecycle, authorization-action, and event-delivery details are explicitly listed rather than guessed.
- The boundary between scope approval and implementation approval is clear.

Exit criteria and definition of done:
- Scope decisions and remaining decisions are documented for review.
- No provider integration, production credentials, live payment path, or application behavior is added by the scope-definition step.
- Phase 9 may be planned after this scope record is reviewed; billing implementation remains separately gated.

Remaining business decisions:
- Provider selection and test-environment boundary: confirm Tunisia onboarding, TND customer charging, recurring off-session card support, signed webhooks, merchant settlement currency/payout schedule/fees, and provider-backed customer mapping before choosing. Flouci is the first vendor to query, not a selected provider; card autopay availability must be confirmed in writing. Konnect/Paymee recurring-card capability was not established in the public docs reviewed.
- Exact configurable limits/prices, trial policy, and rules for an explicit paid upgrade (automatic upgrades remain out of scope); tier names are Free and Pro. TND customer-charge currency is required; settlement currency is a separate open question.
- Checkout/customer mapping and any provider-backed source-of-truth reconciliation after a provider is separately selected.
- Any tenant-admin billing operation beyond viewing usage, updating the billing profile, and canceling; Paid activation remains unavailable outside local/test fixtures.
- The exact audit-retention duration if the project has no established policy, plus access controls and privacy review for support-read records.
- Secret-manager/configuration mechanism for environment-specific mock-event key provisioning and rotation; operational recovery for event-application failures. Event IDs are retained indefinitely and delivery retries must preserve duplicate-safe processing.

Verified evidence (phase 8): Owner scope choices recorded in this section on 2026-10-24, including Tunisia seller location, mandatory TND customer charges, follow-up lifecycle, quota, role, and mock-event protocol decisions. A current provider-docs review found no vendor yet verified against every required condition; Flouci is an inquiry candidate only, not a selection. No Phase 8 application implementation or integration was performed; implementation and the remaining decisions above are pending.
```

## Phase 9: Hardening, CI and release readiness

### Status: NOT STARTED
**Reason:** CI pipeline and security regression suite not yet established; requires all prior phases foundation.

### Goal and scope
Establish CI pipeline for build/lint/tests, backend tests/static analysis, dependency review, migration validation and security regression checks. Document rollback and observability.

### Prerequisites and dependencies
- Phase 8 billing and usage enforcement scope documented
- Phase 7 invoicing models and frontend forms operational
- Phase 6 tenant-scoped data and migrations operational
- Node, pnpm, PHP/Composer, Docker all available (from Phase 0 resolution)

### Expected deliverables and files
- CI workflow (GitHub Actions or documented equivalent) for build/lint/tests
- Backend PHPUnit test suite with at least one test (or documented blocker)
- Frontend test script addition (Vitest or documented rationale for absence)
- Dependency review and security regression checks
- Documented rollback procedure and observability setup

### Coding-agent prompt
```
Focus: Establish CI and security regression suite for Keelon.

1. Add a GitHub Actions workflow (or document equivalent) that runs: `pnpm run build`, `pnpm run lint`, and `php bin/phpunit` (if PHP available).
2. Add at least one backend PHPUnit test (or document the blocker if PHP unavailable) that verifies a tenant isolation invariant.
3. Add frontend test script to package.json (or document rationale if absent — e.g., "no test script defined; to be added in Phase N").
4. Run `composer audit` or `npm audit` and capture findings (document; do not remediate without approval).
5. Document a rollback procedure for migrations (re-run down() on disposable DB; record steps).
6. Observe and log: `docker compose up -d` behavior and DB volume persistence.

Do not use production data or credentials in any CI workflow. Do not assume PHPUnit tests will pass; document any blocker. Do not modify the frontend design system or invoicing models to make checks pass.

Scope limits:
- Only establish the CI pipeline and document findings.
- Do not remediate audit findings without explicit approval.
- Do not modify production or shared database configurations.

Do-not rules:
- Do not use production database data in any CI job.
- Do not add secrets or credentials to GitHub Actions workflow files.
- Do not assume any check is "passing" without running it and capturing output.
- Do not modify the frontend invoicing forms or billing scope to satisfy CI.

Test cases:
- CI workflow file exists and lists at least 3 checks (build, lint, test).
- At least one PHPUnit test exists or the blocker is documented with exact output.
- Frontend test script status is recorded (present or documented reason).
- `npm/audit` findings are captured and documented.

Expected results for every test case:
- Workflow file exists with at least 3 job steps.
- PHPUnit test file exists or blocker output is documented.
- Frontend test script status is noted (present/absent with reason).
- Audit findings are listed (count and severity).

Acceptance criteria:
- CI workflow file exists and is valid.
- Test coverage is documented (or blockers are recorded).
- Audit findings are captured without remediation.
- Rollback procedure is documented.

Exit criteria and definition of done:
- CI workflow is operational (or blockers are documented with output).
- Test and audit status is recorded.
- Roadmap is updated with any new findings.
- No phase is marked VERIFIED without repository evidence.

Unresolved business decisions:
- CI runner selection and job dependencies
- Frontend test framework selection (Vitest vs none documented)
- Backend test feasibility given PHP availability
- Rollback procedure detail level

Verified evidence (phase 9): NOT APPLICABLE (status is NOT STARTED)
```

## Governance notes

- No phase counts as implemented without repository evidence and real test output
- Critical decision points: PHP/Composer availability, TypeScript/Eslint compatibility resolution, RLS vs app-layer tenant scoping, Stripe integration scope decision, CI pipeline establishment
- The roadmap guides the next phase without altering the current foundation
- Tenant isolation/RLS, auth, and billing remain unverified until explicit implementation and tests
- All unresolved decisions are carried forward and assigned before implementation begins
- Roadmap was first created from repository evidence on 2026-10-20; no previous roadmap was present
- Update README and roadmap when implemented capabilities, architecture, setup, dependencies, or verification evidence changes