import { Link, useSearchParams } from 'react-router'
import { AuthLayout } from './auth/AuthLayout'
import { useI18n } from '../i18n/I18nContext'

/**
 * Invitation links arrive as `/accept-invitation?token=…`. Previews and
 * acceptance need endpoints this build does not have, so the token is shown as
 * received and the page says plainly that nothing was changed.
 */
export default function AcceptInvitation() {
  const { m } = useI18n()
  const [params] = useSearchParams()
  const token = params.get('token')

  return (
    <AuthLayout title={m.acceptInvitation.title} subtitle={m.acceptInvitation.subtitle}>
      <p className="eyebrow">{m.acceptInvitation.eyebrow}</p>
      {token === null ? (
        <p className="auth-alert auth-alert-error">{m.acceptInvitation.tokenMissing}</p>
      ) : (
        <>
          <p className="auth-subtitle">{m.acceptInvitation.gateBody}</p>
          <p className="token-box">
            <span>{m.acceptInvitation.tokenLabel}</span>
            <code>{token.slice(0, 8)}…</code>
          </p>
          <p className="field-hint">{m.acceptInvitation.gateEndpoint}</p>
        </>
      )}
      <div className="auth-actions">
        <Link className="button button-primary" to="/sign-in">{m.acceptInvitation.signIn}</Link>
        <Link className="button button-ghost" to="/">{m.acceptInvitation.backHome}</Link>
      </div>
    </AuthLayout>
  )
}
