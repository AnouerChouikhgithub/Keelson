import type { InvoiceStatus } from './dashboard'

export type InvoiceRecord = {
  id: string
  number: string
  customer: string
  initials: string
  issueDate: string
  dueDate: string
  amount: number
  status: InvoiceStatus
}

/** Invoice table fixtures for the Invoices screen. Amounts are TND, dates ISO. */
export const invoiceRecords: InvoiceRecord[] = [
  { id: 'inv-084', number: 'INV-2026-084', customer: 'Maison Jasmin', initials: 'MJ', issueDate: '2026-10-22', dueDate: '2026-11-05', amount: 4800, status: 'paid' },
  { id: 'inv-083', number: 'INV-2026-083', customer: 'Société El Amal', initials: 'EA', issueDate: '2026-10-20', dueDate: '2026-11-03', amount: 2350, status: 'overdue' },
  { id: 'inv-082', number: 'INV-2026-082', customer: 'Carthage Studio', initials: 'CS', issueDate: '2026-10-18', dueDate: '2026-11-01', amount: 1200, status: 'sent' },
  { id: 'inv-081', number: 'INV-2026-081', customer: 'Les Oliviers', initials: 'LO', issueDate: '2026-10-15', dueDate: '2026-10-29', amount: 6750, status: 'paid' },
  { id: 'inv-080', number: 'INV-2026-080', customer: 'Yassine Jebali', initials: 'YJ', issueDate: '2026-10-12', dueDate: '2026-10-26', amount: 890, status: 'draft' },
  { id: 'inv-079', number: 'INV-2026-079', customer: 'Atelier Medina', initials: 'AM', issueDate: '2026-10-08', dueDate: '2026-10-22', amount: 3150, status: 'paid' },
  { id: 'inv-078', number: 'INV-2026-078', customer: 'Ons Trabelsi', initials: 'OT', issueDate: '2026-10-05', dueDate: '2026-10-19', amount: 1420, status: 'overdue' },
  { id: 'inv-077', number: 'INV-2026-077', customer: 'Bizerte Marine', initials: 'BM', issueDate: '2026-10-02', dueDate: '2026-10-16', amount: 5600, status: 'sent' },
  { id: 'inv-076', number: 'INV-2026-076', customer: 'Sidi Bou Café', initials: 'SB', issueDate: '2026-09-28', dueDate: '2026-10-12', amount: 780, status: 'paid' },
  { id: 'inv-075', number: 'INV-2026-075', customer: 'Amine Ben Salah', initials: 'AB', issueDate: '2026-09-24', dueDate: '2026-10-08', amount: 2100, status: 'paid' },
  { id: 'inv-074', number: 'INV-2026-074', customer: 'Sfax Textiles', initials: 'ST', issueDate: '2026-09-20', dueDate: '2026-10-04', amount: 9420, status: 'draft' },
  { id: 'inv-073', number: 'INV-2026-073', customer: 'La Marsa Design', initials: 'LM', issueDate: '2026-09-16', dueDate: '2026-09-30', amount: 1980, status: 'paid' },
]
