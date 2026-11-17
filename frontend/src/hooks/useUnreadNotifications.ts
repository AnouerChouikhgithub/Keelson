import { useCallback } from 'react'
import { listNotifications } from '../api/notifications'
import { useApiResource } from '../api/useApiResource'

/**
 * The unread count for the header badge.
 *
 * There is no count endpoint, so the badge reads the unread page the API already
 * offers (100 is its maximum). More than 100 unread is displayed as `99+` by the
 * caller; the number is an affordance, never an authorization decision.
 */
export function useUnreadNotifications(organizationId: string | null): number {
  const load = useCallback(async (signal: AbortSignal) => {
    const page = await listNotifications({ unreadOnly: true, limit: 100, signal })
    return page.items.length
  }, [])
  const resource = useApiResource('notifications:unread', load, { enabled: organizationId !== null })
  return resource.state.status === 'ready' ? resource.state.data : 0
}
