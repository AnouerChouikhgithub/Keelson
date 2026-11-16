import { useRef } from 'react'

/**
 * Moves focus to the first input a failed submit singled out.
 *
 * A submit that returns field errors while focus stays on the button leaves a
 * keyboard or screen-reader user with no idea which input to fix; the server (or
 * the local rule) names the fields, so the first one in visual order is focused.
 */
export function useFieldFocus<Field extends string>(order: readonly Field[]) {
  const refs = useRef<Partial<Record<Field, HTMLInputElement | null>>>({})

  function register(name: Field) {
    return (element: HTMLInputElement | null) => {
      refs.current[name] = element
    }
  }

  function focusFirst(fields: Record<string, string> | null): void {
    if (fields === null) return
    const first = order.find((name) => fields[name] !== undefined)
    if (first !== undefined) refs.current[first]?.focus()
  }

  return { register, focusFirst }
}
