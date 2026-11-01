import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import SwingResearch from '@/Pages/SwingResearch.vue'
import { defaultSwingFilters, swingFilterError, swingSignedPercent, swingVariants } from '@/Support/swingResearch'

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { props: ['researchDemo'], template: '<div :data-demo="researchDemo"><slot /></div>' } }))
vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }))
const wrappers = []
const report = (overrides = {}) => ({ prepared: true, enabled: true, demonstration: false, warnings: [], collection: {}, variants: Object.fromEntries(swingVariants.map(key => [key, { label: { baseline: 'Production baseline', nearer_entry: 'Closer entry', earlier_exit: 'Earlier exit' }[key], description: `${key} rules` }])), summary: swingVariants.map(variant => ({ variant, total: 2, qualified: 1, watching: 1, in_progress: 0, completed: 1, needs_review: 0, target_reached: 1, invalidated: 0, ended: 0, target_rate: { hits: 1, eligible: 1, rate: 100 }, avg_mfe_pct: 1, avg_mae_pct: 0.2 })), groups: [{ id: 'group-1', symbol: 'SPY', horizon: 'short', source_date: '2026-10-20', created_at: '2026-10-21T12:00:00Z', cases: swingVariants.map((variant, index) => ({ id: index + 1, variant, status: index === 2 ? 'needs_review' : 'target_reached', original_target: 110, state: { direction: 'bullish', qualified_at: '2026-10-21', qualified_price: 101, levels: { entry_low: 100, entry_high: 102, target: index === 2 ? 106 : 110, invalidation: 98, invalidation_side: 'below' } }, latest_price: { date: '2026-10-23', close: 108, distance_to_entry_pct: 5.88 }, metrics: { mfe_pct: 8, mae_pct: 1 } })) }], ...overrides })
function start(props = {}) { const wrapper = mount(SwingResearch, { props }); wrappers.push(wrapper); return wrapper }
beforeEach(() => { window.history.replaceState({}, '', '/swing-research'); axios.get.mockReset(); axios.post.mockReset(); axios.get.mockResolvedValue({ data: report() }); axios.post.mockResolvedValue({ data: { prepared: true, warnings: [] } }) })
afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); window.history.replaceState({}, '', '/') })

