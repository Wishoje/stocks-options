import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import NetGexChart from '@/Components/NetGexChart.vue'
import StrikeDeltaChart from '@/Components/StrikeDeltaChart.vue'
import VolumeDeltaChart from '@/Components/VolumeDeltaChart.vue'
import {
  buildStrikePoints,
  chartSnapshotName,
  chartStrikeRows,
  strongestByMagnitude,
} from '@/Components/EodStrikes/strikeChartUtils.js'

vi.mock('vue-chartjs', () => ({
  Bar: {
    name: 'Bar',
    props: {
      data: Object,
      options: Object,
    },
    template: '<div class="chart-stub" />',
  },
}))

let wrapper

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
})

describe('EOD strike chart data helpers', () => {
  it('sorts a copy, preserves missing versus zero, and retains every source row when grouping', () => {
    const source = Object.freeze([
      Object.freeze({ strike: 103, call: null, put: 0, note: 'third' }),
      Object.freeze({ strike: 101, call: 4, put: null, note: 'first' }),
      Object.freeze({ strike: 102, call: 0, put: -2, note: 'second' }),
    ])
    const rows = chartStrikeRows(source)

    expect(rows.map(row => row.__strike)).toEqual([101, 102, 103])
    expect(source.map(row => row.strike)).toEqual([103, 101, 102])

    const points = buildStrikePoints(rows, {
      call: row => row.call,
      put: row => row.put,
    })
    expect(points.map(point => point.call)).toEqual([4, 0, null])
    expect(points.map(point => point.put)).toEqual([null, -2, 0])

    const denseRows = chartStrikeRows(Array.from({ length: 151 }, (_, index) => ({
      strike: 400 + index,
      call: index === 0 ? null : index,
      put: index % 2 ? 0 : -index,
      raw_marker: `row-${index}`,
    })))
    const grouped = buildStrikePoints(denseRows, {
      call: row => row.call,
      put: row => row.put,
    }, { bucket: true, maxBars: 100 })

    expect(grouped.length).toBeLessThanOrEqual(100)
    expect(grouped.reduce((count, point) => count + point.sourceCount, 0)).toBe(151)
    expect(grouped.flatMap(point => point.sourceRows).map(row => row.raw_marker)).toHaveLength(151)
    expect(grouped.flatMap(point => point.sourceRows).some(row => row.call == null)).toBe(true)

    const floatingBoundary = buildStrikePoints(chartStrikeRows([
      { strike: 0, value: 1 },
      { strike: 0.1, value: 1 },
      { strike: 0.2, value: 1 },
      { strike: 0.3, value: 1 },
    ]), { value: row => row.value }, { bucket: true, maxBars: 3 })
    expect(floatingBoundary.length).toBeLessThanOrEqual(3)
    expect(floatingBoundary.reduce((count, point) => count + point.sourceCount, 0)).toBe(4)
  })

  it('selects a real zero reading but does not invent a selection from missing values', () => {
    expect(strongestByMagnitude([
      { label: '100', value: null },
      { label: '101', value: 0 },
    ], [point => point.value])?.label).toBe('101')
    expect(strongestByMagnitude([
      { label: '100', value: null },
      { label: '101', value: undefined },
    ], [point => point.value])).toBeNull()
    expect(chartSnapshotName('delta oi', 'SPY', '30 days', '2026-09-11', 'daily', '2026-09-10'))
      .toBe('delta-oi-SPY-30-days-2026-09-11-daily-2026-09-10')
  })
})

