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
const latestPrice = overrides => ({ date: '2026-10-28', close: 100, distance_to_entry_pct: 6.2, entry_position: 'below', ...overrides })
const mountPlan = overrides => mount(SwingIdeas, { props: { items: [{ ...plan(), ...overrides }], prepared: true, horizon: 'short', horizons, completedSession: '2026-10-28' } })
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
  it.each(['watching', 'conditions_met', 'in_progress', 'needs_update'])('shows the latest completed close separately from original evidence for %s plans', status_group => {
    const w = mountPlan({ status_group, latest_price: latestPrice() })
    expect(w.get('.swing-latest-price').text()).toContain('Latest completed EOD close: 100 on 2026-10-28.')
    expect(w.get('.swing-latest-price').text()).toContain('Entry area is 6.2% above this close.')
    expect(w.get('.swing-evidence dt').text()).toBe('Original EOD close · 2026-10-23')
    expect(w.get('.swing-evidence dd').text()).toBe('106.9')
    expect(w.get('.swing-levels dd').text()).toBe('106.2–106.9')
    expect(w.get('.swing-latest-price').text().includes('Wait for the wall test and daily close confirmation.')).toBe(status_group === 'watching')
    expect(w.text()).not.toContain('wall is near price')
    w.unmount()
  })
  it.each([
    ['bullish', 'below', 100, 6.2, 'above'],
    ['bearish', 'below', 100, 6.2, 'above'],
    ['bullish', 'above', 110, 2.82, 'below'],
    ['bearish', 'above', 110, 2.82, 'below'],
  ])('describes entry distance from the close for %s plans with price %s entry', (direction, entry_position, close, distance_to_entry_pct, relative) => {
    const w = mountPlan({ direction, latest_price: latestPrice({ close, distance_to_entry_pct, entry_position }) })
    expect(w.get('.swing-latest-price').text()).toContain(`Entry area is ${distance_to_entry_pct}% ${relative} this close.`)
    expect(w.get('.wall-status').text()).toContain('Watching')
    expect(w.find('.swing-qualified').exists()).toBe(false)
    w.unmount()
  })
  it('shows a zero distance inside entry without treating proximity as confirmation', () => {
    const w = mountPlan({ latest_price: latestPrice({ close: 106.5, distance_to_entry_pct: 0, entry_position: 'inside' }) })
    expect(w.get('.swing-latest-price').text()).toContain('This close is inside the entry area.')
    expect(w.get('.wall-status').text()).toContain('Watching')
    expect(w.find('.swing-qualified').exists()).toBe(false)
    w.unmount()
  })
  it.each([
    ['missing data', null],
    ['zero close', latestPrice({ close: 0 })],
    ['nonfinite close', latestPrice({ close: Infinity })],
    ['missing distance', latestPrice({ distance_to_entry_pct: null })],
    ['nonfinite distance', latestPrice({ distance_to_entry_pct: NaN })],
    ['negative distance', latestPrice({ distance_to_entry_pct: -1 })],
    ['missing date', latestPrice({ date: null })],
    ['unknown position', latestPrice({ entry_position: 'unknown' })],
  ])('does not substitute original evidence or invent distance for %s', (label, latest_price) => {
    const w = mountPlan({ latest_price })
    expect(w.get('.swing-latest-price').text()).toBe('Latest completed EOD close unavailable.')
    expect(w.get('.swing-evidence dd').text()).toBe('106.9')
    expect(w.get('.wall-status').text()).toContain('Watching')
    w.unmount()
  })
  it('keeps finished plans historical and preserves direction and status filters', async () => {
    const w = mount(SwingIdeas, { props: { items: [
      { ...plan(), id: 'finished', symbol: 'QQQ', status_group: 'finished', direction: 'bearish', latest_price: latestPrice() },
      { ...plan(), latest_price: latestPrice() },
    ], prepared: true, horizon: 'short', horizons, completedSession: '2026-10-28' } })
    expect(w.findAll('.swing-latest-price')).toHaveLength(1)
    await w.get('.wall-status-filters select').setValue('bearish')
    expect(w.findAll('.swing-card')).toHaveLength(1)
    expect(w.get('.swing-symbol').text()).toBe('QQQ')
    expect(w.find('.swing-latest-price').exists()).toBe(false)
    await w.get('.wall-status-filters select').setValue('all')
    await w.findAll('.wall-status-filters button').find(button => button.text().startsWith('Watching')).trigger('click')
    expect(w.findAll('.swing-card')).toHaveLength(1)
    expect(w.get('.swing-symbol').text()).toBe('SPY')
    w.unmount()
  })
})
