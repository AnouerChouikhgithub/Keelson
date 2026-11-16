import type { Messages } from '../i18n'

/**
 * Maps the current URL to the name shown in the breadcrumb and tab title.
 *
 * The path is the only input, so a nested route (a new invoice, one invoice)
 * still gets a truthful label without every screen registering itself.
 */
export function pageLabel(pathname: string, m: Messages): string {
  const segments = pathname.split('/').filter(Boolean)
  // Workspace routes are `/o/:orgId/<section>[/<rest>]`; the section starts at 2.
  const startsAt = segments[0] === 'o' ? 2 : 0
  const section = segments[startsAt] ?? ''
  const rest = segments[startsAt + 1]

  if (section === 'invoices') {
    if (rest === 'new') return m.page.newInvoice
    if (rest !== undefined) return m.page.invoice
    return m.page.invoices
  }

  switch (section) {
    case 'dashboard': return m.page.dashboard
    case 'customers': return m.page.customers
    case 'team': return m.page.team
    case 'audit': return m.page.audit
    case 'settings': return m.page.settings
    case 'account': return m.page.account
    case 'notifications': return m.page.notifications
    case 'onboarding': return m.page.onboarding
    case 'accept-invitation': return m.acceptInvitation.title
    default: return m.page.dashboard
  }
}
