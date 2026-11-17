import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { presentedFields } from '../api/formErrors'
import { createOrganization, subdomainAvailable } from '../api/organizationAdmin'
import { presentError } from '../api/presentError'
import { invalidateResource } from '../api/useApiResource'
import { useMutation } from '../api/useMutation'
import { DataTable } from '../components/DataTable'
import { EmptyState } from '../components/EmptyState'
import { ErrorState } from '../components/ErrorState'
import { Field } from '../components/Field'
import { Icon } from '../components/Icon'
import { Skeleton } from '../components/Skeleton'
import { useToast } from '../components/ToastContext'
import { useDebouncedValue } from '../hooks/useDebouncedValue'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import { useOrganizations } from '../app/useOrganizations'
import './simple.css'

const SUBDOMAIN = /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/

type Availability = 'idle' | 'checking' | 'available' | 'taken'

/**
 * Creating a workspace and listing the ones the caller already belongs to.
 *
 * The availability check is a hint only: the unique index is the authority, so a
 * `409 subdomain_taken` is still handled after a green check.
 */
export default function Onboarding() {
  const { m } = useI18n()
  const organizations = useOrganizations()
  const navigate = useNavigate()
  const toast = useToast()
  useDocumentTitle(m.page.onboarding)

  const [name, setName] = useState('')
  const [subdomain, setSubdomain] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [checked, setChecked] = useState<{ value: string; available: boolean } | null>(null)
  const debouncedSubdomain = useDebouncedValue(subdomain.trim().toLowerCase(), 400)
  const create = useMutation(createOrganization, { announceFailure: false })
  const validAddress = debouncedSubdomain.length >= 3 && SUBDOMAIN.test(debouncedSubdomain)

  useEffect(() => {
    if (!validAddress) return
    let cancelled = false
    const controller = new AbortController()
    subdomainAvailable(debouncedSubdomain, controller.signal).then(
      (available) => {
        if (!cancelled) setChecked({ value: debouncedSubdomain, available })
      },
      () => {
        // A failed hint is not a failed form: the submit still decides.
      },
    )
    return () => {
      cancelled = true
      controller.abort()
    }
  }, [debouncedSubdomain, validAddress])

  // Derived, not stored: an invalid or half-typed address is simply not checked
  // yet, so no effect has to reset a status it never set.
  const availability: Availability = !validAddress
    ? 'idle'
    : checked?.value === debouncedSubdomain
      ? (checked.available ? 'available' : 'taken')
      : 'checking'

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const trimmedName = name.trim()
    const address = subdomain.trim().toLowerCase()
    const next: Record<string, string> = {}
    if (trimmedName.length < 2 || trimmedName.length > 120) next.name = m.onboarding.invalidName
    if (address.length < 3 || address.length > 63 || !SUBDOMAIN.test(address)) next.subdomain = m.onboarding.invalidAddress
    setErrors(next)
    if (Object.keys(next).length > 0) return

    const outcome = await create.run({ name: trimmedName, subdomain: address })
    if (!outcome.ok) {
      setErrors(presentedFields(outcome.error) ?? {})
      toast.error(presentError(m, outcome.error))
      return
    }
    invalidateResource('organizations')
    toast.success(m.onboarding.created(outcome.data.name))
    void navigate(`/o/${outcome.data.id}/dashboard`)
  }

  return (
    <div className="simple-page">
      <section className="page-intro">
        <div>
          <p className="eyebrow">{m.onboarding.eyebrow}</p>
          <h1>{m.onboarding.title}</h1>
          <p>{m.onboarding.subtitle}</p>
        </div>
      </section>

      <section className="panel" aria-labelledby="your-organizations-title">
        <div className="panel-heading">
          <div>
            <h2 id="your-organizations-title">{m.onboarding.yours}</h2>
            <p>{m.onboarding.yoursHint}</p>
          </div>
        </div>

        {organizations.state.status === 'loading' && <Skeleton variant="list" count={3} label={m.common.loading} />}
        {organizations.state.status === 'error' && (
          <ErrorState
            title={m.api.listFailed}
            message={presentError(m, organizations.state.error)}
            retryLabel={m.common.retry}
            onRetry={organizations.reload}
          />
        )}
        {organizations.state.status === 'ready' && organizations.state.data.length === 0 && (
          <EmptyState icon="building" title={m.account.noWorkspace} body={m.account.noWorkspaceHint} />
        )}
        {organizations.state.status === 'ready' && organizations.state.data.length > 0 && (
          <DataTable caption={m.onboarding.yours}>
            <thead>
              <tr>
                <th scope="col">{m.common.organization}</th>
                <th scope="col">{m.settings.subdomain}</th>
                <th scope="col">{m.common.role}</th>
                <th scope="col"><span className="visually-hidden">{m.common.actions}</span></th>
              </tr>
            </thead>
            <tbody>
              {organizations.state.data.map((organization) => (
                <tr key={organization.id}>
                  <td className="cell-strong">{organization.name}</td>
                  <td>{organization.subdomain}.keelson.app</td>
                  <td>{m.roles[organization.role.toLowerCase() as keyof typeof m.roles]}</td>
                  <td className="cell-end">
                    <Link className="button button-ghost button-sm" to={`/o/${organization.id}/dashboard`}>
                      {m.onboarding.open}
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        )}
      </section>

      <section className="panel" aria-labelledby="new-organization-title">
        <div className="panel-heading">
          <div>
            <h2 id="new-organization-title">{m.onboarding.formTitle}</h2>
            <p>{m.onboarding.formHint}</p>
          </div>
        </div>

        <form className="settings-form" onSubmit={handleSubmit} noValidate>
          <Field label={m.onboarding.orgName} required error={errors.name}>
            {(field) => (
              <input
                {...field}
                value={name}
                maxLength={120}
                autoComplete="organization"
                placeholder={m.onboarding.namePlaceholder}
                onChange={(event) => setName(event.target.value)}
              />
            )}
          </Field>
          <Field
            label={m.onboarding.address}
            required
            error={errors.subdomain}
            hint={
              availability === 'checking' ? m.onboarding.addressChecking
                : availability === 'available' ? m.onboarding.addressAvailable
                  : availability === 'taken' ? m.onboarding.addressTaken
                    : m.onboarding.addressHint
            }
          >
            {(field) => (
              <input
                {...field}
                value={subdomain}
                maxLength={63}
                autoComplete="off"
                spellCheck={false}
                placeholder={m.onboarding.addressPlaceholder}
                onChange={(event) => setSubdomain(event.target.value)}
              />
            )}
          </Field>
          <div className="settings-form-actions">
            <button type="submit" className="button button-primary" disabled={create.pending} aria-busy={create.pending || undefined}>
              <Icon name="plus" size={17} /> {create.pending ? m.onboarding.creating : m.onboarding.createOrg}
            </button>
          </div>
        </form>
      </section>
    </div>
  )
}
