import { Icon } from './Icon'
import { useI18n } from '../i18n/I18nContext'

/**
 * The sign-out surface shared by My Account and Settings.
 *
 * Presentation only: the caller performs the server sign-out and owns the
 * outcome, so this component never reports a result of its own.
 */
export function SessionCard({
  onLogout,
  pending,
  className = 'settings-card',
}: {
  onLogout: () => void
  pending: boolean
  className?: string
}) {
  const { m } = useI18n()

  return (
    <section className={`panel ${className}`}>
      <h2>{m.session.cardTitle}</h2>
      <p className="card-help">{m.session.logoutHelp}</p>
      <button
        type="button"
        className="button button-secondary"
        onClick={onLogout}
        disabled={pending}
        aria-busy={pending || undefined}
      >
        <Icon name="logout" size={16} /> {pending ? m.session.signingOut : m.profile.logout}
      </button>
    </section>
  )
}
