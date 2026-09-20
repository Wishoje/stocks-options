import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import Modal from '@/Components/Modal.vue'
import Scanner from '@/Pages/Scanner.vue'
import {
  closestStrikeGex,
  compareWallHits,
  scannerChunks,
  scannerNumber,
  scannerStateFromSearch,
  scannerUrl,
  volumeDashboardUrl,
  wallDashboardUrl,
  wallTagsFor,
} from '@/Pages/Scanner/scannerUtils.js'

vi.mock('axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
  },
}))

const AppLayout = { template: '<div><slot name="header"/><slot/></div>' }
const AppShell = { template: '<div class="gex-ui"><slot/></div>' }
const DialogModal = {
  props: ['show', 'labelledby'],
  emits: ['close'],
  template: '<div v-if="show" class="dialog-stub" role="dialog" :aria-labelledby="labelledby"><slot name="title"/><slot name="content"/><slot name="footer"/></div>',
}
const NetGexChart = { props: ['strikeData', 'eod'], template: '<div data-testid="net-gex-stub">{{ strikeData.length }} exact strikes</div>' }

function mountScanner() {
  return mount(Scanner, {
    attachTo: document.body,
    global: { stubs: { AppLayout, AppShell, DialogModal, NetGexChart } },
  })
}

function volumePayload(items, options = {}) {
  const lookbackApplied = options.lookbackApplied ?? false
  const limit = options.limit ?? 200
  return {
    trade_date: options.tradeDate ?? '2026-09-11',
    limit,
    source: lookbackApplied ? 'fallback_db' : 'hot_option_symbols',
    symbols: items.map(item => item.symbol),
    items,
    meta: {
      count: items.length,
      available_count: options.availableCount ?? items.length,
      source: options.backendSource ?? (lookbackApplied ? 'polygon_eod_fallback' : 'steadyapi'),
      total_vol: items.reduce((sum, item) => sum + (item.total_volume ?? 0), 0),
      avg_pcr: options.avgPcr ?? 0,
      window_start: options.windowStart ?? (lookbackApplied ? '2026-09-02' : null),
      window_end: options.windowEnd ?? (lookbackApplied ? '2026-09-11' : null),
      requested_days: options.requestedDays ?? 10,
      effective_days: options.effectiveDays ?? (lookbackApplied ? 10 : null),
      lookback_control_applied: lookbackApplied,
      scope: options.scope ?? (lookbackApplied ? 'local_chain_fallback' : 'source_defined_session'),
    },
  }
}

function wallCoverage(overrides = {}) {
  return {
    requested_symbols: 1,
    requested_timeframes: 4,
    requested_pairs: 4,
    latest_rows: 0,
    stale_rows: 0,
    invalid_rows: 0,
    no_wall_rows: 0,
    invalid_or_no_wall_rows: 0,
    usable_rows: 0,
    unmatched_usable_rows: 0,
    matched_rows: 0,
    ...overrides,
  }
}

function wallPayload(items = [], options = {}) {
  const byTimeframe = items.reduce((groups, item) => {
    const timeframe = item.timeframe
    if (!groups[timeframe]) groups[timeframe] = []
    groups[timeframe].push(item)
    return groups
  }, {})
  return {
    near_pct: options.nearPct ?? 1,
    near_pts: options.nearPts ?? null,
    timeframes: ['1d', '7d', '14d', '30d'],
    applied: {
      near_pct: options.nearPct ?? 1,
      near_pts: options.nearPts ?? null,
      timeframes: ['1d', '7d', '14d', '30d'],
    },
    coverage: wallCoverage({
      latest_rows: items.length,
      usable_rows: items.length,
      matched_rows: items.length,
      ...options.coverage,
    }),
    items,
    by_timeframe: byTimeframe,
    extra: 'preserved',
  }
}

function wallItem(symbol, side = 'call', distancePct = 0.5, distancePts = 2.5) {
  const key = 'eod_' + side
  return {
    symbol,
    spot: 500,
    timeframe: '30d',
    trade_date: '2026-09-11',
    hits: [key],
    walls: {
      [key]: {
        strike: side === 'call' ? 502.5 : 497.5,
        distance_pc: distancePct,
        distance_pt: distancePts,
      },
    },
  }
}

