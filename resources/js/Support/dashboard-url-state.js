export const dashboardTimeframes = ['0d', '1d', '7d', '14d', '30d', '90d']
export const dashboardTabs = {
  eod: ['overview', 'positioning', 'volatility', 'ua', 'strikes'],
  intraday: ['flow', 'strikes'],
}

function symbol(value, fallback = 'SPY') {
  const cleaned = String(value || '').trim().toUpperCase().replace(/[^A-Z0-9.^-]/g, '').slice(0, 15)
  return cleaned || fallback
}

export function dashboardStateFromSearch(search = '', fallback = {}) {
  const params = new URLSearchParams(search)
  const requestedMode = params.get('mode')
  const mode = requestedMode === 'intraday' ? 'intraday' : 'eod'
  const requestedTab = params.get('tab')
  const defaultTab = mode === 'intraday' ? 'flow' : 'strikes'
  const tab = dashboardTabs[mode].includes(requestedTab) ? requestedTab : defaultTab
  const requestedTimeframe = params.get('timeframe')
  const timeframe = dashboardTimeframes.includes(requestedTimeframe)
    ? requestedTimeframe
    : (dashboardTimeframes.includes(fallback.timeframe) ? fallback.timeframe : '14d')

  return {
    symbol: symbol(params.get('symbol'), symbol(fallback.symbol)),
    mode,
    tab,
    timeframe,
  }
}

export function dashboardUrl(currentHref, state) {
  const url = new URL(currentHref, 'http://localhost')
  url.searchParams.set('symbol', symbol(state.symbol))
  url.searchParams.set('mode', state.mode === 'intraday' ? 'intraday' : 'eod')
  const mode = url.searchParams.get('mode')
  const tab = dashboardTabs[mode].includes(state.tab)
    ? state.tab
    : (mode === 'intraday' ? 'flow' : 'strikes')
  url.searchParams.set('tab', tab)
  url.searchParams.set('timeframe', dashboardTimeframes.includes(state.timeframe) ? state.timeframe : '14d')
  return `${url.pathname}${url.search}${url.hash}`
}
