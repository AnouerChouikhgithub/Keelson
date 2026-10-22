import { useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import { dashboardMetrics, organization as demoOrganization, recentInvoices, revenueByMonth } from './mocks/dashboard'
import { mockInvitations, mockOrganizations, mockTeamMembers } from './mocks/organizations'
import type { InvitationRecord, OrganizationSummary, TeamMember } from './mocks/organizations'
import { useTheme } from './theme/ThemeProvider'
import type { ThemeMode } from './theme/ThemeProvider'
import './App.css'

type PageName = 'Dashboard' | 'Onboarding' | 'Invoices' | 'Customers' | 'Team' | 'Billing' | 'Settings'
type IconName = 'grid' | 'file' | 'users' | 'card' | 'settings' | 'help' | 'chevron' | 'plus' | 'search' | 'bell' | 'arrow' | 'calendar' | 'more' | 'trend' | 'clock' | 'sparkle' | 'sun' | 'moon' | 'monitor' | 'menu' | 'building' | 'mail' | 'check' | 'close' | 'link' | 'shield'

const iconPaths: Record<IconName, string[]> = {
  grid: ['M3.5 3.5h7v7h-7zM13.5 3.5h7v7h-7zM3.5 13.5h7v7h-7zM13.5 13.5h7v7h-7z'],
  file: ['M6 2.75h8l4 4v14.5H6z', 'M14 2.75v4h4', 'M9 12h6M9 16h6'],
  users: ['M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20', 'M9.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', 'M17 3.2a4 4 0 0 1 0 7.6M21 20v-1.5a4 4 0 0 0-3-3.87'],
  card: ['M3 6.5h18v12H3z', 'M3 10h18', 'M7 15.5h3'],
  settings: ['M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z', 'M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-1.7 2.94-.1-.03a1.7 1.7 0 0 0-1.8.72l-.04.09h-3.4l-.04-.09a1.7 1.7 0 0 0-1.8-.72l-.1.03-1.7-2.94.06-.06A1.7 1.7 0 0 0 9.5 15l-.1-.03v-3.4l.1-.03a1.7 1.7 0 0 0-.34-1.88L9.1 9.6l1.7-2.94.1.03a1.7 1.7 0 0 0 1.8-.72l.04-.09h3.4l.04.09a1.7 1.7 0 0 0 1.8.72l.1-.03 1.7 2.94-.06.06A1.7 1.7 0 0 0 19.4 12l.1.03v3.4z'],
  help: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M9.6 9a2.5 2.5 0 1 1 4.4 1.6c-1.1 1.1-2 1.4-2 3', 'M12 17.5h.01'],
  chevron: ['m9 18 6-6-6-6'], plus: ['M12 5v14M5 12h14'],
  search: ['M10.8 18.1a7.3 7.3 0 1 0 0-14.6 7.3 7.3 0 0 0 0 14.6z', 'm16.2 16.2 4.3 4.3'],
  bell: ['M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9', 'M10 21h4'],
  arrow: ['M5 12h14', 'm13 6 6 6-6 6'], calendar: ['M4 5h16v16H4z', 'M8 3v4M16 3v4M4 10h16'],
  more: ['M5 12h.01M12 12h.01M19 12h.01'], trend: ['m3 17 6-6 4 4 8-9', 'M15 6h6v6'],
  clock: ['M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z', 'M12 6v6l4 2'],
  sparkle: ['m12 3 1.5 5.5L19 10l-5.5 1.5L12 17l-1.5-5.5L5 10l5.5-1.5z', 'm19 15 .7 2.3L22 18l-2.3.7L19 21l-.7-2.3L16 18l2.3-.7z'],
  sun: ['M12 3v2M12 19v2M3 12h2M19 12h2M5.64 5.64l1.42 1.42m9.88 9.88 1.42 1.42m0-12.72-1.42 1.42m-9.88 9.88-1.42 1.42', 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z'],
  moon: ['M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2z'],
  monitor: ['M3 4h18v13H3z', 'M8 21h8M12 17v4'], menu: ['M4 6h16M4 12h16M4 18h16'],
  building: ['M3 21h18', 'M5 21V5l7-3 7 3v16', 'M9 9h.01M15 9h.01M9 13h.01M15 13h.01M10 21v-4h4v4'],
  mail: ['M4 5h16v14H4z', 'm4 7 8 6 8-6'],
  check: ['m5 12 4 4L19 6'], close: ['m6 6 12 12M18 6 6 18'],
  link: ['M10 13a5 5 0 0 0 7.1 0l3-3a5 5 0 0 0-7.1-7.1l-1.7 1.7', 'M14 11a5 5 0 0 0-7.1 0l-3 3a5 5 0 0 0 7.1 7.1l1.7-1.7'],
  shield: ['M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z', 'm9 12 2 2 4-4'],
}

function Icon({ name, size = 18 }: { name: IconName; size?: number }) {
  return <svg aria-hidden="true" className="icon" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">{iconPaths[name].map((path) => <path key={path} d={path} />)}</svg>
}

const navItems: { label: PageName; icon: IconName }[] = [
  { label: 'Dashboard', icon: 'grid' },
  { label: 'Onboarding', icon: 'building' },
  { label: 'Invoices', icon: 'file' },
  { label: 'Customers', icon: 'users' },
  { label: 'Team', icon: 'users' },
  { label: 'Billing', icon: 'card' },
  { label: 'Settings', icon: 'settings' },
]

function Sparkline({ values }: { values: number[] }) {
  const points = values.map((value, index) => `${(index / (values.length - 1)) * 100},${100 - value}`).join(' ')
  return <svg className="sparkline" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"><polyline points={points} /></svg>
}

function Dashboard({ activeOrganization }: { activeOrganization: OrganizationSummary }) {
  return (
    <>
      <section className="welcome-row">
        <div><p className="eyebrow">WEDNESDAY, OCTOBER 22, 2026</p><h1>Good morning, Amine <span aria-hidden="true">✦</span></h1><p className="welcome-copy">Here’s what’s happening with your business today.</p></div>
        <button className="button button-primary" type="button"><Icon name="plus" size={19} /> Create invoice</button>
      </section>

      <section className="metrics-grid" aria-label="Business overview">
        {dashboardMetrics.map((metric) => <article className={`metric-card metric-${metric.tone}`} key={metric.label}>
          <div className="metric-top"><span>{metric.label}</span><span className="metric-icon"><Icon name={metric.tone === 'teal' ? 'trend' : metric.tone === 'amber' ? 'clock' : metric.tone === 'rose' ? 'calendar' : 'sparkle'} size={17} /></span></div>
          <div className="metric-value">{metric.value}<small>{metric.label === 'Paid rate' ? '' : ' TND'}</small></div>
          <div className="metric-bottom"><span className={`metric-change ${metric.direction}`}><Icon name="trend" size={14} /> {metric.change}</span><span className="metric-caption">vs last month</span><Sparkline values={metric.series} /></div>
        </article>)}
      </section>

      <section className="dashboard-columns">
        <article className="panel revenue-panel">
          <div className="panel-heading"><div><h2>Revenue overview</h2><p>Keep an eye on your business growth</p></div><button className="select-button" type="button"><Icon name="calendar" size={16} /> Last 12 months <Icon name="chevron" size={15} /></button></div>
          <div className="chart-summary"><strong>48,500.000 <span>TND</span></strong><span className="positive-pill"><Icon name="trend" size={14} /> 12.8%</span><span className="chart-comparison">compared to last year</span></div>
          <div className="bar-chart" role="img" aria-label="Revenue chart showing monthly revenue for the last 12 months">
            {revenueByMonth.map((month) => <div className="bar-column" key={month.month}><div className="bar-track"><div className={`bar-fill ${month.month === 'Oct' ? 'bar-current' : ''}`} style={{ height: `${month.value}%` }} /></div><span>{month.month}</span></div>)}
          </div>
        </article>
        <article className="panel activity-panel">
          <div className="panel-heading"><div><h2>Quick overview</h2><p>Your business at a glance</p></div><button className="icon-button" aria-label="More overview options" type="button"><Icon name="more" /></button></div>
          <div className="overview-total"><span>Invoices this month</span><strong>28</strong><span className="overview-note">6 more than last month</span></div>
          <div className="overview-progress"><div><span>Paid invoices</span><strong>24 <small>of 28</small></strong></div><div className="progress-track"><span style={{ width: '86%' }} /></div></div>
          <div className="overview-footer"><span className="avatar-stack"><span className="mini-avatar avatar-one">MJ</span><span className="mini-avatar avatar-two">EA</span><span className="mini-avatar avatar-three">CS</span></span><span>Activity from your team</span><Icon name="chevron" size={16} /></div>
        </article>
      </section>

      <section className="panel invoices-panel">
        <div className="panel-heading invoices-heading"><div><h2>Recent invoices</h2><p>A snapshot of your latest activity</p></div><button className="button button-secondary" type="button">View all invoices <Icon name="arrow" size={16} /></button></div>
        <div className="table-wrap"><table><thead><tr><th>INVOICE</th><th>CUSTOMER</th><th>ISSUE DATE</th><th>DUE DATE</th><th>AMOUNT</th><th>STATUS</th><th><span className="visually-hidden">Actions</span></th></tr></thead>
          <tbody>{recentInvoices.map((invoice) => <tr key={invoice.number}><td className="invoice-number">{invoice.number}</td><td><span className="customer-cell"><span className={`customer-avatar customer-${invoice.tone}`}>{invoice.initials}</span>{invoice.customer}</span></td><td>{invoice.issueDate}</td><td>{invoice.dueDate}</td><td className="amount-cell">{invoice.amount}</td><td><span className={`status-badge status-${invoice.tone}`}><span />{invoice.status}</span></td><td><button className="icon-button row-action" aria-label={`More actions for ${invoice.number}`} type="button"><Icon name="more" /></button></td></tr>)}</tbody>
        </table></div>
      </section>
      <footer className="dashboard-footer"><span>Showing mock data for <strong>{activeOrganization.name}</strong></span><span>All amounts in Tunisian dinar (TND)</span></footer>
    </>
  )
}

function Placeholder({ page }: { page: PageName }) {
  const descriptions: Record<PageName, string> = {
    Dashboard: '', Onboarding: '', Invoices: 'Create, review, and track invoices for your customers.', Customers: 'Keep your customer details organized in one place.', Team: 'Manage your organization members and invitations.', Billing: 'Review your plan and account usage.', Settings: 'Update your organization preferences and appearance.',
  }
  return <section className="placeholder-panel"><span className="placeholder-icon"><Icon name={navItems.find((item) => item.label === page)?.icon ?? 'grid'} size={25} /></span><p className="eyebrow">WORKSPACE</p><h1>{page}</h1><p>{descriptions[page]}</p><span className="coming-soon"><span /> Screen placeholder · mock data only</span></section>
}

function Onboarding({
  onOrganizationCreated,
  onInvitationCreated,
  invitations,
  organizations,
  activeOrganizationId,
}: {
  onOrganizationCreated: (organization: OrganizationSummary) => void
  onInvitationCreated: (invitation: InvitationRecord) => void
  invitations: InvitationRecord[]
  organizations: OrganizationSummary[]
  activeOrganizationId: string
}) {
  const [name, setName] = useState('')
  const [subdomain, setSubdomain] = useState('')
  const [email, setEmail] = useState('')
  const [role, setRole] = useState<InvitationRecord['role']>('Member')
  const [message, setMessage] = useState('')
  const [createdOrganization, setCreatedOrganization] = useState<OrganizationSummary | null>(null)
  const [plan, setPlan] = useState<'Free' | 'Pro' | 'Business'>('Free')
  const normalizedSubdomain = subdomain.trim().toLowerCase()
  const previewSubdomain = normalizedSubdomain.replace(/-+/g, '-').replace(/^-|-$/g, '') || 'your-company'
  const validSubdomain = normalizedSubdomain.length <= 63 && /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(normalizedSubdomain)
  const duplicateSubdomain = organizations.some((item) => item.subdomain === normalizedSubdomain)
  const canCreateOrganization = name.trim().length > 0 && normalizedSubdomain.length > 0 && validSubdomain && !duplicateSubdomain

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
    setMessage(`${created.name} is ready in this local demo on the ${plan} plan.`)
    setName('')
    setSubdomain('')
  }

  function changeInvitationStatus(invitationId: string, status: InvitationRecord['status']) {
    const existingInvitation = invitations.find((invitation) => invitation.id === invitationId)
    if (!existingInvitation) return
    onInvitationCreated({
      ...existingInvitation,
      status,
      sentAt: status === 'Pending' ? 'Just now' : existingInvitation.sentAt,
      expiresAt: status === 'Pending' ? 'In 72 hours' : existingInvitation.expiresAt,
    })
    setMessage(status === 'Revoked' ? 'The sample invitation was revoked locally.' : 'The sample invitation was resent locally. No email or token was sent.')
  }

  function submitInvitation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const normalizedEmail = email.trim().toLowerCase()
    const invitation: InvitationRecord = {
      id: `mock-invitation-${Date.now()}`,
      organizationId: activeOrganizationId,
      email: normalizedEmail,
      role,
      status: 'Pending',
      sentAt: 'Just now',
      expiresAt: 'In 72 hours',
    }
    onInvitationCreated(invitation)
    setEmail('')
    setMessage(`Demo invitation for ${normalizedEmail} was added. No email or token was sent.`)
  }

  return <div className="onboarding-page">
    <section className="onboarding-intro"><div><p className="eyebrow">GETTING STARTED</p><h1>Set up your workspace</h1><p>Create a sample organization and add teammates to explore the onboarding flow.</p></div><span className="demo-notice"><Icon name="shield" size={15} /> Frontend demo · no data is saved</span></section>
    {message && <div className="inline-feedback" role="status"><span className="feedback-icon"><Icon name="check" size={17} /></span><span>{message}</span><button type="button" aria-label="Dismiss notification" onClick={() => setMessage('')}><Icon name="close" size={16} /></button></div>}
    <div className="onboarding-grid">
      <section className="panel onboarding-card"><div className="step-heading"><span className="step-number">01</span><div><h2>Create your organization</h2><p>Your workspace brings your team and customer activity together.</p></div></div>
        <form className="onboarding-form" onSubmit={submitOrganization}>
          <label className="field-label" htmlFor="organization-name">Organization name</label><input id="organization-name" className="text-field" autoComplete="organization" placeholder="e.g. Atelier Noura" value={name} onChange={(event) => setName(event.target.value)} required maxLength={120} />
          <label className="field-label" htmlFor="organization-subdomain">Choose a workspace address</label><div className="subdomain-field"><input id="organization-subdomain" className="text-field" placeholder="your-company" value={subdomain} onChange={(event) => setSubdomain(event.target.value)} required maxLength={63} aria-describedby="subdomain-hint subdomain-state" /><span>.keelson.app</span></div>
          <div className="field-hint" id="subdomain-hint">Use lowercase letters, numbers, and hyphens. This demo checks its local sample list only.</div>
          <div className={`availability-state ${duplicateSubdomain || (normalizedSubdomain && !validSubdomain) ? 'availability-error' : canCreateOrganization ? 'availability-ready' : ''}`} id="subdomain-state" aria-live="polite"><span />{duplicateSubdomain ? 'That sample address is already in use.' : !validSubdomain && normalizedSubdomain ? 'Use 1–63 lowercase letters, numbers, or internal hyphens.' : validSubdomain && normalizedSubdomain ? 'This sample address is available in this demo.' : 'Enter an address to preview its format.'}</div>
          <div className="subdomain-preview"><span>Preview address</span><strong>{previewSubdomain}<span>.keelson.app</span></strong></div>
          <label className="field-label" htmlFor="organization-plan">Choose a starting plan</label><select id="organization-plan" className="text-field role-select" value={plan} onChange={(event) => setPlan(event.target.value as typeof plan)}><option value="Free">Free · explore the workspace</option><option value="Pro">Pro · for growing teams</option><option value="Business">Business · advanced operations</option></select>
          <p className="field-hint plan-hint">Plan selection is a visual demo only. No subscription or billing is created.</p>
          <button className="button button-primary form-submit" type="submit" disabled={!canCreateOrganization}>Create organization <Icon name="arrow" size={16} /></button>
        </form>
      </section>

      <section className="panel onboarding-card invite-card"><div className="step-heading"><span className="step-number">02</span><div><h2>Invite your teammates</h2><p>Invite teammates to {organizations.find((item) => item.id === activeOrganizationId)?.name ?? 'this workspace'} in the local demo.</p></div></div>
        <form className="onboarding-form" onSubmit={submitInvitation}>
          <label className="field-label" htmlFor="invite-email">Teammate email</label><div className="input-with-icon"><Icon name="mail" size={17} /><input id="invite-email" type="email" autoComplete="email" placeholder="name@company.com" value={email} onChange={(event) => setEmail(event.target.value)} required /></div>
          <label className="field-label" htmlFor="invite-role">Workspace role</label><select id="invite-role" className="text-field role-select" value={role} onChange={(event) => setRole(event.target.value as InvitationRecord['role'])}><option value="Admin">Admin · manage workspace members</option><option value="Member">Member · work with customers</option><option value="Viewer">Viewer · read-only access</option></select>
          <div className="invite-safety"><Icon name="shield" size={16} /><span>This demo does not send email, generate invitation tokens, or change permissions.</span></div>
          <button className="button button-secondary form-submit" type="submit"><Icon name="plus" size={17} /> Add sample invitation</button>
        </form>
        <div className="invitation-list"><div className="list-heading"><strong>Recent invitations</strong><span>{invitations.filter((item) => item.status === 'Pending').length} pending</span></div>
          {invitations.length === 0 ? <div className="invitation-empty">No invitations yet. Add a teammate above to preview the list.</div> : invitations.slice(0, 3).map((invitation) => <div className="invitation-row" key={invitation.id}><span className="invite-avatar"><Icon name="mail" size={16} /></span><span className="invitation-details"><strong>{invitation.email}</strong><small>{invitation.role} · {invitation.status === 'Pending' ? `Expires ${invitation.expiresAt}` : `Revoked · sent ${invitation.sentAt}`}</small></span><div className="invitation-actions"><span className={`invite-status ${invitation.status === 'Pending' ? 'pending' : 'revoked'}`}>{invitation.status}</span>{invitation.status === 'Pending' ? <><button className="invitation-action" type="button" onClick={() => changeInvitationStatus(invitation.id, 'Pending')}>Resend</button><button className="invitation-action revoke-action" type="button" onClick={() => changeInvitationStatus(invitation.id, 'Revoked')}>Revoke</button></> : <button className="invitation-action" type="button" onClick={() => changeInvitationStatus(invitation.id, 'Pending')}>Resend</button>}</div></div>)}
        </div>
      </section>
    </div>
    <section className="panel onboarding-members"><div className="panel-heading"><div><h2>Team preview</h2><p>Sample members for {createdOrganization?.name ?? demoOrganization.name}</p></div><span className="sample-tag">MOCK DATA</span></div><div className="member-preview-grid">{mockTeamMembers.map((member) => <MemberPreview member={member} key={member.id} />)}</div></section>
  </div>
}

