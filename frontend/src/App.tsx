import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { useNavigate } from 'react-router'
import { listOrganizations } from './api/organizations'
import type { OrganizationMembership } from './api/types'
import { presentError } from './api/presentError'
import { useApiResource } from './api/useApiResource'
import { Icon } from './components/Icon'
import type { IconName } from './components/Icon'
import { ProfileMenu } from './components/ProfileMenu'
import { SearchDialog } from './components/SearchDialog'
import { StatusGate } from './components/StatusGate'
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
import { buildSearchResults, searchShortcutLabel } from './search/search'
import { useAuth } from './session/AuthContext'
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

function App() {
  const { m } = useI18n()
  const { user, signOut } = useAuth()
  const navigate = useNavigate()
  const notifications = useNotifications()
  const [activePage, setActivePage] = useState<PageId>('dashboard')
  const [mobileNavOpen, setMobileNavOpen] = useState(false)
  const [searchOpen, setSearchOpen] = useState(false)
  const [activeOrganizationId, setActiveOrganizationId] = useState<string | null>(null)
  const [invitations, setInvitations] = useState<InvitationRecord[]>(mockInvitations)
  const [editorInvoiceId, setEditorInvoiceId] = useState<string | null>(null)
  const [logoutPending, setLogoutPending] = useState(false)
  const [logoutError, setLogoutError] = useState<string | null>(null)

  // A new session mounts a new shell, so the memberships are read once per mount
  // and refreshed explicitly by the resource hook.
  const loadOrganizations = useCallback((signal: AbortSignal) => listOrganizations(signal), [])
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
   * Sign-out asks the API to end the session, then sends the tab to the sign-in
   * screen. A failed call keeps the shell visible and says why: navigating after
   * an unconfirmed sign-out would only bounce back here on the next session read.
   */
  async function handleLogout() {
    if (logoutPending) return
    setLogoutError(null)
    setLogoutPending(true)
    try {
      await signOut()
      navigate('/sign-in', { replace: true })
    } catch (error) {
      setLogoutError(presentError(m, error))
    } finally {
      setLogoutPending(false)
    }
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

  // The router mounts this shell only for a server-confirmed session, so `user`
  // is the identity to render. No invented fallback identity is used.
  const displayName = user?.displayName ?? user?.email ?? null
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
          email={user?.email ?? null}
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
          {logoutError !== null && <div className="inline-feedback inline-feedback-error" role="alert">{logoutError}</div>}
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
