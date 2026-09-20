import { dashboardUrl } from '@/Support/dashboard-url-state.js'

export const SCANNER_TIMEFRAMES = ['1d', '7d', '14d', '30d']
export const SCANNER_UNIVERSE_LIMIT = 500

export function scannerNumber(value) {
  if (value == null || value === '' || typeof value === 'boolean') return null
  const number = Number(value)
  return Number.isFinite(number) ? number : null
}

export function scannerSymbol(value) {
  return String(value || '').trim().toUpperCase()
}

function oneOf(value, allowed, fallback) {
  return allowed.includes(value) ? value : fallback
}

function boundedInteger(value, allowed, fallback) {
  const number = Number.parseInt(value, 10)
  return allowed.includes(number) ? number : fallback
}

export function scannerStateFromSearch(search = '') {
  const params = new URLSearchParams(search)
  const nearPct = scannerNumber(params.get('near_pct'))
  const nearPts = scannerNumber(params.get('near_pts'))

  return {
    mode: oneOf(params.get('scan'), ['gex', 'volume'], 'gex'),
    density: oneOf(params.get('density'), ['comfortable', 'compact'], 'comfortable'),
    limit: boundedInteger(params.get('limit'), [100, 200, 300, 400, 500], 200),
    days: boundedInteger(params.get('days'), [5, 10, 20], 10),
    nearPct: nearPct != null && nearPct >= 0 && nearPct <= 100 ? nearPct : 1,
    nearPts: nearPts != null && nearPts >= 0 ? nearPts : null,
    sort: oneOf(params.get('sort'), ['nearest', 'call', 'put'], 'nearest'),
  }
}

export function scannerUrl(currentHref, state) {
  const url = new URL(currentHref, 'http://localhost')
  url.searchParams.set('scan', oneOf(state.mode, ['gex', 'volume'], 'gex'))
  url.searchParams.set('density', oneOf(state.density, ['comfortable', 'compact'], 'comfortable'))
  url.searchParams.set('limit', String(boundedInteger(state.limit, [100, 200, 300, 400, 500], 200)))
  const days = Number.parseInt(state.days, 10)
  if ([5, 10, 20].includes(days)) url.searchParams.set('days', String(days))
  else url.searchParams.delete('days')
  const nearPct = scannerNumber(state.nearPct)
  url.searchParams.set('near_pct', String(nearPct != null && nearPct >= 0 && nearPct <= 100 ? nearPct : 1))
  const nearPts = validNearPoints(state.nearPts)
  if (nearPts == null) url.searchParams.delete('near_pts')
  else url.searchParams.set('near_pts', String(nearPts))
  url.searchParams.set('sort', oneOf(state.sort, ['nearest', 'call', 'put'], 'nearest'))
  return `${url.pathname}${url.search}${url.hash}`
}

export function scannerChunks(symbols, maximum = SCANNER_UNIVERSE_LIMIT) {
  const unique = [...new Set((Array.isArray(symbols) ? symbols : []).map(scannerSymbol).filter(Boolean))]
  const size = Math.max(1, Math.floor(scannerNumber(maximum) ?? SCANNER_UNIVERSE_LIMIT))
  const chunks = []
  for (let index = 0; index < unique.length; index += size) chunks.push(unique.slice(index, index + size))
  return chunks
}

export function wallSide(key) {
  if (String(key).includes('call')) return 'call'
  if (String(key).includes('put')) return 'put'
  return 'wall'
}

export function wallSession(key) {
  return String(key).startsWith('intraday_') ? 'intraday' : 'eod'
}

export function wallDashboardUrl(symbol, tag = null, timeframe = '14d') {
  const mode = tag?.session === 'intraday' || String(tag?.key || '').startsWith('intraday_')
    ? 'intraday'
    : 'eod'

  return dashboardUrl('/dashboard', {
    symbol: scannerSymbol(symbol),
    mode,
    tab: mode === 'intraday' ? 'strikes' : 'strikes',
    timeframe: SCANNER_TIMEFRAMES.includes(String(timeframe).toLowerCase())
      ? String(timeframe).toLowerCase()
      : '14d',
  })
}

export function volumeDashboardUrl(symbol) {
  return dashboardUrl('/dashboard', {
    symbol: scannerSymbol(symbol),
    mode: 'eod',
    tab: 'overview',
    timeframe: '14d',
  })
}

