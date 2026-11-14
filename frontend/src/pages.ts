export type PageId =
  | 'dashboard'
  | 'onboarding'
  | 'invoices'
  | 'customers'
  | 'team'
  | 'billing'
  | 'settings'
  | 'account'
  | 'notifications'
  | 'editor'
  | 'pricing'

export type NavPageId = Exclude<PageId, 'account' | 'notifications' | 'editor'>
