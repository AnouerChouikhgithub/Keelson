import { useCallback, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { createCustomer, deleteCustomer, listCustomers, updateCustomer } from '../api/customers'
import { isConflict, presentError } from '../api/presentError'
import type { BillingAddress, Customer, CustomerInput, OrganizationRole } from '../api/types'
import { useApiResource } from '../api/useApiResource'
import { canDeleteInvoiceResources, canWriteInvoiceResources } from '../api/organizations'
import { EmptyState } from '../components/EmptyState'
import { Icon } from '../components/Icon'
import { OverlaySurface } from '../components/OverlaySurface'
import { PersonCell } from '../components/PersonCell'
import { SearchField } from '../components/SearchField'
import { useEscapeKey } from '../hooks/useEscapeKey'
import { useI18n } from '../i18n/I18nContext'
import { initialsOf } from '../utils/initials'

type PanelState =
  | { mode: 'view'; customer: Customer }
  | { mode: 'create' }
  | { mode: 'edit'; customer: Customer }
  | { mode: 'delete'; customer: Customer }
  | null

type FormState = {
  name: string
  email: string
  phone: string
  street: string
  city: string
  postalCode: string
  countryCode: string
}

const emptyForm: FormState = { name: '', email: '', phone: '', street: '', city: '', postalCode: '', countryCode: '' }

function toForm(customer: Customer): FormState {
  return {
    name: customer.name,
    email: customer.email ?? '',
    phone: customer.phone ?? '',
    street: customer.billingAddress?.street ?? '',
    city: customer.billingAddress?.city ?? '',
    postalCode: customer.billingAddress?.postalCode ?? '',
    countryCode: customer.billingAddress?.countryCode ?? '',
  }
}

function isCompleteAddress(form: FormState): boolean {
  return [form.street, form.city, form.postalCode, form.countryCode].some((value) => value.trim() !== '')
}

/** Builds the exact payload the API accepts; the address is all-or-nothing. */
function toPayload(form: FormState): CustomerInput {
  const billingAddress: BillingAddress | null = isCompleteAddress(form)
    ? {
        street: form.street.trim(),
        city: form.city.trim(),
        postalCode: form.postalCode.trim(),
        countryCode: form.countryCode.trim().toUpperCase(),
      }
    : null
  return {
    name: form.name.trim(),
    email: form.email.trim() === '' ? null : form.email.trim(),
    phone: form.phone.trim() === '' ? null : form.phone.trim(),
    billingAddress,
  }
}

function addressLine(customer: Customer): string {
  const address = customer.billingAddress
  if (!address) return '—'
  return [address.street, address.postalCode, address.city, address.countryCode].filter(Boolean).join(', ')
}

export function CustomersScreen({ organizationId, role }: { organizationId: string; role: OrganizationRole }) {
  const { m } = useI18n()
  const copy = m.workspace.customers
  const [query, setQuery] = useState('')
  const [panel, setPanel] = useState<PanelState>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  const load = useCallback((signal: AbortSignal) => listCustomers(organizationId, { signal }), [organizationId])
  const { state, reload } = useApiResource(load)

  const canWrite = canWriteInvoiceResources(role)
  const canDelete = canDeleteInvoiceResources(role)

  const customers = useMemo(() => state.status === 'ready' ? state.data.items : [], [state])
  const filtered = useMemo(() => {
    const needle = query.trim().toLocaleLowerCase()
    if (needle === '') return customers
    return customers.filter((customer) =>
      customer.name.toLocaleLowerCase().includes(needle)
      || (customer.email ?? '').toLocaleLowerCase().includes(needle))
  }, [customers, query])

  const hasMore = state.status === 'ready' && state.data.nextCursor !== null

  useEscapeKey(panel !== null && !pending, () => setPanel(null))

  async function submitForm(form: FormState) {
    if (pending) return
    const payload = toPayload(form)
    setPending(true)
    setNotice(null)
    try {
      if (panel?.mode === 'edit') {
        const updated = await updateCustomer(organizationId, panel.customer.id, payload)
        setPanel(null)
        setNotice(copy.updated(updated.name))
      } else {
        const created = await createCustomer(organizationId, payload)
        setPanel(null)
        setNotice(copy.created(created.name))
      }
      reload()
    } catch (error) {
      setNotice(presentError(m, error))
      // A rejected edit means the row may have changed elsewhere: keep the
      // form open, but do not silently overwrite the list with stale data.
      if (isConflict(error)) reload()
    } finally {
      setPending(false)
    }
  }

  async function confirmDelete(customer: Customer) {
    if (pending) return
    setPending(true)
    setNotice(null)
    try {
      await deleteCustomer(organizationId, customer.id)
      setPanel(null)
      setNotice(copy.deleted(customer.name))
      reload()
    } catch (error) {
      // 409 => invoices still reference this customer; that is a product rule,
      // not a retryable failure, so explain it and refresh the list.
      setNotice(isConflict(error) ? copy.blocked : presentError(m, error))
      setPanel({ mode: 'view', customer })
      reload()
    } finally {
      setPending(false)
    }
  }

  return (
    <section className="panel workspace-panel">
      <div className="panel-heading workspace-heading">
        <div>
          <h1>{m.page.customers}</h1>
          <p>{m.placeholder.customers}</p>
        </div>
        {canWrite && (
          <button className="button button-primary" type="button" onClick={() => { setNotice(null); setPanel({ mode: 'create' }) }}>
            <Icon name="plus" size={18} /> {copy.add}
          </button>
        )}
      </div>

      <div className="toolbar">
        <SearchField label={copy.searchPlaceholder} value={query} onChange={setQuery} />
      </div>

      {notice && (
        <div className="inline-feedback" role="status">
          <Icon name="check" size={16} />
          <span>{notice}</span>
          <button type="button" aria-label={m.common.dismiss} onClick={() => setNotice(null)}><Icon name="close" size={15} /></button>
        </div>
      )}

      {state.status === 'loading' && (
        <p className="mock-note" role="status">{m.api.loading}</p>
      )}

      {state.status === 'error' && (
        <EmptyState
          icon="users"
          title={m.api.offline}
          body={presentError(m, state.error)}
          action={{ label: m.api.reload, onClick: reload }}
        />
      )}

      {state.status === 'ready' && customers.length === 0 && (
        <EmptyState
          icon="users"
          title={copy.noRecords}
          body={copy.noRecordsHint}
          action={canWrite ? { label: copy.add, onClick: () => setPanel({ mode: 'create' }) } : undefined}
        />
      )}

      {state.status === 'ready' && customers.length > 0 && filtered.length === 0 && (
        <EmptyState
          icon="users"
          title={copy.empty}
          body={copy.emptyHint}
          action={{ label: copy.clearSearch, onClick: () => setQuery('') }}
        />
      )}

      {state.status === 'ready' && filtered.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{copy.name}</th>
                <th>{copy.contact}</th>
                <th>{copy.address}</th>
                <th><span className="visually-hidden">{copy.details}</span></th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((customer) => (
                <tr key={customer.id}>
                  <td><PersonCell initials={initialsOf(customer.name)} name={customer.name} /></td>
                  <td className="muted-cell">
                    {customer.email ?? '—'}
                    {customer.phone && <><br /><span className="muted-cell">{customer.phone}</span></>}
                  </td>
                  <td>{addressLine(customer)}</td>
                  <td>
                    <button type="button" className="button button-ghost" aria-label={copy.open(customer.name)} onClick={() => setPanel({ mode: 'view', customer })}>
                      {copy.view} <Icon name="chevron" size={15} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="table-footer">
        <span className="mock-note">{hasMore ? copy.partial : copy.sourceNote}</span>
      </div>

      {panel?.mode === 'view' && (
        <OverlaySurface variant="drawer" closeLabel={copy.close} onClose={() => setPanel(null)} label={panel.customer.name}>
          <div className="drawer-heading">
            <p className="eyebrow">{copy.drawerEyebrow}</p>
            <button type="button" className="icon-button" aria-label={copy.close} onClick={() => setPanel(null)}><Icon name="close" /></button>
          </div>
          <div className="drawer-identity">
            <span className="drawer-avatar">{initialsOf(panel.customer.name)}</span>
            <div>
              <h2>{panel.customer.name}</h2>
              <span className="muted-cell">{panel.customer.email ?? '—'}</span>
            </div>
          </div>
          <dl className="detail-list">
            <div><dt>{copy.contact}</dt><dd>{panel.customer.phone ?? '—'}</dd></div>
            <div><dt>{copy.address}</dt><dd>{addressLine(panel.customer)}</dd></div>
          </dl>
          <div className="modal-actions">
            {canWrite && (
              <button type="button" className="button button-secondary" onClick={() => setPanel({ mode: 'edit', customer: panel.customer })}>{copy.edit}</button>
            )}
            {canDelete && (
              <button type="button" className="button button-ghost" onClick={() => setPanel({ mode: 'delete', customer: panel.customer })}>{copy.remove(panel.customer.name)}</button>
            )}
            <button type="button" className="button button-ghost" onClick={() => setPanel(null)}>{copy.close}</button>
          </div>
        </OverlaySurface>
      )}

      {(panel?.mode === 'create' || panel?.mode === 'edit') && (
        <CustomerForm
          key={panel.mode === 'edit' ? panel.customer.id : 'new'}
          title={panel.mode === 'edit' ? copy.editTitle : copy.createTitle}
          initial={panel.mode === 'edit' ? toForm(panel.customer) : emptyForm}
          submitLabel={panel.mode === 'edit' ? copy.save : copy.create}
          pending={pending}
          onCancel={() => setPanel(null)}
          onSubmit={submitForm}
        />
      )}

      {panel?.mode === 'delete' && (
        <OverlaySurface variant="dialog" closeLabel={copy.cancel} onClose={() => setPanel(null)} labelledBy="delete-customer-title">
          <h2 id="delete-customer-title">{copy.deleteTitle}</h2>
          <p>{copy.deleteBody}</p>
          <div className="modal-actions">
            <button type="button" className="button button-ghost" onClick={() => setPanel(null)} disabled={pending}>{copy.cancel}</button>
            <button type="button" className="button button-primary" onClick={() => void confirmDelete(panel.customer)} disabled={pending}>
              {pending ? copy.saving : copy.confirmDelete}
            </button>
          </div>
        </OverlaySurface>
      )}
    </section>
  )
}

function CustomerForm({
  title,
  initial,
  submitLabel,
  pending,
  onCancel,
  onSubmit,
}: {
  title: string
  initial: FormState
  submitLabel: string
  pending: boolean
  onCancel: () => void
  onSubmit: (form: FormState) => Promise<void>
}) {
  const { m } = useI18n()
  const copy = m.workspace.customers
  const [form, setForm] = useState<FormState>(initial)
  const [errors, setErrors] = useState<Partial<Record<keyof FormState, string>>>({})

  function update<K extends keyof FormState>(field: K, value: FormState[K]) {
    setForm((current) => ({ ...current, [field]: value }))
  }

  function validate(): boolean {
    const next: Partial<Record<keyof FormState, string>> = {}
    const name = form.name.trim()
    if (name === '' || name.length > 160) next.name = copy.invalidName
    const email = form.email.trim()
    if (email !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) next.email = copy.invalidEmail
    if (isCompleteAddress(form)) {
      for (const field of ['street', 'city', 'postalCode', 'countryCode'] as const) {
        if (form[field].trim() === '') next[field] = copy.invalidName
      }
      if (!/^[A-Za-z]{2}$/.test(form.countryCode.trim())) next.countryCode = copy.invalidCountry
    }
    setErrors(next)
    return Object.keys(next).length === 0
  }

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (pending || !validate()) return
    void onSubmit(form)
  }

  return (
    <OverlaySurface variant="dialog" closeLabel={copy.cancel} onClose={onCancel} labelledBy="customer-form-title">
      <h2 id="customer-form-title">{title}</h2>
      <form onSubmit={handleSubmit} noValidate>
        <label className="field">
          <span>{copy.nameField}</span>
          <input value={form.name} maxLength={160} onChange={(event) => update('name', event.target.value)} aria-invalid={errors.name !== undefined} />
          {errors.name && <span className="field-error">{errors.name}</span>}
        </label>
        <div className="field-row">
          <label className="field">
            <span>{copy.emailField}</span>
            <input type="email" value={form.email} onChange={(event) => update('email', event.target.value)} aria-invalid={errors.email !== undefined} />
            {errors.email && <span className="field-error">{errors.email}</span>}
          </label>
          <label className="field">
            <span>{copy.phoneField}</span>
            <input value={form.phone} maxLength={40} onChange={(event) => update('phone', event.target.value)} />
          </label>
        </div>
        <p className="field-hint">{copy.billingOptional}</p>
        <div className="field-row">
          <label className="field">
            <span>{copy.street}</span>
            <input value={form.street} onChange={(event) => update('street', event.target.value)} aria-invalid={errors.street !== undefined} />
          </label>
          <label className="field">
            <span>{copy.city}</span>
            <input value={form.city} onChange={(event) => update('city', event.target.value)} aria-invalid={errors.city !== undefined} />
          </label>
        </div>
        <div className="field-row">
          <label className="field">
            <span>{copy.postalCode}</span>
            <input value={form.postalCode} onChange={(event) => update('postalCode', event.target.value)} aria-invalid={errors.postalCode !== undefined} />
          </label>
          <label className="field">
            <span>{copy.countryCode}</span>
            <input value={form.countryCode} maxLength={2} onChange={(event) => update('countryCode', event.target.value)} aria-invalid={errors.countryCode !== undefined} />
            {errors.countryCode && <span className="field-error">{errors.countryCode}</span>}
          </label>
        </div>
        <div className="modal-actions">
          <button type="button" className="button button-ghost" onClick={onCancel} disabled={pending}>{copy.cancel}</button>
          <button type="submit" className="button button-primary" disabled={pending}>{pending ? copy.saving : submitLabel}</button>
        </div>
      </form>
    </OverlaySurface>
  )
}
