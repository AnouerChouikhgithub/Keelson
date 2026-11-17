import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { useI18n } from '../i18n/I18nContext'
import { Icon } from './Icon'
import './EmptyState.css'

/**
 * Says plainly that a screen's endpoint does not exist in this build.
 *
 * Sprint 7 was asked for screens on real data. Where the API has no route, the
 * honest answer is this notice — not demo rows dressed up as production data.
 */
export function BackendGate({
  title,
  body,
  endpoint,
  backTo,
  children,
}: {
  title: string
  body: string
  endpoint: string
  backTo?: string
  children?: ReactNode
}) {
  const { m } = useI18n()

  return (
    <section className="backend-gate" aria-labelledby="backend-gate-title">
      <div className="backend-gate-head">
        <span className="empty-state-icon" aria-hidden="true"><Icon name="link" size={24} /></span>
        <div>
          <p className="backend-gate-eyebrow">{m.gate.eyebrow}</p>
          <h2 id="backend-gate-title">{title}</h2>
        </div>
      </div>
      <p>{body}</p>
      <dl>
        <dt>{m.gate.endpointLabel}</dt>
        <dd><code>{endpoint}</code></dd>
      </dl>
      <p>{m.gate.sourceNote}</p>
      {children}
      {backTo && (
        <div className="backend-gate-actions">
          <Link className="button button-secondary" to={backTo}>{m.gate.backToDashboard}</Link>
        </div>
      )}
    </section>
  )
}
