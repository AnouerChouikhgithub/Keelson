import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { listAuditEvents } from './audit'

const fetchMock = vi.fn<typeof fetch>()
const organizationId = '11111111-1111-4111-8111-111111111111'
const invoiceId = '33333333-3333-4333-8333-333333333333'

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

beforeEach(() => {
  vi.stubGlobal('fetch', fetchMock)
  fetchMock.mockReset()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('audit API', () => {
  it('reads the organization audit trail with a bounded limit', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ items: [] }))

    await listAuditEvents(organizationId)

    expect(fetchMock.mock.calls[0][0]).toBe(`/api/organizations/${organizationId}/invoice-audit-events?limit=100`)
  })

  it('scopes the request to one invoice and skips an empty filter', async () => {
    // A fresh response per call: a Response body can only be read once.
    fetchMock.mockImplementation(() => Promise.resolve(jsonResponse({ items: [] })))
    await listAuditEvents(organizationId, { invoiceId })
    expect(fetchMock.mock.calls[0][0]).toBe(`/api/organizations/${organizationId}/invoice-audit-events?invoiceId=${invoiceId}&limit=100`)

    fetchMock.mockClear()
    await listAuditEvents(organizationId, { invoiceId: null, limit: 25 })
    expect(fetchMock.mock.calls[0][0]).toBe(`/api/organizations/${organizationId}/invoice-audit-events?limit=25`)
  })

  it('keeps only rows with an id, an action and a timestamp', async () => {
    fetchMock.mockResolvedValue(jsonResponse({
      items: [
        {
          id: 'event-1',
          invoiceId,
          customerId: null,
          invoiceNumber: '2026-000007',
          customerName: null,
          action: 'invoice.sent',
          actorPrincipalId: 'principal-1',
          metadata: { channel: 'api' },
          createdAt: '2026-11-16T09:30:00+00:00',
        },
        { id: '', action: 'invoice.paid', createdAt: '2026-11-16T09:30:00+00:00' },
        { id: 'event-2', action: 'invoice.paid' },
        { id: 'event-3', action: 'invoice.created', createdAt: '2026-11-16T09:30:00+00:00' },
      ],
    }))

    const events = await listAuditEvents(organizationId)

    expect(events).toEqual([
      {
        id: 'event-1',
        invoiceId,
        customerId: null,
        invoiceNumber: '2026-000007',
        customerName: null,
        action: 'invoice.sent',
        actorPrincipalId: 'principal-1',
        metadata: { channel: 'api' },
        createdAt: '2026-11-16T09:30:00+00:00',
      },
      {
        id: 'event-3',
        invoiceId: null,
        customerId: null,
        invoiceNumber: null,
        customerName: null,
        action: 'invoice.created',
        actorPrincipalId: null,
        metadata: null,
        createdAt: '2026-11-16T09:30:00+00:00',
      },
    ])
  })

  it('treats a body without items as an empty page', async () => {
    fetchMock.mockResolvedValue(jsonResponse({}))

    await expect(listAuditEvents(organizationId)).resolves.toEqual([])
  })

  it('maps a denial for a foreign tenant to the 404 the API returns', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ error: { code: 'not_found', message: 'Resource not found.' } }, 404))

    await expect(listAuditEvents(organizationId)).rejects.toMatchObject({ status: 404, code: 'not_found' })
  })
})
