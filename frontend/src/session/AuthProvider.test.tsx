import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { request } from '../api/client'
import { clearApiCache } from '../api/useApiResource'
import { encodeSignal } from './sync'
import { useAuth } from './AuthContext'
import { AuthProvider } from './AuthProvider'

/**
 * The cache module is spied, not replaced: the provider must drop cached tenant
 * reads when the account behind the tab changes, and that is the assertion.
 */
vi.mock('../api/useApiResource', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api/useApiResource')>()
  return { ...actual, clearApiCache: vi.fn() }
})

type Payload = { authenticated: boolean; user?: { id: string; email: string; displayName: string }; csrfToken?: string }

class FakeChannel {
  static instances: FakeChannel[] = []
  onmessage: ((event: MessageEvent) => void) | null = null
  readonly sent: unknown[] = []
  readonly name: string

  constructor(name: string) {
    this.name = name
    FakeChannel.instances.push(this)
  }

  postMessage(data: unknown) {
    this.sent.push(data)
  }

  close() {
    // The provider closes its channel on unmount; nothing to release here.
  }

  emit(signal: string) {
    this.onmessage?.({ data: encodeSignal(signal as 'signed-in') } as MessageEvent)
  }
}

let sessionPayload: Payload
let organizationStatus: number

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function Harness() {
  const { status, user } = useAuth()
  return (
    <div>
      <span data-testid="status">{status}</span>
      <span data-testid="user">{user?.id ?? '-'}</span>
    </div>
  )
}

function renderProvider() {
  render(
    <AuthProvider>
      <Harness />
    </AuthProvider>,
  )
}

const signedIn = (id: string): Payload => ({
  authenticated: true,
  user: { id, email: `${id}@example.test`, displayName: id },
  csrfToken: `csrf-${id}`,
})

beforeEach(() => {
  FakeChannel.instances = []
  sessionPayload = signedIn('principal-a')
  organizationStatus = 200
  vi.stubGlobal('BroadcastChannel', FakeChannel)
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      const path = typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url
      if (path.includes('/api/session')) return jsonResponse(sessionPayload)
      return jsonResponse({ error: { code: 'unauthenticated', message: 'Authentication is required.' } }, organizationStatus)
    }),
  )
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.mocked(clearApiCache).mockClear()
})

describe('AuthProvider cross-tab session', () => {
  it('signs this tab out when another tab signs out', async () => {
    renderProvider()
    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('authenticated'))

    sessionPayload = { authenticated: false }
    FakeChannel.instances.at(-1)?.emit('signed-out')

    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('anonymous'))
    expect(screen.getByTestId('user')).toHaveTextContent('-')
    expect(clearApiCache).toHaveBeenCalled()
  })

  it('switches to the account another tab signed in as and drops stale tenant reads', async () => {
    renderProvider()
    await waitFor(() => expect(screen.getByTestId('user')).toHaveTextContent('principal-a'))

    sessionPayload = signedIn('principal-b')
    FakeChannel.instances.at(-1)?.emit('signed-in')

    await waitFor(() => expect(screen.getByTestId('user')).toHaveTextContent('principal-b'))
    expect(clearApiCache).toHaveBeenCalled()
  })

  it('keeps cached reads when the same account is re-confirmed', async () => {
    renderProvider()
    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('authenticated'))

    FakeChannel.instances.at(-1)?.emit('session-changed')

    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('authenticated'))
    expect(clearApiCache).not.toHaveBeenCalled()
  })

  it('does not answer a signal with a signal, so tabs cannot ping-pong', async () => {
    renderProvider()
    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('authenticated'))

    const channel = FakeChannel.instances.at(-1)
    channel?.emit('session-changed')

    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('authenticated'))
    expect(channel?.sent).toEqual([])
  })

  it('revalidates when the tab becomes active again', async () => {
    renderProvider()
    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('authenticated'))

    sessionPayload = { authenticated: false }
    window.dispatchEvent(new Event('focus'))

    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('anonymous'))
  })

  it('re-reads the session after a 401 instead of inventing a signed-out state', async () => {
    renderProvider()
    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('authenticated'))

    // The cookie is gone; the next API call says so and the provider asks the
    // session endpoint what that means.
    sessionPayload = { authenticated: false }
    organizationStatus = 401
    await expect(request('/api/organizations/x')).rejects.toMatchObject({ status: 401 })

    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('anonymous'))
  })
})
