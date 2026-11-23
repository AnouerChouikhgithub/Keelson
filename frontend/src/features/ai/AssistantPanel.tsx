import { useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { Icon } from '../../components/Icon'
import { OverlaySurface } from '../../components/OverlaySurface'
import { isAbortError } from '../../api/errors'
import { useI18n } from '../../i18n/I18nContext'
import { askGuide } from './api'
import type { AiGuideAnswer } from './api'
import { presentAiError } from './presentAiError'
import './ai.css'

/**
 * The guidance side panel.
 *
 * `OverlaySurface` supplies what the brief asks of any transient surface — a
 * modal drawer, a trapped focus, Escape to close and focus restored to the
 * button that opened it — so this component owns only the conversation. The
 * answer is rendered as a text node (never HTML, never markdown) and sits in a
 * polite live region, so a screen reader hears the new answer without the page
 * moving.
 */
export function AssistantPanel({ organizationId, onClose }: { organizationId: string; onClose: () => void }) {
  const { m, locale } = useI18n()
  const [question, setQuestion] = useState('')
  const [asked, setAsked] = useState<string | null>(null)
  const [answer, setAnswer] = useState<AiGuideAnswer | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [pending, setPending] = useState(false)
  // A double submit must not spend two requests; the ref is checked before React
  // has had a chance to re-render the disabled button.
  const inFlight = useRef(false)

  async function ask(text: string) {
    const trimmed = text.trim()
    if (trimmed === '' || inFlight.current) return
    inFlight.current = true
    setPending(true)
    setNotice(null)
    setAsked(trimmed)
    try {
      const next = await askGuide(trimmed, { organizationId, locale })
      setAnswer(next)
    } catch (caught) {
      if (isAbortError(caught)) return
      setAnswer(null)
      setNotice(presentAiError(m, caught))
    } finally {
      inFlight.current = false
      setPending(false)
    }
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    void ask(question)
  }

  return (
    <OverlaySurface
      variant="drawer"
      closeLabel={m.ai.assistant.close}
      onClose={onClose}
      label={m.ai.assistant.title}
    >
      <div className="ai-panel">
        <header className="ai-panel-header">
          <h2>{m.ai.assistant.title}</h2>
          <p>{m.ai.assistant.intro}</p>
        </header>

        <div className="ai-answer" aria-live="polite" aria-atomic="true">
          {pending && <p className="ai-pending">{m.ai.assistant.thinking}</p>}
          {!pending && notice !== null && <p className="ai-notice">{notice}</p>}
          {!pending && notice === null && answer !== null && (
            <div className="ai-answer-body">
              <p className="ai-generated-label">{m.ai.assistant.label}</p>
              <p className="ai-answer-text">{answer.answer}</p>
              {answer.sources.length > 0 && (
                <div className="ai-sources">
                  <p className="ai-sources-title">{m.ai.assistant.sources}</p>
                  {/* `id` is an internal documentation id, never a URL, so these
                      are text and not links: inventing an href would invent a route. */}
                  <ul>
                    {answer.sources.map((source) => (
                      <li key={source.id}>
                        <span>{source.title}</span>
                        <code>{source.id}</code>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
              <button
                type="button"
                className="button button-ghost button-sm"
                disabled={pending || asked === null}
                onClick={() => { if (asked !== null) void ask(asked) }}
              >
                <Icon name="sparkle" size={16} /> {m.ai.assistant.regenerate}
              </button>
            </div>
          )}
          {!pending && notice === null && answer === null && (
            <p className="ai-hint">{m.ai.assistant.empty}</p>
          )}
        </div>

        <form className="ai-composer" onSubmit={submit}>
          <label className="visually-hidden" htmlFor="ai-question">{m.ai.assistant.questionLabel}</label>
          <textarea
            id="ai-question"
            value={question}
            rows={3}
            maxLength={2000}
            placeholder={m.ai.assistant.placeholder}
            disabled={pending}
            onChange={(event) => setQuestion(event.target.value)}
          />
          <button
            type="submit"
            className="button button-primary"
            disabled={pending || question.trim() === ''}
            aria-busy={pending || undefined}
          >
            <Icon name="arrow" size={17} />
            {pending ? m.ai.assistant.thinking : m.ai.assistant.send}
          </button>
        </form>
      </div>
    </OverlaySurface>
  )
}
