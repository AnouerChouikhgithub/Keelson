import { expect, test } from '@playwright/test'
import { useLocale } from '../support/locale'
import { OWNER, signIn } from '../support/session'

/**
 * F-03: at 390px the dashboard stat cards clipped a three-decimal amount — the
 * audit measured "Outstanding" needing 152px in a 129px box, so the reader saw
 * `TND 11,95…` instead of the figure.
 *
 * The largest realistic amount is written into the live card rather than seeded,
 * because no fixture carries a ten-million invoice: the question is whether the
 * card can display it, and that is a measurement of the box. `scrollWidth` beyond
 * `clientWidth` is exactly what the clipping looked like.
 */

const LOCALES = ['en', 'fr', 'ar'] as const

for (const locale of LOCALES) {
  test(`dashboard stat values are never clipped — ${locale} @ 390px`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await useLocale(page, locale)
    await signIn(page, OWNER)

    const values = page.locator('.metric-value')
    await expect(values.first()).toBeVisible()

    const amount = await page.evaluate(
      (value) =>
        new Intl.NumberFormat(value, {
          style: 'currency',
          currency: 'TND',
          minimumFractionDigits: 3,
          maximumFractionDigits: 3,
        }).format(9999999.999),
      locale,
    )

    // The currency code must still be part of the string the card is stressed with.
    expect(amount, 'the stressed amount still carries its currency').toMatch(/TND|د\.ت/)

    const overflows = await page.evaluate((text) => {
      const nodes = Array.from(document.querySelectorAll('.metric-value'))
      for (const node of nodes) {
        node.textContent = text
      }
      return nodes.map((node) => node.scrollWidth - node.clientWidth)
    }, amount)

    expect(overflows.length, 'the dashboard rendered its stat cards').toBeGreaterThan(0)
    expect(Math.max(...overflows), 'no stat value is wider than its card').toBeLessThanOrEqual(0)

    await testInfo.attach(`dashboard-stats-${locale}-390.png`, {
      body: await page.screenshot(),
      contentType: 'image/png',
    })
  })
}
