import { useState } from 'react'
import { Icon } from '../../components/Icon'
import { useI18n } from '../../i18n/I18nContext'
import { AssistantPanel } from './AssistantPanel'
import { canUseAi, useAiStatus } from './useAiStatus'

/**
 * The header entry point, loaded as its own chunk.
 *
 * It owns the status read so nothing AI-related renders when the deployment has
 * AI off, the workspace has not opted in, or `/api/ai/status` failed: the brief
 * asks for absence, not an error. The trigger is the element focus returns to
 * when the panel closes, because the focus trap remembers the opener.
 */
export default function AssistantLauncher({ organizationId }: { organizationId: string }) {
  const { m } = useI18n()
  const status = useAiStatus(organizationId)
  const [open, setOpen] = useState(false)

  if (!canUseAi(status)) return null

  return (
    <>
      <button
        type="button"
        className="ai-trigger"
        aria-label={m.ai.assistant.open}
        aria-haspopup="dialog"
        aria-expanded={open}
        onClick={() => setOpen(true)}
      >
        <Icon name="sparkle" size={17} />
        <span>{m.ai.assistant.open}</span>
      </button>
      {open && <AssistantPanel onClose={() => setOpen(false)} />}
    </>
  )
}
