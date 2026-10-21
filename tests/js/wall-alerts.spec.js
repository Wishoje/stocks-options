import { beforeEach, describe, expect, it } from 'vitest'
import { createWallAlertEngine, currentWallAlertRows, wallAlertSettings } from '@/Support/wallAlerts'

const first = Date.parse('2026-09-30T14:31:00Z')
const later = first + 300000
const settings = { enabled: true, testing: false, sound: false }
const key = value => value.repeat(64)
const row = (changes = {}) => ({ symbol: 'SPY', side: 'put', strike: 100, close: 100.1,
  observed_at: '2026-09-30T14:15:00Z', status: 'conditions_met',
  alert_keys: { setup: key('a'), testing: null }, setup: { direction: 'bullish',
    entry_zone: { low: 100.05, high: 100.15 }, invalidation: { price: 99.95 }, target: { strike: 101 },
    expires_at: '2026-09-30T14:40:00Z' }, ...changes })
const data = (items = [row()], changes = {}) => ({ schema_version: 'wall-watchlist.v1', session: '2026-09-30',
  timeframe: '7d', state: 'ready', quote_delay_seconds: 900, refresh_until: '2026-09-30T20:00:00Z', items, ...changes })
const newRow = () => row({ observed_at: '2026-09-30T14:20:00Z', alert_keys: { setup: key('b') } })
beforeEach(() => localStorage.clear())

describe('wall alert eligibility and event identity', () => {
  it('defaults to off and scopes preferences to the signed-in account', () => {
    expect(wallAlertSettings(1)).toEqual({ enabled: false, testing: false, sound: false })
    localStorage.setItem('gex:wall-alert-settings:v1:1', JSON.stringify(settings))
    expect(wallAlertSettings(1).enabled).toBe(true)
    expect(wallAlertSettings(2).enabled).toBe(false)
  })
  it('primes existing events silently and only emits a later newly confirmed setup', () => {
    const engine = createWallAlertEngine(1)
    expect(engine.consume(data(), settings, first)).toEqual([])
    expect(engine.consume(data([newRow()]), settings, later)).toHaveLength(1)
    expect(engine.consume(data([newRow()]), settings, later + 60000)).toEqual([])
  })
  it('does not replay older catch-up inserts, or setups present when alerts are re-enabled', () => {
    const engine = createWallAlertEngine(1)
    engine.consume(data([]), settings, first)
    expect(engine.consume(data(), settings, later)).toEqual([])
    engine.reset()
    expect(engine.consume(data([newRow()]), settings, later)).toEqual([])
  })
  it('keeps repeated assessments of one setup silent while allowing a different setup', () => {
    const engine = createWallAlertEngine(1)
    engine.consume(data([]), settings, first)
    engine.consume(data([newRow()]), settings, later)
    expect(engine.consume(data([row({ ...newRow(), observed_at: '2026-09-30T14:25:00Z' })]), settings, later + 300000)).toEqual([])
    expect(engine.consume(data([row({ ...newRow(), observed_at: '2026-09-30T14:25:00Z', alert_keys: { setup: key('c') } })]), settings, later + 300000)).toHaveLength(1)
  })
  it('shares seen identities across tabs but isolates accounts', () => {
    const a = createWallAlertEngine(1), b = createWallAlertEngine(1), other = createWallAlertEngine(2)
    for (const engine of [a, b, other]) engine.consume(data([]), settings, first)
    expect(a.consume(data([newRow()]), settings, later)).toHaveLength(1)
    expect(b.consume(data([newRow()]), settings, later)).toEqual([])
    expect(other.consume(data([newRow()]), settings, later)).toHaveLength(1)
    const stored = localStorage.getItem('gex:wall-alert-seen:v1:1')
    expect(stored).not.toContain('SPY'); expect(stored).not.toContain('entry_zone')
  })
  it('only alerts testing when opted in and never replays testing when it is enabled later', () => {
    const engine = createWallAlertEngine(1)
    engine.consume(data([]), settings, first)
    const testing = row({ observed_at: '2026-09-30T14:20:00Z', status: 'testing', setup: null, alert_keys: { testing: key('b') } })
    expect(engine.consume(data([testing]), settings, later)).toEqual([])
    expect(engine.consume(data([testing]), { ...settings, testing: true }, later)).toEqual([])
    const changed = { ...testing, alert_keys: { testing: key('c') } }
    expect(engine.consume(data([changed]), { ...settings, testing: true }, later)).toHaveLength(1)
  })
  it('does not alert unknown, stale, future, expired, missed-entry or malformed records', () => {
    for (const change of [
      { status: 'touch' }, { observed_at: '2026-09-30T13:55:00Z' }, { observed_at: '2026-09-30T14:25:00Z' },
      { setup: { ...row().setup, expires_at: '2026-09-30T14:19:00Z' } }, { close: 100.2 },
      { setup: null }, { alert_keys: { setup: 'bad' } }, { symbol: '<script>' },
      { setup: { ...row().setup, direction: 'unknown' } }, { strike: 0 },
    ]) {
      expect(currentWallAlertRows(data([row({ ...newRow(), ...change })]), later)).toEqual([])
    }
  })
  it('honors bullish and bearish exclusive entry boundaries', () => {
    expect(currentWallAlertRows(data([row({ close: 100.05 })]), first)).toEqual([])
    expect(currentWallAlertRows(data([row({ close: 100.15 })]), first)).toHaveLength(1)
    const bearish = { ...row().setup, direction: 'bearish' }
    expect(currentWallAlertRows(data([row({ close: 100.15, setup: bearish })]), first)).toEqual([])
    expect(currentWallAlertRows(data([row({ close: 100.05, setup: bearish })]), first)).toHaveLength(1)
  })
  it('rejects historical, synthetic, outside-session and other-scope payloads', () => {
    for (const change of [{ session: '2026-09-29' }, { timeframe: '14d' }, { state: 'demonstration' },
      { state: 'outside_session' }, { refresh_until: null }, { refresh_until: '2026-09-30T14:30:00Z' }]) {
      expect(currentWallAlertRows(data([row()], change), first)).toEqual([])
    }
    expect(currentWallAlertRows(data(), Date.parse('2026-09-30T20:00:00Z'))).toEqual([])
  })
  it('starts a new session silently and tolerates unavailable browser storage', () => {
    const unavailable = { getItem() { throw new Error('blocked') }, setItem() { throw new Error('blocked') } }
    const engine = createWallAlertEngine(1, unavailable)
    expect(engine.consume(data(), settings, first)).toEqual([])
    expect(engine.consume(data([newRow()]), settings, later)).toHaveLength(1)
    const next = data([row({ observed_at: '2026-10-01T14:15:00Z', setup: { ...row().setup, expires_at: '2026-10-01T14:40:00Z' } })], { session: '2026-10-01', refresh_until: '2026-10-01T20:00:00Z' })
    expect(engine.consume(next, settings, Date.parse('2026-10-01T14:31:00Z'))).toEqual([])
  })
})
