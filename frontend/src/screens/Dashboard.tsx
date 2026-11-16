import { useCallback } from 'react'
import { Link, useNavigate } from 'react-router'
import { listInvoices } from '../api/invoices'
import type { Invoice, InvoiceStatus } from '../api/types'
import { useApiResource } from '../api/useApiResource'
import { presentError } from '../api/presentError'
import { DataTable } from '../components/DataTable'
import { EmptyState } from '../components/EmptyState'
import { ErrorState } from '../components/ErrorState'
import { Icon } from '../components/Icon'
import { PersonCell } from '../components/PersonCell'
import { Skeleton } from '../components/Skeleton'
import { StatusBadge } from '../components/StatusBadge'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import { initialsOf } from '../utils/initials'
import { useWorkspace } from '../app/WorkspaceContext'
import './Dashboard.css'

type MonthBucket = { key: string; year: number; month: number; paid: number }

const STATUSES: InvoiceStatus[] = ['draft', 'sent', 'paid', 'overdue']

function monthsBack(count: number): { key: string; year: number; month: number }[] {
  const today = new Date()
  return Array.from({ length: count }, (_, index) => {
    const date = new Date(today.getFullYear(), today.getMonth() - (count - 1 - index), 1)
    return {
      key: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`,
      year: date.getFullYear(),
      month: date.getMonth(),
    }
  })
}

function amountOf(invoice: Invoice): number {
  const value = Number(invoice.total)
  return Number.isFinite(value) ? value : 0
}

function inMonth(invoice: Invoice, key: string): boolean {
  return invoice.issueDate.slice(0, 7) === key
}

/**
 * Every figure on this screen is derived from the invoice rows the API
 * returned — nothing is seeded, sampled or estimated. The chart is an SVG for
 * sighted users plus the same numbers in a visually hidden table.
 */
export default function Dashboard() {
  const { m, currency, number, date, month } = useI18n()
  const { membership } = useWorkspace()
  const navigate = useNavigate()
  const load = useCallback(
    (signal: AbortSignal) => listInvoices(membership.id, { limit: 100, signal }),
    [membership.id],
  )
  const invoices = useApiResource(`invoices:${membership.id}:all`, load)
  const today = new Date()
  const thisMonthKey = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`
  useDocumentTitle(m.page.dashboard)

  if (invoices.state.status === 'loading') {
    return (
      <div className="dashboard">
        <Skeleton variant="text" count={2} label={m.common.loading} />
        <Skeleton variant="card" count={4} label={m.common.loading} />
        <Skeleton variant="chart" count={1} label={m.common.loading} />
      </div>
    )
  }

  if (invoices.state.status === 'error') {
    return (
      <ErrorState
        title={m.dashboard.loadFailed}
        message={presentError(m, invoices.state.error)}
        retryLabel={m.common.retry}
        onRetry={invoices.reload}
      />
    )
  }

  const rows = invoices.state.data.items
  const thisMonth = rows.filter((invoice) => inMonth(invoice, thisMonthKey))
  const paidThisMonth = thisMonth.filter((invoice) => invoice.status === 'paid')
  const revenue = paidThisMonth.reduce((sum, invoice) => sum + amountOf(invoice), 0)
  const outstanding = rows
    .filter((invoice) => invoice.status === 'sent' || invoice.status === 'overdue')
    .reduce((sum, invoice) => sum + amountOf(invoice), 0)
  const overdue = rows
    .filter((invoice) => invoice.status === 'overdue')
    .reduce((sum, invoice) => sum + amountOf(invoice), 0)
  const paidRate = thisMonth.length === 0 ? 0 : paidThisMonth.length / thisMonth.length

  const buckets: MonthBucket[] = monthsBack(12).map((month) => ({
    ...month,
    paid: rows
      .filter((invoice) => invoice.status === 'paid' && inMonth(invoice, month.key))
      .reduce((sum, invoice) => sum + amountOf(invoice), 0),
  }))
  const chartMax = Math.max(...buckets.map((bucket) => bucket.paid), 1)
  const chartTotal = buckets.reduce((sum, bucket) => sum + bucket.paid, 0)
  const recent = [...rows]
    .sort((left, right) => right.issueDate.localeCompare(left.issueDate) || right.number.localeCompare(left.number))
    .slice(0, 5)
  const statusCounts = STATUSES.map((status) => ({
    status,
    count: rows.filter((invoice) => invoice.status === status).length,
  }))

  return (
    <div className="dashboard">
      <section className="page-intro">
        <div>
          <p className="eyebrow">{m.dashboard.eyebrow}</p>
          <h1>{m.dashboard.greetingNamed(membership.name)}</h1>
          <p>{m.dashboard.subtitle}</p>
        </div>
        <Link className="button button-primary" to={`/o/${membership.id}/invoices/new`}>
          <Icon name="plus" size={18} /> {m.dashboard.createInvoice}
        </Link>
      </section>

      <section className="metrics-grid" aria-label={m.dashboard.overviewAria}>
        <article className="metric-card">
          <p className="metric-label">{m.dashboard.metrics.revenue}</p>
          <p className="metric-value">{currency(revenue)}</p>
          <p className="metric-caption">{m.dashboard.changeInvoices(paidThisMonth.length)}</p>
        </article>
        <article className="metric-card">
          <p className="metric-label">{m.dashboard.metrics.outstanding}</p>
          <p className="metric-value">{currency(outstanding)}</p>
          <p className="metric-caption">{m.dashboard.statusCount(rows.filter((row) => row.status === 'sent' || row.status === 'overdue').length)}</p>
        </article>
        <article className="metric-card">
          <p className="metric-label">{m.dashboard.metrics.overdue}</p>
          <p className="metric-value">{currency(overdue)}</p>
          <p className="metric-caption">{m.dashboard.statusCount(rows.filter((row) => row.status === 'overdue').length)}</p>
        </article>
        <article className="metric-card">
          <p className="metric-label">{m.dashboard.metrics.paidRate}</p>
          <p className="metric-value">{number(paidRate * 100, 0)}%</p>
          <p className="metric-caption">{m.dashboard.paidRateHint}</p>
        </article>
      </section>

      <div className="dashboard-columns">
        <section className="panel chart-panel" aria-labelledby="revenue-title">
          <div className="panel-heading">
            <div>
              <h2 id="revenue-title">{m.dashboard.revenueTitle}</h2>
              <p>{m.dashboard.revenueSubtitle}</p>
            </div>
            <div className="chart-total">
              <span>{m.dashboard.revenueTotal}</span>
              <strong>{currency(chartTotal)}</strong>
            </div>
          </div>

          {chartTotal === 0 ? (
            <EmptyState icon="trend" title={m.dashboard.chartEmpty} />
          ) : (
            <figure className="revenue-chart">
              <svg
                viewBox="0 0 720 200"
                role="img"
                aria-labelledby="revenue-svg-title revenue-chart-desc"
                preserveAspectRatio="none"
              >
                <title id="revenue-svg-title">{m.dashboard.revenueTitle}</title>
                <desc id="revenue-chart-desc">{m.dashboard.revenueAria(currency(chartTotal))}</desc>
                {[0.25, 0.5, 0.75, 1].map((line) => (
                  <line
                    key={line}
                    x1="0"
                    x2="720"
                    y1={180 - line * 160}
                    y2={180 - line * 160}
                    className="chart-grid-line"
                  />
                ))}
                {buckets.map((bucket, index) => {
                  const height = bucket.paid === 0 ? 0 : Math.max(4, (bucket.paid / chartMax) * 160)
                  const x = index * 60 + 12
                  return (
                    <rect
                      key={bucket.key}
                      x={x}
                      y={180 - height}
                      width={36}
                      height={height}
                      rx="4"
                      className={`chart-bar ${bucket.key === thisMonthKey ? 'chart-bar-current' : ''}`}
                    />
                  )
                })}
              </svg>
              <div className="chart-axis" aria-hidden="true">
                {buckets.map((bucket) => (
                  <span key={bucket.key}>{month(bucket.month)}</span>
                ))}
              </div>
              <figcaption className="visually-hidden">{m.dashboard.chartCaption}</figcaption>
              <div className="visually-hidden">
                <table>
                  <caption>{m.dashboard.chartCaption}</caption>
                  <thead>
                    <tr>
                      <th scope="col">{m.dashboard.chartMonth}</th>
                      <th scope="col">{m.dashboard.chartRevenue}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {buckets.map((bucket) => (
                      <tr key={bucket.key}>
                        <th scope="row">{month(bucket.month)} {bucket.year}</th>
                        <td>{currency(bucket.paid)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </figure>
          )}
        </section>

        <section className="panel status-panel" aria-labelledby="status-counts-title">
          <div className="panel-heading">
            <div>
              <h2 id="status-counts-title">{m.dashboard.statusTitle}</h2>
              <p>{m.dashboard.statusSubtitle}</p>
            </div>
          </div>
          <ul className="status-list">
            {statusCounts.map((entry) => (
              <li key={entry.status}>
                <StatusBadge status={entry.status} />
                <strong>{number(entry.count, 0)}</strong>
              </li>
            ))}
          </ul>
        </section>
      </div>

      <section className="panel recent-panel" aria-labelledby="recent-invoices-title">
        <div className="panel-heading">
          <div>
            <h2 id="recent-invoices-title">{m.dashboard.recentTitle}</h2>
            <p>{m.dashboard.recentSubtitle}</p>
          </div>
          <Link className="button button-secondary button-sm" to={`/o/${membership.id}/invoices`}>
            {m.dashboard.viewAll} <Icon name="arrow" size={16} />
          </Link>
        </div>

        {recent.length === 0 ? (
          <EmptyState
            icon="file"
            title={m.dashboard.emptyTitle}
            body={m.dashboard.emptyBody}
            action={{
              label: m.dashboard.createInvoice,
              onClick: () => { void navigate(`/o/${membership.id}/invoices/new`) },
            }}
          />
        ) : (
          <DataTable caption={m.dashboard.tableCaption}>
            <thead>
              <tr>
                <th scope="col">{m.dashboard.table.invoice}</th>
                <th scope="col">{m.dashboard.table.customer}</th>
                <th scope="col">{m.dashboard.table.issueDate}</th>
                <th scope="col">{m.dashboard.table.amount}</th>
                <th scope="col">{m.dashboard.table.status}</th>
              </tr>
            </thead>
            <tbody>
              {recent.map((invoice) => (
                <tr key={invoice.id}>
                  <td className="cell-strong">
                    <Link to={`/o/${membership.id}/invoices/${invoice.id}`} aria-label={m.dashboard.open(invoice.number)}>
                      {invoice.number}
                    </Link>
                  </td>
                  <td>
                    <PersonCell initials={initialsOf(invoice.customer.name)} name={invoice.customer.name} />
                  </td>
                  <td>{date(invoice.issueDate)}</td>
                  <td className="cell-amount">{currency(amountOf(invoice))}</td>
                  <td><StatusBadge status={invoice.status} /></td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        )}
        <p className="dashboard-footer">{m.dashboard.footer}</p>
      </section>
    </div>
  )
}