function MemberPreview({ member }: { member: TeamMember }) {
  return <div className="member-preview"><span className="member-avatar">{member.initials}</span><span className="member-copy"><strong>{member.name}</strong><small>{member.location}</small></span><span className={`role-pill role-${member.role.toLowerCase()}`}>{member.role}</span></div>
}

function OrganizationSwitcher({
  organizations,
  activeOrganizationId,
  onSelect,
  onCreate,
}: {
  organizations: OrganizationSummary[]
  activeOrganizationId: string
  onSelect: (organizationId: string) => void
  onCreate: () => void
}) {
  const [isOpen, setIsOpen] = useState(false)
  const activeOrganization = organizations.find((item) => item.id === activeOrganizationId) ?? organizations[0]
  return <div className="organization-menu-wrap">
    <button className="organization-switcher" type="button" aria-label={`Current organization: ${activeOrganization.name}`} aria-expanded={isOpen} onClick={() => setIsOpen(!isOpen)}><span className="org-avatar">{activeOrganization.initials}</span><span className="org-text"><strong>{activeOrganization.name}</strong><small>{activeOrganization.location}</small></span><Icon name="chevron" size={16} /></button>
    {isOpen && <div className="organization-menu" role="menu" aria-label="Switch organization">
      <p className="menu-eyebrow">YOUR WORKSPACES</p>
      {organizations.map((item) => <button type="button" role="menuitemradio" aria-checked={item.id === activeOrganizationId} className={`organization-option ${item.id === activeOrganizationId ? 'selected' : ''}`} key={item.id} onClick={() => { onSelect(item.id); setIsOpen(false) }}><span className="org-avatar">{item.initials}</span><span><strong>{item.name}</strong><small>{item.location} · {item.subdomain}.keelson.app</small></span>{item.id === activeOrganizationId && <Icon name="check" size={16} />}</button>)}
      <div className="menu-separator" /><button type="button" className="create-workspace-link" onClick={() => { onCreate(); setIsOpen(false) }}><Icon name="plus" size={17} /> Create an organization</button>
      <p className="menu-footnote">Local prototype only · switching is not authorization</p>
    </div>}
  </div>
}

