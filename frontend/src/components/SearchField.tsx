import { Icon } from './Icon'

export function SearchField({
  label,
  value,
  onChange,
}: {
  label: string
  value: string
  onChange: (value: string) => void
}) {
  return (
    <div className="search-field">
      <Icon name="search" size={16} />
      <input
        type="search"
        value={value}
        aria-label={label}
        placeholder={label}
        onChange={(event) => onChange(event.target.value)}
      />
    </div>
  )
}
