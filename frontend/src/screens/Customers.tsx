import { useCallback, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { createCustomer, deleteCustomer, listCustomers, readCustomer, updateCustomer } from '../api/customers'
import { canDeleteInvoiceResources, canWriteInvoiceResources } from '../api/organizations'
import { presentError } from '../api/presentError'
import type { BillingAddress, Customer, CustomerInput, CustomerSummary } from '../api/types'
import { invalidateResource, useApiResource } from '../api/useApiResource'
import { useMutation } from '../api/useMutation'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { DataTable } from '../components/DataTable'
import { EmptyState } from '../components/EmptyState'
import { ErrorState } from '../components/ErrorState'
import { Field } from '../components/Field'
import { Icon } from '../components/Icon'
import { LoadMore } from '../components/Pagination'
import { OverlaySurface } from '../components/OverlaySurface'
import { Skeleton } from '../components/Skeleton'
import { useToast } from '../components/ToastContext'
import { useDebouncedValue } from '../hooks/useDebouncedValue'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import type { Messages } from '../i18n'
import { useWorkspace } from '../app/WorkspaceContext'
import './customers.css'

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

function toForm(customer: Pick<Customer, 'name' | 'email' | 'phone'> & { billingAddress?: BillingAddress | null }): FormState {
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

function toInput(form: FormState): CustomerInput {
  const hasAddress = [form.street, form.city, form.postalCode, form.countryCode].some((value) => value.trim() !== '')
  return {
    name: form.name.trim(),
    email: form.email.trim() === '' ? null : form.email.trim(),
    phone: form.phone.trim() === '' ? null : form.phone.trim(),
    billingAddress: hasAddress
      ? {
        street: form.street.trim(),
        city: form.city.trim(),
        postalCode: form.postalCode.trim(),
        countryCode: form.countryCode.trim().toUpperCase(),
      }
      : null,
  }
}

function validate(input: CustomerInput, m: Messages): Record<string, string> {
  const next: Record<string, string> = {}
  if (input.name.length < 1 || input.name.length > 160) next.name = m.customers.invalidName
  if (input.email !== null && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.email)) next.email = m.customers.invalidEmail
  if (input.phone !== null && input.phone.length > 40) next.phone = m.customers.invalidPhone
  if (input.billingAddress !== null && !/^[A-Za-z]{2}$/.test(input.billingAddress.countryCode)) {
    next.countryCode = m.customers.invalidCountry
  }
  return next
}

/**
 * The drawer's form owns its draft state, initialised once from the record it
 * was mounted with. Opening a different customer gives it a new `key`, so no
 * effect has to copy a server response into local state after render.
 */
function CustomerForm({
  initial,
  submitLabel,
  pending,
  onSubmit,
  onCancel,
}: {
  initial: FormState
  submitLabel: string
  pending: boolean
  onSubmit: (input: CustomerInput) => void
  onCancel: () => void
}) {
  const { m } = useI18n()
  const [form, setForm] = useState<FormState>(initial)
  const [errors, setErrors] = useState<Record<string, string>>({})

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const input = toInput(form)
    const next = validate(input, m)
    setErrors(next)
    if (Object.keys(next).length === 0) onSubmit(input)
  }

  return (
    <form onSubmit={submit} noValidate>
      <Field label={m.customers.nameField} required error={errors.name}>
        {(field) => (
          <input {...field} value={form.name} maxLength={160} onChange={(event) => setForm({ ...form, name: event.target.value })} />
        )}
      </Field>
      <Field label={m.customers.emailField} error={errors.email}>
        {(field) => (
          <input {...field} type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} />
        )}
      </Field>
      <Field label={m.customers.phoneField} error={errors.phone}>
        {(field) => (
          <input {...field} type="tel" value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} />
        )}
      </Field>

      <fieldset className="address-fieldset">
        <legend>{m.customers.billingOptional}</legend>
        <p className="field-hint">{m.customers.billingHint}</p>
        <Field label={m.customers.street}>
          {(field) => (
            <input {...field} value={form.street} onChange={(event) => setForm({ ...form, street: event.target.value })} />
          )}
        </Field>
        <div className="field-row">
          <Field label={m.customers.city}>
            {(field) => (
              <input {...field} value={form.city} onChange={(event) => setForm({ ...form, city: event.target.value })} />
            )}
          </Field>
          <Field label={m.customers.postalCode}>
            {(field) => (
              <input {...field} value={form.postalCode} onChange={(event) => setForm({ ...form, postalCode: event.target.value })} />
            )}
          </Field>
        </div>
        <Field label={m.customers.countryCode} hint="TN, FR, DZ…" error={errors.countryCode}>
          {(field) => (
            <input
              {...field}
              value={form.countryCode}
              maxLength={2}
              autoComplete="country"
              onChange={(event) => setForm({ ...form, countryCode: event.target.value })}
            />
          )}
        </Field>
      </fieldset>

      <div className="confirm-actions">
        <button type="button" className="button button-ghost" onClick={onCancel}>{m.common.cancel}</button>
        <button type="submit" className="button button-primary" disabled={pending} aria-busy={pending || undefined}>
          {pending ? m.customers.saving : submitLabel}
        </button>
      </div>
    </form>
  )
}

