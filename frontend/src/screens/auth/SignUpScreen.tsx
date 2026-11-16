import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router'
import { register, resendVerification } from '../../api/auth'
import { presentAuthError } from '../../api/presentError'
import { useI18n } from '../../i18n/I18nContext'
import { AuthLayout, FormAlert } from './AuthLayout'
import { PasswordField } from './PasswordField'
import { useFieldFocus } from './useFieldFocus'

type ResendState = 'idle' | 'pending' | 'sent' | 'failed'

/**
 * Creates the account and its first organization.
 *
 * The server answers `201 verification_sent` even for an address that already
 * exists, so this screen must never say "that email is taken": it confirms that a
 * link was sent and offers to send another. The confirmation state replaces the
 * form, because submitting twice would only repeat the same answer.
 */
export function SignUpScreen() {
  const { m } = useI18n()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [displayName, setDisplayName] = useState('')
  const [organizationName, setOrganizationName] = useState('')
  const [fields, setFields] = useState<Record<string, string> | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)
  const [sent, setSent] = useState(false)
  const [resend, setResend] = useState<ResendState>('idle')
  const { register: registerField, focusFirst } = useFieldFocus(['email', 'password', 'displayName', 'organizationName'] as const)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (pending) return
    setPending(true)
    setFormError(null)
    setFields(null)
    try {
      await register({ email: email.trim(), password, displayName: displayName.trim(), organizationName: organizationName.trim() })
      setSent(true)
    } catch (error) {
      const presented = presentAuthError(m, error)
      setFormError(presented.message)
      setFields(presented.fields)
      focusFirst(presented.fields)
    } finally {
      setPending(false)
    }
  }

  async function handleResend() {
    if (resend === 'pending') return
    setResend('pending')
    try {
      await resendVerification(email.trim())
      setResend('sent')
    } catch {
      setResend('failed')
    }
  }

  if (sent) {
    return (
      <AuthLayout title={m.auth.checkEmailTitle} subtitle={m.auth.checkEmailBody(email.trim())}>
        <div className="auth-actions">
          <button type="button" className="button button-secondary" onClick={() => { void handleResend() }} disabled={resend === 'pending'} aria-busy={resend === 'pending' || undefined}>
            {resend === 'pending' ? m.auth.resending : m.auth.resend}
          </button>
          {resend === 'sent' && <p role="status" className="field-hint">{m.auth.resent}</p>}
          {resend === 'failed' && <p role="alert" className="field-error">{m.auth.resendFailed}</p>}
        </div>
        <p className="auth-links">
          <Link to="/sign-in">{m.auth.backToSignIn}</Link>
        </p>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title={m.auth.signUpTitle} subtitle={m.auth.signUpSubtitle}>
      <form className="auth-form" onSubmit={handleSubmit}>
        {formError !== null && <FormAlert tone="error">{formError}</FormAlert>}

        <div className="field">
          <label htmlFor="sign-up-email">{m.auth.fields.email}</label>
          <input
            id="sign-up-email"
            ref={registerField('email')}
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            autoComplete="email"
            inputMode="email"
            aria-invalid={fields?.email === undefined ? undefined : true}
            aria-describedby={fields?.email === undefined ? undefined : 'sign-up-email-error'}
            required
          />
          {fields?.email !== undefined && <p className="field-error" id="sign-up-email-error">{fields.email}</p>}
        </div>

        <PasswordField
          label={m.auth.fields.password}
          value={password}
          onChange={setPassword}
          autoComplete="new-password"
          inputRef={registerField('password')}
          error={fields?.password}
          hint={m.auth.passwordRule}
        />

        <div className="field">
          <label htmlFor="sign-up-name">{m.auth.fields.displayName}</label>
          <input
            id="sign-up-name"
            ref={registerField('displayName')}
            type="text"
            value={displayName}
            onChange={(event) => setDisplayName(event.target.value)}
            autoComplete="name"
            aria-invalid={fields?.displayName === undefined ? undefined : true}
            aria-describedby={fields?.displayName === undefined ? undefined : 'sign-up-name-error'}
            required
          />
          {fields?.displayName !== undefined && <p className="field-error" id="sign-up-name-error">{fields.displayName}</p>}
        </div>

        <div className="field">
          <label htmlFor="sign-up-organization">{m.auth.fields.organizationName}</label>
          <input
            id="sign-up-organization"
            ref={registerField('organizationName')}
            type="text"
            value={organizationName}
            onChange={(event) => setOrganizationName(event.target.value)}
            autoComplete="organization"
            aria-invalid={fields?.organizationName === undefined ? undefined : true}
            aria-describedby={fields?.organizationName === undefined ? undefined : 'sign-up-organization-error'}
            required
          />
          {fields?.organizationName !== undefined && <p className="field-error" id="sign-up-organization-error">{fields.organizationName}</p>}
        </div>

        <button type="submit" className="button button-primary auth-submit" disabled={pending} aria-busy={pending || undefined}>
          {pending ? m.auth.creatingAccount : m.auth.signUpSubmit}
        </button>
      </form>

      <p className="auth-links">
        {m.auth.haveAccount} <Link to="/sign-in">{m.auth.signInLink}</Link>
      </p>
    </AuthLayout>
  )
}
