import { ai } from './ai'
import { acceptInvitation, auth } from './auth'
import { account, onboarding, settings } from './settings'
import { common, errors, nav, notFound, page, profile, roles, search, session, status, theme, toast, api } from './common'
import { customers } from './customers'
import { dashboard } from './dashboard'
import { invoices } from './invoices'
import { notifications, team } from './team'

/**
 * The English dictionary is the source of truth for the message shape; `fr` and
 * `ar` are typed against it, so a missing key is a type error and the parity
 * test catches empty strings.
 */
export const en = {
  common,
  nav,
  page,
  theme,
  search,
  api,
  profile,
  session,
  status,
  roles,
  toast,
  errors,
  notFound,
  auth,
  acceptInvitation,
  dashboard,
  invoices,
  customers,
  team,
  notifications,
  onboarding,
  settings,
  account,
  ai,
}
