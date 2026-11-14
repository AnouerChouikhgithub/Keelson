import { useMemo, useState } from 'react'
import { Icon } from '../components/Icon'
import { useI18n } from '../i18n/I18nContext'
import { customerRecords } from '../mocks/customers'

type Line = { id: number; description: string; quantity: string; unitPrice: string }

const initialLines: Line[] = [
  { id: 1, description: 'Identité visuelle — atelier', quantity: '1', unitPrice: '2400.000' },
  { id: 2, description: 'Déclinaisons print', quantity: '3', unitPrice: '180.000' },
]

function toAmount(value: string): number {
  const parsed = Number.parseFloat(value.replace(',', '.'))
  return Number.isFinite(parsed) && parsed > 0 ? parsed : 0
}

export function InvoiceEditorScreen({ onBack }: { onBack: () => void }) {
  const { m, currency, date } = useI18n()
  const copy = m.workspace.editor
  const [customerId, setCustomerId] = useState(customerRecords[0].id)
  const [issueDate, setIssueDate] = useState('2026-11-14')
  const [dueDate, setDueDate] = useState('2026-11-28')
  const [lines, setLines] = useState<Line[]>(initialLines)
  const [taxRate, setTaxRate] = useState('19')
  const [discount, setDiscount] = useState('0')
  const [feedback, setFeedback] = useState<string | null>(null)

  const customer = customerRecords.find((item) => item.id === customerId) ?? customerRecords[0]

  const totals = useMemo(() => {
    const subtotal = lines.reduce((sum, line) => sum + toAmount(line.quantity) * toAmount(line.unitPrice), 0)
    const tax = subtotal * (toAmount(taxRate) / 100)
    const total = Math.max(0, subtotal - toAmount(discount) + tax)
    return { subtotal, tax, total }
  }, [lines, taxRate, discount])

  function updateLine(id: number, patch: Partial<Line>) {
    setLines((current) => current.map((line) => line.id === id ? { ...line, ...patch } : line))
  }

  function addLine() {
    setLines((current) => [...current, { id: Date.now(), description: '', quantity: '1', unitPrice: '0.000' }])
  }

  function removeLine(id: number) {
    setLines((current) => current.length > 1 ? current.filter((line) => line.id !== id) : current)
  }

  return (
    <section className="editor-screen">
      <div className="panel-heading workspace-heading">
        <div>
          <button type="button" className="button button-ghost" onClick={onBack}>
            <Icon name="chevron" size={15} /> {copy.back}
          </button>
          <h1>{copy.title}</h1>
          <p>{copy.subtitle}</p>
        </div>
      </div>

      {feedback && <div className="inline-feedback" role="status"><Icon name="check" size={16} /> {feedback}</div>}

      <div className="editor-layout">
        <form className="panel nested-panel editor-form" onSubmit={(event) => event.preventDefault()}>
          <label className="field">
            <span>{copy.customer}</span>
            <select value={customerId} onChange={(event) => setCustomerId(event.target.value)}>
              {customerRecords.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
            </select>
          </label>

          <div className="field-row">
            <label className="field">
              <span>{copy.issueDate}</span>
              <input type="date" value={issueDate} onChange={(event) => setIssueDate(event.target.value)} />
            </label>
            <label className="field">
              <span>{copy.dueDate}</span>
              <input type="date" value={dueDate} onChange={(event) => setDueDate(event.target.value)} />
            </label>
          </div>

          <h2>{copy.lineItems}</h2>
          <div className="line-list">
            {lines.map((line, index) => (
              <div className="line-row" key={line.id}>
                <label className="field">
                  <span className="visually-hidden">{`${copy.description} ${index + 1}`}</span>
                  <input
                    value={line.description}
                    placeholder={copy.emptyLine}
                    aria-label={`${copy.description} ${index + 1}`}
                    onChange={(event) => updateLine(line.id, { description: event.target.value })}
                  />
                </label>
                <label className="field line-qty">
                  <span className="visually-hidden">{copy.quantity}</span>
                  <input inputMode="decimal" value={line.quantity} aria-label={`${copy.quantity} ${index + 1}`} onChange={(event) => updateLine(line.id, { quantity: event.target.value })} />
                </label>
                <label className="field line-price">
                  <span className="visually-hidden">{copy.unitPrice}</span>
                  <input inputMode="decimal" value={line.unitPrice} aria-label={`${copy.unitPrice} ${index + 1}`} onChange={(event) => updateLine(line.id, { unitPrice: event.target.value })} />
                </label>
                <span className="line-total">{currency(toAmount(line.quantity) * toAmount(line.unitPrice))}</span>
                <button type="button" className="icon-button" aria-label={copy.removeLine(index + 1)} onClick={() => removeLine(line.id)}>
                  <Icon name="close" size={16} />
                </button>
              </div>
            ))}
          </div>
          <button type="button" className="button button-ghost" onClick={addLine}><Icon name="plus" size={16} /> {copy.addLine}</button>

          <div className="field-row">
            <label className="field">
              <span>{copy.taxRate}</span>
              <input inputMode="decimal" value={taxRate} onChange={(event) => setTaxRate(event.target.value)} />
            </label>
            <label className="field">
              <span>{copy.discount}</span>
              <input inputMode="decimal" value={discount} onChange={(event) => setDiscount(event.target.value)} />
            </label>
          </div>
        </form>

        <section className="panel nested-panel preview-pane" aria-label={copy.preview}>
          <div className="preview-heading">
            <span>{copy.preview}</span>
            <span className="mock-note">{copy.previewNote}</span>
          </div>
          <div className="preview-paper">
            <div className="preview-brand"><img src="/Keelson.svg" alt="" /><strong>keelson</strong></div>
            <div className="preview-meta">
              <div><span>{copy.billTo}</span><strong>{customer.name}</strong><small>{customer.address}</small><small>{customer.email}</small></div>
              <div className="preview-dates">
                <span>{copy.issueDate}: {date(issueDate)}</span>
                <span>{copy.dueDate}: {date(dueDate)}</span>
              </div>
            </div>
            <div className="table-wrap preview-table-wrap">
              <table>
                <thead><tr><th>{copy.description}</th><th>{copy.quantity}</th><th>{copy.unitPrice}</th><th>{copy.lineTotal}</th></tr></thead>
                <tbody>
                  {lines.map((line) => (
                    <tr key={line.id}>
                      <td>{line.description || '—'}</td>
                      <td className="amount-cell">{line.quantity}</td>
                      <td className="amount-cell">{currency(toAmount(line.unitPrice))}</td>
                      <td className="amount-cell">{currency(toAmount(line.quantity) * toAmount(line.unitPrice))}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <dl className="preview-totals">
              <div><dt>{copy.subtotal}</dt><dd>{currency(totals.subtotal)}</dd></div>
              <div><dt>{copy.tax}</dt><dd>{currency(totals.tax)}</dd></div>
              <div className="preview-grand"><dt>{copy.total}</dt><dd>{currency(totals.total)}</dd></div>
            </dl>
          </div>
        </section>
      </div>

      <div className="sticky-bar">
        <button type="button" className="button button-secondary" onClick={() => setFeedback(copy.saved)}>{copy.saveDraft}</button>
        <button type="button" className="button button-primary" disabled>{copy.send}</button>
        <span className="mock-note">{copy.sentLocal}</span>
      </div>
    </section>
  )
}
