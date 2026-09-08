import { flushPromises, shallowMount } from '@vue/test-utils'
import { nextTick } from 'vue'
import axios from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Dashboard from '@/Components/Dashboard.vue'

vi.mock('axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    defaults: { headers: { common: {} } },
  },
}))

const TIMEFRAMES = ['0d', '1d', '7d', '14d', '30d', '90d']
const wrappers = []
let gexResponse
let statusResponse

function deferred() {
  let resolve
  let reject
  const promise = new Promise((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

function snapshot(symbol = 'SPY', timeframe = '14d', count = 3) {
  return {
    status: 200,
    data: {
      symbol,
      timeframe,
      date: '2026-09-04',
      available_timeframes: TIMEFRAMES,
      timeframe_expirations: Object.fromEntries(TIMEFRAMES.map(tf => [tf, ['2026-09-18']])),
      strike_data: Array.from({ length: count }, (_, i) => ({
        strike: 200 + i,
        net_gex: i + 10,
        call_oi: i + 100,
        put_oi: i + 50,
        call_volume: i + 20,
        put_volume: i + 5,
      })),
    },
    headers: {},
  }
}

function preparation(state, { status = 200, symbol = 'IWM' } = {}) {
  return {
    status,
    data: {
      status: 'fetching',
      status_url: '/api/work-runs/' + symbol.toLowerCase(),
      bootstrap: {
        state,
        fast_ready: ['fast_ready', 'filling', 'fill_failed', 'full_ready'].includes(state),
        full_ready: state === 'full_ready',
        retryable: !['fill_failed', 'full_ready', 'no_options'].includes(state),
      },
    },
    headers: {},
  }
}

function gatewayTimeout() {
  return {
    message: 'Request failed with status code 504',
    response: { status: 504, data: { error: 'Request failed with status code 504' } },
  }
}

function gexCalls(timeframe) {
  return axios.get.mock.calls.filter(([url, options]) =>
    url === '/api/gex-levels' && (!timeframe || options.params.timeframe === timeframe))
}

async function advance(ms) {
  await vi.advanceTimersByTimeAsync(ms)
  await flushPromises()
}

async function mountDashboard(symbol = 'SPY') {
  window.history.replaceState({}, '', symbol === 'SPY' ? '/' : '/?symbol=' + symbol)
  const wrapper = shallowMount(Dashboard, {
    global: {
      stubs: {
        uiErrorBlock: {
          props: ['message', 'detail'],
          template: '<div data-testid="eod-error">{{ message }} {{ detail }}</div>',
        },
      },
    },
  })
  wrappers.push(wrapper)
  await flushPromises()
  // URL-selected symbols also use the normal debounced selection watcher.
  await advance(300)
  return wrapper
}

async function selectTimeframe(wrapper, timeframe) {
  wrapper.vm.gexTf = timeframe
  await nextTick()
  await flushPromises()
}

function chartRows(wrapper) {
  const chart = wrapper.findComponent({ name: 'NetGexChart' })
  expect(chart.exists()).toBe(true)
  return chart.props('strikeData')
}

describe('Dashboard EOD loading', () => {
  beforeEach(() => {
    vi.useFakeTimers({
      toFake: ['Date', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'],
    })
    vi.setSystemTime(new Date('2026-09-06T12:00:00Z'))
    localStorage.setItem('gex_onboarding_v1', 'seen')
    axios.get.mockReset()
    axios.post.mockReset()
    gexResponse = (symbol, timeframe) => Promise.resolve(snapshot(symbol, timeframe))
    statusResponse = () => Promise.resolve(preparation('full_ready'))
    axios.get.mockImplementation((url, options = {}) => {
      if (url === '/api/gex-levels') {
        return gexResponse(options.params.symbol, options.params.timeframe)
      }
      if (url.startsWith('/api/work-runs/') || url === '/api/symbol/status') {
        return statusResponse(url, options)
      }
      if (['/api/dex', '/api/iv/term', '/api/ua'].includes(url)) {
        return Promise.resolve({ status: 200, data: {}, headers: {} })
      }
      return Promise.reject(new Error('Unexpected API request: ' + url))
    })
    axios.post.mockImplementation(() => Promise.reject(new Error('Unexpected prime request')))
  })

  afterEach(() => {
    wrappers.splice(0).forEach(wrapper => wrapper.unmount())
    vi.clearAllTimers()
    vi.useRealTimers()
    window.history.replaceState({}, '', '/')
  })

  function eodBootstrap(state, { terminal = false, generation = 1 } = {}) {
    return {
      state,
      terminal,
      retryable: false,
      run_id: 'amd-run-' + generation,
      status_url: '/api/work-runs/amd-run-' + generation,
      fast_ready: true,
      full_ready: state === 'full_ready',
      eod_ready: true,
      enrichment_ready: state === 'full_ready',
      catalog: { generation },
      coverage: { completed_expirations: 11, expected_expirations: 11 },
      phases: {
        fill: { status: 'completed' },
        intraday: { status: 'completed' },
        enrichment: { status: state === 'fill_failed' ? 'failed' : state === 'full_ready' ? 'completed' : 'running' },
      },
    }
  }

  it('shows complete AMD EOD data with failed analytics without a filling spinner or terminal polling', async () => {
    const wrapper = await mountDashboard('AMD')
    gexResponse = (symbol, timeframe) => Promise.resolve({
      ...snapshot(symbol, timeframe, 225),
      data: { ...snapshot(symbol, timeframe, 225).data, bootstrap: eodBootstrap('fill_failed', { terminal: true }) },
    })

    await selectTimeframe(wrapper, '30d')
    expect(chartRows(wrapper)).toHaveLength(225)
    expect(wrapper.vm.preparing).toMatchObject({ fullReady: false, eodReady: true, terminal: true, filling: false })
    expect(wrapper.text()).toContain('EOD data ready for AMD')
    expect(wrapper.text()).toContain('Additional analytics could not be prepared.')
    expect(wrapper.text()).toContain('11 of 11 expirations complete.')
    expect(wrapper.text()).not.toContain('Filling full data for AMD')
    expect(wrapper.find('[role="status"] .animate-spin').exists()).toBe(false)
    await advance(10_000)
    expect(axios.get.mock.calls.some(([url]) => url.startsWith('/api/work-runs/'))).toBe(false)
  })

  it('applies a fresh terminal response to an already filling same-symbol banner and stops its poll', async () => {
    const wrapper = await mountDashboard('AMD')
    gexResponse = (symbol, timeframe) => Promise.resolve({
      ...snapshot(symbol, timeframe),
      data: { ...snapshot(symbol, timeframe).data, bootstrap: eodBootstrap(timeframe === '90d' ? 'fill_failed' : 'filling', { terminal: timeframe === '90d' }) },
    })
    await selectTimeframe(wrapper, '30d')
    expect(wrapper.text()).toContain('Additional analytics are still being prepared.')
    expect(wrapper.vm.preparing.timer).not.toBeNull()

    await selectTimeframe(wrapper, '90d')
    expect(wrapper.vm.preparing).toMatchObject({ fullReady: false, terminal: true, partialFailed: true, filling: false })
    expect(wrapper.vm.preparing.timer).toBeNull()
    expect(wrapper.text()).toContain('Additional analytics could not be prepared.')
    await advance(5_000)
    expect(axios.get.mock.calls.some(([url]) => url.startsWith('/api/work-runs/'))).toBe(false)
  })

  it('clears a filling banner on fresh full readiness and does not resurrect it from an older cached timeframe', async () => {
    const wrapper = await mountDashboard('AMD')
    gexResponse = (symbol, timeframe) => Promise.resolve({
      ...snapshot(symbol, timeframe),
      data: { ...snapshot(symbol, timeframe).data, bootstrap: eodBootstrap(timeframe === '90d' ? 'full_ready' : 'filling', { terminal: timeframe === '90d' }) },
    })
    await selectTimeframe(wrapper, '30d')
    expect(wrapper.vm.preparing.partial).toBe(true)
    await selectTimeframe(wrapper, '90d')
    expect(wrapper.vm.preparing).toMatchObject({ fullReady: true, partial: false, terminal: true })
    expect(wrapper.find('[role="status"]').exists()).toBe(false)

    await selectTimeframe(wrapper, '30d')
    expect(gexCalls('30d')).toHaveLength(1)
    expect(wrapper.vm.preparing).toMatchObject({ fullReady: true, partial: false, terminal: true })
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect(wrapper.vm.preparing.timer).toBeNull()
  })

  it('accepts a new bootstrap generation after a terminal one but rejects an older live response', async () => {
    const wrapper = await mountDashboard('AMD')
    gexResponse = (symbol, timeframe) => {
      const bootstrap = timeframe === '30d' ? eodBootstrap('fill_failed', { terminal: true })
        : timeframe === '90d' ? eodBootstrap('filling', { generation: 2 })
          : eodBootstrap('full_ready', { terminal: true })
      return Promise.resolve({ ...snapshot(symbol, timeframe), data: { ...snapshot(symbol, timeframe).data, bootstrap } })
    }
    await selectTimeframe(wrapper, '30d')
    expect(wrapper.vm.preparing.terminal).toBe(true)
    await selectTimeframe(wrapper, '90d')
    expect(wrapper.vm.preparing).toMatchObject({ terminal: false, runGeneration: 2, runId: 'amd-run-2' })
    await selectTimeframe(wrapper, '7d')
    expect(wrapper.vm.preparing).toMatchObject({ terminal: false, fullReady: false, runGeneration: 2, runId: 'amd-run-2' })
  })

  it.each(['fast_ready', 'fill_failed'])(
    'does not cache HTTP 202 and renders IWM 1M strikes when polling reaches %s',
    async state => {
      const wrapper = await mountDashboard('IWM')
      let ready = false
      gexResponse = (symbol, timeframe) => Promise.resolve(
        timeframe === '30d' && !ready
          ? preparation('fast_running', { status: 202 })
          : snapshot(symbol, timeframe, 225),
      )
      statusResponse = () => {
        ready = true
        return Promise.resolve(preparation(state))
      }

      await selectTimeframe(wrapper, '30d')
      expect(wrapper.vm.eodLevels).toBeNull()
      expect(wrapper.vm.cache.has('gex|IWM|30d')).toBe(false)
      expect(wrapper.vm.preparing.active).toBe(true)
      expect(wrapper.vm.eodError).toBe('')

      await advance(2_000)
      expect(wrapper.vm.preparing.fastReady).toBe(true)
      await advance(750)

      expect(gexCalls('30d')).toHaveLength(2)
      expect(wrapper.vm.gexTf).toBe('30d')
      expect(wrapper.vm.eodLevels.symbol).toBe('IWM')
      expect(chartRows(wrapper)).toHaveLength(225)
      expect(wrapper.vm.eodLoading).toBe(false)
      expect(wrapper.vm.eodError).toBe('')
      expect(wrapper.vm.preparing.active).toBe(false)
      if (state === 'fill_failed') {
        expect(wrapper.vm.preparing.partialFailed).toBe(true)
        expect(wrapper.text()).toContain('Partial data for IWM')
      }
      expect(axios.post).not.toHaveBeenCalled()
    },
  )

  it('refreshes an empty HTTP 200 snapshot as soon as its bootstrap is fast-ready', async () => {
    const wrapper = await mountDashboard('IWM')
    let reads = 0
    gexResponse = (symbol, timeframe) => {
      reads += 1
      const response = snapshot(symbol, timeframe, reads === 1 ? 0 : 225)
      if (reads === 1) Object.assign(response.data, preparation('filling').data)
      return Promise.resolve(response)
    }

    await selectTimeframe(wrapper, '30d')
    expect(chartRows(wrapper)).toHaveLength(0)
    await advance(750)

    expect(gexCalls('30d')).toHaveLength(2)
    expect(chartRows(wrapper)).toHaveLength(225)
    expect(wrapper.vm.eodError).toBe('')
  })

  it('refreshes the currently selected timeframe after readiness, not the old polling timeframe', async () => {
    const wrapper = await mountDashboard('IWM')
    gexResponse = (symbol, timeframe) => {
      const response = snapshot(symbol, timeframe)
      if (timeframe === '30d') Object.assign(response.data, preparation('filling').data)
      return Promise.resolve(response)
    }

    await selectTimeframe(wrapper, '30d')
    await selectTimeframe(wrapper, '90d')
    await advance(750)

    expect(gexCalls('30d')).toHaveLength(1)
    expect(gexCalls('90d')).toHaveLength(2)
    expect(wrapper.vm.gexTf).toBe('90d')
    expect(wrapper.vm.eodLevels.timeframe).toBe('90d')
    expect(chartRows(wrapper)).toHaveLength(3)
  })

  it('accepts populated HTTP 200 data even when IWM bootstrap fill has failed', async () => {
    const wrapper = await mountDashboard('IWM')
    gexResponse = (symbol, timeframe) => {
      const response = snapshot(symbol, timeframe, 225)
      Object.assign(response.data, preparation('fill_failed').data)
      return Promise.resolve(response)
    }

    await selectTimeframe(wrapper, '30d')
    await advance(5_000)

    expect(chartRows(wrapper)).toHaveLength(225)
    expect(wrapper.vm.eodError).toBe('')
    expect(wrapper.vm.preparing.active).toBe(false)
    expect(gexCalls('30d')).toHaveLength(1)
    expect(axios.get.mock.calls.some(([url]) => url.startsWith('/api/work-runs/'))).toBe(false)
    expect(axios.post).not.toHaveBeenCalled()
  })

  it('accepts and caches a valid empty no-options HTTP 200 snapshot without preparing', async () => {
    const wrapper = await mountDashboard()
    gexResponse = (symbol, timeframe) => {
      const response = snapshot(symbol, timeframe, 0)
      Object.assign(response.data, preparation('no_options', { symbol }).data)
      return Promise.resolve(response)
    }

    await selectTimeframe(wrapper, '30d')
    await selectTimeframe(wrapper, '14d')
    await selectTimeframe(wrapper, '30d')
    await advance(5_000)

    expect(chartRows(wrapper)).toEqual([])
    expect(wrapper.vm.eodError).toBe('')
    expect(wrapper.vm.preparing.active).toBe(false)
    expect(gexCalls('30d')).toHaveLength(1)
    expect(axios.post).not.toHaveBeenCalled()
  })

  it('clears a previous 504 when returning to a cached timeframe', async () => {
    const wrapper = await mountDashboard()
    gexResponse = () => Promise.reject(gatewayTimeout())
    await selectTimeframe(wrapper, '30d')
    expect(wrapper.vm.eodError).toContain('504')
    expect(wrapper.find('[data-testid="eod-error"]').exists()).toBe(true)

    await selectTimeframe(wrapper, '14d')

    expect(gexCalls('14d')).toHaveLength(1)
    expect(wrapper.vm.eodError).toBe('')
    expect(wrapper.vm.eodLoading).toBe(false)
    expect(wrapper.find('[data-testid="eod-error"]').exists()).toBe(false)
    expect(chartRows(wrapper)).toHaveLength(3)
  })

  it('clears loading on a cache hit while an older request is still pending', async () => {
    const wrapper = await mountDashboard()
    const pending = deferred()
    gexResponse = () => pending.promise
    await selectTimeframe(wrapper, '30d')
    expect(wrapper.vm.eodLoading).toBe(true)

    await selectTimeframe(wrapper, '14d')
    expect(wrapper.vm.eodLoading).toBe(false)
    expect(chartRows(wrapper)).toHaveLength(3)
    pending.reject(gatewayTimeout())
    await flushPromises()
    expect(wrapper.vm.gexTf).toBe('14d')
    expect(wrapper.vm.eodError).toBe('')
    expect(chartRows(wrapper)).toHaveLength(3)
  })

  it.each(['success', 'error'])(
    'ignores an older same-symbol timeframe %s after the latest timeframe has loaded',
    async outcome => {
      const wrapper = await mountDashboard()
      const old = deferred()
      const current = deferred()
      gexResponse = (_symbol, timeframe) => timeframe === '30d' ? old.promise : current.promise
      await selectTimeframe(wrapper, '30d')
      await selectTimeframe(wrapper, '90d')

      current.resolve(snapshot('SPY', '90d', 9))
      await flushPromises()
      expect(chartRows(wrapper)).toHaveLength(9)

      if (outcome === 'success') old.resolve(snapshot('SPY', '30d', 30))
      else old.reject(gatewayTimeout())
      await flushPromises()

      expect(wrapper.vm.gexTf).toBe('90d')
      expect(wrapper.vm.eodLevels.timeframe).toBe('90d')
      expect(chartRows(wrapper)).toHaveLength(9)
      expect(wrapper.vm.eodError).toBe('')
      expect(wrapper.vm.eodLoading).toBe(false)
    },
  )

  it('keeps the latest loading state when an older timeframe finishes first', async () => {
    const wrapper = await mountDashboard()
    const old = deferred()
    const current = deferred()
    gexResponse = (_symbol, timeframe) => timeframe === '30d' ? old.promise : current.promise
    await selectTimeframe(wrapper, '30d')
    await selectTimeframe(wrapper, '90d')

    old.resolve(snapshot('SPY', '30d', 30))
    await flushPromises()
    expect(wrapper.vm.eodLoading).toBe(true)
    expect(wrapper.vm.eodLevels).toBeNull()
    expect(wrapper.vm.gexTf).toBe('90d')

    current.resolve(snapshot('SPY', '90d', 9))
    await flushPromises()
    expect(wrapper.vm.eodLoading).toBe(false)
    expect(chartRows(wrapper)).toHaveLength(9)
  })

  it.each([
    { reason: 'another symbol', symbol: 'IWM', mode: 'eod' },
    { reason: 'another mode', symbol: 'SPY', mode: 'intraday' },
  ])('ignores an early stale caller for $reason without changing existing state', async ({ symbol, mode }) => {
    const wrapper = await mountDashboard()
    wrapper.vm.cache.set('gex|IWM|14d', { t: Date.now(), data: snapshot('IWM').data })
    wrapper.vm.dataMode = mode
    await nextTick()
    await flushPromises()
    wrapper.vm.eodError = 'Previous 504'
    wrapper.vm.eodLoading = true
    const levels = wrapper.vm.eodLevels
    const cacheEntries = Array.from(wrapper.vm.cache.entries())
    const updated = wrapper.vm.lastUpdated
    const requests = axios.get.mock.calls.length
    await nextTick()

    await wrapper.vm.fetchGexLevelsEOD(symbol, '14d')
    await flushPromises()

    expect(wrapper.vm.eodLevels).toBe(levels)
    expect(wrapper.vm.eodError).toBe('Previous 504')
    expect(wrapper.vm.eodLoading).toBe(true)
    expect(wrapper.vm.lastUpdated).toBe(updated)
    expect(wrapper.vm.gexTf).toBe('14d')
    expect(Array.from(wrapper.vm.cache.entries())).toEqual(cacheEntries)
    expect(axios.get.mock.calls).toHaveLength(requests)
  })

  it('does not recursively retry a 404 that advertises only the requested timeframe', async () => {
    const wrapper = await mountDashboard()
    let reads = 0
    gexResponse = () => {
      reads += 1
      // Bound a regression to three requests rather than hanging the test runner.
      if (reads > 2) return Promise.reject(gatewayTimeout())
      return Promise.reject({
        response: {
          status: 404,
          data: { error: 'Snapshot unavailable', available_timeframes: ['30d'] },
        },
      })
    }

    // This represents a fallback/readiness caller before the UI selection applies.
    await wrapper.vm.fetchGexLevelsEOD('SPY', '30d')
    await flushPromises()

    expect(gexCalls('30d')).toHaveLength(1)
    expect(wrapper.vm.gexTf).toBe('14d')
    expect(wrapper.vm.eodError).toBe('Snapshot unavailable')
    expect(wrapper.vm.eodLoading).toBe(false)
    expect(wrapper.vm.cache.has('gex|SPY|30d')).toBe(false)
    expect(axios.post).not.toHaveBeenCalled()
  })

  it('coalesces duplicate transport requests while applying the newest caller response', async () => {
    const wrapper = await mountDashboard()
    const month = deferred()
    const quarter = deferred()
    gexResponse = (_symbol, timeframe) => timeframe === '30d' ? month.promise : quarter.promise
    await selectTimeframe(wrapper, '30d')
    await selectTimeframe(wrapper, '90d')
    await selectTimeframe(wrapper, '30d')

    expect(gexCalls('30d')).toHaveLength(1)
    expect(gexCalls('90d')).toHaveLength(1)
    month.resolve(snapshot('SPY', '30d', 30))
    await flushPromises()

    expect(wrapper.vm.gexTf).toBe('30d')
    expect(wrapper.vm.eodLevels.timeframe).toBe('30d')
    expect(chartRows(wrapper)).toHaveLength(30)
    expect(wrapper.vm.eodLoading).toBe(false)

    quarter.resolve(snapshot('SPY', '90d', 9))
    await flushPromises()
    expect(wrapper.vm.gexTf).toBe('30d')
    expect(chartRows(wrapper)).toHaveLength(30)
    expect(wrapper.vm.eodError).toBe('')
  })
})
