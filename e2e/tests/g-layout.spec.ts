import { expect, test } from '@playwright/test'
import { useLocale } from '../support/locale'

/**
 * F-02: the public screens build their fields by hand, and the `.field` rules
 * were not reachable from the auth frame, so the label sat beside the input and
 * the input fell back to its intrinsic ~180px width.
 *
 * These are real browser measurements — jsdom has no layout engine — and each
 * case attaches a screenshot at its viewport so the state can be reviewed.
 * Screenshots are attachments in `test-results/`, not committed binaries.
 * The dashboard equivalent (money clipping, F-03) is `h-money.spec.ts`.
 */

const LOCALES = [
  { id: 'en', dir: 'ltr' },
  { id: 'fr', dir: 'ltr' },
  { id: 'ar', dir: 'rtl' },
] as const

const VIEWPORTS = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'phone', width: 390, height: 844 },
] as const

const PUBLIC_SCREENS = [
  { name: 'sign-in', path: '/sign-in' },
  { name: 'sign-up', path: '/sign-up' },
  { name: 'forgot-password', path: '/forgot-password' },
  { name: 'reset-password', path: '/reset-password?token=layout-check' },
] as const

for (const locale of LOCALES) {
  for (const viewport of VIEWPORTS) {
    for (const screen of PUBLIC_SCREENS) {
      test(`${screen.name} keeps its field layout — ${locale.id} @ ${viewport.name}`, async ({ page }, testInfo) => {
        await page.setViewportSize({ width: viewport.width, height: viewport.height })
        await useLocale(page, locale.id)
        await page.goto(screen.path)

        await expect(page.locator('html')).toHaveAttribute('dir', locale.dir)

        const field = page.locator('.field').first()
        const label = field.locator('label').first()
        const input = field.locator('input').first()
        await expect(input).toBeVisible()

        const fieldBox = await field.boundingBox()
        const labelBox = await label.boundingBox()
        const inputBox = await input.boundingBox()
        expect(fieldBox, 'the field has a box').not.toBeNull()
        expect(labelBox, 'the label has a box').not.toBeNull()
        expect(inputBox, 'the input has a box').not.toBeNull()

        // The label sits above the control, never beside it.
        expect(labelBox!.y + labelBox!.height).toBeLessThanOrEqual(inputBox!.y + 2)

        // The control fills the card instead of its 180px intrinsic width.
        expect(inputBox!.width).toBeGreaterThanOrEqual(fieldBox!.width - 2)
        expect(inputBox!.width).toBeGreaterThan(200)

        // Nothing spills sideways, which is what RTL mirroring would break first.
        const overflow = await page.evaluate(
          () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        )
        expect(overflow).toBeLessThanOrEqual(0)

        await testInfo.attach(`${screen.name}-${locale.id}-${viewport.name}.png`, {
          body: await page.screenshot(),
          contentType: 'image/png',
        })
      })
    }
  }
}

