# AI live findings — resolution

Date: 2026-11-25. Branch: `fix/ai-live-findings` (not merged).
Provider: Groq `openai/gpt-oss-20b` through the OpenAI-compatible adapter, real key in
the untracked `backend/.env.local` (never printed, copied or logged). Every call ran
against the disposable PostgreSQL 16 container `keelson-ai-fix-db` (port 64639, tmpfs,
`keelson_ai_fix_dev`), never the Compose volume or the development database.

| Finding | Status | Commit |
| --- | --- | --- |
| L-01 reasoning completions were unreadable | **FIXED** | `d1f0949` |
| L-02 correct summaries were rejected by grounding | **FIXED**, one unexplained Arabic fallback remains | `52b047c` |
| L-03 ordinary questions retrieved nothing | **FIXED** | `59beb10` |
| L-04 the panel claimed the assistant reads tenant data | **FIXED** | `5d36d60` |
| L-05 a failed guide call wrote no ledger row | **FIXED** | `c116855` |
| L-06 answers invented support channels and fields | **PARTIALLY FIXED** (residual UI/role wording below) | `e3a2c0a`, `7bb0577` |
| L-07 the effective database was invisible | **FIXED** | `136eaef` |

## What changed

- **L-01** `HttpAiProvider` accepts string and text-part content, ignores reasoning
  fields, strips closed and unclosed ` thinking` blocks, and classifies truncation,
  malformed bodies, empty answers and timeouts. `AI_REASONING_EFFORT` is optional and
  the summary budget is 1200 completion tokens. Ten captured response shapes are unit
  tests; before the fix every real call spent its 320 tokens on reasoning and returned
  `content=""`.
- **L-02** `GroundingCheck::rejectionReason()` returns a code, strips ISO, localized and
  non-breaking-hyphen dates, accepts millime-labelled amounts and French dot decimals.
  Rejections are logged with metadata only. 58 summary unit tests and 198 eval tests pass.
- **L-03** Retrieval now indexes curated per-article keywords, uses light stemming, a
  specificity rule (a title or keyword term must match) and out-of-scope verb/shadda
  checks instead of a flat two-term floor. The eval has 52 covered and 16 off-topic
  questions per language.
- **L-04** The three intros say answers come from the product guide and the member's
  role; the consent paragraph lists what a summary sends, including up to five customer
  display names. A guard test pins the exact sentences.
- **L-05** A failed row is committed on its own short connection with the tenant setting
  applied. The reservation stays in the request transaction, so a rollback releases it
  and a caught summary failure releases it explicitly.
- **L-06** The system prompt forbids inventing support channels, emails, phone numbers,
  menus, buttons and unlisted features; the invoice article separates edit from delete
  permissions in all three languages; the 15 A1 questions assert banned phrases.
- **L-07** `app:config:check` prints the effective host, port and database and the env
  file order; both READMEs explain that `.env.dev.local` outranks `.env.local`.

## Live verification

### Assistant — 15 A1 questions

First pass (burst): 9 answered, 6 `503 ai_unavailable` with reason `transport`. The six
were retried with 8 s spacing and cache cleared between calls; all six answered.

| Locale | Questions | Correct article first | Banned phrase found | Wrong not-covered |
| --- | --- | --- | --- | --- |
| en | 5 | 5 | 0 | 0 |
| fr | 5 | 5 | 0 | 0 |
| ar | 5 | 5 | 0 | 0 |

The scan covered support channels, `support@`, customer IDs, phone numbers, links,
`<script>` and markdown images across every captured answer. Example: `Comment inviter
un collègue ?` → `team-and-roles`; `كيف أنشئ فاتورة؟` → `invoice-lifecycle`.

Residual unsupported claims in the captured answers (L-06):

1. `en` “How do I create an invoice?” — “Click Create (or the “+” icon)”; the article
   names no control.
2. `en` “How do I verify my email address?” — “check your spam folder or resend the
   verification email”; the article describes neither.
3. `fr` “Comment créer une facture ?” — “menu « Factures » … cliquez sur « Créer »”;
   same UI invention.
4. `fr` “Comment inviter un collègue ?” — lists `Owner` as an invitable role; the article
   only fixes what an Admin may invite and never lists Owner as invitable.
5. `ar` “كيف أتحقق من بريدي الإلكتروني؟” — “request a new link from your account
   settings”; no such screen is documented.

