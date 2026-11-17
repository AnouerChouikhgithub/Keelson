import { useCallback } from 'react'
import { Icon } from '../../components/Icon'
import { useApiResource } from '../../api/useApiResource'
import { useI18n } from '../../i18n/I18nContext'
import { getOnboarding } from './api'
import type { OnboardingStep } from './api'
import './ai.css'

/**
 * The getting-started card.
 *
 * Progress is counted from the steps themselves rather than from a total the
 * response might also carry, so the checklist and its counter cannot disagree.
 * A failed or empty read renders nothing: an onboarding hint is not worth an
 * error state on the dashboard.
 */
export function OnboardingChecklist({ organizationId }: { organizationId: string }) {
  const { m } = useI18n()
  const load = useCallback((signal: AbortSignal) => getOnboarding(organizationId, signal), [organizationId])
  const resource = useApiResource(`ai-onboarding:${organizationId}`, load)

  if (resource.state.status !== 'ready') return null
  const steps = resource.state.data.steps
  if (steps.length === 0) return null

  const done = steps.filter((step) => step.completed).length
  const allDone = done === steps.length

  return (
    <section className="panel ai-onboarding" aria-labelledby="ai-onboarding-title">
      <div className="panel-heading">
        <div>
          <h2 id="ai-onboarding-title">{m.ai.onboarding.title}</h2>
          <p>{m.ai.onboarding.subtitle}</p>
        </div>
        <span className={`ai-progress ${allDone ? 'ai-progress-done' : ''}`}>
          {m.ai.onboarding.progress(done, steps.length)}
        </span>
      </div>
      <progress
        className="ai-progress-bar"
        value={done}
        max={steps.length}
        aria-label={m.ai.onboarding.progress(done, steps.length)}
      />
      <ol className="ai-checklist">
        {steps.map((step) => (
          <ChecklistRow key={step.key} step={step} />
        ))}
      </ol>
    </section>
  )
}

function ChecklistRow({ step }: { step: OnboardingStep }) {
  const { m } = useI18n()
  // The contract does not define step copy, so a server label wins and the key
  // is the last resort; nothing is invented client-side.
  const label = step.label ?? step.key

  return (
    <li className={`ai-checklist-row ${step.completed ? 'done' : ''}`}>
      <span className="ai-check" aria-hidden="true">
        {step.completed && <Icon name="check" size={14} />}
      </span>
      <span className="ai-checklist-label">{label}</span>
      <span className="visually-hidden">
        {step.completed ? m.ai.onboarding.done : m.ai.onboarding.todo}
      </span>
    </li>
  )
}
