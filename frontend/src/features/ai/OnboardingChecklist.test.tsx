import { render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { installFetch } from '../../test/fetchMock'
import { OnboardingChecklist } from './OnboardingChecklist'
import { AiProviders } from './testRender'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('OnboardingChecklist', () => {
  it('counts the completed steps and marks them for assistive technology', async () => {
    installFetch([
      {
        path: '/onboarding',
        method: 'GET',
        json: {
          steps: [
            { key: 'organization', label: 'Create the workspace', completed: true },
            { key: 'customer', label: 'Add a first customer', completed: false },
            { key: 'invoice', label: 'Send an invoice', completed: false },
          ],
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
    expect(screen.getByText('Create the workspace')).toBeVisible()
    // The row state is announced even though the tick itself is decorative.
    expect(screen.getByText('Done')).toBeVisible()
    expect(screen.getAllByText('Not done yet')).toHaveLength(2)
  })

  it('falls back to the step key when the server sends no label', async () => {
    installFetch([
      { path: '/onboarding', method: 'GET', json: { steps: [{ key: 'invite_teammate', completed: false }] } },
    ])

    render(
      <AiProviders>
        <OnboardingChecklist organizationId="org-checklist-key" />
      </AiProviders>,
    )

    expect(await screen.findByText('invite_teammate')).toBeVisible()
  })

  it('renders nothing for an empty checklist', async () => {
    const fetchMock = installFetch([{ path: '/onboarding', method: 'GET', json: { steps: [] } }])

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
