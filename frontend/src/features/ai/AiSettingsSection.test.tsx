import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { installFetch, requests } from '../../test/fetchMock'
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
      <AiProviders>
        <AiSettingsSection organizationId="org-settings-owner" role="Owner" />
      </AiProviders>,
    )

    expect(await screen.findByRole('heading', { name: 'AI assistance' })).toBeVisible()
    expect(screen.getByText(/sent to the workspace’s AI provider/)).toBeVisible()
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
      <AiProviders>
        <AiSettingsSection organizationId="org-settings-invalid" role="Admin" />
      </AiProviders>,
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
      <AiProviders>
        <AiSettingsSection organizationId="org-settings-member" role="Member" />
      </AiProviders>,
    )

    expect(screen.queryByRole('heading', { name: 'AI assistance' })).toBeNull()
    expect(screen.queryByLabelText('Monthly token budget')).toBeNull()
  })

  it('renders nothing when the status call fails', async () => {
    const fetchMock = installFetch([{ path: '/api/ai/status', method: 'GET', status: 503, json: {} }])
    render(
      <AiProviders>
        <AiSettingsSection organizationId="org-settings-failed" role="Owner" />
      </AiProviders>,
    )

    await waitFor(() => expect(fetchMock).toHaveBeenCalled())
    expect(screen.queryByRole('heading', { name: 'AI assistance' })).toBeNull()
  })
})
