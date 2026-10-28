import { wallAlertSessionDate } from '@/Support/wallAlerts'
import { wallLifecycleRows } from '@/Support/wallLifecycle'

export const WALL_ALERT_INBOX_LIMIT = 2000
const MAX_STORED_CHARACTERS = 3000000
const validKey = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value)
const validPrice = value => Number.isFinite(value) && value > 0
const validTime = value => typeof value === 'string' && Number.isFinite(Date.parse(value))

function lifecycleSnapshot(item, now) {
  const state = item.lifecycle
  if (!state || state.symbol !== item.symbol || state.side !== item.side || state.strike !== item.strike
    || state.session !== wallAlertSessionDate(now) || !['watching', 'conditions_met', 'in_progress', 'finished', 'needs_update'].includes(state.status_group)
    || !validTime(state.observed_at) || Date.parse(state.observed_at) > now) return null
  if (['evaluated_through', 'qualified_at', 'outcome_at'].some(key => validTime(state[key]) && Date.parse(state[key]) > now)) return null
  const result = { symbol: item.symbol, side: item.side, strike: item.strike, session: state.session,
    timeframe: '7d', alert_id: item.alert_id, status_group: state.status_group, qualified: state.qualified === true,
    observed_at: state.observed_at }
  if (validKey(state.id)) result.id = state.id
  if (validKey(state.assessment_id)) result.assessment_id = state.assessment_id
  if (['in_progress', 'watching', 'target_reached', 'invalidated', 'session_ended', 'expired', 'entry_missed', 'no_setup', 'unresolved'].includes(state.outcome)) result.outcome = state.outcome
  for (const key of ['qualified_at', 'evaluated_through', 'outcome_at', 'reviewed_through']) {
    if (validTime(state[key]) && Date.parse(state[key]) <= now) result[key] = state[key]
  }
  for (const key of ['entry_expires_at', 'review_until']) if (validTime(state[key])) result[key] = state[key]
  if (typeof state.reason === 'string') result.reason = state.reason.slice(0, 500)
  return result
}

function laterLifecycle(one, two) {
  if (!one) return two
  if (!two) return one
  if (one.status_group === 'finished' && two.status_group !== 'finished') return one
  if (two.status_group === 'finished' && one.status_group !== 'finished') return two
  const at = state => Date.parse(state.evaluated_through || state.outcome_at || state.observed_at) || 0
  if (at(one) === at(two)) return two.status_group === 'finished' ? two : one
  return at(one) > at(two) ? one : two
}

// Retain the original qualifying assessment, rather than a later feed row.
function snapshot(item, now) {
  if (!item || item.preview || item.demonstration || !validKey(item.alert_id)
    || !/^[A-Z][A-Z0-9.-]{0,9}$/.test(item.symbol || '') || !['put', 'call'].includes(item.side)
    || !['conditions_met', 'testing'].includes(item.status) || !validPrice(item.strike) || !validPrice(item.close)
    || typeof item.observed_at !== 'string') return null
  const observed = Date.parse(item.observed_at), received = item.received_at
  if (!Number.isFinite(observed) || !Number.isFinite(received) || observed > received || received > now
    || wallAlertSessionDate(observed) !== wallAlertSessionDate(now)
    || wallAlertSessionDate(received) !== wallAlertSessionDate(now)) return null
  let setup = null
  if (item.status === 'conditions_met') {
    const value = item.setup
    if (!['bullish', 'bearish'].includes(value?.direction)
      || ![value?.entry_zone?.low, value?.entry_zone?.high, value?.invalidation?.price, value?.target?.strike].every(validPrice)
      || value.entry_zone.low >= value.entry_zone.high || !['above', 'below'].includes(value.invalidation.close_side)) return null
    setup = { direction: value.direction,
      pattern: ['reversal', 'continuation', 'reclaim'].includes(value.pattern) ? value.pattern : null,
      entry_zone: { low: value.entry_zone.low, high: value.entry_zone.high },
      invalidation: { price: value.invalidation.price, close_side: value.invalidation.close_side },
      target: { strike: value.target.strike } }
  }
  const lifecycle = lifecycleSnapshot(item, now)
  return { alert_id: item.alert_id, assessment_id: validKey(item.assessment_id) ? item.assessment_id : null,
    symbol: item.symbol, side: item.side, strike: item.strike, close: item.close, status: item.status,
    observed_at: item.observed_at, received_at: received, setup,
    ...(lifecycle ? { lifecycle } : {}),
    ...(item.server_recorded === true ? { server_recorded: true } : {}),
    ...(item.origin === 'watchlist' ? { origin: 'watchlist' } : {}),
    read_at: Number.isFinite(item.read_at) && item.read_at >= received && item.read_at <= now ? item.read_at : null }
}

