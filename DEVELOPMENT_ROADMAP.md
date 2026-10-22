# Keelson Development Roadmap

This roadmap scopes the multi-tenant SaaS starter domain only. It does not claim any verified backend implementation, tenant isolation, or billing functionality. All phases are scoped to what can be confirmed from repository evidence as of 2026-10-22.

## Intro

Keelson is a multi-tenant SaaS starter project with organization membership and invoicing described in project guidance. The current tracked repository is a starter baseline: the frontend is a Vite/React TypeScript app, and the backend is a Symfony 7.4 skeleton. No tenant isolation, authentication, authorization, billing, or invoicing code has been verified in tracked source or manifests. This roadmap guides implementation toward a secure tenant foundation before any user-facing features are built.

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
| 1: Requirements, domain model and API contract | IN REVIEW - PR #3 | Document tenant/principal model and API boundaries; record approved security decisions and UI invariants |
| 2: Backend domain model and migrations | NOT STARTED | Add approved persistence dependencies; define migrations for tenant schema; test migrations in disposable DB |
| 3: Client app shell and design system | NOT STARTED | Set up React + Vite + Tailwind; integrate shadcn/ui component patterns; implement Home and organization-aware screens |
| 4: Client workflows — organization onboarding | NOT STARTED | Implement organization creation, switching, and member invitation flows (frontend + API) |
| 5: Authentication and authorization | NOT STARTED | Implement the approved OIDC + server-managed-session contract; enforce tenant isolation on API routes; role-based access controls |
| 6: Tenant-scoped data and migrations | NOT STARTED | Implement repository pattern for tenant-separated reads/writes; verify cross-tenant access is denied |
| 7: Invoicing demonstration | NOT STARTED | Implement customer/invoice lifecycle guided by ui-spec.md; state transitions, totals, validation, audit trail |
| 8: Billing and usage enforcement | NOT STARTED | Stripe integration scope; subscription lifecycle; usage counters and atomic enforcement; admin separation |
| 9: Hardening, CI and release readiness | NOT STARTED | CI pipeline for build/lint/tests; security regression suite; documented rollback and observability |

Status evidence (verified 2026-10-22):
- Phase 0 PR #2 is merged into `main` (merge commit `911b16ff667c9173ca74fb0972e750fc7fc67164`); its recorded environment evidence and blockers are now part of `main`.
- Phase 1 PR #3 is merged into `main` (merge commit `4225679eb3a7daccaffacf65bd521636b6cc78ab`); the draft contract is in `main`, but explicit product/security review has not been recorded and Phase 1 is not VERIFIED.
- `cd Keelson/frontend && pnpm install` — PASS: `Already up to date`; pnpm 12.14.0.
- `cd Keelson/frontend && pnpm run build` — PASS: Vite 8.3.4 build completed successfully (20 modules; exit 0).
- `cd Keelson/frontend && pnpm run lint` — FAIL, exit 2: `typescript-eslint@8.71.2` reports `typescript-eslint does not support TS 7.0.` No dependency/config workaround applied; decision: defer and retain as a blocker for a later scoped phase.
- `cd Keelson && docker compose -f docker-compose.yml config --quiet` — PASS, exit 0 with no output. Compose syntax validated only; no services started and no DB/volume touched.
- `cd Keelson && php -v` — PHP 8.5.12; `composer -V` — Composer 2.10.0; `pnpm -v` — 12.14.0; `node -v` — v24.13.0; `docker version --format "{{.Server.Version}}"` — 29.5.2.
- Disposable migration database decision: use isolated ephemeral PostgreSQL only; this phase did not provision/start it or connect to any database.
- `cd Keelson && git status --porcelain --ignored` — `?? docs/ORCHESTRATION_READINESS.md`; ignored `.freebuff/`, `backend/vendor/`, `frontend/dist/`, `frontend/node_modules/`. Existing readiness report was not staged or changed.
- `cd Keelson && git rev-parse HEAD` before phase changes — `27bf422f8097e564027927f5e08c40215d2bc7b0`; branch was based on up-to-date `origin/main`.
- `git diff --stat` before phase changes — empty.
- Backend PHPUnit, PHPStan, container lint and DB migrations were not run: Phase 0 forbids backend operations and database migrations. No backend test cases beyond the bootstrap are noted in the baseline audit.
- No frontend test script is defined; no frontend test command was run.
- No CI workflow was found in the baseline repository inspection.