function ThemeControl() {
  const { mode, setMode } = useTheme()
  const options: { value: ThemeMode; label: string; icon: IconName }[] = [{ value: 'light', label: 'Light', icon: 'sun' }, { value: 'dark', label: 'Dark', icon: 'moon' }, { value: 'system', label: 'System', icon: 'monitor' }]
  return <div className="theme-control" role="group" aria-label="Appearance">
    {options.map((option) => <button type="button" key={option.value} aria-label={`${option.label} theme`} aria-pressed={mode === option.value} className={mode === option.value ? 'theme-option selected' : 'theme-option'} onClick={() => setMode(option.value)}><Icon name={option.icon} size={15} /><span>{option.label}</span></button>)}
  </div>
}

function App() {
  const [activePage, setActivePage] = useState<PageName>('Dashboard')
  const [mobileNavOpen, setMobileNavOpen] = useState(false)
  const [organizations, setOrganizations] = useState<OrganizationSummary[]>(mockOrganizations)
  const [activeOrganizationId, setActiveOrganizationId] = useState(mockOrganizations[0].id)
  const [invitations, setInvitations] = useState<InvitationRecord[]>(mockInvitations)
  const activeOrganization = organizations.find((item) => item.id === activeOrganizationId) ?? mockOrganizations[0]
  const isDashboard = activePage === 'Dashboard'
  const pageContent: ReactNode = isDashboard ? <Dashboard activeOrganization={activeOrganization} /> : activePage === 'Onboarding' ? <Onboarding onOrganizationCreated={(newOrganization) => { setOrganizations((current) => [...current, newOrganization]); setActiveOrganizationId(newOrganization.id) }} onInvitationCreated={(invitation) => setInvitations((current) => [invitation, ...current.filter((item) => item.id !== invitation.id)])} invitations={invitations.filter((invitation) => invitation.organizationId === activeOrganizationId)} organizations={organizations} activeOrganizationId={activeOrganizationId} /> : <Placeholder page={activePage} />

  function selectPage(page: PageName) {
    setActivePage(page)
    setMobileNavOpen(false)
  }

  return <div className="app-shell">
    <aside className={`sidebar ${mobileNavOpen ? 'sidebar-open' : ''}`}>
      <a className="brand" href="#dashboard" onClick={(event) => { event.preventDefault(); selectPage('Dashboard') }}><img src="/Keelson.svg" alt="" /><span>keelson</span></a>
      <OrganizationSwitcher organizations={organizations} activeOrganizationId={activeOrganizationId} onSelect={setActiveOrganizationId} onCreate={() => selectPage('Onboarding')} />
      <div className="nav-label">WORKSPACE</div>
      <nav className="primary-nav" aria-label="Main navigation">{navItems.map((item) => <button type="button" key={item.label} className={`nav-link ${activePage === item.label ? 'active' : ''}`} aria-current={activePage === item.label ? 'page' : undefined} onClick={() => selectPage(item.label)}><Icon name={item.icon} /><span>{item.label}</span>{item.label === 'Invoices' && <span className="nav-count">8</span>}</button>)}</nav>
      <div className="sidebar-spacer" />
      <div className="sidebar-help"><div className="help-icon"><Icon name="help" size={18} /></div><div><strong>Need a hand?</strong><span>Visit our help center</span></div><Icon name="arrow" size={15} /></div>
      <div className="profile-row"><span className="profile-avatar">AB</span><span className="profile-info"><strong>Amine Ben Salah</strong><small>Owner</small></span><button type="button" aria-label="Profile options" className="icon-button"><Icon name="more" /></button></div>
    </aside>

    <div className="main-column">
      <header className="topbar"><button type="button" aria-label="Toggle navigation" className="mobile-menu icon-button" onClick={() => setMobileNavOpen(!mobileNavOpen)}><Icon name="menu" /></button><div className="breadcrumbs"><span>{activeOrganization.name}</span><Icon name="chevron" size={14} /><strong>{activePage}</strong></div><div className="topbar-actions"><button className="search-trigger" type="button"><Icon name="search" size={17} /><span>Search anything...</span><kbd>⌘ K</kbd></button><button className="icon-button notification-button" aria-label="Notifications" type="button"><Icon name="bell" /><i /></button><span className="topbar-divider" /><ThemeControl /></div></header>
      <main className="main-content">{pageContent}</main>
    </div>
  </div>
}

export default App
