import type { InvoiceStatus } from '../api/types'

export type MetricId = 'revenue' | 'outstanding' | 'overdue' | 'paidRate'

export type DashboardMetric = {
  id: MetricId
  value: number
  display: 'currency' | 'percent'
  change: { kind: 'percent' | 'invoices'; value: number }
  direction: 'up' | 'down'
  series: number[]
  tone: 'teal' | 'amber' | 'rose' | 'blue'
}

export type InvoiceRow = {
  number: string
  customer: string
  initials: string
  issueDate: string
  dueDate: string
  amount: number
  status: InvoiceStatus
}

export const organization = {
  name: 'Atelier Noura',
  subdomain: 'atelier-noura',
  initials: 'AN',
  location: 'Tunis, Tunisia',
}

export const dashboardMetrics: DashboardMetric[] = [
  { id: 'revenue', value: 48500, display: 'currency', change: { kind: 'percent', value: 12.8 }, direction: 'up', series: [22, 31, 27, 43, 38, 56, 50, 74, 68, 85, 76, 96], tone: 'teal' },
  { id: 'outstanding', value: 12840, display: 'currency', change: { kind: 'invoices', value: 4 }, direction: 'down', series: [70, 62, 68, 54, 61, 43, 49, 35, 43, 28, 31, 22], tone: 'amber' },
  { id: 'overdue', value: 2350, display: 'currency', change: { kind: 'invoices', value: 2 }, direction: 'down', series: [24, 31, 21, 40, 35, 48, 42, 61, 53, 73, 67, 86], tone: 'rose' },
  { id: 'paidRate', value: 0.924, display: 'percent', change: { kind: 'percent', value: 4.2 }, direction: 'up', series: [34, 39, 36, 48, 46, 60, 56, 65, 70, 74, 83, 91], tone: 'blue' },
]

export const revenueTotal = 48500
export const revenueChangePercent = 12.8
export const invoicesThisMonth = 28
export const paidInvoicesThisMonth = 24

export const revenueByMonth = [
  { monthIndex: 4, value: 29 },
  { monthIndex: 5, value: 42 },
  { monthIndex: 6, value: 36 },
  { monthIndex: 7, value: 55 },
  { monthIndex: 8, value: 49 },
  { monthIndex: 9, value: 72 },
  { monthIndex: 10, value: 63 },
  { monthIndex: 11, value: 88 },
  { monthIndex: 0, value: 77 },
  { monthIndex: 1, value: 94 },
  { monthIndex: 2, value: 82 },
  { monthIndex: 3, value: 100 },
]

export const recentInvoices: InvoiceRow[] = [
  { number: 'INV-2025-084', customer: 'Maison Jasmin', initials: 'MJ', issueDate: '2026-10-22', dueDate: '2026-11-05', amount: 4800, status: 'paid' },
  { number: 'INV-2025-083', customer: 'Société El Amal', initials: 'EA', issueDate: '2026-10-20', dueDate: '2026-11-03', amount: 2350, status: 'overdue' },
  { number: 'INV-2025-082', customer: 'Carthage Studio', initials: 'CS', issueDate: '2026-10-18', dueDate: '2026-11-01', amount: 1200, status: 'sent' },
  { number: 'INV-2025-081', customer: 'Les Oliviers', initials: 'LO', issueDate: '2026-10-15', dueDate: '2026-10-29', amount: 6750, status: 'paid' },
  { number: 'INV-2025-080', customer: 'Yassine Jebali', initials: 'YJ', issueDate: '2026-10-12', dueDate: '2026-10-26', amount: 890, status: 'draft' },
]
