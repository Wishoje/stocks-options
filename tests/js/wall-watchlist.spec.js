import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import WallWatchlist from '@/Components/WallWatchlist.vue'

vi.mock('axios', () => ({ default: { get: vi.fn() } }))
const wrappers = []
const row = (symbol = 'SPY', changes = {}) => ({ symbol, side: 'put', strike: 100, close: 100.1,
  observed_at: '2026-09-30T14:15:00Z', reaction: 'touch', status: 'touch', setup: null, ...changes })
const setup = () => ({ direction: 'bullish', pattern: 'reversal', expires_at: '2026-09-30T14:30:00Z',
  entry_zone: { low: 100.05, high: 100.15 }, invalidation: { price: 99.95, close_side: 'below' }, target: { strike: 101 },
  evidence_checks: [{ key: 'ordered_price_confirmation', passed: true }, { key: 'wall_persistence', passed: false },
    { key: 'supported_geometry', passed: true }] })
const payload = (items = [row()], changes = {}) => ({ schema_version: 'wall-watchlist.v1', session: '2026-09-30',
  timeframe: '7d', generated_at: '2026-09-30T14:31:00Z', quote_delay_seconds: 900,
  watchlist_count: 3, assessed_count: 3, items, state: 'ready', local_demo_available: true,
  refresh_until: '2026-09-30T20:00:00Z', next_refresh_at: '2026-10-01T13:45:00Z', ...changes })
async function render(data = payload(), props = {}) {
  axios.get.mockResolvedValueOnce({ data })
  const w = mount(WallWatchlist, { props }); wrappers.push(w)
  await flushPromises()
  // These behavior cases inspect All; the default qualification filter is checked separately.
  if (w.find('.filters button').exists()) await w.get('.filters button').trigger('click')
  return w
}
beforeEach(() => { vi.useFakeTimers(); vi.setSystemTime(new Date('2026-09-30T14:31:00Z')); axios.get.mockReset() })
afterEach(() => { wrappers.splice(0).forEach(w => w.unmount()); vi.clearAllTimers(); vi.useRealTimers() })

