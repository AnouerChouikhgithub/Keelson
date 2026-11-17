import { useCallback, useRef, useState } from 'react'
import { useI18n } from '../i18n/I18nContext'
import { isAbortError } from './errors'
import { presentError } from './presentError'
import { useToast } from '../components/ToastContext'

export type MutationOutcome<T> =
  | { ok: true; data: T }
  | { ok: false; error: unknown }

/**
 * Runs one write and reports it consistently.
 *
 * Pending state disables the calling control, an abort (the screen unmounted)
 * is ignored, and every failure is both returned to the caller and announced
 * once through the toast region. Success copy is passed per call because the
 * same endpoint produces different sentences in different screens.
 */
export function useMutation<Args extends unknown[], Result>(
  action: (...args: Args) => Promise<Result>,
  options: { announceFailure?: boolean } = {},
): {
  run: (...args: Args) => Promise<MutationOutcome<Result>>
  pending: boolean
  error: unknown
  reset: () => void
} {
  const { announceFailure = true } = options
  const { m } = useI18n()
  const toast = useToast()
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<unknown>(null)
  // A burst of clicks must not start a second write while the first is in
  // flight; the ref is checked synchronously, before React re-renders.
  const inFlight = useRef(false)

  const run = useCallback(async (...args: Args): Promise<MutationOutcome<Result>> => {
    if (inFlight.current) return { ok: false, error: null }
    inFlight.current = true
    setPending(true)
    setError(null)
    try {
      const data = await action(...args)
      return { ok: true, data }
    } catch (caught) {
      if (isAbortError(caught)) return { ok: false, error: caught }
      setError(caught)
      if (announceFailure) toast.error(presentError(m, caught))
      return { ok: false, error: caught }
    } finally {
      inFlight.current = false
      setPending(false)
    }
  }, [action, announceFailure, m, toast])

  const reset = useCallback(() => setError(null), [])

  return { run, pending, error, reset }
}
