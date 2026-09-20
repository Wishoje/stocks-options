import { flushPromises, mount } from '@vue/test-utils'
import axios from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import ExpiryPressureTile from '@/Components/ExpiryPressureTile.vue'

vi.mock('axios', () => ({ default: { get: vi.fn() } }))

function deferred() {
  let resolve
  let reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}

function response(symbol = 'SPY') {
  return {
    data: {
      symbol,
      data_date: '2026-09-09',
      headline_pin: 82,
      entries: [
        {
          exp_date: '2026-09-09',
          pin_score: 82,
          max_pain: 500.25,
          source_chain_date: '2026-09-09',
          clusters: Array.from({ length: 5 }, (_, index) => ({
            strike: 495 + index,
            density: 1000 + index,
            distance: `0.0${index}`,
            score: `8${index}.5`,
          })),
        },
        {
          exp_date: '2026-09-10',
          pin_score: 41,
          max_pain: null,
          source_chain_date: null,
          clusters: [
            { strike: 505, density: 800, distance: 0, score: 44.4 },
            { strike: 510, density: 700, distance: null, score: 40 },
          ],
        },
        {
          exp_date: '2026-09-11',
          pin_score: 0,
          max_pain: 0,
          source_chain_date: '2026-09-08',
          clusters: [],
        },
      ],
    },
  }
}

describe('Positioning expiry pressure', () => {
  let wrapper

  beforeEach(() => {
    vi.useFakeTimers()
    axios.get.mockReset()
    axios.get.mockResolvedValue(response())
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.clearAllTimers()
    vi.useRealTimers()
  })

  it('renders every expiry and cluster field without truncating the selected or full datasets', async () => {
    const source = response().data
    wrapper = mount(ExpiryPressureTile, { props: { symbol: 'SPY', days: 3 } })
    await flushPromises()

    expect(wrapper.vm.data).toEqual(source)
    expect(wrapper.findAll('[data-testid="pressure-expiry-table"] tbody tr')).toHaveLength(3)
    expect(wrapper.findAll('[data-testid="selected-pressure-clusters"] tbody tr')).toHaveLength(5)
    expect(wrapper.findAll('[data-testid="all-pressure-clusters"] tbody tr')).toHaveLength(7)
    expect(wrapper.get('[data-testid="selected-pressure-clusters"]').text()).toContain('1000')
    expect(wrapper.get('[data-testid="selected-pressure-clusters"]').text()).toContain('80.5')
    expect(wrapper.text()).toContain('82.0of 100')
    expect(wrapper.text()).toContain('not a probability')
    expect(wrapper.emitted('reading-inspected')).toBeUndefined()

    await wrapper.get('[data-testid="pressure-expiry-select"]').setValue('2026-09-11')
    expect(wrapper.emitted('reading-inspected')).toHaveLength(1)
    const detail = wrapper.get('[data-testid="selected-pressure-detail"]')
    expect(detail.text()).toContain('Score 0.0 / 100')
    expect(detail.text()).toContain('Max pain0')
    expect(axios.get).toHaveBeenCalledTimes(1)
  })

  it('keeps the pin explanation visible and opens its reading guide in a modal', async () => {
    wrapper = mount(ExpiryPressureTile)
    await flushPromises()

    const metric = wrapper.get('.gex-metric[aria-label="Headline pin score"]')
    expect(metric.attributes('data-prominence')).toBe('primary')
    expect(metric.text()).toContain('0–100 score combining open-interest density and distance from spot; not a probability')
    expect(wrapper.find('[aria-label="Explain the pin score"]').exists()).toBe(false)

    const trigger = wrapper.get('[aria-controls="expiry-pressure-guide"]')
    expect(trigger.text()).toBe('Reading guide')
    expect(trigger.attributes('aria-haspopup')).toBe('dialog')
    expect(trigger.attributes('aria-expanded')).toBe('false')

    await trigger.trigger('click')
    const dialog = document.querySelector('#expiry-pressure-guide')
    expect(dialog).not.toBeNull()
    expect(dialog.getAttribute('role')).toBe('dialog')
    expect(dialog.textContent).toContain('How to read expiry pressure')
    expect(dialog.textContent).toContain('The score is not a probability')
    expect(trigger.attributes('aria-expanded')).toBe('true')

    dialog.querySelector('.gex-help-dialog__close').click()
    await flushPromises()
    expect(document.querySelector('#expiry-pressure-guide')).toBeNull()
    expect(trigger.attributes('aria-expanded')).toBe('false')
  })

  it('keeps missing headline data unavailable instead of displaying zero', async () => {
    axios.get.mockResolvedValue({
      data: { symbol: 'SPY', data_date: null, headline_pin: null, entries: [] },
    })
    wrapper = mount(ExpiryPressureTile)
    await flushPromises()

    const metric = wrapper.findAll('.gex-metric').find(item => item.text().includes('Headline pin score'))
    expect(metric.text()).toContain('Unavailable')
    expect(metric.text()).not.toContain('0of 100')
    expect(wrapper.text()).toContain('No expiry pressure readings')
  })

  it('aborts and rejects a late response after the symbol changes', async () => {
    const old = deferred()
    axios.get.mockImplementation((_url, options) => options.params.symbol === 'AAPL'
      ? old.promise
      : Promise.resolve(response('QQQ')))

    wrapper = mount(ExpiryPressureTile, { props: { symbol: 'AAPL', days: 3 } })
    const oldSignal = axios.get.mock.calls[0][1].signal
    await wrapper.setProps({ symbol: 'QQQ' })
    await flushPromises()

    expect(oldSignal.aborted).toBe(true)
    expect(wrapper.vm.data.symbol).toBe('QQQ')
    old.resolve(response('AAPL'))
    await flushPromises()
    expect(wrapper.vm.data.symbol).toBe('QQQ')
    expect(wrapper.text()).not.toContain('AAPL')
  })

  it('retries a missing snapshot only while active and stops after three retries', async () => {
    axios.get.mockResolvedValue({
      data: { symbol: 'SPY', data_date: null, headline_pin: null, entries: [] },
    })
    wrapper = mount(ExpiryPressureTile)
    await flushPromises()

    await wrapper.setProps({ active: false })
    await vi.advanceTimersByTimeAsync(12000)
    expect(axios.get).toHaveBeenCalledTimes(1)

    await wrapper.setProps({ active: true })
    await flushPromises()
    await vi.advanceTimersByTimeAsync(20000)
    expect(axios.get).toHaveBeenCalledTimes(5)
    expect(vi.getTimerCount()).toBe(0)
  })
})
