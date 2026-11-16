import { useId } from 'react'
import type { ReactNode } from 'react'
import './Field.css'

type FieldRenderProps = {
  id: string
  describedBy: string | undefined
  invalid: boolean
  required: boolean
}

/**
 * Label, hint and error wired to the control with `aria-describedby`.
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
      {children({ id, describedBy, invalid: Boolean(error), required })}
      {hint && <small className="field-hint" id={hintId}>{hint}</small>}
      {error && <small className="field-error" id={errorId} role="alert">{error}</small>}
    </div>
  )
}
