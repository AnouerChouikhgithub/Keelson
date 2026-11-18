# AI API contract

Status: **implemented.** The provider boundary, budget accounting, rate limits and
safety helpers exist. `POST /api/ai/guide` answers from the bundled documentation and
`POST /api/organizations/{organizationId}/ai/summaries/{kind}` produces grounded
workspace summaries. Both features reach a provider only through `AiGateway`, so the
switch, the opt-in, the rate limits and the budget apply identically to each.

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

The product assistant. It answers from the bundled documentation only — never from
tenant data — and it names the articles it used.

Request:

```json
{"question": "How do I mark an invoice paid?", "locale": "en"}
```

- `question` is a string of 3 to 500 characters.
- `locale` is `en`, `fr` or `ar` and defaults to `en`. It selects the documentation
  and the language of the fixed "not covered" answer; the model is told to answer in it.
- `organizationId` is optional and selects the tenant whose settings, budget and rate
  limits apply. Without it, the caller's oldest active organization is used; a foreign
  one is `404`, like every tenant route.

Retrieval scores the locale's articles with BM25 and keeps the top three the caller's
role may see. An article counts as related only when the question shares **at least two
distinct terms** with it and the score clears the relevance floor; one shared word is
not evidence of topicality. When nothing clears it the answer is a fixed message in the
caller's locale with `sources: []` and **no provider call**, so an uncovered question
spends nothing. The off-topic evaluation set measures exactly that (see `docs/ai.md`).

Success response `200`:

```json
{
  "answer": "Open the invoice and choose Mark paid.",
  "sources": [{"id": "invoice-lifecycle", "title": "Invoices"}]
}
```

- `answer` is sanitized plain text: no HTML, no links, no markdown images, capped at
  4000 characters.
- `sources` holds at most three entries and never a provider URL.

The prompt is a constant system rule plus two delimited data blocks — the articles, and
the role with the question — so text inside a question cannot become an instruction. A
Viewer cannot retrieve an Owner/Admin article, so the answer is built without it.

Errors: `400 invalid_id`, `401`, `402 ai_budget_exceeded`, `403 ai_disabled` or
`csrf_invalid`, `404`, `422 validation_failed`, `429 ai_rate_limited` (with
`Retry-After`), `503 ai_unavailable`.

## `POST /api/organizations/{organizationId}/ai/summaries/{kind}`

Implemented. `kind` is one of `overview`, `receivables` or `activity`; any other value
is `404`, like a foreign tenant. Any active member may ask — the tenant is resolved
through the caller's membership, and the aggregates are the ones every role already
sees on the dashboard, the invoice list and the customer list.

Optional request body:

```json
{"locale": "fr"}
```

`locale` is `en`, `fr` or `ar` and only selects the language of the text; without it
the `Accept-Language` header decides, then English. An unsupported value is `422`.

Response `200`:

```json
{
  "text": "Three invoices are overdue and one customer accounts for most of the balance.",
  "generatedAt": "2026-12-01T10:15:30+00:00",
  "fallback": false,
  "facts": {
    "kind": "receivables",
    "role": "Member",
    "locale": "en",
    "currency": "TND",
    "openInvoiceCount": 6,
    "overdueInvoiceCount": 3,
    "dueSoonInvoiceCount": 1,
    "outstandingMillimes": 1240000,
    "overdueMillimes": 800000,
    "dueSoonMillimes": 100000,
    "asOf": "2026-12-01",
    "oldestDueDate": "2026-11-21",
    "customerNames": ["Acme SARL"]
  }
}
```

- `text` is sanitized plain text: no HTML, markdown, links or images.
- `fallback` is `true` when the deterministic template was used: the provider was
  unavailable, returned nothing usable, or wrote a figure that `facts` cannot derive.
  The response is still `200` — the caller asked for a summary and the facts are theirs.
- `facts` is everything the text was allowed to say. Counts are integers, amounts are
  integer **millimes** of the tenant's most used currency (`currency`), dates are ISO
  calendar dates, and `customerNames` holds at most five display names.
- The prompt contains only `kind`, `role`, `locale`, `currency`, those numbers, those
  dates and those display names. No email address, phone number, billing address,
  invoice line, note, member identity or invoice number is ever sent to a provider.

Failures: `400` (`invalid_id`), `401`, `402` (`ai_budget_exceeded`), `403`
(`ai_disabled` or `csrf_invalid`), `404` (unknown kind or foreign tenant), `422`
(unsupported locale), `429` (`ai_rate_limited`, with `Retry-After`). A provider outage
is not a failure: it produces the template with `fallback: true`.

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
retaining customer content. An operator reads it with
`php bin/console app:ai:usage --organization=<uuid> --days=30`.

## Row-level security

`organization_ai_settings` and `ai_requests` are tenant tables: both are `ENABLE` **and**
`FORCE ROW LEVEL SECURITY` with the standard
`organization_id = current_setting('app.organization_id')` policy
(`Version20261201130000`), like `customers`, `invoices`, `invitations` and
`notifications`. See `docs/adr/0003-postgresql-row-level-security.md`.

`POST /api/ai/guide` names its organization in the body and has no `{organizationId}`
route attribute, so no listener sets the context for it; `AiGateway` activates the tenant
itself (`inTenant()`), after proving the caller is an active member, with
`set_config(..., true)` scoped to the enclosing transaction. Without that context an
unscoped read returns no rows and an unscoped write is rejected. Both tables carry an
`organization_id` index, so the policy filter and tenant-scoped reports stay cheap.
