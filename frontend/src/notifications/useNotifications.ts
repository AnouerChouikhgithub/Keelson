import { useCallback, useEffect, useState } from 'react'
import { mockNotifications } from '../mocks/notifications'
import type { AppNotification } from '../mocks/notifications'

const readKey = 'keelson-notifications-read'

function readStoredIds(): string[] {
  if (typeof window === 'undefined') return []
  try {
    const parsed: unknown = JSON.parse(window.localStorage.getItem(readKey) ?? '[]')
    return Array.isArray(parsed) ? parsed.filter((id): id is string => typeof id === 'string') : []
  } catch {
    return []
  }
}

export type NotificationState = {
  notifications: AppNotification[]
  unreadCount: number
  markRead: (id: string) => void
  markAllRead: () => void
  isRead: (id: string) => boolean
}

export function useNotifications(): NotificationState {
  const [readIds, setReadIds] = useState<string[]>(readStoredIds)

  useEffect(() => {
    try {
      window.localStorage.setItem(readKey, JSON.stringify(readIds))
    } catch {
      // Read state still works for this session when storage is unavailable.
    }
  }, [readIds])

  const isRead = useCallback((id: string) => readIds.includes(id), [readIds])
  const markRead = useCallback((id: string) => {
    setReadIds((current) => (current.includes(id) ? current : [...current, id]))
  }, [])
  const markAllRead = useCallback(() => {
    setReadIds(mockNotifications.map((notification) => notification.id))
  }, [])

  return {
    notifications: mockNotifications,
    unreadCount: mockNotifications.filter((notification) => !readIds.includes(notification.id)).length,
    markRead,
    markAllRead,
    isRead,
  }
}
