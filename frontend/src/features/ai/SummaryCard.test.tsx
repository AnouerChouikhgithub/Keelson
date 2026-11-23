import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { installFetch, requests } from '../../test/fetchMock'
import { SummaryCard } from './SummaryCard'
import { AiProviders } from './testRender'

afterEach(() => {
  vi.unstubAllGlobals()
  window.localStorage.clear()
})

const summary = {
  text: 'Overview: 7 customers, 18 invoices, 3 overdue and 11,956.600 TND outstanding.',
  generatedAt: '2026-11-18T00:23:18+00:00',
  fallback: false,
  facts: {
    kind: 'overview',
    role: 'Owner',
    locale: 'en',
    currency: 'TND',
    customerCount: 7,
    invoiceCount: 18,
    overdueInvoiceCount: 3,
    outstandingMillimes: 11956600,
    asOf: '2026-11-18',
    customerNames: ['Atlas Harbour Supplies (fixture)'],
  },
}

async function generateAndRead(extra: Record<string, unknown> = {}) {
  installFetch([{ path: '/ai/summaries/overview', method: 'POST', json: { ...summary, ...extra } }])
  const user = userEvent.setup()
  const view = render(
    <AiProviders>
      <SummaryCard organizationId="org-summary" kind="overview" />
    </AiProviders>,
  )
  // The Arabic run renders localized labels, so the card's only button is
  // found structurally rather than by its English name. `.ai-summary` also
  // excludes the toast region, which is a second landmark.
  const card = document.querySelector('.ai-summary')
  await user.click(within(card as HTMLElement).getByRole('button'))
  await screen.findByText(summary.text)
  return view
}

describe('SummaryCard', () => {
  it('generates on request and shows the facts above the sentence', async () => {
    const fetchMock = installFetch([
      { path: '/ai/summaries/overview', method: 'POST', json: summary },
    ])
    const user = userEvent.setup()
    render(
      <AiProviders>
        <SummaryCard organizationId="org-summary" kind="overview" />
      </AiProviders>,
    )

    // Nothing is generated on mount: every call spends the workspace budget.
    expect(fetchMock).not.toHaveBeenCalled()

    await user.click(screen.getByRole('button', { name: 'Generate summary' }))
    await screen.findByText(summary.text)

    const section = screen.getByRole('region', { name: 'Workspace summary' })
    const text = section.textContent ?? ''
    expect(text.indexOf('Overdue invoices')).toBeGreaterThanOrEqual(0)
    expect(text.indexOf('Overdue invoices')).toBeLessThan(text.indexOf(summary.text))
    expect(screen.getByText('AI-generated')).toBeVisible()
    // The request metadata is not a workspace figure.
    expect(within(section).queryByText('Owner')).toBeNull()
    expect(within(section).queryByText('TND')).toBeNull()
    expect(requests(fetchMock)[0]).toMatchObject({
      method: 'POST',
      path: '/api/organizations/org-summary/ai/summaries/overview',
      body: { locale: 'en' },
    })
  })

  it('hides the AI label when the text is the deterministic fallback', async () => {
    await generateAndRead({ fallback: true })
    expect(screen.queryByText('AI-generated')).toBeNull()
    expect(screen.getByText(summary.text)).toBeVisible()
  })

  it('formats counts, millimes and ISO dates through the active locale in Arabic', async () => {
    window.localStorage.setItem('keelson-locale', 'ar')
    await generateAndRead()

    expect(document.documentElement.dir).toBe('rtl')
    // Compared against `Intl` rather than a literal digit shape: CLDR decides
    // whether `ar` uses Arabic-Indic or Latin digits, and the card must follow
    // the formatter instead of hardcoding either.
    expect(screen.getByText(new Intl.NumberFormat('ar', { maximumFractionDigits: 0 }).format(3))).toBeVisible()
    // The millimes become a currency amount, which proves `currency()` ran.
    expect(screen.getByText(/د\.ت\./)).toBeVisible()
  })

  it('shows the localized budget message instead of the sentence', async () => {
    installFetch([
      { path: '/ai/summaries/activity', method: 'POST', status: 402, json: { error: { code: 'ai_budget_exceeded', message: 'x' } } },
    ])
    const user = userEvent.setup()
    render(
      <AiProviders>
        <SummaryCard organizationId="org-summary-budget" kind="activity" />
      </AiProviders>,
    )

    await user.click(screen.getByRole('button', { name: 'Generate summary' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'This workspace has used its AI budget for this month.',
    )
    expect(screen.queryByText(summary.text)).toBeNull()
  })
})
