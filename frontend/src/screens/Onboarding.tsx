import { useState } from 'react'
import type { FormEvent } from 'react'
import { Icon } from '../components/Icon'
import { useI18n } from '../i18n/I18nContext'
import { organization as demoOrganization } from '../mocks/dashboard'
import { mockTeamMembers } from '../mocks/organizations'
import type { InvitationRecord, OrganizationSummary, TeamMember } from '../mocks/organizations'

function MemberPreview({ member }: { member: TeamMember }) {
  const { m } = useI18n()
  return (
    <div className="member-preview">
      <span className="member-avatar">{member.initials}</span>
      <span className="member-copy"><strong>{member.name}</strong><small>{member.location}</small></span>
      <span className={`role-pill role-${member.role.toLowerCase()}`}>{m.roles[member.role.toLowerCase() as keyof typeof m.roles]}</span>
    </div>
  )
}

type OnboardingProps = {
  onOrganizationCreated: (organization: OrganizationSummary) => void
  onInvitationCreated: (invitation: InvitationRecord) => void
  invitations: InvitationRecord[]
  organizations: OrganizationSummary[]
  activeOrganizationId: string
}

export function Onboarding({ onOrganizationCreated, onInvitationCreated, invitations, organizations, activeOrganizationId }: OnboardingProps) {
  const { m, date } = useI18n()
  const [name, setName] = useState('')
  const [subdomain, setSubdomain] = useState('')
  const [email, setEmail] = useState('')
  const [role, setRole] = useState<InvitationRecord['role']>('Member')
  const [message, setMessage] = useState('')
  const [createdOrganization, setCreatedOrganization] = useState<OrganizationSummary | null>(null)
  const [plan, setPlan] = useState<'Free' | 'Pro' | 'Business'>('Free')
  const normalizedSubdomain = subdomain.trim().toLowerCase()
  const previewSubdomain = normalizedSubdomain.replace(/-+/g, '-').replace(/^-|-$/g, '') || m.onboarding.addressPlaceholder
  const validSubdomain = normalizedSubdomain.length <= 63 && /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(normalizedSubdomain)
  const duplicateSubdomain = organizations.some((item) => item.subdomain === normalizedSubdomain)
  const canCreateOrganization = name.trim().length > 0 && normalizedSubdomain.length > 0 && validSubdomain && !duplicateSubdomain
  const activeOrganizationName = organizations.find((item) => item.id === activeOrganizationId)?.name ?? demoOrganization.name

  function submitOrganization(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!canCreateOrganization) return
    const organizationName = name.trim()
    const created: OrganizationSummary = {
      id: `mock-${normalizedSubdomain}-${Date.now()}`,
      name: organizationName,
      subdomain: normalizedSubdomain,
      initials: organizationName.split(/\s+/).map((word) => word[0]).join('').slice(0, 2).toUpperCase(),
      location: 'Tunisia',
    }
    setCreatedOrganization(created)
    onOrganizationCreated(created)
    setMessage(m.onboarding.messages.orgCreated(created.name, plan))
    setName('')
    setSubdomain('')
  }

  function changeInvitationStatus(invitationId: string, status: InvitationRecord['status']) {
    const existingInvitation = invitations.find((invitation) => invitation.id === invitationId)
    if (!existingInvitation) return
    const now = new Date()
    onInvitationCreated({
      ...existingInvitation,
      status,
      sentAt: status === 'Pending' ? now.toISOString() : existingInvitation.sentAt,
      expiresAt: status === 'Pending' ? new Date(now.getTime() + 72 * 60 * 60 * 1000).toISOString() : existingInvitation.expiresAt,
    })
    setMessage(status === 'Revoked' ? m.onboarding.messages.invitationRevoked : m.onboarding.messages.invitationResent)
  }

  function submitInvitation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const normalizedEmail = email.trim().toLowerCase()
    const now = new Date()
    const invitation: InvitationRecord = {
      id: `mock-invitation-${Date.now()}`,
      organizationId: activeOrganizationId,
      email: normalizedEmail,
      role,
      status: 'Pending',
      sentAt: now.toISOString(),
      expiresAt: new Date(now.getTime() + 72 * 60 * 60 * 1000).toISOString(),
    }
    onInvitationCreated(invitation)
    setEmail('')
    setMessage(m.onboarding.messages.invitationAdded(normalizedEmail))
  }

  return (
    <div className="onboarding-page">
      <section className="onboarding-intro">
        <div>
          <p className="eyebrow">{m.onboarding.eyebrow}</p>
          <h1>{m.onboarding.title}</h1>
          <p>{m.onboarding.subtitle}</p>
        </div>
        <span className="demo-notice"><Icon name="shield" size={15} /> {m.onboarding.demoNotice}</span>
      </section>

      {message && (
        <div className="inline-feedback" role="status">
          <span className="feedback-icon"><Icon name="check" size={17} /></span>
          <span>{message}</span>
          <button type="button" aria-label={m.common.dismiss} onClick={() => setMessage('')}><Icon name="close" size={16} /></button>
        </div>
      )}

      <div className="onboarding-grid">
        <section className="panel onboarding-card">
          <div className="step-heading">
            <span className="step-number">01</span>
            <div><h2>{m.onboarding.title}</h2><p>{m.onboarding.subtitle}</p></div>
          </div>
          <form className="onboarding-form" onSubmit={submitOrganization}>
            <label className="field-label" htmlFor="organization-name">{m.onboarding.orgName}</label>
            <input id="organization-name" className="text-field" autoComplete="organization" placeholder={m.onboarding.orgNamePlaceholder} value={name} onChange={(event) => setName(event.target.value)} required maxLength={120} />

            <label className="field-label" htmlFor="organization-subdomain">{m.onboarding.address}</label>
            <div className="subdomain-field">
              <input id="organization-subdomain" className="text-field" placeholder={m.onboarding.addressPlaceholder} value={subdomain} onChange={(event) => setSubdomain(event.target.value)} required maxLength={63} aria-describedby="subdomain-hint subdomain-state" />
              <span>.keelson.app</span>
            </div>
            <div className="field-hint" id="subdomain-hint">{m.onboarding.addressHint}</div>
            <div className={`availability-state ${duplicateSubdomain || (normalizedSubdomain && !validSubdomain) ? 'availability-error' : canCreateOrganization ? 'availability-ready' : ''}`} id="subdomain-state" aria-live="polite">
              <span />
              {duplicateSubdomain ? m.onboarding.addressTaken : !validSubdomain && normalizedSubdomain ? m.onboarding.addressInvalid : validSubdomain && normalizedSubdomain ? m.onboarding.addressAvailable : m.onboarding.addressPrompt}
            </div>
            <div className="subdomain-preview"><span>{m.onboarding.preview}</span><strong>{previewSubdomain}<span>.keelson.app</span></strong></div>

            <label className="field-label" htmlFor="organization-plan">{m.onboarding.plan}</label>
            <select id="organization-plan" className="text-field role-select" value={plan} onChange={(event) => setPlan(event.target.value as typeof plan)}>
              <option value="Free">{m.onboarding.plans.free}</option>
              <option value="Pro">{m.onboarding.plans.pro}</option>
              <option value="Business">{m.onboarding.plans.business}</option>
            </select>
            <p className="field-hint plan-hint">{m.onboarding.planHint}</p>
            <button className="button button-primary form-submit" type="submit" disabled={!canCreateOrganization}>{m.onboarding.createOrg} <Icon name="arrow" size={16} /></button>
          </form>
        </section>

        <section className="panel onboarding-card invite-card">
          <div className="step-heading">
            <span className="step-number">02</span>
            <div><h2>{m.onboarding.inviteTitle}</h2><p>{m.onboarding.inviteSubtitle(activeOrganizationName)}</p></div>
          </div>
          <form className="onboarding-form" onSubmit={submitInvitation}>
            <label className="field-label" htmlFor="invite-email">{m.onboarding.inviteEmail}</label>
            <div className="input-with-icon">
              <Icon name="mail" size={17} />
              <input id="invite-email" type="email" autoComplete="email" placeholder={m.onboarding.inviteEmailPlaceholder} value={email} onChange={(event) => setEmail(event.target.value)} required />
            </div>
            <label className="field-label" htmlFor="invite-role">{m.onboarding.inviteRole}</label>
            <select id="invite-role" className="text-field role-select" value={role} onChange={(event) => setRole(event.target.value as InvitationRecord['role'])}>
              <option value="Admin">{m.onboarding.roleOptions.admin}</option>
              <option value="Member">{m.onboarding.roleOptions.member}</option>
              <option value="Viewer">{m.onboarding.roleOptions.viewer}</option>
            </select>
            <div className="invite-safety"><Icon name="shield" size={16} /><span>{m.onboarding.inviteSafety}</span></div>
            <button className="button button-secondary form-submit" type="submit"><Icon name="plus" size={17} /> {m.onboarding.addInvitation}</button>
          </form>
          <div className="invitation-list">
            <div className="list-heading">
              <strong>{m.onboarding.recentInvitations}</strong>
              <span>{m.onboarding.pendingCount(invitations.filter((item) => item.status === 'Pending').length)}</span>
            </div>
            {invitations.length === 0
              ? <div className="invitation-empty">{m.onboarding.noInvitations}</div>
              : invitations.slice(0, 3).map((invitation) => (
                <div className="invitation-row" key={invitation.id}>
                  <span className="invite-avatar"><Icon name="mail" size={16} /></span>
                  <span className="invitation-details">
                    <strong>{invitation.email}</strong>
                    <small>
                      {m.roles[invitation.role.toLowerCase() as keyof typeof m.roles]} ·{' '}
                      {invitation.status === 'Pending' ? m.onboarding.expires(date(invitation.expiresAt)) : m.onboarding.revokedSent(date(invitation.sentAt))}
                    </small>
                  </span>
                  <div className="invitation-actions">
                    <span className={`invite-status ${invitation.status === 'Pending' ? 'pending' : 'revoked'}`}>
                      {invitation.status === 'Pending' ? m.invitationStatus.pending : m.invitationStatus.revoked}
                    </span>
                    {invitation.status === 'Pending' ? (
                      <>
                        <button className="invitation-action" type="button" onClick={() => changeInvitationStatus(invitation.id, 'Pending')}>{m.onboarding.resend}</button>
                        <button className="invitation-action revoke-action" type="button" onClick={() => changeInvitationStatus(invitation.id, 'Revoked')}>{m.onboarding.revoke}</button>
                      </>
                    ) : (
                      <button className="invitation-action" type="button" onClick={() => changeInvitationStatus(invitation.id, 'Pending')}>{m.onboarding.resend}</button>
                    )}
                  </div>
                </div>
              ))}
          </div>
        </section>
      </div>

      <section className="panel onboarding-members">
        <div className="panel-heading">
          <div><h2>{m.onboarding.teamPreview}</h2><p>{m.onboarding.sampleMembers(createdOrganization?.name ?? demoOrganization.name)}</p></div>
          <span className="sample-tag">{m.common.mockData}</span>
        </div>
        <div className="member-preview-grid">{mockTeamMembers.map((member) => <MemberPreview member={member} key={member.id} />)}</div>
      </section>
    </div>
  )
}
