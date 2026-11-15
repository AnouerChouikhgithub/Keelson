import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { deleteInvoiceDraft, listInvoices, markInvoicePaid, sendInvoice } from '../api/invoices'
import type { InvoiceStatusFilter } from '../api/invoices'
import { canDeleteInvoiceResources, canWriteInvoiceResources } from '../api/organizations'
import { isConflict, presentError } from '../api/presentError'
import type { Invoice, OrganizationRole } from '../api/types'
import { useApiResource } from '../api/useApiResource'
import { EmptyState } from '../components/EmptyState'
import { Icon } from '../components/Icon'
import { PersonCell } from '../components/PersonCell'
import { SearchField } from '../components/SearchField'
import { StatusBadge } from '../components/StatusBadge'
import { statusTone } from '../components/statusTone'
import { useEscapeKey } from '../hooks/useEscapeKey'
import { useI18n } from '../i18n/I18nContext'
import { initialsOf } from '../utils/initials'

type SortKey = 'number' | 'amount' | 'dueDate'

const filters: InvoiceStatusFilter[] = ['all', 'draft', 'sent', 'paid', 'overdue']
const pageSize = 6

/**
 * Amounts arrive as three-decimal strings. They are converted for display only;
 * the server's decimal string is the authoritative value and is never written
 * back after being parsed.
 */
function displayAmount(value: string): number {
  const parsed = Number.parseFloat(value)
  return Number.isFinite(parsed) ? parsed : 0
}

