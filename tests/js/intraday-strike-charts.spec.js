import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import PcrByStrikeChart from '@/Components/PcrByStrikeChart.vue'
import PremiumByStrikeChart from '@/Components/PremiumByStrikeChart.vue'
import VolOverOiChart from '@/Components/VolOverOiChart.vue'
import {
  aggregateRatio,
  buildRatioStrikePoints,
  chartIntradayRows,
  pcrValue,
  ratioDetails,
  returnedFieldKeys,
  volOiValue,
} from '@/Components/IntradayStrikes/intradayStrikeUtils.js'

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

async function openReadings(testId) {
  const details = wrapper.get(`[data-testid="${testId}"]`)
  expect(details.attributes('open')).toBeUndefined()
  expect(wrapper.findAll('tbody tr')).toHaveLength(0)
  details.element.open = true
  await details.trigger('toggle')
  await wrapper.vm.$nextTick()
  return details
}

describe('intraday strike ratio helpers', () => {
  it('keeps a real zero and refuses zero or missing denominators', () => {
    expect(ratioDetails({ vol_oi: 0 }, 'vol_oi')).toMatchObject({ value: 0, source: 'returned' })
    expect(volOiValue({
      vol_oi: null,
      call_vol: 5,
      put_vol: 5,
      oi_call_eod: 50,
      oi_put_eod: 50,
    })).toBe(0.1)
    expect(volOiValue({
      vol_oi: null,
      call_vol: 5,
      put_vol: 5,
      oi_call_eod: 0,
      oi_put_eod: 0,
    })).toBeNull()
    expect(volOiValue({
      vol_oi: null,
      call_vol: 5,
      put_vol: null,
      oi_call_eod: 50,
      oi_put_eod: 50,
    })).toBeNull()

    expect(pcrValue({ pcr: 0, call_vol: 10, put_vol: 0 })).toBe(0)
    expect(pcrValue({ pcr: null, call_vol: 10, put_vol: 0 })).toBe(0)
    expect(pcrValue({ pcr: null, call_vol: 0, put_vol: 10 })).toBeNull()
    expect(pcrValue({ pcr: null, call_vol: null, put_vol: 10 })).toBeNull()
  })

  it('recomputes grouped ratios from components instead of averaging ratios', () => {
    const pcrRows = chartIntradayRows([
      { strike: 100, call_vol: 1, put_vol: 4, pcr: 4 },
      { strike: 101, call_vol: 100, put_vol: 50, pcr: 0.5 },
    ])
    const pcrPoint = buildRatioStrikePoints(pcrRows, 'pcr', { bucket: true, maxBars: 1 })[0]
    expect(pcrPoint.value).toBeCloseTo(54 / 101)
    expect(pcrPoint.value).not.toBeCloseTo((4 + 0.5) / 2)
    expect(pcrPoint.sourceCount).toBe(2)
    expect(pcrPoint.ratioSource).toBe('aggregated')

    const volOiRows = chartIntradayRows([
      { strike: 100, call_vol: 5, put_vol: 5, oi_call_eod: 5, oi_put_eod: 5, vol_oi: 1 },
      { strike: 101, call_vol: 50, put_vol: 50, oi_call_eod: 500, oi_put_eod: 500, vol_oi: 0.1 },
    ])
    const volOiPoint = buildRatioStrikePoints(volOiRows, 'vol_oi', { bucket: true, maxBars: 1 })[0]
    expect(volOiPoint.value).toBeCloseTo(110 / 1010)
    expect(volOiPoint.value).not.toBeCloseTo(0.55)
  })

  it('keeps every source row in dense grouping and every returned field in the audit', () => {
    const source = Object.freeze(Array.from({ length: 151 }, (_, index) => Object.freeze({
      strike: 400 + index,
      call_vol: index,
      put_vol: index % 3,
      pcr: index ? (index % 3) / index : null,
      future_field: { row: index },
    })))
    const rows = chartIntradayRows(source)
    const points = buildRatioStrikePoints(rows, 'pcr', { bucket: true, maxBars: 100 })

    expect(points.length).toBeLessThanOrEqual(100)
    expect(points.reduce((count, point) => count + point.sourceCount, 0)).toBe(151)
    expect(points.flatMap(point => point.sourceRows).map(row => row.future_field)).toHaveLength(151)
    expect(returnedFieldKeys(rows)).toEqual(expect.arrayContaining(['strike', 'call_vol', 'put_vol', 'pcr', 'future_field']))
    expect(source[0].call_vol).toBe(0)
  })

  it('only reports an aggregate when all ratio components are available', () => {
    const incomplete = aggregateRatio(chartIntradayRows([
      { strike: 100, call_vol: 10, put_vol: 5 },
      { strike: 105, call_vol: null, put_vol: 5, pcr: 2 },
    ]), 'pcr')
    expect(incomplete.value).toBeNull()
    expect(incomplete.completeCount).toBe(1)
    expect(incomplete.coverageComplete).toBe(false)

    const zeroDenominator = aggregateRatio(chartIntradayRows([
      { strike: 100, call_vol: 0, put_vol: 5, pcr: null },
    ]), 'pcr')
    expect(zeroDenominator.value).toBeNull()
    expect(zeroDenominator.zeroDenominatorCount).toBe(1)
  })
})

