import { expect, test } from '@playwright/test'
import { VIEWER, signIn } from '../support/session'

/**
 * Journey (d): a Viewer reads but cannot write, and the server — not the hidden
 * button — is what refuses the forged call.
 *
 * Two different refusals are asserted on purpose:
 *  - a write **without** the CSRF token is `403 csrf_invalid`, because the
 *    mutation guard runs before authorization;
 *  - a write **with** a valid token but a Viewer membership is `404`, not `403`:
 *    the voters answer "not found" so a caller cannot distinguish a foreign
 *    tenant from a missing record (see docs/openapi.yaml).
 */
test('a viewer sees no write actions and the forged API calls are refused', async ({ page }) => {
  await signIn(page, VIEWER)

  await page.getByRole('link', { name: 'Customers', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Customers', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Add customer' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Delete customer' })).toHaveCount(0)

  await page.getByRole('link', { name: 'Invoices', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Invoices', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Create invoice' })).toHaveCount(0)

  // Read the CSRF token the same way the SPA does, from the session payload.
  const { organizationId, csrfToken } = await page.evaluate(async () => {
    const session = (await (await fetch('/api/session')).json()) as { csrfToken: string }
    const organizations = (await (await fetch('/api/me/organizations')).json()) as { items: Array<{ id: string }> }
    return { organizationId: organizations.items[0]?.id ?? '', csrfToken: session.csrfToken }
  })
  expect(organizationId).not.toBe('')

  const withoutToken = await page.request.post(`/api/organizations/${organizationId}/customers`, {
    headers: { Origin: new URL(page.url()).origin },
    data: { name: 'Forged customer' },
  })
  expect(withoutToken.status(), 'the CSRF guard answers 403 before authorization').toBe(403)
  expect((await withoutToken.json()).error.code).toBe('csrf_invalid')

  const asViewer = await page.request.post(`/api/organizations/${organizationId}/customers`, {
    headers: { Origin: new URL(page.url()).origin, 'X-CSRF-Token': csrfToken },
    data: { name: 'Forged customer' },
  })
  expect(asViewer.status(), 'a viewer is denied as 404, never 403').toBe(404)

  const stillReadable = await page.request.get(`/api/organizations/${organizationId}/customers?limit=5`)
  expect(stillReadable.status()).toBe(200)
})
