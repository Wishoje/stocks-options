import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import WallFlowDetails from '@/Components/WallFlowDetails.vue'

const flow = () => ({
  side: 'put', strike: 100, data_date: '2026-09-30', wall_build_state: 'building', stable_band_pct: 2, open_interest: 200,
  daily: { comparable: true, change: 50, change_pct: 33.333, baseline_oi: 150, baseline_date: '2026-09-29' },
  five_session: { comparable: true, change: -100, change_pct: -33.333, baseline_oi: 300, baseline_date: '2026-09-23' },
  expiry_contributions: [{ expiry: '2026-10-09', open_interest: 80, change_1d: 30, change_5d: -20 }, { expiry: '2026-10-02', open_interest: 120, change_1d: 20, change_5d: -80 }],
  activity: { call_volume: 0, put_volume: null },
})
describe('EOD wall OI changes', () => {
  it('shows daily and five-session directions separately and keeps the guide and full table collapsed', () => {
    const wrapper = mount(WallFlowDetails, { props: { flow: flow() } })
    expect(wrapper.get('.flow-state').text()).toBe('Building')
    expect(wrapper.findAll('.flow-metrics dd').map(x => x.text())).toEqual(['+50 contracts', '−100 contracts'])
    expect(wrapper.text()).toContain('+33.3%')
    expect(wrapper.text()).toContain('since Sep 23')
    expect(wrapper.text()).toContain('label always follows the daily')
    expect(wrapper.findAll('.flow-drivers strong').map(x => x.text())).toEqual(['+30', '+20'])
    expect(wrapper.get('details').attributes('open')).toBeUndefined()
    expect(wrapper.get('.flow-table').attributes('tabindex')).toBe('0')
    expect(wrapper.findAll('.flow-activity dd').map(x => x.text())).toEqual(['0', '—'])
    expect(wrapper.text()).not.toMatch(/snapshot|missing inputs|incomplete/i)
    wrapper.unmount()
  })
  it('clears the former strike and side when a different wall is selected', async () => {
    const wrapper = mount(WallFlowDetails, { props: { flow: flow() } })
    const next = { ...flow(), side: 'call', strike: 110, wall_build_state: 'unwinding', expiry_contributions: [] }
    await wrapper.setProps({ flow: next })
    expect(wrapper.attributes('aria-label')).toBe('Call wall build and unwind')
    expect(wrapper.text()).toContain('Call OI at 110')
    expect(wrapper.text()).not.toContain('Put OI at 100')
    expect(wrapper.get('.flow-state').text()).toBe('Unwinding')
    expect(wrapper.find('.flow-drivers').exists()).toBe(false)
    await wrapper.setProps({ flow: null })
    expect(wrapper.find('section').exists()).toBe(false)
    wrapper.unmount()
  })
  it('distinguishes a real zero from a comparison that cannot be made', () => {
    const data = { ...flow(), wall_build_state: null, daily: { comparable: false, change: null, change_pct: null }, five_session: { comparable: true, change: 0, baseline_oi: 0, baseline_date: '2026-09-23', change_pct: null } }
    const wrapper = mount(WallFlowDetails, { props: { flow: data } })
    expect(wrapper.get('.flow-state').text()).toBe('Compare daily OI')
    expect(wrapper.findAll('.flow-metrics dd').map(x => x.text())).toEqual(['—', '0 contracts'])
    expect(wrapper.text()).toContain('From zero OI')
    expect(wrapper.text()).toContain('Awaiting a matching comparison')
    expect(wrapper.text()).not.toMatch(/NaN|Infinity/)
    wrapper.unmount()
  })
})
