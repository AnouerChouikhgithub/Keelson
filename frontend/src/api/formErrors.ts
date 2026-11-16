import { isApiError } from './errors'

/**
 * Turns the server's `fields:{name:[messages]}` into one message per input.
 *
 * The backend already localizes nothing, so its text is shown verbatim on the
 * named field; a screen that gets no field list must fall back to its own
 * form-level message rather than inventing a field.
 */
export function presentedFields(error: unknown): Record<string, string> | null {
  if (!isApiError(error) || error.fields === null) return null
  const next: Record<string, string> = {}
  for (const [name, messages] of Object.entries(error.fields)) {
    const first = messages[0]
    if (first !== undefined && first !== '') next[name] = first
  }
  return Object.keys(next).length === 0 ? null : next
}
