import type { ReactNode } from 'react'
import { Navigate, Route, Routes } from 'react-router'
import App from './App'
import { StatusGate } from './components/StatusGate'
import { useI18n } from './i18n/I18nContext'
import { ForgotPasswordScreen } from './screens/auth/ForgotPasswordScreen'
import { ResetPasswordScreen } from './screens/auth/ResetPasswordScreen'
import { SignInScreen } from './screens/auth/SignInScreen'
import { SignUpScreen } from './screens/auth/SignUpScreen'
import { VerifyEmailScreen } from './screens/auth/VerifyEmailScreen'
import { useAuth } from './session/AuthContext'

/** Signed-out visitors only: a signed-in user has no business on these screens. */
function AnonymousOnly({ children }: { children: ReactNode }) {
  const { status } = useAuth()
  return status === 'authenticated' ? <Navigate to="/" replace /> : <>{children}</>
}

/** Signed-in users only: everything that is not a public auth route. */
function AuthenticatedOnly({ children }: { children: ReactNode }) {
  const { status } = useAuth()
  return status === 'authenticated' ? <>{children}</> : <Navigate to="/sign-in" replace />
}

/**
 * The five public routes, and the app shell for everything else.
 *
 * The shell keeps its own in-page navigation (Sprint 7 moves it onto routes), so
 * here it is one catch-all route rather than a tree. Session loading and failure
 * are handled before routing: until the server has answered, a redirect would be
 * a guess, and showing the shell would be a lie.
 */
export function AppRouter() {
  const { m } = useI18n()
  const { status, refresh } = useAuth()

  if (status === 'loading' || status === 'error') {
    return (
      <StatusGate
        title={m.session.cardTitle}
        message={status === 'loading' ? m.api.loading : m.session.readFailed}
        onRetry={() => { void refresh() }}
      />
    )
  }

  return (
    <Routes>
      <Route path="/sign-in" element={<AnonymousOnly><SignInScreen /></AnonymousOnly>} />
      <Route path="/sign-up" element={<AnonymousOnly><SignUpScreen /></AnonymousOnly>} />
      <Route path="/verify-email" element={<AnonymousOnly><VerifyEmailScreen /></AnonymousOnly>} />
      <Route path="/forgot-password" element={<AnonymousOnly><ForgotPasswordScreen /></AnonymousOnly>} />
      <Route path="/reset-password" element={<AnonymousOnly><ResetPasswordScreen /></AnonymousOnly>} />
      <Route path="*" element={<AuthenticatedOnly><App /></AuthenticatedOnly>} />
    </Routes>
  )
}
