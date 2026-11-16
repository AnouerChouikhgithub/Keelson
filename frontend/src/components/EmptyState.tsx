import type { ReactNode } from 'react'
import { Icon } from './Icon'
import type { IconName } from './Icon'
import './EmptyState.css'

/** The designed "nothing here" state shared by every list and panel. */
export function EmptyState({
  icon = 'sparkle',
  title,
  body,
  action,
  children,
}: {
  icon?: IconName
  title: string
  body?: string
  action?: { label: string; onClick: () => void }
  children?: ReactNode
}) {
  return (
    <div className="empty-state">
      <span className="empty-state-icon" aria-hidden="true"><Icon name={icon} size={24} /></span>
      <strong>{title}</strong>
      {body && <p>{body}</p>}
      {action && (
        <button type="button" className="button button-secondary" onClick={action.onClick}>
          {action.label}
        </button>
      )}
      {children}
    </div>
  )
}
