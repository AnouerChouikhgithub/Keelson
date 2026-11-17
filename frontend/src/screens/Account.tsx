import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { presentedFields } from '../api/formErrors'
import { changePassword, updateProfile } from '../api/profile'
import { presentError } from '../api/presentError'
import { useMutation } from '../api/useMutation'
import { Field } from '../components/Field'
import { Icon } from '../components/Icon'
import { useToast } from '../components/ToastContext'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import { useAuth } from '../session/AuthContext'
import { initialsOf } from '../utils/initials'
import { useWorkspace } from '../app/WorkspaceContext'
import './simple.css'

/**
 * Profile and password writes.
 *
 * A password change keeps this session and ends the others server-side, so the
 * screen reports the server's answer instead of signing itself out.
 */
export default function Account() {
  const { m } = useI18n()
  const { user, refresh, signOut } = useAuth()
  const { membership } = useWorkspace()
  const navigate = useNavigate()
  const toast = useToast()
  useDocumentTitle(m.page.account)

  const [displayName, setDisplayName] = useState(user?.displayName ?? '')
  const [profileErrors, setProfileErrors] = useState<Record<string, string>>({})
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [passwordErrors, setPasswordErrors] = useState<Record<string, string>>({})

  const saveProfile = useMutation(updateProfile, { announceFailure: false })
  const savePassword = useMutation(changePassword, { announceFailure: false })

  const name = user?.displayName ?? user?.email ?? m.account.unknownIdentity
  const roleLabel = m.roles[membership.role.toLowerCase() as keyof typeof m.roles]

  async function handleProfile(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const trimmed = displayName.trim()
    if (trimmed === '') {
      setProfileErrors({ displayName: m.account.displayNameInvalid })
      return
    }
    setProfileErrors({})
    const outcome = await saveProfile.run(trimmed)
    if (!outcome.ok) {
      setProfileErrors(presentedFields(outcome.error) ?? {})
      return
    }
    await refresh()
    toast.success(m.account.saved)
  }

  async function handlePassword(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (newPassword !== confirmation) {
      setPasswordErrors({ confirmPassword: m.account.passwordMismatch })
      return
    }
    if (newPassword.length < 10 || newPassword.length > 72) {
      setPasswordErrors({ newPassword: m.account.passwordRule })
      return
    }
    setPasswordErrors({})
    const outcome = await savePassword.run(currentPassword, newPassword)
    if (!outcome.ok) {
      setPasswordErrors(presentedFields(outcome.error) ?? {})
      return
    }
    setCurrentPassword('')
    setNewPassword('')
    setConfirmation('')
    toast.success(m.account.passwordSaved)
  }

  async function handleSignOut() {
    try {
      await signOut()
      navigate('/sign-in', { replace: true })
    } catch (error) {
      toast.error(presentError(m, error))
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

      <section className="panel" aria-labelledby="profile-title">
        <h2 id="profile-title">{m.account.profileTitle}</h2>
        <p className="panel-help">{m.account.profileHelp}</p>
        <form className="settings-form" onSubmit={handleProfile} noValidate>
          <Field label={m.account.name} required error={profileErrors.displayName}>
            {(field) => (
              <input
                {...field}
                value={displayName}
                maxLength={120}
                autoComplete="name"
                onChange={(event) => setDisplayName(event.target.value)}
              />
            )}
          </Field>
          <div className="settings-form-actions">
            <button type="submit" className="button button-primary" disabled={saveProfile.pending} aria-busy={saveProfile.pending || undefined}>
              {saveProfile.pending ? m.account.saving : m.account.save}
            </button>
          </div>
        </form>
      </section>

      <section className="panel" aria-labelledby="password-title">
        <h2 id="password-title">{m.account.passwordTitle}</h2>
        <p className="panel-help">{m.account.passwordHelp}</p>
        <form className="settings-form" onSubmit={handlePassword} noValidate>
          <Field label={m.account.currentPassword} required error={passwordErrors.currentPassword}>
            {(field) => (
              <input
                {...field}
                type="password"
                value={currentPassword}
                autoComplete="current-password"
                onChange={(event) => setCurrentPassword(event.target.value)}
              />
            )}
          </Field>
          <Field label={m.account.newPassword} required error={passwordErrors.newPassword} hint={m.account.passwordRule}>
            {(field) => (
              <input
                {...field}
                type="password"
                value={newPassword}
                autoComplete="new-password"
                onChange={(event) => setNewPassword(event.target.value)}
              />
            )}
          </Field>
          <Field label={m.account.confirmPassword} required error={passwordErrors.confirmPassword}>
            {(field) => (
              <input
                {...field}
                type="password"
                value={confirmation}
                autoComplete="new-password"
                onChange={(event) => setConfirmation(event.target.value)}
              />
            )}
          </Field>
          <div className="settings-form-actions">
            <button type="submit" className="button button-primary" disabled={savePassword.pending} aria-busy={savePassword.pending || undefined}>
              {savePassword.pending ? m.account.changing : m.account.changePassword}
            </button>
            <Link className="button button-ghost" to="/forgot-password">{m.account.useResetFlow}</Link>
          </div>
        </form>
      </section>

      <section className="panel" aria-labelledby="sessions-title">
        <h2 id="sessions-title">{m.account.sessionsTitle}</h2>
        <p className="panel-help">{m.account.sessionsHelp}</p>
        <p className="panel-help">{m.account.sessionsNote}</p>
        <div className="settings-form-actions">
          <button type="button" className="button button-secondary" onClick={() => { void handleSignOut() }}>
            <Icon name="logout" size={16} /> {m.account.signOut}
          </button>
        </div>
      </section>
    </div>
  )
}