export function InvoicesScreen({
  organizationId,
  role,
  onOpenEditor,
}: {
  organizationId: string
  role: OrganizationRole
  onOpenEditor: (invoiceId: string | null) => void
}) {
  const { m, currency, date } = useI18n()
  const copy = m.workspace.invoices
  const [filter, setFilter] = useState<InvoiceStatusFilter>('all')
  const [query, setQuery] = useState('')
  const [sort, setSort] = useState<{ key: SortKey; asc: boolean }>({ key: 'dueDate', asc: false })
  const [selected, setSelected] = useState<string[]>([])
  const [page, setPage] = useState(0)
  const [menuFor, setMenuFor] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [pendingId, setPendingId] = useState<string | null>(null)
  const menuCell = useRef<HTMLTableCellElement>(null)

  const canWrite = canWriteInvoiceResources(role)
  const canDelete = canDeleteInvoiceResources(role)

  const load = useCallback(
    (signal: AbortSignal) => listInvoices(organizationId, { status: filter, signal }),
    [organizationId, filter],
  )
  const { state, reload } = useApiResource(load)

  useEscapeKey(menuFor !== null, () => setMenuFor(null))

  useEffect(() => {
    if (menuFor === null) return
    function handlePointerDown(event: MouseEvent) {
      if (!menuCell.current?.contains(event.target as Node)) setMenuFor(null)
    }
    document.addEventListener('mousedown', handlePointerDown)
    return () => document.removeEventListener('mousedown', handlePointerDown)
  }, [menuFor])

  const rows = useMemo(() => state.status === 'ready' ? state.data.items : [], [state])
  const morePages = state.status === 'ready' && state.data.nextCursor !== null

  const filtered = useMemo(() => {
    const needle = query.trim().toLocaleLowerCase()
    const matches = rows.filter((invoice) => needle === ''
      || invoice.number.toLocaleLowerCase().includes(needle)
      || invoice.customer.name.toLocaleLowerCase().includes(needle))
    return [...matches].sort((left, right) => {
      const comparison = sort.key === 'amount'
        ? displayAmount(left.total) - displayAmount(right.total)
        : sort.key === 'number'
          ? left.number.localeCompare(right.number)
          : left.dueDate.localeCompare(right.dueDate)
      return sort.asc ? comparison : -comparison
    })
  }, [rows, query, sort])

  const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize))
  const currentPage = Math.min(page, pageCount - 1)
  const visible = filtered.slice(currentPage * pageSize, currentPage * pageSize + pageSize)
  const allVisibleSelected = visible.length > 0 && visible.every((invoice) => selected.includes(invoice.id))

  function toggleSort(key: SortKey) {
    setSort((current) => ({ key, asc: current.key === key ? !current.asc : true }))
  }

  function toggleSelected(id: string) {
    setSelected((current) => current.includes(id) ? current.filter((item) => item !== id) : [...current, id])
  }

  function toggleAllVisible() {
    setSelected((current) => allVisibleSelected
      ? current.filter((id) => !visible.some((invoice) => invoice.id === id))
      : [...new Set([...current, ...visible.map((invoice) => invoice.id)])])
  }

  async function markPaid(invoices: Invoice[]) {
    const payable = invoices.filter((invoice) => invoice.status !== 'paid')
    if (payable.length === 0 || pendingId !== null) return
    setPendingId(payable[0].id)
    setNotice(null)
    try {
      for (const invoice of payable) {
        await markInvoicePaid(organizationId, invoice.id)
      }
      setNotice(payable.length === 1 ? copy.markedPaid(payable[0].number) : copy.markedPaidMany(payable.length))
      setSelected((current) => current.filter((id) => !payable.some((invoice) => invoice.id === id)))
    } catch (error) {
      setNotice(isConflict(error) ? copy.statusChanged : presentError(m, error))
    } finally {
      setPendingId(null)
      setMenuFor(null)
      reload()
    }
  }

  async function removeDraft(invoice: Invoice) {
    if (pendingId !== null) return
    setPendingId(invoice.id)
    setNotice(null)
    try {
      await deleteInvoiceDraft(organizationId, invoice.id)
      setNotice(copy.deletedDraft(invoice.number))
      setSelected((current) => current.filter((id) => id !== invoice.id))
    } catch (error) {
      setNotice(isConflict(error) ? copy.statusChanged : presentError(m, error))
    } finally {
      setPendingId(null)
      setMenuFor(null)
      reload()
    }
  }

  async function send(invoice: Invoice) {
    if (pendingId !== null) return
    setPendingId(invoice.id)
    setNotice(null)
    try {
      const sent = await sendInvoice(organizationId, invoice.id)
      setNotice(m.workspace.editor.sentServer(sent.number))
    } catch (error) {
      setNotice(isConflict(error) ? copy.statusChanged : presentError(m, error))
    } finally {
      setPendingId(null)
      setMenuFor(null)
      reload()
    }
  }

  const columns: { key: SortKey | null; label: string }[] = [
    { key: 'number', label: copy.columns.number },
    { key: null, label: copy.columns.customer },
    { key: null, label: copy.columns.issue },
    { key: 'dueDate', label: copy.columns.due },
    { key: 'amount', label: copy.columns.amount },
    { key: null, label: copy.columns.status },
  ]

  return (
    <section className="panel workspace-panel">
      <div className="panel-heading workspace-heading">
        <div>
          <h1>{m.page.invoices}</h1>
          <p>{m.placeholder.invoices}</p>
        </div>
        {canWrite && (
          <button className="button button-primary" type="button" onClick={() => onOpenEditor(null)}>
            <Icon name="plus" size={18} /> {m.dashboard.createInvoice}
          </button>
        )}
      </div>

      <div className="toolbar">
        <SearchField
          label={copy.searchPlaceholder}
          value={query}
          onChange={(value) => { setQuery(value); setPage(0) }}
        />
        <div className="filter-tabs" role="tablist" aria-label={m.page.invoices}>
          {filters.map((id) => (
            <button
              key={id}
              type="button"
              role="tab"
              aria-selected={filter === id}
              className={`filter-tab ${filter === id ? 'active' : ''}`}
              onClick={() => {
                // A different filter is a different list: stale selections or a
                // page number past the end would point at rows no longer shown.
                setFilter(id)
                setSelected([])
                setPage(0)
                setMenuFor(null)
              }}
            >
              {copy.filters[id]}
            </button>
          ))}
        </div>
      </div>

      {notice && (
        <div className="inline-feedback" role="status">
          <Icon name="check" size={16} />
          <span>{notice}</span>
          <button type="button" aria-label={m.common.dismiss} onClick={() => setNotice(null)}><Icon name="close" size={15} /></button>
        </div>
      )}

      {selected.length > 0 && canWrite && (
        <div className="bulk-bar">
          <span>{copy.selected(selected.length)}</span>
          <button
            type="button"
            className="button button-secondary"
            disabled={pendingId !== null}
            onClick={() => void markPaid(rows.filter((invoice) => selected.includes(invoice.id)))}
          >
            {copy.markPaid}
          </button>
          <button type="button" className="button button-ghost" onClick={() => setSelected([])}>{copy.clearSelection}</button>
        </div>
      )}

      {state.status === 'loading' && <p className="mock-note" role="status">{m.api.loading}</p>}

      {state.status === 'error' && (
        <EmptyState
          icon="file"
          title={copy.loadFailed}
          body={presentError(m, state.error)}
          action={{ label: m.api.reload, onClick: reload }}
        />
      )}

      {state.status === 'ready' && rows.length === 0 && (
        <EmptyState
          icon="file"
          title={copy.noInvoices}
          body={copy.noInvoicesHint}
          action={canWrite ? { label: m.dashboard.createInvoice, onClick: () => onOpenEditor(null) } : undefined}
        />
      )}

      {state.status === 'ready' && rows.length > 0 && visible.length === 0 && (
        <EmptyState
          icon="file"
          title={copy.empty}
          body={copy.emptyHint}
          action={{ label: copy.clear, onClick: () => { setFilter('all'); setQuery('') } }}
        />
      )}

      {visible.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th className="check-cell">
                  <input
                    type="checkbox"
                    checked={allVisibleSelected}
                    aria-label={copy.selectAll}
                    onChange={toggleAllVisible}
                  />
                </th>
                {columns.map((column) => (
                  <th key={column.label}>
                    {column.key ? (
                      <button
                        type="button"
                        className="sort-button"
                        aria-label={copy.sortBy(column.label)}
                        aria-pressed={sort.key === column.key}
                        onClick={() => toggleSort(column.key as SortKey)}
                      >
                        {column.label}
                        <Icon name="chevron" size={13} />
                      </button>
                    ) : column.label}
                  </th>
                ))}
                <th><span className="visually-hidden">{copy.columns.actions}</span></th>
              </tr>
            </thead>
            <tbody>
              {visible.map((invoice) => (
                <tr key={invoice.id}>
                  <td className="check-cell">
                    <input
                      type="checkbox"
                      checked={selected.includes(invoice.id)}
                      aria-label={copy.selectRow(invoice.number)}
                      onChange={() => toggleSelected(invoice.id)}
                    />
                  </td>
                  <td className="invoice-number">{invoice.number}</td>
                  <td>
                    <PersonCell initials={initialsOf(invoice.customer.name)} name={invoice.customer.name} tone={statusTone[invoice.status]} />
                  </td>
                  <td>{date(invoice.issueDate)}</td>
                  <td>{date(invoice.dueDate)}</td>
                  <td className="amount-cell">{currency(displayAmount(invoice.total))}</td>
                  <td><StatusBadge status={invoice.status} /></td>
                  <td className="row-menu-cell" ref={menuFor === invoice.id ? menuCell : null}>
                    <button
                      className="icon-button row-action"
                      type="button"
                      aria-label={copy.rowMenu(invoice.number)}
                      aria-expanded={menuFor === invoice.id}
                      disabled={pendingId !== null}
                      onClick={() => setMenuFor(menuFor === invoice.id ? null : invoice.id)}
                    >
                      <Icon name="more" />
                    </button>
                    {menuFor === invoice.id && (
                      <div className="row-menu" role="menu">
                        {canWrite && invoice.status !== 'paid' && (
                          <button type="button" role="menuitem" onClick={() => void markPaid([invoice])}>{copy.markPaid}</button>
                        )}
                        {canWrite && invoice.status === 'draft' && (
                          <button type="button" role="menuitem" onClick={() => { setMenuFor(null); onOpenEditor(invoice.id) }}>{copy.openEditor}</button>
                        )}
                        {canWrite && invoice.status === 'draft' && (
                          <button type="button" role="menuitem" onClick={() => void send(invoice)}>{copy.send}</button>
                        )}
                        {canDelete && invoice.status === 'draft' && (
                          <button type="button" role="menuitem" className="danger-item" onClick={() => void removeDraft(invoice)}>{copy.deleteDraft}</button>
                        )}
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="table-footer">
        <span className="mock-note">{morePages ? copy.partial : copy.sourceNote}</span>
        {visible.length > 0 && (
          <div className="pagination">
            <button type="button" className="icon-button" aria-label={copy.previous} disabled={currentPage === 0} onClick={() => setPage(currentPage - 1)}>
              <Icon name="chevron" size={16} />
            </button>
            <span>{copy.pageOf(currentPage + 1, pageCount)}</span>
            <button type="button" className="icon-button pagination-next" aria-label={copy.next} disabled={currentPage >= pageCount - 1} onClick={() => setPage(currentPage + 1)}>
              <Icon name="chevron" size={16} />
            </button>
          </div>
        )}
      </div>
    </section>
  )
}
