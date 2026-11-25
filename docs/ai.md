# AI features

Status: **implemented and measured.** Two features ship: the documentation assistant
(`POST /api/ai/guide`) and the workspace summaries
(`POST /api/organizations/{organizationId}/ai/summaries/{kind}`). Both reach a provider
only through `App\Ai\AiGateway`, so the deployment switch, the organization opt-in, the
rate limits, the budget and the ledger apply identically to each. The HTTP contract is
[docs/api/ai.md](api/ai.md); this page is the design and the operating numbers.

Nothing here is a general-purpose chat. The assistant answers from the bundled
documentation and says so when the documentation does not cover the question; the
summaries describe aggregates the caller can already see on screen. The guide is never
given tenant data at all, and a summary is only returned when every quantity in it is
provable from the facts object — otherwise it is replaced by a deterministic template
(`fallback: true`). What that check does and does not cover is set out in **What
\"provable\" means** below.

## The chain every call goes through

1. **Active membership.** A foreign or unknown organization is `404`, like every other
   tenant route. There is no "forbidden" and no tenant enumeration.
2. **Global switch.** `AI_ENABLED=false` stops every call for every tenant, before a
   provider is resolved.
3. **Organization opt-in.** The tenant's stored `enabled` flag. New organizations start
   disabled and with a budget of `0`.
4. **Rate limits.** `limiter.ai_principal` (20 calls / 5 minutes per principal) and
   `limiter.ai_organization` (200 calls / 5 minutes per organization). A refusal is
   `429 ai_rate_limited` with `Retry-After`.
5. **Budget reservation.** One conditional `UPDATE ... RETURNING` on
   `organization_ai_settings` reserves an estimate (`prompt characters / 4 + maxTokens`)
   and commits before the provider is called. The predicate is the entire budget rule,
   so PostgreSQL arbitrates two concurrent requests and a nearly empty budget cannot
   fund both. The same gate is evaluated for a cache hit; see **Caching and the budget**.
6. **Provider call.** `AI_PROVIDER=fake` in tests and CI, `openai` (any
   OpenAI-compatible `AI_BASE_URL`) in production-like environments.
7. **Settlement.** A second transaction replaces the estimate with the real token
   counts, appends the `ai_requests` ledger row and logs tokens and latency only.

A failure at step 6 releases the reservation and writes a `failed` row with an error
code, so an outage neither bills the tenant nor hides that an attempt happened. An
answer that sanitizes down to nothing is treated as a failure (`503 ai_unavailable`),
not as an empty success.

## Caching and the budget

A summary is cached for fifteen minutes in `cache.ai_summary`, keyed on the tenant,
the caller's role, the summary kind and a digest of the facts. The cache saves a
provider call — it never bypasses a gate.

Both refusals are decided **before** the cache lookup, so a cached answer cannot
outlive an organization's opt-out or its budget:

- `AI_ENABLED=false` or an organization that has not opted in is `403 ai_disabled`,
  cache hit or not;
- a tenant with **no budget left** (or a budget of `0`, the schema default) is
  `402 ai_budget_exceeded`, cache hit or not.

This is policy **B** in the review that found the inconsistency: every summary
request requires available budget, including cached ones. The alternatives were a
cached answer that is free to read after the budget is gone (A) and a third,
explicit cache-access policy (C). Both would let the most common way to stop AI for
a tenant — set its budget to `0` — keep serving model-generated prose, while
`POST /api/ai/guide` refuses with the same exhausted allowance; B is the only rule
that reads the same for both features. A cache hit reserves nothing and writes no
ledger row: the ledger records provider attempts, and there was none.

The cache key starts with the organization id, so two tenants with identical facts
share nothing. `backend/tests/Integration/Summary/SummaryBudgetPolicyTest.php` pins
that with two tenants whose facts are byte-identical.

## Retrieval: what the guide answers

Each locale has its own article set under `backend/resources/guide/{en,fr,ar}`. A
question is tokenized, scored with BM25 against the locale's articles, and the top three
articles the caller's role may see become the sources.

An article is only related when the question shares **at least two distinct terms**
with it and the score clears `0.5`. One shared word is not evidence of topicality:
before that rule the question *"Write a poem about the sea."* matched an article through
the ordinary word "about" with a score of 2.4, which was enough to spend a real provider
call on a question the corpus cannot answer. The off-topic evaluation set now measures
this directly: 30 questions across the three languages, each of which must produce the
fixed "not covered" message with **no provider call and no ledger row**.

