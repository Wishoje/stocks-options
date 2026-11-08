import { describe, expect, it } from 'vitest'
import { IDEA_STATUS_FILTERS, ideaStatusLabel, presentIdea, wallIdeaRows, wallInboxIdeaRows } from '@/Support/ideaPresentation'
import { wallInboxHistoryRows } from '@/Support/wallLifecycle'

const now = Date.parse('2026-10-26T15:16:00Z')
const setup = () => ({ direction: 'bullish', pattern: 'reclaim', entry_zone: { low: 100.05, high: 100.15 },
  invalidation: { price: 99.95, close_side: 'below' }, target: { strike: 101 },
  outcome: 'target_reached', review_until: '2026-10-26T20:00:00Z' })
const row = (key = 'a', changes = {}) => ({ id: key, alert_id: key.repeat(64), assessment_id: key.repeat(64),
  symbol: 'SPY', side: 'put', strike: 100, close: 100.1, session: '2026-10-26', timeframe: '7d',
  observed_at: '2026-10-26T14:30:00Z', qualified_at: '2026-10-26T14:30:00Z',
  status: 'conditions_met', status_group: 'finished', qualified: true, setup: setup(),
  outcome: 'target_reached', reason: 'The original target was reached.', entry_window_closed: true,
  lifecycle: { qualified: true, status_group: 'finished', outcome: 'target_reached' },
  received_at: now - 60000, read_at: now - 30000, ...changes })
const feed = changes => ({ schema_version: 'wall-watchlist.v1', session: '2026-10-26', timeframe: '7d', state: 'ready',
  items: [], lifecycle_items: [], alert_history: [], ...changes })

