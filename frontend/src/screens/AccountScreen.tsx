import { Icon } from '../components/Icon'
import { SessionCard } from '../components/SessionCard'
import { useI18n } from '../i18n/I18nContext'
import type { OrganizationSummary } from '../mocks/organizations'

const owner = {
  name: 'Amine Ben Salah',
  email: 'amine@atelier-noura.tn',
  initials: 'AB',
}

export function AccountScreen({
  activeOrganization,
  onLogout,
  logoutPending,
}: {
  activeOrganization: OrganizationSummary
  onLogout: () => void
  logoutPending: boolean
}) {
  const { m } = useI18n()

  return (
    <div className="account-page">
      <section className="page-intro">
        <div>
          <p className="eyebrow">{m.account.eyebrow}</p>
          <h1>{m.account.title}</h1>
          <p>{m.account.subtitle}</p>
        </div>
        <span className="demo-notice"><Icon name="shield" size={15} /> {m.account.demoNotice}</span>
      </section>

      <section className="panel account-card">
        <div className="account-identity">
          <span className="account-avatar" aria-hidden="true">{owner.initials}</span>
          <div>
            <h2>{owner.name}</h2>
            <p>{owner.email}</p>
          </div>
        </div>

        <dl className="detail-grid">
          <div><dt>{m.account.name}</dt><dd>{owner.name}</dd></div>
          <div><dt>{m.account.email}</dt><dd>{owner.email}</dd></div>
          <div><dt>{m.account.organization}</dt><dd>{activeOrganization.name}</dd></div>
          <div><dt>{m.account.role}</dt><dd>{m.roles.owner}</dd></div>
        </dl>

        <p className="account-note">{m.account.demoBody}</p>
      </section>

      <SessionCard onLogout={onLogout} pending={logoutPending} className="account-card" />
    </div>
  )
}
