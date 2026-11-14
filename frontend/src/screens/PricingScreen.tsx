import { useState } from 'react'
import { Icon } from '../components/Icon'
import { useI18n } from '../i18n/I18nContext'
import { currentPlan, planCatalog } from '../mocks/billing'

export function PricingScreen({ onStartFree }: { onStartFree: () => void }) {
  const { m } = useI18n()
  const copy = m.workspace.pricing
  const [period, setPeriod] = useState<'monthly' | 'yearly'>('monthly')

  return (
    <section className="pricing-screen">
      <header className="pricing-header">
        <p className="eyebrow">{m.page.billing}</p>
        <h1>{copy.title}</h1>
        <p>{copy.subtitle}</p>
        <div className="filter-tabs pricing-toggle" role="tablist" aria-label={`${copy.monthly} / ${copy.yearly}`}>
          {(['monthly', 'yearly'] as const).map((value) => (
            <button
              key={value}
              type="button"
              role="tab"
              aria-selected={period === value}
              className={`filter-tab ${period === value ? 'active' : ''}`}
              onClick={() => setPeriod(value)}
            >
              {value === 'monthly' ? copy.monthly : copy.yearly}
            </button>
          ))}
        </div>
        <span className="visually-hidden" role="status">{period}</span>
      </header>

      <div className="pricing-grid">
        {planCatalog.map((plan) => {
          const isFree = plan.id === 'free'
          const features = isFree ? copy.freeFeatures : copy.proFeatures
          return (
            <article className={`pricing-card ${plan.id === currentPlan ? 'plan-current' : ''}`} key={plan.id}>
              <div className="plan-top">
                <h2>{isFree ? copy.free : copy.pro}</h2>
                {plan.id === currentPlan && <span className="pill-current">{copy.current}</span>}
                {plan.testOnly && <span className="pill-test">{copy.testOnly}</span>}
              </div>
              <p className="pricing-price">{copy.priceUnset}</p>
              <span className="mock-note">{period === 'monthly' ? copy.monthly : copy.yearly}</span>
              <ul className="feature-list">
                {features.map((feature) => (
                  <li key={feature}><Icon name="check" size={16} /> {feature}</li>
                ))}
              </ul>
              {isFree ? (
                <button type="button" className="button button-primary" onClick={onStartFree}>{copy.ctaFree}</button>
              ) : (
                <button type="button" className="button button-secondary" disabled>{copy.ctaPro}</button>
              )}
            </article>
          )
        })}
      </div>

      <p className="mock-note pricing-footnote">{copy.footnote}</p>
    </section>
  )
}
