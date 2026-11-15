import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { query, request } from './client'
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

  it('reads a followed sign-in page as an ended session, not a parse failure', async () => {
    // An expired session is redirected to the OIDC provider, and fetch follows
    // that redirect to an HTML 200.
    fetchMock.mockResolvedValue(new Response('<html><body>Sign in</body></html>', {
      status: 200,
      headers: { 'Content-Type': 'text/html; charset=utf-8' },
    }))

    await expect(request('/api/session')).rejects.toMatchObject({ kind: 'http', status: 401 })
  })

  it('rejects a success body that is not JSON', async () => {
    fetchMock.mockResolvedValue(new Response('not json', { status: 200 }))

    await expect(request('/api/x')).rejects.toMatchObject({ kind: 'parse' })
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
