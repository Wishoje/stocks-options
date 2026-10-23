import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WallNextStepSummary from '@/Components/WallNextStepSummary.vue'

const at = '2026-10-23T15:15:00Z'
const setup = changes => ({ side: 'put', strike: 765, status: 'watch', pattern: 'reversal', direction: 'bullish',
  observed_at: at, entry_zone: { low: 765.3825, high: 766.1475 },
  invalidation: { close_side: 'below', price: 764.6175 }, target: { strike: 770 },
  next_condition: { version: 'wall-next-condition.v1', setup_status: 'watch', code: 'reversal_close',
    text: 'Wait for a second consecutive five-minute close above 765.3825.', completed_closes: 1, required_closes: 2 }, ...changes })
const reaction = { id: 'bounce', side: 'put', strike: 765, status: 'bounce', observed_at: at }
const interaction = changes => ({ symbol: 'SPY', session: '2026-10-23', state: 'ready', as_of: at,
  current: { put: reaction }, events: [reaction], setups: { current: { put: setup() } }, ...changes })
const render = (data = interaction()) => mount(WallNextStepSummary, { props: { interaction: data,
  latestReading: { walls: { put: [{ strike: 765 }], call: [{ strike: 785 }] } } } })

describe('Next wall condition summary', () => {
  it('shows the backend next step and recorded progress without claiming an entry', () => {
    const w = render()
    expect(w.text()).toContain('What to watch now')
    expect(w.text()).toContain('1 of 2 confirming closes recorded')
    expect(w.text()).toContain('second consecutive five-minute close above 765.3825')
    expect(w.text()).toContain('Assessed 11:15 AM ET')
    expect(w.text()).toContain('Prices delayed 15 min')
    expect(w.text()).not.toContain('Entry area')
    w.unmount()
  })

  it('puts direction, entry, invalidation and target together only for a qualifying assessment', () => {
    const w = render(interaction({ setups: { current: { put: setup({ status: 'conditions_met' }) } } }))
    expect(w.text()).toContain('Bullish · Conditions met 11:15 AM ET')
    expect(w.text()).toContain('765.3825–766.1475')
    expect(w.text()).toContain('below 764.6175')
    expect(w.text()).toContain('Reference target770')
    expect(w.text()).toContain('Check current price')
    expect(w.find('.progress').exists()).toBe(false)
    w.unmount()
  })

  it.each(['session_review', 'awaiting_update'])('suppresses active levels and progress during %s', state => {
    const w = render(interaction({ state, setups: { current: { put: setup({ status: 'conditions_met' }) } } }))
    expect(w.text()).not.toContain('Entry area')
    expect(w.find('.progress').exists()).toBe(false)
    expect(w.text()).toContain(state === 'session_review' ? 'There is no active entry' : 'The last setup is not active')
    w.unmount()
  })

  it('does not reuse another strike or mismatched next-condition status', () => {
    const w = render(interaction({ setups: { current: { put: setup({ strike: 760 }) } } }))
    expect(w.text()).not.toContain('765.3825')
    expect(w.find('.progress').exists()).toBe(false)
    w.unmount()
    const missed = render(interaction({ setups: { current: { put: setup({ status: 'entry_missed' }) } } }))
    expect(missed.text()).toContain('Price moved beyond the entry area')
    expect(missed.text()).not.toContain('second consecutive')
    expect(missed.find('.progress').exists()).toBe(false)
    missed.unmount()
  })

  it('clearly dates event inspection and returns to latest without using later progress', async () => {
    const w = render(interaction({ state: 'session_review', setups: { current: { put: setup({ status: 'expired' }) }, readings: [setup()] } }))
    await w.setProps({ selectedEventId: 'bounce' })
    expect(w.text()).toContain('At this recorded assessment')
    expect(w.text()).toContain('Recorded 11:15 AM ET')
    expect(w.text()).toContain('1 of 2 confirming closes recorded')
    expect(w.findAll('.next-step-card')).toHaveLength(1)
    await w.findAll('button')[1].trigger('click')
    expect(w.emitted('evidence')).toEqual([[]])
    await w.get('button').trigger('click')
    expect(w.emitted('inspect')).toEqual([[null]])
    await w.setProps({ selectedEventId: null, interaction: interaction({ symbol: 'QQQ', setups: {} }) })
    expect(w.text()).toContain('QQQ')
    expect(w.text()).not.toContain('1 of 2')
    w.unmount()
  })
})
