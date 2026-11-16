import { useCallback, useState } from 'react'
import type { FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { isApiError } from '../api/errors'
import { presentedFields } from '../api/formErrors'
import {
  createInvitation,
  listInvitations,
  resendInvitation,
  revokeInvitation,
} from '../api/invitations'
import { listMembers, removeMember, updateMemberRole } from '../api/members'
import {
  canAdministerTeam,
  canGrantRole,
} from '../api/organizations'
import { presentError } from '../api/presentError'
import { invalidateResource, useApiResource } from '../api/useApiResource'
import { useMutation } from '../api/useMutation'
import type { Invitation, InviteRole, Member, OrganizationRole } from '../api/types'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { DataTable } from '../components/DataTable'
import { EmptyState } from '../components/EmptyState'
import { ErrorState } from '../components/ErrorState'
import { Field } from '../components/Field'
import { Icon } from '../components/Icon'
import { Skeleton } from '../components/Skeleton'
import { useToast } from '../components/ToastContext'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import { useAuth } from '../session/AuthContext'
import { useWorkspace } from '../app/WorkspaceContext'
import './simple.css'

const GRANTABLE: InviteRole[] = ['Admin', 'Member', 'Viewer']
const ALL_ROLES: OrganizationRole[] = ['Owner', 'Admin', 'Member', 'Viewer']

function memberLabel(member: Member): string {
  return member.name ?? member.email
}

/**
 * The real team of one organization: roster, role changes, removal, leaving and
 * invitations.
 *
 * Every affordance here mirrors a server rule, and none of them replaces one:
 * an Admin only sees role choices they may grant, the last Owner's controls are
 * disabled with the reason stated, and a refusal still arrives as a `404`/`409`
 * that the screen reports. Hiding an action is a convenience, never authority.
 */
export default function Team() {
  const { m, date } = useI18n()
  const { membership, refreshMemberships } = useWorkspace()
  const { user } = useAuth()
  const navigate = useNavigate()
  const toast = useToast()
  useDocumentTitle(m.page.team)

  const orgId = membership.id
  const canAdmin = canAdministerTeam(membership.role)
  const [inviteEmail, setInviteEmail] = useState('')
  const [inviteRole, setInviteRole] = useState<InviteRole>('Member')
  const [inviteErrors, setInviteErrors] = useState<Record<string, string>>({})
  const [pendingRemove, setPendingRemove] = useState<Member | null>(null)
  const [pendingRevoke, setPendingRevoke] = useState<Invitation | null>(null)

  const members = useApiResource(
    `members:${orgId}`,
    useCallback((signal: AbortSignal) => listMembers(orgId, signal), [orgId]),
  )
  const invitations = useApiResource(
    `invitations:${orgId}`,
    useCallback((signal: AbortSignal) => listInvitations(orgId, signal), [orgId]),
    { enabled: canAdmin },
  )

  const changeRole = useMutation(
    (target: Member, role: OrganizationRole) => updateMemberRole(orgId, target.id, role),
    { announceFailure: false },
  )
  const remove = useMutation(
    (target: Member) => removeMember(orgId, target.id),
    { announceFailure: false },
  )
  const invite = useMutation(
    (input: { email: string; role: InviteRole }) => createInvitation(orgId, input),
    { announceFailure: false },
  )
  const resend = useMutation(
    (invitation: Invitation) => resendInvitation(orgId, invitation.id),
    { announceFailure: false },
  )
  const revoke = useMutation(
    (invitation: Invitation) => revokeInvitation(orgId, invitation.id),
    { announceFailure: false },
  )

  const rows = members.state.status === 'ready' ? members.state.data : []
  const ownerCount = rows.filter((member) => member.role === 'Owner').length
  const self = rows.find((member) => member.principalId === user?.id) ?? null
  const selfIsLastOwner = self !== null && self.role === 'Owner' && ownerCount === 1

  function canManage(target: Member): boolean {
    if (target.principalId === user?.id) return false
    if (membership.role === 'Owner') return true
    return membership.role === 'Admin' && (target.role === 'Member' || target.role === 'Viewer')
  }

  function isLastOwner(target: Member): boolean {
    return target.role === 'Owner' && ownerCount === 1
  }

  function reportFailure(error: unknown): void {
    // The last-Owner rule has its own sentence; every other refusal is generic.
    if (isApiError(error) && error.code === 'last_owner') {
      toast.error(m.team.lastOwnerBlocked)
      return
    }
    toast.error(presentError(m, error))
  }

  function refreshRoster(): void {
    invalidateResource(`members:${orgId}`)
    members.reload()
  }

  async function handleRole(target: Member, role: OrganizationRole) {
    if (role === target.role) return
    const outcome = await changeRole.run(target, role)
    if (!outcome.ok) {
      reportFailure(outcome.error)
      return
    }
    toast.success(m.team.roleUpdated(memberLabel(outcome.data)))
    refreshRoster()
    if (target.principalId === user?.id) refreshMemberships()
  }

  async function handleRemove() {
    if (pendingRemove === null) return
    const target = pendingRemove
    const leaving = target.principalId === user?.id
    const outcome = await remove.run(target)
    if (!outcome.ok) {
      reportFailure(outcome.error)
      return
    }
    setPendingRemove(null)
    invalidateResource(`members:${orgId}`)
    if (leaving) {
      toast.success(m.team.left)
      invalidateResource('organizations')
      refreshMemberships()
      void navigate('/', { replace: true })
      return
    }
    toast.success(m.team.removed(memberLabel(target)))
    refreshRoster()
  }

  async function handleInvite(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const email = inviteEmail.trim()
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setInviteErrors({ email: m.team.invalidEmail })
      return
    }
    setInviteErrors({})
    const outcome = await invite.run({ email, role: inviteRole })
    if (!outcome.ok) {
      setInviteErrors(presentedFields(outcome.error) ?? {})
      toast.error(presentError(m, outcome.error))
      return
    }
    setInviteEmail('')
    toast.success(m.team.invited(outcome.data.email))
    invalidateResource(`invitations:${orgId}`)
    invitations.reload()
  }

  async function handleResend(invitation: Invitation) {
    const outcome = await resend.run(invitation)
    if (!outcome.ok) {
      reportFailure(outcome.error)
      return
    }
    toast.success(m.team.resent(outcome.data.email))
    invalidateResource(`invitations:${orgId}`)
    invitations.reload()
  }

  async function handleRevoke() {
    if (pendingRevoke === null) return
    const outcome = await revoke.run(pendingRevoke)
    if (!outcome.ok) {
      reportFailure(outcome.error)
      return
    }
    toast.success(m.team.revoked(outcome.data.email))
    setPendingRevoke(null)
    invalidateResource(`invitations:${orgId}`)
    invitations.reload()
  }

  return (
    <div className="simple-page">
      <section className="page-intro">
        <div>
          <p className="eyebrow">{m.nav.workspace}</p>
          <h1>{m.team.title}</h1>
          <p>{m.team.subtitle}</p>
        </div>
      </section>

      <section className="panel" aria-labelledby="members-title">
        <div className="panel-heading">
          <div>
            <h2 id="members-title">{m.team.membersTitle}</h2>
            <p>{m.team.membersHint}</p>
          </div>
        </div>

        {members.state.status === 'loading' && <Skeleton variant="table" count={4} label={m.common.loading} />}
        {members.state.status === 'error' && (
          <ErrorState
            title={m.team.loadFailed}
            message={presentError(m, members.state.error)}
            retryLabel={m.common.retry}
            onRetry={members.reload}
          />
        )}
        {members.state.status === 'ready' && (
          <DataTable caption={m.team.tableCaption}>
            <thead>
              <tr>
                <th scope="col">{m.team.columns.name}</th>
                <th scope="col">{m.team.columns.email}</th>
                <th scope="col">{m.team.columns.role}</th>
                <th scope="col">{m.team.columns.joined}</th>
                <th scope="col"><span className="visually-hidden">{m.team.columns.actions}</span></th>
              </tr>
            </thead>
            <tbody>
              {rows.map((member) => {
                const manageable = canManage(member)
                const lastOwner = isLastOwner(member)
                const isSelf = member.principalId === user?.id
                return (
                  <tr key={member.id}>
                    <td className="cell-strong">
                      {memberLabel(member)}
                      {isSelf && <span className="you-tag">{m.team.you}</span>}
                    </td>
                    <td>{member.email}</td>
                    <td>
                      {manageable ? (
                        <select
                          className="role-select"
                          aria-label={m.team.roleFor(memberLabel(member))}
                          value={member.role}
                          disabled={changeRole.pending || lastOwner}
                          title={lastOwner ? m.team.lastOwnerNote : undefined}
                          onChange={(event) => { void handleRole(member, event.target.value as OrganizationRole) }}
                        >
                          {ALL_ROLES.filter((role) => canGrantRole(membership.role, role)).map((role) => (
                            <option key={role} value={role}>{m.roles[role.toLowerCase() as keyof typeof m.roles]}</option>
                          ))}
                        </select>
                      ) : (
                        <span>{m.roles[member.role.toLowerCase() as keyof typeof m.roles]}</span>
                      )}
                    </td>
                    <td>{date(member.joinedAt)}</td>
                    <td className="cell-end cell-actions">
                      {isSelf ? (
                        <button
                          type="button"
                          className="button button-ghost button-sm danger-text"
                          disabled={remove.pending || selfIsLastOwner}
                          title={selfIsLastOwner ? m.team.lastOwnerNote : undefined}
                          onClick={() => setPendingRemove(member)}
                        >
                          {m.team.leave}
                        </button>
                      ) : manageable ? (
                        <button
                          type="button"
                          className="button button-ghost button-sm danger-text"
                          disabled={remove.pending || lastOwner}
                          title={lastOwner ? m.team.lastOwnerNote : undefined}
                          onClick={() => setPendingRemove(member)}
                        >
                          {m.common.delete}
                        </button>
                      ) : null}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </DataTable>
        )}
        <p className="role-note">{m.team.lastOwnerNote}</p>
      </section>

      {canAdmin && (
        <section className="panel" aria-labelledby="invite-title">
          <div className="panel-heading">
            <div>
              <h2 id="invite-title">{m.team.inviteTitle}</h2>
              <p>{m.team.inviteHint}</p>
            </div>
          </div>
          <form className="settings-form" onSubmit={handleInvite} noValidate>
            <Field label={m.team.inviteEmail} required error={inviteErrors.email}>
              {(field) => (
                <input
                  {...field}
                  type="email"
                  value={inviteEmail}
                  autoComplete="off"
                  onChange={(event) => setInviteEmail(event.target.value)}
                />
              )}
            </Field>
            <Field label={m.team.inviteRole} required error={inviteErrors.role}>
              {(field) => (
                <select {...field} value={inviteRole} onChange={(event) => setInviteRole(event.target.value as InviteRole)}>
                  {GRANTABLE.filter((role) => canGrantRole(membership.role, role)).map((role) => (
                    <option key={role} value={role}>{m.roles[role.toLowerCase() as keyof typeof m.roles]}</option>
                  ))}
                </select>
              )}
            </Field>
            <div className="settings-form-actions">
              <button type="submit" className="button button-primary" disabled={invite.pending} aria-busy={invite.pending || undefined}>
                <Icon name="mail" size={17} /> {invite.pending ? m.team.inviting : m.team.invite}
              </button>
            </div>
          </form>
        </section>
      )}

      {canAdmin && (
        <section className="panel" aria-labelledby="invitations-title">
          <div className="panel-heading">
            <div>
              <h2 id="invitations-title">{m.team.pendingTitle}</h2>
              <p>{m.team.pendingHint}</p>
            </div>
          </div>

          {invitations.state.status === 'loading' && <Skeleton variant="list" count={2} label={m.common.loading} />}
          {invitations.state.status === 'error' && (
            <ErrorState
              title={m.team.invitationsLoadFailed}
              message={presentError(m, invitations.state.error)}
              retryLabel={m.common.retry}
              onRetry={invitations.reload}
            />
          )}
          {invitations.state.status === 'ready' && invitations.state.data.length === 0 && (
            <EmptyState icon="mail" title={m.team.noInvitations} body={m.team.noInvitationsHint} />
          )}
          {invitations.state.status === 'ready' && invitations.state.data.length > 0 && (
            <DataTable caption={m.team.pendingCaption}>
              <thead>
                <tr>
                  <th scope="col">{m.team.pendingColumns.email}</th>
                  <th scope="col">{m.team.pendingColumns.role}</th>
                  <th scope="col">{m.team.pendingColumns.expires}</th>
                  <th scope="col"><span className="visually-hidden">{m.common.actions}</span></th>
                </tr>
              </thead>
              <tbody>
                {invitations.state.data.map((invitation) => {
                  const pending = invitation.status === 'pending' && !invitation.expired
                  return (
                    <tr key={invitation.id}>
                      <td className="cell-strong">{invitation.email}</td>
                      <td>{m.roles[invitation.role.toLowerCase() as keyof typeof m.roles]}</td>
                      <td>{date(invitation.expiresAt)}</td>
                      <td className="cell-end cell-actions">
                        {pending && canGrantRole(membership.role, invitation.role) && (
                          <>
                            <button
                              type="button"
                              className="button button-ghost button-sm"
                              disabled={resend.pending}
                              onClick={() => { void handleResend(invitation) }}
                            >
                              {resend.pending ? m.team.resending : m.team.resend}
                            </button>
                            <button
                              type="button"
                              className="button button-ghost button-sm danger-text"
                              disabled={revoke.pending}
                              onClick={() => setPendingRevoke(invitation)}
                            >
                              {m.team.revoke}
                            </button>
                          </>
                        )}
                        {!pending && <span className="field-hint">{m.team.notPending}</span>}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </DataTable>
          )}
        </section>
      )}

      <ConfirmDialog
        open={pendingRemove !== null}
        title={pendingRemove?.principalId === user?.id ? m.team.leaveTitle : m.team.removeTitle}
        body={pendingRemove?.principalId === user?.id
          ? m.team.leaveBody
          : m.team.removeBody(pendingRemove === null ? '' : memberLabel(pendingRemove))}
        confirmLabel={pendingRemove?.principalId === user?.id ? m.team.leave : m.common.delete}
        onConfirm={() => { void handleRemove() }}
        onClose={() => setPendingRemove(null)}
        pending={remove.pending}
      />

      <ConfirmDialog
        open={pendingRevoke !== null}
        title={m.team.revokeTitle}
        body={m.team.revokeBody}
        confirmLabel={m.team.revoke}
        onConfirm={() => { void handleRevoke() }}
        onClose={() => setPendingRevoke(null)}
        pending={revoke.pending}
      />
    </div>
  )
}