beforeEach(() => {
  window.history.replaceState({}, '', '/scanner')
  axios.get.mockImplementation((url) => {
    if (url === '/api/watchlist') return Promise.resolve({ data: [{ id: 1, symbol: 'SPY' }] })
    if (url === '/api/watchlist/universe') return Promise.resolve({ data: [{ symbol: 'SPY' }] })
    if (url === '/api/hot-options') return Promise.resolve({ data: volumePayload([]) })
    if (url === '/api/gex-levels') return Promise.resolve({ data: { data_date: '2026-09-11', strike_data: [] } })
    return Promise.reject(new Error('Unexpected GET ' + url))
  })
  axios.post.mockImplementation((url) => {
    if (url === '/api/scanner/walls') return Promise.resolve({ data: wallPayload([]) })
    return Promise.reject(new Error('Unexpected POST ' + url))
  })
  axios.delete.mockResolvedValue({ data: null })
})

afterEach(() => {
  vi.useRealTimers()
  vi.clearAllMocks()
  document.body.innerHTML = ''
})

describe('scanner data helpers', () => {
  it('keeps zero distinct from unavailable across wall fields and GEX matching', () => {
    expect(scannerNumber(0)).toBe(0)
    expect(scannerNumber(null)).toBeNull()
    expect(scannerNumber('')).toBeNull()

    const labels = {
      eod_call: { kind: 'Call wall', timeframe: 'End-of-day', timeframeShort: 'EOD' },
      eod_put: { kind: 'Put wall', timeframe: 'End-of-day', timeframeShort: 'EOD' },
    }
    const tags = wallTagsFor({
      hits: ['eod_call'],
      walls: {
        eod_call: { strike: 0, distance_pc: 0, distance_pt: 0 },
        eod_put: { strike: 90, distance_pc: 10, distance_pt: 10 },
      },
    }, labels)
    expect(tags).toEqual([expect.objectContaining({ side: 'call', strike: 0, distancePct: 0, distancePts: 0 })])

    expect(closestStrikeGex([
      { strike: 100, net_gex: null },
      { strike: 101, net_gex: 0 },
    ], 100)).toEqual({ strike: 101, netGex: 0, pctOfMax: null })
  })

  it('creates context-aware dashboard handoffs and round-trips applied scanner controls', () => {
    expect(volumeDashboardUrl(' aapl ')).toBe('/dashboard?symbol=AAPL&mode=eod&tab=overview&timeframe=14d')
    expect(wallDashboardUrl('spy', { key: 'eod_call' }, '30d')).toBe('/dashboard?symbol=SPY&mode=eod&tab=strikes&timeframe=30d')
    expect(wallDashboardUrl('qqq', { key: 'intraday_put' }, '7d')).toBe('/dashboard?symbol=QQQ&mode=intraday&tab=strikes&timeframe=7d')

    const url = scannerUrl('https://example.test/scanner?campaign=review#results', {
      mode: 'volume', density: 'compact', limit: 500, days: 20, nearPct: 0, nearPts: 0, sort: 'put',
    })
    expect(url).toBe('/scanner?campaign=review&scan=volume&density=compact&limit=500&days=20&near_pct=0&near_pts=0&sort=put#results')
    expect(scannerStateFromSearch(new URL(url, 'https://example.test').search)).toEqual({
      mode: 'volume', density: 'compact', limit: 500, days: 20, nearPct: 0, nearPts: 0, sort: 'put',
    })
    expect(scannerUrl('https://example.test/scanner?days=20', {
      mode: 'volume', density: 'comfortable', limit: 200, days: null, nearPct: 1, nearPts: null, sort: 'nearest',
    })).not.toContain('days=')
  })

  it('chunks a global universe at the API limit without dropping or duplicating symbols', () => {
    const symbols = [...Array.from({ length: 1_201 }, (_, index) => 'S' + index), 's0', '', null]
    const chunks = scannerChunks(symbols)
    expect(chunks.map(chunk => chunk.length)).toEqual([500, 500, 201])
    expect(new Set(chunks.flat()).size).toBe(1_201)
  })

  it('sorts copies by wall priority and retains source arrays', () => {
    const source = Object.freeze([
      Object.freeze({ symbol: 'PUT', hits: ['eod_put'], walls: { eod_put: { distance_pc: 0.8 } } }),
      Object.freeze({ symbol: 'CALL', hits: ['eod_call'], walls: { eod_call: { distance_pc: 0.9 } } }),
      Object.freeze({ symbol: 'NEAR', hits: ['eod_call'], walls: { eod_call: { distance_pc: 0 } } }),
    ])
    expect([...source].sort((a, b) => compareWallHits(a, b, 'nearest')).map(row => row.symbol)).toEqual(['NEAR', 'PUT', 'CALL'])
    expect([...source].sort((a, b) => compareWallHits(a, b, 'put'))[0].symbol).toBe('PUT')
    expect(source.map(row => row.symbol)).toEqual(['PUT', 'CALL', 'NEAR'])
  })
})

