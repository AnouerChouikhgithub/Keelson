import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { listOrganizations } from './api/organizations'
import type { OrganizationMembership } from './api/types'
import { presentError } from './api/presentError'
import { useApiResource } from './api/useApiResource'
import { Icon } from './components/Icon'
import type { IconName } from './components/Icon'
import { ProfileMenu } from './components/ProfileMenu'
import { SearchDialog } from './components/SearchDialog'
import { ThemeControl } from './components/ThemeControl'
import { useI18n } from './i18n/I18nContext'
import { mockInvitations } from './mocks/organizations'
import type { InvitationRecord } from './mocks/organizations'
import { useNotifications } from './notifications/useNotifications'
import type { NavPageId, PageId } from './pages'
import { AccountScreen } from './screens/AccountScreen'
import { BillingScreen } from './screens/BillingScreen'
import { CustomersScreen } from './screens/CustomersScreen'
import { Dashboard } from './screens/Dashboard'
import { InvoiceEditorScreen } from './screens/InvoiceEditorScreen'
import { InvoicesScreen } from './screens/InvoicesScreen'
import { PricingScreen } from './screens/PricingScreen'
import { TeamScreen } from './screens/TeamScreen'
import { NotificationsScreen } from './screens/NotificationsScreen'
import { Onboarding } from './screens/Onboarding'
import { SettingsScreen } from './screens/SettingsScreen'
import { SignedOutScreen } from './screens/SignedOutScreen'
import { buildSearchResults, searchShortcutLabel } from './search/search'
import { beginEndSession, clearSessionEnded, readSession, readSessionEnded } from './session/session'
import type { ServerSession } from './session/session'
import { initialsOf } from './utils/initials'
import './App.css'

const navItems: { id: NavPageId; icon: IconName }[] = [
  { id: 'dashboard', icon: 'grid' },
  { id: 'onboarding', icon: 'building' },
  { id: 'invoices', icon: 'file' },
  { id: 'customers', icon: 'users' },
  { id: 'team', icon: 'users' },
  { id: 'billing', icon: 'card' },
  { id: 'settings', icon: 'settings' },
  { id: 'pricing', icon: 'globe' },
]

type SessionState =
  | { status: 'loading' }
  | { status: 'anonymous' }
  | { status: 'error' }
  | { status: 'authenticated'; session: Extract<ServerSession, { status: 'authenticated' }> }

function OrganizationSwitcher({
  organizations,
  activeOrganizationId,
  onSelect,
  onCreate,
}: {
  organizations: OrganizationMembership[]
  activeOrganizationId: string | null
  onSelect: (organizationId: string) => void
  onCreate: () => void
}) {
  const { m } = useI18n()
  const [isOpen, setIsOpen] = useState(false)
  const activeOrganization = organizations.find((item) => item.id === activeOrganizationId) ?? organizations[0]

  if (!activeOrganization) {
    return (
      <button className="organization-switcher" type="button" onClick={onCreate}>
        <span className="org-avatar">—</span>
        <span className="org-text"><strong>{m.account.noWorkspace}</strong><small>{m.nav.createOrg}</small></span>
        <Icon name="plus" size={16} />
      </button>
    )
  }

  return (
    <div className="organization-menu-wrap">
      <button
        className="organization-switcher"
        type="button"
        aria-label={m.nav.currentOrg(activeOrganization.name)}
        aria-expanded={isOpen}
        aria-haspopup="menu"
        onClick={() => setIsOpen(!isOpen)}
      >
        <span className="org-avatar">{initialsOf(activeOrganization.name)}</span>
        <span className="org-text">
          <strong>{activeOrganization.name}</strong>
          <small>{m.roles[activeOrganization.role.toLowerCase() as keyof typeof m.roles]} · {activeOrganization.subdomain}</small>
        </span>
        <Icon name="chevron" size={16} />
      </button>
      {isOpen && (
        <div className="organization-menu" role="menu" aria-label={m.nav.switchOrg}>
          <p className="menu-eyebrow">{m.nav.yourWorkspaces}</p>
          {organizations.map((item) => (
            <button
              type="button"
              role="menuitemradio"
              aria-checked={item.id === activeOrganizationId}
              className={`organization-option ${item.id === activeOrganizationId ? 'selected' : ''}`}
              key={item.id}
              onClick={() => { onSelect(item.id); setIsOpen(false) }}
            >
              <span className="org-avatar">{initialsOf(item.name)}</span>
              <span>
                <strong>{item.name}</strong>
                <small>{item.subdomain} · {m.roles[item.role.toLowerCase() as keyof typeof m.roles]}</small>
              </span>
              {item.id === activeOrganizationId && <Icon name="check" size={16} />}
            </button>
          ))}
          <div className="menu-separator" />
          <button type="button" className="create-workspace-link" onClick={() => { onCreate(); setIsOpen(false) }}>
            <Icon name="plus" size={17} /> {m.nav.createOrg}
          </button>
          <p className="menu-footnote">{m.nav.menuFootnote}</p>
        </div>
      )}
    </div>
  )
}

