import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { login, logout } from '../api/auth'
import { setCsrfToken, setSessionRefresher, setUnauthorizedHandler } from '../api/client'
import { clearApiCache } from '../api/useApiResource'
import { AuthContext } from './AuthContext'
import type { AuthValue } from './AuthContext'
import { readSession } from './session'
import type { ServerSession } from './session'
import { createSessionBroadcaster, shouldInvalidate } from './sync'
import type { SessionBroadcaster } from './sync'

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

/** A tab that was in the background for a moment re-checks once re-shown, not on a timer. */
const VISIBILITY_REVALIDATE_INTERVAL_MS = 30_000

/**
 * Owns the session mirror and the in-memory CSRF token.
 *
 * The token never leaves this process: it is handed to `api/client.ts` in
 * memory, and to no storage API. Reading the session is the only way into the
 * `authenticated` state, so signing in, refreshing and retrying a rotated token
 * all converge on the same server-confirmed shape.
 *
 * The session is shared by every tab of the browser, so this provider keeps the
 * tabs in step: a sign-in or sign-out broadcasts a hint, the other tabs re-read
 * the session, and a changed principal drops all cached tenant reads. A tab
 * never takes another tab's word for the account — the message only prompts the
 * re-read.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>({ status: 'loading' })
  const sessionRef = useRef<ServerSession | null>(null)
  const broadcasterRef = useRef<SessionBroadcaster | null>(null)
  const revalidatingRef = useRef<Promise<void> | null>(null)
  const lastVisibilityCheckRef = useRef(0)

  /** Adopts a confirmed answer. `null` is a failure, and renders as the gate. */
  const adopt = useCallback((session: ServerSession | null) => {
    sessionRef.current = session
    setCsrfToken(session?.status === 'authenticated' ? session.csrfToken : null)
    setState(toState(session))
  }, [])

  /**
   * Re-reads the session; concurrent callers share one request.
   *
   * Deliberately silent on failure: an unreachable server is not an account
   * change, so the last confirmed state stays on screen and the next signal or
   * focus retries.
   */
  const revalidate = useCallback(async (): Promise<void> => {
    if (revalidatingRef.current !== null) return revalidatingRef.current

    const run = (async () => {
      const previous = sessionRef.current
      const next = await readSession()
      if (next === null) return
      if (shouldInvalidate(previous, next)) clearApiCache()
      adopt(next)
    })().finally(() => {
      revalidatingRef.current = null
    })

    revalidatingRef.current = run
    return run
  }, [adopt])

  const load = useCallback(async () => {
    const session = await readSession()
    if (session !== null) {
      if (shouldInvalidate(sessionRef.current, session)) clearApiCache()
      adopt(session)
    }
    return session
  }, [adopt])

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

  // A request answered 401 means this tab's cookie is gone (signed out elsewhere,
  // or the session expired). The server is the authority: re-read, do not guess.
  useEffect(() => {
    setUnauthorizedHandler(() => {
      void revalidate()
    })
    return () => {
      setUnauthorizedHandler(null)
    }
  }, [revalidate])

  // Sign-in and sign-out in another tab.
  useEffect(() => {
    const broadcaster = createSessionBroadcaster(() => {
      void revalidate()
    })
    broadcasterRef.current = broadcaster
    return () => {
      broadcasterRef.current = null
      broadcaster.close()
    }
  }, [revalidate])

  // Coming back to a backgrounded tab: one revalidation at most per interval, so
  // alt-tabbing does not poll the server.
  useEffect(() => {
    const check = () => {
      if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return
      const now = Date.now()
      if (now - lastVisibilityCheckRef.current < VISIBILITY_REVALIDATE_INTERVAL_MS) return
      lastVisibilityCheckRef.current = now
      void revalidate()
    }

    document.addEventListener('visibilitychange', check)
    window.addEventListener('focus', check)
    return () => {
      document.removeEventListener('visibilitychange', check)
      window.removeEventListener('focus', check)
    }
  }, [revalidate])

  // The first read happens once, through a promise continuation rather than an
  // awaited call, so the initial state is already rendered while the API is asked.
  useEffect(() => {
    let cancelled = false
    void readSession().then((session) => {
      if (cancelled) return
      adopt(session)
    })
    return () => {
      cancelled = true
    }
  }, [adopt])

  const refresh = useCallback(async () => {
    await load()
  }, [load])

  const signIn = useCallback(async (email: string, password: string) => {
    const session = await login(email, password)
    // A different principal may be signing in: tenant reads must not survive.
    clearApiCache()
    sessionRef.current = session
    setCsrfToken(session.csrfToken)
    setState({ status: 'authenticated', session })
    broadcasterRef.current?.post('signed-in')
  }, [])

  const signOut = useCallback(async () => {
    // The provider only reports what the server did: a failed sign-out leaves
    // this state untouched and throws, so the caller can show the failure
    // instead of pretending the session is over.
    await logout()
    clearApiCache()
    sessionRef.current = { status: 'anonymous' }
    setCsrfToken(null)
    setState({ status: 'anonymous' })
    broadcasterRef.current?.post('signed-out')
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
