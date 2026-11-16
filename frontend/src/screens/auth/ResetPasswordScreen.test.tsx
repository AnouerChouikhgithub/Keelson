import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { en } from '../../i18n/en'
import { I18nProvider } from '../../i18n/I18nProvider'
import { anonymousSession, installFetch, requests } from '../../test/fetchMock'
import type { FetchRoute } from '../../test/fetchMock'
import { ResetPasswordScreen } from './ResetPasswordScreen'

function renderReset(path = '/reset-password?token=reset-token') {
  const view = render(
    <I18nProvider>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/reset-password" element={<ResetPasswordScreen />} />
          <Route path="/sign-in" element={<p>sign in</p>} />
        </Routes>
      </MemoryRouter>
    </I18nProvider>,
  )
  return { ...view, user: userEvent.setup() }
}

function stub(routes: FetchRoute[]) {
  return installFetch([anonymousSession, ...routes])
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('ResetPasswordScreen', () => {
  it('rejects a mismatched confirmation locally, without sending it', async () => {
    const fetchMock = stub([])
    const { user } = renderReset()

    await user.type(screen.getByLabelText(en.auth.fields.password), 'correct-horse-battery')
    await user.type(screen.getByLabelText(en.auth.fields.confirmPassword), 'correct-horse-battery!')
    await user.click(screen.getByRole('button', { name: en.auth.resetSubmit }))

    expect(await screen.findByText(en.auth.errors.passwordMismatch)).toBeInTheDocument()
    expect(screen.getByLabelText(en.auth.fields.confirmPassword)).toHaveFocus()
    expect(requests(fetchMock).some((call) => call.path.includes('/api/auth/reset-password'))).toBe(false)
  })

  it('localizes a 422 on the new password', async () => {
    stub([
      {
        path: '/api/auth/reset-password',
        method: 'POST',
        status: 422,
        json: {
          error: {
            code: 'validation_failed',
            message: 'Validation failed.',
            fields: { password: ['This value is too short.'] },
          },
        },
      },
    ])
    const { user } = renderReset()

    await user.type(screen.getByLabelText(en.auth.fields.password), 'short')
    await user.type(screen.getByLabelText(en.auth.fields.confirmPassword), 'short')
    await user.click(screen.getByRole('button', { name: en.auth.resetSubmit }))

    // The hint and the error share one `aria-describedby` list, and the page
    // subtitle repeats the rule, so read the error paragraph itself.
    const password = screen.getByLabelText(en.auth.fields.password)
    await waitFor(() => expect(password).toHaveAttribute('aria-invalid', 'true'))
    const errorId = password.getAttribute('aria-describedby')?.split(' ').at(-1) ?? ''
    expect(document.getElementById(errorId)).toHaveTextContent(en.auth.errors.passwordRule)
    expect(password).toHaveFocus()
  })

  it('sends the token and the new password, then confirms', async () => {
    const fetchMock = stub([{ path: '/api/auth/reset-password', method: 'POST', status: 204, response: new Response(null, { status: 204 }) }])
    const { user } = renderReset()

    await user.type(screen.getByLabelText(en.auth.fields.password), 'correct-horse-battery')
    await user.type(screen.getByLabelText(en.auth.fields.confirmPassword), 'correct-horse-battery')
    await user.click(screen.getByRole('button', { name: en.auth.resetSubmit }))

    expect(await screen.findByText(en.auth.resetDoneTitle)).toBeInTheDocument()
    expect(requests(fetchMock).find((call) => call.path.includes('/api/auth/reset-password'))?.body).toEqual({
      token: 'reset-token',
      password: 'correct-horse-battery',
    })
  })

  it('asks for a new link when the URL carries no token', () => {
    stub([])
    renderReset('/reset-password')

    expect(screen.getByText(en.auth.resetMissingToken)).toBeInTheDocument()
    expect(screen.queryByLabelText(en.auth.fields.password)).not.toBeInTheDocument()
  })
})
