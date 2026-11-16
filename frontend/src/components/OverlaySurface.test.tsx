import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it } from 'vitest'
import { OverlaySurface } from './OverlaySurface'

/**
 * jsdom reports every element with `offsetParent === null`, so the trap's
 * visibility filter leaves only the focused element visible to it. The checks
 * below therefore cover what the DOM can prove: focus moves inside, focus cannot
 * leak out, Escape closes, and focus returns to the opener.
 */
function Harness({ onClosed }: { onClosed?: () => void }) {
  const [open, setOpen] = useState(false)
  return (
    <div>
      <button type="button" onClick={() => setOpen(true)}>
        Open dialog
      </button>
      <button type="button">Outside</button>
      {open && (
        <OverlaySurface
          variant="dialog"
          closeLabel="Close panel"
          onClose={() => {
            setOpen(false)
            onClosed?.()
          }}
          labelledBy="overlay-title"
        >
          <h2 id="overlay-title">Invite a teammate</h2>
          <button type="button">First</button>
          <button type="button">Last</button>
        </OverlaySurface>
      )}
    </div>
  )
}

describe('OverlaySurface', () => {
  it('renders a modal dialog in a portal with focus inside it', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    await user.click(screen.getByRole('button', { name: 'Open dialog' }))

    const dialog = screen.getByRole('dialog', { name: 'Invite a teammate' })
    expect(dialog).toHaveAttribute('aria-modal', 'true')
    expect(dialog.contains(document.activeElement)).toBe(true)
    expect(screen.getByRole('button', { name: 'Close panel' })).toHaveAttribute('tabindex', '-1')
  })

  it('closes on Escape and returns focus to the element that opened it', async () => {
    const user = userEvent.setup()
    render(<Harness />)
    const opener = screen.getByRole('button', { name: 'Open dialog' })

    await user.click(opener)
    expect(screen.getByRole('dialog')).toBeInTheDocument()

    fireEvent.keyDown(document, { key: 'Escape' })

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(opener).toHaveFocus()
  })

  it('prevents Tab from moving focus outside the surface', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    await user.click(screen.getByRole('button', { name: 'Open dialog' }))
    screen.getByRole('button', { name: 'Outside' }).focus()

    const leaked = fireEvent.keyDown(document, { key: 'Tab' })

    // `fireEvent` answers false when a listener called preventDefault.
    expect(leaked).toBe(false)
    expect(screen.getByRole('dialog').contains(document.activeElement)).toBe(false)
  })

  it('closes from the scrim and locks page scrolling while open', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    await user.click(screen.getByRole('button', { name: 'Open dialog' }))
    expect(document.body.style.overflow).toBe('hidden')

    await user.click(screen.getByRole('button', { name: 'Close panel' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(document.body.style.overflow).toBe('')
  })
})
