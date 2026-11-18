import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router'
import { verifyEmail } from '../../api/auth'
import { ApiError } from '../../api/errors'
import { presentAuthError } from '../../api/presentError'
import { useI18n } from '../../i18n/I18nContext'
import { AuthLayout, FormAlert } from './AuthLayout'
import { PasswordField } from './PasswordField'
import { useFieldFocus } from './useFieldFocus'

/**
 * Redeems the link from the verification mail and sets the account password.
 *
 * The password belongs here rather than at registration: the link proves the
 * person reading it owns the mailbox, so this is the first moment the account's
 * password can be chosen by its owner. Whoever typed the address at sign-up may
 * not be that person, and their password stops working the moment this form is
 * submitted — that is the pre-registration hijack this screen closes.
 *
 * The token is redeemed by the submit, not by opening the page, so a mail scanner
 * that follows the link cannot burn a single-use token before the owner arrives.
 * A used or expired link is terminal (retrying cannot help), while a rejected
 * password keeps the form open with the field highlighted.
 */
export function VerifyEmailScreen() {
  const { m } = useI18n()
  const [params] = useSearchParams()
  const token = params.get('token')
  const missing = token === null || token === ''
  const [password, setPassword] = useState('')
  const [formError, setFormError] = useState<string | null>(null)
  const [fields, setFields] = useState<Record<string, string> | null>(null)
  const [pending, setPending] = useState(false)
  const [done, setDone] = useState(false)
  const [unusable, setUnusable] = useState(false)
  const { register, focusFirst } = useFieldFocus(['password'] as const)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (pending || token === null || token === '') return

    setPending(true)
    setFormError(null)
    setFields(null)
    try {
      await verifyEmail(token, password)
      setDone(true)
    } catch (error) {
      if (error instanceof ApiError && error.code === 'invalid_token') {
        setUnusable(true)
        return
      }
      const presented = presentAuthError(m, error)
      setFormError(presented.message)
      setFields(presented.fields)
      focusFirst(presented.fields)
    } finally {
      setPending(false)
    }
  }

  if (missing) {
    return (
      <AuthLayout title={m.auth.verifyTitle} subtitle={m.auth.verifyMissingToken}>
        <Link className="button button-primary auth-submit" to="/sign-in">{m.auth.backToSignIn}</Link>
      </AuthLayout>
    )
  }

  if (done) {
    return (
      <AuthLayout title={m.auth.verifiedTitle} subtitle={m.auth.verifiedBody}>
        <FormAlert tone="success">{m.auth.verifiedBody}</FormAlert>
        <Link className="button button-primary auth-submit" to="/sign-in">{m.auth.backToSignIn}</Link>
      </AuthLayout>
    )
  }

  if (unusable) {
    return (
      <AuthLayout title={m.auth.verifyFailedTitle} subtitle={m.auth.verifyFailedBody}>
        <FormAlert tone="error">{m.auth.verifyFailedBody}</FormAlert>
        <Link className="button button-primary auth-submit" to="/sign-in">{m.auth.backToSignIn}</Link>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title={m.auth.verifyTitle} subtitle={m.auth.verifySubtitle}>
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
        />

        <button type="submit" className="button button-primary auth-submit" disabled={pending} aria-busy={pending || undefined}>
          {pending ? m.auth.verifySubmitting : m.auth.verifySubmit}
        </button>
      </form>

      <p className="auth-links">
        <Link to="/sign-in">{m.auth.backToSignIn}</Link>
      </p>
    </AuthLayout>
  )
}
