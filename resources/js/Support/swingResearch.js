export const SWING_RESEARCH_ALERTS = Symbol('swing-research-alerts')
export const swingVariants = ['baseline', 'nearer_entry', 'earlier_exit']
export const swingVariantLabels = { baseline: 'Production baseline', nearer_entry: 'Closer entry', earlier_exit: 'Earlier exit' }
export const swingHorizons = [{ key: 'short', label: '2–5 days' }, { key: 'position', label: '1–3 weeks' }]
export const swingStatusLabel = value => ({ watching: 'Waiting for confirmation', qualified: 'Conditions met', conditions_met: 'Conditions met', in_progress: 'In progress', needs_review: 'Needs review', needs_update: 'Needs review', target_reached: 'Target reached', invalidated: 'Invalidated', ended: 'Ended', expired: 'Window ended', holding_ended: 'Holding period ended', entry_expired: 'Entry window ended', entry_missed: 'Entry area missed', no_idea: 'No eligible setup', finished: 'Review complete', completed: 'Review complete' }[value] || value?.replaceAll('_', ' ') || 'Needs review')
const finite = value => value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value))
export const swingNumber = (value, digits = 2) => finite(value) ? new Intl.NumberFormat('en-US', { maximumFractionDigits: digits }).format(Number(value)) : '—'
export const swingPercent = value => finite(value) ? `${swingNumber(value)}%` : '—'
export function swingSignedPercent(value) {
  if (!finite(value)) return '—'
  const rounded = Number(Number(value).toFixed(2))
  return `${rounded > 0 ? '+' : ''}${swingNumber(rounded === 0 ? 0 : rounded)}%`
}
export function swingTime(value) {
  if (!value) return '—'
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value
  const date = new Date(value)
  return Number.isFinite(date.getTime()) ? `${new Intl.DateTimeFormat('en-US', { timeZone: 'America/New_York', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(date)} ET` : '—'
}
export function defaultSwingFilters(now = new Date(), search = '') {
  const query = new URLSearchParams(search)
  const to = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now)
  const start = new Date(`${to}T12:00:00Z`)
  start.setUTCDate(start.getUTCDate() - 44)
  return { horizon: query.get('horizon') === 'position' ? 'position' : 'short', from: query.get('from') || start.toISOString().slice(0, 10), to: query.get('to') || to, symbol: (query.get('symbol') || '').trim().toUpperCase() }
}
export function swingFilterError(filters) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(filters.from || '') || !/^\d{4}-\d{2}-\d{2}$/.test(filters.to || '')) return 'Choose both dates.'
  if (filters.from > filters.to) return 'From date must be on or before To date.'
  if ((Date.parse(filters.to) - Date.parse(filters.from)) / 86400000 > 179) return 'Choose a period of 180 days or less.'
  if (filters.symbol && !/^[A-Z][A-Z0-9.-]{0,9}$/.test(filters.symbol.trim().toUpperCase())) return 'Enter a valid stock or ETF symbol.'
  return ''
}
export const swingFilterParams = filters => ({ horizon: filters.horizon === 'position' ? 'position' : 'short', from: filters.from, to: filters.to, symbol: filters.symbol.trim().toUpperCase() })
export const swingCaseUrl = item => `/swing-research?${new URLSearchParams({ symbol: item.symbol, horizon: item.horizon })}#swing-case-${encodeURIComponent(item.id)}`
