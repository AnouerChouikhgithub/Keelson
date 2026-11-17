import type { Messages } from '../i18n'
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

export type PresentedAuthError = {
  /** Form-level alert text. */
  message: string
  /** One localized message per rejected field, keyed by the input name. */
  fields: Record<string, string> | null
}

/**
 * Maps an auth failure to the copy an auth screen shows.
 *
 * The stable server `code` decides the message, so a wrong password, an
 * unconfirmed address and a throttled attempt each get their own sentence. Field
 * messages from a `422` are localized through the same rules the client checks
 * locally; an unexpected field falls back to the server's own wording.
 */
export function presentAuthError(m: Messages, error: unknown): PresentedAuthError {
  if (!(error instanceof ApiError)) return { message: m.api.unexpected, fields: null }
  if (error.kind === 'network') return { message: m.api.offline, fields: null }
  if (error.kind === 'timeout') return { message: m.api.timeout, fields: null }
  if (error.kind === 'parse') return { message: m.api.unexpected, fields: null }

  const fields = error.fields === null ? null : localizeFields(m, error.fields)

  switch (error.code) {
    case 'invalid_credentials':
      return { message: m.auth.errors.invalidCredentials, fields: null }
    case 'email_not_verified':
      return { message: m.auth.errors.emailNotVerified, fields: null }
    case 'invalid_token':
      return { message: m.auth.errors.invalidToken, fields: null }
    case 'validation_failed':
      return { message: m.auth.errors.validation, fields }
    case 'too_many_login_attempts':
    case 'too_many_resend_attempts':
      return { message: m.auth.errors.tooManyAttempts, fields: null }
    case 'csrf_invalid':
      return { message: m.auth.errors.sessionExpired, fields: null }
    default:
      break
  }

  if (error.status === 429) return { message: m.auth.errors.tooManyAttempts, fields: null }
  if (error.status === 422) return { message: m.auth.errors.validation, fields }
  if (error.status === 401) return { message: m.api.unauthorized, fields: null }
  if (error.status !== null && error.status >= 500) return { message: m.api.server, fields: null }

  return { message: error.detail ?? m.api.unexpected, fields }
}

function localizeFields(m: Messages, fields: Record<string, string[]>): Record<string, string> {
  const localized: Record<string, string> = {}
  for (const [name, messages] of Object.entries(fields)) {
    localized[name] = fieldMessage(m, name) ?? messages[0] ?? m.auth.errors.validation
  }
  return localized
}

/** Only the fields the auth screens render get a local rule; the rest keep the server text. */
function fieldMessage(m: Messages, name: string): string | null {
  switch (name) {
    case 'email':
      return m.auth.errors.invalidEmail
    case 'password':
      return m.auth.errors.passwordRule
    case 'organizationName':
      return m.auth.errors.organizationNameRule
    case 'displayName':
      return m.auth.errors.displayNameRule
    default:
      return null
  }
}
