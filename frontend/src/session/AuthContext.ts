import { createContext, useContext } from 'react'
import type { SessionUser } from './session'

/**
 * What the shell needs to know about the current session.
 *
 * `status` is never guessed: `loading` until the server answers, `error` when it
 * cannot be reached, and `authenticated` only with a user *and* a CSRF token the
 * server handed out. `signIn`/`signOut` resolve only when the server confirmed
 * the change; they reject otherwise, so a screen can report the real outcome.
 */
export type AuthStatus = 'loading' | 'anonymous' | 'authenticated' | 'error'

export type AuthValue = {
  status: AuthStatus
  user: SessionUser | null
  csrfToken: string | null
  /** Re-reads `GET /api/session`; also the retry for a failed read. */
  refresh: () => Promise<void>
  signIn: (email: string, password: string) => Promise<void>
  signOut: () => Promise<void>
}

export const AuthContext = createContext<AuthValue | null>(null)

export function useAuth(): AuthValue {
  const value = useContext(AuthContext)
  if (!value) throw new Error('useAuth must be used inside AuthProvider')
  return value
}
