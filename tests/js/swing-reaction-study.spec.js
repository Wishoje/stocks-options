import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import SetupStudy from '@/Pages/SetupStudy.vue'
import { studyCsv } from '@/Support/setupStudy'

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const wrappers = []
const reaction = {
  id: 'reaction-1', symbol: 'SPY', direction: 'bullish', horizon: 'short', pattern: 'hold_reclaim',
  rule_version: 'swing-wall-reaction.v2', metric_version: 'price-touch-study.v2', metric_basis: 'price_touch',
  tier: { key: 'gold', label: 'Gold', version: 'swing-idea-tier.v1', assigned_at: '2026-11-06T14:00:00Z' },
  signal_at: '2026-11-09T15:00:00Z', available_at: '2026-11-06T14:00:00Z',
  status: 'completed', outcome: 'target_reached', qualified: true, followup_complete: true, needs_update: false,
  signal_price: 101, entry_low: 100.5, entry_high: 101, invalidation: 99, target: 105,
  metrics: { mfe_pct: 3.96, mae_pct: 0.4, mfe_r: 2, mae_r: 0.2, strict_outcome: 'target_reached',
    observations: 20, last_at: '2026-11-10T15:00:00Z', entry_revisited: true },
}
const report = () => ({
  filters: { cohort: 'swing_short', from: '2026-11-01', to: '2026-11-10', symbol: '', direction: '' },
  rows: [reaction], summary: { total: 3, qualified: 1, cancelled_before_entry: 1, no_entry: 1 },
  methodology: {}, breakdowns: {
    rule_versions: [{ label: 'swing-wall-reaction.v2', total: 1, completed: 1 }],
    measurement_methods: [{ label: 'price-touch-study.v2', total: 1, completed: 1 }],
    tiers: [{ label: 'Gold · swing-idea-tier.v1', total: 1, completed: 1 }],
  },
})

describe('Touch-based swing study', () => {
  beforeEach(() => { axios.get.mockReset(); axios.get.mockResolvedValue({ data: report() }); axios.post.mockReset() })
  afterEach(() => wrappers.splice(0).forEach(wrapper => wrapper.unmount()))

  it('identifies the new strategy and measurement method in CSV without calling range moves closes', () => {
    const csv = studyCsv([reaction])
    const [header, data] = csv.split('\r\n').map(line => line.match(/"(?:[^"]|"")*"/g))
    const cell = name => data[header.indexOf(`"${name}"`)]
    expect(cell('Strategy')).toBe('"Wall reclaim"')
    expect(cell('Rule version')).toBe('"swing-wall-reaction.v2"')
    expect(cell('Measurement method')).toBe('"Observed price touches"')
    expect(cell('Measurement version')).toBe('"price-touch-study.v2"')
    expect(cell('Idea tier')).toBe('"Gold"')
    expect(cell('Tier version')).toBe('"swing-idea-tier.v1"')
    expect(cell('Tier assigned time (ET)')).toContain('Nov 6, 2026')
    expect(cell('Advanced: Best close R')).toBe('""')
    expect(cell('Advanced: Best range move R')).toBe('"2"')
  })

  it('shows entry-touch measurement, no-entry cancellations and version breakdowns', async () => {
    const wrapper = mount(SetupStudy); wrappers.push(wrapper); await flushPromises()
    expect(wrapper.text()).toContain('Before entry: 1 cancelled · 1 entry windows ended')
    expect(wrapper.text()).toContain('By strategy version')
    expect(wrapper.text()).toContain('By measurement method')
    expect(wrapper.text()).toContain('By frozen idea tier')
    expect(wrapper.text()).toContain('Wall reactions v2 uses observed ranges after the entry touch')
    await wrapper.get('.study-inspect').trigger('click')
    expect(wrapper.get('.study-detail-content').text()).toContain('Observed price bars20')
    expect(wrapper.get('.study-detail-content').text()).toContain('Best range move in R+2R')
    expect(wrapper.get('.study-detail-content').text()).toContain('Unclear ordering is excluded from verified results')
  })

  it('sends the selected swing strategy version without changing the intraday filter', async () => {
    const wrapper = mount(SetupStudy); wrappers.push(wrapper); await flushPromises()
    const swing = wrapper.findAll('.study-cohorts button').find(button => button.text().includes('2–5'))
    await swing.trigger('click'); await flushPromises()
    expect(axios.get.mock.lastCall[1].params.rule_version).toBe('swing-wall-reaction.v2')
    await wrapper.get('[aria-label="Strategy version"]').setValue('swing-wall-reaction.v2')
    await wrapper.get('form').trigger('submit'); await flushPromises()
    expect(axios.get.mock.lastCall[1].params.rule_version).toBe('swing-wall-reaction.v2')
    await wrapper.findAll('.study-cohorts button')[0].trigger('click'); await flushPromises()
    expect(axios.get.mock.lastCall[1].params.rule_version).toBeUndefined()
  })
})
