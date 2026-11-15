import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createCustomer, deleteCustomer, listCustomers, readCustomer, updateCustomer } from './customers'
import type { CustomerInput } from './types'

const fetchMock = vi.fn<typeof fetch>()
const organizationId = '11111111-1111-4111-8111-111111111111'
const customerId = '22222222-2222-4222-8222-222222222222'

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

const customer = {
  id: customerId,
  name: 'Maison Jasmin',
  email: 'facturation@maisonjasmin.tn',
  phone: '+216 71 245 180',
  billingAddress: { street: '12 rue de Marseille', city: 'Tunis', postalCode: '1000', countryCode: 'TN' },
}

beforeEach(() => {
  vi.stubGlobal('fetch', fetchMock)
  fetchMock.mockReset()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('customers API', () => {
  it('lists customers inside the organization path with a bounded limit', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ items: [{ id: customerId, name: customer.name, email: null, phone: null }], nextCursor: null }))

    const page = await listCustomers(organizationId)

    expect(page.items).toHaveLength(1)
    expect(page.nextCursor).toBeNull()
    const [path] = fetchMock.mock.calls[0]
    expect(path).toBe(`/api/organizations/${organizationId}/customers?limit=100`)
  })

  it('passes the cursor for the next page', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ items: [], nextCursor: null }))

    await listCustomers(organizationId, { cursor: 'abc', limit: 25 })

    expect(fetchMock.mock.calls[0][0]).toBe(`/api/organizations/${organizationId}/customers?cursor=abc&limit=25`)
  })

  it('reads one customer and unwraps the envelope', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ customer }))

    await expect(readCustomer(organizationId, customerId)).resolves.toMatchObject({ id: customerId, name: 'Maison Jasmin' })
    expect(fetchMock.mock.calls[0][0]).toBe(`/api/organizations/${organizationId}/customers/${customerId}`)
  })

  it('creates a customer with the exact payload the API validates', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ customer }, 201))
    const input: CustomerInput = {
      name: 'Maison Jasmin',
      email: 'facturation@maisonjasmin.tn',
      phone: null,
      billingAddress: { street: '12 rue de Marseille', city: 'Tunis', postalCode: '1000', countryCode: 'TN' },
    }

    await expect(createCustomer(organizationId, input)).resolves.toMatchObject({ id: customerId })

    const [path, init] = fetchMock.mock.calls[0]
    expect(path).toBe(`/api/organizations/${organizationId}/customers`)
    expect(init?.method).toBe('POST')
    expect(JSON.parse(String(init?.body))).toEqual(input)
  })

  it('patches only the fields it was given', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ customer }))

    await updateCustomer(organizationId, customerId, { phone: '+216 70 000 000' })

    const [, init] = fetchMock.mock.calls[0]
    expect(init?.method).toBe('PATCH')
    expect(JSON.parse(String(init?.body))).toEqual({ phone: '+216 70 000 000' })
  })

  it('deletes a customer and accepts the empty 204', async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))

    await expect(deleteCustomer(organizationId, customerId)).resolves.toBeUndefined()
    expect(fetchMock.mock.calls[0][1]?.method).toBe('DELETE')
  })

  it('surfaces the 409 conflict raised when invoices reference the customer', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ error: { message: 'Customer is referenced by invoices.' } }, 409))

    await expect(deleteCustomer(organizationId, customerId)).rejects.toMatchObject({
      status: 409,
      detail: 'Customer is referenced by invoices.',
    })
  })
})
