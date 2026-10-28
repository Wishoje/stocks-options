import { describe, expect, it } from 'vitest'
import { activeWallRows, wallInboxHistoryRows, wallInboxRows } from '@/Support/wallLifecycle'

const key = value => value.repeat(64)
const setup = { direction: 'bullish', entry_zone: { low: 100.05, high: 100.15 },
  invalidation: { price: 99.95, close_side: 'below' }, target: { strike: 101 } }
const row = (id, at, change = {}) => ({ alert_id: key(id), assessment_id: key(id), symbol: 'SPY', side: 'put',
  strike: 100, close: 100.1, status: 'conditions_met', observed_at: at, session: '2026-09-30', timeframe: '7d',
  setup, read_at: null, ...change })
const life = (item, status = 'in_progress', at = '2026-09-30T15:00:00Z') => ({ ...item,
  status_group: status, qualified: true, qualified_at: item.observed_at, evaluated_through: at,
  outcome: status === 'finished' ? 'target_reached' : 'in_progress',
  outcome_at: status === 'finished' ? at : null })
const afterClose = Date.parse('2026-09-30T21:00:00Z')

describe('all-day alert inbox presentation', () => {
  it('keeps a completed morning alert after close and during a network failure without changing Scanner', () => {
    const item = row('a', '2026-09-30T14:00:00Z')
    item.lifecycle = life(item, 'finished')
    const feed = { schema_version: 'wall-watchlist.v1', session: '2026-09-30', timeframe: '7d',
      state: 'outside_session', items: [], lifecycle_items: [], alert_history: [item] }
    const projected = wallInboxRows([item], feed, afterClose)
    expect(wallInboxHistoryRows(projected)[0]).toMatchObject({ status_group: 'finished', lifecycle: { outcome: 'target_reached' } })
    expect(activeWallRows(projected)).toEqual([])
    expect(wallInboxHistoryRows(wallInboxRows([item], null, afterClose, true))[0].status_group).toBe('finished')
  })

  it('groups repeated confirmations within an episode but keeps a later same-level setup separate', () => {
    const first = row('a', '2026-09-30T14:00:00Z', { read_at: afterClose })
    const repeated = row('b', '2026-09-30T14:20:00Z')
    const later = row('c', '2026-09-30T15:05:00Z')
    for (const item of [first, repeated]) { item.status_group = 'finished'; item.lifecycle = life(item, 'finished') }
    later.status_group = 'in_progress'; later.lifecycle = life(later, 'in_progress', '2026-09-30T15:10:00Z')
    const grouped = wallInboxHistoryRows([later, repeated, first])
    expect(grouped).toHaveLength(2)
    expect(grouped[0].alert_id).toBe(key('c'))
    expect(grouped[1].related_alert_ids).toEqual([key('a'), key('b')])
    expect(grouped[1].status_group).toBe('finished')
    expect(grouped[1].read_at).toBeNull()
    expect(grouped[1].additional_confirmations).toEqual([{ observed_at: repeated.observed_at, assessment_id: repeated.assessment_id }])
  })

  it('does not join different symbols or changed target boundaries into the same episode', () => {
    const first = row('a', '2026-09-30T14:00:00Z', { status_group: 'in_progress' })
    const different = row('b', '2026-09-30T14:20:00Z', { status_group: 'in_progress', setup: { ...setup, target: { strike: 102 } } })
    const symbol = row('c', '2026-09-30T14:20:00Z', { status_group: 'in_progress', symbol: 'QQQ' })
    expect(wallInboxHistoryRows([first, different, symbol])).toHaveLength(3)
  })
})
