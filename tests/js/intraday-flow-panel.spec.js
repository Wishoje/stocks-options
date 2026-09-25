import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import IntradayFlowChart from '@/Components/IntradayFlow/IntradayFlowChart.vue'
import IntradayFlowPanel from '@/Components/IntradayFlow/IntradayFlowPanel.vue'
import {
  buildFlowPoints,
  chartFlowRows,
  completeVolumeTotal,
  focusFlowBand,
  normalizeFlowRows,
} from '@/Components/IntradayFlow/intradayFlowUtils.js'

vi.mock('vue-chartjs', () => ({
  Bar: {
    name: 'Bar',
    props: { data: Object, options: Object },
    template: '<div class="chart-stub" />',
  },
}))

const wrappers = []

afterEach(() => {
  wrappers.splice(0).forEach(wrapper => wrapper.unmount())
})

function mountPanel(overrides = {}) {
  const wrapper = mount(IntradayFlowPanel, {
    props: {
      symbol: 'SPY',
      dataSymbol: 'SPY',
      totals: {
        call_vol: 1_200_000,
        put_vol: 800_000,
        total: 2_000_000,
        pcr_vol: 0.6667,
        premium: 1_234_567.89,
      },
      rows: [
        {
          strike: '500.00',
          call_vol: 1_200_000,
          put_vol: 0,
          call_prem: 734_567.89,
          put_prem: 0,
          oi_call_eod: 4_000_000,
          oi_put_eod: null,
          vol_oi: 0.3,
          pcr: 0,
          net_gex_live: 12345.6789,
          net_gex_delta: 100.25,
          future_source_field: 'kept verbatim',
        },
        {
          strike: 505,
          call_vol: 0,
          put_vol: 800_000,
          call_prem: 0,
          put_prem: 500_000,
          oi_call_eod: 0,
          oi_put_eod: 3_000_000,
          vol_oi: 0.2666666667,
          pcr: null,
          net_gex_live: null,
          net_gex_delta: 0,
        },
      ],
      summary: {
        open: true,
        trade_date: '2026-09-11',
        refresh_eligible: false,
        refresh_reason: 'recently_completed',
        totals: { call_vol: 1_200_000, put_vol: 800_000, total: 2_000_000, pcr_vol: 0.6667, premium: 1_234_567.89 },
      },
      responseMeta: {
        open: true,
        sourceAsOf: '2026-09-11T15:58:00Z',
        rawAsOf: '2026-09-11T15:58:00Z',
        receivedAt: '2026-09-11T15:58:03Z',
        ingestionCompletedAt: '2026-09-11T15:58:05Z',
        snapshotAvailable: true,
        refreshEligible: false,
        tradeDate: '2026-09-11',
        sourceTimestampStatus: 'provider',
        marketSession: { phase: 'regular', next_open_at: '2026-09-12T13:30:00Z' },
      },
      snapshotMeta: {
        captured_at: '2026-09-11T15:58:01Z',
        refresh_reason: 'recently_completed',
        snapshot_available: true,
      },
      sourceAgeSeconds: 60,
      sourceLabel: 'Live',
      nextOpenLabel: 'at Sep 12, 9:30 AM ET',
      ...overrides,
    },
  })
  wrappers.push(wrapper)
  return wrapper
}

