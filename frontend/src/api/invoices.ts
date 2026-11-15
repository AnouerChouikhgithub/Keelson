import { query, request } from './client'
import type { Invoice, InvoiceDraftInput, InvoiceStatus, Paginated } from './types'

/** Status filter values the backend accepts; anything else is a `400`. */
export type InvoiceStatusFilter = 'all' | InvoiceStatus

function invoicesPath(organizationId: string, invoiceId?: string): string {
  const base = `/api/organizations/${encodeURIComponent(organizationId)}/invoices`
  return invoiceId === undefined ? base : `${base}/${encodeURIComponent(invoiceId)}`
}

/**
 * One page of invoices. Overdue is derived server-side from the due date, so the
 * `overdue` filter and the returned `status` agree without the UI recomputing.
 */
export function listInvoices(
  organizationId: string,
  options: { status?: InvoiceStatusFilter; cursor?: string | null; limit?: number; signal?: AbortSignal } = {},
): Promise<Paginated<Invoice>> {
  const { status = 'all', cursor, limit = 100, signal } = options
  return request<Paginated<Invoice>>(
    `${invoicesPath(organizationId)}${query({ status: status === 'all' ? null : status, cursor, limit })}`,
    { signal },
  )
}

export async function readInvoice(
  organizationId: string,
  invoiceId: string,
  signal?: AbortSignal,
): Promise<Invoice> {
  const payload = await request<{ invoice: Invoice }>(invoicesPath(organizationId, invoiceId), { signal })
  return payload.invoice
}

export async function createInvoiceDraft(
  organizationId: string,
  input: InvoiceDraftInput,
): Promise<Invoice> {
  const payload = await request<{ invoice: Invoice }>(invoicesPath(organizationId), { method: 'POST', body: input })
  return payload.invoice
}

/** `409` when the invoice is no longer a draft; `422` for invalid input. */
export async function updateInvoiceDraft(
  organizationId: string,
  invoiceId: string,
  input: InvoiceDraftInput,
): Promise<Invoice> {
  const payload = await request<{ invoice: Invoice }>(invoicesPath(organizationId, invoiceId), {
    method: 'PATCH',
    body: input,
  })
  return payload.invoice
}

/** Idempotent on the server: sending a sent invoice returns it unchanged. */
export async function sendInvoice(organizationId: string, invoiceId: string): Promise<Invoice> {
  const payload = await request<{ invoice: Invoice }>(`${invoicesPath(organizationId, invoiceId)}/send`, { method: 'POST' })
  return payload.invoice
}

/** Bookkeeping transition only — no money is collected. Idempotent. */
export async function markInvoicePaid(organizationId: string, invoiceId: string): Promise<Invoice> {
  const payload = await request<{ invoice: Invoice }>(`${invoicesPath(organizationId, invoiceId)}/mark-paid`, { method: 'POST' })
  return payload.invoice
}

/** `409` unless the invoice is still a draft. */
export function deleteInvoiceDraft(organizationId: string, invoiceId: string): Promise<void> {
  return request<void>(invoicesPath(organizationId, invoiceId), { method: 'DELETE' })
}
