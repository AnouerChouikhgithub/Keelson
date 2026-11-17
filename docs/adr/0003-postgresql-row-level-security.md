# ADR 0003 — Tenant isolation is enforced in the application, not by RLS

- **Status:** accepted, with a known gap
- **Date:** 2026-11-16

## Context

Every tenant table carries `organization_id`. Two places could enforce that a
request only ever reaches its own tenant's rows:

1. **Application layer** — repositories join the caller's membership and filter by
   `organization_id`, and voters re-check the membership on every request.
2. **Database layer** — PostgreSQL row-level security with a session variable
   (`SET LOCAL app.organization_id`) and policies per table.

RLS is the stronger guarantee: it also covers a new query that forgets its
filter. It also requires every connection to set the variable, a non-superuser
application role, and policies for every table plus the session table.

## Decision

Ship **application-layer scoping** in 1.0.0 and treat RLS as future work:

- `InvoiceVoter`, `OrganizationVoter` and `PrincipalVoter` require an active
  membership on an active organization.
- Repository list/read methods take the principal id and join the membership, so a
  caller cannot read another tenant's rows even with a valid organization id.
- A denial answers **404**, identical to a missing record, so the API does not
  confirm that a foreign tenant exists.
- Tests assert cross-tenant reads return nothing
  (`TenantScopedRepositoryTest`, `InvoiceAuthorizationMatrixTest`,
  `TeamInvitationTest`).

RLS is **not implemented**. There are no policies and no `SET LOCAL
app.organization_id` in the codebase; do not assume it exists. This is recorded in
`docs/limitations.md` and in the README.

## Consequences

- One authorization layer to read and test, no per-connection state to keep in
  sync, and no surprises when a background command runs without a tenant.
- A new query that forgets the tenant filter is a data leak. The mitigation is
  review plus the repository tests that assert cross-tenant emptiness.
- Migrating to RLS later is additive: the policies can be introduced with the
  application filters kept as defence in depth, and the app role swapped for a
  non-owner role.
- The privilege split (`keelson_owner` owns the schema, `keelson_app` runs the
  application) already exists in CI and in `docs/deployment.md`, which is the
  prerequisite RLS work.
