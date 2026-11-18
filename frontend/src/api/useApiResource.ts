import { useCallback, useEffect, useRef, useState } from 'react'
import { isAbortError } from './errors'

export type ApiResource<T> =
  | { status: 'loading' }
  | { status: 'ready'; data: T }
  | { status: 'error'; error: unknown }

/**
 * Module-level read cache, keyed by resource (for example
 * `invoices:<orgId>:draft`). It is intentionally not persisted: a reload must
 * re-authorize against the server, and a sign-out leaves nothing behind.
 */
const cache = new Map<string, ApiResource<unknown>>()

/**
 * Drops the whole cache. Call it whenever the authenticated principal changes:
 * the cache holds tenant data, and a second sign-in in the same tab must not
 * read the previous account's list before the server answers.
 */
export function clearApiCache(): void {
  cache.clear()
}

/**
 * Mounted hooks that want to hear about invalidation.
 *
 * Dropping the cache entry is not enough on its own: a mounted hook keeps the
 * value it fetched in its own state and would keep rendering it until it
 * remounted. Notifying it is what makes a write in one component show up in
 * another mounted one — the header assistant appearing as soon as an owner
 * enables AI in settings is exactly that case.
 */
const invalidationListeners = new Set<{ key: string; notify: () => void }>()

function matchesKey(key: string, keyOrPrefix: string): boolean {
  return key === keyOrPrefix || key.startsWith(`${keyOrPrefix}:`)
}

/**
 * Drops cached reads for one key, or for every key with a prefix, and refetches
 * every mounted reader of them.
 */
export function invalidateResource(keyOrPrefix: string): void {
  for (const key of cache.keys()) {
    if (matchesKey(key, keyOrPrefix)) cache.delete(key)
  }
  for (const listener of invalidationListeners) {
    if (matchesKey(listener.key, keyOrPrefix)) listener.notify()
  }
}

/**
 * Loads one resource, caching the last successful read under `key`.
 *
 * A cache hit renders immediately and is revalidated in the background, so
 * navigating back to a list does not flash a skeleton. `reload()` drops the
 * cached value first, which is what a retry button and a post-mutation refresh
 * need. An aborted request never becomes a visible error, and a superseded
 * response cannot overwrite newer state.
 */
export function useApiResource<T>(
  key: string,
  load: (signal: AbortSignal) => Promise<T>,
  options: { enabled?: boolean } = {},
): {
  state: ApiResource<T>
  reload: () => void
} {
  const { enabled = true } = options
  const [entry, setEntry] = useState<{ key: string; state: ApiResource<T> } | null>(null)
  const [reloadCount, setReloadCount] = useState(0)
  const mounted = useRef(true)

  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])

  useEffect(() => {
    if (!enabled) return
    const controller = new AbortController()
    load(controller.signal).then(
      (data) => {
        if (controller.signal.aborted) return
        const next: ApiResource<T> = { status: 'ready', data }
        cache.set(key, next)
        setEntry({ key, state: next })
      },
      (error: unknown) => {
        if (controller.signal.aborted || isAbortError(error)) return
        // A failed revalidation keeps the last good value on screen; only a
        // resource with nothing cached becomes a visible error.
        if (cache.get(key)?.status === 'ready') return
        setEntry({ key, state: { status: 'error', error } })
      },
    )
    return () => controller.abort()
  }, [key, load, reloadCount, enabled])

  const cached = cache.get(key) as ApiResource<T> | undefined
  const state: ApiResource<T> = entry !== null && entry.key === key
    ? entry.state
    : cached ?? { status: 'loading' }

  const reload = useCallback(() => {
    cache.delete(key)
    setEntry(null)
    setReloadCount((count) => count + 1)
  }, [key])

  // Invalidation from elsewhere re-runs the loader: the cache entry is already
  // gone, so clearing the local copy and bumping the counter is what replaces
  // the stale value instead of leaving it on screen.
  useEffect(() => {
    const listener = {
      key,
      notify: () => {
        setEntry(null)
        setReloadCount((count) => count + 1)
      },
    }
    invalidationListeners.add(listener)
    return () => {
      invalidationListeners.delete(listener)
    }
  }, [key])

  return { state, reload }
}
