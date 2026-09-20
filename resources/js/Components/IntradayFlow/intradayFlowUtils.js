import { numeric } from '../UI/numbers.js'

const FIELD_ALIASES = {
  callVolume: ['call_vol', 'call_vol_delta', 'call_volume_delta'],
  putVolume: ['put_vol', 'put_vol_delta', 'put_volume_delta'],
  callPremium: ['call_prem', 'premium_call'],
  putPremium: ['put_prem', 'premium_put'],
  callOpenInterest: ['oi_call_eod', 'call_oi_eod'],
  putOpenInterest: ['oi_put_eod', 'put_oi_eod'],
  pcr: ['pcr', 'pcr_volume'],
  volumeOverOpenInterest: ['vol_oi', 'volume_over_oi'],
  netGexLive: ['net_gex_live', 'net_gex'],
  netGexDelta: ['net_gex_delta'],
}

function firstNumeric(row, fields) {
  for (const field of fields) {
    if (!Object.prototype.hasOwnProperty.call(row, field)) continue
    const value = numeric(row[field])
    if (value != null) return value
    if (row[field] == null || row[field] === '') return null
  }
  return null
}

function firstPresent(row, fields) {
  for (const field of fields) {
    if (Object.prototype.hasOwnProperty.call(row, field)) return row[field]
  }
  return null
}

function strikeLabel(value) {
  if (value == null) return 'Unavailable'
  return Number.isInteger(value) ? String(value) : String(Number(value.toFixed(4)))
}

export function normalizeFlowRows(rows = []) {
  return (Array.isArray(rows) ? rows : []).map((source, index) => {
    const row = source && typeof source === 'object' ? source : {}
    return {
      ...row,
      __source: row,
      __source_index: index,
      __row_key: `${row.strike ?? 'missing'}-${index}`,
      __strike: numeric(row.strike),
      __strike_raw: row.strike ?? null,
      __call_volume: firstNumeric(row, FIELD_ALIASES.callVolume),
      __call_volume_raw: firstPresent(row, FIELD_ALIASES.callVolume),
      __put_volume: firstNumeric(row, FIELD_ALIASES.putVolume),
      __put_volume_raw: firstPresent(row, FIELD_ALIASES.putVolume),
      __call_premium: firstNumeric(row, FIELD_ALIASES.callPremium),
      __call_premium_raw: firstPresent(row, FIELD_ALIASES.callPremium),
      __put_premium: firstNumeric(row, FIELD_ALIASES.putPremium),
      __put_premium_raw: firstPresent(row, FIELD_ALIASES.putPremium),
      __call_oi: firstNumeric(row, FIELD_ALIASES.callOpenInterest),
      __call_oi_raw: firstPresent(row, FIELD_ALIASES.callOpenInterest),
      __put_oi: firstNumeric(row, FIELD_ALIASES.putOpenInterest),
      __put_oi_raw: firstPresent(row, FIELD_ALIASES.putOpenInterest),
      __pcr: firstNumeric(row, FIELD_ALIASES.pcr),
      __pcr_raw: firstPresent(row, FIELD_ALIASES.pcr),
      __vol_oi: firstNumeric(row, FIELD_ALIASES.volumeOverOpenInterest),
      __vol_oi_raw: firstPresent(row, FIELD_ALIASES.volumeOverOpenInterest),
      __net_gex_live: firstNumeric(row, FIELD_ALIASES.netGexLive),
      __net_gex_live_raw: firstPresent(row, FIELD_ALIASES.netGexLive),
      __net_gex_delta: firstNumeric(row, FIELD_ALIASES.netGexDelta),
      __net_gex_delta_raw: firstPresent(row, FIELD_ALIASES.netGexDelta),
    }
  })
}

export function chartFlowRows(rows = []) {
  return normalizeFlowRows(rows)
    .filter(row => row.__strike != null)
    .sort((left, right) => left.__strike - right.__strike)
}

export function focusFlowBand(rows, { relativeThreshold = 0.02, padding = 8 } = {}) {
  if (!rows.length) return []
  const maxima = ['__call_volume', '__put_volume'].map(key => rows.reduce((largest, row) => {
    const value = numeric(row[key])
    return value == null ? largest : Math.max(largest, Math.abs(value))
  }, 0))
  if (maxima.every(maximum => maximum === 0)) return rows
  const thresholds = maxima.map(maximum => maximum > 0
    ? maximum * relativeThreshold
    : Number.POSITIVE_INFINITY)

  let first = -1
  let last = -1
  rows.forEach((row, index) => {
    const active = [row.__call_volume, row.__put_volume]
      .some((value, side) => value != null && Math.abs(value) >= thresholds[side])
    if (!active) return
    if (first < 0) first = index
    last = index
  })
  if (first < 0 || last < 0) return rows
  return rows.slice(Math.max(0, first - padding), Math.min(rows.length, last + padding + 1))
}