export function wallTagsFor(hit, labelMeta) {
  if (!Array.isArray(hit?.hits) || !hit?.walls || typeof hit.walls !== 'object') return []

  return hit.hits.flatMap((key) => {
    const meta = labelMeta[key]
    const wall = hit.walls[key]
    if (!meta || !wall) return []

    const distancePct = scannerNumber(wall.distance_pc)
    const distancePts = scannerNumber(wall.distance_pt)

    return [{
      key,
      kind: meta.kind,
      side: wallSide(key),
      session: wallSession(key),
      timeframe: meta.timeframe,
      timeframeShort: meta.timeframeShort,
      strike: scannerNumber(wall.strike),
      distancePct,
      distancePts,
    }]
  })
}

export function nearestWallDistance(hit) {
  const values = Object.values(hit?.walls || {})
  let pct = null
  let pts = null

  for (const wall of values) {
    const distancePct = scannerNumber(wall?.distance_pc)
    const distancePts = scannerNumber(wall?.distance_pt)
    if (distancePct != null) pct = pct == null ? Math.abs(distancePct) : Math.min(pct, Math.abs(distancePct))
    if (distancePts != null) pts = pts == null ? Math.abs(distancePts) : Math.min(pts, Math.abs(distancePts))
  }

  return pct == null && pts == null ? null : { pct, pts }
}

export function wallHitCounts(hit) {
  return (hit?.hits || []).reduce((counts, key) => {
    const side = wallSide(key)
    if (side === 'call' || side === 'put') counts[side] += 1
    return counts
  }, { call: 0, put: 0 })
}

export function compareWallHits(left, right, mode = 'nearest') {
  const leftCounts = wallHitCounts(left)
  const rightCounts = wallHitCounts(right)
  const leftBias = leftCounts.call - leftCounts.put
  const rightBias = rightCounts.call - rightCounts.put

  if (mode === 'call') {
    if ((leftCounts.call > 0) !== (rightCounts.call > 0)) return leftCounts.call > 0 ? -1 : 1
    if (leftBias !== rightBias) return rightBias - leftBias
  }
  if (mode === 'put') {
    if ((leftCounts.put > 0) !== (rightCounts.put > 0)) return leftCounts.put > 0 ? -1 : 1
    if (leftBias !== rightBias) return leftBias - rightBias
  }

  const leftNear = nearestWallDistance(left)?.pct ?? Number.POSITIVE_INFINITY
  const rightNear = nearestWallDistance(right)?.pct ?? Number.POSITIVE_INFINITY
  if (leftNear !== rightNear) return leftNear - rightNear
  return scannerSymbol(left?.symbol).localeCompare(scannerSymbol(right?.symbol))
}

export function closestStrikeGex(strikeData, targetStrike) {
  const target = scannerNumber(targetStrike)
  if (!Array.isArray(strikeData) || !strikeData.length || target == null) return null

  let best = null
  let bestDistance = Number.POSITIVE_INFINITY
  const numericRows = []

  for (const row of strikeData) {
    const strike = scannerNumber(row?.strike)
    const netGex = scannerNumber(row?.net_gex ?? row?.netGex)
    if (strike == null || netGex == null) continue
    numericRows.push({ strike, netGex })
    const distance = Math.abs(strike - target)
    if (distance < bestDistance) {
      bestDistance = distance
      best = { strike, netGex }
    }
  }

  if (!best) return null
  const maxAbsolute = numericRows.reduce((maximum, row) => Math.max(maximum, Math.abs(row.netGex)), 0)

  return {
    ...best,
    pctOfMax: maxAbsolute > 0 ? (Math.abs(best.netGex) / maxAbsolute) * 100 : null,
  }
}

export function validNearPoints(value) {
  const number = scannerNumber(value)
  return number != null && number >= 0 ? number : null
}

export function validNearPercent(value, fallback = 1) {
  const number = scannerNumber(value)
  return number != null && number >= 0 && number <= 100 ? number : fallback
}

export function hotResultItems(items, symbols) {
  if (Array.isArray(items) && items.length) return items
  return (Array.isArray(symbols) ? symbols : []).map(symbol => ({ symbol }))
}
