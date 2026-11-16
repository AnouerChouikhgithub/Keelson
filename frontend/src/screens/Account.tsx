import { Link, useNavigate } from 'react-router'
import { BackendGate } from '../components/BackendGate'
import { Icon } from '../components/Icon'
import { useToast } from '../components/ToastContext'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import { useAuth } from '../session/AuthContext'
import { initialsOf } from '../utils/initials'
import { useWorkspace } from '../app/WorkspaceContext'
import './simple.css'

/**
 * Identity from `/api/session` and the role from the URL's organization are
 * real. Profile writes and an in-session password change have no endpoint, so
 * both point at the flows that do work instead of pretending to save.
 */
export default function Account() {
  const { m } = useI18n()
  const { user, signOut } = useAuth()
  const { membership } = useWorkspace()
  const navigate = useNavigate()
  const toast = useToast()
  useDocumentTitle(m.page.account)

  const name = user?.displayName ?? user?.email ?? m.account.unknownIdentity
  const roleLabel = m.roles[membership.role.toLowerCase() as keyof typeof m.roles]

  async function handleSignOut() {
    try {
      await signOut()
      navigate('/sign-in', { replace: true })
    } catch {
      toast.error(m.api.unexpected)
    }
  }

  return (
    <div className="simple-page">
      <section className="page-intro">
        <div>
          <p className="eyebrow">{m.account.eyebrow}</p>
          <h1>{m.account.title}</h1>
          <p>{m.account.subtitle}</p>
        </div>
      </section>

      <section className="panel account-card" aria-labelledby="identity-title">
        <div className="account-identity">
          <span className="account-avatar" aria-hidden="true">{initialsOf(name)}</span>
          <div>
            <h2 id="identity-title">{name}</h2>
            <p>{user?.email ?? '—'}</p>
          </div>
        </div>

        <dl className="detail-grid">
          <div><dt>{m.account.name}</dt><dd>{user?.displayName ?? '—'}</dd></div>
          <div><dt>{m.account.email}</dt><dd>{user?.email ?? '—'}</dd></div>
          <div><dt>{m.account.organization}</dt><dd>{membership.name}</dd></div>
          <div><dt>{m.account.role}</dt><dd>{roleLabel}</dd></div>
        </dl>
      </section>

      <BackendGate title={m.account.profileGateTitle} body={m.account.profileGateBody} endpoint={m.account.profileGateEndpoint} />

      <section className="panel" aria-labelledby="password-title">
        <h2 id="password-title">{m.account.passwordTitle}</h2>
        <p className="panel-help">{m.account.passwordHelp}</p>
        <p className="panel-help">{m.account.passwordGateBody}</p>
        <Link className="button button-secondary" to="/forgot-password">
          {m.account.useResetFlow}
        </Link>
      </section>

      <section className="panel" aria-labelledby="sessions-title">
        <h2 id="sessions-title">{m.account.sessionsTitle}</h2>
        <p className="panel-help">{m.account.sessionsHelp}</p>
        <p className="panel-help">{m.account.sessionsNote}</p>
        <button type="button" className="button button-secondary" onClick={() => { void handleSignOut() }}>
          <Icon name="logout" size={16} /> {m.account.signOut}
        </button>
      </section>
    </div>
  )
}
