import { useEffect, useRef } from 'react'

/**
 * Closes a transient surface (drawer, dialog, row menu) when Escape is pressed.
 * The callback is kept in a ref so callers can pass an inline arrow without
 * re-subscribing the listener on every render.
 */
export function useEscapeKey(enabled: boolean, onEscape: () => void): void {
  const callback = useRef(onEscape)

  useEffect(() => {
    callback.current = onEscape
  }, [onEscape])

  useEffect(() => {
    if (!enabled) return
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') callback.current()
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [enabled])
}
