import type { ReactNode } from 'react'
import { useI18n } from '../../i18n/I18nContext'
import { locales, messages } from '../../i18n'
import type { Locale } from '../../i18n'
import './auth.css'

/**
 * The frame every public screen shares: a centred card on the app background,
 * the Keelson mark, one title, one lead line and the form.
 *
 * Widths are capped rather than fixed (`min(…, 100%)`) and padding is in logical
 * properties, so the card holds at 360 px and mirrors correctly under `dir=rtl`.
 */
export function AuthLayout({ title, subtitle, children }: { title: string; subtitle: string; children: ReactNode }) {
  const { m, locale, setLocale } = useI18n()

  return (
    <main className="auth-screen">
      <section className="auth-card">
        <div className="auth-brand">
          <img src="/Keelson.svg" alt="" width={34} height={34} />
          <span>keelson</span>
        </div>
        <h1>{title}</h1>
        <p className="auth-subtitle">{subtitle}</p>
        {children}
        <div className="auth-locale">
          <label htmlFor="auth-locale" className="visually-hidden">{m.settings.language}</label>
          <select
            id="auth-locale"
            value={locale}
            onChange={(event) => setLocale(event.target.value as Locale)}
          >
            {locales.map((value) => (
              <option key={value} value={value}>{messages[value].common.languageName}</option>
            ))}
          </select>
        </div>
      </section>
    </main>
  )
}

/** A form-level result line: a failure the screen must state, or a confirmation. */
export function FormAlert({ tone, children, id }: { tone: 'error' | 'success'; children: ReactNode; id?: string }) {
  return (
    <p id={id} className={`auth-alert auth-alert-${tone}`} role={tone === 'error' ? 'alert' : 'status'}>
      {children}
    </p>
  )
}
