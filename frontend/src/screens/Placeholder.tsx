import { Icon } from '../components/Icon'
import type { IconName } from '../components/Icon'
import { useI18n } from '../i18n/I18nContext'
import type { NavPageId } from '../pages'

type PlaceholderDescription = 'invoices' | 'customers' | 'team' | 'billing'

const placeholderPages: Partial<Record<NavPageId, { icon: IconName; description: PlaceholderDescription }>> = {
  invoices: { icon: 'file', description: 'invoices' },
  customers: { icon: 'users', description: 'customers' },
  team: { icon: 'users', description: 'team' },
  billing: { icon: 'card', description: 'billing' },
}

export function Placeholder({ page }: { page: NavPageId }) {
  const { m } = useI18n()
  const config = placeholderPages[page]
  if (!config) return null

  return (
    <section className="placeholder-panel">
      <span className="placeholder-icon"><Icon name={config.icon} size={25} /></span>
      <p className="eyebrow">{m.placeholder.eyebrow}</p>
      <h1>{m.page[page]}</h1>
      <p>{m.placeholder[config.description]}</p>
      <span className="coming-soon"><span /> {m.placeholder.badge}</span>
    </section>
  )
}
