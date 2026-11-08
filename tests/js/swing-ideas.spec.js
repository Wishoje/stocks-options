import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
const state = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({ router: { get: state.get }, Link: { props: ['href'], template: '<a :href="href"><slot/></a>' } }))
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot/></div>' } }))
import SwingIdeas from '@/Pages/SwingIdeas.vue'
const horizons = { short: { label: '2–5 trading days', scope_label: '1M' }, position: { label: '1–3 weeks', scope_label: '3M' } }
const plan = () => ({ id: 'original', symbol: 'SPY', horizon: 'short', source_date: '2026-10-23', reviewed_through: '2026-10-23',
  idea_date: '2026-10-26', available_through: '2026-10-30',
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
    expect(w.text()).toContain('Idea from 2026-10-26 · Available through 2026-10-30')
    expect(w.text()).toContain('Idea available through2026-10-30')
    expect(w.text()).toContain('five trading sessions')
    expect(w.text()).toContain('fifteen')
    expect(w.text()).not.toContain('Entry deadline')
    expect(w.text()).not.toContain('0% / 0%')
    await w.get('.swing-horizons button[aria-pressed="false"]').trigger('click')
    expect(state.get).toHaveBeenCalledWith('/swing-ideas', { horizon: 'position' }, { preserveScroll: true })
    w.unmount()
  })
  it('keeps an available older idea readable and never fabricates an entry on an empty page', () => {
    const w = mount(SwingIdeas, { props: { items: [{ ...plan(), is_current: false }], prepared: true, horizon: 'short', horizons, completedSession: '2026-10-26' } })
    expect(w.get('.wall-status').text()).toContain('Watching')
    expect(w.get('.swing-date').text()).toContain('Available through 2026-10-30')
    expect(w.get('.swing-reason').text()).toContain('Watch for a wall test')
    w.unmount()
    const empty = mount(SwingIdeas, { props: { items: [], prepared: true, horizon: 'short', horizons } })
    expect(empty.text()).toContain('No qualifying swing ideas in this EOD review')
    expect(empty.text()).not.toContain('Your swing ideas are being prepared')
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
    expect(w.get('.swing-latest-price').text().includes('Wait for the wall test and daily close confirmation.')).toBe(['watching', 'needs_update'].includes(status_group))
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
  it('presents private follow-up records as dated ideas and preserves simple direction and idea filters', async () => {
    const w = mount(SwingIdeas, { props: { items: [
      { ...plan(), id: 'finished', symbol: 'QQQ', status_group: 'finished', direction: 'bearish', latest_price: latestPrice(),
        qualified_at: '2026-10-27', qualified_price: 106.5, outcome: 'invalidated',
        reason: 'The original trade was invalidated after entry.', deadline: '2026-11-03' },
      { ...plan(), latest_price: latestPrice() },
    ], prepared: true, horizon: 'short', horizons, completedSession: '2026-10-28' } })
    expect(w.findAll('.swing-latest-price')).toHaveLength(2)
    await w.get('.wall-status-filters select').setValue('bearish')
    expect(w.findAll('.swing-card')).toHaveLength(1)
    expect(w.get('.swing-symbol').text()).toBe('QQQ')
    expect(w.find('.swing-latest-price').exists()).toBe(true)
    expect(w.get('.wall-status').text()).toContain('Conditions met')
    expect(w.text()).not.toMatch(/invalidated|In progress|Past setups|Review ends by|Entry deadline/i)
    await w.get('.wall-status-filters select').setValue('all')
    await w.findAll('.wall-status-filters button').find(button => button.text().startsWith('Watching')).trigger('click')
    expect(w.findAll('.swing-card')).toHaveLength(1)
    expect(w.get('.swing-symbol').text()).toBe('SPY')
    w.unmount()
  })

  it.each(['target_reached', 'invalidated', 'holding_ended', 'entry_expired', 'entry_missed'])('does not expose a private %s result or its stored reason', outcome => {
    const w = mountPlan({ status: 'finished', status_group: 'finished', outcome,
      qualified_at: '2026-10-27', qualified_price: 106.5,
      reason: `Private follow-up result: ${outcome}`, event_review: `Private event: ${outcome}`,
      deadline: '2026-11-03', latest_price: latestPrice() })

    expect(w.get('.wall-status').text()).toContain('Conditions met')
    expect(w.get('.swing-qualified').text()).toBe('Conditions met 2026-10-27 at close 106.5')
    expect(w.get('.swing-levels').text()).toContain('106.2–106.9')
    expect(w.get('.swing-levels').text()).toContain('Reference target111')
    expect(w.get('.swing-levels').text()).toContain('Risk boundary · daily closebelow 104.5')
    expect(w.text()).not.toMatch(/Private follow-up|Private event|Target reached|Invalidated|In progress|Past setups|Review ends by|Entry deadline/i)
    expect(w.text()).not.toContain(outcome)
    w.unmount()
  })

  it('does not label an unqualified private terminal record as conditions met', () => {
    const w = mountPlan({ status: 'finished', status_group: 'finished', outcome: 'entry_expired',
      qualified: false, reason: 'The entry window expired.' })
    expect(w.get('.wall-status').text()).toContain('Watching')
    expect(w.find('.swing-qualified').exists()).toBe(false)
    expect(w.get('.swing-reason').text()).toContain('Watch for a wall test')
    expect(w.text()).not.toMatch(/entry window expired|Past setup/i)
    w.unmount()
  })

  it('shows the supplied long-horizon availability without resetting it after a newer EOD review', async () => {
    const item = { ...plan(), horizon: 'position', scope_label: '3M', available_through: '2026-11-13' }
    const w = mount(SwingIdeas, { props: { items: [item], prepared: true, horizon: 'position', horizons, completedSession: '2026-10-28' } })
    expect(w.get('.swing-date').text()).toContain('Idea from 2026-10-26 · Available through 2026-11-13')
    await w.setProps({ items: [{ ...item, reviewed_through: '2026-10-29' }], completedSession: '2026-10-29' })
    expect(w.get('.swing-date').text()).toContain('Idea from 2026-10-26 · Available through 2026-11-13')
    expect(w.get('.swing-date').text()).toContain('EOD review 2026-10-29')
    expect(w.get('.swing-card footer a').attributes('href')).toContain('timeframe=90d')
    w.unmount()
  })
})
