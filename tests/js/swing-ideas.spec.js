import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
const state = vi.hoisted(() => ({ get: vi.fn(), reload: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({ router: state, Link: { props: ['href'], template: '<a :href="href"><slot/></a>' } }))
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot/></div>' } }))
import SwingIdeas from '@/Pages/SwingIdeas.vue'

const horizons = { short: { label: '2–5 trading days', scope_label: '1M' }, position: { label: '1–3 weeks', scope_label: '3M' } }
const plan = overrides => ({ id: 'reclaim-spy', symbol: 'SPY', horizon: 'short', source_date: '2026-11-10',
  status: 'watching', direction: 'bullish', side: 'put', pattern: 'hold_reclaim', scope_label: '1M',
  entry_deadline: '2026-11-13T21:00:00Z', available_at: '2026-11-10T23:00:00Z',
  levels: { wall: 760, touch_low: 759, touch_high: 761, trigger: 761, entry_low: 761, entry_high: 762,
    invalidation: 757, target: 770, invalidation_side: 'below' },
  evidence: { wall_relative_gex: 4.5, target_room_ratio: 1.6, atr14: 5, net_gex: -4e8 }, ...overrides })
const mounted = []
const render = props => {
  const wrapper = mount(SwingIdeas, { props: { items: [plan()], prepared: true, horizon: 'short', horizons, ...props } })
  mounted.push(wrapper)
  return wrapper
}
afterEach(() => { mounted.splice(0).forEach(wrapper => wrapper.unmount()) })

describe('Swing ideas navigation and price levels', () => {
  it('defaults to the whole universe and preserves the horizon when choosing a watchlist', async () => {
    const w = render({ horizon: 'position', symbolCount: 143 })
    expect(w.get('[aria-label="Swing symbol coverage"] button[aria-pressed=true]').text()).toBe('All symbols')
    expect(w.get('.swing-coverage').text()).toContain('143 symbols')
    await w.get('[aria-label="Swing symbol coverage"] button[aria-pressed=false]').trigger('click')
    expect(state.get).toHaveBeenLastCalledWith('/swing-ideas', { horizon: 'position', scope: 'watchlist' }, { preserveScroll: true })
  })

  it('keeps the personal scope and demonstration selection when changing the holding period', async () => {
    const w = render({ scope: 'watchlist', demonstration: true })
    expect(w.get('.swing-intro a').attributes('href')).toBe('/swing-ideas?horizon=short&scope=watchlist')
    await w.get('[aria-label="Swing holding period"] button[aria-pressed=false]').trigger('click')
    expect(state.get).toHaveBeenLastCalledWith('/swing-ideas', { horizon: 'position', scope: 'watchlist', demo: 1 }, { preserveScroll: true })
  })

  it.each(['universe', 'watchlist'])('shows a useful preparation state for the %s scope', scope => {
    const w = render({ items: [], prepared: false, scope })
    expect(w.get('.swing-empty h2').text()).toBe('Ideas are being prepared.')
    expect(w.get('.swing-empty').text().includes('Choose All symbols')).toBe(scope === 'watchlist')
    expect(w.find('.swing-card').exists()).toBe(false)
  })

  it('shows concrete entry target stop and entry deadline without requiring a closing price', () => {
    const w = render()
    const levels = w.get('.swing-levels').text()
    expect(levels).toContain('761–762')
    expect(levels).toContain('Target770')
    expect(levels).toContain('Stop level · price touch757')
    expect(levels).toContain('Entry deadlineNov 13, 4:00 PM ET')
    expect(w.get('.swing-trigger').text()).not.toMatch(/daily close|20-day|50-day/i)
    expect(w.get('.swing-why').text()).toContain('4.5×')
    expect(w.get('.swing-why').text()).toContain('1.6× room')
    expect(w.text()).toContain('Prices delayed at least 15 minutes')
  })

  it('shows the holding deadline after an observed entry and retains the original levels', () => {
    const item = plan({ status: 'entry_touched', entry_at: '2026-11-11T15:10:00Z', entry_price: 762,
      holding_deadline: '2026-11-18T21:00:00Z' })
    const w = render({ items: [item] })
    expect(w.get('.swing-status').text()).toBe('Entry touched')
    expect(w.get('.swing-levels').text()).toContain('Holding limitNov 18, 4:00 PM ET')
    expect(w.get('.swing-levels').text()).not.toContain('Entry deadline')
    expect(w.get('.swing-event').text()).toContain('Entry touch recorded Nov 11, 10:10 AM ET · 762')
    expect(w.get('.swing-levels dd').text()).toBe('761–762')
  })

  it('uses recorded delayed price without substituting the original daily review price', () => {
    const w = render({ items: [plan({ evidence: { close: 740 }, latest_price: { value: 761.35, at: '2026-11-11T15:30:00Z' } })] })
    expect(w.get('.swing-latest-price').text()).toBe('Delayed price 761.35 · Nov 11, 10:30 AM ET')
    expect(w.get('.swing-status').text()).toBe('Watching')
    expect(w.get('.swing-latest-price').text()).not.toContain('740')
  })

  it('explains the two holding windows and keeps longer-term analysis links on the 3M scope', () => {
    const w = render({ horizon: 'position', items: [plan({ horizon: 'position', scope_label: '3M' })] })
    expect(w.get('.swing-guide').text()).toContain('three trading sessions for entry')
    expect(w.get('.swing-guide').text()).toContain('up to fifteen after a touch')
    expect(w.get('.swing-guide').text()).toContain('not an executed trade')
    expect(w.get('.swing-card footer a').attributes('href')).toContain('timeframe=90d')
  })
})
