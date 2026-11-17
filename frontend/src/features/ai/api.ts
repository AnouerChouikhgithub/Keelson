import { query, request } from '../../api/client'
import type { RequestOptions } from '../../api/client'
import { isAbortError, isApiError } from '../../api/errors'

/**
 * Typed client for `docs/api/ai.md`.
 *
 * Every failure is normalized to `AiError`, whose `code` is one of the
 * contract's error codes plus the three transport outcomes the shared client
 * already distinguishes (network, timeout, unexpected body). Screens never read
 * `ApiError` directly, so a new contract code shows up as one compile error in
 * `presentAiError` instead of an English server sentence on screen.
 */

export type AiStatus = {
  /** The deployment-wide `AI_ENABLED` switch. */
  enabled: boolean
  /** This organization's stored opt-in. */
  organizationEnabled: boolean
  budgetRemainingTokens: number
}

export type AiSettings = {
  enabled: boolean
  monthlyTokenBudget: number
  budgetRemainingTokens: number
}

/** `PUT` replaces both fields: any omitted one is a `422`. */
export type AiSettingsInput = {
  enabled: boolean
  monthlyTokenBudget: number
}

/**
 * An internal identifier such as `invoices.send`, never a URL: the contract
 * forbids provider links, so the panel renders these as text, not anchors.
 */
type AiSource = {
  title: string
  reference: string
}

export type AiGuideAnswer = {
  /** Plain text. Rendered as a React text node only. */
  answer: string
  sources: AiSource[]
}

export type SummaryKind = 'organization' | 'invoices' | 'customers'

export type AiSummary = {
  /** Sanitized plain text. Rendered as a React text node only. */
  text: string
  generatedAt: string
  /** Aggregates the summary was built from, so the UI never parses the prose. */
  facts: Record<string, string | number>
  /**
   * Not part of the response shown in `docs/api/ai.md`, but the sprint brief
   * requires the AI label to be hidden when the text is a deterministic
   * fallback rather than model output. Absent means "model output".
   */
  fallback?: boolean
}

/**
 * One onboarding step.
 *
 * `docs/api/ai.md` does not describe `GET …/onboarding` (the foundation branch
 * only stubs guide and summaries), so this is the smallest shape the checklist
 * needs: a stable machine key, an optional server-supplied label and the state.
 * The PR records the assumption so the backend owner can confirm it.
 */
export type OnboardingStep = {
  key: string
  label?: string | null
  completed: boolean
}

export type OnboardingChecklistData = {
  steps: OnboardingStep[]
}

export type AiErrorCode =
  | 'ai_disabled'
  | 'ai_budget_exceeded'
  | 'ai_rate_limited'
  | 'ai_unavailable'
  | 'validation_failed'
  | 'not_found'
  | 'not_implemented'
  | 'csrf_invalid'
  | 'unauthenticated'
  | 'network'
  | 'timeout'
  | 'unexpected'

/** The codes the backend contract itself defines. */
const CONTRACT_CODES: readonly AiErrorCode[] = [
  'ai_disabled',
  'ai_budget_exceeded',
  'ai_rate_limited',
  'ai_unavailable',
  'validation_failed',
  'not_found',
  'not_implemented',
  'csrf_invalid',
]

export class AiError extends Error {
  readonly code: AiErrorCode
  readonly status: number | null
  /** Per-field messages from a `422`; the server's own text for these fields. */
  readonly fields: Record<string, string[]> | null

  constructor(code: AiErrorCode, status: number | null = null, fields: Record<string, string[]> | null = null) {
    super(code)
    this.name = 'AiError'
    this.code = code
    this.status = status
    this.fields = fields
  }
}

/**
 * Maps the shared `ApiError` onto the contract's codes.
 *
 * A stable `code` always wins; the status is only a fallback for a response the
 * contract describes but that arrived without one (a proxy page, a bare 402).
 * A caller abort is not a failure and must never surface as `AiError`.
 */
function toAiError(error: unknown): AiError {
  if (!isApiError(error)) return new AiError('unexpected')
  if (error.kind === 'network') return new AiError('network')
  if (error.kind === 'timeout') return new AiError('timeout')
  if (error.code !== null && CONTRACT_CODES.includes(error.code as AiErrorCode)) {
    return new AiError(error.code as AiErrorCode, error.status, error.fields)
  }
  const status = error.status
  if (status === 401) return new AiError('unauthenticated', status)
  if (status === 402) return new AiError('ai_budget_exceeded', status)
  if (status === 403) return new AiError('ai_disabled', status)
  if (status === 404) return new AiError('not_found', status)
  if (status === 422) return new AiError('validation_failed', status, error.fields)
  if (status === 429) return new AiError('ai_rate_limited', status)
  if (status === 501) return new AiError('not_implemented', status)
  if (status === 503) return new AiError('ai_unavailable', status)
  return new AiError('unexpected', status)
}

async function aiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  try {
    return await request<T>(path, options)
  } catch (error) {
    if (isAbortError(error)) throw error
    throw toAiError(error)
  }
}

function settingsPath(organizationId: string): string {
  return `/api/organizations/${encodeURIComponent(organizationId)}/ai-settings`
}

/** Never calls a provider, so it is safe to read on every shell mount. */
export function getAiStatus(organizationId?: string, signal?: AbortSignal): Promise<AiStatus> {
  return aiRequest<AiStatus>(`/api/ai/status${query({ organizationId })}`, { signal })
}

export function getAiSettings(organizationId: string, signal?: AbortSignal): Promise<AiSettings> {
  return aiRequest<AiSettings>(settingsPath(organizationId), { signal })
}

export function updateAiSettings(organizationId: string, input: AiSettingsInput): Promise<AiSettings> {
  return aiRequest<AiSettings>(settingsPath(organizationId), { method: 'PUT', body: input })
}

export function askGuide(question: string, signal?: AbortSignal): Promise<AiGuideAnswer> {
  return aiRequest<AiGuideAnswer>('/api/ai/guide', { method: 'POST', body: { question }, signal })
}

export function getOnboarding(organizationId: string, signal?: AbortSignal): Promise<OnboardingChecklistData> {
  return aiRequest<OnboardingChecklistData>(
    `/api/organizations/${encodeURIComponent(organizationId)}/onboarding`,
    { signal },
  )
}

export function requestSummary(
  organizationId: string,
  kind: SummaryKind,
  signal?: AbortSignal,
): Promise<AiSummary> {
  return aiRequest<AiSummary>(
    `/api/organizations/${encodeURIComponent(organizationId)}/ai/summaries/${kind}`,
    { method: 'POST', signal },
  )
}
