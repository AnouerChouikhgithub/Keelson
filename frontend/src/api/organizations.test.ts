import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { canDeleteInvoiceResources, canWriteInvoiceResources, listOrganizations } from './organizations'
import { presentError, isConflict } from './presentError'
import { ApiError } from './errors'
import { messages } from '../i18n'

const fetchMock = vi.fn<typeof fetch>()

beforeEach(() => {
  vi.stubGlobal('fetch', fetchMock)
  fetchMock.mockReset()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('organizations API', () => {
  it('keeps only rows that match the verified shape', async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({
      items: [
        { id: 'org-1', name: 'Atelier Noura', subdomain: 'atelier-noura', role: 'Owner' },
        { id: 'org-2', name: 'No role', subdomain: 'no-role' },
        { id: '', name: 'Empty id', subdomain: 'empty', role: 'Member' },
        { id: 'org-3', name: 'Unknown role', subdomain: 'unknown', role: 'Superuser' },
      ],
    }), { status: 200, headers: { 'Content-Type': 'application/json' } }))

    const organizations = await listOrganizations()

    expect(organizations).toEqual([{ id: 'org-1', name: 'Atelier Noura', subdomain: 'atelier-noura', role: 'Owner' }])
    expect(fetchMock.mock.calls[0][0]).toBe('/api/me/organizations')
  })

  it('treats a missing items array as no memberships', async () => {
    fetchMock.mockResolvedValue(new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json' } }))

    await expect(listOrganizations()).resolves.toEqual([])
  })
})

describe('capability helpers mirroring InvoiceVoter', () => {
  it('grants writes to Owner, Admin and Member only', () => {
    expect(canWriteInvoiceResources('Owner')).toBe(true)
    expect(canWriteInvoiceResources('Admin')).toBe(true)
    expect(canWriteInvoiceResources('Member')).toBe(true)
    expect(canWriteInvoiceResources('Viewer')).toBe(false)
  })

  it('grants destructive actions to Owner and Admin only', () => {
    expect(canDeleteInvoiceResources('Owner')).toBe(true)
    expect(canDeleteInvoiceResources('Admin')).toBe(true)
    expect(canDeleteInvoiceResources('Member')).toBe(false)
    expect(canDeleteInvoiceResources('Viewer')).toBe(false)
  })
})

describe('presentError', () => {
  const m = messages.en

  it('localizes the status classes that have distinct UI states', () => {
    expect(presentError(m, new ApiError('x', 'http', 401))).toBe(m.api.unauthorized)
    expect(presentError(m, new ApiError('x', 'http', 403))).toBe(m.api.forbidden)
    expect(presentError(m, new ApiError('x', 'http', 404))).toBe(m.api.notFound)
    expect(presentError(m, new ApiError('x', 'http', 429))).toBe(m.api.rateLimited)
    expect(presentError(m, new ApiError('x', 'http', 500))).toBe(m.api.server)
  })

  it('shows the server detail for validation failures', () => {
    expect(presentError(m, new ApiError('x', 'http', 422, 'customerId must be a UUID.'))).toBe('customerId must be a UUID.')
    expect(presentError(m, new ApiError('x', 'http', 400))).toBe(m.api.invalid)
  })

  it('explains a conflict instead of echoing it, because the caller refetches', () => {
    expect(presentError(m, new ApiError('x', 'http', 409, 'Only draft invoices may be edited.'))).toBe(m.api.conflict)
  })

  it('never echoes internals for a 500', () => {
    expect(presentError(m, new ApiError('x', 'http', 500, 'SQLSTATE[23514]'))).toBe(m.api.server)
  })

  it('reports connection problems separately from server errors', () => {
    expect(presentError(m, new ApiError('x', 'network'))).toBe(m.api.offline)
    expect(presentError(m, new Error('unexpected'))).toBe(m.api.unexpected)
  })

  it('detects conflicts so callers can refetch', () => {
    expect(isConflict(new ApiError('x', 'http', 409))).toBe(true)
    expect(isConflict(new ApiError('x', 'http', 422))).toBe(false)
    expect(isConflict(new Error('x'))).toBe(false)
  })
})
