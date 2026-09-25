import axios, { AxiosError } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { installMarketReadCooldown, rateLimitDelayMs } from '@/Support/market-read-cooldown.js'

describe('market-data read cooldown', () => {
  let client, adapter, limited
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-09-25T12:00:00Z'))
    sessionStorage.clear()
    limited = true
    adapter = vi.fn(async config => {
      const response = { status: limited ? 429 : 200, headers: { 'retry-after': '5' }, config,
        data: limited ? { code: 'work_rate_limited', rate_limit_scope: 'market-data-read' } : {} }
      if (limited) throw new AxiosError('Limited', 'ERR_BAD_REQUEST', config, null, response)
      return response
    })
    client = axios.create({ adapter })
    installMarketReadCooldown(client)
  })
  afterEach(() => vi.useRealTimers())

  it('blocks further reads across market panels until Retry-After expires', async () => {
    await expect(client.get('/api/gex-levels')).rejects.toMatchObject({ response: { status: 429 } })
    limited = false
    await expect(client.get('/api/dex')).rejects.toMatchObject({ localCooldown: true })
    await expect(client.get('/api/iv/term')).rejects.toMatchObject({ localCooldown: true })
    expect(adapter).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(5000)
    await expect(client.get('/api/gex-levels')).rejects.toMatchObject({ localCooldown: true })
    await vi.advanceTimersByTimeAsync(250)
    await expect(client.get('/api/gex-levels')).resolves.toMatchObject({ status: 200 })
    expect(adapter).toHaveBeenCalledTimes(2)
  })

  it('retains the cooldown when a browser reload creates a new HTTP client', async () => {
    await client.get('/api/ua?symbol=XLE').catch(() => {})
    limited = false
    const reloaded = axios.create({ adapter })
    installMarketReadCooldown(reloaded)
    await expect(reloaded.get('/api/gex-levels')).rejects.toMatchObject({ localCooldown: true })
    expect(adapter).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(5250)
    await expect(reloaded.get('/api/gex-levels')).resolves.toMatchObject({ status: 200 })
  })

  it('does not pause work status, authentication, mutations or external requests', async () => {
    await client.get('/api/gex-levels').catch(() => {})
    limited = false
    for (const path of ['/api/work-runs/example', '/api/me', 'https://example.com/api/gex-levels']) {
      await expect(client.get(path)).resolves.toMatchObject({ status: 200 })
    }
    await expect(client.post('/api/prime', { symbol: 'SPY' })).resolves.toMatchObject({ status: 200 })
    expect(adapter).toHaveBeenCalledTimes(5)
  })

  it('never automatically replays a request and does not extend a cooldown on a blocked local read', async () => {
    await client.get('/api/gex-levels').catch(() => {})
    for (let i = 0; i < 5; i++) {
      await vi.advanceTimersByTimeAsync(1000)
      await client.get('/api/gex-levels').catch(() => {})
    }
    limited = false
    await vi.advanceTimersByTimeAsync(250)
    await expect(client.get('/api/gex-levels')).resolves.toMatchObject({ status: 200 })
    expect(adapter).toHaveBeenCalledTimes(2)
  })

  it('does not impose the read cooldown for work-submission 429s', async () => {
    await client.post('/api/prime').catch(() => {})
    limited = false
    await expect(client.get('/api/gex-levels')).resolves.toMatchObject({ status: 200 })
  })

  it('observes 429 responses accepted by a status poller without changing that response', async () => {
    adapter.mockImplementationOnce(async config => ({ status: 429, config,
      headers: { 'retry-after': '5' }, data: { code: 'work_rate_limited' } }))
    await expect(client.get('/api/symbol/status', { validateStatus: () => true }))
      .resolves.toMatchObject({ status: 429 })
    limited = false
    await expect(client.get('/api/gex-levels')).rejects.toMatchObject({ localCooldown: true })
    expect(adapter).toHaveBeenCalledTimes(1)
  })

  it('honors the longer server delay, including HTTP dates and delays over a minute', () => {
    expect(rateLimitDelayMs({ data: { retry_after_seconds: 90 }, headers: { 'retry-after': '15' } })).toBe(90000)
    expect(rateLimitDelayMs({ headers: { 'retry-after': 'Fri, 25 Sep 2026 12:02:00 GMT' } })).toBe(120000)
    expect(rateLimitDelayMs({ headers: { 'retry-after': 'invalid' } })).toBe(60000)
  })
})
