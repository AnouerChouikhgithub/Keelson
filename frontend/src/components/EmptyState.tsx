import { Icon } from './Icon'
import type { IconName } from './Icon'

export function EmptyState({
  icon,
  title,
  body,
  action,
}: {
  icon: IconName
  title: string
  body?: string
  action?: { label: string; onClick: () => void }
}) {
  return (
    <div className="empty-state">
      <Icon name={icon} size={26} />
      <strong>{title}</strong>
      {body && <p>{body}</p>}
      {action && (
        <button type="button" className="button button-secondary" onClick={action.onClick}>
          {action.label}
        </button>
      )}
    </div>
  )
}