describe('EOD net GEX by strike', () => {
  it('creates a readable self-contained panel without mutating or dropping raw readings', async () => {
    const source = Object.freeze([
      Object.freeze({ strike: 110, net_gex: -5_000, call_gex: 1_100, put_gex: 6_100, raw_marker: 'negative' }),
      Object.freeze({ strike: 'bad', net_gex: 99, call_gex: 99, put_gex: 0, raw_marker: 'invalid strike' }),
      Object.freeze({ strike: 100, net_gex: null, call_gex: 0, put_gex: null, raw_marker: 'missing net' }),
      Object.freeze({ strike: 105, net_gex: 2_500, call_gex: 3_000, put_gex: 500, raw_marker: 'positive' }),
    ])

    wrapper = mount(NetGexChart, {
      props: {
        strikeData: source,
        eod: true,
        symbol: 'SPY',
        timeframe: '30D',
        snapshotDate: '2026-09-11',
      },
    })

    expect(wrapper.get('[data-testid="eod-net-gex"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Total net GEX')
    expect(wrapper.text()).toContain('Largest positive')
    expect(wrapper.text()).toContain('Strike 105')
    expect(wrapper.text()).toContain('Largest negative')
    expect(wrapper.text()).toContain('Strike 110')
    expect(wrapper.text()).toContain('Snapshot 2026-09-11')
    expect(wrapper.text()).toContain('30D')
    expect(wrapper.text()).toContain('date summarizes their selected snapshots')
    expect(wrapper.vm.downloadName).toBe('net-gex-SPY-30D-2026-09-11')
    expect(wrapper.vm.rawRows).toHaveLength(4)
    expect(wrapper.vm.sortedData).toHaveLength(3)
    expect(wrapper.vm.chartData.datasets[0].data).toEqual([null, 2_500, -5_000])
    expect(wrapper.vm.displayPoints[0].call).toBe(0)
    expect(wrapper.vm.selectedPoint.label).toBe('110')
    expect(wrapper.findAll('tbody tr')).toHaveLength(4)
    expect(wrapper.get('[data-testid="eod-net-gex-readings"]').attributes('open')).toBeUndefined()
    expect(source[0].put_gex).toBe(6_100)

    wrapper.vm.selectFromChart(null, [{ index: 1 }])
    await wrapper.vm.$nextTick()
    expect(wrapper.vm.selectedPoint.label).toBe('105')
    expect(wrapper.emitted('reading-inspected')).toHaveLength(1)

    await wrapper.setData({ splitView: true })
    expect(wrapper.vm.chartData.datasets[1].data).toEqual([null, -500, -6_100])
    expect(source[0].put_gex).toBe(6_100)
  })

  it('groups a dense render to its performance limit while the raw table remains complete', async () => {
    const source = Object.freeze(Array.from({ length: 151 }, (_, index) => Object.freeze({
      strike: 400 + index,
      net_gex: index - 75,
      call_gex: index,
      put_gex: 75 - index,
      raw_marker: `source-${index}`,
    })))
    wrapper = mount(NetGexChart, { props: { strikeData: source, eod: true } })

    await wrapper.setData({ focusActivity: false, autoBucket: true })

    expect(wrapper.vm.displayPoints.length).toBeLessThanOrEqual(wrapper.vm.MAX_BARS)
    expect(wrapper.vm.displayPoints.reduce((count, point) => count + point.sourceCount, 0)).toBe(151)
    expect(wrapper.vm.tableRows).toHaveLength(151)
    expect(wrapper.findAll('tbody tr')).toHaveLength(151)
  })

  it('keeps split-view availability and strike inspection tied to the active series', async () => {
    wrapper = mount(NetGexChart, {
      props: {
        eod: true,
        strikeData: [{ strike: 500, net_gex: 12_000, call_gex: null, put_gex: null }],
      },
    })

    expect(wrapper.vm.hasChartData).toBe(true)
    expect(wrapper.find('select').exists()).toBe(true)
    await wrapper.setData({ splitView: true })
    expect(wrapper.vm.hasChartData).toBe(false)
    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.text()).toContain('No net GEX readings')
    expect(wrapper.findAll('.strike-chart-frame__actions button').every(button => button.attributes('disabled') !== undefined)).toBe(true)

    await wrapper.setProps({ strikeData: [{ strike: 500, net_gex: null, call_gex: null, put_gex: null }] })
    await wrapper.setData({ splitView: false })
    expect(wrapper.vm.hasChartData).toBe(false)
    expect(wrapper.find('select').exists()).toBe(false)
  })

  it('keeps the existing scanner/default embedded chart contract when eod is omitted', () => {
    wrapper = mount(NetGexChart, {
      props: {
        strikeData: [
          { strike: 505, net_gex: 2_000, call_gex: 3_000, put_gex: 1_000 },
          { strike: 500, net_gex: null, call_gex: null, put_gex: 0 },
        ],
      },
    })

    expect(wrapper.props('eod')).toBe(false)
    expect(wrapper.find('[data-testid="eod-net-gex"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Bars: 2')
    expect(wrapper.text()).toContain('Focusing on main activity band')
    expect(wrapper.text()).toContain('Split Call/Put')
    expect(wrapper.text()).toContain('Focus on activity')
    expect(wrapper.text()).toContain('Auto bucket')
    expect(wrapper.text()).toContain('Reset zoom')
    expect(wrapper.text()).toContain('Snapshot')
    expect(wrapper.vm.displayPoints).toEqual([
      { label: '500', value: 0, call: 0, put: 0 },
      { label: '505', value: 2_000, call: 3_000, put: 1_000 },
    ])
    expect(wrapper.vm.chartData.datasets[0]).toEqual(expect.objectContaining({
      label: 'Net GEX',
      backgroundColor: ['rgba(52,211,153,0.9)', 'rgba(52,211,153,0.9)'],
    }))
    expect(wrapper.vm.chartOptions.normalized).toBeUndefined()
    expect(wrapper.vm.chartOptions.onClick).toBeUndefined()
    expect(wrapper.vm.chartOptions.scales.x.title.text).toBe('Strike (bucketed / focused)')
  })
})

describe('EOD open interest change by strike', () => {
  const rows = [
    {
      strike: 100,
      call_oi_delta: 0,
      put_oi_delta: null,
      call_oi_delta_pct: 0,
      put_oi_delta_pct: null,
      call_oi_wow: 40,
      put_oi_wow: -10,
    },
    {
      strike: 105,
      call_oi_delta: 200,
      put_oi_delta: -50,
      call_oi_delta_pct: 2.5,
      put_oi_delta_pct: -1.25,
      call_oi_wow: 700,
      put_oi_wow: -300,
    },
  ]

  it('shows daily and weekly sources explicitly while keeping zero distinct from missing', async () => {
    wrapper = mount(StrikeDeltaChart, {
      props: {
        strikeData: rows,
        comparisonBasis: 'daily',
        comparisonDate: '2026-09-10',
        comparisonGapTradingDays: 1,
      },
    })

    expect(wrapper.vm.chartData.datasets[0].data).toEqual([0, 200])
    expect(wrapper.vm.chartData.datasets[1].data).toEqual([null, -50])
    expect(wrapper.vm.totalChange).toBeNull()
    expect(wrapper.text()).toContain('Incomplete coverage')
    expect(wrapper.text()).toContain('Call 2/2')
    expect(wrapper.text()).toContain('Put 1/2')
    expect(wrapper.text()).toContain('Daily comparison against 2026-09-10')
    expect(wrapper.text()).toContain('1 trading day back')
    expect(wrapper.text()).toContain('current raw strike set')
    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    expect(wrapper.get('[data-testid="eod-oi-change-readings"]').attributes('open')).toBeUndefined()
    expect(wrapper.emitted('reading-inspected')).toBeUndefined()

    await wrapper.findAll('input[type="checkbox"]')[0].setValue(false)
    expect(wrapper.emitted('reading-inspected')).toBeUndefined()

    await wrapper.get('select').setValue('100')
    expect(wrapper.vm.selectedPoint.label).toBe('100')
    expect(wrapper.emitted('reading-inspected')).toHaveLength(1)

    await wrapper.setProps({
      comparisonBasis: 'weekly',
      comparisonDate: '2026-09-04',
      comparisonGapTradingDays: 5,
      comparisonIsStale: true,
    })
    expect(wrapper.vm.chartData.datasets[0].data).toEqual([40, 700])
    expect(wrapper.vm.chartData.datasets[1].data).toEqual([-10, -300])
    expect(wrapper.vm.totalChange).toBe(430)
    expect(wrapper.text()).toContain('Weekly comparison against 2026-09-04')
    expect(wrapper.text()).toContain('5 trading days back')
    expect(wrapper.get('[data-stale="true"]').exists()).toBe(true)
  })

  it('does not plot returned arithmetic as a change without a dated source', () => {
    wrapper = mount(StrikeDeltaChart, {
      props: { strikeData: rows, comparisonBasis: 'unavailable' },
    })

    expect(wrapper.vm.chartData.datasets[0].data).toEqual([null, null])
    expect(wrapper.vm.chartData.datasets[1].data).toEqual([null, null])
    expect(wrapper.vm.totalChange).toBeNull()
    expect(wrapper.vm.hasChartData).toBe(false)
    expect(wrapper.vm.tableRows[1]).toMatchObject({ call_daily: 200, call_weekly: 700 })
    expect(wrapper.text()).toContain('No dated comparison source is available')
    expect(wrapper.text()).toContain('Open interest comparison unavailable')
    expect(wrapper.text()).toContain('No dated earlier snapshot was returned')
    expect(wrapper.text()).toContain('Returned daily call field')
    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.findAll('.strike-chart-frame__actions button').every(button => button.attributes('disabled') !== undefined)).toBe(true)
  })

  it('keeps a valid all-zero daily comparison classified and plotted as daily', () => {
    wrapper = mount(StrikeDeltaChart, {
      props: {
        strikeData: [{ strike: 100, call_oi_delta: 0, put_oi_delta: 0 }],
        comparisonBasis: 'daily',
        comparisonDate: '2026-09-10',
      },
    })

    expect(wrapper.vm.hasChartData).toBe(true)
    expect(wrapper.vm.chartData.datasets[0].data).toEqual([0])
    expect(wrapper.vm.chartData.datasets[1].data).toEqual([0])
    expect(wrapper.vm.totalChange).toBe(0)
    expect(wrapper.text()).toContain('Daily comparison against 2026-09-10')
    expect(wrapper.text()).toContain('current raw strike set')
    expect(wrapper.text()).toContain('Net OI change0')
  })
})

