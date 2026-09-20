import { numeric } from '../UI/numbers.js'
import {
  buildStrikePoints,
  chartStrikeRows,
  normalizedStrikeRows,
} from '../EodStrikes/strikeChartUtils.js'

const FIELD_LABELS = {
  strike: 'Strike',
  call_vol: 'Call volume',
  call_vol_delta: 'Cumulative call volume (compatibility field)',
  call_volume_delta: 'Cumulative call volume (compatibility field)',
  put_vol: 'Put volume',
  put_vol_delta: 'Cumulative put volume (compatibility field)',
  put_volume_delta: 'Cumulative put volume (compatibility field)',
  oi_call_eod: 'Call EOD open interest',
  oi_put_eod: 'Put EOD open interest',
  vol_oi: 'Returned Vol/OI ratio',
  pcr: 'Returned put/call ratio',
  call_prem: 'Call premium ($)',
  premium_call: 'Call premium ($)',
  put_prem: 'Put premium ($)',
  premium_put: 'Put premium ($)',
  net_gex_live: 'Returned repriced net GEX',
  net_gex: 'Returned repriced net GEX',
  net_gex_delta: 'Returned net_gex_delta field',
}

function firstNumeric(row, keys) {
  for (const key of keys) {
    const value = numeric(row?.[key])
    if (value != null) return value
  }
  return null
}

export const callVolume = row => firstNumeric(row, ['call_vol', 'call_vol_delta', 'call_volume_delta'])
export const putVolume = row => firstNumeric(row, ['put_vol', 'put_vol_delta', 'put_volume_delta'])
export const callOpenInterest = row => firstNumeric(row, ['oi_call_eod', 'call_oi_eod'])
export const putOpenInterest = row => firstNumeric(row, ['oi_put_eod', 'put_oi_eod'])
export const callPremium = row => firstNumeric(row, ['call_prem', 'premium_call'])
export const putPremium = row => firstNumeric(row, ['put_prem', 'premium_put'])

function returnedRatio(row, kind) {
  return numeric(row?.[kind === 'pcr' ? 'pcr' : 'vol_oi'])
}

export function ratioDetails(row, kind) {
  const provided = returnedRatio(row, kind)
  const numeratorParts = kind === 'pcr'
    ? [putVolume(row)]
    : [callVolume(row), putVolume(row)]
  const denominatorParts = kind === 'pcr'
    ? [callVolume(row)]
    : [callOpenInterest(row), putOpenInterest(row)]
  const components = [...numeratorParts, ...denominatorParts]
  const componentsComplete = components.every(value => value != null)
  const numerator = componentsComplete
    ? numeratorParts.reduce((sum, value) => sum + value, 0)
    : null
  const denominator = componentsComplete
    ? denominatorParts.reduce((sum, value) => sum + value, 0)
    : null
  const derived = denominator != null && denominator > 0
    ? numerator / denominator
    : null

  return {
    value: provided ?? derived,
    provided,
    derived,
    numerator,
    denominator,
    componentsComplete,
    source: provided != null ? 'returned' : derived != null ? 'derived' : 'unavailable',
  }
}

export const volOiValue = row => ratioDetails(row, 'vol_oi').value
export const pcrValue = row => ratioDetails(row, 'pcr').value

export function aggregateRatio(rows, kind) {
  const details = rows.map(row => ratioDetails(row, kind))
  const complete = details.filter(item => item.componentsComplete)
  const numerator = complete.reduce((sum, item) => sum + item.numerator, 0)
  const denominator = complete.reduce((sum, item) => sum + item.denominator, 0)
  const coverageComplete = rows.length > 0 && complete.length === rows.length

  return {
    value: coverageComplete && denominator > 0 ? numerator / denominator : null,
    numerator: complete.length ? numerator : null,
    denominator: complete.length ? denominator : null,
    completeCount: complete.length,
    zeroDenominatorCount: complete.filter(item => item.denominator === 0).length,
    coverageComplete,
  }
}

