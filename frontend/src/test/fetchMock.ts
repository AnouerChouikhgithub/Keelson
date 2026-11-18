import { vi } from 'vitest'

/**
 * One canned HTTP answer.
 *
 * Screens are tested against the real API client, sessions and stores; only
 * `fetch` is replaced, so a request the screen should not make fails the test
 * instead of silently returning an empty object.
 */
export type FetchRoute = {
  /** Substring of the request path, or a pattern it must match. */
  path: string | RegExp
  /** Defaults to any method. */
  method?: string
  status?: number
  /** A fixed body, or a function evaluated per request (a stateful stub). */
  json?: unknown
  /** Used verbatim instead of building a JSON response. */
  response?: Response
}

function matches(route: FetchRoute, method: string, path: string): boolean {
  if (route.method !== undefined && route.method.toUpperCase() !== method) return false
  return typeof route.path === 'string' ? path.includes(route.path) : route.path.test(path)
}

/** Replaces `fetch` with a router over `routes` and returns the spy. */
export function installFetch(routes: FetchRoute[]) {
  const mock = vi.fn<typeof fetch>(async (input, init) => {
    const path = typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url
    const method = (init?.method ?? 'GET').toUpperCase()
    const route = routes.find((candidate) => matches(candidate, method, path))
    if (route === undefined) throw new Error(`Unstubbed request: ${method} ${path}`)
    if (route.response !== undefined) return route.response
    // A function body is called for every request, so a test can model a value
    // that changes between calls (a status that flips after a write).
    const body = typeof route.json === 'function' ? (route.json as () => unknown)() : route.json
    return new Response(JSON.stringify(body ?? {}), {
      status: route.status ?? 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
  vi.stubGlobal('fetch', mock)
  return mock
}

/** The requests the screen made, as `METHOD /path` with the parsed body. */
export function requests(mock: ReturnType<typeof installFetch>) {
  return mock.mock.calls.map(([input, init]) => ({
    method: (init?.method ?? 'GET').toUpperCase(),
    path: typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url,
    body: typeof init?.body === 'string' ? (JSON.parse(init.body) as unknown) : null,
  }))
}

/** The `{authenticated:false}` answer `GET /api/session` gives a signed-out tab. */
export const anonymousSession: FetchRoute = {
  path: '/api/session',
  method: 'GET',
  json: { authenticated: false },
}

/** A signed-in session for screens that read the current user. */
export function authenticatedSession(userId: string, csrfToken = 'csrf-token'): FetchRoute {
  return {
    path: '/api/session',
    method: 'GET',
    json: { authenticated: true, user: { id: userId, email: 'owner@example.test', displayName: 'Owner' }, csrfToken },
  }
}
