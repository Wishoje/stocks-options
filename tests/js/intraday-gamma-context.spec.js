import { mount, flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import IntradayGammaContext from '@/Components/IntradayGammaContext.vue'
import IntradayWallTracker from '@/Components/IntradayWallTracker.vue'
import axios from 'axios'
vi.mock('axios', () => ({ default: { get: vi.fn() } }))

const reading = (minute = '00', flip = 99) => {
  const row = { observed_at: `2026-09-30T14:${minute}:00Z`, basis_key: 'basis', scope_key: 'scope', spot: 100,
    walls: { put: [{ strike: 95 }], call: [{ strike: 105 }] }, provenance: { oi_date: '2026-09-29' } }
  const nearest = { price: flip, below_sign: -1, above_sign: 1 }
  row.gamma_context = { status: 'ready', model_version: 'intraday-fixed-reference-iv-gamma-profile.v1',
    observed_at: row.observed_at, basis_key: row.basis_key, scope_key: row.scope_key, reference_price: row.spot,
    regime: 'positive_gamma', current_sign: 1, nearest_crossing: nearest, gamma_flip: flip,
    distance_to_nearest_flip_pct: 100 - flip, flip_status: 'single_crossing', crossings: [nearest],
    grid: { lower_price: 90, upper_price: 110 } }
  return row
}
const render = (row, rows = [row]) => mount(IntradayGammaContext, { props: { reading: row, readings: rows } })

describe('Intraday gamma context', () => {
  it('shows the selected price, nearest flip and comparable movement', () => {
    const rows = [reading(), reading('05', 99.5)]
    const wrapper = render(rows[1], rows)
    expect(wrapper.text()).toContain('Positive gamma')
    expect(wrapper.text()).toContain('99.5')
    expect(wrapper.text()).toContain('0.5% above')
    expect(wrapper.text()).toContain('Up 0.5 points')
    expect(wrapper.text()).toContain('Since 10:00 AM ET')
  })

  it('hides older readings without context and rejects mismatched observation identity', () => {
    const row = reading()
    delete row.gamma_context
    expect(render(row).find('section').exists()).toBe(false)
    const other = reading()
    other.gamma_context.observed_at = '2026-09-30T14:05:00Z'
    expect(render(other).find('section').exists()).toBe(false)
  })

  it('does not compare across changed inputs or skipped gamma readings', () => {
    const first = reading(), last = reading('05', 99.5)
    first.basis_key = first.gamma_context.basis_key = 'other-basis'
    expect(render(last, [first, last]).text()).toContain('First gamma reading')
    const skipped = reading('03')
    delete skipped.gamma_context
    expect(render(last, [reading(), skipped, last]).text()).toContain('First gamma reading')
  })

  it('explains no crossing within the tested range without displaying a made-up level', () => {
    const row = reading()
    Object.assign(row.gamma_context, { flip_status: 'no_crossing', gamma_flip: null, nearest_crossing: null, crossings: [] })
    const wrapper = render(row)
    expect(wrapper.text()).toContain('No flip in range')
    expect(wrapper.text()).toContain('Tested 90–110')
    expect(wrapper.text()).not.toContain('0% above')
  })

  it('lists multiple crossings and does not claim movement of one unique flip', () => {
    const row = reading()
    row.gamma_context.flip_status = 'multiple_crossings'
    row.gamma_context.gamma_flip = null
    row.gamma_context.crossings.push({ price: 104, below_sign: 1, above_sign: -1 })
    const wrapper = render(row)
    expect(wrapper.text()).toContain('Nearest of 2 flips')
    expect(wrapper.text()).toContain('Multiple flips')
    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    expect(wrapper.text()).not.toContain('First gamma reading')
  })

  it('follows the existing chart slider without extra requests or future-reading comparisons', async () => {
    const rows = [reading(), reading('05', 99.5)]
    axios.get.mockReset()
    axios.get.mockResolvedValue({ data: { symbol: 'SPY', schema_version: 'intraday-walls.v1', dataset: 'intraday_capture',
      timeframe: '7d', session: '2026-09-30', segments: [{ id: 1, start_reason: 'first_observation', observations: rows,
        migration: { put: { amount: 0 }, call: { amount: 0 } } }] } })
    const wrapper = mount(IntradayWallTracker, { props: { symbol: 'SPY' } })
    await flushPromises()
    expect(wrapper.get('[aria-label="Intraday gamma context"]').text()).toContain('Up 0.5 points')
    await wrapper.get('[aria-label="Inspect wall reading"]').setValue('0')
    expect(wrapper.get('[aria-label="Intraday gamma context"]').text()).toContain('First gamma reading')
    expect(wrapper.get('[aria-label="Intraday gamma context"]').text()).not.toContain('99.5')
    expect(axios.get).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })
})
