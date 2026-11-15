import type { OrganizationRole } from '../api/types'
import { SessionCard } from '../components/SessionCard'
import { useI18n } from '../i18n/I18nContext'
import { initialsOf } from '../utils/initials'

/**
 * The signed-in identity, as reported by `/api/session`, plus the caller's role
 * in the active organization as reported by `/api/me/organizations`. Nothing on
 * this screen is demo data, and nothing is editable because the backend exposes
 * no profile write endpoint yet.
 */
export function AccountScreen({
  displayName,
  email,
  activeOrganization,
  role,
  onLogout,
  logoutPending,
}: {
  displayName: string | null
  email: string | null
  activeOrganization: { id: string; name: string } | null
  role: OrganizationRole | null
  onLogout: () => void
  logoutPending: boolean
}) {
  const { m } = useI18n()
  const name = displayName ?? email ?? m.account.unknownIdentity
  const roleLabel = role === null ? m.account.noWorkspace : m.roles[role.toLowerCase() as keyof typeof m.roles]
  const organizationName = activeOrganization?.name ?? m.account.noWorkspace

  return (
    <div className="account-page">
      <section className="page-intro">
        <div>
          <p className="eyebrow">{m.account.eyebrow}</p>
          <h1>{m.account.title}</h1>
          <p>{m.account.subtitle}</p>
        </div>
        <span className="demo-notice">{m.account.source}</span>
      </section>

      <section className="panel account-card">
        <div className="account-identity">
          <span className="account-avatar" aria-hidden="true">{initialsOf(name)}</span>
          <div>
            <h2>{name}</h2>
            <p>{email ?? '—'}</p>
          </div>
        </div>

        <dl className="detail-grid">
          <div><dt>{m.account.name}</dt><dd>{displayName ?? '—'}</dd></div>
          <div><dt>{m.account.email}</dt><dd>{email ?? '—'}</dd></div>
          <div><dt>{m.account.organization}</dt><dd>{organizationName}</dd></div>
          <div><dt>{m.account.role}</dt><dd>{roleLabel}</dd></div>
        </dl>

        <p className="account-note">{m.account.demoBody}</p>
      </section>

      <SessionCard onLogout={onLogout} pending={logoutPending} className="account-card" />
    </div>
  )
}
