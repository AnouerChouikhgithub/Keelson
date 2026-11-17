import { useCallback, useState } from 'react'
import type { FormEvent } from 'react'
import { Field } from '../../components/Field'
import { isApiError } from '../../api/errors'
import { invalidateResource, useApiResource } from '../../api/useApiResource'
import { useMutation } from '../../api/useMutation'
import type { OrganizationRole } from '../../api/types'
import { useToast } from '../../components/ToastContext'
import { useI18n } from '../../i18n/I18nContext'
import { getAiSettings, updateAiSettings } from './api'
import type { AiSettings } from './api'
import { presentAiError } from './presentAiError'
import { canConfigureAi, useAiStatus } from './useAiStatus'
import './ai.css'

const MAX_BUDGET = 100_000_000

/**
 * The workspace's AI opt-in, for Owners and Admins only.
 *
 * It is gated on the *deployment* switch rather than the organization's opt-in:
 * hiding it when AI is off for the workspace would leave nobody able to turn it
 * back on. A member never sees it, and the server answers `404` for them anyway.
 */
export function AiSettingsSection({
  organizationId,
  role,
}: {
  organizationId: string
  role: OrganizationRole
}) {
  const status = useAiStatus(organizationId)
  const allowed = role === 'Owner' || role === 'Admin'
  if (!allowed || !canConfigureAi(status)) return null
  return <AiSettingsCard organizationId={organizationId} />
}

function AiSettingsCard({ organizationId }: { organizationId: string }) {
  const { m } = useI18n()
  const load = useCallback((signal: AbortSignal) => getAiSettings(organizationId, signal), [organizationId])
  const resource = useApiResource(`ai-settings:${organizationId}`, load)

  if (resource.state.status !== 'ready') return null

  return (
    <section className="panel settings-card ai-settings" aria-labelledby="ai-settings-title">
      <h2 id="ai-settings-title">{m.ai.settings.title}</h2>
      <p className="panel-help">{m.ai.settings.help}</p>
      <AiSettingsForm
        // Rebuild the draft when the workspace or the saved values change.
        key={`${organizationId}:${resource.state.data.enabled}:${resource.state.data.monthlyTokenBudget}`}
        organizationId={organizationId}
        settings={resource.state.data}
        onSaved={resource.reload}
      />
    </section>
  )
}

function AiSettingsForm({
  organizationId,
  settings,
  onSaved,
}: {
  organizationId: string
  settings: AiSettings
  onSaved: () => void
}) {
  const { m, number } = useI18n()
  const toast = useToast()
  const [enabled, setEnabled] = useState(settings.enabled)
  const [budget, setBudget] = useState(String(settings.monthlyTokenBudget))
  const [budgetError, setBudgetError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const save = useMutation(updateAiSettings, { announceFailure: false })

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const raw = budget.trim()
    const parsed = Number(raw)
    if (!/^\d+$/.test(raw) || !Number.isSafeInteger(parsed) || parsed > MAX_BUDGET) {
      setBudgetError(m.ai.settings.invalidBudget)
      return
    }
    setBudgetError(null)
    setFormError(null)

    const outcome = await save.run(organizationId, { enabled, monthlyTokenBudget: parsed })
    if (!outcome.ok) {
      const fields = isApiError(outcome.error) ? outcome.error.fields : null
      setBudgetError(fields?.monthlyTokenBudget?.[0] ?? null)
      setFormError(presentAiError(m, outcome.error))
      return
    }
    // The header button and the dashboard cards read the same status, so the
    // cached copy has to go or they would keep hiding after an opt-in.
    invalidateResource('ai-status')
    toast.success(m.ai.settings.saved)
    onSaved()
  }

  return (
    <form className="settings-form ai-settings-form" onSubmit={submit} noValidate>
      <div className="ai-settings-toggle">
        <label className="ai-checkbox" htmlFor="ai-enabled">
          <input
            id="ai-enabled"
            type="checkbox"
            checked={enabled}
            onChange={(event) => setEnabled(event.target.checked)}
          />
          <span>{m.ai.settings.enabled}</span>
        </label>
        <p className="ai-hint">{m.ai.settings.enabledHelp}</p>
      </div>

      <Field label={m.ai.settings.budget} hint={m.ai.settings.budgetHint} error={budgetError ?? undefined}>
        {(field) => (
          <input
            {...field}
            type="number"
            min={0}
            max={MAX_BUDGET}
            step={1}
            inputMode="numeric"
            value={budget}
            onChange={(event) => setBudget(event.target.value)}
          />
        )}
      </Field>

      <p className="ai-consent">{m.ai.settings.consent}</p>
      <p className="ai-budget-remaining">{m.ai.settings.remaining(number(settings.budgetRemainingTokens, 0))}</p>
      {formError !== null && <p className="ai-notice" role="alert">{formError}</p>}

      <div className="settings-form-actions">
        <button type="submit" className="button button-primary" disabled={save.pending} aria-busy={save.pending || undefined}>
          {save.pending ? m.ai.settings.saving : m.ai.settings.save}
        </button>
      </div>
    </form>
  )
}