No support contact or customer-ID claim survived. The prompt and articles were not
changed again after this run because the spend cap was already exceeded (below), so
these five sentences are **not re-verified**.

### Summary — 10 runs per language, cache cleared before each

| Locale | Model text (`fallback: false`) | Template (`fallback: true`) |
| --- | --- | --- |
| en | 10/10 | 0 |
| fr | 10/10 | 0 |
| ar | 9/10 | 1 |

The one Arabic fallback is a grounding rejection of a provider answer that was billed
(`summary_overview`, `succeeded`). Its text is not stored, so the rejected rule is not
known; this is the same class of finding as L-02 and it is **not diagnosed**. Every
displayed number was correct. Before the fixes the rate was 0/14 model texts.

### Ledger — L-05 in production

| feature | status | reason | rows | tokens |
| --- | --- | --- | --- | --- |
| guide | succeeded | — | 15 | 15,262 |
| guide | failed | `transport` | 6 | 0 |
| summary_overview | succeeded | — | 32 | 30,162 |
| summary_overview | failed | `transport` | 3 | 0 |
| **total** | | | **56** | **45,424** |

56 rows for 56 provider attempts in this session (21 guide attempts, 35 summary
attempts). The pre-fix run had 31 rows for 32 attempts. `used_tokens = 45,424`,
`reserved_tokens = 0`. The first summary pass also proved the cache path: 9 of 10 `en`
and 9 of 10 `fr` calls were served from cache in 0.11–0.17 s without a row.

## Spend and the stop line

Live ledger: **45,424 tokens**. Diagnostics before the fixes spent approximately
**20,153 tokens** (L-01 shape captures 7,220; the 1,024-token probe 2,633; grounding
runs roughly 10,300). Approximate total: **65,577 tokens**, above the 60,000-token stop
line. All live provider calls stopped at that point; the remaining checks are local.
This is a deviation from the brief, reported rather than hidden.

## Gates

| Check | Result |
| --- | --- |
| `composer validate --strict` | pass |
| `composer stan` (level 8, no baseline) | pass (`No errors`) |
| `composer cs` on an LF copy | pass (0 of 266 files) |
| `php bin/phpunit` | **OK (713 tests, 5,290 assertions)** |
| `php bin/phpunit --group ai-eval` | **OK (198 tests, 2,289 assertions)** |
| `doctrine:migrations:up-to-date` (test) | pass |
| `lint:yaml`, `lint:container` | pass |
| `composer audit --locked` | pass |
| `bin/secret-scan.sh` | pass (`clean (580 files)`) |
| `pnpm exec tsc -b`, `pnpm lint` | pass |
| `pnpm test` | **187 tests in 30 files, pass** |
| `pnpm run build`, `check:bundle` | pass (495.4 kB of 800 kB) |
| `pnpm exec knip`, `unused-i18n`, `pnpm audit` | pass (no findings) |
| Playwright, free ports 8300/5300 | 34 passed, 2 failed (see below); AI journey passed on rerun |

Test counts **before** this work were not captured, so no before/after delta is
reported. The figures above are the final ones.

Playwright failures:

- `features/ai.spec.ts` failed on the old consent sentence in its assertion. Fixed and
  rerun alone with `AI_PROVIDER=fake`: **1 passed**.
- `c-invitation.spec.ts` failed because the anonymous preview answered `404`. The token's
  SHA-256 matches the invitation row, but `invitations` has a forced RLS policy
  (`organization_id = current_setting('app.organization_id')`) and the preview runs
  before any tenant is known. `keelson_app` has no `BYPASSRLS`, so the lookup returns
  nothing. This is pre-existing and unrelated to the AI commits; it was reproduced twice.
  It was **not fixed** here because changing that policy is an architecture decision.

Committed alongside this report: `docs/review/AI-LIVE-TEST.md` (the live-run evidence;
it contains fixture organization IDs and synthetic addresses, no key or personal data),
the `docs/runbooks/ai-key-rotation.md` runbook and the captures under
`docs/screenshots/`. `backend/.env` has a pre-existing modified line that is not part of
the commit.

## Not tested

- L-06 residual claims after another prompt tightening: not re-run (spend cap).
- The Arabic grounding rejection cause: the ledger stores no text by design.
- Two visual checks: the assistant panel and summary card were not viewed in a browser
  during this pass; copy and API responses were read directly.
- Coverage: `pcov`/`xdebug` are not installed on this host, so no percentage is claimed.
