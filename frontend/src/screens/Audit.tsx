import { useCallback, useMemo, useState } from 'react'
import { Link } from 'react-router'
import { listAuditEvents } from '../api/audit'
import { presentError } from '../api/presentError'
import { useApiResource } from '../api/useApiResource'
import { DataTable } from '../components/DataTable'
import { EmptyState } from '../components/EmptyState'
import { ErrorState } from '../components/ErrorState'
import { Icon } from '../components/Icon'
import { LoadMore } from '../components/Pagination'
import { Skeleton } from '../components/Skeleton'
import { useDebouncedValue } from '../hooks/useDebouncedValue'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import { useWorkspace } from '../app/WorkspaceContext'
import './simple.css'

const PAGE = 25

/**
 * The audit endpoint returns a single page (`limit` only), so this screen loads
 * up to 100 events once and reveals them 25 at a time. It does not claim
 * server-side paging, and the count says how many were loaded.
 */
export default function Audit() {
  const { m, dateTime } = useI18n()
  const { membership } = useWorkspace()
  const [query, setQuery] = useState('')
  const debounced = useDebouncedValue(query, 250)
  const [shown, setShown] = useState(PAGE)
  const load = useCallback(
    (signal: AbortSignal) => listAuditEvents(membership.id, { limit: 100, signal }),
    [membership.id],
  )
  const resource = useApiResource(`audit:${membership.id}:all`, load)
  useDocumentTitle(m.page.audit)

  const events = useMemo(
    () => (resource.state.status === 'ready' ? resource.state.data : []),
    [resource.state],
  )
  const filtered = useMemo(() => {
    const needle = debounced.trim().toLocaleLowerCase()
    if (needle === '') return events
    return events.filter((event) =>
      `${event.action} ${event.invoiceId ?? ''} ${event.customerId ?? ''}`.toLocaleLowerCase().includes(needle),
    )
  }, [events, debounced])
  const visible = filtered.slice(0, shown)

  return (
    <div className="simple-page">
      <section className="page-intro">
        <div>
          <p className="eyebrow">{m.nav.workspace}</p>
          <h1>{m.invoices.audit.title}</h1>
          <p>{m.invoices.audit.subtitle}</p>
        </div>
      </section>

      <section className="panel list-panel" aria-label={m.invoices.audit.title}>
        <div className="toolbar">
          <div className="search-field">
            <Icon name="search" size={16} />
            <input
              type="search"
              value={query}
              onChange={(event) => { setQuery(event.target.value); setShown(PAGE) }}
              aria-label={m.invoices.audit.filterLabel}
              placeholder={m.invoices.audit.filterLabel}
            />
          </div>
        </div>

        {resource.state.status === 'loading' && <Skeleton variant="table" count={6} label={m.common.loading} />}
        {resource.state.status === 'error' && (
          <ErrorState
            title={m.invoices.audit.loadFailed}
            message={presentError(m, resource.state.error)}
            retryLabel={m.common.retry}
            onRetry={resource.reload}
          />
        )}
        {resource.state.status === 'ready' && events.length === 0 && (
          <EmptyState icon="clock" title={m.invoices.audit.empty} body={m.invoices.audit.emptyHint} />
        )}
        {resource.state.status === 'ready' && events.length > 0 && (
          <DataTable
            caption={m.invoices.audit.caption}
            footer={(
              <LoadMore
                count={visible.length}
                hasMore={filtered.length > shown}
                onLoadMore={() => setShown((current) => current + PAGE)}
              />
            )}
          >
            <thead>
              <tr>
                <th scope="col">{m.invoices.audit.action}</th>
                <th scope="col">{m.invoices.audit.invoice}</th>
                <th scope="col">{m.invoices.audit.customer}</th>
                <th scope="col">{m.invoices.audit.actor}</th>
                <th scope="col">{m.invoices.audit.at}</th>
              </tr>
            </thead>
            <tbody>
              {visible.map((event) => (
                <tr key={event.id}>
                  <td className="cell-strong">{event.action}</td>
                  <td>
                    {event.invoiceId
                      ? <Link to={`/o/${membership.id}/invoices/${event.invoiceId}`}>{event.invoiceId.slice(0, 8)}</Link>
                      : '—'}
                  </td>
                  <td>{event.customerId ? event.customerId.slice(0, 8) : '—'}</td>
                  <td>{event.actorPrincipalId ? event.actorPrincipalId.slice(0, 8) : '—'}</td>
                  <td>{dateTime(event.createdAt)}</td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        )}
      </section>
    </div>
  )
}
