import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { ApiError } from '../../api/errors'
import { presentAuthError } from '../../api/presentError'
import { resendVerification } from '../../api/auth'
import { useI18n } from '../../i18n/I18nContext'
import { useAuth } from '../../session/AuthContext'
import { AuthLayout, FormAlert } from './AuthLayout'
import { PasswordField } from './PasswordField'
import { useFieldFocus } from './useFieldFocus'

type ResendState = 'idle' | 'pending' | 'sent' | 'failed'

export function SignInScreen() {
  const { m } = useI18n()
  const { signIn } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [fields, setFields] = useState<Record<string, string> | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [unverified, setUnverified] = useState(false)
  const [pending, setPending] = useState(false)
  const [resend, setResend] = useState<ResendState>('idle')
  const { register, focusFirst } = useFieldFocus(['email', 'password'] as const)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (pending) return
    setPending(true)
    setFormError(null)
    setFields(null)
    setUnverified(false)
    setResend('idle')
    try {
      await signIn(email.trim(), password)
      navigate('/', { replace: true })
    } catch (error) {
      const presented = presentAuthError(m, error)
      setFormError(presented.message)
      setFields(presented.fields)
      setUnverified(error instanceof ApiError && error.code === 'email_not_verified')
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

  return (
    <AuthLayout title={m.auth.signInTitle} subtitle={m.auth.signInSubtitle}>
      <form className="auth-form" onSubmit={handleSubmit}>
        {formError !== null && <FormAlert tone="error">{formError}</FormAlert>}
        {unverified && (
          <div className="auth-inline-action">
            <button type="button" className="button button-secondary" onClick={() => { void handleResend() }} disabled={resend === 'pending'} aria-busy={resend === 'pending' || undefined}>
              {resend === 'pending' ? m.auth.resending : m.auth.resend}
            </button>
            {resend === 'sent' && <span role="status">{m.auth.resent}</span>}
            {resend === 'failed' && <span role="alert">{m.auth.resendFailed}</span>}
          </div>
        )}

        <div className="field">
          <label htmlFor="sign-in-email">{m.auth.fields.email}</label>
          <input
            id="sign-in-email"
            ref={register('email')}
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            autoComplete="email"
            inputMode="email"
            aria-invalid={fields?.email === undefined ? undefined : true}
            aria-describedby={fields?.email === undefined ? undefined : 'sign-in-email-error'}
            disabled={pending}
            required
          />
          {fields?.email !== undefined && <p className="field-error" id="sign-in-email-error">{fields.email}</p>}
        </div>

        <PasswordField
          label={m.auth.fields.password}
          value={password}
          onChange={setPassword}
          autoComplete="current-password"
          inputRef={register('password')}
          error={fields?.password}
          disabled={pending}
        />

        <button type="submit" className="button button-primary auth-submit" disabled={pending} aria-busy={pending || undefined}>
          {pending ? m.auth.signingIn : m.auth.signInSubmit}
        </button>
      </form>

      <p className="auth-links">
        <Link to="/forgot-password">{m.auth.forgotPasswordLink}</Link>
      </p>
      <p className="auth-links">
        {m.auth.noAccount} <Link to="/sign-up">{m.auth.createAccountLink}</Link>
      </p>
    </AuthLayout>
  )
}
