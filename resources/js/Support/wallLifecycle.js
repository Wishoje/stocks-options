import { currentWallAlertRows, wallAlertSessionDate } from '@/Support/wallAlerts'

export const WALL_STATUS_FILTERS = Object.freeze([
  ['all', 'All'], ['conditions_met', 'Conditions met'], ['watching', 'Watching'],
  ['in_progress', 'In progress'], ['finished', 'Past setups'], ['needs_update', 'Needs update'],
])
export const wallStatusLabel = key => WALL_STATUS_FILTERS.find(([value]) => value === key)?.[1] || 'Needs update'
export const wallOutcomeLabel = key => ({ target_reached: 'Target reached', invalidated: 'Invalidated',
  session_ended: 'Session ended', expired: 'Setup window closed', entry_missed: 'Price moved past entry', no_setup: 'Not qualified', unresolved: 'Comparison interrupted' }[key] || '')

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
      const entryClosed = data.state !== 'demonstration' && Number.isFinite(expiry) && expiry < cutoff
      const qualified = row.qualified === true
      const expiredEntry = entryClosed && row.status_group === 'conditions_met' && !qualified
      const status = entryClosed && qualified && row.status_group === 'conditions_met' ? 'in_progress' : row.status_group
      return { ...row, entry_window_closed: entryClosed, entry_window_elapsed: expiredEntry,
        status_group: status === 'finished' || (fresh && !expiredEntry) ? status : 'needs_update' }
    })
}

export function wallInboxRows(history, data, now = Date.now(), unavailable = false) {
  const saved = history.map(item => item.lifecycle).filter(Boolean)
  const recorded = (Array.isArray(data?.alert_history) ? data.alert_history : []).map(item => item.lifecycle).filter(Boolean)
  const scope = data?.session === wallAlertSessionDate(now) ? data
    : { schema_version: 'wall-watchlist.v1', session: wallAlertSessionDate(now), timeframe: '7d', state: 'unavailable' }
  const lifecycle = new Map()
  for (const row of [...wallLifecycleRows({ ...scope, lifecycle_items: [...saved, ...recorded] }, now, unavailable), ...wallLifecycleRows(data, now, unavailable)]) {
    if (!row.alert_id) continue
    const prior = lifecycle.get(row.alert_id)
    const at = value => Date.parse(value.evaluated_through || value.outcome_at || value.observed_at) || 0
    if (prior?.status_group === 'finished' && row.status_group !== 'finished') continue
    if (row.status_group === 'finished' && prior?.status_group !== 'finished') { lifecycle.set(row.alert_id, row); continue }
    if (!prior || at(row) > at(prior) || (at(row) === at(prior) && row.status_group === 'finished')) lifecycle.set(row.alert_id, row)
  }
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

// Today's inbox keeps completed episodes. Scanner continues to use activeWallRows.
export function wallInboxHistoryRows(rows) {
  const groups = new Map(), episodes = []
  const ordered = [...rows].sort((a, b) => Date.parse(a.qualified_at || a.observed_at) - Date.parse(b.qualified_at || b.observed_at))
  for (const row of ordered) {
    const plan = row.status === 'conditions_met' || row.qualified === true || row.lifecycle?.qualified === true ? wallActivePlanKey(row) : null
    const time = Date.parse(row.qualified_at || row.observed_at)
    const previous = plan ? groups.get(plan) : null
    let episode = previous && (!Number.isFinite(previous.until) || time <= previous.until) ? previous : null
    if (!episode) {
      episode = { members: [], until: null }
      episodes.push(episode)
      if (plan) groups.set(plan, episode)
    }
    episode.members.push(row)
    if (row.status_group === 'finished') {
      const ended = Date.parse(row.lifecycle?.outcome_at || row.outcome_at || row.lifecycle?.evaluated_through || row.evaluated_through)
      if (Number.isFinite(ended)) episode.until = Number.isFinite(episode.until) ? Math.min(episode.until, ended) : ended
    }
  }
  return episodes.map(({ members }) => {
    if (members.length === 1) return members[0]
    const representative = members.find(row => row.status_group === 'finished')
      || [...members].reverse().find(row => row.status_group === 'conditions_met') || members[0]
    return { ...representative,
      related_alert_ids: members.map(row => row.alert_id).filter(Boolean),
      read_at: members.every(row => row.read_at) ? Math.max(...members.map(row => row.read_at)) : null,
      additional_confirmations: members.filter(row => row !== representative).map(row => ({
        observed_at: row.qualified_at || row.observed_at, assessment_id: row.assessment_id,
      })) }
  }).sort((a, b) => Date.parse(b.observed_at) - Date.parse(a.observed_at))
}

// A price plan can receive another pattern confirmation without changing its
// entry, target or invalidation. Keep the individual assessments in storage.
export function wallActivePlanKey(row) {
  const setup = row.setup
  const levels = [row.strike, setup?.entry_zone?.low, setup?.entry_zone?.high, setup?.invalidation?.price, setup?.target?.strike]
  if (!['bullish', 'bearish'].includes(setup?.direction) || !['put', 'call'].includes(row.side)
    || !levels.every(value => Number.isFinite(value) && value > 0)) return null
  const session = row.session || row.lifecycle?.session || (Number.isFinite(Date.parse(row.observed_at)) ? wallAlertSessionDate(Date.parse(row.observed_at)) : null)
  if (!session || !row.symbol) return null
  return JSON.stringify([row.symbol, session, row.timeframe || row.lifecycle?.timeframe || '7d',
    row.side, setup.direction, ...levels, setup.invalidation.close_side || (setup.direction === 'bullish' ? 'below' : 'above')])
}

export function activeWallRows(rows) {
  const active = rows.filter(row => row.status_group !== 'finished' && !row.entry_window_elapsed
    && !['expired', 'entry_missed'].includes(row.outcome || row.lifecycle?.outcome)
    && !row.lifecycle?.entry_window_elapsed)
  const groups = new Map()
  active.forEach((row, index) => {
    const qualified = row.qualified === true || row.lifecycle?.qualified === true || row.status === 'conditions_met'
    const continuing = ['conditions_met', 'in_progress'].includes(row.status_group)
      || (row.status_group === 'needs_update' && qualified)
    const plan = continuing ? wallActivePlanKey(row) : null
    const key = plan || `individual:${index}`
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key).push(row)
  })
  return [...groups.values()].map(members => {
    if (members.length === 1) return members[0]
    const ordered = [...members].sort((a, b) => Date.parse(a.qualified_at || a.observed_at) - Date.parse(b.qualified_at || b.observed_at))
    // A fresh qualifying entry takes precedence; otherwise retain the first
    // original plan as the representative of the continuing follow-up.
    const representative = [...ordered].reverse().find(row => row.status_group === 'conditions_met') || ordered[0]
    return { ...representative,
      related_alert_ids: members.map(row => row.alert_id).filter(Boolean),
      read_at: members.every(row => row.read_at) ? Math.max(...members.map(row => row.read_at)) : null,
      additional_confirmations: ordered.filter(row => row !== representative).map(row => ({
        observed_at: row.qualified_at || row.observed_at, assessment_id: row.assessment_id,
      })) }
  })
}

export function filterWallRows(rows, { status = 'all', symbol = '', direction = 'all', unread = false } = {}) {
  const query = symbol.trim().toUpperCase()
  return rows.filter(row => (status === 'all' || row.status_group === status)
    && (!query || row.symbol.includes(query)) && (direction === 'all' || (row.setup?.direction || row.direction) === direction)
    && (!unread || !row.read_at))
}
