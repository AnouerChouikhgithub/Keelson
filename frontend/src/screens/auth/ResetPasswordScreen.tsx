import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router'
import { resetPassword } from '../../api/auth'
import { presentAuthError } from '../../api/presentError'
import { useI18n } from '../../i18n/I18nContext'
import { AuthLayout, FormAlert } from './AuthLayout'
import { PasswordField } from './PasswordField'
import { useFieldFocus } from './useFieldFocus'

/**
 * Sets a new password from the reset link.
 *
 * The confirmation field is checked here before the request because the server
 * only receives one password: a mismatch is a local mistake, not a rejected
 * value, and it must not be sent. A successful reset also signs every other
 * session out (the stored hash changed), which the server handles alone.
 */
export function ResetPasswordScreen() {
  const { m } = useI18n()
  const [params] = useSearchParams()
  const token = params.get('token')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [fields, setFields] = useState<Record<string, string> | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)
  const [done, setDone] = useState(false)
  const { register, focusFirst } = useFieldFocus(['password', 'confirmPassword'] as const)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (pending) return
    if (token === null || token === '') return

    if (password !== confirmation) {
      const mismatch = { confirmPassword: m.auth.errors.passwordMismatch }
      setFields(mismatch)
      setFormError(null)
      focusFirst(mismatch)
      return
    }

    setPending(true)
    setFormError(null)
    setFields(null)
    try {
      await resetPassword(token, password)
      setDone(true)
    } catch (error) {
      const presented = presentAuthError(m, error)
      setFormError(presented.message)
      setFields(presented.fields)
      focusFirst(presented.fields)
    } finally {
      setPending(false)
    }
  }

  if (token === null || token === '') {
    return (
      <AuthLayout title={m.auth.resetTitle} subtitle={m.auth.resetMissingToken}>
        <Link className="button button-primary auth-submit" to="/forgot-password">{m.auth.forgotSubmit}</Link>
        <p className="auth-links">
          <Link to="/sign-in">{m.auth.backToSignIn}</Link>
        </p>
      </AuthLayout>
    )
  }

  if (done) {
    return (
      <AuthLayout title={m.auth.resetDoneTitle} subtitle={m.auth.resetDoneBody}>
        <FormAlert tone="success">{m.auth.resetDoneBody}</FormAlert>
        <Link className="button button-primary auth-submit" to="/sign-in">{m.auth.backToSignIn}</Link>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title={m.auth.resetTitle} subtitle={m.auth.resetSubtitle}>
      <form className="auth-form" onSubmit={handleSubmit}>
        {formError !== null && <FormAlert tone="error">{formError}</FormAlert>}

        <PasswordField
          label={m.auth.fields.password}
          value={password}
          onChange={setPassword}
          autoComplete="new-password"
          inputRef={register('password')}
          error={fields?.password}
          hint={m.auth.passwordRule}
          disabled={pending}
        />

        <PasswordField
          label={m.auth.fields.confirmPassword}
          value={confirmation}
          onChange={setConfirmation}
          autoComplete="new-password"
          inputRef={register('confirmPassword')}
          error={fields?.confirmPassword}
          disabled={pending}
        />

        <button type="submit" className="button button-primary auth-submit" disabled={pending} aria-busy={pending || undefined}>
          {pending ? m.auth.saving : m.auth.resetSubmit}
        </button>
      </form>

      <p className="auth-links">
        <Link to="/sign-in">{m.auth.backToSignIn}</Link>
      </p>
    </AuthLayout>
  )
}
