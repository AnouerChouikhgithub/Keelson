import { useCallback, useState } from 'react'
import { listNotifications, markAllNotificationsRead, markNotificationRead } from '../api/notifications'
import { presentError } from '../api/presentError'
import { invalidateResource, useApiResource } from '../api/useApiResource'
import { useMutation } from '../api/useMutation'
import { DataTable } from '../components/DataTable'
import { EmptyState } from '../components/EmptyState'
import { ErrorState } from '../components/ErrorState'
import { Skeleton } from '../components/Skeleton'
import { useToast } from '../components/ToastContext'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import './simple.css'

type Filter = 'all' | 'unread'

/**
 * The caller's own notification feed.
 *
 * Marking one read updates the server and then the list, so the header badge
 * (which shares the cache prefix) drops with it. A read notification keeps its
 * row: the audit-style history is useful and the API returns it.
 */
export default function Notifications() {
  const { m, dateTime } = useI18n()
  const toast = useToast()
  useDocumentTitle(m.page.notifications)
  const [filter, setFilter] = useState<Filter>('all')

  const load = useCallback(
    (signal: AbortSignal) => listNotifications({ limit: 50, unreadOnly: filter === 'unread', signal }),
    [filter],
  )
  const resource = useApiResource(`notifications:${filter}`, load)
  const markRead = useMutation(markNotificationRead, { announceFailure: false })
  const markAll = useMutation(markAllNotificationsRead, { announceFailure: false })

  function refresh(): void {
    invalidateResource('notifications')
    resource.reload()
  }

  async function handleRead(notificationId: string) {
    const outcome = await markRead.run(notificationId)
    if (!outcome.ok) {
      toast.error(presentError(m, outcome.error))
      return
    }
    refresh()
  }

  async function handleReadAll() {
    const outcome = await markAll.run()
    if (!outcome.ok) {
      toast.error(presentError(m, outcome.error))
      return
    }
    toast.success(m.notifications.allRead(outcome.data))
    refresh()
  }

  function typeLabel(type: string): string {
    const dictionary = m.notifications.types as Record<string, string | undefined>
    return dictionary[type] ?? m.notifications.other
  }

  const items = resource.state.status === 'ready' ? resource.state.data.items : []
  const hasUnread = items.some((notification) => !notification.read)

  return (
    <div className="simple-page">
      <section className="page-intro">
        <div>
          <p className="eyebrow">{m.nav.workspace}</p>
          <h1>{m.notifications.title}</h1>
          <p>{m.notifications.subtitle}</p>
        </div>
        <div className="page-actions">
          <div className="filter-group" role="group" aria-label={m.notifications.filterLabel}>
            <button
              type="button"
              className={`filter-chip ${filter === 'all' ? 'selected' : ''}`}
              aria-pressed={filter === 'all'}
              onClick={() => setFilter('all')}
            >
              {m.notifications.allFilter}
            </button>
            <button
              type="button"
              className={`filter-chip ${filter === 'unread' ? 'selected' : ''}`}
              aria-pressed={filter === 'unread'}
              onClick={() => setFilter('unread')}
            >
              {m.notifications.unreadFilter}
            </button>
          </div>
          <button
            type="button"
            className="button button-secondary"
            disabled={markAll.pending || !hasUnread}
            aria-busy={markAll.pending || undefined}
            onClick={() => { void handleReadAll() }}
          >
            {markAll.pending ? m.notifications.marking : m.notifications.markAllRead}
          </button>
        </div>
      </section>

      <section className="panel" aria-label={m.notifications.title}>
        {resource.state.status === 'loading' && <Skeleton variant="list" count={4} label={m.common.loading} />}
        {resource.state.status === 'error' && (
          <ErrorState
            title={m.notifications.loadFailed}
            message={presentError(m, resource.state.error)}
            retryLabel={m.common.retry}
            onRetry={resource.reload}
          />
        )}
        {resource.state.status === 'ready' && items.length === 0 && (
          <EmptyState icon="bell" title={m.notifications.empty} body={m.notifications.emptyHint} />
        )}
        {resource.state.status === 'ready' && items.length > 0 && (
          <DataTable caption={m.notifications.caption}>
            <thead>
              <tr>
                <th scope="col">{m.notifications.type}</th>
                <th scope="col">{m.notifications.received}</th>
                <th scope="col"><span className="visually-hidden">{m.common.actions}</span></th>
              </tr>
            </thead>
            <tbody>
              {items.map((notification) => (
                <tr key={notification.id} className={notification.read ? '' : 'row-unread'}>
                  <td className="cell-strong">
                    {typeLabel(notification.type)}
                    {!notification.read && <span className="you-tag">{m.notifications.unreadTag}</span>}
                  </td>
                  <td>{dateTime(notification.createdAt)}</td>
                  <td className="cell-end cell-actions">
                    {!notification.read && (
                      <button
                        type="button"
                        className="button button-ghost button-sm"
                        disabled={markRead.pending}
                        onClick={() => { void handleRead(notification.id) }}
                      >
                        {m.notifications.markRead}
                      </button>
                    )}
                    {notification.read && notification.readAt !== null && (
                      <span className="field-hint">{m.notifications.readAt(dateTime(notification.readAt))}</span>
                    )}
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
