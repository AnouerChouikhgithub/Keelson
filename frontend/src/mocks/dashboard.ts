export type DashboardMetric = {
  label: string
  value: string
  change: string
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
  amount: string
  status: 'Paid' | 'Sent' | 'Overdue' | 'Draft'
  tone: 'green' | 'blue' | 'red' | 'gray'
}

export const organization = {
  name: 'Atelier Noura',
  subdomain: 'atelier-noura',
  initials: 'AN',
  location: 'Tunis, Tunisia',
}

export const dashboardMetrics: DashboardMetric[] = [
  { label: 'Revenue this month', value: '48,500.000', change: '+12.8%', direction: 'up', series: [22, 31, 27, 43, 38, 56, 50, 74, 68, 85, 76, 96], tone: 'teal' },
  { label: 'Outstanding', value: '12,840.000', change: '4 invoices', direction: 'up', series: [70, 62, 68, 54, 61, 43, 49, 35, 43, 28, 31, 22], tone: 'amber' },
  { label: 'Overdue', value: '2,350.000', change: '2 invoices', direction: 'down', series: [24, 31, 21, 40, 35, 48, 42, 61, 53, 73, 67, 86], tone: 'rose' },
  { label: 'Paid rate', value: '92.4%', change: '+4.2%', direction: 'up', series: [34, 39, 36, 48, 46, 60, 56, 65, 70, 74, 83, 91], tone: 'blue' },
]

export const revenueByMonth = [
  { month: 'May', value: 29 },
  { month: 'Jun', value: 42 },
  { month: 'Jul', value: 36 },
  { month: 'Aug', value: 55 },
  { month: 'Sep', value: 49 },
  { month: 'Oct', value: 72 },
  { month: 'Nov', value: 63 },
  { month: 'Dec', value: 88 },
  { month: 'Jan', value: 77 },
  { month: 'Feb', value: 94 },
  { month: 'Mar', value: 82 },
  { month: 'Apr', value: 100 },
]

export const recentInvoices: InvoiceRow[] = [
  { number: 'INV-2025-084', customer: 'Maison Jasmin', initials: 'MJ', issueDate: '22 Oct 2025', dueDate: '05 Nov 2025', amount: '4,800.000 TND', status: 'Paid', tone: 'green' },
  { number: 'INV-2025-083', customer: 'Société El Amal', initials: 'EA', issueDate: '20 Oct 2025', dueDate: '03 Nov 2025', amount: '2,350.000 TND', status: 'Overdue', tone: 'red' },
  { number: 'INV-2025-082', customer: 'Carthage Studio', initials: 'CS', issueDate: '18 Oct 2025', dueDate: '01 Nov 2025', amount: '1,200.000 TND', status: 'Sent', tone: 'blue' },
  { number: 'INV-2025-081', customer: 'Les Oliviers', initials: 'LO', issueDate: '15 Oct 2025', dueDate: '29 Oct 2025', amount: '6,750.000 TND', status: 'Paid', tone: 'green' },
  { number: 'INV-2025-080', customer: 'Yassine Jebali', initials: 'YJ', issueDate: '12 Oct 2025', dueDate: '26 Oct 2025', amount: '890.000 TND', status: 'Draft', tone: 'gray' },
]
