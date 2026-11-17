/**
 * Shared vocabulary: every screen uses these words, so they live in one area
 * file instead of being repeated per feature.
 */
export const common = {
  languageName: 'English',
  skipToContent: 'Skip to content',
  loading: 'Loading…',
  loadingMore: 'Loading…',
  retry: 'Try again',
  reload: 'Reload',
  cancel: 'Cancel',
  close: 'Close',
  saving: 'Saving…',
  delete: 'Delete',
  search: 'Search',
  loadMore: 'Load more',
  loadedCount: (count: number) => (count === 1 ? '1 record loaded' : `${count} records loaded`),
  actions: 'Actions',
  role: 'Role',
  organization: 'Organization',
}

export const nav = {
  workspace: 'WORKSPACE',
  mainNav: 'Main navigation',
  breadcrumb: 'Breadcrumb',
  dashboard: 'Dashboard',
  invoices: 'Invoices',
  customers: 'Customers',
  team: 'Team',
  audit: 'Audit log',
  settings: 'Settings',
  account: 'My account',
  notifications: 'Notifications',
  notificationsWithCount: (count: number) => `Notifications (${count} unread)`,
  toggleNav: 'Toggle navigation',
  closeNav: 'Close navigation',
  currentOrg: (name: string) => `Current organization: ${name}`,
  switchOrg: 'Switch organization',
  yourWorkspaces: 'YOUR WORKSPACES',
  createOrg: 'Create an organization',
  menuFootnote: 'The URL selects the tenant; the server re-authorizes every request.',
  openProfileMenu: 'Open profile menu',
  profileMenuLabel: 'Account menu',
}

export const page = {
  dashboard: 'Dashboard',
  invoices: 'Invoices',
  newInvoice: 'New invoice',
  invoice: 'Invoice',
  customers: 'Customers',
  team: 'Team',
  audit: 'Audit log',
  settings: 'Settings',
  account: 'My account',
  notifications: 'Notifications',
  onboarding: 'Onboarding',
  notFound: 'Page not found',
}

export const theme = {
  appearance: 'Appearance',
  light: 'Light',
  dark: 'Dark',
  system: 'System',
  setTheme: (label: string) => `${label} theme`,
}

export const search = {
  title: 'Search Keelson',
  placeholder: 'Search pages and organizations…',
  open: 'Open search',
  close: 'Close search',
  shortcutHint: (keys: string) => `Press ${keys} to open search`,
  noResults: (query: string) => `No results for “${query}”`,
  noResultsHint: 'Try a page name or one of your organizations.',
  resultCount: (count: number) => (count === 1 ? '1 result' : `${count} results`),
  hint: 'Use the arrow keys to move, Enter to open.',
  kinds: {
    page: 'Page',
    organization: 'Organization',
  },
}

export const api = {
  loading: 'Loading…',
  offline: 'The server could not be reached. Start the API, check your connection, then try again.',
  timeout: 'The server took too long to respond. Try again.',
  unauthorized: 'Your session is no longer valid. Sign in again to continue.',
  forbidden: 'Your workspace role does not allow this action.',
  notFound: 'That record no longer exists.',
  conflict: 'The record changed on the server. The latest version was loaded.',
  invalid: 'The server rejected some values.',
  rateLimited: 'Too many requests. Wait a moment, then try again.',
  server: 'The server could not complete the request.',
  unexpected: 'The server returned an unexpected response.',
  listFailed: 'This list could not be loaded.',
}

export const profile = {
  myAccount: 'My account',
  notifications: 'Notifications',
  settings: 'Settings',
  logout: 'Log out',
}

export const session = {
  cardTitle: 'Session',
  signingOut: 'Signing out…',
  readFailed: 'The session could not be read from the server. Start the API, then try again.',
}

export const status = {
  paid: 'Paid',
  sent: 'Sent',
  overdue: 'Overdue',
  draft: 'Draft',
}

export const roles = {
  owner: 'Owner',
  admin: 'Admin',
  member: 'Member',
  viewer: 'Viewer',
}

export const notFound = {
  eyebrow: '404',
  title: 'This page does not exist',
  body: 'The address may be mistyped, or the record was removed. Choose a workspace from the switcher to continue.',
  backToDashboard: 'Go to the dashboard',
}

export const toast = {
  region: 'Status messages',
  dismiss: 'Dismiss notification',
}

export const errors = {
  boundaryTitle: 'This screen stopped responding',
  boundaryBody: 'An unexpected client error was caught. Your data is on the server; reload the screen to continue.',
  boundaryReload: 'Reload the screen',
}