describe('intraday volume / open interest by strike', () => {
  it('renders a self-contained ratio panel and preserves complete raw data', async () => {
    const source = Object.freeze([
      Object.freeze({ strike: 105, call_vol: 0, put_vol: 0, oi_call_eod: 10, oi_put_eod: 10, vol_oi: 0, net_gex_live: 0, future_field: { note: 'zero' } }),
      Object.freeze({ strike: 100, call_vol: 5, put_vol: 5, oi_call_eod: 50, oi_put_eod: 50, vol_oi: null, net_gex_delta: 123 }),
      Object.freeze({ strike: 110, call_vol: 1, put_vol: 1, oi_call_eod: 0, oi_put_eod: 0, vol_oi: null }),
      Object.freeze({ strike: 'bad', call_vol: 9, raw_marker: 'invalid strike retained' }),
    ])

    wrapper = mount(VolOverOiChart, {
      props: {
        strikeData: source,
        symbol: 'SPY',
        sourceLabel: 'Live',
        snapshotAsOf: '2026-09-11T15:30:00Z',
        sourceTimestampStatus: 'complete',
        marketOpen: true,
      },
    })

    expect(wrapper.get('[data-testid="intraday-vol-oi"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Volume / open interest by strike')
    expect(wrapper.text()).toContain('As of: Sep 11, 2026, 11:30 AM ET')
    expect(wrapper.text()).toContain('Market open')
    const liveBadge = wrapper.findAllComponents({ name: 'UiBadge' }).find(badge => badge.text() === 'Live')
    expect(liveBadge.props('tone')).toBe('positive')
    expect(wrapper.vm.rawRows).toHaveLength(4)
    expect(wrapper.vm.sortedData).toHaveLength(3)
    expect(wrapper.vm.chartData.datasets[0].data).toEqual([0.1, 0, null])
    expect(wrapper.vm.ratioAvailableCount).toBe(2)
    expect(wrapper.vm.tableColumns.map(column => column.key)).toEqual(expect.arrayContaining([
      'future_field',
      'net_gex_live',
      'net_gex_delta',
      '__display_ratio',
    ]))
    await openReadings('intraday-vol-oi-readings')
    expect(wrapper.findAll('tbody tr')).toHaveLength(4)
    expect(wrapper.text()).toContain('{"note":"zero"}')
    expect(wrapper.vm.chartOptions.animation).toBe(false)
    expect(wrapper.vm.chartOptions.scales.y.title.text).toContain('(x)')
    expect(source[0].future_field.note).toBe('zero')

    await wrapper.findAll('button').find(button => button.text() === 'Reading guide').trigger('click')
    expect(document.body.textContent).toContain('ratio, not a percentage')

    expect(wrapper.emitted('reading-inspected')).toBeUndefined()
    wrapper.vm.selectFromChart(null, [{ index: 1 }])
    await wrapper.vm.$nextTick()
    expect(wrapper.emitted('reading-inspected')).toHaveLength(1)
    expect(wrapper.vm.selectedPoint.label).toBe('105')
    expect(wrapper.vm.selectedPoint.value).toBe(0)
  })

  it('caps only the visual scale when an extreme ratio would flatten the useful range', () => {
    const rows = Array.from({ length: 40 }, (_, index) => ({
      strike: 400 + index,
      call_vol: index === 39 ? 1000 : 1,
      put_vol: 0,
      oi_call_eod: 100,
      oi_put_eod: 0,
      vol_oi: index === 39 ? 10 : 0.01,
    }))
    wrapper = mount(VolOverOiChart, { props: { strikeData: rows } })

    expect(wrapper.vm.chartData.datasets[0].data.at(-1)).toBe(10)
    expect(wrapper.vm.chartCeiling).toBeLessThan(10)
    expect(wrapper.vm.clippedCount).toBe(1)
    expect(wrapper.text()).toContain('exact returned ratio and denominator')
  })

  it('labels legacy response time separately from provider source time', async () => {
    wrapper = mount(VolOverOiChart, {
      props: {
        strikeData: [{ strike: 100, vol_oi: 0.5 }],
        snapshotAsOf: '2026-09-11T15:30:00Z',
        sourceTimestampStatus: 'legacy',
        sourceLabel: 'Live',
        marketOpen: true,
      },
    })

    expect(wrapper.text()).toContain('Legacy response time: Sep 11, 2026, 11:30 AM ET')
    const legacyBadge = wrapper.findAllComponents({ name: 'UiBadge' }).find(badge => badge.text() === 'Live')
    expect(legacyBadge.props('tone')).toBe('warning')

    await wrapper.setProps({
      snapshotAsOf: null,
      sourceTimestampStatus: 'unknown',
      sourceLabel: 'Update time unavailable',
    })
    expect(wrapper.text()).toContain('As of: Unavailable')
    const unknownBadge = wrapper.findAllComponents({ name: 'UiBadge' })
      .find(badge => badge.text() === 'Update time unavailable')
    expect(unknownBadge.props('tone')).toBe('warning')
  })
})

describe('intraday put / call ratio by strike', () => {
  it('shows zero as call-led, leaves put-only ratios unavailable, and makes outliers explicit', async () => {
    const rows = Array.from({ length: 30 }, (_, index) => ({
      strike: 500 + index,
      call_vol: index === 1 ? 0 : 10,
      put_vol: index === 0 ? 0 : index === 1 ? 25 : index === 29 ? 10_000 : 10,
      pcr: index === 1 ? null : index === 29 ? 1000 : index === 0 ? 0 : 1,
      raw_marker: `row-${index}`,
    }))
    wrapper = mount(PcrByStrikeChart, {
      props: {
        strikeData: rows,
        symbol: 'QQQ',
        sourceLabel: 'Delayed (2m)',
        marketOpen: true,
      },
    })
    await wrapper.setData({ focusActivity: false })

    expect(wrapper.get('[data-testid="intraday-pcr"]').exists()).toBe(true)
    expect(wrapper.vm.chartData.datasets[0].data[0]).toBe(0)
    expect(wrapper.vm.chartData.datasets[0].data[1]).toBeNull()
    expect(wrapper.vm.chartData.datasets[0].data.at(-1)).toBe(1000)
    expect(wrapper.vm.chartData.datasets[0].backgroundColor[0]).toBe('rgba(115,214,177,0.9)')
    expect(wrapper.vm.chartCeiling).toBeLessThan(1000)
    expect(wrapper.vm.clippedCount).toBe(1)
    expect(wrapper.text()).toContain('small-denominator ratio')
    expect(wrapper.text()).toContain('call-volume denominator')
    await openReadings('intraday-pcr-readings')
    expect(wrapper.findAll('tbody tr')).toHaveLength(30)
    expect(wrapper.vm.chartOptions.scales.y.grid.color({ tick: { value: 1 } })).toContain('174,183,194')

    await wrapper.findAll('button').find(button => button.text() === 'Reading guide').trigger('click')
    expect(document.body.textContent).toContain('ratio, not a percentage')

    expect(wrapper.emitted('reading-inspected')).toBeUndefined()
    await wrapper.get('select').setValue('500')
    await wrapper.vm.$nextTick()
    expect(wrapper.emitted('reading-inspected')).toHaveLength(1)
    expect(wrapper.vm.selectedPoint.value).toBe(0)
  })

  it('uses neutral highest and lowest labels when every ratio is on the same side of one', () => {
    wrapper = mount(PcrByStrikeChart, {
      props: {
        strikeData: [
          { strike: 100, call_vol: 10, put_vol: 20, pcr: 2 },
          { strike: 105, call_vol: 10, put_vol: 30, pcr: 3 },
        ],
      },
    })

    expect(wrapper.text()).toContain('Highest put/call')
    expect(wrapper.text()).toContain('Lowest put/call')
    expect(wrapper.text()).not.toContain('Most call-led')
  })
})

describe('intraday premium estimate by strike', () => {
  it('keeps dollar units, zero, missing values, aliases, and raw fields distinct', async () => {
    const source = Object.freeze([
      Object.freeze({ strike: 100, premium_call: 0, premium_put: null, raw_marker: 'missing put' }),
      Object.freeze({ strike: 105, call_prem: 1000.25, put_prem: 500.75, net_gex_live: 99 }),
      Object.freeze({ strike: 'bad', call_prem: 7, future_field: ['kept'] }),
    ])
    wrapper = mount(PremiumByStrikeChart, {
      props: {
        strikeData: source,
        symbol: 'IWM',
        snapshotName: 'premium IWM current',
      },
    })

    expect(wrapper.get('[data-testid="intraday-premium"]').exists()).toBe(true)
    expect(wrapper.vm.chartData.datasets[0].data).toEqual([0, 1000.25])
    expect(wrapper.vm.chartData.datasets[1].data).toEqual([null, 500.75])
    expect(wrapper.vm.totalPremium).toBeNull()
    expect(wrapper.vm.totalCall).toBe(1000.25)
    expect(wrapper.vm.totalPut).toBe(500.75)
    expect(wrapper.text()).toContain('Call readings')
    expect(wrapper.vm.downloadName).toBe('premium-IWM-current')
    expect(wrapper.vm.tableColumns.map(column => column.key)).toEqual(expect.arrayContaining([
      'premium_call',
      'premium_put',
      'call_prem',
      'put_prem',
      'net_gex_live',
      'future_field',
    ]))
    await openReadings('intraday-premium-readings')
    expect(wrapper.findAll('tbody tr')).toHaveLength(3)
    expect(wrapper.text()).toContain('["kept"]')
    expect(wrapper.vm.chartOptions.scales.y.title.text).toContain('($)')
    expect(source[0].premium_call).toBe(0)

    expect(wrapper.emitted('reading-inspected')).toBeUndefined()
    wrapper.vm.selectFromChart(null, [{ index: 0 }])
    expect(wrapper.emitted('reading-inspected')).toHaveLength(1)

    await wrapper.findAll('button').find(button => button.text() === 'Reading guide').trigger('click')
    expect(document.body.textContent).toContain('Values are US dollars')
    expect(document.body.textContent).toContain('price-based estimates, not confirmed cash flows')
  })

  it('groups a dense plot without dropping rows from the complete table', async () => {
    const rows = Object.freeze(Array.from({ length: 115 }, (_, index) => Object.freeze({
      strike: 100 + index,
      call_prem: index,
      put_prem: index * 2,
      raw_marker: `row-${index}`,
    })))
    wrapper = mount(PremiumByStrikeChart, { props: { strikeData: rows } })
    expect(wrapper.vm.autoBucket).toBe(true)
    await wrapper.setData({ focusActivity: false })

    expect(wrapper.vm.displayPoints.length).toBeLessThanOrEqual(wrapper.vm.MAX_BARS)
    expect(wrapper.vm.displayPoints.reduce((count, point) => count + point.sourceCount, 0)).toBe(115)
    expect(wrapper.vm.rawRows).toHaveLength(115)
    await openReadings('intraday-premium-readings')
    expect(wrapper.findAll('tbody tr')).toHaveLength(115)
  })
})
