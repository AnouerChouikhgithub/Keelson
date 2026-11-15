import { query, request } from './client'
import type { Customer, CustomerInput, CustomerPatch, CustomerSummary, Paginated } from './types'

function customerPath(organizationId: string, customerId?: string): string {
  const base = `/api/organizations/${encodeURIComponent(organizationId)}/customers`
  return customerId === undefined ? base : `${base}/${encodeURIComponent(customerId)}`
}

/**
 * One page of customers. The backend pages by opaque cursor over customer IDs
 * and caps `limit` at 100; an empty cursor asks for the first page.
 */
export function listCustomers(
  organizationId: string,
  options: { cursor?: string | null; limit?: number; signal?: AbortSignal } = {},
): Promise<Paginated<CustomerSummary>> {
  const { cursor, limit = 100, signal } = options
  return request<Paginated<CustomerSummary>>(
    `${customerPath(organizationId)}${query({ cursor, limit })}`,
    { signal },
  )
}

export async function readCustomer(
  organizationId: string,
  customerId: string,
  signal?: AbortSignal,
): Promise<Customer> {
  const payload = await request<{ customer: Customer }>(customerPath(organizationId, customerId), { signal })
  return payload.customer
}

export async function createCustomer(
  organizationId: string,
  input: CustomerInput,
): Promise<Customer> {
  const payload = await request<{ customer: Customer }>(customerPath(organizationId), { method: 'POST', body: input })
  return payload.customer
}

export async function updateCustomer(
  organizationId: string,
  customerId: string,
  patch: CustomerPatch,
): Promise<Customer> {
  const payload = await request<{ customer: Customer }>(customerPath(organizationId, customerId), {
    method: 'PATCH',
    body: patch,
  })
  return payload.customer
}

/** `204` on success; a customer referenced by invoices is `409`. */
export function deleteCustomer(organizationId: string, customerId: string): Promise<void> {
  return request<void>(customerPath(organizationId, customerId), { method: 'DELETE' })
}
