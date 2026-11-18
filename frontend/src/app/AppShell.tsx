import { Suspense, lazy, useEffect, useMemo, useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useMatch, useNavigate } from 'react-router'
import { presentError } from '../api/presentError'
import type { OrganizationMembership } from '../api/types'
import { ErrorBoundary } from '../components/ErrorBoundary'
import { Icon } from '../components/Icon'
import type { IconName } from '../components/Icon'
import { ProfileMenu } from '../components/ProfileMenu'
import { SearchDialog } from '../components/SearchDialog'
import { Skeleton } from '../components/Skeleton'
import { ThemeControl } from '../components/ThemeControl'
import { useToast } from '../components/ToastContext'
import { useI18n } from '../i18n/I18nContext'
import { useUnreadNotifications } from '../hooks/useUnreadNotifications'
import { buildSearchResults, searchShortcutLabel } from '../search/search'
import { useAuth } from '../session/AuthContext'
import { initialsOf } from '../utils/initials'
import { pageLabel } from './CurrentPage'
import { useOrganizations } from './useOrganizations'
import './AppShell.css'

// Its own chunk: the shell must not pay for the AI feature in the entry file.
const AssistantLauncher = lazy(() => import('../features/ai/AssistantLauncher'))

const navItems: { segment: string; icon: IconName; labelKey: 'dashboard' | 'invoices' | 'customers' | 'team' | 'audit' | 'settings' }[] = [
  { segment: 'dashboard', icon: 'grid', labelKey: 'dashboard' },
  { segment: 'invoices', icon: 'file', labelKey: 'invoices' },
  { segment: 'customers', icon: 'users', labelKey: 'customers' },
  { segment: 'team', icon: 'shield', labelKey: 'team' },
  { segment: 'audit', icon: 'clock', labelKey: 'audit' },
  { segment: 'settings', icon: 'settings', labelKey: 'settings' },
]

function OrganizationSwitcher({
  organizations,
  activeOrganizationId,
}: {
  organizations: OrganizationMembership[]
  activeOrganizationId: string | null
}) {
  const { m } = useI18n()
  const [isOpen, setIsOpen] = useState(false)
  const active = organizations.find((item) => item.id === activeOrganizationId) ?? null

  if (!active) {
    return (
      <Link className="organization-switcher" to="/onboarding">
        <span className="org-avatar" aria-hidden="true">—</span>
        <span className="org-text"><strong>{m.account.noWorkspace}</strong><small>{m.nav.createOrg}</small></span>
        <Icon name="plus" size={16} />
      </Link>
    )
  }

  return (
    <div className="organization-menu-wrap">
      <button
        className="organization-switcher"
        type="button"
        aria-label={m.nav.currentOrg(active.name)}
        aria-expanded={isOpen}
        aria-haspopup="menu"
        onClick={() => setIsOpen((current) => !current)}
      >
        <span className="org-avatar" aria-hidden="true">{initialsOf(active.name)}</span>
        <span className="org-text">
          <strong>{active.name}</strong>
          <small>{m.roles[active.role.toLowerCase() as keyof typeof m.roles]} · {active.subdomain}</small>
        </span>
        <Icon name="chevron" size={16} />
      </button>
      {isOpen && (
        <div className="organization-menu" role="menu" aria-label={m.nav.switchOrg}>
          <p className="menu-eyebrow">{m.nav.yourWorkspaces}</p>
          {organizations.map((item) => (
            <Link
              key={item.id}
              role="menuitemradio"
              aria-checked={item.id === active.id}
              className={`organization-option ${item.id === active.id ? 'selected' : ''}`}
              to={`/o/${item.id}/dashboard`}
              onClick={() => setIsOpen(false)}
            >
              <span className="org-avatar" aria-hidden="true">{initialsOf(item.name)}</span>
              <span>
                <strong>{item.name}</strong>
                <small>{item.subdomain} · {m.roles[item.role.toLowerCase() as keyof typeof m.roles]}</small>
              </span>
              {item.id === active.id && <Icon name="check" size={16} />}
            </Link>
          ))}
          <div className="menu-separator" role="presentation" />
          <Link className="create-workspace-link" to="/onboarding" onClick={() => setIsOpen(false)}>
            <Icon name="plus" size={17} /> {m.nav.createOrg}
          </Link>
          <p className="menu-footnote">{m.nav.menuFootnote}</p>
        </div>
      )}
    </div>
  )
}

/**
 * The signed-in frame: one navigation landmark, one header, one main landmark
 * and a skip link that reaches it before the 6-item navigation.
 */
