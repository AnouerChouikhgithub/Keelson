# ADR 0003 — Tenant isolation is enforced by PostgreSQL, with the application as a second layer

- **Status:** accepted 2026-11-17; **supersedes** the 2026-11-16 decision that
  shipped application-layer scoping alone
- **Date:** 2026-11-17

## Context

Every tenant table carries `organization_id`. Two layers can enforce that a request
only ever reaches its own tenant's rows:

1. **Application** — repositories join the caller's membership and filter by
   `organization_id`, and voters re-check the membership on every request.
2. **Database** — PostgreSQL row-level security with a transaction-local session
   variable and a policy per table.

The previous version of this ADR chose (1) alone for 1.0.0 and deferred (2). That
left one acknowledged failure mode: **a new query that forgets the tenant filter is
a data leak**, mitigated only by review and by tests that assert cross-tenant
emptiness. Review is not a guarantee; a single `createQueryBuilder()` without the
join would return another tenant's invoices to a signed-in user. The privilege split
(`keelson_owner` owns the schema, `keelson_app` runs the API) already existed, which
is the prerequisite (2) needs.

## Decision

Ship both layers, with the database as the guarantee:

- Migration `Version20261121120000` enables **and forces** row-level security on
  `customers`, `invoices`, `invitations` and `notifications`, and
  `Version20261201130000` applies the same policy to `organization_ai_settings` and
  `ai_requests`, each with

  ```sql
  USING (organization_id = current_setting('app.organization_id', true)::uuid)
  WITH CHECK (organization_id = current_setting('app.organization_id', true)::uuid)
  ```

  `FORCE` is the load-bearing keyword: without it the table owner is exempt, and a
  deployment whose application role happens to own the schema would silently have
  no isolation at all.
- `App\Tenancy\TenantContext` sets the value with
  `set_config('app.organization_id', …, true)`. The third argument makes it
  **transaction-local**, so it is discarded on commit or rollback and cannot leak
  to the next request that reuses the worker's connection. A plain `SET` would leak;
  that is why this is a class and not a one-liner.
- `App\EventListener\TenantIsolationListener` opens the request's transaction (it has
  to — a transaction-local setting needs a transaction), sets the tenant when the
  request has a route `organizationId` and the caller has an **active membership**
  in it, and resolves that transaction on response, exception or termination. It
  runs at `KernelEvents::REQUEST` priority 0, after the firewall (8) and after the
  access listener (1) that consults the voters, so a denied request never opens a
  transaction. A transaction it did not open — a test harness, a wrapping
  decorator — is joined and left alone, exactly as `TransactionBoundary` already
  behaves.
- Console commands and fixtures state their tenant explicitly through
  `TenantContext::run($organizationId, callable)`. `app:invoices:mark-overdue`
  now enumerates active **organizations** rather than running a `DISTINCT` over
  `invoices`: an unscoped read of a tenant table returns nothing under the policies,
  so the tenant has to be known before its rows can be selected.

### Two deliberate exceptions

**`invoice_lines` does not exist.** Line items are rows inside the JSON column
`invoices.line_items`. The migration applies the same policy inside a
`to_regclass('public.invoice_lines')` guard, so a future normalised table is
protected rather than silently added outside the policy.

**`notifications` are per recipient across organizations.** The product's inbox is
"everything addressed to me", not "everything in the tenant I am currently in", so
its policy also accepts rows where
`principal_id = current_setting('app.principal_id', true)::uuid`; the listener sets
that for every authenticated request. Its `WITH CHECK` stays **tenant-only**: a
notification is always written inside the tenant it belongs to. Applying the tenant
predicate alone would have emptied the inbox for any user in more than one
organization.

**Identity tables stay application-layer on purpose.** `principals`, `memberships`,
`auth_tokens` and `sessions` are not tenant data: a session must be readable before
any tenant is known, and a membership is what establishes tenancy in the first place.
A policy on them would make sign-in impossible. Authorization for them remains the
voters and the repositories.

### The cost, stated plainly

- **One transaction per authenticated request**, open from routing until the response
  is built. That is a connection (and a snapshot) held for the request's duration,
  exactly as an explicit `BEGIN` in the controller would be. It is the price of a
  transaction-local setting; a session-level `SET` would be cheaper and would leak
  across requests on a reused connection.
- **`/readyz` is now a real gate.** It fails when the connecting role is a superuser
  or has `BYPASSRLS`, because either attribute silently exempts it from every policy.
  A deployment that "looks" protected but is not must not report ready.
- **Every future tenant table needs a migration** with `ENABLE`/`FORCE` and a policy.
  Forgetting one leaves that table on the application layer alone; that is now a
  schema-review item, not an implicit default.

## Consequences

- A query that forgets its tenant filter returns **no rows** instead of another
  tenant's rows. `tests/Integration/RowLevelSecurityTest.php` proves this on a
  second connection as a role that is neither the owner nor `BYPASSRLS`, with no
  `WHERE` clause at all, and proves that a missing variable yields zero rows rather
  than everything.
- The application filters stay. They are now defence in depth and the thing that
  produces a `404` (rather than an empty list) for a foreign identifier.
- Cross-tenant writes are refused by the database: `WITH CHECK` rejects a row whose
  `organization_id` is not the current tenant, even from a hand-written `INSERT`.
- The test suite still runs on an owner/superuser connection in CI, which bypasses
  the policies; the RLS assertions use a dedicated non-`BYPASSRLS` probe role. Running
  the whole suite as `keelson_app` is a follow-up, recorded in
  [limitations.md](../limitations.md): every suite that seeds tenant rows would have
  to wrap its writes in `TenantContext`, which is a separate change.
