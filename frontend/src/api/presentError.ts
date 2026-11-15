import type { Messages } from '../i18n/messages'
import { ApiError } from './errors'

/**
 * Maps an API failure to localized copy.
 *
 * `ApiError.detail` is the server's own message and is appended only for the
 * statuses where it is useful (validation and conflict). Everything else uses
 * the localized generic text, so internal exception text never reaches the UI.
 */
export function presentError(m: Messages, error: unknown): string {
  if (!(error instanceof ApiError)) return m.api.unexpected
  if (error.kind === 'network') return m.api.offline
  if (error.kind === 'timeout') return m.api.timeout
  if (error.kind === 'parse') return m.api.unexpected
  const status = error.status ?? 0
  if (status === 401) return m.api.unauthorized
  if (status === 403) return m.api.forbidden
  if (status === 404) return m.api.notFound
  if (status === 429) return m.api.rateLimited
  if (status >= 500) return m.api.server
  if (status === 400 || status === 422) return error.detail ?? m.api.invalid
  if (status === 409) return m.api.conflict
  return error.detail ?? m.api.server
}

/** True when the failure means the record moved on and must be re-read. */
export function isConflict(error: unknown): boolean {
  return error instanceof ApiError && error.status === 409
}