describe('intraday flow data helpers', () => {
  it('sorts copies, keeps zero distinct from missing, and retains every raw row while grouping', () => {
    const source = Object.freeze([
      Object.freeze({ strike: 102, call_vol: null, put_vol: 0, marker: 'last' }),
      Object.freeze({ strike: 100, call_vol: 5, put_vol: null, marker: 'first' }),
      Object.freeze({ strike: 101, call_vol: 0, put_vol: 2, marker: 'middle' }),
      Object.freeze({ strike: 'bad', call_vol: 99, put_vol: 99, marker: 'invalid strike' }),
    ])

    const normalized = normalizeFlowRows(source)
    const chartRows = chartFlowRows(source)
    expect(normalized).toHaveLength(4)
    expect(chartRows.map(row => row.__strike)).toEqual([100, 101, 102])
    expect(chartRows.map(row => row.__call_volume)).toEqual([5, 0, null])
    expect(chartRows.map(row => row.__put_volume)).toEqual([null, 2, 0])
    expect(source.map(row => row.marker)).toEqual(['last', 'first', 'middle', 'invalid strike'])

    const dense = chartFlowRows(Array.from({ length: 151 }, (_, index) => ({
      strike: 400 + index,
      call_vol: index === 0 ? null : index,
      put_vol: index % 2 ? 0 : index,
      exact_marker: `row-${index}`,
    })))
    const grouped = buildFlowPoints(dense, { bucket: true, maxBars: 90 })
    expect(grouped.length).toBeLessThanOrEqual(90)
    expect(grouped.reduce((total, point) => total + point.sourceCount, 0)).toBe(151)
    expect(grouped.flatMap(point => point.sourceRows).map(row => row.exact_marker)).toHaveLength(151)
    expect(grouped.flatMap(point => point.sourceRows).some(row => row.__call_volume == null)).toBe(true)
    expect(grouped.reduce((total, point) => total + (point.callVolume ?? 0), 0))
      .toBe(dense.reduce((total, row) => total + (row.__call_volume ?? 0), 0))
    expect(completeVolumeTotal(null, 0, 0)).toBe(0)
    expect(completeVolumeTotal(null, null, 0)).toBeNull()

    const sideAware = chartFlowRows([
      { strike: 90, call_vol: 0, put_vol: 3 },
      { strike: 100, call_vol: 10_000, put_vol: 0 },
      { strike: 110, call_vol: 0, put_vol: 100 },
    ])
    expect(focusFlowBand(sideAware, { relativeThreshold: 0.5, padding: 0 }).map(row => row.__strike))
      .toEqual([100, 110])
  })
})

