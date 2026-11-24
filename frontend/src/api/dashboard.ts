import { request } from './client'
import type { Dashboard } from './types'

/**
 * `GET /api/organizations/{id}/dashboard` — the only source of dashboard figures.
 *
 * Every count, sum and monthly bucket is computed by PostgreSQL over the whole
 * tenant. The screen must not derive a figure from a list: a list is paginated
 * (100 rows at most), so a headline computed from it would describe the first
 * page rather than the workspace.
 */
export function readDashboard(organizationId: string, signal?: AbortSignal): Promise<Dashboard> {
  return request<Dashboard>(`/api/organizations/${encodeURIComponent(organizationId)}/dashboard`, { signal })
}
