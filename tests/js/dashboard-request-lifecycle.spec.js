import { flushPromises, shallowMount } from '@vue/test-utils'
import { nextTick } from 'vue'
import axios from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Dashboard from '@/Components/Dashboard.vue'
import { coalesceDashboardRequest } from '@/Support/dashboard-request-scope.js'

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn(), defaults: { headers: { common: {} } } } }))

const wrappers = []
const handlers = new Map()
const response = (data = {}, status = 200) => ({ status, data, headers: {} })
const deferred = () => {
  let resolve, reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
const calls = url => axios.get.mock.calls.filter(([path]) => path === url)
const termData = symbol => ({ date: symbol, items: [{ tenor: 30, iv: 0.25 }] })
const vrpData = symbol => ({ date: symbol, iv1m: 0.25, rv20: 0.2, vrp: 0.05, z: 1.5 })
const seasonData = symbol => ({ variant: { date: symbol, d1: 1, cum5: 2 }, note: symbol })
const snapshot = symbol => response({ symbol, date: '2026-09-04', strike_data: [{ strike: 100, net_gex: 10 }], expiration_dates: ['2026-09-11', '2026-09-18'] })

async function tick(ms = 0) {
  await vi.advanceTimersByTimeAsync(ms)
  await flushPromises()
}
async function mountDashboard(symbol = 'SPY') {
  window.history.replaceState({}, '', '/?symbol=' + symbol)
  const wrapper = shallowMount(Dashboard)
  wrappers.push(wrapper)
  await flushPromises()
  return wrapper
}
async function activate(wrapper, tab) {
  const button = wrapper.findAll('nav button').find(item => item.text().includes(tab))
  expect(button?.attributes('disabled')).toBeUndefined()
  await button.trigger('click')
  await flushPromises()
}

describe('Dashboard request lifecycle', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'requestAnimationFrame', 'cancelAnimationFrame'] })
    vi.setSystemTime(new Date('2026-09-04T15:00:00Z'))
    localStorage.setItem('gex_onboarding_v1', 'seen')
    handlers.clear()
    axios.get.mockReset()
    axios.post.mockReset()
    axios.get.mockImplementation((url, options = {}) => {
      if (handlers.has(url)) return handlers.get(url)(options)
      const symbol = options.params?.symbol || 'SPY'
      if (url === '/api/gex-levels') return Promise.resolve(snapshot(symbol))
      if (url === '/api/iv/term') return Promise.resolve(response(termData(symbol)))
      if (url === '/api/vrp') return Promise.resolve(response(vrpData(symbol)))
      if (url === '/api/seasonality/5d') return Promise.resolve(response(seasonData(symbol)))
      if (url === '/api/ua' || url === '/api/intraday/ua') return Promise.resolve(response({ data_date: symbol, items: [{ symbol, exp: options.params.exp }] }))
      if (url === '/api/dex') return Promise.resolve(response({ symbol }))
      if (url === '/api/intraday/summary') return Promise.resolve(response({ open: true, refresh_eligible: false, asof: '2026-09-04T14:59:00Z' }))
      if (url === '/api/intraday/strikes') return Promise.resolve(response({ open: true, snapshot_available: true, asof: '2026-09-04T14:59:00Z', totals: {}, items: [] }))
      throw new Error('Unexpected request ' + url)
    })
    axios.post.mockResolvedValue(response({}))
  })

  afterEach(() => {
    wrappers.splice(0).forEach(wrapper => wrapper.unmount())
    vi.clearAllTimers()
    vi.useRealTimers()
    window.history.replaceState({}, '', '/')
  })

  it.each(['SPY', 'QQQ'])('mounts %s with one EOD request and no inactive heavy-tab probes or polling', async symbol => {
    const wrapper = await mountDashboard(symbol)
    await tick(180_000)
    expect(axios.get.mock.calls.map(([url]) => url)).toEqual(['/api/gex-levels'])
    expect(axios.post).not.toHaveBeenCalled()
    expect(wrapper.vm.tabStatus.volatility.state).toBe('idle')
    expect(wrapper.vm.activeTab).toBe('strikes')
  })

  it('starts term, VRP and seasonality concurrently, with one request each for activation and repeated clicks', async () => {
    const term = deferred(), vrp = deferred(), season = deferred()
    handlers.set('/api/iv/term', () => term.promise)
    handlers.set('/api/vrp', () => vrp.promise)
    handlers.set('/api/seasonality/5d', () => season.promise)
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Volatility')
    await activate(wrapper, 'Volatility')
    expect(calls('/api/iv/term')).toHaveLength(1)
    expect(calls('/api/vrp')).toHaveLength(1)
    expect(calls('/api/seasonality/5d')).toHaveLength(1)
    term.resolve(response(termData('SPY')))
    vrp.resolve(response(vrpData('SPY')))
    season.resolve(response(seasonData('SPY')))
    await flushPromises()
    expect(wrapper.vm.loaded.volatility).toBe(true)
    expect(wrapper.vm.term).toEqual(termData('SPY'))
    expect(wrapper.vm.vrp).toEqual(vrpData('SPY'))
    expect(wrapper.vm.season).toEqual(seasonData('SPY').variant)
  })

  it('reuses each documented TTL independently when revisiting volatility', async () => {
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Volatility')
    await activate(wrapper, 'Overview')
    await tick(59_000)
    await activate(wrapper, 'Volatility')
    expect(calls('/api/iv/term')).toHaveLength(1)
    expect(calls('/api/vrp')).toHaveLength(1)
    await activate(wrapper, 'Overview')
    await tick(2_000)
    await activate(wrapper, 'Volatility')
    expect(calls('/api/iv/term')).toHaveLength(2)
    expect(calls('/api/vrp')).toHaveLength(2)
    expect(calls('/api/seasonality/5d')).toHaveLength(1)
  })

  it('shows a successful VRP and seasonality when term fails, without retrying successful data', async () => {
    handlers.set('/api/iv/term', () => Promise.reject({ message: 'Term unavailable', response: { status: 503, data: { error: 'Term unavailable' } } }))
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Volatility')
    expect(wrapper.vm.volErrors).toEqual({ term: 'Term unavailable', vrp: '', season: '' })
    expect(wrapper.findComponent({ name: 'VRPTile' }).props('vrp')).toBe(0.05)
    expect(wrapper.vm.season.date).toBe('SPY')
    await tick(10_000)
    expect(calls('/api/iv/term')).toHaveLength(1)
    expect(calls('/api/vrp')).toHaveLength(1)
  })

  it('renders VRP before slow term settles and retries a partial202 term without refetching ready tiles', async () => {
    const pending = deferred()
    let ready = false
    handlers.set('/api/iv/term', () => ready ? Promise.resolve(response(termData('SPY'))) : pending.promise)
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Volatility')
    expect(wrapper.vm.volState.term).toBe('loading')
    expect(wrapper.findComponent({ name: 'VRPTile' }).props('vrp')).toBe(0.05)
    expect(wrapper.vm.volState.season).toBe('ready')
    pending.resolve(response({}, 202))
    await flushPromises()
    expect(wrapper.vm.tabStatus.volatility.state).toBe('ready')
    expect(wrapper.vm.volState.term).toBe('pending')
    expect(wrapper.findComponent({ name: 'TermTile' }).exists()).toBe(false)
    ready = true
    await tick(5_000)
    expect(wrapper.vm.volState.term).toBe('ready')
    expect(wrapper.findComponent({ name: 'TermTile' }).props('date')).toBe('SPY')
    expect(calls('/api/iv/term')).toHaveLength(2)
    expect(calls('/api/vrp')).toHaveLength(1)
    expect(calls('/api/seasonality/5d')).toHaveLength(1)
    await tick(20_000)
    expect(calls('/api/iv/term')).toHaveLength(2)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('coalesces repeated positioning activation while its readiness request is pending', async () => {
    const pending = deferred()
    handlers.set('/api/dex', () => pending.promise)
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Positioning')
    await activate(wrapper, 'Positioning')
    await activate(wrapper, 'Positioning')
    expect(calls('/api/dex')).toHaveLength(1)
    pending.resolve(response({}))
    await flushPromises()
    expect(wrapper.vm.tabStatus.positioning.state).toBe('ready')
    await tick(15_000)
    expect(calls('/api/dex')).toHaveLength(1)
    expect(vi.getTimerCount()).toBe(0)
  })

  it.each([
    ['term', '/api/iv/term', termData],
    ['vrp', '/api/vrp', vrpData],
    ['season', '/api/seasonality/5d', seasonData],
  ])('does not cache an incomplete200 %s response across the next pending retry', async (tile, url, data) => {
    let ready = false
    handlers.set(url, () => Promise.resolve(response(ready ? data('SPY') : {})))
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Volatility')
    expect(wrapper.vm.volState[tile]).toBe('pending')
    ready = true
    await tick(5_000)
    expect(wrapper.vm.volState[tile]).toBe('ready')
    for (const endpoint of ['/api/iv/term', '/api/vrp', '/api/seasonality/5d']) {
      expect(calls(endpoint)).toHaveLength(endpoint === url ? 2 : 1)
    }
    await tick(20_000)
    expect(calls(url)).toHaveLength(2)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('treats the documented200 null seasonality variant as terminal empty with its note, not endless preparation', async () => {
    handlers.set('/api/seasonality/5d', () => Promise.resolve(response({ symbol: 'SPY', variant: null, note: 'No seasonality available yet.' })))
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Volatility')
    expect(wrapper.vm.volState.season).toBe('empty')
    expect(wrapper.text()).toContain('No seasonality available yet.')
    await tick(120_000)
    expect(calls('/api/seasonality/5d')).toHaveLength(1)
    expect(vi.getTimerCount()).toBe(0)
    await activate(wrapper, 'Overview')
    await activate(wrapper, 'Volatility')
    expect(calls('/api/seasonality/5d')).toHaveLength(1)
    expect(wrapper.vm.volState.season).toBe('empty')
  })

  it('uses distinct EOD/intraday activity cache identities for the same symbol and filters', async () => {
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Unusual Activity')
    wrapper.vm.setMode('intraday')
    await flushPromises()
    // Exercise the existing mode-aware loader, even though the current
    // intraday navigation does not advertise an activity tab.
    wrapper.vm.activate('ua')
    await flushPromises()
    expect(calls('/api/intraday/ua')).toHaveLength(1)
    expect([...wrapper.vm.cacheUA.keys()].map(key => key.split('|')[1]).sort()).toEqual(['eod', 'intraday'])
  })

  it('freezes activity query parameters with the cache key before a deferred transport starts', async () => {
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Unusual Activity')
    wrapper.vm.uaTop = 6
    wrapper.vm.ensureUA()
    wrapper.vm.uaTop = 9
    await flushPromises()
    expect(calls('/api/ua').at(-1)[1].params.per_expiry).toBe(6)
    expect([...wrapper.vm.cacheUA.keys()].some(key => key.includes('|ALL|6|'))).toBe(true)
  })

  it('does not leave the positioning skeleton latched when a mode switch cancels its release timer', async () => {
    const wrapper = await mountDashboard()
    wrapper.vm.userSymbol = 'QQQ'
    wrapper.vm.setMode('intraday')
    await tick(300)
    expect(wrapper.vm.busy.positioning).toBe(false)
    wrapper.vm.setMode('eod')
    await flushPromises()
    await activate(wrapper, 'Positioning')
    expect(wrapper.vm.busy.positioning).toBe(false)
  })

  it('keeps resolved 202 volatility pending, retries only while active, then stops when ready', async () => {
    for (const url of ['/api/iv/term', '/api/vrp', '/api/seasonality/5d']) handlers.set(url, () => Promise.resolve(response({ status: 'preparing' }, 202)))
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Volatility')
    expect(wrapper.vm.activeTab).toBe('volatility')
    expect(wrapper.vm.tabStatus.volatility.state).toBe('pending')
    expect(wrapper.vm.loaded.volatility).toBe(false)
    await tick(5_000)
    expect(calls('/api/iv/term')).toHaveLength(2)
    handlers.clear()
    await tick(5_000)
    expect(wrapper.vm.loaded.volatility).toBe(true)
    expect(wrapper.vm.tabStatus.volatility.state).toBe('ready')
    await tick(20_000)
    expect(calls('/api/iv/term')).toHaveLength(3)
  })

  it('does not poll a pending volatility tab after navigating away', async () => {
    for (const url of ['/api/iv/term', '/api/vrp', '/api/seasonality/5d']) handlers.set(url, () => Promise.resolve(response({}, 202)))
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Volatility')
    await activate(wrapper, 'Overview')
    await tick(30_000)
    expect(calls('/api/iv/term')).toHaveLength(1)
  })

  it('rejects late term, VRP and seasonality responses after a symbol switch', async () => {
    const old = new Map(['/api/iv/term', '/api/vrp', '/api/seasonality/5d'].map(url => [url, deferred()]))
    for (const [url, pending] of old) handlers.set(url, options => options.params.symbol === 'SPY' ? pending.promise : Promise.resolve(response(url.includes('term') ? termData('QQQ') : url.includes('vrp') ? vrpData('QQQ') : seasonData('QQQ'))))
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Volatility')
    wrapper.vm.userSymbol = 'QQQ'
    await tick(300)
    for (const [url, pending] of old) {
      expect(calls(url)[0][1].signal.aborted).toBe(true)
      pending.resolve(response(url.includes('term') ? termData('OLD') : url.includes('vrp') ? vrpData('OLD') : seasonData('OLD')))
    }
    await flushPromises()
    expect(wrapper.vm.term.date).toBe('QQQ')
    expect(wrapper.vm.vrp.date).toBe('QQQ')
    expect(wrapper.vm.season.date).toBe('QQQ')
    expect(wrapper.vm.loaded.volatility).toBe(true)
  })

  it('uses the actual UA request for readiness and stops 202 polls on success', async () => {
    let ready = false
    handlers.set('/api/ua', () => Promise.resolve(ready ? response({ data_date: 'today', items: [] }) : response({}, 202)))
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Unusual Activity')
    expect(calls('/api/ua')).toHaveLength(1)
    expect(calls('/api/ua')[0][1].params.limit).toBe(50)
    expect(wrapper.vm.tabStatus.ua.state).toBe('pending')
    expect(wrapper.vm.loaded.ua).toBe(false)
    ready = true
    await tick(5_000)
    expect(wrapper.vm.loaded.ua).toBe(true)
    expect(wrapper.vm.tabStatus.ua.state).toBe('ready')
    expect([...wrapper.vm.cacheUA.keys()][0]).toMatch(/^ua\|eod\|SPY\|/)
    await tick(20_000)
    expect(calls('/api/ua')).toHaveLength(2)
  })

  it('fences UA expiry races and aborts the obsolete request without clearing the newer spinner', async () => {
    const old = deferred(), current = deferred()
    handlers.set('/api/ua', options => options.params.exp ? current.promise : old.promise)
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Unusual Activity')
    wrapper.vm.uaExp = '2026-09-11'
    await nextTick()
    await flushPromises()
    expect(calls('/api/ua')).toHaveLength(2)
    expect(calls('/api/ua')[0][1].signal.aborted).toBe(true)
    old.resolve(response({ data_date: 'OLD', items: [{ symbol: 'OLD' }] }))
    await flushPromises()
    expect(wrapper.vm.uaLoading).toBe(true)
    expect(wrapper.vm.uaDate).not.toBe('OLD')
    current.resolve(response({ data_date: 'CURRENT', items: [{ symbol: 'SPY' }] }))
    await flushPromises()
    expect(wrapper.vm.uaDate).toBe('CURRENT')
    expect(wrapper.vm.uaLoading).toBe(false)
  })

  it('cancels UA on a mode switch and rejects its late data/error', async () => {
    const old = deferred()
    handlers.set('/api/ua', () => old.promise)
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Unusual Activity')
    wrapper.vm.setMode('intraday')
    await flushPromises()
    expect(calls('/api/ua')[0][1].signal.aborted).toBe(true)
    old.reject(new Error('Old EOD failure'))
    await flushPromises()
    expect(wrapper.vm.errors.ua).toBe('')
    expect(wrapper.vm.uaRows).toEqual([])
    expect(wrapper.vm.activeTab).toBe('flow')
  })

  it('loads one new-symbol UA request with reset expiry rather than first querying the previous symbol expiry', async () => {
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Unusual Activity')
    wrapper.vm.uaExp = '2026-09-11'
    await nextTick()
    await flushPromises()
    wrapper.vm.userSymbol = 'QQQ'
    await tick(300)
    const nextCalls = calls('/api/ua').filter(([, options]) => options.params.symbol === 'QQQ')
    expect(nextCalls).toHaveLength(1)
    expect(nextCalls[0][1].params.exp).toBeNull()
    expect(wrapper.vm.uaExp).toBe('ALL')
    expect(wrapper.vm.uaRows).toEqual([{ symbol: 'QQQ', exp: null }])
  })

  it('fences active-only positioning readiness across symbols and resolved 202 responses', async () => {
    const old = deferred()
    let ready = false
    handlers.set('/api/dex', options => options.params.symbol === 'SPY' ? old.promise : Promise.resolve(response({}, ready ? 200 : 202)))
    const wrapper = await mountDashboard()
    expect(calls('/api/dex')).toHaveLength(0)
    await activate(wrapper, 'Positioning')
    wrapper.vm.userSymbol = 'QQQ'
    await tick(300)
    old.resolve(response({ symbol: 'SPY' }))
    await flushPromises()
    expect(calls('/api/dex')[0][1].signal.aborted).toBe(true)
    expect(wrapper.vm.tabStatus.positioning.state).toBe('pending')
    expect(wrapper.vm.activeTab).toBe('positioning')
    ready = true
    await tick(5_000)
    expect(wrapper.vm.tabStatus.positioning.state).toBe('ready')
    await tick(20_000)
    expect(calls('/api/dex')).toHaveLength(3)
  })

  it('aborts every volatility fetch on unmount and ignores their late responses', async () => {
    const pending = deferred()
    for (const url of ['/api/iv/term', '/api/vrp', '/api/seasonality/5d']) handlers.set(url, () => pending.promise)
    const wrapper = await mountDashboard()
    await activate(wrapper, 'Volatility')
    wrapper.unmount()
    for (const url of ['/api/iv/term', '/api/vrp', '/api/seasonality/5d']) expect(calls(url)[0][1].signal.aborted).toBe(true)
    pending.resolve(response({ date: 'LATE', variant: { date: 'LATE' } }))
    await flushPromises()
    await tick(60_000)
    expect(calls('/api/iv/term')).toHaveLength(1)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('cancels the intraday pull and interval on unmount, without starting a subsequent composite request', async () => {
    const pending = deferred()
    handlers.set('/api/intraday/summary', () => Promise.resolve(response({ open: true, refresh_eligible: true })))
    axios.post.mockReturnValue(pending.promise)
    const wrapper = await mountDashboard()
    wrapper.vm.setMode('intraday')
    await flushPromises()
    expect(axios.post).toHaveBeenCalledTimes(1)
    wrapper.unmount()
    expect(axios.post.mock.calls[0][2].signal.aborted).toBe(true)
    pending.resolve(response({}))
    await tick(120_000)
    expect(calls('/api/intraday/strikes')).toHaveLength(0)
    expect(calls('/api/intraday/summary')).toHaveLength(1)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('clears preparation delay, symbol debounce, positioning animation and timers on unmount', async () => {
    const wrapper = await mountDashboard()
    wrapper.vm.refreshPreparedGex('SPY', '14d', { kind: 'full' })
    wrapper.vm.userSymbol = 'QQQ'
    await tick(20)
    wrapper.vm.refreshPreparedGex('QQQ', '14d', { kind: 'full' })
    wrapper.unmount()
    await tick(5_000)
    expect(calls('/api/gex-levels')).toHaveLength(1)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('aborts a pending prime request on unmount and cannot retain its late response', async () => {
    const pending = deferred()
    axios.post.mockReturnValue(pending.promise)
    const wrapper = await mountDashboard()
    wrapper.vm.kickoffSymbolWarm('SPY')
    await flushPromises()
    wrapper.unmount()
    expect(axios.post.mock.calls[0][2].signal.aborted).toBe(true)
    pending.resolve(response({ status: 'queued' }, 202))
    await tick(5_000)
    expect(calls('/api/gex-levels')).toHaveLength(1)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('does not let an older finally erase a replacement request under the same key', async () => {
    const map = new Map(), old = deferred(), current = deferred()
    const first = coalesceDashboardRequest(map, 'same', () => old.promise)
    await Promise.resolve()
    map.clear()
    const replacement = coalesceDashboardRequest(map, 'same', () => current.promise)
    old.resolve('old')
    await first
    const sendAgain = vi.fn()
    expect(coalesceDashboardRequest(map, 'same', sendAgain)).toBe(replacement)
    expect(sendAgain).not.toHaveBeenCalled()
    current.resolve('current')
    expect(await replacement).toBe('current')
    expect(map.size).toBe(0)
  })
})
