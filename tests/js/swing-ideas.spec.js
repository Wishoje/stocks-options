import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
const state = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({ router: { get: state.get }, Link: { props: ['href'], template: '<a :href="href"><slot/></a>' } }))
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot/></div>' } }))
import SwingIdeas from '@/Pages/SwingIdeas.vue'
const horizons = { short: { label: '2–5 trading days', scope_label: '1M' }, position: { label: '1–3 weeks', scope_label: '3M' } }
const plan = () => ({ id: 'original', symbol: 'SPY', horizon: 'short', source_date: '2026-10-23', reviewed_through: '2026-10-23',
  status_group: 'watching', direction: 'bullish', is_current: true, reason: 'Wait for a wall test and a daily reclaim close.', entry_deadline: '2026-10-28',
  levels: { touch_low: 105.8, touch_high: 106.2, trigger: 106.2, entry_low: 106.2, entry_high: 106.9, invalidation: 104.5, invalidation_side: 'below', target: 111 },
  evidence: { close: 106.9, sma20: 105.95, sma50: 104.45, atr14: 2, net_gex: -4000000, dex_shares: null, volatility: null, target_room_ratio: 1.7 },
  scope_label: '1M', event_review: 'Check upcoming earnings and major events before holding overnight.' })
describe('Swing ideas', () => {
  it('shows separate holding horizons and concrete trigger target invalidation and dates', async () => {
    const w = mount(SwingIdeas, { props: { items: [plan()], prepared: true, horizon: 'short', horizons, completedSession: '2026-10-23' } })
    expect(w.findAll('h1')).toHaveLength(1)
    expect(w.text()).toContain('106.2–106.9')
    expect(w.text()).toContain('below 104.5')
    expect(w.text()).toContain('Reference target111')
    expect(w.text()).toContain('No swing notifications are sent')
    expect(w.text()).not.toContain('0% / 0%')
    await w.get('.swing-horizons button[aria-pressed="false"]').trigger('click')
    expect(state.get).toHaveBeenCalledWith('/swing-ideas', { horizon: 'position' }, { preserveScroll: true })
    w.unmount()
  })
  it('labels an older unfinished plan as needing an update and never fabricates an entry on an empty page', () => {
    const w = mount(SwingIdeas, { props: { items: [{ ...plan(), is_current: false }], prepared: true, horizon: 'short', horizons, completedSession: '2026-10-26' } })
    expect(w.get('.wall-status').text()).toContain('Needs update')
    w.unmount()
    const empty = mount(SwingIdeas, { props: { items: [], prepared: true, horizon: 'short', horizons } })
    expect(empty.text()).toContain('Your swing plans are being prepared')
    expect(empty.find('.swing-levels').exists()).toBe(false)
    empty.unmount()
  })
})
