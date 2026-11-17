import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { OrganizationMembership, OrganizationRole } from '../api/types'
import { clearApiCache } from '../api/useApiResource'
import { WorkspaceContext } from '../app/WorkspaceContext'
import { ToastProvider } from '../components/Toast'
import { en } from '../i18n/en'
import { I18nProvider } from '../i18n/I18nProvider'
import { AuthProvider } from '../session/AuthProvider'
import { authenticatedSession, installFetch, requests } from '../test/fetchMock'
import type { FetchRoute } from '../test/fetchMock'
import Team from './Team'

const orgId = '11111111-1111-4111-8111-111111111111'
const selfId = 'principal-self'
const joinedAt = '2026-01-05T10:00:00+00:00'

type Row = { id: string; principalId: string; email: string; name: string | null; role: OrganizationRole; joinedAt: string }

function membership(role: OrganizationRole): OrganizationMembership {
  return { id: orgId, name: 'Keelson SARL', subdomain: 'keelson', role }
}

const self: Row = { id: 'membership-self', principalId: selfId, email: 'owner@example.test', name: 'Owner', role: 'Owner', joinedAt }
const member: Row = { id: 'membership-member', principalId: 'principal-member', email: 'member@example.test', name: null, role: 'Member', joinedAt }
const otherOwner: Row = { id: 'membership-owner', principalId: 'principal-owner', email: 'owner2@example.test', name: 'Second Owner', role: 'Owner', joinedAt }

function renderTeam(role: OrganizationRole) {
  const value = { membership: membership(role), memberships: [membership(role)], refreshMemberships: () => {} }
  render(
    <I18nProvider>
      <AuthProvider>
        <ToastProvider>
          <WorkspaceContext.Provider value={value}>
            <MemoryRouter initialEntries={[`/o/${orgId}/team`]}>
              <Team />
            </MemoryRouter>
          </WorkspaceContext.Provider>
        </ToastProvider>
      </AuthProvider>
    </I18nProvider>,
  )
}

function stub(rows: Row[], invitations: unknown[] = []) {
  const routes: FetchRoute[] = [
    authenticatedSession(selfId),
    { path: '/members', method: 'GET', json: { items: rows } },
    { path: '/invitations', method: 'GET', json: { items: invitations } },
  ]
  return installFetch(routes)
}

beforeEach(() => {
  clearApiCache()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('Team', () => {
  it('disables the last owner\'s own controls and explains why', async () => {
    stub([self, member])
    renderTeam('Owner')

    expect(await screen.findByText(en.team.lastOwnerNote)).toBeInTheDocument()
    // Wait for the session too: until the current principal is known, every row
    // is treated as someone else's.
    const leave = await screen.findByRole('button', { name: en.team.leave })
    expect(leave).toBeDisabled()
    expect(leave).toHaveAttribute('title', en.team.lastOwnerNote)
    // The roster still offers the actions the Owner is allowed to take.
    expect(screen.getByLabelText(en.team.roleFor('member@example.test'))).toBeEnabled()
    expect(screen.getByRole('button', { name: en.common.delete })).toBeEnabled()
    expect(screen.getByRole('heading', { name: en.team.inviteTitle })).toBeInTheDocument()
  })

  it('offers an admin only the rows and roles an admin may touch', async () => {
    stub([{ ...self, role: 'Admin', name: 'Admin' }, otherOwner, member])
    renderTeam('Admin')

    await screen.findByRole('button', { name: en.team.leave })
    const roleSelect = screen.getByLabelText(en.team.roleFor('member@example.test'))
    expect(within(roleSelect).getByRole('option', { name: en.roles.member })).toBeInTheDocument()
    expect(within(roleSelect).queryByRole('option', { name: en.roles.admin })).not.toBeInTheDocument()
    expect(within(roleSelect).queryByRole('option', { name: en.roles.owner })).not.toBeInTheDocument()

    // An owner outranks an admin: their row is read-only.
    expect(screen.queryByLabelText(en.team.roleFor('Second Owner'))).not.toBeInTheDocument()
    expect(screen.getByText(en.roles.owner)).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: en.common.delete })).toHaveLength(1)

    const inviteSection = screen.getByRole('region', { name: en.team.inviteTitle })
    const inviteRole = within(inviteSection).getByLabelText(en.team.inviteRole, { exact: false })
    expect(within(inviteRole).queryByRole('option', { name: en.roles.admin })).not.toBeInTheDocument()
  })

  it('hides invitations and role controls from a viewer', async () => {
    const fetchMock = stub([
      { ...self, role: 'Viewer', name: 'Viewer' },
      { ...member, id: 'm2', principalId: 'p2', role: 'Owner', email: 'owner2@example.test' },
    ])
    renderTeam('Viewer')

    expect(await screen.findByRole('button', { name: en.team.leave })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: en.team.inviteTitle })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: en.team.pendingTitle })).not.toBeInTheDocument()
    expect(screen.queryByLabelText(en.team.roleFor('owner2@example.test'))).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: en.common.delete })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: en.team.invite })).not.toBeInTheDocument()
    // The invitation list is not even requested for a viewer.
    expect(requests(fetchMock).some((call) => call.path.includes('/invitations'))).toBe(false)
  })
})
