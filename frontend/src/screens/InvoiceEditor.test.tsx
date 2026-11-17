import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { UserEvent } from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { OrganizationMembership, OrganizationRole } from '../api/types'
import { clearApiCache } from '../api/useApiResource'
import { WorkspaceContext } from '../app/WorkspaceContext'
import { ToastProvider } from '../components/Toast'
import { en } from '../i18n/en'
import { I18nProvider } from '../i18n/I18nProvider'
import { installFetch, requests } from '../test/fetchMock'
import type { FetchRoute } from '../test/fetchMock'
import InvoiceEditor from './InvoiceEditor'

const orgId = '11111111-1111-4111-8111-111111111111'
const customerId = '22222222-2222-4222-8222-222222222222'

/** `Field` appends its required marker to the label, so match by prefix. */
const field = (label: string) => screen.getByLabelText(label, { exact: false })

function membership(role: OrganizationRole): OrganizationMembership {
  return { id: orgId, name: 'Keelson SARL', subdomain: 'keelson', role }
}

function renderEditor(role: OrganizationRole = 'Owner') {
  const value = { membership: membership(role), memberships: [membership(role)], refreshMemberships: () => {} }
  const view = render(
    <I18nProvider>
      <ToastProvider>
        <WorkspaceContext.Provider value={value}>
          <MemoryRouter initialEntries={[`/o/${orgId}/invoices/new`]}>
            <Routes>
              <Route path="/o/:orgId/invoices/new" element={<InvoiceEditor />} />
            </Routes>
          </MemoryRouter>
        </WorkspaceContext.Provider>
      </ToastProvider>
    </I18nProvider>,
  )
  return { ...view, user: userEvent.setup() }
}

const customers: FetchRoute = {
  path: '/customers?limit=100',
  method: 'GET',
  json: {
    items: [{ id: customerId, name: 'Maison Jasmin', email: 'factures@maisonjasmin.test', phone: null }],
    nextCursor: null,
  },
}

async function fillLine(user: UserEvent, quantity: string, unitPrice: string) {
  await user.clear(field(en.invoices.editor.quantity))
  await user.type(field(en.invoices.editor.quantity), quantity)
  await user.clear(field(en.invoices.editor.unitPrice))
  await user.type(field(en.invoices.editor.unitPrice), unitPrice)
}

beforeEach(() => {
  clearApiCache()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('InvoiceEditor', () => {
  it('adds line totals, tax and discount into a three-decimal TND total', async () => {
    installFetch([customers])
    const { user, container } = renderEditor()

    await screen.findByLabelText(en.invoices.editor.customer, { exact: false })
    await user.selectOptions(field(en.invoices.editor.customer), customerId)
    await fillLine(user, '2', '10')
    await user.clear(field(en.invoices.editor.discount))
    await user.type(field(en.invoices.editor.discount), '5')

    // Formatters emit a non-breaking space before the currency, so compare the
    // amount itself rather than the whole string.
    expect(container.querySelector('.line-total')?.textContent).toContain('20.000')
    // 2 × 10 = 20, 19 % tax = 3.800, minus a 5.000 discount. The formatter puts a
    // non-breaking space before the currency, so match the amount only.
    const totals = container.querySelector('.preview-totals')?.textContent ?? ''
    expect(totals).toMatch(/20\.000/)
    expect(totals).toMatch(/3\.800/)
    expect(totals).toMatch(/18\.800/)
  })

  it('renders amounts with exactly three decimals, rounding the display', async () => {
    installFetch([customers])
    const { user, container } = renderEditor()

    await screen.findByLabelText(en.invoices.editor.customer, { exact: false })
    await fillLine(user, '1', '0.0004')

    // The estimate keeps full precision; the invoice shows TND's three decimals,
    // so a sub-millime amount is displayed as zero, never as "0.0004".
    expect(container.querySelector('.line-total')?.textContent).toContain('0.000')
    expect(container.querySelector('.line-total')?.textContent).not.toContain('0.0004')
  })

  it('blocks a submit that has no customer and no description', async () => {
    const fetchMock = installFetch([customers])
    const { user, container } = renderEditor()

    await screen.findByLabelText(en.invoices.editor.customer, { exact: false })
    await user.click(screen.getByRole('button', { name: en.invoices.editor.saveDraft }))

    const errors = [...container.querySelectorAll('.field-error')].map((element) => element.textContent)
    expect(errors).toContain(en.invoices.editor.selectCustomer)
    expect(errors).toContain(en.invoices.editor.emptyLine)
    expect(requests(fetchMock).some((call) => call.method === 'POST')).toBe(false)
  })

  it('shows the forbidden state instead of the form for a viewer', async () => {
    const fetchMock = installFetch([customers])
    renderEditor('Viewer')

    expect(await screen.findByText(en.invoices.editor.forbiddenTitle)).toBeInTheDocument()
    expect(screen.queryByLabelText(en.invoices.editor.customer, { exact: false })).not.toBeInTheDocument()
    expect(requests(fetchMock).some((call) => call.method !== 'GET')).toBe(false)
  })
})
