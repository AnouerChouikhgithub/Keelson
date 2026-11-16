import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { UserEvent } from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { en } from '../../i18n/en'
import { I18nProvider } from '../../i18n/I18nProvider'
import { anonymousSession, installFetch, requests } from '../../test/fetchMock'
import type { FetchRoute } from '../../test/fetchMock'
import { SignUpScreen } from './SignUpScreen'

function renderSignUp() {
  render(
    <I18nProvider>
      <MemoryRouter initialEntries={['/sign-up']}>
        <Routes>
          <Route path="/sign-up" element={<SignUpScreen />} />
          <Route path="/sign-in" element={<p>sign in</p>} />
        </Routes>
      </MemoryRouter>
    </I18nProvider>,
  )
  return userEvent.setup()
}

async function fill(user: UserEvent, email: string, organizationName: string) {
  await user.type(screen.getByLabelText(en.auth.fields.email), email)
  await user.type(screen.getByLabelText(en.auth.fields.password), 'correct-horse-battery')
  await user.type(screen.getByLabelText(en.auth.fields.displayName), 'Anouer')
  await user.type(screen.getByLabelText(en.auth.fields.organizationName), organizationName)
  await user.click(screen.getByRole('button', { name: en.auth.signUpSubmit }))
}

function stub(routes: FetchRoute[]) {
  return installFetch([anonymousSession, ...routes])
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('SignUpScreen', () => {
  it('confirms the verification email and posts the whole form', async () => {
    const fetchMock = stub([{ path: '/api/auth/register', method: 'POST', status: 201, json: { status: 'verification_sent' } }])
    const user = renderSignUp()

    await fill(user, 'new@example.test', 'Maison Jasmin')

    expect(await screen.findByText(en.auth.checkEmailTitle)).toBeInTheDocument()
    expect(screen.getByText(en.auth.checkEmailBody('new@example.test'))).toBeInTheDocument()
    expect(requests(fetchMock).find((call) => call.path.endsWith('/api/auth/register'))?.body).toEqual({
      email: 'new@example.test',
      password: 'correct-horse-battery',
      displayName: 'Anouer',
      organizationName: 'Maison Jasmin',
    })
  })

  it('localizes 422 field errors and focuses the first one', async () => {
    stub([
      {
        path: '/api/auth/register',
        method: 'POST',
        status: 422,
        json: {
          error: {
            code: 'validation_failed',
            message: 'Validation failed.',
            fields: {
              email: ['This value is not a valid email address.'],
              organizationName: ['This value should not be blank.'],
            },
          },
        },
      },
    ])
    const user = renderSignUp()

    await fill(user, 'new@example.test', 'Maison Jasmin')

    expect(await screen.findByText(en.auth.errors.invalidEmail)).toBeInTheDocument()
    expect(screen.getByText(en.auth.errors.organizationNameRule)).toBeInTheDocument()
    expect(screen.getByLabelText(en.auth.fields.email)).toHaveFocus()
  })

  it('reports throttling on the form rather than per field', async () => {
    stub([
      {
        path: '/api/auth/register',
        method: 'POST',
        status: 429,
        json: { error: { code: 'too_many_resend_attempts', message: 'Too many requests.' } },
      },
    ])
    const user = renderSignUp()

    await fill(user, 'new@example.test', 'Maison Jasmin')

    expect(await screen.findByRole('alert')).toHaveTextContent(en.auth.errors.tooManyAttempts)
  })
})
