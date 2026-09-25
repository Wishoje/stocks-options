import { flushPromises, mount } from '@vue/test-utils'
import axios from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import OiDistributionChart from '@/Components/OiDistributionChart.vue'
import OptionDistributionDonut from '@/Components/OptionDistributionDonut.vue'
import OverviewMetrics from '@/Components/OverviewMetrics.vue'
import QScorePanel from '@/Components/QScorePanel.vue'
import VolDistributionChart from '@/Components/VolDistributionChart.vue'

vi.mock('axios', () => ({ default: { get: vi.fn() } }))
vi.mock('chart.js', () => ({
  ArcElement: {},
  Chart: { register: vi.fn() },
  Tooltip: {},
}))
vi.mock('vue-chartjs', () => ({
  Doughnut: {
    name: 'Doughnut',
    props: ['data', 'options'],
    template: '<div class="doughnut-stub" />',
  },
}))

function qscorePayload(overrides = {}) {
  return {
    symbol: 'SPY',
    date: '2026-09-11',
    source_dates: {
      option: { earliest: '2026-09-11', latest: '2026-09-18' },
      volatility: '2026-09-11',
      momentum: '2026-09-10',
      seasonality: null,
    },
    scores: {
      option: { score: 4, expl: 'Option positioning explanation.' },
      vol: { score: 3, expl: 'Volatility explanation.' },
      momo: { score: 2, expl: 'Momentum explanation.' },
      season: { score: 1, expl: 'Seasonality explanation.' },
    },
    ...overrides,
  }
}

