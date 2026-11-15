import { ApiError, serverMessage } from './errors'

/**
 * Single HTTP entry point for the SPA.
 *
 * Requests are same-origin by default (Vite proxies `/api` in development), so
 * the Symfony session cookie travels with `credentials: 'same-origin'`. The
 * backend's mutation guard accepts a same-origin `Origin`; no CSRF header or
 * token endpoint is invented here.
 *
 * `VITE_API_BASE_URL` exists for deployments that serve the API from another
 * origin (which then needs an explicit CORS allow-list). It must never contain
 * a secret: Vite inlines every `VITE_*` value into the public bundle.
 */
const configuredBaseUrl = (import.meta.env.VITE_API_BASE_URL ?? '').trim().replace(/\/+$/, '')

export type RequestOptions = {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE'
  body?: unknown
  signal?: AbortSignal
  /** Overrides the request deadline; a hung backend must not spin forever. */
  timeoutMs?: number
}

const DEFAULT_TIMEOUT_MS = 20_000

/**
 * Performs one request and returns the parsed JSON body.
 *
 * Callers must treat `ApiError.kind`/`status` as the contract and localize the
 * message; `detail` is only for validation/conflict text from the server.
 */
export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, signal: callerSignal, timeoutMs = DEFAULT_TIMEOUT_MS } = options
  // A deadline is separate from a caller abort: the first is a failure the user
  // must see, the second is a request the caller deliberately superseded.
  const deadline = AbortSignal.timeout(timeoutMs)
  const signal = callerSignal === undefined ? deadline : AbortSignal.any([callerSignal, deadline])
  let response: Response
  try {
    response = await fetch(`${configuredBaseUrl}${path}`, {
      method,
      signal,
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
      },
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    })
  } catch (error) {
    // A deadline is a real failure; a caller abort is not (the caller moved on).
    if (deadline.aborted) throw new ApiError('The request timed out', 'timeout')
    if (error instanceof DOMException && error.name === 'AbortError') throw error
    throw new ApiError('Network request failed', 'network')
  }

  // 204 (delete) and other bodyless successes are valid responses.
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
    throw new ApiError(
      `Request failed with status ${response.status}`,
      'http',
      response.status,
      serverMessage(payload),
    )
  }

  if (text !== '' && payload === null) {
    // An expired session is redirected to the provider's sign-in page, which
    // `fetch` follows and which answers 200 HTML. That is an unauthenticated
    // session, not a malformed payload, so it must not look like a server bug.
    if (isHtml(response)) throw new ApiError('The session has ended', 'http', 401)
    throw new ApiError('The server returned a response that is not JSON', 'parse', response.status)
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
