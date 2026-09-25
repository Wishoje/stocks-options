import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WallLevels from '@/Components/WallLevels.vue'
import { wallCoverage, wallReadings, formatWallCoverage } from '@/lib/wall-levels'

function levels() { return {
  symbol: 'SPY', data_date: '2026-09-24', put_support: 550, put_wall_2: 545, call_resistance: 560,
  social_quality: { total_open_interest: 10000, missing_gamma_oi_share: .0028, missing_input_rows: 2, source_rows: 100, missing_expiration_count: 0 },
  strike_data: [{ strike: 550, net_gex: -2000000, call_gex: 1000000, put_gex: 3000000 }, { strike: 545, net_gex: 0, call_gex: 100, put_gex: 100 }],
} }
describe('Wall levels', () => {
  it('normalizes the original strike data without mutation, preserving zero and missing readings', () => {
    const source = levels(), original = JSON.stringify(source)
    expect(wallReadings(source, 'put').map(r => r.net)).toEqual([-20000, 0])
    expect(wallReadings(source, 'call')[0].net).toBeNull()
    expect(JSON.stringify(source)).toBe(original)
    expect(wallReadings({ put_support: null }, 'put')).toEqual([])
  })
  it('does not round nonzero coverage gaps to full coverage', () => {
    expect(formatWallCoverage(99.999)).toBe('>99.99%')
    expect(formatWallCoverage(100)).toBe('100.00%')
    expect(formatWallCoverage(0)).toBe('0.00%')
    expect(formatWallCoverage(null)).toBe('Not available')
  })
  it('only reports measurable OI coverage and does not certify it as GEX coverage', () => {
    expect(wallCoverage(levels().social_quality)).toBeCloseTo(99.72)
    expect(wallCoverage({ total_open_interest: 0, missing_gamma_oi_share: 0 })).toBeNull()
    expect(wallCoverage({ total_open_interest: 10, missing_gamma_oi_share: 1.1 })).toBeNull()
    expect(wallCoverage(null)).toBeNull()
  })
  it('lets users inspect additional walls and resets the selection when the source changes', async () => {
    const wrapper = mount(WallLevels, { props: { levels: levels(), symbol: 'SPY' } })
    await wrapper.get('[aria-label="Put wall levels"]').findAll('button')[1].trigger('click')
    expect(wrapper.get('.wall-price').text()).toBe('545')
    expect(wrapper.get('.wall-reading strong').text()).toBe('0')
    await wrapper.setProps({ levels: { ...levels(), put_support: 600, put_wall_2: null } })
    expect(wrapper.get('.wall-price').text()).toBe('600')
    expect(wrapper.get('.wall-reading strong').text()).toBe('Not available')
    wrapper.unmount()
  })
  it('keeps user guidance focused on levels and units without coverage diagnostics', () => {
    const wrapper = mount(WallLevels, { props: { levels: levels(), symbol: 'SPY' } })
    expect(wrapper.get('summary').text()).toBe('How to read these levels')
    expect(wrapper.find('details').attributes('open')).toBeUndefined()
    expect(wrapper.text()).not.toMatch(/incomplete|unverified|missing|coverage|excluded|99\.72/i)
    expect(wrapper.text()).toContain('USD per 1% underlying move')
    expect(wrapper.text()).toContain('Data as of 2026-09-24')
    wrapper.unmount()
  })
  it('opens the existing chart through navigation, without its own data request', async () => {
    const wrapper = mount(WallLevels, { props: { levels: levels(), showChartLink: true } })
    await wrapper.get('.walls-button').trigger('click')
    expect(wrapper.emitted('open-chart')).toHaveLength(1)
    wrapper.unmount()
  })
})
