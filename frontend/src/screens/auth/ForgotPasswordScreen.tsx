import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router'
import { forgotPassword } from '../../api/auth'
import { presentAuthError } from '../../api/presentError'
import { useI18n } from '../../i18n/I18nContext'
import { AuthLayout, FormAlert } from './AuthLayout'
import { useFieldFocus } from './useFieldFocus'

/**
 * Requests a reset link.
 *
 * The server answers `202` whether or not the address has an account, so the
 * confirmation says "if that address has an account" and never reveals which
 * case it was. Only a malformed address (`422`) is corrected here.
 */
export function ForgotPasswordScreen() {
  const { m } = useI18n()
  const [email, setEmail] = useState('')
  const [fields, setFields] = useState<Record<string, string> | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)
  const [sent, setSent] = useState(false)
  const { register, focusFirst } = useFieldFocus(['email'] as const)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (pending) return
    setPending(true)
    setFormError(null)
    setFields(null)
    try {
      await forgotPassword(email.trim())
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

  if (sent) {
    return (
      <AuthLayout title={m.auth.forgotSentTitle} subtitle={m.auth.forgotSentBody}>
        <FormAlert tone="success">{m.auth.forgotSentBody}</FormAlert>
        <Link className="button button-primary auth-submit" to="/sign-in">{m.auth.backToSignIn}</Link>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title={m.auth.forgotTitle} subtitle={m.auth.forgotSubtitle}>
      <form className="auth-form" onSubmit={handleSubmit}>
        {formError !== null && <FormAlert tone="error">{formError}</FormAlert>}
        <div className="field">
          <label htmlFor="forgot-email">{m.auth.fields.email}</label>
          <input
            id="forgot-email"
            ref={register('email')}
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            autoComplete="email"
            inputMode="email"
            aria-invalid={fields?.email === undefined ? undefined : true}
            aria-describedby={fields?.email === undefined ? undefined : 'forgot-email-error'}
            disabled={pending}
            required
          />
          {fields?.email !== undefined && <p className="field-error" id="forgot-email-error">{fields.email}</p>}
        </div>
        <button type="submit" className="button button-primary auth-submit" disabled={pending} aria-busy={pending || undefined}>
          {pending ? m.auth.sending : m.auth.forgotSubmit}
        </button>
      </form>
      <p className="auth-links">
        <Link to="/sign-in">{m.auth.backToSignIn}</Link>
      </p>
    </AuthLayout>
  )
}
