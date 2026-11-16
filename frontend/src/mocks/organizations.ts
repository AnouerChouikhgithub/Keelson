import type { OrganizationRole, TeamMember } from '../api/types'

export type InvitationRecord = {
  id: string
  organizationId: string
  email: string
  role: Exclude<OrganizationRole, 'Owner'>
  status: 'Pending' | 'Revoked'
  sentAt: string
  expiresAt: string
}

export const mockTeamMembers: TeamMember[] = [
  { id: 'amine-ben-salah', name: 'Amine Ben Salah', email: 'amine@atelier-noura.tn', initials: 'AB', role: 'Owner', location: 'Tunis' },
  { id: 'ons-trabelsi', name: 'Ons Trabelsi', email: 'ons@atelier-noura.tn', initials: 'OT', role: 'Admin', location: 'Sousse' },
  { id: 'yassine-jebali', name: 'Yassine Jebali', email: 'yassine@atelier-noura.tn', initials: 'YJ', role: 'Member', location: 'Sfax' },
]

export const mockInvitations: InvitationRecord[] = [
  { id: 'invite-ons-001', organizationId: 'atelier-noura', email: 'ons.trabelsi@example.tn', role: 'Member', status: 'Pending', sentAt: '2026-10-22', expiresAt: '2026-10-25' },
]
