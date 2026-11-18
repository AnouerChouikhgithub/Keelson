import { useCallback } from 'react'
import { Icon } from '../../components/Icon'
import { useApiResource } from '../../api/useApiResource'
import { useI18n } from '../../i18n/I18nContext'
import { getOnboarding } from './api'
import './ai.css'

/**
 * The getting-started card.
 *
 * The server sends a closed set of step ids and computes the progress itself,
 * so the counter below is `completed`/`total` from the response rather than a
 * client-side recount — a step the UI cannot draw still counts. A failed or
 * empty read renders nothing: an onboarding hint is not worth an error state on
 * the dashboard.
 */
export function OnboardingChecklist({ organizationId }: { organizationId: string }) {
  const { m } = useI18n()
  const load = useCallback((signal: AbortSignal) => getOnboarding(organizationId, signal), [organizationId])
  const resource = useApiResource(`ai-onboarding:${organizationId}`, load)

  if (resource.state.status !== 'ready') return null
  const { steps, completed, total } = resource.state.data
  if (steps.length === 0) return null

  // Read explicitly so the unused-key script can see every label.
  const labels: Record<string, string> = {
    invite_team: m.ai.onboarding.steps.invite_team,
    add_customer: m.ai.onboarding.steps.add_customer,
    create_invoice: m.ai.onboarding.steps.create_invoice,
    send_invoice: m.ai.onboarding.steps.send_invoice,
    record_payment: m.ai.onboarding.steps.record_payment,
  }
  const allDone = total > 0 && completed >= total

  return (
    <section className="panel ai-onboarding" aria-labelledby="ai-onboarding-title">
      <div className="panel-heading">
        <div>
          <h2 id="ai-onboarding-title">{m.ai.onboarding.title}</h2>
          <p>{m.ai.onboarding.subtitle}</p>
        </div>
        <span className={`ai-progress ${allDone ? 'ai-progress-done' : ''}`}>
          {m.ai.onboarding.progress(completed, total)}
        </span>
      </div>
      <progress
        className="ai-progress-bar"
        value={completed}
        max={total}
        aria-label={m.ai.onboarding.progress(completed, total)}
      />
      <ol className="ai-checklist">
        {steps.map((step) => (
          <ChecklistRow key={step.id} label={labels[step.id] ?? step.id} done={step.done} />
        ))}
      </ol>
    </section>
  )
}

/**
 * The step ids are a closed set shared with the backend; a step this build has
 * no copy for still renders, with its id as the last resort.
 */
function ChecklistRow({ label, done }: { label: string; done: boolean }) {
  const { m } = useI18n()

  return (
    <li className={`ai-checklist-row ${done ? 'done' : ''}`}>
      <span className="ai-check" aria-hidden="true">
        {done && <Icon name="check" size={14} />}
      </span>
      <span className="ai-checklist-label">{label}</span>
      <span className="visually-hidden">
        {done ? m.ai.onboarding.done : m.ai.onboarding.todo}
      </span>
    </li>
  )
}
