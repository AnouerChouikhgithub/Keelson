import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
// The card deep-links from the dashboard hint, so it reads `useLocation`.
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { installFetch, requests } from '../../test/fetchMock'
import AssistantLauncher from './AssistantLauncher'
import { AiSettingsSection } from './AiSettingsSection'
import { AiProviders } from './testRender'

afterEach(() => {
  vi.unstubAllGlobals()
})

const enabledDeployment = { enabled: true, organizationEnabled: false, budgetRemainingTokens: 0 }
const storedSettings = { enabled: false, monthlyTokenBudget: 50000, budgetRemainingTokens: 50000 }

function routes(status = enabledDeployment, settings = storedSettings) {
  return [
    { path: '/api/ai/status', method: 'GET', json: status },
    { path: '/ai-settings', method: 'GET', json: settings },
    { path: '/ai-settings', method: 'PUT', json: { ...settings, enabled: true } },
  ]
}

describe('AiSettingsSection', () => {
  it('lets an owner read the consent text and turn AI on', async () => {
    const fetchMock = installFetch(routes())
    const user = userEvent.setup()
    render(
      <MemoryRouter><AiProviders>
        <AiSettingsSection organizationId="org-settings-owner" role="Owner" />
      </AiProviders></MemoryRouter>,
    )

    expect(await screen.findByRole('heading', { name: 'AI assistance' })).toBeVisible()
    expect(screen.getByText(/sends only your question/)).toBeVisible()
    expect(screen.getByText('50,000 tokens left this month')).toBeVisible()
    expect(screen.getByLabelText('Monthly token budget')).toHaveValue(50000)

    await user.click(screen.getByLabelText('Enable AI for this workspace'))
    await user.click(screen.getByRole('button', { name: 'Save AI settings' }))

    await waitFor(() => {
      expect(requests(fetchMock).some((request) => request.method === 'PUT')).toBe(true)
    })
    expect(requests(fetchMock).find((request) => request.method === 'PUT')?.body).toEqual({
      enabled: true,
      monthlyTokenBudget: 50000,
    })
    expect(await screen.findByText('AI settings saved.')).toBeVisible()
  })

  it('rejects a budget that is not a whole number without calling the API', async () => {
    const fetchMock = installFetch(routes())
    const user = userEvent.setup()
    render(
      <MemoryRouter><AiProviders>
        <AiSettingsSection organizationId="org-settings-invalid" role="Admin" />
      </AiProviders></MemoryRouter>,
    )

    const budget = await screen.findByLabelText('Monthly token budget')
    await user.clear(budget)
    await user.type(budget, '10.5')
    await user.click(screen.getByRole('button', { name: 'Save AI settings' }))

    expect(await screen.findByText('Use a whole number from 0 to 100,000,000.')).toBeVisible()
    expect(requests(fetchMock).some((request) => request.method === 'PUT')).toBe(false)
  })

  it('renders nothing for a member', () => {
    installFetch(routes())
    render(
      <MemoryRouter><AiProviders>
        <AiSettingsSection organizationId="org-settings-member" role="Member" />
      </AiProviders></MemoryRouter>,
    )

    expect(screen.queryByRole('heading', { name: 'AI assistance' })).toBeNull()
    expect(screen.queryByLabelText('Monthly token budget')).toBeNull()
  })

  it('makes the header assistant appear as soon as an owner enables AI', async () => {
    // A stateful status: the organization opt-in only turns on after the PUT, so
    // this fails if the mounted launcher keeps its cached "off" answer.
    let organizationEnabled = false
    const fetchMock = installFetch([
      {
        path: '/api/ai/status',
        method: 'GET',
        json: () => ({ enabled: true, organizationEnabled, budgetRemainingTokens: 50000 }),
      },
      { path: '/ai-settings', method: 'GET', json: storedSettings },
      {
        path: '/ai-settings',
        method: 'PUT',
        json: () => {
          organizationEnabled = true
          return { ...storedSettings, enabled: true }
        },
      },
    ])
    const user = userEvent.setup()
    render(
      <MemoryRouter><AiProviders>
        <AssistantLauncher organizationId="org-settings-activation" />
        <AiSettingsSection organizationId="org-settings-activation" role="Owner" />
      </AiProviders></MemoryRouter>,
    )

    await screen.findByRole('heading', { name: 'AI assistance' })
    expect(screen.queryByRole('button', { name: 'Ask Keelson' })).toBeNull()

    await user.click(screen.getByLabelText('Enable AI for this workspace'))
    await user.click(screen.getByRole('button', { name: 'Save AI settings' }))

    expect(await screen.findByRole('button', { name: 'Ask Keelson' })).toBeVisible()
    // It appeared from a second read of the status, not from a cached answer.
    expect(
      requests(fetchMock).filter((request) => request.path.startsWith('/api/ai/status')),
    ).toHaveLength(4)
  })

  it('renders nothing when the status call fails', async () => {
    const fetchMock = installFetch([{ path: '/api/ai/status', method: 'GET', status: 503, json: {} }])
    render(
      <MemoryRouter><AiProviders>
        <AiSettingsSection organizationId="org-settings-failed" role="Owner" />
      </AiProviders></MemoryRouter>,
    )

    await waitFor(() => expect(fetchMock).toHaveBeenCalled())
    expect(screen.queryByRole('heading', { name: 'AI assistance' })).toBeNull()
  })
})
