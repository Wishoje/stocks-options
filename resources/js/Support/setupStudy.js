export const studyCohorts = [
  { key: 'intraday', label: 'Intraday' },
  { key: 'swing_short', label: 'Swing 2–5 days' },
  { key: 'swing_position', label: 'Swing 1–3 weeks' },
]

const finite = value => (typeof value === 'number' || (typeof value === 'string' && value.trim() !== ''))
  && Number.isFinite(Number(value))

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

function signedMovePercent(value, sign) {
  if (!finite(value)) return '—'
  const magnitude = Number(Math.abs(Number(value)).toFixed(2))
  return magnitude === 0 ? '0%' : `${sign}${studyNumber(magnitude)}%`
}

export const studyFavorablePercent = value => signedMovePercent(value, '+')
export const studyAdversePercent = value => signedMovePercent(value, '-')

/** Archived percentages already account for the idea's bullish or bearish direction. */
export function studyMoveDollars(row, kind) {
  if (!['favorable', 'adverse'].includes(kind) || !finite(row?.signal_price) || Number(row.signal_price) <= 0) return null
  const percent = kind === 'favorable' ? row.metrics?.mfe_pct : row.metrics?.mae_pct
  if (!finite(percent) || (finite(row.metrics?.observations) && Number(row.metrics.observations) <= 0)) return null
  const magnitude = Math.abs(Number(percent)) * Number(row.signal_price) / 100
  if (!Number.isFinite(magnitude)) return null
  return magnitude === 0 ? 0 : kind === 'favorable' ? magnitude : -magnitude
}

export function studyDollars(value) {
  if (!finite(value)) return '—'
  const rounded = Number(Number(value).toFixed(2))
  return new Intl.NumberFormat('en-US', {
    style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2, signDisplay: 'exceptZero',
  }).format(rounded === 0 ? 0 : rounded)
}

/** Whether the milestone has been observed so far; a partial review may still change. */
export function studyPercentMilestone(row, thresholdPct) {
  const percent = row?.metrics?.mfe_pct
  if (!finite(percent) || Number(percent) < 0 || !finite(thresholdPct) || Number(thresholdPct) <= 0
    || (finite(row?.metrics?.observations) && Number(row.metrics.observations) <= 0)) return null
  return Number(percent) >= Number(thresholdPct)
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
  if (typeof value === 'number' && !Number.isFinite(value)) value = null
  let text = value === null || value === undefined ? '' : String(value)
  if (typeof value === 'string' && /^[\s]*[=+\-@\t\r]/.test(text)) text = `'${text}`
  return `"${text.replaceAll('"', '""')}"`
}

export function studyCsv(rows) {
  const time = value => studyTime(value) === '—' ? null : studyTime(value)
  const dollars = (row, kind) => {
    const value = studyMoveDollars(row, kind)
    return value === null ? null : Number(value.toFixed(2)) || 0
  }
  const move = (row, field, sign) => finite(row.metrics?.[field])
    && !(finite(row.metrics?.observations) && Number(row.metrics.observations) <= 0)
    ? (Number(row.metrics[field]) === 0 ? 0 : sign * Math.abs(Number(row.metrics[field]))) : null
  const needsReview = row => row.needs_update || row.status === 'needs_update'
  const verified = row => row.qualified === true && row.followup_complete === true
    && !needsReview(row) && !row.metrics?.evidence_gap
    && ['target_reached', 'target_hit', 'invalidated', 'session_ended', 'holding_ended', 'window_closed'].includes(row.metrics?.strict_outcome)
  const targetReached = row => verified(row)
    ? ['target_reached', 'target_hit'].includes(row.metrics.strict_outcome) : null
  const status = row => needsReview(row) ? 'Needs review' : ({
    watching: 'Waiting for confirmation', conditions_met: 'Conditions met', qualified: 'Conditions met',
    in_progress: 'Still being tracked', completed: 'Review complete',
  }[row.status] || studyLabel(row.status))
  const result = row => needsReview(row) ? 'Needs review' : verified(row) ? studyLabel(row.metrics.strict_outcome) : null
  const boolean = value => typeof value === 'boolean' ? value : null
  const columns = [
    ['Symbol', row => row.symbol], ['Direction', row => studyLabel(row.direction)],
    ['Status', status], ['Result', result],
    ['Signal time (ET)', row => time(row.signal_at)], ['Available time (ET)', row => time(row.available_at)],
    ['Signal price', row => row.signal_price], ['Entry area low', row => row.entry_low], ['Entry area high', row => row.entry_high],
    ['Invalidation price', row => row.invalidation], ['Target price', row => row.target],
    ['Best favorable stock move (%)', row => move(row, 'mfe_pct', 1)],
    ['Best favorable move ($)', row => dollars(row, 'favorable')],
    ['Biggest move against idea (%)', row => move(row, 'mae_pct', -1)],
    ['Adverse move ($)', row => dollars(row, 'adverse')],
    ['Target reached before invalidation', targetReached],
    ['Entry area revisited', row => boolean(row.metrics?.entry_revisited)],
    ['Result time (ET)', row => time(row.metrics?.strict_at)],
    ['Qualified', row => boolean(row.qualified)], ['Follow-up complete', row => boolean(row.followup_complete)],
    ['Favorable stock move of 0.5% observed', row => studyPercentMilestone(row, .5)],
    ['Favorable stock move of 1% observed', row => studyPercentMilestone(row, 1)],
    ['Advanced: Plan ID', row => row.id], ['Advanced: Cohort (raw)', row => row.horizon],
    ['Advanced: Direction (raw)', row => row.direction], ['Advanced: Status (raw)', row => row.status],
    ['Advanced: Outcome (raw)', row => row.outcome], ['Advanced: Signal time (UTC)', row => row.signal_at],
    ['Advanced: Available time (UTC)', row => row.available_at], ['Advanced: Confirmation count', row => row.confirmation_count],
    ['Advanced: Best close R', row => row.metrics?.close_mfe_r ?? row.metrics?.mfe_r],
    ['Advanced: Adverse close distance R', row => row.metrics?.close_mae_r ?? row.metrics?.mae_r],
    ['Advanced: Favorable 0.5R', row => row.metrics?.reached_05r], ['Advanced: Favorable 1R', row => row.metrics?.reached_1r],
    ['Advanced: Availability timing', row => row.metrics?.publication_timing],
    ['Advanced: First favorable 0.5R time (UTC)', row => row.metrics?.first_05r_at],
    ['Advanced: First favorable 1R time (UTC)', row => row.metrics?.first_1r_at],
    ['Advanced: Outcome time (UTC)', row => row.metrics?.strict_at],
    ['Advanced: Strict outcome', row => row.metrics?.strict_outcome],
    ['Advanced: Source (JSON)', row => JSON.stringify(row.source || {})], ['Advanced: Evidence (JSON)', row => JSON.stringify(row.evidence || {})],
  ]
  return [columns.map(([label]) => studyCsvCell(label)).join(','),
    ...rows.map(row => columns.map(([, get]) => studyCsvCell(get(row))).join(','))].join('\r\n')
}
