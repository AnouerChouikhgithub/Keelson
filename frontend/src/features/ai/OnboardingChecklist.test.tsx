import { render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { installFetch } from '../../test/fetchMock'
import { OnboardingChecklist } from './OnboardingChecklist'
import { AiProviders } from './testRender'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('OnboardingChecklist', () => {
  it('localizes the steps and announces the server-computed progress', async () => {
    installFetch([
      {
        path: '/onboarding',
        method: 'GET',
        json: {
          steps: [
            { id: 'invite_team', done: true },
            { id: 'add_customer', done: false },
            { id: 'create_invoice', done: false },
          ],
          completed: 1,
          total: 3,
        },
      },
    ])

    render(
      <AiProviders>
        <OnboardingChecklist organizationId="org-checklist" />
      </AiProviders>,
    )

    expect(await screen.findByRole('heading', { name: 'Set up your workspace' })).toBeVisible()
    expect(screen.getByText('1 of 3 done')).toBeVisible()
    expect(screen.getByText('Invite a teammate')).toBeVisible()
    expect(screen.getByText('Add a customer')).toBeVisible()
    // The row state is announced even though the tick itself is decorative.
    expect(screen.getByText('Done')).toBeVisible()
    expect(screen.getAllByText('Not done yet')).toHaveLength(2)
  })

  it('falls back to the step id when this build has no copy for it', async () => {
    installFetch([
      {
        path: '/onboarding',
        method: 'GET',
        json: { steps: [{ id: 'archive_workspace', done: false }], completed: 0, total: 1 },
      },
    ])

    render(
      <AiProviders>
        <OnboardingChecklist organizationId="org-checklist-key" />
      </AiProviders>,
    )

    expect(await screen.findByText('archive_workspace')).toBeVisible()
  })

  it('renders nothing for an empty checklist', async () => {
    const fetchMock = installFetch([
      { path: '/onboarding', method: 'GET', json: { steps: [], completed: 0, total: 0 } },
    ])

    render(
      <AiProviders>
        <OnboardingChecklist organizationId="org-checklist-empty" />
      </AiProviders>,
    )

    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled())
    expect(screen.queryByRole('heading', { name: 'Set up your workspace' })).toBeNull()
  })

  it('renders nothing when the checklist call fails', async () => {
    installFetch([{ path: '/onboarding', method: 'GET', status: 500, json: {} }])

    render(
      <AiProviders>
        <OnboardingChecklist organizationId="org-checklist-failed" />
      </AiProviders>,
    )

    await vi.waitFor(() => {
      expect(screen.queryByRole('heading', { name: 'Set up your workspace' })).toBeNull()
    })
    expect(screen.queryByText(/unexpected response/i)).toBeNull()
  })
})
