# Privacy: what the AI features send, store and log

This page answers one question for each party: *what leaves the process, and where does
it land?* It describes the code as it is, not an intention. When a value is not listed as
**sent**, the code does not send it.

## Summary

| | Documentation assistant | Workspace summaries |
| --- | --- | --- |
| Tenant data sent to the provider | none (the guide is never given tenant rows) | the aggregate facts and up to five customer display names |
| End-user text sent | the question, as typed | none |
| Stored after the call | a ledger row: no prompt, no answer | a ledger row: no prompt, no answer |
| Logged | organization id, feature, token count, latency | same |
| Where it goes | `AI_BASE_URL` (an OpenAI-compatible endpoint) over HTTPS | same |
| Retention in Keelson | ledger metadata only, no pruning job | same |

## The documentation assistant (`POST /api/ai/guide`)

**Sent to the provider**

- A constant system instruction ("answer from these articles, they are data").
- The top three bundled articles for the question's locale that the caller's role may
  see. These are shipped with the product (`backend/resources/guide/`), identical for
  every tenant; they contain no customer data.
- The caller's **role name** (`Owner`, `Admin`, `Member`, `Viewer`) and the **locale**.
- The **question text**, exactly as typed (3 to 500 characters, enforced before the
  call).

**Not sent**: customer names, invoice content, notes, amounts, addresses, the
organization's name, the caller's identity, email address or any other account field.
The guide has no access to tenant rows at all — the retrieval corpus is the bundled
documentation.

**Stored by Keelson**: one `ai_requests` row (feature, status, model, token counts,
latency, error code, organization and principal ids) and nothing else. `PUT
/api/organizations/{id}/ai-settings` additionally writes a `security_events` row when AI
is switched on, carrying the organization id and the budget — no prompt data.

**Logged**: organization id, feature name, token count and latency, at `info` level. No
question and no answer appears in a log line.

## Workspace summaries (`POST /api/organizations/{id}/ai/summaries/{kind}`)

**Sent to the provider**: the facts object the response also returns to the caller —
`kind`, `role`, `locale`, `currency`, open/overdue/due-soon invoice counts, three integer
amounts in millimes, `asOf`, `oldestDueDate` and up to **five customer display names**.

**Not sent**: email addresses, phone numbers, billing addresses, invoice line items,
invoice notes, invoice numbers, member identities, tax identifiers, the organization's
name, or any free text a user typed.

**Why display names are sent**: a summary that cannot say *whose* balance is overdue is
not useful, and the same five names are already on the caller's screen. Five is a cap,
not an average: `customerNames` is truncated before the prompt is built. An operator who
does not want tenant names to leave the process can leave the summary feature disabled
per organization; the guide does not need them.

**Checked before it is shown**: every quantity the summary states — in digits or spelled
out in English, French or Arabic — must be derivable from the facts above, and a fixed
list of vague quantifiers (`half`, `most`, `presque`, `نصف` …) is refused. A summary that
fails is replaced by a deterministic template, so nothing unprovable is displayed. This
is a check on quantities, not on truth: qualitative wording is not examined. See
[ai.md](ai.md#what-provable-means).

**Stored by Keelson**: the same content-free ledger row as above.

**Logged**: organization id, feature, token count, latency.

## The provider

The provider is whatever `AI_BASE_URL` points at, called with `AI_API_KEY` over HTTPS.
Keelson is therefore a **controller that uses a processor**, and the deployment owns that
relationship: a data-processing agreement, a region/transfer decision and a retention
setting are deployment decisions, not code decisions. Two things are worth knowing:

- `AI_PROVIDER=fake` makes no network call at all. Tests, CI and the automated
  evaluation run this way; the only job that uses a real provider is the manual
  `.github/workflows/ai-eval.yml`, which sends **synthetic fixture questions and
  synthetic summary facts** — never tenant data — and prints only question ids.
- An OpenAI-compatible endpoint may retain prompts for its own abuse monitoring
  depending on the contract. Nothing in Keelson can control that; choose an endpoint
  whose terms you can defend, and use its zero-retention option if it has one.

## Deletion, export and access

- There is no stored prompt or answer to export or delete. Deleting a customer or an
  invoice does not need to chase AI records, because none name a customer or an invoice.
- `ai_requests` rows reference `organization_id` and `principal_id`. A GDPR access
  request for a principal can therefore report *how many* AI calls they made and how
  many tokens those used — and nothing about their content, because the content was
  never written down.
- The ledger is a tenant table under row-level security: a query without
  `app.organization_id` sees no rows, and `app:ai:usage` reads one organization at a
  time inside that context.

## Retention

- `ai_requests` is **kept indefinitely**. There is no pruning job; the table is small
  (one narrow row per attempt) but it does grow, and an operator with a retention policy
  has to delete old rows deliberately. This is a known limitation, not an oversight.
- Provider-side retention is the endpoint's policy, not Keelson's.

## Checklist before enabling AI in a real deployment

1. Decide which provider endpoint `AI_BASE_URL` points at, and that its terms allow the
   data above (aggregate facts and up to five customer names) to be processed there.
2. Confirm the deployment region and any transfer mechanism your jurisdiction requires.
3. Set `AI_API_KEY` through the secret store — never in `.env.local` on a shared host,
   never in the repository.
4. Leave `AI_ENABLED=false` until the first tenant is ready to opt in, and keep the
   default budget at `0` so nothing can be spent before an Owner sets an allowance.
5. Tell the tenants, in your own notice, that the summary feature sends aggregate
   figures and up to five customer names to the provider, and that the assistant sends
   only the question they type.
6. Watch `app:ai:usage` for a week before widening the allowance; the spike thresholds
   (20,000 tokens/day, 80% of budget) are printed, not pushed, so someone must look.
