export type PlanId = 'free' | 'pro'

export type PlanCatalogEntry = {
  id: PlanId
  /** Phase 8: Pro stays test-fixture-only until a provider/checkout is approved. */
  testOnly: boolean
}

/** Free and Pro only: the recorded Phase 8 policy, not the three-plan UI sketch. */
export const planCatalog: PlanCatalogEntry[] = [
  { id: 'free', testOnly: false },
  { id: 'pro', testOnly: true },
]

export const currentPlan: PlanId = 'free'

/** Limits and prices are required configuration with no defaults (Phase 8). */
export const configuredLimits: { invoices: number | null; seats: number | null } = {
  invoices: null,
  seats: null,
}

export const usage = {
  invoiceDraftsThisMonth: 28,
  activeSeats: 3,
}

/** No provider is selected, so there is no payment method and no billing history. */
export const billingHistory: { id: string; date: string; amount: number; status: 'paid' }[] = []
