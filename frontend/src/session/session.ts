/**
 * Server session client.
 *
 * Authentication state belongs to the Symfony session; the SPA only mirrors
 * it. Nothing here may invent a signed-in or signed-out state — every
 * transition is confirmed by a server response, so the UI never shows a fake
 * "logged out" success.
 */

export type SessionUser = {
  id: string
  email: string | null
  displayName: string | null
}

export type LogoutTarget = { url: string; csrfToken: string }

export type ServerSession =
  | { status: 'authenticated'; user: SessionUser; logout: LogoutTarget }
  | { status: 'anonymous' }

const sessionEndpoint = '/api/session'
const signedOutMarker = 'keelson-session-ended'
/** The only endpoint the sign-out form may post the CSRF token to. */
const logoutPath = '/logout'

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function parseUser(value: unknown): SessionUser | null {
  if (!isRecord(value)) return null
  const { id, email, displayName } = value
  if (typeof id !== 'string' || id === '') return null
  return {
    id,
    email: typeof email === 'string' ? email : null,
    displayName: typeof displayName === 'string' ? displayName : null,
  }
}

/**
 * Validates the session's sign-out target.
 *
 * The CSRF token must never leave the app: the target is the exact same-origin
 * `/logout` path and nothing else. A leading-slash check is not enough, because
 * `//evil.example` is a protocol-relative URL the browser resolves to another
 * host, and `/\evil.example` can be normalized the same way.
 */
export function parseLogoutTarget(value: unknown): LogoutTarget | null {
  if (!isRecord(value)) return null
  const { url, csrfToken } = value
  if (typeof url !== 'string' || url !== logoutPath) return null
  if (typeof csrfToken !== 'string' || csrfToken === '') return null
  return { url, csrfToken }
}

export function parseSessionPayload(value: unknown): ServerSession | null {
  if (!isRecord(value)) return null
  if (value.authenticated === false) return { status: 'anonymous' }
  if (value.authenticated !== true) return null

  const user = parseUser(value.user)
  const logout = parseLogoutTarget(value.logout)
  // An authenticated session without a usable logout target is reported as
  // unusable rather than as signed in: showing "Log out" that cannot end the
  // session is the defect this client exists to avoid.
  if (user === null || logout === null) return null
  return { status: 'authenticated', user, logout }
}

/** Returns null when the server state cannot be determined. */
export async function readSession(): Promise<ServerSession | null> {
  try {
    const response = await fetch(sessionEndpoint, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
      // The shell gates on this probe; without a deadline an unresponsive API
      // would leave the application on its loading screen indefinitely.
      signal: AbortSignal.timeout(10_000),
    })
    if (!response.ok) return null
    return parseSessionPayload(await response.json())
  } catch {
    return null
  }
}

/**
 * Starts the sign-out as a full-page form POST.
 *
 * The CSRF-protected route must be reached as a top-level navigation: after
 * clearing its session, Symfony answers with a redirect to the provider's
 * `end_session_endpoint`, and only the browser following that redirect ends
 * the provider's single sign-on session. A `fetch()` cannot do it — with
 * `redirect: 'manual'` the browser never navigates, and following a
 * cross-origin, credentialed redirect from fetch is blocked. So this submits
 * a real form and lets the page leave; the provider returns to the app, whose
 * session probe then reports the anonymous state.
 */
export function beginEndSession(target: LogoutTarget): void {
  markSessionEnded()

  const form = document.createElement('form')
  form.method = 'post'
  form.action = target.url
  form.hidden = true

  const csrfToken = document.createElement('input')
  csrfToken.type = 'hidden'
  csrfToken.name = '_csrf_token'
  csrfToken.value = target.csrfToken

  form.append(csrfToken)
  document.body.append(form)
  form.submit()
}

/**
 * Remembers in this tab that the browser has just been sent through sign-out.
 * A full-page navigation loses in-memory state, and after the provider returns
 * the server only says "anonymous" — which a never-signed-in visitor also gets.
 * The marker lets the shell show the signed-out screen without treating the
 * anonymous state itself as proof of a sign-out.
 */
function markSessionEnded(): void {
  try {
    window.sessionStorage.setItem(signedOutMarker, '1')
  } catch {
    // Storage can be unavailable (private mode); the flow still works.
  }
}

/** True while this tab is returning from a sign-out navigation. */
export function readSessionEnded(): boolean {
  try {
    return window.sessionStorage.getItem(signedOutMarker) === '1'
  } catch {
    return false
  }
}

/** Clears the marker once the server reports an authenticated session. */
export function clearSessionEnded(): void {
  try {
    window.sessionStorage.removeItem(signedOutMarker)
  } catch {
    // Nothing to clear when storage is unavailable.
  }
}
