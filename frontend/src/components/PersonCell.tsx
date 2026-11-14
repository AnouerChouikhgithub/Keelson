export function PersonCell({
  initials,
  name,
  tone = 'teal',
}: {
  initials: string
  name: string
  /** Badge colour shared with the status palette; defaults to the brand tone. */
  tone?: string
}) {
  return (
    <span className="customer-cell">
      <span className={`customer-avatar customer-${tone}`}>{initials}</span>
      {name}
    </span>
  )
}
