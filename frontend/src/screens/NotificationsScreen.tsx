import { useState } from 'react'
import { Icon } from '../components/Icon'
import type { IconName } from '../components/Icon'
import { useI18n } from '../i18n/I18nContext'
import type { AppNotification } from '../mocks/notifications'
import { notificationText } from '../notifications/notificationText'
import type { PageId } from '../pages'

const kindIcon: Record<AppNotification['kind'], IconName> = {
  invoicePaid: 'check',
  invoiceOverdue: 'clock',
  invoiceSent: 'arrow',
  invitationAccepted: 'mail',
  invitationPending: 'mail',
  memberJoined: 'users',
  security: 'shield',
}

type NotificationsScreenProps = {
  notifications: AppNotification[]
  unreadCount: number
  isRead: (id: string) => boolean
  markRead: (id: string) => void
  markAllRead: () => void
  onNavigate: (page: PageId) => void
}

export function NotificationsScreen({ notifications, unreadCount, isRead, markRead, markAllRead, onNavigate }: NotificationsScreenProps) {
  const { m, dateTime } = useI18n()
  const [filter, setFilter] = useState<'all' | 'unread'>('all')
  const [announcement, setAnnouncement] = useState('')

  const visible = notifications.filter((notification) => filter === 'all' || !isRead(notification.id))

  return (
    <div className="notifications-page">
      <section className="page-intro">
        <div>
          <p className="eyebrow">{m.page.notifications}</p>
          <h1>{m.notifications.title}</h1>
          <p>{m.notifications.subtitle}</p>
        </div>
        <span className="demo-notice"><Icon name="shield" size={15} /> {m.notifications.demoNotice}</span>
      </section>

      <div className="notification-toolbar">
        <div className="filter-group" role="group" aria-label={m.notifications.filterLabel}>
          {(['all', 'unread'] as const).map((value) => (
            <button
              key={value}
              type="button"
              className={`filter-option ${filter === value ? 'selected' : ''}`}
              aria-pressed={filter === value}
              onClick={() => setFilter(value)}
            >
              {value === 'all' ? m.notifications.all : m.notifications.unread}
            </button>
          ))}
        </div>
        <button
          type="button"
          className="button button-secondary"
          disabled={unreadCount === 0}
          onClick={() => { markAllRead(); setAnnouncement(m.notifications.allMarkedRead) }}
        >
          <Icon name="check" size={16} /> {m.notifications.markAllRead}
        </button>
      </div>

      <p className="notification-count" role="status" aria-live="polite">{announcement || m.notifications.unreadCount(unreadCount)}</p>

      <section className="panel notification-panel" aria-label={m.notifications.listLabel}>
        {visible.length === 0 ? (
          <div className="notification-empty">
            <span className="placeholder-icon"><Icon name="check" size={24} /></span>
            <h2>{m.notifications.emptyTitle}</h2>
            <p>{m.notifications.emptyBody}</p>
          </div>
        ) : (
          <ul className="notification-list">
            {visible.map((notification) => {
              const read = isRead(notification.id)
              return (
                <li className={`notification-item ${read ? 'is-read' : 'is-unread'}`} key={notification.id}>
                  <span className="notification-icon"><Icon name={kindIcon[notification.kind]} size={17} /></span>
                  <span className="notification-body">
                    <strong>{notificationText(notification, m)}</strong>
                    <small>{dateTime(notification.createdAt)}</small>
                  </span>
                  <span className="notification-actions">
                    {!read && (
                      <button type="button" className="notification-action" onClick={() => { markRead(notification.id); setAnnouncement(m.notifications.markedRead) }}>
                        {m.notifications.markRead}
                      </button>
                    )}
                    <button type="button" className="notification-action" onClick={() => onNavigate(notification.destination)}>
                      {m.notifications.goTo(m.page[notification.destination])}
                      <Icon name="arrow" size={14} />
                    </button>
                  </span>
                  {!read && <span className="visually-hidden">{m.common.unread}</span>}
                </li>
              )
            })}
          </ul>
        )}
      </section>
    </div>
  )
}
