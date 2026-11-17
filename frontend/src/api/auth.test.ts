import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { forgotPassword, login, logout, register, resendVerification, resetPassword, verifyEmail } from './auth'
import { setCsrfToken, setSessionRefresher } from './client'
import { ApiError } from './errors'

const fetchMock = vi.fn<typeof fetch>()

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function noContent(): Response {
  return new Response(null, { status: 204 })
}

beforeEach(() => {
  vi.stubGlobal('fetch', fetchMock)
  fetchMock.mockReset()
  // Module state is shared by the file: every test starts without a token.
  setCsrfToken(null)
  setSessionRefresher(null)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('login', () => {
  it('posts the credentials as JSON and returns the server session', async () => {
    fetchMock.mockResolvedValue(jsonResponse({
      authenticated: true,
      user: { id: 'principal-1', email: 'owner@example.test', displayName: 'Owner' },
      csrfToken: 'csrf-1',
    }))

    await expect(login('owner@example.test', 'Correct-Horse-9')).resolves.toEqual({
      status: 'authenticated',
      user: { id: 'principal-1', email: 'owner@example.test', displayName: 'Owner' },
      csrfToken: 'csrf-1',
    })

    const [path, init] = fetchMock.mock.calls[0]
    expect(path).toBe('/api/auth/login')
    expect(init?.method).toBe('POST')
    expect(JSON.parse(String(init?.body))).toEqual({ email: 'owner@example.test', password: 'Correct-Horse-9' })
  })

  it('rejects a 200 that is not a usable session instead of reporting a sign-in', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ authenticated: true, user: null, csrfToken: null }))

    await expect(login('owner@example.test', 'Correct-Horse-9')).rejects.toMatchObject({ kind: 'parse' })
  })

  it('keeps the server code so the screen can ask for a resend', async () => {
    fetchMock.mockResolvedValue(jsonResponse({
      error: { code: 'email_not_verified', message: 'Confirm your email address before signing in.' },
    }, 403))

    const error = await login('owner@example.test', 'Correct-Horse-9').catch((failure: unknown) => failure)
    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ status: 403, code: 'email_not_verified' })
  })

  it('keeps the per-field messages of a rejected registration', async () => {
    fetchMock.mockResolvedValue(jsonResponse({
      error: {
        code: 'validation_failed',
        message: 'The request could not be validated.',
        fields: { password: ['This value is too short.'], organizationName: ['This value is too short.'] },
      },
    }, 422))

    const error = await register({
      email: 'owner@example.test',
      password: 'short',
      displayName: 'Owner',
      organizationName: 'A',
    }).catch((failure: unknown) => failure)

    expect(error).toMatchObject({
      status: 422,
      code: 'validation_failed',
      fields: { password: ['This value is too short.'], organizationName: ['This value is too short.'] },
    })
  })
})

describe('the remaining auth endpoints', () => {
  it('sends the documented method, path and body for each call', async () => {
    fetchMock.mockResolvedValue(noContent())

    await verifyEmail('verify-token', 'New-Horse-42')
    await resendVerification('owner@example.test')
    await forgotPassword('owner@example.test')
    await resetPassword('reset-token', 'New-Horse-42')
    await logout()

    const calls = fetchMock.mock.calls.map(([path, init]) => ({
      path,
      method: init?.method,
      body: init?.body === undefined ? null : JSON.parse(String(init.body)),
    }))

    expect(calls).toEqual([
      { path: '/api/auth/verify-email', method: 'POST', body: { token: 'verify-token', password: 'New-Horse-42' } },
      { path: '/api/auth/resend-verification', method: 'POST', body: { email: 'owner@example.test' } },
      { path: '/api/auth/forgot-password', method: 'POST', body: { email: 'owner@example.test' } },
      { path: '/api/auth/reset-password', method: 'POST', body: { token: 'reset-token', password: 'New-Horse-42' } },
      { path: '/api/auth/logout', method: 'POST', body: null },
    ])
  })

  it('registers with the four fields the API expects', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ status: 'verification_sent' }, 201))

    await register({
      email: 'owner@example.test',
      password: 'Correct-Horse-9',
      displayName: 'Amina Owner',
      organizationName: 'Northwind',
    })

    const [path, init] = fetchMock.mock.calls[0]
    expect(path).toBe('/api/auth/register')
    expect(JSON.parse(String(init?.body))).toEqual({
      email: 'owner@example.test',
      password: 'Correct-Horse-9',
      displayName: 'Amina Owner',
      organizationName: 'Northwind',
    })
  })

  it('accepts a 202 answer without a body', async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 202 }))

    await expect(forgotPassword('owner@example.test')).resolves.toBeUndefined()
  })

  it('carries the CSRF token on sign-out', async () => {
    setCsrfToken('csrf-1')
    fetchMock.mockResolvedValue(noContent())

    await logout()

    const [, init] = fetchMock.mock.calls[0]
    expect((init?.headers as Record<string, string>)['X-CSRF-Token']).toBe('csrf-1')
  })
})
