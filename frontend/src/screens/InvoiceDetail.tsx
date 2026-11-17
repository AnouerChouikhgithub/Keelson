import { useCallback, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { listAuditEvents } from '../api/audit'
import { deleteInvoiceDraft, markInvoicePaid, readInvoice, sendInvoice } from '../api/invoices'
import { canDeleteInvoiceResources, canWriteInvoiceResources } from '../api/organizations'
import { presentError } from '../api/presentError'
import { invalidateResource, useApiResource } from '../api/useApiResource'
import { useMutation } from '../api/useMutation'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { DataTable } from '../components/DataTable'
import { EmptyState } from '../components/EmptyState'
import { ErrorState } from '../components/ErrorState'
import { Icon } from '../components/Icon'
import { Skeleton } from '../components/Skeleton'
import { StatusBadge } from '../components/StatusBadge'
import { useToast } from '../components/ToastContext'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import { useWorkspace } from '../app/WorkspaceContext'
import './invoices.css'

export default function InvoiceDetail() {
  const { m, currency, date, dateTime } = useI18n()
  const { membership } = useWorkspace()
  const { invoiceId } = useParams<{ invoiceId: string }>()
  const navigate = useNavigate()
  const toast = useToast()
  const orgId = membership.id
  const [confirmDelete, setConfirmDelete] = useState(false)

  const load = useCallback(
    (signal: AbortSignal) => readInvoice(orgId, invoiceId ?? '', signal),
    [orgId, invoiceId],
  )
  const resource = useApiResource(`invoice:${orgId}:${invoiceId}`, load, { enabled: invoiceId !== undefined })
  const auditLoad = useCallback(
    (signal: AbortSignal) => (invoiceId === undefined ? Promise.resolve([]) : listAuditEvents(orgId, { invoiceId, limit: 25, signal })),
    [orgId, invoiceId],
  )
  const audit = useApiResource(`audit:${orgId}:${invoiceId}`, auditLoad, { enabled: invoiceId !== undefined })
  useDocumentTitle(m.page.invoice)

  const invoice = resource.state.status === 'ready' ? resource.state.data : null

  const send = useMutation(useCallback(() => sendInvoice(orgId, invoiceId ?? ''), [orgId, invoiceId]))
  const markPaid = useMutation(useCallback(() => markInvoicePaid(orgId, invoiceId ?? ''), [orgId, invoiceId]))
  const remove = useMutation(useCallback(() => deleteInvoiceDraft(orgId, invoiceId ?? ''), [orgId, invoiceId]))

  async function handleSend() {
    const outcome = await send.run()
    if (!outcome.ok || !invoice) return
    toast.success(m.invoices.detail.sent(invoice.number))
    invalidateResource(`invoices:${orgId}`)
    resource.reload()
    audit.reload()
  }

  async function handleMarkPaid() {
    const outcome = await markPaid.run()
    if (!outcome.ok) return
    // Reload the detail and the audit trail from the server: the mutation returns
    // the updated invoice, but the badge, the totals and the new audit row all
    // come from the resource, and a stale badge is exactly what a reader notices.
    invalidateResource(`invoices:${orgId}`)
    invalidateResource(`dashboard:${orgId}`)
    resource.reload()
    audit.reload()
    toast.success(m.invoices.detail.markedPaid(outcome.data.number))
  }

  async function handleDelete() {
    const outcome = await remove.run()
    if (!outcome.ok) return
    setConfirmDelete(false)
    if (invoice) toast.success(m.invoices.detail.deleted(invoice.number))
    invalidateResource(`invoices:${orgId}`)
    void navigate(`/o/${orgId}/invoices`)
  }

  if (resource.state.status === 'loading') {
    return <Skeleton variant="text" count={6} label={m.invoices.detail.loadFailed} />
  }

  if (resource.state.status === 'error') {
    const notFound = presentError(m, resource.state.error) === m.api.notFound
    return (
      <ErrorState
        title={notFound ? m.invoices.detail.notFoundTitle : m.invoices.detail.loadFailed}
        message={notFound ? m.invoices.detail.notFoundBody : presentError(m, resource.state.error)}
        retryLabel={m.common.retry}
        onRetry={resource.reload}
      />
    )
  }

  const data = resource.state.data
  const isDraft = data.status === 'draft'
  const canWrite = canWriteInvoiceResources(membership.role)
  const canDelete = canDeleteInvoiceResources(membership.role)

  return (
    <div className="invoice-detail">
      <div className="detail-toolbar no-print">
        <Link className="button button-ghost button-sm" to={`/o/${orgId}/invoices`}>
          <Icon name="arrow" size={16} /> {m.invoices.detail.back}
        </Link>
        <div className="detail-actions">
          {isDraft && canWrite && (
            <Link className="button button-secondary button-sm" to={`/o/${orgId}/invoices/${data.id}/edit`}>
              <Icon name="file" size={16} /> {m.invoices.detail.editDraft}
            </Link>
          )}
          {isDraft && canWrite && (
            <button type="button" className="button button-primary button-sm" onClick={() => { void handleSend() }} disabled={send.pending} aria-busy={send.pending || undefined}>
              {send.pending ? m.invoices.detail.sending : m.invoices.detail.send}
            </button>
          )}
          {!isDraft && data.status !== 'paid' && canWrite && (
            <button type="button" className="button button-primary button-sm" onClick={() => { void handleMarkPaid() }} disabled={markPaid.pending} aria-busy={markPaid.pending || undefined}>
              {markPaid.pending ? m.invoices.detail.markingPaid : m.invoices.detail.markPaid}
            </button>
          )}
          <button type="button" className="button button-secondary button-sm" onClick={() => window.print()}>
            <Icon name="file" size={16} /> {m.invoices.detail.print}
          </button>
          {isDraft && canDelete && (
            <button type="button" className="button button-danger button-sm" onClick={() => setConfirmDelete(true)}>
              {m.invoices.detail.deleteDraft}
            </button>
          )}
        </div>
      </div>

      <article className="invoice-document" aria-labelledby="invoice-document-title">
        <header className="invoice-document-head">
          <div className="invoice-brand">
            <img src="/Keelson.svg" alt="" width={28} height={28} />
            <div>
              <strong>keelson</strong>
              <span>{membership.name}</span>
            </div>
          </div>
          <div className="invoice-document-meta">
            <h1 id="invoice-document-title">{m.invoices.detail.title(data.number)}</h1>
            <StatusBadge status={data.status} />
          </div>
        </header>

        <dl className="invoice-meta-grid">
          <div>
            <dt>{m.invoices.detail.billTo}</dt>
            <dd>
              <strong>{data.customer.name}</strong>
              {data.customer.email && <span>{data.customer.email}</span>}
              {data.customer.phone && <span>{data.customer.phone}</span>}
              {data.customer.billingAddress && (
                <span>
                  {data.customer.billingAddress.street}, {data.customer.billingAddress.postalCode}{' '}
                  {data.customer.billingAddress.city}, {data.customer.billingAddress.countryCode}
                </span>
              )}
            </dd>
          </div>
          <div>
            <dt>{m.invoices.detail.issueDate}</dt>
            <dd>{date(data.issueDate)}</dd>
          </div>
          <div>
            <dt>{m.invoices.detail.dueDate}</dt>
            <dd>{date(data.dueDate)}</dd>
          </div>
          <div>
            <dt>{m.invoices.detail.number}</dt>
            <dd>{data.number}</dd>
          </div>
        </dl>

        <DataTable caption={m.invoices.detail.linesCaption}>
          <thead>
            <tr>
              <th scope="col">{m.invoices.detail.lineDescription}</th>
              <th scope="col" className="cell-end">{m.invoices.detail.lineQuantity}</th>
              <th scope="col" className="cell-end">{m.invoices.detail.lineUnit}</th>
              <th scope="col" className="cell-end">{m.invoices.detail.lineTotal}</th>
            </tr>
          </thead>
          <tbody>
            {data.lineItems.map((line, index) => (
              <tr key={`${line.description}-${index}`}>
                <td>{line.description}</td>
                <td className="cell-end cell-amount">{line.quantity}</td>
                <td className="cell-end cell-amount">{currency(Number(line.unitPrice))}</td>
                <td className="cell-end cell-amount">{currency(Number(line.lineTotal))}</td>
              </tr>
            ))}
          </tbody>
        </DataTable>

        <dl className="invoice-totals">
          <div>
            <dt>{m.invoices.detail.subtotal}</dt>
            <dd>{currency(Number(data.subtotal))}</dd>
          </div>
          {data.taxRate !== null && (
            <div>
              <dt>{m.invoices.detail.tax(String(Number(data.taxRate)))}</dt>
              <dd>{currency(Number(data.taxAmount))}</dd>
            </div>
          )}
          {data.discountAmount !== null && Number(data.discountAmount) > 0 && (
            <div>
              <dt>{m.invoices.detail.discount}</dt>
              <dd>−{currency(Number(data.discountAmount))}</dd>
            </div>
          )}
          <div className="invoice-grand-total">
            <dt>{m.invoices.detail.total}</dt>
            <dd>{currency(Number(data.total))}</dd>
          </div>
        </dl>

        <p className="invoice-print-note no-print">{m.invoices.detail.printHint}</p>
      </article>

      <section className="panel audit-panel no-print" aria-labelledby="invoice-audit-title">
        <div className="panel-heading">
          <div>
            <h2 id="invoice-audit-title">{m.invoices.detail.auditTitle}</h2>
            <p>{m.invoices.detail.auditSubtitle}</p>
          </div>
        </div>
        {audit.state.status === 'loading' && <Skeleton variant="list" count={3} label={m.common.loading} />}
        {audit.state.status === 'error' && (
          <ErrorState
            title={m.invoices.detail.auditFailed}
            message={presentError(m, audit.state.error)}
            retryLabel={m.common.retry}
            onRetry={audit.reload}
          />
        )}
        {audit.state.status === 'ready' && (
          audit.state.data.length === 0
            ? <EmptyState icon="clock" title={m.invoices.detail.auditEmpty} />
            : (
              <DataTable caption={m.invoices.audit.caption}>
                <thead>
                  <tr>
                    <th scope="col">{m.invoices.audit.action}</th>
                    <th scope="col">{m.invoices.audit.at}</th>
                  </tr>
                </thead>
                <tbody>
                  {audit.state.data.map((event) => (
                    <tr key={event.id}>
                      <td className="cell-strong">{event.action}</td>
                      <td>{dateTime(event.createdAt)}</td>
                    </tr>
                  ))}
                </tbody>
              </DataTable>
            )
        )}
      </section>

      <ConfirmDialog
        open={confirmDelete}
        title={m.invoices.detail.confirmDeleteTitle}
        body={m.invoices.detail.confirmDeleteBody}
        confirmLabel={m.invoices.detail.deleteDraft}
        onConfirm={() => { void handleDelete() }}
        onClose={() => setConfirmDelete(false)}
        pending={remove.pending}
      />
    </div>
  )
}
