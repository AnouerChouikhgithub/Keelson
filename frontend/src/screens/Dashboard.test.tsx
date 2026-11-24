import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { clearApiCache } from '../api/useApiResource'
import type { Dashboard as DashboardPayload, Invoice } from '../api/types'
import { WorkspaceContext } from '../app/WorkspaceContext'
import { en } from '../i18n/en'
import { I18nProvider } from '../i18n/I18nProvider'
import { installFetch, requests } from '../test/fetchMock'
import Dashboard from './Dashboard'

/**
 * F-01: the dashboard's headline figures must come from `GET …/dashboard`.
 *
 * It used to load `listInvoices({ limit: 100 })` and sum in the browser, so every
 * figure silently ignored invoice 101 onward. The stub below throws on any
 * unstubbed request, which is what makes "no invoice list request" an assertion
 * rather than a hope.
 */

const membership = { id: 'org-1', name: 'Northwind', subdomain: 'northwind', role: 'Owner' as const }

function sampleInvoice(overrides: Partial<Invoice> = {}): Invoice {
  return {
    id: 'inv-1',
    number: '2026-000001',
    customerId: 'cus-1',
    customer: { id: 'cus-1', name: 'Acme SARL' },
    status: 'sent',
    issueDate: '2026-11-02',
    dueDate: '2026-11-16',
    currency: 'TND',
    lineItems: [],
    taxRate: null,
    discountAmount: null,
    subtotal: '100.000',
    taxAmount: '0.000',
    total: '100.000',
    sentAt: '2026-11-02T09:00:00+00:00',
    paidAt: null,
    ...overrides,
  } as Invoice
}

function payload(overrides: Partial<DashboardPayload> = {}): DashboardPayload {
  return {
    outstandingMillimes: 5000000,
    overdueMillimes: 2500000,
    overdueCount: 42,
    paidThisMonthMillimes: 1500000,
    paidThisMonthCount: 7,
    issuedThisMonthCount: 20,
    paidRateThisMonthPercent: 35,
    customerCount: 12,
    statusCounts: { draft: 3, sent: 100, overdue: 42, paid: 105 },
    revenueByMonth: Array.from({ length: 12 }, (_, index) => ({
      month: `2026-${String(index + 1).padStart(2, '0')}`,
      paidMillimes: index === 10 ? 40000 : 0,
    })),
    recentInvoices: [sampleInvoice()],
    ...overrides,
  }
}

function renderDashboard() {
  return render(
    <I18nProvider>
      <WorkspaceContext.Provider
        value={{ membership, memberships: [membership], refreshMemberships: () => {} }}
      >
        <MemoryRouter initialEntries={['/o/org-1/dashboard']}>
          <Dashboard />
        </MemoryRouter>
      </WorkspaceContext.Provider>
    </I18nProvider>,
  )
}

beforeEach(() => {
  clearApiCache()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('Dashboard', () => {
  it('reads every figure from the dashboard endpoint', async () => {
    const fetchMock = installFetch([
      { path: '/dashboard', method: 'GET', json: payload() },
      { path: '/api/ai/status', method: 'GET', json: { enabled: false, organizationEnabled: false, budgetRemainingTokens: 0 } },
    ])

    renderDashboard()

    // 5,000.000 TND outstanding — a figure only the server can know.
    expect(await screen.findByText('TND 5,000.000')).toBeInTheDocument()
    expect(screen.getByText('TND 2,500.000')).toBeInTheDocument()
    expect(screen.getByText('TND 1,500.000')).toBeInTheDocument()
    expect(screen.getByText('35%')).toBeInTheDocument()
    // 7 invoices paid this month, from the server's count.
    expect(screen.getByText(en.dashboard.changeInvoices(7))).toBeInTheDocument()
    // 142 open invoices (100 sent + 42 overdue) is more than one page of a list.
    expect(screen.getByText(en.dashboard.statusCount(142))).toBeInTheDocument()

    const paths = requests(fetchMock).map((call) => call.path)
    expect(paths.some((path) => path.includes('/api/organizations/org-1/dashboard'))).toBe(true)
    expect(paths.some((path) => path.includes('/invoices'))).toBe(false)
  })

  it('shows the empty state for a workspace with no invoices', async () => {
    installFetch([
      {
        path: '/dashboard',
        method: 'GET',
        json: payload({
          outstandingMillimes: 0,
          overdueMillimes: 0,
          overdueCount: 0,
          paidThisMonthMillimes: 0,
          paidThisMonthCount: 0,
          issuedThisMonthCount: 0,
          paidRateThisMonthPercent: 0,
          statusCounts: { draft: 0, sent: 0, overdue: 0, paid: 0 },
          revenueByMonth: Array.from({ length: 12 }, (_, index) => ({
            month: `2026-${String(index + 1).padStart(2, '0')}`,
            paidMillimes: 0,
          })),
          recentInvoices: [],
        }),
      },
      { path: '/api/ai/status', method: 'GET', json: { enabled: false, organizationEnabled: false, budgetRemainingTokens: 0 } },
    ])

    renderDashboard()

    expect(await screen.findByText(en.dashboard.emptyTitle)).toBeInTheDocument()
    expect(screen.getByText(en.dashboard.chartEmpty)).toBeInTheDocument()
  })
})
