import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { UserEvent } from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { en } from '../../i18n/en'
import { I18nProvider } from '../../i18n/I18nProvider'
import { AuthProvider } from '../../session/AuthProvider'
import { anonymousSession, installFetch, requests } from '../../test/fetchMock'
import type { FetchRoute } from '../../test/fetchMock'
import { SignInScreen } from './SignInScreen'

function renderSignIn() {
  render(
    <I18nProvider>
      <AuthProvider>
        <MemoryRouter initialEntries={['/sign-in']}>
          <Routes>
            <Route path="/sign-in" element={<SignInScreen />} />
            <Route path="/" element={<p>signed in</p>} />
          </Routes>
        </MemoryRouter>
      </AuthProvider>
    </I18nProvider>,
  )
  return userEvent.setup()
}

async function submit(user: UserEvent, email: string, password: string) {
  await user.type(screen.getByLabelText(en.auth.fields.email), email)
  await user.type(screen.getByLabelText(en.auth.fields.password), password)
  await user.click(screen.getByRole('button', { name: en.auth.signInSubmit }))
}

function stub(routes: FetchRoute[]) {
  return installFetch([anonymousSession, ...routes])
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('SignInScreen', () => {
  it('shows localized 422 field errors and focuses the first rejected input', async () => {
    const fetchMock = stub([
      {
        path: '/api/auth/login',
        method: 'POST',
        status: 422,
        json: {
          error: {
            code: 'validation_failed',
            message: 'This value is not a valid email address.',
            fields: { email: ['This value is not a valid email address.'], password: ['This value is too short.'] },
          },
        },
      },
    ])
    const user = renderSignIn()

    await submit(user, 'viewer@example.test', 'short')

    expect(await screen.findByText(en.auth.errors.invalidEmail)).toBeInTheDocument()
    expect(screen.getByText(en.auth.errors.passwordRule)).toBeInTheDocument()
    // The server's own English prose never reaches the screen.
    expect(screen.queryByText('This value is not a valid email address.')).not.toBeInTheDocument()
    expect(screen.getByLabelText(en.auth.fields.email)).toHaveFocus()
    expect(requests(fetchMock).find((call) => call.path.endsWith('/api/auth/login'))?.body).toEqual({
      email: 'viewer@example.test',
      password: 'short',
    })
  })

  it('reports a wrong password with its own sentence', async () => {
    stub([
      {
        path: '/api/auth/login',
        method: 'POST',
        status: 401,
        json: { error: { code: 'invalid_credentials', message: 'Invalid credentials.' } },
      },
    ])
    const user = renderSignIn()

    await submit(user, 'viewer@example.test', 'wrong-password')

    expect(await screen.findByRole('alert')).toHaveTextContent(en.auth.errors.invalidCredentials)
    expect(screen.queryByText('Invalid credentials.')).not.toBeInTheDocument()
  })

  it('explains a throttled attempt even when the server sends no code', async () => {
    stub([{ path: '/api/auth/login', method: 'POST', status: 429, json: { error: { message: 'Too many attempts.' } } }])
    const user = renderSignIn()

    await submit(user, 'viewer@example.test', 'wrong-password')

    expect(await screen.findByRole('alert')).toHaveTextContent(en.auth.errors.tooManyAttempts)
  })

  it('offers a resend for an unverified address and confirms it was sent', async () => {
    stub([
      {
        path: '/api/auth/login',
        method: 'POST',
        status: 403,
        json: { error: { code: 'email_not_verified', message: 'Your email address is not verified.' } },
      },
      { path: '/api/auth/resend-verification', method: 'POST', status: 202, json: {} },
    ])
    const user = renderSignIn()

    await submit(user, 'unverified@example.test', 'correct-horse')

    expect(await screen.findByRole('alert')).toHaveTextContent(en.auth.errors.emailNotVerified)
    await user.click(screen.getByRole('button', { name: en.auth.resend }))
    expect(await screen.findByRole('status')).toHaveTextContent(en.auth.resent)
  })
})
