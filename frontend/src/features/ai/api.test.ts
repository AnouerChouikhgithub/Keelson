import { afterEach, describe, expect, it, vi } from 'vitest'
import { installFetch, requests } from '../../test/fetchMock'
import {
  AiError,
  askGuide,
  getAiSettings,
  getAiStatus,
  getOnboarding,
  requestSummary,
  updateAiSettings,
} from './api'

/**
 * The AI client is exercised against the real shared client, so the CSRF header,
 * the JSON envelope and the error mapping are the production ones.
 */
afterEach(() => {
  vi.unstubAllGlobals()
})

describe('AI endpoints', () => {
  it('reads the status for one organization', async () => {
    const fetch = installFetch([
      { path: '/api/ai/status', method: 'GET', json: { enabled: true, organizationEnabled: true, budgetRemainingTokens: 10 } },
    ])

    await expect(getAiStatus('org-1')).resolves.toEqual({ enabled: true, organizationEnabled: true, budgetRemainingTokens: 10 })
    expect(requests(fetch)[0]).toMatchObject({ method: 'GET', path: '/api/ai/status?organizationId=org-1' })
  })

  it('omits the query string when no organization is given', async () => {
    const fetch = installFetch([
      { path: '/api/ai/status', method: 'GET', json: { enabled: false, organizationEnabled: false, budgetRemainingTokens: 0 } },
    ])

    await getAiStatus()
    expect(requests(fetch)[0]?.path).toBe('/api/ai/status')
  })

  it('reads and replaces the workspace settings', async () => {
    const fetch = installFetch([
      { path: '/ai-settings', method: 'GET', json: { enabled: false, monthlyTokenBudget: 50000, budgetRemainingTokens: 50000 } },
      { path: '/ai-settings', method: 'PUT', json: { enabled: true, monthlyTokenBudget: 2000, budgetRemainingTokens: 2000 } },
    ])

    await getAiSettings('org 1')
    await updateAiSettings('org 1', { enabled: true, monthlyTokenBudget: 2000 })

    const [read, write] = requests(fetch)
    expect(read).toMatchObject({ method: 'GET', path: '/api/organizations/org%201/ai-settings' })
    expect(write).toMatchObject({
      method: 'PUT',
      path: '/api/organizations/org%201/ai-settings',
      body: { enabled: true, monthlyTokenBudget: 2000 },
    })
  })

  it('asks the guide endpoint with the question', async () => {
    const fetch = installFetch([
      { path: '/api/ai/guide', method: 'POST', json: { answer: 'Choose Mark paid.', sources: [] } },
    ])

    await askGuide('How do I mark an invoice paid?')
    expect(requests(fetch)[0]).toMatchObject({
      method: 'POST',
      path: '/api/ai/guide',
      body: { question: 'How do I mark an invoice paid?' },
    })
  })

  it('reads the onboarding checklist', async () => {
    const fetch = installFetch([
      { path: '/onboarding', method: 'GET', json: { steps: [{ key: 'customer', completed: true }] } },
    ])

    await getOnboarding('org-1')
    expect(requests(fetch)[0]).toMatchObject({ method: 'GET', path: '/api/organizations/org-1/onboarding' })
  })

  it('requests a summary of one kind', async () => {
    const fetch = installFetch([
      { path: '/ai/summaries/invoices', method: 'POST', json: { text: 'x', generatedAt: '2026-12-01T10:00:00+00:00', facts: {} } },
    ])

    await requestSummary('org-1', 'invoices')
    expect(requests(fetch)[0]).toMatchObject({
      method: 'POST',
      path: '/api/organizations/org-1/ai/summaries/invoices',
    })
  })
})

describe('AI error mapping', () => {
  const cases: [number, string, string][] = [
    [403, 'ai_disabled', 'ai_disabled'],
    [402, 'ai_budget_exceeded', 'ai_budget_exceeded'],
    [429, 'ai_rate_limited', 'ai_rate_limited'],
    [503, 'ai_unavailable', 'ai_unavailable'],
    [422, 'validation_failed', 'validation_failed'],
    [404, 'not_found', 'not_found'],
    [501, 'not_implemented', 'not_implemented'],
    [403, 'csrf_invalid', 'csrf_invalid'],
    [401, 'unauthenticated', 'unauthenticated'],
  ]

  it.each(cases)('maps %i %s to the %s code', async (status, code, expected) => {
    installFetch([{ path: '/api/ai/guide', method: 'POST', status, json: { error: { code, message: 'x' } } }])

    const failure = await askGuide('question').catch((error: unknown) => error)
    expect(failure).toBeInstanceOf(AiError)
    expect((failure as AiError).code).toBe(expected)
    expect((failure as AiError).status).toBe(status)
  })

  it('keeps the per-field messages of a rejected settings write', async () => {
    installFetch([
      {
        path: '/ai-settings',
        method: 'PUT',
        status: 422,
        json: { error: { code: 'validation_failed', message: 'x', fields: { monthlyTokenBudget: ['Too large.'] } } },
      },
    ])

    const failure = await updateAiSettings('org-1', { enabled: true, monthlyTokenBudget: 1 }).catch((error: unknown) => error)
    expect((failure as AiError).fields).toEqual({ monthlyTokenBudget: ['Too large.'] })
  })

  it('reports a network failure as its own code', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('failed to fetch') }))

    const failure = await getAiStatus('org-1').catch((error: unknown) => error)
    expect((failure as AiError).code).toBe('network')
  })

  it('lets an abort pass through untouched', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => { throw new DOMException('aborted', 'AbortError') }))
    const controller = new AbortController()
    controller.abort()

    const failure = await askGuide('question', controller.signal).catch((error: unknown) => error)
    expect(failure).toBeInstanceOf(DOMException)
    expect(failure).not.toBeInstanceOf(AiError)
  })

  it('falls back to the status when the envelope has no code', async () => {
    installFetch([{ path: '/ai/summaries/customers', method: 'POST', status: 402, json: {} }])

    const failure = await requestSummary('org-1', 'customers').catch((error: unknown) => error)
    expect((failure as AiError).code).toBe('ai_budget_exceeded')
  })
})
