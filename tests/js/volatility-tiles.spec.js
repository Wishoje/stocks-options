import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import Seasonality5Tile from '@/Components/Seasonality5Tile.vue'
import TermTile from '@/Components/TermTile.vue'
import VRPTile from '@/Components/VRPTile.vue'

let wrapper

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
})

describe('Volatility term structure', () => {
  it('keeps every expiry, preserves missing versus zero, and exposes a keyboard inspector', async () => {
    const items = Object.freeze([
      Object.freeze({ exp: '2026-09-18', iv: '0.200000', source_chain_date: '2026-09-11' }),
      Object.freeze({ exp: '2026-09-25', iv: null, source_chain_date: '2026-09-10' }),
      Object.freeze({ exp: '2026-10-02', iv: 0, source_chain_date: '2026-09-11' }),
      Object.freeze({ exp: '2026-10-16', iv: 0.3, source_chain_date: '2026-09-11' }),
    ])

    wrapper = mount(TermTile, {
      attachTo: document.body,
      props: { items, date: '2026-09-11' },
    })

    expect(wrapper.vm.termItems).toHaveLength(4)
    expect(wrapper.vm.termItems.map(item => item.iv_value)).toEqual([0.2, null, 0, 0.3])
    expect(wrapper.findAll('tbody tr')).toHaveLength(4)
    expect(wrapper.text()).toContain('2026-09-10')
    expect(wrapper.get('[data-testid="term-readings-disclosure"]').attributes('open')).toBeUndefined()
    expect(wrapper.get('.term-line').attributes('d')).not.toMatch(/NaN|Infinity/)
    expect((wrapper.get('.term-line').attributes('d').match(/M/g) || [])).toHaveLength(2)
    expect(wrapper.text()).toContain('Contango')
    expect(wrapper.text()).toContain('+10.0pp')

    const scrubber = wrapper.get('input[type="range"]')
    expect(scrubber.attributes('aria-valuetext')).toContain('2026-09-18')
    expect(wrapper.emitted('reading-inspected')).toBeUndefined()
    await scrubber.setValue(1)
    expect(wrapper.emitted('reading-inspected')).toBeUndefined()
    expect(scrubber.attributes('aria-valuetext')).toContain('IV unavailable')
    expect(wrapper.get('[aria-live="polite"]').text()).toContain('As of 2026-09-10')
    await scrubber.setValue(2)
    expect(wrapper.emitted('reading-inspected')).toHaveLength(1)
    expect(wrapper.get('[aria-live="polite"]').text()).toContain('0.0% IV')
  })

  it('shows returned-but-unusable expiries separately from an empty response', () => {
    wrapper = mount(TermTile, {
      props: {
        items: [
          { exp: '2026-09-18', iv: null, source_chain_date: '2026-09-11' },
          { tenor: 30, iv: undefined },
        ],
      },
    })

    expect(wrapper.get('.term-empty').text()).toContain('No usable IV readings')
    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    expect(wrapper.find('.gex-status').exists()).toBe(false)
    wrapper.unmount()

    wrapper = mount(TermTile)
    expect(wrapper.get('.gex-status').text()).toContain('No term structure data')
    expect(wrapper.find('.term-chart').exists()).toBe(false)
  })

  it('opens a contained reading guide without changing the source data', async () => {
    const items = [{ exp: '2026-09-18', iv: 0.2, source_chain_date: '2026-09-11' }]
    wrapper = mount(TermTile, { attachTo: document.body, props: { items } })

    await wrapper.get('.gex-button').trigger('click')
    const dialog = document.body.querySelector('[role="dialog"]')
    expect(dialog?.textContent).toContain('How to read term structure')
    expect(dialog?.textContent).toContain('compare implied volatility across dates')
    expect(items).toEqual([{ exp: '2026-09-18', iv: 0.2, source_chain_date: '2026-09-11' }])
  })
})