describe('scanner page', () => {
  it('labels authoritative stored rankings truthfully and disables unsupported lookback changes', async () => {
    window.history.replaceState({}, '', '/scanner?scan=volume&limit=200&days=20')
    axios.get.mockImplementation((url) => {
      if (url === '/api/watchlist' || url === '/api/watchlist/universe') return Promise.resolve({ data: [] })
      if (url === '/api/hot-options') return Promise.resolve({ data: volumePayload([
        { symbol: 'SPY', rank: 1, total_volume: 100, put_call: 1, last_price: 500 },
      ], {
        limit: 200,
        availableCount: 200,
        backendSource: 'polygon_eod',
        windowStart: '2026-09-02',
        windowEnd: '2026-09-11',
        effectiveDays: 10,
        scope: 'stored_snapshot',
        requestedDays: 20,
      }) })
      return Promise.reject(new Error('Unexpected GET ' + url))
    })

    const wrapper = mountScanner()
    await flushPromises()

    expect(wrapper.text()).toContain('Stored source window 2026-09-02 to 2026-09-11')
    expect(wrapper.text()).toContain('200 available / requested top 200')
    const lookbackButtons = wrapper.findAll('.scanner-toolbar fieldset').at(1).findAll('button')
    expect(lookbackButtons).toHaveLength(3)
    expect(lookbackButtons.every(button => button.attributes('disabled') !== undefined)).toBe(true)
    expect(wrapper.text()).toContain('source-defined')
    expect(axios.get.mock.calls.filter(([url]) => url === '/api/hot-options')).toHaveLength(1)
    wrapper.unmount()
  })

  it('removes a misleading lookback query for a source-defined current-session ranking', async () => {
    window.history.replaceState({}, '', '/scanner?scan=volume&limit=200&days=20')
    axios.get.mockImplementation((url) => {
      if (url === '/api/watchlist' || url === '/api/watchlist/universe') return Promise.resolve({ data: [] })
      if (url === '/api/hot-options') return Promise.resolve({ data: volumePayload([
        { symbol: 'IWM', rank: 1, total_volume: 10, put_call: 0, last_price: 0 },
      ], { effectiveDays: null, requestedDays: 20 }) })
      return Promise.reject(new Error('Unexpected GET ' + url))
    })

    const wrapper = mountScanner()
    await flushPromises()

    expect(wrapper.text()).toContain('Source-defined current-session ranking')
    expect(window.location.search).not.toContain('days=')
    wrapper.unmount()
  })

  it('ignores a late volume response after the fallback lookback changes', async () => {
    window.history.replaceState({}, '', '/scanner?scan=volume&limit=100&days=10')
    let resolveOld
    let hotRequest = 0
    axios.get.mockImplementation((url) => {
      if (url === '/api/watchlist' || url === '/api/watchlist/universe') return Promise.resolve({ data: [] })
      if (url === '/api/hot-options') {
        hotRequest += 1
        if (hotRequest === 1) return new Promise(resolve => { resolveOld = resolve })
        return Promise.resolve({ data: volumePayload([
          { symbol: 'NEW', rank: 1, total_volume: 5, put_call: 1, last_price: 1 },
        ], { limit: 100, lookbackApplied: true, requestedDays: 5, effectiveDays: 5 }) })
      }
      return Promise.reject(new Error('Unexpected GET ' + url))
    })

    const wrapper = mountScanner()
    await wrapper.findAll('.scanner-toolbar fieldset').at(1).findAll('button')[0].trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('NEW')

    resolveOld({ data: volumePayload([
      { symbol: 'OLD', rank: 1, total_volume: 9, put_call: 1, last_price: 1 },
    ], { limit: 100, lookbackApplied: true }) })
    await flushPromises()
    expect(wrapper.text()).toContain('NEW')
    expect(wrapper.text()).not.toContain('OLD')
    wrapper.unmount()
  })

  it('keeps applied volume provenance and rows when a new scope fails', async () => {
    window.history.replaceState({}, '', '/scanner?scan=volume&limit=200&days=10')
    let hotRequest = 0
    axios.get.mockImplementation((url) => {
      if (url === '/api/watchlist' || url === '/api/watchlist/universe') return Promise.resolve({ data: [] })
      if (url === '/api/hot-options') {
        hotRequest += 1
        if (hotRequest === 1) return Promise.resolve({ data: volumePayload([
          { symbol: 'SPY', rank: 1, total_volume: 5, put_call: 1, last_price: 500 },
        ], { limit: 200, availableCount: 1, lookbackApplied: true }) })
        return Promise.reject(new Error('refresh unavailable'))
      }
      return Promise.reject(new Error('Unexpected GET ' + url))
    })

    const wrapper = mountScanner()
    await flushPromises()
    await wrapper.findAll('.scanner-toolbar fieldset').at(0).findAll('button')[2].trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('SPY')
    expect(wrapper.text()).toContain('Retaining the applied top 200 results (10-day EOD fallback window).')
    expect(wrapper.text()).toContain('1 available / requested top 200')
    expect(window.location.search).toContain('limit=200')
    wrapper.unmount()
  })

  it('renders 400 results with zeros and metric-rich accessible names without nested buttons', async () => {
    window.history.replaceState({}, '', '/scanner?scan=volume&limit=400&days=10')
    const items = Array.from({ length: 400 }, (_, index) => ({
      symbol: 'S' + index,
      rank: index + 1,
      total_volume: index === 0 ? 0 : index * 100,
      put_call: index === 0 ? 0 : 1,
      last_price: index === 0 ? 0 : 100 + index,
      raw_marker: 'preserved-' + index,
    }))
    axios.get.mockImplementation((url) => {
      if (url === '/api/watchlist' || url === '/api/watchlist/universe') return Promise.resolve({ data: [] })
      if (url === '/api/hot-options') return Promise.resolve({ data: volumePayload(items, {
        limit: 400,
        availableCount: 400,
        lookbackApplied: true,
      }) })
      return Promise.reject(new Error('Unexpected GET ' + url))
    })

    const wrapper = mountScanner()
    await flushPromises()

    expect(wrapper.findAll('.scanner-result-card')).toHaveLength(400)
    expect(wrapper.find('.scanner-result-card').text()).toContain('0.00')
    expect(wrapper.find('.scanner-result-card').text()).toContain('$0.00')
    expect(wrapper.get('.scanner-result-card__open').attributes('aria-label')).toContain('total volume 0')
    expect(wrapper.get('.scanner-result-card__open').attributes('aria-label')).toContain('put call ratio 0.00')
    expect(wrapper.get('.scanner-result-card__open').attributes('aria-label')).toContain('last price $0.00')
    expect(wrapper.findAll('button button')).toHaveLength(0)

    await wrapper.findAll('.scanner-density button')[1].trigger('click')
    expect(wrapper.get('.scanner-page').attributes('data-density')).toBe('compact')
    expect(axios.get.mock.calls.filter(([url]) => url === '/api/hot-options')).toHaveLength(1)
    wrapper.unmount()
  })

  it('scans universes over 500 symbols in bounded requests and merges coverage', async () => {
    const universe = Array.from({ length: 501 }, (_, index) => ({ symbol: 'S' + index }))
    axios.get.mockImplementation((url) => {
      if (url === '/api/watchlist') return Promise.resolve({ data: [] })
      if (url === '/api/watchlist/universe') return Promise.resolve({ data: universe })
      if (url === '/api/hot-options') return Promise.resolve({ data: volumePayload([]) })
      return Promise.reject(new Error('Unexpected GET ' + url))
    })
    axios.post.mockImplementation((url, body) => {
      if (url !== '/api/scanner/walls') return Promise.reject(new Error('Unexpected POST ' + url))
      const items = body.symbols.map(symbol => wallItem(symbol))
      return Promise.resolve({ data: wallPayload(items, {
        coverage: {
          requested_symbols: body.symbols.length,
          requested_pairs: body.symbols.length * 4,
        },
      }) })
    })

    const wrapper = mountScanner()
    await flushPromises()
    const scans = axios.post.mock.calls.filter(([url]) => url === '/api/scanner/walls')
    expect(scans).toHaveLength(2)
    expect(scans.map(([, body]) => body.symbols.length)).toEqual([500, 1])
    expect(wrapper.findAll('.scanner-wall-result')).toHaveLength(501)
    expect(wrapper.text()).toContain('2004 requested pairs')
    wrapper.unmount()
  })

  it('shows invalid draft thresholds immediately without changing applied state or requesting data', async () => {
    const wrapper = mountScanner()
    await flushPromises()
    const initialRequests = axios.post.mock.calls.filter(([url]) => url === '/api/scanner/walls').length
    const percentInput = wrapper.findAll('.scanner-wall-toolbar input')[0]

    await percentInput.setValue('101')
    await flushPromises()

    expect(wrapper.get('#scanner-wall-validation').text()).toContain('Enter a percent from 0 to 100.')
    expect(percentInput.attributes('aria-invalid')).toBe('true')
    expect(percentInput.attributes('aria-describedby')).toBe('scanner-wall-validation')
    const apply = wrapper.findAll('button').find(button => button.text().includes('Apply and scan'))
    expect(apply.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('1.0% / nearest')
    expect(window.location.search).toContain('near_pct=1')
    expect(axios.post.mock.calls.filter(([url]) => url === '/api/scanner/walls')).toHaveLength(initialRequests)
    wrapper.unmount()
  })

  it('retains applied wall thresholds and matches when a draft refresh fails', async () => {
    let scans = 0
    axios.post.mockImplementation((url) => {
      if (url !== '/api/scanner/walls') return Promise.reject(new Error('Unexpected POST ' + url))
      scans += 1
      if (scans === 1) return Promise.resolve({ data: wallPayload([wallItem('SPY')]) })
      return Promise.reject(new Error('wall refresh unavailable'))
    })

    const wrapper = mountScanner()
    await flushPromises()
    await wrapper.findAll('.scanner-wall-toolbar input')[0].setValue('0.5')
    const apply = wrapper.findAll('button').find(button => button.text().includes('Apply and scan'))
    await apply.trigger('click')
    await flushPromises()

    expect(wrapper.findAll('.scanner-wall-result')).toHaveLength(1)
    expect(wrapper.text()).toContain('Retaining results for the applied 1.0% threshold.')
    expect(wrapper.text()).toContain('Changes not applied')
    expect(wrapper.text()).toContain('1.0% / nearest')
    expect(window.location.search).toContain('near_pct=1')
    wrapper.unmount()
  })

  it('does not claim results were retained when the first wall request fails', async () => {
    axios.post.mockRejectedValue(new Error('wall service unavailable'))

    const wrapper = mountScanner()
    await flushPromises()

    expect(wrapper.text()).toContain('wall service unavailable')
    expect(wrapper.text()).not.toContain('Retaining results')
    wrapper.unmount()
  })

  it('sorts existing wall results immediately without another scan and stores the sort in history state', async () => {
    axios.post.mockResolvedValue({ data: wallPayload([
      wallItem('CALL', 'call', 0.1, 0.5),
      wallItem('PUT', 'put', 0.8, 4),
    ]) })

    const wrapper = mountScanner()
    await flushPromises()
    const scansBefore = axios.post.mock.calls.filter(([url]) => url === '/api/scanner/walls').length
    expect(wrapper.findAll('.scanner-wall-result')[0].text()).toContain('CALL')

    const putSort = wrapper.findAll('.scanner-wall-sort button').find(button => button.text().includes('Put walls'))
    await putSort.trigger('click')
    await flushPromises()

    expect(wrapper.findAll('.scanner-wall-result')[0].text()).toContain('PUT')
    expect(axios.post.mock.calls.filter(([url]) => url === '/api/scanner/walls')).toHaveLength(scansBefore)
    expect(window.location.search).toContain('sort=put')
    expect(wrapper.text()).toContain('1.0% / put')
    wrapper.unmount()
  })

  it('distinguishes stale wall coverage from a true threshold miss', async () => {
    axios.post.mockResolvedValue({ data: wallPayload([], {
      coverage: {
        requested_pairs: 4,
        latest_rows: 4,
        stale_rows: 4,
        usable_rows: 0,
        matched_rows: 0,
      },
    }) })

    const wrapper = mountScanner()
    await flushPromises()

    expect(wrapper.text()).toContain('Wall snapshots are outside the freshness window')
    expect(wrapper.text()).toContain('4 latest rows: 4 stale')
    expect(wrapper.text()).not.toContain('No walls matched the applied thresholds')
    wrapper.unmount()
  })

  it('shows all wall distances, names the detail dialog, and restores focus after close', async () => {
    const wall = {
      ...wallItem('SPY', 'call', 0, 0),
      walls: {
        eod_call: { strike: 500, distance_pc: 0, distance_pt: 0 },
        eod_put: { strike: 480, distance_pc: 4, distance_pt: 20 },
      },
      raw_marker: 'wall-row',
    }
    axios.post.mockResolvedValue({ data: wallPayload([wall], { nearPts: 0 }) })
    axios.get.mockImplementation((url) => {
      if (url === '/api/watchlist') return Promise.resolve({ data: [{ id: 1, symbol: 'SPY' }] })
      if (url === '/api/watchlist/universe') return Promise.resolve({ data: [{ symbol: 'SPY' }] })
      if (url === '/api/hot-options') return Promise.resolve({ data: volumePayload([]) })
      if (url === '/api/gex-levels') return Promise.resolve({ data: {
        data_date: '2026-09-11',
        strike_data: [{ strike: 500, net_gex: 0, note: 'exact zero' }],
        extra_detail: true,
      } })
      return Promise.reject(new Error('Unexpected GET ' + url))
    })

    const wrapper = mountScanner()
    await flushPromises()
    const trigger = wrapper.get('.scanner-wall-tag')
    expect(trigger.text()).toContain('0.00%')
    expect(trigger.text()).toContain('$0.00')
    expect(trigger.attributes('aria-label')).toContain('30d')
    expect(trigger.attributes('aria-label')).toContain('0.00 percent away')
    expect(trigger.attributes('aria-label')).toContain('0.00 points away')

    trigger.element.focus()
    await trigger.trigger('click')
    await flushPromises()
    expect(wrapper.get('.dialog-stub').attributes('aria-labelledby')).toBe('scanner-wall-detail-title')
    expect(wrapper.get('#scanner-wall-detail-title').text()).toContain('SPY / 30D / Call wall')
    expect(wrapper.get('[data-testid="net-gex-stub"]').text()).toContain('1 exact strikes')
    expect(wrapper.text()).toContain('EOD Net GEX near wall')

    vi.useFakeTimers()
    await wrapper.findAll('.dialog-stub button').at(-1).trigger('click')
    await vi.advanceTimersByTimeAsync(220)
    expect(document.activeElement).toBe(trigger.element)
    wrapper.unmount()
  })

  it('aborts an in-flight universe request when the page unmounts', async () => {
    let universeSignal
    axios.get.mockImplementation((url, config = {}) => {
      if (url === '/api/watchlist') return Promise.resolve({ data: [] })
      if (url === '/api/watchlist/universe') {
        universeSignal = config.signal
        return new Promise(() => {})
      }
      if (url === '/api/hot-options') return Promise.resolve({ data: volumePayload([]) })
      return Promise.reject(new Error('Unexpected GET ' + url))
    })

    const wrapper = mountScanner()
    await flushPromises()
    expect(universeSignal.aborted).toBe(false)
    wrapper.unmount()
    expect(universeSignal.aborted).toBe(true)
  })
})

describe('scanner dialog accessibility', () => {
  it('forwards a visible title id to the native dialog', async () => {
    const showModal = vi.fn()
    const close = vi.fn()
    const wrapper = mount(Modal, {
      props: { show: false, labelledby: 'scanner-title' },
      slots: { default: '<h2 id="scanner-title">Scanner wall detail</h2>' },
      attachTo: document.body,
    })
    wrapper.get('dialog').element.showModal = showModal
    wrapper.get('dialog').element.close = close

    await wrapper.setProps({ show: true })
    expect(showModal).toHaveBeenCalledOnce()
    expect(wrapper.get('dialog').attributes('aria-labelledby')).toBe('scanner-title')
    expect(wrapper.get('dialog').attributes('aria-label')).toBeUndefined()
    wrapper.unmount()
  })
})
