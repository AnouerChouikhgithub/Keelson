import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { en } from '../i18n/en'
import { I18nProvider } from '../i18n/I18nProvider'
import { ToastProvider } from './Toast'
import { useToast } from './ToastContext'
import type { ToastTone } from './ToastContext'

/** One button per tone so the test drives the public API, not internals. */
function Trigger({ tone, message }: { tone: ToastTone; message: string }) {
  const toast = useToast()
  return (
    <button type="button" onClick={() => toast.show(message, tone)}>
      show {tone}
    </button>
  )
}

function renderToasts() {
  render(
    <I18nProvider>
      <ToastProvider>
        <Trigger tone="success" message="Invoice saved" />
        <Trigger tone="error" message="Could not save" />
      </ToastProvider>
    </I18nProvider>,
  )
  return userEvent.setup()
}

describe('Toast', () => {
  it('announces messages in a polite live region without taking focus', async () => {
    const user = renderToasts()
    await user.click(screen.getByRole('button', { name: 'show success' }))

    const region = screen.getByRole('region', { name: en.toast.region })
    expect(region).toHaveAttribute('aria-live', 'polite')
    expect(screen.getByText('Invoice saved')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'show success' })).toHaveFocus()
  })

  it('dismisses one message with its own button and leaves the others', async () => {
    const user = renderToasts()
    await user.click(screen.getByRole('button', { name: 'show success' }))
    await user.click(screen.getByRole('button', { name: 'show error' }))

    await user.click(screen.getAllByRole('button', { name: en.toast.dismiss })[0])

    expect(screen.queryByText('Invoice saved')).not.toBeInTheDocument()
    expect(screen.getByText('Could not save')).toBeInTheDocument()
  })

  it('keeps at most four messages on screen', async () => {
    const user = renderToasts()
    for (let index = 0; index < 6; index += 1) {
      await user.click(screen.getByRole('button', { name: 'show success' }))
    }

    expect(screen.getAllByRole('button', { name: en.toast.dismiss })).toHaveLength(4)
  })
})
