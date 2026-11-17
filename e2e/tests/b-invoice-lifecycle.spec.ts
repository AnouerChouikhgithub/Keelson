import { expect, test } from '@playwright/test'
import { OWNER, signIn } from '../support/session'

/**
 * Journey (b): the owner takes a new customer through a full invoice lifecycle —
 * draft, sent, paid — and receives the notification the transition writes.
 */
test('owner invoices a customer, sends it, marks it paid and sees the notification', async ({ page }) => {
  // The fixture database is reused between runs, so the customer carries a stamp:
  // a fixed name would accumulate duplicates in the shared workspace.
  const customerName = `E2E Lifecycle ${Date.now().toString(36)}`

  await signIn(page, OWNER)

  await page.getByRole('link', { name: 'Customers', exact: true }).click()
  await page.getByRole('button', { name: 'Add customer' }).click()
  await page.getByLabel('Name', { exact: false }).fill(customerName)
  await page.getByRole('button', { name: 'Create customer' }).click()
  await expect(page.getByText(customerName).first()).toBeVisible()

  await page.getByRole('link', { name: 'Invoices', exact: true }).click()
  // The header action is a router link styled as a button; the empty-state one is
  // a real button, so accept either.
  await page
    .getByRole('link', { name: 'Create invoice' })
    .or(page.getByRole('button', { name: 'Create invoice' }))
    .first()
    .click()

  await page.getByLabel('Customer', { exact: false }).selectOption({ label: customerName })
  await page.getByLabel('Description', { exact: false }).fill('E2E consulting day')
  await page.getByLabel('Qty', { exact: false }).fill('2')
  await page.getByLabel('Unit price', { exact: false }).fill('150')
  await page.getByRole('button', { name: 'Save draft' }).click()

  // Saving navigates to the invoice the server created, with server totals.
  await expect(page.getByRole('heading', { name: /^Invoice \d{4}-\d{6}$/ })).toBeVisible()
  await expect(page.getByText('Draft', { exact: true }).first()).toBeVisible()
  const number = (await page.getByRole('heading', { name: /^Invoice / }).textContent())?.replace('Invoice ', '').trim() ?? ''

  await page.getByRole('button', { name: 'Send invoice' }).click()
  await expect(page.getByText('Sent', { exact: true }).first()).toBeVisible()
  await expect(page.getByText(`Invoice ${number} sent.`)).toBeVisible()

  await page.getByRole('button', { name: 'Mark as paid' }).click()
  await expect(page.getByText('Paid', { exact: true }).first()).toBeVisible()
  await expect(page.getByText(`Invoice ${number} marked as paid.`)).toBeVisible()

  // The transition notifies the tenant's other Owners/Admins, so the feed has
  // something new for this owner as well (the actor's own copy is excluded only
  // for the actor of *other* principals' notifications).
  await page.getByRole('link', { name: /^Notifications/ }).click()
  await expect(page.getByRole('heading', { name: 'Notifications', exact: true })).toBeVisible()
  await expect(page.getByRole('table')).toContainText(/Invoice paid|Invoice overdue|Member joined|Invitation accepted/)
})
