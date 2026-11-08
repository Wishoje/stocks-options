import { wallAlertSessionDate } from '@/Support/wallAlerts'
import { activeWallRows } from '@/Support/wallLifecycle'

export const IDEA_STATUS_FILTERS = Object.freeze([
  ['all', 'All'], ['conditions_met', 'Conditions met'], ['watching', 'Watching'],
])

export const ideaStatusLabel = status => status === 'conditions_met' || status === 'in_progress'
  ? 'Conditions met' : 'Watching'

const fields = [
  'id', 'alert_id', 'assessment_id', 'event_id', 'symbol', 'side', 'strike', 'close', 'direction', 'reaction',
  'session', 'timeframe', 'observed_at', 'qualified_at', 'qualified_price', 'plan_session', 'received_at', 'read_at', 'origin',
  'server_recorded', 'preview', 'demonstration', 'related_alert_ids', 'additional_confirmations',
  'source_date', 'idea_date', 'available_through', 'reviewed_through', 'latest_price', 'scope_label',
]
const evidenceFields = [
  'close', 'sma20', 'sma50', 'atr14', 'return5_pct', 'net_gex', 'dex_shares', 'volatility',
  'oi_change', 'target_room_ratio', 'target_rolloff_pct',
]
const pick = (value, keys) => value && typeof value === 'object'
  ? Object.fromEntries(keys.filter(key => Object.hasOwn(value, key)).map(key => [key, value[key]])) : {}
const validTime = value => typeof value === 'string' && Number.isFinite(Date.parse(value))
const qualified = row => row?.qualified === true || row?.status === 'conditions_met'
  || row?.status_group === 'conditions_met' || row?.status === 'in_progress' || row?.status_group === 'in_progress'
  || validTime(row?.qualified_at) || row?.lifecycle?.qualified === true || validTime(row?.lifecycle?.qualified_at)
const terminal = row => row?.status_group === 'finished'
  || ['finished', 'expired', 'invalidated', 'target_reached', 'entry_missed', 'session_ended'].includes(row?.status)
const outcomeText = /target[_ -]?(?:reached|hit)|\binvalidat(?:ed|ion occurred)\b|\bin[_ -]progress\b|\bfinished\b|\bexpired\b|\bentry[_ -]missed\b|\breview (?:ended|ends)\b/i

function setupLevels(value) {
  if (!value || typeof value !== 'object') return null
  return {
    ...pick(value, ['direction', 'pattern']),
    ...(value.entry_zone ? { entry_zone: pick(value.entry_zone, ['low', 'high']) } : {}),
    ...(value.invalidation ? { invalidation: pick(value.invalidation, ['price', 'close_side']) } : {}),
    ...(value.target ? { target: pick(value.target, ['strike']) } : {}),
    ...(Array.isArray(value.evidence_checks) ? { evidence_checks: value.evidence_checks.map(check => pick(check, ['key', 'passed'])) } : {}),
  }
}

