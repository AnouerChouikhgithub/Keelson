/**
 * One error type for every API failure.
 *
 * The UI decides what to say from `kind`/`status`/`code` (localized), and may
 * show the server's `detail` for validation and conflict messages. `code` and
 * `fields` come from the backend envelope `{error:{code,message,fields?}}` and
 * let a screen react to one specific failure (`email_not_verified`,
 * `csrf_invalid`, …) without parsing prose. Never put a token, request body or
 * raw stack trace in `detail`.
 */

export type ApiErrorKind = 'http' | 'network' | 'timeout' | 'parse'

export class ApiError extends Error {
  readonly status: number | null
  readonly kind: ApiErrorKind
  /** Server-provided message when the response carried one; already sanitized. */
  readonly detail: string | null
  /** Stable machine-readable code from the server envelope, when present. */
  readonly code: string | null
  /** Per-field validation messages, when the server rejected specific inputs. */
  readonly fields: Record<string, string[]> | null

  constructor(
    message: string,
    kind: ApiErrorKind,
    status: number | null = null,
    detail: string | null = null,
    code: string | null = null,
    fields: Record<string, string[]> | null = null,
  ) {
    super(message)
    this.name = 'ApiError'
    this.kind = kind
    this.status = status
    this.detail = detail
    this.code = code
    this.fields = fields
  }
}

/** True for a fetch abort, which callers must ignore instead of reporting. */
export function isAbortError(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError'
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError
}

function errorDocument(payload: unknown): Record<string, unknown> | null {
  if (typeof payload !== 'object' || payload === null) return null
  const nested = (payload as Record<string, unknown>).error
  return typeof nested === 'object' && nested !== null ? (nested as Record<string, unknown>) : null
}

/**
 * Reads the message out of the backend's error document.
 *
 * The API returns `{"error":{"code":"…","message":"…"}}`; Symfony's own
 * exception listener may return `{"title": "…", "detail": "…"}`. Anything else
 * (an HTML error page, a proxy page) has no safe message to show.
 */
export function serverMessage(payload: unknown): string | null {
  const nested = errorDocument(payload)
  if (nested !== null) {
    const message = nested.message
    if (typeof message === 'string' && message.trim() !== '') return message
  }
  if (typeof payload !== 'object' || payload === null) return null
  const record = payload as Record<string, unknown>
  for (const key of ['detail', 'title', 'message']) {
    const value = record[key]
    if (typeof value === 'string' && value.trim() !== '') return value
  }
  return null
}

/** Reads the stable error code, ignoring anything that is not a plain word. */
export function errorCode(payload: unknown): string | null {
  const code = errorDocument(payload)?.code
  return typeof code === 'string' && /^[a-z0-9_]+$/.test(code) ? code : null
}

/** Reads `fields:{name:[messages]}` into a flat per-field list. */
export function errorFields(payload: unknown): Record<string, string[]> | null {
  const fields = errorDocument(payload)?.fields
  if (typeof fields !== 'object' || fields === null) return null
  const parsed: Record<string, string[]> = {}
  for (const [name, messages] of Object.entries(fields as Record<string, unknown>)) {
    if (!Array.isArray(messages)) continue
    const clean = messages.filter((message): message is string => typeof message === 'string' && message.trim() !== '')
    if (clean.length > 0) parsed[name] = clean
  }
  return Object.keys(parsed).length > 0 ? parsed : null
}
