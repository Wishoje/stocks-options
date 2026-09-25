import { flushPromises, mount } from '@vue/test-utils'
import axios from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import SkewTile from '@/Components/SkewTile.vue'

vi.mock('axios', () => ({ default: { get: vi.fn() } }))

function deferred() {
  let resolve
  let reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}

function summary(symbol = 'SPY', days = 7, skew = 0) {
  return {
    data: {
      id: `${symbol}-${days}`,
      symbol,
      data_date: '2026-09-09',
      exp_date: days === 0 ? '2026-09-09' : days === 7 ? '2026-09-16' : '2026-09-30',
      iv_put_25d: '0.25000000',
      iv_call_25d: '0.25000000',
      skew_pc: skew,
      curvature: 0,
      skew_pc_dod: null,
      curvature_dod: '0.00000000',
      n_points: 0,
      k_span: 0,
      source_chain_date: '2026-09-08',
      calculation_version: 'v4',
    },
  }
}

function history(symbol = 'SPY', days = 7, count = 30) {
  return {
    data: Array.from({ length: count }, (_, index) => ({
      data_date: new Date(Date.UTC(2026, 7, 11 + index)).toISOString().slice(0, 10),
      exp_date: days === 0 ? '2026-09-09' : days === 7 ? '2026-09-16' : '2026-09-30',
      dte: days,
      iv_put_25d: index === 2 ? null : `0.${String(24 + index).padStart(2, '0')}0000`,
      iv_call_25d: '0.220000',
      skew_pc: index === 0 ? 0 : index === 2 ? null : `${(index / 1000).toFixed(6)}`,
      curvature: index === 3 ? 0 : '0.00123456',
      quality_code: `${symbol}-${index}`,
    })),
  }
}

