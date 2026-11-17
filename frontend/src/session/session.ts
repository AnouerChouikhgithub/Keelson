/**
 * Server session client.
 *
 * Authentication state belongs to the Symfony session; the SPA only mirrors it.
 * Nothing here may invent a signed-in or signed-out state — every transition is
 * confirmed by a server response, so the UI never shows a fake "signed out"
 * success.
 */

import { request } from '../api/client'

export type SessionUser = {
  id: string
  email: string | null
  displayName: string | null
}

export type ServerSession =
  | { status: 'authenticated'; user: SessionUser; csrfToken: string }
  | { status: 'anonymous' }

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

/**
 * Validates `GET /api/session`, which is also the body of a successful login.
 *
 * An authenticated payload without a usable CSRF token is reported as unusable
 * rather than as signed in: the UI would otherwise render a shell in which every
 * save, send and delete silently fails. `null` therefore means "the server's
 * answer cannot be trusted", never "signed out".
 */
export function parseSessionPayload(value: unknown): ServerSession | null {
  if (!isRecord(value)) return null
  if (value.authenticated === false) return { status: 'anonymous' }
  if (value.authenticated !== true) return null

  const user = parseUser(value.user)
  const csrfToken = value.csrfToken
  if (user === null) return null
  if (typeof csrfToken !== 'string' || csrfToken === '') return null

  return { status: 'authenticated', user, csrfToken }
}

/**
 * Reads the session once. Returns null when the server state cannot be
 * determined: unreachable, timed out, or an answer that does not match the
 * contract. The caller decides whether that is a gate with a retry or a failure.
 */
export async function readSession(): Promise<ServerSession | null> {
  try {
    return parseSessionPayload(await request<unknown>(sessionEndpoint, { timeoutMs: 10_000 }))
  } catch {
    return null
  }
}
