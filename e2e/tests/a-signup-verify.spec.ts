import { expect, test } from '@playwright/test'
import { waitForMailLink } from '../support/mailpit'
import { DEMO_PASSWORD, signIn, signOut, uniqueEmail } from '../support/session'

/**
 * Journey (a): a brand-new person signs up, confirms the address through the
 * mail Mailpit captured, signs in, creates workspace data and signs out.
 *
 * The verification link is followed from the message body, not synthesised from
 * the database, so a broken mailer fails this test.
 */
test('sign up, verify by email, sign in, create a customer and sign out', async ({ page }) => {
  const email = uniqueEmail('e2e-signup')

  await page.goto('/sign-up')
  await page.getByLabel('Email', { exact: true }).fill(email)
  await page.getByLabel('Password', { exact: true }).fill(DEMO_PASSWORD)
  await page.getByLabel('Your name', { exact: true }).fill('E2E Signup Owner')
  await page.getByLabel('Organization name', { exact: true }).fill('E2E Signup Studio')
  await page.getByRole('button', { name: 'Create account' }).click()

  await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible()
  await expect(page.getByText(email)).toBeVisible()

  const verifyPath = await waitForMailLink(email, '/verify-email')
  await page.goto(verifyPath)
  await expect(page.getByRole('heading', { name: 'Email confirmed' })).toBeVisible()

  await signIn(page, email)

  // The new account owns its first organization, so the workspace is usable now.
  await page.getByRole('link', { name: 'Customers', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Customers', exact: true })).toBeVisible()

  await page.getByRole('button', { name: 'Add customer' }).click()
  // `Field` appends its required marker to the label, so match by prefix.
  await page.getByLabel('Name', { exact: false }).fill('E2E Signup Client')
  await page.getByLabel('Email', { exact: false }).fill('client@example.test')
  await page.getByRole('button', { name: 'Create customer' }).click()
  await expect(page.getByText('E2E Signup Client')).toBeVisible()

  await signOut(page)
})
