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

export type LogoutResult = 'confirmed' | 'failed'

const sessionEndpoint = '/api/session'

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

function parseLogout(value: unknown): LogoutTarget | null {
  if (!isRecord(value)) return null
  const { url, csrfToken } = value
  if (typeof url !== 'string' || typeof csrfToken !== 'string') return null
  // Only ever post the token back to the same origin: a session response must
  // never be able to redirect the sign-out request elsewhere.
  if (!url.startsWith('/')) return null
  return { url, csrfToken }
}

function parseSession(value: unknown): ServerSession | null {
  if (!isRecord(value)) return null
  if (value.authenticated === false) return { status: 'anonymous' }
  if (value.authenticated !== true) return null

  const user = parseUser(value.user)
  const logout = parseLogout(value.logout)
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
    })
    if (!response.ok) return null
    return parseSession(await response.json())
  } catch {
    return null
  }
}

/**
 * Ends the server session through the CSRF-protected logout route, then
 * re-reads the session. `confirmed` is only returned when the server reports
 * the caller is anonymous afterwards.
 */
export async function endSession(target: LogoutTarget): Promise<LogoutResult> {
  try {
    const response = await fetch(target.url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ _csrf_token: target.csrfToken }),
      redirect: 'manual',
    })
    // Symfony answers a successful logout with a redirect to the target path;
    // a rejected CSRF token answers 403 and an anonymous caller is bounced to
    // /login. `redirect: 'manual'` hides that status behind an opaque
    // redirect, which is still proof the route accepted the POST.
    const accepted = response.type === 'opaqueredirect'
      || response.ok
      || response.status === 302
      || response.status === 303
    if (!accepted) return 'failed'
  } catch {
    return 'failed'
  }

  const after = await readSession()
  if (after === null) return 'failed'
  return after.status === 'anonymous' ? 'confirmed' : 'failed'
}
