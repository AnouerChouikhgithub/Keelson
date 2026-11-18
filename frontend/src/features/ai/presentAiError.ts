import type { Messages } from '../../i18n'
import { AiError } from './api'
import type { AiErrorCode } from './api'

/**
 * Localized copy for every AI failure.
 *
 * The record is exhaustive over `AiErrorCode`, so adding a code to the contract
 * is a type error here, and every key is read explicitly (no `m.ai.errors[code]`)
 * so `scripts/unused-i18n.mjs` can see all of them.
 */
const messages: Record<AiErrorCode, (m: Messages) => string> = {
  ai_disabled: (m) => m.ai.errors.aiDisabled,
  ai_budget_exceeded: (m) => m.ai.errors.aiBudgetExceeded,
  ai_rate_limited: (m) => m.ai.errors.aiRateLimited,
  ai_unavailable: (m) => m.ai.errors.aiUnavailable,
  validation_failed: (m) => m.ai.errors.validationFailed,
  not_found: (m) => m.ai.errors.notFound,
  not_implemented: (m) => m.ai.errors.notImplemented,
  csrf_invalid: (m) => m.ai.errors.csrfInvalid,
  unauthenticated: (m) => m.ai.errors.unauthenticated,
  network: (m) => m.ai.errors.network,
  timeout: (m) => m.ai.errors.timeout,
  unexpected: (m) => m.ai.errors.unexpected,
}

export function presentAiError(m: Messages, error: unknown): string {
  if (!(error instanceof AiError)) return m.ai.errors.unexpected
  return messages[error.code](m)
}
