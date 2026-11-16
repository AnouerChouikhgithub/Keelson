/**
 * Types for the verified backend contract in `docs/api-contract.md`.
 *
 * Every shape here was read from `backend/src` (and its serializers) — not from
 * a screen design. Keep this file in sync with that document rather than
 * inventing fields the API does not return.
 */

export type BillingAddress = {
  street: string
  city: string
  postalCode: string
  countryCode: string
}

/** Customer as returned by list/read/create/update (server lower-cases email). */
export type Customer = {
  id: string
  name: string
  email: string | null
  phone: string | null
  billingAddress?: BillingAddress | null
}

/** The list endpoint omits `billingAddress`; read returns it. */
export type CustomerSummary = Omit<Customer, 'billingAddress'>

export type CustomerInput = {
  name: string
  email: string | null
  phone: string | null
  billingAddress: BillingAddress | null
}

export type CustomerPatch = Partial<CustomerInput>

export type InvoiceStatus = 'draft' | 'sent' | 'paid' | 'overdue'

/** Mirrors `Invoice::getLineItems()` after `InvoiceCalculator` normalization. */
export type InvoiceLineItem = {
  description: string
  quantity: string
  unitPrice: string
  lineTotal: string
}

export type InvoiceCustomerSnapshot = {
  id: string
  name: string
  email: string | null
  phone: string | null
  billingAddress: BillingAddress | null
}

export type Invoice = {
  id: string
  number: string
  customerId: string
  customer: InvoiceCustomerSnapshot
  /** Already derived for readers by the server; never recomputed in the UI. */
  status: InvoiceStatus
  issueDate: string
  dueDate: string
  currency: string
  lineItems: InvoiceLineItem[]
  taxRate: string | null
  discountAmount: string | null
  subtotal: string
  taxAmount: string
  total: string
  sentAt: string | null
  paidAt: string | null
}

/** What `POST`/`PATCH /invoices` accepts. Amounts are decimal strings. */
export type InvoiceDraftInput = {
  customerId: string
  issueDate: string
  dueDate: string
  currency: 'TND'
  lineItems: { description: string; quantity: string; unitPrice: string }[]
  taxRate: string | null
  discountAmount: string | null
}

export type Paginated<T> = {
  items: T[]
  nextCursor: string | null
}

export type OrganizationRole = 'Owner' | 'Admin' | 'Member' | 'Viewer'

/** Row of `GET /api/me/organizations`. */
export type OrganizationMembership = {
  id: string
  name: string
  subdomain: string
  role: OrganizationRole
}

/**
 * A created/selected organization as the screens render it. Not an API shape:
 * organization creation has no endpoint yet, so this is built locally.
 */
export type OrganizationSummary = {
  id: string
  name: string
  subdomain: string
  initials: string
  location: string
}

/** Team rows are mock data until the team API exists. */
export type TeamMember = {
  id: string
  name: string
  email: string
  initials: string
  role: OrganizationRole
  location: string
}
