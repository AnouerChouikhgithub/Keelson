import { useId, useState } from 'react'
import type { FormEvent } from 'react'
import { useI18n } from '../i18n/I18nContext'
import { Button } from './Button'
import { Icon } from './Icon'
import { OverlaySurface } from './OverlaySurface'

/**
 * Confirmation for a destructive action.
 *
 * `confirmPhrase` turns it into a typed-name confirmation: the confirm button
 * stays disabled until the exact text is entered. The caller performs the
 * request and passes `pending`, so the dialog never closes on a failure.
 */
export function ConfirmDialog({
  open,
  title,
  body,
  confirmLabel,
  onConfirm,
  onClose,
  pending = false,
  tone = 'danger',
  confirmPhrase,
  phraseLabel,
  phraseMismatch,
}: {
  open: boolean
  title: string
  body: string
  confirmLabel: string
  onConfirm: () => void
  onClose: () => void
  pending?: boolean
  tone?: 'danger' | 'primary'
  confirmPhrase?: string
  phraseLabel?: string
  phraseMismatch?: string
}) {
  const { m } = useI18n()
  const [typed, setTyped] = useState('')
  const titleId = useId()
  const bodyId = useId()
  const phraseId = useId()

  if (!open) return null
  const matches = confirmPhrase === undefined || typed.trim() === confirmPhrase

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (matches && !pending) onConfirm()
  }

  return (
    <OverlaySurface
      variant="dialog"
      closeLabel={m.common.close}
      onClose={onClose}
      labelledBy={titleId}
      describedBy={bodyId}
    >
      <form onSubmit={submit}>
        <div className="overlay-heading">
          <div>
            <h2 id={titleId}>{title}</h2>
            <p id={bodyId}>{body}</p>
          </div>
          <button type="button" className="icon-button" aria-label={m.common.close} onClick={onClose}>
            <Icon name="close" />
          </button>
        </div>

        {confirmPhrase !== undefined && (
          <div className="field">
            <label htmlFor={phraseId}>{phraseLabel ?? confirmPhrase}</label>
            <input
              id={phraseId}
              value={typed}
              onChange={(event) => setTyped(event.target.value)}
              autoComplete="off"
              aria-invalid={typed !== '' && !matches}
              aria-describedby={typed !== '' && !matches ? `${phraseId}-error` : undefined}
            />
            {typed !== '' && !matches && (
              <small className="field-error" id={`${phraseId}-error`}>{phraseMismatch}</small>
            )}
          </div>
        )}

        <div className="confirm-actions">
          <Button variant="ghost" onClick={onClose} disabled={pending}>{m.common.cancel}</Button>
          <Button
            variant={tone === 'danger' ? 'danger' : 'primary'}
            type="submit"
            loading={pending}
            loadingLabel={m.common.saving}
            disabled={!matches}
          >
            {confirmLabel}
          </Button>
        </div>
      </form>
    </OverlaySurface>
  )
}
