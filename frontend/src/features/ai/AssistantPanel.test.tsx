import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { requests } from '../../test/fetchMock'
import { installFetch } from '../../test/fetchMock'
import AssistantLauncher from './AssistantLauncher'
import { AssistantPanel } from './AssistantPanel'
import { AiProviders } from './testRender'

afterEach(() => {
  vi.unstubAllGlobals()
})

const approvedStatus = {
  enabled: true,
  organizationEnabled: true,
  budgetRemainingTokens: 42000,
}

const guideAnswer = {
  answer: 'Open the invoice and choose Mark paid.',
  sources: [{ id: 'invoice-lifecycle', title: 'Invoices' }],
}

/** An opener button, so focus restoration can be asserted on a real element. */
function Harness() {
  const [open, setOpen] = useState(false)
  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>Open assistant</button>
      {open && <AssistantPanel organizationId="org-panel" onClose={() => setOpen(false)} />}
    </>
  )
}

describe('AssistantLauncher', () => {
  it('renders the header button when the workspace may use AI', async () => {
    installFetch([{ path: '/api/ai/status', method: 'GET', json: approvedStatus }])

    render(
      <AiProviders>
        <AssistantLauncher organizationId="org-launcher-on" />
      </AiProviders>,
    )

    expect(await screen.findByRole('button', { name: 'Ask Keelson' })).toBeVisible()
  })

  it('renders nothing when AI is disabled for the workspace', async () => {
    const fetchMock = installFetch([
      { path: '/api/ai/status', method: 'GET', json: { ...approvedStatus, organizationEnabled: false } },
    ])

    render(
      <AiProviders>
        <AssistantLauncher organizationId="org-launcher-off" />
      </AiProviders>,
    )

    await waitFor(() => expect(fetchMock).toHaveBeenCalled())
    await act(async () => { await Promise.resolve() })
    // The providers always render the toast region, so absence is asserted on
    // the AI control itself.
    expect(screen.queryByRole('button', { name: 'Ask Keelson' })).toBeNull()
  })

  it('renders nothing and no error when the status call fails', async () => {
    const fetchMock = installFetch([{ path: '/api/ai/status', method: 'GET', status: 503, json: {} }])

    render(
      <AiProviders>
        <AssistantLauncher organizationId="org-launcher-failed" />
      </AiProviders>,
    )

    await waitFor(() => expect(fetchMock).toHaveBeenCalled())
    await act(async () => { await Promise.resolve() })
    expect(screen.queryByRole('button', { name: 'Ask Keelson' })).toBeNull()
    expect(screen.queryByText(/unexpected response/i)).toBeNull()
  })
})

describe('AssistantPanel', () => {
  it('opens with focus inside, closes on Escape and returns focus to the opener', async () => {
    const user = userEvent.setup()
    render(
      <AiProviders>
        <Harness />
      </AiProviders>,
    )
    const opener = screen.getByRole('button', { name: 'Open assistant' })

    await user.click(opener)

    const dialog = screen.getByRole('dialog', { name: 'Keelson assistant' })
    expect(dialog).toHaveAttribute('aria-modal', 'true')
    expect(screen.getByLabelText('Your question')).toHaveFocus()
    expect(screen.getByRole('button', { name: 'Ask' })).toBeDisabled()

    fireEvent.keyDown(document, { key: 'Escape' })

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(opener).toHaveFocus()
  })

  it('announces the answer in a live region and labels it as AI-generated', async () => {
    const fetchMock = installFetch([
      { path: '/api/ai/guide', method: 'POST', json: guideAnswer },
    ])
    const user = userEvent.setup()
    render(
      <AiProviders>
        <Harness />
      </AiProviders>,
    )
    await user.click(screen.getByRole('button', { name: 'Open assistant' }))

    await user.type(screen.getByLabelText('Your question'), 'How do I mark an invoice paid?')
    await user.click(screen.getByRole('button', { name: 'Ask' }))

    const answer = await screen.findByText(guideAnswer.answer)
    expect(answer.closest('[aria-live="polite"]')).not.toBeNull()
    expect(screen.getByText('AI-generated')).toBeVisible()
    expect(screen.getByText('Sources')).toBeVisible()
    expect(screen.getByText('Invoices')).toBeVisible()
    expect(screen.getByText('invoice-lifecycle')).toBeVisible()
    expect(requests(fetchMock)[0]?.body).toEqual({
      question: 'How do I mark an invoice paid?',
      organizationId: 'org-panel',
      locale: 'en',
    })
  })

  it('asks the same question again when regenerating', async () => {
    const fetchMock = installFetch([{ path: '/api/ai/guide', method: 'POST', json: guideAnswer }])
    const user = userEvent.setup()
    render(
      <AiProviders>
        <Harness />
      </AiProviders>,
    )
    await user.click(screen.getByRole('button', { name: 'Open assistant' }))
    await user.type(screen.getByLabelText('Your question'), 'Where are my invoices?')
    await user.click(screen.getByRole('button', { name: 'Ask' }))
    await screen.findByText(guideAnswer.answer)

    await user.click(screen.getByRole('button', { name: 'Ask again' }))

    await waitFor(() => expect(requests(fetchMock)).toHaveLength(2))
    expect(requests(fetchMock)[1]?.body).toEqual({
      question: 'Where are my invoices?',
      organizationId: 'org-panel',
      locale: 'en',
    })
  })

  it('shows the localized message when the server has not implemented the guide yet', async () => {
    installFetch([
      { path: '/api/ai/guide', method: 'POST', status: 501, json: { error: { code: 'not_implemented', message: 'Not yet.' } } },
    ])
    const user = userEvent.setup()
    render(
      <AiProviders>
        <Harness />
      </AiProviders>,
    )
    await user.click(screen.getByRole('button', { name: 'Open assistant' }))
    await user.type(screen.getByLabelText('Your question'), 'Anything?')
    await user.click(screen.getByRole('button', { name: 'Ask' }))

    expect(await screen.findByText('AI answers are not available on this server yet.')).toBeVisible()
    expect(screen.queryByText('AI-generated')).toBeNull()
  })
})
