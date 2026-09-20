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
const NEXT_OPEN = '2026-09-08T13:30:00Z'
const wrappers = []
let summaryResponse
let strikesResponse

function response(data) {
  return { status: 200, data, headers: {} }
}

function summary(overrides = {}) {
  return {
    open: true,
    asof: '2026-09-04T14:40:00Z',
    source_asof: '2026-09-04T14:40:00Z',
    received_at: '2026-09-04T14:59:59Z',
    ingestion_completed_at: '2026-09-04T14:59:59Z',
    snapshot_available: true,
    refresh_eligible: false,
    source_timestamp_complete: true,
    totals: { call_vol: 120, put_vol: 80, premium: 5000, pcr_vol: 0.67 },
    market_session: { phase: 'regular', refresh_allowed: true, next_open_at: NEXT_OPEN },
    ...overrides,
  }
}

function strikes(overrides = {}) {
  return {
    ...summary(),
    items: [{
      strike: 500,
      call_vol: 120,
      put_vol: 80,
      oi_call_eod: 1000,
      oi_put_eod: 900,
      vol_oi: 0.11,
      pcr: 0.67,
      call_prem: 3000,
      put_prem: 2000,
      net_gex_live: 50000,
      net_gex_delta: 1000,
    }],
    ...overrides,
  }
}

function calls(url) {
  return axios.get.mock.calls.filter(([path]) => path === url)
}

function pulls() {
  return axios.post.mock.calls.filter(([path]) => path === '/api/intraday/pull')
}

async function advance(ms) {
  await vi.advanceTimersByTimeAsync(ms)
  await flushPromises()
}

async function changeMode(wrapper, mode) {
  wrapper.vm.setMode(mode)
  await nextTick()
  await flushPromises()
}

async function mountIntraday() {
  const wrapper = shallowMount(Dashboard)
  wrappers.push(wrapper)
  await flushPromises()
  await advance(300)
  expect(wrapper.vm.dataMode).toBe('eod')
  await changeMode(wrapper, 'intraday')
  return wrapper
}

