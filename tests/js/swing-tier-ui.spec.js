import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
const state = vi.hoisted(() => ({ get: vi.fn(), reload: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({ router: state, Link: { props: ['href'], template: '<a :href="href"><slot/></a>' } }))
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot/></div>' } }))
import SwingIdeas from '@/Pages/SwingIdeas.vue'

const horizons = { short: { label: '2–5 trading days', scope_label: '1M' }, position: { label: '1–3 weeks', scope_label: '3M' } }
const tier = (key = 'gold', overrides = {}) => ({ version: 'swing-tier.v1', key, label: key[0].toUpperCase() + key.slice(1),
  rank: { gold: 1, silver: 2, bronze: 3 }[key], headline: 'Room to the target with a defined stop.',
  reasons: ['Target room is 2.4× the stop distance.', 'Most wall exposure lasts through the holding window.'],
  tradeoffs: ['The entry area is 1.2% from the reviewed price.'], metrics: { reward_risk: 2.4, risk_pct: 1.5 }, ...overrides })
const plan = overrides => ({ id: 'spy', symbol: 'SPY', pattern: 'hold_reclaim', direction: 'bullish', side: 'put',
  status: 'watching', source_date: '2026-11-10', entry_deadline: '2026-11-13T21:00:00Z',
  levels: { wall: 760, touch_low: 759, touch_high: 761, trigger: 761, entry_low: 761, entry_high: 762,
    invalidation: 757, target: 770 }, evidence: { wall_relative_gex: 4.5, target_room_ratio: 1.6 }, tier: tier(), ...overrides })
const mounted = []
const render = props => {
  const wrapper = mount(SwingIdeas, { props: { items: [plan()], prepared: true, horizon: 'short', horizons, ...props } })
  mounted.push(wrapper)
  return wrapper
}
const selectTier = w => w.findAll('.swing-search select')[2]
const statusButton = (w, text) => w.findAll('.swing-status-filters button').find(button => button.text().startsWith(text))
afterEach(() => { mounted.splice(0).forEach(wrapper => wrapper.unmount()); vi.useRealTimers() })

describe('Swing idea tiers', () => {
  it.each([['gold', 'watching', 'Watching'], ['silver', 'ready', 'Ready'], ['bronze', 'entry_touched', 'Entry touched']])
  ('shows %s beside the independent %s lifecycle status', (key, status, label) => {
    const w = render({ items: [plan({ tier: tier(key), status })] })
    expect(w.get('.swing-tier').text()).toBe(tier(key).label)
    expect(w.get('.swing-tier').attributes('aria-label')).toBe(`${tier(key).label} tier`)
    expect(w.get('.swing-status').text()).toBe(label)
    expect(w.find('.swing-why').exists()).toBe(false)
    expect(w.get('.swing-tier-summary h3').text()).toBe(`Why ${tier(key).label}`)
    expect(w.get('.swing-tier-summary summary').text()).toBe('Details and trade-offs')
    expect(w.get('.swing-tier-summary').text()).toContain('Target room is 2.4× the stop distance.')
    expect(w.get('.swing-tier-summary').text()).toContain('The entry area is 1.2% from the reviewed price.')
  })

  it('starts with all tiers and shows every tier rather than hiding lower-ranked ideas', () => {
    const w = render({ items: [plan(), plan({ id: 'silver', symbol: 'QQQ', tier: tier('silver') }),
      plan({ id: 'bronze', symbol: 'TSLA', tier: tier('bronze') })] })
    expect(selectTier(w).element.value).toBe('all')
    expect(w.findAll('.swing-card')).toHaveLength(3)
    expect(selectTier(w).findAll('option').map(option => option.text())).toEqual(['All tiers', 'Gold · 1', 'Silver · 1', 'Bronze · 1'])
  })

  it('combines tier with strategy direction symbol and lifecycle filters', async () => {
    const w = render({ items: [plan(), plan({ id: 'qgold', symbol: 'QQQ', direction: 'bearish', pattern: 'break_retest', status: 'ready' }),
      plan({ id: 'qsilver', symbol: 'QQQ', direction: 'bearish', pattern: 'break_retest', status: 'ready', tier: tier('silver') })] })
    await selectTier(w).setValue('gold')
    await w.get('input[type=search]').setValue('qq')
    await w.findAll('.swing-search select')[0].setValue('bearish')
    await w.findAll('.swing-search select')[1].setValue('break_retest')
    await statusButton(w, 'Ready').trigger('click')
    expect(w.findAll('.swing-card')).toHaveLength(1)
    expect(w.get('.swing-symbol').text()).toBe('QQQ')
    expect(w.get('.swing-tier').text()).toBe('Gold')
    expect(selectTier(w).findAll('option').map(option => option.text())).toContain('Silver · 1')
  })

  it('preserves the selected tier and empty lifecycle filter through data refresh', async () => {
    vi.useFakeTimers()
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible')
    let pending
    state.reload.mockImplementation(options => { pending = options })
    const w = render({ items: [plan({ status: 'ready' })] })
    await selectTier(w).setValue('gold')
    await statusButton(w, 'Entry touched').trigger('click')
    expect(w.text()).toContain('No ideas match these filters')
    await vi.advanceTimersByTimeAsync(60_000)
    await w.setProps({ items: [plan({ status: 'entry_touched', entry_at: '2026-11-11T15:10:00Z' })] })
    pending.onFinish()
    expect(w.findAll('.swing-card')).toHaveLength(1)
    expect(selectTier(w).element.value).toBe('gold')
    expect(statusButton(w, 'Entry touched').attributes('aria-pressed')).toBe('true')
    await vi.advanceTimersByTimeAsync(60_000)
    await w.setProps({ items: [plan({ id: 'silver', tier: tier('silver'), status: 'entry_touched' })] })
    pending.onFinish()
    expect(w.find('.swing-card').exists()).toBe(false)
    expect(w.text()).toContain('No ideas match these filters')
    expect(selectTier(w).element.value).toBe('gold')
    expect(selectTier(w).findAll('option').map(option => option.text())).toContain('Gold · 0')
  })

  it('resets tier when scope or holding period changes', async () => {
    const w = render()
    await selectTier(w).setValue('bronze')
    await w.setProps({ scope: 'watchlist' })
    expect(selectTier(w).element.value).toBe('all')
    await selectTier(w).setValue('gold')
    await w.setProps({ horizon: 'position' })
    expect(selectTier(w).element.value).toBe('all')
  })

  it('keeps older examples without a tier readable and never assigns a browser-derived grade', async () => {
    const w = render({ items: [plan({ tier: null })] })
    expect(w.findAll('.swing-card')).toHaveLength(1)
    expect(w.find('.swing-tier').exists()).toBe(false)
    expect(w.get('.swing-why h3').text()).toBe('Why this level')
    expect(w.get('.swing-status').text()).toBe('Watching')
    await selectTier(w).setValue('gold')
    expect(w.find('.swing-card').exists()).toBe(false)
  })

  it('explains fixed review context without equating Gold with price confirmation or a success rate', () => {
    const w = render()
    const guide = w.get('.swing-guide').text()
    expect(guide).toContain('stop distance, wall expiry support and entry proximity at the level review')
    expect(guide).toContain('Gold ideas still need price confirmation.')
    expect(w.get('.swing-status').text()).toBe('Watching')
    expect(w.text()).not.toMatch(/win probability|success rate|confidence score|\d+% chance|guaranteed/i)
  })
})
