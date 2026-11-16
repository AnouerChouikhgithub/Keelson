/**
 * Types for the verified backend contract in `docs/api-contract.md`.
 *
 * Every shape here was read from `backend/src` — not from a screen design.
 * Keep this file in sync with that document rather than inventing fields the
 * API does not return.
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

/** Roles an invitation may carry: ownership is never handed out by invitation. */
export type InviteRole = Exclude<OrganizationRole, 'Owner'>

/** Row of `GET …/members`. `name` is the principal's display name, if any. */
export type Member = {
  id: string
  principalId: string
  email: string
  name: string | null
  role: OrganizationRole
  joinedAt: string
}

export type InvitationStatus = 'pending' | 'accepted' | 'revoked'

/** Row of `GET …/invitations`; the plaintext token is never part of it. */
export type Invitation = {
  id: string
  email: string
  role: OrganizationRole
  status: InvitationStatus
  expired: boolean
  expiresAt: string
  createdAt: string
}

/** `GET /api/invitations/{token}` — public, reveals nothing about accounts. */
export type InvitationPreview = {
  organizationName: string
  role: OrganizationRole
  email: string
  expired: boolean
  accepted: boolean
}

export type AcceptInvitationResult = {
  organization: { id: string; name: string }
  role: OrganizationRole
  accountCreated: boolean
}

/** Row of `GET /api/notifications`. `data` is opaque per `type`. */
export type AppNotification = {
  id: string
  type: string
  data: Record<string, unknown>
  organizationId: string | null
  read: boolean
  createdAt: string
  readAt: string | null
}

/** `PATCH /api/me` response. */
export type ProfileUser = {
  id: string
  email: string
  displayName: string | null
}

/** Organization administration responses (`POST`/`PATCH`/`DELETE /api/organizations`). */
export type OrganizationDetail = {
  id: string
  name: string
  subdomain: string
  status: string
  role: OrganizationRole
  createdAt: string
}

/** Row of `GET /api/me/organizations`. */
export type OrganizationMembership = {
  id: string
  name: string
  subdomain: string
  role: OrganizationRole
}

/**
 * Row of `GET …/invoice-audit-events`.
 *
 * The endpoint returns a single page (`limit` only, no cursor), so the audit
 * screen pages through what it received rather than pretending to page the API.
 */
export type AuditEvent = {
  id: string
  invoiceId: string | null
  customerId: string | null
  action: string
  actorPrincipalId: string | null
  metadata: Record<string, unknown> | null
  createdAt: string
}
