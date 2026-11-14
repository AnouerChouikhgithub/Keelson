import { useEffect, useMemo, useRef, useState } from 'react'
import { EmptyState } from '../components/EmptyState'
import { Icon } from '../components/Icon'
import { PersonCell } from '../components/PersonCell'
import { SearchField } from '../components/SearchField'
import { StatusBadge } from '../components/StatusBadge'
import { statusTone } from '../components/statusTone'
import { useEscapeKey } from '../hooks/useEscapeKey'
import { useI18n } from '../i18n/I18nContext'
import type { InvoiceStatus } from '../mocks/dashboard'
import { invoiceRecords } from '../mocks/invoices'

type Filter = 'all' | InvoiceStatus
type SortKey = 'number' | 'amount' | 'dueDate'

const filters: Filter[] = ['all', 'draft', 'sent', 'paid', 'overdue']
const pageSize = 6

export function InvoicesScreen({ onOpenEditor }: { onOpenEditor: () => void }) {
  const { m, currency, date } = useI18n()
  const copy = m.workspace.invoices
  const [rows, setRows] = useState(invoiceRecords)
  const [filter, setFilter] = useState<Filter>('all')
  const [query, setQuery] = useState('')
  const [sort, setSort] = useState<{ key: SortKey; asc: boolean }>({ key: 'dueDate', asc: false })
  const [selected, setSelected] = useState<string[]>([])
  const [page, setPage] = useState(0)
  const [menuFor, setMenuFor] = useState<string | null>(null)
  const menuCell = useRef<HTMLTableCellElement>(null)

  useEscapeKey(menuFor !== null, () => setMenuFor(null))

  useEffect(() => {
    if (menuFor === null) return
    function handlePointerDown(event: MouseEvent) {
      if (!menuCell.current?.contains(event.target as Node)) setMenuFor(null)
    }
    document.addEventListener('mousedown', handlePointerDown)
    return () => document.removeEventListener('mousedown', handlePointerDown)
  }, [menuFor])

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase()
    const matches = rows.filter((invoice) => {
      const inFilter = filter === 'all' || invoice.status === filter
      const inQuery = needle === ''
        || invoice.number.toLowerCase().includes(needle)
        || invoice.customer.toLowerCase().includes(needle)
      return inFilter && inQuery
    })
    return [...matches].sort((left, right) => {
      const comparison = sort.key === 'amount'
        ? left.amount - right.amount
        : sort.key === 'number'
          ? left.number.localeCompare(right.number)
          : left.dueDate.localeCompare(right.dueDate)
      return sort.asc ? comparison : -comparison
    })
  }, [rows, filter, query, sort])

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

  function markPaid(ids: string[]) {
    setRows((current) => current.map((invoice) => ids.includes(invoice.id) ? { ...invoice, status: 'paid' } : invoice))
    setSelected((current) => current.filter((id) => !ids.includes(id)))
    setMenuFor(null)
  }

  function deleteDraft(id: string) {
    setRows((current) => current.filter((invoice) => invoice.id !== id || invoice.status !== 'draft'))
    setSelected((current) => current.filter((item) => item !== id))
    setMenuFor(null)
  }

  function createDraft() {
    const nextNumber = `INV-2026-${String(rows.length + 85).padStart(3, '0')}`
    setRows((current) => [{
      id: `inv-new-${current.length}`,
      number: nextNumber,
      customer: copy.newCustomer,
      initials: 'NC',
      issueDate: '2026-11-14',
      dueDate: '2026-11-28',
      amount: 0,
      status: 'draft',
    }, ...current])
    setFilter('all')
    setQuery('')
    setPage(0)
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
        <button className="button button-primary" type="button" onClick={createDraft}>
          <Icon name="plus" size={18} /> {m.dashboard.createInvoice}
        </button>
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
              onClick={() => { setFilter(id); setPage(0) }}
            >
              {copy.filters[id]}
              <span className="filter-count">{id === 'all' ? rows.length : rows.filter((invoice) => invoice.status === id).length}</span>
            </button>
          ))}
        </div>
      </div>

      {selected.length > 0 && (
        <div className="bulk-bar">
          <span>{copy.selected(selected.length)}</span>
          <button type="button" className="button button-secondary" onClick={() => markPaid(selected)}>{copy.markPaid}</button>
          <button type="button" className="button button-ghost" onClick={() => setSelected([])}>{copy.clearSelection}</button>
        </div>
      )}

      {visible.length === 0 ? (
        <EmptyState
          icon="file"
          title={copy.empty}
          body={copy.emptyHint}
          action={{ label: copy.clear, onClick: () => { setFilter('all'); setQuery('') } }}
        />
      ) : (
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
                    <PersonCell initials={invoice.initials} name={invoice.customer} tone={statusTone[invoice.status]} />
                  </td>
                  <td>{date(invoice.issueDate)}</td>
                  <td>{date(invoice.dueDate)}</td>
                  <td className="amount-cell">{currency(invoice.amount)}</td>
                  <td><StatusBadge status={invoice.status} /></td>
                  <td className="row-menu-cell" ref={menuFor === invoice.id ? menuCell : null}>
                    <button
                      className="icon-button row-action"
                      type="button"
                      aria-label={copy.rowMenu(invoice.number)}
                      aria-expanded={menuFor === invoice.id}
                      onClick={() => setMenuFor(menuFor === invoice.id ? null : invoice.id)}
                    >
                      <Icon name="more" />
                    </button>
                    {menuFor === invoice.id && (
                      <div className="row-menu" role="menu">
                        {invoice.status !== 'paid' && (
                          <button type="button" role="menuitem" onClick={() => markPaid([invoice.id])}>{copy.markPaid}</button>
                        )}
                        {invoice.status === 'draft' && (
                          <button type="button" role="menuitem" onClick={() => { setMenuFor(null); onOpenEditor() }}>{copy.openEditor}</button>
                        )}
                        {invoice.status === 'draft' && (
                          <button type="button" role="menuitem" className="danger-item" onClick={() => deleteDraft(invoice.id)}>{copy.deleteDraft}</button>
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
        <span className="mock-note">{copy.mockNote}</span>
        <div className="pagination">
          <button type="button" className="icon-button" aria-label={copy.previous} disabled={currentPage === 0} onClick={() => setPage(currentPage - 1)}>
            <Icon name="chevron" size={16} />
          </button>
          <span>{copy.pageOf(currentPage + 1, pageCount)}</span>
          <button type="button" className="icon-button pagination-next" aria-label={copy.next} disabled={currentPage >= pageCount - 1} onClick={() => setPage(currentPage + 1)}>
            <Icon name="chevron" size={16} />
          </button>
        </div>
      </div>
    </section>
  )
}
