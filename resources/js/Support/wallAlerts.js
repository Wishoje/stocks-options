export const WALL_ALERTS = Symbol('wall-alerts')
export const DEFAULT_WALL_ALERT_SETTINGS = Object.freeze({ enabled: true, testing: false, sound: false })
const validKey = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value)
const today = now => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(now))
const validPrice = price => Number.isFinite(price) && price > 0

export function currentWallAlertRows(data, now = Date.now()) {
  if (data?.schema_version !== 'wall-watchlist.v1' || data.state !== 'ready' || data.timeframe !== '7d'
    || data.session !== today(now) || Date.parse(data.refresh_until) <= now || !Number.isFinite(Date.parse(data.refresh_until))) return []
  const delay = Math.max(900, Number(data.quote_delay_seconds) || 900) * 1000 + 30000
  const cutoff = now - delay
  return (Array.isArray(data.items) ? data.items : []).filter(row => {
    const at = Date.parse(row.observed_at)
    if (!/^[A-Z][A-Z0-9.-]{0,9}$/.test(row.symbol || '') || !['put', 'call'].includes(row.side)
      || !validPrice(row.strike) || !validPrice(row.close) || !Number.isFinite(at) || at > cutoff || cutoff - at > 600000) return false
    if (row.status === 'testing') return validKey(row.alert_keys?.testing)
    if (row.status !== 'conditions_met' || !validKey(row.alert_keys?.setup)) return false
    const setup = row.setup, low = setup?.entry_zone?.low, high = setup?.entry_zone?.high
    return [low, high, setup?.invalidation?.price, setup?.target?.strike].every(validPrice) && low < high
      && Date.parse(setup.expires_at) >= cutoff
      && (setup.direction === 'bullish' ? row.close > low && row.close <= high
        : setup.direction === 'bearish' && row.close >= low && row.close < high)
  })
}

export function createWallAlertEngine(owner, storage) {
  try { storage ||= window.localStorage } catch { storage = null }
  const key = `gex:wall-alert-seen:v1:${owner}`
  let session, primed = false, startedAfter = 0, seen = new Set()
  function read() {
    try { const value = JSON.parse(storage?.getItem(key) || 'null'); return value?.session === session ? value : null } catch { return null }
  }
  function merge() { for (const id of read()?.ids || []) if (validKey(id)) seen.add(id) }
  function save() {
    // Bounded event identities only; no prices, setup levels or account details.
    seen = new Set([...seen].slice(-2000))
    try { storage?.setItem(key, JSON.stringify({ session, ids: [...seen] })) } catch {}
  }
  function reset() { primed = false; startedAfter = 0 }
  function consume(data, settings, now = Date.now()) {
    if (!settings.enabled || data?.state === 'demonstration') return []
    if (data?.session !== today(now) || data.timeframe !== '7d') return []
    if (session !== data.session) { session = data.session; primed = false; seen = new Set(); merge() }
    const rows = currentWallAlertRows(data, now)
    if (data.state !== 'ready') return []
    merge()
    const candidates = []
    for (const row of rows) {
      const id = row.status === 'conditions_met' ? row.alert_keys.setup : row.alert_keys.testing
      const unseen = !seen.has(id)
      seen.add(id)
      for (const value of Object.values(row.alert_keys || {})) if (validKey(value)) seen.add(value)
      if (primed && unseen && Date.parse(row.observed_at) > startedAfter && (row.status === 'conditions_met' || settings.testing)) candidates.push({ ...row, alert_id: id })
    }
    if (!primed) {
      primed = true
      startedAfter = now - (Math.max(900, Number(data.quote_delay_seconds) || 900) * 1000 + 30000)
    }
    save()
    return candidates.sort((a, b) => Number(b.status === 'conditions_met') - Number(a.status === 'conditions_met') || Date.parse(b.observed_at) - Date.parse(a.observed_at))
  }
  return { consume, reset }
}

export function wallAlertSettings(owner, storage) {
  try {
    storage ||= window.localStorage
    const value = JSON.parse(storage.getItem(`gex:wall-alert-settings:v1:${owner}`) || 'null')
    return Object.fromEntries(Object.entries(DEFAULT_WALL_ALERT_SETTINGS).map(([key, fallback]) => [key, typeof value?.[key] === 'boolean' ? value[key] : fallback]))
  } catch { return { ...DEFAULT_WALL_ALERT_SETTINGS } }
}
