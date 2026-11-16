import { BackendGate } from '../components/BackendGate'
import { Field } from '../components/Field'
import { Icon } from '../components/Icon'
import { ThemeControl } from '../components/ThemeControl'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import { locales, messages } from '../i18n'
import { useWorkspace } from '../app/WorkspaceContext'
import './simple.css'

/**
 * Appearance and language are real, local preferences. Organization name,
 * subdomain and deletion are owner-only in the product design but have no
 * endpoint here, so they are read-only with an explicit gate.
 */
export default function Settings() {
  const { m, locale, setLocale } = useI18n()
  const { membership } = useWorkspace()
  useDocumentTitle(m.page.settings)
  const isOwner = membership.role === 'Owner'

  return (
    <div className="simple-page">
      <section className="page-intro">
        <div>
          <p className="eyebrow">{m.nav.workspace}</p>
          <h1>{m.settings.title}</h1>
          <p>{m.settings.subtitle}</p>
        </div>
      </section>

      <section className="panel settings-card" aria-labelledby="appearance-title">
        <h2 id="appearance-title">{m.settings.appearance}</h2>
        <p className="panel-help">{m.settings.appearanceHelp}</p>
        <ThemeControl className="settings-theme" />
      </section>

      <section className="panel settings-card" aria-labelledby="language-title">
        <h2 id="language-title">{m.settings.language}</h2>
        <p className="panel-help">{m.settings.languageHelp}</p>
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
              <span>{messages[value].common.languageName}</span>
              {locale === value && <Icon name="check" size={15} />}
            </button>
          ))}
        </div>
        <p className="panel-help">{m.settings.localNote}</p>
      </section>

      <section className="panel settings-card" aria-labelledby="organization-title">
        <h2 id="organization-title">{m.settings.organization}</h2>
        <p className="panel-help">{m.settings.organizationHelp}</p>
        <div className="settings-form">
          <Field label={m.settings.name} hint={isOwner ? undefined : m.settings.ownerOnly}>
            {(field) => <input {...field} value={membership.name} readOnly />}
          </Field>
          <Field label={m.settings.subdomain} hint={isOwner ? undefined : m.settings.ownerOnly}>
            {(field) => <input {...field} value={membership.subdomain} readOnly />}
          </Field>
        </div>
      </section>

      <BackendGate
        title={m.settings.gateTitle}
        body={m.settings.gateBody}
        endpoint={m.settings.gateEndpoint}
      />

      <section className="panel settings-card danger-zone" aria-labelledby="danger-title">
        <h2 id="danger-title">{m.settings.danger}</h2>
        <p className="panel-help">{m.settings.dangerHelp}</p>
        <p className="panel-help">{m.settings.deleteGateBody}</p>
        <button type="button" className="button button-danger" disabled title={m.settings.deleteGateBody}>
          {m.settings.deleteOrg}
        </button>
      </section>
    </div>
  )
}
