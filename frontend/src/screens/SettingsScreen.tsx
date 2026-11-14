import { Icon } from '../components/Icon'
import { SessionCard } from '../components/SessionCard'
import { ThemeControl } from '../components/ThemeControl'
import { useI18n } from '../i18n/I18nContext'
import { locales, messages } from '../i18n/messages'
import { usePreferences } from '../settings/usePreferences'
import type { PreferenceKey } from '../settings/usePreferences'

const preferenceKeys: PreferenceKey[] = ['invoiceUpdates', 'paymentReminders', 'teamActivity', 'productNews']

export function SettingsScreen({ onLogout, logoutPending }: { onLogout: () => void; logoutPending: boolean }) {
  const { m, locale, setLocale } = useI18n()
  const { preferences, toggle } = usePreferences()

  return (
    <div className="settings-page">
      <section className="page-intro">
        <div>
          <p className="eyebrow">{m.page.settings}</p>
          <h1>{m.settings.title}</h1>
          <p>{m.settings.subtitle}</p>
        </div>
      </section>

      <section className="panel settings-card">
        <h2>{m.settings.appearance}</h2>
        <p className="settings-help">{m.settings.appearanceHelp}</p>
        <ThemeControl className="settings-theme" />
      </section>

      <section className="panel settings-card">
        <h2>{m.settings.language}</h2>
        <p className="settings-help">{m.settings.languageHelp}</p>
        <div className="language-grid" role="group" aria-label={m.settings.language}>
          {locales.map((value) => (
            <button
              key={value}
              type="button"
              className={`language-option ${locale === value ? 'selected' : ''}`}
              aria-pressed={locale === value}
              onClick={() => setLocale(value)}
            >
              <Icon name="globe" size={16} />
              <span>{messages[value].languageName}</span>
              {locale === value && <Icon name="check" size={15} />}
            </button>
          ))}
        </div>
      </section>

      <section className="panel settings-card">
        <h2>{m.settings.prefs}</h2>
        <p className="settings-help">{m.settings.prefsHelp}</p>
        <div className="preference-list">
          {preferenceKeys.map((key) => (
            <div className="preference-row" key={key}>
              <span className="preference-copy">
                <strong>{m.settings[key]}</strong>
                <small>{m.settings[`${key}Help`]}</small>
              </span>
              <button
                type="button"
                role="switch"
                aria-checked={preferences[key]}
                aria-label={m.settings[key]}
                className={`toggle ${preferences[key] ? 'toggle-on' : ''}`}
                onClick={() => toggle(key)}
              >
                <span className="toggle-track"><span className="toggle-thumb" /></span>
                <span className="toggle-label">{preferences[key] ? m.settings.on : m.settings.off}</span>
              </button>
            </div>
          ))}
        </div>
        <p className="settings-note">{m.settings.persistence}</p>
        <p className="settings-note">{m.settings.demo}</p>
      </section>

      <SessionCard onLogout={onLogout} pending={logoutPending} />
    </div>
  )
}
