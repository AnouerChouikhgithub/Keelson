import { expect, test } from '@playwright/test'
import { uniqueEmail } from '../support/session'

/**
 * Journey (e): a wrong password says only that the credentials are wrong, and
 * repeated attempts are throttled with a message a person can act on.
 *
 * A throwaway address is used so the shared fixture accounts keep working even
 * if the limiter is hit mid-run (the buckets are per address).
 */
test('a wrong password is refused and repeated attempts are throttled', async ({ page }) => {
  const email = uniqueEmail('e2e-throttle')
  const wrongPassword = page.getByRole('alert').filter({ hasText: 'The email address or password is incorrect.' })
  const throttled = page.getByRole('alert').filter({ hasText: 'Too many attempts. Try again in a minute.' })

  await page.goto('/sign-in')
  await page.getByLabel('Email', { exact: true }).fill(email)
  await page.getByLabel('Password', { exact: true }).fill('definitely-not-the-password')
  await page.getByRole('button', { name: 'Sign in' }).click()

  await expect(wrongPassword).toBeVisible()
  await expect(page).toHaveURL(/\/sign-in$/)

  // The limiter allows five attempts per address per minute; a later one answers
  // 429. Each attempt clears the previous alert, so wait for an alert to appear
  // and then read which one it is.
  for (let attempt = 0; attempt < 7; attempt += 1) {
    if (await throttled.isVisible().catch(() => false)) break
    await page.getByRole('button', { name: /^Sign in/ }).click()
    await expect(page.getByRole('alert').first()).toBeVisible()
    if (await throttled.isVisible().catch(() => false)) break
    await expect(wrongPassword).toBeVisible()
  }

  await expect(throttled).toBeVisible()
})
