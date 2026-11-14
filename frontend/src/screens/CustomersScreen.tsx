import { useMemo, useState } from 'react'
import { EmptyState } from '../components/EmptyState'
import { Icon } from '../components/Icon'
import { OverlaySurface } from '../components/OverlaySurface'
import { PersonCell } from '../components/PersonCell'
import { SearchField } from '../components/SearchField'
import { useEscapeKey } from '../hooks/useEscapeKey'
import { useI18n } from '../i18n/I18nContext'
import { customerRecords } from '../mocks/customers'

export function CustomersScreen() {
  const { m, currency, date, number } = useI18n()
  const copy = m.workspace.customers
  const [query, setQuery] = useState('')
  const [openId, setOpenId] = useState<string | null>(null)

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase()
    if (needle === '') return customerRecords
    return customerRecords.filter((customer) =>
      customer.name.toLowerCase().includes(needle)
      || customer.email.toLowerCase().includes(needle)
      || customer.city.toLowerCase().includes(needle))
  }, [query])

  const selected = customerRecords.find((customer) => customer.id === openId) ?? null

  useEscapeKey(selected !== null, () => setOpenId(null))

  return (
    <section className="panel workspace-panel">
      <div className="panel-heading workspace-heading">
        <div>
          <h1>{m.page.customers}</h1>
          <p>{m.placeholder.customers}</p>
        </div>
      </div>

      <div className="toolbar">
        <SearchField label={copy.searchPlaceholder} value={query} onChange={setQuery} />
      </div>

      {filtered.length === 0 ? (
        <EmptyState
          icon="users"
          title={copy.empty}
          body={copy.emptyHint}
          action={{ label: copy.clearSearch, onClick: () => setQuery('') }}
        />
      ) : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{copy.name}</th>
                <th>{copy.contact}</th>
                <th>{copy.city}</th>
                <th>{copy.invoices}</th>
                <th>{copy.revenue}</th>
                <th><span className="visually-hidden">{copy.details}</span></th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((customer) => (
                <tr key={customer.id}>
                  <td><PersonCell initials={customer.initials} name={customer.name} /></td>
                  <td className="muted-cell">{customer.email}</td>
                  <td>{customer.city}</td>
                  <td className="amount-cell">{number(customer.invoiceCount, 0)}</td>
                  <td className="amount-cell">{currency(customer.billed)}</td>
                  <td>
                    <button type="button" className="button button-ghost" aria-label={copy.open(customer.name)} onClick={() => setOpenId(customer.id)}>
                      {copy.view} <Icon name="chevron" size={15} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="table-footer">
        <span className="mock-note">{copy.mockNote}</span>
      </div>

      {selected && (
        <OverlaySurface variant="drawer" closeLabel={copy.close} onClose={() => setOpenId(null)} label={selected.name}>
            <div className="drawer-heading">
              <p className="eyebrow">{copy.drawerEyebrow}</p>
              <button type="button" className="icon-button" aria-label={copy.close} onClick={() => setOpenId(null)}><Icon name="close" /></button>
            </div>
            <div className="drawer-identity">
              <span className="drawer-avatar">{selected.initials}</span>
              <div>
                <h2>{selected.name}</h2>
                <span className="muted-cell">{selected.email}</span>
              </div>
            </div>
            <dl className="detail-list">
              <div><dt>{copy.contact}</dt><dd>{selected.phone}</dd></div>
              <div><dt>{copy.address}</dt><dd>{selected.address}</dd></div>
              <div><dt>{copy.invoices}</dt><dd>{number(selected.invoiceCount, 0)}</dd></div>
              <div><dt>{copy.revenue}</dt><dd>{currency(selected.billed)}</dd></div>
              <div><dt>{copy.lastInvoice}</dt><dd>{date(selected.lastInvoice)}</dd></div>
            </dl>
            <div className="drawer-activity">
              <h3>{copy.activity}</h3>
              <p>{copy.activityRows(selected.invoiceCount)}</p>
            </div>
            <button type="button" className="button button-secondary" onClick={() => setOpenId(null)}>{copy.close}</button>
        </OverlaySurface>
      )}
    </section>
  )
}
