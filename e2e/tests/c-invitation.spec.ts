import { expect, test } from '@playwright/test'
import { waitForMailLink } from '../support/mailpit'
import { DEMO_PASSWORD, OWNER, signIn, uniqueEmail } from '../support/session'

/**
 * Journey (c): an Owner invites an address, and the invitation is accepted in a
 * second browser context — a different person, not the inviter's session.
 *
 * The link comes from the invitation email in Mailpit, so delivery, the token
 * digest and the public preview endpoint are all exercised.
 */
test('owner invites a new user and the invitation is accepted in a second context', async ({ page, browser }) => {
  const email = uniqueEmail('e2e-invite')

  await signIn(page, OWNER)
  await page.getByRole('link', { name: 'Team', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Team', exact: true })).toBeVisible()

  await page.getByLabel('Email', { exact: false }).fill(email)
  await page.getByRole('button', { name: 'Send invitation' }).click()
  await expect(page.getByText(`Invitation sent to ${email}.`)).toBeVisible()
  await expect(page.getByRole('table', { name: 'Pending invitations in this workspace' })).toContainText(email)

  const invitePath = await waitForMailLink(email, '/accept-invitation')

  const inviteeContext = await browser.newContext()
  const invitee = await inviteeContext.newPage()
  try {
    await invitee.goto(invitePath)
    await expect(invitee.getByRole('heading', { name: 'Accept your invitation' })).toBeVisible()
    await expect(invitee.getByText(email)).toBeVisible()

    await invitee.getByLabel('Your name', { exact: false }).fill('E2E Invitee')
    // Exact, or the confirm field and both reveal buttons match too.
    await invitee.getByLabel('Password', { exact: true }).fill(DEMO_PASSWORD)
    await invitee.getByLabel('Confirm password', { exact: true }).fill(DEMO_PASSWORD)
    await invitee.getByRole('button', { name: 'Accept invitation' }).click()

    await expect(invitee.getByRole('heading', { name: 'You joined the workspace' })).toBeVisible()

    // A brand-new address gets an account, so the screen points at sign-in rather
    // than at the workspace: the invitation proves the address, the password is
    // the invitee's to choose at this step.
    await invitee.getByRole('link', { name: 'Sign in' }).click()
    await invitee.getByLabel('Email', { exact: true }).fill(email)
    await invitee.getByLabel('Password', { exact: true }).fill(DEMO_PASSWORD)
    await invitee.getByRole('button', { name: 'Sign in' }).click()

    // The new member can read the workspace they were invited to.
    await expect(invitee.getByRole('button', { name: 'Open profile menu' })).toBeVisible()
    await invitee.getByRole('link', { name: 'Customers', exact: true }).click()
    await expect(invitee.getByRole('heading', { name: 'Customers', exact: true })).toBeVisible()
  } finally {
    await inviteeContext.close()
  }
})