## Phase 0: Environment and repository readiness

### Status: VERIFIED - merged in PR #2
**Reason:** Required Phase 0 environment checks and blockers were reviewed in PR #2, which is merged into `main` at `911b16ff667c9173ca74fb0972e750fc7fc67164`. The lint incompatibility remains documented and deferred; verification does not mean every check passed.

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

### Status: IN REVIEW - PR #3 merged; owner review pending
**Reason:** The contract and model draft are recorded below from explicit user-approved choices and merged into `main` in PR #3 at `4225679eb3a7daccaffacf65bd521636b6cc78ab`. No product/security owner review is recorded in the PR; this phase is not VERIFIED and Phase 2 must not start until that review is confirmed.

### Goal and scope
Define the tenant, principal, and organization model as the secure foundation for all subsequent work. Establish API contract boundaries before any implementation begins.

### Prerequisites and dependencies
- Phase 0 PR #2 merged into `main`; environment evidence and the lint blocker are recorded above.
- Explicit product/security owner review of the draft below is still required; the user's chosen options are recorded as proposed contract decisions, not as completed independent security review.
- Review `docs/ui-spec.md` for intended screen invariants below (these are product requirements, not verified implementation).
- No database schema, authentication code, routes, or API implementations are assumed to exist.

### Expected deliverables and files
- This roadmap contains the draft model, API contract, approved choices, and negative access-control test concepts.
- Product/security owner review note remains pending.

### Phase 1 draft contract (for product/security review)

#### Scope and evidence boundary
This is a planning contract only. It defines intended responsibilities and wire-level shapes for subsequent review; it does not assert that any endpoint, identity provider, RLS policy, UI, or domain behavior currently exists. No SQL, migration, implementation code, credentials, or provider-specific OIDC settings are defined here. Invoice, billing, payment, and subscription fields are excluded because they were not separately approved in the Phase 1 decision dialogue; `docs/ui-spec.md` mentions those screens, but that alone is not approval to define billing APIs or persistence.

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

#### Tenant, principal, and authorization model (draft)
- **Principal/user:** the authenticated human identity established by the OIDC identity provider. Contract-level identity reference is an opaque, stable `subject` (`sub`) plus display name and email for presentation/contact only. Email is not an authorization key. Provider issuer and subject together identify the external identity; account-linking behavior is outside scope pending product review.
- **Organization/tenant:** a tenant boundary with an opaque organization ID, display name, unique normalized subdomain, lifecycle status, and creation/update timestamps. Tenant membership is explicit; a user's existence or invitation does not grant membership.
- **Ownership:** each active organization has exactly one active Owner membership. Ownership transfer is not included in this contract and requires an explicit reviewed operation before implementation. Organization deletion is an explicit, separately authorized lifecycle action; cascade/retention behavior is unresolved and must be decided before migrations.
- **Membership:** association of a principal with one organization, a role (`Owner`, `Admin`, `Member`, or `Viewer`), status (`active` or `suspended`), and timestamps. Role grants are organization-scoped. A member may belong to multiple organizations; switching changes the selected organization context only and never grants access.
- **Invitation:** organization-scoped invitation with normalized recipient email, intended role (not `Owner`), issuer principal, random single-use token whose verifier is stored hashed, expiry 72 hours after issuance, lifecycle status (`pending`, `accepted`, `revoked`, or `expired`), and timestamps. A raw token is generated only on issuance/resend and delivered through a security-approved channel; it is never exposed by API responses, read/list responses, or logs. Exact delivery and recovery mechanisms remain pending review. Accepting consumes it atomically; revoke/accept/expiry are terminal for that token. Resend issues a new token and invalidates the previous one. Acceptance must bind to a signed-in identity whose verified email matches the invitation recipient; product review must confirm email-verification availability and behavior. Reissue rate limits, delivery channel, and invited-user account creation are unresolved.
- **Roles:** Owner may administer organization membership/invitations and organization settings; Admin may administer members/invitations and ordinary settings but cannot transfer ownership or delete the organization; Member may use ordinary organization workflows; Viewer has read-only organization access. Exact per-resource/action permissions are a Phase 1 review item, and role names alone do not imply invoice or billing capabilities.
- **Super-admin:** a separate platform-level authority, not a tenant role or organization membership. No self-service endpoint can grant it. Its identity source, assignment process, auditing, and impersonation boundary are unresolved; platform administration remains unavailable until those controls are separately specified and reviewed.
- **Tenant enforcement decision (user-approved draft):** application-layer authorization and organization scoping on every request, plus PostgreSQL Row-Level Security as defense in depth. The rationale is to enforce business roles and tenant context in the application while retaining a database boundary against a missed or faulty application filter; neither layer alone is treated as sufficient. The server derives the active organization from authenticated membership/context, verifies membership and role for every operation, and establishes database tenant context transaction-locally. This is a target design only: database support/policies, connection-pool context reset, background jobs, and bypass roles must be designed and tested in Phase 2/5 before any security claim.
- **Identity/session decision (user-approved draft):** OIDC sign-in with a server-managed session. Provider selection, OIDC client configuration, callback/CSRF details, session store, cookie attributes/lifetime, sign-up/account-linking behavior, and recovery flow remain open implementation decisions. No JWT bearer contract is introduced.
- **Invitation-token decision (user-approved draft):** cryptographically random single-use token, store only a hash/verifier, 72-hour expiry; acceptance/revocation invalidates it and resend rotates it. Token format, entropy parameters, throttling, and delivery are implementation/security review items.