/**
 * Shown while the session or the caller's memberships cannot be read. It never
 * falls back to demo identity or demo records.
 */
function StatusGate({ title, message, onRetry }: { title: string; message: string; onRetry: () => void }) {
  const { m } = useI18n()
  return (
    <main className="signed-out-screen">
      <section className="panel signed-out-card">
        <img src="/Keelson.svg" alt="" width={34} height={34} />
        <h1>{title}</h1>
        <p>{message}</p>
        <button type="button" className="button button-secondary" onClick={onRetry}>{m.api.reload}</button>
      </section>
    </main>
  )
}

function App() {
  const { m } = useI18n()
  const notifications = useNotifications()
  const [activePage, setActivePage] = useState<PageId>('dashboard')
  const [mobileNavOpen, setMobileNavOpen] = useState(false)
  const [searchOpen, setSearchOpen] = useState(false)
  const [sessionState, setSessionState] = useState<SessionState>({ status: 'loading' })
  const [sessionAttempt, setSessionAttempt] = useState(0)
  const [activeOrganizationId, setActiveOrganizationId] = useState<string | null>(null)
  const [invitations, setInvitations] = useState<InvitationRecord[]>(mockInvitations)
  const [editorInvoiceId, setEditorInvoiceId] = useState<string | null>(null)
  // Survives the full-page sign-out navigation, so the returning tab shows the
  // signed-out screen instead of briefly re-rendering the shell.
  const [signedOut, setSignedOut] = useState(readSessionEnded)
  const [logoutPending, setLogoutPending] = useState(false)
  const [logoutNotice, setLogoutNotice] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    void readSession().then((state) => {
      if (cancelled) return
      if (state === null) {
        setSessionState({ status: 'error' })
        return
      }
      if (state.status === 'anonymous') {
        setSessionState({ status: 'anonymous' })
        return
      }
      clearSessionEnded()
      setSignedOut(false)
      setSessionState({ status: 'authenticated', session: state })
    })
    return () => { cancelled = true }
  }, [sessionAttempt])

  const sessionUser = sessionState.status === 'authenticated' ? sessionState.session.user : null

  const loadOrganizations = useCallback(
    (signal: AbortSignal) => (sessionState.status === 'authenticated'
      ? listOrganizations(signal)
      : Promise.resolve([])),
    // A new session must refetch the memberships.
    [sessionState.status],
  )
  const organizations = useApiResource(loadOrganizations)
  const membershipList = useMemo(
    () => organizations.state.status === 'ready' ? organizations.state.data : [],
    [organizations.state],
  )

  // The selected organization is a UI preference; the server re-authorizes every
  // request. The selection is resolved during render rather than synchronized
  // by an effect, so a revoked membership simply stops matching.
  const activeMembership = membershipList.find((item) => item.id === activeOrganizationId)
    ?? membershipList[0]
    ?? null
  const activeOrganization = activeMembership
    ? { id: activeMembership.id, name: activeMembership.name }
    : null

  /** Renders organization-scoped content, or an honest gate while it is unknown. */
  function withWorkspace(render: (organizationId: string, role: OrganizationMembership['role']) => ReactNode): ReactNode {
    const retry = () => { organizations.reload() }
    if (organizations.state.status === 'loading') return <StatusGate title={m.nav.workspace} message={m.api.loading} onRetry={retry} />
    if (organizations.state.status === 'error') {
      return <StatusGate title={m.nav.workspace} message={presentError(m, organizations.state.error)} onRetry={retry} />
    }
    if (activeMembership === null) return <StatusGate title={m.account.noWorkspace} message={m.account.noWorkspaceHint} onRetry={retry} />
    return render(activeMembership.id, activeMembership.role)
  }

  const searchResults = useMemo(
    () => buildSearchResults({ m, organizations: membershipList, notifications: notifications.notifications }),
    [m, membershipList, notifications.notifications],
  )

  function selectPage(page: PageId) {
    setActivePage(page)
    setMobileNavOpen(false)
  }

  function selectOrganization(organizationId: string) {
    // Different tenant => different data; close the editor so it cannot keep a
    // draft ID that belongs to the previous organization.
    setActiveOrganizationId(organizationId)
    setEditorInvoiceId(null)
  }

  /**
   * Sign-out goes through the server as a top-level form POST, so the browser
   * follows the provider's end-session redirect. {@link beginEndSession}
   * leaves the page; the signed-out screen is shown when the provider returns.
   */
  function handleLogout() {
    if (logoutPending) return
    setLogoutNotice(null)
    if (sessionState.status === 'loading' || sessionState.status === 'error') {
      setLogoutNotice(m.session.sessionUnavailable)
      return
    }
    if (sessionState.status === 'anonymous') {
      setLogoutNotice(m.session.noActiveSession)
      return
    }
    setLogoutPending(true)
    beginEndSession(sessionState.session.logout)
  }

  useEffect(() => {
    function handleShortcut(event: KeyboardEvent) {
      if ((event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 'k') {
        if (searchOpen) return
        event.preventDefault()
        setSearchOpen(true)
      }
    }
    window.addEventListener('keydown', handleShortcut)
    return () => window.removeEventListener('keydown', handleShortcut)
  }, [searchOpen])

  if (signedOut) return <SignedOutScreen />

  if (sessionState.status === 'loading') {
    return <StatusGate title={m.session.cardTitle} message={m.api.loading} onRetry={() => setSessionAttempt((attempt) => attempt + 1)} />
  }

  if (sessionState.status === 'error') {
    return <StatusGate title={m.session.cardTitle} message={m.session.sessionUnavailable} onRetry={() => setSessionAttempt((attempt) => attempt + 1)} />
  }

  // No invented identity: an anonymous session shows the real sign-in entry point.
  if (sessionState.status === 'anonymous') return <SignedOutScreen />

  const displayName = sessionUser?.displayName ?? sessionUser?.email ?? null
  const profileName = displayName ?? m.account.unknownIdentity

  let pageContent: ReactNode
  switch (activePage) {
    case 'dashboard':
      pageContent = activeOrganization === null
        ? withWorkspace(() => null)
        : <Dashboard activeOrganization={activeOrganization} />
      break
    case 'onboarding':
      pageContent = (
        <Onboarding
          onInvitationCreated={(invitation) => setInvitations((current) => [invitation, ...current.filter((item) => item.id !== invitation.id)])}
          invitations={invitations.filter((invitation) => invitation.organizationId === activeOrganizationId)}
          organizations={membershipList}
          activeOrganizationId={activeOrganizationId}
        />
      )
      break
    case 'invoices':
      pageContent = withWorkspace((organizationId, role) => (
        <InvoicesScreen
          key={`invoices-${organizationId}`}
          organizationId={organizationId}
          role={role}
          onOpenEditor={(invoiceId) => { setEditorInvoiceId(invoiceId); selectPage('editor') }}
        />
      ))
      break
    case 'editor':
      pageContent = withWorkspace((organizationId, role) => (
        <InvoiceEditorScreen
          key={`editor-${organizationId}-${editorInvoiceId ?? 'new'}`}
          organizationId={organizationId}
          role={role}
          invoiceId={editorInvoiceId}
          onBack={() => selectPage('invoices')}
        />
      ))
      break
    case 'pricing':
      pageContent = <PricingScreen onStartFree={() => selectPage('onboarding')} />
      break
    case 'customers':
      pageContent = withWorkspace((organizationId, role) => (
        <CustomersScreen key={`customers-${organizationId}`} organizationId={organizationId} role={role} />
      ))
      break
    case 'team':
      pageContent = <TeamScreen />
      break
    case 'billing':
      pageContent = <BillingScreen />
      break
    case 'account':
      pageContent = (
        <AccountScreen
          displayName={displayName}
          email={sessionUser?.email ?? null}
          activeOrganization={activeOrganization}
          role={activeMembership?.role ?? null}
          onLogout={handleLogout}
          logoutPending={logoutPending}
        />
      )
      break
    case 'notifications':
      pageContent = (
        <NotificationsScreen
          notifications={notifications.notifications}
          unreadCount={notifications.unreadCount}
          isRead={notifications.isRead}
          markRead={notifications.markRead}
          markAllRead={notifications.markAllRead}
          onNavigate={selectPage}
        />
      )
      break
    case 'settings':
      pageContent = <SettingsScreen onLogout={handleLogout} logoutPending={logoutPending} />
      break
  }

  return (
    <div className="app-shell">
      <aside className={`sidebar ${mobileNavOpen ? 'sidebar-open' : ''}`}>
        <a className="brand" href="#dashboard" onClick={(event) => { event.preventDefault(); selectPage('dashboard') }}>
          <img src="/Keelson.svg" alt="" />
          <span>keelson</span>
        </a>
        <OrganizationSwitcher
          organizations={membershipList}
          activeOrganizationId={activeOrganizationId}
          onSelect={selectOrganization}
          onCreate={() => selectPage('onboarding')}
        />
        <div className="nav-label">{m.nav.workspace}</div>
        <nav className="primary-nav" aria-label={m.nav.mainNav}>
          {navItems.map((item) => (
            <button
              type="button"
              key={item.id}
              className={`nav-link ${activePage === item.id ? 'active' : ''}`}
              aria-current={activePage === item.id ? 'page' : undefined}
              onClick={() => selectPage(item.id)}
            >
              <Icon name={item.icon} />
              <span>{m.nav[item.id]}</span>
            </button>
          ))}
        </nav>
        <div className="sidebar-spacer" />
        <div className="sidebar-help">
          <div className="help-icon"><Icon name="help" size={18} /></div>
          <div><strong>{m.nav.helpTitle}</strong><span>{m.nav.helpSubtitle}</span></div>
          <Icon name="arrow" size={15} />
        </div>
        <div className="profile-row">
          <span className="profile-avatar">{initialsOf(profileName)}</span>
          <span className="profile-info">
            <strong>{profileName}</strong>
            <small>{activeMembership ? m.roles[activeMembership.role.toLowerCase() as keyof typeof m.roles] : m.account.noWorkspace}</small>
          </span>
          <ProfileMenu onNavigate={selectPage} onLogout={handleLogout} logoutPending={logoutPending} />
        </div>
      </aside>

      <div className="main-column">
        <header className="topbar">
          <button type="button" aria-label={m.nav.toggleNav} className="mobile-menu icon-button" onClick={() => setMobileNavOpen(!mobileNavOpen)}>
            <Icon name="menu" />
          </button>
          <div className="breadcrumbs">
            <span>{activeOrganization?.name ?? m.account.noWorkspace}</span>
            <Icon name="chevron" size={14} />
            <strong>{m.page[activePage]}</strong>
          </div>
          <div className="topbar-actions">
            <button
              className="search-trigger"
              type="button"
              aria-label={m.search.open}
              aria-haspopup="dialog"
              aria-expanded={searchOpen}
              aria-keyshortcuts="Control+K Meta+K"
              onClick={() => setSearchOpen(true)}
            >
              <Icon name="search" size={17} />
              <span>{m.nav.search}</span>
              <kbd>{searchShortcutLabel()}</kbd>
            </button>
            <button
              className="icon-button notification-button"
              aria-label={notifications.unreadCount > 0 ? `${m.nav.notifications} · ${m.notifications.unreadCount(notifications.unreadCount)}` : m.nav.notifications}
              type="button"
              onClick={() => selectPage('notifications')}
            >
              <Icon name="bell" />
              {notifications.unreadCount > 0 && <span className="notification-badge" aria-hidden="true">{notifications.unreadCount}</span>}
            </button>
            <span className="topbar-divider" />
            <ThemeControl />
          </div>
        </header>
        <main className="main-content">
          {logoutNotice && <div className="inline-feedback" role="alert">{logoutNotice}</div>}
          {organizations.state.status === 'error' && (
            <div className="inline-feedback" role="alert">
              <span>{presentError(m, organizations.state.error)}</span>
              <button type="button" className="button button-ghost" onClick={organizations.reload}>{m.api.reload}</button>
            </div>
          )}
          {pageContent}
        </main>
      </div>
      {searchOpen && (
        <SearchDialog
          results={searchResults}
          onClose={() => setSearchOpen(false)}
          onNavigate={selectPage}
        />
      )}
    </div>
  )
}

export default App
