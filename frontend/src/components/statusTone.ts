import type { InvoiceStatus } from '../mocks/dashboard'

/** Avatar/badge tone per invoice status, shared by the dashboard and invoices table. */
export const statusTone: Record<InvoiceStatus, string> = {
  paid: 'green',
  sent: 'blue',
  overdue: 'red',
  draft: 'gray',
}
