import type { Messages } from '../i18n/messages'
import { recentInvoices } from '../mocks/dashboard'
import type { AppNotification } from '../mocks/notifications'
import type { OrganizationSummary } from '../mocks/organizations'
import { notificationText } from '../notifications/notificationText'
import type { PageId } from '../pages'

export type SearchResultKind = 'page' | 'organization' | 'invoice' | 'customer' | 'notification'

export type SearchResult = {
  id: string
  kind: SearchResultKind
  title: string
  subtitle?: string
  page: PageId
}

const searchablePages: PageId[] = [
  'dashboard',
  'onboarding',
  'invoices',
  'customers',
  'team',
  'billing',
  'settings',
  'notifications',
  'account',
]

/** Detects the platform once so the shortcut hint matches the real key. */
export function searchShortcutLabel(): string {
  return /Mac|iPhone|iPad/.test(navigator.userAgent) ? '⌘ K' : 'Ctrl K'
}

export function buildSearchResults({
  m,
  organizations,
  notifications,
}: {
  m: Messages
  organizations: OrganizationSummary[]
  notifications: AppNotification[]
}): SearchResult[] {
  const results: SearchResult[] = searchablePages.map((page) => ({
    id: `page-${page}`,
    kind: 'page',
    title: m.page[page],
    page,
  }))

  for (const organization of organizations) {
    results.push({
      id: `organization-${organization.id}`,
      kind: 'organization',
      title: organization.name,
      subtitle: `${organization.location} · ${organization.subdomain}.keelson.app`,
      page: 'onboarding',
    })
  }

  for (const invoice of recentInvoices) {
    results.push({
      id: `invoice-${invoice.number}`,
      kind: 'invoice',
      title: invoice.number,
      subtitle: invoice.customer,
      page: 'invoices',
    })
  }

  for (const customer of new Set(recentInvoices.map((invoice) => invoice.customer))) {
    results.push({
      id: `customer-${customer}`,
      kind: 'customer',
      title: customer,
      page: 'customers',
    })
  }

  for (const notification of notifications) {
    results.push({
      id: `notification-${notification.id}`,
      kind: 'notification',
      title: notificationText(notification, m),
      subtitle: m.page.notifications,
      page: notification.destination,
    })
  }

  return results
}

/** Matches on title and subtitle; a blank query intentionally returns nothing. */
export function filterSearchResults(results: SearchResult[], query: string, locale: string): SearchResult[] {
  const needle = query.trim().toLocaleLowerCase(locale)
  if (!needle) return []
  return results
    .filter((result) => `${result.title} ${result.subtitle ?? ''}`.toLocaleLowerCase(locale).includes(needle))
    .slice(0, 8)
}
