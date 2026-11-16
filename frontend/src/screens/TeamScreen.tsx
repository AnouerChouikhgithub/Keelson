import { useState } from 'react'
import type { FormEvent } from 'react'
import type { TeamMember } from '../api/types'
import { EmptyState } from '../components/EmptyState'
import { Icon } from '../components/Icon'
import { OverlaySurface } from '../components/OverlaySurface'
import { PersonCell } from '../components/PersonCell'
import { useEscapeKey } from '../hooks/useEscapeKey'
import { useI18n } from '../i18n/I18nContext'
import type { InvitationRecord } from '../mocks/organizations'
import { mockInvitations, mockTeamMembers } from '../mocks/organizations'

type AssignedRole = Exclude<TeamMember['role'], 'Owner'>

export function TeamScreen() {
  const { m, date, number } = useI18n()
  const copy = m.workspace.team
  const [members, setMembers] = useState<TeamMember[]>(mockTeamMembers)
  const [invitations, setInvitations] = useState<InvitationRecord[]>(mockInvitations)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [email, setEmail] = useState('')
  const [role, setRole] = useState<AssignedRole>('Member')
  const [error, setError] = useState<string | null>(null)
  const [feedback, setFeedback] = useState<string | null>(null)

  useEscapeKey(dialogOpen, () => setDialogOpen(false))

  function changeRole(memberId: string, nextRole: AssignedRole) {
    setMembers((current) => current.map((member) => member.id === memberId && member.role !== 'Owner' ? { ...member, role: nextRole } : member))
    setFeedback(copy.roleUpdated)
  }

  function resend(invitation: InvitationRecord) {
    setInvitations((current) => current.map((item) => item.id === invitation.id ? { ...item, expiresAt: '2026-11-17' } : item))
    setFeedback(copy.resent(invitation.email))
  }

  function revoke(invitation: InvitationRecord) {
    setInvitations((current) => current.filter((item) => item.id !== invitation.id))
    setFeedback(copy.revoked(invitation.email))
  }

  function submitInvitation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const normalized = email.trim()
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalized)) {
      setError(copy.invalidEmail)
      return
    }
    setInvitations((current) => [{
      id: `invite-local-${current.length + 1}`,
      organizationId: 'atelier-noura',
      email: normalized,
      role,
      status: 'Pending',
      sentAt: '2026-11-14',
      expiresAt: '2026-11-17',
    }, ...current])
    setError(null)
    setDialogOpen(false)
    setEmail('')
    setRole('Member')
    setFeedback(copy.added(normalized))
  }

  return (
    <section className="panel workspace-panel">
      <div className="panel-heading workspace-heading">
        <div>
          <h1>{m.page.team}</h1>
          <p>{m.placeholder.team}</p>
        </div>
        <button type="button" className="button button-primary" onClick={() => { setFeedback(null); setDialogOpen(true) }}>
          <Icon name="plus" size={18} /> {copy.invite}
        </button>
      </div>

      {feedback && <div className="inline-feedback" role="status"><Icon name="check" size={16} /> {feedback}</div>}

      <div className="workspace-section">
        <h2>{copy.members} <span className="section-count">{number(members.length, 0)}</span></h2>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{copy.name}</th>
                <th>{m.workspace.customers.contact}</th>
                <th>{copy.location}</th>
                <th>{copy.role}</th>
              </tr>
            </thead>
            <tbody>
              {members.map((member) => (
                <tr key={member.id}>
                  <td><PersonCell initials={member.initials} name={member.name} /></td>
                  <td className="muted-cell">{member.email}</td>
                  <td>{member.location}</td>
                  <td>
                    {member.role === 'Owner' ? (
                      <span className="role-pill">{m.roles.owner}</span>
                    ) : (
                      <select
                        className="role-select"
                        value={member.role}
                        aria-label={copy.changeRole(member.name)}
                        onChange={(event) => changeRole(member.id, event.target.value as AssignedRole)}
                      >
                        <option value="Admin">{m.roles.admin}</option>
                        <option value="Member">{m.roles.member}</option>
                        <option value="Viewer">{m.roles.viewer}</option>
                      </select>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="mock-note">{copy.roleHint}</p>
      </div>

      <div className="workspace-section">
        <h2>{copy.invitations} <span className="section-count">{number(invitations.length, 0)}</span></h2>
        {invitations.length === 0 ? (
          <EmptyState icon="mail" title={copy.empty} body={copy.emptyHint} />
        ) : (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>{m.workspace.customers.contact}</th>
                  <th>{copy.role}</th>
                  <th>{copy.expires}</th>
                  <th><span className="visually-hidden">{copy.actions}</span></th>
                </tr>
              </thead>
              <tbody>
                {invitations.map((invitation) => (
                  <tr key={invitation.id}>
                    <td>{invitation.email}</td>
                    <td><span className="role-pill">{m.roles[invitation.role.toLowerCase() as 'admin' | 'member' | 'viewer']}</span></td>
                    <td>{date(invitation.expiresAt)}</td>
                    <td className="actions-cell">
                      <button type="button" className="button button-ghost" aria-label={copy.resendFor(invitation.email)} onClick={() => resend(invitation)}>{copy.resend}</button>
                      <button type="button" className="button button-ghost danger-text" aria-label={copy.revokeFor(invitation.email)} onClick={() => revoke(invitation)}>{copy.revoke}</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <p className="mock-note">{copy.localNote}</p>
      </div>

      {dialogOpen && (
        <OverlaySurface variant="dialog" closeLabel={copy.cancel} onClose={() => setDialogOpen(false)} labelledBy="invite-title">
            <div className="drawer-heading">
              <h2 id="invite-title">{copy.dialogTitle}</h2>
              <button type="button" className="icon-button" aria-label={copy.cancel} onClick={() => setDialogOpen(false)}><Icon name="close" /></button>
            </div>
            <form onSubmit={submitInvitation}>
              <label className="field">
                <span>{copy.email}</span>
                <input
                  type="email"
                  value={email}
                  autoFocus
                  placeholder={copy.emailPlaceholder}
                  aria-invalid={error !== null}
                  onChange={(event) => { setEmail(event.target.value); setError(null) }}
                />
                {error && <small className="field-error" role="alert">{error}</small>}
              </label>
              <label className="field">
                <span>{copy.role}</span>
                <select value={role} onChange={(event) => setRole(event.target.value as AssignedRole)}>
                  <option value="Admin">{m.roles.admin}</option>
                  <option value="Member">{m.roles.member}</option>
                  <option value="Viewer">{m.roles.viewer}</option>
                </select>
              </label>
              <div className="modal-actions">
                <button type="button" className="button button-ghost" onClick={() => setDialogOpen(false)}>{copy.cancel}</button>
                <button type="submit" className="button button-primary">{copy.send}</button>
              </div>
            </form>
        </OverlaySurface>
      )}
    </section>
  )
}
