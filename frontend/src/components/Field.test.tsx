import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Field } from './Field'

/**
 * Callers spread the render props straight onto the control, so what Field hands
 * over has to be the ARIA attributes themselves. Passing `describedBy`/`invalid`
 * put unknown attributes on the input: React warned and the description and the
 * `[aria-invalid='true']` error styling silently stopped working.
 */
describe('Field', () => {
  it('wires the hint and the error into aria-describedby', () => {
    render(
      <Field label="Budget" hint="A whole number" error="Too large">
        {(field) => <input {...field} />}
      </Field>,
    )

    const input = screen.getByLabelText('Budget')
    expect(input).toHaveAttribute('aria-invalid', 'true')
    const describedBy = input.getAttribute('aria-describedby') ?? ''
    expect(describedBy).not.toBe('')
    // Both messages are referenced, in the order Field renders them.
    const ids = describedBy.split(' ')
    expect(ids).toHaveLength(2)
    expect(document.getElementById(ids[0])).toHaveTextContent('A whole number')
    expect(document.getElementById(ids[1])).toHaveTextContent('Too large')
  })

  it('omits aria-invalid for a valid field', () => {
    render(<Field label="Name">{(field) => <input {...field} />}</Field>)

    expect(screen.getByLabelText('Name')).not.toHaveAttribute('aria-invalid')
  })

  it('spreads no non-ARIA props onto the control', () => {
    render(<Field label="Name">{(field) => <input {...field} />}</Field>)

    const input = screen.getByLabelText('Name')
    expect(input).not.toHaveAttribute('describedby')
    expect(input).not.toHaveAttribute('invalid')
  })
})
