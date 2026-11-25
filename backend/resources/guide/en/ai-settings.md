---
id: ai-settings
title: AI settings
roles: [Owner, Admin]
keywords: ["ai settings","enable ai","turn on ai","token budget","rate limit","ai disabled","opt in","ledger","prompt","answer","prompts","answers","log"]
---

AI is off until an Owner or Admin turns it on for the organization. Open settings and choose the AI section: the switch and the monthly token budget (0 to 100,000,000 tokens) are both required. Any other role, including Member, receives not found on these settings.

Turning the switch from off to on records a `security_events` row of type `ai_enabled`; disabling it does not, because turning something off is not a new capability.

A request is refused before it reaches a provider when AI is off (`403 ai_disabled`), when the reservation would pass the monthly budget (`402 ai_budget_exceeded`), or when a rate limit is empty (`429 ai_rate_limited`, with `Retry-After`). The limits are 20 requests per 5 minutes per person and 200 per 5 minutes per organization, so one tab cannot loop and one tenant cannot drain its budget in a burst.

The ledger records the feature, status, model, token counts and latency. It never stores a prompt or an answer.
