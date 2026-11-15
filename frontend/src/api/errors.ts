/**
 * One error type for every API failure.
 *
 * The UI decides what to say from `kind`/`status` (localized), and may show the
 * server's `detail` for validation and conflict messages. Never put a token,
 * request body or raw stack trace in `detail`.
 */

export type ApiErrorKind = 'http' | 'network' | 'timeout' | 'parse'

export class ApiError extends Error {
  readonly status: number | null
  readonly kind: ApiErrorKind
  /** Server-provided message when the response carried one; already sanitized. */
  readonly detail: string | null

  constructor(message: string, kind: ApiErrorKind, status: number | null = null, detail: string | null = null) {
    super(message)
    this.name = 'ApiError'
    this.kind = kind
    this.status = status
    this.detail = detail
  }
}

/** True for a fetch abort, which callers must ignore instead of reporting. */
export function isAbortError(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError'
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError
}

/**
 * Reads the message out of the backend's error document.
 *
 * The tenant API returns `{"error":{"message": "..."}}`; Symfony's own
 * exception listener may return `{"title": "...", "detail": "..."}`. Anything
 * else (an HTML error page, a proxy page) has no safe message to show.
 */
export function serverMessage(payload: unknown): string | null {
  if (typeof payload !== 'object' || payload === null) return null
  const record = payload as Record<string, unknown>
  const nested = record.error
  if (typeof nested === 'object' && nested !== null) {
    const message = (nested as Record<string, unknown>).message
    if (typeof message === 'string' && message.trim() !== '') return message
  }
  for (const key of ['detail', 'title', 'message']) {
    const value = record[key]
    if (typeof value === 'string' && value.trim() !== '') return value
  }
  return null
}
