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
  text: 'Three invoices are overdue and one customer accounts for most of the balance.',
  generatedAt: '2026-12-01T10:15:30+00:00',
  facts: { overdueInvoices: 3, outstandingTotal: '1240.000' },
}

async function generateAndRead(extra: Record<string, unknown> = {}) {
  installFetch([{ path: '/ai/summaries/organization', method: 'POST', json: { ...summary, ...extra } }])
  const user = userEvent.setup()
  const view = render(
    <AiProviders>
      <SummaryCard organizationId="org-summary" kind="organization" />
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
      { path: '/ai/summaries/organization', method: 'POST', json: summary },
    ])
    const user = userEvent.setup()
    render(
      <AiProviders>
        <SummaryCard organizationId="org-summary" kind="organization" />
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
    expect(requests(fetchMock)[0]).toMatchObject({
      method: 'POST',
      path: '/api/organizations/org-summary/ai/summaries/organization',
    })
  })

  it('hides the AI label when the text is a deterministic fallback', async () => {
    await generateAndRead({ fallback: true })
    expect(screen.queryByText('AI-generated')).toBeNull()
    expect(screen.getByText(summary.text)).toBeVisible()
  })

  it('formats figures through the active locale in Arabic', async () => {
    window.localStorage.setItem('keelson-locale', 'ar')
    await generateAndRead()

    expect(document.documentElement.dir).toBe('rtl')
    // Compared against `Intl` rather than a literal digit shape: CLDR decides
    // whether `ar` uses Arabic-Indic or Latin digits, and the card must follow
    // the formatter instead of hardcoding either.
    expect(screen.getByText(new Intl.NumberFormat('ar', { maximumFractionDigits: 0 }).format(3))).toBeVisible()
    // The amount carries the Arabic TND symbol, which proves `currency()` ran.
    expect(screen.getByText(/د\.ت\./)).toBeVisible()
  })

  it('shows the localized budget message instead of the sentence', async () => {
    installFetch([
      { path: '/ai/summaries/customers', method: 'POST', status: 402, json: { error: { code: 'ai_budget_exceeded', message: 'x' } } },
    ])
    const user = userEvent.setup()
    render(
      <AiProviders>
        <SummaryCard organizationId="org-summary-budget" kind="customers" />
      </AiProviders>,
    )

    await user.click(screen.getByRole('button', { name: 'Generate summary' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'This workspace has used its AI budget for this month.',
    )
    expect(screen.queryByText(summary.text)).toBeNull()
  })
})
