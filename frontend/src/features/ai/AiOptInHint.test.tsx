import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { OrganizationRole } from '../../api/types'
import { installFetch } from '../../test/fetchMock'
import { AiOptInHint } from './AiOptInHint'
import { AiProviders } from './testRender'

afterEach(() => {
  vi.unstubAllGlobals()
  window.localStorage.clear()
})

type Status = { enabled: boolean; organizationEnabled: boolean; budgetRemainingTokens: number }

/** Renders the hint for one role and one `/api/ai/status` answer. */
function renderHint(role: OrganizationRole, status: Status | null, locale?: 'ar') {
  if (locale !== undefined) window.localStorage.setItem('keelson-locale', locale)
  const fetchMock = installFetch(
    status === null
      ? [{ path: '/api/ai/status', method: 'GET', status: 404, json: { error: { code: 'not_found', message: 'x' } } }]
      : [{ path: '/api/ai/status', method: 'GET', json: status }],
  )
  render(
    <MemoryRouter initialEntries={['/o/org-1/dashboard']}>
      <AiProviders>
        <AiOptInHint organizationId="org-1" role={role} />
      </AiProviders>
    </MemoryRouter>,
  )
  return fetchMock
}

const availableNotEnabled: Status = { enabled: true, organizationEnabled: false, budgetRemainingTokens: 0 }

describe('AiOptInHint', () => {
  it('offers the setting to an Owner while the workspace has not opted in', async () => {
    renderHint('Owner', availableNotEnabled)

    const hint = await screen.findByRole('note')
    expect(hint).toHaveTextContent('AI assistance is available for this workspace.')
    const link = screen.getByRole('link', { name: 'Turn it on in Settings' })
    expect(link).toHaveAttribute('href', '/o/org-1/settings#ai-settings')
  })

  it('offers it to an Admin too', async () => {
    renderHint('Admin', availableNotEnabled)
    expect(await screen.findByRole('note')).toBeVisible()
  })

  it.each<[OrganizationRole]>([['Member'], ['Viewer']])(
    'renders nothing and makes no request for a %s',
    (role) => {
      const fetchMock = renderHint(role, availableNotEnabled)

      expect(screen.queryByRole('note')).toBeNull()
      expect(screen.queryByRole('link')).toBeNull()
      expect(fetchMock).not.toHaveBeenCalled()
    },
  )

  it('renders nothing when the deployment switch is off', async () => {
    renderHint('Owner', { enabled: false, organizationEnabled: false, budgetRemainingTokens: 0 })

    // The status still resolves; the assertion waits for it rather than racing it.
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(screen.queryByRole('note')).toBeNull()
    expect(screen.queryByRole('link')).toBeNull()
  })

  it('renders nothing once the workspace has opted in', async () => {
    renderHint('Owner', { enabled: true, organizationEnabled: true, budgetRemainingTokens: 5000 })

    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(screen.queryByRole('note')).toBeNull()
  })

  it('renders nothing when the status read fails, like every other AI surface', async () => {
    renderHint('Owner', null)

    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(screen.queryByRole('note')).toBeNull()
  })

  it('is localized in Arabic', async () => {
    renderHint('Owner', availableNotEnabled, 'ar')

    expect(await screen.findByRole('note')).toHaveTextContent('المساعدة بالذكاء الاصطناعي متاحة لمساحة العمل هذه.')
    expect(screen.getByRole('link', { name: 'فعّلها من الإعدادات' })).toBeVisible()
    expect(document.documentElement.dir).toBe('rtl')
  })
})
