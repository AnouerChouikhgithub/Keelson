import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { query, request, setCsrfToken, setSessionRefresher } from './client'
import { ApiError } from './errors'

const fetchMock = vi.fn<typeof fetch>()

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

beforeEach(() => {
  vi.stubGlobal('fetch', fetchMock)
  fetchMock.mockReset()
  // The token and the refresher are module state shared by the file.
  setCsrfToken(null)
  setSessionRefresher(null)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('request', () => {
  it('returns the parsed JSON body', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ items: [{ id: 'a' }] }))

    await expect(request<{ items: { id: string }[] }>('/api/x')).resolves.toEqual({ items: [{ id: 'a' }] })
  })

  it('sends same-origin credentials and asks for no caching', async () => {
    fetchMock.mockResolvedValue(jsonResponse({}))

    await request('/api/x', { method: 'POST', body: { name: 'A' } })

    const [path, init] = fetchMock.mock.calls[0]
    expect(path).toBe('/api/x')
    expect(init?.credentials).toBe('same-origin')
    expect(init?.cache).toBe('no-store')
    expect(init?.method).toBe('POST')
    expect(init?.body).toBe(JSON.stringify({ name: 'A' }))
    expect((init?.headers as Record<string, string>)['Content-Type']).toBe('application/json')
  })

  it('omits a JSON content type and body on GET', async () => {
    fetchMock.mockResolvedValue(jsonResponse({}))

    await request('/api/x')

    const [, init] = fetchMock.mock.calls[0]
    expect(init?.body).toBeUndefined()
    expect((init?.headers as Record<string, string>)['Content-Type']).toBeUndefined()
  })

  it('resolves a 204 with no body instead of failing to parse it', async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))

    await expect(request<void>('/api/x', { method: 'DELETE' })).resolves.toBeUndefined()
  })

  it('keeps the server message for a validation failure', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ error: { message: 'Customer name must contain 1 to 160 characters.' } }, 422))

    await expect(request('/api/x', { method: 'POST', body: {} })).rejects.toMatchObject({
      name: 'ApiError',
      kind: 'http',
      status: 422,
      detail: 'Customer name must contain 1 to 160 characters.',
    })
  })

  it('survives a non-JSON error response', async () => {
    fetchMock.mockResolvedValue(new Response('<html>Bad gateway</html>', {
      status: 502,
      headers: { 'Content-Type': 'text/html' },
    }))

    const error = await request('/api/x').catch((failure: unknown) => failure)
    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ kind: 'http', status: 502, detail: null })
  })

  it('reports a network failure without leaking internals', async () => {
    fetchMock.mockRejectedValue(new TypeError('fetch failed'))

    await expect(request('/api/x')).rejects.toMatchObject({ kind: 'network', status: null })
  })

  it('reports a request that exceeds its deadline as a timeout, not a hang', async () => {
    fetchMock.mockImplementation((_input, init) => new Promise((_resolve, reject) => {
      init?.signal?.addEventListener('abort', () => reject(init.signal?.reason))
    }))

    await expect(request('/api/x', { timeoutMs: 1 })).rejects.toMatchObject({ kind: 'timeout' })
  })

  it('rethrows an abort so callers can ignore a superseded request', async () => {
    fetchMock.mockRejectedValue(new DOMException('The operation was aborted.', 'AbortError'))

    const error = await request('/api/x').catch((failure: unknown) => failure)
    expect(error).toBeInstanceOf(DOMException)
    expect((error as DOMException).name).toBe('AbortError')
  })

  it('reports an HTML success body as a misrouted request, not an ended session', async () => {
    // The API answers JSON on every route, so HTML here means a proxy or the SPA
    // fallback served the call and it never reached a controller.
    fetchMock.mockResolvedValue(new Response('<html><body>App</body></html>', {
      status: 200,
      headers: { 'Content-Type': 'text/html; charset=utf-8' },
    }))

    await expect(request('/api/session')).rejects.toMatchObject({ kind: 'http', status: 200 })
  })

  it('rejects a success body that is not JSON', async () => {
    fetchMock.mockResolvedValue(new Response('not json', { status: 200 }))

    await expect(request('/api/x')).rejects.toMatchObject({ kind: 'parse' })
  })
})

