import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import WallSetupHistoryPanel from '@/Components/WallSetupHistoryPanel.vue'

const row = (id = 'first', changes = {}) => ({
  id, side: 'put', strike: 100, pattern: 'reversal', direction: 'bullish',
  evidence_grade: 'strong', observed_at: '2026-09-30T14:00:00Z', qualified: true,
  outcome: 'target_reached', outcome_at: '2026-09-30T14:10:00Z', reason: 'A later close reached the frozen target.',
  entry_zone: { low: 100.05, high: 100.15 }, invalidation: { price: 99.95 }, target: { strike: 101 },
  reference_close: 100.1, review_until: '2026-09-30T15:00:00Z', review_basis: 'saved_assessment',
  first_recorded_at: '2026-09-30T14:20:00Z', follow_up_bars: [{ id: 'bar', observed_at: '2026-09-30T14:10:00Z', close: 101.1 }],
  ...changes,
})
const payload = (cases = [row()], changes = {}) => ({
  symbol: 'SPY', session: '2026-09-30', timeframe: '14d', horizon_minutes: 60,
  summary: { scenarios: cases.length, qualified: 1, qualified_outcomes: { target_reached: 1 }, overlapping_reviews: 0 }, cases,
  ...changes,
})

describe('Recorded setup results', () => {
  it('shows the qualified denominator and explains the descriptive review rules', async () => {
    const w = mount(WallSetupHistoryPanel, { props: { reviews: payload() } })
    expect(w.text()).toContain('What happened after the setups?')
    expect(w.text()).toContain('One count per setup')
    expect(w.text()).toContain('They do not assume an executed trade')
    expect(w.text()).toContain('Saved assessment')
    expect(w.text()).toContain('Counts describe observed price paths')
    await w.find('button[aria-label^="Inspect"]').trigger('click')
    const detail = w.get('[aria-label="Selected setup review"]')
    expect(detail.text()).toContain('100.05–100.15')
    expect(detail.text()).toContain('101.1')
    expect(detail.text()).toContain('Assessment saved at 10:20 AM ET')
    expect(detail.text()).toContain('Completed closes after the eligible setup')
    await detail.find('button').trigger('click')
    expect(w.find('[aria-label="Selected setup review"]').exists()).toBe(false)
  })

  it('filters outcomes and sides without a request and resets inspection on scope changes', async () => {
    const w = mount(WallSetupHistoryPanel, { props: { reviews: payload([row(), row('second', { side: 'call', outcome: 'invalidated' })]) } })
    await w.get('[aria-label="Filter setup outcomes"]').setValue('invalidated')
    expect(w.findAll('tbody tr')).toHaveLength(1)
    expect(w.text()).toContain('Call wall 100')
    await w.get('[aria-label="Filter setup wall"]').setValue('put')
    expect(w.text()).toContain('No scenarios match these filters')
    await w.setProps({ reviews: payload([row('new')], { symbol: 'QQQ', timeframe: '7d' }) })
    expect(w.get('[aria-label="Filter setup outcomes"]').element.value).toBe('qualified')
    expect(w.get('[aria-label="Filter setup wall"]').element.value).toBe('all')
    expect(w.findAll('tbody tr')).toHaveLength(1)
    expect(w.find('[aria-label="Selected setup review"]').exists()).toBe(false)
  })

  it('paginates bounded rows and keeps terminal cases which never qualified visible', async () => {
    const cases = Array.from({ length: 10 }, (_, i) => row(`case-${i}`, { qualified: false, outcome: 'entry_missed' }))
    const w = mount(WallSetupHistoryPanel, { props: { reviews: payload(cases, { summary: { scenarios: 10, qualified: 0, qualified_outcomes: {} } }) } })
    await w.get('[aria-label="Filter setup outcomes"]').setValue('all')
    expect(w.text()).toContain('10 did not reach an eligible entry close')
    expect(w.text()).toContain('Before an eligible close')
    expect(w.findAll('tbody tr')).toHaveLength(8)
    await w.findAll('button').find(button => button.text() === 'Next').trigger('click')
    expect(w.findAll('tbody tr')).toHaveLength(2)
    expect(w.text()).toContain('Page 2 of 2')
  })

  it('labels replay, overlap, limits and demonstration instead of claiming independent trading results', async () => {
    const reviews = payload([row('replay', { review_basis: 'session_replay', first_recorded_at: null, overlaps_another_review: true })],
      { demonstration: true, capture_limit_reached: true, summary: { scenarios: 1, qualified: 1, overlapping_reviews: 1, qualified_outcomes: { target_reached: 1 } } })
    const w = mount(WallSetupHistoryPanel, { props: { reviews } })
    expect(w.text()).toContain('Local synthetic example')
    expect(w.text()).toContain('first 160 saved assessments')
    expect(w.text()).toContain('1 reviews overlap another review')
    expect(w.text()).toContain('Session replay')
    await w.find('button[aria-label^="Inspect"]').trigger('click')
    expect(w.get('[aria-label="Selected setup review"]').text()).toContain('Treat their outcomes as related observations')
    expect(w.text()).not.toContain('Assessment saved at')
  })

  it('handles an empty session with no fabricated rates or outcomes', () => {
    const w = mount(WallSetupHistoryPanel, { props: { reviews: payload([], { summary: { scenarios: 0, qualified: 0 } }) } })
    expect(w.text()).toContain('The review starts when today’s price sequence creates a setup')
    expect(w.find('table').exists()).toBe(false)
    expect(w.text()).not.toMatch(/\d+%/)
  })
})