export function AppShell() {
  const { m } = useI18n()
  const { user, signOut } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()
  const toast = useToast()
  const organizations = useOrganizations()
  // Navigation closes the drawer explicitly instead of reacting to the path in
  // an effect: the click that navigates is the event that closes it.
  const [mobileNavOpen, setMobileNavOpen] = useState(false)
  const [searchOpen, setSearchOpen] = useState(false)
  const [logoutPending, setLogoutPending] = useState(false)

  const workspaceMatch = useMatch('/o/:orgId/*')
  const memberships = useMemo(
    () => (organizations.state.status === 'ready' ? organizations.state.data : []),
    [organizations.state],
  )
  const orgId = workspaceMatch?.params.orgId ?? null
  const membership = memberships.find((item) => item.id === orgId) ?? null
  const page = pageLabel(location.pathname, m)
  const profileName = user?.displayName ?? user?.email ?? m.account.unknownIdentity

  const searchResults = useMemo(
    () => buildSearchResults({ m, organizations: memberships, organizationId: orgId }),
    [m, memberships, orgId],
  )
  const unreadNotifications = useUnreadNotifications(orgId)

  useEffect(() => {
    function handleShortcut(event: KeyboardEvent) {
      if ((event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        setSearchOpen(true)
      }
    }
    window.addEventListener('keydown', handleShortcut)
    return () => window.removeEventListener('keydown', handleShortcut)
  }, [])

  async function handleLogout() {
    if (logoutPending) return
    setLogoutPending(true)
    try {
      await signOut()
      navigate('/sign-in', { replace: true })
    } catch (error) {
      // The server decides; a failed sign-out must not look like a success.
      toast.error(presentError(m, error))
    } finally {
      setLogoutPending(false)
    }
  }

  return (
    <>
      <a className="skip-link" href="#main-content">{m.common.skipToContent}</a>
      <div className="app-shell">
        <aside id="workspace-sidebar" className={`sidebar ${mobileNavOpen ? 'sidebar-open' : ''}`}>
          <Link className="brand" to={orgId === null ? '/' : `/o/${orgId}/dashboard`} onClick={() => setMobileNavOpen(false)}>
            <img src="/Keelson.svg" alt="" width={30} height={30} />
            <span>keelson</span>
          </Link>

          <OrganizationSwitcher organizations={memberships} activeOrganizationId={orgId} />

          {orgId !== null && (
            <>
              <p className="nav-label">{m.nav.workspace}</p>
              <nav className="primary-nav" aria-label={m.nav.mainNav}>
                {navItems.map((item) => (
                  <NavLink
                    key={item.segment}
                    to={`/o/${orgId}/${item.segment}`}
                    className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}
                    onClick={() => setMobileNavOpen(false)}
                  >
                    <Icon name={item.icon} />
                    <span>{m.nav[item.labelKey]}</span>
                  </NavLink>
                ))}
              </nav>
            </>
          )}

          <div className="sidebar-spacer" />

          <div className="profile-row">
            <span className="profile-avatar" aria-hidden="true">{initialsOf(profileName)}</span>
            <span className="profile-info">
              <strong>{profileName}</strong>
              <small>{membership ? m.roles[membership.role.toLowerCase() as keyof typeof m.roles] : m.account.noWorkspace}</small>
            </span>
            <ProfileMenu
              orgId={orgId ?? membership?.id ?? ''}
              onLogout={handleLogout}
              logoutPending={logoutPending}
              onNavigate={() => setMobileNavOpen(false)}
            />
          </div>
        </aside>

        <div className="main-column">
          <header className="topbar">
            <button
              type="button"
              aria-label={mobileNavOpen ? m.nav.closeNav : m.nav.toggleNav}
              aria-expanded={mobileNavOpen}
              aria-controls="workspace-sidebar"
              className="mobile-menu icon-button"
              onClick={() => setMobileNavOpen((current) => !current)}
            >
              <Icon name={mobileNavOpen ? 'close' : 'menu'} />
            </button>
            <nav className="breadcrumbs" aria-label={m.nav.breadcrumb}>
              {membership && <span>{membership.name}</span>}
              {membership && <Icon name="chevron" size={14} />}
              <strong aria-current="page">{page}</strong>
            </nav>
            <div className="topbar-actions">
              {orgId !== null && (
                <Suspense fallback={null}>
                  <AssistantLauncher organizationId={orgId} />
                </Suspense>
              )}
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
                <span>{m.common.search}</span>
                <kbd>{searchShortcutLabel()}</kbd>
              </button>
              {orgId !== null && (
                <Link
                  className="icon-button notification-button"
                  aria-label={unreadNotifications > 0 ? m.nav.notificationsWithCount(unreadNotifications) : m.nav.notifications}
                  to={`/o/${orgId}/notifications`}
                >
                  <Icon name="bell" />
                  {unreadNotifications > 0 && (
                    <span className="notification-badge" aria-hidden="true">
                      {unreadNotifications > 99 ? '99+' : unreadNotifications}
                    </span>
                  )}
                </Link>
              )}
              <span className="topbar-divider" role="presentation" />
              <ThemeControl />
            </div>
          </header>

          <main id="main-content" className="main-content" tabIndex={-1}>
            <ErrorBoundary key={location.pathname}>
              <Suspense fallback={<Skeleton variant="table" count={5} label={m.common.loading} />}>
                <Outlet />
              </Suspense>
            </ErrorBoundary>
          </main>
        </div>
      </div>

      {mobileNavOpen && (
        <button
          type="button"
          className="sidebar-scrim"
          aria-label={m.nav.closeNav}
          onClick={() => setMobileNavOpen(false)}
        />
      )}

      {searchOpen && (
        <SearchDialog
          results={searchResults}
          onClose={() => setSearchOpen(false)}
          onNavigate={(to) => { void navigate(to) }}
        />
      )}
    </>
  )
}