describe('IntradayFlowPanel', () => {
  it('makes volume, PCR, and freshness primary while preserving exact returned values in collapsed details', async () => {
    const wrapper = mountPanel()

    expect(wrapper.text()).toContain('Cumulative current-session contract volume across all returned expiries')
    expect(wrapper.text()).toContain('Dashboard expiry timeframes do not scope this view')
    expect(wrapper.text()).toContain('Session volume2Mcontracts')
    expect(wrapper.text()).toContain('Volume put/call ratio0.67puts ÷ calls')
    expect(wrapper.text()).toContain('Call volume1.2Mcontracts')
    expect(wrapper.text()).toContain('Put volume800Kcontracts')
    expect(wrapper.text()).toContain('Estimated premium notional$1.23M')
    expect(wrapper.text()).toContain('Current intraday data')
    expect(wrapper.getComponent(IntradayFlowChart).vm.groupDenseStrikes).toBe(true)
    expect(wrapper.get('[aria-label="Session volume"]').attributes('title')).toBe('2000000')
    expect(wrapper.get('[aria-label="Volume put/call ratio"]').attributes('title')).toBe('0.6667')

    const snapshotDetails = wrapper.get('[data-testid="intraday-flow-snapshot-details"]')
    const strikeDetails = wrapper.get('[data-testid="intraday-flow-strike-readings"]')
    expect(snapshotDetails.attributes('open')).toBeUndefined()
    expect(strikeDetails.attributes('open')).toBeUndefined()
    expect(wrapper.findAll('[data-testid="intraday-flow-strike-readings"] tbody tr')).toHaveLength(0)

    strikeDetails.element.open = true
    await strikeDetails.trigger('toggle')
    expect(wrapper.text()).toContain('Exact intraday flow by strike · 2 readings')
    expect(wrapper.text()).toContain('12345.6789')
    expect(wrapper.text()).toContain('0.2666666667')
    expect(wrapper.text()).toContain('kept verbatim')
    expect(wrapper.findAll('[data-testid="intraday-flow-strike-readings"] tbody tr')).toHaveLength(2)

    snapshotDetails.element.open = true
    await snapshotDetails.trigger('toggle')
    expect(wrapper.text()).toContain('Exact returned totals')
    expect(wrapper.text()).toContain('1234567.89')

    await wrapper.get('.intraday-flow__actions .gex-button:last-child').trigger('click')
    expect(wrapper.emitted('refresh')).toHaveLength(1)
  })

  it('shows stale, unknown-source, and closed states without presenting an ingestion clock as market time', async () => {
    const wrapper = mountPanel({ sourceAgeSeconds: 600, sourceLabel: 'Delayed (10m)' })
    expect(wrapper.get('.gex-status').attributes('data-state')).toBe('stale')
    expect(wrapper.text()).toContain('Delayed intraday data')

    await wrapper.setProps({
      sourceAgeSeconds: null,
      sourceLabel: 'Update time unavailable',
      responseMeta: {
        ...wrapper.props('responseMeta'),
        sourceAsOf: null,
        receivedAt: '2026-09-11T15:58:03Z',
        ingestionCompletedAt: '2026-09-11T15:58:05Z',
      },
    })
    expect(wrapper.get('.gex-status').attributes('data-state')).toBe('sparse')
    expect(wrapper.text()).toContain('Update time is unavailable')
    expect(wrapper.text()).not.toContain('15:58:03')
    expect(wrapper.text()).not.toContain('15:58:05')

    await wrapper.setProps({
      responseMeta: {
        ...wrapper.props('responseMeta'),
        open: false,
        sourceAsOf: '2026-09-11T15:58:00Z',
      },
      sourceLabel: 'Market Closed',
    })
    expect(wrapper.get('.gex-status').attributes('data-state')).toBe('closed')
    expect(wrapper.text()).toContain('Showing the last stored session data')
    expect(wrapper.text()).toContain('Session updates resume at Sep 12, 9:30 AM ET')
  })

  it('does not substitute legacy asof when an explicit provider-source contract says the source clock is unavailable', () => {
    const wrapper = mountPanel({
      responseMeta: {},
      snapshotMeta: {
        asof: '2026-09-11T15:58:00Z',
        source_asof: null,
        source_timestamp_status: 'unknown',
        snapshot_available: true,
      },
      summary: {},
      sourceAgeSeconds: null,
      sourceLabel: '',
    })

    expect(wrapper.text()).toContain('Update time is unavailable')
    expect(wrapper.text()).not.toContain('Source as of Sep 11')
  })

  it('labels a rolling-deploy legacy clock as response time rather than provider time', () => {
    const wrapper = mountPanel({
      responseMeta: {
        open: true,
        sourceAsOf: '2026-09-11T15:58:00Z',
        sourceTimestampStatus: 'legacy',
        snapshotAvailable: true,
      },
      sourceAgeSeconds: 30,
      sourceLabel: 'Live',
    })

    expect(wrapper.get('.gex-status').attributes('data-state')).toBe('sparse')
    expect(wrapper.text()).toContain('Recent legacy response')
    expect(wrapper.text()).toContain('Volume is cumulative for that session.')
    expect(wrapper.text()).not.toContain('Current intraday data')
  })

  it('uses snapshot_available for no-data state and never renders an old symbol under the new heading', async () => {
    const wrapper = mountPanel({
      symbol: 'QQQ',
      dataSymbol: 'SPY',
      refreshing: true,
      rows: [{ strike: 500, call_vol: 999, put_vol: 888, marker: 'old SPY row' }],
    })

    expect(wrapper.text()).toContain('Updating QQQ intraday flow')
    expect(wrapper.text()).toContain('prior symbol remains hidden')
    expect(wrapper.text()).not.toContain('old SPY row')
    expect(wrapper.findComponent(IntradayFlowChart).exists()).toBe(false)

    await wrapper.setProps({
      dataSymbol: 'QQQ',
      refreshing: false,
      rows: [],
      totals: { call_vol: 0, put_vol: 0, total: 0, premium: 0, pcr_vol: null },
      responseMeta: {
        open: false,
        snapshotAvailable: false,
        sourceAsOf: null,
        marketSession: { phase: 'closed', next_open_at: '2026-09-12T13:30:00Z' },
      },
    })
    expect(wrapper.text()).toContain('No stored intraday data for QQQ')
    expect(wrapper.text()).not.toContain('Session volume0contracts')
    expect(wrapper.findComponent(IntradayFlowChart).exists()).toBe(false)
  })

  it('keeps aligned data visible on a refresh error and exposes retry when no data set exists', async () => {
    const wrapper = mountPanel({ error: 'Network unavailable' })
    expect(wrapper.get('.gex-status').attributes('data-state')).toBe('error')
    expect(wrapper.text()).toContain('last recorded readings remain visible')
    expect(wrapper.findComponent(IntradayFlowChart).exists()).toBe(true)

    await wrapper.setProps({
      rows: [],
      totals: {},
      responseMeta: { open: true, snapshotAvailable: false, sourceAsOf: null },
    })
    const retry = wrapper.get('.gex-status .gex-button')
    await retry.trigger('click')
    expect(wrapper.emitted('retry')).toHaveLength(1)
  })
})

