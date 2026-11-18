# Keelson API contract (prose companion)

> **Status at 1.0.0:** the authoritative contract is
> [`docs/openapi.yaml`](openapi.yaml), and a test
> (`backend/tests/Unit/Docs/OpenApiContractTest.php`) fails when its paths stop
> matching the router. This file is the prose behind those shapes — why a status
> code is what it is, what a denial means, how a flow was verified — and it is kept
> for that reasoning, not as a second source of truth. Sections written during an
> earlier sprint keep their own wording; where a number disagreed with the suite it
> was updated on 2026-11-17 (counts: `bash bin/test-counts.sh`).

**Verified on 2026-11-16** by reading `backend/src/Controller/`, `backend/src/Repository/`,
`backend/src/Service/`, `backend/src/Security/` and `config/packages/security.yaml`, by running the
backend suite (`php bin/phpunit`, exit 0 — counts from `bash bin/test-counts.sh`) and by exercising every
sign-in flow with `curl` against a local server with Mailpit. This document describes **behaviour
that exists today**, not a target design. Anything the frontend needs but the backend does not
provide is listed in [Gaps](#gaps-and-how-each-was-classified).

Base URL: same origin as the SPA. In development the Vite server proxies only `/api` to Symfony
(`frontend/vite.config.ts`, target `KEELSON_BACKEND_ORIGIN`, default `http://127.0.0.1:8000`); every
other path is a client-side route served by the SPA.

All responses are JSON with `Cache-Control: no-store, private`, except `204 No Content` replies
and HTML error pages produced by Symfony itself.

Errors always use one shape:

```json
{ "error": { "code": "validation_failed", "message": "Human-readable, safe to display.", "fields": { "password": ["…"] } } }
```

`code` is stable and machine-readable; `fields` appears only when a specific input is at fault.

## 1. Authentication

| Property | Value |
| --- | --- |
| Mechanism | First-party email + password (`json_login`), server-side PHP session |
| Transport | `HttpOnly` Symfony session cookie; **no** bearer token, **no** API key |
| Password storage | `password_hash` via Symfony's password hasher; plaintext never stored or logged |
| Client requirement | `credentials: 'same-origin'` (the SPA is served from the API origin or through the dev proxy) |
| Sign-in | `POST /api/auth/login` (JSON `{email, password}`) |
| Sign-out | `POST /api/auth/logout` with `X-CSRF-Token` |

`config/packages/security.yaml` access control, in order:

1. `^/api/auth/(register|verify-email|login|forgot-password|reset-password|resend-verification)$` —
   `PUBLIC_ACCESS`
2. `^/api/session$` — `PUBLIC_ACCESS` (the shell must be able to learn it is signed out)
3. `^/healthz$`, `^/readyz$` — `PUBLIC_ACCESS`
4. `^/` — `ROLE_USER`

Consequences the frontend must handle:

- An anonymous `fetch('/api/…')` is answered `401 {"error":{"code":"unauthenticated",…}}`.
  There is no sign-in page to redirect to, so no HTML is ever returned for an API path.
- `/api/session` is the only endpoint the shell may call before authentication.
- Sign-in is JSON, not form-encoded: send `Content-Type: application/json` and
  `{ "email": "…", "password": "…" }`.

### `GET /api/session`

Public, no database access. Used by `frontend/src/session/session.ts`.

```json
{
  "authenticated": true,
  "user": { "id": "9c5ca65a-…", "email": "owner@example.test", "displayName": "Owner Example" },
  "csrfToken": "…"
}
```

When signed out: `{"authenticated": false, "user": null, "csrfToken": null}`.

`email` is always present and lower-cased; `displayName` may be `null`. `csrfToken` is `null` while
anonymous because the endpoint deliberately avoids creating a session just to hand out a token.

### `POST /api/auth/login`

JSON body `{"email": "…", "password": "…"}`. Success `200` returns **exactly** the session
payload above, so the SPA can treat the login response as its session.

| Failure | Status | `code` |
| --- | --- | --- |
| Wrong password, unknown address, malformed body | `401` | `invalid_credentials` |
| Password correct but the address is unconfirmed | `403` | `email_not_verified` |
| More than five attempts for one address in a minute | `429` + `Retry-After` | `too_many_login_attempts` |

Wrong password and unknown address are deliberately identical. `email_not_verified` is only
reachable after the password has been accepted, so it cannot be used to discover accounts.

### `POST /api/auth/register`

JSON body `{"email", "password", "organizationName", "displayName"?}`. Creates the principal, its
first organization and an `Owner` membership in one transaction and mails a verification link to
`FRONTEND_URL/verify-email?token=…`.

- `201 {"status": "verification_sent"}` — always, including when the address already exists. In that
  case nothing is written and nothing is sent, so the endpoint cannot be used to discover accounts.
- `422 validation_failed` with `fields` — email format, password length (10…72), organization name
  (2…120), display name (≤120).
- `400 invalid_json` — the body is not a JSON object.

### `POST /api/auth/verify-email`

JSON body `{"token": "…"}`. `204` on success. Unknown, expired (24 h) and already-used tokens all
return `400 invalid_token`.

### `POST /api/auth/forgot-password`

JSON body `{"email": "…"}`. Always `202`, and a reset link (valid 1 h) is mailed only when the
account exists.

### `POST /api/auth/reset-password`

JSON body `{"token", "password"}`. `204` on success; the address is also marked verified, because
receiving the link proves control of the mailbox. Changing the hash signs every other session out
(Symfony's context listener compares the stored hash on the next request). Reused or expired tokens
return `400 invalid_token`.

### `POST /api/auth/resend-verification`

JSON body `{"email": "…"}`. Added in Sprint 3 so the sign-in screen can offer a
"Resend the email" action when the password was right but the address is unconfirmed.

- Auth: public, so the visitor does not need a session they cannot get yet.
- Always `202` with an empty body — for an unknown address, for an address that is
already confirmed, and for one that is waiting. A fresh link is mailed only in the
last case, so the endpoint neither reveals nor probes accounts.
- Issuing the new token retires the previous unused verification link.
- Throttled like sign-in, in its own buckets: 5 requests per address and 100 per
  client address per minute, then `429 too_many_resend_attempts` with `Retry-After`.
  Separate buckets mean flooding this endpoint cannot lock the account out of
  signing in once it is confirmed.
- `422 validation_failed` when the body is not a valid address (a malformed address
  is a client mistake, not an account probe).

### `POST /api/auth/logout`

No body. Requires `X-CSRF-Token`; `204` and the session is invalidated. Anonymous calls answer `401`,
a missing or wrong token answers `403 csrf_invalid` and leaves the session alone
(`SessionLogoutTest`, `CsrfProtectionTest`).

## 2. Organizations

### `GET /api/me/organizations` — added in this sprint

There was **no** endpoint that told the browser which organizations the caller belongs to, so the
tenant-scoped routes below were unreachable from a real session. This read-only endpoint was added
(`backend/src/Controller/MeOrganizationsController.php`) rather than hard-coding tenant IDs in the
frontend.

- Auth: `ROLE_USER`. Anonymous → `401 unauthenticated` (asserted by `MeOrganizationsTest`).
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

Two rules, enforced by `App\EventListener\CsrfProtectionListener` for every `POST`/`PUT`/`PATCH`/
`DELETE`:

1. **Origin.** When the request carries `Origin`, it must equal `getSchemeAndHttpHost()`.
   Otherwise `403 csrf_invalid`. This applies to the authentication routes too and runs *before*
   the firewall, so a cross-site attempt never reaches the password check.
2. **CSRF token.** An authenticated unsafe request under `/api` must send
   `X-CSRF-Token: <csrfToken from GET /api/session>`. The only exemptions are the unauthenticated
   auth endpoints (`login`, `register`, `verify-email`, `forgot-password`, `reset-password`), because
   their caller has no session to protect; they still obey the origin rule. Logout is **not**
   exempt.

Failures are `403 {"error":{"code":"csrf_invalid","message":"A valid CSRF token is required for this request."}}`.
The token is the `csrfToken` field of the session payload, minted for the id `api`.

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
| `400` | malformed patch/status/cursor (`invalid_id`), invalid JSON body (`invalid_json`), unusable token (`invalid_token`) | localized generic invalid-input message |
| `401` | not authenticated (`unauthenticated`) or failed sign-in (`invalid_credentials`) | session-ended or sign-in message |
| `403` | origin/CSRF guard failed (`csrf_invalid`), or the address is unconfirmed (`email_not_verified`) | permission-denied or "confirm your email" message |
| `404` | missing record **or** not a member / suspended tenant | "not found" message; the list is refreshed |
| `409` | delete blocked by invoices, non-draft edit/delete, number collision | conflict explanation + refetch |
| `422` | validation (`validation_failed`, with `fields`) | the server's per-field messages, shown at form level |
| `429` | too many sign-in attempts (`too_many_login_attempts`) | back-off message; respect `Retry-After` |
| `500` | unexpected server/proxy error | generic message; internals never displayed |

## 7. Gaps and how each was classified

| Gap | Classification | Resolution |
| --- | --- | --- |
| No endpoint listing the caller's organizations | Missing backend capability | **Added** `GET /api/me/organizations` (read-only, membership-scoped, 4 tests) |
| No server-side organization switch | Product decision requiring approval | Not built; the SPA keeps a client-side selection and the server re-authorizes every request |
| No server-side customer/invoice search or sort | Existing endpoint with insufficient capability | Client-side filter/sort over the loaded page, with a visible "partial results" note when `nextCursor` is set |
| No `billingAddress` in customer list rows | Existing endpoint with insufficient response data | The list shows name/email/phone; the address comes from the read endpoint |
| No profile write endpoint | Missing backend capability | `AccountScreen` is read-only and says so |
| Organizations, invitations, team, dashboard metrics, notifications | Frontend-only demo surfaces with no backend | **Resolved in sprint 5**: all of them now have endpoints and the SPA renders real data. Billing and pricing remain unbuilt — there is no provider integration and no endpoint, and nothing in the SPA pretends otherwise |
| No component/DOM test runner (no `jsdom`, no Testing Library) | Dependency approval required | **Resolved in sprint 8**: `jsdom` and Testing Library are approved dev dependencies and 15 component/unit files run in two vitest projects |

## 8. How this document was verified

- Routes: `php bin/console debug:router --env=test` (session, memberships, 5 customer, 7 invoice,
  audit and 6 auth routes — all listed above).
- Shapes and validation: the controllers, `InputHydrator`, `JsonBody`, `ApiErrorResponse`,
  `InvoiceSerializer`, `InvoiceCalculator`, `InvoiceInput`, `Customer`, `CustomerRepository`,
  `InvoiceRepository`, `InvoiceVoter`, `security.yaml`, `CsrfProtectionListener`.
- Behaviour: `php bin/phpunit` exits 0 against the helper's
  disposable PostgreSQL 16 container (never the Compose database). At 1.0.0 the same behaviour is
  also exercised end to end by the seven Playwright journeys in `e2e/tests/`.
- Manual round trip: register → Mailpit → verify → login → `GET /api/session` → invoice `POST`
  without and with `X-CSRF-Token` → logout → forgot → reset, all with `curl` against
  `php -S 127.0.0.1:8000 -t public public/index.php` and Mailpit on `http://localhost:8026`.
- Frontend agreement: `frontend/src/api/` consumes exactly these shapes, and the journey suite
  fails if a screen and the API disagree about one.

## 9. Sprint 5: organizations, team, invitations

Every tenant route below authorizes through `OrganizationVoter` (active membership on an active
organization). A denial is `404 not_found`, like the invoice API: a caller must not be able to tell
a foreign tenant from a missing one. Only an Owner may rename, readdress or delete the tenant.

### Organizations

| Method | Path | Who | Success |
| --- | --- | --- | --- |
| `POST` | `/api/organizations` | any signed-in principal | `201` `{organization:{id,name,subdomain,status,role:"Owner",createdAt}}` |
| `PATCH` | `/api/organizations/{id}` | Owner | `200` same shape |
| `DELETE` | `/api/organizations/{id}` | Owner | `200`, organization `status:"deleted"` |
| `GET` | `/api/organizations/subdomain-availability?value=` | any signed-in principal | **always** `200` `{available:boolean}` |

- `POST` body `{name, subdomain}`. `name` 2–120 characters; `subdomain` 3–63 characters matching
  `^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$`, stored lower-cased. Reserved hostnames (`www`, `api`,
  `admin`, `app`, `auth`, `mail`, `dashboard`, `docs`, `status`, `support`, `billing`, …) are
  `422 validation_failed` with a `subdomain` field; a taken subdomain is
  `409 {"code":"subdomain_taken"}`. The creator's membership is created as `Owner` in the same
  transaction.
- `PATCH` body `{name?, subdomain?}`; neither field present is `422`. A duplicate subdomain is
  `409 subdomain_taken`.
- `DELETE` body `{confirmName}` must equal the organization name exactly; otherwise
  `422 validation_failed` with a `confirmName` field. Deletion is **soft**: the row keeps
  `status:"deleted"`, memberships, invoices and audit events stay, and every read path filters the
  tenant out (further requests answer `404`). The subdomain stays taken.
- `subdomain-availability` is rate limited per principal (60/minute, `429 too_many_requests`). It is
  a hint for the create form: an invalid or reserved value simply answers `false`.

### Members

| Method | Path | Who | Success |
| --- | --- | --- | --- |
| `GET` | `/api/organizations/{id}/members` | any active member | `200 {items:[{id,principalId,email,name,role,joinedAt}],nextCursor:null}` |
| `PATCH` | `…/members/{membershipId}` | Owner, or Admin for Member/Viewer targets | `200 {member:{…}}` |
| `DELETE` | `…/members/{membershipId}` | Owner/Admin, or the member themself (leave) | `204` |

Rules (each is a test in `TeamInvitationTest`):

- An Owner may change or remove anyone.
- An Admin may only change/remove a **Member** or **Viewer**, and may never grant `Admin` or
  `Owner`. Anything else is `404`.
- A Member or a Viewer cannot manage anybody (`404`); they may still remove their own membership.
- The last Owner cannot be demoted or removed: `409 {"code":"last_owner"}`.
- Removal deletes the membership row, so the address can be invited again.

### Invitations

| Method | Path | Who | Success |
| --- | --- | --- | --- |
| `POST` | `/api/organizations/{id}/invitations` | Owner/Admin (Admin: Member/Viewer only) | `201 {invitation:{id,email,role,status,expired,expiresAt,createdAt}}` |
| `GET` | `…/invitations` | Owner/Admin | `200 {items:[…]}` |
| `POST` | `…/invitations/{invitationId}/resend` | Owner/Admin (same ceiling) | `200`, new token, `expiresAt` reset to +7 days |
| `DELETE` | `…/invitations/{invitationId}` | Owner/Admin (same ceiling) | `200`, `status:"revoked"` |
| `GET` | `/api/invitations/{token}` | **public** | `200 {organizationName,role,email,expired,accepted}` |
| `POST` | `/api/auth/accept-invitation` | public (or the invited account's session) | `201` when an account was created, else `200` |

- The plaintext token is never returned by an API: it is stored as a SHA-256 digest and appears only
  in the email (`FRONTEND_URL/accept-invitation?token=…`, built by `MailFactory::invitation()`).
- Duplicate pending invitation for the same address: `409 conflict`. Address already a member:
  `409 {"code":"already_member"}`. An Admin inviting as `Admin` is `404`.
- `resend` replaces the stored digest (the previous link stops working) and restarts the 7-day
  window; it is rate limited per invitation (5/minute → `429`). Only a pending invitation can be
  resent or revoked: `409 {"code":"invitation_not_pending"}`.
- The public preview returns nothing about account existence and nothing about the invitation id.
  An unknown token is `404`; an expired or used one still resolves so the page can explain itself.
- `accept-invitation` body `{token, password?, displayName?}`:
  - address has **no** account: `password` (10–72 characters) is required and the new principal is
    marked verified, because receiving the link proves mailbox control (`422` on `password` when
    missing);
  - address **has** an account: the caller must be signed in as it, otherwise
    `401 {"code":"sign_in_required"}` — a signed-in person cannot attach someone else's address;
  - accepting creates the membership, marks the invitation accepted, and writes `member_joined`
    notifications for the tenant's Owners and Admins (the new member excluded) plus
    `invitation_accepted` for the inviter;
  - an unusable token is `400 {"code":"invalid_token"}`.

## 10. Dashboard, notifications, profile

### `GET /api/organizations/{id}/dashboard`

Any active member. `200`:

```json
{
  "outstandingMillimes": 843000,
  "overdueMillimes": 312000,
  "overdueCount": 3,
  "paidThisMonthMillimes": 0,
  "customerCount": 12,
  "statusCounts": { "draft": 2, "sent": 5, "overdue": 3, "paid": 8 },
  "revenueByMonth": [{ "month": "2025-12", "paidMillimes": 0 }, "… 12 entries, oldest first …"],
  "recentInvoices": ["… five newest, the invoice shape from section 4 …"]
}
```

Money is integer millimes. `overdue` is derived from the due date exactly like the invoice read
path, so the dashboard and the list agree. The aggregates are four SQL statements (one filtered scan
for the counts and sums, one monthly grouping, one customer count, one ordered query for the recent
invoices): `DashboardApiTest::testTheDashboardCostsTheSameForManyInvoicesAsForFew` asserts the query
count is unchanged between a tenant with 3 invoices and one with 33.

### Notifications

| Method | Path | Success |
| --- | --- | --- |
| `GET` | `/api/notifications?limit&cursor&unread=1` | `200 {items:[{id,type,data,organizationId,read,createdAt,readAt}],nextCursor}` |
| `POST` | `/api/notifications/{id}/read` | `200 {notification:{…}}` |
| `POST` | `/api/notifications/read-all` | `200 {updated:n}` |

Own notifications only: another person's id is `404`. `limit` defaults to 25 (max 100); `cursor` is
opaque; a malformed cursor is `400 {"code":"invalid_cursor"}`. Marking a read notification again is a
no-op. Types: `invoice_overdue`, `invoice_paid`, `invitation_accepted`, `member_joined`. Automatic
notifications come from the `mark-paid` transition (recipients: Owners and Admins, actor excluded)
and from `app:invoices:mark-overdue`; they are deduplicated per recipient, type and invoice.

### Profile

| Method | Path | Body | Success |
| --- | --- | --- | --- |
| `PATCH` | `/api/me` | `{displayName}` | `200 {user:{id,email,displayName}}` |
| `POST` | `/api/me/password` | `{currentPassword,newPassword}` | `204` |

A wrong current password is `422 validation_failed` with a `currentPassword` field. On success the
stored hash changes, the **current** session is kept (its token is rewritten with the new hash) and
every other session is signed out by Symfony's hash comparison on its next request.

## 11. Lists: search and cursor pagination

`GET …/customers`, `…/invoices` and `…/invoice-audit-events` accept:

| Parameter | Meaning |
| --- | --- |
| `q` | case-insensitive “contains”: customer name or email; invoice number or customer name; audit action |
| `limit` | page size, default **25**, maximum 100 |
| `cursor` | opaque keyset cursor returned as `nextCursor`; malformed → `400 invalid_cursor` |

Responses keep their existing fields and add `nextCursor` (string or `null`). The cursor is the
base64url of `{id, createdAt}` and pages resume strictly after that row, so inserts during paging
cannot shift the window. Customers are now ordered by `created_at DESC, id DESC` (previously by
`id ASC`); invoices and audit events keep newest-first. Supporting indexes:
`(organization_id, created_at DESC, id DESC)` on all three tables, plus
`invoices (organization_id, due_date)` and `(organization_id, paid_at)` for the dashboard, and a
partial unique index on pending invitations per `(organization_id, lower(email))`.

## 12. Scheduled work

`php bin/console app:invoices:mark-overdue` processes each organization separately, in batches of
200 invoices with one short transaction per batch. It is idempotent (each batch re-selects only
`sent` invoices past their due date), creates the audit event and the `invoice_overdue`
notifications, and can be run at any frequency. Schedule it once a day; see the README.

## 10. Release hardening (2026-11-17)

Two behaviours changed in ways a client can observe.

**Registration subdomain collision.** Registration still answers
`201 {"status":"verification_sent"}` for an address that already exists (it must
not reveal that), but a *generated subdomain* that cannot be allocated is a
different failure. If all 50 candidate subdomains derived from the organization
name are taken, the request answers:

```json
{ "error": { "code": "subdomain_unavailable", "message": "That organization name is in use; choose another and retry." } }
```

and nothing is created: no principal, no organization, no token, no email. The
same code is returned if the subdomain insert loses a race with a concurrent
registration that used the same name. A client may treat it as a validation error
on `organizationName`.

**Token redemption.** `POST /api/auth/verify-email` and
`POST /api/auth/reset-password` redeem their token with a single conditional
`UPDATE ... RETURNING`, inside the same transaction as the account change. A
second request carrying the same link answers `400 invalid_token`; a failed
account change rolls the consumption back, so the link remains usable. The
response shapes are unchanged.

**401 handling.** Any authenticated endpoint may answer `401 unauthenticated`
when the session has expired. The SPA reacts by re-reading `GET /api/session`
rather than assuming a signed-out state.
