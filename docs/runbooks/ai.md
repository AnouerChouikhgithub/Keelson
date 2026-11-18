# Runbook: AI features

For whoever is on call when the assistant or the summaries misbehave. Every action here
is reversible; none requires a migration. Design background is in
[docs/ai.md](../ai.md), the HTTP contract in [docs/api/ai.md](../api/ai.md), and what
leaves the process in [docs/privacy-data-flow.md](../privacy-data-flow.md).

## 1. Stop everything now (kill switch)

```bash
# In the deployment environment:
AI_ENABLED=false
# then restart the PHP workers so the container rebinds the flag
```

Every AI call answers `403 ai_disabled` before a provider is resolved, so this stops
spend within one deploy. It does not touch rate-limit counters, budgets or ledger rows.

Verify:

```bash
php bin/console app:ai:usage --days=1        # "AI enabled: no" for every organization
```

## 2. Stop one tenant

An Owner or Admin of that tenant can do it through the product
(`PUT /api/organizations/{organizationId}/ai-settings` with `{"enabled": false,
"monthlyTokenBudget": 0}`). For support without their credentials, the same effect
comes from setting the row's `enabled` to `false` — in a tenant context, so:

```bash
php bin/console app:ai:usage --organization=<uuid>     # confirm the budget you are about to zero
```

and use the API with an admin session if at all possible. Direct SQL on
`organization_ai_settings` must run inside `App\Tenancy\TenantContext::run()`; outside it
row-level security sees no rows and the `UPDATE` affects nothing.

## 3. Spend is higher than expected

```bash
php bin/console app:ai:usage --organization=<uuid> --days=30
php bin/console app:ai:usage --organization=<uuid> --days=30 --json
```

Read it in this order:

1. **Per-day rows.** A single day above 20,000 tokens is the spike threshold: either one
   tenant used the feature heavily or something loops.
2. **`requests` vs `failed`.** Many `failed` rows with no success means retries against a
   broken provider — the reservations are released, so the daily total should stay flat.
3. **`prompt_tokens` vs `completion_tokens`.** Prompt tokens growing while answers stay
   the same length means the retrieved articles got longer (the corpus changed), not that
   users ask more.

Actions, in order of preference: lower the tenant's monthly budget to the observed
usage; disable the tenant; only then `AI_ENABLED=false`.

## 4. Everyone gets `402 ai_budget_exceeded`

The organization's `used_tokens + reserved_tokens` has reached `monthly_token_budget`
for the current period. Check the stored numbers:

```bash
php bin/console app:ai:usage --organization=<uuid> --days=1
```

- Budget `0` is the default: the tenant never configured an allowance. That is not a
  bug; an Owner sets one.
- Usage near the budget: raise the budget, or wait for the next period. The period is
  `Y-m` of the server's clock; the counters reset on the first request of the new month.
- Usage far below the budget with requests still refused: `reserved_tokens` is high.
  Reservations are released when a call fails, but a process that was killed mid-call
  (deploy, OOM, `SIGKILL`) cannot release one, so a stale reservation stays until the
  period rolls over. This is a known limitation — lowering `reserved_tokens` for that
  tenant inside a tenant context is the manual repair, and it should be recorded.

## 5. Everyone gets `429 ai_rate_limited`

The limits are in `backend/config/packages/rate_limiter.yaml`: 20 calls / 5 minutes per
principal and 200 / 5 minutes per organization. `Retry-After` on the response is
accurate. Nothing is broken; the client should back off. If the per-organization limit
is genuinely too low for a tenant, that is a configuration change with a deploy, not an
incident action.

## 6. `503 ai_unavailable`

Every provider-side failure looks like this to the caller, and the difference is in the
ledger's `error_code`:

| `error_code` | Meaning | Action |
| --- | --- | --- |
| `not_configured` | `AI_API_KEY`, `AI_MODEL` or `AI_BASE_URL` is empty | Fix the environment (§8) |
| `timeout` | The provider did not answer in time | Check the provider's status page; this is usually theirs |
| `transport` | Connection/TLS failure, **or any 4xx from the provider** (bad credential, rejected payload, the provider's own 429) | Check `AI_BASE_URL`, then the key (§8); if traffic is being throttled upstream, slow down or raise the provider tier |
| `server_error` | Two attempts failed with a 5xx | Provider incident |
| `malformed_response` | The body could not be parsed, or the answer sanitized to nothing | Provider/model change, or a safety filter returning empty |
| `unexpected_provider_error` | Anything else that escaped the provider | Read the log line; treat as a bug |

```bash
php bin/console app:ai:usage --organization=<uuid> --days=2   # failed count per day
```

Then grep the application log for `AI request` lines: they carry organization id,
feature, token count and latency — never a prompt or an answer.

If `malformed` appears for every request right after a model change, roll the model
identifier back (`AI_MODEL`) and re-run the evaluation (§9) before switching again.

## 7. Summaries say `fallback: true`

Not an incident by itself. The template is used when the provider is unavailable, the
answer was empty, or it contained a figure the facts cannot derive. If it happens for
every summary, treat it as §6. If it happens for one summary kind only, the model is
consistently writing a number that is not in the facts — roll the model back and re-run
the evaluation; do not "fix" it by loosening the fact check.

## 8. Rotating the provider key

1. Create the new key at the provider.
2. Write it into the deployment secret store (`AI_API_KEY`); never into a tracked file.
3. Restart the workers.
4. Confirm one successful call (a guide question as an opted-in tenant) and one
   `ai_requests` row with `status = succeeded`.
5. Revoke the old key at the provider.

An empty `AI_API_KEY` means the HTTP provider is not configured: every call answers
`503 ai_unavailable` and leaves a `not_configured` ledger row. That is the intended
failure mode, not a crash.

## 9. Changing the model or the endpoint

The gate is the evaluation, not a demo:

1. Run **Actions → AI evaluation (real provider) → Run workflow** with the new `model`
   and, if needed, `base_url`. It is `workflow_dispatch` only, never on a pull request.
2. Read the pass-rate table it prints (`guide-en`, `guide-fr`, `guide-ar`,
   `off-topic`, `summaries`). It fails below `minimum_pass_rate` (default 90).
3. Only if it passes, change `AI_MODEL`/`AI_BASE_URL` in the deployment.

The suite is the same one CI runs against the deterministic fake provider, so a set that
fails here and passes in CI is the provider's problem. The table contains question ids
only — no prompt, no answer, no tenant data — so it is safe to paste into a ticket.

## 10. Running the safety checks locally

```bash
cd backend
bash bin/test-db.sh start                     # disposable PostgreSQL 16
php bin/phpunit tests/Ai                      # eval + red team + drills, fake provider
php bin/phpunit --group ai-eval               # the evaluation set only
php bin/phpunit tests/Ai/Drills               # provider failures and budget races
php bin/phpunit tests/Ai/RedTeam              # injection and tenant-boundary cases
bash bin/test-db.sh stop
```

The red-team suite drives raw HTTP against the real kernel and asserts status codes,
error codes and ledger side effects — including that a foreign organization id in a body
is a `404`, that a 100 KB question is a `422` before any lookup, and that a provider
answer containing HTML, a link or a markdown image never reaches the response.

## 11. After any AI incident

Record, in the incident note: the time window, the affected organization ids, the
`error_code`s from the ledger, the tokens spent in the window (`app:ai:usage --days` for
that window), whether the kill switch was used and when it was lifted. Nothing else is
recoverable later, because the prompts and answers were never stored — which is the
point of the design and the limit of the investigation.