#### API contract boundaries (draft; JSON over HTTPS)
All authenticated endpoints use the server-managed session. Request organization IDs are selectors, never proof of authority: every operation verifies authenticated principal, active membership, role, and tenant scope. Responses must not disclose another tenant's existence or data; inaccessible and nonexistent organization resources should use a consistent non-enumerating denial policy. Mutating requests require CSRF/origin protection appropriate to the eventual session implementation. Error schemas and pagination conventions are common contract items to finalize before implementation. Role labels below are provisional until the Owner/Admin/Member/Viewer action matrix receives product/security approval.

1. **Create organization** — `POST /api/organizations`; authenticated principal only. Request: `{ "name": string, "subdomain": string }`. Response `201`: `{ "organization": { "id": string, "name": string, "subdomain": string, "status": "active" }, "membership": { "role": "Owner", "status": "active" } }`. Server normalizes/validates subdomain uniqueness atomically and creates exactly one Owner membership for the caller. No plan, payment, or billing property is accepted.
2. **Check subdomain availability** — `GET /api/organization-subdomains/{subdomain}/availability`; public, advisory response `{ "available": boolean }`. Must not reserve the value or replace uniqueness validation on creation. Rate limiting and disclosure policy are implementation decisions.
3. **List organizations / member switch** — `GET /api/me/organizations` returns only the caller's memberships, with `{ "items": [{ "organization": { "id": string, "name": string, "subdomain": string }, "role": Role, "membershipStatus": string }] }`. `POST /api/session/organization` request `{ "organizationId": string }`; response `{ "activeOrganization": { "id": string, "name": string }, "role": Role }`. Server verifies active membership before changing session context; unknown and unauthorized IDs do not reveal tenant data.
4. **List members** — `GET /api/organizations/{organizationId}/members`; response `{ "items": [{ "principal": { "subject": string, "displayName": string, "email": string }, "role": Role, "status": string, "joinedAt": string }] }`. Restricted to authorized tenant members according to reviewed permissions; no cross-tenant lookup.
5. **Change member role** — `PATCH /api/organizations/{organizationId}/members/{subject}`; request `{ "role": "Admin" | "Member" | "Viewer" }`; response `{ "membership": { "subject": string, "role": Role, "status": "active" } }`. Owner/Admin-only draft; cannot assign Owner, change the acting user's own role, or remove/demote the last Owner. Exact authority and concurrency behavior require review.
6. **Remove member** — `DELETE /api/organizations/{organizationId}/members/{subject}`; response `204`; Owner/Admin-only draft. Cannot remove the last active Owner; self-removal/owner departure and historical-data retention remain review items. No operation can change another tenant's membership.
7. **Issue invitation** — `POST /api/organizations/{organizationId}/invitations`; request `{ "email": string, "role": "Admin" | "Member" | "Viewer" }`; response `201` includes invitation ID, email, role, `status: "pending"`, and `expiresAt`, but never the stored token verifier or raw token. The raw token is generated and dispatched only through a separately approved channel, never returned by the API; delivery mechanism requires security/product approval. Owner/Admin only; cannot invite Owner or create duplicate active membership/invitation without defined conflict behavior.
8. **List invitations** — `GET /api/organizations/{organizationId}/invitations`; response `{ "items": [{ "id": string, "email": string, "role": Role, "status": string, "expiresAt": string, "createdAt": string }] }`. Never returns a raw token or hash/verifier. Restricted to reviewed Owner/Admin permissions.
9. **Resend invitation** — `POST /api/organizations/{organizationId}/invitations/{invitationId}/resend`; no token in request; response returns pending invitation metadata with refreshed `expiresAt`. Owner/Admin only; invalidates any previous token and issues a new single-use token. Delivery mechanism and resend throttling require approval.
10. **Revoke invitation** — `DELETE /api/organizations/{organizationId}/invitations/{invitationId}`; response `204`; Owner/Admin only. Revocation is idempotent or returns a documented terminal-state conflict (choose consistently before implementation); revoked tokens cannot be accepted.
11. **Accept invitation** — `POST /api/invitations/accept`; request `{ "token": string }` under an authenticated session; response `{ "organization": { "id": string, "name": string }, "membership": { "role": Role, "status": "active" } }`. Validate hash, pending status, expiry, and verified email match; atomically consume token and create membership, preventing replay and duplicate membership. Invalid, expired, revoked, used, and non-matching-email cases return a consistent non-enumerating denial.

