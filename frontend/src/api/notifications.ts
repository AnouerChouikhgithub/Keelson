import { query, request } from './client'
import type { AppNotification, Paginated } from './types'

export function parseNotification(value: unknown): AppNotification | null {
  if (typeof value !== 'object' || value === null) return null
  const record = value as Record<string, unknown>
  const { id, type, data, organizationId, read, createdAt, readAt } = record
  if (typeof id !== 'string' || id === '') return null
  if (typeof type !== 'string' || type === '') return null
  if (typeof read !== 'boolean') return null
  if (typeof createdAt !== 'string' || createdAt === '') return null
  return {
    id,
    type,
    data: typeof data === 'object' && data !== null ? (data as Record<string, unknown>) : {},
    organizationId: typeof organizationId === 'string' ? organizationId : null,
    read,
    createdAt,
    readAt: typeof readAt === 'string' ? readAt : null,
  }
}

/** One page of the caller's own notifications, newest first. */
export async function listNotifications(
  options: { limit?: number; cursor?: string | null; unreadOnly?: boolean; signal?: AbortSignal } = {},
): Promise<Paginated<AppNotification>> {
  const { limit = 25, cursor, unreadOnly = false, signal } = options
  const payload = await request<{ items?: unknown; nextCursor?: unknown }>(
    `/api/notifications${query({ limit, cursor, unread: unreadOnly ? 1 : null })}`,
    { signal },
  )
  const items = Array.isArray(payload.items)
    ? payload.items.flatMap((item): AppNotification[] => {
      const notification = parseNotification(item)
      return notification === null ? [] : [notification]
    })
    : []
  return { items, nextCursor: typeof payload.nextCursor === 'string' ? payload.nextCursor : null }
}

/** Marking a read notification again is a no-op on the server. */
export async function markNotificationRead(notificationId: string): Promise<AppNotification> {
  const payload = await request<{ notification?: unknown }>(
    `/api/notifications/${encodeURIComponent(notificationId)}/read`,
    { method: 'POST' },
  )
  const notification = parseNotification(payload.notification)
  if (notification === null) throw new Error('The notification response did not contain a usable notification')
  return notification
}

/** Returns how many rows the server flipped. */
export async function markAllNotificationsRead(): Promise<number> {
  const payload = await request<{ updated?: unknown }>('/api/notifications/read-all', { method: 'POST' })
  return typeof payload.updated === 'number' ? payload.updated : 0
}
