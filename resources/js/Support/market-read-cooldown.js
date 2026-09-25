import { AxiosError } from 'axios'

// These routes share Laravel's market-data-read budget. Work submission and
// work-run polling have separate limits and must remain independent.
const readRoutes = new Set([
  '/api/gex-levels', '/api/symbols', '/api/symbol/status', '/api/watchlist',
  '/api/watchlist/universe', '/api/watchlist/eod-exports', '/api/iv/term',
  '/api/vrp', '/api/qscore', '/api/seasonality/5d', '/api/iv/skew',
  '/api/iv/skew/by-bucket', '/api/iv/skew/history', '/api/iv/skew/history/bucket',
  '/api/dex', '/api/expiry-pressure', '/api/expiry-pressure/batch', '/api/ua',
  '/api/intraday/summary', '/api/intraday/volume-by-strike', '/api/intraday/ua',
  '/api/intraday/strikes', '/api/intraday/repriced-gex-by-strike',
  '/api/hot-options', '/api/option-chain',
])

export function rateLimitDelayMs(response, now = Date.now()) {
  const header = response?.headers?.get?.('retry-after') ?? response?.headers?.['retry-after']
  const seconds = [response?.data?.retry_after_seconds, header]
    .filter(value => value !== undefined && value !== null && String(value).trim() !== '')
    .map(value => Number(value))
    .filter(value => Number.isFinite(value) && value >= 0)
  if (header && !Number.isFinite(Number(header))) {
    const deadline = Date.parse(header)
    if (Number.isFinite(deadline)) seconds.push(Math.max(0, (deadline - now) / 1000))
  }
  return Math.max(1000, (seconds.length ? Math.max(...seconds) : 60) * 1000)
}

export function installMarketReadCooldown(client) {
  const storageKey = 'gex:market-read-cooldown:v1'
  let blockedUntil = 0
  try {
    const stored = Number(sessionStorage.getItem(storageKey))
    if (Number.isFinite(stored) && stored > Date.now()) blockedUntil = stored
  } catch { /* Storage may be unavailable. Keep the in-memory cooldown. */ }
  const isMarketRead = config => {
    // Only relative same-origin application requests are governed here.
    const path = String(config?.url || '').split('?')[0]
    const method = String(config?.method || 'get').toLowerCase()
    return (method === 'get' && (readRoutes.has(path) || path.startsWith('/api/watchlist/eod-export/')))
      || (method === 'post' && path === '/api/scanner/walls')
  }
  const requestId = client.interceptors.request.use(config => {
    if (isMarketRead(config) && blockedUntil > Date.now()) {
      const seconds = Math.ceil((blockedUntil - Date.now()) / 1000)
      const response = {
        status: 429, statusText: 'Too Many Requests', config,
        headers: { 'retry-after': String(seconds) },
        data: { code: 'work_rate_limited', rate_limit_scope: 'market-data-read',
          message: `Data requests are paused. Try again in ${seconds} seconds.`, retry_after_seconds: seconds },
      }
      const error = new AxiosError(response.data.message, 'ERR_BAD_REQUEST', config, null, response)
      error.localCooldown = true
      throw error
    }
    return config
  })
  const observeLimit = (response, config) => {
    if (response?.status === 429 && isMarketRead(config)
      && response.data?.code === 'work_rate_limited'
      && (!response.data.rate_limit_scope || response.data.rate_limit_scope === 'market-data-read')) {
      blockedUntil = Math.max(blockedUntil, Date.now() + rateLimitDelayMs(response) + 250)
      try { sessionStorage.setItem(storageKey, String(blockedUntil)) } catch { /* Best effort. */ }
    }
  }
  const responseId = client.interceptors.response.use(response => {
    // Status pollers may accept 429 via validateStatus. Observe the limit
    // without changing their existing resolved-response contract.
    observeLimit(response, response.config)
    return response
  }, error => {
    if (!error.localCooldown) observeLimit(error?.response, error?.config)
    return Promise.reject(error)
  })
  return () => {
    client.interceptors.request.eject(requestId)
    client.interceptors.response.eject(responseId)
  }
}
