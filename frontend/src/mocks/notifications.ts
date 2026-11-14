import type { PageId } from '../pages'

export type NotificationKind =
  | 'invoicePaid'
  | 'invoiceOverdue'
  | 'invoiceSent'
  | 'invitationAccepted'
  | 'invitationPending'
  | 'memberJoined'
  | 'security'

export type AppNotification = {
  id: string
  kind: NotificationKind
  params: { number?: string; customer?: string; email?: string; name?: string }
  createdAt: string
  destination: PageId
}

export const mockNotifications: AppNotification[] = [
  { id: 'ntf-001', kind: 'invoicePaid', params: { number: 'INV-2025-084', customer: 'Maison Jasmin' }, createdAt: '2026-11-13T08:40:00Z', destination: 'invoices' },
  { id: 'ntf-002', kind: 'invoiceOverdue', params: { number: 'INV-2025-083', customer: 'Société El Amal' }, createdAt: '2026-11-13T07:05:00Z', destination: 'invoices' },
  { id: 'ntf-003', kind: 'invitationAccepted', params: { email: 'ons.trabelsi@example.tn' }, createdAt: '2026-11-12T16:20:00Z', destination: 'team' },
  { id: 'ntf-004', kind: 'invoiceSent', params: { number: 'INV-2025-082', customer: 'Carthage Studio' }, createdAt: '2026-11-12T10:15:00Z', destination: 'invoices' },
  { id: 'ntf-005', kind: 'memberJoined', params: { name: 'Yassine Jebali' }, createdAt: '2026-11-11T14:30:00Z', destination: 'team' },
  { id: 'ntf-006', kind: 'security', params: {}, createdAt: '2026-11-10T09:00:00Z', destination: 'account' },
  { id: 'ntf-007', kind: 'invitationPending', params: { email: 'ons.trabelsi@example.tn' }, createdAt: '2026-11-09T11:45:00Z', destination: 'team' },
]