function deferred() {
  let resolve
  let reject
  const promise = new Promise((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

describe('Overview Q-Score panel', () => {
  beforeEach(() => axios.get.mockReset())
  afterEach(() => document.body.replaceChildren())

  it('keeps all four source scores and explanations while showing the weighted summary and provenance', async () => {
    const payload = qscorePayload()
    axios.get.mockResolvedValue({ data: payload })

    const wrapper = mount(QScorePanel, {
      attachTo: document.body,
      props: { symbol: 'spy', snapshotDate: '2026-09-11' },
    })
    await flushPromises()

    expect(axios.get).toHaveBeenCalledWith('/api/qscore', expect.objectContaining({
      params: { symbol: 'SPY', date: '2026-09-11' },
      signal: expect.any(AbortSignal),
    }))
    expect(wrapper.vm.qscorePayload).toEqual(payload)
    expect(wrapper.vm.overall).toBeCloseTo(2.85)
    expect(wrapper.get('.gex-qscore-panel__overall').text()).toContain('2.9/ 4')
    expect(wrapper.findAll('.gex-qscore-card')).toHaveLength(4)
    for (const score of Object.values(payload.scores)) expect(wrapper.text()).toContain(score.expl)
    expect(wrapper.text()).toContain('2026-09-11 to 2026-09-18')
    expect(wrapper.text()).toContain('Momentum2026-09-10')
    expect(wrapper.text()).toContain('SeasonalityUnavailable')
    expect(wrapper.text()).toContain('Scoring anchor 2026-09-11')
    wrapper.unmount()
  })

  it('does not show placeholder neutral scores and suppresses a stale symbol/date response', async () => {
    const spyRequest = deferred()
    const qqqRequest = deferred()
    axios.get
      .mockReturnValueOnce(spyRequest.promise)
      .mockReturnValueOnce(qqqRequest.promise)

    const wrapper = mount(QScorePanel, {
      props: { symbol: 'SPY', snapshotDate: '2026-09-10' },
    })
    await flushPromises()
    expect(wrapper.text()).toContain('Loading SPY Q-Score')
    expect(wrapper.findAll('.gex-qscore-card')).toHaveLength(0)

    await wrapper.setProps({ symbol: 'QQQ', snapshotDate: '2026-09-11' })
    expect(axios.get).toHaveBeenLastCalledWith('/api/qscore', expect.objectContaining({
      params: { symbol: 'QQQ', date: '2026-09-11' },
    }))

    spyRequest.resolve({ data: qscorePayload({ symbol: 'SPY', date: '2026-09-10' }) })
    await flushPromises()
    expect(wrapper.vm.qscorePayload).toBeNull()
    expect(wrapper.text()).toContain('Loading QQQ Q-Score')

    const qqqPayload = qscorePayload({ symbol: 'QQQ' })
    qqqRequest.resolve({ data: qqqPayload })
    await flushPromises()
    expect(wrapper.vm.qscorePayload).toEqual(qqqPayload)
    expect(wrapper.text()).toContain('QQQ')
    wrapper.unmount()
  })

  it('keeps zero as a score and marks an absent score and overall result unavailable', async () => {
    axios.get.mockResolvedValue({
      data: qscorePayload({
        source_dates: {
          option: { earliest: null, latest: null },
          volatility: null,
          momentum: null,
          seasonality: null,
        },
        scores: {
          option: { score: 0, expl: 'Zero is a valid reading.' },
          vol: { score: 2, expl: 'Volatility reading.' },
          momo: { score: null, expl: 'Momentum missing.' },
          season: { score: 1, expl: 'Seasonality reading.' },
        },
      }),
    })

    const wrapper = mount(QScorePanel, { props: { symbol: 'SPY' } })
    await flushPromises()

    expect(wrapper.vm.scoreItems[0].score).toBe(0)
    expect(wrapper.vm.overall).toBeNull()
    expect(wrapper.get('.gex-qscore-panel__overall').text()).toBe('Unavailable')
    expect(wrapper.findAll('.gex-qscore-card')[0].text()).toContain('0.0/ 4')
    expect(wrapper.findAll('.gex-qscore-card')[2].text()).toContain('Unavailable')
    expect(wrapper.findAll('.gex-qscore-panel__sources dd[data-missing="true"]')).toHaveLength(4)
    wrapper.unmount()
  })

  it('offers an inline retry after a failed request', async () => {
    axios.get
      .mockRejectedValueOnce(new Error('Service unavailable'))
      .mockResolvedValueOnce({ data: qscorePayload() })

    const wrapper = mount(QScorePanel, { props: { symbol: 'IWM', snapshotDate: '2026-09-11' } })
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('Service unavailable')

    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.findAll('.gex-qscore-card')).toHaveLength(4)
    wrapper.unmount()
  })

  it('defers its request while Overview is inactive and loads when activated', async () => {
    axios.get.mockResolvedValue({ data: qscorePayload() })
    const wrapper = mount(QScorePanel, {
      props: { symbol: 'SPY', snapshotDate: '2026-09-11', active: false },
    })
    await flushPromises()

    expect(axios.get).not.toHaveBeenCalled()
    await wrapper.setProps({ active: true })
    await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(1)
    expect(wrapper.vm.qscorePayload).toEqual(qscorePayload())
    wrapper.unmount()
  })
})

describe('Overview option distributions', () => {
  afterEach(() => document.body.replaceChildren())

  it('retains raw OI totals, shows compact readings, and supports keyboard series inspection', async () => {
    const callOi = 11108895.239174
    const putOi = 688833.502319
    const wrapper = mount(OiDistributionChart, {
      attachTo: document.body,
      props: { callOi, putOi },
    })
    const distribution = wrapper.getComponent(OptionDistributionDonut)

    expect(wrapper.emitted('reading-inspected')).toBeUndefined()
    expect(distribution.vm.callNumeric).toBe(callOi)
    expect(distribution.vm.putNumeric).toBe(putOi)
    expect(distribution.vm.total).toBe(callOi + putOi)
    expect(distribution.vm.chartData.datasets[0].data).toEqual([callOi, putOi])
    expect(wrapper.text()).toContain('11.1M')
    expect(wrapper.text()).toContain('688.8K')
    expect(wrapper.text()).not.toContain('11108895.239174')
    expect(wrapper.find('.doughnut-stub').exists()).toBe(true)

    const [callButton, putButton] = wrapper.findAll('.gex-distribution__choices button')
    distribution.vm.chartOptions.onClick({}, [{ index: 1 }])
    await wrapper.vm.$nextTick()
    expect(wrapper.emitted('reading-inspected')).toHaveLength(1)
    expect(putButton.attributes('aria-pressed')).toBe('true')
    expect(wrapper.get('.gex-distribution__selected').text()).toContain('Puts')
    await putButton.trigger('keydown', { key: 'ArrowLeft' })
    expect(wrapper.emitted('reading-inspected')).toHaveLength(2)
    expect(callButton.attributes('aria-pressed')).toBe('true')
    expect(document.activeElement).toBe(callButton.element)
    wrapper.unmount()
  })

  it('distinguishes a missing input from two valid zero volume totals', async () => {
    const wrapper = mount(VolDistributionChart, {
      props: { callVol: null, putVol: 0 },
    })
    expect(wrapper.text()).toContain('Both call and put totals are required')
    expect(wrapper.text()).toContain('CallsUnavailable')
    expect(wrapper.text()).toContain('Puts0')
    expect(wrapper.find('.doughnut-stub').exists()).toBe(false)

    await wrapper.setProps({ callVol: 0 })
    expect(wrapper.text()).toContain('Call and put totals are both zero')
    expect(wrapper.get('.gex-distribution__ratio').attributes('aria-label')).toBe('Calls 0.0%; puts 0.0%')
    expect(wrapper.find('.doughnut-stub').exists()).toBe(false)
    wrapper.unmount()
  })
})

describe('Overview summary metrics', () => {
  it('keeps every metric, highlights the comparison fallback, and uses readable precision', () => {
    const wrapper = mount(OverviewMetrics, {
      props: {
        scopeLabel: '2W',
        levels: {
          data_date: '2026-09-11',
          date_prev: '2026-09-09',
          date_prev_gap_trading_days: 2,
          date_prev_is_stale: true,
          hvl: '761.000000',
          pcr_volume: '1.612345',
          call_interest_percentage: '24.77123',
          put_interest_percentage: '75.22877',
          call_open_interest_total: '1383923',
          put_open_interest_total: '4202245',
          call_volume_total: '971257',
          put_volume_total: '1567520',
          total_oi_delta: '480277',
          total_volume_delta: '-981474',
        },
      },
    })

    expect(wrapper.findAll('.gex-metric')).toHaveLength(8)
    expect(wrapper.text()).toContain('HVL761')
    expect(wrapper.text()).toContain('Volume put/call ratio1.61')
    expect(wrapper.text()).toContain('Total open interest5.59Mcontracts')
    expect(wrapper.text()).toContain('Call OI share24.8%')
    expect(wrapper.text()).toContain('Open-interest change+480.28Kcontracts')
    expect(wrapper.text()).toContain('Volume change−981.47Kcontracts')
    expect(wrapper.text()).toMatch(/Prior 2026-09-09\s+· 2 sessions back/)
    expect(wrapper.text()).toContain('Earlier comparison')
    expect(wrapper.text()).toContain('nearest available session')
    expect(wrapper.text()).not.toContain('1.612345')
  })

  it('keeps zero readings and does not manufacture totals from a missing side', () => {
    const wrapper = mount(OverviewMetrics, {
      props: {
        levels: {
          hvl: 0,
          pcr_volume: 0,
          call_interest_percentage: 0,
          put_interest_percentage: null,
          call_open_interest_total: 0,
          put_open_interest_total: null,
          call_volume_total: 0,
          put_volume_total: 0,
          total_oi_delta: 0,
          total_volume_delta: null,
        },
      },
    })

    const values = wrapper.findAll('.gex-metric-value').map(node => node.text())
    expect(values).toEqual([
      '0', '0.00', 'Unavailable', '0contracts',
      '0.0%', 'Unavailable', '0contracts', 'Unavailable',
    ])
  })
})
