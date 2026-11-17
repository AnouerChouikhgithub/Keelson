import type { ButtonHTMLAttributes } from 'react'
import { Icon } from './Icon'
import type { IconName } from './Icon'
import './Button.css'

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'

/**
 * One button, four emphases, one loading rule. Pending work disables the
 * control and swaps the label, which is also what `aria-busy` announces.
 */
export function Button({
  variant = 'secondary',
  size = 'md',
  icon,
  loading = false,
  loadingLabel,
  className = '',
  children,
  disabled,
  type = 'button',
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: ButtonVariant
  size?: 'sm' | 'md'
  icon?: IconName
  loading?: boolean
  loadingLabel?: string
}) {
  return (
    <button
      {...rest}
      type={type}
      className={`button button-${variant} button-${size} ${className}`.trim()}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
    >
      {icon && !loading && <Icon name={icon} size={17} />}
      <span>{loading ? (loadingLabel ?? children) : children}</span>
    </button>
  )
}
