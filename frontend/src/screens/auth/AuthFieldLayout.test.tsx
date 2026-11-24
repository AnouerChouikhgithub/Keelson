import { render } from '@testing-library/react'
import type { ReactElement } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider'
import { AuthProvider } from '../../session/AuthProvider'
import { ToastProvider } from '../../components/Toast'
import { anonymousSession, installFetch } from '../../test/fetchMock'
import AcceptInvitation from '../AcceptInvitation'
import { ForgotPasswordScreen } from './ForgotPasswordScreen'
import { ResetPasswordScreen } from './ResetPasswordScreen'
import { SignInScreen } from './SignInScreen'
import { SignUpScreen } from './SignUpScreen'

/**
 * The public screens must keep the shared `.field` layout.
 *
 * `.field` (label above the control, control at 100% of the card) lives in
 * `components/Field.css`, which only `Field.tsx` imported. These screens build
 * their fields by hand, so the classes arrived with no rules behind them: the
 * label and the input collapsed onto one line and the input fell back to its
 * intrinsic width. `AuthLayout` now imports that stylesheet, and this test fails
 * if the import disappears again.
 *
 * jsdom has no layout engine — every box measures zero — so the assertion is on
 * the cascade that produces the layout: the computed `display` of the field and
 * the computed `width` of its control. `display: grid` with the label first is
 * what puts the label above the input; `100%` is what fills the card instead of
 * the browser's ~180px intrinsic input width.
 */

const preview = {
  organizationName: 'Northwind',
  role: 'Member',
  email: 'invited@example.test',
  expired: false,
  accepted: false,
}

function renderScreen(element: ReactElement, path: string, url: string) {
  installFetch([
    anonymousSession,
    { path: '/api/invitations/', method: 'GET', json: preview },
  ])

  return render(
    <I18nProvider>
      <ToastProvider>
        <AuthProvider>
          <MemoryRouter initialEntries={[url]}>
            <Routes>
              <Route path={path} element={element} />
            </Routes>
          </MemoryRouter>
        </AuthProvider>
      </ToastProvider>
    </I18nProvider>,
  )
}

afterEach(() => {
  vi.unstubAllGlobals()
})

function expectFieldLayout(container: HTMLElement) {
  const field = container.querySelector('.field')
  expect(field, 'the screen renders at least one .field').not.toBeNull()

  const label = field!.querySelector('label')
  const input = field!.querySelector('input')
  expect(label, '.field holds a label').not.toBeNull()
  expect(input, '.field holds an input').not.toBeNull()

  // Label first inside a grid: stacked above, never inline beside the control.
  expect(field!.firstElementChild).toBe(label)
  expect(getComputedStyle(field!).display).toBe('grid')

  // Full width of the card, not the browser's intrinsic input width.
  expect(getComputedStyle(input!).width).toBe('100%')
}

describe('public screens keep the shared field layout', () => {
  it('sign-in', () => {
    const { container } = renderScreen(<SignInScreen />, '/sign-in', '/sign-in')
    expectFieldLayout(container)
  })

  it('sign-up', () => {
    const { container } = renderScreen(<SignUpScreen />, '/sign-up', '/sign-up')
    expectFieldLayout(container)
  })

  it('forgot password', () => {
    const { container } = renderScreen(<ForgotPasswordScreen />, '/forgot-password', '/forgot-password')
    expectFieldLayout(container)
  })

  it('reset password', () => {
    const { container } = renderScreen(<ResetPasswordScreen />, '/reset-password', '/reset-password?token=abc')
    expectFieldLayout(container)
  })

  it('accept invitation', async () => {
    const { container, findByText } = renderScreen(
      <AcceptInvitation />,
      '/accept-invitation',
      '/accept-invitation?token=abc',
    )
    // The form only exists once the public preview resolved.
    await findByText(/Northwind/)
    expectFieldLayout(container)
  })
})