12. **List customers** — `GET /api/organizations/{organizationId}/customers`; response `200`: `{ "items": [CustomerSummary], "nextCursor": string | null }`. `CustomerSummary`: `{ "id": string, "name": string, "email": string | null, "phone": string | null }`. Only authorized members of that organization receive data.
13. **Create customer** — `POST /api/organizations/{organizationId}/customers`; request `{ "name": string, "email": string | null, "phone": string | null, "billingAddress": Address | null }`; response `201`: `{ "customer": Customer }`. `Address`: `{ "street": string, "city": string, "postalCode": string, "countryCode": string }`; `Customer` includes `CustomerSummary` plus `billingAddress`. Address validation/normalization and duplicate rules require review.
14. **List invoices** — `GET /api/organizations/{organizationId}/invoices`; optional status filter. Response `200`: `{ "items": [InvoiceSummary], "nextCursor": string | null }`. Only authorized members of that organization receive data; `InvoiceSummary`: `{ "id": string, "customerId": string, "status": "draft" | "sent" | "paid" | "overdue", "issueDate": "YYYY-MM-DD", "dueDate": "YYYY-MM-DD", "currency": string, "total": string }`.
15. **Create invoice draft** — `POST /api/organizations/{organizationId}/invoices`; request `{ "customerId": string, "issueDate": "YYYY-MM-DD", "dueDate": "YYYY-MM-DD", "currency": string, "lineItems": [{ "description": string, "quantity": number, "unitPrice": string }], "taxRate": string | null, "discountAmount": string | null }`. Response `201`: `{ "invoice": Invoice }` with `status: "draft"`. Request is tenant-scoped and verifies that the customer belongs to the organization. The field shape is a draft derived from the UI; currency policy, decimal precision, tax meaning, discount semantics, totals and rounding require domain/security review before migrations or implementation.
16. **Read invoice** — `GET /api/organizations/{organizationId}/invoices/{invoiceId}`; response `200`: `{ "invoice": Invoice }`; enforce organization scoping on both invoice and customer references.
17. **Update draft** — `PATCH /api/organizations/{organizationId}/invoices/{invoiceId}`; accepts the editable fields from the create-draft request only while the invoice remains `draft`; response returns `{ "invoice": Invoice }`. Sent/paid/overdue state changes are not permitted through this operation.
18. **Send invoice** — `POST /api/organizations/{organizationId}/invoices/{invoiceId}/send`; response returns `{ "invoice": Invoice }` with `status: "sent"`. Delivery mechanism, authorization granularity, atomic state transition, and retry/idempotency behavior remain review items.

Draft invoice response representation: `{ "id": string, "customerId": string, "status": "draft" | "sent" | "paid" | "overdue", "issueDate": "YYYY-MM-DD", "dueDate": "YYYY-MM-DD", "currency": string, "lineItems": [{ "description": string, "quantity": number, "unitPrice": string }], "taxRate": string | null, "discountAmount": string | null, "subtotal": string, "taxAmount": string, "total": string }`. Amounts are decimal strings to avoid JSON binary floating point, but exact precision/rounding and whether `overdue` is derived are unresolved contract choices. These are proposed wire shapes only, not approved financial rules.

