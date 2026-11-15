# Keelson API contract (verified against the Symfony source)

**Verified on 2026-11-15** by reading `backend/src/Controller/`, `backend/src/Repository/`,
`backend/src/Service/` and `backend/src/Security/`, and by running the backend suite
(`php bin/phpunit` → 97 tests / 527 assertions, exit 0 in the Sprint 3 run; Sprint 2's 86/450 result is historical) plus the frontend suite against the same
shapes. This document describes **behaviour that exists today**, not a target design. Anything the
frontend needs but the backend does not provide is listed in
[Gaps](#gaps-and-how-each-was-classified).

Base URL: same origin as the SPA. In development the Vite server proxies `/api`, `/login`,
`/login_check` and `/logout` to Symfony (`frontend/vite.config.ts`, target
`KEELSON_BACKEND_ORIGIN`, default `http://127.0.0.1:8000`).

All responses are JSON with `Cache-Control: no-store, private`, except `204 No Content` replies
and HTML error pages produced by Symfony itself.

Errors always use one shape:

```json
{ "error": { "message": "Human-readable, safe to display." } }
```

## 1. Authentication

| Property | Value |
| --- | --- |
| Mechanism | OIDC authorization-code + PKCE (Logto through `drenso/oauth2-oidc-bundle`), server-side PHP session |
| Transport | `HttpOnly` Symfony session cookie; **no** bearer token, **no** API key |
| Client requirement | `credentials: 'same-origin'` (the SPA is served from the API origin or through the dev proxy) |
| Entry point | `GET /login` → `302` to the provider authorization endpoint |
| Callback | `GET /login_check` (must be answered by Symfony, never by the SPA) |
| Sign-out | `POST /logout` with a CSRF field, `ROLE_USER` |

`config/packages/security.yaml` access control, in order:

1. `^/login$` — `PUBLIC_ACCESS`
2. `^/api/session$` — `PUBLIC_ACCESS` (the shell must be able to learn it is signed out)
3. `^/logout$` — `ROLE_USER`
4. `^/healthz$` — `PUBLIC_ACCESS`
5. `^/` — `ROLE_USER`

Consequences the frontend must handle:

- An anonymous `fetch('/api/…')` is **not** answered with `401`; the firewall redirects to `/login`,
  which redirects to the provider, and `fetch` follows both hops to an **HTML 200** page.
  `frontend/src/api/client.ts` maps an HTML success body on an API path to an
  `ApiError(kind: 'http', status: 401)` so the UI shows a session-ended state instead of a parse
  error. Tested in `client.test.ts` ("reads a followed sign-in page as an ended session").
- `/api/session` is the only endpoint the shell may call before authentication.

### `GET /api/session`

Public, no database access. Used by `frontend/src/session/session.ts`.

```json
{
  "authenticated": true,
  "user": { "id": "9c5ca65a-…", "email": "owner@example.test", "displayName": "Owner Example" },
  "logout": { "url": "/logout", "csrfToken": "…" }
}
```

When signed out: `{"authenticated": false, "user": null, "logout": null}`.

`displayName` and `email` may be `null` depending on the ID-token claims. `logout` is `null` while
anonymous because the endpoint deliberately avoids creating a session just to hand out a token.

### `POST /logout`

Form-encoded `_csrf_token` taken from the `logout.csrfToken` above. On success the session is
invalidated and `App\EventListener\OidcEndSessionLogoutListener` redirects to the provider's
`end_session_endpoint` (`id_token_hint`, `post_logout_redirect_uri`); if the provider cannot be
reached it degrades to the local redirect (`302 /`) instead of failing. Anonymous or missing-token
requests do not invalidate anything (covered by `SessionLogoutTest`, `OidcSessionLogoutTest`).

## 2. Organizations

### `GET /api/me/organizations` — added in this sprint

There was **no** endpoint that told the browser which organizations the caller belongs to, so the
tenant-scoped routes below were unreachable from a real session. This read-only endpoint was added
(`backend/src/Controller/MeOrganizationsController.php`) rather than hard-coding tenant IDs in the
frontend.

- Auth: `ROLE_USER`. Anonymous → `302 /login` (asserted by `MeOrganizationsTest`).
- Request: no parameters. Any `organizationId` in the query string is ignored.
- Response `200`:

```json
{ "items": [ { "id": "…", "name": "Atelier Noura", "subdomain": "atelier-noura", "role": "Owner" } ] }
```

- Scope: the caller's **active** memberships on **active** organizations
  (`MembershipRepository::findActiveForPrincipal`). Suspended memberships and suspended
  organizations are omitted.
- `role` ∈ `Owner`, `Admin`, `Member`, `Viewer`. It is informational: every tenant endpoint still
  authorizes through `InvoiceVoter`, so the field cannot widen access.
- Tests: `backend/tests/Integration/MeOrganizationsTest.php` — 4 tests covering projection keys,
  both memberships, cross-tenant omission, suspended membership/organization omission, anonymous
  redirect and query-parameter tampering.

### Organization switching

There is no switch endpoint and no server-side "active organization" concept. The organization is a
path parameter, re-authorized per request. The SPA keeps the selection in component state only, and
a selection the server no longer recognizes yields the usual `404`/empty result. **A client-selected
ID never authenticates anything.**

## 3. Authorization model (`App\Security\InvoiceVoter`)

| Capability | Attributes | Roles allowed |
| --- | --- | --- |
| Read customers/invoices | `INVOICE_READ` | `Owner`, `Admin`, `Member`, `Viewer` |
| Create/update/send/mark-paid | `INVOICE_WRITE` | `Owner`, `Admin`, `Member` |
| Delete a draft | `INVOICE_DELETE_DRAFT` | `Owner`, `Admin` |

The voter requires an **active** membership on an **active** organization (`findActiveForPrincipal`
style lookup through `MembershipRepository`). A denial for a foreign, suspended or unknown tenant is
returned as `404 {"error":{"message":"Resource not found."}}` — deliberately indistinguishable from a
genuinely missing record — never `403`. `403` is reserved for a mutation whose origin/CSRF check
fails.

The frontend mirrors these three capabilities in `frontend/src/api/organizations.ts`
(`canWriteInvoiceResources`, `canDeleteInvoiceResources`) **only to hide actions that would fail**;
the server remains authoritative. Role strings are compared case-sensitively as returned by the API.

### Mutation guard (CSRF / origin)

Every non-safe request must satisfy **one** of:

1. `X-CSRF-Token: <token for id "invoice_api">`, or
2. `Origin` equal to `getSchemeAndHttpHost()`.

Otherwise `403 {"error":{"message":"A valid same-origin request is required."}}`. The SPA sends no
`X-CSRF-Token` (no token endpoint exposes it) and relies on the browser-supplied same-origin
`Origin` header, which the Vite proxy preserves (`changeOrigin: false`). Do not invent a CSRF header
or a token route.

## 4. Customers

Routes from `InvoiceApiController` (`debug:router --env=test`):

| Method | Path | Auth | Success |
| --- | --- | --- | --- |
| GET | `/api/organizations/{organizationId}/customers` | READ | `200` |
| POST | `/api/organizations/{organizationId}/customers` | WRITE | `201` |
| GET | `/api/organizations/{organizationId}/customers/{customerId}` | READ | `200` |
| PATCH | `/api/organizations/{organizationId}/customers/{customerId}` | WRITE | `200` |
| DELETE | `/api/organizations/{organizationId}/customers/{customerId}` | DELETE_DRAFT | `204` |

Deleting a customer uses the same `INVOICE_DELETE_DRAFT` capability as deleting a draft invoice, so
`Owner` and `Admin` only — a `Member` gets `404`. The frontend hides the action for a `Member`
accordingly, and still handles the denial if the role changed in another tab.

### List

Query parameters:

| Name | Type | Default | Notes |
| --- | --- | --- | --- |
| `cursor` | string (customer UUID) | — | `customer.id > cursor`, ordered by `id ASC` |
| `limit` | int | `50` | clamped to `1…100` |

```json
{ "items": [ { "id": "…", "name": "Maison Jasmin", "email": "…", "phone": "…" } ], "nextCursor": null }
```

`billingAddress` is **intentionally absent** from list rows (`InvoiceSerializer::customer($c, false)`).
`nextCursor` is the last row's id when more rows exist, otherwise `null`. There is **no** server-side
search: the frontend filters the loaded page client-side and says so when `nextCursor` is non-null.

### Read / create / update

Read and create/update responses include the address, which is `null` or an object with exactly
four string fields:

```json
{
  "customer": {
    "id": "…", "name": "Maison Jasmin", "email": "facturation@…", "phone": "+216 …",
    "billingAddress": { "street": "12 rue de Marseille", "city": "Tunis", "postalCode": "1000", "countryCode": "TN" }
  }
}
```

Create body: `{"name": "...", "email": null, "phone": null, "billingAddress": null | {...}}`.
`email` and `phone` are optional/nullable; `billingAddress` is all-or-nothing.

Validation (`App\Entity\Customer::apply`):

| Rule | Failure |
| --- | --- |
| `name` 1–160 characters after trim | `422` `Customer name must contain 1 to 160 characters.` |
| `phone` 1–40 characters when provided | `422` `Customer phone must contain 1 to 40 characters when provided.` |
| `billingAddress` keys exactly `street`, `city`, `postalCode`, `countryCode` | `422` `Billing address must include street, city, postalCode, and countryCode only.` |
| every address field 1–160 characters | `422` `Billing address fields must contain 1 to 160 characters.` |
| `countryCode` is upper-cased by the entity | — |

PATCH accepts only the mutable subset. An empty or wholly unsupported patch is
`400 Customer patch contains no mutable fields or unsupported fields.` Unknown keys are rejected, not
silently ignored. The frontend sends only changed fields (`updateCustomer`).

### Delete

`204` on success. The row is locked (`FOR UPDATE`) before the reference check so a concurrent invoice
insert cannot race the delete (`CustomerDeletionConcurrencyTest`).

`409 {"error":{"message":"Customer is referenced by invoices."}}` when the foreign key is violated.
Only a foreign-key violation maps to `409`; other database errors propagate as `500`.

## 5. Invoices

| Method | Path | Auth | Success |
| --- | --- | --- | --- |
| GET | `/api/organizations/{organizationId}/invoices` | READ | `200` |
| POST | `/api/organizations/{organizationId}/invoices` | WRITE | `201` |
| GET | `/api/organizations/{organizationId}/invoices/{invoiceId}` | READ | `200` |
| PATCH | `/api/organizations/{organizationId}/invoices/{invoiceId}` | WRITE | `200` |
| POST | `/api/organizations/{organizationId}/invoices/{invoiceId}/send` | WRITE | `200` |
| POST | `/api/organizations/{organizationId}/invoices/{invoiceId}/mark-paid` | WRITE | `200` |
| DELETE | `/api/organizations/{organizationId}/invoices/{invoiceId}` | DELETE_DRAFT | `204` |
| GET | `/api/organizations/{organizationId}/invoice-audit-events` | READ | `200` |

### Invoice representation

```json
{
  "invoice": {
    "id": "…",
    "number": "2026-000001",
    "customerId": "…",
    "customer": { "id": "…", "name": "…", "email": null, "phone": null, "billingAddress": { … } },
    "status": "draft",
    "issueDate": "2026-11-01",
    "dueDate": "2026-11-15",
    "currency": "TND",
    "lineItems": [ { "description": "Work", "quantity": "1.000", "unitPrice": "10.000", "lineTotal": "10.000" } ],
    "taxRate": "19.0000",
    "discountAmount": "0.000",
    "subtotal": "10.000",
    "taxAmount": "1.900",
    "total": "11.900",
    "sentAt": null,
    "paidAt": null
  }
}
```

- `customer` is the **snapshot** stored on the invoice at creation time, not the live customer row.
- `status` is `effectiveStatus()`: `draft`, `sent`, `paid`, or `overdue` **derived** for a `sent`
  invoice whose `dueDate` is in the past. Reads never write invoice state.
- Amounts are **decimal strings**, scaled to 3 places (millimes; currency is `TND` only).
  `taxRate` has up to 4 decimals. `sentAt`/`paidAt` are ISO-8601 or `null`.
- `lineTotal` and all totals are computed by `InvoiceCalculator` on the server. The frontend never
  posts totals and never treats its own arithmetic as authoritative; a draft shows an estimate
  labelled as such until the server returns numbers.

### List

| Name | Type | Default | Notes |
| --- | --- | --- | --- |
| `status` | `all` \| `draft` \| `sent` \| `paid` \| `overdue` | `all` | anything else → `400 Invoice status filter is invalid.` |
| `cursor` | base64url JSON `{id, createdAt}` | — | malformed → `400 Invoice cursor is invalid.` |
| `limit` | int | `50` | clamped `1…100` |

`sent` excludes overdue invoices (`dueDate >= today`); `overdue` includes stored `overdue` rows and
`sent` rows past due. Response: `{"items": [...], "nextCursor": "…" | null}`.

The frontend requests `limit=100` (the maximum) and paginates the loaded page client-side, showing a
"partial results" note when `nextCursor` is non-null.

### Draft body (create and PATCH)

```json
{
  "customerId": "uuid",
  "issueDate": "2026-11-01",
  "dueDate": "2026-11-15",
  "currency": "TND",
  "lineItems": [ { "description": "Work", "quantity": "1.000", "unitPrice": "10.000" } ],
  "taxRate": "19",
  "discountAmount": "0"
}
```

Validation (all failures are `422` with the message shown):

| Rule | Message |
| --- | --- |
| `customerId` is a UUID | `422 customerId must be a UUID.` |
| customer must exist in this organization | `404 Resource not found.` |
| dates are `YYYY-MM-DD` calendar dates | `<field> must be a valid calendar date in YYYY-MM-DD format.` |
| 1–100 line items | `Invoice must contain between 1 and 100 line items.` |
| description 1–240 characters | `Line item description must contain 1 to 240 characters.` |
| quantity positive | `Line item quantity must be positive.` |
| amounts are non-negative decimal strings, no exponent | `<field> must be a non-negative decimal without exponent notation.` |
| scale limits: `quantity`/`unitPrice`/`discountAmount` 3, `taxRate` 4 | `<field> supports at most N decimal places.` |

`currency` must be `TND`. Totals in the body are ignored; unknown fields are rejected.

### Lifecycle

| Operation | Allowed from | Result |
| --- | --- | --- |
| PATCH | `draft` | `200`, or `409 Only draft invoices may be edited.` |
| `send` | `draft` | `200`; on a non-draft invoice returns the unchanged invoice as a `200` no-op |
| `mark-paid` | `sent` / `overdue` | `200`; an already-`paid` invoice is the same `200` no-op; any other state is `409` |
| DELETE | `draft` | `204`, or `409 Only draft invoices may be deleted.` |

Transitions run inside one transaction with a row lock (`SELECT … FOR UPDATE`), write the audit event
in the same flush, and are the only place status changes. Number allocation takes a per-tenant/year
advisory lock; a collision is `409 Invoice number allocation conflicted; retry the request.`
Concurrency suites: `InvoiceDraftConcurrencyTest`, `InvoiceNumberAllocationTest`,
`AuditAtomicityTest`, `InvoiceAuthorizationMatrixTest`.

The frontend treats any `409` on a lifecycle call as "the record moved on": it shows the server's
explanation and refetches the invoice (`isConflict` in `frontend/src/api/presentError.ts`). It never
retries a mutation automatically.

## 6. Status codes the frontend maps

| Status | Meaning here | Frontend behaviour |
| --- | --- | --- |
| `400` | malformed patch/status/cursor, invalid JSON body | localized generic invalid-input message |
| `401` | never emitted by the API; produced by the client for a followed sign-in page | session-ended message |
| `403` | origin/CSRF guard failed | permission-denied message |
| `404` | missing record **or** not a member / suspended tenant | "not found" message; the list is refreshed |
| `409` | delete blocked by invoices, non-draft edit/delete, number collision | conflict explanation + refetch |
| `422` | validation | the server's message, shown at form level |
| `500` | unexpected server/proxy error | generic message; internals never displayed |

## 7. Gaps and how each was classified

| Gap | Classification | Resolution |
| --- | --- | --- |
| No endpoint listing the caller's organizations | Missing backend capability | **Added** `GET /api/me/organizations` (read-only, membership-scoped, 4 tests) |
| No server-side organization switch | Product decision requiring approval | Not built; the SPA keeps a client-side selection and the server re-authorizes every request |
| No server-side customer/invoice search or sort | Existing endpoint with insufficient capability | Client-side filter/sort over the loaded page, with a visible "partial results" note when `nextCursor` is set |
| No `billingAddress` in customer list rows | Existing endpoint with insufficient response data | The list shows name/email/phone; the address comes from the read endpoint |
| No profile write endpoint | Missing backend capability | `AccountScreen` is read-only and says so |
| Organizations, invitations, team, billing, pricing, dashboard metrics, notifications | Frontend-only demo surfaces with no backend | Left as mock data, clearly labelled, and they never mutate the real workspace list |
| No component/DOM test runner (no `jsdom`, no Testing Library) | Dependency approval required | Tests stay at the API boundary in the Node environment; component tests deferred |

## 8. How this document was verified

- Routes: `php bin/console debug:router --env=test` (15 `/api/…` routes: session, memberships, 5 customer, 7 invoice, audit — all listed above).
- Shapes and validation: the controllers, `InvoiceSerializer`, `InvoiceCalculator`, `InvoiceInput`,
  `Customer`, `CustomerRepository`, `InvoiceRepository`, `InvoiceVoter`, `security.yaml`.
- Behaviour: `php bin/phpunit` → **97 tests / 527 assertions**, exit 0 in the Sprint 3 run, against the helper's disposable PostgreSQL 16 container (never the Compose database). Sprint 2's earlier run was 86 tests / 450 assertions.
- Frontend agreement: `pnpm test` → 56 tests asserting the exact paths, methods, bodies, credentials,
  status handling and error mapping described here.

Not verified: a real Logto sign-in/callback/sign-out round trip. This checkout has no
`backend/.env.local` and no provider client credentials, so `/api/session` cannot even construct the
OIDC client (it answers `500 Environment variable not found: "OIDC_CLIENT_ID"`). The authenticated
flows above are verified by the backend functional tests with a synthetic security token, not against
a live provider.
