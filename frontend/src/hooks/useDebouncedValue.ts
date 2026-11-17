import { useEffect, useState } from 'react'

/**
 * Returns `value` once it has stopped changing for `delayMs`.
 *
 * Used by every list search so typing does not refilter on each keystroke, and
 * by the subdomain check so a half-typed address is never queried.
 */
export function useDebouncedValue<T>(value: T, delayMs = 300): T {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delayMs)
    return () => clearTimeout(timer)
  }, [value, delayMs])

  return debounced
}
