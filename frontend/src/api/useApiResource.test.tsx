import { act, renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { invalidateResource, useApiResource } from './useApiResource'

/**
 * The shared read cache, tested on its own.
 *
 * `invalidateResource` is what a write calls to say "this read is stale". It has
 * to reach hooks that are already mounted, not just the cache: a mounted hook
 * keeps its value in local state, so dropping the cache alone left the old value
 * on screen until the component remounted. Each test uses its own key because
 * the cache and the listener set are module-level.
 */
async function mountReady(key: string, loader: () => Promise<{ value: number }>) {
  const hook = renderHook(() => useApiResource(key, loader))
  await waitFor(() => expect(hook.result.current.state.status).toBe('ready'))
  return hook
}

describe('invalidateResource', () => {
  it('refetches a mounted reader whose key matches exactly', async () => {
    const loader = vi.fn(async () => ({ value: 1 }))
    const { result } = await mountReady('invoices:org-exact', loader)
    expect(loader).toHaveBeenCalledTimes(1)

    act(() => invalidateResource('invoices:org-exact'))

    await waitFor(() => expect(loader).toHaveBeenCalledTimes(2))
    await waitFor(() => expect(result.current.state.status).toBe('ready'))
  })

  it('refetches a mounted reader whose key starts with the prefix', async () => {
    const loader = vi.fn(async () => ({ value: 1 }))
    await mountReady('invoices:org-prefix:all', loader)

    act(() => invalidateResource('invoices:org-prefix'))

    await waitFor(() => expect(loader).toHaveBeenCalledTimes(2))
  })

  it('leaves a reader of an unrelated key alone', async () => {
    const loader = vi.fn(async () => ({ value: 1 }))
    await mountReady('invoices:org-other', loader)

    act(() => invalidateResource('customers:org-other'))
    // Give a refetch the chance to start before asserting it did not.
    await act(async () => { await Promise.resolve() })

    expect(loader).toHaveBeenCalledTimes(1)
  })

  it('does not refetch a reader that has unmounted', async () => {
    const loader = vi.fn(async () => ({ value: 1 }))
    const { unmount } = await mountReady('invoices:org-unmounted', loader)

    unmount()
    act(() => invalidateResource('invoices:org-unmounted'))
    await act(async () => { await Promise.resolve() })

    expect(loader).toHaveBeenCalledTimes(1)
  })

  it('drops the cached value so the next mount reads again', async () => {
    const loader = vi.fn(async () => ({ value: 1 }))
    const first = await mountReady('invoices:org-remount', loader)
    first.unmount()

    act(() => invalidateResource('invoices:org-remount'))
    await mountReady('invoices:org-remount', loader)

    expect(loader).toHaveBeenCalledTimes(2)
  })
})
