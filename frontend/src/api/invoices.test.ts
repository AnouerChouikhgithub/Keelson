import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createInvoiceDraft,
  deleteInvoiceDraft,
  listInvoices,
  markInvoicePaid,
  readInvoice,
  sendInvoice,
  updateInvoiceDraft,
} from './invoices'
import type { Invoice, InvoiceDraftInput } from './types'

const fetchMock = vi.fn<typeof fetch>()
const organizationId = '11111111-1111-4111-8111-111111111111'
const invoiceId = '33333333-3333-4333-8333-333333333333'

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

const invoice: Invoice = {
  id: invoiceId,
  number: '2026-000001',
  customerId: '22222222-2222-4222-8222-222222222222',
  customer: {
    id: '22222222-2222-4222-8222-222222222222',
    name: 'Maison Jasmin',
    email: null,
    phone: null,
    billingAddress: null,
  },
  status: 'draft',
  issueDate: '2026-11-01',
  dueDate: '2026-11-15',
  currency: 'TND',
  lineItems: [{ description: 'Work', quantity: '1.000', unitPrice: '10.000', lineTotal: '10.000' }],
  taxRate: '19.0000',
  discountAmount: '0.000',
  subtotal: '10.000',
  taxAmount: '1.900',
  total: '11.900',
  sentAt: null,
  paidAt: null,
}

const draft: InvoiceDraftInput = {
  customerId: invoice.customerId,
  issueDate: '2026-11-01',
  dueDate: '2026-11-15',
  currency: 'TND',
  lineItems: [{ description: 'Work', quantity: '1.000', unitPrice: '10.000' }],
  taxRate: '19',
  discountAmount: null,
}

beforeEach(() => {
  vi.stubGlobal('fetch', fetchMock)
  fetchMock.mockReset()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('invoices API', () => {
  it('omits the status filter for "all"', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ items: [invoice], nextCursor: 'next' }))

    const page = await listInvoices(organizationId)
    expect(page.items[0].status).toBe('draft')
    expect(page.nextCursor).toBe('next')
    expect(fetchMock.mock.calls[0][0]).toBe(`/api/organizations/${organizationId}/invoices?limit=100`)
  })

  it('passes a server-supported status filter and cursor', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ items: [], nextCursor: null }))

    await listInvoices(organizationId, { status: 'overdue', cursor: 'c1', limit: 10 })

    expect(fetchMock.mock.calls[0][0]).toBe(`/api/organizations/${organizationId}/invoices?status=overdue&cursor=c1&limit=10`)
  })

  it('reads one invoice', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ invoice }))

    await expect(readInvoice(organizationId, invoiceId)).resolves.toMatchObject({ number: '2026-000001' })
  })

  it('creates a draft with line items, tax and discount', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ invoice }, 201))

    await createInvoiceDraft(organizationId, draft)

    const [path, init] = fetchMock.mock.calls[0]
    expect(path).toBe(`/api/organizations/${organizationId}/invoices`)
    expect(init?.method).toBe('POST')
    expect(JSON.parse(String(init?.body))).toEqual(draft)
  })

  it('updates an existing draft', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ invoice }))

    await updateInvoiceDraft(organizationId, invoiceId, draft)

    expect(fetchMock.mock.calls[0][1]?.method).toBe('PATCH')
  })

  it('sends and marks paid through their own routes', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ invoice: { ...invoice, status: 'sent' } }))
    fetchMock.mockResolvedValueOnce(jsonResponse({ invoice: { ...invoice, status: 'paid' } }))

    await expect(sendInvoice(organizationId, invoiceId)).resolves.toMatchObject({ status: 'sent' })
    await expect(markInvoicePaid(organizationId, invoiceId)).resolves.toMatchObject({ status: 'paid' })

    expect(fetchMock.mock.calls[0][0]).toBe(`/api/organizations/${organizationId}/invoices/${invoiceId}/send`)
    expect(fetchMock.mock.calls[1][0]).toBe(`/api/organizations/${organizationId}/invoices/${invoiceId}/mark-paid`)
  })

  it('deletes a draft through the invoice route', async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))

    await expect(deleteInvoiceDraft(organizationId, invoiceId)).resolves.toBeUndefined()
    expect(fetchMock.mock.calls[0][1]?.method).toBe('DELETE')
  })

  it('surfaces a 409 when the draft is no longer editable', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ error: { message: 'Only draft invoices may be edited.' } }, 409))

    await expect(updateInvoiceDraft(organizationId, invoiceId, draft)).rejects.toMatchObject({
      status: 409,
      detail: 'Only draft invoices may be edited.',
    })
  })

  it('surfaces a 422 with the server calculation rule', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ error: { message: 'unitPrice supports at most 3 decimal places.' } }, 422))

    await expect(createInvoiceDraft(organizationId, draft)).rejects.toMatchObject({
      status: 422,
      detail: 'unitPrice supports at most 3 decimal places.',
    })
  })
})
