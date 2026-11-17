# AI API contract

Status: **foundation only.** The provider boundary, budget accounting, rate limits and
safety helpers exist; the two generative endpoints (`/api/ai/guide` and the organization
summaries) are routed stubs that answer `501`. This document is the contract other
tracks code against, so the request and response shapes below are fixed even where the
implementation is not there yet.

Nothing in this API is public: every endpoint requires an authenticated session and
every organization-scoped endpoint requires an **active membership** of the tenant in
the path. A foreign or unknown organization id is answered with the same `404` as a
missing one. `PUT` requests additionally need the `Origin` and `X-CSRF-Token` headers
documented in `docs/security.md`.

## Configuration

| Variable | Meaning |
| --- | --- |
| `AI_ENABLED` | Global deployment switch. `false` disables every AI call, whatever the organization asked for. |
| `AI_PROVIDER` | `fake` (deterministic, no network) or `openai` (HTTP provider). |
| `AI_API_KEY` | Provider credential. Never committed; supplied through the environment. An empty value means the HTTP provider is not configured. |
| `AI_MODEL` | Model identifier sent with each request. |
| `AI_BASE_URL` | Base URL of an OpenAI-compatible endpoint (the provider appends `/chat/completions`). |

The tracked `.env` contains placeholders only. Real values belong in `.env.local` (local)
or the deployment secret store (production).

## Errors

Every failure uses the shared envelope:

```json
{"error": {"code": "ai_budget_exceeded", "message": "The organization has used its AI token budget for this month."}}
```

| Code | HTTP | Meaning |
| --- | --- | --- |
| `ai_disabled` | `403` | The global switch is off, or this organization has not enabled AI. Nothing was sent to a provider. |
| `ai_budget_exceeded` | `402` | The reservation for this request would take the organization past its monthly token budget. Nothing was sent to a provider. |
| `ai_rate_limited` | `429` | The principal or organization rate limit was hit. Carries `Retry-After`. |
| `ai_unavailable` | `503` | The provider timed out, returned a server error twice, or answered with a body this API could not parse. `AiGateway` records a failed ledger row. |
| `validation_failed` | `422` | The body or settings are invalid. Same envelope, with `fields`. |
| `not_found` | `404` | Unknown tenant, or the caller has no active membership. |
| `not_implemented` | `501` | A routed stub: the contract exists, the generator does not. |

## `GET /api/ai/status`

The caller's AI eligibility. `organizationId` is an optional query parameter; without
it the caller's first active organization (oldest membership) is used. The endpoint
never calls a provider.

Response `200`:

```json
{
  "enabled": true,
  "organizationEnabled": true,
  "budgetRemainingTokens": 42000
}
```

- `enabled` is the global `AI_ENABLED` switch.
- `organizationEnabled` is the organization's stored opt-in (and `false` when the
  caller has no active organization).
- `budgetRemainingTokens` is `max(0, monthlyTokenBudget - usedTokens - reservedTokens)`
  for the current month, or the full budget when the stored period has rolled over.
  In-flight reservations are subtracted so two concurrent callers cannot both be told
  the same tokens are free.

Errors: `401`, plus `404` when an explicit `organizationId` is not the caller's.

## `GET /api/organizations/{organizationId}/ai-settings`

Owner or Admin only. Any other role, or a foreign tenant, gets `404`.

Response `200`:

```json
{
  "enabled": true,
  "monthlyTokenBudget": 50000,
  "budgetRemainingTokens": 42000
}
```

## `PUT /api/organizations/{organizationId}/ai-settings`

Owner or Admin only. Replaces both fields; any omitted field is invalid.

Request:

```json
{"enabled": true, "monthlyTokenBudget": 50000}
```

- `enabled` is a boolean.
- `monthlyTokenBudget` is an integer between `0` and `100000000`. `0` with
  `enabled: true` makes every call fail `ai_budget_exceeded`.

Response `200` has the same shape as `GET`. Turning the switch from off to on writes a
`security_events` row of type `ai_enabled` (organization id and budget, no prompt data).

Errors: `401`, `403` (`csrf_invalid`), `404`, `422`.

## `POST /api/ai/guide`

**Stub — answers `501`.** Reserved contract for the assistant that answers questions
about the product and the caller's own data.

Request:

```json
{"question": "How do I mark an invoice paid?"}
```

Success response `200` (once implemented):

```json
{
  "answer": "Open the invoice and choose Mark paid.",
  "sources": [
    {"title": "Invoices", "reference": "invoices.send"}
  ]
}
```

- `answer` is plain text: no HTML, no links and no markdown images.
- `sources` never carries a provider URL; `reference` is an internal identifier.

## `POST /api/organizations/{organizationId}/ai/summaries/{kind}`

**Stub — answers `501`.** `kind` is one of `organization`, `invoices`, `customers`.
Any active member may ask; the tenant is resolved through the membership.

Success response `200` (once implemented):

```json
{
  "text": "Three invoices are overdue and one customer accounts for most of the balance.",
  "generatedAt": "2026-12-01T10:15:30+00:00",
  "facts": {"overdueInvoices": 3, "outstandingTotal": "1240.00"}
}
```

`text` is sanitized plain text. `facts` holds aggregates the summary was built from, so
the client can label them without parsing prose.

## Budget accounting

`AiGateway` is the only entry point features use.

1. Resolve the caller's active membership (a foreign tenant is `404`).
2. Check `AI_ENABLED`, then the organization's opt-in.
3. Consume the principal and organization rate-limit buckets.
4. **Reserve** an estimate (`prompt characters / 4 + maxTokens`) with one conditional
   `UPDATE ... RETURNING`. The predicate is the whole budget rule, so PostgreSQL
   arbitrates two concurrent requests: a nearly exhausted budget cannot fund both.
5. Call the provider.
6. **Settle** in a second transaction: `used_tokens += real total`,
   `reserved_tokens -= estimate`, and insert the `ai_requests` ledger row. A failed
   call releases the reservation and writes a `failed` row instead.

`ai_requests` deliberately stores **no prompt and no answer text**: feature, status,
model, token counts, latency and an error code are enough to audit spend without
retaining customer content.

## Row-level security

PostgreSQL RLS does not exist on this branch (see `docs/adr/0003-postgresql-row-level-security.md`).
`organization_ai_settings` and `ai_requests` are therefore isolated by the application
layer only, exactly like the other tenant tables: reads are scoped by the membership
resolved before any query. When RLS lands, both tables need an `organization_id` policy
matching the rest of the schema. Both carry an `organization_id` index so a policy
filter and tenant-scoped reports stay cheap.
