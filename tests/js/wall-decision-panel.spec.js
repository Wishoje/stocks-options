import { mount, flushPromises } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WallDecisionPanel from '@/Components/WallDecisionPanel.vue'

const at = '2026-10-01T19:55:00Z'
const event = { id: 'touch', side: 'put', strike: 765, status: 'touch', observed_at: at,
  close: 765.1, tolerance: .3825, reason: 'Price reached the wall.',
  episode_started_at: '2026-10-01T19:50:00Z', wall_observed_at: '2026-10-01T19:50:00Z', evidence_bar_ids: ['bar'] }
const setup = (changes = {}) => ({ side: 'put', strike: 765, pattern: 'reversal', direction: 'bullish',
  status: 'conditions_met', observed_at: at, confirmed_at: at,
  entry_zone: { low: 765.383, high: 766.148 }, invalidation: { price: 764.617, close_side: 'below' },
  target: { strike: 770 }, ...changes })
const reading = { observed_at: at, spot: 765.1,
  walls: { put: [{ strike: 765 }], call: [{ strike: 785 }] } }
const interaction = (changes = {}) => ({ symbol: 'SPY', session: '2026-10-01', timeframe: '7d', state: 'ready',
  as_of: at, events: [], readings: [], bars: [],
  current: { put: { side: 'put', strike: 765, status: 'unknown' }, call: { side: 'call', strike: 785, status: 'unknown' } },
  ...changes })
const render = (data, extra = {}) => mount(WallDecisionPanel, { props: { interaction: data, latestReading: reading, ...extra } })

describe('Simple wall decisions', () => {
  it('replaces expired scenarios and zero result tiles with a session summary and clearly dated reactions', () => {
    const data = interaction({ state: 'session_review', events: [event],
      setups: { current: { put: setup({ status: 'expired' }) }, readings: [] },
      setup_reviews: { summary: { scenarios: 6, qualified: 0, qualified_outcomes: {} }, cases: [] } })
    const w = render(data)
    expect(w.text()).toContain('Session ended · No setup confirmed')
    expect(w.text()).toContain('no active entry')
    expect(w.findAll('.decision-card')).toHaveLength(2)
    expect(w.get('.decision-card.put').text()).toContain('Earlier price response')
    expect(w.text()).toContain('3:55 PM ET')
    expect(w.text()).toContain('Price touched the level')
    expect(w.text()).not.toMatch(/Expired|Invalidated|Entry area|What happened after the setups/)
    expect(w.find('.next-condition').exists()).toBe(false)
    expect(w.find('[aria-label="Recorded setup results"]').exists()).toBe(false)
    w.unmount()
  })

  it('gives concrete price distances without inventing a response or entry', () => {
    const w = render(interaction({ state: 'waiting_for_bars' }), { latestReading: { ...reading, spot: 770.74 } })
    expect(w.text()).toContain('Delayed price 770.74')
    expect(w.get('.decision-card.put').text()).toContain('Price is 5.74 above this wall')
    expect(w.get('.decision-card.call').text()).toContain('Price is 14.26 below this wall')
    expect(w.text()).toContain('Watch only if price returns to 765')
    expect(w.text()).toContain('No price reaction recorded yet')
    expect(w.find('.ready').exists()).toBe(false)
    w.unmount()
  })

  it('shows entry, invalidation and reference level only for a complete qualifying assessment', () => {
    const w = render(interaction({ current: { put: event }, setups: { current: { put: setup() } } }))
    expect(w.text()).toContain('Setup conditions met at the recorded close')
    expect(w.get('.next-condition.ready').text()).toContain('765.383–766.148')
    expect(w.text()).toContain('below 764.617')
    expect(w.text()).toContain('Reference target770')
    expect(w.text()).toContain('check current price before acting')
    w.unmount()
  })

  it.each([
    ['expired', { status: 'expired' }], ['entry missed', { status: 'entry_missed' }],
    ['another strike', { strike: 760 }], ['no target', { target: null }],
  ])('does not display an active entry for %s', (_, changes) => {
    const w = render(interaction({ setups: { current: { put: setup(changes) } } }))
    expect(w.find('.ready').exists()).toBe(false)
    expect(w.text()).not.toContain('Entry area')
    w.unmount()
  })

  it('pauses a previously qualifying entry while the next assessment is pending', () => {
    const w = render(interaction({ state: 'awaiting_update', setups: { current: { put: setup() } } }))
    expect(w.text()).toContain('Waiting for the next price assessment')
    expect(w.text()).toContain('The last setup is not active')
    expect(w.find('.ready').exists()).toBe(false)
    w.unmount()
  })

  it('uses the pending sequence’s real threshold rather than calling a touch an entry', () => {
    const w = render(interaction({ current: { put: event },
      setups: { current: { put: setup({ status: 'watch', confirmed_at: null }) } } }))
    expect(w.text()).toContain('two consecutive five-minute closes above 765.383 after the touch')
    expect(w.text()).toContain('No confirmed setup yet')
    expect(w.text()).not.toContain('Entry area')
    w.unmount()
  })

  it('opens the selected event’s evidence and resets details when the symbol changes', async () => {
    const data = interaction({ events: [event], current: { put: event },
      bars: [{ id: 'bar', t: Date.parse(event.episode_started_at), o: 766, h: 766.1, l: 765, c: 765.1 }],
      setups: { current: { put: setup({ status: 'expired' }) }, readings: [setup()] } })
    const w = render(data)
    expect(w.find('.evidence').exists()).toBe(false)
    await w.setProps({ selectedEventId: 'touch' }); await flushPromises()
    expect(w.text()).toContain('Reviewing a recorded price reaction')
    expect(w.text()).toContain('Recorded close 765.1')
    expect(w.findAll('.decision-card')).toHaveLength(1)
    expect(w.get('.evidence').text()).toContain('Price reached the wall')
    expect(w.get('.next-condition.ready').text()).toContain('Conditions met at this time')
    await w.get('.review-context button').trigger('click')
    expect(w.emitted('inspect')).toEqual([[null]])
    await w.setProps({ selectedEventId: null })
    expect(w.find('.evidence').exists()).toBe(false)
    await w.setProps({ interaction: interaction({ symbol: 'QQQ' }) })
    expect(w.get('.advanced-review').element.open).toBe(false)
    expect(w.find('[aria-label="Price behavior at the walls"]').exists()).toBe(false)
    w.unmount()
  })

  it('does not reuse a past assessment from another leading strike or claim an uncomputed session result', () => {
    const w = render(interaction({ state: 'session_review', current: { put: event } }), {
      latestReading: { ...reading, walls: { put: [{ strike: 760 }], call: [{ strike: 785 }] } },
    })
    expect(w.get('.decision-card.put').text()).not.toContain('Price touched the level')
    expect(w.text()).toContain('No active setup')
    expect(w.text()).not.toContain('No setup confirmed')
    w.unmount()
  })

  it('keeps voluntarily opened details open during a background update in the same session', async () => {
    const data = interaction()
    const w = render(data)
    const details = w.get('.advanced-review')
    details.element.open = true
    await details.trigger('toggle')
    await w.setProps({ interaction: { ...data, as_of: '2026-10-01T20:00:00Z' } })
    expect(details.element.open).toBe(true)
    expect(w.find('[aria-label="Price behavior at the walls"]').exists()).toBe(true)
    w.unmount()
  })
})
