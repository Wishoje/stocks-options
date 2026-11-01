import { defaultSwingFilters, swingFilterError, swingStatusLabel } from '@/Support/swingResearch'

export const INTRADAY_RESEARCH_ALERTS = Symbol('intraday-research-alerts')
export const intradayVariants = ['baseline', 'post_publication_retest']
export const intradayVariantLabels = { baseline: 'Recorded-close control', post_publication_retest: 'Post-publication retest' }
export const intradayStatusLabel = value => ({ watching: 'Waiting for retest', waiting: 'Waiting for retest', not_qualified: 'Conditions not met', entry_window_ended: 'Entry window ended', session_ended: 'Session ended', resolved_before_entry: 'Original scenario ended before retest' }[value] || swingStatusLabel(value))
export function defaultIntradayFilters(now = new Date(), search = '') {
  const filters = defaultSwingFilters(now, search)
  if (!new URLSearchParams(search).has('from')) {
    const start = new Date(`${filters.to}T12:00:00Z`)
    start.setUTCDate(start.getUTCDate() - 13)
    filters.from = start.toISOString().slice(0, 10)
  }
  return { from: filters.from, to: filters.to, symbol: filters.symbol }
}
export const intradayFilterError = swingFilterError
export const intradayFilterParams = filters => ({ from: filters.from, to: filters.to, symbol: filters.symbol.trim().toUpperCase() })
export const intradayCaseUrl = item => {
  const params = new URLSearchParams({ symbol: item.symbol })
  if (item.session) { params.set('from', item.session); params.set('to', item.session) }
  else if (item.signal_at) {
    const session = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(item.signal_at))
    params.set('from', session); params.set('to', session)
  }
  return `/intraday-research?${params}#intraday-case-${encodeURIComponent(item.id)}`
}
