import { wallAlertSessionDate } from '@/Support/wallAlerts'
import { wallLifecycleRows } from '@/Support/wallLifecycle'

export const WALL_ALERT_INBOX_LIMIT = 100
const validKey = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value)
const validPrice = value => Number.isFinite(value) && value > 0

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
  return { alert_id: item.alert_id, assessment_id: validKey(item.assessment_id) ? item.assessment_id : null,
    symbol: item.symbol, side: item.side, strike: item.strike, close: item.close, status: item.status,
    observed_at: item.observed_at, received_at: received, setup,
    ...(item.origin === 'watchlist' ? { origin: 'watchlist' } : {}),
    read_at: Number.isFinite(item.read_at) && item.read_at >= received && item.read_at <= now ? item.read_at : null }
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

function combine(rows, now) {
  const byId = new Map()
  for (const row of rows) {
    const item = snapshot(row, now)
    if (!item) continue
    const previous = byId.get(item.alert_id)
    if (!previous) { byId.set(item.alert_id, item); continue }
    const original = previous.received_at <= item.received_at ? previous : item
    byId.set(item.alert_id, { ...original, read_at: Math.max(previous.read_at || 0, item.read_at || 0) || null })
  }
  return [...byId.values()].sort((a, b) => b.received_at - a.received_at
    || Date.parse(b.observed_at) - Date.parse(a.observed_at) || a.alert_id.localeCompare(b.alert_id)).slice(0, WALL_ALERT_INBOX_LIMIT)
}

export function createWallAlertInbox(owner, storage) {
  try { storage ||= window.localStorage } catch { storage = null }
  const key = `gex:wall-alert-inbox:v1:${owner}`
  let items = [], session, persistent = !!storage

  function stored(now) {
    try {
      const raw = storage?.getItem(key)
      if (!raw) return []
      const value = raw.length <= 200000 ? JSON.parse(raw) : null
      if (value?.session !== wallAlertSessionDate(now)) { storage?.removeItem(key); return [] }
      return Array.isArray(value.items) ? combine(value.items.slice(0, WALL_ALERT_INBOX_LIMIT), now) : []
    } catch { persistent = false; return null }
  }
  function save() {
    try { storage?.setItem(key, JSON.stringify({ session, items })); persistent = !!storage }
    catch { persistent = false }
  }
  function read(now = Date.now()) {
    const day = wallAlertSessionDate(now)
    if (session !== day) {
      session = day
      items = stored(now) || []
    }
    items = combine(items, now)
    return items
  }
  function sync(now = Date.now()) {
    read(now)
    items = stored(now) ?? items
    return items
  }
  function add(rows, now = Date.now()) {
    read(now)
    items = combine([...items, ...(stored(now) || []), ...rows.map(row => ({ ...row, read_at: null }))], now)
    save()
    return items
  }
  function markRead(id = null, now = Date.now()) {
    read(now)
    const ids = new Set(Array.isArray(id) ? id : [id])
    items = combine([...items, ...(stored(now) || [])], now).map(item =>
      !item.read_at && (!id || ids.has(item.alert_id)) ? { ...item, read_at: now } : item)
    save()
    return items
  }
  return { key, read, sync, add, markRead, get persistent() { return persistent } }
}