**Not defined in this contract:** customer update/delete APIs, invoice tax/legal rules or accounting numbering, payment collection, billing/subscription/Stripe endpoints, organization deletion behavior, ownership transfer, logo upload, password reset (OIDC-provider-dependent), platform admin/impersonation, email delivery, or provider-specific OIDC details. These require separate approved contracts; do not infer approval from UI copy.

#### Negative access-control cases to carry into planned tests
- Unauthenticated principal cannot create organizations, list private memberships, switch context, list members, or manage/accept invitations.
- Authenticated user cannot select an organization with no active membership, nor infer its existence from a different response shape/status.
- A user who belongs to organizations A and B cannot read or mutate A's members/invitations while B is active by substituting A's ID; explicit A access remains membership/role checked.
- Viewer and Member cannot issue, resend, or revoke invitations; Admin/Owner permissions must be tested against the finalized matrix.
- Admin cannot grant Owner, transfer ownership, delete an organization, or access platform-super-admin operations.
- No organization member can grant platform-super-admin authority; tenant roles cannot cross the platform boundary.
- Expired, revoked, already accepted, replayed, malformed, and wrong-verified-email invitation tokens cannot create membership; concurrent acceptance creates at most one membership.
- A token is never returned by invitation list/read, persisted in plaintext, or written to logs; resend invalidates the old token.
- RLS must deny cross-tenant rows when application scoping is omitted or misapplied; application checks must deny when RLS is disabled/misconfigured. Connection reuse/background execution must not inherit another tenant's context.
- Changing the session active organization cannot elevate role; simultaneous requests/session rotation must not leak prior tenant context.

#### Review decisions and unresolved items
User-approved draft choices for this phase:
- Tenant isolation: application authorization/scoping plus PostgreSQL RLS defense in depth.
- Identity/session: OIDC sign-in with server-managed sessions.
- Invitation token: random single-use token stored hashed, 72-hour expiry, rotate on resend.

Still requires explicit product/security owner review or a follow-up decision before implementation: approval of the draft contract and permission matrix; OIDC provider and account-linking/email verification; exact role permissions; ownership transfer and organization deletion/retention; invitation delivery, throttling, and duplicate rules; API error/pagination/CSRF conventions; RLS database-context lifecycle and trusted roles; and whether invoice/customer/API/billing contracts belong in a later phase. No independent security approval is claimed.

### Coding-agent prompt
```
Focus: Review and complete Phase 1's tenant, domain, and API contract draft for Keelson.

1. Review the Phase 1 draft in this roadmap against `docs/ui-spec.md`; confirm all 11 specified screens and shared presentation invariants are captured without treating them as implemented behavior.
2. Review the user-approved draft choices: application authorization/scoping plus PostgreSQL RLS defense in depth; OIDC with server-managed sessions; random single-use hashed invitation token with 72-hour expiry and token rotation on resend. Do not silently change these choices; record requested revisions for owner approval.
3. Review organization, membership, invitation, customer, and invoice endpoint shapes, tenant boundaries, role assumptions, invitation lifecycle, and negative access-control test concepts for consistency and migration-planning sufficiency.
4. Obtain and persist explicit product/security owner review of the remaining open items before Phase 2 begins.

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
- Tenant model document lists user, organization, membership, role, and invitation fields.
- API contract specifies organization creation, organization switching, membership management, invitations, and proposed customer/invoice boundaries with request/response shapes.
- User-approved application checks plus RLS choice is recorded with rationale and explicit implementation caveats.
- Negative access-control cases are mapped to test concepts.

Expected results for every test case:
- Model document exists and covers all required fields.
- API contract includes org creation, member switching, invitation lifecycle, membership management, customer/invoice draft boundaries, and negative access cases.
- Tenant enforcement choice and rationale are explicit, pending product/security review.
- Negative access cases are identified and documented.

Acceptance criteria:
- Tenant model document is complete and reviewed by security owner.
- API contract is sufficient for Phase 2 migration planning after owner review.
- The user-approved RLS plus application-checks decision is explicitly documented; product/security review must confirm it.

Exit criteria and definition of done:
- Model and API contract documents are persisted.
- Security review note is recorded.
- Phase 2 can begin with clear migration prerequisites.

User-approved draft decisions (not independently security-reviewed):
- Tenant enforcement uses application authorization/scoping plus PostgreSQL RLS defense in depth.
- Authentication uses OIDC with server-managed sessions.
- Invitation tokens are random, single-use, stored hashed, expire after 72 hours, and rotate on resend.

Open review decisions:
- Approve or revise the draft contract and per-resource role permission matrix.
- Select OIDC provider and settle account linking, verified-email and recovery behavior.
- Define ownership transfer, organization deletion/retention, invitation delivery/rate limits/duplicate handling, API error/pagination/CSRF conventions, invoice/customer constraints, and RLS transaction/context lifecycle.
- Confirm whether invoice/customer endpoints belong in the agreed API contract scope; no billing/payment endpoints are included.

Verified evidence (phase 1): Draft contract is persisted in this roadmap and merged in PR #3 at `4225679eb3a7daccaffacf65bd521636b6cc78ab`. Product/security owner review remains pending; do not mark VERIFIED or start Phase 2 until that review is confirmed.
```

