export type OrganizationSummary = {
  id: string
  name: string
  subdomain: string
  initials: string
  location: string
}

export type TeamMember = {
  id: string
  name: string
  email: string
  initials: string
  role: 'Owner' | 'Admin' | 'Member' | 'Viewer'
  location: string
}

export type InvitationRecord = {
  id: string
  organizationId: string
  email: string
  role: 'Admin' | 'Member' | 'Viewer'
  status: 'Pending' | 'Revoked'
  sentAt: string
  expiresAt: string
}

export const mockOrganizations: OrganizationSummary[] = [
  { id: 'atelier-noura', name: 'Atelier Noura', subdomain: 'atelier-noura', initials: 'AN', location: 'Tunis, Tunisia' },
  { id: 'carthage-studio', name: 'Carthage Studio', subdomain: 'carthage-studio', initials: 'CS', location: 'La Marsa, Tunisia' },
]

export const mockTeamMembers: TeamMember[] = [
  { id: 'amine-ben-salah', name: 'Amine Ben Salah', email: 'amine@atelier-noura.tn', initials: 'AB', role: 'Owner', location: 'Tunis' },
  { id: 'ons-trabelsi', name: 'Ons Trabelsi', email: 'ons@atelier-noura.tn', initials: 'OT', role: 'Admin', location: 'Sousse' },
  { id: 'yassine-jebali', name: 'Yassine Jebali', email: 'yassine@atelier-noura.tn', initials: 'YJ', role: 'Member', location: 'Sfax' },
]

export const mockInvitations: InvitationRecord[] = [
  { id: 'invite-ons-001', organizationId: 'atelier-noura', email: 'ons.trabelsi@example.tn', role: 'Member', status: 'Pending', sentAt: '22 Oct 2026', expiresAt: '25 Oct 2026' },
]