export function niceFlowStep(step) {
  if (!Number.isFinite(step) || step <= 0) return 1
  const power = 10 ** Math.floor(Math.log10(step))
  for (const unit of [1, 2, 5, 10]) {
    const candidate = unit * power
    if (step <= candidate) return candidate
  }
  return 10 * power
}

function sumAvailable(rows, key) {
  let count = 0
  const value = rows.reduce((sum, row) => {
    const current = numeric(row[key])
    if (current == null) return sum
    count += 1
    return sum + current
  }, 0)
  return count ? value : null
}

function flowPoint(label, start, end, sourceRows) {
  const callVolume = sumAvailable(sourceRows, '__call_volume')
  const putVolume = sumAvailable(sourceRows, '__put_volume')
  const callPremium = sumAvailable(sourceRows, '__call_premium')
  const putPremium = sumAvailable(sourceRows, '__put_premium')
  return {
    label,
    start,
    end,
    sourceCount: sourceRows.length,
    sourceRows,
    callVolume,
    putVolume,
    callPremium,
    putPremium,
    pcr: callVolume != null && callVolume > 0 && putVolume != null
      ? putVolume / callVolume
      : null,
  }
}

export function buildFlowPoints(rows, { bucket = false, maxBars = 90 } = {}) {
  if (!rows.length) return []
  const parsedLimit = Number(maxBars)
  const limit = Number.isFinite(parsedLimit) && parsedLimit > 0
    ? Math.max(1, Math.floor(parsedLimit))
    : 90

  if (!bucket || rows.length <= limit) {
    return rows.map(row => flowPoint(
      strikeLabel(row.__strike),
      row.__strike,
      row.__strike,
      [row],
    ))
  }

  const minimum = rows[0].__strike
  const maximum = rows.at(-1).__strike
  const step = niceFlowStep((maximum - minimum) / Math.min(limit, rows.length))
  const bucketCount = Math.min(limit, Math.max(1, Math.ceil((maximum - minimum) / step)))
  const buckets = new Map()

  rows.forEach(row => {
    const index = Math.min(
      bucketCount - 1,
      Math.max(0, Math.floor((row.__strike - minimum) / step)),
    )
    if (!buckets.has(index)) buckets.set(index, [])
    buckets.get(index).push(row)
  })

  return [...buckets.entries()]
    .sort(([left], [right]) => left - right)
    .map(([index, sourceRows]) => {
      const start = minimum + index * step
      const end = start + step
      return flowPoint(`${strikeLabel(start)}–${strikeLabel(end)}`, start, end, sourceRows)
    })
}

export function strongestFlowPoint(points = []) {
  return points.reduce((strongest, point) => {
    const values = [numeric(point.callVolume), numeric(point.putVolume)]
    if (values.every(value => value == null)) return strongest
    const magnitude = values.reduce((sum, value) => sum + Math.abs(value ?? 0), 0)
    return !strongest || magnitude > strongest.magnitude ? { point, magnitude } : strongest
  }, null)?.point ?? null
}

export function completeVolumeTotal(explicitTotal, callVolume, putVolume) {
  const total = numeric(explicitTotal)
  if (total != null) return total
  const call = numeric(callVolume)
  const put = numeric(putVolume)
  return call == null || put == null ? null : call + put
}

export function compactUnsigned(value) {
  const number = numeric(value)
  if (number == null) return 'Unavailable'
  const magnitude = Math.abs(number)
  const unit = magnitude >= 1e9 ? [1e9, 'B'] : magnitude >= 1e6 ? [1e6, 'M'] : magnitude >= 1e3 ? [1e3, 'K'] : [1, '']
  const scaled = number / unit[0]
  const display = unit[0] === 1
    ? String(Number(Math.abs(scaled) < 1 && scaled !== 0 ? scaled.toPrecision(3) : scaled.toFixed(2)))
    : String(Number(scaled.toFixed(2)))
  return `${display}${unit[1]}`
}

export function compactUsd(value) {
  const display = compactUnsigned(value)
  return display === 'Unavailable' ? display : `$${display}`
}

export function ratioLabel(value, digits = 2) {
  const number = numeric(value)
  return number == null ? 'Unavailable' : number.toFixed(digits)
}

export function flowSnapshotName(symbol, tradeDate, sourceAsOf) {
  const parts = ['intraday-flow', symbol, tradeDate, sourceAsOf]
    .filter(part => part != null && String(part).trim())
    .map(part => String(part).trim().replace(/[^a-zA-Z0-9._-]+/g, '-').replace(/^-+|-+$/g, ''))
    .filter(Boolean)
  return parts.join('-') || 'intraday-flow'
}
