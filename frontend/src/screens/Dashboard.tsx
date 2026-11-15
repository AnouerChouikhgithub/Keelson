import { Icon } from '../components/Icon'
import { PersonCell } from '../components/PersonCell'
import { StatusBadge } from '../components/StatusBadge'
import { statusTone } from '../components/statusTone'
import { formatCurrencySymbol } from '../i18n/format'
import { useI18n } from '../i18n/I18nContext'
import {
  dashboardMetrics,
  invoicesThisMonth,
  paidInvoicesThisMonth,
  recentInvoices,
  revenueByMonth,
  revenueChangePercent,
  revenueTotal,
} from '../mocks/dashboard'

function Sparkline({ values }: { values: number[] }) {
  const points = values.map((value, index) => `${(index / (values.length - 1)) * 100},${100 - value}`).join(' ')
  return <svg className="sparkline" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"><polyline points={points} /></svg>
}

export function Dashboard({ activeOrganization }: { activeOrganization: { id: string; name: string } }) {
  const { m, locale, currency, percent, date, month, number } = useI18n()

  return (
    <>
      <section className="welcome-row">
        <div>
          <p className="eyebrow">{m.dashboard.eyebrow}</p>
          <h1>{m.dashboard.greeting} <span aria-hidden="true">✦</span></h1>
          <p className="welcome-copy">{m.dashboard.subtitle}</p>
        </div>
        <button className="button button-primary" type="button"><Icon name="plus" size={19} /> {m.dashboard.createInvoice}</button>
      </section>

      <section className="metrics-grid" aria-label={m.dashboard.overviewAria}>
        {dashboardMetrics.map((metric) => (
          <article className={`metric-card metric-${metric.tone}`} key={metric.id}>
            <div className="metric-top">
              <span>{m.dashboard.metrics[metric.id]}</span>
              <span className="metric-icon"><Icon name={metric.tone === 'teal' ? 'trend' : metric.tone === 'amber' ? 'clock' : metric.tone === 'rose' ? 'calendar' : 'sparkle'} size={17} /></span>
            </div>
            <div className="metric-value">
              {metric.display === 'currency' ? currency(metric.value) : percent(metric.value)}
              {metric.id !== 'paidRate' && <small>{formatCurrencySymbol(locale)}</small>}
            </div>
            <div className="metric-bottom">
              <span className={`metric-change ${metric.direction}`}>
                <Icon name="trend" size={14} />{' '}
                {metric.change.kind === 'percent' ? m.dashboard.changePercent(number(metric.change.value, 1)) : m.dashboard.changeInvoices(metric.change.value)}
              </span>
              <span className="metric-caption">{m.dashboard.vsLastMonth}</span>
              <Sparkline values={metric.series} />
            </div>
          </article>
        ))}
      </section>

      <section className="dashboard-columns">
        <article className="panel revenue-panel">
          <div className="panel-heading">
            <div><h2>{m.dashboard.revenueTitle}</h2><p>{m.dashboard.revenueSubtitle}</p></div>
            <button className="select-button" type="button"><Icon name="calendar" size={16} /> {m.dashboard.last12Months} <Icon name="chevron" size={15} /></button>
          </div>
          <div className="chart-summary">
            <strong>{currency(revenueTotal)}</strong>
            <span className="positive-pill"><Icon name="trend" size={14} /> {m.dashboard.changePercent(number(revenueChangePercent, 1))}</span>
            <span className="chart-comparison">{m.dashboard.comparedLastYear}</span>
          </div>
          <div className="bar-chart" role="img" aria-label={m.dashboard.revenueAria}>
            {revenueByMonth.map((entry) => (
              <div className="bar-column" key={entry.monthIndex}>
                <div className="bar-track"><div className={`bar-fill ${entry.monthIndex === 9 ? 'bar-current' : ''}`} style={{ height: `${entry.value}%` }} /></div>
                <span>{month(entry.monthIndex)}</span>
              </div>
            ))}
          </div>
        </article>
        <article className="panel activity-panel">
          <div className="panel-heading">
            <div><h2>{m.dashboard.quickTitle}</h2><p>{m.dashboard.quickSubtitle}</p></div>
            <button className="icon-button" aria-label={m.dashboard.moreOverview} type="button"><Icon name="more" /></button>
          </div>
          <div className="overview-total">
            <span>{m.dashboard.invoicesThisMonth}</span>
            <strong>{number(invoicesThisMonth, 0)}</strong>
            <span className="overview-note">{m.dashboard.moreThanLastMonth(6)}</span>
          </div>
          <div className="overview-progress">
            <div><span>{m.dashboard.paidInvoices}</span><strong>{number(paidInvoicesThisMonth, 0)} <small>{m.dashboard.ofCount(invoicesThisMonth)}</small></strong></div>
            <div className="progress-track"><span style={{ width: '86%' }} /></div>
          </div>
          <div className="overview-footer">
            <span className="avatar-stack"><span className="mini-avatar avatar-one">MJ</span><span className="mini-avatar avatar-two">EA</span><span className="mini-avatar avatar-three">CS</span></span>
            <span>{m.dashboard.activity}</span>
            <Icon name="chevron" size={16} />
          </div>
        </article>
      </section>

      <section className="panel invoices-panel">
        <div className="panel-heading invoices-heading">
          <div><h2>{m.dashboard.recentTitle}</h2><p>{m.dashboard.recentSubtitle}</p></div>
          <button className="button button-secondary" type="button">{m.dashboard.viewAll} <Icon name="arrow" size={16} /></button>
        </div>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{m.dashboard.table.invoice}</th>
                <th>{m.dashboard.table.customer}</th>
                <th>{m.dashboard.table.issueDate}</th>
                <th>{m.dashboard.table.dueDate}</th>
                <th>{m.dashboard.table.amount}</th>
                <th>{m.dashboard.table.status}</th>
                <th><span className="visually-hidden">{m.dashboard.table.actions}</span></th>
              </tr>
            </thead>
            <tbody>
              {recentInvoices.map((invoice) => (
                <tr key={invoice.number}>
                  <td className="invoice-number">{invoice.number}</td>
                  <td><PersonCell initials={invoice.initials} name={invoice.customer} tone={statusTone[invoice.status]} /></td>
                  <td>{date(invoice.issueDate)}</td>
                  <td>{date(invoice.dueDate)}</td>
                  <td className="amount-cell">{currency(invoice.amount)}</td>
                  <td><StatusBadge status={invoice.status} /></td>
                  <td><button className="icon-button row-action" aria-label={m.dashboard.moreActions(invoice.number)} type="button"><Icon name="more" /></button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
      <footer className="dashboard-footer"><span>{m.dashboard.footerMock(activeOrganization.name)}</span><span>{m.dashboard.footerCurrency}</span></footer>
    </>
  )
}
