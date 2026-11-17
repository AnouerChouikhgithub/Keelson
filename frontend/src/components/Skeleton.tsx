import './Skeleton.css'

type SkeletonProps = {
  /** `text` for paragraphs, `card` for metric tiles, `table` for rows, `chart` for the dashboard plot. */
  variant?: 'text' | 'card' | 'table' | 'chart' | 'list'
  count?: number
  /** Announced to assistive technology; the bars themselves are decorative. */
  label: string
}

export function Skeleton({ variant = 'text', count = 3, label }: SkeletonProps) {
  return (
    <div className={`skeleton skeleton-${variant}`} role="status" aria-live="polite" aria-label={label}>
      <span className="visually-hidden">{label}</span>
      {Array.from({ length: count }, (_, index) => (
        <span className="skeleton-line" key={index} aria-hidden="true" />
      ))}
    </div>
  )
}
