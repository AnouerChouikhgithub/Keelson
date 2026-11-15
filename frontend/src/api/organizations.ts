import { request } from './client'
import type { OrganizationMembership, OrganizationRole } from './types'

const roles: OrganizationRole[] = ['Owner', 'Admin', 'Member', 'Viewer']

function isRole(value: unknown): value is OrganizationRole {
  return typeof value === 'string' && (roles as string[]).includes(value)
}

/**
 * `GET /api/me/organizations` — the caller's own active memberships.
 *
 * The returned `role` is informational for rendering; the backend re-checks
 * membership and role on every tenant request, so a tampered value here cannot
 * grant anything. Rows that do not match the verified shape are dropped rather
 * than rendered as half-valid organizations.
 */
export async function listOrganizations(signal?: AbortSignal): Promise<OrganizationMembership[]> {
  const payload = await request<{ items?: unknown }>('/api/me/organizations', { signal })
  if (!Array.isArray(payload.items)) return []
  return payload.items.flatMap((item): OrganizationMembership[] => {
    if (typeof item !== 'object' || item === null) return []
    const record = item as Record<string, unknown>
    const { id, name, subdomain, role } = record
    if (typeof id !== 'string' || id === '') return []
    if (typeof name !== 'string' || typeof subdomain !== 'string') return []
    if (!isRole(role)) return []
    return [{ id, name, subdomain, role }]
  })
}

/** Capability helpers that mirror `InvoiceVoter`, labelled as UI affordances only. */
export function canWriteInvoiceResources(role: OrganizationRole): boolean {
  return role === 'Owner' || role === 'Admin' || role === 'Member'
}

export function canDeleteInvoiceResources(role: OrganizationRole): boolean {
  return role === 'Owner' || role === 'Admin'
}
