import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { listCustomers } from '../api/customers'
import { createInvoiceDraft, readInvoice, sendInvoice, updateInvoiceDraft } from '../api/invoices'
import { canWriteInvoiceResources } from '../api/organizations'
import { isConflict, presentError } from '../api/presentError'
import type { CustomerSummary, Invoice, InvoiceDraftInput, OrganizationRole } from '../api/types'
import { useApiResource } from '../api/useApiResource'
import { EmptyState } from '../components/EmptyState'
import { Icon } from '../components/Icon'
import { useI18n } from '../i18n/I18nContext'

type Line = { key: string; description: string; quantity: string; unitPrice: string }

type FormState = {
  customerId: string
  issueDate: string
  dueDate: string
  lines: Line[]
  taxRate: string
  discount: string
}

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function inDays(days: number): string {
  const date = new Date()
  date.setDate(date.getDate() + days)
  return date.toISOString().slice(0, 10)
}

function newLine(): Line {
  return { key: `line-${Date.now()}-${Math.random().toString(16).slice(2)}`, description: '', quantity: '1', unitPrice: '0.000' }
}

function emptyForm(): FormState {
  return { customerId: '', issueDate: today(), dueDate: inDays(14), lines: [newLine()], taxRate: '', discount: '' }
}

function formFromInvoice(invoice: Invoice): FormState {
  return {
    customerId: invoice.customerId,
    issueDate: invoice.issueDate,
    dueDate: invoice.dueDate,
    lines: invoice.lineItems.map((line, index) => ({
      key: `saved-${index}`,
      description: line.description,
      quantity: line.quantity,
      unitPrice: line.unitPrice,
    })),
    taxRate: invoice.taxRate ?? '',
    discount: invoice.discountAmount ?? '',
  }
}

/** Normalizes user input to the decimal string format the API validates. */
function decimal(value: string): string {
  return value.trim().replace(',', '.')
}

/** Local preview only; the server recalculates on save. */
function toNumber(value: string): number {
  const parsed = Number.parseFloat(decimal(value))
  return Number.isFinite(parsed) && parsed > 0 ? parsed : 0
}

function toPayload(form: FormState): InvoiceDraftInput {
  return {
    customerId: form.customerId,
    issueDate: form.issueDate,
    dueDate: form.dueDate,
    currency: 'TND',
    lineItems: form.lines.map((line) => ({
      description: line.description.trim(),
      quantity: decimal(line.quantity),
      unitPrice: decimal(line.unitPrice),
    })),
    taxRate: form.taxRate.trim() === '' ? null : decimal(form.taxRate),
    discountAmount: form.discount.trim() === '' ? null : decimal(form.discount),
  }
}