describe('Watchlist wall activity', () => {
  it('defaults to conditions met and keeps that zero-count selection during updates', async () => {
    axios.get.mockResolvedValueOnce({ data: payload([]) })
    const w = mount(WallWatchlist); wrappers.push(w); await flushPromises()
    expect(w.get('.filters button[aria-pressed="true"]').text()).toBe('Conditions met · 0')
    expect(w.text()).toContain('Your selection stays in place')
  })
  it('shows the saved next condition and proven progress without promoting a pending entry', async () => {
    const w = await render(payload([row('QQQ', { reaction: 'bounce', next_condition: {
      text: 'Wait for a second consecutive five-minute close above 100.05.', completed_closes: 1, required_closes: 2,
    } })]))
    expect(w.text()).toContain('Price bounced higher')
    expect(w.text()).toContain('1 of 2 confirming closes recorded')
    expect(w.text()).toContain('Next: Wait for a second consecutive five-minute close above 100.05.')
    expect(w.text()).not.toContain('Entry area')
    expect(w.text()).not.toContain('Higher-price scenario')
    expect(w.get('[aria-label="View QQQ wall assessment"]').text()).toContain('View assessment')
    expect(axios.get).toHaveBeenCalledTimes(1)
  })

  it('loads one aggregate request and shows qualified levels with the checks that actually passed', async () => {
    const w = await render(payload([row('SPY', { status: 'conditions_met', setup: setup() }), row('QQQ')]), { selectedSymbol: 'SPY' })
    expect(axios.get).toHaveBeenCalledTimes(1)
    expect(axios.get.mock.calls[0][0]).toBe('/api/intraday/wall-watchlist')
    expect(w.text()).toContain('1 setup with conditions met')
    expect(w.text()).toContain('100.05–100.15')
    expect(w.text()).toContain('below 99.95')
    expect(w.text()).toContain('Reference target101')
    expect(w.text()).toContain('Price confirmed · Target room checked')
    expect(w.text()).not.toContain('Wall stable')
    expect(w.text()).toContain('Prices delayed 15 min')
    expect(w.get('.selected .symbol').text()).toBe('SPY')
    await w.get('[aria-label="View QQQ wall assessment"]').trigger('click')
    expect(w.emitted('open')[0][0]).toMatchObject({ symbol: 'QQQ', demonstration: false })
    expect(axios.get).toHaveBeenCalledTimes(1)
    await w.setProps({ selectedSymbol: 'QQQ' })
    expect(axios.get).toHaveBeenCalledTimes(1)
    expect(w.get('.selected .symbol').text()).toBe('QQQ')
  })

  it('filters and reveals additional rows locally without another request', async () => {
    const rows = Array.from({ length: 8 }, (_, i) => row('SYM' + i, i === 0 ? { status: 'conditions_met', setup: setup() } : {}))
    const w = await render(payload(rows))
    expect(w.findAll('.activity-row')).toHaveLength(6)
    await w.get('.show-more').trigger('click')
    expect(w.findAll('.activity-row')).toHaveLength(8)
    await w.get('.filters button[aria-pressed="false"]').trigger('click')
    expect(w.findAll('.activity-row')).toHaveLength(1)
    expect(axios.get).toHaveBeenCalledTimes(1)
  })

  it('removes expired qualifying entries between polls rather than leaving stale entry levels', async () => {
    const w = await render(payload([row('SPY', { status: 'conditions_met', setup: { ...setup(), expires_at: '2026-09-30T14:15:45Z' } }), row('QQQ')]))
    expect(w.text()).toContain('Entry area')
    await w.get('.filters button[aria-pressed="false"]').trigger('click')
    await vi.advanceTimersByTimeAsync(20000)
    expect(w.findAll('.activity-row')).toHaveLength(0)
    expect(w.get('.filters button[aria-pressed="true"]').text()).toBe('Conditions met · 0')
    expect(w.text()).not.toContain('Entry area')
  })

  it('refreshes only while visible, avoids overlap, and backs off after a rate limit', async () => {
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
    const w = await render()
    await vi.advanceTimersByTimeAsync(60000)
    expect(axios.get).toHaveBeenCalledTimes(1)
    let finish
    axios.get.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    visibility.mockReturnValue('visible'); document.dispatchEvent(new Event('visibilitychange')); await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(60000)
    expect(axios.get).toHaveBeenCalledTimes(2)
    finish({ data: payload() }); await flushPromises()
    axios.get.mockRejectedValueOnce({ response: { status: 429, headers: { 'retry-after': '180' } } })
    await vi.advanceTimersByTimeAsync(60000); await flushPromises()
    expect(w.text()).toContain('Please wait a moment')
    const count = axios.get.mock.calls.length
    await vi.advanceTimersByTimeAsync(120000)
    expect(axios.get).toHaveBeenCalledTimes(count)
    axios.get.mockResolvedValue({ data: payload() })
    await vi.advanceTimersByTimeAsync(60000); await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(count + 1)
    expect(w.find('[role="alert"]').exists()).toBe(false)
  })

  it('clears old watchlist membership immediately and ignores cancelled late results', async () => {
    let oldFinish
    axios.get.mockImplementationOnce(() => new Promise(resolve => { oldFinish = resolve }))
    const w = mount(WallWatchlist); wrappers.push(w)
    const oldSignal = axios.get.mock.calls[0][1].signal
    axios.get.mockResolvedValueOnce({ data: payload([], { watchlist_count: 0, assessed_count: 0 }) })
    window.dispatchEvent(new Event('watchlist-updated')); await flushPromises()
    expect(oldSignal.aborted).toBe(true)
    oldFinish({ data: payload() }); await flushPromises()
    expect(w.find('.activity-row').exists()).toBe(false)
    expect(w.text()).toContain('Add symbols to your watchlist')
  })

  it('rejects a previous session, another timeframe and a synthetic response in normal mode', async () => {
    for (const changes of [{ session: '2026-09-29' }, { timeframe: '14d' }, { state: 'demonstration' }]) {
      const w = await render(payload([row()], changes))
      expect(w.find('.activity-row').exists()).toBe(false)
      expect(w.find('[role="alert"]').exists()).toBe(true)
      w.unmount()
    }
  })

  it('labels the local example, links to its evidence, and does not poll it', async () => {
    const w = await render(payload([]))
    axios.get.mockResolvedValueOnce({ data: payload([row('SPY', { status: 'conditions_met', setup: setup(), event_id: 'demo-event' })],
      { state: 'demonstration', session: '2026-09-29', refresh_until: null, next_refresh_at: null }) })
    await w.get('.preview').trigger('click'); await flushPromises()
    expect(axios.get.mock.calls[1][1].params).toEqual({ demo: 1 })
    expect(w.text()).toContain('Synthetic historical examples')
    await w.get('[aria-label="View SPY wall assessment"]').trigger('click')
    expect(w.emitted('open')[0][0]).toMatchObject({ demonstration: true, event_id: 'demo-event' })
    await vi.advanceTimersByTimeAsync(120000)
    expect(axios.get).toHaveBeenCalledTimes(2)
  })

  it('continues read-only lifecycle refreshes after the close through the bounded finalization window', async () => {
    vi.setSystemTime(new Date('2026-09-30T19:59:30Z'))
    await render(payload([], { lifecycle_refresh_until: '2026-09-30T20:30:00Z' }))
    axios.get.mockResolvedValue({ data: payload([], { state: 'outside_session', refresh_until: null,
      lifecycle_refresh_until: '2026-09-30T20:30:00Z' }) })
    await vi.advanceTimersByTimeAsync(60000)
    expect(axios.get).toHaveBeenCalledTimes(2)
    vi.setSystemTime(new Date('2026-09-30T20:15:30Z'))
    await vi.advanceTimersByTimeAsync(60000)
    expect(axios.get).toHaveBeenCalledTimes(3)
    vi.setSystemTime(new Date('2026-09-30T20:30:00Z'))
    await vi.advanceTimersByTimeAsync(120000)
    expect(axios.get).toHaveBeenCalledTimes(3)
    expect(axios.get.mock.calls.every(([url]) => url === '/api/intraday/wall-watchlist')).toBe(true)
  })
  it('stops polling after session close and cancels its outstanding request when unmounted', async () => {
    vi.setSystemTime(new Date('2026-09-30T19:59:30Z'))
    const w = await render(payload([], { generated_at: '2026-09-30T19:59:30Z' }))
    await vi.advanceTimersByTimeAsync(120000)
    expect(axios.get).toHaveBeenCalledTimes(1)
    expect(w.text()).toContain('The market is closed')
    const signal = axios.get.mock.calls[0][1].signal
    w.unmount()
    expect(signal.aborted).toBe(true)
  })

  it('reloads saved session history when returning after the finalization window without restarting idle polling', async () => {
    vi.setSystemTime(new Date('2026-09-30T21:00:00Z'))
    const history = payload([], { state: 'outside_session', refresh_until: null, lifecycle_refresh_until: null,
      alert_history: [{ alert_id: 'recorded-while-away' }] })
    axios.get.mockResolvedValue({ data: history })
    const w = mount(WallWatchlist); wrappers.push(w); await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(1)
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
    await vi.advanceTimersByTimeAsync(120000)
    expect(axios.get).toHaveBeenCalledTimes(1)
    visibility.mockReturnValue('visible')
    document.dispatchEvent(new Event('visibilitychange')); await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(120000)
    expect(axios.get).toHaveBeenCalledTimes(2)
    visibility.mockRestore()
  })

  it('retains a return-to-tab refresh until its cooldown ends after regular polling has stopped', async () => {
    vi.setSystemTime(new Date('2026-09-30T21:00:00Z'))
    axios.get.mockResolvedValue({ data: payload([], { state: 'outside_session', refresh_until: null, lifecycle_refresh_until: null }) })
    const w = mount(WallWatchlist); wrappers.push(w); await flushPromises()
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
    await vi.advanceTimersByTimeAsync(20000)
    visibility.mockReturnValue('visible')
    document.dispatchEvent(new Event('visibilitychange')); await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(40000); await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(120000)
    expect(axios.get).toHaveBeenCalledTimes(2)
    visibility.mockRestore()
  })
})
