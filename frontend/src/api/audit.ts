import { query, request } from './client'
import type { AuditEvent } from './types'

function auditPath(organizationId: string): string {
  return `/api/organizations/${encodeURIComponent(organizationId)}/invoice-audit-events`
}

/**
 * One page of audit events, newest first.
 *
 * The endpoint accepts only `limit` (clamped to 100) — there is no cursor — so
 * callers page through the returned array themselves and must say so rather
 * than implying server-side paging.
 */
export async function listAuditEvents(
  organizationId: string,
  options: { invoiceId?: string | null; limit?: number; signal?: AbortSignal } = {},
): Promise<AuditEvent[]> {
  const { invoiceId, limit = 100, signal } = options
  const payload = await request<{ items?: unknown }>(
    `${auditPath(organizationId)}${query({ invoiceId, limit })}`,
    { signal },
  )
  if (!Array.isArray(payload.items)) return []
  return payload.items.flatMap((item): AuditEvent[] => {
    if (typeof item !== 'object' || item === null) return []
    const record = item as Record<string, unknown>
    const {
      id,
      invoiceId: invoice,
      customerId,
      invoiceNumber,
      customerName,
      action,
      actorPrincipalId,
      metadata,
      createdAt,
    } = record
    if (typeof id !== 'string' || id === '') return []
    if (typeof action !== 'string' || typeof createdAt !== 'string') return []
    return [{
      id,
      invoiceId: typeof invoice === 'string' ? invoice : null,
      customerId: typeof customerId === 'string' ? customerId : null,
      // Readable references, resolved by the server in two queries per page.
      invoiceNumber: typeof invoiceNumber === 'string' ? invoiceNumber : null,
      customerName: typeof customerName === 'string' ? customerName : null,
      action,
      actorPrincipalId: typeof actorPrincipalId === 'string' ? actorPrincipalId : null,
      metadata: typeof metadata === 'object' && metadata !== null ? metadata as Record<string, unknown> : null,
      createdAt,
    }]
  })
}