## Phase 2: Backend domain model and migrations

### Status: NOT STARTED
**Reason:** Backend PHP/Composer environment not yet available; migrations not defined.

### Goal and scope
Add approved persistence dependencies (Symfony/Doctrine or equivalent) and define migrations for the tenant schema. Test migrations in a disposable DB before any production consideration.

### Prerequisites and dependencies
- Phase 1 domain model and API contract documented and reviewed
- PHP >=8.2 and Composer available on the target system
- Agreed tenant scoping approach (RLS or application-layer)
- Disposable/local database target confirmed (not shared or production)

### Expected deliverables and files
- Updated `composer.json` with approved persistence dependencies (or documented alternative)
- Migration files for tenant schema (tested against disposable DB)
- Repository pattern for tenant-separated reads/writes
- DATABASE_URL configuration for local development (no credentials in tracked files)

### Coding-agent prompt
```
Focus: Set up the backend domain model and migrations for Keelon.

1. Ensure PHP >=8.2 and Composer are available on the system (do not install if not present).
2. Add approved persistence dependency to `composer.json` (e.g., symfony/orm-pack or documented alternative).
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
- Disposable DB target is confirmed and isolated from Compose volume.
- Phase 3 can begin with frontend work starting independently.

Unresolved business decisions:
- Choice of persistence approach (Doctrine ORM vs alternative vs raw SQL)
- Migration naming and versioning strategy
- DATABASE_URL management (local vs CI secrets)

Verified evidence (phase 2): NOT APPLICABLE (status is NOT STARTED)
```

## Phase 3: Client app shell and design system

### Status: NOT STARTED
**Reason:** Frontend design system and component patterns not yet established.

### Goal and scope
Set up the React + Vite + Tailwind environment with shadcn/ui component patterns. Implement the Home screen and organization-aware screens as the client app shell.

### Prerequisites and dependencies
- Phase 0 environment verified (Node, pnpm, Docker Compose passing)
- Phase 1 domain model and API contract documented (may be preliminary)
- Tailwind CSS configured or ready for integration
- shadcn/ui component library or equivalent design system decision

### Expected deliverables and files
- Vite project configured with Tailwind CSS
- shadcn/ui components installed or equivalent design tokens defined
- Home screen component replacing the Vite starter `App.tsx`
- Organization-aware layout with navigation between screens
- TypeScript strict mode configuration verified (no `any` unless justified)

### Coding-agent prompt
```
Focus: Set up the Keelon frontend app shell and design system.

1. Ensure `cd frontend && pnpm install` completes (document any errors).
2. Install shadcn/ui components or configure Tailwind design tokens if not present.
3. Replace `src/App.tsx` with a Home screen that matches the ui-spec.md mock (use mock data only, no backend calls).
4. Add organization-aware navigation (placeholder links only, no auth yet).
5. Verify `pnpm run build` passes with TypeScript strict mode.
6. Verify `pnpm run lint` results are documented (known incompatibility or passing).

Do not implement any authentication guards or API calls. Do not assume any backend endpoints are available. Do not modify `composer.json`, `docker-compose.yml`, or any backend files. Do not claim any feature is "connected" without verified API evidence.

Scope limits:
- Only set up the app shell and design system components.
- Do not implement organization switching logic yet.
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
- Design system (Tailwind/shadcn) is configured or decisions are documented.
- Home screen matches ui-spec.md intent (mock data accepted).

Exit criteria and definition of done:
- Frontend app shell is operational and buildable.
- Design system decisions are recorded.
- Phase 4 can begin with organization onboarding workflows.

Unresolved business decisions:
- Tailwind vs shadcn/ui component library choice
- Mock data vs API-driven state management approach
- Navigation structure and route naming

Verified evidence (phase 3): NOT APPLICABLE (status is NOT STARTED)
```

