import { describe, expect, it } from 'vitest'
import { parseSessionPayload } from './session'

const token = 'csrf-token-value'

describe('parseSessionPayload', () => {
  it('reports an anonymous session without a user or token', () => {
    expect(parseSessionPayload({ authenticated: false, user: null, csrfToken: null })).toEqual({
      status: 'anonymous',
    })
  })

  it('accepts an authenticated session with its in-memory CSRF token', () => {
    expect(
      parseSessionPayload({
        authenticated: true,
        user: { id: 'principal-1', email: 'owner@example.test', displayName: 'Owner' },
        csrfToken: token,
      }),
    ).toEqual({
      status: 'authenticated',
      user: { id: 'principal-1', email: 'owner@example.test', displayName: 'Owner' },
      csrfToken: token,
    })
  })

  it('keeps a missing email and display name as null', () => {
    expect(
      parseSessionPayload({
        authenticated: true,
        user: { id: 'principal-1', email: null, displayName: null },
        csrfToken: token,
      }),
    ).toEqual({ status: 'authenticated', user: { id: 'principal-1', email: null, displayName: null }, csrfToken: token })
  })

  it.each([
    ['null', null],
    ['an empty string', ''],
    ['a number', 42],
    ['a missing field', undefined],
  ])('treats an authenticated payload with %s as its token as unusable', (_label, csrfToken) => {
    // Unusable, not signed in: without a token every save and delete would fail.
    expect(
      parseSessionPayload({
        authenticated: true,
        user: { id: 'principal-1', email: 'owner@example.test', displayName: null },
        csrfToken,
      }),
    ).toBeNull()
  })

  it('rejects unknown shapes instead of guessing', () => {
    expect(parseSessionPayload(null)).toBeNull()
    expect(parseSessionPayload({})).toBeNull()
    expect(parseSessionPayload({ authenticated: 'yes' })).toBeNull()
    expect(parseSessionPayload({ authenticated: true, user: null, csrfToken: token })).toBeNull()
    expect(parseSessionPayload({ authenticated: true, user: { id: '' }, csrfToken: token })).toBeNull()
    expect(parseSessionPayload({ authenticated: true, user: { id: 7 }, csrfToken: token })).toBeNull()
  })
})
