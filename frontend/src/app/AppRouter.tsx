import { lazy } from 'react'
import type { ReactNode } from 'react'
import { Navigate, Route, Routes } from 'react-router'
import { Skeleton } from '../components/Skeleton'
import { StatusGate } from '../components/StatusGate'
import { useI18n } from '../i18n/I18nContext'
import { ForgotPasswordScreen } from '../screens/auth/ForgotPasswordScreen'
import { ResetPasswordScreen } from '../screens/auth/ResetPasswordScreen'
import { SignInScreen } from '../screens/auth/SignInScreen'
import { SignUpScreen } from '../screens/auth/SignUpScreen'
import { VerifyEmailScreen } from '../screens/auth/VerifyEmailScreen'
import { useAuth } from '../session/AuthContext'
import { AppShell } from './AppShell'
import { OrganizationScope } from './OrganizationScope'
import { useOrganizations } from './useOrganizations'

// One chunk per route: the shell stays small, and a screen's code is fetched
// only when its URL is opened.
const DashboardScreen = lazy(() => import('../screens/Dashboard'))
const InvoicesScreen = lazy(() => import('../screens/Invoices'))
const InvoiceDetailScreen = lazy(() => import('../screens/InvoiceDetail'))
const InvoiceEditorScreen = lazy(() => import('../screens/InvoiceEditor'))
const CustomersScreen = lazy(() => import('../screens/Customers'))
const AuditScreen = lazy(() => import('../screens/Audit'))
const TeamScreen = lazy(() => import('../screens/Team'))
const NotificationsScreen = lazy(() => import('../screens/Notifications'))
const SettingsScreen = lazy(() => import('../screens/Settings'))
const AccountScreen = lazy(() => import('../screens/Account'))
const OnboardingScreen = lazy(() => import('../screens/Onboarding'))
const AcceptInvitationScreen = lazy(() => import('../screens/AcceptInvitation'))
const NotFoundScreen = lazy(() => import('../screens/NotFound'))

/** Signed-out visitors only: a signed-in user has no business on these screens. */
function AnonymousOnly({ children }: { children: ReactNode }) {
  const { status } = useAuth()
  return status === 'authenticated' ? <Navigate to="/" replace /> : <>{children}</>
}

/** `/` opens the first workspace, or onboarding when there is none yet. */
function WorkspaceIndex() {
  const { m } = useI18n()
  const organizations = useOrganizations()

  if (organizations.state.status === 'loading') {
    return <Skeleton variant="list" count={4} label={m.common.loading} />
  }
  if (organizations.state.status === 'error') {
    return (
      <StatusGate
        title={m.api.listFailed}
        message={m.session.readFailed}
        onRetry={organizations.reload}
      />
    )
  }
  const first = organizations.state.data[0]
  return <Navigate to={first ? `/o/${first.id}/dashboard` : '/onboarding'} replace />
}

/**
 * Public auth routes, then the signed-in shell. Session loading and failure are
 * settled before routing: until the server answers, a redirect would be a guess.
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

  if (status !== 'authenticated') {
    return (
      <Routes>
        <Route path="/sign-in" element={<SignInScreen />} />
        <Route path="/sign-up" element={<SignUpScreen />} />
        <Route path="/verify-email" element={<VerifyEmailScreen />} />
        <Route path="/forgot-password" element={<ForgotPasswordScreen />} />
        <Route path="/reset-password" element={<ResetPasswordScreen />} />
        <Route path="/accept-invitation" element={<AcceptInvitationScreen />} />
        <Route path="*" element={<Navigate to="/sign-in" replace />} />
      </Routes>
    )
  }

  return (
    <Routes>
      <Route path="/sign-in" element={<AnonymousOnly><SignInScreen /></AnonymousOnly>} />
      <Route path="/sign-up" element={<AnonymousOnly><SignUpScreen /></AnonymousOnly>} />
      <Route path="/verify-email" element={<AnonymousOnly><VerifyEmailScreen /></AnonymousOnly>} />
      <Route path="/forgot-password" element={<AnonymousOnly><ForgotPasswordScreen /></AnonymousOnly>} />
      <Route path="/reset-password" element={<AnonymousOnly><ResetPasswordScreen /></AnonymousOnly>} />
      <Route path="/accept-invitation" element={<AcceptInvitationScreen />} />

      <Route element={<AppShell />}>
        <Route index element={<WorkspaceIndex />} />
        <Route path="/onboarding" element={<OnboardingScreen />} />
        <Route path="/o/:orgId" element={<OrganizationScope />}>
          <Route index element={<Navigate to="dashboard" replace />} />
          <Route path="dashboard" element={<DashboardScreen />} />
          <Route path="invoices" element={<InvoicesScreen />} />
          <Route path="invoices/new" element={<InvoiceEditorScreen />} />
          <Route path="invoices/:invoiceId" element={<InvoiceDetailScreen />} />
          <Route path="invoices/:invoiceId/edit" element={<InvoiceEditorScreen />} />
          <Route path="customers" element={<CustomersScreen />} />
          <Route path="audit" element={<AuditScreen />} />
          <Route path="team" element={<TeamScreen />} />
          <Route path="notifications" element={<NotificationsScreen />} />
          <Route path="settings" element={<SettingsScreen />} />
          <Route path="account" element={<AccountScreen />} />
          <Route path="*" element={<NotFoundScreen />} />
        </Route>
        <Route path="*" element={<NotFoundScreen />} />
      </Route>
    </Routes>
  )
}
