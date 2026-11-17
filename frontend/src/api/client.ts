import { ApiError, errorCode, errorFields, serverMessage } from './errors'

/**
 * Single HTTP entry point for the SPA.
 *
 * Requests are same-origin: the SPA is served from the same origin as the API
 * (Vite proxies `/api` in development, the reverse proxy routes it in
 * production), so the Symfony session cookie travels with
 * `credentials: 'same-origin'` and no CORS negotiation is involved. A
 * cross-origin deployment is deliberately not supported — the backend sends no
 * `Access-Control-Allow-*` headers, and inventing a client-side base URL would
 * only produce requests the browser refuses. See `docs/adr/0006`.
 *
 * Every unsafe request also carries the CSRF token the session handed out, in
 * the `X-CSRF-Token` header the backend's mutation guard requires.
 *
 * Failure messages on `ApiError` are stable machine codes, never prose: the UI
 * localizes from `kind`/`status`/`code` in `presentError`, so no English string
 * from this file can reach a screen.
 */

export type RequestOptions = {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE'
  body?: unknown
  signal?: AbortSignal
  /** Overrides the request deadline; a hung backend must not spin forever. */
  timeoutMs?: number
  /** Set on the single retry taken after a `csrf_invalid` refusal. */
  csrfRetried?: boolean
}

const DEFAULT_TIMEOUT_MS = 20_000
const UNSAFE_METHODS = new Set<string>(['POST', 'PUT', 'PATCH', 'DELETE'])

/**
 * The session's CSRF token, held in memory only.
 *
 * It is set by the session provider from `GET /api/session` (and refreshed after
 * a rotation); it is never written to `localStorage`, `sessionStorage` or a
 * cookie of our own, so closing the tab discards it.
 */
let csrfToken: string | null = null

/** Re-reads the session and returns its (possibly rotated) token, or null. */
let sessionRefresher: (() => Promise<string | null>) | null = null

/** Called once per request the server refused with 401, so the provider can re-read. */
let unauthorizedHandler: (() => void) | null = null

export function setCsrfToken(token: string | null): void {
  csrfToken = token
}

export function setSessionRefresher(refresher: (() => Promise<string | null>) | null): void {
  sessionRefresher = refresher
}

export function setUnauthorizedHandler(handler: (() => void) | null): void {
  unauthorizedHandler = handler
}

/**
 * Performs one request and returns the parsed JSON body.
 *
 * Callers must treat `ApiError.kind`/`status`/`code` as the contract and
 * localize the message; `detail` is only for validation/conflict text from the
 * server.
 *
 * A `403 csrf_invalid` means the session handed us a token the server no longer
 * accepts (it was rotated by a login in another tab, for example). That is
 * recoverable: re-read the session once, then replay the request once. The
 * `csrfRetried` flag bounds it, so a server that keeps refusing cannot turn one
 * click into a request loop.
 *
 * A `401` means the cookie is gone or expired; the provider is told once and
 * decides from the server's answer, rather than the client inventing a
 * signed-out state.
 */
export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, signal: callerSignal, timeoutMs = DEFAULT_TIMEOUT_MS, csrfRetried = false } = options
  // A deadline is separate from a caller abort: the first is a failure the user
  // must see, the second is a request the caller deliberately superseded.
  const deadline = AbortSignal.timeout(timeoutMs)
  const signal = callerSignal === undefined ? deadline : AbortSignal.any([callerSignal, deadline])
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (UNSAFE_METHODS.has(method) && csrfToken !== null) headers['X-CSRF-Token'] = csrfToken

  let response: Response
  try {
    response = await fetch(path, {
      method,
      signal,
      credentials: 'same-origin',
      cache: 'no-store',
      headers,
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    })
  } catch (error) {
    // A deadline is a real failure; a caller abort is not (the caller moved on).
    if (deadline.aborted) throw new ApiError('request_timeout', 'timeout')
    if (error instanceof DOMException && error.name === 'AbortError') throw error
    throw new ApiError('network_error', 'network')
  }

  // 204 (delete, sign-out) and other bodyless successes are valid responses.
  if (response.status === 204) return undefined as T

  const text = await response.text()
  let payload: unknown = null
  if (text !== '') {
    try {
      payload = JSON.parse(text)
    } catch {
      payload = null
    }
  }

  if (!response.ok) {
    // A non-JSON error page (proxy, HTML exception page) yields only a status.
    const failure = new ApiError(
      'http_error',
      'http',
      response.status,
      serverMessage(payload),
      errorCode(payload),
      errorFields(payload),
    )

    if (response.status === 401) unauthorizedHandler?.()

    if (failure.code === 'csrf_invalid' && !csrfRetried && UNSAFE_METHODS.has(method) && sessionRefresher !== null) {
      const renewed = await sessionRefresher()
      if (renewed !== null) {
        return request<T>(path, { ...options, csrfRetried: true })
      }
    }

    throw failure
  }

  if (text !== '' && payload === null) {
    // The API answers JSON for every route, so HTML here means the request was
    // served by something else (a proxy, the SPA fallback, a host rewrite) and
    // the call never reached a controller. That is a misrouted request, not an
    // ended session, so it must not be reported as one.
    if (isHtml(response)) throw new ApiError('html_response', 'http', response.status)
    throw new ApiError('invalid_json_response', 'parse', response.status)
  }

  return payload as T
}

function isHtml(response: Response): boolean {
  return (response.headers.get('Content-Type') ?? '').toLowerCase().includes('text/html')
}

/** Encodes query parameters, skipping empty values. */
export function query(params: Record<string, string | number | null | undefined>): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === null || value === undefined || value === '') continue
    search.set(key, String(value))
  }
  const encoded = search.toString()
  return encoded === '' ? '' : `?${encoded}`
}
