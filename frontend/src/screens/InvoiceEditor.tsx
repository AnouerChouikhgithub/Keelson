import { useCallback, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router'
import { listCustomers } from '../api/customers'
import { canWriteInvoiceResources } from '../api/organizations'
import { createInvoiceDraft, readInvoice, sendInvoice, updateInvoiceDraft } from '../api/invoices'
import { presentError } from '../api/presentError'
import type { Invoice, InvoiceDraftInput } from '../api/types'
import { invalidateResource, useApiResource } from '../api/useApiResource'
import { useMutation } from '../api/useMutation'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { EmptyState } from '../components/EmptyState'
import { ErrorState } from '../components/ErrorState'
import { Field } from '../components/Field'
import { Icon } from '../components/Icon'
import { Skeleton } from '../components/Skeleton'
import { useToast } from '../components/ToastContext'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import type { Messages } from '../i18n'
import { useWorkspace } from '../app/WorkspaceContext'
import './invoices.css'

type LineDraft = { key: string; description: string; quantity: string; unitPrice: string }

type DraftState = {
  customerId: string
  issueDate: string
  dueDate: string
  lines: LineDraft[]
  taxRate: string
  discount: string
}

function todayIso(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

function plusDays(iso: string, days: number): string {
  const date = new Date(`${iso}T00:00:00`)
  date.setDate(date.getDate() + days)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function blankDraft(): DraftState {
  const issue = todayIso()
  return {
    customerId: '',
    issueDate: issue,
    dueDate: plusDays(issue, 15),
    lines: [{ key: 'line-1', description: '', quantity: '1', unitPrice: '0' }],
    taxRate: '19',
    discount: '0',
  }
}

function fromInvoice(invoice: Invoice): DraftState {
  return {
    customerId: invoice.customerId,
    issueDate: invoice.issueDate,
    dueDate: invoice.dueDate,
    lines: invoice.lineItems.map((line, index) => ({
      key: `line-${index + 1}`,
      description: line.description,
      quantity: String(Number(line.quantity)),
      unitPrice: String(Number(line.unitPrice)),
    })),
    taxRate: invoice.taxRate === null ? '' : String(Number(invoice.taxRate)),
    discount: invoice.discountAmount === null ? '0' : String(Number(invoice.discountAmount)),
  }
}

function validate(draft: DraftState, m: Messages): Record<string, string> {
  const next: Record<string, string> = {}
  if (draft.customerId === '') next.customerId = m.invoices.editor.selectCustomer
  if (draft.issueDate === '' || draft.dueDate === '') next.dueDate = m.api.invalid
  if (draft.dueDate < draft.issueDate) next.dueDate = m.api.invalid
  draft.lines.forEach((line, index) => {
    if (line.description.trim() === '') next[`line-${index}-description`] = m.invoices.editor.emptyLine
    if (!(Number(line.quantity) > 0)) next[`line-${index}-quantity`] = m.api.invalid
    if (!(Number(line.unitPrice) >= 0)) next[`line-${index}-unitPrice`] = m.api.invalid
  })
  return next
}

function toInput(draft: DraftState): InvoiceDraftInput {
  return {
    customerId: draft.customerId,
    issueDate: draft.issueDate,
    dueDate: draft.dueDate,
    currency: 'TND',
    lineItems: draft.lines.map((line) => ({
      description: line.description.trim(),
      quantity: line.quantity,
      unitPrice: line.unitPrice,
    })),
    taxRate: draft.taxRate.trim() === '' ? null : draft.taxRate,
    discountAmount: draft.discount.trim() === '' ? null : draft.discount,
  }
}

/**
 * The editor form is its own component so its draft state is initialised from
 * the record it was mounted with (a new draft or a specific invoice). That is
 * why no effect has to copy a fetched invoice into local state after render.
 */
function InvoiceForm({
  initial,
  eyebrow,
  title,
  submitLabel,
  customerOptions,
  organizationName,
  saving,
  sending,
  onSave,
  onSaveAndSend,
  onBack,
}: {
  initial: DraftState
  eyebrow: string
  title: string
  submitLabel: string
  customerOptions: { id: string; name: string; email: string | null }[]
  organizationName: string
  saving: boolean
  sending: boolean
  onSave: (input: InvoiceDraftInput) => void
  onSaveAndSend: (input: InvoiceDraftInput) => void
  onBack: () => void
}) {
  const { m, currency } = useI18n()
  const [draft, setDraft] = useState<DraftState>(initial)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [dirty, setDirty] = useState(false)
  const [confirmDiscard, setConfirmDiscard] = useState(false)
  useDocumentTitle(title)

  const estimate = useMemo(() => {
    const subtotal = draft.lines.reduce((sum, line) => sum + (Number(line.quantity) || 0) * (Number(line.unitPrice) || 0), 0)
    const rate = Number(draft.taxRate) || 0
    const discount = Number(draft.discount) || 0
    return { subtotal, tax: (subtotal * rate) / 100, total: subtotal + (subtotal * rate) / 100 - discount }
  }, [draft])

  const selectedCustomer = customerOptions.find((customer) => customer.id === draft.customerId)

  function change(patch: Partial<DraftState>) {
    setDraft((current) => ({ ...current, ...patch }))
    setDirty(true)
  }

  function updateLine(key: string, patch: Partial<LineDraft>) {
    setDraft((current) => ({
      ...current,
      lines: current.lines.map((line) => (line.key === key ? { ...line, ...patch } : line)),
    }))
    setDirty(true)
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const next = validate(draft, m)
    setErrors(next)
    if (Object.keys(next).length === 0) onSave(toInput(draft))
  }

  function submitAndSend() {
    const next = validate(draft, m)
    setErrors(next)
    if (Object.keys(next).length === 0) onSaveAndSend(toInput(draft))
  }

  return (
    <div className="editor-page">
      <div className="detail-toolbar">
        <button type="button" className="button button-ghost button-sm" onClick={() => (dirty ? setConfirmDiscard(true) : onBack())}>
          <Icon name="arrow" size={16} /> {m.invoices.editor.back}
        </button>
      </div>

      <section className="page-intro">
        <div>
          <p className="eyebrow">{eyebrow}</p>
          <h1>{title}</h1>
          <p>{m.invoices.editor.subtitle}</p>
        </div>
      </section>

      <form className="editor-layout" onSubmit={submit} noValidate>
        <section className="panel editor-form" aria-labelledby="editor-form-title">
          <h2 id="editor-form-title">{m.invoices.editor.lineItems}</h2>

          <Field label={m.invoices.editor.customer} required error={errors.customerId}>
            {(field) => (
              <select {...field} value={draft.customerId} onChange={(event) => change({ customerId: event.target.value })}>
                <option value="">{m.invoices.editor.selectCustomer}</option>
                {customerOptions.map((customer) => (
                  <option key={customer.id} value={customer.id}>{customer.name}</option>
                ))}
              </select>
            )}
          </Field>

          <div className="field-row">
            <Field label={m.invoices.editor.issueDate} required>
              {(field) => (
                <input
                  {...field}
                  type="date"
                  value={draft.issueDate}
                  onChange={(event) => change({ issueDate: event.target.value, dueDate: plusDays(event.target.value, 15) })}
                />
              )}
            </Field>
            <Field label={m.invoices.editor.dueDate} required error={errors.dueDate}>
              {(field) => (
                <input
                  {...field}
                  type="date"
                  value={draft.dueDate}
                  min={draft.issueDate}
                  onChange={(event) => change({ dueDate: event.target.value })}
                />
              )}
            </Field>
          </div>

          <div className="line-list">
            {draft.lines.map((line, index) => (
              <div className="line-row" key={line.key}>
                <Field
                  label={index === 0 ? m.invoices.editor.description : `${m.invoices.editor.description} ${index + 1}`}
                  required
                  error={errors[`line-${index}-description`]}
                >
                  {(field) => (
                    <input
                      {...field}
                      value={line.description}
                      placeholder={m.invoices.editor.emptyLine}
                      onChange={(event) => updateLine(line.key, { description: event.target.value })}
                    />
                  )}
                </Field>
                <Field label={m.invoices.editor.quantity} error={errors[`line-${index}-quantity`]}>
                  {(field) => (
                    <input {...field} inputMode="decimal" value={line.quantity} onChange={(event) => updateLine(line.key, { quantity: event.target.value })} />
                  )}
                </Field>
                <Field label={m.invoices.editor.unitPrice} error={errors[`line-${index}-unitPrice`]}>
                  {(field) => (
                    <input {...field} inputMode="decimal" value={line.unitPrice} onChange={(event) => updateLine(line.key, { unitPrice: event.target.value })} />
                  )}
                </Field>
                <span className="line-total" aria-label={m.invoices.editor.lineTotal}>
                  {currency((Number(line.quantity) || 0) * (Number(line.unitPrice) || 0))}
                </span>
                <button
                  type="button"
                  className="icon-button"
                  aria-label={m.invoices.editor.removeLine(index + 1)}
                  disabled={draft.lines.length === 1}
                  onClick={() => change({ lines: draft.lines.filter((item) => item.key !== line.key) })}
                >
                  <Icon name="close" size={16} />
                </button>
              </div>
            ))}
          </div>
          <button
            type="button"
            className="button button-ghost button-sm add-line"
            onClick={() => change({ lines: [...draft.lines, { key: `line-${Date.now()}`, description: '', quantity: '1', unitPrice: '0' }] })}
          >
            <Icon name="plus" size={16} /> {m.invoices.editor.addLine}
          </button>

          <div className="field-row">
            <Field label={m.invoices.editor.taxRate}>
              {(field) => (
                <input {...field} inputMode="decimal" value={draft.taxRate} onChange={(event) => change({ taxRate: event.target.value })} />
              )}
            </Field>
            <Field label={m.invoices.editor.discount}>
              {(field) => (
                <input {...field} inputMode="decimal" value={draft.discount} onChange={(event) => change({ discount: event.target.value })} />
              )}
            </Field>
          </div>

          <div className="editor-actions">
            <button type="submit" className="button button-secondary" disabled={saving} aria-busy={saving || undefined}>
              {saving ? m.invoices.editor.saving : submitLabel}
            </button>
            <button type="button" className="button button-primary" onClick={submitAndSend} disabled={saving || sending}>
              {sending ? m.invoices.editor.sending : m.invoices.editor.send}
            </button>
          </div>
          <p className="field-hint">{m.invoices.editor.estimate}</p>
        </section>

        <aside className="panel preview-pane" aria-labelledby="preview-title">
          <div className="preview-heading">
            <h2 id="preview-title">{m.invoices.editor.preview}</h2>
            <span className="preview-note">{m.invoices.editor.previewNote}</span>
          </div>
          <div className="preview-paper">
            <div className="preview-brand">
              <img src="/Keelson.svg" alt="" width={22} height={22} />
              <span>{organizationName}</span>
            </div>
            <div className="preview-meta">
              <div>
                <span>{m.invoices.editor.billTo}</span>
                <strong>{selectedCustomer?.name ?? m.invoices.editor.selectCustomer}</strong>
                {selectedCustomer?.email && <small>{selectedCustomer.email}</small>}
              </div>
              <div className="preview-dates">
                <span>{m.invoices.editor.issueDate}: {draft.issueDate}</span>
                <span>{m.invoices.editor.dueDate}: {draft.dueDate}</span>
              </div>
            </div>
            <table className="preview-lines">
              <caption className="visually-hidden">{m.invoices.editor.lineItems}</caption>
              <thead>
                <tr>
                  <th scope="col">{m.invoices.editor.description}</th>
                  <th scope="col">{m.invoices.editor.quantity}</th>
                  <th scope="col">{m.invoices.editor.unitPrice}</th>
                  <th scope="col">{m.invoices.editor.lineTotal}</th>
                </tr>
              </thead>
              <tbody>
                {draft.lines.map((line) => (
                  <tr key={line.key}>
                    <td>{line.description || '—'}</td>
                    <td>{line.quantity}</td>
                    <td>{currency(Number(line.unitPrice) || 0)}</td>
                    <td>{currency((Number(line.quantity) || 0) * (Number(line.unitPrice) || 0))}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <dl className="preview-totals">
              <div><dt>{m.invoices.editor.subtotal}</dt><dd>{currency(estimate.subtotal)}</dd></div>
              <div><dt>{m.invoices.editor.tax}</dt><dd>{currency(estimate.tax)}</dd></div>
              <div className="preview-grand"><dt>{m.invoices.editor.total}</dt><dd>{currency(estimate.total)}</dd></div>
            </dl>
          </div>
        </aside>
      </form>

      <ConfirmDialog
        open={confirmDiscard}
        title={m.invoices.editor.discardTitle}
        body={m.invoices.editor.discardBody}
        confirmLabel={m.invoices.editor.discard}
        onClose={() => setConfirmDiscard(false)}
        onConfirm={() => { setConfirmDiscard(false); onBack() }}
      />
    </div>
  )
}

export default function InvoiceEditor() {
  const { m } = useI18n()
  const { membership } = useWorkspace()
  const { invoiceId } = useParams<{ invoiceId: string }>()
  const navigate = useNavigate()
  const toast = useToast()
  const orgId = membership.id
  const isEditing = invoiceId !== undefined

  const customersLoad = useCallback(
    (signal: AbortSignal) => listCustomers(orgId, { limit: 100, signal }),
    [orgId],
  )
  const customers = useApiResource(`customers:${orgId}`, customersLoad)

  const invoiceLoad = useCallback(
    (signal: AbortSignal) => readInvoice(orgId, invoiceId ?? '', signal),
    [orgId, invoiceId],
  )
  const invoice = useApiResource(`invoice:${orgId}:${invoiceId}`, invoiceLoad, { enabled: isEditing })

  const save = useMutation(useCallback(
    async (input: InvoiceDraftInput) => (isEditing ? updateInvoiceDraft(orgId, invoiceId ?? '', input) : createInvoiceDraft(orgId, input)),
    [isEditing, orgId, invoiceId],
  ))
  const send = useMutation(useCallback((id: string) => sendInvoice(orgId, id), [orgId]))

  const back = useCallback(() => { void navigate(`/o/${orgId}/invoices`) }, [navigate, orgId])

  // The server rejects the write anyway; the screen does not pretend otherwise.
  if (!canWriteInvoiceResources(membership.role)) {
    return (
      <EmptyState
        icon="shield"
        title={m.invoices.editor.forbiddenTitle}
        body={m.invoices.editor.forbiddenBody}
        action={{ label: m.invoices.detail.back, onClick: back }}
      />
    )
  }

  async function handleSave(input: InvoiceDraftInput) {
    const outcome = await save.run(input)
    if (!outcome.ok) return
    invalidateResource(`invoices:${orgId}`)
    toast.success(m.invoices.editor.savedServer(outcome.data.number))
    void navigate(`/o/${orgId}/invoices/${outcome.data.id}`)
  }

  async function handleSaveAndSend(input: InvoiceDraftInput) {
    const saved = await save.run(input)
    if (!saved.ok) return
    invalidateResource(`invoices:${orgId}`)
    const sent = await send.run(saved.data.id)
    if (sent.ok) toast.success(m.invoices.editor.sentServer(sent.data.number))
    void navigate(`/o/${orgId}/invoices/${saved.data.id}`)
  }

  if (isEditing && invoice.state.status === 'loading') {
    return <Skeleton variant="text" count={6} label={m.invoices.editor.loading} />
  }
  if (isEditing && invoice.state.status === 'error') {
    return (
      <ErrorState
        title={m.invoices.detail.loadFailed}
        message={presentError(m, invoice.state.error)}
        retryLabel={m.common.retry}
        onRetry={invoice.reload}
      />
    )
  }
  if (isEditing && invoice.state.status === 'ready' && invoice.state.data.status !== 'draft') {
    const current = invoice.state.data
    return (
      <EmptyState
        icon="file"
        title={m.invoices.editor.draftOnly}
        body={current.number}
        action={{ label: m.invoices.detail.back, onClick: () => { void navigate(`/o/${orgId}/invoices/${current.id}`) } }}
      />
    )
  }

  if (customers.state.status === 'loading' || (isEditing && invoice.state.status !== 'ready')) {
    return <Skeleton variant="text" count={6} label={m.common.loading} />
  }
  if (customers.state.status === 'error') {
    return (
      <ErrorState
        title={m.customers.loadFailed}
        message={presentError(m, customers.state.error)}
        retryLabel={m.common.retry}
        onRetry={customers.reload}
      />
    )
  }

  const customerOptions = customers.state.data.items
  if (customerOptions.length === 0) {
    return (
      <EmptyState
        icon="users"
        title={m.invoices.editor.noCustomers}
        body={m.invoices.editor.noCustomersHint}
        action={{ label: m.customers.add, onClick: () => { void navigate(`/o/${orgId}/customers`) } }}
      />
    )
  }

  const source = isEditing && invoice.state.status === 'ready' ? invoice.state.data : null

  return (
    <InvoiceForm
      key={source?.id ?? 'new'}
      initial={source === null ? blankDraft() : fromInvoice(source)}
      eyebrow={source === null ? m.page.newInvoice : m.page.invoices}
      title={source === null ? m.invoices.editor.newTitle : m.invoices.editor.editTitle(source.number)}
      submitLabel={m.invoices.editor.saveDraft}
      customerOptions={customerOptions}
      organizationName={membership.name}
      saving={save.pending}
      sending={send.pending}
      onSave={(input) => { void handleSave(input) }}
      onSaveAndSend={(input) => { void handleSaveAndSend(input) }}
      onBack={back}
    />
  )
}