describe('CSRF token', () => {
  it('sends the session token on unsafe methods only', async () => {
    setCsrfToken('csrf-1')
    // A fresh Response per call: a body can only be read once.
    fetchMock.mockImplementation(async () => jsonResponse({}))

    await request('/api/x', { method: 'POST', body: {} })
    await request('/api/x', { method: 'DELETE' })
    await request('/api/x')

    const headerOf = (call: number) => (fetchMock.mock.calls[call][1]?.headers as Record<string, string>)['X-CSRF-Token']
    expect(headerOf(0)).toBe('csrf-1')
    expect(headerOf(1)).toBe('csrf-1')
    expect(headerOf(2)).toBeUndefined()
  })

  it('omits the header while no token is known', async () => {
    fetchMock.mockResolvedValue(jsonResponse({}))

    await request('/api/x', { method: 'PATCH', body: { name: 'A' } })

    const [, init] = fetchMock.mock.calls[0]
    expect((init?.headers as Record<string, string>)['X-CSRF-Token']).toBeUndefined()
  })

  it('re-reads the session once and replays the request after csrf_invalid', async () => {
    setCsrfToken('stale')
    const refresher = vi.fn(async () => {
      setCsrfToken('fresh')
      return 'fresh'
    })
    setSessionRefresher(refresher)
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ error: { code: 'csrf_invalid', message: 'A valid CSRF token is required.' } }, 403))
      .mockResolvedValueOnce(jsonResponse({ ok: true }))

    await expect(request('/api/x', { method: 'POST', body: {} })).resolves.toEqual({ ok: true })

    expect(refresher).toHaveBeenCalledTimes(1)
    expect(fetchMock).toHaveBeenCalledTimes(2)
    const [, retryInit] = fetchMock.mock.calls[1]
    expect((retryInit?.headers as Record<string, string>)['X-CSRF-Token']).toBe('fresh')
    expect(JSON.parse(String(retryInit?.body))).toEqual({})
  })

  it('retries at most once when the replay is refused too', async () => {
    const refresher = vi.fn(async () => 'fresh')
    setSessionRefresher(refresher)
    fetchMock.mockImplementation(async () => jsonResponse({ error: { code: 'csrf_invalid', message: 'A valid CSRF token is required.' } }, 403))

    await expect(request('/api/x', { method: 'POST', body: {} })).rejects.toMatchObject({
      kind: 'http',
      status: 403,
      code: 'csrf_invalid',
    })

    expect(refresher).toHaveBeenCalledTimes(1)
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('does not retry a safe request marked csrf_invalid', async () => {
    const refresher = vi.fn(async () => 'fresh')
    setSessionRefresher(refresher)
    fetchMock.mockImplementation(async () => jsonResponse({ error: { code: 'csrf_invalid', message: 'refused' } }, 403))

    await expect(request('/api/x')).rejects.toMatchObject({ status: 403 })

    expect(refresher).not.toHaveBeenCalled()
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('does not retry when the session cannot be re-read', async () => {
    setSessionRefresher(async () => null)
    fetchMock.mockResolvedValue(jsonResponse({ error: { code: 'csrf_invalid', message: 'refused' } }, 403))

    await expect(request('/api/x', { method: 'POST', body: {} })).rejects.toMatchObject({ status: 403 })

    expect(fetchMock).toHaveBeenCalledTimes(1)
  })
})

describe('query', () => {
  it('skips empty values and encodes the rest', () => {
    expect(query({ status: 'draft', cursor: null, limit: 100, empty: '' })).toBe('?status=draft&limit=100')
  })

  it('returns an empty string when nothing is set', () => {
    expect(query({ cursor: null })).toBe('')
  })
})