export function InvoiceEditorScreen({
  organizationId,
  role,
  invoiceId,
  onBack,
}: {
  organizationId: string
  role: OrganizationRole
  invoiceId: string | null
  onBack: () => void
}) {
  const { m, currency, date } = useI18n()
  const copy = m.workspace.editor
  const canWrite = canWriteInvoiceResources(role)

  const loadEditor = useCallback(async (signal: AbortSignal) => {
    const [customers, invoice] = await Promise.all([
      listCustomers(organizationId, { signal, limit: 100 }),
      invoiceId === null ? Promise.resolve(null) : readInvoice(organizationId, invoiceId, signal),
    ])
    return { customers: customers.items, invoice }
  }, [invoiceId, organizationId])

  const { state, reload } = useApiResource(loadEditor)

  const [form, setForm] = useState<FormState>(emptyForm)
  const [savedInvoice, setSavedInvoice] = useState<Invoice | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState<'save' | 'send' | null>(null)
  const initializedFor = useRef<string | null>(null)

  // The form is seeded once per loaded draft, so a refetch can never overwrite
  // edits the user is making. A save updates the form from the server response
  // directly instead of waiting for this effect.
  useEffect(() => {
    if (state.status !== 'ready') return
    const invoice = state.data.invoice
    const key = invoice?.id ?? 'new'
    if (initializedFor.current === key) return
    initializedFor.current = key
    setSavedInvoice(invoice)
    setForm(invoice ? formFromInvoice(invoice) : emptyForm())
  }, [state])

  const customers: CustomerSummary[] = state.status === 'ready' ? state.data.customers : []
  const locked = savedInvoice !== null && savedInvoice.status !== 'draft'
  const readOnly = !canWrite || locked

  const customer = customers.find((item) => item.id === form.customerId) ?? null

  const estimate = useMemo(() => {
    const subtotal = form.lines.reduce((sum, line) => sum + toNumber(line.quantity) * toNumber(line.unitPrice), 0)
    const discount = toNumber(form.discount)
    const tax = Math.max(0, subtotal - discount) * (toNumber(form.taxRate) / 100)
    return { subtotal, tax: Math.round(tax * 1000) / 1000, total: Math.max(0, subtotal - discount) + Math.round(tax * 1000) / 1000 }
  }, [form.lines, form.taxRate, form.discount])

  function updateLine(key: string, patch: Partial<Line>) {
    setForm((current) => ({ ...current, lines: current.lines.map((line) => line.key === key ? { ...line, ...patch } : line) }))
  }

  function validate(): string | null {
    if (form.customerId === '') return copy.selectCustomer
    if (form.issueDate === '' || form.dueDate === '') return copy.saveFailed
    if (form.dueDate < form.issueDate) return copy.saveFailed
    if (form.lines.length === 0 || form.lines.every((line) => line.description.trim() === '')) return copy.saveFailed
    return null
  }

  async function save(event?: FormEvent<HTMLFormElement>) {
    event?.preventDefault()
    if (pending !== null || readOnly) return
    const problem = validate()
    if (problem !== null) {
      setError(problem)
      setNotice(null)
      return
    }
    setPending('save')
    setError(null)
    setNotice(null)
    try {
      const payload = toPayload(form)
      const invoice = savedInvoice === null
        ? await createInvoiceDraft(organizationId, payload)
        : await updateInvoiceDraft(organizationId, savedInvoice.id, payload)
      setSavedInvoice(invoice)
      setForm(formFromInvoice(invoice))
      initializedFor.current = invoice.id
      setNotice(copy.savedServer(invoice.number))
    } catch (failure) {
      // Totals, tax and dates are validated by the server: show its message and
      // keep the form editable rather than pretending the draft was stored.
      setError(isConflict(failure) ? copy.draftOnly : presentError(m, failure))
      if (isConflict(failure)) reload()
    } finally {
      setPending(null)
    }
  }

  async function send() {
    if (pending !== null || savedInvoice === null || locked) return
    setPending('send')
    setError(null)
    setNotice(null)
    try {
      const invoice = await sendInvoice(organizationId, savedInvoice.id)
      setSavedInvoice(invoice)
      setNotice(copy.sentServer(invoice.number))
    } catch (failure) {
      setError(isConflict(failure) ? copy.draftOnly : presentError(m, failure))
      if (isConflict(failure)) reload()
    } finally {
      setPending(null)
    }
  }

  if (state.status === 'loading') {
    return (
      <section className="editor-screen">
        <p className="mock-note" role="status">{copy.loading}</p>
      </section>
    )
  }

  if (state.status === 'error') {
    return (
      <section className="editor-screen">
        <button type="button" className="button button-ghost" onClick={onBack}><Icon name="chevron" size={15} /> {copy.back}</button>
        <EmptyState icon="file" title={copy.saveFailed} body={presentError(m, state.error)} action={{ label: m.api.reload, onClick: reload }} />
      </section>
    )
  }

  if (customers.length === 0) {
    return (
      <section className="editor-screen">
        <button type="button" className="button button-ghost" onClick={onBack}><Icon name="chevron" size={15} /> {copy.back}</button>
        <EmptyState icon="users" title={copy.noCustomers} body={copy.noCustomersHint} />
      </section>
    )
  }

  // A sent or paid invoice is shown from the stored record; a draft previews the
  // numbers currently in the form and is labelled as an estimate.
  const previewLines = savedInvoice !== null && savedInvoice.status !== 'draft' ? savedInvoice : null
  const previewRows = previewLines === null
    ? form.lines.map((line) => ({
        key: line.key,
        description: line.description,
        quantity: line.quantity,
        unitPrice: line.unitPrice,
        total: String(toNumber(line.quantity) * toNumber(line.unitPrice)),
      }))
    : previewLines.lineItems.map((line, index) => ({
        key: `stored-${index}`,
        description: line.description,
        quantity: line.quantity,
        unitPrice: line.unitPrice,
        total: line.lineTotal,
      }))

  return (
    <section className="editor-screen">
      <div className="panel-heading workspace-heading">
        <div>
          <button type="button" className="button button-ghost" onClick={onBack}>
            <Icon name="chevron" size={15} /> {copy.back}
          </button>
          <h1>{savedInvoice === null ? copy.newTitle : copy.editTitle(savedInvoice.number)}</h1>
          <p>{copy.subtitle}</p>
        </div>
      </div>

      {notice && (
        <div className="inline-feedback" role="status">
          <Icon name="check" size={16} />
          <span>{notice}</span>
          <button type="button" aria-label={m.common.dismiss} onClick={() => setNotice(null)}><Icon name="close" size={15} /></button>
        </div>
      )}
      {error && <div className="inline-feedback" role="alert"><span>{error}</span></div>}
      {readOnly && <div className="inline-feedback" role="status"><span>{locked ? copy.draftOnly : m.api.forbidden}</span></div>}

      <div className="editor-layout">
        <form className="panel nested-panel editor-form" onSubmit={(event) => void save(event)} noValidate>
          <label className="field">
            <span>{copy.customer}</span>
            <select
              value={form.customerId}
              disabled={readOnly}
              onChange={(event) => setForm((current) => ({ ...current, customerId: event.target.value }))}
            >
              <option value="">{copy.selectCustomer}</option>
              {customers.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
            </select>
          </label>

          <div className="field-row">
            <label className="field">
              <span>{copy.issueDate}</span>
              <input type="date" value={form.issueDate} disabled={readOnly} onChange={(event) => setForm((current) => ({ ...current, issueDate: event.target.value }))} />
            </label>
            <label className="field">
              <span>{copy.dueDate}</span>
              <input type="date" value={form.dueDate} disabled={readOnly} onChange={(event) => setForm((current) => ({ ...current, dueDate: event.target.value }))} />
            </label>
          </div>

          <h2>{copy.lineItems}</h2>
          <div className="line-list">
            {form.lines.map((line, index) => (
              <div className="line-row" key={line.key}>
                <label className="field">
                  <span className="visually-hidden">{`${copy.description} ${index + 1}`}</span>
                  <input
                    value={line.description}
                    placeholder={copy.emptyLine}
                    aria-label={`${copy.description} ${index + 1}`}
                    disabled={readOnly}
                    onChange={(event) => updateLine(line.key, { description: event.target.value })}
                  />
                </label>
                <label className="field line-qty">
                  <span className="visually-hidden">{copy.quantity}</span>
                  <input inputMode="decimal" value={line.quantity} aria-label={`${copy.quantity} ${index + 1}`} disabled={readOnly} onChange={(event) => updateLine(line.key, { quantity: event.target.value })} />
                </label>
                <label className="field line-price">
                  <span className="visually-hidden">{copy.unitPrice}</span>
                  <input inputMode="decimal" value={line.unitPrice} aria-label={`${copy.unitPrice} ${index + 1}`} disabled={readOnly} onChange={(event) => updateLine(line.key, { unitPrice: event.target.value })} />
                </label>
                <span className="line-total">{currency(toNumber(line.quantity) * toNumber(line.unitPrice))}</span>
                <button type="button" className="icon-button" aria-label={copy.removeLine(index + 1)} disabled={readOnly || form.lines.length === 1} onClick={() => setForm((current) => ({ ...current, lines: current.lines.filter((item) => item.key !== line.key) }))}>
                  <Icon name="close" size={16} />
                </button>
              </div>
            ))}
          </div>
          <button type="button" className="button button-ghost" disabled={readOnly} onClick={() => setForm((current) => ({ ...current, lines: [...current.lines, newLine()] }))}>
            <Icon name="plus" size={16} /> {copy.addLine}
          </button>

          <div className="field-row">
            <label className="field">
              <span>{copy.taxRate}</span>
              <input inputMode="decimal" value={form.taxRate} disabled={readOnly} onChange={(event) => setForm((current) => ({ ...current, taxRate: event.target.value }))} />
            </label>
            <label className="field">
              <span>{copy.discount}</span>
              <input inputMode="decimal" value={form.discount} disabled={readOnly} onChange={(event) => setForm((current) => ({ ...current, discount: event.target.value }))} />
            </label>
          </div>
        </form>

        <section className="panel nested-panel preview-pane" aria-label={copy.preview}>
          <div className="preview-heading">
            <span>{copy.preview}</span>
            <span className="mock-note">{previewLines ? copy.readonlyTotals : copy.estimate}</span>
          </div>
          <div className="preview-paper">
            <div className="preview-brand"><img src="/Keelson.svg" alt="" /><strong>keelson</strong></div>
            <div className="preview-meta">
              <div>
                <span>{copy.billTo}</span>
                <strong>{previewLines?.customer.name ?? customer?.name ?? copy.selectCustomer}</strong>
                {previewLines?.customer.billingAddress && (
                  <small>{[previewLines.customer.billingAddress.street, previewLines.customer.billingAddress.city].filter(Boolean).join(', ')}</small>
                )}
                <small>{previewLines?.customer.email ?? customer?.email ?? ''}</small>
              </div>
              <div className="preview-dates">
                <span>{copy.issueDate}: {date(previewLines?.issueDate ?? form.issueDate)}</span>
                <span>{copy.dueDate}: {date(previewLines?.dueDate ?? form.dueDate)}</span>
              </div>
            </div>
            <div className="table-wrap preview-table-wrap">
              <table>
                <thead><tr><th>{copy.description}</th><th>{copy.quantity}</th><th>{copy.unitPrice}</th><th>{copy.lineTotal}</th></tr></thead>
                <tbody>
                  {previewRows.map((row) => (
                    <tr key={row.key}>
                      <td>{row.description || '—'}</td>
                      <td className="amount-cell">{row.quantity}</td>
                      <td className="amount-cell">{currency(toNumber(row.unitPrice))}</td>
                      <td className="amount-cell">{currency(toNumber(row.total))}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <dl className="preview-totals">
              <div><dt>{copy.subtotal}</dt><dd>{currency(previewLines ? toNumber(previewLines.subtotal) : estimate.subtotal)}</dd></div>
              <div><dt>{copy.tax}</dt><dd>{currency(previewLines ? toNumber(previewLines.taxAmount) : estimate.tax)}</dd></div>
              <div className="preview-grand"><dt>{copy.total}</dt><dd>{currency(previewLines ? toNumber(previewLines.total) : estimate.total)}</dd></div>
            </dl>
          </div>
        </section>
      </div>

      <div className="sticky-bar">
        <button type="button" className="button button-secondary" disabled={readOnly || pending !== null} onClick={() => void save()}>
          {pending === 'save' ? copy.saving : copy.saveDraft}
        </button>
        <button type="button" className="button button-primary" disabled={readOnly || pending !== null || savedInvoice === null} onClick={() => void send()}>
          {pending === 'send' ? copy.sending : copy.send}
        </button>
        {savedInvoice !== null && <span className="mock-note">{copy.savedServer(savedInvoice.number)}</span>}
      </div>
    </section>
  )
}
