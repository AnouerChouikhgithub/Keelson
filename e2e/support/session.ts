import { expect } from '@playwright/test'
import type { Page } from '@playwright/test'

/** The documented demo password; every fixture account shares it. */
export const DEMO_PASSWORD = process.env.FIXTURE_PASSWORD ?? 'Keelson-Demo-2026!'

export const OWNER = 'owner@example.test'
export const ADMIN = 'admin@example.test'
export const MEMBER = 'member@example.test'
export const VIEWER = 'viewer@example.test'

let counter = 0

/** A fresh address per run, so repeated local runs do not collide. */
export function uniqueEmail(prefix: string): string {
  counter += 1
  return `${prefix}-${Date.now()}-${counter}@example.test`
}

/** Signs in through the real form and waits for the workspace to load. */
export async function signIn(page: Page, email: string, password = DEMO_PASSWORD): Promise<void> {
  await page.goto('/sign-in')
  await page.getByLabel('Email', { exact: true }).fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).not.toHaveURL(/\/sign-in$/)
  await expect(page.getByRole('button', { name: 'Open profile menu' })).toBeVisible()
}

/** Signs out through the account menu and waits for the sign-in screen. */
export async function signOut(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Open profile menu' }).click()
  await page.getByText('Log out', { exact: true }).click()
  await expect(page).toHaveURL(/\/sign-in$/)
}
