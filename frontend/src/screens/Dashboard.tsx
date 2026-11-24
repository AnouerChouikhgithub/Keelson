import { useCallback } from 'react'
import { Link, useNavigate } from 'react-router'
import { readDashboard } from '../api/dashboard'
import type { InvoiceStatus } from '../api/types'
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
import { AiOptInHint } from '../features/ai/AiOptInHint'
import { OnboardingChecklist } from '../features/ai/OnboardingChecklist'
import { SummaryCard } from '../features/ai/SummaryCard'
import { canUseAi, useAiStatus } from '../features/ai/useAiStatus'
import './Dashboard.css'

type MonthBucket = { key: string; year: number; month: number; paid: number }

/** Display order of the status panel; the counts themselves come from the server. */
const STATUSES: InvoiceStatus[] = ['draft', 'sent', 'paid', 'overdue']

/** Millimes per currency unit — the invoice representation (three decimals). */
const MILLIMES_PER_UNIT = 1000

/** Display only: the server has already summed these as integers. */
function amount(millimes: number): number {
  return millimes / MILLIMES_PER_UNIT
}

/**
 * Every figure on this screen comes from `GET …/dashboard`, where PostgreSQL
 * computes it across the whole tenant. Nothing is summed here: the invoice list
 * is paginated, so aggregating a page of it would silently ignore everything
 * past the last row on it. The chart is an SVG for sighted users plus the same
 * numbers in a visually hidden table.
 */
export default function Dashboard() {
  const { m, currency, number, date, month } = useI18n()
  const { membership } = useWorkspace()
  const navigate = useNavigate()
  const load = useCallback(
    (signal: AbortSignal) => readDashboard(membership.id, signal),
    [membership.id],
  )
  const dashboard = useApiResource(`dashboard:${membership.id}`, load)
  useDocumentTitle(m.page.dashboard)
  // One status read for both cards; it renders nothing when AI is off or the
  // call fails, which is what the brief asks for.
  const aiStatus = useAiStatus(membership.id)

  if (dashboard.state.status === 'loading') {
    return (
      <div className="dashboard">
        <Skeleton variant="text" count={2} label={m.common.loading} />
        <Skeleton variant="card" count={4} label={m.common.loading} />
        <Skeleton variant="chart" count={1} label={m.common.loading} />
      </div>
    )
  }

  if (dashboard.state.status === 'error') {
    return (
      <ErrorState
        title={m.dashboard.loadFailed}
        message={presentError(m, dashboard.state.error)}
        retryLabel={m.common.retry}
        onRetry={dashboard.reload}
      />
    )
  }

  const data = dashboard.state.data
  const buckets: MonthBucket[] = data.revenueByMonth.map((entry) => ({
    key: entry.month,
    year: Number(entry.month.slice(0, 4)),
    month: Number(entry.month.slice(5, 7)) - 1,
    paid: amount(entry.paidMillimes),
  }))
  const chartTotalMillimes = data.revenueByMonth.reduce((sum, entry) => sum + entry.paidMillimes, 0)
  const chartMax = Math.max(...buckets.map((bucket) => bucket.paid), 1)
  const recent = data.recentInvoices
  const openCount = data.statusCounts.sent + data.statusCounts.overdue
  const thisMonthKey = buckets.length === 0 ? '' : buckets[buckets.length - 1].key

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

      {canUseAi(aiStatus) ? (
        <div className="dashboard-ai">
          <OnboardingChecklist organizationId={membership.id} />
          <SummaryCard organizationId={membership.id} kind="overview" />
        </div>
      ) : (
        // Nothing to show, but an Owner may not know the feature exists: one
        // line, only for the roles that can act on it.
        <AiOptInHint organizationId={membership.id} role={membership.role} />
      )}

      <section className="metrics-grid" aria-label={m.dashboard.overviewAria}>
        <article className="metric-card">
          <p className="metric-label">{m.dashboard.metrics.revenue}</p>
          <p className="metric-value">{currency(amount(data.paidThisMonthMillimes))}</p>
          <p className="metric-caption">{m.dashboard.changeInvoices(data.paidThisMonthCount)}</p>
        </article>
        <article className="metric-card">
          <p className="metric-label">{m.dashboard.metrics.outstanding}</p>
          <p className="metric-value">{currency(amount(data.outstandingMillimes))}</p>
          <p className="metric-caption">{m.dashboard.statusCount(openCount)}</p>
        </article>
        <article className="metric-card">
          <p className="metric-label">{m.dashboard.metrics.overdue}</p>
          <p className="metric-value">{currency(amount(data.overdueMillimes))}</p>
          <p className="metric-caption">{m.dashboard.statusCount(data.overdueCount)}</p>
        </article>
        <article className="metric-card">
          <p className="metric-label">{m.dashboard.metrics.paidRate}</p>
          <p className="metric-value">{number(data.paidRateThisMonthPercent, 0)}%</p>
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
              <strong>{currency(amount(chartTotalMillimes))}</strong>
            </div>
          </div>

          {chartTotalMillimes === 0 ? (
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
                <desc id="revenue-chart-desc">{m.dashboard.revenueAria(currency(amount(chartTotalMillimes)))}</desc>
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
            {STATUSES.map((status) => (
              <li key={status}>
                <StatusBadge status={status} />
                <strong>{number(data.statusCounts[status], 0)}</strong>
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
                  <td className="cell-amount">{currency(Number(invoice.total))}</td>
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
