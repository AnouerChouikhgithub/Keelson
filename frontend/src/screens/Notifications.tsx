import { BackendGate } from '../components/BackendGate'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import { useWorkspace } from '../app/WorkspaceContext'
import './simple.css'

/**
 * No notification feed or read state exists in the API, so there is no unread
 * badge in the header and no list here. This screen states that rather than
 * showing browser-local demo notifications.
 */
export default function Notifications() {
  const { m } = useI18n()
  const { membership } = useWorkspace()
  useDocumentTitle(m.page.notifications)

  return (
    <div className="simple-page">
      <section className="page-intro">
        <div>
          <p className="eyebrow">{m.nav.workspace}</p>
          <h1>{m.notifications.title}</h1>
          <p>{m.notifications.subtitle}</p>
        </div>
      </section>

      <BackendGate
        title={m.notifications.gateTitle}
        body={m.notifications.gateBody}
        endpoint={m.notifications.gateEndpoint}
        backTo={`/o/${membership.id}/dashboard`}
      />
    </div>
  )
}
