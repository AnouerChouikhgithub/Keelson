import { useId, useState } from 'react'
import type { Ref } from 'react'
import { Icon } from '../../components/Icon'
import { useI18n } from '../../i18n/I18nContext'

/**
 * A password input with a visibility toggle.
 *
 * The toggle is a real button with `aria-pressed`, not a checkbox or a link, so
 * assistive technology reports whether the value is currently visible. The hint
 * and the error are both wired through `aria-describedby`, which is how the rule
 * reaches a screen-reader user before they submit.
 */
export function PasswordField({
  label,
  value,
  onChange,
  autoComplete,
  inputRef,
  error,
  hint,
  disabled = false,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  autoComplete: 'current-password' | 'new-password'
  inputRef?: Ref<HTMLInputElement>
  error?: string
  hint?: string
  disabled?: boolean
}) {
  const { m } = useI18n()
  const [visible, setVisible] = useState(false)
  const id = useId()
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const describedBy = [hint === undefined ? null : hintId, error === undefined ? null : errorId]
    .filter((valuePart): valuePart is string => valuePart !== null)
    .join(' ')

  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      <div className="field-control">
        <input
          id={id}
          ref={inputRef}
          type={visible ? 'text' : 'password'}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          autoComplete={autoComplete}
          aria-invalid={error === undefined ? undefined : true}
          aria-describedby={describedBy === '' ? undefined : describedBy}
          disabled={disabled}
          required
        />
        <button
          type="button"
          className="password-toggle"
          aria-pressed={visible}
          aria-label={visible ? m.auth.hidePassword : m.auth.showPassword}
          onClick={() => setVisible((current) => !current)}
          disabled={disabled}
        >
          <Icon name={visible ? 'eyeOff' : 'eye'} size={17} />
        </button>
      </div>
      {hint !== undefined && <p className="field-hint" id={hintId}>{hint}</p>}
      {error !== undefined && <p className="field-error" id={errorId}>{error}</p>}
    </div>
  )
}
