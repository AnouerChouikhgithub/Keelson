import { useState } from 'react'
import type { FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { presentedFields } from '../api/formErrors'
import { deleteOrganization, updateOrganization } from '../api/organizationAdmin'
import { canOwnOrganization } from '../api/organizations'
import { presentError } from '../api/presentError'
import { invalidateResource } from '../api/useApiResource'
import { useMutation } from '../api/useMutation'
import type { OrganizationMembership } from '../api/types'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { Field } from '../components/Field'
import { Icon } from '../components/Icon'
import { ThemeControl } from '../components/ThemeControl'
import { useToast } from '../components/ToastContext'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import { locales, messages } from '../i18n'
import { useWorkspace } from '../app/WorkspaceContext'
import './simple.css'

const SUBDOMAIN = /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/

/**
 * The organization form is keyed by the tenant, so switching workspace rebuilds
 * its draft state from that membership instead of copying it in an effect.
 */
function OrganizationForm({
  membership,
  onSaved,
}: {
  membership: OrganizationMembership
  onSaved: (name: string) => void
}) {
  const { m } = useI18n()
  const [name, setName] = useState(membership.name)
  const [subdomain, setSubdomain] = useState(membership.subdomain)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const save = useMutation(
    (patch: { name?: string; subdomain?: string }) => updateOrganization(membership.id, patch),
    { announceFailure: false },
  )

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const nextName = name.trim()
    const nextSubdomain = subdomain.trim().toLowerCase()
    const next: Record<string, string> = {}
    if (nextName.length < 2 || nextName.length > 120) next.name = m.settings.invalidName
    if (nextSubdomain.length < 3 || nextSubdomain.length > 63 || !SUBDOMAIN.test(nextSubdomain)) {
      next.subdomain = m.settings.invalidSubdomain
    }
    setErrors(next)
    if (Object.keys(next).length > 0) return

    // Only the fields that changed are sent: an empty patch is a server-side 422.
    const patch: { name?: string; subdomain?: string } = {}
    if (nextName !== membership.name) patch.name = nextName
    if (nextSubdomain !== membership.subdomain) patch.subdomain = nextSubdomain
    if (Object.keys(patch).length === 0) return

    const outcome = await save.run(patch)
    if (!outcome.ok) {
      setErrors(presentedFields(outcome.error) ?? {})
      return
    }
    onSaved(outcome.data.name)
  }

  return (
    <form className="settings-form" onSubmit={submit} noValidate>
      <Field label={m.settings.name} error={errors.name}>
        {(field) => (
          <input {...field} value={name} maxLength={120} onChange={(event) => setName(event.target.value)} />
        )}
      </Field>
      <Field label={m.settings.subdomain} error={errors.subdomain} hint={`${subdomain.trim().toLowerCase() || '…'}.keelson.app`}>
        {(field) => (
          <input
            {...field}
            value={subdomain}
            maxLength={63}
            spellCheck={false}
            onChange={(event) => setSubdomain(event.target.value)}
          />
        )}
      </Field>
      <div className="settings-form-actions">
        <button type="submit" className="button button-primary" disabled={save.pending} aria-busy={save.pending || undefined}>
          {save.pending ? m.settings.saving : m.settings.save}
        </button>
      </div>
    </form>
  )
}

/**
 * Appearance and language are browser-local; the organization block writes
 * through the owner-only endpoints, and deletion is a typed confirmation.
 */
export default function Settings() {
  const { m, locale, setLocale } = useI18n()
  const { membership, refreshMemberships } = useWorkspace()
  const navigate = useNavigate()
  const toast = useToast()
  useDocumentTitle(m.page.settings)
  const isOwner = canOwnOrganization(membership.role)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const remove = useMutation(
    () => deleteOrganization(membership.id, membership.name),
    { announceFailure: false },
  )

  async function handleDelete() {
    const outcome = await remove.run()
    if (!outcome.ok) {
      toast.error(presentError(m, outcome.error))
      return
    }
    setConfirmDelete(false)
    toast.success(m.settings.deleted(membership.name))
    invalidateResource('organizations')
    void navigate('/', { replace: true })
  }

  return (
    <div className="simple-page">
      <section className="page-intro">
        <div>
          <p className="eyebrow">{m.nav.workspace}</p>
          <h1>{m.settings.title}</h1>
          <p>{m.settings.subtitle}</p>
        </div>
      </section>

      <section className="panel settings-card" aria-labelledby="appearance-title">
        <h2 id="appearance-title">{m.settings.appearance}</h2>
        <p className="panel-help">{m.settings.appearanceHelp}</p>
        <ThemeControl className="settings-theme" />
      </section>

      <section className="panel settings-card" aria-labelledby="language-title">
        <h2 id="language-title">{m.settings.language}</h2>
        <p className="panel-help">{m.settings.languageHelp}</p>
        <div className="language-grid" role="group" aria-label={m.settings.language}>
          {locales.map((value) => (
            <button
              key={value}
              type="button"
              className={`language-option ${locale === value ? 'selected' : ''}`}
              aria-pressed={locale === value}
              onClick={() => setLocale(value)}
            >
              <Icon name="globe" size={16} />
              <span>{messages[value].common.languageName}</span>
              {locale === value && <Icon name="check" size={15} />}
            </button>
          ))}
        </div>
        <p className="panel-help">{m.settings.localNote}</p>
      </section>

      <section className="panel settings-card" aria-labelledby="organization-title">
        <h2 id="organization-title">{m.settings.organization}</h2>
        <p className="panel-help">{m.settings.organizationHelp}</p>
        {isOwner ? (
          <OrganizationForm
            key={membership.id}
            membership={membership}
            onSaved={(name) => {
              invalidateResource('organizations')
              refreshMemberships()
              toast.success(m.settings.saved(name))
            }}
          />
        ) : (
          <div className="settings-form">
            <Field label={m.settings.name} hint={m.settings.ownerOnly}>
              {(field) => <input {...field} value={membership.name} readOnly />}
            </Field>
            <Field label={m.settings.subdomain} hint={m.settings.ownerOnly}>
              {(field) => <input {...field} value={membership.subdomain} readOnly />}
            </Field>
          </div>
        )}
      </section>

      {isOwner && (
        <section className="panel settings-card danger-zone" aria-labelledby="danger-title">
          <h2 id="danger-title">{m.settings.danger}</h2>
          <p className="panel-help">{m.settings.dangerHelp}</p>
          <div className="settings-form-actions">
            <button type="button" className="button button-danger" onClick={() => setConfirmDelete(true)}>
              {m.settings.deleteOrg}
            </button>
          </div>
        </section>
      )}

      <ConfirmDialog
        open={confirmDelete}
        title={m.settings.deleteTitle}
        body={m.settings.deleteBody}
        confirmLabel={m.settings.deleteOrg}
        confirmPhrase={membership.name}
        phraseLabel={m.settings.deletePhraseLabel(membership.name)}
        onConfirm={() => { void handleDelete() }}
        onClose={() => setConfirmDelete(false)}
        pending={remove.pending}
      />
    </div>
  )
}