describe('customer idea presentation', () => {
  it('keeps qualified ideas and reference levels while removing trade outcomes and progress', () => {
    const original = row(), copy = JSON.stringify(original)
    const idea = presentIdea(original)
    expect(idea).toMatchObject({ status_group: 'conditions_met', status: 'conditions_met', qualified: true,
      alert_id: original.alert_id, assessment_id: original.assessment_id, read_at: original.read_at,
      setup: { entry_zone: { low: 100.05, high: 100.15 }, invalidation: { price: 99.95 }, target: { strike: 101 } } })
    expect(idea).not.toHaveProperty('outcome')
    expect(idea).not.toHaveProperty('lifecycle')
    expect(idea).not.toHaveProperty('entry_window_closed')
    expect(idea.setup).not.toHaveProperty('outcome')
    expect(idea.setup).not.toHaveProperty('review_until')
    expect(JSON.stringify(idea)).not.toMatch(/target_reached|original target was reached|finished/)
    expect(JSON.stringify(original)).toBe(copy)
  })

  it('does not promote an unqualified finished scenario or repeat its tracking reason', () => {
    const idea = presentIdea(row('b', { status: 'expired', qualified: false, qualified_at: null,
      lifecycle: null, reason: 'Entry missed.', next_condition: { text: 'The setup expired.' } }))
    expect(idea.status_group).toBe('watching')
    expect(idea.qualified).toBe(false)
    expect(idea.reason).toBe('Watch for a completed price sequence at this wall.')
    expect(idea).not.toHaveProperty('next_condition')
  })

  it('preserves the recorded qualification price, plan date and demo link without accepting an invalid price', () => {
    const idea = presentIdea(row('a', { qualified_price: 100.1, plan_session: '2026-10-26', event_id: 'demo-event' }))
    expect(idea).toMatchObject({ qualified_price: 100.1, plan_session: '2026-10-26', event_id: 'demo-event' })
    expect(presentIdea(row('a', { qualified_price: Infinity, plan_session: 'not a date' }))).not.toHaveProperty('qualified_price')
    expect(presentIdea(row('a', { qualified_price: 0, plan_session: 'not a date' }))).not.toHaveProperty('plan_session')
  })

  it('retains genuine confirmation progress for a developing idea without copying arbitrary reasons', () => {
    const idea = presentIdea(row('b', { status: 'watch', status_group: 'watching', qualified: false, qualified_at: null,
      lifecycle: null, next_condition: { text: 'Wait for a second completed close above 100.05.', completed_closes: 1, required_closes: 2,
        outcome: 'invalidated' } }))
    expect(idea.reason).toBe('Wait for a second completed close above 100.05.')
    expect(idea.next_condition).toEqual({ text: idea.reason, completed_closes: 1, required_closes: 2 })
  })

  it('keeps all same-day qualified ideas in Scanner after private target or invalidation outcomes', () => {
    const rows = [row('a'), row('b', { symbol: 'QQQ', outcome: 'invalidated' })]
    const projected = wallIdeaRows(feed({ lifecycle_items: rows }), now)
    expect(projected.map(item => item.symbol)).toEqual(['SPY', 'QQQ'])
    expect(projected.every(item => item.status_group === 'conditions_met')).toBe(true)
    expect(JSON.stringify(projected)).not.toMatch(/invalidated|target_reached|finished/)
  })

  it('recovers qualified history while retaining only the current developing wall per symbol and side', () => {
    const current = { ...row('c'), status: 'testing', status_group: 'watching', qualified: false, qualified_at: null, lifecycle: null }
    const old = { ...current, id: 'old', observed_at: '2026-10-26T14:00:00Z' }
    const rejected = { ...current, id: 'ended', status: 'expired', status_group: 'finished' }
    const data = feed({ items: [current], lifecycle_items: [old, rejected], alert_history: [row('a'), old] })
    expect(wallIdeaRows(data, now).map(item => item.id)).toEqual(['a', 'c'])
  })

  it('groups repeat confirmations without using a private result to split the idea', () => {
    const first = row('a'), second = row('b', { observed_at: '2026-10-26T14:35:00Z', qualified_at: '2026-10-26T14:35:00Z', read_at: null })
    const result = wallIdeaRows(feed({ lifecycle_items: [first], alert_history: [first, second] }), now)
    expect(result).toHaveLength(1)
    expect(result[0].related_alert_ids).toEqual([first.alert_id, second.alert_id])
    expect(result[0].read_at).toBeNull()
    const groupedInbox = wallInboxHistoryRows(wallInboxIdeaRows([first, second], feed(), now))
    expect(groupedInbox).toHaveLength(1)
    expect(groupedInbox[0].related_alert_ids).toHaveLength(2)
  })

  it('keeps inbox identities, read marks and recorded conditions stable during a failed or empty poll', () => {
    const original = row()
    const expected = wallInboxIdeaRows([original], feed(), now)
    expect(wallInboxIdeaRows([original], null, now, true)).toEqual(expected)
    expect(expected[0].alert_id).toBe(original.alert_id)
    expect(expected[0].read_at).toBe(original.read_at)
    expect(expected[0].status_group).toBe('conditions_met')
  })

  it('clears live ideas and saved inbox rows at the next market date while preserving explicit demos', () => {
    expect(wallIdeaRows(feed({ lifecycle_items: [row()] }), now + 86400000)).toEqual([])
    expect(wallInboxIdeaRows([row()], null, now + 86400000)).toEqual([])
    expect(wallIdeaRows(feed({ state: 'demonstration', lifecycle_items: [row()] }), now + 86400000)).toHaveLength(1)
  })

  it('does not accept another scope or a future observed condition', () => {
    expect(wallIdeaRows(feed({ timeframe: '14d', lifecycle_items: [row()] }), now)).toEqual([])
    expect(wallIdeaRows(feed({ lifecycle_items: [row('a', { observed_at: '2026-10-26T16:00:00Z' })] }), now)).toEqual([])
  })

  it('offers only the public idea filters', () => {
    expect(IDEA_STATUS_FILTERS).toEqual([['all', 'All'], ['conditions_met', 'Conditions met'], ['watching', 'Watching']])
    expect(ideaStatusLabel('in_progress')).toBe('Conditions met')
    expect(ideaStatusLabel('finished')).toBe('Watching')
  })
})
