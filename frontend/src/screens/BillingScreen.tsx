import { useState } from 'react'
import { EmptyState } from '../components/EmptyState'
import { Icon } from '../components/Icon'
import { OverlaySurface } from '../components/OverlaySurface'
import { useEscapeKey } from '../hooks/useEscapeKey'
import { useI18n } from '../i18n/I18nContext'
import { billingHistory, configuredLimits, currentPlan, planCatalog, usage } from '../mocks/billing'

export function BillingScreen() {
  const { m, currency, date, number } = useI18n()
  const copy = m.workspace.billing
  const [upgradeOpen, setUpgradeOpen] = useState(false)

  useEscapeKey(upgradeOpen, () => setUpgradeOpen(false))

  const meters = [
    { id: 'invoices', label: copy.invoiceUsage, used: usage.invoiceDraftsThisMonth, limit: configuredLimits.invoices },
    { id: 'seats', label: copy.seatUsage, used: usage.activeSeats, limit: configuredLimits.seats },
  ]

  return (
    <section className="panel workspace-panel">
      <div className="panel-heading workspace-heading">
        <div>
          <h1>{m.page.billing}</h1>
          <p>{m.placeholder.billing}</p>
        </div>
        <button type="button" className="button button-secondary" onClick={() => setUpgradeOpen(true)}>{copy.upgrade}</button>
      </div>

      <div className="plan-grid">
        {planCatalog.map((plan) => {
          const isCurrent = plan.id === currentPlan
          return (
            <article className={`plan-card ${isCurrent ? 'plan-current' : ''}`} key={plan.id}>
              <div className="plan-top">
                <h2>{plan.id === 'free' ? copy.free : copy.pro}</h2>
                {isCurrent && <span className="pill-current">{copy.current}</span>}
                {plan.testOnly && <span className="pill-test">{copy.testOnly}</span>}
              </div>
              <p>{plan.id === 'free' ? copy.planFree : copy.planPro}</p>
              <div className="plan-price">{copy.priceUnset}</div>
              {!isCurrent && (
                <button type="button" className="button button-secondary" onClick={() => setUpgradeOpen(true)}>{copy.upgrade}</button>
              )}
            </article>
          )
        })}
      </div>

      <div className="dashboard-columns">
        <article className="panel nested-panel">
          <h2>{copy.usage}</h2>
          {meters.map((meter) => (
            <div className="meter" key={meter.id}>
              <div className="meter-top">
                <span>{meter.label}</span>
                <strong>{meter.limit === null ? copy.limitUnset : copy.usedOf(meter.used, number(meter.limit, 0))}</strong>
              </div>
              {meter.limit === null ? (
                <div className="progress-track progress-unset" aria-hidden="true"><span style={{ width: '18%' }} /></div>
              ) : (
                <div className="progress-track"><span style={{ width: `${Math.min(100, Math.round((meter.used / meter.limit) * 100))}%` }} /></div>
              )}
              <span className="meter-value">{number(meter.used, 0)} {meter.id === 'invoices' ? copy.invoiceUsage.toLowerCase() : copy.seatUsage.toLowerCase()}</span>
            </div>
          ))}
          <p className="mock-note">{copy.mockNote}</p>
        </article>

        <article className="panel nested-panel">
          <h2>{copy.paymentMethod}</h2>
          <div className="payment-empty">
            <span className="placeholder-icon"><Icon name="card" size={22} /></span>
            <p>{copy.noPayment}</p>
          </div>
        </article>
      </div>

      <div className="workspace-section">
        <h2>{copy.history}</h2>
        {billingHistory.length === 0 ? (
          <EmptyState icon="file" title={copy.historyEmpty} body={copy.historyHint} />
        ) : (
          <div className="table-wrap">
            <table>
              <thead><tr><th>{copy.date}</th><th>{copy.amount}</th><th>{copy.status}</th></tr></thead>
              <tbody>
                {billingHistory.map((entry) => (
                  <tr key={entry.id}><td>{date(entry.date)}</td><td className="amount-cell">{currency(entry.amount)}</td><td>{m.status[entry.status]}</td></tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {upgradeOpen && (
        <OverlaySurface variant="dialog" closeLabel={copy.close} onClose={() => setUpgradeOpen(false)} labelledBy="billing-dialog-title">
            <div className="drawer-heading">
              <h2 id="billing-dialog-title">{copy.upgradeTitle}</h2>
              <button type="button" className="icon-button" aria-label={copy.close} onClick={() => setUpgradeOpen(false)}><Icon name="close" /></button>
            </div>
            <p>{copy.upgradeBody}</p>
            <div className="modal-actions">
              <button type="button" className="button button-secondary" onClick={() => setUpgradeOpen(false)}>{copy.close}</button>
            </div>
        </OverlaySurface>
      )}
    </section>
  )
}
