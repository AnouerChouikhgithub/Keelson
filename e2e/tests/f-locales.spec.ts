import { expect, test } from '@playwright/test'
import { DEMO_PASSWORD, OWNER } from '../support/session'

/**
 * Journey (f): the two translated locales still sign in, and Arabic flips the
 * document to right-to-left.
 *
 * The fields are addressed by type rather than by label: the point of the smoke
 * test is that the labels themselves changed language.
 */
test.describe('locales', () => {
  test('French sign-in smoke test', async ({ page }) => {
    await page.goto('/sign-in')
    await page.locator('#auth-locale').selectOption('fr')

    await expect(page.locator('html')).toHaveAttribute('lang', 'fr')
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
    await expect(page.getByRole('heading', { level: 1 })).not.toHaveText(/Sign in to Keelson/)

    await page.locator('input[type="email"]').fill(OWNER)
    await page.locator('input[type="password"]').fill(DEMO_PASSWORD)
    await page.locator('form').getByRole('button', { name: /./ }).last().click()

    await expect(page).not.toHaveURL(/\/sign-in$/)
    await expect(page.getByRole('button', { name: 'Ouvrir le menu du profil' })).toBeVisible()
  })

  test('Arabic sign-in smoke test keeps the document right-to-left', async ({ page }) => {
    await page.goto('/sign-in')
    await page.locator('#auth-locale').selectOption('ar')

    await expect(page.locator('html')).toHaveAttribute('lang', 'ar')
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
    // Arabic copy actually rendered, not just the direction attribute.
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(/[\u0600-\u06FF]/)

    await page.locator('input[type="email"]').fill(OWNER)
    await page.locator('input[type="password"]').fill(DEMO_PASSWORD)
    await page.locator('form').getByRole('button', { name: /./ }).last().click()

    await expect(page).not.toHaveURL(/\/sign-in$/)
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
  })
})
