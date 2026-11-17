import { request } from './client'
import type { Member, OrganizationRole } from './types'

const roles: OrganizationRole[] = ['Owner', 'Admin', 'Member', 'Viewer']

function membersPath(organizationId: string, membershipId?: string): string {
  const base = `/api/organizations/${encodeURIComponent(organizationId)}/members`
  return membershipId === undefined ? base : `${base}/${encodeURIComponent(membershipId)}`
}

/**
 * One roster row, or null when the shape is not the one the contract documents.
 *
 * A malformed row is dropped rather than rendered half-empty: a member with no
 * role cannot be authorized against and must not appear as if they had one.
 */
function parseMember(value: unknown): Member | null {
  if (typeof value !== 'object' || value === null) return null
  const record = value as Record<string, unknown>
  const { id, principalId, email, name, role, joinedAt } = record
  if (typeof id !== 'string' || id === '') return null
  if (typeof principalId !== 'string' || principalId === '') return null
  if (typeof email !== 'string' || email === '') return null
  if (typeof role !== 'string' || !(roles as string[]).includes(role)) return null
  if (typeof joinedAt !== 'string' || joinedAt === '') return null
  return {
    id,
    principalId,
    email,
    name: typeof name === 'string' && name.trim() !== '' ? name : null,
    role: role as OrganizationRole,
    joinedAt,
  }
}

/** `GET …/members` — every active member may read the roster. */
export async function listMembers(organizationId: string, signal?: AbortSignal): Promise<Member[]> {
  const payload = await request<{ items?: unknown }>(membersPath(organizationId), { signal })
  if (!Array.isArray(payload.items)) return []
  return payload.items.flatMap((item): Member[] => {
    const member = parseMember(item)
    return member === null ? [] : [member]
  })
}

/** `PATCH …/members/{id}` — Owner, or Admin for Member/Viewer targets only. */
export async function updateMemberRole(
  organizationId: string,
  membershipId: string,
  role: OrganizationRole,
): Promise<Member> {
  const payload = await request<{ member?: unknown }>(membersPath(organizationId, membershipId), {
    method: 'PATCH',
    body: { role },
  })
  const member = parseMember(payload.member)
  if (member === null) throw new Error('The roster response did not contain a usable member')
  return member
}

/** `DELETE …/members/{id}` — leaving is removing your own membership. */
export function removeMember(organizationId: string, membershipId: string): Promise<void> {
  return request<void>(membersPath(organizationId, membershipId), { method: 'DELETE' })
}