describe('IntradayFlowChart', () => {
  it('supports click, touch-equivalent selection, native keyboard inspection, and display-only grouping', async () => {
    const rows = Array.from({ length: 121 }, (_, index) => ({
      strike: 400 + index,
      call_vol: index === 20 ? null : index,
      put_vol: index === 80 ? 10_000 : 0,
      marker: `source-${index}`,
    }))
    const wrapper = mount(IntradayFlowChart, {
      props: { rows, symbol: 'SPY', tradeDate: '2026-09-11', sourceAsOf: '2026-09-11T15:58:00Z' },
    })
    wrappers.push(wrapper)

    expect(wrapper.text()).toContain('Keyboard users can use the Inspect strike control')
    expect(wrapper.vm.groupDenseStrikes).toBe(true)
    expect(wrapper.vm.selectedPoint.sourceRows.some(row => row.strike === 480)).toBe(true)
    expect(wrapper.vm.chartData.datasets[0].label).toBe('Call volume')
    expect(wrapper.vm.chartData.datasets[1].label).toBe('Put volume')
    expect(wrapper.vm.chartOptions.scales.y.title.text).toBe('Session volume (contracts)')

    const select = wrapper.get('select')
    const firstLabel = wrapper.vm.displayPoints[0].label
    await select.setValue(firstLabel)
    expect(wrapper.vm.selectedPoint.label).toBe(firstLabel)
    wrapper.vm.selectFromChart(null, [{ index: 1 }])
    await wrapper.vm.$nextTick()
    expect(wrapper.vm.selectedPoint.label).toBe(wrapper.vm.displayPoints[1].label)

    const controls = wrapper.findAll('.intraday-flow-chart__controls input')
    await controls[0].setValue(false)
    await controls[1].setValue(true)
    expect(wrapper.vm.displayPoints.length).toBeLessThanOrEqual(90)
    expect(wrapper.vm.displayPoints.reduce((total, point) => total + point.sourceCount, 0)).toBe(121)
    expect(wrapper.vm.displayPoints.flatMap(point => point.sourceRows).map(row => row.marker)).toHaveLength(121)
  })

  it('keeps a returned zero chartable and leaves a missing reading unavailable', () => {
    const wrapper = mount(IntradayFlowChart, {
      props: {
        rows: [
          { strike: 100, call_vol: 0, put_vol: null },
          { strike: 101, call_vol: null, put_vol: null },
        ],
      },
    })
    wrappers.push(wrapper)

    expect(wrapper.vm.hasChartData).toBe(true)
    expect(wrapper.vm.chartData.datasets[0].data).toEqual([0, null])
    expect(wrapper.vm.chartData.datasets[1].data).toEqual([null, null])
    expect(wrapper.vm.selectedPoint.label).toBe('100')
    expect(wrapper.vm.selectedOptions.map(option => option.value)).toEqual(['100'])

    wrapper.vm.selectFromChart(null, [{ index: 1 }])
    wrapper.vm.selectFromControl('101')
    expect(wrapper.emitted('reading-inspected')).toBeUndefined()

    wrapper.vm.selectFromChart(null, [{ index: 0 }])
    expect(wrapper.emitted('reading-inspected')).toHaveLength(1)
  })
})
