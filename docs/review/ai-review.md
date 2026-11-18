# AI release review

Scope: the AI features as they exist on `sprint/g-ai-release` — `App\Ai\*`,
`App\Guide\*`, the summary service, the AI HTTP surface, the migrations that back them,
and the tests, workflows and documents that claim things about them.

Method: every claim in `docs/ai.md` and `docs/api/ai.md` was checked against the code,
the tenant boundary was attacked from the outside (raw HTTP with a foreign organization
id, a 100 KB body, injected delimiters), and the cost path was exercised against a real
PostgreSQL with two connections. Findings are ordered by severity. Each one that was
fixed carries the test that now prevents it from coming back.

## Critical

### C1. The AI tables had no row-level-security policy

`Version20261201120000` created `organization_ai_settings` and `ai_requests` without
`ENABLE ROW LEVEL SECURITY`, `FORCE ROW LEVEL SECURITY` or a policy, while the project
rule is that no tenant table is added without one in the same migration. Every other
tenant table (`customers`, `invoices`, `invitations`, `notifications`) is enforced by the
database; these two were protected by the application layer alone, so a single missed
`WHERE organization_id = …` — in a future repository method, a console command, or a
report query — would have crossed tenants silently, and `/readyz`'s RLS probe did not
cover them.

**Fixed** by `Version20261201130000`: both tables get the standard
`organization_id = current_setting('app.organization_id')` policy, enabled **and
forced**. `AiGateway` and `AiStatusController` now perform their reads and writes inside
`TenantContext` (`AiGateway::inTenant()`), which is what the policy requires: without it
an unscoped read returns no rows and an unscoped write is rejected.

**Regression test**: `tests/Integration/RowLevelSecurityTest::testTheAiTablesAreScopedByTheSamePolicy`
— a non-`BYPASSRLS` probe connection sees zero rows of both tables with no scope, sees
its own tenant's row and not the other's, and cannot insert a foreign row.
`testThePoliciesAreEnabledAndForcedOnEveryTenantTable` now lists the two tables, so a
future AI table without a policy fails the suite.

## High

### H1. One ordinary word was enough to spend a provider call

The guide's relevance floor was a score of `0.5` on BM25, and BM25 is generous to a rare
word: *"Write a poem about the sea."* matched an article through the word "about" with a
score of 2.4. The result was a paid provider call and an answer built on an unrelated
article, for a question the corpus cannot answer. This was not hypothetical: the
off-topic evaluation set found **9 of 30** such questions reaching a provider on the
first run.

**Fixed** in `App\Guide\GuideIndex`: an article is related only when the question shares
at least **two distinct terms** with it *and* the score clears the floor
(`MINIMUM_MATCHED_TERMS = 2`). One shared word is not evidence of topicality.

**Regression test**: the off-topic set in `tests/Ai/Eval/data/guide-offtopic.json` (10
questions × 3 languages) asserts the fixed not-covered message, `sources: []`, **no
provider call and no ledger row** (`GuideEvalTest::testTheOffTopicSetNeverSpendsAToken`).
All 120 in-scope questions still pass with the stricter rule.

## Medium

### M2. The gateway did not verify that the request belonged to the caller it was given

`AiGateway::complete(Principal, organizationId, AiRequest)` trusts three separate
arguments. Nothing checked that `AiRequest::organizationId` matched the billed
`organizationId`, or that `AiRequest::principalId` matched the principal. A caller that
built a request for tenant A and passed tenant B would have reserved B's budget, sent A's
prompt, and written the ledger row against B — a cross-tenant accounting error that
application-level `if`s at each call site would eventually miss.

**Fixed** in `AiGateway`: a mismatch throws `InvalidArgumentException` before the
membership check, so the invariant is enforced once, at the only entry point to a
provider.

**Regression test**: `tests/Integration/AiGatewayTest` (the caller/tenant mismatch cases)
plus `testAForeignCallerGetsNotFoundNotADisabled`, which now builds the request for the
stranger it authenticates as and still gets a `404`.

## Low

### L3. A reservation can leak when the process dies mid-call

The budget is reserved in a committed transaction before the provider call and released
in a second one. A process killed between the two (`SIGKILL`, OOM, a deploy during a slow
call) cannot release its estimate, so `reserved_tokens` stays high until the period rolls
over. It costs the tenant headroom, never money, and it is bounded by the estimate.

Documented in `docs/runbooks/ai.md` with the manual repair (lower `reserved_tokens` for
that tenant inside a tenant context, and record it). Building a reaper would need a
heartbeat per in-flight call; that is not worth a table today.

## Checked and found sound

- **Tenant boundary over HTTP.** A body naming another organization is `404` on the guide
  and on every summary kind; a foreign `{organizationId}` in the path is `404`; another
  organization's AI settings are `404` for read and write. None of these consult the
  provider or the budget first.
- **Input limits.** A 100 KB question is `422` from the input hydrator before any
  retrieval or lookup; 500 characters is the accepted maximum.
- **Delimiters and control characters.** `PromptBuilder` neutralises a delimiter written
  inside the question, a customer name, an invoice note and a member name, strips
  zero-width/bidi characters and keeps newlines and tabs. Two data blocks, two delimiter
  pairs: an injected delimiter cannot create a third.
- **Provider output.** HTML, a bare URL, a markdown link and a markdown image are all
  removed before the answer reaches the response; a link keeps its label and loses its
  target. An empty answer is a `503`, not an empty success.
- **Invented figures in summaries.** A number the facts cannot derive forces the
  deterministic template with `fallback: true`; the facts in the response are the ones
  the text was allowed to use.
- **Failure drills.** Timeout, `429`, `500`, malformed JSON and an empty answer each
  produce the documented status, error code and ledger row, and each releases the
  reservation exactly once.
- **Global switch.** `AI_ENABLED=false` short-circuits before the provider is resolved,
  for both features, with no ledger row.
- **Cost accounting under concurrency.** Two real connections racing a nearly empty
  budget: the second reservation is refused, and the boundary is exact down to one token.
- **Content-free ledger.** `ai_requests` has no column that can hold a prompt or an
  answer, and `ai_requests`/`organization_ai_settings` are now RLS-enforced (C1).
- **Logs.** AI log lines carry organization id, feature, token count and latency. No
  prompt, no answer, no email address.
- **The manual workflow.** `workflow_dispatch` only, with an `if` guard that refuses a
  pull-request event, and a table built from JUnit case names (question ids) rather than
  from assertions, so a failure cannot print an answer.

## Not reviewed

- The answer *quality* of a real model. The deterministic suite measures retrieval and
  the safety properties; quality against a live provider is what the manual workflow
  reports, and its numbers are model- and vendor-dependent by nature.
- Provider-side retention and transfer. Those are deployment decisions recorded in
  `docs/privacy-data-flow.md`, not properties of this code.
