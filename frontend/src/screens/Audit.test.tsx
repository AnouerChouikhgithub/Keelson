import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { clearApiCache } from '../api/useApiResource'
import { WorkspaceContext } from '../app/WorkspaceContext'
import { ToastProvider } from '../components/Toast'
import { I18nProvider } from '../i18n/I18nProvider'
import { AuthProvider } from '../session/AuthProvider'
import { authenticatedSession, installFetch } from '../test/fetchMock'
import type { FetchRoute } from '../test/fetchMock'
import Audit from './Audit'

const orgId = '11111111-1111-4111-8111-111111111111'
const selfId = 'principal-self'
const invoiceId = '22222222-2222-4222-8222-222222222222'

const sent = {
  id: 'event-sent',
  invoiceId,
  customerId: '33333333-3333-4333-8333-333333333333',
  invoiceNumber: '2026-000042',
  customerName: 'Atlas Harbour Supplies (fixture)',
  action: 'invoice.sent',
  actorPrincipalId: selfId,
  metadata: {},
  createdAt: '2026-11-20T09:00:00+00:00',
}

// Its invoice no longer exists: the server resolves nothing, the row keeps the id.
const deleted = {
  ...sent,
  id: 'event-deleted',
  invoiceId: '44444444-4444-4444-8444-444444444444',
  invoiceNumber: null,
  customerName: null,
  action: 'invoice.deleted',
}

function renderAudit() {
  const membership = { id: orgId, name: 'Keelson SARL', subdomain: 'keelson', role: 'Owner' as const }
  const routes: FetchRoute[] = [
    authenticatedSession(selfId),
    { path: '/invoice-audit-events', method: 'GET', json: { items: [sent, deleted], nextCursor: null } },
  ]
  installFetch(routes)
  render(
    <I18nProvider>
      <AuthProvider>
        <ToastProvider>
          <WorkspaceContext.Provider value={{ membership, memberships: [membership], refreshMemberships: () => {} }}>
            <MemoryRouter initialEntries={[`/o/${orgId}/audit`]}>
              <Audit />
            </MemoryRouter>
          </WorkspaceContext.Provider>
        </ToastProvider>
      </AuthProvider>
    </I18nProvider>,
  )
}

beforeEach(() => {
  clearApiCache()
  window.localStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('Audit', () => {
  it('shows a localized label and keeps the raw code underneath', async () => {
    renderAudit()

    const label = await screen.findByText('Invoice sent')
    const cell = label.closest('td')
    expect(cell).not.toBeNull()
    // The stable code is still there, for search and for support.
    expect(within(cell as HTMLElement).getByText('invoice.sent')).toBeVisible()
    expect(screen.getByText('Invoice deleted')).toBeVisible()
  })

  it('shows the invoice number and customer name instead of hex ids', async () => {
    renderAudit()

    const link = await screen.findByRole('link', { name: '2026-000042' })
    expect(link).toHaveAttribute('href', `/o/${orgId}/invoices/${invoiceId}`)
    // The full id stays available without being printed.
    expect(link).toHaveAttribute('title', invoiceId)
    expect(screen.getByText('Atlas Harbour Supplies (fixture)')).toBeVisible()
  })

  it('falls back to the short id when the invoice is gone', async () => {
    renderAudit()

    const link = await screen.findByRole('link', { name: '44444444' })
    expect(link).toHaveTextContent('44444444')
    expect(screen.queryByText('2026-000042')).not.toBeNull()
  })
})
