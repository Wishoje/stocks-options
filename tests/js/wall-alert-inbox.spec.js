import { describe, expect, it } from 'vitest'
import { createWallAlertInbox, wallInboxSetupSnapshots, WALL_ALERT_INBOX_LIMIT } from '@/Support/wallAlertInbox'

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

const activeSetup = (change = {}) => ({ ...row(), id: key(5), session: '2026-09-30', timeframe: '7d',
  status_group: 'in_progress', qualified: true, outcome: 'in_progress',
  qualified_at: '2026-09-30T14:00:00Z', observed_at: '2026-09-30T14:00:00Z',
  evaluated_through: '2026-09-30T14:20:00Z', entry_expires_at: '2026-09-30T14:15:00Z', ...change })
const activeFeed = (rows, change = {}) => ({ schema_version: 'wall-watchlist.v1', session: '2026-09-30',
  timeframe: '7d', state: 'ready', quote_delay_seconds: 900, refresh_until: '2026-09-30T20:00:00Z',
  lifecycle_items: rows, items: [], ...change })

describe('active watchlist setups in the inbox', () => {
  it('captures an in-progress scenario with its original qualification and levels after the entry window', () => {
    const [item] = wallInboxSetupSnapshots(activeFeed([activeSetup()]), now)
    expect(item).toMatchObject({ origin: 'watchlist', alert_id: key(1), assessment_id: key(1001),
      status: 'conditions_met', observed_at: '2026-09-30T14:00:00Z', received_at: now,
      setup: row().setup, read_at: null })
    expect(item).not.toHaveProperty('status_group')
  })
  it('requires fresh, qualified, current-session evidence and valid original assessment levels', () => {
    for (const change of [{ qualified: false }, { status_group: 'watching' }, { status_group: 'finished', outcome: 'expired' },
      { status_group: 'needs_update' }, { setup: { direction: 'bullish' } }, { assessment_id: null },
      { session: '2026-09-29' }, { timeframe: '14d' }, { evaluated_through: '2026-09-30T13:50:00Z' },
      { qualified_at: '2026-09-30T14:25:00Z' },
      { qualified: false, status_group: 'conditions_met', entry_expires_at: '2026-09-30T14:10:00Z' }]) {
      expect(wallInboxSetupSnapshots(activeFeed([activeSetup(change)]), now)).toEqual([])
    }
    for (const change of [{ state: 'demonstration' }, { state: 'outside_session' }, { timeframe: '14d' },
      { session: '2026-09-29' }, { refresh_until: '2026-09-30T14:30:00Z' }]) {
      expect(wallInboxSetupSnapshots(activeFeed([activeSetup()], change), now)).toEqual([])
    }
  })
  it('recovers a qualified setup after the entry deadline without replaying a popup', () => {
    const [item] = wallInboxSetupSnapshots(activeFeed([activeSetup({ status_group: 'conditions_met', entry_expires_at: '2026-09-30T14:10:00Z' })]), now)
    expect(item).toMatchObject({ origin: 'watchlist', observed_at: '2026-09-30T14:00:00Z' })
    const [paused] = wallInboxSetupSnapshots(activeFeed([activeSetup({ status_group: 'needs_update', outcome: 'unresolved' })]), now)
    expect(paused).toMatchObject({ origin: 'watchlist' })
  })
  it('retains read state and original levels when the same watchlist setup is rediscovered', () => {
    const saved = storage(), inbox = createWallAlertInbox(1, saved)
    inbox.add(wallInboxSetupSnapshots(activeFeed([activeSetup()]), now), now)
    inbox.markRead(key(1), now + 1000)
    inbox.add(wallInboxSetupSnapshots(activeFeed([activeSetup({ close: 766 })]), now + 2000), now + 2000)
    const restored = createWallAlertInbox(1, saved).read(now + 2000)
    expect(restored).toHaveLength(1)
    expect(restored[0]).toMatchObject({ origin: 'watchlist', read_at: now + 1000, close: 765.8,
      observed_at: '2026-09-30T14:00:00Z', assessment_id: key(1001) })
  })
})

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
  it('marks all confirmations in a grouped plan read without affecting another plan', () => {
    const saved = storage(), inbox = createWallAlertInbox(1, saved)
    inbox.add([row(1), row(2), row(3)], now)
    inbox.markRead([key(1), key(2)], now + 1000)
    const restored = createWallAlertInbox(1, saved).read(now + 2000)
    expect(restored.filter(item => item.read_at).map(item => item.alert_id).sort()).toEqual([key(1), key(2)])
    expect(restored.find(item => item.alert_id === key(3)).read_at).toBeNull()
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