When nothing clears the floor the answer is a constant, localized message with
`sources: []`. That is a deliberate product decision: an answer built on a barely
related article is worse than "I don't have documentation for that".

## Prompts and output

- The prompt is a fixed instruction plus data blocks wrapped in
  `<<<UNTRUSTED_DATA ... UNTRUSTED_DATA>>>`. Everything a tenant controls — a question,
  a customer name, an invoice note, a member's display name — goes inside a block and is
  labelled as data.
- `PromptBuilder` removes control characters, zero-width and bidi characters, and
  neutralises any delimiter written inside a field, so a field cannot close the block
  and start issuing instructions. Newlines and tabs survive because they are meaningful
  in an invoice note. Each field is capped at 2000 characters.
- `OutputSanitizer` decodes entities, strips HTML and script/style bodies, removes
  markdown images (a remote fetch, i.e. a tracking pixel), reduces markdown links to
  their label, removes bare URLs and caps the text at 4000 characters. The client still
  renders it as text — the sanitizer is defence in depth, not permission to use
  `innerHTML`.

Input hygiene is not a security boundary. The answer is untrusted text and is treated
as such.

## Cost

- **Default budget: `0`.** `monthly_token_budget` defaults to zero in the schema, which
  is what keeps the feature off until an Owner or Admin configures it. A suggested
  starting allowance is **50,000 tokens per month** — a few hundred guide answers or a
  few dozen summaries; the number an operator picks is theirs, and nothing in the code
  enforces the suggestion.
- **Alert thresholds** (`App\Ai\AiBudgetPolicy`): more than **20,000 tokens in one day**
  (40% of that suggested allowance) or **80% of the monthly budget** spent. They are
  printed by `app:ai:usage`, not pushed anywhere: there is no alerting integration.
- The ledger is `ai_requests`: one row per attempt with `organization_id`,
  `principal_id`, `feature`, `status` (`succeeded`/`failed`), `model`,
  `prompt_tokens`, `completion_tokens`, `total_tokens`, `latency_ms`, `error_code` and
  `created_at`. **No prompt and no answer text is stored.** That is what makes spend
  auditable without retaining customer content.
- A provider failure's `error_code` names the stage, not just the outcome: `timeout`,
  `transport`, `server_error`, `not_configured`, `malformed_body` (unreadable shape),
  `truncated` (the output limit was reached before any visible text — usually a
  reasoning model spending `max_tokens` on hidden tokens) and `empty_after_sanitize`
  (an answer existed but nothing survived sanitising). A summary rejected by
  `GroundingCheck` is a **successful** provider call: the ledger row says `succeeded`
  and the API returns the deterministic template with `fallback: true`. The rejection
  itself is logged as `AI summary rejected by grounding` with the organization, locale,
  role and a reason code (`vague_quantity`, `ungrounded_number`,
  `ungrounded_number_word`, `unreadable_number`) — never the text or the offending token.

### `app:ai:usage`

```bash
php bin/console app:ai:usage                                # every active organization, 7 days
php bin/console app:ai:usage --organization=<uuid> --days=30
php bin/console app:ai:usage --organization=<uuid> --json    # same numbers, machine-readable
```

It prints, per organization: the stored opt-in and monthly budget, the tokens used and
remaining, and one row per UTC day with request counts, failures and the three token
counters. Days are UTC because `created_at` is; a local report will disagree near
midnight at the edges of the window. `--days` accepts 1 to 366. A malformed
`--organization` is a usage error (exit `2`), never a report.

The command reads through `TenantContext`, so each tenant's rows are read inside that
tenant's row-level-security context rather than filtered by a `WHERE` clause alone.

## What "provable" means

`App\Summary\GroundingCheck` is the gate between a plausible summary and an invented
one. It is deliberately a proof over the facts, not a second model, and it has two
rules:

