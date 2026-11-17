import type { ServerSession } from './session'

/**
 * Cross-tab session signalling.
 *
 * The session itself lives on the server; tabs only tell each other that it may
 * have changed and then each re-reads `GET /api/session`. A tab never trusts a
 * message as authentication state — a message is a hint, the server is the
 * answer. That keeps a stale or forged message from signing anyone in.
 *
 * `BroadcastChannel` is the fast path. Where it is unavailable (older Safari,
 * some embeddings) a `localStorage` write falls back to the `storage` event,
 * which fires in the other tabs only. Both carry the same small JSON payload, so
 * one decoder covers them.
 */
export type SessionSignal = 'signed-in' | 'signed-out' | 'session-changed'

const CHANNEL_NAME = 'keelson.session'
const STORAGE_KEY = 'keelson.session.signal'

/**
 * What a session means for cached tenant data.
 *
 * `unknown` is a read that could not be confirmed; it is never equal to a real
 * identity, so a confirmed answer after a failure refreshes the caches.
 */
export function sessionIdentity(session: ServerSession | null): string {
  if (session === null) return 'unknown'
  return session.status === 'anonymous' ? 'anonymous' : `user:${session.user.id}`
}

/**
 * Whether cached tenant reads must be dropped.
 *
 * A failed re-read (`next === null`) changes nothing: a transient network error
 * is not an account switch, and clearing the screen on one would be worse than
 * keeping data the server already authorised. Anything confirmed that describes
 * a different principal — including signed in to signed out — invalidates.
 */
export function shouldInvalidate(previous: ServerSession | null, next: ServerSession | null): boolean {
  if (next === null) return false
  if (previous === null) return true
  return sessionIdentity(previous) !== sessionIdentity(next)
}

export function encodeSignal(signal: SessionSignal, at: number = Date.now()): string {
  return JSON.stringify({ signal, at })
}

/** Accepts the JSON string used by the storage fallback and the object form of BroadcastChannel. */
export function decodeSignal(raw: unknown): SessionSignal | null {
  let value: unknown = raw
  if (typeof raw === 'string') {
    try {
      value = JSON.parse(raw)
    } catch {
      return null
    }
  }
  if (typeof value !== 'object' || value === null) return null
  const signal = (value as { signal?: unknown }).signal
  return signal === 'signed-in' || signal === 'signed-out' || signal === 'session-changed' ? signal : null
}

export type SessionBroadcaster = {
  post: (signal: SessionSignal) => void
  close: () => void
}

/**
 * Subscribes to other tabs' signals and returns a way to send one.
 *
 * Never throws: a browser that blocks storage in private mode simply gets a
 * no-op broadcast, which degrades to "this tab learns on its next focus" rather
 * than breaking sign-in.
 */
export function createSessionBroadcaster(onSignal: (signal: SessionSignal) => void): SessionBroadcaster {
  const BroadcastChannelCtor = globalThis.BroadcastChannel
  if (typeof BroadcastChannelCtor === 'function') {
    const channel = new BroadcastChannelCtor(CHANNEL_NAME)
    channel.onmessage = (event: MessageEvent) => {
      const signal = decodeSignal(event.data)
      if (signal !== null) onSignal(signal)
    }

    return {
      post: (signal) => {
        try {
          channel.postMessage(encodeSignal(signal))
        } catch {
          // A closed channel throws; losing one hint is harmless.
        }
      },
      close: () => {
        channel.close()
      },
    }
  }

  if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
    const onStorage = (event: StorageEvent) => {
      if (event.key !== STORAGE_KEY) return
      const signal = decodeSignal(event.newValue)
      if (signal !== null) onSignal(signal)
    }
    window.addEventListener('storage', onStorage)

    return {
      post: (signal) => {
        try {
          window.localStorage.setItem(STORAGE_KEY, encodeSignal(signal))
        } catch {
          // Storage disabled: the other tabs still revalidate on focus.
        }
      },
      close: () => window.removeEventListener('storage', onStorage),
    }
  }

  return { post: () => {}, close: () => {} }
}