describe('EOD contract volume branch', () => {
  const rows = [
    {
      strike: 100,
      call_vol_delta: 0,
      put_vol_delta: null,
      call_vol_delta_pct: 0,
      put_vol_delta_pct: null,
      call_vol_wow: 400,
      put_vol_wow: -100,
    },
    {
      strike: 105,
      call_vol_delta: 2_500,
      put_vol_delta: -1_000,
      call_vol_delta_pct: 12.5,
      put_vol_delta_pct: -5,
      call_vol_wow: 8_000,
      put_vol_wow: -3_000,
    },
  ]

  it('uses the modern EOD panel and honors explicit comparison provenance', async () => {
    wrapper = mount(VolumeDeltaChart, {
      props: {
        eod: true,
        strikeData: rows,
        comparisonBasis: 'daily',
        comparisonDate: '2026-09-10',
        comparisonGapTradingDays: 1,
      },
    })

    expect(wrapper.get('[data-testid="eod-volume-change"]').exists()).toBe(true)
    expect(wrapper.vm.chartData.datasets[0].data).toEqual([0, 2_500])
    expect(wrapper.vm.chartData.datasets[1].data).toEqual([null, -1_000])
    expect(wrapper.vm.totalChange).toBeNull()
    expect(wrapper.text()).toContain('Incomplete coverage')
    expect(wrapper.text()).toContain('Daily comparison against 2026-09-10')
    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    expect(wrapper.get('[data-testid="eod-volume-change-readings"]').attributes('open')).toBeUndefined()

    wrapper.vm.selectFromChart(null, [{ index: 1 }])
    expect(wrapper.emitted('reading-inspected')).toHaveLength(1)

    await wrapper.setProps({
      comparisonBasis: 'weekly',
      comparisonDate: '2026-09-04',
      comparisonGapTradingDays: 5,
    })
    expect(wrapper.vm.chartData.datasets[0].data).toEqual([400, 8_000])
    expect(wrapper.vm.chartData.datasets[1].data).toEqual([-100, -3_000])
    expect(wrapper.vm.totalChange).toBe(5_300)
    expect(wrapper.text()).toContain('Weekly comparison against 2026-09-04')
  })

  it('keeps returned fields inspectable but unplotted when comparison provenance is unavailable', () => {
    wrapper = mount(VolumeDeltaChart, {
      props: { eod: true, strikeData: rows, comparisonBasis: 'unavailable' },
    })

    expect(wrapper.vm.hasChartData).toBe(false)
    expect(wrapper.vm.chartData.datasets[0].data).toEqual([null, null])
    expect(wrapper.vm.chartData.datasets[1].data).toEqual([null, null])
    expect(wrapper.vm.tableRows[1]).toMatchObject({ call_daily: 2_500, call_weekly: 8_000 })
    expect(wrapper.text()).toContain('No dated comparison source is available')
    expect(wrapper.text()).toContain('Contract volume comparison unavailable')
    expect(wrapper.text()).toContain('No dated earlier snapshot was returned')
    expect(wrapper.text()).toContain('Returned daily call field')
  })

  it('keeps the existing intraday/default markup and chart behavior when eod is omitted', () => {
    wrapper = mount(VolumeDeltaChart, {
      props: {
        strikeData: [
          { strike: 105, call_vol_delta: 2_000, put_vol_delta: -1_000 },
          { strike: 100, call_vol_delta: null, put_vol_delta: 0 },
        ],
      },
    })

    expect(wrapper.props('eod')).toBe(false)
    expect(wrapper.find('[data-testid="eod-volume-change"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Bars: 2')
    expect(wrapper.text()).toContain('Focusing on main activity band')
    expect(wrapper.text()).toContain('Focus on activity')
    expect(wrapper.text()).toContain('Auto bucket')
    expect(wrapper.text()).toContain('Reset zoom')
    expect(wrapper.text()).toContain('Snapshot')
    expect(wrapper.vm.displayPoints).toEqual([
      { label: '100', call: 0, put: 0 },
      { label: '105', call: 2_000, put: -1_000 },
    ])
    expect(wrapper.vm.chartData.datasets[0]).toEqual(expect.objectContaining({
      label: 'Call Delta Vol',
      backgroundColor: 'rgba(52,211,153,0.9)',
    }))
    expect(wrapper.vm.chartOptions.normalized).toBeUndefined()
    expect(wrapper.vm.chartOptions.onClick).toBeUndefined()
    expect(wrapper.vm.chartOptions.scales.x.title.text).toBe('Strike (bucketed / focused)')
  })
})