describe('Swing research view', () => {
  it('shows all three paired profiles with fixed levels, source dates, entry distance and explicit completed denominators', async () => {
    const wrapper = start(); await flushPromises()
    expect(wrapper.findAll('.profile')).toHaveLength(3); expect(wrapper.findAll('.paper-case')).toHaveLength(3)
    expect(wrapper.text()).toContain('1 of 1 completed cases'); expect(wrapper.text()).toContain('Source date 2026-10-20')
    expect(wrapper.text()).toContain('5.88%'); expect(wrapper.text()).toContain('Original full target110')
    expect(wrapper.text()).toContain('Needs review'); expect(wrapper.text()).toContain('100–102')
    expect(wrapper.text()).toContain('do not establish which profile performs better')
    expect(axios.post).not.toHaveBeenCalled()
  })
  it('applies the horizon, uppercase symbol and dates and prevents an invalid range', async () => {
    const wrapper = start(); await flushPromises()
    await wrapper.get('select').setValue('position'); await wrapper.get('input[placeholder="All symbols"]').setValue(' qqq ')
    await wrapper.get('form').trigger('submit'); await flushPromises()
    expect(axios.get.mock.calls.at(-1)[1].params).toMatchObject({ horizon: 'position', symbol: 'QQQ' })
    const dateInputs = wrapper.findAll('input[type="date"]'); await dateInputs[0].setValue('2026-10-31'); await dateInputs[1].setValue('2026-10-01')
    const calls = axios.get.mock.calls.length; await wrapper.get('form').trigger('submit'); expect(axios.get).toHaveBeenCalledTimes(calls)
    expect(wrapper.text()).toContain('From date must be on or before To date.')
  })
  it('compares original entry distance and signed outcome moves without implying profit', async () => {
    const payload = report()
    payload.summary[0].avg_initial_entry_distance_pct = 2.5; payload.summary[0].avg_outcome_move_pct = -1.25
    payload.summary[1].avg_initial_entry_distance_pct = 0.4; payload.summary[1].avg_outcome_move_pct = 0.75
    payload.summary[2].avg_initial_entry_distance_pct = null; payload.summary[2].avg_outcome_move_pct = null
    payload.groups[0].cases[0].status = 'finished'; payload.groups[0].cases[0].state.outcome = 'target_reached'
    payload.groups[0].cases[1].metrics.needs_update = true
    axios.get.mockResolvedValueOnce({ data: payload })
    const wrapper = start(); await flushPromises()
    const profiles = wrapper.findAll('.profile')
    expect(profiles[0].text()).toContain('Entry distance when recorded2.5%'); expect(profiles[0].text()).toContain('Move at recorded outcome-1.25%')
    expect(profiles[1].text()).toContain('Move at recorded outcome+0.75%'); expect(profiles[2].text()).toContain('Move at recorded outcome—')
    expect(wrapper.text()).toContain('They do not measure trading profit.')
    expect(wrapper.findAll('.case-state')[0].text()).toBe('Target reached'); expect(wrapper.findAll('.case-state')[1].text()).toBe('Needs review')
    expect(swingSignedPercent(-0.00001)).toBe('0%'); expect(swingSignedPercent(null)).toBe('—')
  })
  it('handles empty data, server errors and missing completed denominators without a false zero rate', async () => {
    axios.get.mockResolvedValueOnce({ data: report({ groups: [], summary: swingVariants.map(variant => ({ variant, total: 0, target_rate: { hits: 0, eligible: 0, rate: 0 } })) }) })
    const wrapper = start(); await flushPromises()
    expect(wrapper.text()).toContain('No recorded cases in this view'); expect(wrapper.text()).toContain('No eligible completed cases')
    expect(wrapper.get('.target-rate strong').text()).toBe('—')
    axios.get.mockRejectedValueOnce({ response: { status: 403 } }); await wrapper.get('.actions button').trigger('click'); await flushPromises()
    expect(wrapper.text()).toContain('available to the owner account only')
  })
  it('shows all reviewed groups when no-plan groups are omitted from the case cards', async () => {
    axios.get.mockResolvedValueOnce({ data: report({ reviewed_groups: 1006, without_plan_groups: 1005 }) })
    const wrapper = start(); await flushPromises()
    expect(wrapper.text()).toContain('1,006 paired reviews · 1,005 without a qualifying plan.')
    expect(wrapper.findAll('.case-group')).toHaveLength(1)
  })
  it('opens URL-selected synthetic examples with alerts suppressed and never posts collection', async () => {
    window.history.replaceState({}, '', '/swing-research?demo=1&symbol=SPY&horizon=position')
    axios.get.mockResolvedValue({ data: report({ demonstration: true }) })
    const wrapper = start({ localDemoAvailable: true }); await flushPromises()
    expect(wrapper.attributes('data-demo')).toBe('true'); expect(wrapper.text()).toContain('Synthetic examples')
    expect(axios.get.mock.calls[0][1].params).toMatchObject({ demo: 1, symbol: 'SPY', horizon: 'position' })
    const refresh = wrapper.findAll('.actions button')[1]; expect(refresh.attributes('disabled')).toBeDefined()
    await refresh.trigger('click'); expect(axios.post).not.toHaveBeenCalled()
  })
  it('rejects synthetic payloads unless synthetic mode was explicitly selected', async () => {
    axios.get.mockResolvedValue({ data: report({ demonstration: true }) })
    const wrapper = start(); await flushPromises(); expect(wrapper.text()).toContain('could not load'); expect(wrapper.find('.profile').exists()).toBe(false)
  })
})

it('uses 45 inclusive Eastern calendar dates and validates the 180 day limit', () => {
  expect(defaultSwingFilters(new Date('2026-10-31T01:00:00Z'))).toMatchObject({ from: '2026-09-16', to: '2026-10-30', horizon: 'short' })
  expect(swingFilterError({ from: '2026-01-01', to: '2026-07-01', symbol: '' })).toContain('180 days')
})
