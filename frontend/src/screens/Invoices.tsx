import { useCallback, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { listInvoices } from '../api/invoices'
import type { InvoiceStatusFilter } from '../api/invoices'
import { canWriteInvoiceResources } from '../api/organizations'
import { presentError } from '../api/presentError'
import type { Invoice } from '../api/types'
import { useApiResource } from '../api/useApiResource'
import { DataTable } from '../components/DataTable'
import { EmptyState } from '../components/EmptyState'
import { ErrorState } from '../components/ErrorState'
import { Icon } from '../components/Icon'
import { LoadMore } from '../components/Pagination'
import { PersonCell } from '../components/PersonCell'
import { Skeleton } from '../components/Skeleton'
import { StatusBadge } from '../components/StatusBadge'
import { useToast } from '../components/ToastContext'
import { useDebouncedValue } from '../hooks/useDebouncedValue'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import { initialsOf } from '../utils/initials'
import { useWorkspace } from '../app/WorkspaceContext'
import './invoices.css'

const filters: InvoiceStatusFilter[] = ['all', 'draft', 'sent', 'paid', 'overdue']
const PAGE_SIZE = 50

export default function Invoices() {
  const { m, currency, date } = useI18n()
  const { membership } = useWorkspace()
  const toast = useToast()
  const navigate = useNavigate()
  const [status, setStatus] = useState<InvoiceStatusFilter>('all')
  const [query, setQuery] = useState('')
  const debouncedQuery = useDebouncedValue(query, 250)
  const [more, setMore] = useState<{ key: string; items: Invoice[]; cursor: string | null }>({ key: '', items: [], cursor: null })
  const [loadingMore, setLoadingMore] = useState(false)

  const orgId = membership.id
  const key = `${orgId}:${status}`
  const load = useCallback(
    (signal: AbortSignal) => listInvoices(orgId, { status, limit: PAGE_SIZE, signal }),
    [orgId, status],
  )
  const resource = useApiResource(`invoices:${key}`, load)
  useDocumentTitle(m.page.invoices)

  const { items, nextCursor } = useMemo(() => {
    const firstPage = resource.state.status === 'ready' ? resource.state.data.items : []
    return {
      items: more.key === key ? [...firstPage, ...more.items] : firstPage,
      nextCursor: more.key === key ? more.cursor : (resource.state.status === 'ready' ? resource.state.data.nextCursor : null),
    }
  }, [resource.state, more, key])

  const visible = useMemo(() => {
    const needle = debouncedQuery.trim().toLocaleLowerCase()
    if (needle === '') return items
    return items.filter((invoice) =>
      `${invoice.number} ${invoice.customer.name} ${invoice.customer.email ?? ''}`.toLocaleLowerCase().includes(needle),
    )
  }, [items, debouncedQuery])

  async function loadMore() {
    if (nextCursor === null || loadingMore) return
    setLoadingMore(true)
    try {
      const page = await listInvoices(orgId, { status, cursor: nextCursor, limit: PAGE_SIZE })
      setMore((current) => ({
        key,
        items: [...(current.key === key ? current.items : []), ...page.items],
        cursor: page.nextCursor,
      }))
    } catch (error) {
      toast.error(presentError(m, error))
    } finally {
      setLoadingMore(false)
    }
  }

  function resetFilters() {
    setQuery('')
    setStatus('all')
  }

  const heading = (
    <section className="page-intro">
      <div>
        <p className="eyebrow">{m.nav.workspace}</p>
        <h1>{m.invoices.title}</h1>
        <p>{m.invoices.subtitle}</p>
      </div>
      {canWriteInvoiceResources(membership.role) && (
        <Link className="button button-primary" to={`/o/${orgId}/invoices/new`}>
          <Icon name="plus" size={18} /> {m.invoices.create}
        </Link>
      )}
    </section>
  )

  if (resource.state.status === 'loading') {
    return (
      <div className="invoices-page">
        {heading}
        <Skeleton variant="table" count={6} label={m.common.loading} />
      </div>
    )
  }

  if (resource.state.status === 'error') {
    return (
      <div className="invoices-page">
        {heading}
        <ErrorState
          title={m.invoices.loadFailed}
          message={presentError(m, resource.state.error)}
          retryLabel={m.common.retry}
          onRetry={resource.reload}
        />
      </div>
    )
  }

  const hasFilters = debouncedQuery.trim() !== '' || status !== 'all'

  return (
    <div className="invoices-page">
      {heading}

      <section className="panel list-panel" aria-label={m.invoices.title}>
        <div className="toolbar">
          <div className="search-field">
            <Icon name="search" size={16} />
            <input
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              aria-label={m.invoices.searchLabel}
              placeholder={m.invoices.searchPlaceholder}
            />
          </div>
          <div className="filter-tabs" role="group" aria-label={m.invoices.filtersLabel}>
            {filters.map((value) => (
              <button
                key={value}
                type="button"
                className={`filter-tab ${status === value ? 'active' : ''}`}
                aria-pressed={status === value}
                onClick={() => setStatus(value)}
              >
                {m.invoices.filters[value]}
              </button>
            ))}
          </div>
          {hasFilters && (
            <button type="button" className="button button-ghost button-sm" onClick={resetFilters}>
              {m.invoices.clear}
            </button>
          )}
        </div>

        {items.length === 0 ? (
          <EmptyState
            icon="file"
            title={m.invoices.noInvoices}
            body={m.invoices.noInvoicesHint}
            action={canWriteInvoiceResources(membership.role)
              ? { label: m.invoices.create, onClick: () => { void navigate(`/o/${orgId}/invoices/new`) } }
              : undefined}
          />
        ) : visible.length === 0 ? (
          <EmptyState
            icon="search"
            title={m.invoices.empty}
            body={m.invoices.emptyHint}
            action={{ label: m.invoices.clear, onClick: resetFilters }}
          />
        ) : (
          <DataTable
            caption={m.invoices.tableCaption}
            footer={(
              <LoadMore
                count={visible.length}
                hasMore={nextCursor !== null}
                loading={loadingMore}
                onLoadMore={() => { void loadMore() }}
                note={debouncedQuery.trim() === '' ? undefined : m.invoices.clientSearchNote}
              />
            )}
          >
            <thead>
              <tr>
                <th scope="col">{m.invoices.columns.number}</th>
                <th scope="col">{m.invoices.columns.customer}</th>
                <th scope="col">{m.invoices.columns.issue}</th>
                <th scope="col">{m.invoices.columns.due}</th>
                <th scope="col">{m.invoices.columns.amount}</th>
                <th scope="col">{m.invoices.columns.status}</th>
                <th scope="col"><span className="visually-hidden">{m.invoices.columns.actions}</span></th>
              </tr>
            </thead>
            <tbody>
              {visible.map((invoice) => (
                <tr key={invoice.id}>
                  <td className="cell-strong">
                    <Link to={`/o/${orgId}/invoices/${invoice.id}`} aria-label={m.invoices.open(invoice.number)}>
                      {invoice.number}
                    </Link>
                  </td>
                  <td><PersonCell initials={initialsOf(invoice.customer.name)} name={invoice.customer.name} /></td>
                  <td>{date(invoice.issueDate)}</td>
                  <td>{date(invoice.dueDate)}</td>
                  <td className="cell-amount">{currency(Number(invoice.total))}</td>
                  <td><StatusBadge status={invoice.status} /></td>
                  <td className="cell-end">
                    <Link className="button button-ghost button-sm" to={`/o/${orgId}/invoices/${invoice.id}`}>
                      {m.invoices.open(invoice.number)}
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        )}
      </section>
    </div>
  )
}
