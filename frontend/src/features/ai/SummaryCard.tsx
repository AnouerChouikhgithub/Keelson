import { useRef, useState } from 'react'
import { Icon } from '../../components/Icon'
import { isAbortError } from '../../api/errors'
import { useI18n } from '../../i18n/I18nContext'
import { requestSummary } from './api'
import type { AiSummary, SummaryKind } from './api'
import { presentAiError } from './presentAiError'
import './ai.css'

/**
 * A summary is generated on request, never on mount: every call spends the
 * workspace's token budget, so a dashboard visit must not do it silently.
 *
 * The numbers come first, from `facts`, and are formatted locally (including
 * Arabic digits); the model's sentence follows as plain text. When the backend
 * says the text is the deterministic template (`fallback: true`), the AI label
 * is omitted because no model wrote it.
 */

/** Amounts arrive in integer millimes, the invoice representation. */
const MONEY_KEY = /Millimes$/
/** `asOf`, `windowStart`, `oldestDueDate` are ISO calendar dates. */
const DATE_KEY = /(Date|Start)$|^asOf$/
/** Describe the request, not the workspace. */
const METADATA = new Set(['kind', 'role', 'locale', 'currency'])

export function SummaryCard({ organizationId, kind }: { organizationId: string; kind: SummaryKind }) {
  const { m, locale, currency, number, date, dateTime } = useI18n()
  const [summary, setSummary] = useState<AiSummary | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [pending, setPending] = useState(false)
  const inFlight = useRef(false)

  // Read explicitly so the unused-key script can see every label.
  const copy: Record<SummaryKind, { title: string; subtitle: string }> = {
    overview: { title: m.ai.summary.overview.title, subtitle: m.ai.summary.overview.subtitle },
    receivables: { title: m.ai.summary.receivables.title, subtitle: m.ai.summary.receivables.subtitle },
    activity: { title: m.ai.summary.activity.title, subtitle: m.ai.summary.activity.subtitle },
  }
  const labels: Record<string, string> = {
    customerCount: m.ai.facts.customerCount,
    invoiceCount: m.ai.facts.invoiceCount,
    draftInvoiceCount: m.ai.facts.draftInvoiceCount,
    sentInvoiceCount: m.ai.facts.sentInvoiceCount,
    paidInvoiceCount: m.ai.facts.paidInvoiceCount,
    overdueInvoiceCount: m.ai.facts.overdueInvoiceCount,
    openInvoiceCount: m.ai.facts.openInvoiceCount,
    dueSoonInvoiceCount: m.ai.facts.dueSoonInvoiceCount,
    outstandingMillimes: m.ai.facts.outstandingMillimes,
    overdueMillimes: m.ai.facts.overdueMillimes,
    dueSoonMillimes: m.ai.facts.dueSoonMillimes,
    paidThisMonthMillimes: m.ai.facts.paidThisMonthMillimes,
    paidMillimes: m.ai.facts.paidMillimes,
    windowDays: m.ai.facts.windowDays,
    invoicesCreated: m.ai.facts.invoicesCreated,
    invoicesSent: m.ai.facts.invoicesSent,
    invoicesPaid: m.ai.facts.invoicesPaid,
    customersCreated: m.ai.facts.customersCreated,
    asOf: m.ai.facts.asOf,
    oldestDueDate: m.ai.facts.oldestDueDate,
    windowStart: m.ai.facts.windowStart,
    customerNames: m.ai.facts.customerNames,
  }

  function formatFact(factKey: string, value: string | number | string[]) {
    if (Array.isArray(value)) return value.join(', ')
    if (MONEY_KEY.test(factKey)) {
      const parsed = Number(value)
      if (Number.isFinite(parsed)) return currency(parsed / 1000)
    }
    if (DATE_KEY.test(factKey) && typeof value === 'string') return date(value)
    if (typeof value === 'number') return number(value, 0)
    return value
  }

  async function generate() {
    if (inFlight.current) return
    inFlight.current = true
    setPending(true)
    setNotice(null)
    try {
      setSummary(await requestSummary(organizationId, kind, { locale }))
    } catch (caught) {
      if (isAbortError(caught)) return
      setSummary(null)
      setNotice(presentAiError(m, caught))
    } finally {
      inFlight.current = false
      setPending(false)
    }
  }

  const headingId = `ai-summary-${kind}`
  const facts =
    summary === null ? [] : Object.entries(summary.facts).filter(([factKey]) => !METADATA.has(factKey))

  return (
    <section className="panel ai-summary" aria-labelledby={headingId}>
      <div className="panel-heading">
        <div>
          <h2 id={headingId}>{copy[kind].title}</h2>
          <p>{copy[kind].subtitle}</p>
        </div>
        <button
          type="button"
          className="button button-secondary button-sm"
          disabled={pending}
          aria-busy={pending || undefined}
          onClick={() => { void generate() }}
        >
          <Icon name="sparkle" size={16} />
          {pending
            ? m.ai.summary.generating
            : summary === null
              ? m.ai.summary.generate
              : m.ai.summary.regenerate}
        </button>
      </div>

      {notice !== null && <p className="ai-notice" role="alert">{notice}</p>}

      {summary !== null && (
        <>
          {facts.length > 0 && (
            <dl className="ai-summary-facts">
              {facts.map(([factKey, value]) => (
                <div key={factKey}>
                  <dt>{labels[factKey] ?? factKey}</dt>
                  <dd>{formatFact(factKey, value)}</dd>
                </div>
              ))}
            </dl>
          )}
          <p className="ai-summary-text">{summary.text}</p>
          <div className="ai-summary-meta">
            {!summary.fallback && <span className="ai-generated-label">{m.ai.assistant.label}</span>}
            <span className="ai-summary-date">{m.ai.summary.generatedAt(dateTime(summary.generatedAt))}</span>
          </div>
        </>
      )}
    </section>
  )
}
