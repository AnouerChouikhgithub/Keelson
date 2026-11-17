import { useCallback, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router'
import { isApiError } from '../api/errors'
import { presentedFields } from '../api/formErrors'
import { acceptInvitation, previewInvitation } from '../api/invitations'
import { presentError } from '../api/presentError'
import type { AcceptInvitationResult } from '../api/types'
import { invalidateResource, useApiResource } from '../api/useApiResource'
import { useMutation } from '../api/useMutation'
import { Skeleton } from '../components/Skeleton'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import { useAuth } from '../session/AuthContext'
import { AuthLayout, FormAlert } from './auth/AuthLayout'
import { PasswordField } from './auth/PasswordField'
import { useFieldFocus } from './auth/useFieldFocus'
import './simple.css'

/**
 * `/accept-invitation?token=…`.
 *
 * The preview is public and tells the visitor who invited them and what role
 * they would get. Acceptance creates the account when the address is new, so a
 * password is required only then; an existing account must already be signed in
 * as that address, which the server answers with `401 sign_in_required` and the
 * screen turns into a sign-in step.
 */
export default function AcceptInvitation() {
  const { m } = useI18n()
  const { status } = useAuth()
  const [params] = useSearchParams()
  const token = params.get('token')
  useDocumentTitle(m.acceptInvitation.title)

  const [displayName, setDisplayName] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [signInRequired, setSignInRequired] = useState(false)
  const [result, setResult] = useState<AcceptInvitationResult | null>(null)
  const { register, focusFirst } = useFieldFocus(['password', 'confirmPassword'] as const)

  const preview = useApiResource(
    `invitation:${token ?? ''}`,
    useCallback((signal: AbortSignal) => previewInvitation(token ?? '', signal), [token]),
    { enabled: token !== null && token !== '' },
  )
  const accept = useMutation(
    (input: { token: string; password?: string; displayName?: string }) => acceptInvitation(input),
    { announceFailure: false },
  )

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (token === null || token === '') return
    setFormError(null)

    if (password !== '' && password !== confirmation) {
      const mismatch = { confirmPassword: m.auth.errors.passwordMismatch }
      setErrors(mismatch)
      focusFirst(mismatch)
      return
    }
    if (password !== '' && (password.length < 10 || password.length > 72)) {
      const invalid = { password: m.acceptInvitation.passwordInvalid }
      setErrors(invalid)
      focusFirst(invalid)
      return
    }
    setErrors({})

    const outcome = await accept.run({
      token,
      ...(password === '' ? {} : { password }),
      ...(displayName.trim() === '' ? {} : { displayName: displayName.trim() }),
    })
    if (!outcome.ok) {
      if (isApiError(outcome.error) && outcome.error.code === 'sign_in_required') {
        setSignInRequired(true)
        return
      }
      setErrors(presentedFields(outcome.error) ?? {})
      setFormError(presentError(m, outcome.error))
      focusFirst(presentedFields(outcome.error))
      return
    }
    setResult(outcome.data)
    // A new membership changes which workspaces the shell may show. An anonymous
    // visitor signs in next, which re-reads the session and the list anyway.
    invalidateResource('organizations')
  }

  if (token === null || token === '') {
    return (
      <AuthLayout title={m.acceptInvitation.title} subtitle={m.acceptInvitation.tokenMissing}>
        <p className="auth-alert auth-alert-error">{m.acceptInvitation.tokenMissing}</p>
        <Link className="button button-primary auth-submit" to="/sign-in">{m.acceptInvitation.signIn}</Link>
      </AuthLayout>
    )
  }

  if (result !== null) {
    return (
      <AuthLayout title={m.acceptInvitation.acceptedTitle} subtitle={result.organization.name}>
        <FormAlert tone="success">
          {m.acceptInvitation.acceptedBody(result.organization.name, result.role)}
        </FormAlert>
        {result.accountCreated ? (
          <Link className="button button-primary auth-submit" to="/sign-in">{m.acceptInvitation.signIn}</Link>
        ) : (
          <Link className="button button-primary auth-submit" to={`/o/${result.organization.id}/dashboard`}>
            {m.acceptInvitation.openWorkspace}
          </Link>
        )}
        <p className="auth-links">
          <Link to="/sign-in">{m.acceptInvitation.backHome}</Link>
        </p>
      </AuthLayout>
    )
  }

  if (preview.state.status === 'loading') {
    return (
      <AuthLayout title={m.acceptInvitation.title} subtitle={m.acceptInvitation.subtitle}>
        <Skeleton variant="text" count={3} label={m.common.loading} />
      </AuthLayout>
    )
  }

  if (preview.state.status === 'error') {
    const notFound = isApiError(preview.state.error) && preview.state.error.status === 404
    return (
      <AuthLayout
        title={notFound ? m.acceptInvitation.invalidTitle : m.acceptInvitation.title}
        subtitle={notFound ? m.acceptInvitation.invalidBody : presentError(m, preview.state.error)}
      >
        <Link className="button button-primary auth-submit" to="/sign-in">{m.acceptInvitation.signIn}</Link>
        <p className="auth-links">
          <Link to="/">{m.acceptInvitation.backHome}</Link>
        </p>
      </AuthLayout>
    )
  }

  const invitation = preview.state.data
  if (invitation.accepted || invitation.expired) {
    return (
      <AuthLayout
        title={invitation.accepted ? m.acceptInvitation.usedTitle : m.acceptInvitation.expiredTitle}
        subtitle={invitation.organizationName}
      >
        <p className="auth-alert auth-alert-error">
          {invitation.accepted ? m.acceptInvitation.usedBody : m.acceptInvitation.expiredBody}
        </p>
        <Link className="button button-primary auth-submit" to="/sign-in">{m.acceptInvitation.signIn}</Link>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title={m.acceptInvitation.title} subtitle={m.acceptInvitation.subtitle}>
      <p className="eyebrow">{m.acceptInvitation.eyebrow}</p>
      <dl className="invitation-facts">
        <div><dt>{m.acceptInvitation.organization}</dt><dd>{invitation.organizationName}</dd></div>
        <div><dt>{m.acceptInvitation.role}</dt><dd>{m.roles[invitation.role.toLowerCase() as keyof typeof m.roles]}</dd></div>
        <div><dt>{m.acceptInvitation.email}</dt><dd>{invitation.email}</dd></div>
      </dl>

      {signInRequired ? (
        <>
          <FormAlert tone="error">{m.acceptInvitation.signInRequired}</FormAlert>
          <p className="auth-subtitle">{m.acceptInvitation.signInRequiredBody(invitation.email)}</p>
          <Link className="button button-primary auth-submit" to="/sign-in">{m.acceptInvitation.signIn}</Link>
        </>
      ) : (
        <form className="auth-form" onSubmit={handleSubmit} noValidate>
          {formError !== null && <FormAlert tone="error">{formError}</FormAlert>}
          <div className="field">
            <label htmlFor="accept-name">{m.acceptInvitation.nameLabel}</label>
            <input
              id="accept-name"
              value={displayName}
              autoComplete="name"
              onChange={(event) => setDisplayName(event.target.value)}
            />
            <p className="field-hint">{m.acceptInvitation.nameHint}</p>
          </div>

          <PasswordField
            label={m.acceptInvitation.passwordLabel}
            value={password}
            onChange={setPassword}
            autoComplete="new-password"
            inputRef={register('password')}
            error={errors.password}
            hint={m.acceptInvitation.passwordHint}
            disabled={accept.pending}
          />
          <PasswordField
            label={m.auth.fields.confirmPassword}
            value={confirmation}
            onChange={setConfirmation}
            autoComplete="new-password"
            inputRef={register('confirmPassword')}
            error={errors.confirmPassword}
            disabled={accept.pending}
          />

          <button type="submit" className="button button-primary auth-submit" disabled={accept.pending} aria-busy={accept.pending || undefined}>
            {accept.pending ? m.acceptInvitation.accepting : m.acceptInvitation.accept}
          </button>
          {status === 'anonymous' && <p className="field-hint">{m.acceptInvitation.existingAccountHint}</p>}
        </form>
      )}

      <p className="auth-links">
        <Link to="/sign-in">{m.acceptInvitation.signIn}</Link>
      </p>
    </AuthLayout>
  )
}
