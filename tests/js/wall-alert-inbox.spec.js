import { describe, expect, it } from 'vitest'
import { createWallAlertInbox, WALL_ALERT_INBOX_LIMIT } from '@/Support/wallAlertInbox'

const now = Date.parse('2026-09-30T14:36:00Z')
const key = id => id.toString(16).padStart(64, '0')
const row = (id = 1) => ({ alert_id: key(id), assessment_id: key(id + 1000), symbol: 'SPY', side: 'put',
  status: 'conditions_met', strike: 765, close: 765.8, observed_at: '2026-09-30T14:20:00Z', received_at: now,
  setup: { direction: 'bullish', pattern: 'reversal', entry_zone: { low: 765.3825, high: 766.1475 },
    invalidation: { price: 764.6175, close_side: 'below' }, target: { strike: 770 } } })
function storage() {
  const values = new Map()
  return { getItem: key => values.get(key) || null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) }
}

describe('today’s recorded wall alert inbox', () => {
  it('retains original levels and assessment through reload and later repeated readings', () => {
    const saved = storage(), inbox = createWallAlertInbox(1, saved), original = row()
    inbox.add([original], now)
    original.setup.entry_zone.low = 999
    const repeated = { ...row(), assessment_id: key(9999), received_at: now + 1000,
      observed_at: '2026-09-30T14:25:00Z', setup: { ...row().setup, target: { strike: 780 } } }
    inbox.add([repeated], now + 1000)
    const [restored] = createWallAlertInbox(1, saved).read(now + 1000)
    expect(restored.assessment_id).toBe(key(1001))
    expect(restored.observed_at).toBe('2026-09-30T14:20:00Z')
    expect(restored.setup.entry_zone.low).toBe(765.3825)
    expect(restored.setup.target.strike).toBe(770)
    expect(restored.read_at).toBeNull()
  })
  it('marks one or all read across reloads and keeps accounts isolated', () => {
    const saved = storage(), inbox = createWallAlertInbox(1, saved)
    inbox.add([row(1), row(2)], now)
    expect(inbox.markRead(key(1), now + 1000).filter(item => !item.read_at)).toHaveLength(1)
    const reload = createWallAlertInbox(1, saved)
    expect(reload.read(now + 2000).find(item => item.alert_id === key(1)).read_at).toBe(now + 1000)
    expect(createWallAlertInbox(2, saved).read(now + 2000)).toEqual([])
    expect(reload.markRead(null, now + 2000).every(item => item.read_at)).toBe(true)
  })
  it('keeps up to 100 most recently received alerts without carrying live-feed objects', () => {
    const inbox = createWallAlertInbox(1, storage())
    const result = inbox.add(Array.from({ length: 140 }, (_, index) => ({ ...row(index + 1), received_at: now + index,
      evidence: 'not retained', alert_keys: { setup: key(index + 1) } })), now + 200)
    expect(result).toHaveLength(WALL_ALERT_INBOX_LIMIT)
    expect(result[0].alert_id).toBe(key(140))
    expect(result.at(-1).alert_id).toBe(key(41))
    expect(result[0]).not.toHaveProperty('evidence')
    expect(result[0]).not.toHaveProperty('alert_keys')
  })
  it('clears records at midnight Eastern time, including after a page reload', () => {
    const saved = storage(), inbox = createWallAlertInbox(1, saved)
    inbox.add([row()], now)
    expect(inbox.read(Date.parse('2026-10-01T03:59:59Z'))).toHaveLength(1)
    expect(inbox.read(Date.parse('2026-10-01T04:00:00Z'))).toEqual([])
    expect(saved.getItem(inbox.key)).toBeNull()
    expect(createWallAlertInbox(1, saved).read(Date.parse('2026-10-01T04:00:00Z'))).toEqual([])
  })
  it('ignores previews, demonstrations, malformed values and other dates', () => {
    const inbox = createWallAlertInbox(1, storage())
    for (const change of [{ preview: true }, { demonstration: true }, { alert_id: 'bad' }, { symbol: '<script>' },
      { side: 'other' }, { status: 'watch' }, { strike: 0 }, { close: null }, { received_at: now + 1 },
      { observed_at: '2026-09-29T14:20:00Z' }, { setup: { ...row().setup, invalidation: { price: 764, close_side: 'maybe' } } },
      { setup: { ...row().setup, direction: 'unknown' } }]) {
      expect(inbox.add([{ ...row(), ...change }], now)).toEqual([])
    }
    expect(inbox.add([{ ...row(), status: 'testing', setup: null }], now)[0].setup).toBeNull()
  })
  it('merges concurrent tab records and read status without replacing an original snapshot', () => {
    const saved = storage(), one = createWallAlertInbox(1, saved), two = createWallAlertInbox(1, saved)
    one.read(now); two.read(now)
    one.add([row(1)], now)
    two.add([row(2)], now)
    expect(one.sync(now)).toHaveLength(2)
    two.markRead(key(1), now + 1000)
    expect(one.sync(now + 1000).find(item => item.alert_id === key(1)).read_at).toBe(now + 1000)
    saved.removeItem(one.key)
    expect(one.sync(now + 1000)).toEqual([])
  })
  it('retains this visit’s alerts if browser storage is unavailable and ignores corrupt stored JSON', () => {
    const blocked = { getItem() { throw new Error('blocked') }, setItem() { throw new Error('blocked') } }
    const inbox = createWallAlertInbox(1, blocked)
    expect(inbox.add([row()], now)).toHaveLength(1)
    expect(inbox.sync(now + 1000)).toHaveLength(1)
    expect(inbox.markRead(null, now + 2000)[0].read_at).toBe(now + 2000)
    expect(inbox.persistent).toBe(false)
    const saved = storage(), corrupt = createWallAlertInbox(1, saved)
    saved.setItem(corrupt.key, '{invalid')
    expect(corrupt.read(now)).toEqual([])
    expect(corrupt.add([row()], now)).toHaveLength(1)
    expect(corrupt.persistent).toBe(true)
  })
})
