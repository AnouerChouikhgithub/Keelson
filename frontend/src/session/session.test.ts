import { describe, expect, it } from 'vitest'
import { parseLogoutTarget, parseSessionPayload } from './session'

const token = 'csrf-token-value'

describe('parseLogoutTarget', () => {
  it('accepts the same-origin logout endpoint', () => {
    expect(parseLogoutTarget({ url: '/logout', csrfToken: token })).toEqual({
      url: '/logout',
      csrfToken: token,
    })
  })

  it.each([
    ['protocol-relative host', '//evil.example/logout'],
    ['backslash-normalized host', '/\\evil.example/logout'],
    ['absolute http URL', 'http://evil.example/logout'],
    ['absolute https URL', 'https://evil.example/logout'],
    ['query string', '/logout?next=https://evil.example'],
    ['fragment', '/logout#https://evil.example'],
    ['trailing slash', '/logout/'],
    ['different case', '/Logout'],
    ['different path', '/api/logout'],
    ['plain path without a leading slash', 'logout'],
    ['empty string', ''],
  ])('rejects a %s target', (_label, url) => {
    expect(parseLogoutTarget({ url, csrfToken: token })).toBeNull()
  })

  it('rejects a missing or empty CSRF token', () => {
    expect(parseLogoutTarget({ url: '/logout' })).toBeNull()
    expect(parseLogoutTarget({ url: '/logout', csrfToken: '' })).toBeNull()
    expect(parseLogoutTarget({ url: '/logout', csrfToken: 42 })).toBeNull()
  })

  it('rejects a non-object payload', () => {
    expect(parseLogoutTarget(null)).toBeNull()
    expect(parseLogoutTarget('/logout')).toBeNull()
  })
})

describe('parseSessionPayload', () => {
  it('reports an anonymous session without a logout target', () => {
    expect(parseSessionPayload({ authenticated: false, user: null, logout: null })).toEqual({
      status: 'anonymous',
    })
  })

  it('accepts an authenticated session with the expected fields', () => {
    expect(
      parseSessionPayload({
        authenticated: true,
        user: { id: 'principal-1', email: 'owner@example.test', displayName: 'Owner' },
        logout: { url: '/logout', csrfToken: token },
      }),
    ).toEqual({
      status: 'authenticated',
      user: { id: 'principal-1', email: 'owner@example.test', displayName: 'Owner' },
      logout: { url: '/logout', csrfToken: token },
    })
  })

  it('treats an authenticated session with a hostile logout target as unusable', () => {
    const payload = {
      authenticated: true,
      user: { id: 'principal-1', email: null, displayName: null },
      logout: { url: '//evil.example/logout', csrfToken: token },
    }
    // Null (not authenticated) so the UI never offers a sign-out that would
    // send the CSRF token to another origin.
    expect(parseSessionPayload(payload)).toBeNull()
  })

  it('rejects unknown shapes instead of guessing', () => {
    expect(parseSessionPayload(null)).toBeNull()
    expect(parseSessionPayload({})).toBeNull()
    expect(parseSessionPayload({ authenticated: true, user: null })).toBeNull()
    expect(parseSessionPayload({ authenticated: true, user: { id: '' } })).toBeNull()
  })
})
