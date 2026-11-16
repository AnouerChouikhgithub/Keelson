import type { Messages } from '../i18n'

export type SearchResultKind = 'page' | 'organization'

export type SearchResult = {
  id: string
  kind: SearchResultKind
  title: string
  subtitle?: string
  /** Route the result opens; every workspace route is organization-scoped. */
  to: string
}

const searchablePages = [
  'dashboard',
  'invoices',
  'customers',
  'team',
  'audit',
  'settings',
  'account',
  'notifications',
] as const

/** Detects the platform once so the shortcut hint matches the real key. */
export function searchShortcutLabel(): string {
  return /Mac|iPhone|iPad/.test(navigator.userAgent) ? '⌘ K' : 'Ctrl K'
}

/**
 * Pages plus the caller's organizations. Invoices and customers are absent on
 * purpose: only the loaded page of a list lives in the browser, so a "global"
 * search over them would silently miss records and imply server search.
 */
export function buildSearchResults({
  m,
  organizations,
  organizationId,
}: {
  m: Messages
  organizations: { id: string; name: string; subdomain: string }[]
  organizationId: string | null
}): SearchResult[] {
  const base = organizationId === null ? null : `/o/${organizationId}`
  const results: SearchResult[] = base === null
    ? []
    : searchablePages.map((page) => ({
      id: `page-${page}`,
      kind: 'page',
      title: m.page[page],
      to: `${base}/${page}`,
    }))

  for (const organization of organizations) {
    results.push({
      id: `organization-${organization.id}`,
      kind: 'organization',
      title: organization.name,
      subtitle: `${organization.subdomain}.keelson.app`,
      to: `/o/${organization.id}/dashboard`,
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
