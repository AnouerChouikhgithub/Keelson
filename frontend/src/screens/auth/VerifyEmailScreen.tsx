import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { verifyEmail } from '../../api/auth'
import { useI18n } from '../../i18n/I18nContext'
import { AuthLayout, FormAlert } from './AuthLayout'

type VerifyState = 'missing' | 'verifying' | 'verified' | 'failed'

/**
 * Redeems the link from the verification mail.
 *
 * The token is single-use, so the request must happen exactly once per token: a
 * ref (not state) guards it, which also survives React's StrictMode
 * mount/unmount/mount in development. The result is applied without a per-run
 * "cancelled" flag on purpose: the second StrictMode run returns early, so a flag
 * set by the first run's cleanup would discard the only result we will ever get.
 * A missing token, an expired one and one already used are three different
 * situations for the user, so they get three different sentences.
 */
export function VerifyEmailScreen() {
  const { m } = useI18n()
  const [params] = useSearchParams()
  const token = params.get('token')
  const started = useRef(false)
  const [state, setState] = useState<VerifyState>(token === null || token === '' ? 'missing' : 'verifying')

  useEffect(() => {
    if (token === null || token === '' || started.current) return
    started.current = true
    void verifyEmail(token)
      .then(() => setState('verified'))
      .catch(() => setState('failed'))
  }, [token])

  if (state === 'verifying') {
    return (
      <AuthLayout title={m.auth.verifyTitle} subtitle={m.auth.verifying}>
        <p className="auth-progress" role="status">{m.api.loading}</p>
      </AuthLayout>
    )
  }

  if (state === 'verified') {
    return (
      <AuthLayout title={m.auth.verifiedTitle} subtitle={m.auth.verifiedBody}>
        <FormAlert tone="success">{m.auth.verifiedBody}</FormAlert>
        <Link className="button button-primary auth-submit" to="/sign-in">{m.auth.backToSignIn}</Link>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout
      title={state === 'missing' ? m.auth.verifyTitle : m.auth.verifyFailedTitle}
      subtitle={state === 'missing' ? m.auth.verifyMissingToken : m.auth.verifyFailedBody}
    >
      {state === 'failed' && <FormAlert tone="error">{m.auth.verifyFailedBody}</FormAlert>}
      <Link className="button button-primary auth-submit" to="/sign-in">{m.auth.backToSignIn}</Link>
    </AuthLayout>
  )
}