describe('Variance risk premium', () => {
  it('makes VRP the primary metric and maps a finite z-score onto the historical gauge', () => {
    wrapper = mount(VRPTile, {
      props: {
        date: '2026-09-11',
        iv1m: '0.2400',
        rv20: '0.1400',
        vrp: '0.1000',
        z: '1.5',
        sourceMeta: {
          anchor_date: '2026-09-11',
          selected_exp_date: '2026-10-02',
          source_chain_date: '2026-09-11',
          fallback_reason: null,
        },
      },
    })

    const primary = wrapper.get('.gex-metric[data-prominence="primary"]')
    expect(primary.text()).toContain('Volatility premium+10.0pp')
    expect(wrapper.text()).toContain('Implied volatility24.0%')
    expect(wrapper.text()).toContain('Realized volatility14.0%')
    expect(wrapper.text()).toContain('IV rich')
    expect(wrapper.get('[role="meter"]').attributes()).toMatchObject({
      'aria-valuenow': '1.5',
      'aria-valuetext': expect.stringContaining('+1.50σ'),
    })
    expect(wrapper.get('.vrp-gauge__pointer').attributes('style')).toContain('left: 75%')
    expect(wrapper.get('[data-testid="vrp-calculation-disclosure"]').attributes('open')).toBeUndefined()
    expect(wrapper.text()).toContain('2026-10-02')
  })

  it('keeps zero values recorded and does not classify a missing z-score as neutral', () => {
    wrapper = mount(VRPTile, {
      props: { iv1m: 0, rv20: 0, vrp: 0, z: 0 },
    })

    expect(wrapper.text()).toContain('Volatility premium0.0pp')
    expect(wrapper.text()).toContain('Balanced')
    expect(wrapper.get('[role="meter"]').attributes('aria-valuenow')).toBe('0')
    wrapper.unmount()

    wrapper = mount(VRPTile, {
      props: { iv1m: 0.2, rv20: 0.2, vrp: 0, z: null },
    })
    expect(wrapper.text()).toContain('Signal unavailable')
    expect(wrapper.find('[role="meter"]').exists()).toBe(false)
    expect(wrapper.get('.vrp-gauge--empty').text()).toContain('Historical comparison unavailable')
  })

  it('shows a clear empty state when every returned metric is missing', () => {
    wrapper = mount(VRPTile, {
      props: {
        iv1m: null,
        rv20: null,
        vrp: null,
        z: null,
        sourceMeta: {
          anchor_date: '2026-09-11',
          selected_exp_date: null,
          source_chain_date: null,
          fallback_reason: 'no_term_rows',
        },
      },
    })
    expect(wrapper.get('.gex-status').text()).toContain('No volatility premium data')
    expect(wrapper.get('.gex-status').text()).toContain('No term structure rows were available')
    expect(wrapper.find('.gex-metric').exists()).toBe(false)
    expect(wrapper.get('[data-testid="vrp-calculation-disclosure"]').attributes('open')).toBeUndefined()
    expect(wrapper.text()).toContain('2026-09-11')
    expect(wrapper.text()).toContain('No term structure rows were available')
  })

  it.each([
    [4, 3, 'left: 100%', '+4.00σ'],
    [-5, -3, 'left: 0%', '−5.00σ'],
  ])('clamps a z-score of %s to the meter boundary without hiding its actual value', (z, meterValue, pointer, label) => {
    wrapper = mount(VRPTile, {
      props: { iv1m: 0.2, rv20: 0.1, vrp: 0.1, z },
    })

    expect(wrapper.get('[role="meter"]').attributes('aria-valuenow')).toBe(String(meterValue))
    expect(wrapper.get('[role="meter"]').attributes('aria-valuetext')).toContain(label)
    expect(wrapper.get('[role="meter"]').attributes('aria-valuetext')).toContain('display boundary')
    expect(wrapper.get('.vrp-gauge__pointer').attributes('style')).toContain(pointer)
  })
})

describe('Five-session seasonality', () => {
  it('renders all five forward sessions on a signed zero baseline and preserves gaps', async () => {
    wrapper = mount(Seasonality5Tile, {
      attachTo: document.body,
      props: {
        date: '2026-09-11',
        d1: 0,
        d2: null,
        d3: '-0.006',
        d4: 0.003,
        d5: 0.002,
        cum5: '-0.001',
        z: '-1.2',
        note: 'Computed from +/- 2d calendar window across 15y (samples: 12).',
      },
    })

    const days = wrapper.findAll('.seasonality-day')
    expect(days).toHaveLength(5)
    expect(wrapper.vm.dailyItems.map(item => item.value)).toEqual([0, null, -0.006, 0.003, 0.002])
    expect(days[0].text()).toContain('0.0%')
    expect(days[1].text()).toContain('Unavailable')
    expect(days[2].find('.seasonality-bar').attributes('data-negative')).toBe('true')
    expect(wrapper.findAll('.seasonality-zero')).toHaveLength(5)
    expect(wrapper.text()).toContain('Cumulative 5D−0.1%')
    expect(wrapper.text()).toContain('Bearish headwind')
    expect(wrapper.text()).toContain('samples: 12')
    expect(wrapper.get('[data-testid="seasonality-calculation-disclosure"]').attributes('open')).toBeUndefined()

    await days[0].trigger('keydown', { key: 'ArrowRight' })
    expect(wrapper.emitted('reading-inspected')).toBeUndefined()
    expect(days[1].attributes('aria-pressed')).toBe('true')
    expect(document.activeElement).toBe(days[1].element)
    expect(wrapper.get('[aria-live="polite"]').text()).toContain('Unavailable')
    await days[1].trigger('keydown', { key: 'End' })
    expect(wrapper.emitted('reading-inspected')).toHaveLength(1)
    expect(days[4].attributes('aria-pressed')).toBe('true')
  })

  it('keeps available daily values when cumulative return and z-score are missing', () => {
    wrapper = mount(Seasonality5Tile, {
      props: { d1: 0.001, d2: null, d3: null, d4: null, d5: null, cum5: null, z: null },
    })

    expect(wrapper.find('.gex-status').exists()).toBe(false)
    expect(wrapper.text()).toContain('Cumulative 5DUnavailable')
    expect(wrapper.text()).toContain('Signal unavailable')
    expect(wrapper.findAll('.seasonality-day')).toHaveLength(5)
  })

  it('shows the source note in the empty state without inventing neutral data', () => {
    wrapper = mount(Seasonality5Tile, {
      props: {
        d1: null,
        d2: null,
        d3: null,
        d4: null,
        d5: null,
        cum5: null,
        z: null,
        note: 'Not enough price history.',
      },
    })

    expect(wrapper.get('.gex-status').text()).toContain('Not enough price history.')
    expect(wrapper.text()).not.toContain('Neutral seasonal')
    expect(wrapper.find('.seasonality-chart').exists()).toBe(false)
  })
})
