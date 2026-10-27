export const studyCohorts = [
  { key: 'intraday', label: 'Intraday' },
  { key: 'swing_short', label: 'Swing 2–5 days' },
  { key: 'swing_position', label: 'Swing 1–3 weeks' },
]

const finite = value => value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value))

export function defaultStudyFilters(now = new Date()) {
  const to = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(now)
  const start = new Date(`${to}T12:00:00Z`)
  start.setUTCDate(start.getUTCDate() - 13)
  return { cohort: 'intraday', from: start.toISOString().slice(0, 10), to, symbol: '', direction: '' }
}

export function studyFilterParams(filters) {
  return {
    cohort: studyCohorts.some(item => item.key === filters.cohort) ? filters.cohort : 'intraday',
    from: filters.from,
    to: filters.to,
    symbol: String(filters.symbol || '').trim().toUpperCase(),
    direction: ['bullish', 'bearish'].includes(filters.direction) ? filters.direction : '',
  }
}

export function studyNumber(value, digits = 2) {
  return finite(value) ? new Intl.NumberFormat('en-US', { maximumFractionDigits: digits }).format(Number(value)) : '—'
}

export function studyR(value) {
  return finite(value) ? `${Number(value) > 0 ? '+' : ''}${studyNumber(value)}R` : '—'
}

export function studyAdverseR(value) {
  return finite(value) ? studyR(Number(value) === 0 ? 0 : -Math.abs(Number(value))) : '—'
}

export function studyPercent(value) {
  return finite(value) ? `${studyNumber(value)}%` : '—'
}

export function studyRate(metric) {
  if (!metric || !finite(metric.eligible) || Number(metric.eligible) <= 0 || !finite(metric.rate)) return '—'
  return studyPercent(metric.rate)
}

export function studyTime(value) {
  if (!value) return '—'
  const date = new Date(value)
  if (!Number.isFinite(date.getTime())) return '—'
  return `${new Intl.DateTimeFormat('en-US', {
    timeZone: 'America/New_York', month: 'short', day: 'numeric', year: 'numeric',
    hour: 'numeric', minute: '2-digit',
  }).format(date)} ET`
}

const labels = {
  conditions_met: 'Conditions met', in_progress: 'In progress', needs_update: 'Needs update',
  watching: 'Watching', completed: 'Completed', qualified: 'Qualified',
  target_reached: 'Target reached', target_hit: 'Target reached', invalidated: 'Invalidated',
  window_closed: 'Setup window closed', timed_out: 'Review window ended', expired: 'Review window ended',
  session_ended: 'Session ended', holding_ended: 'Holding period ended', not_qualified: 'Not qualified',
  pending: 'Pending', insufficient_data: 'Insufficient follow-up', no_entry: 'Entry not confirmed',
  resolved_before_publication: 'Resolved before app availability', open_at_publication: 'Open at app availability',
  timing_unknown: 'Availability timing unknown',
  bullish: 'Bullish', bearish: 'Bearish', intraday: 'Intraday', swing_short: 'Swing 2–5 days', swing_position: 'Swing 1–3 weeks',
}

export function studyLabel(value) {
  if (value === null || value === undefined || value === '') return '—'
  const text = String(value)
  return labels[text] || text.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase())
}

export function studyEvidenceEntries(value, prefix = '', depth = 0) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return []
  return Object.entries(value).flatMap(([key, item]) => {
    const label = prefix ? `${prefix} · ${studyLabel(key)}` : studyLabel(key)
    if (item && typeof item === 'object' && !Array.isArray(item) && depth < 2) {
      return studyEvidenceEntries(item, label, depth + 1)
    }
    return [{ label, value: item === null || item === undefined ? '—'
      : typeof item === 'boolean' ? (item ? 'Yes' : 'No')
        : typeof item === 'object' ? JSON.stringify(item) : String(item) }]
  })
}

export function studyCsvCell(value) {
  let text = value === null || value === undefined ? '' : String(value)
  if (typeof value === 'string' && /^[\s]*[=+\-@\t\r]/.test(text)) text = `'${text}`
  return `"${text.replaceAll('"', '""')}"`
}

export function studyCsv(rows) {
  const columns = [
    ['Plan ID', row => row.id], ['Symbol', row => row.symbol], ['Direction', row => row.direction],
    ['Cohort', row => row.horizon], ['Signal time', row => row.signal_at], ['Available time', row => row.available_at],
    ['Status', row => row.needs_update ? 'needs_update' : row.status], ['Outcome', row => row.outcome],
    ['Signal price', row => row.signal_price], ['Entry low', row => row.entry_low], ['Entry high', row => row.entry_high],
    ['Invalidation', row => row.invalidation], ['Target', row => row.target], ['Qualified', row => row.qualified],
    ['Follow-up complete', row => row.followup_complete], ['Confirmations', row => row.confirmation_count],
    ['Best close R', row => row.metrics?.close_mfe_r ?? row.metrics?.mfe_r],
    ['Adverse close distance R', row => row.metrics?.close_mae_r ?? row.metrics?.mae_r],
    ['Favorable 0.5R', row => row.metrics?.reached_05r], ['Favorable 1R', row => row.metrics?.reached_1r],
    ['Availability timing', row => row.metrics?.publication_timing],
    ['First favorable 0.5R time', row => row.metrics?.first_05r_at],
    ['First favorable 1R time', row => row.metrics?.first_1r_at],
    ['Outcome time', row => row.metrics?.strict_at],
    ['Entry revisited', row => row.metrics?.entry_revisited], ['Strict outcome', row => row.metrics?.strict_outcome],
    ['Source', row => JSON.stringify(row.source || {})], ['Evidence', row => JSON.stringify(row.evidence || {})],
  ]
  return [columns.map(([label]) => studyCsvCell(label)).join(','),
    ...rows.map(row => columns.map(([, get]) => studyCsvCell(get(row))).join(','))].join('\r\n')
}