## Phase 4: Client workflows — organization onboarding

### Status: NOT STARTED
**Reason:** Organization onboarding flows not yet implemented; requires auth and API foundation from Phases 1-3.

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

Verified evidence (phase 4): NOT APPLICABLE (status is NOT STARTED)
```

## Phase 5: Authentication and authorization

### Status: NOT STARTED
**Reason:** Auth implementation not yet started; requires PHP/Composer and model decisions from Phase 1.

### Goal and scope
Implement JWT or session-based authentication and enforce tenant isolation on all API routes. Role-based access controls per the defined model.

### Prerequisites and dependencies
- Phase 2 backend persistence and migrations operational
- Phase 1 tenant model and API contract documented
- PHP >=8.2 and Composer available (or approved auth approach documented)
- API route structure from Phase 3 and 4

### Expected deliverables and files
- Authentication middleware or guard (Symfony session or JWT)
- Tenant isolation on all API routes (request-scoped tenant context)
- Role-based access control checks on protected routes
- Sign-out and session expiration handling
- Cross-tenant access denial verified in test concepts

### Coding-agent prompt
```
Focus: Implement authentication and authorization for Keelon backend.

1. Ensure PHP >=8.2 and Composer are available (document if not present).
2. Add authentication middleware to Symfony kernel (session-based or JWT placeholder).
3. Add tenant context derivation per the Phase 1 decision (RLS or application-layer).
4. Add role-based access control check on a sample API route (document the check logic).
5. Implement sign-out endpoint that invalidates session or revokes JWT.
6. Document cross-tenant access denial test concept for API routes.

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
- Tenant context is derived on a sample API route.
- Role check is present on a protected route.
- Sign-out endpoint is present and documented.

Expected results for every test case:
- Middleware file exists and is referenced in kernel config.
- Tenant context variable is set on the request.
- Role check condition is documented in code comments.
- Sign-out route is configured and returns a success response.

Acceptance criteria:
- Auth middleware is present and documented.
- Tenant isolation is explicit on all API routes (documented, not necessarily effective).
- Role-based access checks are present on sample routes.
- Sign-out is functional (documented behavior).

Exit criteria and definition of done:
- Auth middleware and tenant isolation are documented.
- Role checks are present on sample routes.
- Phase 6 can begin with tenant-scoped data implementation.

Unresolved business decisions:
- JWT vs session-based auth approach
- Tenant context derivation (RLS vs app-layer) final decision
- Role hierarchy and permission matrix design

Verified evidence (phase 5): NOT APPLICABLE (status is NOT STARTED)
```

## Phase 6: Tenant-scoped data and migrations

### Status: NOT STARTED
**Reason:** Tenant-separated repository pattern not yet implemented; requires auth from Phase 5 and DB from Phase 2.

### Goal and scope
Implement repository pattern for tenant-separated reads/writes. Verify cross-tenant access is denied at the data access layer. All queries must scope by authenticated principal membership.

### Prerequisites and dependencies
- Phase 5 auth and authorization operational (even if placeholder)
- Phase 2 migrations and persistence dependency operational
- Agreed tenant scoping approach from Phase 1 decision
- Repository pattern established or documented

### Expected deliverables and files
- Repository class or method for tenant-scoped reads/writes
- Cross-tenant access denial test concepts
- All API queries scope by authenticated principal membership
- Migration updates if tenant schema changes

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

Verified evidence (phase 6): NOT APPLICABLE (status is NOT STARTED)
```

## Phase 7: Invoicing demonstration

### Status: NOT STARTED
**Reason:** Invoicing not yet implemented; guided by ui-spec.md but requires tenant foundation from Phases 1-6.

