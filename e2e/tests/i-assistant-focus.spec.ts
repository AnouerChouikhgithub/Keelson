import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { OWNER, signIn } from '../support/session'

/**
 * Journey: the assistant keeps keyboard focus inside its drawer.
 *
 * F-13 in the review: opening the panel, asking with the keyboard and waiting for
 * the answer is the path a keyboard user takes, and nothing in the component tests
 * types a real key — `fireEvent` leaves focus wherever the test put it. This spec
 * uses Playwright's real keyboard: it focuses the header button, opens the drawer
 * with Enter, submits with Enter from the textarea, and asserts after the answer
 * that focus is still inside the dialog, that Tab stays inside it, and that Escape
 * puts focus back on the button that opened it.
 *
 * It skips with a reason when this deployment has AI turned off, exactly like the
 * AI feature journey: with no assistant button there is nothing to focus.
 */
async function focusInsideDialog(page: Page): Promise<boolean> {
  return page.evaluate(() => {
    const active = document.activeElement
    return active !== null && active.closest('[role="dialog"]') !== null
  })
}

test('keeps focus in the assistant across an answer (real keyboard)', async ({ page }) => {
  const status = await page.request.get('/api/ai/status')
  test.skip(status.status() === 404, 'The backend does not expose /api/ai/status yet.')

  await signIn(page, OWNER)
  await page.getByRole('link', { name: 'Settings', exact: true }).click()

  const settingsHeading = page.getByRole('heading', { name: 'AI assistance' })
  const surfaced = await settingsHeading
    .waitFor({ state: 'visible', timeout: 15_000 })
    .then(() => true)
    .catch(() => false)
  test.skip(
    !surfaced,
    'This deployment has AI turned off (AI_ENABLED=false), so there is no assistant button to focus.',
  )

  // The fixture organization ships with AI disabled; the journey opts in the way
  // an owner does, and tolerates a previous journey having done it already.
  const enable = page.getByLabel('Enable AI for this workspace')
  if (!(await enable.isChecked())) await enable.check()
  await page.getByLabel('Monthly token budget').fill('50000')
  await page.getByRole('button', { name: 'Save AI settings' }).click()
  await expect(page.getByText('AI settings saved.')).toBeVisible()

  // Open with a real key from the button itself, not with a click.
  const opener = page.getByRole('button', { name: 'Ask Keelson' })
  await opener.focus()
  await expect(opener).toBeFocused()
  await page.keyboard.press('Enter')

  const dialog = page.getByRole('dialog', { name: 'Keelson assistant' })
  await expect(dialog).toBeVisible()

  // The drawer traps focus: the composer is the element it opens on.
  const composer = page.getByLabel('Your question')
  await expect(composer).toBeFocused()

  // Enter inside a textarea inserts a newline, so the keyboard path to an answer
  // is Tab to the submit button and Enter on it — which is also the step that
  // disables the button and drops focus if the panel does not put it back.
  await page.keyboard.type('How do I mark an invoice paid?')
  await page.keyboard.press('Tab')
  await expect(page.getByRole('button', { name: 'Ask', exact: true })).toBeFocused()
  await page.keyboard.press('Enter')

  const answered = page.locator('.ai-answer-body')
  const notImplemented = page.getByText('AI answers are not available on this server yet.')
  await expect(answered.or(notImplemented)).toBeVisible({ timeout: 30_000 })
  test.skip(
    await notImplemented.isVisible(),
    'The backend routes /api/ai/guide but still answers 501, so there is no answer to hold focus across.',
  )

  // The answer replaced the pending state. Focus must not have fallen to <body>:
  // that is the bug this journey exists for, because the next Tab would leave the
  // dialog.
  expect(await focusInsideDialog(page)).toBe(true)
  await expect(composer).toBeFocused()

  // Tab stays inside the drawer, whichever control it moves to.
  for (let step = 0; step < 4; step += 1) {
    await page.keyboard.press('Tab')
    expect(await focusInsideDialog(page), `focus left the dialog after Tab #${step + 1}`).toBe(true)
  }
  await expect(dialog, 'Escape closes the drawer from inside it').toBeVisible()
  await page.keyboard.press('Escape')
  await expect(dialog).toBeHidden()

  // And focus is restored to the control that opened it.
  await expect(opener).toBeFocused()
})
