import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ServerSession } from './session'
import { createSessionBroadcaster, decodeSignal, encodeSignal, sessionIdentity, shouldInvalidate } from './sync'

const authenticated = (id: string): ServerSession => ({
  status: 'authenticated',
  user: { id, email: `${id}@example.test`, displayName: id },
  csrfToken: `csrf-${id}`,
})
const anonymous: ServerSession = { status: 'anonymous' }

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('sessionIdentity', () => {
  it('distinguishes an unknown session from a confirmed anonymous one', () => {
    expect(sessionIdentity(null)).toBe('unknown')
    expect(sessionIdentity(anonymous)).toBe('anonymous')
    expect(sessionIdentity(authenticated('a'))).toBe('user:a')
  })
})

describe('shouldInvalidate', () => {
  it('drops tenant caches when the principal changes', () => {
    expect(shouldInvalidate(authenticated('a'), authenticated('b'))).toBe(true)
  })

  it('drops them when the tab signs in or out', () => {
    expect(shouldInvalidate(anonymous, authenticated('a'))).toBe(true)
    expect(shouldInvalidate(authenticated('a'), anonymous)).toBe(true)
  })

  it('keeps them for the same principal, even with a rotated token', () => {
    expect(shouldInvalidate(authenticated('a'), authenticated('a'))).toBe(false)
  })

  it('treats a failed re-read as no news, not as a sign-out', () => {
    // A network hiccup must not blank a screen the server already authorised.
    expect(shouldInvalidate(authenticated('a'), null)).toBe(false)
  })

  it('invalidates on the first confirmed answer after an unknown one', () => {
    expect(shouldInvalidate(null, anonymous)).toBe(true)
  })
})

describe('signals', () => {
  it('round-trips every signal type', () => {
    for (const signal of ['signed-in', 'signed-out', 'session-changed'] as const) {
      expect(decodeSignal(encodeSignal(signal, 1))).toBe(signal)
      expect(decodeSignal({ signal, at: 1 })).toBe(signal)
    }
  })

  it('rejects anything that is not a known signal', () => {
    expect(decodeSignal('not json')).toBeNull()
    expect(decodeSignal('null')).toBeNull()
    expect(decodeSignal({ signal: 'signed-in-as-admin' })).toBeNull()
    expect(decodeSignal(42)).toBeNull()
    expect(decodeSignal(undefined)).toBeNull()
  })
})

describe('createSessionBroadcaster', () => {
  it('is a no-op when the browser has neither channel nor window', () => {
    const received: string[] = []
    const broadcaster = createSessionBroadcaster((signal) => received.push(signal))

    expect(() => broadcaster.post('signed-out')).not.toThrow()
    expect(() => broadcaster.close()).not.toThrow()
    expect(received).toEqual([])
  })

  it('posts and receives over BroadcastChannel', () => {
    const instances: FakeChannel[] = []
    class FakeChannel {
      onmessage: ((event: MessageEvent) => void) | null = null
      readonly sent: unknown[] = []
      readonly name: string
      closed = false

      constructor(name: string) {
        this.name = name
        instances.push(this)
      }
      postMessage(data: unknown) {
        this.sent.push(data)
      }
      close() {
        this.closed = true
      }
    }
    vi.stubGlobal('BroadcastChannel', FakeChannel)

    const received: string[] = []
    const broadcaster = createSessionBroadcaster((signal) => received.push(signal))

    expect(instances).toHaveLength(1)
    expect(instances[0].name).toBe('keelson.session')
    broadcaster.post('signed-out')
    expect(instances[0].sent).toHaveLength(1)

    // The other tab's message arrives as an event; an unreadable one is ignored.
    instances[0].onmessage?.({ data: encodeSignal('signed-in') } as MessageEvent)
    instances[0].onmessage?.({ data: 'garbage' } as MessageEvent)
    expect(received).toEqual(['signed-in'])

    broadcaster.close()
    expect(instances[0].closed).toBe(true)
  })

  it('falls back to a storage event when BroadcastChannel is missing', () => {
    const listeners: ((event: StorageEvent) => void)[] = []
    const stored = new Map<string, string>()
    const fakeWindow = {
      addEventListener: (type: string, listener: (event: StorageEvent) => void) => {
        if (type === 'storage') listeners.push(listener)
      },
      removeEventListener: (_type: string, listener: (event: StorageEvent) => void) => {
        const index = listeners.indexOf(listener)
        if (index >= 0) listeners.splice(index, 1)
      },
      localStorage: {
        setItem: (key: string, value: string) => stored.set(key, value),
      },
    }
    vi.stubGlobal('BroadcastChannel', undefined)
    vi.stubGlobal('window', fakeWindow)

    const received: string[] = []
    const broadcaster = createSessionBroadcaster((signal) => received.push(signal))

    broadcaster.post('signed-in')
    expect(stored.get('keelson.session.signal')).toBeDefined()

    // Another key and a malformed value are ignored; the real signal is not.
    listeners.forEach((listener) => listener({ key: 'theme', newValue: encodeSignal('signed-out') } as StorageEvent))
    listeners.forEach((listener) => listener({ key: 'keelson.session.signal', newValue: 'garbage' } as StorageEvent))
    listeners.forEach((listener) =>
      listener({ key: 'keelson.session.signal', newValue: encodeSignal('signed-out') } as StorageEvent),
    )
    expect(received).toEqual(['signed-out'])

    broadcaster.close()
    expect(listeners).toHaveLength(0)
  })
})
