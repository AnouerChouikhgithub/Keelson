import type { ReactNode } from 'react'
import { ToastProvider } from '../../components/Toast'
import { I18nProvider } from '../../i18n/I18nProvider'

/**
 * The two providers every AI surface reads: localized copy and the toast region
 * the settings save announces through. Screens are not mocked; only `fetch` is.
 */
export function AiProviders({ children }: { children: ReactNode }) {
  return (
    <I18nProvider>
      <ToastProvider>{children}</ToastProvider>
    </I18nProvider>
  )
}
