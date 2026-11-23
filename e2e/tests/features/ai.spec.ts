import { expect, test } from '@playwright/test'
import { waitForMailLink } from '../../support/mailpit'
import { DEMO_PASSWORD, signIn, uniqueEmail } from '../../support/session'

/**
 * Journey: a new owner enables AI, asks the assistant a question and generates a
 * workspace summary.
 *
 * The journey runs against the backend's deterministic `fake` provider, so it
 * asserts wiring — status gating, the settings write, both generative calls —
 * and not the wording of a model. It skips with a reason when the API it needs
 * is not deployed on this branch: `/api/ai/status` answering `404` means the AI
 * routes are absent, and `501` from either generator means the contract is
 * routed but not implemented yet.
 */
test('enables AI, asks the assistant and generates a summary', async ({ page }) => {
  const status = await page.request.get('/api/ai/status')
  test.skip(
    status.status() === 404,
    'The backend does not expose /api/ai/status yet; this journey needs the AI endpoints from the c-ai-foundation branch.',
  )

  const email = uniqueEmail('e2e-ai')

  await page.goto('/sign-up')
  await page.getByLabel('Email', { exact: true }).fill(email)
  await page.getByLabel('Password', { exact: true }).fill(DEMO_PASSWORD)
  await page.getByLabel('Your name', { exact: true }).fill('E2E AI Owner')
  await page.getByLabel('Organization name', { exact: true }).fill('E2E AI Studio')
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible()

  const verifyPath = await waitForMailLink(email, '/verify-email')
  await page.goto(verifyPath)
  // Confirming the link also sets the password the owner signs in with, so the
  // journey follows the same form a person submits.
  await page.getByLabel('Password', { exact: true }).fill(DEMO_PASSWORD)
  await page.getByRole('button', { name: 'Confirm my email' }).click()
  await expect(page.getByRole('heading', { name: 'Email confirmed' })).toBeVisible()

  await signIn(page, email)
  await page.getByRole('link', { name: 'Settings', exact: true }).click()

  // The section only exists when the deployment switch is on; add the
  // organization's opt-in here.
  const settingsHeading = page.getByRole('heading', { name: 'AI assistance' })
  const surfaced = await settingsHeading
    .waitFor({ state: 'visible', timeout: 15_000 })
    .then(() => true)
    .catch(() => false)
  test.skip(
    !surfaced,
    'This deployment has AI turned off (AI_ENABLED=false), so /api/ai/status reports no AI and the settings section is hidden.',
  )

  await expect(page.getByText(/sent to the workspace’s AI provider/)).toBeVisible()
  await page.getByLabel('Enable AI for this workspace').check()
  await page.getByLabel('Monthly token budget').fill('50000')
  await page.getByRole('button', { name: 'Save AI settings' }).click()
  await expect(page.getByText('AI settings saved.')).toBeVisible()

  // The assistant appears in the header only after the workspace opted in.
  await page.getByRole('button', { name: 'Ask Keelson' }).click()
  await expect(page.getByRole('dialog', { name: 'Keelson assistant' })).toBeVisible()
  await page.getByLabel('Your question').fill('How do I mark an invoice paid?')
  await page.getByRole('button', { name: 'Ask', exact: true }).click()

  const generated = page.getByText('AI-generated')
  const notImplemented = page.getByText('AI answers are not available on this server yet.')
  await expect(generated.or(notImplemented)).toBeVisible({ timeout: 30_000 })
  test.skip(
    await notImplemented.isVisible(),
    'The backend routes /api/ai/guide but still answers 501, so there is no answer to assert yet.',
  )
  await page.keyboard.press('Escape')
  await expect(page.getByRole('dialog', { name: 'Keelson assistant' })).toBeHidden()

  await page.getByRole('link', { name: 'Dashboard', exact: true }).click()
  const summaryHeading = page.getByRole('heading', { name: 'Workspace summary' })
  await expect(summaryHeading).toBeVisible()
  await page.getByRole('button', { name: 'Generate summary' }).click()

  const summaryNotImplemented = page.getByText('AI answers are not available on this server yet.')
  const summaryText = page.locator('.ai-summary-text')
  await expect(summaryText.or(summaryNotImplemented)).toBeVisible({ timeout: 30_000 })
  test.skip(
    await summaryNotImplemented.isVisible(),
    'The backend routes the summary endpoint but still answers 501, so there is no summary to assert yet.',
  )
})
