import type { ReactNode } from 'react'
import './DataTable.css'

/**
 * A table with a caption for screen readers and a horizontal scroller for
 * narrow screens. `caption` is required: an unlabelled data table is not
 * navigable by assistive technology.
 */
export function DataTable({
  caption,
  children,
  footer,
}: {
  caption: string
  children: ReactNode
  footer?: ReactNode
}) {
  return (
    <div className="data-table">
      <div className="data-table-scroll">
        <table>
          <caption className="visually-hidden">{caption}</caption>
          {children}
        </table>
      </div>
      {footer}
    </div>
  )
}
