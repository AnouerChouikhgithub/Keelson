import { useEffect, useState } from 'react'
import { isAbortError } from './errors'

export type ApiResource<T> =
  | { status: 'loading' }
  | { status: 'ready'; data: T }
  | { status: 'error'; error: unknown }

type Entry<T> = { loader: unknown; state: ApiResource<T> }

/**
 * Loads one resource and cancels the request when the caller unmounts or the
 * loader changes (organization switch, filter change, reload).
 *
 * `state` is derived, not synchronized: when a different loader is supplied the
 * previous result is simply not returned, so no effect needs to write "loading"
 * during render. Pass a `useCallback`-stable loader — its identity is the
 * dependency, so an inline arrow would refetch on every render. An aborted
 * request never becomes a visible error, and a superseded response cannot
 * overwrite newer state.
 */
export function useApiResource<T>(load: (signal: AbortSignal) => Promise<T>): {
  state: ApiResource<T>
  reload: () => void
} {
  const [entry, setEntry] = useState<Entry<T> | null>(null)
  const [reloadCount, setReloadCount] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    load(controller.signal).then(
      (data) => {
        if (!controller.signal.aborted) setEntry({ loader: load, state: { status: 'ready', data } })
      },
      (error: unknown) => {
        if (controller.signal.aborted || isAbortError(error)) return
        setEntry({ loader: load, state: { status: 'error', error } })
      },
    )
    return () => controller.abort()
  }, [load, reloadCount])

  const state: ApiResource<T> = entry !== null && entry.loader === load
    ? entry.state
    : { status: 'loading' }

  return { state, reload: () => setReloadCount((count) => count + 1) }
}
