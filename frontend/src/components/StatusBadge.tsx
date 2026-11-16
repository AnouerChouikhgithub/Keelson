import { useI18n } from '../i18n/I18nContext'
import type { InvoiceStatus } from '../api/types'
import { statusTone } from './statusTone'

export function StatusBadge({ status }: { status: InvoiceStatus }) {
  const { m } = useI18n()

  return (
    <span className={`status-badge status-${statusTone[status]}`}>
      <span />
      {m.status[status]}
    </span>
  )
}
