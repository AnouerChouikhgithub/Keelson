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
 * says the text is a deterministic fallback, the AI label is omitted because no
 * model wrote it.
 */

const MONEY_KEY = /(total|amount|revenue|balance)$/i

export function SummaryCard({ organizationId, kind }: { organizationId: string; kind: SummaryKind }) {
  const { m, currency, number, dateTime } = useI18n()
  const [summary, setSummary] = useState<AiSummary | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [pending, setPending] = useState(false)
  const inFlight = useRef(false)

  // Read explicitly so the unused-key script can see every label.
  const copy: Record<SummaryKind, { title: string; subtitle: string }> = {
    organization: { title: m.ai.summary.organization.title, subtitle: m.ai.summary.organization.subtitle },
    invoices: { title: m.ai.summary.invoices.title, subtitle: m.ai.summary.invoices.subtitle },
    customers: { title: m.ai.summary.customers.title, subtitle: m.ai.summary.customers.subtitle },
  }
  const labels: Record<string, string> = {
    overdueInvoices: m.ai.facts.overdueInvoices,
    outstandingTotal: m.ai.facts.outstandingTotal,
    paidThisMonth: m.ai.facts.paidThisMonth,
    draftInvoices: m.ai.facts.draftInvoices,
    totalCustomers: m.ai.facts.totalCustomers,
    newCustomers: m.ai.facts.newCustomers,
  }

  function formatFact(factKey: string, value: string | number) {
    if (MONEY_KEY.test(factKey)) {
      const parsed = Number(value)
      if (Number.isFinite(parsed)) return currency(parsed)
    }
    if (typeof value === 'number') return number(value, 0)
    return value
  }

  async function generate() {
    if (inFlight.current) return
    inFlight.current = true
    setPending(true)
    setNotice(null)
    try {
      setSummary(await requestSummary(organizationId, kind))
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
  const facts = summary === null ? [] : Object.entries(summary.facts)

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
            {summary.fallback !== true && <span className="ai-generated-label">{m.ai.assistant.label}</span>}
            <span className="ai-summary-date">{m.ai.summary.generatedAt(dateTime(summary.generatedAt))}</span>
          </div>
        </>
      )}
    </section>
  )
}