export function wallInboxServerSnapshots(data, now = Date.now(), includeTesting = false) {
  if (data?.schema_version !== 'wall-watchlist.v1' || data.state === 'demonstration'
    || data.timeframe !== '7d' || data.session !== wallAlertSessionDate(now) || !Array.isArray(data.alert_history)) return []
  const cutoff = now - (Math.max(900, Number(data.quote_delay_seconds) || 900) * 1000 + 30000)
  return data.alert_history.filter(row => row && row.session === data.session && row.timeframe === '7d'
    && (row.status === 'conditions_met' || (includeTesting && row.status === 'testing'))
    && Date.parse(row.observed_at) <= cutoff)
    .map(row => snapshot({ ...row, received_at: now, origin: 'watchlist', server_recorded: true }, now)).filter(Boolean)
}

export function wallInboxSetupSnapshots(data, now = Date.now()) {
  if (data?.state !== 'ready' || data.timeframe !== '7d') return []
  const cutoff = now - (Math.max(900, Number(data.quote_delay_seconds) || 900) * 1000 + 30000)
  return wallLifecycleRows(data, now)
    .filter(row => row.qualified === true && row.session === data.session && (row.timeframe || '7d') === '7d'
      && (['conditions_met', 'in_progress'].includes(row.status_group) || (row.status_group === 'needs_update' && row.outcome === 'unresolved'))
      && !row.entry_window_elapsed
      && validKey(row.assessment_id) && validKey(row.alert_id))
    .map(row => {
      const observed = row.qualified_at || row.observed_at
      if (Date.parse(observed) > cutoff) return null
      return snapshot({ ...row, status: 'conditions_met', observed_at: observed,
        received_at: now, origin: 'watchlist' }, now)
    }).filter(Boolean)
}

export function mergeWallAlertHistory(rows, now = Date.now()) {
  const byId = new Map()
  for (const row of rows) {
    const item = snapshot(row, now)
    if (!item) continue
    const previous = byId.get(item.alert_id)
    if (!previous) { byId.set(item.alert_id, item); continue }
    const samePlan = previous.symbol === item.symbol && previous.side === item.side && previous.strike === item.strike
      && previous.status === item.status && JSON.stringify(previous.setup) === JSON.stringify(item.setup)
    if (!samePlan) continue
    const canonical = [previous, item].filter(row => row.server_recorded)
      .sort((a, b) => Date.parse(a.observed_at) - Date.parse(b.observed_at))[0]
    const first = previous.received_at <= item.received_at ? previous : item
    const original = canonical && Date.parse(canonical.observed_at) < Date.parse(first.observed_at) ? canonical : first
    byId.set(item.alert_id, { ...original,
      received_at: Math.min(previous.received_at, item.received_at),
      ...(laterLifecycle(previous.lifecycle, item.lifecycle) ? { lifecycle: laterLifecycle(previous.lifecycle, item.lifecycle) } : {}),
      read_at: Math.max(previous.read_at || 0, item.read_at || 0) || null })
  }
  return [...byId.values()].sort((a, b) => Date.parse(b.observed_at) - Date.parse(a.observed_at)
    || b.received_at - a.received_at || a.alert_id.localeCompare(b.alert_id))
}

export function createWallAlertInbox(owner, storage) {
  try { storage ||= window.localStorage } catch { storage = null }
  const key = `gex:wall-alert-inbox:v1:${owner}`
  let items = [], session, persistent = !!storage

  function stored(now) {
    try {
      const raw = storage?.getItem(key)
      if (!raw) return []
      const value = raw.length <= MAX_STORED_CHARACTERS ? JSON.parse(raw) : null
      if (value?.session !== wallAlertSessionDate(now)) { storage?.removeItem(key); return [] }
      return Array.isArray(value.items) ? mergeWallAlertHistory(value.items.slice(0, WALL_ALERT_INBOX_LIMIT), now) : []
    } catch { persistent = false; return null }
  }
  function save() {
    try { storage?.setItem(key, JSON.stringify({ session, items: items.slice(0, WALL_ALERT_INBOX_LIMIT) })); persistent = !!storage }
    catch { persistent = false }
  }
  function read(now = Date.now()) {
    const day = wallAlertSessionDate(now)
    if (session !== day) {
      session = day
      items = stored(now) || []
    }
    items = mergeWallAlertHistory(items, now)
    return items
  }
  function sync(now = Date.now()) {
    read(now)
    items = stored(now) ?? items
    return items
  }
  function add(rows, now = Date.now()) {
    read(now)
    items = mergeWallAlertHistory([...items, ...(stored(now) || []), ...rows.map(row => ({ ...row, read_at: null }))], now)
    save()
    return items
  }
  function markRead(id = null, now = Date.now()) {
    read(now)
    const ids = new Set(Array.isArray(id) ? id : [id])
    items = mergeWallAlertHistory([...items, ...(stored(now) || [])], now).map(item =>
      !item.read_at && (!id || ids.has(item.alert_id)) ? { ...item, read_at: now } : item)
    save()
    return items
  }
  return { key, read, sync, add, markRead, get persistent() { return persistent } }
}
