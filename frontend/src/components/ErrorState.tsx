import { Icon } from './Icon'
import './EmptyState.css'

/** A failure with the one action that can recover from it. */
export function ErrorState({
  title,
  message,
  retryLabel,
  onRetry,
}: {
  title: string
  message: string
  retryLabel: string
  onRetry?: () => void
}) {
  return (
    <div className="error-state" role="alert">
      <span className="error-state-icon" aria-hidden="true"><Icon name="shield" size={24} /></span>
      <h2>{title}</h2>
      <p>{message}</p>
      {onRetry && (
        <button type="button" className="button button-secondary" onClick={onRetry}>
          {retryLabel}
        </button>
      )}
    </div>
  )
}
