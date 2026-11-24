import type { Page } from '@playwright/test'

/**
 * Sets the interface language before the app renders.
 *
 * The i18n provider reads the choice from storage on its first render, so the
 * page arrives in the requested language rather than flipping under the user.
 * The key is the one `I18nProvider` writes (`keelson-locale`).
 */
export async function useLocale(page: Page, locale: string): Promise<void> {
  await page.addInitScript((value) => {
    window.localStorage.setItem('keelson-locale', value)
  }, locale)
}