### Goal and scope
Implement customer/invoice lifecycle guided by `docs/ui-spec.md`. Define state transitions, totals, validation, and audit trail. Tenant isolation must apply (users see only their organization's invoices).

### Prerequisites and dependencies
- Phase 6 tenant-scoped data and migrations operational
- Phase 1 domain model and API contract documented
- Phase 5 authentication and authorization operational
- ui-spec.md invariants reviewed (not verified as implemented)

### Expected deliverables and files
- Customer entity or model (tenant-scoped)
- Invoice entity or model (tenant-scoped, with state machine)
- Invoice state transitions (draft → sent → paid → void, with explicit rules)
- Validation rules for totals, tax, and currency (explicitly defined, not mocked)
- Audit trail logging for invoice creation, state changes, and deletions
- Frontend form for invoice creation and management (mock data, tenant-scoped)

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
- Only define models, state machine, validation rules, and frontend forms with mock data.
- Do not implement any Stripe webhook or payment provider integration.
- Do not claim any invoice state is "verified" without acceptance criteria and tests.

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

Unresolved business decisions:
- Currency and tax rule explicit definition (or defer to product owner)
- Audit trail logging detail level and storage location
- Invoice number generation strategy

Verified evidence (phase 7): NOT APPLICABLE (status is NOT STARTED)
```

## Phase 8: Billing and usage enforcement

### Status: NOT STARTED
**Reason:** Billing not yet implemented; Stripe integration scope to be decided; requires invoicing foundation from Phase 7.

### Goal and scope
Define Stripe integration scope, subscription lifecycle, usage counters, and atomic enforcement. Admin capabilities separated from tenant admin. No production credentials or live data.

### Prerequisites and dependencies
- Phase 7 invoicing demonstration operational (models, state machine, validation)
- Phase 1 domain model and API contract documented
- Phase 5 authentication and authorization operational
- Stripe account or test mode credentials (not in tracked files; documented only)

### Expected deliverables and files
- Stripe integration scope decision record (test mode only; no credentials in tracked files)
- Subscription lifecycle model (plan state source of truth, checkout/customer mapping, entitlement, grace period)
- Usage counters and atomic enforcement mechanism (documented policy, not implemented unless approved)
- Admin vs tenant admin capability separation (documented boundary)
- Provider test-mode fixtures or mocked webhook integration (no production data)

### Coding-agent prompt
```
Focus: Define billing and usage enforcement for Keelon (scope only; no live integration).

1. Document the Stripe integration scope decision: test mode only; no credentials in tracked files.
2. Define subscription lifecycle model: plan state source of truth, checkout/customer mapping, entitlement semantics, grace-period rules.
3. Define usage counters and atomic enforcement policy (document measurable counters and concurrency policy; do not implement unless approved).
4. Separate admin capabilities from tenant admin (document the boundary and authentication/authorization requirements).
5. Add provider test-mode fixtures or mocked webhook integration (no production data or credentials).
6. Document entitlement check: subscription state changes do not bypass entitlements (record the check logic).

Do not add any production Stripe credentials or go live with payment processing. Do not assume any delivery guarantees without explicit contract. Do not modify the frontend design system or invoice models beyond documented scope.

Scope limits:
- Only define the billing scope, subscription model, and usage policy.
- Do not implement any live Stripe webhooks or production charges.
- Do not modify any existing database schema beyond documented scope.

Do-not rules:
- Do not add production Stripe keys to .env or any tracked file.
- Do not implement any webhook handler that processes live events.
- Do not assume any usage limit enforcement is effective without documented policy and tests.
- Do not modify the invoicing models from Phase 7 beyond agreed scope.

Test cases:
- Scope decision record names test-mode-only integration and no tracked credentials.
- Subscription lifecycle model has at least 3 decision points (plan, checkout, entitlement).
- Usage counter policy is documented with at least 2 measurable counters.
- Admin vs tenant admin boundary is documented with at least 2 capability differences.

Expected results for every test case:
- Scope decision record exists and names test mode only.
- Subscription model has documented decision points.
- Usage policy documents at least 2 counters and concurrency approach.
- Admin/tenant admin boundary has at least 2 documented differences.

Acceptance criteria:
- Billing scope decision is recorded and reviewed.
- Subscription lifecycle model has documented decision points.
- Usage policy is documented with concurrency approach.
- Admin/tenant admin boundary is documented.

Exit criteria and definition of done:
- Billing scope decision recorded and reviewed.
- Subscription model and usage policy documented.
- Phase 9 can begin with hardening and release readiness.

Unresolved business decisions:
- Stripe test mode vs alternative billing provider
- Usage counter granularity and measurement approach
- Admin vs tenant admin capability boundaries final design
- Entitlement check automation level

Verified evidence (phase 8): NOT APPLICABLE (status is NOT STARTED)
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