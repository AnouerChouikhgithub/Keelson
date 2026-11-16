import { query, request } from './client'
import type { OrganizationDetail } from './types'

function organizationPath(organizationId?: string): string {
  const base = '/api/organizations'
  return organizationId === undefined ? base : `${base}/${encodeURIComponent(organizationId)}`
}

function parseOrganization(value: unknown): OrganizationDetail | null {
  if (typeof value !== 'object' || value === null) return null
  const record = value as Record<string, unknown>
  const { id, name, subdomain, status, role, createdAt } = record
  if (typeof id !== 'string' || typeof name !== 'string' || typeof subdomain !== 'string') return null
  if (typeof status !== 'string' || typeof createdAt !== 'string') return null
  if (role !== 'Owner' && role !== 'Admin' && role !== 'Member' && role !== 'Viewer') return null
  return { id, name, subdomain, status, role, createdAt }
}

/** `POST /api/organizations` — the creator becomes its Owner in the same transaction. */
export async function createOrganization(input: { name: string; subdomain: string }): Promise<OrganizationDetail> {
  const payload = await request<{ organization?: unknown }>(organizationPath(), { method: 'POST', body: input })
  const organization = parseOrganization(payload.organization)
  if (organization === null) throw new Error('The organization response did not contain a usable organization')
  return organization
}

/** `PATCH /api/organizations/{id}` — Owner only; a duplicate address is `409`. */
export async function updateOrganization(
  organizationId: string,
  patch: { name?: string; subdomain?: string },
): Promise<OrganizationDetail> {
  const payload = await request<{ organization?: unknown }>(organizationPath(organizationId), {
    method: 'PATCH',
    body: patch,
  })
  const organization = parseOrganization(payload.organization)
  if (organization === null) throw new Error('The organization response did not contain a usable organization')
  return organization
}

/** Soft delete: typing the exact name is the confirmation. */
export async function deleteOrganization(organizationId: string, confirmName: string): Promise<OrganizationDetail> {
  const payload = await request<{ organization?: unknown }>(organizationPath(organizationId), {
    method: 'DELETE',
    body: { confirmName },
  })
  const organization = parseOrganization(payload.organization)
  if (organization === null) throw new Error('The organization response did not contain a usable organization')
  return organization
}

/**
 * `GET /api/organizations/subdomain-availability` — a hint for the form.
 *
 * The endpoint always answers `200 {available:false}` for an invalid, reserved
 * or taken value, so this never needs an error path of its own.
 */
export async function subdomainAvailable(value: string, signal?: AbortSignal): Promise<boolean> {
  const payload = await request<{ available?: unknown }>(
    `/api/organizations/subdomain-availability${query({ value })}`,
    { signal },
  )
  return payload.available === true
}