const PAGE_SIZE = 50

export default function Customers() {
  const { m } = useI18n()
  const { membership } = useWorkspace()
  const toast = useToast()
  const orgId = membership.id
  const [query, setQuery] = useState('')
  const debouncedQuery = useDebouncedValue(query, 250)
  const [more, setMore] = useState<{ key: string; items: CustomerSummary[]; cursor: string | null }>({ key: '', items: [], cursor: null })
  const [loadingMore, setLoadingMore] = useState(false)
  const [drawer, setDrawer] = useState<{ mode: 'create' | 'edit'; customerId: string | null } | null>(null)
  const [pendingDelete, setPendingDelete] = useState<CustomerSummary | null>(null)
  useDocumentTitle(m.page.customers)

  const load = useCallback(
    (signal: AbortSignal) => listCustomers(orgId, { limit: PAGE_SIZE, signal }),
    [orgId],
  )
  const resource = useApiResource(`customers:${orgId}`, load)

  const editLoad = useCallback(
    (signal: AbortSignal) => readCustomer(orgId, drawer?.customerId ?? '', signal),
    [orgId, drawer?.customerId],
  )
  const editing = useApiResource(`customer:${orgId}:${drawer?.customerId ?? ''}`, editLoad, {
    enabled: drawer?.mode === 'edit' && drawer?.customerId !== null,
  })

  const save = useMutation(useCallback(
    async (input: CustomerInput) => (
      drawer?.mode === 'edit' && drawer.customerId !== null
        ? updateCustomer(orgId, drawer.customerId, input)
        : createCustomer(orgId, input)
    ),
    [orgId, drawer],
  ))
  const remove = useMutation(useCallback(
    (customerId: string) => deleteCustomer(orgId, customerId),
    [orgId],
  ))

  const { items, nextCursor } = useMemo(() => {
    const firstPage = resource.state.status === 'ready' ? resource.state.data.items : []
    return {
      items: more.key === orgId ? [...firstPage, ...more.items] : firstPage,
      nextCursor: more.key === orgId ? more.cursor : (resource.state.status === 'ready' ? resource.state.data.nextCursor : null),
    }
  }, [resource.state, more, orgId])

  const visible = useMemo(() => {
    const needle = debouncedQuery.trim().toLocaleLowerCase()
    if (needle === '') return items
    return items.filter((customer) =>
      `${customer.name} ${customer.email ?? ''} ${customer.phone ?? ''}`.toLocaleLowerCase().includes(needle),
    )
  }, [items, debouncedQuery])

  async function loadMore() {
    if (nextCursor === null || loadingMore) return
    setLoadingMore(true)
    try {
      const page = await listCustomers(orgId, { cursor: nextCursor, limit: PAGE_SIZE })
      setMore((current) => ({
        key: orgId,
        items: [...(current.key === orgId ? current.items : []), ...page.items],
        cursor: page.nextCursor,
      }))
    } catch (error) {
      toast.error(presentError(m, error))
    } finally {
      setLoadingMore(false)
    }
  }

  async function handleSave(input: CustomerInput) {
    const outcome = await save.run(input)
    if (!outcome.ok) return
    toast.success(drawer?.mode === 'edit' ? m.customers.updated(outcome.data.name) : m.customers.created(outcome.data.name))
    invalidateResource(`customers:${orgId}`)
    invalidateResource(`dashboard:${orgId}`)
    resource.reload()
    setDrawer(null)
  }

  async function confirmDelete() {
    if (pendingDelete === null) return
    const outcome = await remove.run(pendingDelete.id)
    if (!outcome.ok) return
    toast.success(m.customers.deleted(pendingDelete.name))
    invalidateResource(`customers:${orgId}`)
    resource.reload()
    setPendingDelete(null)
  }

  const heading = (
    <section className="page-intro">
      <div>
        <p className="eyebrow">{m.nav.workspace}</p>
        <h1>{m.customers.title}</h1>
        <p>{m.customers.subtitle}</p>
      </div>
      {canWriteInvoiceResources(membership.role) && (
        <button type="button" className="button button-primary" onClick={() => setDrawer({ mode: 'create', customerId: null })}>
          <Icon name="plus" size={18} /> {m.customers.add}
        </button>
      )}
    </section>
  )

  if (resource.state.status === 'loading') {
    return <div className="customers-page">{heading}<Skeleton variant="table" count={6} label={m.common.loading} /></div>
  }

  if (resource.state.status === 'error') {
    return (
      <div className="customers-page">
        {heading}
        <ErrorState
          title={m.customers.loadFailed}
          message={presentError(m, resource.state.error)}
          retryLabel={m.common.retry}
          onRetry={resource.reload}
        />
      </div>
    )
  }

  return (
    <div className="customers-page">
      {heading}

      <section className="panel list-panel" aria-label={m.customers.title}>
        <div className="toolbar">
          <div className="search-field">
            <Icon name="search" size={16} />
            <input
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              aria-label={m.customers.searchLabel}
              placeholder={m.customers.searchPlaceholder}
            />
          </div>
          {debouncedQuery.trim() !== '' && (
            <button type="button" className="button button-ghost button-sm" onClick={() => setQuery('')}>
              {m.customers.clearSearch}
            </button>
          )}
        </div>

        {items.length === 0 ? (
          <EmptyState
            icon="users"
            title={m.customers.noRecords}
            body={m.customers.noRecordsHint}
            action={canWriteInvoiceResources(membership.role)
              ? { label: m.customers.add, onClick: () => setDrawer({ mode: 'create', customerId: null }) }
              : undefined}
          />
        ) : visible.length === 0 ? (
          <EmptyState
            icon="search"
            title={m.customers.empty}
            body={m.customers.emptyHint}
            action={{ label: m.customers.clearSearch, onClick: () => setQuery('') }}
          />
        ) : (
          <DataTable
            caption={m.customers.tableCaption}
            footer={(
              <LoadMore
                count={visible.length}
                hasMore={nextCursor !== null}
                loading={loadingMore}
                onLoadMore={() => { void loadMore() }}
                note={debouncedQuery.trim() === '' ? undefined : m.customers.clientSearchNote}
              />
            )}
          >
            <thead>
              <tr>
                <th scope="col">{m.customers.columns.name}</th>
                <th scope="col">{m.customers.columns.email}</th>
                <th scope="col">{m.customers.columns.phone}</th>
                <th scope="col"><span className="visually-hidden">{m.customers.columns.actions}</span></th>
              </tr>
            </thead>
            <tbody>
              {visible.map((customer) => (
                <tr key={customer.id}>
                  <td className="cell-strong">{customer.name}</td>
                  <td>{customer.email ?? '—'}</td>
                  <td>{customer.phone ?? '—'}</td>
                  <td className="cell-end cell-actions">
                    {canWriteInvoiceResources(membership.role) && (
                      <button type="button" className="button button-ghost button-sm" onClick={() => setDrawer({ mode: 'edit', customerId: customer.id })}>
                        {m.customers.edit}
                      </button>
                    )}
                    {canDeleteInvoiceResources(membership.role) && (
                      <button type="button" className="button button-ghost button-sm danger-text" onClick={() => setPendingDelete(customer)}>
                        {m.common.delete}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        )}
      </section>

      {drawer !== null && (
        <OverlaySurface
          variant="drawer"
          closeLabel={m.customers.close}
          onClose={() => setDrawer(null)}
          label={drawer.mode === 'edit' ? m.customers.editTitle : m.customers.createTitle}
        >
          <div className="overlay-heading">
            <div>
              <p className="eyebrow">{m.customers.drawerEyebrow}</p>
              <h2>{drawer.mode === 'edit' ? m.customers.editTitle : m.customers.createTitle}</h2>
            </div>
            <button type="button" className="icon-button" aria-label={m.customers.close} onClick={() => setDrawer(null)}>
              <Icon name="close" />
            </button>
          </div>

          {drawer.mode === 'create' && (
            <CustomerForm
              initial={emptyForm}
              submitLabel={m.customers.create}
              pending={save.pending}
              onSubmit={(input) => { void handleSave(input) }}
              onCancel={() => setDrawer(null)}
            />
          )}

          {drawer.mode === 'edit' && editing.state.status === 'loading' && (
            <Skeleton variant="text" count={4} label={m.common.loading} />
          )}
          {drawer.mode === 'edit' && editing.state.status === 'error' && (
            <ErrorState
              title={m.customers.loadFailed}
              message={presentError(m, editing.state.error)}
              retryLabel={m.common.retry}
              onRetry={editing.reload}
            />
          )}
          {drawer.mode === 'edit' && editing.state.status === 'ready' && (
            <CustomerForm
              key={drawer.customerId ?? 'edit'}
              initial={toForm(editing.state.data)}
              submitLabel={m.customers.save}
              pending={save.pending}
              onSubmit={(input) => { void handleSave(input) }}
              onCancel={() => setDrawer(null)}
            />
          )}
        </OverlaySurface>
      )}

      <ConfirmDialog
        open={pendingDelete !== null}
        title={m.customers.deleteTitle}
        body={m.customers.deleteBody}
        confirmLabel={m.customers.confirmDelete}
        onConfirm={() => { void confirmDelete() }}
        onClose={() => setPendingDelete(null)}
        pending={remove.pending}
      />
    </div>
  )
}