1. **Every quantity must be derivable.** A quantity is a number written in digits
   (`1 234,50`, `1,234.50`, `٣` — Arabic-Indic digits and the Arabic decimal separator
   are folded first) or spelled out in English, French or Arabic words (zero to one
   hundred, including `twenty-one`, `quatre-vingt-dix`, `deux cents`, `خمسة وعشرون`;
   Arabic diacritics and tatweel are folded, and a leading `و` connector is skipped).
   Each recovered quantity must equal, to one unit in its last written digit, a figure
   in the facts, a millime amount converted to its currency unit, the sum or the
   difference of two figures of the same kind, or the same for a percentage the facts
   allow. `INV-2026-77` and a hex digest are identifiers, not quantities.
2. **No vague quantity.** A fixed word list — English `half`, `most`, `majority`,
   `almost all`, `nearly`, `about a third`; French `moitié`, `majorité`, `presque`,
   `environ`, `tiers`; Arabic `نصف`, `معظم`, `أغلب`, `تقريبًا`, `ثلث` — is refused
   outright, because no fact can prove it. A hit inside a customer name the model was
   given is exempt: the name is quoted data, not a claim.

Both rules run after the text is sanitised and before it is returned. A failure discards
the whole answer and returns `SummaryFallback` with `fallback: true` — the same
behaviour a provider failure gets.

**What is not checked.** Wording outside the two rules is not checked: a qualitative
claim ("your customers pay late"), a comparison of two provable figures, an ordinal
("the third invoice") or a quantity spelled in a form the word lists do not cover is
returned as written. The check proves numbers, not truth. Treat a summary as a
well-formed restatement of the facts on screen, not as an audit of the business.

The system prompt asks for the same thing the check enforces: every quantity in digits,
never in words, and no vague quantifiers — so a model that follows the prompt is
accepted on the first pass rather than being sent to the fallback.

## How it is verified

| Where | What it proves |
| --- | --- |
| `backend/tests/Ai/Eval/` (`--group ai-eval`) | 40 guide questions per language (120 total) each assert the expected article ranks first; 30 off-topic questions assert the not-covered message with no provider call; one fixture per summary kind asserts the exact facts the prompt may use; `GroundingEvalTest` pins the boundary itself — digits, spelled-out numbers, a contradiction and vague wording per language, plus the property that every deterministic template passes the check it sends answers to. |
| CI backend job | The `ai-eval` group runs with `FakeAiProvider`, so it is deterministic and offline; it is part of the normal suite, not a separate gate. |
| `.github/workflows/ai-eval.yml` | Manual `workflow_dispatch` only. Uses the real provider through the secret `AI_API_KEY`, runs the same suite, prints a pass-rate table per set from the JUnit names (question ids, never prompts or answers) and fails below `--minimum` (default 90%). No `pull_request` trigger exists, so a fork cannot reach the secret. |
| `backend/tests/Ai/RedTeam/` | Injection through the question, a customer name, an invoice note and a member name; a body naming another organization; a 100 KB question; control and bidi characters; a provider answering with HTML, a link, a markdown image, invented numbers, a spelled-out figure or a vague quantity. Each asserts the exact safe outcome. |
| `backend/tests/Ai/Drills/` | Timeout, `429`, `500`, malformed JSON and an empty answer; `AI_ENABLED=false`; two real database connections racing a nearly empty budget. |

The eval measures **retrieval**, not answer quality: the expected article must be
`sources[0]`. Cross-provider answer comparison is what the manual workflow is for, and it
is deliberately not a CI gate — a pass rate that depends on a vendor's model would make
the pipeline non-deterministic.

## Failure behaviour

| Situation | Caller sees | Ledger |
| --- | --- | --- |
| `AI_ENABLED=false` or organization disabled | `403 ai_disabled` | nothing |
| Budget exhausted (fresh summary or cached summary) | `402 ai_budget_exceeded` | nothing |
| Rate limit hit | `429 ai_rate_limited` + `Retry-After` | nothing |
| Provider timeout / 5xx after retry / unparseable body | `503 ai_unavailable` | `failed` row with the reason |
| Answer sanitizes to empty | `503 ai_unavailable` (`malformed`) | `failed` row |
| Provider outage during a summary | `200` with `fallback: true` | `failed` row |

Summaries degrade; the guide refuses. A summary has a deterministic template that says
the same true facts, so falling back is better than a 503. The guide has nothing true to
fall back to.

## What is not built

See [limitations.md](limitations.md): no streaming, no tool/function calling, no
per-feature budgets, no prompt caching, no alerting integration, no admin screen for
usage (the console command is the interface), and no supported locale beyond `en`, `fr`
and `ar`.