// Customer status describes the recorded price condition, never a trade outcome.
// Keep this projection separate from the records used by the private study.
export function presentIdea(row) {
  if (!row || typeof row !== 'object') return null
  const met = qualified(row)
  const result = pick(row, fields)
  if (!Number.isFinite(result.qualified_price) || result.qualified_price <= 0) delete result.qualified_price
  if (typeof result.plan_session !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(result.plan_session)) delete result.plan_session
  result.qualified = met
  result.status_group = met ? 'conditions_met' : 'watching'
  result.status = met ? 'conditions_met' : row.status === 'testing' ? 'testing' : 'watch'
  if (!result.qualified_at && validTime(row.lifecycle?.qualified_at)) result.qualified_at = row.lifecycle.qualified_at
  if (!result.session && validTime(row.observed_at)) result.session = wallAlertSessionDate(Date.parse(row.observed_at))
  if (row.alert_keys) result.alert_keys = pick(row.alert_keys, ['setup', 'testing'])
  if (Array.isArray(row.additional_confirmations)) result.additional_confirmations = row.additional_confirmations
    .map(item => pick(item, ['observed_at', 'assessment_id']))
  if (row.setup) result.setup = setupLevels(row.setup)
  if (row.levels) result.levels = pick(row.levels, ['touch_low', 'touch_high', 'trigger', 'entry_low', 'entry_high', 'invalidation', 'invalidation_side', 'target'])
  if (row.evidence) result.evidence = pick(row.evidence, evidenceFields)

  const next = !met && !terminal(row) && typeof row.next_condition?.text === 'string'
    && !outcomeText.test(row.next_condition.text) ? row.next_condition : null
  result.reason = met
    ? 'Conditions were met at the recorded price. Review the idea levels against the current price.'
    : next?.text || 'Watch for a completed price sequence at this wall.'
  if (next) result.next_condition = pick(next, ['text', 'completed_closes', 'required_closes'])
  return result
}

function inSession(row, session, now, demonstration) {
  if (!row || !row.symbol || !['put', 'call'].includes(row.side) || !Number.isFinite(row.strike) || row.strike <= 0
    || !validTime(row.observed_at) || (row.session && row.session !== session)
    || (row.timeframe && row.timeframe !== '7d')) return false
  const observed = Date.parse(row.observed_at)
  return demonstration || (observed <= now && wallAlertSessionDate(observed) === session)
}

function withFeedIdentity(row, data) {
  return { ...row, session: row.session || data.session, timeframe: row.timeframe || data.timeframe,
    id: row.id || row.assessment_id || `${row.symbol}:${row.side}:${row.observed_at}`,
    alert_id: row.alert_id || row.alert_keys?.[qualified(row) ? 'setup' : 'testing'] }
}

export function wallIdeaRows(data, now = Date.now()) {
  const demonstration = data?.state === 'demonstration'
  if (data?.schema_version !== 'wall-watchlist.v1' || data.timeframe !== '7d'
    || (!demonstration && data.session !== wallAlertSessionDate(now))) return []
  const sources = [
    ...(Array.isArray(data.items) ? data.items : []).map(row => ({ row, history: false })),
    ...(Array.isArray(data.lifecycle_items) ? data.lifecycle_items : []).map(row => ({ row, history: false })),
    ...(Array.isArray(data.alert_history) ? data.alert_history : []).map(row => ({ row, history: true })),
  ]
  const byIdentity = new Map(), watching = new Map()
  for (const source of sources) {
    if (!source.row || typeof source.row !== 'object') continue
    const raw = withFeedIdentity(source.row, data)
    if (!inSession(raw, data.session, now, demonstration)) continue
    if (!qualified(raw) && (source.history || terminal(raw))) continue
    const idea = presentIdea(raw)
    if (idea.qualified) {
      const identity = idea.alert_id || idea.assessment_id || idea.id
      const prior = byIdentity.get(identity)
      byIdentity.set(identity, { ...prior, ...idea, read_at: idea.read_at ?? prior?.read_at ?? null })
    } else {
      const identity = `${idea.symbol}:${idea.side}`
      if (!watching.has(identity) || Date.parse(idea.observed_at) > Date.parse(watching.get(identity).observed_at)) watching.set(identity, idea)
    }
  }
  return activeWallRows([...byIdentity.values(), ...watching.values()])
    .sort((a, b) => Date.parse(b.qualified_at || b.observed_at) - Date.parse(a.qualified_at || a.observed_at))
}

export function wallInboxIdeaRows(history, data, now = Date.now(), unavailable = false) {
  const session = wallAlertSessionDate(now)
  // A failed poll cannot erase a recorded condition or turn it into a trade
  // outcome. Access checks are enforced by the feed and notification owner.
  return (Array.isArray(history) ? history : [])
    .filter(row => inSession(row, session, now, false))
    .map(presentIdea)
}