export function buildRatioStrikePoints(rows, kind, options = {}) {
  const skeleton = buildStrikePoints(rows, { __placeholder: () => 0 }, options)
  return skeleton.map(({ __placeholder, ...point }) => {
    if (point.sourceCount === 1) {
      const details = ratioDetails(point.sourceRows[0], kind)
      return {
        ...point,
        value: details.value,
        numerator: details.numerator,
        denominator: details.denominator,
        componentCoverage: details.componentsComplete ? 1 : 0,
        ratioSource: details.source,
      }
    }

    const aggregate = aggregateRatio(point.sourceRows, kind)
    return {
      ...point,
      value: aggregate.value,
      numerator: aggregate.numerator,
      denominator: aggregate.denominator,
      componentCoverage: aggregate.completeCount,
      ratioSource: aggregate.coverageComplete && aggregate.value != null ? 'aggregated' : 'unavailable',
    }
  })
}

export function normalizedIntradayRows(rows = []) {
  return normalizedStrikeRows(rows)
}

export function chartIntradayRows(rows = []) {
  return chartStrikeRows(rows)
}

export function returnedFieldKeys(rows = [], preferred = []) {
  const discovered = new Set()
  rows.forEach(row => {
    Object.keys(row || {}).forEach(key => {
      if (!key.startsWith('__')) discovered.add(key)
    })
  })

  return [
    ...preferred.filter(key => discovered.delete(key)),
    ...discovered,
  ]
}

export function returnedFieldColumns(rows = [], preferred = [], formatters = {}) {
  return returnedFieldKeys(rows, preferred).map(key => {
    const values = rows
      .map(row => row?.[key])
      .filter(value => value != null && value !== '')
    const numericColumn = values.length > 0 && values.every(value => numeric(value) != null)
    return {
      key,
      label: FIELD_LABELS[key] ?? key.replaceAll('_', ' '),
      numeric: numericColumn,
      sortable: true,
      format: formatters[key] ?? formatReturnedValue,
    }
  })
}

export function formatReturnedValue(value) {
  if (value == null || value === '') return 'Unavailable'
  if (typeof value === 'object') {
    try {
      return JSON.stringify(value)
    } catch {
      return String(value)
    }
  }
  return String(value)
}

export function formatRatio(value, digits = 2) {
  const number = numeric(value)
  if (number == null) return 'Unavailable'
  return `${number.toLocaleString('en-US', { maximumFractionDigits: digits, minimumFractionDigits: digits })}x`
}

export function formatCurrency(value, { compact = false } = {}) {
  const number = numeric(value)
  if (number == null) return 'Unavailable'
  return new Intl.NumberFormat('en-US', compact
    ? { style: 'currency', currency: 'USD', notation: 'compact', maximumFractionDigits: 2 }
    : { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(number)
}

export function ratioSourceLabel(source) {
  if (source === 'returned') return 'API ratio'
  if (source === 'derived') return 'Derived from returned components'
  if (source === 'aggregated') return 'Recomputed from grouped components'
  return 'Unavailable'
}

export function readableLinearCeiling(values, {
  minimum = 1,
  quantile = 0.95,
  outlierFactor = 2.5,
  padding = 0.12,
} = {}) {
  const sorted = values
    .map(numeric)
    .filter(value => value != null && value >= 0)
    .sort((left, right) => left - right)
  if (!sorted.length) return minimum

  const maximum = sorted.at(-1)
  const quantileIndex = Math.min(
    sorted.length - 1,
    Math.max(0, Math.floor((sorted.length - 1) * quantile)),
  )
  const typicalCeiling = Math.max(minimum, sorted[quantileIndex] * (1 + padding))
  return maximum > typicalCeiling * outlierFactor
    ? typicalCeiling
    : Math.max(minimum, maximum * (1 + padding))
}
