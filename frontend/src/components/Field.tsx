import { useId } from 'react'
import type { ReactNode } from 'react'
import './Field.css'

type FieldRenderProps = {
  id: string
  /**
   * The props below are named after the attributes they become, because every
   * caller spreads them straight onto the control (`<input {...field} />`). A
   * `describedBy`/`invalid` pair would reach the DOM as unknown attributes, so
   * React warns and neither the description nor the error styling applies.
   */
  'aria-describedby': string | undefined
  /** Present only when the field is invalid (see `[aria-invalid='true']` in Field.css). */
  'aria-invalid': true | undefined
  required: boolean
}

/**
 * Label, hint and error wired to the control with `aria-describedby` and
 * `aria-invalid`.
 *
 * A render prop is used instead of cloning the child so an input, select or
 * textarea keeps its own typing and every attribute is passed explicitly —
 * nothing depends on a wrapper guessing.
 */
export function Field({
  label,
  hint,
  error,
  required = false,
  children,
}: {
  label: string
  hint?: string
  error?: string | null
  required?: boolean
  children: (props: FieldRenderProps) => ReactNode
}) {
  const id = useId()
  const hintId = hint ? `${id}-hint` : undefined
  const errorId = error ? `${id}-error` : undefined
  const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined

  return (
    <div className="field">
      <label htmlFor={id}>
        {label}
        {required && <span className="field-required" aria-hidden="true"> *</span>}
        {required && <span className="visually-hidden"> ({label})</span>}
      </label>
      {children({
        id,
        'aria-describedby': describedBy,
        'aria-invalid': Boolean(error) || undefined,
        required,
      })}
      {hint && <small className="field-hint" id={hintId}>{hint}</small>}
      {error && <small className="field-error" id={errorId} role="alert">{error}</small>}
    </div>
  )
}