describe('Positioning skew', () => {
  let wrapper

  beforeEach(() => {
    axios.get.mockReset()
    axios.get.mockImplementation((url, options) => Promise.resolve(
      url === '/api/iv/skew/by-bucket'
        ? summary(options.params.symbol, options.params.days)
        : history(options.params.symbol, options.params.days),
    ))
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('keeps every payload and history row while secondary details start collapsed', async () => {
    wrapper = mount(SkewTile, { props: { symbol: 'SPY' } })
    await flushPromises()

    expect(axios.get).toHaveBeenCalledTimes(2)
    expect(axios.get).toHaveBeenCalledWith('/api/iv/skew/by-bucket', expect.objectContaining({
      params: { symbol: 'SPY', days: 7 },
    }))
    expect(axios.get).toHaveBeenCalledWith('/api/iv/skew/history/bucket', expect.objectContaining({
      params: { symbol: 'SPY', days: 7, limit: 30 },
    }))
    expect(wrapper.vm.summaryPayload).toEqual(summary().data)
    expect(wrapper.vm.historyPayload).toEqual(history().data)
    expect(wrapper.vm.skewSeries).toHaveLength(30)
    expect(wrapper.findAll('[data-testid="skew-history-complete"] tbody tr')).toHaveLength(30)
    expect(wrapper.get('[data-testid="skew-history-complete"]').text()).toContain('Quality Code')
    expect(wrapper.get('[data-testid="skew-history-complete"]').attributes('open')).toBeUndefined()
    expect(wrapper.find('[data-testid="skew-summary-exact"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Skew history')
    expect(wrapper.text()).toContain('Calculation details')
    expect(wrapper.text()).toContain('All 30 daily readings')
    expect(wrapper.text()).toContain('0.0pp')
    expect(wrapper.text()).toContain('Unavailable')
    expect(wrapper.text()).not.toContain('Quote diagnostics')
    expect(wrapper.text()).not.toContain('Open authorized skew diagnostics')

    expect(wrapper.get('#positioning-skew-1w').attributes('aria-controls')).toBe('positioning-skew-panel')
    expect(wrapper.get('#positioning-skew-panel').attributes()).toMatchObject({
      role: 'tabpanel',
      'aria-labelledby': 'positioning-skew-1w',
    })

    expect(wrapper.get('[data-testid="history-readings-disclosure"]').attributes('open')).toBeUndefined()
    expect(wrapper.get('[data-testid="history-calculation-disclosure"]').attributes('open')).toBeUndefined()
    const primaryMetrics = wrapper.findAll('.gex-metric[data-prominence="primary"]')
    expect(primaryMetrics.map(metric => metric.text())).toEqual(expect.arrayContaining([
      expect.stringContaining('Skew'),
      expect.stringContaining('Daily skew change'),
    ]))

    expect(wrapper.emitted('reading-inspected')).toBeUndefined()
    await wrapper.get('input[type="range"]').setValue(4)
    expect(wrapper.emitted('reading-inspected')).toHaveLength(1)
    await wrapper.get('[data-testid="skew-history-complete"] .gex-sort').trigger('click')
    expect(axios.get).toHaveBeenCalledTimes(2)
  })

  it('uses a direction cue for skew change and a concise status when curvature is absent', async () => {
    const summaryResponse = summary()
    summaryResponse.data.skew_pc_dod = -0.007
    summaryResponse.data.curvature = null
    summaryResponse.data.curvature_dod = null
    delete summaryResponse.data.n_points
    delete summaryResponse.data.k_span
    axios.get.mockImplementation((url, options) => Promise.resolve(
      url === '/api/iv/skew/by-bucket'
        ? summaryResponse
        : history(options.params.symbol, options.params.days),
    ))

    wrapper = mount(SkewTile, { props: { symbol: 'SPY' } })
    await flushPromises()

    const changeMetric = wrapper.findAll('.gex-metric').find(item => item.text().startsWith('Daily skew change'))
    expect(changeMetric.text()).toContain('↓ -0.7pp')
    expect(wrapper.findAll('.gex-metric').filter(item => item.text().startsWith('Curvature'))).toHaveLength(0)
    expect(wrapper.get('.gex-inline-status').text()).toContain('Curvature is not available for this expiry')
    expect(wrapper.vm.hasQuoteDiagnostics).toBe(false)
    expect(wrapper.findAll('.gex-badge').some(item => item.text().startsWith('Quality'))).toBe(false)
    expect(wrapper.text()).not.toContain('Quality result')
  })

  it('changes bucket with one summary/history pair and makes same-bucket clicks local', async () => {
    wrapper = mount(SkewTile, { props: { symbol: 'SPY' } })
    await flushPromises()

    await wrapper.get('#positioning-skew-0d').trigger('click')
    await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(4)
    expect(axios.get.mock.calls.slice(-2).map(([, options]) => options.params.days)).toEqual([0, 0])
    expect(wrapper.vm.row.exp).toBe('2026-09-09')
    expect(wrapper.findAll('[data-testid="skew-history-complete"] tbody tr')).toHaveLength(30)

    await wrapper.get('#positioning-skew-0d').trigger('click')
    await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(4)
  })

  it('aborts an obsolete bucket pair and cannot mix its late summary with current history', async () => {
    const oldSummary = deferred()
    const oldHistory = deferred()
    axios.get.mockImplementation((url, options) => {
      if (options.params.days === 7) {
        return url === '/api/iv/skew/by-bucket' ? oldSummary.promise : oldHistory.promise
      }
      return Promise.resolve(url === '/api/iv/skew/by-bucket'
        ? summary('SPY', 0, -0.0125)
        : history('SPY', 0, 4))
    })

    wrapper = mount(SkewTile, { props: { symbol: 'SPY' } })
    const oldSignals = axios.get.mock.calls.map(([, options]) => options.signal)
    await wrapper.get('#positioning-skew-0d').trigger('click')
    await flushPromises()

    expect(oldSignals.every(signal => signal.aborted)).toBe(true)
    expect(wrapper.vm.row.exp).toBe('2026-09-09')
    expect(wrapper.vm.row.skew_pc).toBe(-0.0125)
    expect(wrapper.vm.historyPayload).toHaveLength(4)

    oldSummary.resolve(summary('SPY', 7, 0.99))
    oldHistory.resolve(history('SPY', 7, 30))
    await flushPromises()
    expect(wrapper.vm.row.exp).toBe('2026-09-09')
    expect(wrapper.vm.row.skew_pc).toBe(-0.0125)
    expect(wrapper.vm.historyPayload).toHaveLength(4)
    expect(wrapper.vm.historyPayload.every(item => item.dte === 0)).toBe(true)
  })

  it('shows an empty API scope as unavailable rather than zero', async () => {
    axios.get.mockImplementation(url => Promise.resolve({ data: url.endsWith('by-bucket') ? {} : [] }))
    wrapper = mount(SkewTile)
    await flushPromises()

    expect(wrapper.text()).toContain('No skew data is available for the selected bucket')
    expect(wrapper.find('.gex-metric').exists()).toBe(false)
    expect(wrapper.text()).toContain('Skew data is not available yet')
    expect(wrapper.text()).toContain('Retry')
  })

  it('does not load or change scope while inactive and resumes for the current symbol', async () => {
    wrapper = mount(SkewTile, { props: { symbol: 'SPY', active: false } })
    await flushPromises()
    expect(axios.get).not.toHaveBeenCalled()

    await wrapper.setProps({ active: true })
    await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(2)

    await wrapper.setProps({ active: false })
    await wrapper.setProps({ symbol: 'QQQ' })
    await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(2)

    await wrapper.setProps({ active: true })
    await flushPromises()
    expect(axios.get).toHaveBeenCalledTimes(4)
    expect(wrapper.vm.row.symbol).toBe('QQQ')
  })
})
