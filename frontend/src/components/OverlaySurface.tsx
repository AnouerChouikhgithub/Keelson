import { useEffect, useRef } from 'react'
import type { ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useFocusTrap } from '../hooks/useFocusTrap'
import './OverlaySurface.css'

/**
 * Scrim plus dialog/drawer container shared by the customer drawer, the invite
 * dialog and the confirm dialogs.
 *
 * Focus is moved inside on open, trapped while open and restored on close;
 * Escape closes; the scrim is a labelled button so a pointer and a screen
 * reader both have a way out. Rendered in a portal so a transformed ancestor
 * cannot clip it.
 */
export function OverlaySurface({
  variant,
  closeLabel,
  onClose,
  label,
  labelledBy,
  describedBy,
  children,
}: {
  variant: 'drawer' | 'dialog'
  closeLabel: string
  onClose: () => void
  label?: string
  labelledBy?: string
  describedBy?: string
  children: ReactNode
}) {
  const container = useRef<HTMLDivElement>(null)
  useFocusTrap(container, onClose)

  // The page behind a modal must not scroll underneath it.
  useEffect(() => {
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.body.style.overflow = previous
    }
  }, [])

  return createPortal(
    <div className={`overlay-layer overlay-${variant}`}>
      <button type="button" className="overlay-scrim" aria-label={closeLabel} onClick={onClose} tabIndex={-1} />
      {variant === 'drawer' ? (
        <aside
          ref={container}
          className="overlay-drawer"
          role="dialog"
          aria-modal="true"
          aria-label={label}
        >
          {children}
        </aside>
      ) : (
        <div
          ref={container}
          className="overlay-dialog"
          role="dialog"
          aria-modal="true"
          aria-labelledby={labelledBy}
          aria-describedby={describedBy}
          tabIndex={-1}
        >
          {children}
        </div>
      )}
    </div>,
    document.body,
  )
}
