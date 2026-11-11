import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
const state = vi.hoisted(() => ({ get: vi.fn(), reload: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({ router: state, Link: { props: ['href'], template: '<a :href="href"><slot/></a>' } }))
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot/></div>' } }))
import SwingIdeas from '@/Pages/SwingIdeas.vue'

const horizons = { short: { label: '2–5 trading days', scope_label: '1M' }, position: { label: '1–3 weeks', scope_label: '3M' } }
const plan = overrides => ({ id: 'spy', symbol: 'SPY', pattern: 'hold_reclaim', direction: 'bullish', side: 'put',
  status: 'watching', source_date: '2026-11-10', entry_deadline: '2026-11-13T21:00:00Z',
  levels: { wall: 760, touch_low: 759, touch_high: 761, trigger: 761, entry_low: 761, entry_high: 762,
    invalidation: 757, target: 770 }, evidence: { wall_relative_gex: 4.5, target_room_ratio: 1.6 }, ...overrides })
const mounted = []
const render = props => {
  const wrapper = mount(SwingIdeas, { props: { items: [plan()], prepared: true, horizon: 'short', horizons, ...props } })
  mounted.push(wrapper)
  return wrapper
}
const statusButton = (w, text) => w.findAll('.swing-status-filters button').find(button => button.text().startsWith(text))
afterEach(() => { mounted.splice(0).forEach(wrapper => wrapper.unmount()); vi.useRealTimers() })

describe('Swing wall-reaction strategies and active lifecycle', () => {
  it('names reclaim and break/retest separately and does not force the moving-average direction', () => {
    const w = render({ items: [plan(), plan({ id: 'break', pattern: 'break_retest', direction: 'bearish' })] })
    expect(w.findAll('.swing-strategy').map(node => node.text())).toEqual(['Wall reclaim', 'Wall break & retest'])
    expect(w.findAll('.swing-card')).toHaveLength(2)
    expect(w.text()).not.toContain('price and its 20-day average')
    expect(w.findAll('.swing-trigger')[1].text()).toContain('stay beyond it, then retest')
  })

  it.each(['cancelled', 'completed', 'expired', 'ambiguous', 'finished', 'needs_update'])('excludes %s records even when they once qualified', terminal => {
    const w = render({ items: [plan({ status: terminal, qualified_at: '2026-11-11T15:00:00Z', outcome: 'target_reached' })] })
    expect(w.find('.swing-card').exists()).toBe(false)
    expect(w.text()).toContain('No active swing ideas right now.')
    expect(w.text()).not.toContain('target_reached')
  })

  it('keeps ready and entry-touched ideas separate from watching', async () => {
    const w = render({ items: [plan(), plan({ id: 'qqq', symbol: 'QQQ', status: 'ready', qualified_at: '2026-11-11T15:00:00Z' }),
      plan({ id: 'tsla', symbol: 'TSLA', status: 'entry_touched', entry_at: '2026-11-11T15:10:00Z' })] })
    expect(w.findAll('.swing-status').map(node => node.text())).toEqual(['Watching', 'Ready', 'Entry touched'])
    await statusButton(w, 'Ready').trigger('click')
    expect(w.findAll('.swing-card')).toHaveLength(1)
    expect(w.get('.swing-symbol').text()).toBe('QQQ')
    expect(w.get('.swing-event').text()).toContain('Reaction confirmed')
    expect(w.get('.swing-trigger').text()).toContain('later price touch')
  })

  it('combines symbol direction and strategy filters and retains an empty selection', async () => {
    const w = render({ items: [plan(), plan({ id: 'break', symbol: 'QQQ', status: 'ready', pattern: 'break_retest', direction: 'bearish' })] })
    await w.get('input[type=search]').setValue('qq')
    await w.findAll('.swing-search select')[0].setValue('bearish')
    await w.findAll('.swing-search select')[1].setValue('break_retest')
    expect(w.findAll('.swing-card')).toHaveLength(1)
    expect(w.get('.swing-symbol').text()).toBe('QQQ')
    await statusButton(w, 'Watching').trigger('click')
    expect(w.text()).toContain('No ideas match these filters')
    expect(statusButton(w, 'Watching').attributes('aria-pressed')).toBe('true')
    expect(w.get('input[type=search]').element.value).toBe('qq')
  })

  it('keeps supplied confirmation progress without mapping it to intraday statuses', () => {
    const w = render({ items: [plan({ next_condition: { text: 'Acceptance recorded. Watch for a return to 760.' }, why: 'The wall is ranked first and has four contributing expirations.' })] })
    expect(w.get('.swing-trigger').text()).toContain('Acceptance recorded. Watch for a return to 760.')
    expect(w.get('.swing-why').text()).toContain('four contributing expirations')
    expect(w.get('.swing-status').text()).toBe('Watching')
  })

  it('refreshes only swing data and keeps the selected filter and scroll position', async () => {
    vi.useFakeTimers()
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible')
    state.reload.mockImplementation(options => options.onFinish())
    const w = render()
    await statusButton(w, 'Watching').trigger('click')
    await vi.advanceTimersByTimeAsync(60_000)
    expect(state.reload).toHaveBeenCalledTimes(1)
    expect(state.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['items', 'evaluatedAt', 'summary'], preserveScroll: true, preserveState: true }))
    expect(statusButton(w, 'Watching').attributes('aria-pressed')).toBe('true')
    expect(w.findAll('.swing-card')).toHaveLength(1)
  })

  it('does not overlap requests and cancels a pending refresh on unmount', async () => {
    vi.useFakeTimers()
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible')
    const cancel = vi.fn()
    state.reload.mockImplementation(options => options.onCancelToken({ cancel }))
    const w = render()
    await vi.advanceTimersByTimeAsync(180_000)
    expect(state.reload).toHaveBeenCalledTimes(1)
    w.unmount()
    mounted.splice(mounted.indexOf(w), 1)
    expect(cancel).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(60_000)
    expect(state.reload).toHaveBeenCalledTimes(1)
  })

  it('applies refreshed entry and terminal states without resetting an empty filter', async () => {
    vi.useFakeTimers()
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible')
    let pending
    state.reload.mockImplementation(options => { pending = options })
    const w = render({ items: [plan({ status: 'ready' })] })
    await statusButton(w, 'Entry touched').trigger('click')
    expect(w.text()).toContain('No ideas match these filters')

    await vi.advanceTimersByTimeAsync(60_000)
    await w.setProps({ items: [plan({ status: 'entry_touched', entry_at: '2026-11-11T15:10:00Z' })] })
    pending.onFinish()
    expect(w.get('.swing-status').text()).toBe('Entry touched')
    expect(statusButton(w, 'Entry touched').attributes('aria-pressed')).toBe('true')

    await vi.advanceTimersByTimeAsync(60_000)
    await w.setProps({ items: [plan({ status: 'completed', outcome: 'target_reached' })] })
    pending.onFinish()
    expect(w.find('.swing-card').exists()).toBe(false)
    expect(statusButton(w, 'Entry touched').attributes('aria-pressed')).toBe('true')
    expect(statusButton(w, 'Entry touched').text()).toBe('Entry touched · 0')
    expect(w.text()).toContain('No active swing ideas right now.')
    expect(w.text()).not.toContain('target_reached')
    expect(state.reload).toHaveBeenCalledTimes(2)
  })

  it.each([true, false])('does not poll examples or hidden tabs (demonstration %s)', demonstration => {
    vi.useFakeTimers()
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue(demonstration ? 'visible' : 'hidden')
    render({ demonstration })
    vi.advanceTimersByTime(120_000)
    expect(state.reload).not.toHaveBeenCalled()
  })
})
