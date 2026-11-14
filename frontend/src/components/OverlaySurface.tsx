import type { ReactNode } from 'react'

/**
 * Scrim plus dialog container shared by the customer drawer, the invite dialog
 * and the billing dialog. The calling screen owns the heading and content.
 */
export function OverlaySurface({
  variant,
  closeLabel,
  onClose,
  label,
  labelledBy,
  children,
}: {
  variant: 'drawer' | 'dialog'
  closeLabel: string
  onClose: () => void
  label?: string
  labelledBy?: string
  children: ReactNode
}) {
  return (
    <div className="drawer-layer">
      <button type="button" className="drawer-scrim" aria-label={closeLabel} onClick={onClose} />
      {variant === 'drawer' ? (
        <aside className="detail-drawer" role="dialog" aria-modal="true" aria-label={label}>
          {children}
        </aside>
      ) : (
        <div className="modal-card" role="dialog" aria-modal="true" aria-labelledby={labelledBy}>
          {children}
        </div>
      )}
    </div>
  )
}
