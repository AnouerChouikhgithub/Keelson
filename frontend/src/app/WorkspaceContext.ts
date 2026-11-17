import { createContext, useContext } from 'react'
import type { OrganizationMembership } from '../api/types'

export type WorkspaceValue = {
  /** The membership resolved from `/o/:orgId` — never client-asserted authority. */
  membership: OrganizationMembership
  memberships: OrganizationMembership[]
  /** Re-reads `/api/me/organizations`, for example after a role change elsewhere. */
  refreshMemberships: () => void
}

export const WorkspaceContext = createContext<WorkspaceValue | null>(null)

export function useWorkspace(): WorkspaceValue {
  const value = useContext(WorkspaceContext)
  if (!value) throw new Error('useWorkspace must be used inside OrganizationScope')
  return value
}
