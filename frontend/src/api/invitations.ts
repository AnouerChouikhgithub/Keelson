import { query, request } from './client'
import type {
  AcceptInvitationResult,
  Invitation,
  InvitationPreview,
  InviteRole,
  OrganizationRole,
} from './types'

const roles: OrganizationRole[] = ['Owner', 'Admin', 'Member', 'Viewer']

function invitationsPath(organizationId: string, invitationId?: string, action?: string): string {
  const base = `/api/organizations/${encodeURIComponent(organizationId)}/invitations`
  if (invitationId === undefined) return base
  const item = `${base}/${encodeURIComponent(invitationId)}`
  return action === undefined ? item : `${item}/${action}`
}

function parseInvitation(value: unknown): Invitation | null {
  if (typeof value !== 'object' || value === null) return null
  const record = value as Record<string, unknown>
  const { id, email, role, status, expired, expiresAt, createdAt } = record
  if (typeof id !== 'string' || id === '') return null
  if (typeof email !== 'string' || email === '') return null
  if (typeof role !== 'string' || !(roles as string[]).includes(role)) return null
  if (status !== 'pending' && status !== 'accepted' && status !== 'revoked') return null
  if (typeof expired !== 'boolean') return null
  if (typeof expiresAt !== 'string' || typeof createdAt !== 'string') return null
  return { id, email, role: role as OrganizationRole, status, expired, expiresAt, createdAt }
}

/** `GET …/invitations` — Owner and Admin only. */
export async function listInvitations(organizationId: string, signal?: AbortSignal): Promise<Invitation[]> {
  const payload = await request<{ items?: unknown }>(invitationsPath(organizationId), { signal })
  if (!Array.isArray(payload.items)) return []
  return payload.items.flatMap((item): Invitation[] => {
    const invitation = parseInvitation(item)
    return invitation === null ? [] : [invitation]
  })
}

/** `POST …/invitations` — the token only ever travels by email. */
export async function createInvitation(
  organizationId: string,
  input: { email: string; role: InviteRole },
): Promise<Invitation> {
  const payload = await request<{ invitation?: unknown }>(invitationsPath(organizationId), {
    method: 'POST',
    body: input,
  })
  const invitation = parseInvitation(payload.invitation)
  if (invitation === null) throw new Error('The invitation response did not contain a usable invitation')
  return invitation
}

/** A new token and a fresh seven-day window; the previous link stops working. */
export async function resendInvitation(organizationId: string, invitationId: string): Promise<Invitation> {
  const payload = await request<{ invitation?: unknown }>(
    invitationsPath(organizationId, invitationId, 'resend'),
    { method: 'POST' },
  )
  const invitation = parseInvitation(payload.invitation)
  if (invitation === null) throw new Error('The invitation response did not contain a usable invitation')
  return invitation
}

export async function revokeInvitation(organizationId: string, invitationId: string): Promise<Invitation> {
  const payload = await request<{ invitation?: unknown }>(invitationsPath(organizationId, invitationId), {
    method: 'DELETE',
  })
  const invitation = parseInvitation(payload.invitation)
  if (invitation === null) throw new Error('The invitation response did not contain a usable invitation')
  return invitation
}

/** `GET /api/invitations/{token}` — public, and deliberately enumeration-safe. */
export function previewInvitation(token: string, signal?: AbortSignal): Promise<InvitationPreview> {
  return request<InvitationPreview>(`/api/invitations/${encodeURIComponent(token)}${query({})}`, { signal })
}

/**
 * `POST /api/auth/accept-invitation`.
 *
 * A `401 sign_in_required` means the address already has an account and the
 * caller is not signed in as it; the screen turns that into a sign-in link
 * instead of pretending the invitation failed.
 */
export function acceptInvitation(input: {
  token: string
  password?: string
  displayName?: string
}): Promise<AcceptInvitationResult> {
  return request<AcceptInvitationResult>('/api/auth/accept-invitation', { method: 'POST', body: input })
}
