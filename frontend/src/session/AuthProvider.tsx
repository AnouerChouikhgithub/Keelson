import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { login, logout } from '../api/auth'
import { setCsrfToken, setSessionRefresher } from '../api/client'
import { AuthContext } from './AuthContext'
import type { AuthValue } from './AuthContext'
import { readSession } from './session'
import type { ServerSession } from './session'

type AuthenticatedSession = Extract<ServerSession, { status: 'authenticated' }>

type AuthState =
  | { status: 'loading' }
  | { status: 'anonymous' }
  | { status: 'error' }
  | { status: 'authenticated'; session: AuthenticatedSession }

function toState(session: ServerSession | null): AuthState {
  if (session === null) return { status: 'error' }
  if (session.status === 'anonymous') return { status: 'anonymous' }
  return { status: 'authenticated', session }
}

/**
 * Owns the session mirror and the in-memory CSRF token.
 *
 * The token never leaves this process: it is handed to `api/client.ts` in
 * memory, and to no storage API. Reading the session is the only way into the
 * `authenticated` state, so signing in, refreshing and retrying a rotated token
 * all converge on the same server-confirmed shape.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>({ status: 'loading' })

  const load = useCallback(async () => {
    const session = await readSession()
    setCsrfToken(session?.status === 'authenticated' ? session.csrfToken : null)
    setState(toState(session))
    return session
  }, [])

  // The client's one retry after `403 csrf_invalid` re-reads the session here, so
  // a token rotated in another tab is picked up without a page reload.
  useEffect(() => {
    setSessionRefresher(async () => {
      const session = await load()
      return session?.status === 'authenticated' ? session.csrfToken : null
    })
    return () => {
      setSessionRefresher(null)
    }
  }, [load])

  // The first read happens once, through a promise continuation rather than an
  // awaited call, so the initial state is already rendered while the API is asked.
  useEffect(() => {
    let cancelled = false
    void readSession().then((session) => {
      if (cancelled) return
      setCsrfToken(session?.status === 'authenticated' ? session.csrfToken : null)
      setState(toState(session))
    })
    return () => {
      cancelled = true
    }
  }, [])

  const refresh = useCallback(async () => {
    await load()
  }, [load])

  const signIn = useCallback(async (email: string, password: string) => {
    const session = await login(email, password)
    setCsrfToken(session.csrfToken)
    setState({ status: 'authenticated', session })
  }, [])

  const signOut = useCallback(async () => {
    // The provider only reports what the server did: a failed sign-out leaves
    // this state untouched and throws, so the caller can show the failure
    // instead of pretending the session is over.
    await logout()
    setCsrfToken(null)
    setState({ status: 'anonymous' })
  }, [])

  const value = useMemo<AuthValue>(() => {
    if (state.status === 'authenticated') {
      return {
        status: 'authenticated',
        user: state.session.user,
        csrfToken: state.session.csrfToken,
        refresh,
        signIn,
        signOut,
      }
    }
    return { status: state.status, user: null, csrfToken: null, refresh, signIn, signOut }
  }, [state, refresh, signIn, signOut])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
