import { currentWallAlertRows, wallAlertSessionDate } from '@/Support/wallAlerts'

export const WALL_STATUS_FILTERS = Object.freeze([
  ['all', 'All'], ['conditions_met', 'Conditions met'], ['watching', 'Watching'],
  ['in_progress', 'In progress'], ['finished', 'Past setups'], ['needs_update', 'Needs update'],
])
export const wallStatusLabel = key => WALL_STATUS_FILTERS.find(([value]) => value === key)?.[1] || 'Needs update'
export const wallOutcomeLabel = key => ({ target_reached: 'Target reached', invalidated: 'Invalidated',
  expired: 'Setup window closed', entry_missed: 'Price moved past entry', no_setup: 'Not qualified', unresolved: 'Comparison interrupted' }[key] || '')

export function wallLifecycleRows(data, now = Date.now(), unavailable = false) {
  if (data?.schema_version !== 'wall-watchlist.v1' || (data.state !== 'demonstration' && data.session !== wallAlertSessionDate(now))) return []
  const source = Array.isArray(data.lifecycle_items) ? data.lifecycle_items : (data.items || []).map(row => ({
    ...row, id: row.assessment_id || `${row.symbol}:${row.side}`, session: data.session,
    alert_id: row.alert_keys?.[row.status === 'conditions_met' ? 'setup' : 'testing'],
    status_group: row.status === 'conditions_met' ? 'conditions_met' : 'watching',
    qualified: row.status === 'conditions_met', evaluated_through: row.observed_at,
    reason: row.next_condition?.text,
  }))
  const cutoff = now - (Math.max(900, Number(data.quote_delay_seconds) || 900) * 1000 + 30000)
  return source.filter(row => row.symbol && row.observed_at && Number.isFinite(Date.parse(row.observed_at)))
    .map(row => {
      const at = Date.parse(row.evaluated_through || row.observed_at)
      const fresh = data.state === 'demonstration' || (!unavailable && Number.isFinite(at) && at <= cutoff
        && cutoff - at <= 600000 && data.state === 'ready' && Date.parse(data.refresh_until) > now)
      // A proven terminal result remains a recorded fact when collection pauses.
      const expiry = Date.parse(row.entry_expires_at || row.setup?.expires_at)
      const expiredEntry = data.state !== 'demonstration' && row.status_group === 'conditions_met' && Number.isFinite(expiry) && expiry < cutoff
      return { ...row, entry_window_elapsed: expiredEntry,
        status_group: row.status_group === 'finished' || (fresh && !expiredEntry) ? row.status_group : 'needs_update' }
    })
}

export function wallInboxRows(history, data, now = Date.now(), unavailable = false) {
  const lifecycle = new Map(wallLifecycleRows(data, now, unavailable).filter(row => row.alert_id).map(row => [row.alert_id, row]))
  const current = new Map(currentWallAlertRows(data, now).map(row => [row.alert_keys?.[row.status === 'conditions_met' ? 'setup' : 'testing'], row]))
  return history.map(item => {
    const state = lifecycle.get(item.alert_id)
    const match = state?.symbol === item.symbol && state?.side === item.side && state?.strike === item.strike
      && state?.session === wallAlertSessionDate(now)
    return { ...item, lifecycle: match ? state : null,
      status_group: match ? state.status_group : !unavailable && current.has(item.alert_id)
        ? item.status === 'conditions_met' ? 'conditions_met' : 'watching' : 'needs_update' }
  })
}

export function activeWallRows(rows) {
  return rows.filter(row => row.status_group !== 'finished' && !row.entry_window_elapsed
    && !['expired', 'entry_missed'].includes(row.outcome || row.lifecycle?.outcome)
    && !row.lifecycle?.entry_window_elapsed)
}

export function filterWallRows(rows, { status = 'all', symbol = '', direction = 'all', unread = false } = {}) {
  const query = symbol.trim().toUpperCase()
  return rows.filter(row => (status === 'all' || row.status_group === status)
    && (!query || row.symbol.includes(query)) && (direction === 'all' || (row.setup?.direction || row.direction) === direction)
    && (!unread || !row.read_at))
}
