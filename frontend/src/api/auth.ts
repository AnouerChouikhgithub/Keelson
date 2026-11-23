import { request } from './client'
import { ApiError } from './errors'
import { parseSessionPayload } from '../session/session'
import type { ServerSession } from '../session/session'

/**
 * The `/api/auth/*` surface, one function per endpoint.
 *
 * Every function returns only after the server answered: a `204`/`202` means the
 * server accepted the request, and a rejected promise carries the
 * `{error:{code,message,fields?}}` envelope as an {@link ApiError}, so screens
 * can react to `invalid_credentials`, `email_not_verified`, `invalid_token`, …
 * without reading prose.
 */

export type SignUpInput = {
  email: string
  password: string
  displayName: string
  organizationName: string
}

/** `POST /api/auth/login` — the response is exactly the `GET /api/session` body. */
export async function login(email: string, password: string): Promise<Extract<ServerSession, { status: 'authenticated' }>> {
  const payload = await request<unknown>('/api/auth/login', { method: 'POST', body: { email, password } })
  const session = parseSessionPayload(payload)
  if (session === null || session.status !== 'authenticated') {
    // A 200 without a session would leave the shell signed in with no CSRF
    // token, i.e. unable to save anything. Report it instead of guessing.
    throw new ApiError('The login response did not contain a usable session', 'parse')
  }
  return session
}

/** `POST /api/auth/register` — always resolves for a well-formed body. */
export async function register(input: SignUpInput): Promise<void> {
  await request<void>('/api/auth/register', { method: 'POST', body: input })
}

/**
 * `POST /api/auth/verify-email` — confirms the address and sets its password.
 *
 * The password is required: the link proves the mailbox is reachable, and the
 * address owner chooses the password there rather than accepting the one stored
 * by whoever registered the address first.
 */
export async function verifyEmail(token: string, password: string): Promise<void> {
  await request<void>('/api/auth/verify-email', { method: 'POST', body: { token, password } })
}

/** `POST /api/auth/resend-verification` — always resolves; nothing is revealed. */
export async function resendVerification(email: string): Promise<void> {
  await request<void>('/api/auth/resend-verification', { method: 'POST', body: { email } })
}

/** `POST /api/auth/forgot-password` — always resolves; nothing is revealed. */
export async function forgotPassword(email: string): Promise<void> {
  await request<void>('/api/auth/forgot-password', { method: 'POST', body: { email } })
}

/** `POST /api/auth/reset-password` — resolves when the password was replaced. */
export async function resetPassword(token: string, password: string): Promise<void> {
  await request<void>('/api/auth/reset-password', { method: 'POST', body: { token, password } })
}

/**
 * `POST /api/auth/logout` — ends the server session.
 *
 * This is a JSON call, not a form POST: the backend invalidates its own session,
 * so there is no provider to redirect to and no reason to leave the page.
 */
export async function logout(): Promise<void> {
  await request<void>('/api/auth/logout', { method: 'POST' })
}
