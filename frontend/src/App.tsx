import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { Icon } from './components/Icon'
import type { IconName } from './components/Icon'
import { ProfileMenu } from './components/ProfileMenu'
import { SearchDialog } from './components/SearchDialog'
import { ThemeControl } from './components/ThemeControl'
import { useI18n } from './i18n/I18nContext'
import { mockInvitations, mockOrganizations } from './mocks/organizations'
import type { InvitationRecord, OrganizationSummary } from './mocks/organizations'
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
import { Placeholder } from './screens/Placeholder'
import { SettingsScreen } from './screens/SettingsScreen'
import { SignedOutScreen } from './screens/SignedOutScreen'
import { buildSearchResults, searchShortcutLabel } from './search/search'
import { beginEndSession, clearSessionEnded, readSession, readSessionEnded } from './session/session'
import type { ServerSession } from './session/session'
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
  organizations: OrganizationSummary[]
  activeOrganizationId: string
  onSelect: (organizationId: string) => void
  onCreate: () => void
}) {
  const { m } = useI18n()
  const [isOpen, setIsOpen] = useState(false)
  const activeOrganization = organizations.find((item) => item.id === activeOrganizationId) ?? organizations[0]

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
        <span className="org-avatar">{activeOrganization.initials}</span>
        <span className="org-text"><strong>{activeOrganization.name}</strong><small>{activeOrganization.location}</small></span>
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
              <span className="org-avatar">{item.initials}</span>
              <span><strong>{item.name}</strong><small>{item.location} · {item.subdomain}.keelson.app</small></span>
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
  const notifications = useNotifications()
  const [activePage, setActivePage] = useState<PageId>('dashboard')
  const [mobileNavOpen, setMobileNavOpen] = useState(false)
  const [organizations, setOrganizations] = useState<OrganizationSummary[]>(mockOrganizations)
  const [activeOrganizationId, setActiveOrganizationId] = useState(mockOrganizations[0].id)
  const [invitations, setInvitations] = useState<InvitationRecord[]>(mockInvitations)
  const [searchOpen, setSearchOpen] = useState(false)
  // Server-owned authentication state. `null` means it could not be read, which
  // is never treated as signed in or signed out.
  const [session, setSession] = useState<ServerSession | null>(null)
  // Survives the full-page sign-out navigation, so the returning tab shows the
  // signed-out screen instead of briefly re-rendering the shell.
  const [signedOut, setSignedOut] = useState(readSessionEnded)
  const [logoutPending, setLogoutPending] = useState(false)
  const [logoutNotice, setLogoutNotice] = useState<string | null>(null)
  const activeOrganization = organizations.find((item) => item.id === activeOrganizationId) ?? mockOrganizations[0]
  const searchResults = useMemo(
    () => buildSearchResults({ m, organizations, notifications: notifications.notifications }),
    [m, organizations, notifications.notifications],
  )

  useEffect(() => {
    let cancelled = false
    void readSession().then((state) => {
      if (cancelled) return
      setSession(state)
      // An authenticated session always overrides the tab's sign-out marker.
      if (state?.status === 'authenticated') {
        clearSessionEnded()
        setSignedOut(false)
      }
    })
    return () => { cancelled = true }
  }, [])

  /**
   * Sign-out goes through the server as a top-level form POST, so the browser
   * follows the provider's end-session redirect. {@link beginEndSession}
   * leaves the page; the signed-out screen is shown when the provider returns.
   */
  function handleLogout() {
    if (logoutPending) return
    setLogoutNotice(null)
    if (session === null) {
      // Unknown state is not the same as signed out: say so instead of
      // pretending there was a session to end.
      setLogoutNotice(m.session.sessionUnavailable)
      return
    }
    if (session.status === 'anonymous') {
      setLogoutNotice(m.session.noActiveSession)
      return
    }
    setLogoutPending(true)
    beginEndSession(session.logout)
  }

  // Handed to the menu and every sign-out card.
  const requestLogout = handleLogout

  useEffect(() => {
    function handleShortcut(event: KeyboardEvent) {
      if ((event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 'k') {
        // Only the app search owns the shortcut; never intercept it while it is already open.
        if (searchOpen) return
        event.preventDefault()
        setSearchOpen(true)
      }
    }
    window.addEventListener('keydown', handleShortcut)
    return () => window.removeEventListener('keydown', handleShortcut)
  }, [searchOpen])

  function selectPage(page: PageId) {
    setActivePage(page)
    setMobileNavOpen(false)
  }

  // Reached only after the server confirmed the session ended.
  if (signedOut) return <SignedOutScreen />

  let pageContent: ReactNode
  switch (activePage) {
    case 'dashboard':
      pageContent = <Dashboard activeOrganization={activeOrganization} />
      break
    case 'onboarding':
      pageContent = (
        <Onboarding
          onOrganizationCreated={(newOrganization) => { setOrganizations((current) => [...current, newOrganization]); setActiveOrganizationId(newOrganization.id) }}
          onInvitationCreated={(invitation) => setInvitations((current) => [invitation, ...current.filter((item) => item.id !== invitation.id)])}
          invitations={invitations.filter((invitation) => invitation.organizationId === activeOrganizationId)}
          organizations={organizations}
          activeOrganizationId={activeOrganizationId}
        />
      )
      break
    case 'invoices':
      pageContent = <InvoicesScreen onOpenEditor={() => selectPage('editor')} />
      break
    case 'editor':
      pageContent = <InvoiceEditorScreen onBack={() => selectPage('invoices')} />
      break
    case 'pricing':
      pageContent = <PricingScreen onStartFree={() => selectPage('onboarding')} />
      break
    case 'customers':
      pageContent = <CustomersScreen />
      break
    case 'team':
      pageContent = <TeamScreen />
      break
    case 'billing':
      pageContent = <BillingScreen />
      break
    case 'account':
      pageContent = <AccountScreen activeOrganization={activeOrganization} onLogout={requestLogout} logoutPending={logoutPending} />
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
      pageContent = <SettingsScreen onLogout={requestLogout} logoutPending={logoutPending} />
      break
    default:
      pageContent = <Placeholder page={activePage} />
  }

  return (
    <div className="app-shell">
      <aside className={`sidebar ${mobileNavOpen ? 'sidebar-open' : ''}`}>
        <a className="brand" href="#dashboard" onClick={(event) => { event.preventDefault(); selectPage('dashboard') }}>
          <img src="/Keelson.svg" alt="" />
          <span>keelson</span>
        </a>
        <OrganizationSwitcher organizations={organizations} activeOrganizationId={activeOrganizationId} onSelect={setActiveOrganizationId} onCreate={() => selectPage('onboarding')} />
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
              {item.id === 'invoices' && <span className="nav-count">8</span>}
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
          <span className="profile-avatar">AB</span>
          <span className="profile-info"><strong>Amine Ben Salah</strong><small>{m.roles.owner}</small></span>
          <ProfileMenu onNavigate={selectPage} onLogout={requestLogout} logoutPending={logoutPending} />
        </div>
      </aside>

      <div className="main-column">
        <header className="topbar">
          <button type="button" aria-label={m.nav.toggleNav} className="mobile-menu icon-button" onClick={() => setMobileNavOpen(!mobileNavOpen)}>
            <Icon name="menu" />
          </button>
          <div className="breadcrumbs">
            <span>{activeOrganization.name}</span>
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