describe('Dashboard intraday ingestion freshness', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'] })
    vi.setSystemTime(new Date('2026-09-04T15:00:00Z'))
    window.history.replaceState({}, '', '/')
    localStorage.setItem('gex_onboarding_v1', 'seen')
    axios.get.mockReset()
    axios.post.mockReset()
    summaryResponse = () => Promise.resolve(response(summary()))
    strikesResponse = () => Promise.resolve(response(strikes()))
    axios.get.mockImplementation((url, options = {}) => {
      if (url === '/api/intraday/summary') return summaryResponse(options)
      if (url === '/api/intraday/strikes') return strikesResponse(options)
      if (url === '/api/gex-levels') {
        return Promise.resolve(response({
          symbol: options.params.symbol,
          timeframe: options.params.timeframe,
          data_date: '2026-09-03',
          available_timeframes: TIMEFRAMES,
          timeframe_expirations: Object.fromEntries(TIMEFRAMES.map(tf => [tf, ['2026-09-18']])),
          strike_data: [{ strike: 500, net_gex: 1000, call_oi: 100, put_oi: 50 }],
        }))
      }
      if (['/api/dex', '/api/iv/term', '/api/ua', '/api/intraday/ua'].includes(url)) {
        return Promise.resolve(response({}))
      }
      return Promise.reject(new Error('Unexpected API request: ' + url))
    })
    axios.post.mockImplementation((url) => url === '/api/intraday/pull'
      ? Promise.resolve({ status: 202, data: { status: 'queued' }, headers: {} })
      : Promise.reject(new Error('Unexpected API mutation: ' + url)))
  })

  afterEach(() => {
    wrappers.splice(0).forEach(wrapper => wrapper.unmount())
    vi.clearAllTimers()
    vi.useRealTimers()
    window.history.replaceState({}, '', '/')
  })

  it('obeys refresh_eligible=false even when the displayed source is twenty minutes old', async () => {
    const wrapper = await mountIntraday()

    expect(calls('/api/intraday/summary')).toHaveLength(1)
    expect(calls('/api/intraday/strikes')).toHaveLength(1)
    expect(pulls()).toHaveLength(0)
    expect(wrapper.vm.lastUpdated).toBe('2026-09-04T14:40:00Z')
    expect(wrapper.vm.intradaySourceLabel).toBe('Delayed (20m)')
    expect(wrapper.text()).toContain('Delayed (20m)')
    expect(wrapper.vm.intradayHasData).toBe(true)
    expect(wrapper.vm.intradayLevels.call_volume_total).toBe(120)

    await advance(30_000)
    expect(calls('/api/intraday/summary')).toHaveLength(1)
    expect(pulls()).toHaveLength(0)
  })

  it('keeps market-session state unknown while the first intraday summary is pending', async () => {
    let resolveSummary
    summaryResponse = () => new Promise(resolve => { resolveSummary = resolve })
    const wrapper = shallowMount(Dashboard)
    wrappers.push(wrapper)
    await flushPromises()
    await advance(300)

    wrapper.vm.setMode('intraday')
    await nextTick()
    await flushPromises()

    expect(wrapper.vm.marketOpen).toBeNull()
    expect(wrapper.vm.intradaySourceLabel).toBe('Loading session state')
    expect(wrapper.get('.gex-dashboard-freshness').attributes('data-state')).toBe('loading')
    expect(wrapper.text()).not.toContain('Market Closed')

    resolveSummary(response(summary()))
    await flushPromises()
    expect(wrapper.vm.marketOpen).toBe(true)
  })

  it('queues once after the eligibility read instead of also posting on the mode switch', async () => {
    summaryResponse = () => Promise.resolve(response(summary({ refresh_eligible: true })))
    const wrapper = await mountIntraday()

    expect(pulls()).toEqual([['/api/intraday/pull', { symbols: ['SPY'] }, { signal: expect.any(AbortSignal) }]])
    const summaryIndex = axios.get.mock.calls.findIndex(([url]) => url === '/api/intraday/summary')
    expect(axios.get.mock.invocationCallOrder[summaryIndex]).toBeLessThan(axios.post.mock.invocationCallOrder[0])
    await wrapper.vm.refreshIntraday()
    await flushPromises()
    expect(pulls()).toHaveLength(1)
    expect(calls('/api/intraday/summary')).toHaveLength(1)
  })

  it.each([false, true])('manual refresh respects eligibility=%s without a second POST', async eligible => {
    const wrapper = await mountIntraday()
    summaryResponse = () => Promise.resolve(response(summary({ refresh_eligible: eligible })))
    await wrapper.vm.manualRefresh()
    await flushPromises()

    expect(calls('/api/intraday/summary')).toHaveLength(2)
    expect(calls('/api/intraday/strikes')).toHaveLength(2)
    expect(pulls()).toHaveLength(eligible ? 1 : 0)
  })

  it('keeps an available open-session snapshot with unknown source time and shows no fake clock', async () => {
    const unknown = { asof: null, source_asof: null, source_timestamp_complete: false }
    summaryResponse = () => Promise.resolve(response(summary(unknown)))
    strikesResponse = () => Promise.resolve(response(strikes(unknown)))
    const wrapper = await mountIntraday()

    expect(wrapper.vm.intradaySnapshotAvailable).toBe(true)
    expect(wrapper.vm.intradayHasData).toBe(true)
    expect(wrapper.vm.intradaySnapshotAsOf).toBeNull()
    expect(wrapper.vm.lastUpdated).toBeNull()
    expect(wrapper.vm.intradaySourceLabel).toBe('Provider update time unavailable')
    expect(wrapper.text()).toContain('Provider update time unavailable')
    expect(wrapper.vm.cacheIntraday.get('SPY')).toMatchObject({ available: true, asof: null })
    expect(pulls()).toHaveLength(0)

    await wrapper.vm.refreshIntraday()
    await flushPromises()
    expect(calls('/api/intraday/summary')).toHaveLength(1)
    expect(wrapper.vm.lastUpdated).toBeNull()
    expect(wrapper.vm.intradayLevels.strike_data).toHaveLength(1)
  })

  it('uses the explicit provider source clock and preserves raw null and zero fields', async () => {
    const providerTime = '2026-09-04T14:40:00Z'
    const receiptTime = '2026-09-04T14:59:59Z'
    summaryResponse = () => Promise.resolve(response(summary({
      asof: receiptTime,
      source_asof: providerTime,
      received_at: receiptTime,
      ingestion_completed_at: receiptTime,
    })))
    strikesResponse = () => Promise.resolve(response(strikes({
      asof: receiptTime,
      source_asof: providerTime,
      received_at: receiptTime,
      ingestion_completed_at: receiptTime,
      totals: { call_vol: null, put_vol: 0, premium: null, pcr_vol: null },
      items: [{
        strike: 500,
        call_vol: null,
        put_vol: 0,
        oi_call_eod: null,
        oi_put_eod: 0,
        vol_oi: null,
        pcr: null,
        call_prem: null,
        put_prem: 0,
        net_gex_live: null,
        net_gex_delta: 0,
        provider_contract_count: 17,
      }],
    })))

    const wrapper = await mountIntraday()

    expect(wrapper.vm.intradaySnapshotAsOf).toBe(providerTime)
    expect(wrapper.vm.lastUpdated).toBe(providerTime)
    expect(wrapper.vm.intradayReceivedAt).toBe(receiptTime)
    expect(wrapper.vm.intradayIngestionCompletedAt).toBe(receiptTime)
    expect(wrapper.vm.intradayLevels.call_volume_total).toBeNull()
    expect(wrapper.vm.intradayLevels.put_volume_total).toBe(0)
    expect(wrapper.vm.intradayLevels.premium_total).toBeNull()
    expect(wrapper.vm.intradayLevels.strike_data[0]).toMatchObject({
      call_vol: null,
      put_vol: 0,
      net_gex_live: null,
      net_gex_delta: 0,
      provider_contract_count: 17,
    })
    expect(wrapper.vm.intradayLevels.strike_data[0]).not.toHaveProperty('call_vol_delta')
    expect(wrapper.vm.intradayLevels.strike_data[0]).not.toHaveProperty('put_vol_delta')
    expect(wrapper.vm.intradayLevels.strike_data[0]).not.toHaveProperty('premium_call')
    expect(wrapper.vm.intradayLevels.strike_data[0]).not.toHaveProperty('premium_put')
    expect(wrapper.vm.intradayLevels.intraday_snapshot_meta.items).toBeUndefined()
  })

  it('does not relabel a receipt clock as provider time when the source contract reports unknown', async () => {
    const receiptTime = '2026-09-04T14:59:59Z'
    const unknownSource = {
      asof: receiptTime,
      source_asof: null,
      source_timestamp_complete: false,
      source_timestamp_status: 'unknown',
      received_at: receiptTime,
    }
    summaryResponse = () => Promise.resolve(response(summary(unknownSource)))
    strikesResponse = () => Promise.resolve(response(strikes(unknownSource)))

    const wrapper = await mountIntraday()

    expect(wrapper.vm.intradaySnapshotAvailable).toBe(true)
    expect(wrapper.vm.intradaySnapshotAsOf).toBeNull()
    expect(wrapper.vm.lastUpdated).toBeNull()
    expect(wrapper.vm.intradayReceivedAt).toBe(receiptTime)
    expect(wrapper.vm.intradaySourceTimestampStatus).toBe('unknown')
    expect(wrapper.text()).toContain('Provider update time unavailable')
    expect(wrapper.text()).not.toContain('Source as of')
  })

  it('treats an explicitly missing composite source clock as authoritative', async () => {
    const summarySource = '2026-09-04T14:40:00Z'
    summaryResponse = () => Promise.resolve(response(summary({ source_asof: summarySource })))
    strikesResponse = () => Promise.resolve(response(strikes({
      source_asof: null,
      source_timestamp_complete: false,
      source_timestamp_status: 'unknown',
    })))

    const wrapper = await mountIntraday()

    expect(wrapper.vm.intradaySnapshotAvailable).toBe(true)
    expect(wrapper.vm.intradaySnapshotAsOf).toBeNull()
    expect(wrapper.vm.lastUpdated).toBeNull()
    expect(wrapper.vm.intradaySourceTimestampStatus).toBe('unknown')
    expect(wrapper.text()).toContain('Provider update time unavailable')
    expect(wrapper.text()).not.toContain('Delayed (20m)')
  })

  it('keeps an explicitly available all-zero snapshot available', async () => {
    const emptyTotals = { call_vol: 0, put_vol: 0, total: 0, premium: 0, pcr_vol: null }
    summaryResponse = () => Promise.resolve(response(summary({ totals: emptyTotals })))
    strikesResponse = () => Promise.resolve(response(strikes({ items: [], totals: emptyTotals })))

    const wrapper = await mountIntraday()

    expect(wrapper.vm.intradaySnapshotAvailable).toBe(true)
    expect(wrapper.vm.intradayHasData).toBe(true)
    expect(wrapper.vm.intradayLevels.strike_data).toEqual([])
    expect(wrapper.vm.intradayLevels.call_volume_total).toBe(0)
    expect(wrapper.vm.intradayLevels.put_volume_total).toBe(0)
    expect(wrapper.vm.topError).toBe('')
  })

  it('lets composite snapshot_available=false override the summary availability', async () => {
    summaryResponse = () => Promise.resolve(response(summary({ snapshot_available: true })))
    strikesResponse = () => Promise.resolve(response(strikes({
      snapshot_available: false,
      source_asof: null,
      source_timestamp_complete: false,
      items: [{
        strike: 500,
        call_vol: 0,
        put_vol: 0,
        oi_call_eod: 123,
        oi_put_eod: 456,
        scaffold_marker: 'retained exactly',
      }],
      totals: { call_vol: 0, put_vol: 0, total: 0, premium: 0, pcr_vol: null },
    })))

    const wrapper = await mountIntraday()

    expect(wrapper.vm.intradaySnapshotAvailable).toBe(false)
    expect(wrapper.vm.intradayHasData).toBe(false)
    expect(wrapper.vm.cacheIntraday.has('SPY')).toBe(false)
    expect(wrapper.vm.intradayLevels.strike_data).toEqual([{
      strike: 500,
      call_vol: 0,
      put_vol: 0,
      oi_call_eod: 123,
      oi_put_eod: 456,
      scaffold_marker: 'retained exactly',
    }])

    wrapper.vm.activate('strikes')
    await nextTick()
    await flushPromises()
    expect(wrapper.findComponent({ name: 'VolOverOiChart' }).exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'PcrByStrikeChart' }).exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'PremiumByStrikeChart' }).exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'UiPanel' }).props('title')).toBe('Live strikes')
  })

  it('hides the previous symbol payload immediately during an intraday symbol change', async () => {
    const wrapper = await mountIntraday()
    expect(wrapper.vm.intradayDataSymbol).toBe('SPY')
    expect(wrapper.vm.levels.strike_data).toHaveLength(1)

    wrapper.vm.userSymbol = 'QQQ'
    await nextTick()

    expect(wrapper.vm.intradayTransition).toBe(true)
    expect(wrapper.vm.levels).toBeNull()
    const loadingStatus = wrapper.findAllComponents({ name: 'UiStatus' })
      .find(component => component.props('title') === 'Loading QQQ intraday data')
    expect(loadingStatus).toBeTruthy()
    expect(loadingStatus.props('message')).toBe('The previous symbol is hidden while its replacement snapshot is verified.')
  })

  it('retains a closed-session unknown-time snapshot across mode/cache changes', async () => {
    vi.setSystemTime(new Date('2026-09-06T15:00:00Z'))
    const closed = {
      open: false,
      asof: null,
      source_asof: null,
      source_timestamp_complete: false,
      market_session: { phase: 'closed', refresh_allowed: false, next_open_at: NEXT_OPEN },
    }
    summaryResponse = () => Promise.resolve(response(summary(closed)))
    strikesResponse = () => Promise.resolve(response(strikes(closed)))
    const wrapper = await mountIntraday()

    expect(wrapper.text()).toContain('Showing last available intraday snapshot')
    expect(wrapper.text()).toContain('Provider update time is unavailable.')
    expect(wrapper.text()).not.toContain('Source as of')
    expect(wrapper.vm.lastUpdated).toBeNull()
    await changeMode(wrapper, 'eod')
    expect(wrapper.vm.lastUpdated).not.toBeNull()
    await changeMode(wrapper, 'intraday')

    expect(calls('/api/intraday/summary')).toHaveLength(1)
    expect(wrapper.vm.intradayHasData).toBe(true)
    expect(wrapper.vm.lastUpdated).toBeNull()
    expect(wrapper.vm.intradayLevels.call_volume_total).toBe(120)
    expect(pulls()).toHaveLength(0)
  })

  it('does not use five-second pending retries or POST when a closed session has no snapshot', async () => {
    vi.setSystemTime(new Date('2026-09-06T15:00:00Z'))
    const closed = {
      open: false,
      asof: null,
      source_asof: null,
      snapshot_available: false,
      refresh_eligible: false,
      totals: { call_vol: 0, put_vol: 0, premium: 0, pcr_vol: null },
      market_session: { phase: 'closed', refresh_allowed: false, next_open_at: NEXT_OPEN },
    }
    summaryResponse = () => Promise.resolve(response(summary(closed)))
    strikesResponse = () => Promise.resolve(response(strikes({ ...closed, items: [] })))
    const wrapper = await mountIntraday()

    expect(wrapper.vm.intradayHasData).toBe(false)
    expect(wrapper.vm.cacheIntraday.has('SPY')).toBe(false)
    expect(wrapper.text()).toContain('No intraday snapshot for SPY yet')
    expect(wrapper.text()).toContain('The market is closed. The first live snapshot can be collected')
    expect(wrapper.text()).toContain('Sep 08')
    expect(wrapper.text()).toContain('9:30 AM')
    expect(wrapper.text()).not.toContain('Showing last available intraday snapshot')

    await advance(25_000)
    expect(calls('/api/intraday/summary')).toHaveLength(1)
    expect(calls('/api/intraday/strikes')).toHaveLength(1)
    expect(pulls()).toHaveLength(0)
    expect(wrapper.vm.intradayLoading).toBe(false)
  })

  it.each([
    ['fresh open quote', true, '2026-09-04T14:59:50Z', 0],
    ['stale open quote', true, '2026-09-04T14:40:00Z', 1],
    ['closed stored quote', false, '2026-09-04T14:40:00Z', 0],
  ])('keeps the rolling-deploy legacy fallback for a %s', async (_name, open, asof, expectedPulls) => {
    const oldSummary = summary({ open, asof })
    const oldStrikes = strikes({ open, asof })
    for (const data of [oldSummary, oldStrikes]) {
      for (const key of ['refresh_eligible', 'snapshot_available', 'market_session', 'source_asof', 'source_timestamp_complete', 'received_at', 'ingestion_completed_at']) {
        delete data[key]
      }
    }
    summaryResponse = () => Promise.resolve(response(oldSummary))
    strikesResponse = () => Promise.resolve(response(oldStrikes))
    const wrapper = await mountIntraday()

    expect(pulls()).toHaveLength(expectedPulls)
    expect(wrapper.vm.intradaySnapshotAvailable).toBe(true)
    expect(wrapper.vm.lastUpdated).toBe(asof)
    expect(wrapper.vm.intradayLevels.strike_data).toHaveLength(1)
  })

  it('uses the zoned legacy summary time instead of parsing unzoned composite text in the browser timezone', async () => {
    vi.setSystemTime(new Date('2026-09-04T20:00:00Z'))
    const oldSummary = summary({ asof: '2026-09-04T19:59:00Z' })
    const oldStrikes = strikes({ asof: '2026-09-04 14:59:00' })
    for (const data of [oldSummary, oldStrikes]) {
      for (const key of ['refresh_eligible', 'snapshot_available', 'market_session', 'source_asof', 'source_timestamp_complete', 'source_timestamp_status', 'received_at', 'ingestion_completed_at']) {
        delete data[key]
      }
    }
    summaryResponse = () => Promise.resolve(response(oldSummary))
    strikesResponse = () => Promise.resolve(response(oldStrikes))

    const wrapper = await mountIntraday()

    expect(wrapper.vm.intradaySnapshotAsOf).toBe('2026-09-04T19:59:00Z')
    expect(wrapper.vm.intradaySourceAge).toBe(60)
    expect(wrapper.vm.intradaySourceTimestampStatus).toBe('legacy')
  })

  it('polls a pending open-session snapshot without creating more work', async () => {
    let strikeReads = 0
    const missing = { asof: null, source_asof: null, snapshot_available: false, refresh_eligible: false }
    summaryResponse = () => Promise.resolve(response(summary(missing)))
    strikesResponse = () => {
      strikeReads += 1
      return Promise.resolve(response(strikeReads === 1
        ? strikes({ ...missing, items: [], totals: { call_vol: 0, put_vol: 0 } })
        : strikes()))
    }
    const wrapper = await mountIntraday()
    expect(wrapper.vm.cacheIntraday.has('SPY')).toBe(false)

    await advance(5_000)
    expect(calls('/api/intraday/summary')).toHaveLength(2)
    expect(calls('/api/intraday/strikes')).toHaveLength(2)
    expect(pulls()).toHaveLength(0)
    expect(wrapper.vm.cacheIntraday.has('SPY')).toBe(true)
    expect(wrapper.vm.intradayHasData).toBe(true)

    await advance(5_000)
    expect(calls('/api/intraday/summary')).toHaveLength(2)
  })

  it('ages the displayed source label on a cache hit without making a provider-start request', async () => {
    const source = '2026-09-04T14:58:45Z'
    summaryResponse = () => Promise.resolve(response(summary({ asof: source, source_asof: source })))
    strikesResponse = () => Promise.resolve(response(strikes({ asof: source, source_asof: source })))
    const wrapper = await mountIntraday()
    expect(wrapper.vm.intradaySourceLabel).toBe('Live')

    await advance(15_000)
    await wrapper.vm.refreshIntraday()
    await flushPromises()
    expect(wrapper.vm.intradaySourceLabel).toBe('Delayed (1m)')
    expect(wrapper.vm.lastUpdated).toBe(source)
    expect(calls('/api/intraday/summary')).toHaveLength(1)
    expect(pulls()).toHaveLength(0)
  })

  it('keeps an aligned snapshot visible when a soft refresh fails', async () => {
    const wrapper = await mountIntraday()
    const retained = wrapper.vm.intradayLevels
    summaryResponse = () => Promise.reject(new Error('temporary provider failure'))

    await wrapper.vm.manualRefresh()
    await flushPromises()

    expect(wrapper.vm.intradayLevels).toBe(retained)
    expect(wrapper.vm.intradayHasData).toBe(true)
    expect(wrapper.vm.intradayError).toBe('temporary provider failure')
    expect(wrapper.vm.topError).toBe('')
  })

  it('does not refresh symbol-scoped intraday endpoints when the hidden EOD timeframe changes', async () => {
    const wrapper = await mountIntraday()
    const summaryCalls = calls('/api/intraday/summary').length
    const strikeCalls = calls('/api/intraday/strikes').length

    wrapper.vm.gexTf = '30d'
    await nextTick()
    await flushPromises()

    expect(calls('/api/intraday/summary')).toHaveLength(summaryCalls)
    expect(calls('/api/intraday/strikes')).toHaveLength(strikeCalls)
  })

  it('unmounts the three live-strike charts when the user returns to Flow', async () => {
    const wrapper = await mountIntraday()
    expect(wrapper.findComponent({ name: 'IntradayFlowPanel' }).exists()).toBe(true)
    expect(wrapper.findComponent({ name: 'VolOverOiChart' }).exists()).toBe(false)

    wrapper.vm.activate('strikes')
    await nextTick()
    await flushPromises()
    expect(wrapper.findComponent({ name: 'VolOverOiChart' }).exists()).toBe(true)
    expect(wrapper.findComponent({ name: 'PcrByStrikeChart' }).exists()).toBe(true)
    expect(wrapper.findComponent({ name: 'PremiumByStrikeChart' }).exists()).toBe(true)

    wrapper.vm.activate('flow')
    await nextTick()
    expect(wrapper.findComponent({ name: 'VolOverOiChart' }).exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'PcrByStrikeChart' }).exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'PremiumByStrikeChart' }).exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'IntradayFlowPanel' }).exists()).toBe(true)
  })
})
